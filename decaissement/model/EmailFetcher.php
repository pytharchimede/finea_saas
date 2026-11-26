<?php

class EmailFetcher
{
    private $hostname;
    private $username;
    private $password;
    private $inbox;

    private $serverSpec = '';
    private $currentMailbox = 'INBOX';
    private $mailboxDelimiter = null;

    private $lastError = null;

    private $alertedEmails = [];


    // Constructeur pour initialiser les informations de connexion
    public function __construct($hostname, $username, $password)
    {
        $this->hostname = $hostname; // Utiliser l'hôte spécifié
        $this->username = $username;
        $this->password = $password;

        $this->serverSpec = $this->extractServerSpec((string)$this->hostname);
        $this->currentMailbox = $this->extractMailboxFromHostname((string)$this->hostname);
        if ($this->currentMailbox === '') {
            $this->currentMailbox = 'INBOX';
        }

        // Tentative de connexion IMAP (silencieuse: pas d'echo qui pollue l'UI)
        if (!function_exists('imap_open')) {
            $this->lastError = "Extension PHP IMAP non installée/activée.";
            $this->inbox = null;
            return;
        }

        $this->inbox = @imap_open($this->hostname, $this->username, $this->password);
        if (!$this->inbox) {
            $this->lastError = 'Impossible de se connecter au serveur de messagerie.';
            $err = imap_last_error();
            if ($err) {
                $this->lastError .= ' ' . $err;
            }
            $this->inbox = null;
        }
    }

    public function getCurrentMailbox(): string
    {
        return $this->currentMailbox;
    }

    public function reopenMailbox(string $mailbox): bool
    {
        if (!$this->isConnected()) {
            return false;
        }

        $mailbox = trim($mailbox);
        if ($mailbox === '') {
            $mailbox = 'INBOX';
        }

        $target = $this->serverSpec !== '' ? ($this->serverSpec . $this->encodeMailboxName($mailbox)) : $mailbox;
        $ok = false;
        if (function_exists('imap_reopen')) {
            $ok = (bool)@imap_reopen($this->inbox, $target);
        }

        if ($ok) {
            $this->currentMailbox = $mailbox;
            return true;
        }

        $this->lastError = 'Impossible d’ouvrir le dossier: ' . $mailbox;
        $err = imap_last_error();
        if ($err) {
            $this->lastError .= ' ' . $err;
        }
        return false;
    }

    public function listMailboxes(string $pattern = '*'): array
    {
        if (!$this->isConnected()) {
            return [];
        }

        if (!function_exists('imap_getmailboxes')) {
            return [];
        }

        $server = $this->serverSpec !== '' ? $this->serverSpec : (string)$this->hostname;
        $boxes = @imap_getmailboxes($this->inbox, $server, $pattern);
        if (!$boxes || !is_array($boxes)) {
            return [];
        }

        $delimiter = null;
        foreach ($boxes as $b) {
            if (is_object($b) && isset($b->delimiter) && (string)$b->delimiter !== '') {
                $delimiter = (string)$b->delimiter;
                break;
            }
        }
        $this->mailboxDelimiter = $delimiter ?: $this->getMailboxDelimiter();

        $out = [];
        foreach ($boxes as $b) {
            if (!is_object($b) || empty($b->name)) {
                continue;
            }
            $fullName = (string)$b->name;
            $short = $this->stripServerSpec($fullName);
            $short = $this->decodeMailboxName($short);
            $short = trim($short);
            if ($short === '') {
                continue;
            }

            $role = 'custom';
            $u = strtoupper($short);
            if ($u === 'INBOX') {
                $role = 'inbox';
            } elseif (preg_match('/(^|[.\\/])SENT( ITEMS)?$/i', $short) || preg_match('/(^|[.\\/])ENVOY(E|É)S$/iu', $short)) {
                $role = 'sent';
            } elseif (preg_match('/(^|[.\\/])DRAFTS?$/i', $short) || preg_match('/(^|[.\\/])BROUILLONS?$/iu', $short)) {
                $role = 'drafts';
            }

            $label = $short;
            if ($role === 'inbox') {
                $label = 'Boîte de réception';
            } elseif ($role === 'sent') {
                $label = 'Envoyés';
            } elseif ($role === 'drafts') {
                $label = 'Brouillons';
            } else {
                $del = $this->mailboxDelimiter ?: '.';
                $label = str_replace($del, ' / ', $short);
            }

            $out[] = [
                'name' => $short,
                'label' => $label,
                'role' => $role,
            ];
        }

        // Trier: inbox/sent/drafts en premier, puis alpha
        usort($out, static function ($a, $b) {
            $prio = ['inbox' => 0, 'sent' => 1, 'drafts' => 2, 'custom' => 3];
            $pa = $prio[(string)($a['role'] ?? 'custom')] ?? 3;
            $pb = $prio[(string)($b['role'] ?? 'custom')] ?? 3;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return strnatcasecmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
        });

        return $out;
    }

