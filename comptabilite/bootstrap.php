<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../services/AccountingService.php';
require_once __DIR__ . '/../services/AccountingDisbursementSource.php';

require_once __DIR__.'/../model/AccountingPermissions.php';
AccessManager::ensureOptionalPermissionColumns(Database::getConnection());
AccessManager::refreshSessionPermissions();
function accountingCan(string $permission): bool {
    return AccountingPermissions::allows($_SESSION['permissions']??[],[$permission]);
}
function accountingAction(string $action): bool {
    return AccountingPermissions::action($_SESSION['permissions']??[],$action);
}
function accountingView(string $view): bool {
    return AccountingPermissions::view($_SESSION['permissions']??[],$view);
}
function accountingRequire(bool $allowed): void {
    if (!$allowed) {
        http_response_code(403);
        exit('Accès refusé : vous ne disposez pas du droit comptable requis.');
    }
}
accountingRequire(AccountingPermissions::allows($_SESSION['permissions']??[]));
$canWrite = accountingAction('save');
try {
    $accounting = new AccountingService(Database::getConnection());
    $accounting->prepareForUse();
} catch (Throwable $e) {
    error_log('[accounting_auto_install] '.$e->getMessage());
    http_response_code(503);
    header('Retry-After: 30');
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="fr"><meta charset="utf-8"><title>Initialisation comptable</title><main><h1>Initialisation comptable indisponible</h1><p>La préparation automatique n’a pas pu se terminer. Rechargez la page pour réessayer.</p><p><a href="index.php">Réessayer</a> · <a href="../selection_portail.php">Retour au portail</a></p></main></html>';
    exit;
}
$sourceBridge = new AccountingDisbursementSource(Database::getConnection());
$userId = (int) ($_SESSION['user_id'] ?? 0);
$_SESSION['accounting_csrf'] ??= bin2hex(random_bytes(32));
$csrf = $_SESSION['accounting_csrf'];
function ah(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function money(mixed $value): string {
    $raw = AccountingService::decimal((int)$value);
    [$whole,$fraction] = explode('.', $raw);
    return preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $whole) . ',' . $fraction;
}
function accountingToken(): void { echo '<input type="hidden" name="csrf" value="'.ah($_SESSION['accounting_csrf']).'">'; }
function accountingCsvCell(mixed $value): string {
    $value=(string)$value;
    return preg_match('/^[\s]*[=+@\-]/u',$value) ? "'".$value : $value;
}
