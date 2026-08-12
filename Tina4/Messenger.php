<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Messenger — Email sending (SMTP) and reading (IMAP), zero external dependencies.
 * Uses raw socket communication for SMTP and PHP's imap_* extension for reading.
 *
 * Unified .env-driven configuration with constructor override.
 * Priority: constructor params > .env > sensible defaults
 *
 *   # .env
 *   TINA4_MAIL_HOST=smtp.gmail.com
 *   TINA4_MAIL_PORT=587
 *   TINA4_MAIL_USERNAME=user@gmail.com
 *   TINA4_MAIL_PASSWORD=app-password
 *   TINA4_MAIL_FROM=noreply@myapp.com
 *   TINA4_MAIL_ENCRYPTION=tls
 *   TINA4_MAIL_IMAP_HOST=imap.gmail.com
 *   TINA4_MAIL_IMAP_PORT=993
 *
 *   $mail = new Messenger();                                    // reads from .env
 *   $mail = new Messenger(host: "smtp.office365.com", port: 587);  // override
 *   $mail->send("user@test.com", "Welcome", "<h1>Hello!</h1>", html: true, text: "Hello!");
 */

namespace Tina4;

class Messenger
{
    /** @var bool Whether an SMTP host was actually configured (see the constructor) */
    private bool $smtpConfigured = false;

    /** @var DevMailbox|null The local mailbox, present only when this messenger captures */
    public ?DevMailbox $devMailbox = null;

    /** @var string SMTP host */
    private string $host;

    /** @var int SMTP port */
    private int $port;

    /** @var string SMTP username */
    private string $username;

    /** @var string SMTP password */
    private string $password;

    /** @var string Sender email address */
    private string $fromAddress;

    /** @var string|null Sender display name */
    private ?string $fromName;

    /** @var string Encryption mode: tls, ssl, starttls, none */
    private string $encryption;

    /** @var bool Whether to use STARTTLS (derived from encryption) */
    private bool $useTls;

    /** @var string|null IMAP host */
    private ?string $imapHost;

    /** @var int IMAP port */
    private int $imapPort;

    /** @var string IMAP username — may differ from the SMTP username */
    private string $imapUsername;

    /** @var string IMAP password — may differ from the SMTP password */
    private string $imapPassword;

    /** @var string IMAP encryption mode: 'tls', 'starttls', or 'none' */
    private string $imapEncryption;

    /** @var int Socket timeout in seconds */
    private int $timeout = 30;

