<?php
namespace Ticketing\Services;

use Ticketing\Repositories\TicketRepository;

final class TicketReceptionService
{
    public function __construct(private TicketRepository $repo) {}

    public static function parseDeadline(string $input): string
    {
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$input);
        if (!$date || $date->format('Y-m-d\TH:i') !== $input || $date <= new \DateTimeImmutable()) throw new \DomainException('Indiquez une date et une heure de rendu valides, dans le futur.');
        return $date->format('Y-m-d H:i:s');
    }

    public function upload(int $id, array $file, array $actor): void
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) throw new \DomainException('Sélectionnez le document signé à joindre.');
        $size=filesize($file['tmp_name']);
        if ($size<=0 || $size>12*1024*1024) throw new \DomainException('Le document doit peser entre 1 octet et 12 Mo.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $extensions=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'];
        if (!isset($extensions[$mime])) throw new \DomainException('Formats acceptés : PDF, JPG et PNG.');
        $dir=dirname(__DIR__).'/private/receptions';
        if (!is_dir($dir) && !mkdir($dir,0770,true) && !is_dir($dir)) throw new \RuntimeException('Stockage des preuves indisponible.');
        $name=bin2hex(random_bytes(24)).'.'.$extensions[$mime];
        if (!move_uploaded_file($file['tmp_name'],$dir.'/'.$name)) throw new \RuntimeException('Impossible de conserver le document.');
        try {
            $this->repo->addReceptionProof($id,['stored_name'=>$name,'original_name'=>mb_substr(basename((string)$file['name']),0,255),'mime_type'=>$mime,'size_bytes'=>$size],$actor);
        } catch (\Throwable $e) { unlink($dir.'/'.$name); throw $e; }
    }
}
