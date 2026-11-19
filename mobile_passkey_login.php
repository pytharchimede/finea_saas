<?php
$token = (string)($_GET['token'] ?? '');
$validToken = (bool)preg_match('/^[A-Za-z0-9_-]{43}$/', $token);
?><!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Validation mobile — FINEA</title>
    <style>
        :root{font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#172033;background:#f6f7fb}
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:radial-gradient(circle at top,#eef2ff 0,#f8fafc 44%,#f6f7fb 100%)}
        .card{width:min(100%,430px);background:#fff;border:1px solid #e8eaf2;border-radius:28px;padding:28px;box-shadow:0 24px 60px rgba(31,41,55,.10)}
        .logo{display:block;max-width:190px;max-height:72px;margin:0 auto 22px}.icon{width:72px;height:72px;margin:auto;border-radius:24px;display:grid;place-items:center;background:linear-gradient(135deg,#4338ca,#7c3aed);color:#fff;font-size:34px}
        h1{text-align:center;font-size:25px;margin:18px 0 8px}.lead{text-align:center;color:#667085;line-height:1.6;margin:0 0 22px}.notice{background:#ecfdf5;border:1px solid #bbf7d0;border-radius:18px;padding:14px;color:#166534;font-size:14px;line-height:1.5;margin-bottom:18px}
        button{width:100%;border:0;border-radius:18px;padding:16px;font-size:16px;font-weight:800;cursor:pointer;background:#111827;color:#fff}button:disabled{opacity:.55;cursor:not-allowed}.small{font-size:12px;color:#7b8497;text-align:center;line-height:1.55;margin-top:16px}.msg{display:none;border-radius:16px;padding:13px 14px;margin-top:16px;font-size:14px;line-height:1.45}.ok{display:block;background:#ecfdf5;color:#166534;border:1px solid #bbf7d0}.err{display:block;background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
    </style>
</head>
<body>
<main class="card" id="mobile-passkey-card" data-token="<?= htmlspecialchars($validToken ? $token : '', ENT_QUOTES, 'UTF-8') ?>">
    <img class="logo" src="branding_asset.php?type=logo" alt="BANAMUR">
    <div class="icon">⌾</div>
    <h1>Confirmer la connexion FINEA</h1>
    <p class="lead">Utilisez l’empreinte, le visage ou le code de verrouillage de ce téléphone. Aucun Bluetooth avec l’ordinateur n’est nécessaire.</p>
    <div class="notice">Votre biométrie reste dans le téléphone. FINEA reçoit uniquement la preuve cryptographique de votre Passkey.</div>
    <?php if ($validToken): ?>
        <button type="button" id="mobile-passkey-confirm">Confirmer mon identité</button>
    <?php else: ?>
        <div class="msg err" style="display:block">Ce lien de connexion est invalide. Scannez un nouveau QR code depuis l’ordinateur.</div>
    <?php endif; ?>
    <div id="mobile-passkey-message" class="msg" role="status" aria-live="polite"></div>
    <p class="small">Cette validation est temporaire et ne connecte que l’ordinateur qui a affiché le QR code.</p>
</main>
<script src="js/mobile-passkey-auth.js"></script>
</body>
</html>