    /**
     * Create a Messenger instance.
     *
     * Priority: constructor params > .env (TINA4_MAIL_* with SMTP_* fallback) > sensible defaults
     *
     * @param string|null $host        SMTP server hostname
     * @param int|null    $port        SMTP server port (587 for TLS, 465 for SSL, 25 for plain)
     * @param string|null $username    SMTP authentication username
     * @param string|null $password    SMTP authentication password
     * @param string|null $fromAddress Default sender email address
     * @param string|null $fromName    Default sender display name
     * @param string|null $encryption  Encryption mode: tls, ssl, starttls, none (default: tls)
     * @param bool|null   $useTls      Deprecated — use $encryption instead
     * @param string|null $imapHost     IMAP server hostname
     * @param int|null    $imapPort     IMAP server port (993 for SSL, 143 for plain)
     * @param string|null $imapUsername IMAP username (defaults to TINA4_MAIL_IMAP_USERNAME, then the SMTP username)
     * @param string|null $imapPassword IMAP password (defaults to TINA4_MAIL_IMAP_PASSWORD, then the SMTP password)
     * @param string|null $imapEncryption IMAP encryption: tls, starttls, none (explicit beats env; default port-aware)
     */
    public function __construct(
        ?string $host = null,
        ?int $port = null,
        ?string $username = null,
        ?string $password = null,
        ?string $fromAddress = null,
        ?string $fromName = null,
        ?string $encryption = null,
        ?bool $useTls = null,
        ?string $imapHost = null,
        ?int $imapPort = null,
        ?string $imapUsername = null,
        ?string $imapPassword = null,
        ?string $imapEncryption = null,
    ) {
        // Whether a host was actually CONFIGURED, which is not the same as $this->host
        // being set: it falls back to 'localhost', so it is never empty and cannot
        // answer "can this messenger send?". The dev-capture gate needs that answer,
        // so record it here while the real inputs are still in scope.
        $this->smtpConfigured = ($host ?? $this->env('TINA4_MAIL_HOST')) !== null;

        // SMTP — priority: constructor > TINA4_MAIL_* > default
        $this->host = $host
            ?? $this->env('TINA4_MAIL_HOST')
            ?? 'localhost';

        $envPort = $this->env('TINA4_MAIL_PORT');
        $this->port = $port ?? ($envPort !== null ? (int)$envPort : 587);

        $this->username = $username
            ?? $this->env('TINA4_MAIL_USERNAME')
            ?? '';

        $this->password = $password
            ?? $this->env('TINA4_MAIL_PASSWORD')
            ?? '';

        $resolvedFrom = $fromAddress
            ?? $this->env('TINA4_MAIL_FROM');
        $this->fromAddress = $resolvedFrom ?? ($this->username ?: 'noreply@localhost');

        $this->fromName = $fromName
            ?? $this->env('TINA4_MAIL_FROM_NAME');

        // Encryption: constructor > .env > backward-compat useTls > default "tls"
        $envEncryption = $encryption
            ?? $this->env('TINA4_MAIL_ENCRYPTION');
        if ($envEncryption !== null) {
            $this->encryption = strtolower($envEncryption);
        } elseif ($useTls !== null) {
            $this->encryption = $useTls ? 'tls' : 'none';
        } else {
            $this->encryption = 'tls';
        }
        $this->useTls = in_array($this->encryption, ['tls', 'starttls'], true);

        // IMAP
        $this->imapHost = $imapHost
            ?? $this->env('TINA4_MAIL_IMAP_HOST');

        $envImapPort = $this->env('TINA4_MAIL_IMAP_PORT');
        $this->imapPort = $imapPort ?? ($envImapPort !== null ? (int)$envImapPort : 993);

        // IMAP credentials — SEPARATE from SMTP. A mailbox that authenticates
        // differently from the SMTP relay (common at most providers) must read
        // the right account. Priority: constructor > TINA4_MAIL_IMAP_* >
        // TINA4_MAIL_* (the SMTP username/password, already resolved above).
        $this->imapUsername = $imapUsername
            ?? $this->env('TINA4_MAIL_IMAP_USERNAME')
            ?? $this->username;
        $this->imapPassword = $imapPassword
            ?? $this->env('TINA4_MAIL_IMAP_PASSWORD')
            ?? $this->password;

        // IMAP encryption — 'tls' (implicit TLS/IMAPS), 'starttls', or 'none'.
        // Independent of SMTP encryption (Gmail-style setups use IMAPS on 993
        // while SMTP runs STARTTLS on 587). Priority: constructor > env >
        // port-aware default. The port-aware default (993 = tls, else none)
        // reproduces the historical port-based flag selection, so a caller that
        // never sets it keeps today's exact connection behaviour; an explicit
        // value now wins (ADR-0041) and is honoured by imapMailbox().
        $explicitImapEnc = $imapEncryption ?? $this->env('TINA4_MAIL_IMAP_ENCRYPTION');
        if ($explicitImapEnc !== null && $explicitImapEnc !== '') {
            $enc = strtolower($explicitImapEnc);
            $this->imapEncryption = in_array($enc, ['tls', 'starttls', 'none'], true) ? $enc : 'tls';
        } else {
            $this->imapEncryption = $this->imapPort === 993 ? 'tls' : 'none';
        }
    }

    /**
     * Resolved IMAP encryption mode after construction. Useful for tests.
     */
    public function getImapEncryption(): string
    {
        return $this->imapEncryption;
    }

