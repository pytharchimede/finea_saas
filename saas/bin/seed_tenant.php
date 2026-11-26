<?php
declare(strict_types=1);
/** Initialisation CLI d'une base tenant déjà installée. Aucune activation automatique. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
require_once dirname(__DIR__) . '/src/Database/PlatformDatabase.php';
use Finea\Saas\Database\PlatformDatabase;
require_once dirname(__DIR__,2).'/model/StandardUnitSeeder.php';
function fail(string $message): never { fwrite(STDERR, "ERREUR : {$message}\n"); exit(1); }
$id = filter_var(getenv('FINEA_SEED_TENANT_ID'), FILTER_VALIDATE_INT);
$db = (string)(getenv('FINEA_SEED_DB_NAME') ?: '');
$host = (string)(getenv('FINEA_SEED_DB_HOST') ?: '127.0.0.1');
$user = (string)(getenv('FINEA_SEED_DB_USER') ?: '');
$pass = getenv('FINEA_SEED_DB_PASS');
$confirm = (string)(getenv('FINEA_SEED_CONFIRM') ?: '');
if (!$id || !preg_match('/^finea_tenant_[a-z0-9_]+$/D', $db) || $confirm !== $db || !$user || $pass === false || $host === '') fail('Variables FINEA_SEED_TENANT_ID, FINEA_SEED_DB_NAME, FINEA_SEED_DB_HOST, FINEA_SEED_DB_USER, FINEA_SEED_DB_PASS, FINEA_SEED_CONFIRM requises.');
try {
 $platform = PlatformDatabase::connection();
 $stmt=$platform->prepare('SELECT t.id,t.database_name,t.status,b.company_name,b.legal_name,b.capital,b.logo_path,b.icon_path,b.address,b.phone,b.email,b.rccm,b.tax_number,b.primary_color,b.accent_color,b.surface_color,b.text_color,b.muted_color FROM saas_tenants t JOIN saas_tenant_branding b ON b.tenant_id=t.id WHERE t.id=?');
 $stmt->execute([$id]); $tenant=$stmt->fetch(PDO::FETCH_ASSOC);
 if (!$tenant) fail('Entreprise ou branding absent du registre SaaS.');
 if (!hash_equals((string)$tenant['database_name'],$db)) fail('La base cible ne correspond pas au registre de cette entreprise.');
 if ($tenant['status'] !== 'pending') fail('Seules les entreprises en attente peuvent être initialisées.');
 $stmt=$platform->prepare('SELECT full_name,email,initial_password_hash,status FROM saas_tenant_admin_invites WHERE tenant_id=?');
 $stmt->execute([$id]); $invite=$stmt->fetch(PDO::FETCH_ASSOC);
 if (!$invite || !$invite['initial_password_hash'] || $invite['status']!=='pending') fail('Administrateur initial et mot de passe haché requis dans SuccessLab.');
 $hash=(string)$invite['initial_password_hash'];
 if (!preg_match('/^\$(2[aby]|argon2)/',$hash)) fail('Format du hachage du mot de passe inattendu.');
 $parts=preg_split('/\s+/u',trim((string)$invite['full_name']),2); $nom=$parts[0]??''; $prenom=$parts[1]??'-';
 if (!$nom || !filter_var($invite['email'],FILTER_VALIDATE_EMAIL)) fail('Nom ou email initial invalide.');
 $pdo=new PDO('mysql:host='.$host.';dbname='.$db.';charset=utf8mb4',$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
 if ($pdo->query('SELECT DATABASE()')->fetchColumn()!==$db) fail('Base connectée inattendue.');
 $count=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'")->fetchColumn();
 $fks=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema=DATABASE() AND constraint_type='FOREIGN KEY'")->fetchColumn();
 if ($count!==415 || $fks!==157) fail("Schéma incomplet : {$count} tables, {$fks} contraintes (attendu : 415 et 157).");
 foreach (['finea_company_branding','user_devis_banamur','role_devis_banamur'] as $table) { $check=$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn(); if ((int)$check!==0) fail("Table {$table} non vide. Initialisation refusée pour éviter les doublons."); }
 $pdo->beginTransaction();
 try {
  $s=$pdo->prepare('INSERT INTO role_devis_banamur (lib_role_devis) VALUES (?)');$s->execute(['Administrateur FINEA SaaS']);$role=(int)$pdo->lastInsertId();
  $s=$pdo->prepare('INSERT INTO user_devis_banamur (mail_pro,password,nom,prenom,role_id,active,acces_admin,acces_direction,gestion_utilisateur,acces_gestion_acces,acces_dashboard,acces_profil,photo,signature) VALUES (?,?,?,?,?,1,1,0,1,1,1,1,?,?)');
  $s->execute([$invite['email'],$hash,$nom,$prenom,$role,'','']);$userId=(int)$pdo->lastInsertId();
  $columns=['id','company_name','legal_name','capital','logo_path','icon_path','address','phone','email','rccm','tax_number','primary_color','accent_color','surface_color','text_color','muted_color','updated_by_user_id'];
  $values=[1,$tenant['company_name'],$tenant['legal_name'],$tenant['capital'],$tenant['logo_path'],$tenant['icon_path'],$tenant['address'],$tenant['phone'],$tenant['email'],$tenant['rccm'],$tenant['tax_number'],$tenant['primary_color'],$tenant['accent_color'],$tenant['surface_color'],$tenant['text_color'],$tenant['muted_color'],$userId];
  $sql='INSERT INTO finea_company_branding (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')';
  $pdo->prepare($sql)->execute($values);
  $unitResult=StandardUnitSeeder::seed($pdo);
  $pdo->commit();
 } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 // Le marquage plateforme intervient après le commit métier; un échec est récupérable manuellement.
 try {$s=$platform->prepare("UPDATE saas_tenant_admin_invites SET status='provisioned',updated_at=NOW() WHERE tenant_id=? AND status='pending'");$s->execute([$id]);}
 catch(Throwable $e){error_log('FINEA seed platform update: '.$e->getMessage());fwrite(STDERR,"ATTENTION : compte créé mais statut d'invitation non synchronisé. Vérifier manuellement.\n");}
 fwrite(STDOUT,"SUCCÈS : administrateur #{$userId} et branding créés, {$unitResult['added']} unités ajoutées dans {$db}. Entreprise NON ACTIVÉE.\n");
} catch(Throwable $e) {fail('Initialisation interrompue : '.$e->getMessage());}
