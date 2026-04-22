<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Project plan management — persistent, human-readable task state.
 *
 * A "plan" is a markdown file under `plan/` at the project root with a
 * lightweight structure the AI (and the human) can both read and edit:
 *
 *     # Add user authentication
 *
 *     Goal: JWT login/registration with refresh tokens.
 *
 *     ## Steps
 *
 *     - [x] Create User model in src/orm/User.php
 *     - [ ] Add /api/login route
 *
 *     ## Notes
 *
 *     Use Tina4\Auth::getToken, 60-minute tokens.
 *
 * At any moment exactly one plan is "current" — recorded by the
 * filename stored in `plan/.current`. That plan's contents are injected
 * into every chat turn's system prompt so the AI keeps working on the
 * same thing across conversations, and the step list gives a clear
 * "done / not done" snapshot.
 *
 * This class mirrors Python's `tina4_python.dev_admin.plan` module
 * byte-for-byte (storage format) so both frameworks share plan/
 * directories interoperably. MCP tools in McpDevTools expose it to the
 * AI; the dev-admin UI surfaces it to the human.
 */

namespace Tina4;

class Plan
{
    public const PLAN_DIR = 'plan';
    public const CURRENT_FILE = '.current';
    public const ARCHIVE_SUBDIR = 'done';

    // ── Internals ──────────────────────────────────────────────────

    private static function projectRoot(): string
    {
        $cwd = getcwd();
        $real = $cwd !== false ? (realpath($cwd) ?: $cwd) : '.';
        return str_replace('\\', '/', (string) $real);
    }

    private static function planDir(): string
    {
        $dir = self::projectRoot() . '/' . self::PLAN_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private static function archiveDir(): string
    {
        $dir = self::planDir() . '/' . self::ARCHIVE_SUBDIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private static function currentPointer(): string
    {
        return self::planDir() . '/' . self::CURRENT_FILE;
    }

    private static function slugify(string $title): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', strtolower(trim($title))) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') {
            return 'plan-' . time();
        }
        return substr($slug, 0, 80);
    }

    /**
     * Parse a plan markdown file into a structured dict.
     *
     * Tolerant — missing sections are fine; unknown sections preserved
     * verbatim in `extra`. Only picks apart what we need to manipulate
     * programmatically (title, goal, steps, notes).
     */
    private static function parse(string $text): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
        $title = '';
        $goal = '';
        $steps = [];
        $notesLines = [];
        $section = null; // null | "steps" | "notes" | "other"
        $stepRe = '/^\s*[-*]\s*\[(?P<box>[ xX])\]\s*(?P<text>.+?)\s*$/';

