<?php
function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function selected($a, $b): string { return (string)$a === (string)$b ? 'selected' : ''; }
function checked($a): string { return !empty($a) ? 'checked' : ''; }
function money($n): string { return number_format((float)$n, 0, ',', ' ') . ' FCFA'; }
function logiBadge(string $status): string {
    $map = ['disponible'=>'success','actif'=>'success','occupe'=>'warning','maintenance'=>'info','panne'=>'danger','hors_service'=>'dark','soumise'=>'warning','en_etude'=>'info','planifiee'=>'primary','en_cours'=>'primary','terminee'=>'success','ouvert'=>'danger','critique'=>'danger','faible'=>'secondary'];
    $class = $map[$status] ?? 'secondary';
    return '<span class="badge bg-'.$class.'">'.e(str_replace('_',' ', $status)).'</span>';
}
