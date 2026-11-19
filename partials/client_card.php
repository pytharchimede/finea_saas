<?php
require_once __DIR__.'/../services/ClientBusinessPolicy.php';

/**
 * Partial: rendu d'une card client.
 * Variables attendues:
 * - $client (array) : ligne client_banamur
 */
?>
<div class="card client-card">
    <div class="card-body">
        <?php $clientId = (int)($client['id_client'] ?? 0); ?>
        <div class="client-card-top">
            <div class="client-logo-frame">
                <?php if (!empty($client['logo_client'])): ?><img src="uploads/logos_clients/<?= htmlspecialchars($client['logo_client']) ?>" alt="Logo de <?= htmlspecialchars($client['nom_client'] ?? '') ?>" class="client-logo">
                <?php else: ?><i class="fas fa-building client-logo-placeholder"></i><?php endif; ?>
            </div>
            <?php if (ClientBusinessPolicy::usable($client)): ?><span class="client-state client-state-ok"><i class="fas fa-check"></i> Identité validée</span><?php else: ?><span class="client-state client-state-warning"><i class="fas fa-triangle-exclamation"></i> À régulariser</span><?php endif; ?>
        </div>

        <div class="client-name mb-2"><?= htmlspecialchars($client['nom_client'] ?? '') ?></div>

        <?php $clientIdentifier=ClientBusinessPolicy::displayIdentifier($client); ?>
        <div class="client-ncc mb-2"><span><?= htmlspecialchars($clientIdentifier['label']) ?></span><strong><?= htmlspecialchars($clientIdentifier['value']?:'Non renseigné') ?></strong></div>

        <div class="info-grid">
            <p><strong>Téléphone:</strong> <?= htmlspecialchars($client['telephone_client'] ?? '') ?></p>
            <p><strong>Code:</strong> <?= htmlspecialchars($client['code_client'] ?? '') ?></p>
            <p><strong>Localisation:</strong> <?= htmlspecialchars($client['localisation_client'] ?? '') ?></p>
            <p><strong>Commune:</strong> <?= htmlspecialchars($client['commune_client'] ?? '') ?></p>
            <p><strong>BP:</strong> <?= htmlspecialchars($client['bp_client'] ?? '') ?></p>
            <p><strong>Pays:</strong> <?= htmlspecialchars($client['pays_client'] ?? '') ?></p>
        </div>

        <?php if ($clientId > 0): ?>
            <div class="client-card-actions">
                <a class="btn btn-sm btn-outline-primary" href="details.php?id=<?= $clientId ?>">
                    <i class="fas fa-chart-line"></i> Détails client
                </a>
                <a class="btn btn-sm btn-outline-primary" href="modifier_client.php?id=<?= $clientId ?>">
                    <i class="fas fa-edit"></i> Ouvrir
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

