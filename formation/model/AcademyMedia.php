<?php
declare(strict_types=1);
function academyVideoDirectory(): string {
    $dir = getenv('FINEA_ACADEMY_VIDEO_DIR') ?: dirname(dirname(__DIR__,2)).'/finea-academy-private-videos';
    $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    if (!is_dir($dir) && !mkdir($dir,0700,true)) throw new RuntimeException('Stockage vidéo indisponible.');
    $real=realpath($dir);
    if (!$real || !$root || $real===$root || str_starts_with($real,$root.DIRECTORY_SEPARATOR)) throw new RuntimeException('Configurer un stockage vidéo hors racine web.');
    return $real;
}
function academyStoreVideo(array $file): string {
    if ($file['error']!==UPLOAD_ERR_OK || $file['size']<1 || $file['size']>500*1024*1024 || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Transfert vidéo invalide ou limite dépassée.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $ext=['video/mp4'=>'mp4','video/webm'=>'webm'][$mime]??null;
    if (!$ext) throw new RuntimeException('Vidéo MP4 ou WebM requise.');
    $name=bin2hex(random_bytes(24)).'.'.$ext;
    if (!move_uploaded_file($file['tmp_name'],academyVideoDirectory().'/'.$name)) throw new RuntimeException('Enregistrement impossible.');
    return $name;
}
