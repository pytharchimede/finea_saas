<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../services/SupplierReferenceService.php';
$masterSuppliers=(new SupplierReferenceService(Database::getConnection()))->options();
$_SESSION['provider_identity_csrf']=$_SESSION['provider_identity_csrf']??bin2hex(random_bytes(24));
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fournisseurs Transport - Admin</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .container {
            max-width: 1100px;
            margin: 20px auto;
            padding: 10px
        }

        table {
            width: 100%;
            border-collapse: collapse
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 8px
        }

        th {
            background: #f2f2f2
        }

        .row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap
        }

        .row>div {
            flex: 1 1 200px
        }

        .card {
            border: 1px solid #ddd;
            padding: 12px;
            border-radius: 6px
        }

        .muted {
            color: #777;
            font-size: 12px
        }

        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            background: #eee
        }

        .badge.on {
            background: #e6ffed;
            color: #0a0
        }

        .badge.off {
            background: #ffecec;
            color: #a00
        }

        .actions button {
            margin-right: 6px
        }
    </style>
    <script>
        const escapeProvider=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        async function loadProviders() {
            const res = await fetch('api/providers_manage.php');
            const data = await res.json();
            const tbody = document.querySelector('#providers tbody');
            tbody.innerHTML = '';
            if (!data.ok) {
                tbody.innerHTML = '<tr><td colspan="8">Erreur: ' + escapeProvider(data.error || 'inconnue') + '</td></tr>';
                return;
            }
            data.providers.forEach(p => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${p.id}</td>
                    <td>${escapeProvider(p.nom)}<div class="muted">${escapeProvider(p.notes)}</div></td>
                    <td>${escapeProvider(p.telephone)}<div class="muted">WA: ${escapeProvider(p.whatsapp)}</div></td>
                    <td>${escapeProvider(p.carburant_mode)} ${p.carburant_valeur||0}</td>
                    <td>${escapeProvider(p.frais_mode)} ${p.frais_valeur||0}</td>
                    <td><span class="badge ${p.actif==1?'on':'off'}">${p.actif==1?'Actif':'Inactif'}</span></td>
                    <td class="actions">
                        <button type="button">Éditer</button>
                    </td>
                `;
                tr.querySelector('button').addEventListener('click',()=>editProvider(JSON.stringify(p)));
                tbody.appendChild(tr);
            });
        }

        function editProvider(pjson) {
            const p = JSON.parse(pjson);
            for (const k of ['id', 'master_supplier_id', 'nom', 'telephone', 'whatsapp', 'notes']) {
                const el = document.getElementById('f_' + k);
                if (el) el.value = p[k] ?? '';
            }
            document.getElementById('f_actif').checked = (p.actif == 1);
            document.getElementById('f_carburant_mode').value = p.carburant_mode || 'percent';
            document.getElementById('f_carburant_valeur').value = p.carburant_valeur || 0;
            document.getElementById('f_frais_mode').value = p.frais_mode || 'fixed';
            document.getElementById('f_frais_valeur').value = p.frais_valeur || 0;
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }
        async function saveProvider(ev) {
            ev.preventDefault();
            const payload = {
                id: parseInt(document.getElementById('f_id').value || '0'),
                master_supplier_id:parseInt(document.getElementById('f_master_supplier_id').value||'0'),
                _csrf:<?= json_encode($_SESSION['provider_identity_csrf']) ?>,
                nom: document.getElementById('f_nom').value.trim(),
                telephone: document.getElementById('f_telephone').value.trim(),
                whatsapp: document.getElementById('f_whatsapp').value.trim(),
                actif: document.getElementById('f_actif').checked ? 1 : 0,
                carburant_mode: document.getElementById('f_carburant_mode').value,
                carburant_valeur: parseFloat(document.getElementById('f_carburant_valeur').value || '0'),
                frais_mode: document.getElementById('f_frais_mode').value,
                frais_valeur: parseFloat(document.getElementById('f_frais_valeur').value || '0'),
                notes: document.getElementById('f_notes').value
            };
            const res = await fetch('api/providers_manage.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            const msg = document.getElementById('msg');
            if (data.ok) {
                msg.textContent = 'Enregistré';
                msg.style.color = 'green';
                (document.getElementById('providerForm')).reset();
                document.getElementById('f_id').value = '';
                await loadProviders();
            } else {
                msg.textContent = 'Erreur: ' + (data.error || 'inconnue');
                msg.style.color = 'red';
            }
        }
        const masterContacts=<?= json_encode(array_column(array_map(static fn(array $s):array=>['id'=>(int)$s['id_fournisseur'],'name'=>$s['supplier_display_name'],'phone'=>$s['telephone']??''],$masterSuppliers),null,'id'),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
        window.addEventListener('DOMContentLoaded',()=>{loadProviders();document.getElementById('f_master_supplier_id').addEventListener('change',event=>{const row=masterContacts[event.target.value];document.getElementById('f_nom').value=row?.name||'';document.getElementById('f_telephone').value=row?.phone||'';});});
    </script>
</head>

<body>
    <div class="container">
        <h2>Gestion des Fournisseurs Transport</h2>
        <p>Le nom et les coordonnées proviennent de la fiche fournisseur commune. <a href="../fournisseur/ajouter_fournisseur.php">Créer un fournisseur</a> · <a href="../fournisseur/fournisseurs.php">Modifier une identité ou une pièce</a>. Une fiche active, complète et validée est obligatoire.</p>
        <div class="card">
            <form id="providerForm" onsubmit="saveProvider(event)">
                <div class="row">
                    <div><label>ID (édition)</label><br><input id="f_id" type="number" placeholder="(laisser vide pour créer)"></div>
                    <div><label>Fiche fournisseur *</label><br><select id="f_master_supplier_id" required><option value="">Sélectionnez une fiche validée</option><?php foreach($masterSuppliers as $supplier): ?><option value="<?= (int)$supplier['id_fournisseur'] ?>"><?= htmlspecialchars($supplier['code_fournisseur'].' · '.$supplier['supplier_display_name'],ENT_QUOTES,'UTF-8') ?></option><?php endforeach; ?></select></div>
                    <div><label>Nom</label><br><input id="f_nom" type="text" readonly></div>
                    <div><label>Téléphone</label><br><input id="f_telephone" type="text" readonly></div>
                    <div><label>WhatsApp</label><br><input id="f_whatsapp" type="text"></div>
                    <div><label>Actif</label><br><input id="f_actif" type="checkbox" checked></div>
                </div>
                <div class="row">
                    <div><label>Carburant - Mode</label><br>
                        <select id="f_carburant_mode">
                            <option value="percent">Pourcentage</option>
                            <option value="fixed">Fixe</option>
                        </select>
                    </div>
                    <div><label>Carburant - Valeur</label><br><input id="f_carburant_valeur" type="number" step="0.01" value="0"></div>
                    <div><label>Frais - Mode</label><br>
                        <select id="f_frais_mode">
                            <option value="fixed">Fixe</option>
                            <option value="percent">Pourcentage</option>
                        </select>
                    </div>
                    <div><label>Frais - Valeur</label><br><input id="f_frais_valeur" type="number" step="0.01" value="0"></div>
                </div>
                <div class="row">
                    <div style="flex:1 1 100%"><label>Notes</label><br><textarea id="f_notes" rows="2" style="width:100%"></textarea></div>
                </div>
                <div class="row">
                    <div><button type="submit">Enregistrer</button></div>
                    <div id="msg" class="muted"></div>
                </div>
            </form>
        </div>
        <h3 style="margin-top:20px">Fournisseurs actifs</h3>
        <table id="providers">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nom</th>
                    <th>Contact</th>
                    <th>Carburant</th>
                    <th>Frais</th>
                    <th>Statut</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td colspan="7">Chargement…</td>
                </tr>
            </tbody>
        </table>
        <p class="muted">Astuce: Cliquez sur "Editer" pour charger la fiche dans le formulaire, modifiez et sauvegardez.</p>
    </div>
</body>

</html>
