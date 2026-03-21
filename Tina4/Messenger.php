<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Messenger — Email sending (SMTP) and reading (IMAP), zero external dependencies.
 * Uses raw socket communication for SMTP and PHP's imap_* extension for reading.
 */

namespace Tina4;

class Messenger
{
    /** @var string|null SMTP host */
    private ?string $host;

    /** @var int|null SMTP port */
    private ?int $port;

    /** @var string|null SMTP username */
    private ?string $username;

    /** @var string|null SMTP password */
    private ?string $password;

    /** @var string|null Sender email address */
    private ?string $fromAddress;

    /** @var string|null Sender display name */
    private ?string $fromName;

    /** @var bool Whether to use STARTTLS */
    private bool $useTls;

    /** @var string|null IMAP host */
    private ?string $imapHost;

    /** @var int|null IMAP port */
    private ?int $imapPort;

    /** @var int Socket timeout in seconds */
    private int $timeout = 30;

    /**
     * @param string|null $host       SMTP server hostname
     * @param int|null    $port       SMTP server port (587 for TLS, 465 for SSL, 25 for plain)
     * @param string|null $username   SMTP authentication username
     * @param string|null $password   SMTP authentication password
     * @param string|null $fromAddress Default sender email address
     * @param string|null $fromName   Default sender display name
     * @param bool        $useTls     Whether to use STARTTLS (default true)
     * @param string|null $imapHost   IMAP server hostname
     * @param int|null    $imapPort   IMAP server port (993 for SSL, 143 for plain)
     */
    public function __construct(
        ?string $host = null,
        ?int $port = null,
        ?string $username = null,
        ?string $password = null,
        ?string $fromAddress = null,
        ?string $fromName = null,
        bool $useTls = true,
        ?string $imapHost = null,
        ?int $imapPort = null,
    ) {
        $this->host = $host ?? $this->env('SMTP_HOST');
        $this->port = $port ?? ($this->env('SMTP_PORT') !== null ? (int)$this->env('SMTP_PORT') : null);
        $this->username = $username ?? $this->env('SMTP_USERNAME');
        $this->password = $password ?? $this->env('SMTP_PASSWORD');
        $this->fromAddress = $fromAddress ?? $this->env('SMTP_FROM');
        $this->fromName = $fromName ?? $this->env('SMTP_FROM_NAME');
        $this->useTls = $useTls;
        $this->imapHost = $imapHost ?? $this->env('IMAP_HOST');
        $this->imapPort = $imapPort ?? ($this->env('IMAP_PORT') !== null ? (int)$this->env('IMAP_PORT') : null);
    }

