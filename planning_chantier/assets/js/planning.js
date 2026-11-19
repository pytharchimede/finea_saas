(function () {
    document.querySelectorAll('[data-financial-pilot]').forEach(function (pilot) {
        var projectSelect = pilot.querySelector('[data-financial-project]');
        var projectBadges = pilot.querySelectorAll('[data-financial-project-badge]');
        var linePanel = pilot.querySelector('[data-financial-lines]');
        var detailPanel = pilot.querySelector('[data-financial-detail]');
        var summary = pilot.querySelector('[data-financial-summary]');
        var toast = pilot.querySelector('[data-financial-toast]');
        var officePerformanceBanner = pilot.querySelector('[data-office-performance-banner]');
        var bulkToggle = pilot.querySelector('[data-bulk-selection-toggle]');
        var bulkBar = pilot.querySelector('[data-bulk-selection-bar]');
        var csrf = pilot.dataset.csrf || '';
        var preparationId = Number(pilot.dataset.preparationId || 0);
        var isOfficeMeeting = pilot.dataset.officeMeeting === '1';
        var meetingParticipants = [];
        try { meetingParticipants = JSON.parse(pilot.dataset.participants || '[]'); } catch (error) {}
        var assignmentPeople = [];
        try { assignmentPeople = JSON.parse(pilot.dataset.assignees || '[]'); } catch (error) {}
        if(!Array.isArray(assignmentPeople)||!assignmentPeople.length)assignmentPeople=meetingParticipants;
        var state = null;
        var selectedLineId = 0;
        var selectedDebourseId = 0;
        var selectedOfficeDetailId = 0;
        var checklistQueues = {};
        var taskSaveTimers = {};
        var taskSaveQueues = {};
        var openCustomSectionId = 0;
        var openCustomPointId = 0;
        var selectedCustomSectionId = 0;

        function money(value) {
            return new Intl.NumberFormat('fr-FR', {maximumFractionDigits: 0}).format(Number(value || 0)) + ' FCFA';
        }
        function percent(value) {
            return new Intl.NumberFormat('fr-FR', {maximumFractionDigits: 1}).format(Number(value || 0)) + ' %';
        }
        function progressColor(value){var v=Math.max(0,Math.min(100,Number(value||0)));if(v>=100)return '#18864b';if(v>=75)return '#65a30d';if(v>=50)return '#eab308';if(v>=25)return '#f97316';return '#dc2626';}
        function fileSize(value) {
            var size=Number(value||0);
            if(size>=1048576)return (size/1048576).toFixed(1)+' Mo';
            if(size>=1024)return Math.round(size/1024)+' Ko';
            return size+' o';
        }
        function fileIcon(file) {
            var name=String((file&&file.original_name)||'').toLowerCase();
            var mime=String((file&&file.mime_type)||'').toLowerCase();
            var extension=name.indexOf('.')>=0?name.split('.').pop():'';
            if(extension==='pdf'||mime.indexOf('pdf')>=0)return {icon:'fa-file-pdf',type:'is-pdf',label:'PDF'};
            if(['xls','xlsx'].indexOf(extension)>=0||mime.indexOf('spreadsheet')>=0||mime.indexOf('excel')>=0)return {icon:'fa-file-excel',type:'is-excel',label:'Excel'};
            if(['doc','docx'].indexOf(extension)>=0||mime.indexOf('word')>=0)return {icon:'fa-file-word',type:'is-word',label:'Word'};
            if(['ppt','pptx'].indexOf(extension)>=0||mime.indexOf('presentation')>=0||mime.indexOf('powerpoint')>=0)return {icon:'fa-file-powerpoint',type:'is-powerpoint',label:'PowerPoint'};
            if(['png','jpg','jpeg','webp','gif'].indexOf(extension)>=0||mime.indexOf('image/')===0)return {icon:'fa-file-image',type:'is-image',label:'Image'};
            if(extension==='csv'||mime.indexOf('csv')>=0)return {icon:'fa-file-csv',type:'is-csv',label:'CSV'};
            if(extension==='txt'||mime.indexOf('text/plain')>=0)return {icon:'fa-file-lines',type:'is-text',label:'Texte'};
            return {icon:'fa-file',type:'is-generic',label:'Fichier'};
        }
        function notify(message, error) {
            toast.hidden = false;
            toast.textContent = message;
            toast.className = 'pc-pilot-toast ' + (error ? 'is-error' : 'is-success');
            clearTimeout(toast._timer);
            toast._timer = setTimeout(function () { toast.hidden = true; }, 3500);
        }
        async function request(payload, method, feedbackTarget) {
            var feedbackAt=method!=='GET'?savedAnchor(feedbackTarget):null;
            pilot.classList.add('is-loading');
            if (method === 'GET') {
                linePanel.innerHTML = '<div class="pc-pilot-empty"><i class="fas fa-spinner fa-spin"></i><strong>Chargement du chantier…</strong></div>';
                detailPanel.innerHTML = '<div class="pc-pilot-empty"><i class="fas fa-spinner fa-spin"></i><strong>Préparation du planning et des déboursés…</strong></div>';
            }
            try {
                var response;
                if (method === 'GET') {
                    response = await fetch('request/financial_pilot.php?chantier_id=' + encodeURIComponent(payload.chantier_id) + '&preparation_id=' + encodeURIComponent(preparationId) + '&csrf=' + encodeURIComponent(csrf), {headers:{'Accept':'application/json'}});
                } else {
                    payload.csrf = csrf;
                    payload.preparation_id = preparationId;
                    response = await fetch('request/financial_pilot.php', {method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(payload)});
                }
                var raw = await response.text();
                var json;
                try {
                    json = JSON.parse(raw);
                } catch (parseError) {
                    throw new Error('Le serveur n’a pas renvoyé une réponse valide. Rechargez la page puis réessayez.');
                }
                if (!response.ok || !json.ok) throw new Error(json.error || 'Mise à jour impossible.');
                state = json.data;
                render();
                if (method !== 'GET') showSavedNear(feedbackAt);
            } catch (error) {
                if (method === 'GET') {
                    linePanel.innerHTML = '<div class="pc-pilot-empty pc-data-warning"><i class="fas fa-triangle-exclamation"></i><strong>' + escapeHtml(error.message || 'Chargement impossible.') + '</strong></div>';
                    detailPanel.innerHTML = '<div class="pc-pilot-empty"><i class="fas fa-rotate-right"></i><strong>Resélectionnez le chantier pour réessayer.</strong></div>';
                }
                notify(error.message || 'Une erreur est survenue.', true);
            } finally {
                pilot.classList.remove('is-loading');
            }
        }
        async function refreshOfficeSilently() {
            var response=await fetch('request/financial_pilot.php?chantier_id='+encodeURIComponent(state.project.id)+'&preparation_id='+encodeURIComponent(preparationId)+'&csrf='+encodeURIComponent(csrf),{headers:{'Accept':'application/json'}});
            var json=await response.json();
            if(!response.ok||!json.ok)throw new Error(json.error||'Actualisation impossible.');
            state=json.data;
            render();
        }
        async function officeTreeRequest(payload) {
            payload.csrf=csrf;
            payload.preparation_id=preparationId;
            var response=await fetch('request/office_tree.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(payload)});
            var json=await response.json();
            if(!response.ok||!json.ok)throw new Error(json.error||'Modification impossible.');
            await refreshOfficeSilently();
        }
        function progressBar(value) {
            var safe = Math.max(0, Math.min(100, Number(value || 0)));
            return '<div class="pc-pilot-progress"><span style="width:' + safe + '%;background:' + progressColor(safe) + '"></span></div>';
        }
        function participantOptions(selected, allowEmpty) {
            var html=allowEmpty?'<option value="">Aucun</option>':'<option value="">Choisir…</option>';
            assignmentPeople.forEach(function(person){
                var id=Number(person.user_id||0);
                if(!id)return;
                html+='<option value="'+id+'" '+(id===Number(selected||0)?'selected':'')+'>'+escapeHtml(person.name||('Utilisateur #'+id))+'</option>';
            });
            return html;
        }
        function participantName(userId) {
            var person=assignmentPeople.find(function(item){return Number(item.user_id||0)===Number(userId||0);});
            return person?String(person.name||''):'';
        }
        function enhancePersonnelSelects(){
            pilot.querySelectorAll('select[name="pilot_user_id"],select[name="copilot_user_id"],select[name="responsible_user_id"]').forEach(function(select){
                if(select.dataset.searchEnhanced==='1')return;
                select.dataset.searchEnhanced='1';
                var wrap=document.createElement('div');wrap.className='pc-person-search';
                var input=document.createElement('input');input.type='text';input.autocomplete='off';input.setAttribute('role','combobox');input.setAttribute('aria-expanded','false');input.placeholder='Rechercher une personne…';
                var list=document.createElement('div');list.className='pc-person-search-list';list.hidden=true;
                select.parentNode.insertBefore(wrap,select);wrap.appendChild(input);wrap.appendChild(select);wrap.appendChild(list);
                select.hidden=true;
                function selectedLabel(){var option=select.options[select.selectedIndex];return option&&option.value?option.textContent.trim():'';}
                function close(){list.hidden=true;input.setAttribute('aria-expanded','false');}
                function draw(){
                    var term=input.value.trim().toLocaleLowerCase('fr');list.innerHTML='';
                    Array.prototype.slice.call(select.options).filter(function(option){return option.value&&(!term||option.textContent.toLocaleLowerCase('fr').indexOf(term)>=0);}).slice(0,40).forEach(function(option){
                        var item=document.createElement('button');item.type='button';item.textContent=option.textContent;item.dataset.value=option.value;
                        item.addEventListener('mousedown',function(event){event.preventDefault();select.value=item.dataset.value;input.value=item.textContent.trim();close();select.dispatchEvent(new Event('change',{bubbles:true}));});
                        list.appendChild(item);
                    });
                    list.hidden=!list.children.length;input.setAttribute('aria-expanded',list.hidden?'false':'true');
                }
                input.value=selectedLabel();
                input.addEventListener('focus',function(){input.select();draw();});
                input.addEventListener('input',draw);
                input.addEventListener('keydown',function(event){
                    if(event.key==='Escape'){close();input.value=selectedLabel();input.blur();}
                    if(event.key==='Enter'&&!list.hidden&&list.firstElementChild){event.preventDefault();list.firstElementChild.dispatchEvent(new MouseEvent('mousedown',{bubbles:true}));}
                });
                input.addEventListener('blur',function(){setTimeout(function(){close();if(!input.value.trim())select.value='';else input.value=selectedLabel();},120);});
            });
        }
        function activateTaskAutoHeight(){
            pilot.querySelectorAll('.pc-office-task-row .pc-sheet-task textarea').forEach(function(textarea){
                function resize(){textarea.style.height='auto';textarea.style.height=Math.max(34,textarea.scrollHeight)+'px';}
                resize();textarea.addEventListener('input',resize);textarea.addEventListener('change',resize);
            });
        }
        function savedAnchor(element){return element&&element.getBoundingClientRect?element.getBoundingClientRect():element;}
        function showSavedNear(element){
            if(!element)return;var rect=savedAnchor(element);if(!rect||(!rect.width&&!rect.height))return;
            var badge=document.createElement('span');badge.className='pc-floating-save-success';badge.innerHTML='<i class="fas fa-check-circle"></i>';badge.title='Modification enregistrée';
            badge.style.left=Math.max(4,Math.min(window.innerWidth-24,rect.right-18))+'px';badge.style.top=Math.max(4,rect.top+(rect.height/2)-9)+'px';
            document.body.appendChild(badge);requestAnimationFrame(function(){badge.classList.add('is-visible');});
            setTimeout(function(){badge.classList.remove('is-visible');setTimeout(function(){badge.remove();},180);},3000);
        }
        function stars(value) {
            var count=Math.max(0,Math.min(5,Math.round(Number(value||0))));
            return '<span class="pc-stars" aria-label="'+Number(value||0).toFixed(1)+' sur 5">'+('★'.repeat(count))+('☆'.repeat(5-count))+'</span>';
        }
        function renderOfficePerformance() {
            if(!officePerformanceBanner||!state)return;
            var scores={};
            assignmentPeople.forEach(function(person){
                var id=Number(person.user_id||0);
                if(id)scores[id]={user_id:id,participant_name:person.name||('Utilisateur #'+id),values:[]};
            });
            function addScore(userId,name,progress,deadline){
                userId=Number(userId||0);if(!userId)return;
                if(!scores[userId])scores[userId]={user_id:userId,participant_name:name||('Utilisateur #'+userId),values:[]};
                var value=Number(progress||0);
                if(value<100&&deadline&&deadline<(new Date()).toISOString().slice(0,10))value*=.6;
                scores[userId].values.push(value);
            }
            (state.lines||[]).forEach(function(line){(line.debourses||[]).forEach(function(debourse){(debourse.details||[]).forEach(function(detail){(detail.meeting_points||[]).forEach(function(task){
                addScore(task.pilot_user_id,task.pilot_name,task.progress,task.new_deadline);
                addScore(task.copilot_user_id,task.copilot_name,task.progress,task.new_deadline);
            });});});});
            (state.meeting_sections||[]).forEach(function(section){
                var tasks=[];try{tasks=JSON.parse(section.payload_json||'[]');}catch(error){}
                (Array.isArray(tasks)?tasks:[]).forEach(function(task){
                    addScore(task.pilot_user_id,participantName(task.pilot_user_id),task.progress,task.new_deadline);
                    addScore(task.copilot_user_id,participantName(task.copilot_user_id),task.progress,task.new_deadline);
                });
            });
            var performanceItems=Object.keys(scores).map(function(id){
                var item=scores[id],count=item.values.length;
                var score=count?item.values.reduce(function(total,value){return total+value;},0)/count:0;
                return {user_id:item.user_id,participant_name:item.participant_name,tasks_count:count,score:score,stars:score/20};
            }).sort(function(a,b){return b.score-a.score||a.participant_name.localeCompare(b.participant_name);});
            officePerformanceBanner.hidden=false;
            officePerformanceBanner.innerHTML='<div class="pc-office-performance-title"><i class="fas fa-star"></i><span><small>Notation automatique</small><strong>'+performanceItems.length+' intervenant(s)</strong></span></div><div class="pc-office-performance-people">'+
                (performanceItems.length?performanceItems.map(function(item){
                    return '<div class="pc-office-performance-person"><span><strong>'+escapeHtml(item.participant_name||'Collaborateur')+'</strong><small>'+Number(item.tasks_count||0)+' tâche(s)</small></span>'+stars(item.stars)+'<b>'+Number(item.score||0).toFixed(1)+'/100</b></div>';
                }).join(''):'')+'</div>';
        }
        function applyOfficeGroups(groups,performance) {
            if(!state||!state.lines)return;
            state.lines.forEach(function(line){
                line.debourses.forEach(function(debourse){
                    debourse.details.forEach(function(detail){
                        detail.meeting_points=(groups[debourse.id]||{})[detail.id]||[];
                    });
                });
            });
            state.staff_performance=performance||state.staff_performance||[];
            recomputeMeetingProgress();
        }
        function recomputeMeetingProgress(){
            if(!state)return;var globalSum=0,globalCount=0;
            (state.lines||[]).forEach(function(line){var lineSum=0,lineCount=0,lineDone=0;
                (line.debourses||[]).forEach(function(debourse){var sum=0,count=0,done=0;
                    (debourse.details||[]).forEach(function(detail){var points=detail.meeting_points||[];var detailSum=points.reduce(function(total,task){return total+Number(task.progress||0);},0);
                        detail.meeting_point_count=points.length;detail.meeting_completed_count=points.filter(function(task){return Number(task.progress||0)>=100;}).length;detail.meeting_progress=points.length?Math.round(detailSum/points.length*10)/10:0;detail.meeting_completed=points.length>0&&detail.meeting_completed_count===points.length;
                        sum+=detailSum;count+=points.length;done+=detail.meeting_completed_count;
                    });
                    debourse.meeting_point_count=count;debourse.meeting_completed_count=done;debourse.progress=count?Math.round(sum/count*10)/10:0;debourse.completed=count>0&&done===count;
                    lineSum+=sum;lineCount+=count;lineDone+=done;
                });
                line.meeting_point_count=lineCount;line.meeting_completed_count=lineDone;line.progress=lineCount?Math.round(lineSum/lineCount*10)/10:0;line.completed=lineCount>0&&lineDone===lineCount;globalSum+=lineSum;globalCount+=lineCount;
            });
            (state.meeting_sections||[]).forEach(function(section){var metrics=customSectionMetrics(section);metrics.tasks.forEach(function(task){globalSum+=Number(task.progress||0);globalCount++;});});
            state.progress=globalCount?Math.round(globalSum/globalCount*10)/10:0;
        }
        function render() {
            if (!state) return;
            recomputeMeetingProgress();
            summary.hidden = false;
            summary.innerHTML = Number(state.project.is_office||0)===1
                ?'<div><span>Service</span><strong>'+escapeHtml((state.lines[0]&&state.lines[0].designation)||'Service Bureau')+'</strong><a class="pc-summary-gantt" href="request/meeting_gantt_pdf.php?preparation_id='+preparationId+'&all=1" target="_blank" title="Exporter le Gantt de tout le service"><i class="fas fa-file-pdf"></i></a></div><div><span>Progression des tâches</span><strong>'+percent(state.progress)+'</strong>'+progressBar(state.progress)+'</div>'
                :'<div><span>Chantier</span><strong>' + escapeHtml((state.project.code || '') + ' · ' + (state.project.titre || '')) + '</strong></div>' +
                '<div><span>Devis client</span><strong>' + escapeHtml(state.project.numero_devis || ('#' + state.project.devis_id)) + '</strong></div>' +
                '<div><span>Avancement pondéré</span><strong>' + percent(state.progress) + '</strong>' + progressBar(state.progress) + '<a class="pc-summary-gantt" href="request/meeting_gantt_pdf.php?preparation_id='+preparationId+'&all=1" target="_blank" title="Exporter le Gantt général du chantier"><i class="fas fa-file-pdf"></i></a></div>' +
                (Number(state.excluded_debourses || 0) > 0 ? '<div class="pc-data-warning"><i class="fas fa-filter-circle-xmark"></i><span>' + state.excluded_debourses + ' déboursé(s) sans sous-ligne masqué(s)</span></div>' : '');
            renderOfficePerformance();
            if (!state.lines.length) {
                var meetingOnlySections=!isOfficeMeeting?(state.meeting_sections||[]):[];
                if(meetingOnlySections.length&&!meetingOnlySections.some(function(section){return Number(section.id)===selectedCustomSectionId;})){
                    selectedCustomSectionId=Number(meetingOnlySections[0].id);
                }
                linePanel.innerHTML = meetingOnlySections.map(function(section){
                    var metrics=customSectionMetrics(section);
                    return '<div class="pc-office-category pc-meeting-only-line '+(Number(section.id)===selectedCustomSectionId?'is-active':'')+'" data-select-custom-section="'+section.id+'" role="button" tabindex="0"><i class="fas fa-folder"></i><span><strong>'+escapeHtml(section.title||'Ligne de réunion')+'</strong><small>'+metrics.completed+'/'+metrics.total+' tâche(s) · '+percent(metrics.progress)+'</small></span><span class="pc-tree-actions"><a href="request/meeting_gantt_pdf.php?preparation_id='+preparationId+'&section_id='+section.id+'" target="_blank" title="Exporter le Gantt PDF" aria-label="Exporter le Gantt PDF"><i class="fas fa-file-pdf"></i></a><button type="button" data-add-custom-task="'+section.id+'" title="Ajouter une tâche"><i class="fas fa-plus"></i></button><button type="button" class="is-danger" data-archive-custom-section="'+section.id+'" title="Retirer cette ligne"><i class="fas fa-xmark"></i></button></span></div>';
                }).join('')+'<div class="pc-quick-create"><button type="button" data-add-first-meeting-line><i class="fas fa-plus"></i> '+(meetingOnlySections.length?'Nouvelle ligne':'Ajouter la première ligne')+'</button></div>';
                var selectedMeetingSection=meetingOnlySections.find(function(section){return Number(section.id)===selectedCustomSectionId;});
                detailPanel.innerHTML = meetingOnlySections.length
                    ? '<div class="pc-site-debourse-list">'+customSectionHtml(selectedMeetingSection)+'</div>'
                    : '<div class="pc-pilot-empty"><i class="fas fa-circle-info"></i><strong>Le devis ne contient aucune ligne.</strong><span>Vous pouvez créer une première ligne uniquement pour les besoins de cette réunion.</span></div>';
                bind();
                return;
            }
            if (!state.lines.some(function (line) { return Number(line.id) === selectedLineId; })) selectedLineId = Number(state.lines[0].id);
            if(!isOfficeMeeting){
                var selectedSiteLine=state.lines.find(function(line){return Number(line.id)===selectedLineId;});
                if(selectedSiteLine&&!selectedSiteLine.debourses.some(function(item){return Number(item.id)===selectedDebourseId;})){
                    selectedDebourseId=selectedSiteLine.debourses.length?Number(selectedSiteLine.debourses[0].id):0;
                }
            }
            if(Number(state.project.is_office||0)===1){
                var officeLine=state.lines[0];
                if(!officeLine.debourses.some(function(item){return Number(item.id)===selectedDebourseId;}))selectedDebourseId=officeLine.debourses.length?Number(officeLine.debourses[0].id):0;
                linePanel.innerHTML='<div class="pc-office-category-head"><span>Catégories du service</span><strong>'+escapeHtml(officeLine.designation||'Service')+'</strong></div>'+
                    officeLine.debourses.map(function(debourse){
                        return '<div class="pc-office-category '+(Number(debourse.id)===selectedDebourseId?'is-active':'')+'" data-select-office-debourse="'+debourse.id+'" role="button" tabindex="0"><input type="checkbox" data-toggle-meeting-scope="debourse" data-toggle-meeting-id="'+debourse.id+'" data-toggle-meeting-debourse="'+debourse.id+'" '+(debourse.completed?'checked':'')+' '+(Number(debourse.meeting_point_count||0)===0?'disabled':'')+' aria-label="Terminer toutes les tâches de cette catégorie"><i class="fas fa-folder"></i><span><strong>'+escapeHtml(debourse.title||'Catégorie')+'</strong><small>'+Number(debourse.meeting_completed_count||0)+'/'+Number(debourse.meeting_point_count||0)+' tâche(s) · '+percent(debourse.progress)+'</small></span><span class="pc-tree-actions"><a href="request/meeting_gantt_pdf.php?preparation_id='+preparationId+'&debourse_id='+debourse.id+'" target="_blank" title="Exporter le Gantt de ce point"><i class="fas fa-file-pdf"></i></a><i class="fas fa-lock" title="Référence financière en lecture seule"></i></span></div>';
                    }).join('')+
                    '<div class="pc-pilot-note"><i class="fas fa-lock"></i> Les catégories proviennent du déboursé financier et restent en lecture seule dans la réunion.</div>';
            }else{
                linePanel.innerHTML = '<div class="pc-office-category-head"><span>Lignes de devis</span><strong>'+escapeHtml(state.project.titre||state.project.code||'Chantier')+'</strong></div>'+state.lines.map(function (line) {
                    return '<div class="pc-office-category '+(Number(line.id)===selectedLineId?'is-active':'')+'" data-select-financial-line="'+line.id+'" role="button" tabindex="0"><input type="checkbox" data-bulk-line="'+line.id+'" aria-label="Sélectionner cette ligne"><i class="fas fa-folder"></i><span><strong>'+escapeHtml(line.designation||('Ligne #'+line.id))+'</strong><small>'+line.debourses.length+' déboursé(s) · '+Number(line.meeting_completed_count||0)+'/'+Number(line.meeting_point_count||0)+' tâche(s) · '+percent(line.progress)+'</small></span><span class="pc-tree-actions"><a href="request/meeting_gantt_pdf.php?preparation_id='+preparationId+'&line_id='+line.id+'" target="_blank" title="Exporter le Gantt PDF" aria-label="Exporter le Gantt PDF"><i class="fas fa-file-pdf"></i></a><button type="button" data-edit-meeting-line="'+line.id+'" data-label="'+escapeHtml(line.designation||'')+'" title="Modifier le libellé uniquement pour cette réunion"><i class="fas fa-pen"></i></button><button type="button" data-add-site-line-task="'+line.id+'" title="Ajouter un point de réunion à cette ligne"><i class="fas fa-plus"></i></button><button type="button" class="is-danger" data-hide-meeting-line="'+line.id+'" title="Retirer cette ligne des réunions chantier"><i class="fas fa-xmark"></i></button></span></div>';
                }).join('');
            }
            renderDetail();
            bind();
        }
        function renderDetail() {
            var line = state.lines.find(function (item) { return Number(item.id) === selectedLineId; });
            if (!line) return;
            if(isOfficeMeeting)renderOfficeDetail(line);
            else renderSiteLineDetail(line);
            return;
            var debourses = line.debourses.map(function (debourse) {
                var details = debourse.details.map(function (detail) {
                    return '<label class="pc-cost-detail"><input type="checkbox" data-toggle-type="detail" data-toggle-id="' + detail.id + '" ' + (detail.planning_completed_at ? 'checked' : '') + '><span><strong>' + escapeHtml(detail.designation || ('Sous-ligne #' + detail.id)) + '</strong><small>' + escapeHtml(detail.categorie || '') + '</small></span><b>' + money(detail.montant) + '</b></label>';
                }).join('');
                if (!details) details = '<div class="pc-pilot-note">Aucune sous-ligne : le déboursé se termine directement avec sa case principale.</div>';
                var meetingPoints = '';
                if (Number(state.project.is_office || 0) === 1) {
                    meetingPoints = '<section class="pc-office-points"><div class="pc-office-points-head"><div><strong>Points de réunion</strong><span>Suivi opérationnel de cette section</span></div><b>' + (debourse.meeting_points || []).length + ' point(s)</b></div>' +
                        (debourse.meeting_points || []).map(function (point) {
                            return '<form class="pc-office-point" data-save-action-point data-id="' + point.id + '" data-debourse-id="' + debourse.id + '">' +
                                '<label>Section<input name="section_title" value="' + escapeHtml(point.section_title || '') + '" placeholder="Ex. Site web"></label>' +
                                '<label class="is-wide">Tâche<textarea name="task_title" required>' + escapeHtml(point.task_title || '') + '</textarea></label>' +
                                '<label>Pilote<select name="pilot_user_id" required>' + participantOptions(point.pilot_user_id,false) + '</select></label>' +
                                '<label>Copilote<select name="copilot_user_id">' + participantOptions(point.copilot_user_id,true) + '</select></label>' +
                                '<label>Ancien délai<input type="date" name="old_deadline" value="' + escapeHtml(point.old_deadline || '') + '"></label>' +
                                '<label>Nouveau délai<input type="date" name="new_deadline" value="' + escapeHtml(point.new_deadline || '') + '"></label>' +
                                '<label>Avancement calculé<input type="hidden" name="progress" value="' + Number(point.progress || 0) + '"><strong>' + percent(point.progress) + '</strong></label>' +
                                '<button><i class="fas fa-save"></i> Mettre à jour</button></form>';
                        }).join('') +
                        '<form class="pc-office-point is-new" data-save-action-point data-id="0" data-debourse-id="' + debourse.id + '">' +
                            '<label>Section<input name="section_title" placeholder="Ex. Licences et abonnements"></label>' +
                            '<label class="is-wide">Nouveau point<textarea name="task_title" required placeholder="Tâche, sujet ou action à suivre…"></textarea></label>' +
                            '<label>Pilote<select name="pilot_user_id" required>' + participantOptions(0,false) + '</select></label><label>Copilote<select name="copilot_user_id">' + participantOptions(0,true) + '</select></label>' +
                            '<label>Ancien délai<input type="date" name="old_deadline"></label><label>Nouveau délai<input type="date" name="new_deadline"></label>' +
                            '<input type="hidden" name="progress" value="0">' +
                            '<button><i class="fas fa-plus"></i> Ajouter</button></form></section>';
                }
                return '<details class="pc-debourse" ' + (debourse.progress > 0 && debourse.progress < 100 ? 'open' : '') + '><summary>' +
                    '<input type="checkbox" data-toggle-type="debourse" data-toggle-id="' + debourse.id + '" ' + (debourse.completed ? 'checked' : '') + ' aria-label="Terminer ce déboursé">' +
                    '<span><strong>' + escapeHtml(debourse.title || 'Déboursé sans libellé') + '</strong><small>' + money(debourse.montant_debourse) + '</small></span><span class="pc-debourse-progress"><b>' + percent(debourse.progress) + '</b>' + progressBar(debourse.progress) + '</span><i class="fas fa-chevron-down"></i></summary>' +
                    '<div class="pc-debourse-body"><form class="pc-inline-dates" data-save-dates="debourse_dates" data-id="' + debourse.id + '"><label>Début<input type="date" name="start_date" value="' + escapeHtml(debourse.date_debut || '') + '" required></label><label>Fin<input type="date" name="end_date" value="' + escapeHtml(debourse.date_fin || '') + '" required></label><button>Mettre à jour</button></form>' +
                    '<form class="pc-debourse-comment" data-save-comment data-id="' + debourse.id + '"><label><strong>Commentaire de réunion</strong><span>Difficulté, décision ou information utile sur ce déboursé</span><textarea name="comment" maxlength="5000" placeholder="Ajouter un commentaire…">' + escapeHtml(debourse.planning_comment || '') + '</textarea></label><button type="submit"><i class="fas fa-save"></i> Enregistrer</button></form>' +
                    meetingPoints + '<div class="pc-cost-details">' + details + '</div></div></details>';
            }).join('');
            if (!debourses) debourses = '<div class="pc-pilot-empty"><i class="fas fa-wallet"></i><strong>Aucun déboursé rattaché à cette ligne.</strong></div>';
            var performance='';
            if(Number(state.project.is_office||0)===1){
                performance='<section class="pc-staff-performance"><div class="pc-office-points-head"><div><strong>Performance du service</strong><span>Note calculée selon l’avancement et le respect des échéances</span></div></div><div class="pc-performance-badges">'+
                    ((state.staff_performance||[]).length?(state.staff_performance||[]).map(function(item){
                        return '<div><strong>'+escapeHtml(item.participant_name||'Collaborateur')+'</strong>'+stars(item.stars)+'<b>'+Number(item.score||0).toFixed(1)+'/100</b><small>'+item.tasks_count+' tâche(s)</small></div>';
                    }).join(''):'<p>Aucune performance calculable tant qu’aucune tâche n’est affectée.</p>')+'</div></section>';
            }
            detailPanel.innerHTML = '<header class="pc-line-detail-head"><div><p>Ligne de devis</p><h3>' + escapeHtml(line.designation || '') + '</h3><span>' + money(line.total) + '</span></div><div class="pc-line-progress"><strong>' + percent(line.progress) + '</strong>' + progressBar(line.progress) + '</div></header>' +
                '<form class="pc-line-planning" data-save-dates="line" data-id="' + line.id + '"><div><strong>Planning contractuel de la ligne</strong><small>Base d’appréciation vendue au client</small></div><label>Début<input type="date" name="start_date" value="' + escapeHtml(line.planning_date_debut || '') + '" required></label><label>Fin<input type="date" name="end_date" value="' + escapeHtml(line.planning_date_fin || '') + '" required></label><button>Enregistrer</button></form>'+performance+'<div class="pc-debourse-list">' + debourses + '</div>';
        }
        function officePointForm(point,debourse,detail,isNew){
            point=point||{};
            var checklist=(point.checklist||[]).map(function(item){
                var itemProgress=item.completed_at?100:Number(item.progress_percent||0);
                return '<label class="pc-checklist-item" draggable="true" data-checklist-draggable="standard" data-point-id="'+Number(point.id||0)+'" data-item-id="'+item.id+'"><i class="fas fa-grip-vertical pc-checklist-grip" title="Glisser pour réordonner"></i><span class="pc-bulk-choice"><input type="checkbox" data-bulk-checklist="'+item.id+'"><span></span></span><input type="checkbox" data-toggle-checklist="'+item.id+'" data-checklist-point="'+Number(point.id||0)+'" data-checklist-debourse="'+debourse.id+'" '+(item.completed_at?'checked':'')+'><span>'+escapeHtml(item.label||'Sous-tâche')+(itemProgress>0?' <b>('+itemProgress.toFixed(0)+'%)</b>':'')+'</span><span class="pc-checklist-actions"><button type="button" data-checklist-progress="'+item.id+'" data-checklist-point="'+Number(point.id||0)+'" data-checklist-debourse="'+debourse.id+'" data-progress="'+itemProgress+'" title="Indiquer une sous-tâche en cours"><i class="fas fa-hourglass-half"></i></button><button type="button" data-tree-rename data-scope="checklist" data-id="'+item.id+'" data-label="'+escapeHtml(item.label||'Sous-tâche')+'" title="Renommer"><i class="fas fa-pen"></i></button><button type="button" class="is-danger" data-tree-archive data-scope="checklist" data-id="'+item.id+'" title="Archiver"><i class="fas fa-xmark"></i></button></span></label>';
            }).join('');
            if(!isNew){
                return '<form class="pc-office-task-row" data-save-action-point data-point-draggable="standard" data-id="'+Number(point.id||0)+'" data-debourse-id="'+debourse.id+'" data-detail-id="'+detail.id+'">'+
                    '<label class="pc-sheet-check" title="'+(checklist?'Cocher ou décocher toute la checklist':'Ajoutez une checklist pour calculer l’avancement')+'"><input type="checkbox" data-toggle-meeting-scope="point" data-toggle-meeting-id="'+Number(point.id||0)+'" data-toggle-meeting-debourse="'+debourse.id+'" '+(Number(point.progress||0)>=100?'checked':'')+' '+(!checklist?'disabled':'')+'></label>'+
                    '<div class="pc-sheet-task"><input type="hidden" name="section_title" value="'+escapeHtml(detail.designation||'')+'"><textarea name="task_title" required aria-label="Tâche">'+escapeHtml(point.task_title||'')+'</textarea><div class="pc-task-checklist">'+checklist+'<div class="pc-checklist-quick" data-checklist-quick="'+Number(point.id||0)+'" hidden><input type="text" maxlength="500" placeholder="Nouvelle sous-tâche + Entrée"><button type="button" data-cancel-checklist title="Annuler la saisie"><i class="fas fa-xmark"></i></button></div></div></div>'+
                    '<label class="pc-sheet-cell"><span>Pilote</span><select name="pilot_user_id" required>'+participantOptions(point.pilot_user_id,false)+'</select></label>'+
                    '<label class="pc-sheet-cell"><span>Copilote</span><select name="copilot_user_id">'+participantOptions(point.copilot_user_id,true)+'</select></label>'+
                    '<label class="pc-sheet-cell"><span>'+(isOfficeMeeting?'Ancien délai':'Date début')+'</span><input type="date" name="old_deadline" value="'+escapeHtml(point.old_deadline||'')+'"></label>'+
                    '<label class="pc-sheet-cell"><span>'+(isOfficeMeeting?'Nouveau délai':'Date fin')+'</span><input type="date" name="new_deadline" value="'+escapeHtml(point.new_deadline||'')+'"></label>'+
                    '<label class="pc-sheet-progress" title="Avancement calculé depuis la checklist"><input type="hidden" name="progress" value="'+Number(point.progress||0)+'"><span class="pc-progress-input"><b>'+Number(point.progress||0).toFixed(0)+'</b><em>%</em></span><span class="pc-progress-track"><i style="width:'+Math.max(0,Math.min(100,Number(point.progress||0)))+'%;background:'+progressColor(point.progress)+'"></i></span></label>'+
                    '<div class="pc-sheet-actions"><button type="button" class="pc-point-drag-handle" draggable="true" data-point-drag-handle title="Glisser pour déplacer le point"><i class="fas fa-grip-vertical"></i></button><a href="request/meeting_gantt_pdf.php?preparation_id='+preparationId+'&point_id='+Number(point.id||0)+'" target="_blank" title="Exporter le Gantt de ce point"><i class="fas fa-file-pdf"></i></a><span class="pc-task-save-state" data-task-save-state></span><button type="button" data-open-checklist="'+Number(point.id||0)+'" title="Ajouter une sous-tâche"><i class="fas fa-plus"></i></button><button type="button" class="is-danger" data-tree-archive data-scope="task" data-id="'+Number(point.id||0)+'" title="Archiver la tâche et sa checklist"><i class="fas fa-xmark"></i></button></div></form>';
            }
            return '<form class="pc-office-point is-new" data-new-point-form="'+detail.id+'" hidden data-save-action-point data-id="0" data-debourse-id="'+debourse.id+'" data-detail-id="'+detail.id+'">'+
                '<input type="hidden" name="section_title" value="'+escapeHtml(detail.designation||'')+'">'+
                '<label class="is-wide">Nouvelle tâche<textarea name="task_title" required placeholder="Action, difficulté ou résultat à suivre…"></textarea></label>'+
                '<label>Pilote<select name="pilot_user_id" required>'+participantOptions(0,false)+'</select></label>'+
                '<label>Copilote<select name="copilot_user_id">'+participantOptions(0,true)+'</select></label>'+
                '<label>'+(isOfficeMeeting?'Ancien délai':'Date début')+'<input type="date" name="old_deadline"></label>'+
                '<label>'+(isOfficeMeeting?'Nouveau délai':'Date fin')+'<input type="date" name="new_deadline"></label>'+
                '<input type="hidden" name="progress" value="0">'+
                '<div class="pc-new-point-actions"><button type="submit"><i class="fas fa-check"></i> Valider l’ajout</button><button type="reset" class="is-secondary"><i class="fas fa-eraser"></i> Vider</button><button type="button" class="is-cancel" data-cancel-new-point><i class="fas fa-xmark"></i> Annuler</button></div></form>';
        }
        function customSectionMetrics(section){
            var tasks=[];
            try{tasks=JSON.parse(section.payload_json||'[]');}catch(error){}
            if(!Array.isArray(tasks))tasks=[];
            var completed=tasks.filter(function(task){return Number(task.progress||0)>=100;}).length;
            var progress=tasks.length?Math.round(tasks.reduce(function(total,task){return total+Number(task.progress||0);},0)/tasks.length):0;
            return {tasks:tasks,total:tasks.length,completed:completed,progress:progress};
        }
        function customSectionHtml(section){
            var metrics=customSectionMetrics(section);
            var tasks=metrics.tasks;
            var taskHtml=tasks.map(function(task){
                var checklist=(task.checklist||[]).map(function(item){
                    var itemProgress=item.completed?100:Number(item.progress||0);
                    return '<label class="pc-checklist-item" draggable="true" data-checklist-draggable="custom" data-section-id="'+section.id+'" data-task-id="'+task.id+'" data-item-id="'+item.id+'"><i class="fas fa-grip-vertical pc-checklist-grip" title="Glisser pour réordonner"></i><input type="checkbox" data-custom-checklist-toggle data-section-id="'+section.id+'" data-task-id="'+task.id+'" data-item-id="'+item.id+'" '+(item.completed?'checked':'')+'><span>'+escapeHtml(item.label||'Sous-tâche')+(itemProgress>0?' <b>('+itemProgress.toFixed(0)+'%)</b>':'')+'</span><span class="pc-checklist-actions"><button type="button" data-custom-checklist-progress data-section-id="'+section.id+'" data-task-id="'+task.id+'" data-item-id="'+item.id+'" data-progress="'+itemProgress+'" title="Indiquer une sous-tâche en cours"><i class="fas fa-hourglass-half"></i></button><button type="button" data-custom-checklist-rename data-section-id="'+section.id+'" data-task-id="'+task.id+'" data-item-id="'+item.id+'" data-label="'+escapeHtml(item.label||'Sous-tâche')+'" title="Modifier la sous-tâche"><i class="fas fa-pen"></i></button><button type="button" class="is-danger" data-custom-checklist-archive data-section-id="'+section.id+'" data-task-id="'+task.id+'" data-item-id="'+item.id+'" title="Retirer la sous-tâche"><i class="fas fa-xmark"></i></button></span></label>';
                }).join('');
                return '<form class="pc-office-task-row pc-custom-task" data-custom-task data-point-draggable="custom" data-section-id="'+section.id+'" data-task-id="'+task.id+'">'+
                    '<label class="pc-sheet-check" title="'+(checklist?'Cocher ou décocher toute la checklist':'Ajoutez une checklist pour calculer l’avancement')+'"><input type="checkbox" name="completed" '+(Number(task.progress||0)>=100?'checked':'')+' '+(!checklist?'disabled':'')+'></label>'+
                    '<div class="pc-sheet-task"><textarea name="title" required aria-label="Tâche">'+escapeHtml(task.title||'')+'</textarea><div class="pc-task-checklist">'+checklist+'<div class="pc-checklist-quick" data-custom-checklist-quick hidden><input name="checklist_label" maxlength="500" placeholder="Nouvelle sous-tâche + Entrée"><button type="button" data-cancel-custom-checklist title="Annuler"><i class="fas fa-xmark"></i></button></div></div></div>'+
                    '<label class="pc-sheet-cell"><span>Responsable</span><select name="pilot_user_id">'+participantOptions(task.pilot_user_id,true)+'</select></label>'+
                    '<label class="pc-sheet-cell"><span>Copilote</span><select name="copilot_user_id">'+participantOptions(task.copilot_user_id,true)+'</select></label>'+
                    '<label class="pc-sheet-cell"><span>Date début</span><input type="date" name="old_deadline" value="'+escapeHtml(task.old_deadline||'')+'"></label>'+
                    '<label class="pc-sheet-cell"><span>Date fin</span><input type="date" name="new_deadline" value="'+escapeHtml(task.new_deadline||'')+'"></label>'+
                    '<label class="pc-sheet-progress" title="Avancement calculé depuis la checklist"><input type="hidden" name="progress" value="'+Number(task.progress||0)+'"><span class="pc-progress-input"><b>'+Number(task.progress||0).toFixed(0)+'</b><em>%</em></span><span class="pc-progress-track"><i style="width:'+Math.max(0,Math.min(100,Number(task.progress||0)))+'%;background:'+progressColor(task.progress)+'"></i></span></label>'+
                    '<div class="pc-sheet-actions"><button type="button" class="pc-point-drag-handle" draggable="true" data-point-drag-handle title="Glisser pour déplacer le point"><i class="fas fa-grip-vertical"></i></button><span data-custom-save-state></span><button type="button" data-open-custom-checklist title="Ajouter une sous-tâche"><i class="fas fa-plus"></i></button><button type="button" class="is-danger" data-custom-task-archive title="Retirer la tâche"><i class="fas fa-xmark"></i></button></div></form>';
            }).join('');
            var progress=metrics.progress;
            var attachmentItems=(section.attachments||[]).map(function(file){
                var kind=fileIcon(file);
                return '<div><a href="request/meeting_custom_attachment.php?id='+file.id+'" target="_blank" title="Ouvrir le fichier '+escapeHtml(kind.label)+'"><i class="fas '+kind.icon+' pc-file-type-icon '+kind.type+'"></i><span>'+escapeHtml(file.original_name)+'</span><small>'+fileSize(file.file_size)+'</small></a><button type="button" class="is-danger" data-archive-custom-attachment="'+file.id+'" data-section-id="'+section.id+'" title="Supprimer la pièce jointe"><i class="fas fa-xmark"></i></button></div>';
            }).join('');
            var files='<details class="pc-subcategory-files pc-custom-point-files"><summary><i class="fas fa-paperclip"></i> '+(section.attachments||[]).length+'</summary><div class="pc-subcategory-file-list">'+attachmentItems+'<label class="pc-file-add"><i class="fas fa-plus"></i> Ajouter des fichiers<input type="file" multiple data-upload-custom-attachments="'+section.id+'" hidden></label></div></details>';
            var pointForm=Number(section.id)===Number(openCustomPointId)?'<form class="pc-office-point pc-custom-task is-new" data-custom-point-form data-section-id="'+section.id+'"><label class="is-wide">Nom du point<input name="title" required autofocus value="'+escapeHtml(section.title||'')+'" placeholder="Ex. Approvisionnement GNT"></label><label>Responsable<select name="responsible_user_id">'+participantOptions(section.responsible_user_id,true)+'</select></label><label>Date de début<input type="date" name="start_date" value="'+escapeHtml(section.start_date||'')+'"></label><label>Date de fin<input type="date" name="end_date" value="'+escapeHtml(section.end_date||'')+'"></label><div class="pc-new-point-actions"><button type="submit"><i class="fas fa-check"></i> Enregistrer le point</button><button type="button" class="is-cancel" data-cancel-custom-point><i class="fas fa-xmark"></i> Annuler</button></div></form>':'';
            var newForm=Number(section.id)===Number(openCustomSectionId)?'<form class="pc-office-point pc-custom-task is-new" data-custom-new-task data-section-id="'+section.id+'"><label class="is-wide">Nouvelle tâche<textarea name="title" required autofocus placeholder="Action, difficulté ou résultat à suivre…"></textarea></label><label>Responsable<select name="pilot_user_id">'+participantOptions(0,true)+'</select></label><label>Copilote<select name="copilot_user_id">'+participantOptions(0,true)+'</select></label><label>Date début<input type="date" name="old_deadline"></label><label>Date fin<input type="date" name="new_deadline"></label><input type="hidden" name="progress" value="0"><div class="pc-new-point-actions"><button type="submit"><i class="fas fa-check"></i> Enregistrer la tâche</button><button type="button" class="is-cancel" data-cancel-custom-new><i class="fas fa-xmark"></i> Annuler</button></div></form>':'';
            var pointMeta=[participantName(section.responsible_user_id),section.start_date&&section.end_date?(section.start_date+' → '+section.end_date):''].filter(Boolean).join(' · ');
            return '<article class="pc-custom-meeting-section pc-office-custom-section"><header class="pc-line-detail-head pc-office-category-title pc-custom-point-head"><div><p>Point du chantier</p><h3>'+escapeHtml(section.title||'Point libre')+'</h3><span>'+metrics.total+' tâche(s)'+(pointMeta?' · '+escapeHtml(pointMeta):'')+'</span></div><div class="pc-custom-point-status"><div class="pc-line-progress"><strong>'+percent(progress)+'</strong>'+progressBar(progress)+'</div><span class="pc-office-summary-actions"><button type="button" data-edit-custom-point="'+section.id+'" title="Modifier le point"><i class="fas fa-pen"></i></button><button type="button" data-add-custom-task="'+section.id+'" title="Ajouter une tâche"><i class="fas fa-plus"></i></button><button type="button" class="is-danger" data-archive-custom-section="'+section.id+'" title="Retirer ce point"><i class="fas fa-xmark"></i></button></span></div></header>'+files+'<div class="pc-custom-task-list">'+pointForm+(tasks.length?'<div class="pc-sheet-columns"><span></span><span>Tâche / checklist</span><span>Pilote</span><span>Copilote</span><span>Date début</span><span>Date fin</span><span>Av.</span><span></span></div>':'')+taskHtml+newForm+'</div></article>';
        }
        function renderSiteLineDetail(line){
            var customSections=(state.meeting_sections||[]).filter(function(section){
                return Number(section.ligne_devis_id||0)===0||Number(section.ligne_devis_id)===Number(line.id);
            }).map(customSectionHtml).join('');
            var debourses=line.debourses.map(function(debourse,index){
                var details=debourse.details.map(function(detail,detailIndex){
                    var points=detail.meeting_points||[];
                    var attachments=(detail.attachments||[]).map(function(file){
                        var kind=fileIcon(file);
                        return '<div><a href="request/office_attachment.php?id='+file.id+'" target="_blank" title="Ouvrir le fichier '+escapeHtml(kind.label)+'"><i class="fas '+kind.icon+' pc-file-type-icon '+kind.type+'"></i><span>'+escapeHtml(file.original_name)+'</span><small>'+fileSize(file.file_size)+'</small></a><button type="button" class="is-danger" data-archive-attachment="'+file.id+'" data-detail-id="'+detail.id+'" title="Archiver la pièce jointe"><i class="fas fa-xmark"></i></button></div>';
                    }).join('');
                    var files='<details class="pc-subcategory-files"><summary><i class="fas fa-paperclip"></i> '+(detail.attachments||[]).length+' pièce(s) jointe(s)</summary><div class="pc-subcategory-file-list">'+(attachments||'<small>Aucune pièce jointe.</small>')+'<label class="pc-file-add"><i class="fas fa-plus"></i> Joindre des fichiers<input type="file" multiple data-upload-attachments="'+detail.id+'" hidden></label></div></details>';
                    return '<details class="pc-office-subcategory" data-office-detail-id="'+detail.id+'" '+(detailIndex===0?'open':'')+'><summary><div><input type="checkbox" data-toggle-meeting-scope="detail" data-toggle-meeting-id="'+detail.id+'" data-toggle-meeting-debourse="'+debourse.id+'" '+(detail.meeting_completed?'checked':'')+' '+(Number(detail.meeting_point_count||0)===0?'disabled':'')+'><i class="fas fa-layer-group"></i><span><strong>'+escapeHtml(detail.designation||'Ligne de déboursé')+'</strong><small>'+Number(detail.meeting_completed_count||0)+'/'+Number(detail.meeting_point_count||0)+' tâche(s) terminée(s) · '+percent(detail.meeting_progress)+'</small></span></div><div class="pc-office-summary-actions"><button type="button" data-open-new-point="'+detail.id+'" title="Ajouter une tâche"><i class="fas fa-plus"></i></button><i class="fas fa-chevron-down"></i></div></summary>'+files+'<div class="pc-office-subcategory-body">'+
                        (points.length?'<div class="pc-sheet-columns"><span></span><span>Tâche / checklist</span><span>Pilote</span><span>Copilote</span><span>Date début</span><span>Date fin</span><span>Av.</span><span></span></div>':'')+
                        (points.length?points.map(function(point){return officePointForm(point,debourse,detail,false);}).join(''):'')+
                        officePointForm({},debourse,detail,true)+'</div></details>';
                }).join('');
                if(!details)details='<div class="pc-pilot-note"><i class="fas fa-circle-info"></i> Ce déboursé ne possède aucune ligne. Il reste visible afin de pouvoir être conservé ou retiré de la réunion.</div>';
                return '<article class="pc-site-debourse-card" data-site-debourse-card="'+debourse.id+'"><header class="pc-site-debourse-head"><label class="pc-bulk-choice"><input type="checkbox" data-bulk-debourse="'+debourse.id+'"><span></span></label><span><small>Déboursé</small><strong>'+escapeHtml(debourse.title||'Déboursé sans libellé')+'</strong><em>'+debourse.details.length+' ligne(s) · '+Number(debourse.meeting_point_count||0)+' tâche(s)</em></span><b>'+percent(debourse.progress)+'</b><button type="button" class="pc-hide-meeting-debourse" data-hide-meeting-debourse="'+debourse.id+'" title="Retirer ce déboursé des réunions chantier"><i class="fas fa-xmark"></i></button></header><div class="pc-office-subcategories">'+details+'</div></article>';
            }).join('');
            detailPanel.innerHTML='<header class="pc-line-detail-head pc-site-selected-line"><div><p>Ligne de devis sélectionnée</p><h3>'+escapeHtml(line.designation||'Ligne de devis')+'</h3><span>'+line.debourses.length+' déboursé(s) affiché(s)</span></div><div class="pc-line-progress"><strong>'+percent(line.progress)+'</strong>'+progressBar(line.progress)+'</div></header>'+
                ((customSections||debourses)?'<div class="pc-site-debourse-list">'+customSections+debourses+'</div>':'<div class="pc-pilot-empty"><i class="fas fa-eye-slash"></i><strong>Aucun point affiché pour cette ligne.</strong></div>');
        }
        function renderOfficeDetail(line){
            var debourse=line.debourses.find(function(item){return Number(item.id)===selectedDebourseId;});
            if(!debourse){
                detailPanel.innerHTML='<div class="pc-pilot-empty"><i class="fas fa-folder-plus"></i><strong>Créez la première catégorie du service.</strong><span>Le formulaire rapide se trouve dans la colonne de gauche.</span></div>';
                return;
            }
            if(!debourse.details.some(function(detail){return Number(detail.id)===selectedOfficeDetailId;})){
                selectedOfficeDetailId=debourse.details.length?Number(debourse.details[0].id):0;
            }
            var details=debourse.details.map(function(detail){
                var points=detail.meeting_points||[];
                var attachments=(detail.attachments||[]).map(function(file){var kind=fileIcon(file);return '<div><a href="request/office_attachment.php?id='+file.id+'" target="_blank" title="Ouvrir le fichier '+escapeHtml(kind.label)+'"><i class="fas '+kind.icon+' pc-file-type-icon '+kind.type+'" aria-label="'+escapeHtml(kind.label)+'"></i><span>'+escapeHtml(file.original_name)+'</span><small>'+fileSize(file.file_size)+'</small></a><button type="button" class="is-danger" data-archive-attachment="'+file.id+'" data-detail-id="'+detail.id+'" title="Archiver la pièce jointe"><i class="fas fa-xmark"></i></button></div>';}).join('');
                var files='<details class="pc-subcategory-files"><summary><i class="fas fa-paperclip"></i> '+(detail.attachments||[]).length+' pièce(s) jointe(s)</summary><div class="pc-subcategory-file-list">'+(attachments||'<small>Aucune pièce jointe.</small>')+'<label class="pc-file-add"><i class="fas fa-plus"></i> Joindre des fichiers<input type="file" multiple data-upload-attachments="'+detail.id+'" hidden></label></div></details>';
                return '<details class="pc-office-subcategory" data-office-detail-id="'+detail.id+'" '+(Number(detail.id)===selectedOfficeDetailId?'open':'')+'><summary><div><input type="checkbox" data-toggle-meeting-scope="detail" data-toggle-meeting-id="'+detail.id+'" data-toggle-meeting-debourse="'+debourse.id+'" '+(detail.meeting_completed?'checked':'')+' '+(Number(detail.meeting_point_count||0)===0?'disabled':'')+' aria-label="Terminer toutes les tâches de cette sous-catégorie"><i class="fas fa-layer-group"></i><span><strong>'+escapeHtml(detail.designation||'Sous-catégorie')+'</strong><small>'+Number(detail.meeting_completed_count||0)+'/'+Number(detail.meeting_point_count||0)+' tâche(s) terminée(s) · '+percent(detail.meeting_progress)+'</small></span></div><div class="pc-office-summary-actions"><i class="fas fa-lock" title="Référence financière en lecture seule"></i><button type="button" data-open-new-point="'+detail.id+'" title="Ajouter une tâche" aria-label="Ajouter une tâche à '+escapeHtml(detail.designation||'cette sous-catégorie')+'"><i class="fas fa-plus"></i></button><i class="fas fa-chevron-down"></i></div></summary>'+files+'<div class="pc-office-subcategory-body">'+
                    (points.length?'<div class="pc-sheet-columns"><span></span><span>Tâche / checklist</span><span>Pilote</span><span>Copilote</span><span>Ancien délai</span><span>Nouveau délai</span><span>Av.</span><span></span></div>':'')+
                    (points.length?points.map(function(point){return officePointForm(point,debourse,detail,false);}).join(''):'<p class="pc-pilot-note">Aucune tâche pour cette sous-catégorie.</p>')+
                    officePointForm({},debourse,detail,true)+'</div></details>';
            }).join('');
            detailPanel.innerHTML='<header class="pc-line-detail-head pc-office-category-title"><div><p>'+(isOfficeMeeting?'Catégorie du service':'Point du chantier')+'</p><h3>'+escapeHtml(debourse.title||'Catégorie')+'</h3><span>'+debourse.details.length+' sous-catégorie(s)</span></div><div class="pc-line-progress"><strong>'+percent(debourse.progress)+'</strong>'+progressBar(debourse.progress)+'</div></header>'+
                '<div class="pc-office-subcategories">'+details+'</div>';
        }
        function officeTaskPayload(form) {
            return {
                csrf:csrf,preparation_id:preparationId,id:Number(form.dataset.id),
                debourse_id:Number(form.dataset.debourseId),
                ligne_debourse_id:Number(form.dataset.detailId),
                section_title:form.elements.section_title.value,
                task_title:form.elements.task_title.value,
                pilot_user_id:form.elements.pilot_user_id.value,
                copilot_user_id:form.elements.copilot_user_id.value,
                old_deadline:form.elements.old_deadline.value,
                new_deadline:form.elements.new_deadline.value,
                progress:form.elements.progress.value
            };
        }
        function queueOfficeTaskSave(form,delay,modifiedField) {
            var id=Number(form.dataset.id||0);
            if(id<=0)return;
            clearTimeout(taskSaveTimers[id]);
            var status=form.querySelector('[data-task-save-state]');
            if(status){
                status.className='pc-task-save-state is-pending';
                status.innerHTML='<i class="fas fa-cloud-arrow-up"></i>';
                status.title='Modification en attente d’enregistrement';
            }
            taskSaveTimers[id]=setTimeout(function(){
                var payload=officeTaskPayload(form);
                var previous=taskSaveQueues[id]||Promise.resolve();
                taskSaveQueues[id]=previous.catch(function(){}).then(async function(){
                    if(status&&status.isConnected){
                        status.className='pc-task-save-state is-saving';
                        status.innerHTML='<i class="fas fa-circle-notch fa-spin"></i>';
                        status.title='Enregistrement en cours';
                    }
                    try{
                        var response=await fetch('request/meeting_action_point.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(payload)});
                        var json=await response.json();
                        if(!response.ok||!json.ok)throw new Error(json.error||'Enregistrement impossible.');
                        applyOfficeGroups(json.groups||{},json.performance||[]);
                        renderOfficePerformance();
                        if(status&&status.isConnected){
                            status.className='pc-task-save-state is-saved';
                            status.innerHTML='';
                            status.title='';
                        }
                        showSavedNear(modifiedField);
                    }catch(error){
                        if(status&&status.isConnected){
                            status.className='pc-task-save-state is-error';
                            status.innerHTML='<i class="fas fa-cloud-exclamation"></i>';
                            status.title='Échec de l’enregistrement automatique';
                        }
                        notify(error.message||'Enregistrement automatique impossible.',true);
                    }
                });
            },delay);
        }
        function bind() {
            async function customAction(payload){
                payload.csrf=csrf;payload.preparation_id=preparationId;
                var response=await fetch('request/meeting_custom_section.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(payload)});
                var json=await response.json();
                if(!response.ok||!json.ok)throw new Error(json.error||'Enregistrement impossible.');
                return json;
            }
            pilot.querySelectorAll('[data-add-custom-task]').forEach(function(button){
                button.addEventListener('click',function(){
                    openCustomPointId=0;
                    openCustomSectionId=Number(button.dataset.addCustomTask);
                    render();
                    var form=pilot.querySelector('[data-custom-new-task][data-section-id="'+openCustomSectionId+'"]');
                    if(form){form.querySelector('textarea[name="title"]').focus();form.scrollIntoView({behavior:'smooth',block:'center'});}
                });
            });
            pilot.querySelectorAll('[data-edit-custom-point]').forEach(function(button){
                button.addEventListener('click',function(){
                    openCustomSectionId=0;
                    openCustomPointId=Number(button.dataset.editCustomPoint);
                    render();
                    var form=pilot.querySelector('[data-custom-point-form][data-section-id="'+openCustomPointId+'"]');
                    if(form){form.elements.title.focus();form.scrollIntoView({behavior:'smooth',block:'center'});}
                });
            });
            pilot.querySelectorAll('[data-custom-point-form]').forEach(function(form){
                form.addEventListener('submit',async function(event){
                    event.preventDefault();
                    var successAt=savedAnchor(event.submitter||form);
                    await customAction({action:'point_save',section_id:Number(form.dataset.sectionId),title:form.elements.title.value,responsible_user_id:form.elements.responsible_user_id.value,start_date:form.elements.start_date.value,end_date:form.elements.end_date.value});
                    openCustomPointId=0;
                    await request({chantier_id:Number(state.project.id)},'GET');
                    showSavedNear(successAt);
                });
                form.querySelector('[data-cancel-custom-point]').addEventListener('click',function(){openCustomPointId=0;render();});
            });
            pilot.querySelectorAll('[data-custom-new-task]').forEach(function(form){
                form.addEventListener('submit',async function(event){
                    event.preventDefault();
                    var successAt=savedAnchor(event.submitter||form);
                    var submit=form.querySelector('button[type="submit"]');submit.disabled=true;
                    try{
                        await customAction({action:'task_save',section_id:Number(form.dataset.sectionId),title:form.elements.title.value,pilot_user_id:form.elements.pilot_user_id.value,copilot_user_id:form.elements.copilot_user_id.value,old_deadline:form.elements.old_deadline.value,new_deadline:form.elements.new_deadline.value,progress:form.elements.progress.value});
                        openCustomSectionId=0;
                        await request({chantier_id:Number(state.project.id)},'GET');
                        showSavedNear(successAt);
                    }catch(error){notify(error.message||'Enregistrement impossible.',true);}
                    finally{submit.disabled=false;}
                });
                form.querySelector('[data-cancel-custom-new]').addEventListener('click',function(){openCustomSectionId=0;render();});
            });
            pilot.querySelectorAll('[data-custom-task]').forEach(function(form){
                var timer;
                function save(forcedCompletion,modifiedField){
                    clearTimeout(timer);
                    var successAt=savedAnchor(modifiedField);
                    var status=form.querySelector('[data-custom-save-state]');
                    if(status)status.innerHTML='<i class="fas fa-circle-notch fa-spin"></i> Enregistrement';
                    timer=setTimeout(async function(){
                        try{
                            var payload={action:'task_save',section_id:Number(form.dataset.sectionId),task_id:form.dataset.taskId,title:form.elements.title.value,pilot_user_id:form.elements.pilot_user_id.value,copilot_user_id:form.elements.copilot_user_id.value,old_deadline:form.elements.old_deadline.value,new_deadline:form.elements.new_deadline.value,progress:form.elements.progress.value};
                            if(typeof forcedCompletion==='boolean')payload.completed=forcedCompletion;
                            var saved=await customAction(payload);
                            var section=(state.meeting_sections||[]).find(function(item){return Number(item.id)===Number(form.dataset.sectionId);});
                            if(section&&Array.isArray(saved.tasks))section.payload_json=JSON.stringify(saved.tasks);
                            var savedTask=(saved.tasks||[]).find(function(task){return String(task.id)===String(form.dataset.taskId);});
                            if(savedTask){
                                form.elements.progress.value=Number(savedTask.progress||0);
                                if(completed)completed.checked=Number(savedTask.progress||0)>=100;
                                form.querySelectorAll('[data-custom-checklist-toggle]').forEach(function(input){
                                    var item=(savedTask.checklist||[]).find(function(entry){return String(entry.id)===String(input.dataset.itemId);});
                                    if(item)input.checked=!!item.completed;
                                });
                                var bar=form.querySelector('.pc-progress-track i');
                                if(bar)bar.style.width=Math.max(0,Math.min(100,Number(savedTask.progress||0)))+'%';
                            }
                            if(typeof forcedCompletion==='boolean'){
                                var scrollTop=detailPanel.scrollTop;
                                recomputeMeetingProgress();render();detailPanel.scrollTop=scrollTop;
                            }
                            renderOfficePerformance();
                            if(status&&status.isConnected)status.innerHTML='';
                            showSavedNear(successAt);
                        }catch(error){if(status&&status.isConnected)status.innerHTML='<i class="fas fa-cloud-exclamation"></i> Échec';notify(error.message,true);}
                    },500);
                }
                form.querySelectorAll('textarea,select,input[type="date"],input[name="progress"]').forEach(function(field){
                    field.addEventListener('input',function(){save(undefined,field);});
                    field.addEventListener('change',function(){save(undefined,field);});
                });
                var completed=form.elements.completed;
                if(completed)completed.addEventListener('change',function(){
                    form.elements.progress.value=completed.checked?100:0;
                    form.querySelectorAll('[data-custom-checklist-toggle]').forEach(function(input){input.checked=completed.checked;});
                    save(completed.checked,completed);
                });
                var checklistQuick=form.querySelector('[data-custom-checklist-quick]');
                var checklistInput=form.elements.checklist_label;
                form.querySelector('[data-open-custom-checklist]').addEventListener('click',function(){
                    checklistQuick.hidden=false;
                    checklistInput.focus();
                });
                form.querySelector('[data-cancel-custom-checklist]').addEventListener('click',function(){
                    checklistInput.value='';
                    checklistQuick.hidden=true;
                });
                checklistInput.addEventListener('keydown',async function(event){
                    if(event.key!=='Enter')return;
                    event.preventDefault();
                    var label=checklistInput.value.trim();if(!label)return;
                    checklistInput.value='';
                    var pending=document.createElement('label');pending.className='pc-checklist-item is-pending';pending.innerHTML='<input type="checkbox" disabled><span>'+escapeHtml(label)+'</span>';
                    checklistQuick.parentNode.insertBefore(pending,checklistQuick);checklistInput.focus();
                    var successAt=savedAnchor(checklistInput);try{
                        var saved=await customAction({action:'checklist_add',section_id:Number(form.dataset.sectionId),task_id:form.dataset.taskId,label:label});
                        var section=(state.meeting_sections||[]).find(function(item){return Number(item.id)===Number(form.dataset.sectionId);});
                        if(section&&Array.isArray(saved.tasks))section.payload_json=JSON.stringify(saved.tasks);
                        var nextValue=checklistInput.value;
                        var scrollTop=detailPanel.scrollTop;render();detailPanel.scrollTop=scrollTop;
                        var next=pilot.querySelector('[data-custom-task][data-section-id="'+form.dataset.sectionId+'"][data-task-id="'+form.dataset.taskId+'"] [data-custom-checklist-quick]');
                        if(next){next.hidden=false;next.querySelector('input').value=nextValue;next.querySelector('input').focus();}
                        showSavedNear(successAt);
                    }catch(error){if(pending.parentNode)pending.remove();notify(error.message||'Ajout impossible.',true);}
                });
                form.querySelector('[data-custom-task-archive]').addEventListener('click',async function(){if(!window.confirm('Retirer cette tâche ?'))return;await customAction({action:'task_archive',section_id:Number(form.dataset.sectionId),task_id:form.dataset.taskId});await request({chantier_id:Number(state.project.id)},'GET');});
            });
            pilot.querySelectorAll('[data-custom-checklist-toggle]').forEach(function(input){input.addEventListener('change',async function(){
                var successAt=savedAnchor(input);try{
                    var saved=await customAction({action:'checklist_toggle',section_id:Number(input.dataset.sectionId),task_id:input.dataset.taskId,item_id:input.dataset.itemId,completed:input.checked});
                    var section=(state.meeting_sections||[]).find(function(item){return Number(item.id)===Number(input.dataset.sectionId);});
                    if(section&&Array.isArray(saved.tasks))section.payload_json=JSON.stringify(saved.tasks);
                    var scrollTop=detailPanel.scrollTop;render();detailPanel.scrollTop=scrollTop;
                    showSavedNear(successAt);
                }catch(error){input.checked=!input.checked;notify(error.message,true);}
            });});
            pilot.querySelectorAll('[data-custom-checklist-progress]').forEach(function(button){button.addEventListener('click',async function(){
                var answer=window.prompt('Pourcentage d’avancement de cette sous-tâche (0 à 100) :',button.dataset.progress||'0');
                if(answer===null)return;var progress=Number(String(answer).replace(',','.'));
                if(!Number.isFinite(progress)||progress<0||progress>100){notify('Saisissez un pourcentage compris entre 0 et 100.',true);return;}
                var successAt=savedAnchor(button);try{
                    var saved=await customAction({action:'checklist_progress',section_id:Number(button.dataset.sectionId),task_id:button.dataset.taskId,item_id:button.dataset.itemId,progress:progress});
                    var section=(state.meeting_sections||[]).find(function(item){return Number(item.id)===Number(button.dataset.sectionId);});
                    if(section&&Array.isArray(saved.tasks))section.payload_json=JSON.stringify(saved.tasks);
                    var scrollTop=detailPanel.scrollTop;render();detailPanel.scrollTop=scrollTop;showSavedNear(successAt);
                }catch(error){notify(error.message||'Mise à jour impossible.',true);}
            });});
            pilot.querySelectorAll('[data-custom-checklist-rename]').forEach(function(button){button.addEventListener('click',async function(){
                var label=window.prompt('Modifier la sous-tâche :',button.dataset.label||'');
                if(label===null||!label.trim())return;
                var successAt=savedAnchor(button);try{
                    var saved=await customAction({action:'checklist_rename',section_id:Number(button.dataset.sectionId),task_id:button.dataset.taskId,item_id:button.dataset.itemId,label:label.trim()});
                    var section=(state.meeting_sections||[]).find(function(item){return Number(item.id)===Number(button.dataset.sectionId);});
                    if(section&&Array.isArray(saved.tasks))section.payload_json=JSON.stringify(saved.tasks);
                    var scrollTop=detailPanel.scrollTop;render();detailPanel.scrollTop=scrollTop;
                    showSavedNear(successAt);
                }catch(error){notify(error.message||'Modification impossible.',true);}
            });});
            pilot.querySelectorAll('[data-custom-checklist-archive]').forEach(function(button){button.addEventListener('click',async function(){var successAt=savedAnchor(button);await customAction({action:'checklist_archive',section_id:Number(button.dataset.sectionId),task_id:button.dataset.taskId,item_id:button.dataset.itemId});await request({chantier_id:Number(state.project.id)},'GET');showSavedNear(successAt);});});
            var draggedChecklist=null;
            pilot.querySelectorAll('[data-checklist-draggable]').forEach(function(item){
                item.addEventListener('dragstart',function(event){
                    draggedChecklist=item;item.classList.add('is-dragging');
                    event.dataTransfer.effectAllowed='move';event.dataTransfer.setData('text/plain',item.dataset.itemId||'');
                });
                item.addEventListener('dragend',function(){item.classList.remove('is-dragging');pilot.querySelectorAll('.pc-checklist-item.is-drag-over').forEach(function(node){node.classList.remove('is-drag-over');});draggedChecklist=null;});
                item.addEventListener('dragover',function(event){
                    if(!draggedChecklist||draggedChecklist===item||draggedChecklist.dataset.checklistDraggable!==item.dataset.checklistDraggable)return;
                    if(item.dataset.checklistDraggable==='standard'&&draggedChecklist.dataset.pointId!==item.dataset.pointId)return;
                    if(item.dataset.checklistDraggable==='custom'&&(draggedChecklist.dataset.sectionId!==item.dataset.sectionId||draggedChecklist.dataset.taskId!==item.dataset.taskId))return;
                    event.preventDefault();item.classList.add('is-drag-over');
                });
                item.addEventListener('dragleave',function(){item.classList.remove('is-drag-over');});
                item.addEventListener('drop',async function(event){
                    event.preventDefault();item.classList.remove('is-drag-over');
                    if(!draggedChecklist||draggedChecklist===item)return;
                    var list=item.parentNode;var before=event.clientY<item.getBoundingClientRect().top+(item.offsetHeight/2);
                    list.insertBefore(draggedChecklist,before?item:item.nextSibling);
                    var ids=Array.from(list.querySelectorAll(':scope > [data-checklist-draggable]')).map(function(node){return node.dataset.itemId;});
                    try{
                        if(item.dataset.checklistDraggable==='custom'){
                            await customAction({action:'checklist_reorder',section_id:Number(item.dataset.sectionId),task_id:item.dataset.taskId,checklist_ids:ids});
                        }else{
                            var pointId=Number(item.dataset.pointId);var form=item.closest('[data-save-action-point]');
                            checklistRequest({action:'checklist_reorder',action_point_id:pointId,checklist_ids:ids,debourse_id:Number(form.dataset.debourseId)},'Checklist réorganisée.');
                        }
                    }catch(error){notify(error.message||'Réorganisation impossible.',true);await request({chantier_id:Number(state.project.id)},'GET');}
                });
            });
            var draggedPoint=null;
            pilot.querySelectorAll('[data-point-drag-handle]').forEach(function(handle){
                handle.addEventListener('dragstart',function(event){
                    draggedPoint=handle.closest('[data-point-draggable]');
                    if(!draggedPoint)return;
                    draggedPoint.classList.add('is-point-dragging');
                    event.dataTransfer.effectAllowed='move';
                    event.dataTransfer.setData('text/plain',draggedPoint.dataset.id||draggedPoint.dataset.taskId||'');
                });
                handle.addEventListener('dragend',function(){
                    if(draggedPoint)draggedPoint.classList.remove('is-point-dragging');
                    pilot.querySelectorAll('.is-point-drag-over').forEach(function(node){node.classList.remove('is-point-drag-over');});
                    draggedPoint=null;
                });
            });
            pilot.querySelectorAll('[data-point-draggable]').forEach(function(row){
                row.addEventListener('dragover',function(event){
                    if(!draggedPoint||draggedPoint===row||draggedPoint.dataset.pointDraggable!==row.dataset.pointDraggable)return;
                    if(row.dataset.pointDraggable==='standard'&&draggedPoint.dataset.detailId!==row.dataset.detailId)return;
                    if(row.dataset.pointDraggable==='custom'&&draggedPoint.dataset.sectionId!==row.dataset.sectionId)return;
                    event.preventDefault();row.classList.add('is-point-drag-over');
                });
                row.addEventListener('dragleave',function(){row.classList.remove('is-point-drag-over');});
                row.addEventListener('drop',async function(event){
                    event.preventDefault();row.classList.remove('is-point-drag-over');
                    if(!draggedPoint||draggedPoint===row)return;
                    var list=row.parentNode;
                    var before=event.clientY<row.getBoundingClientRect().top+(row.offsetHeight/2);
                    list.insertBefore(draggedPoint,before?row:row.nextSibling);
                    var selector=':scope > [data-point-draggable="'+row.dataset.pointDraggable+'"]';
                    var siblings=Array.from(list.querySelectorAll(selector));
                    try{
                        if(row.dataset.pointDraggable==='custom'){
                            await customAction({action:'task_reorder',section_id:Number(row.dataset.sectionId),task_ids:siblings.map(function(node){return node.dataset.taskId;})});
                        }else{
                            var response=await fetch('request/meeting_action_point.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({
                                csrf:csrf,preparation_id:preparationId,action:'point_reorder',ligne_debourse_id:Number(row.dataset.detailId),
                                point_ids:siblings.map(function(node){return Number(node.dataset.id);}),debourse_id:Number(row.dataset.debourseId)
                            })});
                            var json=await response.json();
                            if(!response.ok||!json.ok)throw new Error(json.error||'Réorganisation impossible.');
                        }
                        await request({chantier_id:Number(state.project.id)},'GET');
                        notify('Ordre des points enregistré.');
                    }catch(error){
                        notify(error.message||'Réorganisation impossible.',true);
                        await request({chantier_id:Number(state.project.id)},'GET');
                    }
                });
            });
            pilot.querySelectorAll('[data-bulk-line],[data-bulk-debourse],[data-bulk-checklist]').forEach(function(checkbox){
                checkbox.addEventListener('click',function(event){event.stopPropagation();});
                checkbox.addEventListener('change',updateBulkSelection);
            });
            pilot.querySelectorAll('.pc-sheet-progress input[name="progress"]').forEach(function(input){
                input.addEventListener('input',function(){
                    var value=Math.max(0,Math.min(100,Number(input.value||0)));
                    var bar=input.closest('.pc-sheet-progress').querySelector('.pc-progress-track i');
                    if(bar)bar.style.width=value+'%';
                });
                input.addEventListener('change',function(){
                    if(input.value==='')input.value='0';
                    input.value=String(Math.max(0,Math.min(100,Number(input.value||0))));
                    input.dispatchEvent(new Event('input'));
                });
            });
            pilot.querySelectorAll('.pc-office-subcategory').forEach(function(detail){
                detail.addEventListener('toggle',function(){
                    if(!detail.open)return;
                    selectedOfficeDetailId=Number(detail.dataset.officeDetailId||0);
                    pilot.querySelectorAll('.pc-office-subcategory').forEach(function(other){
                        if(other!==detail)other.open=false;
                    });
                    detail.scrollIntoView({behavior:'smooth',block:'nearest'});
                });
            });
            pilot.querySelectorAll('[data-toggle-meeting-scope]').forEach(function(checkbox){
                checkbox.addEventListener('click',function(event){event.stopPropagation();});
                checkbox.addEventListener('change',async function(){
                    var successAt=savedAnchor(checkbox);
                    pilot.classList.add('is-loading');
                    try{
                        var response=await fetch('request/meeting_action_point.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({
                            csrf:csrf,preparation_id:preparationId,action:'toggle',
                            scope:checkbox.dataset.toggleMeetingScope,id:Number(checkbox.dataset.toggleMeetingId),
                            debourse_id:Number(checkbox.dataset.toggleMeetingDebourse),completed:checkbox.checked
                        })});
                        var json=await response.json();
                        if(!response.ok||!json.ok)throw new Error(json.error||'Mise à jour impossible.');
                        await request({chantier_id:Number(state.project.id)},'GET');
                        showSavedNear(successAt);
                    }catch(error){
                        checkbox.checked=!checkbox.checked;
                        notify(error.message||'Mise à jour impossible.',true);
                    }finally{pilot.classList.remove('is-loading');}
                });
            });
            pilot.querySelectorAll('[data-open-new-point]').forEach(function(button){
                button.addEventListener('click',function(event){
                    event.preventDefault();
                    event.stopPropagation();
                    var form=pilot.querySelector('[data-new-point-form="'+button.dataset.openNewPoint+'"]');
                    if(!form)return;
                    form.hidden=false;
                    form.querySelector('textarea[name="task_title"]').focus();
                    form.scrollIntoView({behavior:'smooth',block:'nearest'});
                });
            });
            pilot.querySelectorAll('[data-open-checklist]').forEach(function(button){
                button.addEventListener('click',function(){
                    var quick=pilot.querySelector('[data-checklist-quick="'+button.dataset.openChecklist+'"]');
                    if(!quick)return;
                    quick.hidden=false;
                    quick.querySelector('input').focus();
                    requestAnimationFrame(function(){
                        quick.scrollIntoView({behavior:'smooth',block:'center'});
                    });
                });
            });
            pilot.querySelectorAll('[data-checklist-quick] input').forEach(function(input){
                input.addEventListener('keydown',function(event){
                    if(event.key==='Escape'){
                        event.preventDefault();
                        input.value='';
                        input.closest('[data-checklist-quick]').hidden=true;
                        return;
                    }
                    if(event.key!=='Enter')return;
                    event.preventDefault();
                    var label=input.value.trim();
                    if(!label)return;
                    var quick=input.closest('[data-checklist-quick]');
                    var pointForm=input.closest('[data-save-action-point]');
                    input.value='';
                    var pending=document.createElement('label');
                    pending.className='pc-checklist-item is-pending';
                    pending.innerHTML='<input type="checkbox" disabled><span>'+escapeHtml(label)+'</span>';
                    quick.parentNode.insertBefore(pending,quick);
                    requestAnimationFrame(function(){
                        quick.scrollIntoView({behavior:'smooth',block:'center'});
                    });
                    checklistRequest({
                        action:'checklist_add',action_point_id:Number(quick.dataset.checklistQuick),
                        label:label,debourse_id:Number(pointForm.dataset.debourseId)
                    },'Sous-tâche ajoutée.',null,pending);
                });
            });
            pilot.querySelectorAll('[data-cancel-checklist]').forEach(function(button){
                button.addEventListener('click',function(){
                    var quick=button.closest('[data-checklist-quick]');
                    quick.querySelector('input').value='';
                    quick.hidden=true;
                });
            });
            pilot.querySelectorAll('[data-toggle-checklist]').forEach(function(checkbox){
                checkbox.addEventListener('change',function(){
                    checklistRequest({
                        action:'checklist_toggle',checklist_id:Number(checkbox.dataset.toggleChecklist),
                        action_point_id:Number(checkbox.dataset.checklistPoint),
                        debourse_id:Number(checkbox.dataset.checklistDebourse),completed:checkbox.checked
                    },checkbox.checked?'Sous-tâche terminée.':'Sous-tâche rouverte.',checkbox);
                });
            });
            pilot.querySelectorAll('[data-checklist-progress]').forEach(function(button){button.addEventListener('click',function(){
                var answer=window.prompt('Pourcentage d’avancement de cette sous-tâche (0 à 100) :',button.dataset.progress||'0');
                if(answer===null)return;var progress=Number(String(answer).replace(',','.'));
                if(!Number.isFinite(progress)||progress<0||progress>100){notify('Saisissez un pourcentage compris entre 0 et 100.',true);return;}
                checklistRequest({action:'checklist_progress',checklist_id:Number(button.dataset.checklistProgress),action_point_id:Number(button.dataset.checklistPoint),debourse_id:Number(button.dataset.checklistDebourse),progress:progress},'Avancement de la sous-tâche enregistré.',button,null);
            });});
            pilot.querySelectorAll('[data-cancel-new-point]').forEach(function(button){
                button.addEventListener('click',function(){
                    var form=button.closest('[data-new-point-form]');
                    if(!form)return;
                    form.reset();
                    form.hidden=true;
                });
            });
            pilot.querySelectorAll('[data-select-financial-line]').forEach(function (button) {
                button.addEventListener('click', function (event) {
                    if (event.target.closest('input,button,.pc-tree-actions')) return;
                    openCustomSectionId=0;
                    openCustomPointId=0;
                    selectedLineId = Number(button.dataset.selectFinancialLine);
                    render();
                    detailPanel.scrollTo({top: 0, behavior: 'smooth'});
                });
                button.addEventListener('keydown',function(event){
                    if(event.key!=='Enter'&&event.key!==' ')return;
                    if(event.target.closest('.pc-tree-actions'))return;
                    event.preventDefault();
                    openCustomSectionId=0;
                    openCustomPointId=0;
                    selectedLineId=Number(button.dataset.selectFinancialLine);
                    render();
                    detailPanel.scrollTo({top:0,behavior:'smooth'});
                });
            });
            pilot.querySelectorAll('[data-select-custom-section]').forEach(function(button){
                function selectSection(event){
                    if(event&&event.target.closest('button,.pc-tree-actions'))return;
                    openCustomSectionId=0;
                    openCustomPointId=0;
                    selectedCustomSectionId=Number(button.dataset.selectCustomSection);
                    render();
                    detailPanel.scrollTo({top:0,behavior:'smooth'});
                }
                button.addEventListener('click',selectSection);
                button.addEventListener('keydown',function(event){
                    if(event.key!=='Enter'&&event.key!==' ')return;
                    event.preventDefault();
                    selectSection(event);
                });
            });
            pilot.querySelectorAll('[data-edit-meeting-line]').forEach(function(button){
                button.addEventListener('click',async function(event){
                    event.preventDefault();event.stopPropagation();
                    var label=window.prompt('Libellé de la ligne pour cette réunion :',button.dataset.label||'');
                    if(label===null||!label.trim())return;
                    button.disabled=true;
                    var successAt=savedAnchor(button);
                    try{
                        var response=await fetch('request/meeting_line_override.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({
                            csrf:csrf,preparation_id:preparationId,ligne_devis_id:Number(button.dataset.editMeetingLine),label:label.trim()
                        })});
                        var json=await response.json();
                        if(!response.ok||!json.ok)throw new Error(json.error||'Modification impossible.');
                        await request({chantier_id:Number(state.project.id)},'GET');
                        showSavedNear(successAt);
                    }catch(error){notify(error.message||'Modification impossible.',true);}
                    finally{button.disabled=false;}
                });
            });
            pilot.querySelectorAll('[data-hide-meeting-line]').forEach(function(button){
                button.addEventListener('click',async function(event){
                    event.preventDefault();event.stopPropagation();
                    if(!window.confirm('Retirer cette ligne des réunions chantier ? La ligne réelle du devis ne sera pas modifiée.'))return;
                    button.disabled=true;
                    try{
                        var response=await fetch('request/meeting_bulk_exclusion.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({
                            csrf:csrf,preparation_id:preparationId,line_ids:[Number(button.dataset.hideMeetingLine)],debourse_ids:[],checklist_ids:[]
                        })});
                        var json=await response.json();
                        if(!response.ok||!json.ok)throw new Error(json.error||'Retrait impossible.');
                        selectedLineId=0;
                        await request({chantier_id:Number(state.project.id)},'GET');
                        notify('Ligne retirée des réunions sans modifier le devis.');
                    }catch(error){notify(error.message||'Retrait impossible.',true);}
                    finally{button.disabled=false;}
                });
            });
            pilot.querySelectorAll('[data-toggle-type]').forEach(function (checkbox) {
                checkbox.addEventListener('click', function (event) { event.stopPropagation(); });
                checkbox.addEventListener('change', function () {
                    request({type:checkbox.dataset.toggleType,id:Number(checkbox.dataset.toggleId),completed:checkbox.checked}, 'POST',checkbox);
                });
            });
            pilot.querySelectorAll('[data-save-dates]').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    event.preventDefault();
                    request({type:form.dataset.saveDates,id:Number(form.dataset.id),start_date:form.elements.start_date.value,end_date:form.elements.end_date.value}, 'POST',event.submitter||form);
                });
            });
            pilot.querySelectorAll('[data-save-comment]').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    event.preventDefault();
                    request({type:'debourse_comment',id:Number(form.dataset.id),comment:form.elements.comment.value}, 'POST',event.submitter||form);
                });
            });
            pilot.querySelectorAll('[data-save-action-point]').forEach(function (form) {
                if(Number(form.dataset.id||0)>0){
                    form.querySelectorAll('textarea[name="task_title"], input[name="progress"]').forEach(function(field){
                        field.addEventListener('input',function(){queueOfficeTaskSave(form,650,field);});
                        field.addEventListener('change',function(){queueOfficeTaskSave(form,0,field);});
                    });
                    form.querySelectorAll('select, input[type="date"]').forEach(function(field){
                        field.addEventListener('change',function(){queueOfficeTaskSave(form,80,field);});
                    });
                }
                form.addEventListener('submit', async function (event) {
                    event.preventDefault();
                    if(Number(form.dataset.id||0)>0){
                        queueOfficeTaskSave(form,0);
                        return;
                    }
                    var payload = officeTaskPayload(form);
                    pilot.classList.add('is-loading');
                    try {
                        var response=await fetch('request/meeting_action_point.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(payload)});
                        var json=await response.json();
                        if(!response.ok||!json.ok)throw new Error(json.error||'Enregistrement impossible.');
                        state.lines.forEach(function(line){line.debourses.forEach(function(deb){deb.details.forEach(function(detail){detail.meeting_points=(json.groups[deb.id]||{})[detail.id]||[];});});});
                        state.staff_performance=json.performance||[];
                        var successAt=savedAnchor(event.submitter||form);render();showSavedNear(successAt);
                    } catch(error) { notify(error.message||'Enregistrement impossible.',true); }
                    finally { pilot.classList.remove('is-loading'); }
                });
            });
            pilot.querySelectorAll('[data-select-office-debourse]').forEach(function(button){
                button.addEventListener('click',function(event){
                    if(event.target.matches('input')||event.target.closest('.pc-tree-actions'))return;
                    selectedDebourseId=Number(button.dataset.selectOfficeDebourse);
                    selectedOfficeDetailId=0;
                    render();
                });
                button.addEventListener('keydown',function(event){
                    if((event.key==='Enter'||event.key===' ')&&!event.target.closest('.pc-tree-actions')){
                        event.preventDefault();
                        selectedDebourseId=Number(button.dataset.selectOfficeDebourse);
                        selectedOfficeDetailId=0;
                        render();
                    }
                });
            });
            pilot.querySelectorAll('[data-select-site-debourse]').forEach(function(button){
                button.addEventListener('click',function(event){
                    if(event.target.closest('[data-hide-meeting-debourse]'))return;
                    selectedLineId=Number(button.dataset.lineId);
                    selectedDebourseId=Number(button.dataset.debourseId);
                    selectedOfficeDetailId=0;
                    render();
                    detailPanel.scrollTo({top:0,behavior:'smooth'});
                });
            });
            pilot.querySelectorAll('[data-hide-meeting-debourse]').forEach(function(button){
                button.addEventListener('click',async function(event){
                    event.preventDefault();
                    event.stopPropagation();
                    if(!window.confirm('Retirer ce déboursé de cette réunion et des prochaines ? Le déboursé réel et ses données financières ne seront pas modifiés.'))return;
                    button.disabled=true;
                    try{
                        var response=await fetch('request/meeting_debourse_visibility.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({
                            csrf:csrf,preparation_id:preparationId,debourse_id:Number(button.dataset.hideMeetingDebourse)
                        })});
                        var json=await response.json();
                        if(!response.ok||!json.ok)throw new Error(json.error||'Retrait impossible.');
                        selectedDebourseId=0;
                        await request({chantier_id:Number(state.project.id)},'GET');
                        notify('Déboursé retiré de cette réunion uniquement.');
                    }catch(error){notify(error.message||'Retrait impossible.',true);}
                    finally{button.disabled=false;}
                });
            });
            pilot.querySelectorAll('[data-archive-custom-section]').forEach(function(button){
                button.addEventListener('click',async function(){
                    if(!window.confirm('Retirer ce point libre de la réunion ?'))return;
                    button.disabled=true;
                    try{
                        var response=await fetch('request/meeting_custom_section.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({
                            csrf:csrf,action:'archive',preparation_id:preparationId,id:Number(button.dataset.archiveCustomSection)
                        })});
                        var json=await response.json();
                        if(!response.ok||!json.ok)throw new Error(json.error||'Retrait impossible.');
                        await request({chantier_id:Number(state.project.id)},'GET');
                        notify('Point retiré de la réunion.');
                    }catch(error){notify(error.message||'Retrait impossible.',true);}
                    finally{button.disabled=false;}
                });
            });
            pilot.querySelectorAll('[data-add-site-line-task]').forEach(function(button){
                button.addEventListener('click',async function(event){
                    event.preventDefault();event.stopPropagation();
                    var lineId=Number(button.dataset.addSiteLineTask);
                    selectedLineId=lineId;
                    button.disabled=true;
                    try{
                        var response=await fetch('request/meeting_custom_section.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({
                            csrf:csrf,action:'create',preparation_id:preparationId,chantier_id:Number(state.project.id),ligne_devis_id:lineId,title:'Nouveau point de réunion'
                        })});
                        var json=await response.json();
                        if(!response.ok||!json.ok)throw new Error(json.error||'Création impossible.');
                        openCustomSectionId=0;
                        openCustomPointId=Number(json.id||0);
                        selectedCustomSectionId=openCustomPointId;
                        await request({chantier_id:Number(state.project.id)},'GET');
                        var form=pilot.querySelector('[data-custom-point-form][data-section-id="'+openCustomPointId+'"]');
                        if(form){form.elements.title.select();form.scrollIntoView({behavior:'smooth',block:'center'});}
                        notify('Renseignez le nouveau point de réunion.');
                    }catch(error){notify(error.message||'Création impossible.',true);}
                    finally{button.disabled=false;}
                });
            });
            pilot.querySelectorAll('[data-add-first-meeting-line]').forEach(function(button){
                button.addEventListener('click',async function(){
                    button.disabled=true;
                    try{
                        var response=await fetch('request/meeting_custom_section.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({
                            csrf:csrf,action:'create',preparation_id:preparationId,chantier_id:Number(state.project.id),ligne_devis_id:0,title:'Première ligne de réunion'
                        })});
                        var json=await response.json();
                        if(!response.ok||!json.ok)throw new Error(json.error||'Création impossible.');
                        openCustomSectionId=0;
                        openCustomPointId=Number(json.id||0);
                        selectedCustomSectionId=openCustomPointId;
                        await request({chantier_id:Number(state.project.id)},'GET');
                        var form=pilot.querySelector('[data-custom-point-form][data-section-id="'+openCustomPointId+'"]');
                        if(form){form.elements.title.select();form.scrollIntoView({behavior:'smooth',block:'center'});}
                        notify('Renseignez le premier point de réunion.');
                    }catch(error){notify(error.message||'Création impossible.',true);}
                    finally{button.disabled=false;}
                });
            });
            pilot.querySelectorAll('[data-tree-rename]').forEach(function(button){
                button.addEventListener('click',async function(event){
                    event.preventDefault();
                    event.stopPropagation();
                    var label=window.prompt('Nouveau libellé :',button.dataset.label||'');
                    if(label===null)return;
                    label=label.trim();
                    if(!label)return notify('Le libellé ne peut pas être vide.',true);
                    button.disabled=true;
                    var successAt=savedAnchor(button);
                    try{
                        await officeTreeRequest({action:'rename',scope:button.dataset.scope,id:Number(button.dataset.id),label:label});
                        showSavedNear(successAt);
                    }catch(error){notify(error.message||'Renommage impossible.',true);}
                    finally{button.disabled=false;}
                });
            });
            pilot.querySelectorAll('[data-tree-archive]').forEach(function(button){
                button.addEventListener('click',async function(event){
                    event.preventDefault();
                    event.stopPropagation();
                    var scope=button.dataset.scope;
                    var warnings={
                        category:'Archiver cette catégorie, toutes ses sous-catégories, tâches, checklists et pièces jointes ?',
                        subcategory:'Archiver cette sous-catégorie, toutes ses tâches, checklists et pièces jointes ?',
                        task:'Archiver cette tâche et toute sa checklist ?',
                        checklist:'Archiver cette ligne de checklist ?'
                    };
                    if(!window.confirm((warnings[scope]||'Archiver cet élément ?')+' Les données resteront conservées en base.'))return;
                    button.disabled=true;
                    try{
                        var archivedId=Number(button.dataset.id);
                        if(scope==='category'&&selectedDebourseId===archivedId)selectedDebourseId=0;
                        await officeTreeRequest({action:'archive',scope:scope,id:archivedId});
                        notify('Élément archivé. Il ne sera plus affiché.');
                    }catch(error){notify(error.message||'Archivage impossible.',true);}
                    finally{button.disabled=false;}
                });
            });
            pilot.querySelectorAll('[data-upload-attachments]').forEach(function(input){
                input.addEventListener('change',async function(){
                    if(!input.files||!input.files.length)return;
                    var formData=new FormData();
                    formData.append('csrf',csrf);
                    formData.append('preparation_id',String(preparationId));
                    formData.append('ligne_debourse_id',input.dataset.uploadAttachments);
                    Array.prototype.forEach.call(input.files,function(file){formData.append('files[]',file);});
                    input.disabled=true;
                    try{
                        var response=await fetch('request/office_attachment.php',{method:'POST',headers:{'Accept':'application/json'},body:formData});
                        var json=await response.json();
                        if(!response.ok||!json.ok)throw new Error(json.error||'Ajout impossible.');
                        await refreshOfficeSilently();
                        notify(Number(json.saved||0)+' pièce(s) jointe(s) ajoutée(s).');
                    }catch(error){notify(error.message||'Ajout des pièces jointes impossible.',true);}
                    finally{input.disabled=false;input.value='';}
                });
            });
            pilot.querySelectorAll('[data-archive-attachment]').forEach(function(button){
                button.addEventListener('click',async function(event){
                    event.preventDefault();
                    event.stopPropagation();
                    if(!window.confirm('Archiver cette pièce jointe ? Le fichier restera conservé.'))return;
                    var formData=new FormData();
                    formData.append('csrf',csrf);
                    formData.append('preparation_id',String(preparationId));
                    formData.append('ligne_debourse_id',button.dataset.detailId);
                    formData.append('archive_id',button.dataset.archiveAttachment);
                    button.disabled=true;
                    try{
                        var response=await fetch('request/office_attachment.php',{method:'POST',headers:{'Accept':'application/json'},body:formData});
                        var json=await response.json();
                        if(!response.ok||!json.ok)throw new Error(json.error||'Archivage impossible.');
                        await refreshOfficeSilently();
                        notify('Pièce jointe archivée.');
                    }catch(error){notify(error.message||'Archivage impossible.',true);}
                    finally{button.disabled=false;}
                });
            });
            pilot.querySelectorAll('[data-upload-custom-attachments]').forEach(function(input){
                input.addEventListener('change',async function(){
                    if(!input.files||!input.files.length)return;
                    var formData=new FormData();
                    formData.append('csrf',csrf);
                    formData.append('preparation_id',String(preparationId));
                    formData.append('meeting_section_id',input.dataset.uploadCustomAttachments);
                    Array.prototype.forEach.call(input.files,function(file){formData.append('files[]',file);});
                    input.disabled=true;
                    try{
                        var response=await fetch('request/meeting_custom_attachment.php',{method:'POST',headers:{'Accept':'application/json'},body:formData});
                        var json=await response.json();
                        if(!response.ok||!json.ok)throw new Error(json.error||'Ajout impossible.');
                        await request({chantier_id:Number(state.project.id)},'GET');
                        notify(Number(json.saved||0)+' pièce(s) jointe(s) ajoutée(s).');
                    }catch(error){notify(error.message||'Ajout des pièces jointes impossible.',true);}
                    finally{input.disabled=false;input.value='';}
                });
            });
            pilot.querySelectorAll('[data-archive-custom-attachment]').forEach(function(button){
                button.addEventListener('click',async function(event){
                    event.preventDefault();event.stopPropagation();
                    if(!window.confirm('Supprimer cette pièce jointe de la réunion ?'))return;
                    var formData=new FormData();
                    formData.append('csrf',csrf);
                    formData.append('preparation_id',String(preparationId));
                    formData.append('meeting_section_id',button.dataset.sectionId);
                    formData.append('archive_id',button.dataset.archiveCustomAttachment);
                    button.disabled=true;
                    try{
                        var response=await fetch('request/meeting_custom_attachment.php',{method:'POST',headers:{'Accept':'application/json'},body:formData});
                        var json=await response.json();
                        if(!response.ok||!json.ok)throw new Error(json.error||'Suppression impossible.');
                        await request({chantier_id:Number(state.project.id)},'GET');
                        notify('Pièce jointe retirée.');
                    }catch(error){notify(error.message||'Suppression impossible.',true);}
                    finally{button.disabled=false;}
                });
            });
            pilot.querySelectorAll('[data-office-structure]').forEach(function(form){
                form.addEventListener('submit',async function(event){
                    event.preventDefault();
                    var payload={csrf:csrf,preparation_id:preparationId,action:form.dataset.officeStructure,designation:form.elements.designation.value,montant:form.elements.montant.value};
                    if(form.elements.detail_designation)payload.detail_designation=form.elements.detail_designation.value;
                    if(form.dataset.debourseId)payload.debourse_id=Number(form.dataset.debourseId);
                    pilot.classList.add('is-loading');
                    try{
                        var response=await fetch('request/office_structure.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(payload)});
                        var json=await response.json();
                        if(!response.ok||!json.ok)throw new Error(json.error||'Création impossible.');
                        if(form.dataset.officeStructure==='add_debourse')selectedDebourseId=Number(json.id||0);
                        await request({chantier_id:Number(state.project.id)},'GET');
                        notify('Structure du service mise à jour.');
                    }catch(error){notify(error.message||'Création impossible.',true);}
                    finally{pilot.classList.remove('is-loading');}
                });
            });
            enhancePersonnelSelects();
            activateTaskAutoHeight();
        }
        function checklistRequest(payload,message,checkbox,pending){
            payload.csrf=csrf;
            payload.preparation_id=preparationId;
            var pointId=Number(payload.action_point_id||0);
            var successAt=savedAnchor(checkbox||pending);
            var previous=checklistQueues[pointId]||Promise.resolve();
            checklistQueues[pointId]=previous.catch(function(){}).then(async function(){
                try{
                var response=await fetch('request/meeting_action_point.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(payload)});
                var json=await response.json();
                if(!response.ok||!json.ok)throw new Error(json.error||'Mise à jour impossible.');
                var currentQuick=payload.action==='checklist_add'?pilot.querySelector('[data-checklist-quick="'+payload.action_point_id+'"]'):null;
                var nextValue=currentQuick?currentQuick.querySelector('input').value:'';
                var scrollTop=detailPanel.scrollTop;
                applyOfficeGroups(json.groups||{},json.performance||[]);
                render();
                detailPanel.scrollTop=scrollTop;
                if(payload.action==='checklist_add'){
                    var quick=pilot.querySelector('[data-checklist-quick="'+payload.action_point_id+'"]');
                    if(quick){
                        quick.hidden=false;
                        quick.querySelector('input').value=nextValue;
                        quick.querySelector('input').focus();
                        requestAnimationFrame(function(){
                            quick.scrollIntoView({behavior:'smooth',block:'center'});
                        });
                    }
                }
                showSavedNear(successAt);
                }catch(error){
                    if(checkbox&&checkbox.matches('input[type="checkbox"]'))checkbox.checked=!checkbox.checked;
                    if(pending&&pending.parentNode)pending.remove();
                    notify(error.message||'Mise à jour impossible.',true);
                }
            });
            return checklistQueues[pointId];
        }
        projectSelect.addEventListener('change', function () {
            selectedLineId = 0;
            projectBadges.forEach(function (badge) {
                badge.classList.toggle('is-active', badge.dataset.financialProjectBadge === projectSelect.value);
            });
            if (projectSelect.value) request({chantier_id:Number(projectSelect.value)}, 'GET');
        });
        pilot.addEventListener('click', function (event) {
            var badge = event.target.closest('[data-financial-project-badge]');
            if (!badge || !pilot.contains(badge)) return;
            event.preventDefault();
            var chantierId = badge.dataset.financialProjectBadge;
            projectBadges.forEach(function (item) {
                item.classList.toggle('is-active', item === badge);
            });
            projectSelect.value = chantierId;
            selectedLineId = 0;
            request({chantier_id:Number(chantierId)}, 'GET');
        });

        if(projectBadges.length===1)projectBadges[0].click();

        var presentationButton=pilot.querySelector('[data-office-presentation]');
        if(presentationButton){
            presentationButton.addEventListener('click',function(){
                var active=pilot.classList.toggle('is-presentation');
                document.body.classList.toggle('pc-presentation-open',active);
                presentationButton.innerHTML=active?'<i class="fas fa-compress"></i> Quitter la présentation':'<i class="fas fa-expand"></i> Mode présentation';
            });
            document.addEventListener('keydown',function(event){
                if(event.key==='Escape'&&pilot.classList.contains('is-presentation'))presentationButton.click();
            });
        }
        var addFreeSection=pilot.querySelector('[data-add-free-meeting-section]');
        if(addFreeSection)addFreeSection.addEventListener('click',async function(){
            var title=window.prompt('Nom du point libre de réunion :','');
            if(title===null||!title.trim())return;
            addFreeSection.disabled=true;
            try{
                var response=await fetch('request/meeting_custom_section.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({
                    csrf:csrf,action:'create',preparation_id:preparationId,chantier_id:Number(state&&state.project?state.project.id:0),ligne_devis_id:0,title:title.trim()
                })});
                var json=await response.json();
                if(!response.ok||!json.ok)throw new Error(json.error||'Création impossible.');
                openCustomSectionId=0;
                openCustomPointId=Number(json.id||0);
                if(state)await request({chantier_id:Number(state.project.id)},'GET');
                var form=pilot.querySelector('[data-custom-point-form][data-section-id="'+openCustomPointId+'"]');
                if(form){form.elements.title.focus();form.scrollIntoView({behavior:'smooth',block:'center'});}
                notify('Point libre ajouté à la réunion.');
            }catch(error){notify(error.message||'Création impossible.',true);}
            finally{addFreeSection.disabled=false;}
        });

        function updateBulkSelection(){
            if(!bulkBar)return;
            var count=pilot.querySelectorAll('[data-bulk-line]:checked,[data-bulk-debourse]:checked,[data-bulk-checklist]:checked').length;
            var countNode=bulkBar.querySelector('[data-bulk-selection-count]');
            var apply=bulkBar.querySelector('[data-bulk-selection-apply]');
            if(countNode)countNode.textContent=String(count);
            if(apply)apply.disabled=count===0;
        }
        function closeBulkSelection(){
            pilot.classList.remove('is-bulk-selecting');
            if(bulkBar)bulkBar.hidden=true;
            pilot.querySelectorAll('[data-bulk-line],[data-bulk-debourse],[data-bulk-checklist]').forEach(function(item){item.checked=false;});
            updateBulkSelection();
            if(bulkToggle)bulkToggle.innerHTML='<i class="fas fa-list-check"></i><span>Sélection multiple</span>';
        }
        if(bulkToggle&&bulkBar){
            bulkToggle.addEventListener('click',function(){
                var active=pilot.classList.toggle('is-bulk-selecting');
                bulkBar.hidden=!active;
                bulkToggle.innerHTML=active?'<i class="fas fa-xmark"></i><span>Quitter la sélection</span>':'<i class="fas fa-list-check"></i><span>Sélection multiple</span>';
                if(!active)closeBulkSelection();
            });
            bulkBar.querySelector('[data-bulk-selection-cancel]').addEventListener('click',closeBulkSelection);
            bulkBar.querySelector('[data-bulk-selection-apply]').addEventListener('click',async function(){
                var lineIds=Array.from(pilot.querySelectorAll('[data-bulk-line]:checked')).map(function(item){return Number(item.dataset.bulkLine);});
                var debourseIds=Array.from(pilot.querySelectorAll('[data-bulk-debourse]:checked')).map(function(item){return Number(item.dataset.bulkDebourse);});
                var checklistIds=Array.from(pilot.querySelectorAll('[data-bulk-checklist]:checked')).map(function(item){return Number(item.dataset.bulkChecklist);});
                if(!lineIds.length&&!debourseIds.length&&!checklistIds.length)return;
                if(!window.confirm('Retirer les éléments sélectionnés ? Les lignes et déboursés resteront intacts dans le devis et les finances.'))return;
                var apply=this;apply.disabled=true;
                try{
                    var response=await fetch('request/meeting_bulk_exclusion.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({
                        csrf:csrf,preparation_id:preparationId,line_ids:lineIds,debourse_ids:debourseIds,checklist_ids:checklistIds
                    })});
                    var json=await response.json();
                    if(!response.ok||!json.ok)throw new Error(json.error||'Retrait multiple impossible.');
                    closeBulkSelection();
                    await request({chantier_id:Number(state.project.id)},'GET');
                    notify(Number(json.removed||0)+' élément(s) retiré(s).');
                }catch(error){notify(error.message||'Retrait multiple impossible.',true);}
                finally{apply.disabled=false;}
            });
        }

        var meetingProjectSelect = document.querySelector('[data-meeting-main-project]');
        if (meetingProjectSelect) {
            meetingProjectSelect.addEventListener('change', function () {
                var option = meetingProjectSelect.options[meetingProjectSelect.selectedIndex];
                var chantierId = option ? Number(option.dataset.chantierId || 0) : 0;
                if (!chantierId) return;
                projectSelect.value = String(chantierId);
                if (projectSelect.value) {
                    projectSelect.dispatchEvent(new Event('change', {bubbles:true}));
                    pilot.scrollIntoView({behavior:'smooth', block:'start'});
                } else {
                    notify('Ce chantier ne possède pas encore de devis exploitable pour le pilotage.', true);
                }
            });
        }
    });

    document.querySelectorAll('[data-pc-search]').forEach(function (search) {
        search.addEventListener('input', function () {
            var q = this.value.toLowerCase().trim();
            document.querySelectorAll('[data-pc-row]').forEach(function (row) {
                row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    });

    document.querySelectorAll('[data-pc-confirm]').forEach(function (btn) {
        btn.addEventListener('click', function (event) {
            if (!confirm(btn.getAttribute('data-pc-confirm') || 'Confirmer cette action ?')) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('[data-select-all-meeting-projects]').forEach(function (button) {
        var form = button.closest('form');
        if (!form) return;
        var checkboxes = form.querySelectorAll('input[name="chantier_ids[]"]');
        button.addEventListener('click', function () {
            var shouldSelect = !Array.prototype.every.call(checkboxes, function (checkbox) {
                return checkbox.checked;
            });
            checkboxes.forEach(function (checkbox) {
                checkbox.checked = shouldSelect;
            });
            button.setAttribute('aria-pressed', shouldSelect ? 'true' : 'false');
            button.querySelector('span').textContent = shouldSelect ? 'Tout désélectionner' : 'Tout sélectionner';
        });
        checkboxes.forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                var allSelected = Array.prototype.every.call(checkboxes, function (item) {
                    return item.checked;
                });
                button.setAttribute('aria-pressed', allSelected ? 'true' : 'false');
                button.querySelector('span').textContent = allSelected ? 'Tout désélectionner' : 'Tout sélectionner';
            });
        });
    });

    function escapeHtml(text) {
        return String(text || '').replace(/[&<>"]/g, function (m) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[m];
        });
    }

    function buildSearchSelect(select) {
        if (!select || select.dataset.pcEnhanced === '1') return;
        select.dataset.pcEnhanced = '1';
        select.classList.add('pc-search-select-native');

        var wrapper = document.createElement('div');
        wrapper.className = 'pc-search-select';
        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);

        var inputWrap = document.createElement('div');
        inputWrap.className = 'pc-search-select-input-wrap';

        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'pc-search-select-input';
        input.autocomplete = 'off';
        input.placeholder = select.getAttribute('data-placeholder') || 'Rechercher et sélectionner...';

        var current = select.options[select.selectedIndex];
        if (current && current.value !== '') input.value = current.textContent.trim();

        var icon = document.createElement('span');
        icon.className = 'pc-search-select-icon';
        icon.innerHTML = '<i class="fas fa-chevron-down"></i>';

        inputWrap.appendChild(input);
        inputWrap.appendChild(icon);
        wrapper.appendChild(inputWrap);

        var menu = document.createElement('div');
        menu.className = 'pc-search-select-menu';
        wrapper.appendChild(menu);

        function options() {
            return Array.prototype.slice.call(select.options).map(function (opt) {
                return {
                    value: opt.value,
                    text: opt.textContent.trim(),
                    disabled: opt.disabled,
                    name: opt.getAttribute('data-name') || '',
                    email: opt.getAttribute('data-email') || ''
                };
            });
        }

        function render(filter) {
            var q = (filter || '').toLowerCase().trim();
            var list = options().filter(function (opt) {
                return !opt.disabled && (q === '' || opt.text.toLowerCase().indexOf(q) !== -1);
            }).slice(0, 60);

            menu.innerHTML = '';

            if (!list.length) {
                var empty = document.createElement('div');
                empty.className = 'pc-search-select-empty';
                empty.textContent = 'Aucun résultat trouvé';
                menu.appendChild(empty);
                return;
            }

            list.forEach(function (opt) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'pc-search-select-option' + (select.value === opt.value ? ' is-active' : '');
                btn.innerHTML = '<i class="fas fa-check-circle"></i><span>' + escapeHtml(opt.text) + '</span>';
                btn.addEventListener('click', function () {
                    select.value = opt.value;
                    input.value = opt.value === '' ? '' : opt.text;
                    wrapper.classList.remove('is-open');
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                });
                menu.appendChild(btn);
            });
        }

        input.addEventListener('focus', function () {
            render(input.value);
            wrapper.classList.add('is-open');
        });
        input.addEventListener('input', function () {
            render(input.value);
            wrapper.classList.add('is-open');
        });
        input.addEventListener('click', function () {
            render(input.value);
            wrapper.classList.add('is-open');
        });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') wrapper.classList.remove('is-open');
            if (event.key === 'Enter') {
                var first = menu.querySelector('.pc-search-select-option');
                if (first && wrapper.classList.contains('is-open')) {
                    event.preventDefault();
                    first.click();
                }
            }
        });

        document.addEventListener('click', function (event) {
            if (!wrapper.contains(event.target)) wrapper.classList.remove('is-open');
        });

        select.addEventListener('change', function () {
            var opt = select.options[select.selectedIndex];
            input.value = opt && opt.value !== '' ? opt.textContent.trim() : '';
        });

        render('');
    }

    function enhanceSearchSelects(scope) {
        (scope || document).querySelectorAll('select[data-pc-select-search]').forEach(buildSearchSelect);
    }

    enhanceSearchSelects(document);

    var pointsContainer = document.querySelector('[data-meeting-points]');
    var addPointBtn = document.querySelector('[data-add-meeting-point]');
    var pointTemplate = document.querySelector('[data-meeting-point-template]');

    function refreshPointNumbers() {
        if (!pointsContainer) return;
        pointsContainer.querySelectorAll('[data-meeting-point]').forEach(function (point, index) {
            var label = point.querySelector('[data-point-number]');
            if (label) label.textContent = String(index + 1);
        });
    }

    function bindPoint(point) {
        var remove = point.querySelector('[data-remove-meeting-point]');
        if (remove) {
            remove.addEventListener('click', function () {
                if (pointsContainer && pointsContainer.querySelectorAll('[data-meeting-point]').length <= 1) {
                    point.querySelectorAll('input, textarea').forEach(function (field) { field.value = ''; });
                    point.querySelectorAll('select').forEach(function (field) {
                        field.selectedIndex = 0;
                        field.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                    return;
                }
                point.remove();
                refreshPointNumbers();
            });
        }
        enhanceSearchSelects(point);
    }

    if (pointsContainer) pointsContainer.querySelectorAll('[data-meeting-point]').forEach(bindPoint);

    if (addPointBtn && pointTemplate && pointsContainer) {
        addPointBtn.addEventListener('click', function () {
            var index = String(Date.now());
            var nextNumber = pointsContainer.querySelectorAll('[data-meeting-point]').length + 1;
            var html = pointTemplate.innerHTML.replace(/__INDEX__/g, index).replace(/__NUMBER__/g, String(nextNumber));
            var wrap = document.createElement('div');
            wrap.innerHTML = html.trim();
            var point = wrap.firstElementChild;
            pointsContainer.appendChild(point);
            bindPoint(point);
            refreshPointNumbers();
            var first = point.querySelector('select[data-pc-select-search], input, textarea');
            if (first) setTimeout(function () {
                var enhanced = point.querySelector('.pc-search-select-input');
                (enhanced || first).focus();
            }, 30);
        });
    }

    var participantContainer = document.querySelector('[data-participants]');
    var addParticipantBtn = document.querySelector('[data-add-participant]');
    var participantTemplate = document.querySelector('[data-participant-template]');

    function bindParticipant(row) {
        enhanceSearchSelects(row);
        var remove = row.querySelector('[data-remove-participant]');
        if (remove) {
            remove.addEventListener('click', function () {
                if (participantContainer && participantContainer.querySelectorAll('[data-participant]').length <= 1) {
                    row.querySelectorAll('input[type="text"], input[type="email"], input:not([type])').forEach(function (field) { field.value = ''; });
                    row.querySelectorAll('select').forEach(function (field) {
                        field.selectedIndex = 0;
                        field.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                    return;
                }
                row.remove();
            });
        }

        var userSelect = row.querySelector('[data-participant-user]');
        if (userSelect) {
            userSelect.addEventListener('change', function () {
                var opt = userSelect.options[userSelect.selectedIndex];
                var name = opt ? (opt.getAttribute('data-name') || '') : '';
                var email = opt ? (opt.getAttribute('data-email') || '') : '';
                var nameField = row.querySelector('[data-participant-name]');
                var emailField = row.querySelector('[data-participant-email]');
                if (nameField && name) nameField.value = name;
                if (emailField && email) emailField.value = email;
            });
        }
    }

    if (participantContainer) participantContainer.querySelectorAll('[data-participant]').forEach(bindParticipant);

    if (addParticipantBtn && participantTemplate && participantContainer) {
        addParticipantBtn.addEventListener('click', function () {
            var index = String(Date.now());
            var html = participantTemplate.innerHTML.replace(/__INDEX__/g, index);
            var wrap = document.createElement('div');
            wrap.innerHTML = html.trim();
            var row = wrap.firstElementChild;
            participantContainer.appendChild(row);
            bindParticipant(row);
        });
    }

    var meetingForm = document.getElementById('meetingForm');
    var modal = document.querySelector('[data-close-meeting-modal]');
    var closeInput = document.querySelector('[data-close-meeting-input]');
    var openCloseBtn = document.querySelector('[data-open-close-meeting]');
    var submitCloseBtn = document.querySelector('[data-submit-close-meeting]');

    function openModal() {
        if (modal) {
            modal.hidden = false;
            var field = modal.querySelector('textarea[name="resume"]');
            if (field) setTimeout(function () { field.focus(); }, 60);
        }
    }

    function closeModal() {
        if (modal) modal.hidden = true;
        if (closeInput) closeInput.value = '0';
    }

    if (openCloseBtn) {
        openCloseBtn.addEventListener('click', function () {
            if (closeInput) closeInput.value = '1';
            openModal();
        });
    }

    document.querySelectorAll('[data-close-modal-dismiss]').forEach(function (btn) {
        btn.addEventListener('click', closeModal);
    });

    function syncParticipantRows() {
        document.querySelectorAll('[data-participant]').forEach(function (row) {
            var userSelect = row.querySelector('[data-participant-user]');
            var nameField = row.querySelector('[data-participant-name]');
            var emailField = row.querySelector('[data-participant-email]');

            if (userSelect && userSelect.value) {
                var opt = userSelect.options[userSelect.selectedIndex];
                var optName = opt ? (opt.getAttribute('data-name') || '') : '';
                var optEmail = opt ? (opt.getAttribute('data-email') || '') : '';
                if (nameField && !String(nameField.value || '').trim() && optName) nameField.value = optName;
                if (emailField && !String(emailField.value || '').trim() && optEmail) emailField.value = optEmail;
            }

            // Les participants sont facultatifs : ils ne doivent jamais bloquer la clôture.
            row.querySelectorAll('input, select, textarea').forEach(function (field) {
                if (field.hasAttribute('data-close-required')) return;
                field.required = false;
                field.removeAttribute('required');
                field.setCustomValidity('');
            });
        });
    }

    function validateCloseRequiredFields() {
        var firstInvalid = null;
        if (modal) {
            modal.querySelectorAll('[data-close-required]').forEach(function (field) {
                if (!String(field.value || '').trim() && !firstInvalid) {
                    firstInvalid = field;
                }
            });
        }
        if (firstInvalid) {
            firstInvalid.focus();
            alert('Veuillez renseigner le résumé final et les décisions générales avant de clôturer.');
            return false;
        }
        return true;
    }

    if (submitCloseBtn && meetingForm) {
        submitCloseBtn.addEventListener('click', function () {
            syncParticipantRows();
            if (!validateCloseRequiredFields()) return;

            if (closeInput) closeInput.value = '1';

            // On utilise submit() volontairement pour éviter que des champs facultatifs
            // générés dynamiquement par les select-search bloquent la clôture via HTML5.
            if (typeof meetingForm.submit === 'function') {
                meetingForm.submit();
            } else {
                HTMLFormElement.prototype.submit.call(meetingForm);
            }
        });
    }

    if (meetingForm && meetingForm.hasAttribute('data-enter-submit')) {
        meetingForm.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') return;
            if (event.target && event.target.closest('.pc-search-select')) return;
            if (event.target && event.target.tagName === 'TEXTAREA') {
                if (event.ctrlKey || event.metaKey) {
                    event.preventDefault();
                    meetingForm.requestSubmit ? meetingForm.requestSubmit() : meetingForm.submit();
                }
                return;
            }
            if (event.target && ['INPUT', 'SELECT'].indexOf(event.target.tagName) !== -1) {
                event.preventDefault();
                meetingForm.requestSubmit ? meetingForm.requestSubmit() : meetingForm.submit();
            }
        });
    }

    var autosaveForm=document.querySelector('[data-meeting-autosave]');
    if(autosaveForm){
        var autosaveTimer=null;
        var autosave=function(useBeacon){
            var payload={
                csrf:autosaveForm.dataset.csrf||'',
                preparation_id:Number((autosaveForm.elements.preparation_id||{}).value||0),
                resume:(autosaveForm.elements.resume||{}).value||'',
                decisions:(autosaveForm.elements.decisions||{}).value||''
            };
            var body=JSON.stringify(payload);
            if(useBeacon&&navigator.sendBeacon){
                navigator.sendBeacon('request/autosave_meeting.php',new Blob([body],{type:'application/json'}));
                return;
            }
            fetch('request/autosave_meeting.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:body,keepalive:true}).catch(function(){});
        };
        autosaveForm.querySelectorAll('textarea').forEach(function(field){
            field.addEventListener('input',function(){clearTimeout(autosaveTimer);autosaveTimer=setTimeout(function(){autosave(false);},800);});
            field.addEventListener('blur',function(){autosave(false);});
        });
        window.addEventListener('pagehide',function(){autosave(true);});
        document.addEventListener('visibilitychange',function(){if(document.visibilityState==='hidden')autosave(true);});
    }
})();
