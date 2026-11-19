<?php
$users = $formOptions['users'] ?? [];
$isRequester = (int)($ticket['requester_id'] ?? 0) === (int)($user['id'] ?? 0);
$logByUser = [];
foreach (($notificationLogs ?? []) as $log) {
    $logByUser[(int)$log['recipient_user_id']]['name'] = $log['recipient_name'];
    $logByUser[(int)$log['recipient_user_id']]['email'] = $log['recipient_email'];
    $logByUser[(int)$log['recipient_user_id']]['channels'][] = $log;
}
$deliveryStats = ['people' => count($logByUser), 'system_read' => 0, 'email_opened' => 0, 'ticket_viewed' => 0];
foreach ($logByUser as $trackingRow) {
    $hasSystemRead = false;
    $hasEmailOpened = false;
    $hasTicketView = false;
    foreach (($trackingRow['channels'] ?? []) as $trackingChannel) {
        $hasSystemRead = $hasSystemRead || !empty($trackingChannel['system_read_at']);
        $hasEmailOpened = $hasEmailOpened || !empty($trackingChannel['email_opened_at']);
        $hasTicketView = $hasTicketView || !empty($trackingChannel['ticket_first_viewed_at']);
    }
    $deliveryStats['system_read'] += $hasSystemRead ? 1 : 0;
    $deliveryStats['email_opened'] += $hasEmailOpened ? 1 : 0;
    $deliveryStats['ticket_viewed'] += $hasTicketView ? 1 : 0;
}
$formatTrackingDate = static fn($value): string => $value ? date('d/m/Y H:i:s', strtotime((string)$value)) : '-';
?>
<section class="ticket-detail-grid">
  <div class="ticket-detail-main">
    <?php require __DIR__.'/reception.php'; ?>
    <div class="ticket-card">
      <div class="ticket-title-block">
        <?= ticket_category_pill($ticket['category_name'] ?? null, $ticket['category_icon'] ?? null, $ticket['category_color'] ?? null, true) ?>
        <h2><?= ticket_h($ticket['title']) ?></h2>
        <p><?= nl2br(ticket_h($ticket['description'])) ?></p>
      </div>
      <div class="ticket-conversation">
        <h3>Conversation</h3>
        <?php foreach($messages as $m): ?>
          <div class="ticket-message">
            <div><b><?= ticket_h($m['author_name']) ?></b><small><?= ticket_h($m['author_service']) ?> · <?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></small></div>
            <p><?= nl2br(ticket_h($m['message'])) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
      <form method="post" action="index.php?action=message" class="ticket-reply">
        <input type="hidden" name="_csrf" value="<?= ticket_h($_SESSION['ticket_reception_csrf']) ?>">
        <input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>">
        <textarea required name="message" rows="4" placeholder="Répondre au ticket..."></textarea>
        <button class="ticket-btn ticket-btn-primary"><i class="fa-solid fa-reply"></i>Envoyer</button>
      </form>
    </div>

    <div class="ticket-card" id="ticket-attachments">
      <div class="ticket-card-head"><div><p>Documents</p><h2>Pièces jointes</h2></div></div>
      <div class="ticket-attachment-grid">
        <?php foreach(($attachments ?? []) as $a): ?>
          <a class="ticket-attachment" href="index.php?action=attachment_download&amp;id=<?= (int)$ticket['id'] ?>&amp;attachment_id=<?= (int)$a['id'] ?>" target="_blank" rel="noopener">
            <i class="fa-solid <?= ticket_h(ticket_file_icon((string)$a['original_name'])) ?>"></i>
            <span><b><?= ticket_h($a['original_name']) ?></b><small><?= ticket_num(((int)$a['size_bytes']) / 1024) ?> Ko · <?= date('d/m/Y H:i', strtotime($a['created_at'])) ?></small></span>
          </a>
        <?php endforeach; ?>
        <?php if(empty($attachments)): ?><div class="ticket-empty">Aucune pièce jointe.</div><?php endif; ?>
      </div>
      <form method="post" action="index.php?action=attachments" enctype="multipart/form-data" class="ticket-upload-inline">
        <input type="hidden" name="_csrf" value="<?= ticket_h($_SESSION['ticket_reception_csrf']) ?>">
        <input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>">
        <label class="ticket-dropzone compact" data-ticket-dropzone>
          <input type="file" name="attachments[]" multiple>
          <span><i class="fa-solid fa-plus"></i></span>
          <strong>Ajouter des fichiers</strong>
          <small>drag & drop ou sélection multiple</small>
        </label>
        <div class="ticket-file-preview" data-ticket-file-preview></div>
        <button class="ticket-btn ticket-btn-soft"><i class="fa-solid fa-paperclip"></i>Joindre</button>
      </form>
    </div>

    <?php if($isRequester): ?>
      <div class="ticket-card" id="ticket-followup">
        <div class="ticket-card-head"><div><p>Relance & suivi</p><h2>Pilotage des notifications</h2></div></div>
        <div class="ticket-followup-grid">
          <form method="post" action="index.php?action=relaunch" class="ticket-followup-panel">
        <input type="hidden" name="_csrf" value="<?= ticket_h($_SESSION['ticket_reception_csrf']) ?>">
            <input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>">
            <i class="fa-solid fa-bullhorn"></i>
            <h3>Relancer les personnes concernées</h3>
            <textarea name="note" rows="3" placeholder="Message de relance optionnel..."></textarea>
            <button class="ticket-btn ticket-btn-primary"><i class="fa-solid fa-paper-plane"></i>Relancer</button>
          </form>
          <form method="post" action="index.php?action=followup_watchers" class="ticket-followup-panel">
        <input type="hidden" name="_csrf" value="<?= ticket_h($_SESSION['ticket_reception_csrf']) ?>">
            <input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>">
            <i class="fa-solid fa-user-plus"></i>
            <h3>Ajouter des personnes en suivi</h3>
            <?= ticket_search_select('Personnes à ajouter','watcher_ids[]',$users,'Saisir pour lister les utilisateurs FINEA',true,'data-empty-text="Aucun utilisateur FINEA trouvé" data-variant="followup"') ?>
            <textarea name="note" rows="3" placeholder="Message transmis aux nouvelles personnes..."></textarea>
            <button class="ticket-btn ticket-btn-yellow"><i class="fa-solid fa-bell"></i>Ajouter & notifier</button>
          </form>
        </div>
        <div class="ticket-notification-log">
          <div class="ticket-tracking-head">
            <div><p>Suivi en temps réel</p><h3>Lecture et envois</h3><small>Actualisé le <?= date('d/m/Y à H:i:s') ?></small></div>
            <button type="button" class="ticket-tracking-refresh" data-ticket-tracking-refresh><i class="fa-solid fa-rotate"></i><span>Actualiser</span></button>
          </div>
          <div class="ticket-tracking-summary">
            <div><i class="fa-solid fa-users"></i><strong><?= (int)$deliveryStats['people'] ?></strong><span>Destinataires</span></div>
            <div><i class="fa-solid fa-bell"></i><strong><?= (int)$deliveryStats['system_read'] ?></strong><span>Notifications lues</span></div>
            <div><i class="fa-solid fa-envelope-open-text"></i><strong><?= (int)$deliveryStats['email_opened'] ?></strong><span>Emails ouverts</span></div>
            <div><i class="fa-solid fa-eye"></i><strong><?= (int)$deliveryStats['ticket_viewed'] ?></strong><span>Fiches consultées</span></div>
          </div>
          <div class="ticket-tracking-people">
          <?php foreach($logByUser as $trackingPerson): ?>
            <?php
              $systemChannel = null;
              $emailChannel = null;
              foreach (($trackingPerson['channels'] ?? []) as $trackingChannel) {
                  if (($trackingChannel['channel'] ?? '') === 'system') $systemChannel = $trackingChannel;
                  if (($trackingChannel['channel'] ?? '') === 'email') $emailChannel = $trackingChannel;
              }
              $ticketViewedAt = $systemChannel['ticket_first_viewed_at'] ?? $emailChannel['ticket_first_viewed_at'] ?? null;
              $ticketLastViewedAt = $systemChannel['ticket_last_viewed_at'] ?? $emailChannel['ticket_last_viewed_at'] ?? null;
              $ticketViewCount = (int)($systemChannel['ticket_view_count'] ?? $emailChannel['ticket_view_count'] ?? 0);
              $hasEngaged = !empty($systemChannel['system_read_at']) || !empty($emailChannel['email_opened_at']) || !empty($ticketViewedAt);
              $relances = max((int)($systemChannel['resent_count'] ?? 0), (int)($emailChannel['resent_count'] ?? 0));
            ?>
            <div class="ticket-tracking-person-row">
              <div class="ticket-notif-person">
                <span class="ticket-notif-avatar"><?= ticket_h(mb_strtoupper(mb_substr((string)($trackingPerson['name'] ?? 'U'), 0, 1))) ?></span>
                <div><b><?= ticket_h($trackingPerson['name'] ?? 'Utilisateur') ?></b><small><?= ticket_h($trackingPerson['email'] ?? '') ?></small></div>
                <span class="ticket-engagement <?= $hasEngaged ? 'is-engaged' : '' ?>"><?= $hasEngaged ? 'A consulté' : 'Sans lecture détectée' ?></span>
              </div>
              <div class="ticket-notif-timeline">
                <div class="ticket-track-step <?= !empty($systemChannel['system_read_at']) ? 'is-complete' : (!empty($systemChannel) ? 'is-sent' : 'is-missing') ?>">
                  <i class="fa-solid fa-bell"></i><span><b><?= !empty($systemChannel['system_read_at']) ? 'Notification FINEA lue' : (!empty($systemChannel) ? 'Notification FINEA envoyée' : 'Notification non envoyée') ?></b><small><?= !empty($systemChannel['system_read_at']) ? $formatTrackingDate($systemChannel['system_read_at']) : (!empty($systemChannel['last_sent_at']) ? $formatTrackingDate($systemChannel['last_sent_at']) : 'Aucun envoi') ?></small></span>
                </div>
                <div class="ticket-track-step <?= (($emailChannel['status'] ?? '') === 'failed') ? 'is-error' : (!empty($emailChannel['email_opened_at']) ? 'is-complete' : (!empty($emailChannel) ? 'is-sent' : 'is-missing')) ?>">
                  <i class="fa-solid <?= !empty($emailChannel['email_opened_at']) ? 'fa-envelope-open-text' : 'fa-envelope' ?>"></i><span>
                    <b><?php if (($emailChannel['status'] ?? '') === 'failed'): ?>Email en échec<?php elseif (!empty($emailChannel['email_opened_at'])): ?>Email ouvert<?php elseif (!empty($emailChannel)): ?>Email envoyé, non ouvert<?php else: ?>Email non envoyé<?php endif; ?></b>
                    <small><?= !empty($emailChannel['email_opened_at']) ? $formatTrackingDate($emailChannel['email_opened_at']) : (!empty($emailChannel['last_sent_at']) ? $formatTrackingDate($emailChannel['last_sent_at']) : 'Aucun envoi') ?></small>
                    <?php if (!empty($emailChannel['error_message'])): ?><em title="<?= ticket_h($emailChannel['error_message']) ?>"><?= ticket_h($emailChannel['error_message']) ?></em><?php endif; ?>
                  </span>
                </div>
                <div class="ticket-track-step <?= $ticketViewedAt ? 'is-complete' : 'is-missing' ?>">
                  <i class="fa-solid fa-eye"></i><span><b><?= $ticketViewedAt ? 'Fiche ticket consultée' : 'Fiche non consultée' ?></b><small><?= $ticketViewedAt ? $formatTrackingDate($ticketViewedAt) . ($ticketViewCount > 1 ? ' · ' . $ticketViewCount . ' visites · dernière ' . $formatTrackingDate($ticketLastViewedAt) : '') : 'Aucune visite enregistrée' ?></small></span>
                </div>
              </div>
              <?php if($relances > 0): ?><div class="ticket-relance-count"><i class="fa-solid fa-paper-plane"></i><?= $relances ?> relance(s)</div><?php endif; ?>
            </div>
          <?php endforeach; ?>
          </div>
          <?php if(empty($logByUser)): ?><div class="ticket-empty">Aucun envoi suivi pour le moment.</div><?php endif; ?>
          <p class="ticket-tracking-note"><i class="fa-solid fa-circle-info"></i>L’ouverture email dépend du chargement des images par la messagerie. La consultation de la fiche dans FINEA reste l’indicateur le plus fiable.</p>
          <h3>Lecture et envois</h3>
          <?php foreach($logByUser as $row): ?>
            <div class="ticket-notif-row">
              <div><b><?= ticket_h($row['name'] ?? 'Utilisateur') ?></b><small><?= ticket_h($row['email'] ?? '') ?></small></div>
              <div class="ticket-notif-channels">
                <?php foreach(($row['channels'] ?? []) as $channel): ?>
                  <?php $readAt = $channel['read_at'] ?? null; ?>
                  <span class="ticket-notif-pill <?= $readAt ? 'is-read' : '' ?>">
                    <i class="fa-solid <?= $channel['channel']==='email' ? 'fa-envelope' : 'fa-bell' ?>"></i>
                    <?= ticket_h(($channel['status'] ?? '')==='failed'?'Échec de l’envoi':($channel['channel']==='email' ? 'Email envoyé' : ($readAt ? 'Notification lue' : 'Notification envoyée'))) ?>
                    <small><?= $readAt ? date('d/m/Y H:i', strtotime($readAt)) : date('d/m/Y H:i', strtotime($channel['last_sent_at'])) ?></small>
                    <?php if((int)$channel['resent_count'] > 0): ?><em><?= (int)$channel['resent_count'] ?> relance(s)</em><?php endif; ?>
                  </span>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if(empty($logByUser)): ?><div class="ticket-empty">Aucun envoi suivi pour le moment.</div><?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <aside class="ticket-card ticket-detail-side">
    <div class="ticket-side-ticket"><span><?= ticket_h($ticket['ticket_no']) ?></span><?= ticket_status_badge($ticket['status']) ?></div>
    <dl class="ticket-dl">
      <dt>Demandeur</dt><dd><?= ticket_h($ticket['requester_name']) ?><small><?= ticket_h($ticket['requester_service']) ?></small></dd>
      <dt>Assignation</dt><dd><?= ticket_h($ticket['assigned_user_name'] ?: ($ticket['assigned_service'] ?: 'Non assigné')) ?></dd>
      <dt>Chantier</dt><dd><?= ticket_h($ticket['chantier'] ?: '-') ?></dd>
      <dt>Module</dt><dd><?= ticket_h($ticket['related_module'] ?: '-') ?></dd>
      <dt>Référence liée</dt><dd><?= ticket_h($ticket['related_reference'] ?: '-') ?></dd>
      <dt>Suivi</dt><dd><span class="ticket-watchers"><?php foreach(($watchers ?? []) as $w): ?><span class="ticket-badge"><?= ticket_h($w['user_name']) ?></span><?php endforeach; ?><?php if(empty($watchers)): ?>-<?php endif; ?></span></dd>
      <dt>Priorité</dt><dd><?= ticket_priority_badge($ticket['priority_name'] ?? '-', $ticket['priority_color'] ?? '#1d2b57') ?></dd>
      <dt><?= !empty($ticket['deadline_set_at'])?'Délai confirmé':'Échéance indicative' ?></dt><dd><?= $ticket['due_at'] ? date('d/m/Y H:i', strtotime($ticket['due_at'])) : '-' ?></dd>
    </dl>
    <?php if($allowedStatuses): ?><form method="post" action="index.php?action=status" class="ticket-side-form">
        <input type="hidden" name="_csrf" value="<?= ticket_h($_SESSION['ticket_reception_csrf']) ?>">
      <input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>">
      <label>Changer le statut<select name="status"><?php foreach($statuses as $k=>$v): if(!in_array($k,$allowedStatuses,true)) continue; ?><option value="<?= ticket_h($k) ?>" <?= $ticket['status']===$k?'selected':'' ?>><?= ticket_h($v) ?></option><?php endforeach; ?></select></label>
      <button class="ticket-btn ticket-btn-soft">Mettre à jour</button>
    </form>
    <?php endif ?>
    <?php if($canReassign && !in_array($ticket['status'],['resolu','cloture','rejete'],true)): ?><form method="post" action="index.php?action=reassign" class="ticket-side-form">
        <input type="hidden" name="_csrf" value="<?= ticket_h($_SESSION['ticket_reception_csrf']) ?>">
      <input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>">
      <label>Réaffecter au service<select name="assigned_service"><option value="">Choisir un service</option><?php foreach($services as $s): $value = is_array($s) ? (string)$s['label'] : (string)$s; ?><option value="<?= ticket_h($value) ?>" <?= $ticket['assigned_service']===$value?'selected':'' ?>><?= ticket_h($value) ?></option><?php endforeach; ?></select></label>
      <label>Ou assigner à une personne<select name="assigned_user_id"><option value="">Affectation au service</option><?php foreach($users as $person): ?><option value="<?= (int)$person['value'] ?>" <?= (int)$ticket['assigned_user_id']===(int)$person['value']?'selected':'' ?>><?= ticket_h($person['label']) ?></option><?php endforeach ?></select></label>
      <small>La personne sélectionnée prime sur le service. La réaffectation remet le ticket à « Nouveau » et demande un nouveau délai.</small>
      <button class="ticket-btn ticket-btn-soft">Réaffecter</button>
    </form>
    <?php endif ?><div class="ticket-history">
      <h4>Historique</h4>
      <?php foreach($history as $h): ?><p><b><?= ticket_h($statuses[$h['new_status']] ?? str_replace('_',' ',$h['new_status'])) ?></b><span><?= ticket_h($h['comment']) ?></span><small><?= ticket_h($h['changed_by_name']) ?> · <?= date('d/m/Y H:i', strtotime($h['created_at'])) ?></small></p><?php endforeach; ?>
    </div>
  </aside>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const refreshButton = document.querySelector('[data-ticket-tracking-refresh]');
  if (!refreshButton) return;
  let formIsDirty = false;
  document.querySelectorAll('form input, form textarea, form select').forEach(function (field) {
    field.addEventListener('input', function () { formIsDirty = true; });
    field.addEventListener('change', function () { formIsDirty = true; });
  });
  refreshButton.addEventListener('click', function () {
    refreshButton.classList.add('is-loading');
    window.location.reload();
  });
  window.setInterval(function () {
    if (!document.hidden && !formIsDirty && !document.querySelector('textarea:focus, input:focus, select:focus')) {
      window.location.reload();
    }
  }, 60000);
});
</script>
