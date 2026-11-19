<?php

/**
 * Partial: rendu d'une card offre.
 * Variables attendues:
 * - $offer (array) : ligne offre_banamur
 * - $offreModel (Offre) : modèle offres (pour récupérer les fichiers)
 */

$fichiers = [];
if (isset($offreModel) && !empty($offer['id_offre'])) {
    $fichiers = $offreModel->getFichiersByOffre((int)$offer['id_offre']);
}

$accordionId = 'accordionFiles' . (string)($offer['id_offre'] ?? '');
$offreId = (int)($offer['id_offre'] ?? 0);
?>

<div class="card h-100 d-flex flex-column offer-card">
    <div class="card-header">
        Projet <span class="offer-num">#<?= htmlspecialchars($offer['num_offre'] ?? '') ?></span>
    </div>
    <div class="card-body d-flex flex-column">
        <div class="info-grid">
            <p><strong>Date du projet :</strong> <?= htmlspecialchars($offer['date_offre'] ?? '') ?></p>
            <p><strong>Référence:</strong> <?= htmlspecialchars($offer['reference_offre'] ?? '') ?></p>
            <p><strong>Commercial:</strong> <?= htmlspecialchars($offer['commercial_dedie'] ?? '') ?></p>
            <p><strong>Créée le:</strong> <?= htmlspecialchars($offer['date_creat_offre'] ?? '') ?></p>
        </div>

        <?php if (!empty($fichiers)): ?>
            <div class="accordion mt-3 offer-accordion" id="<?= htmlspecialchars($accordionId) ?>">
                <div class="accordion-item">
                    <h2 class="accordion-header" id="heading<?= htmlspecialchars($accordionId) ?>">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?= htmlspecialchars($accordionId) ?>" aria-expanded="false" aria-controls="collapse<?= htmlspecialchars($accordionId) ?>">
                            <strong>Fichiers joints</strong>
                        </button>
                    </h2>
                    <div id="collapse<?= htmlspecialchars($accordionId) ?>" class="accordion-collapse collapse" aria-labelledby="heading<?= htmlspecialchars($accordionId) ?>" data-bs-parent="#<?= htmlspecialchars($accordionId) ?>">
                        <div class="accordion-body">
                            <ul class="offer-files">
                                <?php foreach ($fichiers as $fichier): ?>
                                    <?php $webPath = str_replace('../', '', (string)($fichier['file_path'] ?? '')); ?>
                                    <li>
                                        <a class="offer-file-link" href="<?= htmlspecialchars($webPath) ?>" download="<?= htmlspecialchars($fichier['file_name'] ?? '') ?>" target="_blank" rel="noopener">
                                            <i class="fas fa-paperclip"></i>
                                            <?= htmlspecialchars($fichier['file_name'] ?? '') ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($offreId > 0): ?>
            <div class="mt-3 d-flex justify-content-end">
                <a class="btn btn-sm btn-outline-primary" href="modifier_offre.php?id=<?= $offreId ?>">
                    <i class="fas fa-edit"></i> Modifier
                </a>
            </div>
        <?php endif; ?>

        <div class="flex-grow-1"></div>
    </div>
</div>
