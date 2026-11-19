<?php
require_once 'auth_check.php';
include 'header/header_profil.php';

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gérer mon Profil</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/custom_style_profil.css">
</head>

<body>
    <!-- Menu de navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">Mon Profil</a>
            <div class="collapse navbar-collapse" id="navbarNav">
                <?php include 'menu.php'; ?>
            </div>
        </div>
    </nav>

    <!-- Contenu principal -->
    <div class="container">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-user-circle"></i> Gérer mon Profil
            </div>
            <div class="card-body">
                <form action="profil.php" method="POST" enctype="multipart/form-data">
                    <div class="row">
                        <div class="col-md-4 text-center photo-preview">
                            <div class="photo-profile">
                                <!-- Affiche la photo actuelle ou Gravatar si pas de photo -->
                                <img id="previewImage" src="<?php echo $user['photo'] ?: 'https://www.gravatar.com/avatar/' . md5(strtolower(trim($user['mail_pro']))) . '?d=mm&s=200'; ?>" alt="Photo de profil">
                            </div>
                            <div class="mt-3">
                                <input type="file" class="form-control-file" name="photo" accept="image/*" onchange="previewImage(event)">
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label for="nom" class="form-label">Nom</label>
                                <input type="text" class="form-control" id="nom" name="nom" value="<?php echo htmlspecialchars($user['nom']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="prenom" class="form-label">Prénom</label>
                                <input type="text" class="form-control" id="prenom" name="prenom" value="<?php echo htmlspecialchars($user['prenom']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Mot de passe</label>
                                <input type="password" class="form-control" id="password" name="password" placeholder="Laissez vide pour ne pas modifier">
                            </div>
                            <?php if (\AccessManager::hasPermission('acces_veille_ao') || \AccessManager::hasPermission('ao_manage_alerts') || \AccessManager::hasPermission('ao_admin')): ?>
                                <div class="card border-0 bg-light mb-3">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
                                            <div>
                                                <div class="fw-bold text-dark"><i class="fas fa-binoculars text-primary me-2"></i>Alertes appels d'offres</div>
                                                <div class="small text-muted">Choisissez séparément les notifications FINEA et les emails.</div>
                                            </div>
                                            <a href="devis/veille_ao_preferences.php" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-sliders-h"></i> Paramétrer
                                            </a>
                                        </div>
                                        <div class="d-grid gap-2">
                                            <label class="d-flex align-items-center justify-content-between rounded border bg-white p-3">
                                                <span><strong class="d-block">Notifications FINEA</strong><small class="text-muted">Alertes visibles dans l'application</small></span>
                                                <input class="form-check-input fs-5" type="checkbox" name="ao_alerts_system_enabled" value="1" <?= !empty($aoPreferences['alerts_system_enabled']) ? 'checked' : '' ?>>
                                            </label>
                                            <label class="d-flex align-items-center justify-content-between rounded border bg-white p-3">
                                                <span><strong class="d-block">Emails AO</strong><small class="text-muted">Réception selon la fréquence configurée</small></span>
                                                <input class="form-check-input fs-5" type="checkbox" name="ao_alerts_email_enabled" value="1" <?= !empty($aoPreferences['alerts_email_enabled']) ? 'checked' : '' ?>>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary">Mettre à jour</button>
                            <?php if (\AccessManager::hasPermission('acces_veille_ao') || \AccessManager::hasPermission('ao_manage_alerts') || \AccessManager::hasPermission('ao_admin')): ?>
                                <a href="devis/veille_ao_preferences.php" class="btn btn-outline-primary ms-2">
                                    <i class="fas fa-bell"></i> Alertes appels d offres
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <div class="card mt-4 passkey-card" id="passkey-security" data-csrf="<?= htmlspecialchars((string)$_SESSION['passkey_csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <div class="card-header"><i class="fas fa-shield-halved"></i> Sécurité du compte</div>
            <div class="card-body">
                <div class="passkey-onboarding <?= $passkeys ? 'passkey-onboarding-compact' : '' ?>">
                    <div class="passkey-onboarding-icon"><i class="fas fa-fingerprint"></i></div>
                    <div class="flex-grow-1">
                        <h2 class="h4 mb-2"><?= $passkeys ? 'Ajouter un autre appareil' : 'Créez votre Passkey en moins d’une minute' ?></h2>
                        <p class="text-muted mb-3">Plus de mot de passe à saisir : FINEA utilisera le verrouillage sécurisé de votre téléphone.</p>
                        <div class="passkey-steps" aria-label="Étapes de création">
                            <div><span>1</span><strong>Appuyez sur le bouton</strong></div>
                            <i class="fas fa-chevron-right" aria-hidden="true"></i>
                            <div><span>2</span><strong>Suivez le message du téléphone</strong></div>
                            <i class="fas fa-chevron-right" aria-hidden="true"></i>
                            <div><span>3</span><strong>C’est terminé</strong></div>
                        </div>
                        <button type="button" class="btn btn-primary btn-lg mt-4" id="passkey-register-button">
                            <i class="fas fa-mobile-screen-button me-2"></i>Créer ma Passkey maintenant
                        </button>
                        <p class="small text-muted mt-2 mb-0"><i class="fas fa-lock me-1"></i>Votre empreinte ou votre visage reste uniquement sur votre appareil.</p>
                    </div>
                </div>
                <div id="passkey-message" class="alert d-none mt-3" role="status" aria-live="polite"></div>
                <h3 class="h6 mt-4 mb-3">Mes appareils enregistrés</h3>
                <div id="passkey-list" class="list-group text-start">
                    <?php if (!$passkeys): ?><div class="list-group-item text-muted" data-passkey-empty>Aucun appareil enregistré pour le moment.</div><?php endif; ?>
                    <?php foreach ($passkeys as $passkey): ?>
                        <div class="list-group-item d-flex flex-wrap align-items-center justify-content-between gap-3" data-passkey-id="<?= (int)$passkey['id'] ?>">
                            <div><strong><i class="fas fa-mobile-screen me-2 text-primary"></i><?= htmlspecialchars((string)$passkey['device_name'], ENT_QUOTES, 'UTF-8') ?></strong><br><small class="text-muted">Ajoutée le <?= htmlspecialchars(date('d/m/Y', strtotime((string)$passkey['created_at']))) ?> · Dernière utilisation : <?= $passkey['last_used_at'] ? htmlspecialchars(date('d/m/Y H:i', strtotime((string)$passkey['last_used_at']))) : 'jamais' ?></small></div>
                            <button type="button" class="btn btn-sm btn-outline-danger passkey-revoke-button">Révoquer</button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Pied de page -->
    <div class="footer">
        <p>&copy; 2024 Mon Application. Tous droits réservés.</p>
    </div>

    <!-- Scripts JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/passkeys.js"></script>
    <script>
        // Aperçu de l'image sélectionnée
        function previewImage(event) {
            const preview = document.getElementById('previewImage');
            preview.src = URL.createObjectURL(event.target.files[0]);
            preview.onload = () => URL.revokeObjectURL(preview.src); // Libérer la mémoire
        }
    </script>
</body>

</html>
