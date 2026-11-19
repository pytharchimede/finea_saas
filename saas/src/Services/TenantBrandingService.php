<?php
declare(strict_types=1);
namespace Finea\Saas\Services;
use Finea\Saas\Database\PlatformDatabase;
use PDO;
use RuntimeException;
final class TenantBrandingService {
 public function forTenantId(int $tenantId): array {
  if ($tenantId<1) throw new RuntimeException('Tenant invalide.');
  $stmt=PlatformDatabase::connection()->prepare('SELECT t.id,t.slug,t.status,t.company_name AS tenant_name,b.* FROM saas_tenants t INNER JOIN saas_tenant_branding b ON b.tenant_id=t.id WHERE t.id=? LIMIT 1');
  $stmt->execute([$tenantId]);
  $row=$stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new RuntimeException('Branding introuvable.');
  return $row;
 }
 public function forTrustedSlug(string $slug): array {
  if (!preg_match('/^[a-z0-9-]{2,100}$/',$slug)) throw new RuntimeException('Slug invalide.');
  $stmt=PlatformDatabase::connection()->prepare('SELECT id FROM saas_tenants WHERE slug=? LIMIT 1');
  $stmt->execute([$slug]);$id=$stmt->fetchColumn();
  if (!$id) throw new RuntimeException('Entreprise introuvable.');
  return $this->forTenantId((int)$id);
 }
}
