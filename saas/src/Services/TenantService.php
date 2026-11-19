<?php
declare(strict_types=1);

namespace Finea\Saas\Services;

use Finea\Saas\Database\PlatformDatabase;
use PDO;
use RuntimeException;
use Throwable;

final class TenantService
{
    public function list(): array
    {
        return PlatformDatabase::connection()->query(
            'SELECT t.id, t.uuid, t.slug, t.company_name,
                    t.database_name, t.status, t.created_at,
                    b.primary_color, b.accent_color
             FROM saas_tenants t
             LEFT JOIN saas_tenant_branding b ON b.tenant_id = t.id
             ORDER BY t.id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(
        string $companyName,
        ?string $legalName = null,
        string $primaryColor = '#173B68',
        string $accentColor = '#D9A441'
    ): int {
        $companyName = trim($companyName);
        $legalName = trim((string) $legalName);

        if ($companyName === '' || mb_strlen($companyName) > 190) {
            throw new RuntimeException('Nom commercial invalide.');
        }

        if (mb_strlen($legalName) > 255) {
            throw new RuntimeException('Raison sociale trop longue.');
        }

        foreach ([$primaryColor, $accentColor] as $color) {
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                throw new RuntimeException('Couleur invalide.');
            }
        }

        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $companyName);
        $normalized = $normalized === false ? $companyName : $normalized;

        $base = strtolower($normalized);
        $base = preg_replace('/[^a-z0-9]+/', '-', $base);
        $base = trim((string) $base, '-');
        $base = substr($base, 0, 35);
        $base = trim($base, '-');

        if ($base === '') {
            $base = 'entreprise';
        }

        // Le suffixe aléatoire évite les collisions entre entreprises.
        $slug = $base . '-' . bin2hex(random_bytes(4));
        $databaseName = 'finea_tenant_' . str_replace('-', '_', $slug);

        // UUID v4 conforme aux bits de version et de variante.
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        $uuid = sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );

        $pdo = PlatformDatabase::connection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO saas_tenants
                 (uuid, slug, company_name, legal_name,
                  database_name, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
            );

            $stmt->execute([
                $uuid,
                $slug,
                $companyName,
                $legalName ?: null,
                $databaseName,
                'pending',
            ]);

            $id = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                'INSERT INTO saas_tenant_branding
                 (tenant_id, company_name, legal_name,
                  primary_color, accent_color, surface_color,
                  text_color, muted_color, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );

            $stmt->execute([
                $id,
                $companyName,
                $legalName ?: null,
                $primaryColor,
                $accentColor,
                '#FFFFFF',
                '#172033',
                '#64748B',
            ]);

            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