    public function getMailboxDelimiter(): string
    {
        if ($this->mailboxDelimiter) {
            return (string)$this->mailboxDelimiter;
        }

        // Fallback raisonnable: IONOS utilise souvent '.'
        $this->mailboxDelimiter = '.';
        return '.';
    }

    public function createMailbox(string $name): bool
    {
        if (!$this->isConnected() || !function_exists('imap_createmailbox')) {
            return false;
        }

        $name = trim($name);
        if ($name === '') {
            $this->lastError = 'Nom de dossier vide.';
            return false;
        }

        $delimiter = $this->getMailboxDelimiter();
        $upper = strtoupper($name);

        // Par défaut, créer comme sous-dossier de INBOX si l'utilisateur donne un nom simple.
        if (strpos($name, $delimiter) === false && strpos($upper, 'INBOX') !== 0) {
            $name = 'INBOX' . $delimiter . $name;
        }

        $full = $this->serverSpec !== '' ? ($this->serverSpec . $this->encodeMailboxName($name)) : $name;
        $ok = (bool)@imap_createmailbox($this->inbox, $full);
        if (!$ok) {
            $this->lastError = 'Impossible de créer le dossier.';
            $err = imap_last_error();
            if ($err) {
                $this->lastError .= ' ' . $err;
            }
        }
        return $ok;
    }

    public function moveEmailByUid(int $uid, string $targetMailbox): bool
    {
        if (!$this->isConnected() || $uid <= 0) {
            return false;
        }

        $targetMailbox = trim($targetMailbox);
        if ($targetMailbox === '') {
            return false;
        }

        if (!function_exists('imap_mail_move')) {
            return false;
        }

        $targetEnc = $this->encodeMailboxName($targetMailbox);

        $ok = false;
        if (defined('CP_UID')) {
            $ok = (bool)@imap_mail_move($this->inbox, (string)$uid, $targetEnc, CP_UID);
        } else {
            $msgno = @imap_msgno($this->inbox, $uid);
            if ($msgno) {
                $ok = (bool)@imap_mail_move($this->inbox, (string)$msgno, $targetEnc);
            }
        }

        // Do not expunge unrelated messages marked deleted by another client.

        if (!$ok) {
            $this->lastError = 'Impossible de déplacer le mail.';
            $err = imap_last_error();
            if ($err) {
                $this->lastError .= ' ' . $err;
            }
        }

        return $ok;
    }

    private function extractServerSpec(string $hostname): string
    {
        if ($hostname === '') {
            return '';
        }
        if (preg_match('/^\{[^}]+\}/', $hostname, $m)) {
            return (string)$m[0];
        }
        return '';
    }

    private function extractMailboxFromHostname(string $hostname): string
    {
        $spec = $this->extractServerSpec($hostname);
        if ($spec !== '' && strpos($hostname, $spec) === 0) {
            $mb = substr($hostname, strlen($spec));
            $mb = trim((string)$mb);
            return $mb !== '' ? $mb : 'INBOX';
        }
        return 'INBOX';
    }

    private function stripServerSpec(string $name): string
    {
        $name = (string)$name;
        if ($this->serverSpec !== '' && strpos($name, $this->serverSpec) === 0) {
            return substr($name, strlen($this->serverSpec));
        }
        // fallback: retirer un éventuel {..} en tête
        if (preg_match('/^\{[^}]+\}/', $name, $m)) {
            return substr($name, strlen((string)$m[0]));
        }
        return $name;
    }

    private function encodeMailboxName(string $name): string
    {
        if (function_exists('imap_utf7_encode')) {
            return (string)imap_utf7_encode($name);
        }
        return $name;
    }

