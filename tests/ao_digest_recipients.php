<?php
require_once __DIR__ . '/../repositories/AoDigestRepository.php';
final class AoRecipientStatement extends PDOStatement
{
    private int $offset = 0;
    public function __construct(private array $rows) {}
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetchColumn(int $column = 0): mixed { return $this->rows[$this->offset++][$column] ?? false; }
}
final class AoRecipientPdo extends PDO
{
    public array $users = [];
    public array $staff = [];
    public array $heads = [1];
    public function __construct() {}
    public function exec(string $statement): int|false { return 0; }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (str_contains($query, 'FROM user_devis_banamur u')) return new AoRecipientStatement($this->users);
        if (str_contains($query, 'FROM personnel p')) return new AoRecipientStatement($this->staff);
        if (str_contains($query, 'SHOW TABLES')) return new AoRecipientStatement([['rh_service_hierarchy']]);
        if (str_contains($query, 'FROM rh_service_hierarchy')) return new AoRecipientStatement($this->heads);
        throw new RuntimeException('Unexpected query');
    }
}
$pdo = new AoRecipientPdo();
$base = ['personnel_id'=>0,'prenom'=>'Personne','nom'=>'Test','mail_pro'=>'','service_id'=>0,'service_label'=>'','personnel_email'=>'',
    'acces_direction'=>0,'ao_dg_decision'=>0,'acces_admin'=>0,'ao_admin'=>0,'admin_enabled'=>null];
$pdo->users = [array_replace($base,['user_id'=>1,'personnel_id'=>10,'service_id'=>4,'mail_pro'=>'chef@example.test']),
    array_replace($base,['user_id'=>2,'personnel_id'=>20,'service_id'=>7,'mail_pro'=>'tech@example.test']),
    array_replace($base,['user_id'=>3,'mail_pro'=>'dg@example.test','acces_direction'=>1]),
    array_replace($base,['user_id'=>4,'mail_pro'=>'admin@example.test','acces_admin'=>1,'admin_enabled'=>0]),
    array_replace($base,['user_id'=>5,'mail_pro'=>'','ao_admin'=>1]),
    array_replace($base,['user_id'=>6,'personnel_id'=>60,'service_id'=>4,'mail_pro'=>'double@example.test']),
    array_replace($base,['user_id'=>7,'personnel_id'=>70,'service_id'=>7,'mail_pro'=>'DOUBLE@example.test']),
    array_replace($base,['user_id'=>8,'personnel_id'=>80,'service_id'=>4,'mail_pro'=>'admincommercial@example.test','acces_admin'=>1,'admin_enabled'=>0]),
    array_replace($base,['user_id'=>9,'mail_pro'=>'unrelated@example.test'])];
$pdo->staff = [['id'=>10,'name'=>'Chef personnel','email'=>'chef@example.test','service_id'=>4,'service_label'=>'Commercial'],
    ['id'=>90,'name'=>'Commercial sans compte','email'=>'sanscompte@example.test','service_id'=>4,'service_label'=>'Commercial'],
    ['id'=>91,'name'=>'Sans email','email'=>'','service_id'=>7,'service_label'=>'Technique']];
$repo = new AoDigestRepository($pdo);
$settings = ['commercial_service_ids'=>[4],'technical_service_ids'=>[7],'primary_user_id'=>0];
$rows = $repo->recipients($settings);
$users = [];foreach($rows as $row) if($row['user_id']) $users[$row['user_id']]=$row;
function expectRecipients(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
expectRecipients($users[1]['role']==='to','Responsable principal de la hiérarchie RH');
expectRecipients($users[2]['required'] && $users[3]['required'],'Technique et DG obligatoires');
expectRecipients($users[4]['status']==='disabled','Admin désactivé');
expectRecipients($users[5]['status']==='invalid_email','Admin sans mail signalé');
expectRecipients($users[7]['status']==='duplicate','Adresse commune dédupliquée');
expectRecipients($users[8]['status']==='included','Appartenance commerciale obligatoire prioritaire sur rôle admin');
expectRecipients(!isset($users[9]),'Aucun destinataire hors du périmètre');
$envelope=AoDigestPolicy::envelope($rows);
expectRecipients(in_array('sanscompte@example.test',array_column($envelope['cc'],'email'),true),'Collaborateur sans compte inclus');
$settings['primary_user_id']=2;
$blocked=false;try { AoDigestPolicy::envelope($repo->recipients($settings)); }catch(RuntimeException $e){$blocked=true;}
expectRecipients($blocked,'Responsable sélectionné hors commercial refusé');
$settings['primary_user_id']=0;$pdo->heads=[1,6];
$blocked=false;try { AoDigestPolicy::envelope($repo->recipients($settings)); }catch(RuntimeException $e){$blocked=true;}
expectRecipients($blocked,'Hiérarchie ambiguë bloquée');
echo "10 vérifications de destinataires réussies.\n";
