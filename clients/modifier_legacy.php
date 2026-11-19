<?php
declare(strict_types=1);

// L’ancienne route conserve ses liens mais utilise le formulaire et les contrôles communs.
$_GET['id'] = (int)($_GET['id'] ?? $_GET['id_client'] ?? $_POST['id_client'] ?? 0);
require __DIR__.'/modifier.php';