    private function decodeMailboxName(string $name): string
    {
        if (function_exists('imap_utf7_decode')) {
            return (string)imap_utf7_decode($name);
        }
        return $name;
    }

    public function __destruct()
    {
        $this->closeConnection();
    }

    public function isConnected(): bool
    {
        return !empty($this->inbox);
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    // Méthode pour récupérer uniquement les emails non lus
    public function getUnreadEmails()
    {
        return $this->fetchEmails('UNSEEN');
    }

    // Méthode pour récupérer tous les emails (lus et non lus)
    public function getAllEmails()
    {
        return $this->fetchEmails('ALL');
    }

    /**
     * Liste les emails (léger) avec UID, statut lu/non lu et aperçu.
     */
    public function listEmails(string $search_criteria = 'ALL', int $limit = 50, int $offset = 0, ?\MailboxCacheManager $cacheManager = null, int $cacheUserId = 0, string $cacheFolder = 'INBOX'): array
    {
        if (!$this->isConnected()) {
            return [];
        }

        $emails = @imap_search($this->inbox, 'UNDELETED ' . $search_criteria);
        if (!$emails || !is_array($emails)) {
            return [];
        }

        rsort($emails);

        if ($offset < 0) {
            $offset = 0;
        }

        $emails = array_slice($emails, $offset, max(1, $limit));

        $email_data = [];

        foreach ($emails as $email_number) {
            $uid = (int) (@imap_uid($this->inbox, $email_number) ?: 0);

            if ($cacheManager !== null && $cacheUserId > 0 && $uid > 0 && strtoupper(trim($search_criteria)) !== 'UNSEEN') {
                $cached = $cacheManager->getSummary($cacheUserId, $cacheFolder, $uid);
                if (is_array($cached) && !empty($cached['is_read'])) {
                    if (empty($cached['id'])) {
                        $cached['id'] = $email_number;
                    }
                    $email_data[] = $cached;
                    continue;
                }
            }

            $overviewArr = @imap_fetch_overview($this->inbox, $email_number, 0);
            if (!$overviewArr || !isset($overviewArr[0])) {
                continue;
            }
            $overview = $overviewArr[0];

            $subject = isset($overview->subject) ? imap_utf8($overview->subject) : '(Aucun Objet)';
            $from = isset($overview->from) ? imap_utf8($overview->from) : 'Expéditeur Inconnu';
            $date = isset($overview->date) ? $overview->date : 'Date non définie';
            $isRead = !empty($overview->seen);

            // Teaser (best-effort) - ne marque pas comme lu
            $message = @imap_fetchbody($this->inbox, $email_number, 1, FT_PEEK);
            $body = $message ? quoted_printable_decode($message) : '';
            $body = trim(preg_replace('/\s+/', ' ', strip_tags($body)));

            $teaser = function_exists('mb_substr') ? mb_substr($body, 0, 80) : substr($body, 0, 80);

            $row = [
                'uid' => $uid ?: null,
                'id' => $email_number,
                'from' => $from,
                'subject' => $subject,
                'date' => $date,
                'is_read' => $isRead,
                'flagged' => !empty($overview->flagged),
                'teaser' => $teaser,
            ];

            $email_data[] = $row;

            if ($cacheManager !== null && $cacheUserId > 0 && $uid > 0) {
                $cacheManager->saveSummary($cacheUserId, $cacheFolder, $row);
            }
        }

        return $email_data;
    }

    /**
     * Récupère un email complet par UID.
     * Retourne: from, subject, date, message (texte) + attachments (metadata)
     */
    public function getEmailByUid(int $uid, ?\MailboxCacheManager $cacheManager = null, int $cacheUserId = 0, string $cacheFolder = 'INBOX'): ?array
    {
        if (!$this->isConnected() || $uid <= 0) {
            return null;
        }

        if ($cacheManager !== null && $cacheUserId > 0) {
            $cachedMessage = $cacheManager->getFullMessage($cacheUserId, $cacheFolder, $uid);
            if (is_array($cachedMessage)) {
                return $cachedMessage;
            }
        }

        $email_number = @imap_msgno($this->inbox, $uid);
        if (!$email_number) {
            return null;
        }

        $overviewArr = @imap_fetch_overview($this->inbox, $email_number, 0);
        $overview = ($overviewArr && isset($overviewArr[0])) ? $overviewArr[0] : null;

        $subject = $overview && isset($overview->subject) ? imap_utf8($overview->subject) : '(Aucun Objet)';
        $from = $overview && isset($overview->from) ? imap_utf8($overview->from) : 'Expéditeur Inconnu';
        $date = $overview && isset($overview->date) ? $overview->date : 'Date non définie';

        $headerData = $this->fetchHeaderData($email_number);

        $bodyPayload = $this->fetchBodyPayload($email_number);
        $bodyPlain = (string)($bodyPayload['plain'] ?? '');
        $bodyHtml = (string)($bodyPayload['html'] ?? '');

        $attachments = $this->listAttachments($email_number);

        $message = [
            'uid' => $uid,
            'id' => $email_number,
            'from' => $from,
            'subject' => $subject,
            'date' => $date,
            'to' => (string)($headerData['to'] ?? ''),
            'cc' => (string)($headerData['cc'] ?? ''),
            'bcc' => (string)($headerData['bcc'] ?? ''),
            'reply_to' => (string)($headerData['reply_to'] ?? ''),
            'message_id' => (string)($headerData['message_id'] ?? ''),
            'in_reply_to' => (string)($headerData['in_reply_to'] ?? ''),
            'references' => (string)($headerData['references'] ?? ''),
            'to_addresses' => (array)($headerData['to_addresses'] ?? []),
            'cc_addresses' => (array)($headerData['cc_addresses'] ?? []),
            'bcc_addresses' => (array)($headerData['bcc_addresses'] ?? []),
            'reply_to_addresses' => (array)($headerData['reply_to_addresses'] ?? []),
            // Compat: message texte
            'message' => $bodyPlain !== '' ? $bodyPlain : $this->htmlToText($bodyHtml),
            // Version HTML si disponible
            'message_html' => $bodyHtml,
            'message_is_html' => $bodyHtml !== '',
            'attachments' => $attachments,
        ];

        if ($cacheManager !== null && $cacheUserId > 0) {
            $cacheManager->saveFullMessage($cacheUserId, $cacheFolder, $message);
        }

        return $message;
    }

    private function fetchHeaderData(int $email_number): array
    {
        if (!$this->isConnected()) {
            return [];
        }

        // Chemin rapide: récupérer les infos d'en-têtes déjà parsées par l'extension IMAP.
        if (function_exists('imap_headerinfo')) {
            $h = @imap_headerinfo($this->inbox, $email_number);
            if ($h && is_object($h)) {
                $toList = $h->to ?? [];
                $ccList = $h->cc ?? [];
                $bccList = $h->bcc ?? [];
                $replyToList = $h->reply_to ?? [];

                $messageId = trim((string)($h->message_id ?? ''));
                $inReplyTo = trim((string)($h->in_reply_to ?? ''));
                $references = trim((string)($h->references ?? ''));

                return [
                    'to' => $this->formatAddressList($toList),
                    'cc' => $this->formatAddressList($ccList),
                    'bcc' => $this->formatAddressList($bccList),
                    'reply_to' => $this->formatAddressList($replyToList),
                    'message_id' => $this->decodeHeaderText($messageId),
                    'in_reply_to' => $this->decodeHeaderText($inReplyTo),
                    'references' => $this->decodeHeaderText($references),
                    'to_addresses' => $this->addressObjectsToArray($toList),
                    'cc_addresses' => $this->addressObjectsToArray($ccList),
                    'bcc_addresses' => $this->addressObjectsToArray($bccList),
                    'reply_to_addresses' => $this->addressObjectsToArray($replyToList),
                ];
            }
        }

        $raw = @imap_fetchheader($this->inbox, $email_number, FT_PREFETCHTEXT);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        if (!function_exists('imap_rfc822_parse_headers')) {
            return [];
        }

        $headers = @imap_rfc822_parse_headers($raw);
        if (!$headers || !is_object($headers)) {
            return [];
        }

        $toList = $headers->to ?? [];
        $ccList = $headers->cc ?? [];
        $bccList = $headers->bcc ?? [];
        $replyToList = $headers->reply_to ?? [];

        $messageId = $this->decodeHeaderText((string)($headers->message_id ?? ''));
        $inReplyTo = $this->decodeHeaderText((string)($headers->in_reply_to ?? ''));
        $references = $this->decodeHeaderText((string)($headers->references ?? ''));

        return [
            'to' => $this->formatAddressList($toList),
            'cc' => $this->formatAddressList($ccList),
            'bcc' => $this->formatAddressList($bccList),
            'reply_to' => $this->formatAddressList($replyToList),
            'message_id' => trim($messageId),
            'in_reply_to' => trim($inReplyTo),
            'references' => trim($references),
            'to_addresses' => $this->addressObjectsToArray($toList),
            'cc_addresses' => $this->addressObjectsToArray($ccList),
            'bcc_addresses' => $this->addressObjectsToArray($bccList),
            'reply_to_addresses' => $this->addressObjectsToArray($replyToList),
        ];
    }

    private function decodeHeaderText(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        // La plupart des headers (ex: Message-ID) ne sont pas encodés MIME.
        if (strpos($text, '=?') === false) {
            return $text;
        }

        if (function_exists('imap_mime_header_decode')) {
            $decoded = @imap_mime_header_decode($text);
            if (is_array($decoded)) {
                $out = '';
                foreach ($decoded as $part) {
                    $charset = isset($part->charset) ? (string)$part->charset : 'UTF-8';
                    $value = isset($part->text) ? (string)$part->text : '';
                    $out .= $this->convertToUtf8($value, $charset);
                }
                $out = trim($out);
                if ($out !== '') {
                    return $out;
                }
            }
        }

        if (function_exists('imap_utf8')) {
            return (string)imap_utf8($text);
        }

        return $text;
    }

    private function formatAddressList($list): string
    {
        $items = $this->addressObjectsToArray($list);
        if (empty($items)) {
            return '';
        }

        $out = [];
        foreach ($items as $it) {
            $email = (string)($it['email'] ?? '');
            $name = trim((string)($it['name'] ?? ''));
            if ($email === '') {
                continue;
            }
            if ($name !== '') {
                $out[] = $name . ' <' . $email . '>';
            } else {
                $out[] = $email;
            }
        }
        return implode(', ', $out);
    }

    private function addressObjectsToArray($list): array
    {
        if (empty($list) || (!is_array($list) && !is_object($list))) {
            return [];
        }

        $arr = is_array($list) ? $list : (array)$list;
        $out = [];
        foreach ($arr as $obj) {
            if (!is_object($obj)) {
                continue;
            }
            $mailbox = isset($obj->mailbox) ? (string)$obj->mailbox : '';
            $host = isset($obj->host) ? (string)$obj->host : '';
            $email = '';
            if ($mailbox !== '' && $host !== '') {
                $email = $mailbox . '@' . $host;
            }

            $personal = isset($obj->personal) ? $this->decodeHeaderText((string)$obj->personal) : '';
            $personal = trim($personal);

            if ($email === '') {
                continue;
            }

            $out[] = [
                'email' => $email,
                'name' => $personal,
            ];
        }
        return $out;
    }

    public function setMessageFlag(int $uid, string $flag, bool $enabled): bool
    {
        if (!$this->isConnected() || $uid <= 0 || !in_array($flag, ['\\Seen','\\Flagged'], true) || !@imap_msgno($this->inbox, $uid)) return false;
        return $enabled ? @imap_setflag_full($this->inbox,(string)$uid,$flag,ST_UID) : @imap_clearflag_full($this->inbox,(string)$uid,$flag,ST_UID);
    }

    public function markAsReadByUid(int $uid): bool
    {
        if (!$this->isConnected() || $uid <= 0) {
            return false;
        }
        $email_number = @imap_msgno($this->inbox, $uid);
        if (!$email_number) {
            return false;
        }
        return (bool)@imap_setflag_full($this->inbox, (string)$email_number, "\\Seen");
    }

    public function getAttachmentContentByUid(int $uid, int $attachmentIndex): ?array
    {
        if (!$this->isConnected() || $uid <= 0 || $attachmentIndex < 0) {
            return null;
        }

        $email_number = @imap_msgno($this->inbox, $uid);
        if (!$email_number) {
            return null;
        }

        $structure = @imap_fetchstructure($this->inbox, $email_number);
        if (!$structure || empty($structure->parts) || !is_array($structure->parts)) {
            return null;
        }

        $parts = $structure->parts;
        $attachmentParts = [];

        for ($i = 0; $i < count($parts); $i++) {
            $part = $parts[$i];
            $filename = null;

            if (!empty($part->ifdparameters) && !empty($part->dparameters[0]->value)) {
                $filename = $part->dparameters[0]->value;
            } elseif (!empty($part->ifparameters) && !empty($part->parameters[0]->value)) {
                $filename = $part->parameters[0]->value;
            }

            if ($filename !== null) {
                $attachmentParts[] = ['partIndex' => $i, 'filename' => $filename, 'encoding' => (int)($part->encoding ?? 0)];
            }
        }

        if (!isset($attachmentParts[$attachmentIndex])) {
            return null;
        }

        $target = $attachmentParts[$attachmentIndex];
        $partNumber = $target['partIndex'] + 1;
        $data = @imap_fetchbody($this->inbox, $email_number, $partNumber, FT_PEEK);
        if ($data === false) {
            return null;
        }

        $encoding = (int)$target['encoding'];
        if ($encoding === 3) {
            $data = base64_decode($data);
        } elseif ($encoding === 4) {
            $data = quoted_printable_decode($data);
        }

        return [
            'filename' => (string)$target['filename'],
            'content' => $data,
        ];
    }

    // Méthode privée pour récupérer les emails en fonction d'un critère de recherche
    private function fetchEmails($search_criteria)
    {
        if (!$this->isConnected()) {
            return [];
        }

        $emails = @imap_search($this->inbox, $search_criteria);

        $email_data = [];

        if ($emails && is_array($emails)) {
            rsort($emails); // Trier les emails du plus récent au plus ancien

            foreach ($emails as $email_number) {
                $overview = imap_fetch_overview($this->inbox, $email_number, 0);

                // Vérifie que $overview est bien un tableau et contient des données
                if ($overview && isset($overview[0])) {
                    $overview = $overview[0];
                    $message = @imap_fetchbody($this->inbox, $email_number, 1, FT_PEEK);

                    $subject = isset($overview->subject) ? imap_utf8($overview->subject) : '(Aucun Objet)';
                    $from = isset($overview->from) ? imap_utf8($overview->from) : 'Expéditeur Inconnu';
                    $date = isset($overview->date) ? $overview->date : 'Date non définie';

                    // Gestion du corps du message
                    $body = isset($message) ? quoted_printable_decode($message) : 'Aucun contenu';

                    // Extraire les pièces jointes
                    $structure = @imap_fetchstructure($this->inbox, $email_number);
                    $attachments = [];

                    if (isset($structure->parts) && count($structure->parts) > 0) {
                        for ($i = 0; $i < count($structure->parts); $i++) {
                            $attachment = $structure->parts[$i];
                            if ($attachment->ifdparameters) {
                                $filename = $attachment->dparameters[0]->value;
                                $encoding = $attachment->encoding;

                                $attachment_data = @imap_fetchbody($this->inbox, $email_number, $i + 1, FT_PEEK);
                                if ($encoding == 3) { // Base64
                                    $attachment_data = base64_decode($attachment_data);
                                } elseif ($encoding == 4) { // Quoted Printable
                                    $attachment_data = quoted_printable_decode($attachment_data);
                                }

                                $attachments[] = [
                                    'filename' => $filename,
                                    'content' => base64_encode($attachment_data)
                                ];
                            }
                        }
                    }

                    $email_data[] = [
                        'from' => $from,
                        'subject' => $subject,
                        'date' => $date,
                        'message' => $body,
                        'attachments' => $attachments
                    ];
                }
            }
        }

        return $email_data;
    }

    // Compter les emails non lus
    public function countUnreadEmails()
    {
        if (!$this->isConnected()) {
            return 0;
        }

        $emails = @imap_search($this->inbox, 'UNSEEN');
        return $emails ? count($emails) : 0;
    }

    public function checkForNewUnreadEmailsAndSendAlert()
    {
        // Récupère les emails non lus
        if (!$this->isConnected()) {
            return;
        }

        $emails = @imap_search($this->inbox, 'UNSEEN');

        if ($emails) {
            rsort($emails); // Trier les emails du plus récent au plus ancien
            foreach ($emails as $email_number) {
                $uid = imap_uid($this->inbox, $email_number); // Récupère l'UID unique

                // Vérifie si une alerte a déjà été envoyée pour cet e-mail
                if (!in_array($uid, $this->alertedEmails)) {
                    // Récupère les informations de l'e-mail
                    $overview = imap_fetch_overview($this->inbox, $email_number, FT_PEEK)[0];
                    $from = isset($overview->from) ? imap_utf8($overview->from) : 'Expéditeur Inconnu';
                    $subject = isset($overview->subject) ? imap_utf8($overview->subject) : '(Aucun Objet)';

                    // Envoyer l'alerte pour le nouvel email (ici, par exemple, une fonction d'alerte personnalisée)
                    $this->sendAlert("Nouvel email non lu de {$from} avec le sujet: {$subject}");

                    // Ajouter l'UID de cet e-mail à la liste des alertes envoyées
                    $this->alertedEmails[] = $uid;
                }
            }
        }
    }

    private function sendAlert($message)
    {
        // Vous pouvez personnaliser cette fonction pour envoyer une alerte (par email, SMS, etc.)
        // NOTE: Ne pas echo ici pour ne pas polluer l'UI; utiliser error_log.
        error_log("ALERTE: " . $message);
    }


    // Méthode pour vérifier l'exactitude du mot de passe de l'email
    public function verifyPassword()
    {
        if (!function_exists('imap_open')) {
            $this->lastError = "Extension PHP IMAP non installée/activée.";
            return false;
        }

        $imap_connection = @imap_open($this->hostname, $this->username, $this->password);
        if (!$imap_connection) {
            $this->lastError = 'Connexion IMAP impossible.';
            $err = imap_last_error();
            if ($err) {
                $this->lastError .= ' ' . $err;
            }
            return false;
        }

        imap_close($imap_connection);
        return true;
    }

    // Fermer la connexion IMAP
    public function closeConnection()
    {
        if ($this->inbox) {
            @imap_close($this->inbox);
        }
        $this->inbox = null;
    }

    private function fetchBodyPayload(int $email_number): array
    {
        if (!$this->isConnected()) {
            return ['plain' => '', 'html' => ''];
        }

        $structure = @imap_fetchstructure($this->inbox, $email_number);
        if (!$structure) {
            return ['plain' => '', 'html' => ''];
        }

        // Cas message simple (pas multipart)
        if (empty($structure->parts) || !is_array($structure->parts)) {
            $encoding = (int)($structure->encoding ?? 0);
            $charset = $this->detectPartCharset($structure);
            $raw = @imap_body($this->inbox, $email_number, FT_PEEK);
            $decoded = $this->decodeBody((string)$raw, $encoding);
            $decoded = $this->convertToUtf8($decoded, $charset);

            $subtype = strtoupper((string)($structure->subtype ?? ''));
            if ($subtype === 'HTML') {
                return ['plain' => '', 'html' => $decoded];
            }
            return ['plain' => $decoded, 'html' => ''];
        }

        $parts = $this->flattenParts($structure);
        $plain = '';
        $html = '';

        foreach ($parts as $item) {
            $partNo = (string)($item['partNo'] ?? '');
            $part = $item['part'] ?? null;
            if ($partNo === '' || !$part) {
                continue;
            }

            // Ignorer les pièces jointes (disposition ATTACHMENT)
            $disposition = strtoupper((string)($part->disposition ?? ''));
            if ($disposition === 'ATTACHMENT') {
                continue;
            }

            $type = (int)($part->type ?? 0);
            $subtype = strtoupper((string)($part->subtype ?? ''));

            if ($type !== TYPETEXT) {
                continue;
            }

            if (!in_array($subtype, ['PLAIN', 'HTML'], true)) {
                continue;
            }

            $encoding = (int)($part->encoding ?? 0);
            $charset = $this->detectPartCharset($part);

            $raw = @imap_fetchbody($this->inbox, $email_number, $partNo, FT_PEEK);
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }

            $decoded = $this->decodeBody($raw, $encoding);
            $decoded = $this->convertToUtf8($decoded, $charset);

            if ($subtype === 'HTML' && $html === '') {
                $html = $decoded;
            }
            if ($subtype === 'PLAIN' && $plain === '') {
                $plain = $decoded;
            }
        }

        return ['plain' => trim($plain), 'html' => trim($html)];
    }