    /**
     * Send an email via raw SMTP socket.
     *
     * @param string|array $to          Recipient(s)
     * @param string       $subject     Email subject
     * @param string       $body        Email body content
     * @param bool         $html        Whether the body is HTML
     * @param string|null  $text        Plain text alternative (when body is HTML)
     * @param array|string $cc          CC recipients
     * @param array|string $bcc         BCC recipients
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
        ?string $text = null,
        array|string $cc = [],
        array|string $bcc = [],
        ?string $replyTo = null,
        array $attachments = [],
        array $headers = [],
    ): array {

        $recipients = is_array($to) ? $to : [$to];
        $ccList = is_array($cc) ? $cc : ($cc ? [$cc] : []);
        $bccList = is_array($bcc) ? $bcc : ($bcc ? [$bcc] : []);

        // Dev capture is a BRANCH here. PHP previously had NO interception at all:
        // createMessenger() was `return new static()`, so on a box with no SMTP host
        // this opened a socket to localhost:587 and failed. DevMailbox existed and
        // was unreachable from the factory.
        if ($this->shouldCapture()) {
            return $this->devMailbox()->capture(
                $to,
                $subject,
                $body,
                $html,
                $text,
                $ccList,
                $bccList,
                $replyTo,
                $attachments,
                $headers
            );
        }

        // TINA4_MAIL_REDIRECT_TO (MAIL-DEC-01): the REAL-SEND path only --
        // capture already returned above, so this never touches the capture
        // branch. When the list is non-empty, replace every recipient with the
        // redirect list (so ONLY the dev list receives the mail, never the real
        // recipients) and preserve the originals in a header. Subject/body/
        // attachments are untouched; send()'s return shape is unchanged. Read
        // fresh per call, matching shouldCapture()'s own env-read style.
        $redirectTo = $this->parseMailRedirectList($this->env('TINA4_MAIL_REDIRECT_TO'));
        if (!empty($redirectTo)) {
            $originalTo = implode(', ', array_merge($recipients, $ccList, $bccList));
            $recipients = $redirectTo;
            $ccList = [];
            $bccList = [];
            $headers['X-Tina4-Original-To'] = $originalTo;
        }

        $allRecipients = array_merge($recipients, $ccList, $bccList);

        if (empty($allRecipients)) {
            return ['success' => false, 'message' => 'At least one recipient is required', 'id' => null];
        }

        $messageId = $this->generateMessageId();
        $rawMessage = $this->buildMessage($recipients, $subject, $body, $html, $ccList, $replyTo, $attachments, $headers, $messageId, $text);

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
     * Send an HTML email rendered from a Frond template string.
     *
     * The template is rendered with the built-in Frond engine; if rendering
     * fails the raw template is sent (and the failure is logged) rather than
     * dropping the mail. Extra send options (cc/bcc/reply-to/attachments/
     * headers) forward to send(). Parity with the Python master's send_template.
     *
     * @param string|array $to          Recipient(s)
     * @param string       $subject     Email subject
     * @param string       $template    Frond/Twig template source
     * @param array        $data        Template variables
     * @param array|string $cc          CC recipients
     * @param array|string $bcc         BCC recipients
     * @param string|null  $replyTo     Reply-to address
     * @param array        $attachments Attachments (paths or filename/content/mime arrays)
     * @param array        $headers     Additional headers as key => value
     * @return array ['success' => bool, 'message' => string, 'id' => string|null]
     */
    public function sendTemplate(
        string|array $to,
        string $subject,
        string $template,
        array $data = [],
        array|string $cc = [],
        array|string $bcc = [],
        ?string $replyTo = null,
        array $attachments = [],
        array $headers = [],
    ): array {
        $body = $this->renderTemplate($template, $data);

        return $this->send(
            to: $to,
            subject: $subject,
            body: $body,
            html: true,
            text: null,
            cc: $cc,
            bcc: $bcc,
            replyTo: $replyTo,
            attachments: $attachments,
            headers: $headers,
        );
    }

