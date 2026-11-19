<?php
declare(strict_types=1);

final class ContractWhatsAppCatalog
{
    public const STAGES = ['rh'=>'Ressources humaines','juridique'=>'Juridique','technique'=>'Technique','logistique'=>'Logistique','qhse'=>'QHSE','comptabilite'=>'Comptabilité','direction'=>'Direction générale'];

    public static function inputs(): array
    {
        $models=[];
        foreach(self::STAGES as $key=>$label) {
            $models[$key]=self::input($key, 'Bonjour {{1}}, le contrat de sous-traitance {{2}} du chantier {{3}} attend votre intervention au titre de '.$label.'. Votre étape {{4}} peut être complétée et ajustée dans FINEA avant votre signature. Consultez les informations enregistrées puis confirmez votre signature pour poursuivre le circuit. Merci.', $label);
        }
        $models['revision']=self::input('revision','Bonjour {{1}}, les informations du contrat de sous-traitance {{2}} du chantier {{3}} ont été modifiées. Votre visa pour l’étape {{4}} doit être renouvelé. Consultez la version enregistrée dans FINEA, ajustez votre partie si nécessaire, puis confirmez de nouveau votre signature. Merci.','Technique');
        $models['complete']=self::input('complete','Bonjour {{1}}, le contrat de sous-traitance {{2}} du chantier {{3}} a reçu les signatures requises et est désormais actif. Le circuit {{4}} est terminé. Le document signé et son historique sont disponibles dans FINEA pour consultation. Merci.','Contrat finalisé');
        // Le prestataire ne possède pas nécessairement de compte FINEA : pas de lien interne ni de promesse de signature distante.
        $models['prestataire']=['name'=>'finea_contrat_prestataire','language'=>'fr','category'=>'UTILITY','body'=>'Bonjour {{1}}, le contrat de sous-traitance {{2}} du chantier {{3}} a été signé par la Direction générale de BANAMUR. L’étape {{4}} nécessite maintenant votre signature. Merci de prendre contact avec les Ressources humaines de BANAMUR pour consulter le document et organiser sa signature.','samples'=>['1'=>'Entreprise Exemple','2'=>'CST-2026-0001','3'=>'CH0062','4'=>'Signature du sous-traitant']];
        return $models;
    }

    private static function input(string $key,string $body,string $label):array
    {
        $path=$key==='direction'?'signer.php?id=1':($key==='complete'?'voir.php?id=1':'workflow.php?id=1&etape='.($key==='revision'?'technique':$key));
        return ['name'=>'finea_contrat_'.$key,'language'=>'fr','category'=>'UTILITY','body'=>$body,'button_title'=>'Consulter le contrat','button_url'=>'https://projet.fidest.ci/contrats/{{5}}','samples'=>['1'=>'Jean Exemple','2'=>'CST-2026-0001','3'=>'CH0062','4'=>$label,'5'=>$path]];
    }

    public static function phone(string $phone):string
    {
        $phone=preg_replace('/^whatsapp:/i','',trim($phone));
        $phone=preg_replace('/[\s().-]+/','',$phone);
        if(str_starts_with($phone,'00'))$phone='+'.substr($phone,2);
        if(preg_match('/^\d{10}$/D',$phone))$phone='+225'.$phone;
        if(!preg_match('/^\+[1-9]\d{7,14}$/D',$phone))throw new DomainException('Numéro WhatsApp absent ou invalide.');
        return $phone;
    }

    public static function stageHash(array $stage):string
    {
        return hash('sha256',json_encode([(string)($stage['statut']??''),(string)($stage['donnees_json']??''),(int)($stage['signataire_personnel_id']??0),(string)($stage['updated_at']??'')],JSON_THROW_ON_ERROR));
    }
}
