<?php
namespace Ticketing\Services;

final class TicketAttachmentStorage
{
    public function validate(array $files): array
    {
        if (!$files) return [];
        $names=is_array($files['name'] ?? null)?$files['name']:[($files['name'] ?? '')];
        if(count($names)>10) throw new \DomainException('Joignez au maximum 10 fichiers à la fois.');
        $accepted=[]; $total=0;
        foreach($names as $i=>$name) {
            $field=static fn($key,$default)=>is_array($files[$key] ?? null)?($files[$key][$i] ?? $default):($files[$key] ?? $default);
            $error=(int)$field('error',UPLOAD_ERR_NO_FILE);
            if($error===UPLOAD_ERR_NO_FILE) continue;
            if($error!==UPLOAD_ERR_OK) throw new \DomainException('Le téléversement de « '.basename((string)$name).' » a échoué. Vérifiez la taille du fichier.');
            $tmp=(string)$field('tmp_name','');
            if(!is_uploaded_file($tmp)) throw new \DomainException('Fichier téléversé invalide.');
            $size=(int)filesize($tmp); $total+=$size;
            if($size<=0 || $size>12*1024*1024 || $total>30*1024*1024) throw new \DomainException('Maximum : 12 Mo par fichier et 30 Mo par envoi.');
            $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
            $ext=strtolower(pathinfo((string)$name,PATHINFO_EXTENSION));
            $allowed=[
                'pdf'=>['application/pdf'], 'jpg'=>['image/jpeg'], 'jpeg'=>['image/jpeg'], 'png'=>['image/png'], 'webp'=>['image/webp'],
                'txt'=>['text/plain'], 'csv'=>['text/plain','text/csv','application/csv'],
                'doc'=>['application/msword','application/CDFV2'], 'xls'=>['application/vnd.ms-excel','application/CDFV2'],
                'docx'=>['application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/zip'],
                'xlsx'=>['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/zip'],
                'zip'=>['application/zip'],
            ];
            if(!in_array($mime,$allowed[$ext] ?? [],true)) throw new \DomainException('Format de fichier refusé : '.basename((string)$name).'.');
            $accepted[]=['tmp_name'=>$tmp,'original_name'=>mb_substr(basename((string)$name),0,255),'mime_type'=>$mime,'size_bytes'=>$size,'extension'=>$ext];
        }
        return $accepted;
    }

    public function store(array $files): array
    {
        $saved=[];
        try {
            foreach($files as $file) {
                $relative='private/attachments/'.bin2hex(random_bytes(24)).'.'.$file['extension'];
                if(!move_uploaded_file($file['tmp_name'],dirname(__DIR__).'/'.$relative)) throw new \RuntimeException('Stockage des pièces jointes indisponible.');
                $saved[]=$file+['stored_name'=>$relative];
            }
            return $saved;
        } catch(\Throwable $e) { $this->cleanup($saved); throw $e; }
    }

    public function cleanup(array $files): void
    {
        foreach($files as $file) {
            $path=$this->resolve((string)$file['stored_name']);
            if($path) @unlink($path);
        }
    }

    public function resolve(string $relative): ?string
    {
        $root=realpath(dirname(__DIR__)); $path=realpath(dirname(__DIR__).'/'.$relative);
        if(!$path || !is_file($path) || !$root) return null;
        foreach(['/private/attachments/','/uploads/tickets/'] as $allowed) if(str_starts_with($path,$root.$allowed)) return $path;
        return null;
    }
}
