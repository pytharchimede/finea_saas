<?php

class NotificationSmsChannelService
{
    public function send(string $to, string $message): bool
    {
        $to = trim($to);
        $message = trim($message);
        if ($to === '' || $message === '') {
            return false;
        }

        $senderPath = __DIR__ . '/../decaissement/model/SmsSender.php';
        if (!class_exists('SmsSender') && is_file($senderPath)) {
            require_once $senderPath;
        }
        if (!class_exists('SmsSender')) {
            return false;
        }

        try {
            $sender = new \SmsSender();
            if (method_exists($sender, 'send')) {
                return (bool) $sender->send($to, $message);
            }
        } catch (Throwable $e) {
        }

        return false;
    }
}
