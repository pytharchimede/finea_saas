<?php
declare(strict_types=1);

require_once __DIR__.'/../model/NotificationManager.php';
require_once __DIR__.'/ClientBusinessPolicy.php';

final class ClientProjectCompletenessAlertService
{
    public function __construct(private PDO $pdo) {}

    public function dispatch(?string $day = null): array
    {
        $day = $day ?: date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day)) throw new InvalidArgumentException('Date invalide.');
        $lock = $this->pdo->query("SELECT GET_LOCK('finea_daily_completeness', 0)")->fetchColumn();
        if (!$lock) return ['sent'=>false, 'busy'=>true];
        try {
            $check = $this->pdo->prepare('SELECT id FROM finea_completeness_alert_runs WHERE alert_day=? LIMIT 1');
            $check->execute([$day]);
            if ($check->fetchColumn()) return ['sent'=>false, 'duplicate'=>true];
            $items = $this->listing();
            $recipients = $this->recipients();
            if (!$recipients) return ['sent'=>false, 'recipients'=>0];
            new NotificationManager($this->pdo);
            $fingerprint = hash('sha256', json_encode([$day,$items], JSON_UNESCAPED_UNICODE));
            $lines = ['Fiches à normaliser : '.count($items)];
            foreach (array_slice($items, 0, 100) as $item) $lines[] = $item['type'].' #'.$item['id'].' · '.$item['label'].' · '.$item['reason'];
            if (count($items)>100) $lines[] = 'Ouvrez le listing pour consulter toutes les alertes.';
            $message = mb_strcut(implode("\n", $lines), 0, 60000, 'UTF-8');
            $this->pdo->beginTransaction();
            if ($items) {
                $system = $this->pdo->prepare("INSERT INTO system_notifications(created_at,event_key,sub_key,title,message,link_url,recipient_user_id,channels_json,payload_json) VALUES(NOW(),'data_completeness_alert',?,?,?,?,?,'[\"system\"]',?)");
                foreach ($recipients as $id) {
                    $system->execute([$day, 'Fiches incomplètes · '.$day, $message, $this->listingUrl(), $id, json_encode(['day'=>$day,'count'=>count($items)])]);
                }
            }
            $clients = count(array_filter($items, fn($r)=>$r['type']==='Client'));
            $projects = count(array_filter($items, fn($r)=>$r['type']==='Projet'));
            $this->pdo->prepare('INSERT INTO finea_completeness_alert_runs(alert_day,fingerprint,client_count,project_count) VALUES(?,?,?,?)')->execute([$day,$fingerprint,$clients,$projects]);
            $this->pdo->commit();
            return ['sent'=>(bool)$items, 'clients'=>$clients, 'projects'=>$projects, 'suppliers'=>count($items)-$clients-$projects];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('finea_daily_completeness')");
        }
    }

    private function listingUrl(): string
    {
        $configuredUrl = class_exists('EnvConfig') ? (string)EnvConfig::get('APP_BASE_URL', '') : '';
        $base = rtrim((string)(getenv('APP_BASE_PATH') ?: parse_url($configuredUrl, PHP_URL_PATH) ?: ''), '/');
        if (PHP_SAPI !== 'cli') {
            $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
            $relative = str_replace('\\', '/', substr((string)($_SERVER['SCRIPT_FILENAME'] ?? ''), strlen(dirname(__DIR__))));
            if ($relative !== '' && str_ends_with($script, $relative)) $base = substr($script, 0, -strlen($relative));
        }
        return $base.'/fournisseur/alertes_fiches.php';
    }

    public function listing(): array
    {
        require_once __DIR__.'/ClientIdentityMigration.php';
        (new ClientIdentityMigration($this->pdo))->install();
        require_once __DIR__.'/../repositories/FournisseurRepository.php';
        $repo = new FournisseurRepository($this->pdo);
        if (!$repo->isReady()) throw new RuntimeException('Référentiel fournisseurs indisponible.');
        $items = array_merge($this->incompleteClients(), $this->incompleteProjects());
        foreach ($repo->incompleteRows() as $row) {
            $items[] = ['type'=>'Fournisseur', 'id'=>(int)$row['id_fournisseur'], 'label'=>$row['nom_commercial'] ?: $row['raison_sociale'], 'reason'=>implode(', ', $row['missing_document_labels'] ?? []), 'url'=>'edit_fournisseur.php?id='.(int)$row['id_fournisseur']];
        }
        return $items;
    }

    private function recipients(): array
    {
        $columns = $this->pdo->query('SHOW COLUMNS FROM user_devis_banamur')->fetchAll(PDO::FETCH_COLUMN);
        $permissions = array_intersect(['acces_compta','acces_direction','acces_admin'], $columns);
        if (!$permissions) return [];
        return $this->pdo->query('SELECT id FROM user_devis_banamur WHERE active=1 AND ('.implode(' OR ', array_map(fn($p)=>$p.'=1', $permissions)).')')->fetchAll(PDO::FETCH_COLUMN);
    }

    private function incompleteClients():array
    {
        $sql="SELECT id_client,nom_client,COALESCE(ncc,'') ncc FROM client_banamur WHERE NOT ".ClientBusinessPolicy::sqlUsable()." ORDER BY nom_client";
        return array_map(static fn(array $r):array=>['type'=>'Client','url'=>'../clients/modifier.php?id='.(int)$r['id_client'],'id'=>(int)$r['id_client'],'label'=>(string)$r['nom_client'],'reason'=>'Identité fiscale absente ou en attente de validation'], $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)?:[]);
    }
    private function incompleteProjects():array
    {
        // Ces identifiants sont numériques, mais d'anciennes bases les stockent en
        // VARCHAR avec des collations différentes. Une conversion numérique ciblée
        // évite la comparaison textuelle fautive sans modifier les libellés métier.
        $sql="SELECT o.id_offre,o.reference_offre,o.client_id FROM offre_banamur o LEFT JOIN client_banamur c ON CAST(c.id_client AS UNSIGNED)=CAST(o.client_id AS UNSIGNED) WHERE o.client_id IS NULL OR CAST(o.client_id AS UNSIGNED)=0 OR c.id_client IS NULL OR NOT ".ClientBusinessPolicy::sqlUsable('c')." OR TRIM(COALESCE(o.reference_offre,''))='' OR TRIM(COALESCE(o.commercial_dedie,''))='' ORDER BY o.id_offre DESC";
        return array_map(static fn(array $r):array=>['type'=>'Projet','url'=>'../offres/modifier.php?id='.(int)$r['id_offre'],'id'=>(int)$r['id_offre'],'label'=>(string)($r['reference_offre']?:'Projet #'.$r['id_offre']),'reason'=>'Client non conforme ou informations projet incomplètes'], $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)?:[]);
    }
}


