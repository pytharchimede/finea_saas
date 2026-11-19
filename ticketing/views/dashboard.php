<section class="ticket-kpi-grid">
<?php foreach ([['Nouveaux','nouveau','fa-bell','orange'],['En cours','en_cours','fa-spinner','sky'],['En attente','en_attente','fa-clock','amber'],['En retard','overdue','fa-triangle-exclamation','rose'],['Reçus','received','fa-inbox','slate']] as $k): ?>
  <a class="ticket-kpi" href="<?= $k[1] === 'received' ? 'index.php?action=received' : 'index.php?action=admin' ?>">
    <div><span><?= ticket_h($k[0]) ?></span><strong><?= ticket_num((int)($stats[$k[1]] ?? 0)) ?></strong></div>
    <i class="fa-solid <?= ticket_h($k[2]) ?> tone-<?= ticket_h($k[3]) ?>"></i>
  </a>
<?php endforeach; ?>
</section>
<?php ticket_card_open('Opérations prioritaires', 'Tickets à traiter', '<a class="ticket-btn ticket-btn-primary" href="index.php?action=create"><i class="fa-solid fa-plus"></i>Nouveau ticket</a>'); ?>
<?php require __DIR__ . '/ticket_table.php'; ?>
<?php ticket_card_close(); ?>
