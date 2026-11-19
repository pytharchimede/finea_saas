<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../controllers/LogisticsFuelController.php';
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Méthode non autorisée.');
    $controller = new LogisticsFuelController();
    if (($_POST['action'] ?? '') === 'delete') { $controller->delete($_POST); } else { $controller->save($_POST); }
    header('Location: ../fuel.php?success=1'); exit;
} catch (Throwable $e) { http_response_code(500); echo '<h3>Erreur logistique</h3><p>'.htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8').'</p>'; }
