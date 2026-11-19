<?php
declare(strict_types=1);

require_once __DIR__ . '/../model/EnvConfig.php';

final class FineaInstanceIdentity
{
    public static function current(): string
    {
        EnvConfig::loadFromDirectory(__DIR__);

        $mode = trim((string) EnvConfig::get(
            'FINEA_INSTANCE_MODE',
            'development'
        ));

        if ($mode === 'development') {
            return 'development:finea_saas_dev';
        }

        if ($mode !== 'dedicated') {
            throw new RuntimeException('Mode FINEA non autorisé.');
        }

        $uuid = strtolower(trim((string) EnvConfig::get(
            'FINEA_TENANT_UUID',
            ''
        )));

        $database = trim((string) EnvConfig::get(
            'FINEA_TENANT_DB_NAME',
            ''
        ));

        $configuredDatabase = trim((string) EnvConfig::get(
            'PROJECT_DB_NAME',
            ''
        ));

        if (
            !preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D',
                $uuid
            )
            || !preg_match('/^[a-zA-Z0-9_]+$/D', $database)
            || !hash_equals($database, $configuredDatabase)
            || in_array($database, [
                'finea_saas_dev',
                'finea_saas_platform',
            ], true)
        ) {
            throw new RuntimeException('Identité FINEA invalide.');
        }

        return 'dedicated:' . $uuid . ':' . $database;
    }

    public static function bindSession(): void
    {
        $_SESSION['finea_instance_identity'] = self::current();
    }

    public static function sessionMatches(): bool
    {
        $expected = self::current();
        $actual = $_SESSION['finea_instance_identity'] ?? null;

        return is_string($actual)
            && hash_equals($expected, $actual);
    }
}
