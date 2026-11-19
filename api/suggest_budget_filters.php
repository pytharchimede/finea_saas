<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../services/ChantierPauseService.php';

header('Content-Type: application/json; charset=utf-8');

function json_out(array $payload): void
{
    echo json_encode($payload);
    exit;
}

function db_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :col");
        $st->execute([':col' => $column]);
        $cache[$key] = (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

try {
    $type = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

    if ($type === '' || $q === '') {
        json_out(['ok' => true, 'items' => []]);
    }

    $pdo = Database::getConnection();
    $limit = 20;
    $like = '%' . $q . '%';

    if ($type === 'chantier') {
        $activeClause = '';
        if (db_has_column($pdo, 'chantier_banamur', 'archived_at')) {
            $activeClause = 'AND c.archived_at IS NULL';
        }

        $sql = "
            SELECT c.id, c.code, c.titre
            FROM chantier_banamur c
            WHERE (c.code LIKE :q OR c.titre LIKE :q)
            $activeClause
            AND ".ChantierPauseService::activePredicate($pdo,'c.id')."
            ORDER BY c.date_creation DESC
            LIMIT $limit
        ";
        $st = $pdo->prepare($sql);
        $st->execute([':q' => $like]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        foreach ($rows as $r) {
            $label = trim((string)($r['code'] ?? ''));
            $titre = trim((string)($r['titre'] ?? ''));
            if ($titre !== '') {
                $label = $label !== '' ? ($label . ' - ' . $titre) : $titre;
            }
            $items[] = ['value' => (string)($r['id'] ?? ''), 'label' => $label];
        }

        json_out(['ok' => true, 'items' => $items]);
    }

    if ($type === 'client') {
        $sql = "
            SELECT DISTINCT cl.nom_client
            FROM client_banamur cl
            WHERE cl.nom_client IS NOT NULL AND cl.nom_client != ''
              AND cl.nom_client LIKE :q
            ORDER BY cl.nom_client ASC
            LIMIT $limit
        ";
        $st = $pdo->prepare($sql);
        $st->execute([':q' => $like]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        foreach ($rows as $r) {
            $name = trim((string)($r['nom_client'] ?? ''));
            if ($name === '') continue;
            $items[] = ['value' => $name, 'label' => $name];
        }

        json_out(['ok' => true, 'items' => $items]);
    }

    if ($type === 'entreprise') {
        $activeClause = '';
        if (db_has_column($pdo, 'chantier_banamur', 'archived_at')) {
            $activeClause = 'AND c.archived_at IS NULL';
        }

        $sql = "
            SELECT DISTINCT c.entreprise
            FROM chantier_banamur c
            WHERE c.entreprise IS NOT NULL AND c.entreprise != ''
              AND c.entreprise LIKE :q
              $activeClause
            ORDER BY c.entreprise ASC
            LIMIT $limit
        ";
        $st = $pdo->prepare($sql);
        $st->execute([':q' => $like]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        foreach ($rows as $r) {
            $name = trim((string)($r['entreprise'] ?? ''));
            if ($name === '') continue;
            $items[] = ['value' => $name, 'label' => $name];
        }

        json_out(['ok' => true, 'items' => $items]);
    }

    if ($type === 'devis') {
        $sql = "
            SELECT DISTINCT d.numero_devis
            FROM devis_banamur d
            WHERE d.numero_devis IS NOT NULL AND d.numero_devis != ''
              AND d.numero_devis LIKE :q
            ORDER BY d.date_emission DESC
            LIMIT $limit
        ";
        $st = $pdo->prepare($sql);
        $st->execute([':q' => $like]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        foreach ($rows as $r) {
            $num = trim((string)($r['numero_devis'] ?? ''));
            if ($num === '') continue;
            $items[] = ['value' => $num, 'label' => $num];
        }

        json_out(['ok' => true, 'items' => $items]);
    }

    json_out(['ok' => true, 'items' => []]);
} catch (Throwable $e) {
    error_log('[suggest_budget_filters] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Erreur serveur', 'items' => []]);
}
