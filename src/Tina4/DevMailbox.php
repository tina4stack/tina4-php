<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * DevMailbox — Development mailbox that captures emails as JSON files.
 * Zero dependencies — uses only PHP built-in functions.
 */

namespace Tina4;

class DevMailbox
{
    /** @var string Base directory for mailbox storage */
    private string $mailboxDir;

    /**
     * @param string $mailboxDir Directory to store captured emails
     */
    public function __construct(string $mailboxDir = 'data/mailbox')
    {
        $envDir = getenv('TINA4_MAILBOX_DIR');
        if ($envDir !== false && $envDir !== '') {
            $mailboxDir = $envDir;
        }

        if (class_exists(DotEnv::class) && method_exists(DotEnv::class, 'getEnv')) {
            $dotEnvDir = DotEnv::getEnv('TINA4_MAILBOX_DIR');
            if ($dotEnvDir !== null) {
                $mailboxDir = $dotEnvDir;
            }
        }

        $this->mailboxDir = rtrim($mailboxDir, '/');
    }

    /**
     * Capture an email as a JSON file instead of sending it.
     *
     * @param string|array $to          Recipient(s)
     * @param string       $subject     Email subject
     * @param string       $body        Email body content
     * @param bool         $html        Whether the body is HTML
     * @param array        $cc          CC recipients
     * @param array        $bcc         BCC recipients
     * @param string|null  $replyTo     Reply-to address
     * @param array        $attachments Attachment metadata
     * @param array        $headers     Additional headers
     * @return array ['success' => bool, 'message' => string, 'id' => string|null]
     */
    public function capture(
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
        $id = $this->generateId();
        $recipients = is_array($to) ? $to : [$to];

        $email = [
            'id' => $id,
            'to' => $recipients,
            'subject' => $subject,
            'body' => $body,
            'html' => $html,
            'cc' => $cc,
            'bcc' => $bcc,
            'reply_to' => $replyTo,
            'attachments' => $this->processAttachments($attachments),
            'headers' => $headers,
            'date' => date('r'),
            'timestamp' => time(),
            'read' => false,
            'folder' => 'outbox',
        ];

        $outboxPath = $this->folderPath('outbox');
        $this->ensureDir($outboxPath);

        file_put_contents(
            $outboxPath . '/' . $id . '.json',
            json_encode($email, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        return ['success' => true, 'message' => 'Email captured to dev mailbox', 'id' => $id];
    }

    /**
     * List messages in the mailbox.
     *
     * @param int         $limit  Maximum messages to return
     * @param int         $offset Offset for pagination
     * @param string|null $folder Folder name (null for all folders)
     * @return array List of message summary arrays
     */
    public function inbox(int $limit = 50, int $offset = 0, ?string $folder = null): array
    {
        $messages = [];
        $folders = $folder !== null ? [$folder] : $this->listFolders();

        foreach ($folders as $f) {
            $path = $this->folderPath($f);
            if (!is_dir($path)) {
                continue;
            }

            $files = glob($path . '/*.json');
            foreach ($files as $file) {
                $data = json_decode(file_get_contents($file), true);
                if ($data === null) {
                    continue;
                }

                $messages[] = [
                    'id' => $data['id'],
                    'to' => $data['to'] ?? [],
                    'from' => $data['from'] ?? null,
                    'subject' => $data['subject'] ?? '',
                    'date' => $data['date'] ?? '',
                    'timestamp' => $data['timestamp'] ?? 0,
                    'read' => $data['read'] ?? false,
                    'folder' => $data['folder'] ?? $f,
                    'has_attachments' => !empty($data['attachments']),
                ];
            }
        }

        // Sort by timestamp descending (newest first)
        usort($messages, fn(array $a, array $b) => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));

        return array_slice($messages, $offset, $limit);
    }

    /**
     * Read a specific message by ID.
     *
     * @param string $msgId Message ID
     * @return array|null Full message data or null if not found
     */
    public function read(string $msgId): ?array
    {
        $file = $this->findMessage($msgId);
        if ($file === null) {
            return null;
        }

        $data = json_decode(file_get_contents($file), true);
        if ($data === null) {
            return null;
        }

        // Mark as read
        if (!($data['read'] ?? false)) {
            $data['read'] = true;
            file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        }

        return $data;
    }

    /**
     * Get the count of unread messages.
     *
     * @return int Number of unread messages
     */
    public function unreadCount(): int
    {
        $count = 0;
        $folders = $this->listFolders();

        foreach ($folders as $folder) {
            $path = $this->folderPath($folder);
            if (!is_dir($path)) {
                continue;
            }

            $files = glob($path . '/*.json');
            foreach ($files as $file) {
                $data = json_decode(file_get_contents($file), true);
                if ($data !== null && !($data['read'] ?? false)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Delete a specific message by ID.
     *
     * @param string $msgId Message ID
     * @return bool True if message was found and deleted
     */
    public function delete(string $msgId): bool
    {
        $file = $this->findMessage($msgId);
        if ($file === null) {
            return false;
        }

        return unlink($file);
    }

    /**
     * Clear all messages from a folder (or all folders).
     *
     * @param string|null $folder Folder name, or null for all folders
     */
    public function clear(?string $folder = null): void
    {
        $folders = $folder !== null ? [$folder] : $this->listFolders();

        foreach ($folders as $f) {
            $path = $this->folderPath($f);
            if (!is_dir($path)) {
                continue;
            }

            $files = glob($path . '/*.json');
            foreach ($files as $file) {
                unlink($file);
            }
        }
    }

    /**
     * Seed the mailbox with fake inbox messages for development.
     *
     * @param int $count Number of messages to generate
     */
    public function seed(int $count = 5): void
    {
        $inboxPath = $this->folderPath('inbox');
        $this->ensureDir($inboxPath);

        $senders = [
            'alice@example.com',
            'bob@example.com',
            'charlie@example.com',
            'diana@example.com',
            'eve@example.com',
        ];

        $subjects = [
            'Weekly team standup notes',
            'Project deadline reminder',
            'Invoice #%d attached',
            'Meeting rescheduled to Thursday',
            'Quick question about the API',
            'Bug report: login page issue',
            'New feature request',
            'Documentation update needed',
            'Deployment notification',
            'Welcome to the team!',
        ];

        $bodies = [
            'Hi,\n\nJust a quick update on the project status. Everything is on track.\n\nBest regards',
            'Hello,\n\nPlease find the attached document for your review.\n\nThanks',
            'Hi there,\n\nCould you please take a look at this when you get a chance?\n\nCheers',
            'Good morning,\n\nThis is a reminder about the upcoming deadline.\n\nRegards',
            'Hey,\n\nI have a question about the implementation. Can we chat later?\n\nThanks',
        ];

        for ($i = 0; $i < $count; $i++) {
            $id = $this->generateId();
            $sender = $senders[array_rand($senders)];
            $subject = sprintf($subjects[array_rand($subjects)], rand(1000, 9999));
            $body = $bodies[array_rand($bodies)];

            $email = [
                'id' => $id,
                'from' => $sender,
                'to' => ['dev@localhost'],
                'subject' => $subject,
                'body' => $body,
                'html' => false,
                'cc' => [],
                'bcc' => [],
                'reply_to' => $sender,
                'attachments' => [],
                'headers' => [],
                'date' => date('r', time() - rand(0, 86400 * 7)),
                'timestamp' => time() - rand(0, 86400 * 7),
                'read' => (bool)rand(0, 1),
                'folder' => 'inbox',
            ];

            file_put_contents(
                $inboxPath . '/' . $id . '.json',
                json_encode($email, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                LOCK_EX
            );
        }
    }

    /**
     * Get message counts per folder.
     *
     * @param string|null $folder Specific folder or null for all
     * @return array ['inbox' => N, 'outbox' => N, 'total' => N]
     */
    public function count(?string $folder = null): array
    {
        $counts = ['inbox' => 0, 'outbox' => 0, 'total' => 0];
        $folders = $folder !== null ? [$folder] : $this->listFolders();

        foreach ($folders as $f) {
            $path = $this->folderPath($f);
            if (!is_dir($path)) {
                continue;
            }

            $fileCount = count(glob($path . '/*.json'));
            $key = array_key_exists($f, $counts) ? $f : $f;

            if (isset($counts[$f])) {
                $counts[$f] = $fileCount;
            } else {
                $counts[$f] = $fileCount;
            }

            $counts['total'] += $fileCount;
        }

        return $counts;
    }

    /**
     * Get the base mailbox directory path.
     */
    public function getMailboxDir(): string
    {
        return $this->mailboxDir;
    }

    // ── Internal Helpers ─────────────────────────────────────────

    /**
     * Find a message file by ID across all folders.
     */
    private function findMessage(string $msgId): ?string
    {
        $folders = $this->listFolders();

        foreach ($folders as $folder) {
            $path = $this->folderPath($folder) . '/' . $msgId . '.json';
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * List existing folder directories.
     *
     * @return array Folder names
     */
    private function listFolders(): array
    {
        if (!is_dir($this->mailboxDir)) {
            return ['inbox', 'outbox'];
        }

        $folders = [];
        $entries = scandir($this->mailboxDir);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($this->mailboxDir . '/' . $entry)) {
                $folders[] = $entry;
            }
        }

        // Ensure inbox and outbox are always present
        if (!in_array('inbox', $folders, true)) {
            $folders[] = 'inbox';
        }
        if (!in_array('outbox', $folders, true)) {
            $folders[] = 'outbox';
        }

        return $folders;
    }

    /**
     * Get the filesystem path for a folder.
     */
    private function folderPath(string $folder): string
    {
        return $this->mailboxDir . '/' . $folder;
    }

    /**
     * Ensure a directory exists.
     */
    private function ensureDir(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    /**
     * Generate a unique message ID.
     */
    private function generateId(): string
    {
        return bin2hex(random_bytes(8)) . '-' . dechex((int)(microtime(true) * 1000));
    }

    /**
     * Process attachments for storage — convert file paths to metadata.
     */
    private function processAttachments(array $attachments): array
    {
        $processed = [];

        foreach ($attachments as $attachment) {
            if (is_string($attachment)) {
                $processed[] = [
                    'filename' => basename($attachment),
                    'path' => $attachment,
                    'size' => is_file($attachment) ? filesize($attachment) : 0,
                    'mime' => is_file($attachment) ? (mime_content_type($attachment) ?: 'application/octet-stream') : 'application/octet-stream',
                ];
            } elseif (is_array($attachment)) {
                $processed[] = [
                    'filename' => $attachment['filename'] ?? 'attachment',
                    'size' => strlen($attachment['content'] ?? ''),
                    'mime' => $attachment['mime'] ?? 'application/octet-stream',
                ];
            }
        }

        return $processed;
    }
}
