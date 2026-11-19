<?php
require_once __DIR__.'/../auth_check.php';
AccessManager::requirePageAccess('export_contrat_rh.php');
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../services/RhEmployeeContractCompanyService.php';
require_once __DIR__.'/../services/RhEmployeeContractClausePresetService.php';
require_once __DIR__.'/../model/AuditTrail.php';

try {
    $pdo = Database::getConnection();
    $personnelId = max(0, (int)($_GET['personnel_id'] ?? 0));
    $contractId = max(0, (int)($_GET['contract_id'] ?? 0));
    $download = !empty($_GET['download']);
    $saveToDossier = ((string)($_GET['save_to_dossier'] ?? '0')) === '1';
    if ($personnelId < 1) { http_response_code(400); exit('Salarié manquant.'); }

    $sql = "SELECT ec.*,p.nom_personnel_soignant employee_name,p.matricule_personnel_soignant matricule,p.email_personnel_soignant employee_email,p.statut_pers_soignant_code,
        f.lib_fonction fonction,s.lib_service service,
        er.nom_personnel_soignant employer_signer_name,ef.lib_fonction employer_signer_role,
        rr.nom_personnel_soignant rh_signer_name,rf.lib_fonction rh_signer_role
        FROM employee_contracts ec
        JOIN personnel p ON p.id_personnel_soignant=ec.personnel_id
        LEFT JOIN fonction f ON f.id_fonction=p.fonction_id
        LEFT JOIN service s ON s.id_service=p.service_id
        LEFT JOIN personnel er ON er.id_personnel_soignant=ec.employer_signer_personnel_id
        LEFT JOIN fonction ef ON ef.id_fonction=er.fonction_id
        LEFT JOIN personnel rr ON rr.id_personnel_soignant=ec.rh_signer_personnel_id
        LEFT JOIN fonction rf ON rf.id_fonction=rr.fonction_id
        WHERE ec.personnel_id=?".($contractId > 0 ? ' AND ec.id=?' : '')."
        ORDER BY ec.active DESC,ec.start_date DESC,ec.id DESC LIMIT 1";
    $q = $pdo->prepare($sql);
    $params = [$personnelId]; if ($contractId > 0) $params[] = $contractId;
    $q->execute($params);
    $contract = $q->fetch(PDO::FETCH_ASSOC);
    if (!$contract) { http_response_code(404); exit('Contrat salarié introuvable.'); }
    if (in_array((int)($contract['statut_pers_soignant_code'] ?? 0), [2,3], true)) {
        http_response_code(403); exit('Les prestataires et consultants sont gérés dans le circuit de sous-traitance.');
    }

    $company = (new RhEmployeeContractCompanyService($pdo))->resolve($contract);
    $e = static fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    $money = static fn($v) => number_format((float)$v, 0, ',', ' ').' FCFA';
    $date = static fn($v) => $v && $v !== '0000-00-00' ? date('d/m/Y', strtotime((string)$v)) : '—';
    $type = strtoupper((string)($contract['contract_reference'] ?? 'CONTRAT'));
    $title = match($type) {
        'CDI' => 'CONTRAT DE TRAVAIL À DURÉE INDÉTERMINÉE',
        'CDD' => 'CONTRAT DE TRAVAIL À DURÉE DÉTERMINÉE',
        'STAGE' => 'CONVENTION DE STAGE',
        'VACATAIRE' => 'CONTRAT DE VACATION',
        default => 'CONTRAT DE TRAVAIL'
    };
    $clauses = json_decode((string)($contract['clauses_json'] ?? ''), true);
    $clauses = is_array($clauses) && $clauses ? $clauses : RhEmployeeContractClausePresetService::forType($type);
    $mentions = json_decode((string)($contract['mentions_json'] ?? ''), true);
    $mentions = is_array($mentions) ? $mentions : [];
    $reference = trim((string)($contract['contract_label'] ?? '')) ?: ('CTRH-'.str_pad((string)$contract['id'], 6, '0', STR_PAD_LEFT));

    $root = dirname(__DIR__);
    $logoPath = '';
    $logo = trim((string)($company['logo_path'] ?? ''));
    if ($logo !== '') {
        $candidate = $root.'/'.ltrim($logo, '/');
        if (is_file($candidate)) $logoPath = $candidate;
    }

    $signatureImage = static function(PDO $pdo, $userId): string {
        $userId = (int)$userId; if ($userId < 1) return '';
        try {
            $q = $pdo->prepare('SELECT signature FROM user_devis_banamur WHERE id=? LIMIT 1');
            $q->execute([$userId]);
            $ref = trim((string)($q->fetchColumn() ?: '')); if ($ref === '') return '';
            if (preg_match('~^data:image/(?:png|jpeg);base64,[A-Za-z0-9+/=]+$~', $ref)) return $ref;
            $root = dirname(__DIR__); $allowed = realpath($root.'/uploads/user_signatures'); if (!$allowed) return '';
            foreach ([$root.'/'.ltrim($ref,'/'), $root.'/uploads/user_signatures/'.basename($ref)] as $candidate) {
                $real = realpath($candidate);
                if (!$real || !str_starts_with($real, $allowed.DIRECTORY_SEPARATOR) || !is_file($real)) continue;
                $mime = function_exists('mime_content_type') ? mime_content_type($real) : '';
                if (!in_array($mime, ['image/png','image/jpeg'], true)) continue;
                return 'data:'.$mime.';base64,'.base64_encode((string)file_get_contents($real));
            }
        } catch (Throwable $ignored) {}
        return '';
    };
    $rhSignature = $signatureImage($pdo, $contract['rh_signed_by'] ?? 0);
    $dgSignature = $signatureImage($pdo, $contract['dg_signed_by'] ?? 0);
    $employeeSignature = $signatureImage($pdo, $contract['employee_signed_by'] ?? 0);

    final class EmployeeContractPdf extends TCPDF {
        public array $company = [];
        public string $logo = '';
        public string $documentTitle = '';
        public string $reference = '';

        public function Header(): void {
            $w = $this->getPageWidth();
            // Filigrane de sécurité fondé sur le logo officiel.
            if ($this->logo !== '' && is_file($this->logo)) {
                try {
                    $this->SetAlpha(0.035);
                    $this->Image($this->logo, ($w-92)/2, 86, 92, 92, '', '', '', false, 300, '', false, false, 0);
                    $this->SetAlpha(1);
                } catch (Throwable $ignored) { try { $this->SetAlpha(1); } catch (Throwable $ignored2) {} }
            }

            $this->SetFillColor(15,23,42);
            $this->Rect(0,0,$w,38,'F');
            $this->SetFillColor(185,28,28);
            $this->Rect(0,38,$w,1.4,'F');

            if ($this->logo !== '' && is_file($this->logo)) {
                try { $this->Image($this->logo, 12, 6, 25, 25); } catch (Throwable $ignored) {}
            }

            $this->SetXY(42,5.5);
            $this->SetTextColor(255,255,255);
            $this->SetFont('dejavusans','B',8.6);
            $this->Cell(0,4.5, mb_strtoupper((string)($this->company['legal_name'] ?? 'FINEA'),'UTF-8'),0,1,'L');
            $this->SetX(42);
            $this->SetFont('dejavusans','B',11.4);
            $this->MultiCell(151,5.5,$this->documentTitle,0,'L',false,1);
            $this->SetX(42);
            $this->SetFont('dejavusans','',7.2);
            $this->SetTextColor(203,213,225);
            $this->Cell(95,4.5,'Réf. '.$this->reference,0,0,'L');
            $this->Cell(56,4.5,'DOCUMENT RH • CONFIDENTIEL',0,1,'R');
        }

        public function Footer(): void {
            $this->SetY(-22);
            $this->SetDrawColor(185,28,28);
            $this->SetLineWidth(.35);
            $this->Line(12,$this->GetY(),198,$this->GetY());
            $this->Ln(2);
            $this->SetFont('dejavusans','',6.7);
            $this->SetTextColor(71,85,105);
            $parts = array_filter([
                (string)($this->company['legal_name'] ?? ''),
                (string)($this->company['address_line'] ?? ''),
                (string)($this->company['city'] ?? ''),
                !empty($this->company['phone']) ? 'Tél. '.$this->company['phone'] : '',
                !empty($this->company['email']) ? $this->company['email'] : '',
                !empty($this->company['rccm']) ? 'RCCM '.$this->company['rccm'] : '',
                !empty($this->company['taxpayer_account']) ? 'CC '.$this->company['taxpayer_account'] : '',
            ]);
            $this->MultiCell(145,3.6,implode(' • ',$parts),0,'L',false,0);
            $this->SetXY(160,$this->GetY());
            $this->SetFont('dejavusans','B',7);
            $this->Cell(38,4,'Page '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(),0,0,'R');
        }

        public function ensureSpace(float $needed): void {
            if ($this->GetY() + $needed > $this->getPageHeight() - 27) $this->AddPage();
        }
    }

    $pdf = new EmployeeContractPdf('P','mm','A4',true,'UTF-8',false);
    $pdf->company = $company;
    $pdf->logo = $logoPath;
    $pdf->documentTitle = $title;
    $pdf->reference = $reference;
    $pdf->SetMargins(14,46,14);
    $pdf->SetHeaderMargin(0);
    $pdf->SetFooterMargin(10);
    $pdf->SetAutoPageBreak(true,29);
    $pdf->SetCreator('FINEA RH');
    $pdf->SetAuthor((string)($company['legal_name'] ?? 'FINEA'));
    $pdf->SetSubject('Contrat de travail sécurisé');
    $pdf->SetTitle($title.' - '.$contract['employee_name']);
    $pdf->SetKeywords('FINEA, RH, contrat, '.$reference.', '.$contract['employee_name']);
    $pdf->AddPage();

    // Bandeau d'authenticité + QR de vérification documentaire.
    $verifyPayload = implode('|', [
        'FINEA-CONTRACT',
        (string)$contract['id'],
        $reference,
        (string)$personnelId,
        (string)($contract['signature_workflow_status'] ?? 'brouillon'),
        hash('sha256', $reference.'|'.$personnelId.'|'.($contract['company_snapshot_json'] ?? ''))
    ]);
    $pdf->SetFillColor(248,250,252);
    $pdf->SetDrawColor(226,232,240);
    $pdf->RoundedRect(14,47,182,31,3,'1111','DF');
    $pdf->SetXY(19,51);
    $pdf->SetTextColor(15,23,42);
    $pdf->SetFont('dejavusans','B',10.2);
    $pdf->Cell(135,5,'ACTE CONTRACTUEL AUTHENTIFIÉ',0,1);
    $pdf->SetX(19);
    $pdf->SetFont('dejavusans','',7.5);
    $pdf->SetTextColor(71,85,105);
    $pdf->MultiCell(132,4.1,'Référence : '.$reference."\nSalarié : ".$contract['employee_name']."\nIdentifiant FINEA : contrat #".(int)$contract['id'],0,'L');
    $style = ['border'=>0,'vpadding'=>'auto','hpadding'=>'auto','fgcolor'=>[15,23,42],'bgcolor'=>false,'module_width'=>1,'module_height'=>1];
    $pdf->write2DBarcode($verifyPayload,'QRCODE,H',162,51,24,24,$style,'N');
    $pdf->SetXY(157,75.2);
    $pdf->SetFont('dejavusans','I',5.8);
    $pdf->SetTextColor(100,116,139);
    $pdf->Cell(34,3,'QR de traçabilité',0,1,'C');
    $pdf->SetY(83);

    $companyDetails = implode(' • ', array_filter([
        (string)($company['legal_name'] ?? ''), (string)($company['legal_form'] ?? ''),
        (string)($company['address_line'] ?? ''), (string)($company['city'] ?? ''), (string)($company['country'] ?? ''),
        !empty($company['rccm']) ? 'RCCM '.$company['rccm'] : '',
        !empty($company['taxpayer_account']) ? 'Compte contribuable '.$company['taxpayer_account'] : ''
    ]));
    $html = '<table cellpadding="7" style="width:100%;border:1px solid #dbe3ee;background-color:#ffffff">'
        .'<tr><td width="50%"><span style="color:#b91c1c;font-weight:bold;font-size:9pt">EMPLOYEUR</span><br><br>'
        .'<b>'.$e($company['legal_name'] ?? '').'</b><br>'.$e($companyDetails).'<br><br><b>Représenté par :</b><br>'.$e(($company['representative_name']??'').' · '.($company['representative_title']??''))
        .'</td><td width="50%"><span style="color:#b91c1c;font-weight:bold;font-size:9pt">SALARIÉ</span><br><br><b>'.$e($contract['employee_name']).'</b><br>'
        .'Matricule : <b>'.$e($contract['matricule']).'</b><br>Fonction : <b>'.$e($contract['job_title'] ?: $contract['fonction']).'</b><br>Service : '.$e($contract['service']).'</td></tr></table>';
    $pdf->SetFont('dejavusans','',8.6);
    $pdf->writeHTML($html,true,false,true,false,'');
    $pdf->Ln(3);

    $endDate = $date($contract['end_date']);
    $remuneration = $money((float)$contract['base_salary'] + (float)$contract['over_salary']);
    $html = '<table cellpadding="5" style="width:100%;border:1px solid #dbe3ee">'
        .'<tr style="background-color:#0f172a;color:#ffffff;font-weight:bold"><td colspan="2">CONDITIONS ESSENTIELLES DU CONTRAT</td></tr>'
        .'<tr><td width="36%"><b>Type de contrat</b></td><td><b style="color:#b91c1c">'.$e($type).'</b></td></tr>'
        .'<tr><td><b>Prise d’effet</b></td><td><b>'.$date($contract['start_date']).'</b></td></tr>'
        .'<tr><td><b>Fin</b></td><td>'.($type==='CDD' ? '<b style="color:#b91c1c">'.$endDate.'</b>' : $endDate).'</td></tr>'
        .'<tr><td><b>Lieu de travail</b></td><td>'.$e($contract['workplace']).'</td></tr>'
        .'<tr><td><b>Classification</b></td><td>'.$e($contract['classification']).'</td></tr>'
        .'<tr><td><b>Rémunération de référence</b></td><td><b style="color:#b91c1c">'.$remuneration.'</b></td></tr>'
        .'<tr><td><b>Temps de travail</b></td><td>'.$e($contract['weekly_hours']).' h/semaine • '.$e($contract['work_schedule_label']).' • '.$e(substr((string)$contract['daily_work_start'],0,5)).'–'.$e(substr((string)$contract['daily_work_end'],0,5)).'</td></tr>'
        .'</table>';
    $pdf->writeHTML($html,true,false,true,false,'');
    $pdf->Ln(5);

    $pdf->SetTextColor(15,23,42);
    $pdf->SetFont('dejavusans','B',12);
    $pdf->Cell(0,7,'CLAUSES CONTRACTUELLES',0,1,'L');
    $pdf->SetDrawColor(185,28,28); $pdf->SetLineWidth(.45);
    $pdf->Line(14,$pdf->GetY(),52,$pdf->GetY());
    $pdf->Ln(5);

    $importantPattern = '~(rémun|salaire|rupture|préavis|confidential|non[- ]?concurr|disciplin|sanction|obligation|durée|fin de contrat|restitution|responsabil)~iu';
    foreach ($clauses as $i => $clause) {
        $t = is_array($clause) ? trim((string)($clause['title'] ?? '')) : ucfirst(str_replace('_',' ',(string)$i));
        $b = is_array($clause) ? trim((string)($clause['body'] ?? '')) : trim((string)$clause);
        if ($t === '' && $b === '') continue;

        $approxLines = max(2, (int)ceil(mb_strlen($b,'UTF-8') / 105));
        $pdf->ensureSpace(12 + min(38, $approxLines * 4.8));
        $isImportant = preg_match($importantPattern, $t.' '.$b) === 1;

        $pdf->SetFillColor(248,250,252);
        $pdf->SetDrawColor(226,232,240);
        $y = $pdf->GetY();
        $pdf->RoundedRect(14,$y,182,8,2,'1111','DF');
        $pdf->SetXY(18,$y+1.2);
        $pdf->SetFont('dejavusans','B',9.6);
        $pdf->SetTextColor($isImportant ? 185 : 15, $isImportant ? 28 : 23, $isImportant ? 28 : 42);
        $pdf->MultiCell(174,5.2,$t,0,'L',false,1);
        $pdf->Ln(3.2); // espace clair entre le titre et le contenu de l'article
        $pdf->SetFont('dejavusans','',8.7);
        $pdf->SetTextColor(31,41,55);
        $pdf->MultiCell(178,4.9,$b,0,'J',false,1);
        if ($isImportant) {
            $pdf->SetFont('dejavusans','I',6.8);
            $pdf->SetTextColor(127,29,29);
            $pdf->MultiCell(178,3.8,'Disposition importante — à lire avec une attention particulière.',0,'L',false,1);
        }
        $pdf->Ln(5); // respiration entre articles
    }

    if ($mentions) {
        $pdf->ensureSpace(28);
        $pdf->SetFont('dejavusans','B',11);
        $pdf->SetTextColor(185,28,28);
        $pdf->Cell(0,7,'MENTIONS PARTICULIÈRES',0,1);
        $pdf->Ln(2);
        foreach ($mentions as $m) {
            $pdf->ensureSpace(18);
            $pdf->SetFont('dejavusans','B',9);
            $pdf->SetTextColor(15,23,42);
            $pdf->MultiCell(0,5,(string)($m['title'] ?? ''),0,'L');
            $pdf->Ln(1.5);
            $pdf->SetFont('dejavusans','I',8.5);
            $pdf->SetTextColor(51,65,85);
            $pdf->MultiCell(0,4.8,(string)($m['body'] ?? ''),0,'J');
            $pdf->Ln(4);
        }
    }

    // Page dédiée aux signatures : jamais coupée en bas d'une page de clauses.
    $pdf->AddPage();
    $pdf->SetTextColor(15,23,42);
    $pdf->SetFont('dejavusans','B',15);
    $pdf->Cell(0,8,'SIGNATURES & TRAÇABILITÉ',0,1);
    $pdf->SetFont('dejavusans','',8.4);
    $pdf->SetTextColor(71,85,105);
    $pdf->MultiCell(0,5,'Chaque visa électronique est autorisé par authentification Passkey/WebAuthn et rattaché au contrat, au rôle du signataire et à l’étape du workflow.',0,'L');
    $pdf->Ln(6);

    $cards = [
        ['RESSOURCES HUMAINES',$contract['rh_signer_name'] ?? 'Signataire RH',$contract['rh_signer_role'] ?? 'Ressources humaines',$contract['rh_signed_at'] ?? null,$rhSignature],
        ['DIRECTION GÉNÉRALE',$contract['employer_signer_name'] ?? ($company['representative_name'] ?? 'Représentant employeur'),$contract['employer_signer_role'] ?? ($company['representative_title'] ?? 'Direction générale'),$contract['dg_signed_at'] ?? null,$dgSignature],
        ['EMPLOYÉ',$contract['employee_name'],$contract['job_title'] ?: $contract['fonction'],$contract['employee_signed_at'] ?? null,$employeeSignature]
    ];
    $x=14; $y=$pdf->GetY(); $w=57; $gap=5;
    foreach ($cards as $card) {
        $pdf->SetDrawColor(203,213,225); $pdf->SetFillColor(248,250,252);
        $pdf->RoundedRect($x,$y,$w,61,3,'1111','DF');
        $pdf->SetXY($x+3,$y+4); $pdf->SetTextColor(185,28,28); $pdf->SetFont('dejavusans','B',7.5);
        $pdf->MultiCell($w-6,5,$card[0],0,'L');
        $pdf->SetX($x+3); $pdf->SetTextColor(15,23,42); $pdf->SetFont('dejavusans','B',8.5);
        $pdf->MultiCell($w-6,5,(string)$card[1],0,'L');
        $pdf->SetX($x+3); $pdf->SetFont('dejavusans','I',7.2);
        $pdf->MultiCell($w-6,4,(string)$card[2],0,'L');
        if ($card[3] && $card[4]) {
            try {
                $comma = strpos($card[4], ',');
                $raw = $comma !== false ? base64_decode(substr($card[4],$comma+1),true) : false;
                $imgType = str_starts_with($card[4],'data:image/jpeg') ? 'JPG' : 'PNG';
                if ($raw !== false) $pdf->Image('@'.$raw,$x+7,$y+29,$w-14,13,$imgType,'','',false,300,'C');
            } catch (Throwable $ignored) {}
        }
        $pdf->SetXY($x+3,$y+50); $pdf->SetFont('dejavusans','B',7.2);
        if ($card[3]) { $pdf->SetTextColor(5,150,105); $status='SIGNÉ • '.date('d/m/Y H:i',strtotime((string)$card[3])); }
        else { $pdf->SetTextColor(185,28,28); $status='SIGNATURE EN ATTENTE'; }
        $pdf->MultiCell($w-6,5,$status,0,'L');
        $x += $w+$gap;
    }

    $pdf->SetY($y+69);
    $pdf->SetFillColor(248,250,252); $pdf->SetDrawColor(226,232,240);
    $pdf->RoundedRect(14,$pdf->GetY(),182,31,3,'1111','DF');
    $pdf->SetXY(19,$pdf->GetY()+4);
    $pdf->SetTextColor(71,85,105); $pdf->SetFont('dejavusans','',7.4);
    $pdf->MultiCell(132,4.4,'Identifiant FINEA : contrat #'.(int)$contract['id']."\nStatut : ".(string)($contract['signature_workflow_status'] ?? 'brouillon')."\nEntreprise figée : ".(string)($company['snapshot_at'] ?? 'historique')."\nEmpreinte documentaire : ".substr(hash('sha256',$verifyPayload),0,32),0,'L');
    $pdf->write2DBarcode($verifyPayload,'QRCODE,H',164,$pdf->GetY()-16,22,22,$style,'N');

    $file = $reference.'_'.preg_replace('/[^A-Za-z0-9_-]+/','_',strtoupper((string)$contract['employee_name'])).'.pdf';
    $pdfContent = $pdf->Output($file,'S');
    $storedPath = null;
    if ($saveToDossier) {
        $dir = $root.'/uploads/rh/contrat-embauche';
        if (!is_dir($dir) && !mkdir($dir,0775,true) && !is_dir($dir)) throw new RuntimeException('Impossible de créer le dossier d’archivage des contrats.');
        $safeFile = preg_replace('/[^A-Za-z0-9._-]+/','_',$file);
        $absolute = $dir.'/'.$safeFile;
        if (file_put_contents($absolute,$pdfContent) === false) throw new RuntimeException('Impossible d’archiver le contrat généré.');
        @chmod($absolute,0664);
        $storedPath = 'uploads/rh/contrat-embauche/'.$safeFile;
        $signed = (string)($contract['signature_workflow_status'] ?? '') === 'signe';
        $pdo->prepare('UPDATE employee_contracts SET generated_document_path=?, signed_document_path=IF(?, ?, signed_document_path) WHERE id=?')->execute([$storedPath,$signed?1:0,$storedPath,(int)$contract['id']]);
        try {
            $has=$pdo->prepare("SHOW COLUMNS FROM personnel LIKE 'contrat_embauche_path'"); $has->execute();
            if($has->fetch(PDO::FETCH_ASSOC)) $pdo->prepare('UPDATE personnel SET contrat_embauche_path=? WHERE id_personnel_soignant=?')->execute([$storedPath,$personnelId]);
        } catch(Throwable $ignored) {}
    }

    AuditTrail::logFileEvent('export',[
        'module'=>'rh','action'=>'export_employee_contract','entity_type'=>'employee_contract','entity_id'=>(string)$contract['id'],'entity_label'=>$reference,
        'format'=>'pdf','user_id'=>$_SESSION['user_id']??null,'user_name'=>$_SESSION['user_name']??null,
        'details'=>['personnel_id'=>$personnelId,'company_profile_id'=>$contract['company_profile_id']??null,'stored_path'=>$storedPath,'workflow_status'=>$contract['signature_workflow_status']??null]
    ],$pdo);

    while(ob_get_level()>0){ob_end_clean();}
    header('Content-Type: application/pdf');
    header('Content-Length: '.strlen($pdfContent));
    header('Content-Disposition: '.($download?'attachment':'inline').'; filename="'.$file.'"');
    echo $pdfContent; exit;
} catch(Throwable $e) {
    error_log('[RH contract preview] '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Impossible de générer l’aperçu du contrat. Vérifiez que le contrat et l’entreprise sont correctement renseignés. Détail : '.$e->getMessage());
}
