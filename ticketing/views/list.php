<?php ticket_card_open('Centre de Services', $title, '<a class="ticket-btn ticket-btn-primary" href="index.php?action=create"><i class="fa-solid fa-plus"></i>Nouveau ticket</a>'); ?>
<?php require __DIR__ . '/ticket_table.php'; ?>
<?php ticket_card_close(); ?>
