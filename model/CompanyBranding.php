<?php
declare(strict_types=1);

final class CompanyBranding
{
    private static ?array $cache=null;
    public static function defaults(): array
    {
        return ['company_name'=>'BANAMUR INDUSTRIES ET TECHNOLOGIES','legal_name'=>'BANAMUR INDUSTRIES ET TECHNOLOGIES SARL','capital'=>'10 000 000 F CFA','logo_path'=>'logo/default_logo.jpg','icon_path'=>'logo/default_logo.jpg','address'=>'Abidjan, Koumassi Bd. du Gabon prolongé','phone'=>'+225 27 21 36 27 27','email'=>'info@fidest.org','rccm'=>'CI-ABJ-03-2022-B13-02828','tax_number'=>'010274200088','primary_color'=>'#050505','accent_color'=>'#ffcc20','surface_color'=>'#ffffff','text_color'=>'#0f172a','muted_color'=>'#64748b'];
    }
    public static function get(?PDO $pdo=null): array
    {
        if(self::$cache!==null)return self::$cache;
        try{if(!$pdo){require_once __DIR__.'/Database.php';$pdo=Database::getConnection();}$row=$pdo->query('SELECT * FROM finea_company_branding WHERE id=1')->fetch(PDO::FETCH_ASSOC)?:[];}catch(Throwable $e){$row=[];}
        return self::$cache=array_merge(self::defaults(), $row);
    }
    public static function logoFile(?PDO $pdo=null): string
    {
        $configured = self::mediaFile((string)(self::get($pdo)['logo_path'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        // Le logo Banamur doit rester disponible si le chemin en base est vide ou obsolete.
        return self::mediaFile('logo/default_logo.jpg');
    }
    public static function iconFile(?PDO $pdo=null): string{return self::mediaFile((string)(self::get($pdo)['icon_path']??''))?:self::logoFile($pdo);}
    public static function logoUrl(string $rootBase='',?PDO $pdo=null): string{$path=(string)(self::get($pdo)['logo_path']??'');return $path!==''?$rootBase.ltrim($path,'/'):'';}
    public static function iconUrl(string $rootBase='',?PDO $pdo=null): string{$path=(string)(self::get($pdo)['icon_path']??'');return $path!==''?$rootBase.ltrim($path,'/'):self::logoUrl($rootBase,$pdo);}
    private static function mediaFile(string $path): string
    {
        $root = realpath(dirname(__DIR__));
        if (!$root || trim($path) === '') {
            return '';
        }

        $relativePath = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($path)), DIRECTORY_SEPARATOR);
        $file = realpath($root . DIRECTORY_SEPARATOR . $relativePath);
        if (!$file || !is_file($file)) {
            return '';
        }

        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $normalizedFile = str_replace('\\', '/', $file);
        return str_starts_with($normalizedFile, $normalizedRoot) ? $file : '';
    }
    /**
     * Installe ou met à niveau le stockage du branding.
     *
     * Cette méthode effectue des DDL et ne doit être appelée que depuis un
     * flux d'administration, jamais pendant le rendu normal d'une page.
     */
    public static function initializeStorage(PDO $pdo):void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS finea_company_branding(id TINYINT UNSIGNED PRIMARY KEY,company_name VARCHAR(190) NOT NULL,legal_name VARCHAR(255) NULL,capital VARCHAR(190) NULL,logo_path VARCHAR(500) NULL,icon_path VARCHAR(500) NULL,address VARCHAR(500) NULL,phone VARCHAR(100) NULL,email VARCHAR(190) NULL,rccm VARCHAR(190) NULL,tax_number VARCHAR(190) NULL,primary_color CHAR(7) NOT NULL DEFAULT '#050505',accent_color CHAR(7) NOT NULL DEFAULT '#ffcc20',surface_color CHAR(7) NOT NULL DEFAULT '#ffffff',text_color CHAR(7) NOT NULL DEFAULT '#0f172a',muted_color CHAR(7) NOT NULL DEFAULT '#64748b',updated_by_user_id INT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $columns=$pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='finea_company_branding'")->fetchAll(PDO::FETCH_COLUMN);
        $existingColumns=array_fill_keys($columns,true);
        foreach(['capital'=>"VARCHAR(190) NULL AFTER legal_name",'primary_color'=>"CHAR(7) NOT NULL DEFAULT '#050505'",'accent_color'=>"CHAR(7) NOT NULL DEFAULT '#ffcc20'",'surface_color'=>"CHAR(7) NOT NULL DEFAULT '#ffffff'",'text_color'=>"CHAR(7) NOT NULL DEFAULT '#0f172a'",'muted_color'=>"CHAR(7) NOT NULL DEFAULT '#64748b'"] as $column=>$definition){if(!isset($existingColumns[$column])){$pdo->exec("ALTER TABLE finea_company_branding ADD COLUMN {$column} {$definition}");}}
        $pdo->exec("UPDATE finea_company_branding SET capital='10 000 000 F CFA' WHERE id=1 AND (capital IS NULL OR capital='')");
        $existing=(int)$pdo->query('SELECT COUNT(*) FROM finea_company_branding WHERE id=1')->fetchColumn();if($existing)return;$company=[];try{$company=$pdo->query('SELECT * FROM rh_contract_document_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC)?:[];}catch(Throwable $e){}
        $stmt=$pdo->prepare('INSERT INTO finea_company_branding(id,company_name,legal_name,capital,logo_path,icon_path,address,phone,email,rccm,tax_number,primary_color,accent_color,surface_color,text_color,muted_color) VALUES(1,:name,:legal,:capital,:logo,:icon,:address,:phone,:email,:rccm,:tax,:primary,:accent,:surface,:text,:muted)');$stmt->execute(['name'=>$company['employer_name']??'BANAMUR INDUSTRIES ET TECHNOLOGIES','legal'=>trim((string)(($company['employer_name']??'BANAMUR INDUSTRIES ET TECHNOLOGIES').' '.($company['employer_legal_form']??'SARL'))),'capital'=>'10 000 000 F CFA','logo'=>'logo/default_logo.jpg','icon'=>'logo/default_logo.jpg','address'=>$company['headquarters_text']??'Abidjan, Koumassi Bd. du Gabon prolongé','phone'=>'+225 27 21 36 27 27','email'=>'info@fidest.org','rccm'=>$company['rccm_text']??'CI-ABJ-03-2022-B13-02828','tax'=>'010274200088','primary'=>'#050505','accent'=>'#ffcc20','surface'=>'#ffffff','text'=>'#0f172a','muted'=>'#64748b']);
    }
}
