<?php
require_once __DIR__ . '/_ui.php';
$pageTitle = 'Centre de Services & Tickets';
$activeMenu = 'ticketing';
$hasFineaShell = false;
$header = __DIR__ . '/../../decaissement/includes/header.php';
$navbar = __DIR__ . '/../../decaissement/includes/navbar.php';
$footer = __DIR__ . '/../../decaissement/includes/footer.php';
if (is_file($header) && is_file($navbar)) {
    $hasFineaShell = true;
    require_once $header;
    require_once $navbar;
} else {
    ?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>FINEA - Centre de Services</title><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"><link rel="stylesheet" href="assets/css/ticketing.css"><link rel="stylesheet" href="assets/css/ticketing-modern.css"></head><body><?php
}
if ($hasFineaShell) {
    echo '<link rel="stylesheet" href="assets/css/ticketing.css">';
    echo '<link rel="stylesheet" href="assets/css/ticketing-modern.css">';
}
$statsForHero = $stats ?? [];
?>
<div class="ticket-page-bg">
  <div class="ticket-container">
    <section class="ticket-hero">
      <div class="ticket-hero-glow one"></div><div class="ticket-hero-glow two"></div>
      <div class="ticket-hero-content">
        <div class="ticket-hero-text">
          <div class="ticket-hero-tags"><p>Centre de Services</p><span><i class="fa-solid fa-bell"></i><?= ticket_num((int)($statsForHero['overdue'] ?? 0)) ?> SLA en retard</span></div>
          <h1>Tickets & demandes internes</h1>
          <p>Un guichet unique pour adresser une demande à un service, une personne, un chantier ou un module FINEA.</p>
        </div>
        <div class="ticket-hero-actions">
          <a href="index.php?action=create" class="ticket-btn ticket-btn-light"><i class="fa-solid fa-plus"></i>Nouveau ticket</a>
          <a href="index.php?action=received" class="ticket-btn ticket-btn-yellow"><i class="fa-solid fa-inbox"></i>Tickets reçus</a>
          <a href="index.php?action=admin" class="ticket-btn ticket-btn-ghost"><i class="fa-solid fa-list-check"></i>Tous les tickets</a>
        </div>
      </div>
    </section>
    <div class="ticket-content-grid"><main class="ticket-main">
      <?php foreach (['error','success'] as $flashType): if (!empty($_SESSION['ticket_workflow_'.$flashType])): ?>
        <div role="alert" class="ticket-card"><?= htmlspecialchars($_SESSION['ticket_workflow_'.$flashType],ENT_QUOTES,'UTF-8') ?><?php if($name==='create'): ?> <a href="../signature_setup.php">Configurer ma signature</a><?php endif ?></div>
      <?php unset($_SESSION['ticket_workflow_'.$flashType]); endif; endforeach; ?>
      <?php require __DIR__ . '/' . $name . '.php'; ?>
    </main><?= ticket_sidebar() ?></div>
  </div>
</div>
<script src="assets/js/ticketing.js"></script>
<?php
if ($hasFineaShell && is_file($footer)) require_once $footer;
elseif (!$hasFineaShell) echo '</body></html>';
?>