    /**
     * Send an email via raw SMTP socket.
     *
     * @param string|array $to          Recipient(s)
     * @param string       $subject     Email subject
     * @param string       $body        Email body content
     * @param bool         $html        Whether the body is HTML
     * @param array        $cc          CC recipients
     * @param array        $bcc         BCC recipients
     * @param string|null  $replyTo     Reply-to address
     * @param array        $attachments File paths or associative arrays with filename/content/mime
     * @param array        $headers     Additional headers as key => value
     * @return array ['success' => bool, 'message' => string, 'id' => string|null]
     */
    public function send(
        string|array $to,
        string $subject,
        string $body,
        bool $html = false,
        array $cc = [],
        array $bcc = [],
        ?string $replyTo = null,
        array $attachments = [],
        array $headers = [],
    ): array {
        if ($this->host === null || $this->port === null) {
            return ['success' => false, 'message' => 'SMTP host and port are required', 'id' => null];
        }

        if ($this->fromAddress === null) {
            return ['success' => false, 'message' => 'Sender address (fromAddress) is required', 'id' => null];
        }

        $recipients = is_array($to) ? $to : [$to];
        $allRecipients = array_merge($recipients, $cc, $bcc);

        if (empty($allRecipients)) {
            return ['success' => false, 'message' => 'At least one recipient is required', 'id' => null];
        }

        $messageId = $this->generateMessageId();
        $rawMessage = $this->buildMessage($recipients, $subject, $body, $html, $cc, $replyTo, $attachments, $headers, $messageId);

        try {
            $socket = $this->connect();
            $this->readResponse($socket, 220);

            // EHLO
            $this->sendCommand($socket, 'EHLO ' . gethostname(), 250);

            // STARTTLS for port 587
            if ($this->useTls && $this->port === 587) {
                $this->sendCommand($socket, 'STARTTLS', 220);

                $crypto = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($crypto !== true) {
                    fclose($socket);
                    return ['success' => false, 'message' => 'STARTTLS handshake failed', 'id' => null];
                }

                // Re-EHLO after TLS
                $this->sendCommand($socket, 'EHLO ' . gethostname(), 250);
            }

            // AUTH LOGIN
            if ($this->username !== null && $this->password !== null) {
                $this->sendCommand($socket, 'AUTH LOGIN', 334);
                $this->sendCommand($socket, base64_encode($this->username), 334);
                $this->sendCommand($socket, base64_encode($this->password), 235);
            }

            // MAIL FROM
            $this->sendCommand($socket, 'MAIL FROM:<' . $this->fromAddress . '>', 250);

            // RCPT TO for all recipients
            foreach ($allRecipients as $recipient) {
                $addr = $this->extractAddress($recipient);
                $this->sendCommand($socket, 'RCPT TO:<' . $addr . '>', [250, 251]);
            }

            // DATA
            $this->sendCommand($socket, 'DATA', 354);

            // Send message body — dot-stuff lines starting with a period
            $lines = explode("\n", str_replace("\r\n", "\n", $rawMessage));
            foreach ($lines as $line) {
                if (str_starts_with($line, '.')) {
                    $line = '.' . $line;
                }
                fwrite($socket, $line . "\r\n");
            }

            // End DATA
            $this->sendCommand($socket, '.', 250);

            // QUIT
            $this->sendCommand($socket, 'QUIT', 221);
            fclose($socket);

            return ['success' => true, 'message' => 'Email sent successfully', 'id' => $messageId];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'SMTP error: ' . $e->getMessage(), 'id' => null];
        }
    }

    /**
     * Test the SMTP connection.
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public function testConnection(): array
    {
        if ($this->host === null || $this->port === null) {
            return ['success' => false, 'message' => 'SMTP host and port are required'];
        }

        try {
            $socket = $this->connect();
            $response = $this->readResponse($socket, 220);

            $this->sendCommand($socket, 'EHLO ' . gethostname(), 250);

            if ($this->useTls && $this->port === 587) {
                $this->sendCommand($socket, 'STARTTLS', 220);

                $crypto = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($crypto !== true) {
                    fclose($socket);
                    return ['success' => false, 'message' => 'STARTTLS handshake failed'];
                }

                $this->sendCommand($socket, 'EHLO ' . gethostname(), 250);
            }

            if ($this->username !== null && $this->password !== null) {
                $this->sendCommand($socket, 'AUTH LOGIN', 334);
                $this->sendCommand($socket, base64_encode($this->username), 334);
                $this->sendCommand($socket, base64_encode($this->password), 235);
            }

            $this->sendCommand($socket, 'QUIT', 221);
            fclose($socket);

            return ['success' => true, 'message' => 'SMTP connection successful: ' . trim($response)];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'SMTP connection failed: ' . $e->getMessage()];
        }
    }

    // ── IMAP Operations ──────────────────────────────────────────

    /**
     * List messages in a mailbox folder.
     *
     * @param string $folder Folder name (default: INBOX)
     * @param int    $limit  Maximum messages to return
     * @param int    $offset Offset for pagination
     * @return array List of message summary arrays
     */
    public function inbox(string $folder = 'INBOX', int $limit = 20, int $offset = 0): array
    {
        $imap = $this->imapConnect($folder);
        if ($imap === null) {
            return [];
        }

        try {
            $info = imap_check($imap);
            $total = $info->Nmsgs;

            if ($total === 0) {
                imap_close($imap);
                return [];
            }

            // Get messages in reverse order (newest first)
            $start = max(1, $total - $offset - $limit + 1);
            $end = max(1, $total - $offset);

            if ($start > $end) {
                imap_close($imap);
                return [];
            }

            $overview = imap_fetch_overview($imap, "{$start}:{$end}", 0);
            $messages = [];

            if ($overview !== false) {
                // Reverse to get newest first
                $overview = array_reverse($overview);

                foreach ($overview as $msg) {
                    $messages[] = [
                        'uid' => $msg->uid ?? 0,
                        'msgno' => $msg->msgno ?? 0,
                        'subject' => isset($msg->subject) ? $this->decodeMimeHeader($msg->subject) : '',
                        'from' => isset($msg->from) ? $this->decodeMimeHeader($msg->from) : '',
                        'date' => $msg->date ?? '',
                        'seen' => (bool)($msg->seen ?? false),
                        'flagged' => (bool)($msg->flagged ?? false),
                        'size' => $msg->size ?? 0,
                    ];
                }
            }

            imap_close($imap);
            return $messages;
        } catch (\Throwable $e) {
            @imap_close($imap);
            return [];
        }
    }

    /**
     * Read a single message by UID.
     *
     * @param int    $uid      Message UID
     * @param string $folder   Folder name
     * @param bool   $markRead Whether to mark as read
     * @return array|null Message data or null if not found
     */
    public function read(int $uid, string $folder = 'INBOX', bool $markRead = true): ?array
    {
        $imap = $this->imapConnect($folder);
        if ($imap === null) {
            return null;
        }

        try {
            $msgno = imap_msgno($imap, $uid);
            if ($msgno === 0) {
                imap_close($imap);
                return null;
            }

            $header = imap_headerinfo($imap, $msgno);
            $structure = imap_fetchstructure($imap, $uid, FT_UID);

            $body = $this->extractBody($imap, $uid, $structure);
            $attachments = $this->extractAttachments($imap, $uid, $structure);

            if ($markRead) {
                imap_setflag_full($imap, (string)$uid, '\\Seen', ST_UID);
            }

            $result = [
                'uid' => $uid,
                'msgno' => $msgno,
                'subject' => $this->decodeMimeHeader($header->subject ?? ''),
                'from' => $this->formatAddress($header->from ?? []),
                'to' => $this->formatAddress($header->to ?? []),
                'cc' => $this->formatAddress($header->cc ?? []),
                'date' => $header->date ?? '',
                'seen' => (bool)($header->Seen ?? false),
                'flagged' => (bool)($header->Flagged ?? false),
                'body_text' => $body['text'] ?? '',
                'body_html' => $body['html'] ?? '',
                'attachments' => $attachments,
                'message_id' => $header->message_id ?? '',
            ];

            imap_close($imap);
            return $result;
        } catch (\Throwable $e) {
            @imap_close($imap);
            return null;
        }
    }

    /**
     * Get the count of unread messages in a folder.
     *
     * @param string $folder Folder name
     * @return int Number of unread messages
     */
    public function unread(string $folder = 'INBOX'): int
    {
        $imap = $this->imapConnect($folder);
        if ($imap === null) {
            return 0;
        }

        try {
            $status = imap_status($imap, $this->imapMailbox($folder), SA_UNSEEN);
            $count = $status ? $status->unseen : 0;
            imap_close($imap);
            return $count;
        } catch (\Throwable $e) {
            @imap_close($imap);
            return 0;
        }
    }

    /**
     * Search messages in a folder.
     *
     * @param string      $folder     Folder name
     * @param string|null $subject    Subject search term
     * @param string|null $sender     Sender search term
     * @param string|null $since      Date string (e.g. '2024-01-01')
     * @param string|null $before     Date string
     * @param bool        $unseenOnly Only return unseen messages
     * @param int         $limit      Maximum results
     * @return array List of message summary arrays
     */
    public function search(
        string $folder = 'INBOX',
        ?string $subject = null,
        ?string $sender = null,
        ?string $since = null,
        ?string $before = null,
        bool $unseenOnly = false,
        int $limit = 20,
    ): array {
        $imap = $this->imapConnect($folder);
        if ($imap === null) {
            return [];
        }

        try {
            $criteria = [];

            if ($subject !== null) {
                $criteria[] = 'SUBJECT "' . addcslashes($subject, '"\\') . '"';
            }
            if ($sender !== null) {
                $criteria[] = 'FROM "' . addcslashes($sender, '"\\') . '"';
            }
            if ($since !== null) {
                $criteria[] = 'SINCE "' . date('j-M-Y', strtotime($since)) . '"';
            }
            if ($before !== null) {
                $criteria[] = 'BEFORE "' . date('j-M-Y', strtotime($before)) . '"';
            }
            if ($unseenOnly) {
                $criteria[] = 'UNSEEN';
            }

            $searchString = empty($criteria) ? 'ALL' : implode(' ', $criteria);
            $uids = imap_search($imap, $searchString, SE_UID);

            if ($uids === false) {
                imap_close($imap);
                return [];
            }

            // Reverse for newest first, apply limit
            $uids = array_reverse($uids);
            $uids = array_slice($uids, 0, $limit);

            $messages = [];
            foreach ($uids as $uid) {
                $msgno = imap_msgno($imap, $uid);
                if ($msgno === 0) {
                    continue;
                }

                $overview = imap_fetch_overview($imap, (string)$uid, FT_UID);
                if ($overview === false || empty($overview)) {
                    continue;
                }

                $msg = $overview[0];
                $messages[] = [
                    'uid' => $uid,
                    'msgno' => $msg->msgno ?? 0,
                    'subject' => isset($msg->subject) ? $this->decodeMimeHeader($msg->subject) : '',
                    'from' => isset($msg->from) ? $this->decodeMimeHeader($msg->from) : '',
                    'date' => $msg->date ?? '',
                    'seen' => (bool)($msg->seen ?? false),
                    'flagged' => (bool)($msg->flagged ?? false),
                    'size' => $msg->size ?? 0,
                ];
            }

            imap_close($imap);
            return $messages;
        } catch (\Throwable $e) {
            @imap_close($imap);
            return [];
        }
    }

    /**
     * List available IMAP folders.
     *
     * @return array List of folder names
     */
    public function folders(): array
    {
        $imap = $this->imapConnect('INBOX');
        if ($imap === null) {
            return [];
        }

        try {
            $ref = '{' . $this->imapHost . ':' . ($this->imapPort ?? 993) . '/imap/ssl}';
            $list = imap_list($imap, $ref, '*');

            if ($list === false) {
                imap_close($imap);
                return [];
            }

            $folders = [];
            foreach ($list as $folder) {
                // Strip the server prefix
                $name = str_replace($ref, '', $folder);
                $folders[] = $name;
            }

            imap_close($imap);
            return $folders;
        } catch (\Throwable $e) {
            @imap_close($imap);
            return [];
        }
    }

    // ── SMTP Internals ───────────────────────────────────────────

    /**
     * Open a socket connection to the SMTP server.
     *
     * @return resource
     * @throws \RuntimeException On connection failure
     */
    private function connect()
    {
        $address = $this->host . ':' . $this->port;

        // Use SSL wrapper for port 465
        if ($this->port === 465) {
            $address = 'ssl://' . $address;
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $socket = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new \RuntimeException("Cannot connect to SMTP server {$address}: [{$errno}] {$errstr}");
        }

        stream_set_timeout($socket, $this->timeout);

        return $socket;
    }

    /**
     * Send a command and validate the response code.
     *
     * @param resource   $socket  Socket resource
     * @param string     $command SMTP command
     * @param int|array  $expect  Expected response code(s)
     * @return string Full server response
     * @throws \RuntimeException On unexpected response
     */
    private function sendCommand($socket, string $command, int|array $expect): string
    {
        fwrite($socket, $command . "\r\n");
        return $this->readResponse($socket, $expect);
    }

    /**
     * Read and validate the SMTP server response.
     *
     * @param resource  $socket Socket resource
     * @param int|array $expect Expected response code(s)
     * @return string Full response text
     * @throws \RuntimeException On unexpected response
     */
    private function readResponse($socket, int|array $expect): string
    {
        $expectedCodes = is_array($expect) ? $expect : [$expect];
        $response = '';

        while (true) {
            $line = fgets($socket, 4096);
            if ($line === false) {
                throw new \RuntimeException('Lost connection to SMTP server');
            }

            $response .= $line;

            // Check if this is the last line (code followed by space, not hyphen)
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
            if (strlen($line) < 4) {
                break;
            }
        }

        $code = (int)substr($response, 0, 3);

        if (!in_array($code, $expectedCodes, true)) {
            throw new \RuntimeException("SMTP error: expected " . implode('/', $expectedCodes) . ", got {$code}: " . trim($response));
        }

        return $response;
    }

    /**
     * Build a complete RFC 2822 email message.
     *
     * @return string Raw message content
     */
    private function buildMessage(
        array $to,
        string $subject,
        string $body,
        bool $html,
        array $cc,
        ?string $replyTo,
        array $attachments,
        array $headers,
        string $messageId,
    ): string {
        $boundary = 'Tina4_' . bin2hex(random_bytes(16));
        $hasAttachments = !empty($attachments);

        $msg = '';

        // Standard headers
        $fromDisplay = $this->fromName !== null
            ? '=?UTF-8?B?' . base64_encode($this->fromName) . '?= <' . $this->fromAddress . '>'
            : $this->fromAddress;

        $msg .= 'From: ' . $fromDisplay . "\r\n";
        $msg .= 'To: ' . implode(', ', $to) . "\r\n";

        if (!empty($cc)) {
            $msg .= 'Cc: ' . implode(', ', $cc) . "\r\n";
        }

        $msg .= 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n";
        $msg .= 'Date: ' . date('r') . "\r\n";
        $msg .= 'Message-ID: <' . $messageId . ">\r\n";
        $msg .= "MIME-Version: 1.0\r\n";

        if ($replyTo !== null) {
            $msg .= 'Reply-To: ' . $replyTo . "\r\n";
        }

        // Custom headers
        foreach ($headers as $key => $value) {
            $msg .= $key . ': ' . $value . "\r\n";
        }

        if ($hasAttachments) {
            $msg .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . "\r\n";
            $msg .= "\r\n";
            $msg .= '--' . $boundary . "\r\n";

            // Body part
            $contentType = $html ? 'text/html; charset=UTF-8' : 'text/plain; charset=UTF-8';
            $msg .= 'Content-Type: ' . $contentType . "\r\n";
            $msg .= "Content-Transfer-Encoding: base64\r\n";
            $msg .= "\r\n";
            $msg .= chunk_split(base64_encode($body)) . "\r\n";

            // Attachment parts
            foreach ($attachments as $attachment) {
                $msg .= '--' . $boundary . "\r\n";

                if (is_string($attachment)) {
                    // File path
                    if (!is_file($attachment)) {
                        continue;
                    }
                    $filename = basename($attachment);
                    $content = file_get_contents($attachment);
                    $mime = mime_content_type($attachment) ?: 'application/octet-stream';
                } elseif (is_array($attachment)) {
                    $filename = $attachment['filename'] ?? 'attachment';
                    $content = $attachment['content'] ?? '';
                    $mime = $attachment['mime'] ?? 'application/octet-stream';
                } else {
                    continue;
                }

                $msg .= 'Content-Type: ' . $mime . '; name="' . $filename . '"' . "\r\n";
                $msg .= "Content-Transfer-Encoding: base64\r\n";
                $msg .= 'Content-Disposition: attachment; filename="' . $filename . '"' . "\r\n";
                $msg .= "\r\n";
                $msg .= chunk_split(base64_encode($content)) . "\r\n";
            }

            $msg .= '--' . $boundary . "--\r\n";
        } else {
            // Simple message without attachments
            $contentType = $html ? 'text/html; charset=UTF-8' : 'text/plain; charset=UTF-8';
            $msg .= 'Content-Type: ' . $contentType . "\r\n";
            $msg .= "Content-Transfer-Encoding: base64\r\n";
            $msg .= "\r\n";
            $msg .= chunk_split(base64_encode($body));
        }

        return $msg;
    }

    /**
     * Generate a unique message ID.
     */
    private function generateMessageId(): string
    {
        $domain = $this->fromAddress !== null
            ? substr(strrchr($this->fromAddress, '@'), 1)
            : gethostname();

        return bin2hex(random_bytes(8)) . '.' . time() . '@' . $domain;
    }

    /**
     * Extract a bare email address from a display string.
     */
    private function extractAddress(string $address): string
    {
        if (preg_match('/<([^>]+)>/', $address, $matches)) {
            return $matches[1];
        }

        return trim($address);
    }

    // ── IMAP Internals ───────────────────────────────────────────

    /**
     * Open an IMAP connection.
     *
     * @param string $folder Folder name
     * @return resource|null IMAP stream or null on failure
     */
    private function imapConnect(string $folder)
    {
        if (!function_exists('imap_open')) {
            throw new \RuntimeException(
                'IMAP extension is not available. Install php-imap: '
                . 'apt-get install php-imap (Debian/Ubuntu) or '
                . 'yum install php-imap (RHEL/CentOS) or '
                . 'brew install php (macOS with Homebrew)'
            );
        }

        if ($this->imapHost === null) {
            throw new \RuntimeException('IMAP host is required. Set IMAP_HOST env var or pass imapHost to constructor.');
        }

        $mailbox = $this->imapMailbox($folder);

        $imap = @imap_open(
            $mailbox,
            $this->username ?? '',
            $this->password ?? '',
            0,
            1
        );

        if ($imap === false) {
            $errors = imap_errors();
            throw new \RuntimeException('IMAP connection failed: ' . implode('; ', $errors ?: ['Unknown error']));
        }

        return $imap;
    }

    /**
     * Build the IMAP mailbox string.
     */
    private function imapMailbox(string $folder): string
    {
        $port = $this->imapPort ?? 993;
        $flags = $port === 993 ? '/imap/ssl' : '/imap';

        return '{' . $this->imapHost . ':' . $port . $flags . '}' . $folder;
    }

    /**
     * Extract body text and HTML from an IMAP message structure.
     *
     * @param resource $imap      IMAP stream
     * @param int      $uid       Message UID
     * @param object   $structure Message structure
     * @return array ['text' => string, 'html' => string]
     */
    private function extractBody($imap, int $uid, object $structure): array
    {
        $result = ['text' => '', 'html' => ''];

        if (empty($structure->parts)) {
            // Simple message, no parts
            $body = imap_fetchbody($imap, $uid, '1', FT_UID);
            $decoded = $this->decodeBody($body, $structure->encoding ?? 0);

            if (($structure->subtype ?? '') === 'HTML') {
                $result['html'] = $decoded;
            } else {
                $result['text'] = $decoded;
            }

            return $result;
        }

        // Multipart message
        $this->walkParts($imap, $uid, $structure->parts, '1', $result);

        return $result;
    }

    /**
     * Recursively walk MIME parts to extract text and HTML bodies.
     */
    private function walkParts($imap, int $uid, array $parts, string $prefix, array &$result): void
    {
        foreach ($parts as $index => $part) {
            $partNumber = $prefix === '' ? (string)($index + 1) : $prefix . '.' . ($index + 1);

            // First level uses simple numbering
            if ($prefix === '1' && $index === 0) {
                $partNumber = '1';
            } elseif ($prefix === '1') {
                $partNumber = (string)($index + 1);
            }

            $type = $part->type ?? 0;
            $subtype = strtoupper($part->subtype ?? '');

            if ($type === 0) { // TEXT
                $body = imap_fetchbody($imap, $uid, (string)($index + 1), FT_UID);
                $decoded = $this->decodeBody($body, $part->encoding ?? 0);

                if ($subtype === 'HTML' && $result['html'] === '') {
                    $result['html'] = $decoded;
                } elseif ($subtype === 'PLAIN' && $result['text'] === '') {
                    $result['text'] = $decoded;
                }
            } elseif ($type === 1 && !empty($part->parts)) { // MULTIPART
                $this->walkParts($imap, $uid, $part->parts, (string)($index + 1), $result);
            }
        }
    }

    /**
     * Extract attachment metadata from an IMAP message structure.
     *
     * @return array List of attachment info arrays
     */
    private function extractAttachments($imap, int $uid, object $structure): array
    {
        $attachments = [];

        if (empty($structure->parts)) {
            return $attachments;
        }

        foreach ($structure->parts as $index => $part) {
            $disposition = '';
            if (!empty($part->disposition)) {
                $disposition = strtoupper($part->disposition);
            }

            if ($disposition === 'ATTACHMENT' || ($part->type ?? 0) >= 3) {
                $filename = 'attachment_' . ($index + 1);

                // Try to find filename from parameters
                if (!empty($part->dparameters)) {
                    foreach ($part->dparameters as $param) {
                        if (strtoupper($param->attribute) === 'FILENAME') {
                            $filename = $this->decodeMimeHeader($param->value);
                            break;
                        }
                    }
                }
                if (!empty($part->parameters)) {
                    foreach ($part->parameters as $param) {
                        if (strtoupper($param->attribute) === 'NAME') {
                            $filename = $this->decodeMimeHeader($param->value);
                            break;
                        }
                    }
                }

                $attachments[] = [
                    'filename' => $filename,
                    'size' => $part->bytes ?? 0,
                    'mime' => $this->mimeTypeFromPart($part),
                    'part_number' => $index + 1,
                ];
            }
        }

        return $attachments;
    }

    /**
     * Decode an encoded IMAP body part.
     */
    private function decodeBody(string $body, int $encoding): string
    {
        return match ($encoding) {
            0 => $body,                            // 7BIT
            1 => $body,                            // 8BIT
            2 => $body,                            // BINARY
            3 => base64_decode($body),             // BASE64
            4 => quoted_printable_decode($body),   // QUOTED-PRINTABLE
            default => $body,
        };
    }

    /**
     * Decode a MIME-encoded header string.
     */
    private function decodeMimeHeader(string $header): string
    {
        if (!function_exists('imap_mime_header_decode')) {
            return $header;
        }

        $parts = imap_mime_header_decode($header);
        $decoded = '';

        foreach ($parts as $part) {
            $charset = strtoupper($part->charset);
            if ($charset === 'DEFAULT' || $charset === 'UTF-8') {
                $decoded .= $part->text;
            } else {
                $converted = @iconv($charset, 'UTF-8//IGNORE', $part->text);
                $decoded .= $converted !== false ? $converted : $part->text;
            }
        }

        return $decoded;
    }

    /**
     * Format IMAP address objects into a readable string.
     *
     * @param array $addresses Array of address objects
     * @return string Formatted address string
     */
    private function formatAddress(array $addresses): string
    {
        $result = [];

        foreach ($addresses as $addr) {
            $mailbox = $addr->mailbox ?? '';
            $host = $addr->host ?? '';
            $personal = $addr->personal ?? '';

            $email = $mailbox . '@' . $host;

            if ($personal !== '') {
                $result[] = $this->decodeMimeHeader($personal) . ' <' . $email . '>';
            } else {
                $result[] = $email;
            }
        }

        return implode(', ', $result);
    }

    /**
     * Determine MIME type from an IMAP part structure.
     */
    private function mimeTypeFromPart(object $part): string
    {
        $types = ['TEXT', 'MULTIPART', 'MESSAGE', 'APPLICATION', 'AUDIO', 'IMAGE', 'VIDEO', 'MODEL', 'OTHER'];
        $type = $types[$part->type ?? 8] ?? 'OTHER';
        $subtype = $part->subtype ?? 'OCTET-STREAM';

        return strtolower($type . '/' . $subtype);
    }

    /**
     * Get an environment variable value, checking multiple sources.
     */
    private function env(string $key): ?string
    {
        // Check DotEnv first if available
        if (class_exists(DotEnv::class) && method_exists(DotEnv::class, 'getEnv')) {
            $value = DotEnv::getEnv($key);
            if ($value !== null) {
                return $value;
            }
        }

        // Fall back to getenv
        $value = getenv($key);
        return $value !== false ? $value : null;
    }
}
