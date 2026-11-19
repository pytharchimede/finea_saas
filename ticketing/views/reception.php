<div class="ticket-card ticket-reception" id="ticket-reception">
  <div class="ticket-card-head"><div><p>Transmission & engagement</p><h2>Réception du ticket</h2></div><span class="ticket-reception-brand">FINEA × BANAMUR</span></div>
  <p>Imprimez le bordereau, faites signer chaque exécutant puis joignez le document signé à ce ticket.</p>
  <div class="ticket-reception-actions">
    <a class="ticket-btn ticket-btn-primary" href="index.php?action=pdf&amp;id=<?= (int)$ticket['id'] ?>"><i class="fa-solid fa-file-pdf"></i>Exporter en PDF</a>
    <a class="ticket-btn ticket-btn-yellow" target="_blank" rel="noopener" href="index.php?action=pdf&amp;print=1&amp;id=<?= (int)$ticket['id'] ?>"><i class="fa-solid fa-print"></i>Imprimer pour signature</a>
  </div>
  <div class="ticket-reception-grid">
    <section class="ticket-reception-panel">
      <h3><i class="fa-solid fa-users"></i> Exécutants attendus</h3>
      <?php if(empty($executors)): ?><p>Aucun exécutant identifié. Vérifiez le service ou la personne assignée.</p><?php endif ?>
      <ul><?php foreach($executors as $executor): ?><li><?= ticket_h($executor['name']) ?></li><?php endforeach ?></ul>
      <small>Une case de signature manuscrite par personne. La signature du profil de l’émetteur figure sur le PDF.</small>
    </section>
    <section class="ticket-reception-panel">
      <h3><i class="fa-solid fa-clock"></i> Délai de rendu</h3>
      <?php if(!empty($ticket['deadline_set_at'])): ?>
        <strong><?= date('d/m/Y à H:i',strtotime($ticket['due_at'])) ?></strong>
        <p>Confirmé par <?= ticket_h($ticket['deadline_set_by_name']) ?> le <?= date('d/m/Y à H:i',strtotime($ticket['deadline_set_at'])) ?>.</p>
      <?php else: ?><p>À confirmer par un exécutant ou le responsable du service avant la prise en charge.</p><?php endif ?>
      <?php if($canSetDeadline && !in_array($ticket['status'],['resolu','cloture','rejete'],true)): ?>
        <form method="post" action="index.php?action=deadline">
        <input type="hidden" name="_csrf" value="<?= ticket_h($_SESSION['ticket_reception_csrf']) ?>">
          <input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>">
          <label for="ticket-due-at">Date et heure de rendu</label>
          <input id="ticket-due-at" type="datetime-local" name="due_at" required value="<?= !empty($ticket['due_at'])?date('Y-m-d\TH:i',strtotime($ticket['due_at'])):'' ?>">
          <button class="ticket-btn ticket-btn-primary">Confirmer le délai</button>
        </form>
      <?php endif ?>
    </section>
  </div>
  <section class="ticket-reception-panel">
    <h3><i class="fa-solid fa-file-signature"></i> Preuves de réception</h3>
    <?php foreach($receptionProofs as $proof): ?>
      <p><a href="index.php?action=reception_download&amp;id=<?= (int)$ticket['id'] ?>&amp;proof_id=<?= (int)$proof['id'] ?>"><?= ticket_h($proof['original_name']) ?></a><br><small>Déposé par <?= ticket_h($proof['uploaded_by_name']) ?> · <?= date('d/m/Y à H:i',strtotime($proof['created_at'])) ?></small></p>
    <?php endforeach ?>
    <?php if(!$receptionProofs): ?><p>Aucun bordereau signé déposé.</p><?php endif ?>
    <form method="post" action="index.php?action=reception" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= ticket_h($_SESSION['ticket_reception_csrf']) ?>">
      <input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>">
      <label for="ticket-reception-file">Bordereau signé — PDF, JPG ou PNG, 12 Mo maximum</label>
      <input id="ticket-reception-file" type="file" name="reception_proof" accept="application/pdf,image/jpeg,image/png" required>
      <button class="ticket-btn ticket-btn-primary">Joindre la preuve de réception</button>
    </form>
  </section>
</div>
