<?php
require_once __DIR__ . '/../../model/Database.php';

// SaaS: historical app access uses the same isolated tenant connection.
if (!class_exists('DatabaseApp')) {
    class DatabaseApp
    {
        public static function getConnection()
        {
            return Database::getConnection();
        }
    }
}
