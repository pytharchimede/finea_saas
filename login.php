<?php
$pageTitle = 'Connexion';
$activeMenu = '';
require_once __DIR__ . '/decaissement/includes/header.php';
require_once __DIR__ . '/partials/auth_finea_mark.php';
$loginCompanyBranding = $layoutCompanyBranding;
$loginName = htmlspecialchars((string)$loginCompanyBranding['company_name'], ENT_QUOTES, 'UTF-8');
?>

<style>
.tenant-login {background:linear-gradient(135deg,#f4f6fa 40%,color-mix(in srgb,var(--brand-primary,#081c65) 12%,white));}
.tenant-login .login-card {border-top:5px solid var(--brand-primary,#081c65)!important;border-bottom:3px solid var(--brand-accent,#d71920)!important;}
.tenant-login .login-logo {background:white;border:1px solid #e2e8f0;border-radius:14px;padding:14px;display:flex;justify-content:center;align-items:center;}
.tenant-login .login-logo img {width:100%;height:90px;object-fit:contain;}
.tenant-login #login-state-icon,.tenant-login button[type=submit],.tenant-login #mobile-qr-login-button {background:var(--brand-primary,#081c65)!important;color:var(--brand-on-primary,#fff)!important;}
.tenant-login #login-state-title {color:var(--brand-primary,#081c65);}
.tenant-login .login-rule {background:var(--brand-accent,#d71920)!important;}
.tenant-login #passkey-login-button {background:#fff!important;color:var(--brand-primary,#081c65)!important;border:1px solid var(--brand-primary,#081c65)!important;}
.tenant-login input:focus-visible,.tenant-login button:focus-visible,.tenant-login a:focus-visible {outline:2px solid var(--brand-primary,#081c65);outline-offset:3px;}
</style>
<div class="tenant-login min-h-screen py-12 px-6 relative overflow-hidden">
    <div class="absolute inset-0 pointer-events-none">
        <div class="absolute -top-32 -left-32 w-96 h-96 bg-slate-200 rounded-full blur-3xl opacity-20"></div>
        <div class="absolute bottom-0 right-0 w-[28rem] h-[28rem] bg-slate-200 rounded-full blur-3xl opacity-20"></div>
    </div>
    <div class="min-h-[calc(100vh-10rem)] flex items-center justify-center">
        <div class="w-full max-w-md">
            <div class="login-card relative p-8" style="background:#fff;border:1px solid #eef;border-radius:18px;box-shadow:0 4px 12px -2px rgba(0,0,0,.07);">
                <div class="login-logo mb-6">
                    <?php if (!empty($loginCompanyBranding['logo_path'])): ?>
                    <img src="<?= htmlspecialchars($layoutBrandLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= $loginName ?>" loading="eager" />
                    <?php else: ?><strong><?= $loginName ?></strong><?php endif; ?>
                </div>
                <?php renderAuthFineaMark(['subtitle' => 'Espace sécurisé propulsé par Finéa']); ?>
                <div id="login-state-icon" class="w-16 h-16 mx-auto mb-5 rounded-2xl flex items-center justify-center text-white text-xl shadow-md"
                    style="background: linear-gradient(135deg, #4f46e5, #7e22ce);">
                    <i class="fa-solid fa-right-to-bracket"></i>
                </div>

                <div class="text-center">
                    <h2 id="login-state-title" class="text-2xl font-semibold tracking-tight text-gray-800">Connexion à votre espace professionnel</h2>
                    <div class="login-rule mt-3 h-1 w-24 mx-auto rounded-full"></div>
                    <p id="login-state-subtitle" class="mt-3 text-sm text-gray-600">Veuillez saisir vos identifiants professionnels</p>
                </div>

                <div class="attempts-info mt-4 text-sm font-semibold text-gray-700" style="display: none;"></div>
                <div class="message mt-4" style="display: none;"></div>
                <div class="spinner mt-3 text-center" style="display: none;"><i class="fa-solid fa-spinner fa-spin text-gray-700"></i></div>

                <form id="login-form" action="javascript:void(0);" class="mt-6 space-y-4">
                    <div class="relative">
                        <i class="fa-solid fa-envelope absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input type="email" name="mail_pro" placeholder="Adresse e-mail professionnelle" required class="w-full rounded-2xl border border-gray-200 bg-white/80 px-12 py-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                    <div class="relative">
                        <i class="fa-solid fa-lock absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input type="password" name="password" placeholder="Mot de passe" required class="w-full rounded-2xl border border-gray-200 bg-white/80 px-12 py-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                    <button type="submit" class="w-full py-3 text-sm font-semibold rounded-2xl bg-gray-900 text-white hover:bg-gray-800 transition-all duration-300 flex items-center justify-center gap-2">
                        <span>Se connecter</span><i class="fa-solid fa-arrow-right text-xs"></i>
                    </button>
                </form>

                <div id="login-method-separator" class="my-5 flex items-center gap-3 text-xs font-semibold uppercase tracking-wider text-gray-400">
                    <span class="h-px flex-1 bg-gray-200"></span><span>ou</span><span class="h-px flex-1 bg-gray-200"></span>
                </div>

                <div id="passkey-secured-account" class="mt-6 hidden overflow-hidden rounded-3xl border border-emerald-200 bg-gradient-to-br from-emerald-50 via-white to-sky-50 p-5 text-left shadow-sm">
                    <div class="flex gap-3"><span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-emerald-600 text-white"><i class="fa-solid fa-shield-halved"></i></span><div><b class="block text-emerald-950">Ce compte est protégé par Passkey</b><p class="mt-1 text-sm leading-6 text-emerald-800">Confirmez avec une Passkey locale ou scannez le QR code avec votre téléphone. Le mode QR ne nécessite aucun Bluetooth sur l’ordinateur.</p></div></div>
                    <div class="mt-4 flex items-center gap-2 rounded-2xl bg-white px-4 py-3 text-xs font-semibold text-slate-600"><i class="fa-solid fa-lock text-indigo-600"></i>Vos données biométriques restent sur votre appareil et ne sont jamais transmises à FINEA.</div>
                    <button type="button" id="use-another-account" class="mt-4 flex w-full items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-700 transition hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-800"><i class="fa-solid fa-user-plus"></i>Se connecter avec un autre compte</button>
                </div>

                <button type="button" id="passkey-login-button" class="w-full py-3 text-sm font-semibold rounded-2xl border border-indigo-200 bg-indigo-50 text-indigo-800 hover:bg-indigo-100 transition flex items-center justify-center gap-2">
                    <i class="fa-solid fa-fingerprint"></i><span>Se connecter avec une Passkey</span>
                </button>
                <p class="mt-3 text-center text-xs leading-5 text-gray-500">Passkey locale, Windows Hello ou authentificateur compatible.</p>

                <button type="button" id="mobile-qr-login-button" class="mt-3 w-full py-3 text-sm font-bold rounded-2xl bg-slate-900 text-white hover:bg-slate-800 transition flex items-center justify-center gap-2">
                    <i class="fa-solid fa-qrcode"></i><span>Utiliser mon téléphone — sans Bluetooth</span>
                </button>
                <p class="mt-2 text-center text-xs leading-5 text-gray-500">Le téléphone valide votre Passkey directement auprès de FINEA. Aucun Bluetooth, lecteur d’empreinte ou droit administrateur n’est requis sur ce PC.</p>

                <section id="mobile-qr-login-panel" class="hidden mt-4 rounded-3xl border border-slate-200 bg-slate-50 p-5 text-center">
                    <div class="flex items-center justify-between gap-3 text-left">
                        <div><p class="text-xs font-black uppercase tracking-wider text-indigo-600">Connexion par téléphone</p><h3 class="mt-1 font-bold text-slate-900">Scannez avec l’appareil photo</h3></div>
                        <span id="mobile-qr-countdown" class="rounded-full bg-white px-3 py-1 text-xs font-bold text-slate-600 shadow-sm">300s</span>
                    </div>
                    <div class="mt-4 rounded-2xl bg-white p-3 shadow-sm"><img id="mobile-qr-image" class="mx-auto w-full max-w-[260px]" alt=""></div>
                    <div id="mobile-qr-status" class="mt-3 rounded-2xl border border-indigo-100 bg-indigo-50 px-4 py-3 text-sm text-indigo-800">Préparation du QR code…</div>
                    <button type="button" id="mobile-qr-cancel" class="mt-3 text-sm font-bold text-slate-500 hover:text-slate-800">Fermer le QR code</button>
                </section>

                <div id="password-recovery" class="mt-5 text-center">
                    <a href="reset_password.php" class="text-sm font-semibold text-gray-700 hover:text-indigo-600 transition"><i class="fa-solid fa-key mr-1"></i>Mot de passe oublié ?</a>
                </div>

                <div class="mt-8 text-center text-xs text-gray-500">© <?= date('Y') ?> <?= $loginName ?> · Propulsé par Finéa</div>
            </div>
        </div>
    </div>
</div>

<script src="js/login.js"></script>
<script src="js/mobile-qr-login.js"></script>

<?php require_once __DIR__ . '/decaissement/includes/footer.php'; ?>
