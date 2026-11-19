<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Fiche.php';
require_once __DIR__ . '/../model/Precision.php';
require_once __DIR__ . '/../model/Designation.php';

try {
    $pdo = Database::getConnection();
    $ficheObj = new Fiche($pdo);
    $precisionObj = new Precision($pdo);
    $designationObj = new Designation($pdo);

    $all = $ficheObj->getAll();

    $q = $_GET;
    // Optionnel: filtrage statut
    $status = $q['status'] ?? '';
    $fiches = array_values(array_filter($all, function ($f) use ($q, $status) {
        if (!empty($q['date_min']) && substr($f['date_creat_fiche'], 0, 10) < $q['date_min']) return false;
        if (!empty($q['date_max']) && substr($f['date_creat_fiche'], 0, 10) > $q['date_max']) return false;
        if (!empty($q['search'])) {
            $s = mb_strtolower($q['search']);
            $hay = mb_strtolower(($f['num_fiche'] ?? '') . ' ' . ($f['beficiaire_fiche'] ?? '') . ' ' . ($f['designation_fiche'] ?? ''));
            if (mb_strpos($hay, $s) === false) return false;
        }
        if ($status === 'approuve' && (int)($f['approuve'] ?? 0) !== 1) return false;
        if ($status === 'refuse' && (int)($f['approuve'] ?? 0) !== 2) return false;
        if ($status === 'conforme' && (int)($f['conforme'] ?? 0) !== 1) return false;
        if ($status === 'valide' && (int)($f['etat_fiche'] ?? 0) !== 1) return false;
        return true;
    }));

    // Mapper + statut lisible
    $items = array_map(function ($fiche) use ($precisionObj, $designationObj) {
        $photo = trim($fiche['photo_beneficiaire'] ?? '');
        $photoRel = '../uploads/photo/' . $photo;
        $photoExists = $photo && file_exists(__DIR__ . '/../../uploads/photo/' . $photo);
        $st = 'En cours';
        $badge = 'gray';
        if ((int)($fiche['approuve'] ?? 0) === 2) {
            $st = 'Refusée';
            $badge = 'red';
        } elseif ((int)($fiche['approuve'] ?? 0) === 1) {
            $st = 'Approuvée';
            $badge = 'green';
        } elseif ((int)($fiche['conforme'] ?? 0) === 1) {
            $st = 'Conforme';
            $badge = 'blue';
        }
        if ((int)($fiche['etat_fiche'] ?? 0) === 1) {
            $st = 'Validée achat';
            $badge = 'indigo';
        }
        return [
            'id_fiche' => $fiche['id_fiche'],
            'num_fiche' => $fiche['num_fiche'],
            'beficiaire_fiche' => $fiche['beficiaire_fiche'],
            'montant_fiche' => (float)$fiche['montant_fiche'],
            'date_creat_fiche' => $fiche['date_creat_fiche'],
            'photo' => $photoExists ? $photoRel : null,
            'precision' => $precisionObj->getPrecisionById($fiche['precision_fiche'])['libelle'] ?? '',
            'designation' => $designationObj->getDesignationById($fiche['designation_fiche'])['designation'] ?? '',
            'status' => $st,
            'badge' => $badge,
        ];
    }, $fiches);

    echo json_encode([
        'ok' => true,
        'count' => count($items),
        'total' => array_sum(array_column($items, 'montant_fiche')),
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
