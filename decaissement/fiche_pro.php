<?php
// Fiche professionnelle imprimable
// Paramètre: num_fiche
require_once 'model/Database.php';
require_once 'model/AuditTrail.php';
require_once __DIR__ . '/includes/fiche_label_helper.php';
require_once __DIR__ . '/../services/FebBeneficiarySyncService.php';
if (!isset($_GET['num_fiche'])) {
    header('Location: formulaire_demande_decaissement.php');
    exit;
}
$num = $_GET['num_fiche'];
$db = new Database();
$pdo = $db->getConnection();
$febLookup = $pdo->prepare('SELECT feb_request_id FROM fiche_decaissement WHERE num_fiche=? LIMIT 1');
$febLookup->execute([$num]);
FebBeneficiarySyncService::syncFiche($pdo, (int) $febLookup->fetchColumn());
$stmt = $pdo->prepare("SELECT f.*, c.code chantier_code, c.titre chantier_titre, c.entreprise AS entreprise, cl.nom_client, ldb.designation ligne_designation, ldb.categorie ligne_categorie, ld.designation devis_designation FROM fiche_decaissement f LEFT JOIN chantier_banamur c ON c.id=f.chantier_id LEFT JOIN client_banamur cl ON cl.id_client=c.client_id LEFT JOIN ligne_debourse_banamur ldb ON ldb.id=f.ligne_debourse_id LEFT JOIN debourse_banamur db ON db.id=ldb.debourse_id LEFT JOIN ligne_devis_banamur ld ON ld.id=db.ligne_devis_id WHERE f.num_fiche=? AND (f.chantier_id IS NULL OR f.chantier_id=0 OR c.archived_at IS NULL)");
$stmt->execute([$num]);
$fiche = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$fiche) {
    echo 'Fiche introuvable';
    exit;
}

$fiche = enrich_fiche_labels($fiche);

AuditTrail::logVisualEvent('preview', [
    'module' => 'decaissement',
    'action' => 'fiche_pro_preview',
    'entity_type' => 'fiche_decaissement',
    'entity_id' => (string)($fiche['id'] ?? $num),
    'entity_label' => (string)$num,
    'details' => [
        'view_mode' => 'printable_html',
        'chantier' => $fiche['chantier_titre'] ?? null,
        'beneficiaire' => $fiche['beficiaire_fiche'] ?? null,
    ],
], $pdo);

// URL numérique cible de la fiche (version détail) pour le QR
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$qrTargetUrl = $scheme . '://' . $host . $basePath . '/voir_fiche_details.php?num_fiche=' . urlencode($num);

