<div class="ticket-card"><p>Votre signature professionnelle sera automatiquement apposée sur le bordereau du ticket. <a href="../signature_setup.php">Vérifier ma signature</a></p></div>
<?php
$formOptions = $formOptions ?? [];
$chantiers = $formOptions['chantiers'] ?? [];
$services = $formOptions['services'] ?? [];
$users = $formOptions['users'] ?? [];
$modules = $formOptions['modules'] ?? [];
$references = $formOptions['references'] ?? [];
?>
<?php ticket_card_open('Nouveau ticket', 'Créer une demande adressée à un service, une personne ou un module FINEA'); ?>
<form method="post" action="index.php?action=store" class="ticket-form ticket-form-modern" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= ticket_h($_SESSION['ticket_reception_csrf']) ?>">
  <input type="hidden" name="request_nonce" value="<?= ticket_h($_SESSION['ticket_form_old']['request_nonce'] ?? bin2hex(random_bytes(32))) ?>">
  <div class="ticket-form-section">
    <div class="ticket-form-section-head">
      <span><i class="fa-solid fa-ticket"></i></span>
      <div>
        <h3>Demande</h3>
        <p>Objet, chantier concerné et description opérationnelle.</p>
      </div>
    </div>
    <div class="ticket-form-grid two">
      <?= ticket_input('Objet du ticket','title','','text','required maxlength="220" placeholder="Ex : Besoin urgent de pelle hydraulique"') ?>
      <?= ticket_search_select('Chantier concerné','chantier_id',$chantiers,'Saisir le code ou le nom du chantier',false,'data-label-name="chantier"') ?>
    </div>
    <label class="ticket-field"><span>Description détaillée</span><textarea required name="description" rows="6" placeholder="Décrivez clairement la demande, l’incident, le besoin ou le blocage..."><?= ticket_h($_SESSION['ticket_form_old']['description'] ?? '') ?></textarea></label>
  </div>

  <div class="ticket-form-section">
    <div class="ticket-form-section-head">
      <span><i class="fa-solid fa-route"></i></span>
      <div>
        <h3>Orientation</h3>
        <p>Choisissez un service ou un utilisateur actif. Une personne nommément assignée est le destinataire principal.</p>
      </div>
    </div>
    <div class="ticket-form-grid three">
      <label class="ticket-field"><span>Catégorie</span><select required name="category_id"><option value="">Choisir</option><?php foreach($categories as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)($_SESSION['ticket_form_old']['category_id'] ?? 0)===(int)$c['id']?'selected':'' ?>><?= ticket_h($c['name']) ?></option><?php endforeach; ?></select></label>
      <label class="ticket-field"><span>Priorité</span><select required name="priority_id"><?php foreach($priorities as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)($_SESSION['ticket_form_old']['priority_id'] ?? 0)===(int)$p['id']?'selected':'' ?>><?= ticket_h($p['name']) ?> - SLA <?= (int)$p['sla_hours'] ?>h</option><?php endforeach; ?></select></label>
      <?= ticket_search_select('Service destinataire','assigned_service',$services,'Saisir pour lister les services') ?>
    </div>
    <div class="ticket-form-grid two">
      <?= ticket_search_select('Utilisateur FINEA destinataire','assigned_user_id',$users,'Saisir un nom, service ou email',false,'data-label-name="assigned_user_name"') ?>
      <?= ticket_search_select('Autres personnes en suivi','watcher_ids[]',$users,'Ajouter des personnes à notifier',true) ?>
    </div>
  </div>

  <div class="ticket-form-section">
    <div class="ticket-form-section-head">
      <span><i class="fa-solid fa-link"></i></span>
      <div>
        <h3>Contexte lié</h3>
        <p>Module FINEA et document de référence si le ticket s’appuie sur un élément existant.</p>
      </div>
    </div>
    <div class="ticket-form-grid two">
      <?= ticket_search_select('Module lié','related_module',$modules,'Saisir pour lister les modules') ?>
      <?= ticket_search_select('Référence liée (optionnel)','related_reference_value',$references,'FEB, fiche de décaissement, ticket ou document',false,'data-label-name="related_reference"') ?>
    </div>
  </div>

  <div class="ticket-form-section" id="ticket-attachments">
    <div class="ticket-form-section-head">
      <span><i class="fa-solid fa-paperclip"></i></span>
      <div>
        <h3>Pièces jointes</h3>
        <p>Glisser-déposer plusieurs documents avec aperçu avant envoi.</p>
      </div>
    </div>
    <label class="ticket-dropzone" data-ticket-dropzone>
      <input type="file" name="attachments[]" multiple>
      <span><i class="fa-solid fa-cloud-arrow-up"></i></span>
      <strong>Déposer les fichiers ici</strong>
      <small>ou cliquer pour parcourir votre ordinateur</small>
    </label>
    <div class="ticket-file-preview" data-ticket-file-preview></div>
  </div>

  <div class="ticket-form-actions">
    <a href="index.php" class="ticket-btn ticket-btn-soft">Annuler</a>
    <button class="ticket-btn ticket-btn-primary" type="submit"><i class="fa-solid fa-paper-plane"></i>Créer le ticket</button>
  </div>
</form>
<?php ticket_card_close(); ?>
