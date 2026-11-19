<?php
namespace Ticketing\Database;

use PDO;
use RuntimeException;

/**
 * Adaptateur de connexion Ticketing -> FINEA.
 *
 * IMPORTANT : ce module ne possède pas sa propre configuration BDD.
 * Il réutilise la classe Database existante de FINEA, qui charge déjà le .env via EnvConfig.
 *
 * La classe attend une méthode Database::getConnection(): PDO.
 */
class Connection
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        self::loadFineaDatabaseClass();

        if (!class_exists('Database')) {
            throw new RuntimeException("Classe Database introuvable. Vérifiez que Database.php est présent dans le projet FINEA.");
        }

        if (!method_exists('Database', 'getConnection')) {
            throw new RuntimeException("La classe Database doit exposer la méthode statique getConnection().");
        }

        $pdo = \Database::getConnection();
        if (!$pdo instanceof PDO) {
            throw new RuntimeException("Database::getConnection() doit retourner une instance PDO.");
        }

        self::$pdo = $pdo;
        return self::$pdo;
    }

    private static function loadFineaDatabaseClass(): void
    {
        if (class_exists('Database')) {
            return;
        }

        $candidates = [
            // Cas recommandé : /banamur_project_app/Database.php
            __DIR__ . '/../../Database.php',

            // Cas fréquent : /banamur_project_app/model/Database.php
            __DIR__ . '/../../model/Database.php',
            __DIR__ . '/../../models/Database.php',
            __DIR__ . '/../../config/Database.php',
            __DIR__ . '/../../database/Database.php',

            // Cas où le module est déplacé plus profondément
            dirname(__DIR__, 3) . '/Database.php',
            dirname(__DIR__, 3) . '/model/Database.php',
            dirname(__DIR__, 3) . '/config/Database.php',
        ];

        foreach (array_unique($candidates) as $file) {
            if (is_file($file)) {
                require_once $file;
                if (class_exists('Database')) {
                    return;
                }
            }
        }
    }
}
