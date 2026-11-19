<?php

if (!function_exists('renderAuthFineaMark')) {
    function renderAuthFineaMark(array $options = []): void
    {
        $projectRoot = dirname(__DIR__);
        $logoPath = $projectRoot . '/img/app_logo.png';
        $iconPath = $projectRoot . '/img/app_icon.png';
        $logoUrl = is_file($logoPath) ? 'img/app_logo.png' : '';
        $iconUrl = is_file($iconPath) ? 'img/app_icon.png' : '';
        $subtitle = trim((string) ($options['subtitle'] ?? 'Expérience applicative discrètement propulsée par Finéa'));
        $containerClass = trim((string) ($options['container_class'] ?? 'mt-4 flex items-center justify-center'));
        $badgeClass = trim((string) ($options['badge_class'] ?? 'inline-flex items-center gap-3 rounded-full border border-fuchsia-100 bg-white/85 px-3 py-2 text-[11px] font-medium text-slate-500 shadow-sm backdrop-blur-sm'));
        $labelClass = trim((string) ($options['label_class'] ?? 'text-[10px] font-semibold uppercase tracking-[0.22em] text-fuchsia-500'));
        $logoClass = trim((string) ($options['logo_class'] ?? 'hidden h-7 w-auto object-contain md:block'));
        $iconWrapperClass = trim((string) ($options['icon_wrapper_class'] ?? 'inline-flex h-9 w-9 items-center justify-center rounded-full bg-slate-50 p-1.5 shadow-sm md:hidden'));
        $fallbackIconWrapperClass = trim((string) ($options['fallback_icon_wrapper_class'] ?? 'inline-flex h-9 w-9 items-center justify-center rounded-full bg-slate-50 p-1.5 shadow-sm'));
        $subtitleClass = trim((string) ($options['subtitle_class'] ?? 'block text-[11px] text-slate-500'));
?>
        <div class="<?= htmlspecialchars($containerClass, ENT_QUOTES, 'UTF-8') ?>">
            <div class="<?= htmlspecialchars($badgeClass, ENT_QUOTES, 'UTF-8') ?>">
                <?php if ($iconUrl !== ''): ?>
                    <span class="<?= htmlspecialchars($iconWrapperClass, ENT_QUOTES, 'UTF-8') ?>">
                        <img src="<?= htmlspecialchars($iconUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Icône Finéa" class="h-full w-full object-contain">
                    </span>
                <?php endif; ?>
                <?php if ($logoUrl !== ''): ?>
                    <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Logo Finéa" class="<?= htmlspecialchars($logoClass, ENT_QUOTES, 'UTF-8') ?>">
                <?php elseif ($iconUrl !== ''): ?>
                    <span class="<?= htmlspecialchars($fallbackIconWrapperClass, ENT_QUOTES, 'UTF-8') ?>">
                        <img src="<?= htmlspecialchars($iconUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Icône Finéa" class="h-full w-full object-contain">
                    </span>
                <?php endif; ?>
                <span class="text-left leading-tight">
                    <span class="block <?= htmlspecialchars($labelClass, ENT_QUOTES, 'UTF-8') ?>">Finéa</span>
                    <span class="<?= htmlspecialchars($subtitleClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') ?></span>
                </span>
            </div>
        </div>
<?php
    }
}
