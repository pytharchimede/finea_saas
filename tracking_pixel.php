<?php

/**
 * tracking_pixel.php
 * Pixel de tracking pour ouverture d'email
 */

require_once __DIR__ . '/model/Database.php';

// Pixel GIF 1x1
define('GIF', "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;");

// paramètres possibles
$email = $_GET['e'] ?? null;
$token = $_GET['t'] ?? null;

// log fichier
$logFile = __DIR__ . '/storage/tracking_open.log';

$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

try {

    $pdo = Database::getConnection();

    // CAS 1 : tracking par token (nouveau système)
    if ($token) {

        $stmt = $pdo->prepare("
            UPDATE email_logs
            SET opened_at = NOW(),
                ip_address = :ip,
                user_agent = :ua
            WHERE tracking_token = :token
            LIMIT 1
        ");

        $stmt->execute([
            'token' => $token,
            'ip' => $ip,
            'ua' => $userAgent
        ]);
    }

    // CAS 2 : tracking par email (ancien système)
    if ($email) {

        $email = filter_var($email, FILTER_SANITIZE_EMAIL);

        $stmt = $pdo->prepare("
            UPDATE email_logs
            SET opened_at = NOW(),
                ip_address = :ip,
                user_agent = :ua
            WHERE recipient_email = :email
            ORDER BY id DESC
            LIMIT 1
        ");

        $stmt->execute([
            'email' => $email,
            'ip' => $ip,
            'ua' => $userAgent
        ]);

        // log fichier (compatibilité)
        $logEntry = sprintf(
            "%s | %s | %s\n",
            date('Y-m-d H:i:s'),
            $email,
            $ip
        );

        @mkdir(dirname($logFile), 0755, true);
        @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
} catch (Exception $e) {

    error_log('Tracking pixel error: ' . $e->getMessage());
}

// renvoyer le pixel
header('Content-Type: image/gif');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo GIF;
exit;
