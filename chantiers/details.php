<?php
require_once dirname(__DIR__) . '/devis/includes/module_bootstrap.php';
require_once dirname(__DIR__) . '/services/ChantierScheduleReplanningService.php';

$pdo = Database::getConnection();
$chantierId = max(0, (int)($_GET['id'] ?? $_POST['chantier_id'] ?? 0));
$canEditDates = AccessManager::hasPermission('acces_admin') || AccessManager::hasPermission('acces_planning') || AccessManager::hasPermission('acces_direction');
$replanning = new ChantierScheduleReplanningService($pdo);

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && (string)($_POST['schedule_action'] ?? '') === 'update_dates') {
    try {
        if (!$canEditDates) throw new RuntimeException("Vous n'avez pas l'autorisation de modifier le planning du chantier.");
        if ($chantierId <= 0) throw new RuntimeException('Chantier invalide.');
        $dateDebut = trim((string)($_POST['date_debut'] ?? ''));
        $dateFin = trim((string)($_POST['date_fin_prevue'] ?? ''));
        if ($dateDebut === '') throw new RuntimeException('La date de début est obligatoire pour replanifier le chantier.');
        $result = $replanning->replan($chantierId,$dateDebut,$dateFin !== '' ? $dateFin : null,(int)($_SESSION['user_id'] ?? $_SESSION['id_user'] ?? 0),trim((string)($_SESSION['user_name'] ?? $_SESSION['nom'] ?? '')));
        $_SESSION['flash_success'] = sprintf('Planning replanifié de %+d jour(s) : %d déboursé(s), %d ligne(s), %d fiche(s) et %d FEB mise(s) à jour.',$result['delta_days'],$result['debourses'],$result['lines'],$result['fiches'],$result['febs']);
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = 'Replanification impossible : ' . $e->getMessage();
    }
    header('Location: details.php?id=' . $chantierId); exit;
}

$schedule=['date_debut'=>'','date_fin_prevue'=>''];
if($chantierId>0){try{$schedule=$replanning->currentSchedule($chantierId);}catch(Throwable $ignored){}}
ob_start();
devis_module_render_legacy_page('chantiers/details_legacy.php', 'details_chantier.php');
$html=(string)ob_get_clean();
if($canEditDates&&$chantierId>0){
    $start=htmlspecialchars((string)($schedule['date_debut']??''),ENT_QUOTES,'UTF-8');$end=htmlspecialchars((string)($schedule['date_fin_prevue']??''),ENT_QUOTES,'UTF-8');
    $dateCard='<div class="row mb-4"><div class="col-12"><div class="card mb-3 shadow-sm"><div class="card-header"><i class="fas fa-calendar-alt"></i> Replanification globale du chantier</div><div class="card-body">'
        .'<form method="post" action="details.php?id='.$chantierId.'" class="row g-3 align-items-end" onsubmit="return confirm(\'Cette modification va décaler le planning, les déboursés, les fiches de décaissement et les FEB liées, y compris les FEB déjà soumises. Continuer ?\');">'
        .'<input type="hidden" name="schedule_action" value="update_dates"><input type="hidden" name="chantier_id" value="'.$chantierId.'">'
        .'<div class="col-md-4"><label class="form-label fw-semibold">Nouvelle date de début</label><input type="date" class="form-control" name="date_debut" value="'.$start.'" required><div class="form-text">Aucune date minimale ou maximale imposée.</div></div>'
        .'<div class="col-md-4"><label class="form-label fw-semibold">Date de fin prévue</label><input type="date" class="form-control" name="date_fin_prevue" value="'.$end.'"><div class="form-text">Toute date cohérente avec le début est autorisée.</div></div>'
        .'<div class="col-md-4"><button type="submit" class="btn btn-dark w-100"><i class="fas fa-calendar-check"></i> Replanifier tout le chantier</button></div>'
        .'<div class="col-12"><div class="alert alert-warning mb-0"><strong>Impact :</strong> le décalage est propagé au planning, aux déboursés, aux lignes de déboursé, aux fiches de décaissement et aux FEB liées, y compris déjà soumises. Montants, statuts et validations restent inchangés.</div></div></form></div></div></div></div>';
    $needle='<div class="row mb-4">';$position=strpos($html,$needle);$html=$position!==false?substr($html,0,$position).$dateCard.substr($html,$position):$dateCard.$html;
}
echo $html;