$qrImg = '';
// 1) Tentative génération locale via différentes variantes phpqrcode
if (empty($qrImg)) {
    $qrDir = __DIR__ . '/../phpqrcode/';
    $candidates = ['qrlib.php', 'phpqrcode.php', 'QRcode.php'];
    foreach ($candidates as $file) {
        if (file_exists($qrDir . $file)) {
            require_once $qrDir . $file;
            break;
        }
    }
    if (class_exists('QRcode')) {
        if (!defined('QR_ECLEVEL_M')) { // certains forks n'ont pas les constantes
            define('QR_ECLEVEL_L', 0);
            define('QR_ECLEVEL_M', 1);
            define('QR_ECLEVEL_Q', 2);
            define('QR_ECLEVEL_H', 3);
        }
        // Supprimer temporairement les notices deprecated (PHP 8.2+) pendant génération
        $__oldErr = error_reporting();
        error_reporting($__oldErr & ~E_DEPRECATED & ~E_NOTICE);
        ob_start();
        $size = (int)4;
        $margin = (int)1; // forcer int pour éviter implicit float warnings
        QRcode::png($qrTargetUrl, null, QR_ECLEVEL_M, $size, $margin);
        $qrPng = ob_get_clean();
        error_reporting($__oldErr); // restaurer
        if ($qrPng) {
            $qrImg = 'data:image/png;base64,' . base64_encode($qrPng);
        }
    }
}
// 2) Fallback Google Charts si échec
if (empty($qrImg)) {
    $remoteUrl = 'https://chart.googleapis.com/chart?cht=qr&chs=180x180&chl=' . urlencode($qrTargetUrl) . '&choe=UTF-8';
    $remoteData = @file_get_contents($remoteUrl);
    if ($remoteData) {
        $qrImg = 'data:image/png;base64,' . base64_encode($remoteData);
    }
}
// Paths fichiers
$photo = ($fiche['photo_beneficiaire'] && file_exists('uploads/photo/' . $fiche['photo_beneficiaire'])) ? 'uploads/photo/' . $fiche['photo_beneficiaire'] : null;
$cni = ($fiche['cni_beneficiaire'] && file_exists('uploads/cni/' . $fiche['cni_beneficiaire'])) ? 'uploads/cni/' . $fiche['cni_beneficiaire'] : null;
// Signature
$signature = ($fiche['signature_fichier'] && file_exists('uploads/signatures/' . $fiche['signature_fichier'])) ? 'uploads/signatures/' . $fiche['signature_fichier'] : null;
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8" />
    <title>Fiche <?= $num ?></title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <style>
        :root {
            --c1: #0d47a1;
            --c2: #1976d2;
            --c3: #263238;
            --border: #e0e6ed;
            --bg: #ffffff;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            margin: 0;
            background: #f5f8fb;
            color: #2f3944;
        }

        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 10mm auto;
            background: var(--bg);
            box-shadow: 0 4px 18px -6px rgba(0, 0, 0, .12);
            padding: 18mm 18mm 22mm;
            position: relative;
            display: flex;
            flex-direction: column;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 14mm;
            border-bottom: 2px solid var(--c2);
            padding-bottom: 8mm;
        }

        .brand h1 {
            margin: 0;
            font-size: 22px;
            letter-spacing: .5px;
            background: linear-gradient(90deg, var(--c1), var(--c2));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .meta {
            text-align: right;
            font-size: 12px;
            line-height: 1.4;
            color: #455a64;
        }

        .badge-num {
            display: inline-block;
            background: linear-gradient(90deg, var(--c1), var(--c2));
            color: #fff;
            padding: 6px 14px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 13px;
            margin-top: 6px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 14px;
        }

        .card {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px 16px 16px;
            background: #fff;
            position: relative;
        }

        .card h3 {
            margin: 0 0 10px;
            font-size: 14px;
            letter-spacing: .5px;
            text-transform: uppercase;
            color: var(--c1);
        }

        .field {
            margin: 4px 0;
            font-size: 13px;
        }

        .label {
            color: #607d8b;
            display: inline-block;
            min-width: 120px;
            font-weight: 500;
        }

        .amount-box {
            display: flex;
            gap: 10px;
            margin-top: 4px;
        }

        .pill {
            flex: 1;
            background: linear-gradient(135deg, #e3f2fd, #f5f9ff);
            border: 1px solid #bbdefb;
            padding: 10px 12px;
            border-radius: 10px;
            font-size: 12px;
            text-align: center;
        }

        .pill span {
            display: block;
            font-size: 11px;
            color: #546e7a;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .photo {
            max-width: 160px;
            border: 1px solid var(--border);
            border-radius: 10px;
            box-shadow: 0 4px 12px -4px rgba(0, 0, 0, .15);
        }

        .signature-box {
            margin-top: 12px;
            height: 90px;
            border: 1px dashed #b0bec5;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fafafa;
        }

        .signature-box img {
            max-height: 80px;
            max-width: 100%;
            object-fit: contain;
        }

        footer {
            margin-top: auto;
            border-top: 1px solid var(--border);
            padding-top: 10px;
            font-size: 11px;
            color: #607d8b;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .qr img {
            width: 120px;
            height: 120px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fff;
        }

        .annexe-break {
            page-break-before: always;
        }

        @media print {
            body {
                background: #fff;
            }

            .page {
                margin: 0;
                box-shadow: none;
                width: 210mm;
            }

            .no-print {
                display: none !important;
            }
        }

        .actions-bar {
            position: fixed;
            top: 12px;
            left: 50%;
            transform: translateX(-50%);
            background: #fff;
            padding: 8px 18px;
            border-radius: 50px;
            display: flex;
            gap: 14px;
            box-shadow: 0 6px 24px -8px rgba(0, 0, 0, .25);
            z-index: 200;
        }

        .btn {
            border: none;
            background: linear-gradient(90deg, var(--c1), var(--c2));
            color: #fff;
            padding: 8px 18px;
            font-size: 13px;
            border-radius: 30px;
            cursor: pointer;
            font-weight: 600;
            letter-spacing: .5px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn.secondary {
            background: linear-gradient(90deg, #546e7a, #455a64);
        }

        .btn.outline {
            background: #fff;
            color: var(--c1);
            border: 2px solid var(--c2);
        }

        .btn.outline:hover {
            background: linear-gradient(90deg, var(--c1), var(--c2));
            color: #fff;
        }
    </style>
</head>

<body>
    <script>
        function auditVisualPrint() {
            const payload = new URLSearchParams({
                kind: 'print',
                module: 'decaissement',
                action: 'fiche_pro_print',
                entity_type: 'fiche_decaissement',
                entity_id: <?= json_encode((string)($fiche['id'] ?? $num)) ?>,
                entity_label: <?= json_encode((string)$num) ?>,
                source_path: 'decaissement/fiche_pro.php',
                client_timestamp: new Date().toISOString()
            });

            if (navigator.sendBeacon) {
                navigator.sendBeacon('../request/audit_visual_event.php', payload);
                return;
            }

            fetch('../request/audit_visual_event.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: payload.toString(),
                keepalive: true,
                credentials: 'same-origin'
            }).catch(function() {});
        }
    </script>
    <div class="actions-bar no-print">
        <button class="btn" onclick="auditVisualPrint(); window.print();">🖨️ Imprimer</button>
        <a class="btn outline" href="success_fiche.php?num_fiche=<?= urlencode($num) ?>">⬅️ Retour</a>
        <a class="btn secondary" href="formulaire_demande_decaissement.php">➕ Nouvelle</a>
    </div>
    <section class="page">
        <header>
            <div class="brand">
                <h1>FICHE DÉCAISSEMENT</h1>
                <div style="margin-top:6px; font-size:12px; color:#607d8b;">
                    Entreprise: <strong><?= htmlspecialchars($fiche['entreprise'] ?: 'N/A') ?></strong><br>
                    Générée le <?= date('d/m/Y H:i') ?>
                </div>
                <div class="badge-num">N° <?= $num ?></div>
            </div>
            <div class="qr">
                <?php if ($qrImg): ?>
                    <a href="<?= htmlspecialchars($qrTargetUrl) ?>" target="_blank" style="text-decoration:none;">
                        <img src="<?= $qrImg ?>" alt="QR Code" title="Ouvrir la fiche numérique" />
                    </a>
                    <div style="font-size:9px;color:#607d8b;margin-top:4px;word-break:break-all;max-width:120px;">
                        <?= htmlspecialchars(parse_url($qrTargetUrl, PHP_URL_HOST)) ?>
                    </div>
                <?php else: ?>
                    <div style="width:120px;height:120px;display:flex;align-items:center;justify-content:center;font-size:10px;color:#999;border:1px dashed #ccc;">QR indisponible</div>
                    <div style="font-size:9px;color:#607d8b;margin-top:4px;word-break:break-all;max-width:120px;">
                        <?= htmlspecialchars($qrTargetUrl) ?>
                    </div>
                <?php endif; ?>
            </div>
        </header>

        <div class="grid">
            <div class="card">
                <h3>Bénéficiaire</h3>
                <div class="field"><span class="label">Nom complet :</span> <?= htmlspecialchars($fiche['beficiaire_fiche']) ?></div>
                <div class="field"><span class="label">Téléphone :</span> <?= htmlspecialchars($fiche['tel_beneficiaire_fiche']) ?></div>
                <div class="field"><span class="label">Mode paiement :</span> <?= htmlspecialchars($fiche['mode_paiement'] ?: 'N/A') ?></div>
                <div class="field"><span class="label">Entreprise :</span> <?= htmlspecialchars($fiche['entreprise'] ?: 'N/A') ?></div>
                <?php if ($photo): ?><div style="margin-top:8px;"><img src="<?= $photo ?>" class="photo" alt="Photo" /></div><?php endif; ?>
            </div>
            <div class="card">
                <h3>Chantier & Déboursé</h3>
                <div class="field"><span class="label">Chantier :</span> <?= htmlspecialchars(($fiche['chantier_code'] ?: '') . ' - ' . ($fiche['chantier_titre'] ?: '')) ?></div>
                <div class="field"><span class="label">Client :</span> <?= htmlspecialchars($fiche['nom_client'] ?: 'N/A') ?></div>
                <div class="field"><span class="label">Déboursé :</span> <?= htmlspecialchars($fiche['ligne_devis_label'] ?: 'N/A') ?></div>
                <div class="field"><span class="label">Ligne précise :</span> <?= htmlspecialchars($fiche['ligne_debourse_label'] ?: 'N/A') ?></div>
                <?php if ($fiche['details_demande']): ?><div class="field"><span class="label">Détails :</span> <?= nl2br(htmlspecialchars($fiche['details_demande'])) ?></div><?php endif; ?>
            </div>
            <div class="card">
                <h3>Montants</h3>
                <div class="amount-box">
                    <div class="pill"><strong><?= number_format($fiche['montant_fiche'], 0, ',', ' ') ?> FCFA</strong><span>Demandé</span></div>
                    <div class="pill"><strong><?= ($fiche['decaisse'] ? 'OUI' : 'NON') ?></strong><span>Décaissé</span></div>
                </div>
                <div class="field"><span class="label">Approuvé :</span> <?= ($fiche['approuve'] ? 'Oui' : 'Non') ?> / Conforme : <?= ($fiche['conforme'] ? 'Oui' : 'Non') ?> / OTP : <?= ($fiche['otp_valide'] ? 'Oui' : 'Non') ?></div>
                <div class="field"><span class="label">Créée le :</span> <?= date('d/m/Y H:i', strtotime($fiche['date_creat_fiche'])) ?></div>
            </div>
            <div class="card">
                <h3>Signature</h3>
                <div class="signature-box">
                    <?php if ($signature): ?><img src="<?= $signature ?>" alt="Signature" /><?php else: ?>Aucune signature<?php endif; ?>
                </div>
            </div>
        </div>

        <footer>
            <div>Document généré automatiquement - <?= date('d/m/Y H:i') ?> - Ne pas modifier à la main.</div>
            <div style="text-align:right;">Système Décaissement • BANAMUR / FIDEST</div>
        </footer>
    </section>

    <?php if ($cni): ?>
        <section class="page annexe-break">
            <header style="border-bottom:1px solid #ccc; padding-bottom:6mm; margin-bottom:10mm;">
                <h2 style="margin:0; font-size:18px; color:var(--c1);">Annexe – Pièce d'identité</h2>
                <div style="font-size:12px; color:#607d8b;">Fiche <?= $num ?> • CNI du bénéficiaire</div>
            </header>
            <div style="text-align:center;">
                <img src="<?= $cni ?>" alt="CNI" style="max-width:160mm; box-shadow:0 6px 20px -8px rgba(0,0,0,.25); border:1px solid #ddd; border-radius:12px;" />
            </div>
            <footer>
                <div>Annexe générée automatiquement.</div>
                <div style="text-align:right;">Confidentiel</div>
            </footer>
        </section>
    <?php endif; ?>
</body>

</html>
