<?php

declare(strict_types=1);

require_once __DIR__ . '/AuditTrail.php';

class FebAiSettings
{
    private const STORAGE_RELATIVE_PATH = 'storage/feb/ai_settings.json';

    public function isConversationEnabled(): bool
    {
        return !empty($this->load()['conversation_enabled']);
    }

    public function load(): array
    {
        $defaults = ['conversation_enabled' => false, 'reject_unedited_generated_text' => false, 'detection_mode' => 'off', 'detection_provider' => 'sapling', 'detection_threshold' => 100, 'block_on_detection' => false, 'updated_at' => null];
        $path = $this->storagePath();
        if (!is_file($path)) {
            return $defaults;
        }
        $decoded = json_decode((string)@file_get_contents($path), true);
        if (!is_array($decoded)) {
            return $defaults;
        }
        return array_replace($defaults, [
            'conversation_enabled' => !empty($decoded['conversation_enabled']),
            'reject_unedited_generated_text' => !empty($decoded['reject_unedited_generated_text']),
            'detection_mode' => in_array((string)($decoded['detection_mode'] ?? ''), ['off','local','eden'], true) ? (string)$decoded['detection_mode'] : 'off',
            'detection_provider' => in_array((string)($decoded['detection_provider'] ?? ''), ['sapling','winstonai'], true) ? (string)$decoded['detection_provider'] : 'sapling',
            'detection_threshold' => max(50, min(100, (int)($decoded['detection_threshold'] ?? 100))),
            'block_on_detection' => !empty($decoded['block_on_detection']),
            'updated_at' => isset($decoded['updated_at']) ? (string)$decoded['updated_at'] : null,
        ]);
    }

    public function save(bool $enabled, array $actor = [], bool $rejectUneditedGeneratedText = false, array $detection = []): bool
    {
        $path = $this->storagePath();
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true)) {
            return false;
        }
        $mode=in_array((string)($detection['mode']??'off'),['off','local','eden'],true)?(string)$detection['mode']:'off';
        $provider=in_array((string)($detection['provider']??'sapling'),['sapling','winstonai'],true)?(string)$detection['provider']:'sapling';
        $payload = ['conversation_enabled' => $enabled, 'reject_unedited_generated_text' => $rejectUneditedGeneratedText, 'detection_mode'=>$mode, 'detection_provider'=>$provider, 'detection_threshold'=>max(50,min(100,(int)($detection['threshold']??100))), 'block_on_detection'=>!empty($detection['block']), 'updated_at' => date('c')];
        $ok = @file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
        if ($ok) {
            \AuditTrail::log('feb_ai_settings_updated', [
                'module' => 'admin',
                'action' => 'feb_ai_settings',
                'entity_type' => 'settings',
                'entity_label' => 'feb_ai',
                'user_id' => isset($actor['user_id']) ? (int)$actor['user_id'] : null,
                'user_name' => isset($actor['user_name']) ? (string)$actor['user_name'] : null,
                'result' => 'success',
                'details' => ['conversation_enabled' => $enabled, 'reject_unedited_generated_text' => $rejectUneditedGeneratedText, 'passive_learning_enabled' => true],
            ]);
        }
        return $ok;
    }

    public function saveEdenApiKey(string $key): bool
    {
        $key=trim($key);if($key==='')return false;$path=dirname($this->storagePath()).'/eden_api_key';
        if(!is_dir(dirname($path))&&!@mkdir(dirname($path),0755,true))return false;
        $ok=@file_put_contents($path,$key)!==false;if($ok)@chmod($path,0600);return $ok;
    }

    public function edenApiKey(): string
    {
        $env=getenv('EDENAI_API_KEY');if(is_string($env)&&trim($env)!=='')return trim($env);
        $path=dirname($this->storagePath()).'/eden_api_key';return is_file($path)?trim((string)@file_get_contents($path)):'';
    }

    public function hasEdenApiKey(): bool { return $this->edenApiKey()!==''; }

    private function storagePath(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::STORAGE_RELATIVE_PATH);
    }
}
