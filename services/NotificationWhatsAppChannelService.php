<?php

class NotificationWhatsAppChannelService
{
    private string $sid;
    private string $token;
    private string $from;

    public function __construct(?string $sid = null, ?string $token = null, ?string $from = null)
    {
        $this->sid = trim((string) ($sid ?? ''));
        $this->token = trim((string) ($token ?? ''));
        $this->from = trim((string) ($from ?? ''));
    }

    public function send(string $to, string $message): bool
    {
        $to = trim($to);
        $message = trim($message);
        if ($to === '' || $message === '') {
            return false;
        }

        $waPath = __DIR__ . '/../decaissement/model/WhatsAppSMS.php';
        if (!class_exists('WhatsAppSMS') && is_file($waPath)) {
            require_once $waPath;
        }
        if (!class_exists('WhatsAppSMS')) {
            return false;
        }

        $sid = $this->sid !== '' ? $this->sid : (getenv('TWILIO_ACCOUNT_SID') ?: getenv('TWILIO_SID') ?: (defined('TWILIO_ACCOUNT_SID') ? TWILIO_ACCOUNT_SID : (defined('TWILIO_SID') ? TWILIO_SID : '')));
        $token = $this->token !== '' ? $this->token : (getenv('TWILIO_AUTH_TOKEN') ?: getenv('TWILIO_TOKEN') ?: (defined('TWILIO_AUTH_TOKEN') ? TWILIO_AUTH_TOKEN : (defined('TWILIO_TOKEN') ? TWILIO_TOKEN : '')));
        $from = $this->from !== '' ? $this->from : (getenv('TWILIO_WHATSAPP_FROM') ?: (defined('TWILIO_WHATSAPP_FROM') ? TWILIO_WHATSAPP_FROM : ''));
        if ($sid === '' || $token === '' || $from === '') {
            return false;
        }

        try {
            $whatsapp = new \WhatsAppSMS($sid, $token, $from);
            $result = method_exists($whatsapp, 'sendWhatsAppMessage')
                ? $whatsapp->sendWhatsAppMessage($to, $message)
                : $whatsapp->sendMessage($to, $message);
            return !empty($result);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function sendTemplate(string $to, string $contentSid, array $variables = []): bool
    {
        $to = trim($to);
        $contentSid = trim($contentSid);
        if ($to === '' || !preg_match('/^HX[a-fA-F0-9]{32}$/', $contentSid)) {
            return false;
        }

        $client = $this->createLegacyClient();
        if ($client === null) {
            return false;
        }

        try {
            $result = method_exists($client, 'sendContentTemplateMessage')
                ? $client->sendContentTemplateMessage($to, $contentSid, $variables)
                : $client->sendWhatsAppOnlyTextTemplateMessage($to, $contentSid);
            return !empty($result);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function sendTemplateDetailed(string $to, string $contentSid, array $variables = []): array
    {
        $to=trim($to);$contentSid=trim($contentSid);
        $detail=['ok'=>false,'to'=>$to,'from'=>$this->from,'content_sid'=>$contentSid,'message_sid'=>'','status'=>'failed','error_code'=>'','error'=>''];
        if($to===''||!preg_match('/^HX[a-fA-F0-9]{32}$/',$contentSid)){$detail['error']='Destinataire ou Content SID invalide.';return$detail;}
        try{$client=$this->createLegacyClient();if($client===null){$detail['error']='Client Twilio indisponible ou configuration incomplète.';return$detail;}
            $message=$client->sendContentTemplateMessage($to,$contentSid,$variables);
            if($message){$detail['ok']=true;$detail['message_sid']=(string)($message->sid??'');$detail['status']=(string)($message->status??'queued');return$detail;}
            $error=method_exists($client,'getLastError')?(array)$client->getLastError():[];$detail['error']=(string)($error['message']??'Twilio n’a retourné aucun message.');$detail['error_code']=(string)($error['code']??'');return$detail;
        }catch(Throwable $e){$detail['error']=$e->getMessage();$detail['error_code']=(string)$e->getCode();return$detail;}
    }

    public function __call(string $method, array $arguments)
    {
        $client = $this->createLegacyClient();
        if ($client === null || !method_exists($client, $method)) {
            return null;
        }

        try {
            return $client->{$method}(...$arguments);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function createLegacyClient(): ?\WhatsAppSMS
    {
        $waPath = __DIR__ . '/../decaissement/model/WhatsAppSMS.php';
        if (!class_exists('WhatsAppSMS') && is_file($waPath)) {
            require_once $waPath;
        }
        if (!class_exists('WhatsAppSMS')) {
            return null;
        }

        $sid = $this->sid !== '' ? $this->sid : (getenv('TWILIO_ACCOUNT_SID') ?: getenv('TWILIO_SID') ?: (defined('TWILIO_ACCOUNT_SID') ? TWILIO_ACCOUNT_SID : (defined('TWILIO_SID') ? TWILIO_SID : '')));
        $token = $this->token !== '' ? $this->token : (getenv('TWILIO_AUTH_TOKEN') ?: getenv('TWILIO_TOKEN') ?: (defined('TWILIO_AUTH_TOKEN') ? TWILIO_AUTH_TOKEN : (defined('TWILIO_TOKEN') ? TWILIO_TOKEN : '')));
        $from = $this->from !== '' ? $this->from : (getenv('TWILIO_WHATSAPP_FROM') ?: (defined('TWILIO_WHATSAPP_FROM') ? TWILIO_WHATSAPP_FROM : ''));
        if ($sid === '' || $token === '' || $from === '') {
            return null;
        }

        return new \WhatsAppSMS($sid, $token, $from);
    }
}
