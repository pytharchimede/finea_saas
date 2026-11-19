class PortalBadgeSettingsManager
{
private \PDO $pdo;

public function __construct(\PDO $pdo)
{
$this->pdo = $pdo;
}

public function ensureTable(): void
{
$this->pdo->exec(
"CREATE TABLE IF NOT EXISTS portal_badge_settings (
module_key VARCHAR(120) NOT NULL PRIMARY KEY,
counted_description TEXT NULL,
variant_labels_json LONGTEXT NULL,
thresholds_json LONGTEXT NULL,
updated_by INT NULL,
updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);
}

public function getAll(): array
{
$this->ensureTable();
$stmt = $this->pdo->query('SELECT module_key, counted_description, variant_labels_json, thresholds_json, updated_by, updated_at FROM portal_badge_settings ORDER BY module_key ASC');
$rows = $stmt ? ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) : [];
$settings = [];

foreach ($rows as $row) {
$moduleKey = trim((string) ($row['module_key'] ?? ''));
if ($moduleKey === '') {
continue;
}

$variantLabels = json_decode((string) ($row['variant_labels_json'] ?? ''), true);
$thresholds = json_decode((string) ($row['thresholds_json'] ?? ''), true);

$settings[$moduleKey] = [
'module_key' => $moduleKey,
'counted_description' => trim((string) ($row['counted_description'] ?? '')),
'variant_labels' => is_array($variantLabels) ? $variantLabels : [],
'thresholds' => is_array($thresholds) ? $thresholds : [],
'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
'updated_at' => (string) ($row['updated_at'] ?? ''),
];
}

return $settings;
}

public function save(string $moduleKey, array $data, ?int $updatedBy = null): void
{
$this->ensureTable();

$moduleKey = trim($moduleKey);
if ($moduleKey === '') {
throw new \InvalidArgumentException('Module invalide.');
}

$countedDescription = trim((string) ($data['counted_description'] ?? ''));
$variantLabels = is_array($data['variant_labels'] ?? null) ? $data['variant_labels'] : [];
$thresholds = is_array($data['thresholds'] ?? null) ? $data['thresholds'] : [];

$normalizedVariantLabels = [];
foreach (['watch', 'urgent', 'critical'] as $variant) {
$normalizedVariantLabels[$variant] = trim((string) ($variantLabels[$variant] ?? ''));
}

$normalizedThresholds = [];
foreach ($thresholds as $key => $value) {
$thresholdKey = trim((string) $key);
if ($thresholdKey === '') {
continue;
}
$normalizedThresholds[$thresholdKey] = is_numeric($value) ? (int) $value : trim((string) $value);
}

$stmt = $this->pdo->prepare(
'INSERT INTO portal_badge_settings (module_key, counted_description, variant_labels_json, thresholds_json, updated_by)
VALUES (:module_key, :counted_description, :variant_labels_json, :thresholds_json, :updated_by)
ON DUPLICATE KEY UPDATE
counted_description = VALUES(counted_description),
variant_labels_json = VALUES(variant_labels_json),
thresholds_json = VALUES(thresholds_json),
updated_by = VALUES(updated_by),
updated_at = CURRENT_TIMESTAMP'
);
$stmt->execute([
'module_key' => $moduleKey,
'counted_description' => $countedDescription !== '' ? $countedDescription : null,
'variant_labels_json' => json_encode($normalizedVariantLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
'thresholds_json' => json_encode($normalizedThresholds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
'updated_by' => $updatedBy,
]);
}
}