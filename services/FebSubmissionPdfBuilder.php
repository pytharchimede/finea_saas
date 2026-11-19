<?php
declare(strict_types=1);

require_once __DIR__ . '/FebHumanReviewQuotePdfBuilder.php';

/** Présentation FEB alignée sur le devis interne FEB-REV (gabarit ISO BANAMUR). */
final class FebSubmissionPdfBuilder
{
    public static function build(array $request, array $payload, int $requestId, bool $isDraftPreview = false, bool $isSignedRh = false): FebHumanReviewQuotePdfDocument
    {
        $documentId = $isDraftPreview ? 'APERÇU NON SOUMIS' : 'FEB-' . $requestId;
        $object = trim((string)($payload['feb_quote_title'] ?? $payload['description'] ?? 'Expression de besoin'));
        $needs = array_values(array_filter((array)($payload['besoins'] ?? []), 'is_array'));
        $draft = is_array($payload['provisional_decaissement'] ?? null) ? $payload['provisional_decaissement'] : [];
        $status = strtolower(trim((string)($request['status'] ?? 'submitted')));
        $statusLabel = ['draft'=>'Brouillon','submitted'=>'En attente','approved'=>'Approuvée','rejected'=>'Refusée','cancelled'=>'Annulée','details_requested'=>'Compléments demandés'][$status] ?? $status;
        $calculated = 0.0;
        foreach ($needs as $need) {
            $supplier = is_array($need['fournisseur'] ?? null) ? $need['fournisseur'] : [];
            $calculated += max(0.0, (float)($need['quantite'] ?? 0)) * max(0.0, (float)($supplier['prix_unitaire'] ?? 0));
        }
        $declared = (float)($payload['montant'] ?? 0);
        $total = $declared > 0 ? $declared : $calculated;
        $firstSupplier = '';
        foreach ($needs as $need) {
            $name = trim((string)($need['fournisseur']['nom'] ?? ''));
            if ($name !== '') { $firstSupplier = $name; break; }
        }

        $pdf = new FebHumanReviewQuotePdfDocument('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->companyPdfTitle = 'FICHE D’EXPRESSION DE BESOIN';
        $pdf->companyPdfType = 'FEB · Projet';
        $pdf->companyPdfId = $documentId;
        $pdf->companyPdfCode = 'FIN-FEB-ISO9001';
        $pdf->companyPdfQrPayload = json_encode(['type'=>$isDraftPreview?'feb_preview':'feb','id'=>$requestId?:null,'status'=>$status,'object'=>$object,'generated_at'=>date(DATE_ATOM)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $documentId;
        $pdf->SetCreator('FINEA');
        $pdf->SetAuthor('BANAMUR Industries & Technologies');
        $pdf->SetTitle($isDraftPreview ? 'Aperçu FEB avant soumission' : 'Fiche expression de besoin FEB-' . $requestId);
        $pdf->SetSubject($object);
        $pdf->SetMargins(12, 39, 12);
        $pdf->SetAutoPageBreak(true, 25);
        $pdf->AddPage();

        // Même cartouche d'ouverture que le devis saisi FINEA joint par l'utilisateur.
        $pdf->SetFillColor(248, 250, 252);
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->RoundedRect(12, 40, 186, 29, 3, '1111', 'DF');
        $pdf->SetFillColor(250, 189, 2);
        $pdf->Rect(12, 40, 3, 29, 'F');
        $pdf->SetXY(20, 44);
        $pdf->SetFont('dejavusans', 'B', 8);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell(170, 5, 'FICHE D’EXPRESSION DE BESOIN SAISIE DANS FINEA', 0, 1);
        $pdf->SetX(20);
        $pdf->SetFont('dejavusans', 'B', 15);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(170, 8, 'FEB INTERNE · ' . $documentId, 0, 1);
        $pdf->SetX(20);
        $pdf->SetFont('dejavusans', '', 7.6);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell(170, 5, 'Document généré le ' . FebHumanReviewQuotePdfBuilder::dateLabel('now') . ' · Référence qualité FIN-FEB-ISO9001', 0, 1);

        FebHumanReviewQuotePdfBuilder::section($pdf, '01', 'Contexte de la demande', 75);
        FebHumanReviewQuotePdfBuilder::infoCard($pdf, 12, 83, 90, 'DEMANDEUR', (string)($request['requester_name'] ?? $payload['nom'] ?? ''), 18);
        FebHumanReviewQuotePdfBuilder::infoCard($pdf, 108, 83, 90, 'SERVICE BÉNÉFICIAIRE', $isSignedRh ? 'Ressources humaines' : (string)($payload['departement_label'] ?? ''), 18);
        FebHumanReviewQuotePdfBuilder::infoCard($pdf, 12, 105, 90, $isSignedRh ? 'CIRCUIT DE PAIEMENT' : 'FOURNISSEUR DÉCLARÉ', $isSignedRh ? 'Personnel · sans fournisseur' : $firstSupplier, 18);
        FebHumanReviewQuotePdfBuilder::infoCard($pdf, 108, 105, 90, 'MODE DE PAIEMENT · ' . mb_strtoupper($statusLabel), (string)($draft['mode_paiement'] ?? ''), 18);

        FebHumanReviewQuotePdfBuilder::section($pdf, '02', 'Détail des besoins exprimés', 129);
        $pdf->SetY(138);
        FebHumanReviewQuotePdfBuilder::tableHeader($pdf);
        foreach ($needs as $index => $need) {
            $supplier = is_array($need['fournisseur'] ?? null) ? $need['fournisseur'] : [];
            $quantity = max(0.0, (float)($need['quantite'] ?? 0));
            $unitPrice = max(0.0, (float)($supplier['prix_unitaire'] ?? 0));
            FebHumanReviewQuotePdfBuilder::tableRow($pdf, $index + 1, (string)($need['libelle'] ?? ''), (string)($need['type'] ?? $need['categorie'] ?? ''), $quantity, $unitPrice, $quantity * $unitPrice, $index % 2 === 1);
        }
        if (!$needs) {
            $pdf->SetFillColor(248, 250, 252);
            $pdf->SetTextColor(100, 116, 139);
            $pdf->SetFont('dejavusans', '', 9);
            $pdf->MultiCell(186, 14, 'Aucune ligne de besoin transmise.', 1, 'C', true, 1, 12, $pdf->GetY(), true, 0, false, true, 14, 'M');
        }
        FebHumanReviewQuotePdfBuilder::ensureSpace($pdf, 48, false);
        $barY = $pdf->GetY() + 5;
        $pdf->SetFillColor(15, 23, 42);
        $pdf->RoundedRect(12, $barY, 186, 19, 3, '1111', 'F');
        $pdf->SetXY(19, $barY + 5);
        $pdf->SetTextColor(203, 213, 225);
        $pdf->SetFont('dejavusans', 'B', 8);
        $pdf->Cell(80, 7, 'TOTAL DÉCLARÉ DANS LA DEMANDE', 0, 0);
        $pdf->SetTextColor(250, 189, 2);
        $pdf->SetFont('dejavusans', 'B', 15);
        $pdf->Cell(92, 8, FebHumanReviewQuotePdfBuilder::money($total), 0, 1, 'R');
        $pdf->SetY($barY + 24);
        if ($needs && abs($calculated - $total) > 1) {
            $pdf->SetTextColor(180, 83, 9);
            $pdf->SetFont('dejavusans', 'B', 8);
            $pdf->MultiCell(186, 7, 'À vérifier : le total des lignes est ' . FebHumanReviewQuotePdfBuilder::money($calculated) . '.', 0, 'L');
        }

        FebHumanReviewQuotePdfBuilder::ensureSpace($pdf, 55, false);
        $planningY = $pdf->GetY() + 5;
        FebHumanReviewQuotePdfBuilder::section($pdf, '03', 'Affectation et planning', $planningY);
        FebHumanReviewQuotePdfBuilder::infoCard($pdf, 12, $planningY + 9, 90, 'DÉBUT PRÉVU', self::dateLabel($payload['planned_start_date'] ?? ''), 18);
        FebHumanReviewQuotePdfBuilder::infoCard($pdf, 108, $planningY + 9, 90, 'FIN PRÉVUE', self::dateLabel($payload['planned_end_date'] ?? ''), 18);
        $pdf->SetY($planningY + 31);
        $details = trim((string)($draft['details_demande'] ?? $object));
        if ($details !== '') {
            $pdf->SetTextColor(100, 116, 139);
            $pdf->SetFont('dejavusans', '', 7.4);
            $pdf->MultiCell(186, 11, 'Objet : ' . $details, 0, 'L', false, 1, 12, $pdf->GetY(), true, 0, false, true, 11, 'T');
        }
        $pdf->SetTextColor(100, 116, 139);
        $pdf->SetFont('dejavusans', '', 7.4);
        $pdf->MultiCell(186, 10, 'Ce document restitue les données saisies dans FINEA. Les justificatifs et le devis original restent consultables séparément dans le dossier FEB.', 0, 'L', false, 1, 12, $pdf->GetY() + 2);
        return $pdf;
    }

    private static function dateLabel($value): string
    {
        $raw = trim((string)$value);
        if ($raw === '') return 'Non renseignée';
        try { return (new DateTimeImmutable($raw))->format('d/m/Y'); }
        catch (Throwable) { return $raw; }
    }
}

