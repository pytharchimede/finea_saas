<?php

/** Definition unique du template et controle de son approbation avant activation. */
class DailyPointWhatsAppTemplateService
{
    public const BODY = 'Bonjour M. {{1}}, le point journalier de décaissement n° {{2}} du {{3}} est prêt pour votre validation. Il comprend {{4}} fiche(s), pour un montant total de {{5}}. Veuillez consulter le document et apposer votre signature en utilisant le bouton ci-dessous. Votre accès personnel est valable jusqu’au {{6}}. Merci.';
    public const URL = 'https://projet.fidest.ci/finance/signature-point/{{7}}';

    public function __construct(private string $accountSid, private string $authToken) {}

    public static function samples(): array
    {
        return ['1'=>'Directeur Général', '2'=>'125', '3'=>'30/09/2026', '4'=>'8',
            '5'=>'1 250 000 FCFA', '6'=>'01/10/2026 à 18:00', '7'=>str_repeat('a', 64)];
    }

    public static function definition(string $name, string $body = self::BODY): array
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,127}$/', $name)) {
            throw new RuntimeException('Nom du modèle invalide : lettres minuscules, chiffres et underscores uniquement.');
        }
        self::validateBody($body);
        return ['friendly_name'=>$name, 'language'=>'fr', 'variables'=>(object) self::samples(),
            'types'=>['twilio/call-to-action'=>['body'=>$body,
                'actions'=>[['type'=>'URL', 'title'=>'Consulter et signer', 'url'=>self::URL]]]]];
    }

    public function createAndSubmit(string $name, string $body = self::BODY, ?callable $onCreated = null): array
    {
        $content = $this->request('POST', '/Content', self::definition($name, $body));
        $sid = (string) ($content['sid'] ?? '');
        self::validateSid($sid);
        if ($onCreated !== null) {
            try {$onCreated($sid);}catch(Throwable $e){throw new RuntimeException('Modèle créé : '.$sid.'. Enregistrement local impossible ; vérifiez ce SID dans Twilio avant toute nouvelle création.',0,$e);}
        }
        try {
            $approval = $this->request('POST', '/Content/'.$sid.'/ApprovalRequests/whatsapp',
                ['name'=>$name, 'category'=>'UTILITY']);
        } catch (Throwable $e) {
            // Le SID reste recuperable si la creation a reussi mais la soumission a echoue.
            throw new RuntimeException('Modèle créé : '.$sid.'. Soumission refusée. Consultez ce modèle dans Twilio ; ne le recréez pas automatiquement. '.$e->getMessage());
        }
        return ['sid'=>$sid, 'status'=>(string) ($approval['status'] ?? 'pending')];
    }

    public function assertApproved(string $sid, string $expectedBody = self::BODY): void
    {
        self::validateSid($sid);
        $content = $this->request('GET', '/Content/'.$sid);
        $cta = $content['types']['twilio/call-to-action'] ?? [];
        // Un SID approuve mais d'un autre modele ne doit pas recevoir nos sept variables.
        if (($content['language'] ?? '') !== 'fr' || ($cta['body'] ?? '') !== $expectedBody
            || count($cta['actions'] ?? []) !== 1
            || ($cta['actions'][0]['type'] ?? '') !== 'URL'
            || ($cta['actions'][0]['title'] ?? '') !== 'Consulter et signer'
            || ($cta['actions'][0]['url'] ?? '') !== self::URL) {
            throw new RuntimeException('Ce SID ne correspond pas au modèle français FINEA « Consulter et signer ».');
        }
        $approval = $this->request('GET', '/Content/'.$sid.'/ApprovalRequests');
        if (strtolower((string) ($approval['whatsapp']['status'] ?? '')) !== 'approved') {
            throw new RuntimeException('Le modèle WhatsApp n’est pas encore approuvé par Meta. Le modèle actif est conservé.');
        }
    }

    public function fetchApproval(string $sid): array
    {
        self::validateSid($sid);
        $result = $this->request('GET', '/Content/'.$sid.'/ApprovalRequests');
        $wa = $result['whatsapp'] ?? [];
        $reason = $wa['rejection_reason'] ?? '';
        return ['status'=>strtolower(trim((string)($wa['status'] ?? 'unknown'))),
            'category'=>(string)($wa['category'] ?? ''),
            'reason'=>self::safeDiagnostic(is_array($reason) ? json_encode($reason, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR) : (string)$reason)];
    }

    public static function safeDiagnostic(string $text): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/[a-f0-9]{64}/i', '[jeton masqué]', $text);
        $text = preg_replace('/(auth_token|password|secret|token)(["\'\s:=]+)[^\s&,;}]+/i', '$1=[masqué]', $text);
         $text=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
        preg_match('/^.{0,3000}/us', $text, $match);
        return $match[0] ?? 'Diagnostic illisible.';
    }

    public static function validateBody(string $body): void
    {
        preg_match_all('/\{\{([^}]+)\}\}/', $body, $matches);
        $keys = array_values(array_unique($matches[1])); sort($keys);
        if ($keys !== ['1','2','3','4','5','6'] || strlen($body) > 1024 || trim($body) === '')
            throw new RuntimeException('Le texte doit conserver exactement les variables 1 à 6 et rester court. Le jeton 7 est réservé au bouton.');
        if (str_contains(preg_replace('/\{\{[1-6]\}\}/','',$body),'{{') || str_contains(preg_replace('/\{\{[1-6]\}\}/','',$body),'}}')) throw new RuntimeException('Variable mal formée.');
        if (preg_match('/^\s*\{\{|\}\}\s*$|\}\}\s*\{\{/', $body))
            throw new RuntimeException('Ajoutez du texte avant, après et entre les variables.');
    }

    public function signatureRouteReady(): bool
    {
        if (!function_exists('curl_init')) return false;
        $handle = curl_init(str_replace('{{7}}', str_repeat('a',64), self::URL));
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>15]);
        $body = curl_exec($handle); $status = (int)curl_getinfo($handle,CURLINFO_HTTP_CODE); curl_close($handle);
        return $status === 200 && is_string($body) && str_contains($body, 'Lien indisponible')
            && str_contains($body, 'Signature sécurisée du point');
    }

    private static function validateSid(string $sid): void
    {
        if (!preg_match('/^HX[a-fA-F0-9]{32}$/', $sid)) throw new RuntimeException('Content SID invalide.');
    }

    protected function request(string $method, string $path, ?array $payload = null): array
    {
        if (!preg_match('/^AC[a-fA-F0-9]{32}$/', $this->accountSid) || $this->authToken === '') {
            throw new RuntimeException('Configurez d’abord les identifiants Twilio.');
        }
        if (!function_exists('curl_init')) throw new RuntimeException('L’extension PHP cURL est requise.');
        $handle = curl_init('https://content.twilio.com/v1'.$path);
        $options = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_USERPWD=>$this->accountSid.':'.$this->authToken,
            CURLOPT_HTTPAUTH=>CURLAUTH_BASIC, CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_TIMEOUT=>30, CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'Accept: application/json']];
        if ($payload !== null) $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        curl_setopt_array($handle, $options);
        $raw = curl_exec($handle);
        $http = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        if ($raw === false) throw new RuntimeException('Twilio est injoignable. Vérifiez le statut dans la console avant de réessayer.');
        $result = json_decode($raw, true);
        if (!is_array($result) || $http < 200 || $http >= 300) {
            // Ne jamais exposer la reponse brute susceptible de contenir des secrets.
            throw new RuntimeException('Erreur Twilio HTTP '.$http.' (code '.(int) ($result['code'] ?? 0).') : '.self::safeDiagnostic(str_replace($this->authToken,'[secret masqué]',(string)($result['message'] ?? 'Réponse invalide'))), (int)($result['code'] ?? $http));
        }
        return $result;
    }
}