    /**
     * Render a Frond template string, falling back to the raw template when the
     * engine is unavailable or the render fails (mail must not be dropped).
     *
     * @param string $template Template source
     * @param array  $data     Template variables
     * @return string Rendered body (or the raw template on failure)
     */
    private function renderTemplate(string $template, array $data): string
    {
        if (class_exists(Frond::class)) {
            try {
                return (new Frond())->renderString($template, $data);
            } catch (\Throwable $e) {
                Log::warning('Messenger sendTemplate: template render failed, sending raw template: ' . $e->getMessage());
            }
        }
        return $template;
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
     * List messages in a mailbox folder, newest first.
     *
     * Each item carries the cross-framework settled shape (all four frameworks):
     *   uid (string), subject (string), from (string), to (string),
     *   date (ISO-8601 string), snippet (string), seen (bool).
     *
     * @param string $folder Folder name (default: INBOX)
     * @param int    $limit  Maximum messages to return
     * @param int    $offset Offset for pagination
     * @return array<int, array{uid: string, subject: string, from: string, to: string, date: string, snippet: string, seen: bool}>
     */
    public function inbox(string $folder = 'INBOX', int $limit = 20, int $offset = 0): array
    {
        try {
            $imap = $this->imapConnect($folder);
        } catch (\Throwable $e) {
            throw $this->imapFail('inbox', $e);
        }

        try {
            $info = imap_check($imap);
            if ($info === false) {
                throw new MessengerConnectionError("IMAP check failed for folder '{$folder}'");
            }
            $total = $info->Nmsgs;

            if ($total === 0) {
                return [];
            }

            // Get messages in reverse order (newest first)
            $start = max(1, $total - $offset - $limit + 1);
            $end = max(1, $total - $offset);

            if ($start > $end) {
                return [];
            }

            $overview = imap_fetch_overview($imap, "{$start}:{$end}", 0);
            if ($overview === false) {
                throw new MessengerConnectionError("IMAP fetch overview failed for folder '{$folder}'");
            }

            $messages = [];

            // Reverse to get newest first
            $overview = array_reverse($overview);

            foreach ($overview as $msg) {
                $messages[] = $this->summaryItem($imap, $msg);
            }

            return $messages;
        } catch (\Throwable $e) {
            throw $this->imapFail('inbox', $e);
        } finally {
            @imap_close($imap);
        }
    }

    /**
     * Read a single message by UID.
     *
     * Returns the settled read() shape: uid, subject, from, to, cc, date
     * (ISO-8601), body_text, body_html, attachments, headers (name => value
     * map). A genuinely missing UID returns null (a successful fetch with no
     * match, NOT an error).
     *
     * @param string|int $uid  Message UID
     * @param string $folder   Folder name
     * @param bool   $markRead Whether to mark as read
     * @return array<string, mixed>|null Message data or null if not found
     */
    public function read(string|int $uid, string $folder = 'INBOX', bool $markRead = true): ?array
    {
        try {
            $imap = $this->imapConnect($folder);
        } catch (\Throwable $e) {
            throw $this->imapFail('read', $e);
        }

        try {
            $msgno = imap_msgno($imap, $uid);
            if ($msgno === 0) {
                // Genuinely missing UID — a successful fetch with no match, NOT an error.
                return null;
            }

            $header = imap_headerinfo($imap, $msgno);
            $structure = imap_fetchstructure($imap, $uid, FT_UID);
            if ($header === false || $structure === false) {
                throw new MessengerConnectionError("IMAP header/structure fetch failed for uid {$uid}");
            }

            $body = $this->extractBody($imap, $uid, $structure);
            $attachments = $this->extractAttachments($imap, $uid, $structure);

            // Full header map (name => value), parity with the Python master's
            // headers: dict(msg.items()). Peeked so reading the header block
            // never flips \Seen ahead of the explicit markRead below.
            $rawHeaders = imap_fetchheader($imap, $uid, FT_UID);
            $headers = $this->parseHeaders($rawHeaders === false ? '' : $rawHeaders);

            if ($markRead) {
                imap_setflag_full($imap, (string)$uid, '\\Seen', ST_UID);
            }

            // EXACTLY the 10 canonical read() keys, no more (ADR-0042, issue #70).
            // The IMAP SEQUENCE NUMBER ($msgno) stays usable INTERNALLY to fetch the
            // header above, but ADR-0042 forbids exposing it as a public id, so it is
            // NOT a key here; message_id lives in `headers` (dropped as a duplicate);
            // and seen/flagged are inbox()-listing concerns, not read() fields.
            return [
                'uid' => (string)$uid,
                'subject' => $this->decodeMimeHeader($header->subject ?? ''),
                'from' => $this->formatAddress($header->from ?? []),
                'to' => $this->formatAddress($header->to ?? []),
                'cc' => $this->formatAddress($header->cc ?? []),
                'date' => $this->toIso8601((string)($header->date ?? '')),
                'body_text' => $body['text'] ?? '',
                'body_html' => $body['html'] ?? '',
                'attachments' => $attachments,
                'headers' => $headers,
            ];
        } catch (\Throwable $e) {
            throw $this->imapFail('read', $e);
        } finally {
            @imap_close($imap);
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
        try {
            $imap = $this->imapConnect($folder);
        } catch (\Throwable $e) {
            throw $this->imapFail('unread', $e);
        }

        try {
            $status = imap_status($imap, $this->imapMailbox($folder), SA_UNSEEN);
            if ($status === false) {
                throw new MessengerConnectionError("IMAP status failed for folder '{$folder}'");
            }
            // A successful query with no unseen messages returns 0 (not an error).
            return $status->unseen ?? 0;
        } catch (\Throwable $e) {
            throw $this->imapFail('unread', $e);
        } finally {
            @imap_close($imap);
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
        try {
            $imap = $this->imapConnect($folder);
        } catch (\Throwable $e) {
            throw $this->imapFail('search', $e);
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

            // A successful search with no matches returns false in PHP — that is
            // an empty result, NOT a connection/protocol failure (those fail at
            // imapConnect() above and re-raise via imapFail()).
            if ($uids === false) {
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
                $messages[] = $this->summaryItem($imap, $msg);
            }

            return $messages;
        } catch (\Throwable $e) {
            throw $this->imapFail('search', $e);
        } finally {
            @imap_close($imap);
        }
    }

    /**
     * List available IMAP folders.
     *
     * @return array List of folder names
     */
    public function folders(): array
    {
        try {
            $imap = $this->imapConnect('INBOX');
        } catch (\Throwable $e) {
            throw $this->imapFail('folders', $e);
        }

        try {
            $ref = '{' . $this->imapHost . ':' . ($this->imapPort ?? 993) . '/imap/ssl}';
            $list = imap_list($imap, $ref, '*');

            // A connected server always exposes at least INBOX — false here is a
            // list/protocol failure, so fail loud (mirrors Python's non-OK list).
            if ($list === false) {
                throw new MessengerConnectionError('IMAP list failed');
            }

            $folders = [];
            foreach ($list as $folder) {
                // Strip the server prefix
                $name = str_replace($ref, '', $folder);
                $folders[] = $name;
            }

            return $folders;
        } catch (\Throwable $e) {
            throw $this->imapFail('folders', $e);
        } finally {
            @imap_close($imap);
        }
    }

    /**
     * Mark a message as read (set \Seen flag).
     *
     * @param string|int $uid Message UID
     * @param string $folder IMAP folder name
     */
    public function markRead(string|int $uid, string $folder = 'INBOX'): void
    {
        $imap = $this->imapConnect($folder);

        try {
            imap_setflag_full($imap, (string)$uid, '\\Seen', ST_UID);
        } finally {
            @imap_close($imap);
        }
    }

    /**
     * Mark a message as unread (clear the \Seen flag).
     *
     * @param string|int $uid    Message UID
     * @param string     $folder IMAP folder name
     * @throws MessengerConnectionError On a connection/auth/protocol failure
     */
    public function markUnread(string|int $uid, string $folder = 'INBOX'): void
    {
        $imap = $this->imapConnect($folder);

        try {
            imap_clearflag_full($imap, (string)$uid, '\\Seen', ST_UID);
        } finally {
            @imap_close($imap);
        }
    }

    /**
     * Delete a message: flag it \Deleted and expunge the folder.
     *
     * @param string|int $uid    Message UID
     * @param string     $folder IMAP folder name
     * @throws MessengerConnectionError On a connection/auth/protocol failure
     */
    public function delete(string|int $uid, string $folder = 'INBOX'): void
    {
        $imap = $this->imapConnect($folder);

        try {
            imap_setflag_full($imap, (string)$uid, '\\Deleted', ST_UID);
            imap_expunge($imap);
        } finally {
            @imap_close($imap);
        }
    }

    /**
     * Test IMAP connectivity without reading messages.
     *
     * @return array{success: bool, message: string}
     */
    public function testImapConnection(): array
    {
        try {
            $imap = $this->imapConnect('INBOX');
            if ($imap === null) {
                return ['success' => false, 'message' => 'IMAP connection failed'];
            }
            imap_close($imap);
            return ['success' => true, 'message' => "Connected to {$this->imapHost}:{$this->imapPort}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'IMAP connection failed: ' . $e->getMessage()];
        }
    }

    /**
     * Determine whether send() should capture locally instead of talking to SMTP.
     *
     * Availability decides, not verbosity. With no SMTP host configured sending is
     * impossible, so simulate it into a folder rather than failing -- that is what
     * makes a laptop with no mail server usable. TINA4_MAIL_CAPTURE forces capture
     * even when a host IS configured, for "never send real mail from this box".
     *
     * TINA4_DEBUG deliberately does NOT gate this. Debug must still be able to send:
     * tying capture to it means nobody can test a real send from a dev box.
     *
     * @return bool True when the message should be captured locally
     */
    private function shouldCapture(): bool
    {
        $forced = $this->env('TINA4_MAIL_CAPTURE');
        if ($forced !== null && in_array(strtolower($forced), ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        return !$this->smtpConfigured;
    }

    /**
     * Parse TINA4_MAIL_REDIRECT_TO (MAIL-DEC-01): comma-separated addresses,
     * each trimmed, blanks dropped. Empty/unset -> [] (redirect off, no
     * behaviour change).
     *
     * @param string|null $raw The raw env value
     * @return string[] The redirect address list
     */
    private function parseMailRedirectList(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $addresses = [];
        foreach (explode(',', $raw) as $address) {
            $trimmed = trim($address);
            if ($trimmed !== '') {
                $addresses[] = $trimmed;
            }
        }
        return $addresses;
    }

    /**
     * The local mailbox, created on first capture and reused after.
     *
     * @return DevMailbox The dev mailbox this messenger captures into
     */
    private function devMailbox(): DevMailbox
    {
        if ($this->devMailbox === null) {
            $this->devMailbox = new DevMailbox();
        }
        return $this->devMailbox;
    }

    /**
     * Factory that creates a Messenger from the current environment.
     *
     * Returns ONE concrete type, always. When sending is impossible (no SMTP host)
     * or suppressed (TINA4_MAIL_CAPTURE), send() captures into a local DevMailbox
     * instead, decided by a branch inside send() -- so the object you get back has
     * one send() with one signature either way.
     *
     * @return static
     */
    public static function createMessenger(): static
    {
        $messenger = new static();

        // Attach the mailbox eagerly when this messenger will capture, so callers
        // (and the dev dashboard) can inspect it before the first send.
        if ($messenger->shouldCapture()) {
            $messenger->devMailbox = new DevMailbox();
        }

        return $messenger;
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
        ?string $text = null,
    ): string {
        $boundary = 'Tina4_' . bin2hex(random_bytes(16));
        $altBoundary = 'Tina4Alt_' . bin2hex(random_bytes(16));
        $hasAttachments = !empty($attachments);
        $hasTextAlt = $text !== null && $html;

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

            // Body part (with optional text alternative)
            if ($hasTextAlt) {
                $msg .= 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"' . "\r\n";
                $msg .= "\r\n";
                $msg .= '--' . $altBoundary . "\r\n";
                $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $msg .= "Content-Transfer-Encoding: base64\r\n";
                $msg .= "\r\n";
                $msg .= chunk_split(base64_encode($text)) . "\r\n";
                $msg .= '--' . $altBoundary . "\r\n";
                $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
                $msg .= "Content-Transfer-Encoding: base64\r\n";
                $msg .= "\r\n";
                $msg .= chunk_split(base64_encode($body)) . "\r\n";
                $msg .= '--' . $altBoundary . "--\r\n";
            } else {
                $contentType = $html ? 'text/html; charset=UTF-8' : 'text/plain; charset=UTF-8';
                $msg .= 'Content-Type: ' . $contentType . "\r\n";
                $msg .= "Content-Transfer-Encoding: base64\r\n";
                $msg .= "\r\n";
                $msg .= chunk_split(base64_encode($body)) . "\r\n";
            }

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
        } elseif ($hasTextAlt) {
            // Text alternative without attachments
            $msg .= 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"' . "\r\n";
            $msg .= "\r\n";
            $msg .= '--' . $altBoundary . "\r\n";
            $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $msg .= "Content-Transfer-Encoding: base64\r\n";
            $msg .= "\r\n";
            $msg .= chunk_split(base64_encode($text)) . "\r\n";
            $msg .= '--' . $altBoundary . "\r\n";
            $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
            $msg .= "Content-Transfer-Encoding: base64\r\n";
            $msg .= "\r\n";
            $msg .= chunk_split(base64_encode($body)) . "\r\n";
            $msg .= '--' . $altBoundary . "--\r\n";
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
     * Log an IMAP connection/protocol failure and return the error to throw.
     *
     * Re-uses a MessengerConnectionError as-is; otherwise wraps the underlying
     * error so callers can `throw $this->imapFail(__FUNCTION__, $e);`. Logging
     * happens here so a fail-loud read still leaves an audit trail. Mirrors the
     * Python master's _imap_fail().
     *
     * @param string     $method The IMAP method that failed (for the log line)
     * @param \Throwable $exc    The underlying error
     */
    private function imapFail(string $method, \Throwable $exc): MessengerConnectionError
    {
        Log::error("IMAP {$method} failed: " . $exc->getMessage());

        if ($exc instanceof MessengerConnectionError) {
            return $exc;
        }

        return new MessengerConnectionError("IMAP {$method} failed: " . $exc->getMessage(), 0, $exc);
    }

    /**
     * Open an IMAP connection.
     *
     * @param string $folder Folder name
     * @return resource IMAP stream
     * @throws MessengerConnectionError On connection/auth/protocol failure
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
            throw new MessengerConnectionError('IMAP host is required. Set TINA4_MAIL_IMAP_HOST env var or pass imapHost to constructor.');
        }

        $mailbox = $this->imapMailbox($folder);

        $imap = @imap_open(
            $mailbox,
            $this->imapUsername,
            $this->imapPassword,
            0,
            1
        );

        if ($imap === false) {
            $errors = imap_errors();
            throw new MessengerConnectionError('IMAP connection failed: ' . implode('; ', $errors ?: ['Unknown error']));
        }

        return $imap;
    }

    /**
     * Build the IMAP mailbox string.
     */
    private function imapMailbox(string $folder): string
    {
        $port = $this->imapPort ?? 993;

        return '{' . $this->imapHost . ':' . $port . $this->imapFlags() . '}' . $folder;
    }

    /**
     * The imap_open connection flags for the resolved encryption mode:
     *   tls (default) -> /imap/ssl (implicit TLS / IMAPS)
     *   starttls      -> /imap/tls (negotiate STARTTLS)
     *   none          -> /imap     (plain, no TLS)
     *
     * The port-aware default set in the constructor makes the no-explicit-value
     * case reproduce the historical port-based selection exactly.
     */
    private function imapFlags(): string
    {
        return match ($this->imapEncryption) {
            'starttls' => '/imap/tls',
            'none'     => '/imap',
            default    => '/imap/ssl',
        };
    }

    /**
     * Extract body text and HTML from an IMAP message structure.
     *
     * @param resource $imap      IMAP stream
     * @param int      $uid       Message UID
     * @param object   $structure Message structure
     * @param bool     $peek      When true, fetch with FT_PEEK so reading the
     *                            body does NOT set \Seen (used for snippets on a
     *                            listing, which must not mutate the mailbox).
     * @return array ['text' => string, 'html' => string]
     */
    private function extractBody($imap, int $uid, object $structure, bool $peek = false): array
    {
        $result = ['text' => '', 'html' => ''];
        $flags = FT_UID | ($peek ? FT_PEEK : 0);

        if (empty($structure->parts)) {
            // Simple message, no parts
            $body = imap_fetchbody($imap, $uid, '1', $flags);
            $decoded = $this->decodeBody($body === false ? '' : $body, $structure->encoding ?? 0);

            if (($structure->subtype ?? '') === 'HTML') {
                $result['html'] = $decoded;
            } else {
                $result['text'] = $decoded;
            }

            return $result;
        }

        // Multipart message
        $this->walkParts($imap, $uid, $structure->parts, '1', $result, $peek);

        return $result;
    }

    /**
     * Recursively walk MIME parts to extract text and HTML bodies.
     *
     * @param bool $peek When true, fetch with FT_PEEK (does not set \Seen).
     */
    private function walkParts($imap, int $uid, array $parts, string $prefix, array &$result, bool $peek = false): void
    {
        $flags = FT_UID | ($peek ? FT_PEEK : 0);

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
                $body = imap_fetchbody($imap, $uid, (string)($index + 1), $flags);
                $decoded = $this->decodeBody($body === false ? '' : $body, $part->encoding ?? 0);

                if ($subtype === 'HTML' && $result['html'] === '') {
                    $result['html'] = $decoded;
                } elseif ($subtype === 'PLAIN' && $result['text'] === '') {
                    $result['text'] = $decoded;
                }
            } elseif ($type === 1 && !empty($part->parts)) { // MULTIPART
                $this->walkParts($imap, $uid, $part->parts, (string)($index + 1), $result, $peek);
            }
        }
    }

    /**
     * Extract attachments — WITH their raw decoded bytes — from an IMAP message.
     *
     * Each item is the canonical shape (issue #69, ADR-0042):
     * {filename, content_type, size, content}, where `content` is the RAW DECODED
     * bytes (base64 / quoted-printable transfer-decoded back to the original
     * bytes — the same convention as an uploaded file's `content`) and `size` is
     * that byte length. This makes an attachment downloadable straight from
     * read(), matching the Python master's per-attachment `content`.
     *
     * @param resource $imap      IMAP stream (for the per-part body fetch)
     * @param int      $uid       Message UID
     * @param object   $structure imap_fetchstructure() result
     * @return array<int, array{filename: string, content_type: string, size: int, content: string}>
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

                // Fetch this top-level part's body and transfer-decode it to the
                // real attachment bytes. The top-level part number is $index + 1,
                // the same addressing walkParts uses for the body parts.
                $rawPart = imap_fetchbody($imap, $uid, (string)($index + 1), FT_UID);
                $content = $this->decodeBody($rawPart === false ? '' : $rawPart, $part->encoding ?? 0);

                $attachments[] = [
                    'filename' => $filename,
                    'content_type' => $this->mimeTypeFromPart($part),
                    'size' => strlen($content),
                    'content' => $content,
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
     * Build the cross-framework inbox()/search() summary item for one overview
     * message: {uid, subject, from, to, date (ISO-8601), snippet, seen}. The
     * exact field set, names and order are shared across all four frameworks.
     *
     * @param resource $imap IMAP stream (for the snippet body fetch)
     * @param object   $msg  An imap_fetch_overview row
     * @return array{uid: string, subject: string, from: string, to: string, date: string, snippet: string, seen: bool}
     */
    private function summaryItem($imap, object $msg): array
    {
        // uid is a STRING everywhere (Python emits str(uid)); an int here would
        // change the JSON shape ("uid": 1 vs "1") between frameworks.
        $uid = (string)($msg->uid ?? '');

        return [
            'uid' => $uid,
            'subject' => isset($msg->subject) ? $this->decodeMimeHeader($msg->subject) : '',
            'from' => isset($msg->from) ? $this->decodeMimeHeader($msg->from) : '',
            'to' => isset($msg->to) ? $this->decodeMimeHeader($msg->to) : '',
            'date' => $this->toIso8601((string)($msg->date ?? '')),
            'snippet' => $uid !== '' ? $this->snippetFor($imap, (int)$uid) : '',
            'seen' => (bool)($msg->seen ?? false),
        ];
    }

    /**
     * A decoded, transfer-decoded, tag-stripped plain-text preview of a message
     * body, truncated to 200 characters — the shared `snippet` field. Fetched
     * with FT_PEEK so building a listing never flips \Seen. A fetch failure
     * degrades to '' rather than raising (a per-message snippet must not sink
     * the whole listing).
     *
     * @param resource $imap IMAP stream
     * @param int      $uid  Message UID
     */
    private function snippetFor($imap, int $uid): string
    {
        $structure = @imap_fetchstructure($imap, $uid, FT_UID);
        if ($structure === false) {
            return '';
        }
        $body = $this->extractBody($imap, $uid, $structure, true);
        $text = $body['text'] !== '' ? $body['text'] : $body['html'];

        return $this->snippetText($text);
    }

    /**
     * Strip HTML tags, collapse whitespace, and truncate to 200 characters.
     *
     * mb_substr is guarded: ext-mbstring is optional, and an unguarded mb_* call
     * would fatal on a build without it — a snippet is a display preview, so a
     * byte-based substr fallback is acceptable when the extension is absent.
     */
    private function snippetText(string $text): string
    {
        $text = preg_replace('/<[^>]+>/', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = trim($text);

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, 200);
        }
        return substr($text, 0, 200);
    }

    /**
     * Convert an RFC 2822 date header to an ISO-8601 string (parity with the
     * Python master's parsedate_to_datetime(...).isoformat()). An empty or
     * unparseable value is returned unchanged.
     */
    private function toIso8601(string $date): string
    {
        if (trim($date) === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($date))->format('c');
        } catch (\Throwable) {
            return $date;
        }
    }

    /**
     * Parse a raw RFC 2822 header block into a name => value map (last-wins for
     * a repeated header), unfolding continuation lines. Parity with the Python
     * master's headers: dict(msg.items()).
     *
     * @return array<string, string>
     */
    private function parseHeaders(string $raw): array
    {
        $headers = [];
        $current = null;
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            // A folded continuation line begins with whitespace.
            if (($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
                $headers[$current] .= ' ' . trim($line);
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = trim(substr($line, 0, $pos));
            if ($name === '') {
                continue;
            }
            $headers[$name] = trim(substr($line, $pos + 1));
            $current = $name;
        }
        return $headers;
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
