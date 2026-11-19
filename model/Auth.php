<?php
class Auth
{
    public static function logout()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        session_unset();
        session_destroy();

        header('Location: login.php');
        exit();
    }

    public static function isLoggedIn()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['user_id'])) {
            return false;
        }

        require_once __DIR__ . '/../services/FineaInstanceIdentity.php';

        try {
            return FineaInstanceIdentity::sessionMatches();
        } catch (Throwable $e) {
            error_log('[finea_instance_session] ' . $e->getMessage());
            return false;
        }
    }
}
