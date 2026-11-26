<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

final class RhJobDescriptionExcel
{
    private const HEADERS = ['fonction_id','intitule','mission_principale','responsabilites','activites','competences','qualifications','indicateurs_performance','rattachement_hierarchique','conditions_exercice','version','date_effet'];

    public static function download(PDO $pdo, int $selected): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Fiches');
        $sheet->fromArray(self::HEADERS, null, 'A1');
        $functions = $pdo->query('SELECT id_fonction,lib_fonction FROM fonction ORDER BY lib_fonction')->fetchAll(PDO::FETCH_ASSOC);
        if ($selected > 0) {
            foreach ($functions as $f) {
                if ((int)$f['id_fonction'] === $selected) {
                    $sheet->setCellValue('A2', $selected);
                    $sheet->setCellValueExplicit('B2', (string)$f['lib_fonction'], DataType::TYPE_STRING);
                    break;
                }
            }
        }
        $sheet->getStyle('A1:L1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:L1')->getFill()->setFillType('solid')->getStartColor()->setRGB('081C65');
        $sheet->freezePane('C2');
        $sheet->getStyle('K2:L501')->getNumberFormat()->setFormatCode('@');
        foreach (range('A','L') as $col) $sheet->getColumnDimension($col)->setWidth($col === 'A' ? 15 : 30);
        $ref = $book->createSheet();
        $ref->setTitle('Fonctions');
        $ref->fromArray(['fonction_id','Fonction'], null, 'A1');
        foreach ($functions as $i => $f) {
            $ref->setCellValue('A'.($i+2), (int)$f['id_fonction']);
            $ref->setCellValueExplicit('B'.($i+2), (string)$f['lib_fonction'], DataType::TYPE_STRING);
        }
        $ref->getColumnDimension('B')->setWidth(55);
        $guide = $book->createSheet();
        $guide->setTitle('Instructions');
        $guide->fromArray([['Une ligne par fiche. Utiliser les identifiants de l’onglet Fonctions.'],['fonction_id et intitule obligatoires. Version par défaut : 1.0.'],['Date facultative au format AAAA-MM-JJ (texte).'],['Les listes peuvent contenir plusieurs lignes dans une cellule.'],['Les fiches sont importées en brouillon. Aucun remplacement automatique.'],['Maximum 500 lignes. Ne pas modifier les en-têtes.']], null, 'A1');
        $guide->getColumnDimension('A')->setWidth(100);
        $book->setActiveSheetIndex(0);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="modele_fiches_poste.xlsx"');
        header('Cache-Control: no-store');
        (new XlsxWriter($book))->save('php://output');
        exit;
    }

    public static function import(PDO $pdo, array $file, int $uid): int
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '') || ($file['size'] ?? 0) > 2*1024*1024 || strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException('Sélectionnez un fichier XLSX valide de 2 Mo maximum.');
        }
        $zip = new ZipArchive();
        if ($zip->open($file['tmp_name']) !== true) throw new RuntimeException('Fichier XLSX invalide.');
        $total = 0;
        try {
            if ($zip->numFiles > 200) throw new RuntimeException('Classeur trop complexe.');
            for ($i=0;$i<$zip->numFiles;$i++) {
                $entry = $zip->statIndex($i);
                $total += (int)($entry['size'] ?? 0);
                if ($total > 20*1024*1024) throw new RuntimeException('Classeur décompressé trop volumineux.');
            }
        } finally { $zip->close(); }
        $reader = new Xlsx();
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(['Fiches']);
        $book = $reader->load($file['tmp_name']);
        $sheet = $book->getSheetByName('Fiches');
        if (!$sheet || $sheet->getHighestDataRow() > 501 || Coordinate::columnIndexFromString($sheet->getHighestDataColumn()) > 12) throw new RuntimeException('Onglet Fiches absent ou limites dépassées.');
        $read = static function(int $row, int $col) use ($sheet): string {
            $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($col).$row);
            if ($cell->getDataType() === DataType::TYPE_FORMULA) throw new RuntimeException("Ligne $row : formules interdites.");
            return trim((string)$cell->getValue());
        };
        foreach (self::HEADERS as $i=>$name) if ($read(1,$i+1)!==$name) throw new RuntimeException('En-têtes non conformes au modèle.');
        $valid = array_fill_keys($pdo->query('SELECT id_fonction FROM fonction')->fetchAll(PDO::FETCH_COLUMN),true);
        $seen=[];$rows=[];
        $check=$pdo->prepare('SELECT COUNT(*) FROM rh_fiches_poste WHERE fonction_id=? AND version=? AND statut<>\'archivee\'');
        for ($row=2;$row<=$sheet->getHighestDataRow();$row++) {
            $v=[];foreach(self::HEADERS as $i=>$name) $v[]=$read($row,$i+1);
            if (!array_filter($v,static fn($x)=>$x!=='')) continue;
            if (!ctype_digit($v[0]) || !isset($valid[(int)$v[0]]) || $v[1]==='') throw new RuntimeException("Ligne $row : fonction inconnue ou intitulé manquant.");
            $v[10]=$v[10]?:'1.0';
            if ($v[11]!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v[11]) || !checkdate((int)substr($v[11],5,2),(int)substr($v[11],8,2),(int)substr($v[11],0,4)))) throw new RuntimeException("Ligne $row : date invalide (AAAA-MM-JJ).");
            $key=(int)$v[0].':'.strtolower($v[10]);
            if(isset($seen[$key])) throw new RuntimeException("Ligne $row : doublon dans le fichier.");
            $seen[$key]=true;
            $rows[]=[$row,$v];
        }
        if (!$rows) throw new RuntimeException('Aucune fiche à importer.');
        $pdo->beginTransaction();
        try {
            $lock=$pdo->prepare('SELECT id_fonction FROM fonction WHERE id_fonction=? FOR UPDATE');
            $ids=array_unique(array_map(static fn($r)=>(int)$r[1][0],$rows));sort($ids);
            foreach($ids as $id){$lock->execute([$id]);if(!$lock->fetchColumn())throw new RuntimeException('Fonction supprimée.');$lock->closeCursor();}
            $insert=$pdo->prepare("INSERT INTO rh_fiches_poste (".implode(',',self::HEADERS).",statut,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'brouillon',?,?)");
            foreach($rows as [$row,$v]){
                $check->execute([(int)$v[0],$v[10]]);
                if((int)$check->fetchColumn()>0)throw new RuntimeException("Ligne $row : une fiche de cette fonction et version existe déjà.");
                $v=array_map(static fn($x)=>$x===''?null:$x,$v);
                $insert->execute([...$v,$uid,$uid]);
            }
            $pdo->commit();
        } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return count($rows);
    }
}