    private function flattenParts($structure, string $prefix = ''): array
    {
        $result = [];

        if (empty($structure->parts) || !is_array($structure->parts)) {
            return $result;
        }

        for ($i = 0; $i < count($structure->parts); $i++) {
            $part = $structure->parts[$i];
            $partNo = $prefix === '' ? (string)($i + 1) : $prefix . '.' . (string)($i + 1);

            $result[] = ['partNo' => $partNo, 'part' => $part];

            // Recurse si multipart
            if (!empty($part->parts) && is_array($part->parts)) {
                $result = array_merge($result, $this->flattenParts($part, $partNo));
            }
        }

        return $result;
    }

    private function decodeBody(string $raw, int $encoding): string
    {
        if ($encoding === 3) {
            $decoded = base64_decode($raw);
            return is_string($decoded) ? $decoded : '';
        }

        if ($encoding === 4) {
            return quoted_printable_decode($raw);
        }

        return $raw;
    }

    private function detectPartCharset($part): string
    {
        // charset souvent dans $part->parameters (ifparameters)
        if (!empty($part->ifparameters) && !empty($part->parameters) && is_array($part->parameters)) {
            foreach ($part->parameters as $p) {
                if (!empty($p->attribute) && strtolower((string)$p->attribute) === 'charset' && !empty($p->value)) {
                    return (string)$p->value;
                }
            }
        }

        return 'UTF-8';
    }