        foreach ($lines as $raw) {
            $line = rtrim($raw);
            if ($title === '' && str_starts_with($line, '# ')) {
                $title = trim(substr($line, 2));
                continue;
            }
            $low = strtolower(trim($line));
            if (str_starts_with($low, 'goal:') && $goal === '') {
                $goal = trim(substr($line, strpos($line, ':') + 1));
                continue;
            }
            if ($low === '## steps') {
                $section = 'steps';
                continue;
            }
            if ($low === '## notes') {
                $section = 'notes';
                continue;
            }
            if (str_starts_with($line, '## ')) {
                $section = 'other';
                continue;
            }
            if ($section === 'steps') {
                if (preg_match($stepRe, $line, $m)) {
                    $steps[] = [
                        'text' => trim($m['text']),
                        'done' => strtolower($m['box']) === 'x',
                    ];
                }
            } elseif ($section === 'notes' && trim($line) !== '') {
                $notesLines[] = $line;
            }
        }
        return [
            'title' => $title,
            'goal' => $goal,
            'steps' => $steps,
            'notes' => trim(implode("\n", $notesLines)),
        ];
    }

    private static function render(array $plan): string
    {
        $parts = ['# ' . ($plan['title'] ?? 'Untitled plan'), ''];
        $goal = $plan['goal'] ?? '';
        if ($goal !== '') {
            $parts[] = 'Goal: ' . $goal;
            $parts[] = '';
        }
        $parts[] = '## Steps';
        $parts[] = '';
        foreach (($plan['steps'] ?? []) as $step) {
            $box = !empty($step['done']) ? 'x' : ' ';
            $parts[] = '- [' . $box . '] ' . trim($step['text'] ?? '');
        }
        $notes = trim($plan['notes'] ?? '');
        if ($notes !== '') {
            $parts[] = '';
            $parts[] = '## Notes';
            $parts[] = '';
            $parts[] = $notes;
        }
        return implode("\n", $parts) . "\n";
    }

    private static function loadParsed(string $name): array
    {
        if (!str_ends_with($name, '.md')) {
            $name .= '.md';
        }
        $path = self::planDir() . '/' . $name;
        if (!is_file($path)) {
            throw new \RuntimeException("No such plan: {$name}");
        }
        return [$path, self::parse((string) file_get_contents($path))];
    }

    // ── Public API ─────────────────────────────────────────────────

    public static function listPlans(): array
    {
        $dir = self::planDir();
        $current = self::currentName();
        $out = [];
        $files = glob($dir . '/*.md') ?: [];
        sort($files);
        foreach ($files as $path) {
            $parsed = self::parse((string) file_get_contents($path));
            $total = count($parsed['steps']);
            $done = 0;
            foreach ($parsed['steps'] as $s) {
                if (!empty($s['done'])) {
                    $done++;
                }
            }
            $basename = basename($path);
            $stem = pathinfo($basename, PATHINFO_FILENAME);
            $out[] = [
                'name' => $basename,
                'title' => $parsed['title'] !== '' ? $parsed['title'] : $stem,
                'steps_total' => $total,
                'steps_done' => $done,
                'is_current' => $basename === $current,
            ];
        }
        return $out;
    }

    public static function currentName(): string
    {
        $ptr = self::currentPointer();
        if (!is_file($ptr)) {
            return '';
        }
        return trim((string) file_get_contents($ptr));
    }

    public static function setCurrent(string $name): array
    {
        $name = trim($name);
        if (!str_ends_with($name, '.md')) {
            $name .= '.md';
        }
        $path = self::planDir() . '/' . $name;
        if (!is_file($path)) {
            return ['ok' => false, 'error' => "No such plan: {$name}"];
        }
        @file_put_contents(self::currentPointer(), $name);
        return ['ok' => true, 'current' => $name];
    }

    public static function clearCurrent(): array
    {
        $ptr = self::currentPointer();
        if (is_file($ptr)) {
            @unlink($ptr);
        }
        return ['ok' => true];
    }

    public static function current(): array
    {
        $name = self::currentName();
        if ($name === '') {
            return ['current' => null];
        }
        $path = self::planDir() . '/' . $name;
        if (!is_file($path)) {
            self::clearCurrent();
            return [
                'current' => null,
                'warning' => "Current pointer referenced missing file: {$name}",
            ];
        }
        $parsed = self::parse((string) file_get_contents($path));
        $indexed = [];
        foreach ($parsed['steps'] as $i => $s) {
            $indexed[] = [
                'index' => $i,
                'text' => $s['text'],
                'done' => $s['done'],
            ];
        }
        $next = null;
        foreach ($indexed as $s) {
            if (!$s['done']) {
                $next = $s;
                break;
            }
        }
        $doneCount = 0;
        foreach ($indexed as $s) {
            if ($s['done']) {
                $doneCount++;
            }
        }
        return [
            'current' => $name,
            'title' => $parsed['title'],
            'goal' => $parsed['goal'],
            'steps' => $indexed,
            'next_step' => $next,
            'notes' => $parsed['notes'],
            'progress' => [
                'done' => $doneCount,
                'total' => count($indexed),
            ],
            'execution' => self::summariseExecution($name),
        ];
    }

    public static function read(string $name): array
    {
        if (!str_ends_with($name, '.md')) {
            $name .= '.md';
        }
        $path = self::planDir() . '/' . $name;
        if (!is_file($path)) {
            return ['error' => "No such plan: {$name}"];
        }
        return self::parse((string) file_get_contents($path)) + ['name' => $name];
    }

    public static function create(string $title, string $goal = '', array $steps = [], bool $makeCurrent = true): array
    {
        $title = trim($title);
        if ($title === '') {
            return ['ok' => false, 'error' => 'title is required'];
        }
        $stem = self::slugify($title);
        $name = $stem . '.md';
        $path = self::planDir() . '/' . $name;
        if (is_file($path)) {
            return [
                'ok' => false,
                'error' => "Plan already exists: {$name}. Pick a different title or edit the existing one.",
            ];
        }
        $plan = [
            'title' => $title,
            'goal' => trim($goal),
            'steps' => [],
            'notes' => '',
        ];
        foreach ($steps as $s) {
            $t = trim((string) $s);
            if ($t !== '') {
                $plan['steps'][] = ['text' => $t, 'done' => false];
            }
        }
        @file_put_contents($path, self::render($plan));
        if ($makeCurrent) {
            @file_put_contents(self::currentPointer(), $name);
        }
        return [
            'ok' => true,
            'name' => $name,
            'title' => $title,
            'is_current' => $makeCurrent,
        ];
    }

    public static function completeStep(int $index, string $name = ''): array
    {
        $name = $name !== '' ? $name : self::currentName();
        if ($name === '') {
            return ['ok' => false, 'error' => 'No current plan and no name given'];
        }
        try {
            [$path, $plan] = self::loadParsed($name);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if ($index < 0 || $index >= count($plan['steps'])) {
            $last = count($plan['steps']) - 1;
            return ['ok' => false, 'error' => "Step index {$index} out of range (0..{$last})"];
        }
        $plan['steps'][$index]['done'] = true;
        @file_put_contents($path, self::render($plan));
        $remaining = [];
        foreach ($plan['steps'] as $i => $s) {
            if (empty($s['done'])) {
                $remaining[] = $i;
            }
        }
        return [
            'ok' => true,
            'completed' => $plan['steps'][$index]['text'],
            'remaining' => count($remaining),
            'next_step' => $remaining ? $plan['steps'][$remaining[0]]['text'] : null,
        ];
    }

    public static function uncompleteStep(int $index, string $name = ''): array
    {
        $name = $name !== '' ? $name : self::currentName();
        if ($name === '') {
            return ['ok' => false, 'error' => 'No current plan and no name given'];
        }
        try {
            [$path, $plan] = self::loadParsed($name);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if ($index < 0 || $index >= count($plan['steps'])) {
            return ['ok' => false, 'error' => "Step index {$index} out of range"];
        }
        $plan['steps'][$index]['done'] = false;
        @file_put_contents($path, self::render($plan));
        return ['ok' => true, 'step' => $plan['steps'][$index]['text']];
    }

    public static function addStep(string $text, string $name = ''): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['ok' => false, 'error' => 'text is required'];
        }
        $name = $name !== '' ? $name : self::currentName();
        if ($name === '') {
            return ['ok' => false, 'error' => 'No current plan and no name given'];
        }
        try {
            [$path, $plan] = self::loadParsed($name);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $plan['steps'][] = ['text' => $text, 'done' => false];
        @file_put_contents($path, self::render($plan));
        return ['ok' => true, 'step' => $text, 'index' => count($plan['steps']) - 1];
    }

    public static function appendNote(string $text, string $name = ''): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['ok' => false, 'error' => 'text is required'];
        }
        $name = $name !== '' ? $name : self::currentName();
        if ($name === '') {
            return ['ok' => false, 'error' => 'No current plan and no name given'];
        }
        try {
            [$path, $plan] = self::loadParsed($name);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $existing = trim($plan['notes'] ?? '');
        $stamp = gmdate('Y-m-d H:i');
        $plan['notes'] = trim($existing . "\n- [{$stamp}] {$text}");
        @file_put_contents($path, self::render($plan));
        return ['ok' => true, 'appended' => $text];
    }

    // ── Execution ledger — "what has this plan touched so far?" ───

    private static function ledgerPath(string $name = ''): ?string
    {
        $name = $name !== '' ? $name : self::currentName();
        if ($name === '') {
            return null;
        }
        if (!str_ends_with($name, '.md')) {
            $name .= '.md';
        }
        $stem = substr($name, 0, -3);
        return self::planDir() . '/' . $stem . '.log.json';
    }

    public static function recordAction(string $action, string $path, string $note = ''): void
    {
        $lp = self::ledgerPath();
        if ($lp === null) {
            return;
        }
        $entries = [];
        if (is_file($lp)) {
            $decoded = json_decode((string) file_get_contents($lp), true);
            if (is_array($decoded)) {
                $entries = $decoded;
            }
        }
        $entries[] = [
            't' => time(),
            'action' => $action,    // "created" | "patched" | "migration"
            'path' => $path,
            'note' => $note,
        ];
        if (count($entries) > 500) {
            $entries = array_slice($entries, -500);
        }
        @file_put_contents($lp, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public static function summariseExecution(string $name = ''): array
    {
        $lp = self::ledgerPath($name);
        $empty = ['created' => [], 'patched' => [], 'migrations' => [], 'total' => 0];
        if ($lp === null || !is_file($lp)) {
            return $empty;
        }
        $decoded = json_decode((string) file_get_contents($lp), true);
        if (!is_array($decoded)) {
            return $empty;
        }
        $created = [];
        $patched = [];
        $migrations = [];
        foreach ($decoded as $e) {
            $action = $e['action'] ?? '';
            $path = $e['path'] ?? '';
            if ($path === '') {
                continue;
            }
            if ($action === 'migration') {
                if (!in_array($path, $migrations, true)) {
                    $migrations[] = $path;
                }
            } elseif ($action === 'created') {
                if (!in_array($path, $created, true)) {
                    $created[] = $path;
                }
            } elseif ($action === 'patched') {
                if (!in_array($path, $patched, true)) {
                    $patched[] = $path;
                }
            }
        }
        return [
            'created' => array_slice($created, -20),
            'patched' => array_slice($patched, -20),
            'migrations' => array_slice($migrations, -20),
            'total' => count($decoded),
        ];
    }

    /**
     * Auto-generate concrete build steps for an existing (usually empty)
     * plan by asking the Tina4 AI backend. Mirrors Python
     * `plan_flesh()` byte-for-byte in behaviour:
     *
     *   1. Loads plan by ``name`` (defaults to current).
     *   2. Builds a structured qwen prompt from title + goal + existing
     *      steps + caller-supplied prompt.
     *   3. Posts to TINA4_AI_URL with ``stream: false`` and parses the
     *      response as a JSON array of step strings. Falls back to
     *      extracting ``- item`` / ``1. item`` lines on parse failure.
     *   4. Calls ``addStep`` for each proposed step, skipping dupes.
     *
     * @return array{ok:bool,plan?:string,added?:string[],added_count?:int,proposed_count?:int,plan_after?:array,error?:string,raw_reply?:string}
     */
    public static function flesh(string $name = '', string $prompt = ''): array
    {
        $target = trim($name) !== '' ? trim($name) : self::currentName();
        if ($target === '') {
            return ['ok' => false, 'error' => 'No current plan and no name given'];
        }
        $current = self::read($target);
        if (isset($current['error'])) {
            return ['ok' => false, 'error' => $current['error']];
        }

        $existing = array_map(fn($s) => (string)($s['text'] ?? ''), $current['steps'] ?? []);
        $title = $current['title'] ?? $target;
        $goal = $current['goal'] ?? '';

        $systemPrompt = 'You are Tina4, a coding planner embedded in the Tina4 dev '
            . 'admin. The user wants concrete build steps for a project plan. '
            . 'Return ONLY a JSON array of short imperative step strings (no prose, '
            . 'no code-fences, no numbering). 3-8 steps, each referencing concrete '
            . 'files/routes/migrations where possible. Example: '
            . '["Create src/orm/Duck.php with id/name/sighted_at", '
            . '"Add migration 001_create_ducks.sql", '
            . '"Add GET/POST/PUT/DELETE /api/ducks routes in src/routes/ducks.php"]';

        $userParts = ["Plan title: {$title}"];
        if ($goal !== '') $userParts[] = "Goal: {$goal}";
        if ($existing) {
            $userParts[] = "Existing steps (don't repeat):\n- " . implode("\n- ", $existing);
        }
        if ($prompt !== '') $userParts[] = "Extra context from caller: {$prompt}";
        $userParts[] = 'Reply with ONLY the JSON array — no explanation, no markdown fences.';
        $userPrompt = implode("\n\n", $userParts);

        $aiUrl = getenv('TINA4_AI_URL') ?: 'http://andrevanzuydam.com:11437/api/chat';
        $aiModel = getenv('TINA4_AI_MODEL') ?: 'qwen2.5-coder:14b';

        $ch = curl_init($aiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $aiModel,
                'stream' => false,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = $raw === false ? (curl_error($ch) ?: 'curl failed') : '';
        curl_close($ch);
        if ($raw === false || $code !== 200) {
            return ['ok' => false, 'error' => "AI backend unreachable: {$err} (HTTP {$code})"];
        }
        $decoded = json_decode($raw, true) ?: [];
        $reply = $decoded['message']['content'] ?? ($decoded['response'] ?? '');

        // Strip any code fences the model snuck in.
        $body = trim((string) $reply);
        if (str_starts_with($body, '```')) {
            $body = trim($body, "` \n\r\t");
            if (stripos($body, 'json') === 0) {
                $body = trim(substr($body, 4));
            }
            $body = rtrim($body, "` \n\r\t");
        }

        $proposed = [];
        $parsed = json_decode($body, true);
        if (is_array($parsed)) {
            foreach ($parsed as $item) {
                $s = trim((string) $item);
                if ($s !== '') $proposed[] = $s;
            }
        }
        if (!$proposed) {
            // Fallback: extract "- item" or "N. item" lines from prose
            foreach (preg_split("/\r?\n/", (string)$reply) ?: [] as $line) {
                if (preg_match('/^\s*(?:[-*]|\d+[.)])\s+(.+?)\s*$/', $line, $m)) {
                    $proposed[] = trim($m[1]);
                }
            }
        }
        if (!$proposed) {
            return [
                'ok' => false,
                'error' => 'AI returned no usable steps',
                'raw_reply' => substr((string)$reply, 0, 400),
            ];
        }

        $existingLc = array_flip(array_map('strtolower', $existing));
        $added = [];
        foreach ($proposed as $step) {
            $lc = strtolower($step);
            if (isset($existingLc[$lc])) continue;
            $res = self::addStep($step, $target);
            if (!empty($res['ok'])) {
                $added[] = $step;
                $existingLc[$lc] = true;
            }
        }

        return [
            'ok' => true,
            'plan' => $target,
            'added' => $added,
            'added_count' => count($added),
            'proposed_count' => count($proposed),
            'plan_after' => self::read($target),
        ];
    }

    public static function archive(string $name = ''): array
    {
        $name = $name !== '' ? $name : self::currentName();
        if ($name === '') {
            return ['ok' => false, 'error' => 'No current plan and no name given'];
        }
        if (!str_ends_with($name, '.md')) {
            $name .= '.md';
        }
        $src = self::planDir() . '/' . $name;
        if (!is_file($src)) {
            return ['ok' => false, 'error' => "No such plan: {$name}"];
        }
        $dest = self::archiveDir() . '/' . $name;
        if (is_file($dest)) {
            $dest = self::archiveDir() . '/' . time() . '-' . $name;
        }
        @rename($src, $dest);
        if (self::currentName() === $name) {
            self::clearCurrent();
        }
        $rel = str_replace(self::projectRoot() . '/', '', $dest);
        return ['ok' => true, 'archived_to' => $rel];
    }
}
