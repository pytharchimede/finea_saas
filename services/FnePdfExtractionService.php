<?php
declare(strict_types=1);

final class FnePdfExtractionService
{
    public function extract(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new DomainException('Le fichier PDF n’a pas été transmis correctement.');
        $size=(int)($file['size']??0);if($size<=0||$size>15*1024*1024)throw new DomainException('Le PDF doit peser au maximum 15 Mo.');
        $tmp=(string)($file['tmp_name']??'');$mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);if($mime!=='application/pdf'||file_get_contents($tmp,false,null,0,5)!=='%PDF-')throw new DomainException('Le document transmis n’est pas un PDF valide.');
        $work=sys_get_temp_dir().'/fnea_'.bin2hex(random_bytes(8));if(!mkdir($work,0700,true))throw new RuntimeException('Impossible de préparer l’analyse du PDF.');
        try{
            $pdf=$work.'/invoice.pdf';if(!move_uploaded_file($tmp,$pdf)&&!copy($tmp,$pdf))throw new RuntimeException('Impossible de lire le PDF reçu.');
            $textFile=$work.'/invoice.txt';$this->run(['/usr/bin/pdftotext','-layout','-enc','UTF-8',$pdf,$textFile]);$text=is_file($textFile)?(string)file_get_contents($textFile):'';
            $candidates=$this->candidates($text);$prefix=$work.'/page';$this->run(['/usr/bin/pdftoppm','-f','1','-l','3','-r','120','-jpeg','-jpegopt','quality=78',$pdf,$prefix],false);
            $images=[];foreach(glob($prefix.'-*.jpg')?:[] as $image){if(count($images)>=3)break;$raw=file_get_contents($image);if($raw!==false)$images[]='data:image/jpeg;base64,'.base64_encode($raw);}
            return ['file_name'=>(string)($file['name']??'facture.pdf'),'page_count_preview'=>count($images),'text_detected'=>trim($text)!=='','text_preview'=>mb_substr(preg_replace('/\s+/u',' ',trim($text))?:'',0,700),'candidates'=>$candidates,'page_images'=>$images];
        }finally{$this->removeTree($work);}
    }

    public function candidates(string $text): array
    {
        $out=[];$add=static function(string $value,string $type,string $source)use(&$out):void{$value=trim($value," \t\n\r\0\x0B.,;()[]{}<>");if($value==='')return;$key=$type.'|'.strtolower($value);$out[$key]=['value'=>$value,'type'=>$type,'source'=>$source];};
        if(preg_match_all('/\b[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i',$text,$m))foreach($m[0] as $v)$add($v,'verificationCode','Texte PDF');
        if(preg_match_all('/(?:code\s*(?:de\s*)?v[ée]rification|verification\s*code|uuid)\s*[:#-]?\s*([A-Za-z0-9._:\/-]{8,180})/iu',$text,$m))foreach($m[1] as $v)$add($v,'verificationCode','Texte PDF');
        if(preg_match_all('/(?:num[ée]ro\s*(?:de\s*)?facture|invoice\s*number|facture\s*n[°o])\s*[:#-]\s*([A-Za-z0-9._\/-]{4,120})/iu',$text,$m))foreach($m[1] as $v)$add($v,'invoiceNumber','Texte PDF');
        return array_values($out);
    }

    private function run(array $command,bool $required=true): void
    {
        $pipes=[];$process=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process)){if($required)throw new RuntimeException('Outil d’analyse PDF indisponible.');return;}$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);if($required&&$code!==0)throw new RuntimeException('Lecture PDF impossible : '.trim((string)($stderr?:$stdout)));
    }
    private function removeTree(string $dir): void { foreach(glob($dir.'/*')?:[] as $file)@unlink($file);@rmdir($dir); }
}