    private function convertToUtf8(string $content, string $charset): string
    {
        $charset = trim((string)$charset);
        if ($charset === '') {
            return $content;
        }

        $upper = strtoupper($charset);
        // Certains décodeurs renvoient "default" quand le charset est inconnu/non spécifié.
        // Dans ce cas, on évite toute conversion (surtout pour des en-têtes comme Message-ID).
        if (in_array($upper, ['DEFAULT', 'UNKNOWN', 'UNKNOWN-8BIT', 'X-UNKNOWN'], true)) {
            return $content;
        }
        if ($upper === 'UTF-8' || $upper === 'UTF8') {
            return $content;
        }

        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($content, 'UTF-8', $charset);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        if (function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//TRANSLIT//IGNORE', $content);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        return $content;
    }

    private function htmlToText(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', (string)$text);
        return trim((string)$text);
    }

    private function listAttachments(int $email_number): array
    {
        $structure = @imap_fetchstructure($this->inbox, $email_number);
        $attachments = [];

        if (!$structure || empty($structure->parts) || !is_array($structure->parts)) {
            return $attachments;
        }

        for ($i = 0; $i < count($structure->parts); $i++) {
            $part = $structure->parts[$i];
            $filename = null;

            if (!empty($part->ifdparameters) && !empty($part->dparameters[0]->value)) {
                $filename = $part->dparameters[0]->value;
            } elseif (!empty($part->ifparameters) && !empty($part->parameters[0]->value)) {
                $filename = $part->parameters[0]->value;
            }

            if ($filename !== null) {
                $attachments[] = [
                    'index' => count($attachments),
                    'filename' => (string)$filename,
                ];
            }
        }

        return $attachments;
    }

    public function createIonosNewMail($domain, $userName, $mailPassword, $loginIonos, $passIonos, $displayName)
    {
        //Créer le mail pro 
        // Identifiants d'authentification pour l'API IONOS
        // $username = "639478258";
        // $password = "Nolwenn0708*";

        $ionosusername = $loginIonos;
        $ionospassword = $passIonos;

        // URL de l'API pour la création de compte email (à adapter selon la documentation)
        $url = "https://api.ionos.com/email/users";

        // Configuration des paramètres pour la création de l'email
        $data = array(
            "email" => $userName . "@" . $domain . ".com",
            "password" => $mailPassword,
            "displayName" => $displayName,
            "forwarding" => false
        );

        // Initialiser cURL
        $ch = curl_init($url);

        // Options de la requête
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, "$ionosusername:$ionospassword");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
        ));

        // Exécuter la requête et capturer la réponse
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($http_code == 201) {
            echo "Le compte email a été créé avec succès.";
        } else {
            echo "Erreur lors de la création de l'email : " . $response;
        }

        // Fermer la session cURL
        curl_close($ch);
    }
}
