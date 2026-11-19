<?php
declare(strict_types=1);

final class RhFinancialSignature
{
    /** Capture bounded raster bytes, never a mutable profile path or an uploaded SVG. */
    public static function profile(PDO $pdo, int $userId): array
    {
        $q=$pdo->prepare('SELECT signature,signature_data FROM user_devis_banamur WHERE id=?');
        $q->execute([$userId]);$profile=$q->fetch(PDO::FETCH_ASSOC) ?: [];
        $data=trim((string)($profile['signature_data'] ?? ''));$bytes=false;
        if (strlen($data)<=2800000 && preg_match('~^data:image/(png|jpeg);base64,(.+)$~sD',$data,$m)) $bytes=base64_decode($m[2],true);
        if ($bytes===false) {
            $path=trim((string)($profile['signature'] ?? ''));
            $base=realpath(__DIR__.'/../uploads/user_signatures');
            $file=$path!==''?realpath(__DIR__.'/../'.ltrim($path,'/')):false;
            if ($file && $base && str_starts_with($file,$base.DIRECTORY_SEPARATOR) && is_file($file) && filesize($file)<=2097152) $bytes=file_get_contents($file);
        }
        if ($bytes===false || $bytes==='') return ['method'=>'passkey'];
        $image=self::raster($bytes);
        return ['method'=>'passkey','image_data'=>base64_encode($bytes),'image_type'=>$image['type'],'image_hash'=>hash('sha256',$bytes),'profile_captured_at'=>date('Y-m-d H:i:s')];
    }

    public static function raster(string $bytes): array
    {
        $info=strlen($bytes)<=2097152?@getimagesizefromstring($bytes):false;
        if (!$info || !in_array($info[2],[IMAGETYPE_PNG,IMAGETYPE_JPEG],true) || $info[0]<1 || $info[1]<1 || $info[0]>4096 || $info[1]>4096 || $info[0]*$info[1]>8000000) throw new DomainException('Signature de profil invalide. Enregistrez-la à nouveau ou signez manuellement.');
        return ['type'=>$info[2]===IMAGETYPE_PNG?'PNG':'JPEG'];
    }

    public static function imageBytes(array $signature): string
    {
        $encoded=(string)($signature['image_data'] ?? '');
        $bytes=strlen($encoded)<=2800000?base64_decode($encoded,true):false;
        if ($bytes===false || !hash_equals((string)($signature['image_hash'] ?? ''),hash('sha256',$bytes))) throw new DomainException('Empreinte de signature de profil incohérente.');
        $image=self::raster($bytes);
        if ($image['type']!==($signature['image_type'] ?? '')) throw new DomainException('Format de signature incohérent.');
        return $bytes;
    }

    /** Build the image ourselves from bounded coordinates; never accept uploaded SVG. */
    public static function manual(string $json): array
    {
        if ($json === '' || strlen($json) > 100000) throw new DomainException('Dessinez une signature lisible avant de signer.');
        try { $strokes = json_decode($json, true, 16, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new DomainException('Signature manuscrite invalide.'); }
        if (!is_array($strokes) || !array_is_list($strokes) || !$strokes || count($strokes) > 100) throw new DomainException('Signature manuscrite invalide.');
        $paths = []; $count = 0; $distance = 0.0;
        foreach ($strokes as $stroke) {
            if (!is_array($stroke) || !array_is_list($stroke) || !$stroke) throw new DomainException('Signature manuscrite invalide.');
            $points = []; $previous = null;
            foreach ($stroke as $point) {
                if (++$count > 2500 || !is_array($point) || !array_is_list($point) || count($point) !== 2) throw new DomainException('Signature manuscrite invalide.');
                [$x, $y] = $point;
                if ((!is_int($x) && !is_float($x)) || (!is_int($y) && !is_float($y)) || !is_finite((float)$x) || !is_finite((float)$y) || $x < 0 || $x > 600 || $y < 0 || $y > 180) throw new DomainException('Signature manuscrite invalide.');
                $x = round((float)$x, 2); $y = round((float)$y, 2);
                if ($previous) $distance += hypot($x - $previous[0], $y - $previous[1]);
                $previous = [$x, $y]; $points[] = $x.','.$y;
            }
            if (count($points) > 1) $paths[] = '<polyline points="'.implode(' ', $points).'" />';
        }
        if ($distance < 50 || !$paths) throw new DomainException('La signature est absente ou trop courte.');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="180" viewBox="0 0 600 180"><g fill="none" stroke="#172033" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round">'.implode('', $paths).'</g></svg>';
        return ['method'=>'manual', 'svg'=>$svg, 'image_hash'=>hash('sha256', $svg)];
    }
}

