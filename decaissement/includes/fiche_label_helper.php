<?php

function build_fiche_ligne_debourse_base(array $fiche): string
{
    $base = trim((string)($fiche['ligne_designation'] ?? ''));
    if ($base === '') {
        $base = trim((string)($fiche['precision_fiche'] ?? ''));
    }
    if ($base === '' && !empty($fiche['ligne_debourse_id'])) {
        $base = 'Réf. ligne supprimée #' . (int)$fiche['ligne_debourse_id'];
    }
    return $base;
}

function build_fiche_ligne_debourse_label(array $fiche): string
{
    $base = build_fiche_ligne_debourse_base($fiche);
    $detail = trim((string)($fiche['ligne_categorie'] ?? ''));

    if ($base !== '' && $detail !== '') {
        return $base . ' (' . $detail . ')';
    }
    if ($base !== '') {
        return $base;
    }
    return $detail;
}

function build_fiche_ligne_devis_label(array $fiche): string
{
    $label = trim((string)($fiche['devis_designation'] ?? ''));
    if ($label === '') {
        $label = trim((string)($fiche['designation_fiche'] ?? ''));
    }
    return $label;
}

function build_fiche_details_label(array $fiche): string
{
    $details = trim((string)($fiche['details_demande'] ?? ''));
    if ($details !== '') {
        return $details;
    }

    $parentDetails = trim((string)($fiche['parent_details_demande'] ?? ''));
    if ($parentDetails !== '') {
        return $parentDetails;
    }

    return trim((string)($fiche['precision_fiche'] ?? ''));
}

