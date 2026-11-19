<?php
declare(strict_types=1);
require_once __DIR__.'/../bootstrap.php';

function pc_custom_attachment_directories():array
{
    return [
        dirname(__DIR__,2).'/uploads/planning_chantier/office_meetings',
        dirname(__DIR__,2).'/uploads/disbursement_proofs/planning_chantier_office_meetings',
        dirname(__DIR__).'/uploads/office_meetings',
        dirname(__DIR__,2).'/decaissement/uploads/planning_chantier/office_meetings',
    ];
}

function pc_custom_attachment_directory():string
{
    foreach(pc_custom_attachment_directories() as $directory){
        if(!is_dir($directory)){
            $previous=umask(0002);$created=@mkdir($directory,0775,true);umask($previous);
            if(!$created&&!is_dir($directory))continue;
        }
        @chmod($directory,0775);
        if(is_dir($directory)&&is_writable($directory))return $directory;
    }
    throw new RuntimeException('Le stockage des pièces jointes est inaccessible.');
}

try{
    if(class_exists('AccessManager')&&!AccessManager::hasPageAccess('planning_chantier/reunion.php'))throw new RuntimeException('Accès refusé.');
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $id=(int)($_GET['id']??0);
        $stmt=$pdo->prepare("SELECT a.* FROM planning_chantier_office_attachments a
            JOIN planning_chantier_meeting_custom_sections s ON s.id=a.meeting_section_id
            JOIN planning_chantier_meeting_preparations p ON p.id=s.preparation_id
            WHERE a.id=:id AND a.archived_at IS NULL AND s.archived_at IS NULL AND p.status IN ('ready','used') LIMIT 1");
        $stmt->execute(['id'=>$id]);$file=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$file)throw new DomainException('Pièce jointe introuvable.');
        $path='';
        foreach(pc_custom_attachment_directories() as $directory){
            $candidate=$directory.'/'.basename((string)$file['stored_name']);
            if(is_file($candidate)){$path=$candidate;break;}
        }
        if(!is_file($path))throw new DomainException('Le fichier physique est introuvable.');
        header('Content-Type: '.((string)$file['mime_type']?:'application/octet-stream'));
        header('Content-Length: '.filesize($path));
        header("Content-Disposition: inline; filename*=UTF-8''".rawurlencode((string)$file['original_name']));
        readfile($path);exit;
    }

    if(!hash_equals((string)($_SESSION['pc_financial_csrf']??''),(string)($_POST['csrf']??'')))throw new RuntimeException('Session expirée.');
    $preparationId=(int)($_POST['preparation_id']??0);
    $sectionId=(int)($_POST['meeting_section_id']??0);
    $check=$pdo->prepare("SELECT id FROM planning_chantier_meeting_custom_sections
        WHERE id=:section AND preparation_id=:preparation AND archived_at IS NULL LIMIT 1");
    $check->execute(['section'=>$sectionId,'preparation'=>$preparationId]);
    if(!$check->fetchColumn())throw new DomainException('Point de réunion introuvable.');

    if(!empty($_POST['archive_id'])){
        $pdo->prepare("UPDATE planning_chantier_office_attachments SET archived_at=NOW(),archived_by=:user
            WHERE id=:id AND preparation_id=:preparation AND meeting_section_id=:section AND archived_at IS NULL")
            ->execute(['user'=>planning_chantier_user_id(),'id'=>(int)$_POST['archive_id'],'preparation'=>$preparationId,'section'=>$sectionId]);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>true]);exit;
    }

    $files=$_FILES['files']??null;
    if(!$files||!is_array($files['name']??null))throw new DomainException('Sélectionnez au moins un fichier.');
    if(count($files['name'])>10)throw new DomainException('Maximum 10 fichiers par envoi.');
    $allowed=['pdf','doc','docx','xls','xlsx','csv','txt','png','jpg','jpeg','webp','ppt','pptx'];
    $directory=pc_custom_attachment_directory();
    $insert=$pdo->prepare("INSERT INTO planning_chantier_office_attachments
        (preparation_id,ligne_debourse_id,meeting_section_id,original_name,stored_name,mime_type,file_size,uploaded_by,created_at)
        VALUES(:preparation,NULL,:section,:original,:stored,:mime,:size,:user,NOW())");
    $saved=0;
    foreach($files['name'] as $index=>$original){
        if((int)($files['error'][$index]??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)continue;
        if((int)$files['error'][$index]!==UPLOAD_ERR_OK)throw new RuntimeException('Échec du téléversement de '.basename((string)$original).'.');
        $size=(int)($files['size'][$index]??0);
        if($size<=0||$size>15*1024*1024)throw new DomainException('Chaque fichier doit peser au maximum 15 Mo.');
        $extension=strtolower(pathinfo((string)$original,PATHINFO_EXTENSION));
        if(!in_array($extension,$allowed,true))throw new DomainException('Type de fichier non autorisé : '.$extension);
        $stored=bin2hex(random_bytes(16)).'.'.$extension;
        $target=$directory.'/'.$stored;
        if(!move_uploaded_file((string)$files['tmp_name'][$index],$target))throw new RuntimeException('Impossible d’enregistrer '.basename((string)$original).'.');
        @chmod($target,0640);
        $mime=function_exists('mime_content_type')?(string)(mime_content_type($target)?:'application/octet-stream'):'application/octet-stream';
        $insert->execute(['preparation'=>$preparationId,'section'=>$sectionId,'original'=>mb_substr(basename((string)$original),0,255),'stored'=>$stored,'mime'=>$mime,'size'=>$size,'user'=>planning_chantier_user_id()]);
        $saved++;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true,'saved'=>$saved],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    if(!headers_sent())header('Content-Type: application/json; charset=utf-8');
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}
