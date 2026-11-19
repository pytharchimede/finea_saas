<?php
declare(strict_types=1);
require_once __DIR__ . '/FneNccRegistry.php';
require_once __DIR__ . '/FrenchCompanyRegistry.php';
require_once __DIR__ . '/InternationalBusinessIdentity.php';
require_once __DIR__ . '/SupplierExceptionPolicy.php';

final class SupplierIdentity
{
    public static function verify(PDO $pdo, array $input, string $type, array $current = [], ?FneNccRegistry $registry = null, ?FrenchCompanyRegistry $frenchRegistry = null): array
    {
        if (SupplierExceptionPolicy::isException($current)) SupplierExceptionPolicy::requireAllowed();
        $countryHint=strtoupper(trim((string)($input['fiscal_country']??$current['fiscal_country']??'CI')));
        $methodHint=(string)($input['identity_method']??$current['identity_method']??($countryHint==='FR'?'fr':'fne'));
        if (array_key_exists('identity_search',$input) && $methodHint!=='exception') {
            $target=$countryHint==='CI'?'ncc':($methodHint==='fr'?'siret':($methodHint==='vies'?'vat_number':'registration_number'));
            $input[$target]=(string)$input['identity_search'];
        }
        $originalInput = $input;
        $input['ncc_identity'] = null;
        $input['dgi_verified_at'] = null;
        $input['dgi_response_json'] = null;
        $frenchIdentifier = $input['siret'] ?? $current['siret'] ?? '';
        $vatNumber = strtoupper(preg_replace('/\s+/', '', (string)($input['vat_number'] ?? $current['vat_number'] ?? '')));
        $country = strtoupper(trim((string)($input['fiscal_country'] ?? $current['fiscal_country'] ?? 'CI')));
        $country = InternationalBusinessIdentity::country($country);
        foreach (InternationalBusinessIdentity::COLUMNS as $field => $definition) $input[$field] = null;
        $input['identity_status'] = 'unverified'; $input['identity_method'] = $country === 'FR' ? 'fr' : 'fne'; $input['vat_status'] = 'unchecked';
        $input['fiscal_country'] = $country;
        foreach (['siret', 'siren', 'registry_verified_at', 'registry_response_json', 'vat_number'] as $field) $input[$field] = null;
        if ($methodHint==='exception') return SupplierExceptionPolicy::apply(array_replace($originalInput,['fiscal_country'=>$country]),$type,$current);
        if ($type !== 'morale') return $input;
        if ($country !== 'CI' && ($country !== 'FR' || (($originalInput['identity_method'] ?? 'fr') !== 'fr'))) {
            $result = (new InternationalBusinessIdentity())->resolve($originalInput + ['fiscal_country'=>$country], $current);
            $query = $pdo->prepare('SELECT id_fournisseur FROM fournisseur_banamur WHERE identity_key = ? AND id_fournisseur <> ? LIMIT 1');
            $query->execute([$result['identity_key'], (int)($current['id_fournisseur'] ?? 0)]);
            if ($id = $query->fetchColumn()) throw new DomainException('Cet identifiant appartient déjà au fournisseur #' . $id . '.');
            foreach ($result as $key=>$value) if (!in_array($key,['name','address','city'],true)) $input[$key]=$value;
            $input['nom_commercial']=$result['name']; $input['adresse']=$result['address']; $input['ville']=$result['city']; $input['pays']=$country;
            $input['ncc']=$input['rne']=''; $input['is_rne']=0;
            return $input;
        }
        if ($country === 'FR') {
            if ($vatNumber !== '' && !preg_match('/^FR[A-Z0-9]{2}[0-9]{9}$/D', $vatNumber)) throw new DomainException('Format de TVA française invalide.');
            $identity = ($frenchRegistry ?? new FrenchCompanyRegistry())->lookup((string)($frenchIdentifier ?? $current['siret'] ?? ''));
            if ($vatNumber !== '' && substr($vatNumber, 4) !== $identity['siren']) throw new DomainException('La TVA ne correspond pas au SIREN du fournisseur.');
            $query = $pdo->prepare('SELECT id_fournisseur FROM fournisseur_banamur WHERE siret = ? AND id_fournisseur <> ? LIMIT 1');
            $query->execute([$identity['siret'], (int)($current['id_fournisseur'] ?? 0)]);
            if ($id = $query->fetchColumn()) throw new DomainException('Ce SIRET appartient déjà au fournisseur #' . $id . '.');
            $input['ncc'] = $input['rne'] = '';
            $input['is_rne'] = 0;
            $input['identity_status'] = 'registry_verified';
            $input['registration_type'] = 'SIRET'; $input['registration_number'] = $identity['siret'];
            $input['identity_key'] = 'FR:SIRET:'.$identity['siret'];
            $input['identity_checked_at'] = gmdate('Y-m-d H:i:s');
            $input['siret'] = $identity['siret'];
            $input['siren'] = $identity['siren'];
            $input['nom_commercial'] = $identity['name'];
            $input['adresse'] = $identity['address'];
            $input['ville'] = $identity['city'];
            $input['pays'] = 'FR';
            if ($current !== [] && ($current['adresse'] ?? '') !== $identity['address']
                && !(($input['location_source'] ?? '') === 'manual'
                    && is_numeric($input['location_latitude'] ?? null) && is_numeric($input['location_longitude'] ?? null)
                    && abs((float)$input['location_latitude']) <= 90 && abs((float)$input['location_longitude']) <= 180)) {
                $input['location_latitude'] = $input['location_longitude'] = null;
                $input['location_source'] = $input['location_label'] = null;
            }
            $input['vat_number'] = $vatNumber;
            $input['registry_verified_at'] = gmdate('Y-m-d H:i:s');
            $input['registry_response_json'] = json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            return $input;
        }
        $ncc = trim((string)($input['ncc'] ?? $current['ncc'] ?? ''));
        if ($ncc === '') throw new DomainException('Le NCC est obligatoire pour une personne morale, y compris au RNE.');
        $identity = ($registry ?? new FneNccRegistry())->lookup($ncc);
        $query = $pdo->prepare("SELECT id_fournisseur FROM fournisseur_banamur WHERE UPPER(REPLACE(REPLACE(TRIM(ncc), ' ', ''), '-', '')) = ? AND id_fournisseur <> ? LIMIT 1");
        $query->execute([$identity['ncc'], (int)($current['id_fournisseur'] ?? 0)]);
        if ($id = $query->fetchColumn()) throw new DomainException('Ce NCC appartient déjà au fournisseur #' . $id . '. Normalisez sa fiche existante.');
        // raison_sociale is historically the legal-form selector in this application.
        $input['identity_status'] = 'registry_verified';
        $input['identity_checked_at'] = gmdate('Y-m-d H:i:s');
        $input['nom_commercial'] = $identity['name'];
        $input['ncc'] = $input['ncc_identity'] = $identity['ncc'];
        $input['dgi_verified_at'] = gmdate('Y-m-d H:i:s');
        $input['dgi_response_json'] = json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return $input;
    }