function enrich_fiche_beneficiary(array $fiche): array
{
    $supplierId = (int)($fiche['fournisseur_id'] ?? 0);
    if ($supplierId > 0 && class_exists('Database')) {
        static $supplierCache = [];

        if (!array_key_exists($supplierId, $supplierCache)) {
            $supplierCache[$supplierId] = null;
            try {
                $pdo = Database::getConnection();
                $stmt = $pdo->prepare("SELECT id_fournisseur, person_first_name, person_last_name, nom_commercial, raison_sociale, telephone, email, adresse, photo_path, logo_path
                    FROM fournisseur_banamur
                    WHERE id_fournisseur = :id
                    LIMIT 1");
                $stmt->execute(['id' => $supplierId]);
                $supplierCache[$supplierId] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {
                error_log('[decaissement] beneficiary supplier resolution failed: ' . $e->getMessage());
            }
        }

        $supplier = $supplierCache[$supplierId];
        if (is_array($supplier)) {
            $personName = trim((string)($supplier['person_first_name'] ?? '') . ' ' . (string)($supplier['person_last_name'] ?? ''));
            $commercialName = trim((string)($supplier['nom_commercial'] ?? ''));
            $legalName = trim((string)($supplier['raison_sociale'] ?? ''));
            $name = $commercialName !== '' ? $commercialName : ($legalName !== '' ? $legalName : $personName);

            if ($name !== '') {
                $fiche['beneficiary_display_name'] = $name;
                $fiche['beficiaire_fiche'] = $name;
            }

            $phone = trim((string)($supplier['telephone'] ?? ''));
            if ($phone !== '') {
                $fiche['beneficiary_display_phone'] = $phone;
                $fiche['tel_beneficiaire_fiche'] = $phone;
            }

            $fiche['beneficiary_email'] = trim((string)($supplier['email'] ?? ''));
            $fiche['beneficiary_address'] = trim((string)($supplier['adresse'] ?? ''));

            $photoPath = trim((string)($supplier['photo_path'] ?? ''));
            $logoPath = trim((string)($supplier['logo_path'] ?? ''));
            $visual = $photoPath !== '' ? $photoPath : $logoPath;
            $fiche['beneficiary_visual_path'] = $visual;
            $fiche['photo_beneficiaire'] = $visual;
            $fiche['beneficiary_type'] = 'fournisseur';
            $fiche['beneficiary_supplier_id'] = $supplierId;

            return $fiche;
        }
    }

    $personnelId = (int)($fiche['personnel_id'] ?? 0);
    if ($personnelId > 0 && class_exists('Database')) {
        static $personnelCache = [];

        if (!array_key_exists($personnelId, $personnelCache)) {
            $personnelCache[$personnelId] = null;
            try {
                $pdo = Database::getConnection();
                $stmt = $pdo->prepare("SELECT id_personnel_soignant, nom_personnel_soignant, tel_personnel_soignant, photo_personnel_soignant
                    FROM personnel
                    WHERE id_personnel_soignant = :id
                    LIMIT 1");
                $stmt->execute(['id' => $personnelId]);
                $personnelCache[$personnelId] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {
                error_log('[decaissement] beneficiary personnel resolution failed: ' . $e->getMessage());
            }
        }

        $personnel = $personnelCache[$personnelId];
        if (is_array($personnel)) {
            $name = trim((string)($personnel['nom_personnel_soignant'] ?? ''));
            $phone = trim((string)($personnel['tel_personnel_soignant'] ?? ''));
            $visual = trim((string)($personnel['photo_personnel_soignant'] ?? ''));

            if ($name !== '') {
                $fiche['beneficiary_display_name'] = $name;
                $fiche['beficiaire_fiche'] = $name;
            }
            if ($phone !== '') {
                $fiche['beneficiary_display_phone'] = $phone;
                $fiche['tel_beneficiaire_fiche'] = $phone;
            }
            $fiche['beneficiary_visual_path'] = $visual;
            $fiche['photo_beneficiaire'] = $visual;
            $fiche['beneficiary_type'] = 'personnel';
            $fiche['beneficiary_personnel_id'] = $personnelId;

            return $fiche;
        }
    }

    $fiche['beneficiary_display_name'] = trim((string)($fiche['beneficiary_display_name'] ?? $fiche['beficiaire_fiche'] ?? ''));
    $fiche['beneficiary_display_phone'] = trim((string)($fiche['beneficiary_display_phone'] ?? $fiche['tel_beneficiaire_fiche'] ?? ''));
    $fiche['beneficiary_visual_path'] = trim((string)($fiche['beneficiary_visual_path'] ?? $fiche['photo_beneficiaire'] ?? ''));
    $fiche['photo_beneficiaire'] = $fiche['beneficiary_visual_path'];
    $fiche['beneficiary_type'] = $fiche['beneficiary_type'] ?? 'historique';
    return $fiche;
}

function build_fiche_link_status(array $fiche): array
{
    $ligneDebourseId = (int)($fiche['ligne_debourse_id'] ?? 0);
    $linkedId = (int)($fiche['linked_id'] ?? 0);
    $debourseId = (int)($fiche['debourse_id'] ?? 0);
    $chantierDevisId = (int)($fiche['chantier_devis_id'] ?? 0);
    $linkedDevisId = (int)($fiche['linked_devis_id'] ?? 0);

    $hasFallbackDevis = trim((string)($fiche['devis_designation'] ?? '')) === '' && trim((string)($fiche['designation_fiche'] ?? '')) !== '';
    $hasFallbackLine = trim((string)($fiche['ligne_designation'] ?? '')) === '' && trim((string)($fiche['precision_fiche'] ?? '')) !== '';
    $usesFallback = $hasFallbackDevis || $hasFallbackLine;

    if ($ligneDebourseId <= 0) {
        return [
            'code' => 'absent',
            'label' => 'Aucun lien réel',
            'description' => 'Aucune ligne de déboursé valide n’est enregistrée sur cette fiche.',
            'uses_fallback' => $usesFallback,
        ];
    }

    if ($chantierDevisId > 0 && $linkedDevisId > 0 && $chantierDevisId !== $linkedDevisId) {
        return [
            'code' => 'wrong_devis',
            'label' => 'Lien incohérent',
            'description' => 'La ligne rattachée existe mais appartient à un autre devis que celui du chantier.',
            'uses_fallback' => $usesFallback,
        ];
    }

    if ($linkedId > 0 && $debourseId > 0) {
        return [
            'code' => 'valid',
            'label' => 'Lien réel valide',
            'description' => 'Le rattachement vers la ligne de déboursé et son déboursé parent est valide.',
            'uses_fallback' => false,
        ];
    }

    return [
        'code' => 'broken',
        'label' => 'Lien cassé',
        'description' => $usesFallback
            ? 'Le lien réel est cassé. Les libellés affichés ci-dessous proviennent d’un fallback reconstitué depuis la fiche.'
            : 'Le lien réel est cassé et aucune jointure valide n’a été retrouvée.',
        'uses_fallback' => $usesFallback,
    ];
}

function enrich_fiche_labels(array $fiche): array
{
    $fiche = enrich_fiche_beneficiary($fiche);
    $fiche['ligne_debourse_base'] = build_fiche_ligne_debourse_base($fiche);
    $fiche['ligne_debourse_label'] = build_fiche_ligne_debourse_label($fiche);
    $fiche['ligne_devis_label'] = build_fiche_ligne_devis_label($fiche);
    $fiche['details_label'] = build_fiche_details_label($fiche);
    $linkStatus = build_fiche_link_status($fiche);
    $fiche['link_status_code'] = $linkStatus['code'];
    $fiche['link_status_label'] = $linkStatus['label'];
    $fiche['link_status_description'] = $linkStatus['description'];
    $fiche['link_status_uses_fallback'] = !empty($linkStatus['uses_fallback']) ? 1 : 0;
    return $fiche;
}
