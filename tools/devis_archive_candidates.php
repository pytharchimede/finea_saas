<?php
declare(strict_types=1);

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/CommercialProjectFinanceController.php';

$apply = in_array('--apply', $argv, true);
$userId = 0;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--user-id=')) {
        $userId = (int) substr($argument, 10);
    }
}
if ($apply && $userId <= 0) {
    fwrite(STDERR, "--user-id est obligatoire avec --apply.\n");
    exit(2);
}

$pdo = Database::getConnection();
$controller = new CommercialProjectFinanceController($pdo);
$rows = $pdo->query(
    "SELECT d.id,d.numero_devis,d.created_at
     FROM devis_banamur d
     LEFT JOIN devis_archives a ON a.devis_id=d.id AND a.restored_at IS NULL
     WHERE a.devis_id IS NULL
       AND (COALESCE(d.validation_technique,0)=0 OR COALESCE(d.validation_generale,0)=0)
       AND NOT EXISTS(SELECT 1 FROM chantier_banamur c WHERE c.devis_id=d.id)
       AND NOT EXISTS(SELECT 1 FROM facture_vente_banamur fv JOIN chantier_banamur c ON c.id=fv.chantier_id WHERE c.devis_id=d.id)
       AND NOT EXISTS(SELECT 1 FROM debourse_banamur db JOIN ligne_debourse_banamur ldb ON ldb.debourse_id=db.id JOIN fiche_decaissement fd ON fd.ligne_debourse_id=ldb.id WHERE db.devis_id=d.id)
     ORDER BY d.id"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo ($apply ? 'APPLY' : 'DRY-RUN') . ' candidats=' . count($rows) . PHP_EOL;
foreach ($rows as $row) {
    echo sprintf("#%d %s %s\n", $row['id'], $row['numero_devis'], $row['created_at']);
    if ($apply) {
        $controller->archiveQuote(
            (int) $row['id'],
            $userId,
            'Nettoyage contrôlé des devis brouillons sans dépendance'
        );
    }
}
