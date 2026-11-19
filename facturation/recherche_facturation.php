<?php
declare(strict_types=1);

// Compatibilité avec l'ancien intitulé utilisé dans certains raccourcis et favoris.
$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: recherche_factures.php' . ($query !== '' ? '?' . $query : ''), true, 302);
exit;
