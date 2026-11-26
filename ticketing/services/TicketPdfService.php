<?php
namespace Ticketing\Services;

final class TicketPdfService
{
    public function render(array $ticket, array $executors, bool $print = false): string
    {
        require_once dirname(__DIR__, 2).'/vendor/autoload.php';
        require_once dirname(__DIR__, 2).'/model/CompanyExportBranding.php';
        $branding = \CompanyBranding::get();
        $company = (string)$branding['company_name'];
        $pdf = new class extends \TCPDF {
            public string $reference = '';
            public string $company = '';
            public string $logo = '';
            public function Header() {
                $this->SetFillColor(...\CompanyExportBranding::rgbArray('primary')); $this->Rect(0,0,210,5,'F');
                if ($this->logo !== '' && is_file($this->logo)) $this->Image($this->logo,14,9,23,23);
                $this->SetFont('dejavusans','B',17); $this->SetTextColor(...\CompanyExportBranding::rgbArray('primary'));
                $this->SetXY(43,12); $this->Cell(150,8,$this->company,0,1);
                $this->SetFont('dejavusans','',8); $this->SetXY(43,22); $this->Cell(150,6,'CENTRE DE SERVICES • BORDEREAU DE TRANSMISSION',0,1);
                $this->SetXY(43,28); $this->SetFont('dejavusans','',6.5); $this->Cell(150,5,\CompanyExportBranding::identityLine(),0,1);
                $this->SetDrawColor(...\CompanyExportBranding::rgbArray('accent')); $this->SetLineWidth(1); $this->Line(14,36,196,36);
            }
            public function Footer() {
                $this->SetY(-16); $this->SetDrawColor(220,225,235); $this->Line(14,$this->GetY(),196,$this->GetY());
                $this->SetFont('dejavusans','',7); $this->SetTextColor(100,116,139);
                $this->SetXY(14,-15); $this->Cell(182,4,\CompanyExportBranding::contactLine(),0,1,'C');
                $this->SetX(14); $this->Cell(150,7,$this->company.' • FINEA   |   '.$this->reference.'   |   Document interne');
                $this->Cell(32,9,$this->getAliasNumPage().' / '.$this->getAliasNbPages(),0,0,'R');
            }
        };
        $pdf->company=$company;
        $pdf->logo=\CompanyBranding::logoFile();
        $pdf->reference=(string)$ticket['ticket_no'];
        $pdf->SetCreator(\CompanyExportBranding::creator()); $pdf->SetAuthor((string)$ticket['requester_name']);
        $pdf->SetTitle('Ticket '.$ticket['ticket_no']);
        $pdf->SetMargins(14,43,14); $pdf->SetAutoPageBreak(true,22); $pdf->AddPage();
        $pdf->SetFont('dejavusans','',10); $pdf->SetTextColor(...\CompanyExportBranding::rgbArray('primary'));
        $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
        $date=static fn($v)=>$v ? date('d/m/Y à H:i',strtotime((string)$v)) : 'À confirmer';
        $status=\Ticketing\Models\Ticket::STATUSES[$ticket['status']] ?? $ticket['status'];
        $pdf->writeHTML('<span style="font-size:10px;color:#64748b">'.$e($ticket['ticket_no']).' • '.$e($status).'</span><h1 style="font-size:22px">'.$e($ticket['title']).'</h1>',true,false,true,false,'');
        $fields=[
            'ÉMETTEUR'=>$ticket['requester_name'].' · '.($ticket['requester_service'] ?? ''),
            'DESTINATAIRE'=>trim(($ticket['assigned_service'] ?? '').' · '.($ticket['assigned_user_name'] ?? ''),' ·'),
            'CRÉÉ LE'=>$date($ticket['created_at']),
            'CATÉGORIE / PRIORITÉ'=>($ticket['category_name'] ?? '—').' / '.($ticket['priority_name'] ?? '—'),
            'CHANTIER / RÉFÉRENCE'=>trim(($ticket['chantier'] ?? '').' · '.($ticket['related_reference'] ?? ''),' ·') ?: '—',
            'DÉLAI DE RENDU'=>!empty($ticket['deadline_set_at']) ? $date($ticket['due_at']).' — confirmé par '.$ticket['deadline_set_by_name'] : 'À confirmer par le responsable (échéance indicative : '.$date($ticket['due_at']).')'
        ];
        $html='<table cellpadding="7" cellspacing="0">';
        foreach($fields as $label=>$value) $html.='<tr nobr="true"><td width="29%" style="background-color:#eef2f8;color:#64748b;font-size:8px">'.$label.'</td><td width="71%" style="background-color:#f8fafc">'.$e($value).'</td></tr>';
        $pdf->writeHTML($html.'</table><br><h3>01 / Détails de la demande</h3><div style="color:#334155;line-height:1.5">'.nl2br($e($ticket['description'])).'</div>',true,false,true,false,'');
        if($pdf->GetY()>210) $pdf->AddPage();
        $pdf->Ln(4); $pdf->SetFont('dejavusans','B',11); $pdf->Cell(0,8,'02 / Visa de l’émetteur',0,1);
        $y=$pdf->GetY(); $pdf->SetDrawColor(215,222,235); $pdf->RoundedRect(14,$y,182,36,3);
        $pdf->SetXY(19,$y+4); $pdf->SetFont('dejavusans','B',10); $pdf->MultiCell(110,6,(string)$ticket['requester_name'],0,'L');
        $pdf->SetFont('dejavusans','',8); $pdf->SetX(19);
        $pdf->MultiCell(110,5,!empty($ticket['requester_signed_at']) ? 'Signature du profil apposée à la création' . "\n".$date($ticket['requester_signed_at']) : 'Signature du profil actuel de l’émetteur',0,'L');
        $data=(string)($ticket['requester_signature_data'] ?? '');
        if(preg_match('#^data:image/(png|jpeg);base64,(.+)$#s',$data,$m) && ($bin=base64_decode($m[2],true))!==false && strlen($bin)<=2097152 && @getimagesizefromstring($bin)) {
            $pdf->Image('@'.$bin,143,$y+4,44,25,strtoupper($m[1]),'', '',true,300,'',false,false,0,'CM');
        } else { $pdf->SetXY(140,$y+9); $pdf->MultiCell(50,6,'Signature non disponible',0,'L'); }
        $pdf->SetY($y+41);
        $signatureSectionHeight=22+49*(int)ceil(count($executors)/2);
        if($pdf->GetY()+min(225,$signatureSectionHeight)>274) $pdf->AddPage();
        $pdf->SetFont('dejavusans','B',11); $pdf->Cell(0,8,'03 / Réception par les exécutants',0,1);
        $pdf->SetFont('dejavusans','',8); $pdf->MultiCell(0,5,'Chaque exécutant renseigne la date et signe sa case à réception. Numériser ensuite le document et le joindre au ticket comme preuve de réception.',0,'L'); $pdf->Ln(3);
        if(!$executors) { $pdf->MultiCell(0,6,'Aucun exécutant identifié : vérifier l’affectation du ticket avant remise.',0,'L'); }
        foreach(array_chunk($executors,2) as $pair) {
            if($pdf->GetY()+49>274) $pdf->AddPage();
            $y=$pdf->GetY();
            foreach($pair as $col=>$person) {
                $x=14+$col*94;
                $pdf->SetDrawColor(210,218,232); $pdf->RoundedRect($x,$y,88,45,3);
                $pdf->SetXY($x+4,$y+3); $pdf->SetFont('dejavusans','B',9); $pdf->MultiCell(80,5,(string)$person['name'],0,'L',false,1,'','',true,0,false,true,13,'T',true);
                $pdf->SetXY($x+4,$y+17); $pdf->SetFont('dejavusans','',8); $pdf->Cell(80,5,'Reçu le : ____ / ____ / ______    à ____ : ____');
                $pdf->SetXY($x+4,$y+25); $pdf->SetTextColor(100,116,139); $pdf->Cell(80,5,'Signature manuscrite'); $pdf->SetTextColor(...\CompanyExportBranding::rgbArray('primary'));
            }
            $pdf->SetY($y+49);
        }
        if($print) $pdf->IncludeJS('print(true);');
        return $pdf->Output('ticket.pdf','S');
    }
}
