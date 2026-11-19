<?php
// model/PageAccess.php
class PageAccess
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Liste canonique des pages gérables (slug => fichier)
     */
    public static function pagesCatalogue(): array
    {
        return [
            'dashboard' => 'dashboard.php',
            'generer_devis' => 'generer_devis.php',
            'liste_devis' => 'liste_devis.php',
            'liste_client' => 'liste_client.php',
            'liste_offre' => 'liste_offre.php',
            'liste_unite' => 'liste_unite.php',
            'liste_debourse' => 'liste_debourse.php',
            'voir_debourse' => 'voir_debourse.php',
            'gerer_debourse' => 'gerer_debourse.php',
            'liste_chantier' => 'liste_chantier.php',
            'profil' => 'profil.php',
            'liste_utilisateur' => 'liste_utilisateur.php',
            'gestion_acces' => 'gestion_acces.php',
            'portail' => 'selection_portail.php',
        ];
    }

    public function getUserPages(int $userId): array
    {
        try {
            $this->ensureTable();
            $stmt = $this->pdo->prepare("SELECT page_slug FROM user_page_access WHERE user_id = :id AND allowed = 1");
            $stmt->execute(['id' => $userId]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function saveUserPages(int $userId, array $slugs): bool
    {
        $this->ensureTable();
        $this->pdo->beginTransaction();
        try {
            $del = $this->pdo->prepare("DELETE FROM user_page_access WHERE user_id = :id");
            $del->execute(['id' => $userId]);
            $ins = $this->pdo->prepare("INSERT INTO user_page_access (user_id, page_slug, allowed) VALUES (:id, :slug, 1)");
            $slugs = array_unique(array_map('strtolower', $slugs));
            foreach ($slugs as $s) {
                if ($s === '') continue;
                $ins->execute(['id' => $userId, 'slug' => $s]);
            }
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            return false;
        }
    }

    public static function defaultPagesForRole(string $roleNorm): array
    {
        $baseTech = [
            'dashboard',
            'generer_devis',
            'liste_devis',
            'liste_client',
            'liste_offre',
            'liste_unite',
            'liste_debourse',
            'voir_debourse',
            'liste_chantier',
            'profil',
            'portail',
        ];

        switch ($roleNorm) {
            case 'directeur general':
                return array_keys(self::pagesCatalogue());
            case 'technicien deviseur':
                return $baseTech;
            case 'comptable':
            case 'tresorier':
                return ['profil'];
            default:
                return ['profil'];
        }
    }

    private function ensureTable(): void
    {
        static $done = false;
        if ($done) return;
        // Création paresseuse (ne lève pas d'erreur si existe)
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS user_page_access (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, page_slug VARCHAR(100) NOT NULL, allowed TINYINT(1) NOT NULL DEFAULT 1, UNIQUE KEY uq_user_page(user_id,page_slug)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    }
}
