<?php

/**
 * Diagnostic / validation de setup notifications.
 *
 * Usage (CLI):
 *   php tools/diagnose_notifications_setup.php
 *
 * Objectifs:
 * - Vérifier que les droits SQL permettent CREATE/ALTER.
 * - Vérifier/ajouter les colonnes attendues:
 *   - system_notification_rules.title_template
 *   - system_notification_rules.message_template
 *   - fiche_decaissement.created_by_user_id
 *   - user_devis_banamur.acces_hse
 * - S'assurer que les règles par défaut existent (avec templates par défaut).
 * - Donner un aperçu "EPI" sur les fiches récentes.
 */

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/NotificationController.php';

function println(string $s = ''): void
{
    echo $s . PHP_EOL;
}

function headerLine(string $title): void
{
    println('');
    println('=== ' . $title . ' ===');
}

function colExists(PDO $pdo, string $table, string $column): bool
{
    $db = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
    if ($db === '') {
        return false;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND COLUMN_NAME = :c');
    $stmt->execute(['db' => $db, 't' => $table, 'c' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function ensureColumn(PDO $pdo, string $table, string $column, string $ddl): array
{
    if (colExists($pdo, $table, $column)) {
        return ['ok' => true, 'changed' => false, 'message' => 'OK (déjà présent)'];
    }

    try {
        $pdo->exec($ddl);
    } catch (Throwable $e) {
        return ['ok' => false, 'changed' => false, 'message' => 'ECHEC: ' . $e->getMessage()];
    }

    if (colExists($pdo, $table, $column)) {
        return ['ok' => true, 'changed' => true, 'message' => 'OK (ajouté)'];
    }

    return ['ok' => false, 'changed' => false, 'message' => 'ECHEC: colonne toujours absente après ALTER'];
}

function isEpiText(string $text): bool
{
    $hayLower = mb_strtolower($text, 'UTF-8');

    $epiPatterns = [
        '/\b(e\.?p\.?i\.?|épi)\b/u',
        '/\b(équipement|equipement)s?\s+de\s+protection\b/u',
        '/\b(ppe)\b/u',
        '/\bcasque\b/u',
        '/\bgants?\b/u',
        '/\blunettes?\b/u',
        '/\bharnais\b/u',
        '/\bmasque\b/u',
        '/\brespirateur\b/u',
        '/\bvis[iì]ère\b/u',
        '/\bgilet\b/u',
        '/\bcombinaison\b/u',
        '/\btablier\b/u',
        '/\b(bouchons?|protections?)\s+auditives?\b/u',
        '/\bbouchons?\s*d[’\']?\s*oreilles?\b/u',
        '/\b(casque|protections?)\s+antibruit\b/u',
        '/\bchaussures?\s+(de\s+)?s[ée]curit[ée]\b/u',
        '/\bbottes?\s+(de\s+)?s[ée]curit[ée]\b/u',
        '/\bbottes?\b/u',
        '/\btenues?\b/u',
        '/\buniformes?\b/u',
        '/\bchasubles?\b/u',
        '/\b(genouill[eè]res?|coudi[eè]res?)\b/u',
    ];

    foreach ($epiPatterns as $p) {
        if (preg_match($p, $hayLower)) {
            return true;
        }
    }

    return false;
}

try {
    $pdo = \Database::getConnection();
    $db = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');

    headerLine('Connexion');
    println('DB: ' . ($db !== '' ? $db : '(inconnue)'));

    try {
        $who = $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
        $user = $pdo->query('SELECT USER()')->fetchColumn();
        println('CURRENT_USER(): ' . (string) $who);
        println('USER(): ' . (string) $user);
    } catch (Throwable $e) {
        println('INFO user/grants indisponible: ' . $e->getMessage());
    }

    headerLine('SHOW GRANTS (si autorisé)');
    try {
        $rows = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_NUM) ?: [];
        if (!$rows) {
            println('(aucun résultat)');
        }
        foreach ($rows as $r) {
            println((string) ($r[0] ?? ''));
        }
    } catch (Throwable $e) {
        println('SHOW GRANTS refusé/indisponible: ' . $e->getMessage());
    }

    headerLine('Tables notifications (création si nécessaire)');
    // Cela peut déjà faire CREATE TABLE (selon droits)
    try {
        $nm = new \NotificationController($pdo);
        println('NotificationController: OK');
    } catch (Throwable $e) {
        println('NotificationController: ECHEC: ' . $e->getMessage());
        exit(2);
    }

    headerLine('Colonnes / migrations (ALTER)');

    $checks = [
        ['table' => 'system_notification_rules', 'col' => 'title_template', 'ddl' => 'ALTER TABLE system_notification_rules ADD COLUMN title_template VARCHAR(255) NULL'],
        ['table' => 'system_notification_rules', 'col' => 'message_template', 'ddl' => 'ALTER TABLE system_notification_rules ADD COLUMN message_template TEXT NULL'],
        ['table' => 'fiche_decaissement', 'col' => 'created_by_user_id', 'ddl' => 'ALTER TABLE fiche_decaissement ADD created_by_user_id INT NULL AFTER date_creat_fiche'],
        ['table' => 'user_devis_banamur', 'col' => 'acces_hse', 'ddl' => 'ALTER TABLE user_devis_banamur ADD COLUMN acces_hse TINYINT(1) NOT NULL DEFAULT 0'],
    ];

    foreach ($checks as $c) {
        $res = ensureColumn($pdo, $c['table'], $c['col'], $c['ddl']);
        println($c['table'] . '.' . $c['col'] . ' => ' . $res['message']);
    }

    headerLine('Règles par défaut (avec templates)');
    try {
        // On ne connaît pas toutes les request types ici; on crée au minimum les évènements processed + decaissement.
        $nm->ensureDefaultRulesForLegalRequestProcessed();
        $nm->ensureDefaultRulesForDecaissementSubmitted();
        $nm->ensureDefaultRulesForDecaissementProcessed();
        println('ensureDefaultRules*: OK');
    } catch (Throwable $e) {
        println('ensureDefaultRules*: ECHEC: ' . $e->getMessage());
    }

    headerLine('Aperçu heuristique EPI (fiches récentes)');
    try {
        $rows = $pdo->query('SELECT num_fiche, designation_fiche, precision_fiche, details_demande, date_creat_fiche FROM fiche_decaissement ORDER BY date_creat_fiche DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) {
            println('Aucune fiche trouvée.');
        } else {
            $epiCount = 0;
            $i = 0;
            foreach ($rows as $r) {
                $i++;
                $text = (string) (($r['designation_fiche'] ?? '') . ' ' . ($r['precision_fiche'] ?? '') . ' ' . ($r['details_demande'] ?? ''));
                $isEpi = isEpiText($text);
                if ($isEpi) {
                    $epiCount++;
                }
            }
            println('Sur ' . count($rows) . ' fiches récentes, classification EPI=true: ' . $epiCount);
            println('Exemples (max 10) :');

            $shown = 0;
            foreach ($rows as $r) {
                if ($shown >= 10) {
                    break;
                }
                $text = (string) (($r['designation_fiche'] ?? '') . ' ' . ($r['precision_fiche'] ?? '') . ' ' . ($r['details_demande'] ?? ''));
                $isEpi = isEpiText($text);
                if (!$isEpi) {
                    continue;
                }
                $shown++;
                println('- [' . (string) ($r['num_fiche'] ?? '') . '] ' . trim(mb_substr($text, 0, 140, 'UTF-8')));
            }

            if ($shown === 0) {
                println('(Aucun exemple EPI dans les 50 dernières fiches)');
            }
        }
    } catch (Throwable $e) {
        println('Aperçu EPI indisponible: ' . $e->getMessage());
    }

    headerLine('FIN');
    println('OK');
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Fatal: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
