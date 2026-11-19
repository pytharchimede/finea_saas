<?php
function ticket_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ticket_num($n): string { return number_format((float)$n, 0, ',', ' '); }
function ticket_current_action(): string { return (string)($_GET['action'] ?? 'dashboard'); }
function ticket_links(): array {
    return [
        ['href'=>'index.php','action'=>'dashboard','icon'=>'fa-chart-line','label'=>'Dashboard','hint'=>'Vue générale'],
        ['href'=>'index.php?action=create','action'=>'create','icon'=>'fa-plus','label'=>'Nouveau ticket','hint'=>'Créer une demande'],
        ['href'=>'index.php?action=mine','action'=>'mine','icon'=>'fa-user-pen','label'=>'Mes tickets','hint'=>'Mes demandes'],
        ['href'=>'index.php?action=received','action'=>'received','icon'=>'fa-inbox','label'=>'Tickets reçus','hint'=>'À traiter'],
        ['href'=>'index.php?action=service','action'=>'service','icon'=>'fa-people-group','label'=>'Tickets du service','hint'=>'Service'],
        ['href'=>'index.php?action=admin','action'=>'admin','icon'=>'fa-shield-halved','label'=>'Administration','hint'=>'Tous les tickets'],
    ];
}
function ticket_sidebar(): string {
    $current = ticket_current_action();
    $html = '<aside class="ticket-aside">';
    $html .= '<div class="ticket-aside-head"><p>Accès rapides</p><h2>Tickets</h2><span>Navigation interne du Centre de Services.</span></div><div class="ticket-aside-links">';
    foreach (ticket_links() as $l) {
        $active = ($current === $l['action']) || ($current === 'dashboard' && $l['action'] === 'dashboard');
        $class = $active ? 'ticket-aside-link active' : 'ticket-aside-link';
        $html .= '<a class="'.$class.'" href="'.ticket_h($l['href']).'"><span class="ticket-aside-icon"><i class="fa-solid '.ticket_h($l['icon']).'"></i></span><span><b>'.ticket_h($l['label']).'</b><small>'.($active ? 'Page courante' : ticket_h($l['hint'])).'</small></span></a>';
    }
    return $html . '</div></aside>';
}
function ticket_status_badge(string $status): string {
    $labels = ['nouveau'=>'Nouveau','pris_en_charge'=>'Pris en charge','en_cours'=>'En cours','en_attente'=>'En attente','resolu'=>'Résolu','cloture'=>'Clôturé','rejete'=>'Rejeté'];
    return '<span class="ticket-badge status-'.ticket_h($status).'">'.ticket_h($labels[$status] ?? str_replace('_',' ', $status)).'</span>';
}
function ticket_priority_badge(?string $label, ?string $color = null): string {
    return '<span class="ticket-priority" style="--p:'.ticket_h($color ?: '#1d2b57').'">'.ticket_h($label ?: '-').'</span>';
}
function ticket_category_pill(?string $label, ?string $icon = null, ?string $color = null, bool $big = false): string {
    return '<span class="ticket-category '.($big?'big':'').'" style="--c:'.ticket_h($color ?: '#1d2b57').'"><i class="fa-solid '.ticket_h($icon ?: 'fa-ticket').'"></i>'.ticket_h($label ?: 'Sans catégorie').'</span>';
}
function ticket_file_icon(string $name): string {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return match ($ext) {
        'pdf' => 'fa-file-pdf',
        'xls', 'xlsx', 'csv' => 'fa-file-excel',
        'doc', 'docx' => 'fa-file-word',
        'jpg', 'jpeg', 'png', 'gif', 'webp' => 'fa-file-image',
        'zip', 'rar', '7z' => 'fa-file-zipper',
        default => 'fa-file-lines',
    };
}
function ticket_card_open(string $eyebrow, string $title = '', string $right = ''): void {
    echo '<section class="ticket-card"><div class="ticket-card-head"><div><p>'.ticket_h($eyebrow).'</p>'.($title ? '<h2>'.ticket_h($title).'</h2>' : '').'</div>'.$right.'</div>';
}
function ticket_card_close(): void { echo '</section>'; }
function ticket_input(string $label, string $name, string $value='', string $type='text', string $attrs=''): string {
    if(ticket_current_action()==='create') $value=(string)($_SESSION['ticket_form_old'][$name] ?? $value);
    return '<label class="ticket-field"><span>'.ticket_h($label).'</span><input type="'.$type.'" name="'.ticket_h($name).'" value="'.ticket_h($value).'" '.$attrs.'></label>';
}
function ticket_search_select(string $label, string $name, array $options, string $placeholder = 'Saisir pour rechercher', bool $multiple = false, string $attrs = ''): string {
    $old=ticket_current_action()==='create' ? ($_SESSION['ticket_form_old'][rtrim($name,'[]')] ?? []) : [];
    $selected=ticket_h(json_encode(array_map('strval',(array)$old)));
    $json = ticket_h(json_encode(array_values($options), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $inputName = $multiple ? rtrim($name, '[]') . '[]' : $name;
    $html = '<label class="ticket-field ticket-search-field"><span>'.ticket_h($label).'</span>';
    $html .= '<div class="ticket-search-select" data-ticket-select data-selected="'.$selected.'" data-options="'.$json.'" data-name="'.ticket_h($inputName).'" data-multiple="'.($multiple ? '1' : '0').'" '.$attrs.'>';
    $html .= '<div class="ticket-search-control"><div class="ticket-search-values"></div><input type="text" autocomplete="off" placeholder="'.ticket_h($placeholder).'"><i class="fa-solid fa-chevron-down"></i></div>';
    $html .= '<div class="ticket-search-menu"></div>';
    $html .= '</div></label>';
    return $html;
}
