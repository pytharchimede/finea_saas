<?php
declare(strict_types=1);

$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: details.php' . ($query !== '' ? '?' . $query : ''));
exit;