    public static function missing(array $supplier): array
    {
        if (($supplier['supplier_type']??'morale')==='physique') {
            $missing=[];
            if (trim((string)($supplier['identity_document_number']??''))==='') $missing[]='numéro de pièce d’identité';
            if (empty($supplier['cni_path'])) $missing[]='pièce d’identité jointe';
            if (!empty($supplier['personnel_identity_conflict'])) $missing[]='pièce rattachée au personnel : fournisseur interdit';
            if ($missing) return $missing;
        }

        if (SupplierExceptionPolicy::isException($supplier)) {
            return ($supplier['identity_status']??'')==='exception_approved' && !empty($supplier['nom_commercial']) && !empty($supplier['identity_evidence']) && (int)($supplier['identity_checked_by']??0)>0 ? [] : ['Dérogation à régulariser'];
        }
        $missing = [];
        if (($supplier['supplier_type'] ?? 'morale') === 'morale') {
            if (!in_array(($supplier['fiscal_country'] ?? 'CI'), ['CI','FR'], true) || (($supplier['fiscal_country'] ?? 'CI') === 'FR' && ($supplier['identity_method'] ?? 'fr') !== 'fr' && ($supplier['identity_method'] ?? 'fr') !== 'fne')) {
                if (!in_array($supplier['identity_status'] ?? '', ['registry_verified','documentary_verified'],true)) $missing[] = 'Identité internationale à valider';
            } elseif (($supplier['fiscal_country'] ?? 'CI') === 'FR') {
                if (empty($supplier['siret']) || empty($supplier['registry_verified_at'])) $missing[] = 'Identité SIRET à vérifier';
            } elseif (empty($supplier['ncc']) || empty($supplier['dgi_verified_at'])) $missing[] = 'Identité NCC à vérifier via FNE';
            foreach (['raison_sociale'=>'Forme juridique', 'nom_commercial'=>'Dénomination', 'contact_nom'=>'Représentant légal'] as $key=>$label) {
                if (trim((string)($supplier[$key] ?? '')) === '') $missing[] = $label;
            }
        } else {
            foreach (['person_last_name'=>'Nom', 'person_first_name'=>'Prénom'] as $key=>$label) {
                if (trim((string)($supplier[$key] ?? '')) === '') $missing[] = $label;
            }
        }
        foreach (['email'=>'Email', 'telephone'=>'Téléphone', 'adresse'=>'Adresse'] as $key=>$label) {
            if (trim((string)($supplier[$key] ?? '')) === '') $missing[] = $label;
        }
        return $missing;
    }
    /** One interpretation for cards, counts and lookup labels; no registry calls while listing. */
    public static function summary(array $row): array
    {
        $method=(string)($row['identity_method']??'fne');
        $country=(string)($row['fiscal_country']??'CI');
        $state=(string)($row['identity_status']??'unverified');
        if ($method==='exception') return ['label'=>'Dérogation','value'=>$row['code_fournisseur']??'', 'status'=>(($row['validation_status']??'approved')==='approved'?'Autorisation interne — contrôles non effectués':'Dérogation non active — validation requise'),'verified'=>false,'exception'=>true];
        if (($row['supplier_type']??'morale')==='physique') return ['label'=>'Numéro de pièce','value'=>$row['identity_document_number']??'', 'status'=>!empty($row['personnel_identity_conflict'])?'Personnel : fournisseur interdit':(empty($row['identity_document_number'])||empty($row['cni_path'])?'Pièce et numéro à compléter':'Pièce / numéro renseignés'), 'verified'=>false,'exception'=>false];
        if ($country==='CI') { $label='NCC'; $value=$row['ncc']??''; $ok=$value!==''&&!empty($row['dgi_verified_at']); $status=$ok?'FNE vérifié':'FNE à vérifier'; }
        elseif ($country==='FR' && in_array($method,['fr','fne'],true)) { $label='SIRET'; $value=$row['siret']??''; $ok=$value!==''&&!empty($row['registry_verified_at']); $status=$ok?'Registre français vérifié':'SIRET à vérifier'; }
        else {
            $label=$method==='vies'?'TVA':($method==='sec'?'CIK':($row['registration_type']??'Identifiant local'));
            $value=$method==='vies'?($row['vat_number']??''):($row['registration_number']??'');
            $ok=in_array($state,['registry_verified','documentary_verified'],true);
            $status=$state==='registry_verified'?'Registre vérifié':($state==='documentary_verified'?'Identité validée sur justificatifs':'Identité à valider');
            if ($method==='vies') $status=(($row['vat_status']??'')==='valid'?'TVA confirmée':'TVA non vérifiée').' · '.$status;
        }
        return ['label'=>$label,'value'=>$value,'status'=>$status,'verified'=>$ok,'exception'=>false];
    }

}


