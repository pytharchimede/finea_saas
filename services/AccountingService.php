<?php
declare(strict_types=1);

/** Amounts are stored as integer hundredths of XOF. All mutations share a book lock. */
final class AccountingService
{
    public function __construct(private PDO $pdo, bool $install = true, private ?string $sourceRoot = null)
    {
        if ($install) {
            $this->install();
        }
    }

    private const SCHEMA_VERSION = 5;

    public function install(): void
    {
        if ($this->schemaIsCurrent()) return;
        // MySQL DDL commits implicitly: use a connection lock before creating/upgrading tables.
        $database=(string)$this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $lock='accounting_install_'.substr(hash('sha256',$database),0,40);
        $statement=$this->pdo->prepare('SELECT GET_LOCK(?, 30)');
        $statement->execute([$lock]);
        if ((int)$statement->fetchColumn()!==1) {
            throw new RuntimeException('Initialisation comptable en cours. Rechargez la page dans quelques instants.');
        }
        try {
            $this->installSchema();
        } finally {
            $statement=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$lock]);
        }
    }

    private function schemaIsCurrent(): bool
    {
        try {
            return (int)$this->pdo->query('SELECT schema_version FROM accounting_settings WHERE id=1')->fetchColumn() >= self::SCHEMA_VERSION;
        } catch (PDOException $e) {
            if ($e->getCode()!=='42S02') throw $e;
            return false;
        }
    }

    private function installSchema(): void
    {
        if ($this->schemaIsCurrent()) return;
        $sql = (string) file_get_contents(__DIR__ . '/../Database/add_accounting_module.sql');
        foreach (explode(';', $sql) as $statement) {
            if (trim($statement) !== '') {
                $this->pdo->exec($statement);
            }
        }
        $columns=$this->pdo->query('SHOW COLUMNS FROM accounting_entry_sources')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('active_fiche_number',$columns,true)) {
            $this->pdo->exec('ALTER TABLE accounting_entry_sources ADD COLUMN active_fiche_number VARCHAR(120) NULL, ADD UNIQUE KEY uq_accounting_source_fiche (active_fiche_number)');
            $this->pdo->exec("UPDATE accounting_entry_sources SET active_fiche_number=JSON_UNQUOTE(JSON_EXTRACT(source_snapshot,'$.fiche_number')) WHERE active_point_item_id IS NOT NULL");
        }
        $columns=$this->pdo->query('SHOW COLUMNS FROM accounting_settings')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('chart_revision',$columns,true)) $this->pdo->exec("ALTER TABLE accounting_settings ADD COLUMN chart_revision VARCHAR(64) NOT NULL DEFAULT ''");
        $columns=$this->pdo->query('SHOW COLUMNS FROM accounting_accounts')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('is_active',$columns,true)) $this->pdo->exec('ALTER TABLE accounting_accounts ADD COLUMN is_active TINYINT NOT NULL DEFAULT 1');
        $this->pdo->exec('ALTER TABLE accounting_accounts MODIFY label VARCHAR(500) NOT NULL');
        $this->pdo->exec('ALTER TABLE accounting_lines MODIFY account_label VARCHAR(500) NOT NULL');
        $this->atomic(function (): void {
            $revision=hash_file('sha256',__DIR__.'/../comptabilite/data/plan_fourni.csv');
            if ($this->pdo->query('SELECT chart_revision FROM accounting_settings WHERE id=1')->fetchColumn() !== $revision) {
            $this->pdo->exec('UPDATE accounting_accounts SET is_active=0,postable=0');
            $accounts = require __DIR__ . '/../comptabilite/data/syscohada.php';
            $insert = $this->pdo->prepare('INSERT INTO accounting_accounts (code,label,postable,source,is_active) VALUES (?,?,?,?,1) ON DUPLICATE KEY UPDATE label=VALUES(label),postable=VALUES(postable),source=VALUES(source),is_active=1');
            foreach ($accounts as $code => $label) {
                $postable = strlen((string) $code) > 2;
                foreach ($accounts as $child => $_) {
                    if ((string) $child !== (string) $code && str_starts_with((string) $child, (string) $code)) {
                        $postable = false;
                        break;
                    }
                }
                $insert->execute([(string) $code, $label, (int) $postable, 'syscohada_revise']);
            }
            $this->pdo->exec('DELETE a FROM accounting_accounts a LEFT JOIN accounting_lines l ON l.account_code=a.code WHERE a.is_active=0 AND l.id IS NULL');
            $this->execute('UPDATE accounting_settings SET chart_revision=? WHERE id=1',[$revision]);
            $this->audit('chart_replaced',0,null,['count'=>count($accounts),'sha256'=>$revision]);
            }
            $insert = $this->pdo->prepare('INSERT IGNORE INTO accounting_journals (code,label,kind) VALUES (?,?,?)');
            foreach ([['ACH','Achats','achats'],['VEN','Ventes','ventes'],['BQ','Banque','banque'],['CAI','Caisse','caisse'],['OD','Opérations diverses','divers'],['AN','À-nouveaux','ouverture'],['HB','Engagements hors bilan / analytique','hors_bilan']] as $journal) {
                $insert->execute($journal);
            }
            $this->pdo->exec('UPDATE accounting_settings SET schema_version=5 WHERE id=1');
        });
    }

    /** Automatic first use and annual rollover. Existing/custom and closed exercises are preserved. */
    public function prepareForUse(?string $today = null): ?int
    {
        $today=self::date($today ?? date('Y-m-d'));
        return $this->atomic(function () use ($today): ?int {
            $existing=$this->rows('SELECT id FROM accounting_exercises WHERE starts_on<=? AND ends_on>=? LIMIT 1',[$today,$today]);
            if ($existing) return (int)$existing[0]['id'];
            $year=substr($today,0,4); $start=$year.'-01-01'; $end=$year.'-12-31';
            // Never overwrite an established non-calendar fiscal schedule.
            if ($this->rows('SELECT id FROM accounting_exercises WHERE starts_on<=? AND ends_on>=? LIMIT 1',[$end,$start])) return null;
            $this->execute('INSERT INTO accounting_exercises (label,starts_on,ends_on) VALUES (?,?,?)',['Exercice '.$year,$start,$end]);
            $id=(int)$this->pdo->lastInsertId();
            $this->audit('exercise_created_automatically',0,null,['id'=>$id,'start'=>$start,'end'=>$end,'trigger'=>'application_startup']);
            return $id;
        });
    }

    private function atomic(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->query('SELECT id FROM accounting_settings WHERE id=1 FOR UPDATE')->fetchColumn();
            $result = $callback();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function rows(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function execute(string $sql, array $params = []): void
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    private function audit(string $action, int $user, ?int $entry, array $details = []): void
    {
        $this->execute('INSERT INTO accounting_audit (entry_id,user_id,action,details) VALUES (?,?,?,?)',
            [$entry, $user, $action, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }

    public static function amount(string $value): int
    {
        $value = str_replace(',', '.', trim($value));
        if ($value === '') {
            return 0;
        }
        if (!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', $value)) {
            throw new DomainException('Montant invalide : utilisez un nombre positif avec au plus deux décimales, sans séparateur de milliers.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');
        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function decimal(int $amount): string
    {
        return ($amount < 0 ? '-' : '') . intdiv(abs($amount), 100) . '.' . str_pad((string) (abs($amount) % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value || $value < '1900-01-01' || $value > '2199-12-31') {
            throw new DomainException('Date invalide.');
        }
        return $value;
    }

    private static function label(string $value, int $max = 190): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max) {
            throw new DomainException("Le libellé ou la référence est obligatoire (maximum {$max} caractères).");
        }
        return $value;
    }

    public function accounts(): array { return $this->rows('SELECT * FROM accounting_accounts WHERE is_active=1 ORDER BY code'); }
    public function journals(): array { return $this->rows('SELECT * FROM accounting_journals ORDER BY code'); }
    public function exercises(): array { return $this->rows('SELECT * FROM accounting_exercises ORDER BY starts_on DESC'); }

    public function createExercise(string $label, string $start, string $end, int $user): int
    {
        $label = self::label($label, 100); $start = self::date($start); $end = self::date($end);
        if ($end < $start) { throw new DomainException('La fin doit suivre le début de l’exercice.'); }
        return $this->atomic(function () use ($label, $start, $end, $user): int {
            if ($this->rows('SELECT id FROM accounting_exercises WHERE starts_on<=? AND ends_on>=?', [$end,$start])) {
                throw new DomainException('Cet exercice chevauche un exercice existant.');
            }
            $this->execute('INSERT INTO accounting_exercises (label,starts_on,ends_on) VALUES (?,?,?)', [$label,$start,$end]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit('exercise_created', $user, null, ['id'=>$id,'start'=>$start,'end'=>$end]);
            return $id;
        });
    }

    private function openExercise(int $id, string $date): array
    {
        $exercise = $this->rows('SELECT * FROM accounting_exercises WHERE id=?', [$id])[0] ?? null;
        if (!$exercise || $exercise['closed_at'] || $date < $exercise['starts_on'] || $date > $exercise['ends_on'] || ($exercise['closed_through'] && $date <= $exercise['closed_through'])) {
            throw new DomainException('La date ne correspond pas à une période ouverte de cet exercice.');
        }
        return $exercise;
    }

    public function closePeriod(int $id, string $through, int $user): void
    {
        $through = self::date($through);
        $this->atomic(function () use ($id, $through, $user): void {
            $exercise = $this->openExercise($id, $through);
            if ($through >= date('Y-m-d')) { throw new DomainException('Seule une période achevée peut être clôturée.'); }
            if ($through !== $exercise['ends_on'] && (new DateTimeImmutable($through))->format('Y-m-t') !== $through) {
                throw new DomainException('Choisissez une fin de mois ou la fin de l’exercice.');
            }
            if ($this->rows("SELECT id FROM accounting_entries WHERE exercise_id=? AND status='draft' AND entry_date<=? LIMIT 1", [$id,$through])) {
                throw new DomainException('Des brouillons subsistent dans cette période : validez-les ou annulez-les avant clôture.');
            }
            $final = $through === $exercise['ends_on'];
            $this->execute('UPDATE accounting_exercises SET closed_through=?,closed_at=?,closed_by=? WHERE id=?', [$through,$final ? date('Y-m-d H:i:s') : null,$user,$id]);
            $this->audit('period_closed', $user, null, ['exercise_id'=>$id,'through'=>$through,'final'=>$final]);
        });
    }

    public function addJournal(string $code, string $label, string $kind, int $user): void
    {
        $code = strtoupper(trim($code)); $label = self::label($label,100);
        if (!preg_match('/^[A-Z][A-Z0-9]{0,7}$/D', $code) || !in_array($kind,['achats','ventes','banque','caisse','divers','ouverture','hors_bilan'],true)) {
            throw new DomainException('Code ou type de journal invalide.');
        }
        $this->atomic(function () use ($code,$label,$kind,$user): void {
            if ($this->rows('SELECT code FROM accounting_journals WHERE code=?',[$code])) { throw new DomainException('Ce journal existe déjà.'); }
            $this->execute('INSERT INTO accounting_journals (code,label,kind) VALUES (?,?,?)',[$code,$label,$kind]);
            $this->audit('journal_created',$user,null,['code'=>$code,'label'=>$label]);
        });
    }

    /** Additions only: existing and historical account definitions cannot be overwritten. */
    public function importAccounts(array $accounts, int $user): int
    {
        if (!$accounts || count($accounts)>3000) { throw new DomainException('Fournissez entre 1 et 3 000 comptes.'); }
        return $this->atomic(function () use ($accounts,$user): int {
            $existing = array_column($this->accounts(), null, 'code'); $count = 0;
            foreach ($accounts as $account) {
                $code = trim((string)($account['code']??'')); $label = self::label((string)($account['label']??''));
                if (!preg_match('/^[1-9][0-9]{2,11}$/D',$code)) { throw new DomainException('Les comptes de saisie doivent comporter 3 à 12 chiffres, dans les classes 1 à 9.'); }
                if (isset($existing[$code])) {
                    if ($existing[$code]['label'] !== $label) { throw new DomainException("Le compte {$code} existe avec un autre libellé."); }
                    continue;
                }
                if ($this->rows('SELECT code FROM accounting_accounts WHERE code=? AND is_active=0',[$code])) throw new DomainException("Le compte {$code} est archivé pour conserver son historique. Choisissez un autre code.");
                if (!isset($existing[substr($code,0,2)]) && !($code[0]==='9' && isset($existing[9]))) { throw new DomainException("Le groupe SYSCOHADA de {$code} est inconnu."); }
                $this->execute('INSERT INTO accounting_accounts (code,label,postable,source) VALUES (?,?,1,?)',[$code,$label,'personnalise']);
                $existing[$code] = ['code'=>$code,'label'=>$label]; $count++;
            }
            // A parent with movements cannot become a grouping account.
            foreach ($existing as $code => $account) {
                foreach ($existing as $child => $_) {
                    if ((string)$child !== (string)$code && str_starts_with((string)$child,(string)$code)) {
                        if ($this->rows('SELECT id FROM accounting_lines WHERE account_code=? LIMIT 1',[(string)$code])) {
                            throw new DomainException("Le compte {$code} est déjà utilisé : créez un compte frère plutôt qu’un sous-compte.");
                        }
                        $this->execute('UPDATE accounting_accounts SET postable=0 WHERE code=?',[(string)$code]);
                        break;
                    }
                }
            }
            $this->audit('accounts_imported',$user,null,['count'=>$count]);
            return $count;
        });
    }

    private function normalizeLines(array $lines): array
    {
        if (count($lines)<2 || count($lines)>100) { throw new DomainException('Une écriture doit contenir entre 2 et 100 lignes.'); }
        $accounts = array_column($this->accounts(),null,'code'); $result=[];
        foreach ($lines as $line) {
            $code = (string)($line['account_code']??'');
            if (empty($accounts[$code]['postable'])) { throw new DomainException("Compte {$code} inconnu ou collectif : sélectionnez un compte de saisie."); }
            $debit=self::amount((string)($line['debit']??'')); $credit=self::amount((string)($line['credit']??''));
            if (($debit>0) === ($credit>0)) { throw new DomainException('Chaque ligne doit avoir un débit OU un crédit strictement positif.'); }
            $third=trim((string)($line['third_party']??''));
            if (mb_strlen($third)>190) { throw new DomainException('Tiers trop long.'); }
            $result[]=['account_code'=>$code,'account_label'=>$accounts[$code]['label'],'label'=>self::label((string)($line['label']??'')), 'third_party'=>$third,'debit'=>$debit,'credit'=>$credit];
        }
        return $result;
    }

    private function checkBook(string $journal, array $lines): void
    {
        $kind=$this->rows('SELECT kind FROM accounting_journals WHERE code=?',[$journal])[0]['kind']??'';
        foreach ($lines as $line) {
            if (str_starts_with((string)$line['account_code'],'9') !== ($kind==='hors_bilan')) {
                throw new DomainException('Les comptes de classe 9 se saisissent dans un journal hors bilan / analytique ; les classes 1 à 8 dans les journaux généraux.');
            }
        }
    }

    public function saveDraft(array $data, int $user, ?array $document = null, ?array $sourceData = null): int
    {
        return $this->atomic(function () use ($data,$user,$document,$sourceData): int {
            $id=(int)($data['id']??0); $old=null; $source=null;
            require_once __DIR__.'/AccountingDisbursementSource.php';
            $bridge=new AccountingDisbursementSource($this->pdo,$this->sourceRoot);
            if ($id) {
                $old=$this->entry($id);
                if ($old['status']!=='draft') { throw new DomainException('Seul un brouillon peut être modifié.'); }
                if ((int)($data['version']??0)!==(int)$old['version']) { throw new DomainException('Cette écriture a changé. Rechargez-la avant de modifier.'); }
                $this->openExercise((int)$old['exercise_id'],$old['entry_date']);
            } else {
                $key=(string)($data['request_key']??'');
                if (!preg_match('/^[a-f0-9]{64}$/D',$key)) { throw new DomainException('Jeton de création invalide. Rechargez la page.'); }
                $duplicate=$this->rows('SELECT id FROM accounting_entries WHERE request_key=?',[$key]);
                if ($duplicate) { return (int)$duplicate[0]['id']; }
            }
            $exercise=(int)($data['exercise_id']??0); $date=self::date((string)($data['entry_date']??''));
            $this->openExercise($exercise,$date);
            $journal=(string)($data['journal_code']??'');
            if (!$this->rows('SELECT code FROM accounting_journals WHERE code=?',[$journal])) { throw new DomainException('Journal inconnu.'); }
            $label=self::label((string)($data['label']??'')); $reference=self::label((string)($data['reference']??''),100);
            if ($sourceData!==null) {
                if ($id) throw new DomainException('Une source ne peut être réaffectée à une écriture existante.');
                foreach (['point_checked','payment_checked','expense_checked'] as $check) {
                    if (($sourceData['checks'][$check]??'')!=='1') throw new DomainException('Confirmez le contrôle du point signé, du paiement et de la pièce de dépense.');
                }
                $source=$bridge->item((int)$sourceData['id']); $bridge->assertEligible($source);
                if ($this->rows('SELECT entry_id FROM accounting_entry_sources WHERE active_point_item_id=? OR active_fiche_number=?',[$source['point_item_id'],$source['fiche_number']])) throw new DomainException('Ce décaissement possède déjà une écriture. Ouvrez-la depuis le point.');
                $data['lines']=[
                    ['account_code'=>(string)($data['debit_account']??''),'label'=>$label,'third_party'=>$source['beneficiary'],'debit'=>self::decimal($source['amount']),'credit'=>'0'],
                    ['account_code'=>(string)($data['credit_account']??''),'label'=>$label,'third_party'=>$source['beneficiary'],'debit'=>'0','credit'=>self::decimal($source['amount'])],
                ];
            }
            $lines=$this->normalizeLines($data['lines']??[]);
            $this->checkBook($journal,$lines);
            $existingSource=$old['source']??null;
            if ($existingSource && !$source) {
                $source=$bridge->item((int)$existingSource['point_item_id']); $bridge->assertEligible($source);
                if (!hash_equals($existingSource['source_fingerprint'],$source['fingerprint'])) throw new DomainException('Le dossier source a changé : annulez ce brouillon puis contrôlez à nouveau les pièces depuis le point.');
            }
            if ($source) $this->checkDisbursementLines($source,$lines,$date,$journal);
            if ($id) {
                $this->execute('UPDATE accounting_entries SET exercise_id=?,journal_code=?,entry_date=?,label=?,reference=?,version=version+1 WHERE id=?',[$exercise,$journal,$date,$label,$reference,$id]);
                $this->execute('DELETE FROM accounting_lines WHERE entry_id=?',[$id]);
            } else {
                $this->execute('INSERT INTO accounting_entries (exercise_id,journal_code,entry_date,label,reference,request_key,created_by) VALUES (?,?,?,?,?,?,?)',[$exercise,$journal,$date,$label,$reference,$key,$user]);
                $id=(int)$this->pdo->lastInsertId();
            }
            $this->insertLines($id,$lines);
            if ($document !== null) { $this->attach($id,$document,$user); }
            if ($sourceData!==null && $source) {
                $categories=[];
                foreach ($source['files'] as $index=>$file) {
                    $this->attach($id,$bridge->file((int)$source['point_item_id'],(int)$index),$user);
                    $categories[]=$file['category'];
                }
                foreach ($sourceData['uploads']??[] as $category=>$upload) {
                    if (!in_array($category,['payment','expense'],true)) throw new DomainException('Catégorie de pièce inconnue.');
                    $this->attach($id,$upload,$user); $categories[]=$category;
                }
                foreach (['dg_signature','payment','expense'] as $category) {
                    if (!in_array($category,$categories,true)) throw new DomainException('Le dossier doit contenir la signature DG consultable, une preuve de paiement et une pièce de dépense. Ajoutez les pièces manquantes.');
                }
                if (!hash_equals($source['fingerprint'],$bridge->item((int)$source['point_item_id'])['fingerprint'])) throw new DomainException('Le dossier a changé pendant son contrôle. Rechargez le point.');
                $this->execute('INSERT INTO accounting_entry_sources (entry_id,point_item_id,active_point_item_id,active_fiche_number,source_snapshot,source_fingerprint,reviewed_by) VALUES (?,?,?,?,?,?,?)',
                    [$id,$source['point_item_id'],$source['point_item_id'],$source['fiche_number'],json_encode($source,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$source['fingerprint'],$user]);
            }
            $this->audit($old ? 'draft_updated' : 'draft_created',$user,$id,['before'=>$old,'after'=>$this->entry($id)]);
            return $id;
        });
    }

    private function checkDisbursementLines(array $source, array $lines, string $date, string $journal): void
    {
        if ($date<substr((string)$source['fiche_paid_at'],0,10)) throw new DomainException('La date comptable ne peut pas précéder le paiement effectif.');
        $kind=$this->rows('SELECT kind FROM accounting_journals WHERE code=?',[$journal])[0]['kind']??'';
        if (!in_array($kind,['banque','caisse','divers'],true)) throw new DomainException('Utilisez un journal de banque, caisse ou opérations diverses pour ce paiement.');
        $debit=array_sum(array_column($lines,'debit')); $credit=array_sum(array_column($lines,'credit'));
        $treasuryCredit=0;
        foreach ($lines as $line) {
            if (preg_match('/^(52|53|55|57)/',(string)$line['account_code'])) $treasuryCredit+=(int)$line['credit']-(int)$line['debit'];
        }
        if ($debit!==$source['amount'] || $credit!==$source['amount'] || $treasuryCredit!==$source['amount']) {
            throw new DomainException('Le débit, le crédit et la sortie des comptes de trésorerie doivent correspondre exactement au montant effectivement décaissé.');
        }
    }

    private function insertLines(int $id, array $lines): void
    {
        foreach ($lines as $position=>$line) {
            $this->execute('INSERT INTO accounting_lines (entry_id,position,account_code,account_label,label,third_party,debit,credit) VALUES (?,?,?,?,?,?,?,?)',
                [$id,$position+1,$line['account_code'],$line['account_label'],$line['label'],$line['third_party'],$line['debit'],$line['credit']]);
        }
    }

    public static function validateDocument(string $name, string $content): array
    {
        if ($content==='' || strlen($content)>8*1024*1024) { throw new DomainException('Le justificatif doit peser entre 1 octet et 8 Mo.'); }
        $mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($content);
        if (!in_array($mime,['application/pdf','image/jpeg','image/png'],true)) { throw new DomainException('Justificatifs acceptés : PDF, JPEG ou PNG.'); }
        return ['original_name'=>mb_substr(basename(str_replace('\\','/',$name)),0,190),'mime'=>$mime,'size'=>strlen($content),'sha256'=>hash('sha256',$content),'content'=>$content];
    }

    private function attach(int $id, array $document, int $user): void
    {
        $document=self::validateDocument((string)$document['original_name'],(string)$document['content']);
        $this->execute('INSERT INTO accounting_documents (entry_id,original_name,mime,size,sha256,content,created_by) VALUES (?,?,?,?,?,?,?)',
            [$id,$document['original_name'],$document['mime'],$document['size'],$document['sha256'],$document['content'],$user]);
    }

    public function entry(int $id): array
    {
        $entry=$this->rows('SELECT * FROM accounting_entries WHERE id=?',[$id])[0]??null;
        if (!$entry) { throw new DomainException('Écriture introuvable.'); }
        $entry['lines']=$this->rows('SELECT * FROM accounting_lines WHERE entry_id=? ORDER BY position',[$id]);
        $entry['documents']=$this->rows('SELECT id,original_name,mime,size,sha256 FROM accounting_documents WHERE entry_id=? ORDER BY id',[$id]);
        $entry['source']=$this->rows('SELECT * FROM accounting_entry_sources WHERE entry_id=?',[$id])[0]??null;
        $entry['reversed_by']=$this->rows('SELECT id,entry_number FROM accounting_entries WHERE reversal_of=?',[$id])[0]??null;
        return $entry;
    }

    public function document(int $id): array
    {
        $document=$this->rows('SELECT * FROM accounting_documents WHERE id=?',[$id])[0]??null;
        if (!$document) { throw new DomainException('Justificatif introuvable.'); }
        if (!hash_equals($document['sha256'],hash('sha256',$document['content']))) { throw new RuntimeException('Intégrité du justificatif invalide.'); }
        return $document;
    }

    public function history(int $id): array
    {
        return $this->rows('SELECT action,user_id,created_at FROM accounting_audit WHERE entry_id=? ORDER BY id',[$id]);
    }

    public function post(int $id, int $version, int $user): void
    {
        $this->atomic(function () use ($id,$version,$user): void {
            $entry=$this->entry($id);
            if ($entry['status']!=='draft' || (int)$entry['version']!==$version) { throw new DomainException('Le brouillon a changé ou a déjà été validé. Rechargez la page.'); }
            $this->postInside($entry,$user);
        });
    }

    private function postInside(array $entry, int $user): void
    {
        $exercise=$this->openExercise((int)$entry['exercise_id'],$entry['entry_date']);
        if (empty($entry['reversal_of'])) {
            foreach ($entry['lines'] as $line) {
                $account=$this->rows('SELECT is_active,postable FROM accounting_accounts WHERE code=?',[$line['account_code']])[0] ?? null;
                if (!$account || !$account['is_active'] || !$account['postable']) throw new DomainException('Un compte de ce brouillon a été retiré du plan ou est un regroupement. Corrigez son imputation avant validation.');
            }
        }

        if (!empty($entry['source'])) {
            require_once __DIR__.'/AccountingDisbursementSource.php';
            $bridge=new AccountingDisbursementSource($this->pdo,$this->sourceRoot);
            $source=$bridge->item((int)$entry['source']['point_item_id']); $bridge->assertEligible($source);
            if (!hash_equals($entry['source']['source_fingerprint'],$source['fingerprint'])) throw new DomainException('Le dossier de décaissement a changé depuis son contrôle. Annulez ce brouillon et recommencez le contrôle.');
            $this->checkDisbursementLines($source,$entry['lines'],$entry['entry_date'],$entry['journal_code']);
        }
        if ($entry['entry_date']>date('Y-m-d')) { throw new DomainException('Une écriture future ne peut pas être validée.'); }
        if (!$entry['documents']) { throw new DomainException('Ajoutez au moins un justificatif avant de valider l’écriture.'); }
        foreach ($entry['documents'] as $document) { $this->document((int)$document['id']); }
        $debit=array_sum(array_column($entry['lines'],'debit')); $credit=array_sum(array_column($entry['lines'],'credit'));
        if (count($entry['lines'])<2 || $debit<=0 || $debit!==$credit) { throw new DomainException('Écriture déséquilibrée : le total débit doit être égal au total crédit.'); }
        $last=$this->rows("SELECT MAX(entry_date) AS last_date FROM accounting_entries WHERE exercise_id=? AND journal_code=? AND status='posted'",[$entry['exercise_id'],$entry['journal_code']])[0]['last_date'];
        if ($last && $entry['entry_date']<$last) { throw new DomainException("Ce journal contient déjà une écriture validée au {$last}. Choisissez une date au moins égale."); }
        $this->execute('INSERT IGNORE INTO accounting_sequences (exercise_id,journal_code,last_number) VALUES (?,?,0)',[$entry['exercise_id'],$entry['journal_code']]);
        $this->execute('UPDATE accounting_sequences SET last_number=last_number+1 WHERE exercise_id=? AND journal_code=?',[$entry['exercise_id'],$entry['journal_code']]);
        $number=(int)$this->rows('SELECT last_number FROM accounting_sequences WHERE exercise_id=? AND journal_code=?',[$entry['exercise_id'],$entry['journal_code']])[0]['last_number'];
        $formatted=substr($exercise['starts_on'],0,4).'-'.$exercise['id'].'-'.$entry['journal_code'].'-'.str_pad((string)$number,6,'0',STR_PAD_LEFT);
        $this->execute("UPDATE accounting_entries SET status='posted',entry_number=?,sequence_number=?,posted_by=?,posted_at=NOW(),version=version+1 WHERE id=?",[$formatted,$number,$user,$entry['id']]);
        $this->audit('entry_posted',$user,(int)$entry['id'],['number'=>$formatted,'total'=>$debit]);
    }

    public function cancelDraft(int $id, int $version, int $user): void
    {
        $this->atomic(function () use ($id,$version,$user): void {
            $entry=$this->entry($id);
            if ($entry['status']!=='draft' || (int)$entry['version']!==$version) { throw new DomainException('Seul un brouillon inchangé peut être annulé.'); }
            $this->openExercise((int)$entry['exercise_id'],$entry['entry_date']);
            $this->execute("UPDATE accounting_entries SET status='cancelled',version=version+1 WHERE id=?",[$id]);
            $this->execute('UPDATE accounting_entry_sources SET active_point_item_id=NULL,active_fiche_number=NULL WHERE entry_id=?',[$id]);
            $this->audit('draft_cancelled',$user,$id);
        });
    }

    public function reverse(int $id, int $exerciseId, string $date, string $reason, int $user): int
    {
        $date=self::date($date); $reason=self::label($reason);
        return $this->atomic(function () use ($id,$exerciseId,$date,$reason,$user): int {
            $entry=$this->entry($id);
            if ($entry['status']!=='posted' || $entry['reversal_of'] || $entry['reversed_by']) { throw new DomainException('Cette écriture ne peut pas être contrepassée à nouveau.'); }
            if ($date<$entry['entry_date']) { throw new DomainException('La contrepassation ne peut pas précéder l’écriture initiale.'); }
            $this->openExercise($exerciseId,$date);
            $this->execute('INSERT INTO accounting_entries (exercise_id,journal_code,entry_date,label,reference,request_key,created_by,reversal_of,reversal_reason) VALUES (?,?,?,?,?,?,?,?,?)',
                [$exerciseId,$entry['journal_code'],$date,mb_substr('Contrepassation : '.$entry['label'],0,190),$entry['entry_number'],bin2hex(random_bytes(32)),$user,$id,$reason]);
            $newId=(int)$this->pdo->lastInsertId();
            $lines=$entry['lines'];
            foreach ($lines as &$line) { [$line['debit'],$line['credit']]=[$line['credit'],$line['debit']]; } unset($line);
            $this->insertLines($newId,$lines);
            $this->execute('INSERT INTO accounting_documents (entry_id,original_name,mime,size,sha256,content,created_by) SELECT ?,original_name,mime,size,sha256,content,? FROM accounting_documents WHERE entry_id=?',[$newId,$user,$id]);
            $this->postInside($this->entry($newId),$user);
            $this->execute('UPDATE accounting_entry_sources SET active_point_item_id=NULL,active_fiche_number=NULL WHERE entry_id=?',[$id]);
            $this->audit('entry_reversed',$user,$id,['reversal_id'=>$newId,'reason'=>$reason]);
            return $newId;
        });
    }

    public function entries(int $exerciseId, string $status = '', int $page = 1): array
    {
        $params=[$exerciseId]; $where='exercise_id=?';
        if (in_array($status,['draft','posted','cancelled'],true)) { $where.=' AND status=?'; $params[]=$status; }
        $offset=(max(1,$page)-1)*50;
        return $this->rows("SELECT e.*, (SELECT SUM(debit) FROM accounting_lines WHERE entry_id=e.id) AS total FROM accounting_entries e WHERE {$where} ORDER BY entry_date DESC,id DESC LIMIT 51 OFFSET {$offset}",$params);
    }

    /** Full selected-period reports: no hidden truncation. Opening balances include earlier posted movements in this exercise. */
    public function report(int $exerciseId, string $from, string $to, string $journal = '', string $account = ''): array
    {
        $from=self::date($from); $to=self::date($to);
        $exercise=$this->rows('SELECT * FROM accounting_exercises WHERE id=?',[$exerciseId])[0]??null;
        if (!$exercise || $from>$to || $from<$exercise['starts_on'] || $to>$exercise['ends_on']) { throw new DomainException('La période doit être comprise dans l’exercice sélectionné.'); }
        $where="e.exercise_id=? AND e.status='posted' AND e.entry_date<=?";
        $kind=$journal!=='' ? ($this->rows('SELECT kind FROM accounting_journals WHERE code=?',[$journal])[0]['kind']??'') : '';
        $where.=$kind==='hors_bilan' ? " AND l.account_code LIKE '9%'" : " AND l.account_code NOT LIKE '9%'"; $params=[$exerciseId,$to];
        if ($journal!=='') { $where.=' AND e.journal_code=?'; $params[]=$journal; }
        if ($account!=='') { $where.=' AND l.account_code=?'; $params[]=$account; }
        $all=$this->rows("SELECT e.id AS entry_id,e.entry_number,e.entry_date,e.journal_code,e.reference,e.label AS entry_label,l.* FROM accounting_entries e JOIN accounting_lines l ON l.entry_id=e.id WHERE {$where} ORDER BY e.entry_date,e.journal_code,e.sequence_number,l.position",$params);
        $rows=[]; $balances=[];
        foreach ($all as $line) {
            $code=$line['account_code'];
            if (!isset($balances[$code])) { $balances[$code]=['code'=>$code,'label'=>$line['account_label'],'opening'=>0,'debit'=>0,'credit'=>0,'closing'=>0]; }
            $delta=(int)$line['debit']-(int)$line['credit'];
            if ($line['entry_date']<$from) { $balances[$code]['opening']+=$delta; }
            else { $balances[$code]['debit']+=(int)$line['debit']; $balances[$code]['credit']+=(int)$line['credit']; $rows[]=$line; }
            $balances[$code]['closing']+=$delta;
        }
        ksort($balances,SORT_STRING);
        return ['rows'=>$rows,'balances'=>$balances,'exercise'=>$exercise];
    }
}
