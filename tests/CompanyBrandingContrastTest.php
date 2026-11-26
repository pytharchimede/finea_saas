<?php
declare(strict_types=1);
require_once __DIR__.'/../model/CompanyBranding.php';
foreach (['#d71920'=>'#ffffff','#081c65'=>'#ffffff','#ffcc20'=>'#000000','#ffffff'=>'#000000','#000000'=>'#ffffff','#777777'=>'#000000'] as $bg=>$expected) {
    if (CompanyBranding::contrastColor($bg)!==$expected) throw new RuntimeException("Unexpected contrast for $bg");
}
for($r=0;$r<=255;$r+=17)for($g=0;$g<=255;$g+=17)for($b=0;$b<=255;$b+=17){
    $hex=sprintf('#%02x%02x%02x',$r,$g,$b);
    $linear=static function($c){$v=$c/255;return $v<=.04045?$v/12.92:(($v+.055)/1.055)**2.4;};
    $l=.2126*$linear($r)+.7152*$linear($g)+.0722*$linear($b);
    $ratio=CompanyBranding::contrastColor($hex)==='#ffffff'?1.05/($l+.05):($l+.05)/.05;
    if($ratio<4.5)throw new RuntimeException("Insufficient contrast: $hex");
}
echo "CompanyBrandingContrastTest OK (4096 colors)\n";
