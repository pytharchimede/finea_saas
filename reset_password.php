<?php
$pageTitle = 'Mot de passe oublié';
$activeMenu = '';
require_once __DIR__ . '/decaissement/includes/header.php';
require_once __DIR__ . '/partials/auth_finea_mark.php';
?>

<div class="min-h-screen bg-[#f5f7fb] py-20 px-6 relative overflow-hidden">
    <div class="absolute inset-0 pointer-events-none">
        <div class="absolute -top-32 -left-32 w-96 h-96 bg-indigo-200 rounded-full blur-3xl opacity-20"></div>
        <div class="absolute bottom-0 right-0 w-[28rem] h-[28rem] bg-purple-200 rounded-full blur-3xl opacity-20"></div>
    </div>
    <div class="min-h-[calc(100vh-10rem)] flex items-center justify-center">
        <div class="w-full max-w-md">
            <div class="relative p-8" style="background:#fff;border:1px solid #eef;border-radius:18px;box-shadow:0 4px 12px -2px rgba(0,0,0,.07);">
                <div class="flex items-center justify-center gap-4 mb-6">
                    <img src="https://fidest.ci/logo/logo_banamur_transparent.png" alt="BANAMUR" class="h-10 sm:h-12 w-auto object-contain" loading="eager" />
                    <div class="h-8 w-px bg-gray-200"></div>
                    <img src="https://fidest.ci/logo/logo_fidest.png" alt="FIDEST" class="h-10 sm:h-12 w-auto object-contain" loading="eager" />
                </div>
                <?php renderAuthFineaMark(['subtitle' => 'Réinitialisation encadrée dans l’environnement Finéa']); ?>
                <div class="w-16 h-16 mx-auto mb-5 rounded-2xl flex items-center justify-center text-white text-xl shadow-md"
                    style="background: linear-gradient(135deg, #4f46e5, #7e22ce);">
                    <i class="fa-solid fa-key"></i>
                </div>

                <div class="text-center">
                    <h2 class="text-2xl font-semibold tracking-tight text-gray-800">Mot de passe oublié</h2>
                    <div class="mt-3 h-1 w-24 mx-auto rounded-full bg-gradient-to-r from-indigo-500 to-purple-500"></div>
                    <p class="mt-3 text-sm text-gray-600">Saisissez votre e-mail professionnel pour recevoir un lien.</p>
                </div>

                <div class="message mt-4" style="display: none;"></div>
                <div class="spinner mt-3 text-center" style="display: none;">
                    <i class="fa-solid fa-spinner fa-spin text-gray-700"></i>
                </div>

                <form id="reset-password-request-form" action="javascript:void(0);" class="mt-6 space-y-4">
                    <div class="relative">
                        <i class="fa-solid fa-envelope absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input type="email" name="mail_pro" placeholder="Adresse e-mail professionnelle" required
                            class="w-full rounded-2xl border border-gray-200 bg-white/80 px-12 py-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>

                    <button type="submit"
                        class="w-full py-3 text-sm font-semibold rounded-2xl bg-gray-900 text-white hover:bg-gray-800 transition-all duration-300 flex items-center justify-center gap-2">
                        <span>Envoyer le lien</span>
                        <i class="fa-solid fa-paper-plane text-xs"></i>
                    </button>
                </form>

                <div class="mt-5 text-center">
                    <a href="login.php" class="text-sm font-semibold text-gray-700 hover:text-indigo-600 transition">
                        <i class="fa-solid fa-arrow-left mr-1"></i>
                        Retour à la connexion
                    </a>
                </div>

                <div class="mt-8 text-center text-xs text-gray-500">© 2024 Logiciel de Devis - BANAMUR INDUSTRIES & TECH</div>
            </div>
        </div>
    </div>
</div>

<script src="js/reset_password.js"></script>

<?php require_once __DIR__ . '/decaissement/includes/footer.php'; ?>