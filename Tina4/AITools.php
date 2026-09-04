<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Simple menu-driven installer for AI tool context files.
 * The user picks which tools they use, we install the appropriate files.
 *
 * Supports: Claude Code, Cursor, GitHub Copilot, Windsurf, Aider, Cline, OpenAI Codex.
 */
class AITools
{
    /** @var array Ordered list of supported AI tools */
    public static array $AI_TOOLS = [
        ["name" => "claude-code", "description" => "Claude Code", "context_file" => "CLAUDE.md", "config_dir" => ".claude"],
        ["name" => "cursor", "description" => "Cursor", "context_file" => ".cursorules", "config_dir" => ".cursor"],
        ["name" => "copilot", "description" => "GitHub Copilot", "context_file" => ".github/copilot-instructions.md", "config_dir" => ".github"],
        ["name" => "windsurf", "description" => "Windsurf", "context_file" => ".windsurfrules", "config_dir" => null],
        ["name" => "aider", "description" => "Aider", "context_file" => "CONVENTIONS.md", "config_dir" => null],
        ["name" => "cline", "description" => "Cline", "context_file" => ".clinerules", "config_dir" => null],
        ["name" => "codex", "description" => "OpenAI Codex", "context_file" => "AGENTS.md", "config_dir" => null],
    ];

    // ── Skill files (the SKILL.md system) ───────────────────────────────
    // `tina4 ai` installs the actual skills — not just a CLAUDE.md pointer to
    // them — into BOTH the project (.claude/skills, so they travel with the
    // repo) and the user's global ~/.claude/skills (so they're available in
    // every project). A PHP project needs the php developer skill plus the two
    // shared skills; the developer skill ships from the tina4-php release tag,
    // the shared skills from tina4-python. Mirrors the canonical
    // install-skills.sh.

    /** The PHP developer skill (split from the old generic tina4-developer). */
    public const DEV_SKILL = "tina4-developer-php";

    /**
     * Skills installed by `tina4 ai`: skill name → source repo + reference
     * files under references/. The developer skill comes from tina4-php; the
     * two shared skills (tina4-js, tina4-maintainer) come from tina4-python.
     *
     * @return array<string, array{repo:string, refs:string[]}>
     */
    private static function skills(): array
    {
        return [
            self::DEV_SKILL => [
                "repo" => "tina4-php",
                "refs" => ["auth-and-services.md", "data-and-orm.md", "deployment.md",
                           "routes-and-api.md", "templates-and-frontend.md", "realtime.md"],
            ],
            "tina4-js" => [
                "repo" => "tina4-python",
                "refs" => ["html-and-components.md", "signals-and-reactivity.md",
                           "persistence.md", "rtc.md"],
            ],
            "tina4-maintainer" => [
                "repo" => "tina4-python",
                "refs" => ["cli-and-deployment.md", "frond-and-frontend.md",
                           "routing-and-orm.md", "subsystems.md"],
            ],
        ];
    }

    /**
     * Release tag to pull skills from — the installed framework version,
     * overridable with TINA4_SKILLS_REF (e.g. to test a branch). Falls back
     * to "main" when the version can't be resolved.
     */
    private static function skillsRef(): string
    {
        $ref = getenv('TINA4_SKILLS_REF');
        if ($ref !== false && $ref !== '') {
            return $ref;
        }
        if (App::$VERSION !== '' && App::$VERSION !== '0.0.0') {
            return App::$VERSION;
        }
        return 'main';
    }

    /** The user's home directory (cross-platform), or the temp dir as a last resort. */
    private static function homeDir(): string
    {
        foreach (['HOME', 'USERPROFILE'] as $var) {
            $home = getenv($var);
            if ($home !== false && $home !== '') {
                return $home;
            }
        }
        return sys_get_temp_dir();
    }

    /** HTTP statuses worth retrying — a throttle or a server hiccup, not a real answer. */
    private const TRANSIENT_HTTP_CODES = [429, 500, 502, 503, 504];

    /** Backoff (seconds) before each retry — short, one entry per retry attempt. */
    private const FETCH_RETRY_BACKOFF = [1, 2];

    /**
     * Fetch a URL's bytes, or null on any failure. Uses curl when available
     * (clean timeout handling), else file_get_contents. 15s timeout per attempt.
     *
     * raw.githubusercontent.com returns an intermittent 503 under load (a
     * freshly cut release tag is "cold" on GitHub's CDN until it warms), and
     * `tina4 ai` makes ~30 fetches per install — a single transient blip must
     * not abort the whole install. Retries a transient HTTP status
     * (429/500/502/503/504) or a transport-level failure (connection
     * refused/reset/timeout — no response at all) with a short backoff
     * between attempts; a permanent 4xx (404, etc.) is a genuine answer and
     * is never retried. Mirrors Python's `_fetch_bytes` and Ruby's
     * `fetch_bytes`/`TRANSIENT_HTTP_CODES`.
     */
    private static function fetchBytes(string $url): ?string
    {
        $maxAttempts = count(self::FETCH_RETRY_BACKOFF) + 1;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $code = null;
            $transportFailed = false;

            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_CONNECTTIMEOUT => 15,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_FAILONERROR => true,
                    CURLOPT_USERAGENT => 'tina4-php-ai-installer',
                ]);
                $data = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                // curl_close() is a no-op since PHP 8.0 (deprecated in 8.5); the
                // CurlHandle is freed on GC. Leaving it out keeps 8.5 quiet.
                if ($data !== false && $code >= 200 && $code < 300) {
                    return $data;
                }
                $transportFailed = ($code === 0);
            } else {
                $context = stream_context_create([
                    'http' => ['timeout' => 15, 'follow_location' => 1, 'user_agent' => 'tina4-php-ai-installer'],
                    'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
                ]);
                $data = @file_get_contents($url, false, $context);
                if ($data !== false) {
                    return $data;
                }
                $code = self::statusCodeFromResponseHeaders($http_response_header ?? []);
                $transportFailed = ($code === null);
            }

            $transient = $transportFailed || in_array($code, self::TRANSIENT_HTTP_CODES, true);
            $hasMoreAttempts = $attempt <= count(self::FETCH_RETRY_BACKOFF);
            if (!$transient || !$hasMoreAttempts) {
                return null;
            }
            sleep(self::FETCH_RETRY_BACKOFF[$attempt - 1]);
        }

        return null;
    }

    /**
     * Parse the numeric status code from the `$http_response_header` PHP
     * populates after a stream-wrapper `file_get_contents()` call, or null
     * when it is absent/unparseable (a transport failure — no response).
     *
     * @param string[] $headers
     */
    private static function statusCodeFromResponseHeaders(array $headers): ?int
    {
        if (!isset($headers[0]) || !preg_match('/^HTTP\/\S+\s+(\d{3})/', $headers[0], $m)) {
            return null;
        }
        return (int)$m[1];
    }

    /**
     * Install the Tina4 SKILL.md skills into the project AND the user's global
     * ~/.claude/skills, fetched from the release tag matching this framework
     * version. Returns the skills that installed fully. Network-dependent —
     * on a fetch failure the affected skill is skipped gracefully.
     *
     * @param string        $root    Project root.
     * @param string[]|null $targets Destination skills dirs. Defaults to
     *                               <project>/.claude/skills and ~/.claude/skills.
     * @return string[] Names of the skills fully installed.
     */
    public static function installSkills(string $root = ".", ?array $targets = null): array
    {
        $ref = self::skillsRef();
        if ($targets === null) {
            $projectRoot = realpath($root) ?: $root;
            $targets = [
                $projectRoot . '/.claude/skills',
                self::homeDir() . '/.claude/skills',
            ];
        }

        $installed = [];
        foreach (self::skills() as $skill => $spec) {
            $base = "https://raw.githubusercontent.com/tina4stack/{$spec['repo']}/{$ref}/.claude/skills";

            // Fetch once, write to every target. A missing SKILL.md means the
            // skill isn't available at this ref — skip it entirely.
            $skillMd = self::fetchBytes("{$base}/{$skill}/SKILL.md");
            if ($skillMd === null) {
                continue;
            }
            $refData = [];
            foreach ($spec['refs'] as $r) {
                $rd = self::fetchBytes("{$base}/{$skill}/references/{$r}");
                if ($rd !== null) {
                    $refData[$r] = $rd;
                }
            }

            foreach ($targets as $dest) {
                $skillDir = $dest . '/' . $skill;
                $refsDir = $skillDir . '/references';
                if (!is_dir($refsDir)) {
                    mkdir($refsDir, 0755, true);
                }
                file_put_contents($skillDir . '/SKILL.md', $skillMd);
                foreach ($refData as $r => $rd) {
                    file_put_contents($refsDir . '/' . $r, $rd);
                }
            }
            $installed[] = $skill;
        }
        return $installed;
    }

    /**
     * Check if a tool's context file already exists.
     */
    public static function isInstalled(string $root, array $tool): bool
    {
        $root = realpath($root) ?: $root;
        return file_exists($root . '/' . $tool['context_file']);
    }

    /**
     * Print the numbered menu with [installed] markers, read stdin, return selection.
     */
    public static function showMenu(string $root = "."): string
    {
        $root = realpath($root) ?: $root;
        $green = "\e[32m";
        $reset = "\e[0m";

        echo "\n  Tina4 AI Context Installer\n\n";
        foreach (self::$AI_TOOLS as $i => $tool) {
            $num = $i + 1;
            $installed = self::isInstalled($root, $tool);
            $marker = $installed ? "  {$green}[installed]{$reset}" : "";
            echo sprintf("  %d. %-20s %s%s\n", $num, $tool['description'], $tool['context_file'], $marker);
        }

        // tina4-ai tools option
        $tina4AiInstalled = self::commandExists("mdview");
        $marker = $tina4AiInstalled ? "  {$green}[installed]{$reset}" : "";
        echo "  8. Install tina4-ai tools  (requires Python){$marker}\n";
        echo "\n";

        echo "  Select (comma-separated, or 'all'): ";
        $line = trim(fgets(STDIN));
        return $line;
    }

    /**
     * Install context files for the selected tools.
     *
     * @param string $root Project root directory
     * @param string $selection Comma-separated numbers like "1,2,3" or "all"
     * @return string[] List of created/updated file paths
     */
    public static function installSelected(string $root, string $selection): array
    {
        $rootPath = realpath($root) ?: $root;
        $created = [];

        if (strtolower($selection) === 'all') {
            $indices = range(0, count(self::$AI_TOOLS) - 1);
            $installTina4Ai = true;
        } else {
            $parts = array_filter(array_map('trim', explode(',', $selection)));
            $indices = [];
            $installTina4Ai = false;
            foreach ($parts as $p) {
                if (!is_numeric($p)) {
                    continue;
                }
                $n = (int)$p;
                if ($n === 8) {
                    $installTina4Ai = true;
                } elseif ($n >= 1 && $n <= count(self::$AI_TOOLS)) {
                    $indices[] = $n - 1;
                }
            }
        }

        foreach ($indices as $idx) {
            $tool = self::$AI_TOOLS[$idx];
            $context = self::generateContext($tool['name']);
            $files = self::installForTool($rootPath, $tool, $context);
            $created = array_merge($created, $files);
        }

        if ($installTina4Ai) {
            self::installTina4Ai();
        }

        return $created;
    }

    /**
     * Install context for all AI tools (non-interactive).
     */
    public static function installAll(string $root = "."): array
    {
        return self::installSelected($root, "all");
    }

    /**
     * Install context file for a single tool.
     *
     * @return string[] Files created (relative paths)
     */
    // ── v3.13.9: non-destructive context-file writer ───────────────────
    //
    // Pre-v3.13.9 the installer wrote a full developer guide to CLAUDE.md
    // (and the other context files) on every run, clobbering whatever the
    // user had put there. Now it writes only a marker-bracketed Tina4
    // skill block — pointing the assistant at .claude/skills/tina4-*/SKILL.md
    // — and leaves the rest of the file alone.

    /** @return array{0:string,1:string} `[start, end]` markers for ``$contextFile``. */
    private static function markersFor(string $contextFile): array
    {
        if (str_ends_with(strtolower($contextFile), '.md')) {
            return ["<!-- tina4-skills:start -->", "<!-- tina4-skills:end -->"];
        }
        return ["# tina4-skills:start", "# tina4-skills:end"];
    }

    /** Return the marker-bracketed Tina4 skill registration block. */
    private static function skillBlock(string $contextFile): string
    {
        [$start, $end] = self::markersFor($contextFile);
        if (str_ends_with(strtolower($contextFile), '.md')) {
            $body = "## Tina4 Skills\n\n"
                . "When working on this Tina4 project, these skills give the assistant project-aware behaviour:\n\n"
                . "- **" . self::DEV_SKILL . "** — Read `.claude/skills/" . self::DEV_SKILL . "/SKILL.md` before building features.\n"
                . "- **tina4-js** — Read `.claude/skills/tina4-js/SKILL.md` for frontend work.\n"
                . "- **tina4-maintainer** — Read `.claude/skills/tina4-maintainer/SKILL.md` for framework-level changes.\n\n"
                . "If Tina4 behaves differently from what these skills describe, that is a bug in the skill. "
                . "Tell the developer, then report it at https://tina4.com/report-a-skill "
                . "(or open an issue on the matching tina4stack/* GitHub repo).\n\n"
                . "See https://tina4.com for full docs.";
        } else {
            $body = "Tina4 Skills — read these files before working on this project:\n"
                . "  .claude/skills/" . self::DEV_SKILL . "/SKILL.md   (feature development)\n"
                . "  .claude/skills/tina4-js/SKILL.md          (frontend / tina4-js)\n"
                . "  .claude/skills/tina4-maintainer/SKILL.md  (framework-level changes)\n"
                . "Found a skill that disagrees with how Tina4 actually behaves? Tell the developer,\n"
                . "then report it at https://tina4.com/report-a-skill\n"
                . "Docs: https://tina4.com";
        }
        return "{$start}\n{$body}\n{$end}";
    }

    /** True iff both start and end markers appear in order. */
    private static function hasMarkers(string $existing, string $start, string $end): bool
    {
        $sIdx = strpos($existing, $start);
        if ($sIdx === false) {
            return false;
        }
        return strpos($existing, $end, $sIdx + strlen($start)) !== false;
    }

    /** Replace the bracketed block in ``$existing`` with ``$block``. */
    private static function replaceMarkerBlock(string $existing, string $block, string $start, string $end): string
    {
        $sIdx = strpos($existing, $start);
        if ($sIdx === false) {
            return rtrim($existing) . "\n\n" . $block . "\n";
        }
        $eIdx = strpos($existing, $end, $sIdx + strlen($start));
        if ($eIdx === false) {
            return rtrim($existing) . "\n\n" . $block . "\n";
        }
        $before = rtrim(substr($existing, 0, $sIdx));
        $after = ltrim(substr($existing, $eIdx + strlen($end)), "\n");
        $glueBefore = $before !== '' ? "\n\n" : '';
        $glueAfter = $after !== '' ? "\n" . $after : "\n";
        return $before . $glueBefore . $block . $glueAfter;
    }

    /**
     * True if the file starts with a header the pre-v3.13.9 installer
     * wrote. Used to migrate one-time off the old clobber-style install.
     */
    private static function looksLikeOldFrameworkInstall(string $existing): bool
    {
        $head = substr(ltrim($existing), 0, 400);
        $headers = [
            "# Tina4 Python",
            "# Tina4 PHP",
            "# Tina4 Ruby",
            "# CLAUDE.md — AI Developer Guide for tina4-nodejs",
            "# CLAUDE.md - AI Developer Guide for tina4-nodejs",
        ];
        foreach ($headers as $h) {
            if (str_starts_with($head, $h)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Write the context file non-destructively. Returns a human-readable
     * action verb for the caller's log line.
     *
     * Four branches:
     *   1. Doesn't exist  → write framework guide + skill block
     *   2. Has markers    → refresh just the skill block (idempotent)
     *   3. Old header     → migrate: replace old dump with new guide + block
     *   4. User content   → append the skill block, preserve everything else
     */
    private static function writeOrMerge(string $contextPath, string $contextFile, string $frameworkGuide): string
    {
        $block = self::skillBlock($contextFile);
        [$start, $end] = self::markersFor($contextFile);

        if (!file_exists($contextPath)) {
            file_put_contents($contextPath, rtrim($frameworkGuide) . "\n\n" . $block . "\n");
            return "Installed";
        }

        $existing = file_get_contents($contextPath);
        if ($existing === false) {
            $existing = '';
        }

        if (self::hasMarkers($existing, $start, $end)) {
            $newContent = self::replaceMarkerBlock($existing, $block, $start, $end);
            file_put_contents($contextPath, $newContent);
            return "Refreshed skill block in";
        }

        if (self::looksLikeOldFrameworkInstall($existing)) {
            $head = ltrim($existing);
            $preamble = substr($existing, 0, strlen($existing) - strlen($head));
            $newContent = (trim($preamble) !== '' ? rtrim($preamble) . "\n\n" : '')
                . rtrim($frameworkGuide) . "\n\n" . $block . "\n";
            file_put_contents($contextPath, $newContent);
            return "Migrated (replaced old framework dump in)";
        }

        $newContent = rtrim($existing) . "\n\n" . $block . "\n";
        file_put_contents($contextPath, $newContent);
        return "Appended skill block to";
    }

    private static function installForTool(string $root, array $tool, string $context): array
    {
        $created = [];
        $contextPath = $root . '/' . $tool['context_file'];

        // Create config directory if needed
        if (!empty($tool['config_dir'])) {
            $configDir = $root . '/' . $tool['config_dir'];
            if (!is_dir($configDir)) {
                mkdir($configDir, 0755, true);
            }
        }

        // Ensure parent directory exists for the context file
        $parentDir = dirname($contextPath);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }

        // v3.13.9: non-destructive write — see writeOrMerge below.
        $action = self::writeOrMerge($contextPath, $tool['context_file'], $context);
        $relative = ltrim(str_replace($root, '', $contextPath), '/');
        $created[] = $relative;
        echo "  \e[32m[OK]\e[0m {$action} {$relative}\n";

        // Claude-specific extras
        if ($tool['name'] === 'claude-code') {
            $skills = self::installClaudeSkills($root);
            $created = array_merge($created, $skills);
        }

        return $created;
    }

    /**
     * Install tina4-ai package (provides mdview for markdown viewing).
     */
    private static function installTina4Ai(): void
    {
        echo "  Installing tina4-ai tools...\n";
        foreach (['pip3', 'pip'] as $cmd) {
            if (!self::commandExists($cmd)) {
                continue;
            }
            $output = [];
            $returnCode = 0;
            exec("{$cmd} install --upgrade tina4-ai 2>&1", $output, $returnCode);
            if ($returnCode === 0) {
                echo "  \e[32m[OK]\e[0m Installed tina4-ai (mdview)\n";
                return;
            } else {
                $error = implode(' ', array_slice($output, 0, 3));
                echo "  \e[33m!\e[0m {$cmd} failed: " . substr($error, 0, 100) . "\n";
            }
        }
        echo "  \e[33m!\e[0m Python/pip not available — skip tina4-ai\n";
    }

    /**
     * Install Claude Code skill files — network-fetched from the release tag —
     * into the project AND the user's global ~/.claude/skills.
     *
     * The previous implementation copied from the framework's own
     * .claude/skills directory, which exists only in a dev/editable checkout —
     * it is NOT shipped in the Composer package. So installed users got zero
     * skill files, only a CLAUDE.md pointer to skills that weren't there.
     * installSkills() fixes that by fetching the real skills over the network.
     *
     * @return string[] Skills installed (relative-style labels)
     */
    private static function installClaudeSkills(string $root): array
    {
        $created = [];
        foreach (self::installSkills($root) as $skill) {
            $created[] = ".claude/skills/{$skill}/";
            echo "  \e[32m[OK]\e[0m Installed .claude/skills/{$skill}  (project + global)\n";
        }
        return $created;
    }

    /**
     * Generate tool-specific Tina4 PHP context for an AI assistant.
     */
    public static function generateContext(string $toolName = 'claude-code'): string
    {
        $version = App::$VERSION;

        switch ($toolName) {
            case 'claude-code':
                return self::generateClaudeCodeContext();

            case 'cursor':
                return self::generateCursorContext($version);

            case 'copilot':
                return self::generateCopilotContext($version);

            case 'windsurf':
                return self::generateWindsurfContext($version);

            case 'aider':
                return self::generateAiderContext($version);

            case 'cline':
                return self::generateClineContext($version);

            case 'codex':
                return self::generateCodexContext($version);

            default:
                return self::generateCursorContext($version);
        }
    }

    /**
     * Claude Code: return the existing CLAUDE.md from the framework root.
     */
    private static function generateClaudeCodeContext(): string
    {
        $frameworkRoot = dirname(__DIR__);
        $claudeMdPath = $frameworkRoot . '/CLAUDE.md';
        if (file_exists($claudeMdPath)) {
            return file_get_contents($claudeMdPath);
        }
        // Fallback if CLAUDE.md is missing
        return "# Tina4 PHP\n\nSee https://tina4.com for documentation.\n";
    }

    /**
     * Cursor context (~45 lines): header, route + ORM examples, conventions, features, docs link.
     */
    private static function generateCursorContext(string $version): string
    {
        return self::cursorStyleContext($version);
    }

    /**
     * Shared Cursor / Cline context body — both tools take the same guide
     * (header, route + ORM examples, conventions, features, docs link).
     */
    private static function cursorStyleContext(string $version): string
    {
        return <<<CONTEXT
# Tina4 PHP v{$version}

Lightweight, zero-dependency PHP web framework. Docs: https://tina4.com

## Route Example

```php
use Tina4\Router;

Router::get("/api/users", function(\Tina4\Request \$request, \Tina4\Response \$response) {
    return \$response(["users" => []]);
});

Router::post("/api/users", function(\Tina4\Request \$request, \Tina4\Response \$response) {
    return \$response(["created" => \$request->body["name"]], 201);
})->noAuth();
```

## ORM Example

```php
class User extends \Tina4\ORM {
    public \$tableName = "users";
    public \$primaryKey = "id";
}
```

## Conventions

1. Routes return \$response() — always \$response(data) not Response::json()
2. GET routes are public, POST/PUT/PATCH/DELETE require auth by default
3. Use ->noAuth() to make write routes public, ->secure() to protect GET routes
4. Every template extends base.twig
5. All schema changes via migrations — never create tables in route code
6. Use built-in features — never install packages for things Tina4 already provides
7. Service pattern — complex logic in src/app/, routes stay thin
8. PHPDoc every public method with @param/@return/@throws describing the behaviour, not the fix; no orphaned docblocks

## Built-in Features

Router, ORM, Database (SQLite/PostgreSQL/MySQL/MSSQL/Firebird), Frond templates (Twig-compatible), JWT auth, Sessions (File/Redis/Valkey/MongoDB/DB), GraphQL + GraphiQL, WebSocket + Redis backplane, WSDL/SOAP, Queue (File/RabbitMQ/Kafka/MongoDB), HTTP client, Messenger (SMTP/IMAP), FakeData/Seeder, Migrations, SCSS compiler, Swagger/OpenAPI, i18n, Events, Container/DI, HtmlElement, Inline testing, Error overlay, Dev dashboard, Rate limiter, Response cache, Logging, MCP server

## Documentation

https://tina4.com
CONTEXT;
    }

    /**
     * GitHub Copilot context (~30 lines): short header, route example, conventions, features.
     */
    private static function generateCopilotContext(string $version): string
    {
        return <<<CONTEXT
# Tina4 PHP v{$version}

Zero-dependency PHP web framework. Docs: https://tina4.com

## Route Example

```php
use Tina4\Router;

Router::get("/api/users", function(\Tina4\Request \$request, \Tina4\Response \$response) {
    return \$response(["users" => []]);
});
```

## Conventions

1. Routes return \$response() — always \$response(data) not Response::json()
2. GET routes are public, POST/PUT/PATCH/DELETE require auth by default
3. Use ->noAuth() to make write routes public, ->secure() to protect GET routes
4. Every template extends base.twig
5. All schema changes via migrations — never create tables in route code
6. Use built-in features — never install packages for things Tina4 already provides
7. Service pattern — complex logic in src/app/, routes stay thin
8. PHPDoc every public method with @param/@return/@throws describing the behaviour, not the fix; no orphaned docblocks

## Built-in Features

Router, ORM, Database (SQLite/PostgreSQL/MySQL/MSSQL/Firebird), Frond templates (Twig-compatible), JWT auth, Sessions (File/Redis/Valkey/MongoDB/DB), GraphQL + GraphiQL, WebSocket + Redis backplane, WSDL/SOAP, Queue (File/RabbitMQ/Kafka/MongoDB), HTTP client, Messenger (SMTP/IMAP), FakeData/Seeder, Migrations, SCSS compiler, Swagger/OpenAPI, i18n, Events, Container/DI, HtmlElement, Inline testing, Error overlay, Dev dashboard, Rate limiter, Response cache, Logging, MCP server
CONTEXT;
    }

    /**
     * Windsurf context (~60 lines): like cursor but with project structure added.
     */
    private static function generateWindsurfContext(string $version): string
    {
        return <<<CONTEXT
# Tina4 PHP v{$version}

Lightweight, zero-dependency PHP web framework. Docs: https://tina4.com

## Project Structure

```
src/routes/    — Route handlers (auto-discovered)
src/orm/       — ORM models
src/templates/ — Twig templates
src/app/       — Service classes
src/scss/      — SCSS (auto-compiled)
src/public/    — Static assets
src/seeds/     — Database seeders
migrations/    — SQL migration files
tests/         — PHPUnit tests
```

## Route Example

```php
use Tina4\Router;

Router::get("/api/users", function(\Tina4\Request \$request, \Tina4\Response \$response) {
    return \$response(["users" => []]);
});

Router::post("/api/users", function(\Tina4\Request \$request, \Tina4\Response \$response) {
    return \$response(["created" => \$request->body["name"]], 201);
})->noAuth();
```

## ORM Example

```php
class User extends \Tina4\ORM {
    public \$tableName = "users";
    public \$primaryKey = "id";
}
```

## Conventions

1. Routes return \$response() — always \$response(data) not Response::json()
2. GET routes are public, POST/PUT/PATCH/DELETE require auth by default
3. Use ->noAuth() to make write routes public, ->secure() to protect GET routes
4. Every template extends base.twig
5. All schema changes via migrations — never create tables in route code
6. Use built-in features — never install packages for things Tina4 already provides
7. Service pattern — complex logic in src/app/, routes stay thin
8. PHPDoc every public method with @param/@return/@throws describing the behaviour, not the fix; no orphaned docblocks

## Built-in Features

Router, ORM, Database (SQLite/PostgreSQL/MySQL/MSSQL/Firebird), Frond templates (Twig-compatible), JWT auth, Sessions (File/Redis/Valkey/MongoDB/DB), GraphQL + GraphiQL, WebSocket + Redis backplane, WSDL/SOAP, Queue (File/RabbitMQ/Kafka/MongoDB), HTTP client, Messenger (SMTP/IMAP), FakeData/Seeder, Migrations, SCSS compiler, Swagger/OpenAPI, i18n, Events, Container/DI, HtmlElement, Inline testing, Error overlay, Dev dashboard, Rate limiter, Response cache, Logging, MCP server

## Documentation

https://tina4.com
CONTEXT;
    }

    /**
     * Aider context (~58 lines): conventions-focused with patterns and structure.
     */
    private static function generateAiderContext(string $version): string
    {
        return <<<CONTEXT
# Tina4 PHP v{$version} — Conventions

Zero-dependency PHP web framework. Docs: https://tina4.com

## Conventions

1. Routes return \$response() — always \$response(data) not Response::json()
2. GET routes are public, POST/PUT/PATCH/DELETE require auth by default
3. Use ->noAuth() to make write routes public, ->secure() to protect GET routes
4. Every template extends base.twig
5. All schema changes via migrations — never create tables in route code
6. Use built-in features — never install packages for things Tina4 already provides
7. Service pattern — complex logic in src/app/, routes stay thin

## Patterns

### Route

```php
use Tina4\Router;

Router::get("/api/users", function(\Tina4\Request \$request, \Tina4\Response \$response) {
    return \$response(["users" => []]);
});

Router::post("/api/users", function(\Tina4\Request \$request, \Tina4\Response \$response) {
    return \$response(["created" => \$request->body["name"]], 201);
})->noAuth();
```

### ORM

```php
class User extends \Tina4\ORM {
    public \$tableName = "users";
    public \$primaryKey = "id";
}
```

## Project Structure

```
src/routes/    — Route handlers (auto-discovered)
src/orm/       — ORM models
src/templates/ — Twig templates
src/app/       — Service classes
src/scss/      — SCSS (auto-compiled)
src/public/    — Static assets
src/seeds/     — Database seeders
migrations/    — SQL migration files
tests/         — PHPUnit tests
```

## Built-in Features

Router, ORM, Database (SQLite/PostgreSQL/MySQL/MSSQL/Firebird), Frond templates (Twig-compatible), JWT auth, Sessions (File/Redis/Valkey/MongoDB/DB), GraphQL + GraphiQL, WebSocket + Redis backplane, WSDL/SOAP, Queue (File/RabbitMQ/Kafka/MongoDB), HTTP client, Messenger (SMTP/IMAP), FakeData/Seeder, Migrations, SCSS compiler, Swagger/OpenAPI, i18n, Events, Container/DI, HtmlElement, Inline testing, Error overlay, Dev dashboard, Rate limiter, Response cache, Logging, MCP server
CONTEXT;
    }

    /**
     * Cline context (~42 lines): like cursor — shares the Cursor context body.
     */
    private static function generateClineContext(string $version): string
    {
        return self::cursorStyleContext($version);
    }

    /**
     * OpenAI Codex context (~70 lines): task-oriented with CLI commands and full structure.
     */
    private static function generateCodexContext(string $version): string
    {
        return <<<CONTEXT
# Tina4 PHP v{$version}

Lightweight, zero-dependency PHP web framework. Docs: https://tina4.com

## CLI Commands

```bash
composer install              # Install dependencies
bin/tina4php serve            # Start dev server on port 7145
bin/tina4php migrate          # Run database migrations
composer test                 # Run test suite
bin/tina4php routes           # List all registered routes
```

## Project Structure

```
src/routes/    — Route handlers (auto-discovered)
src/orm/       — ORM models
src/templates/ — Twig templates
src/app/       — Service classes
src/scss/      — SCSS (auto-compiled)
src/public/    — Static assets
src/seeds/     — Database seeders
migrations/    — SQL migration files
tests/         — PHPUnit tests
```

## Route Example

```php
use Tina4\Router;

Router::get("/api/users", function(\Tina4\Request \$request, \Tina4\Response \$response) {
    return \$response(["users" => []]);
});

Router::post("/api/users", function(\Tina4\Request \$request, \Tina4\Response \$response) {
    return \$response(["created" => \$request->body["name"]], 201);
})->noAuth();
```

## ORM Example

```php
class User extends \Tina4\ORM {
    public \$tableName = "users";
    public \$primaryKey = "id";
}
```

## Conventions

1. Routes return \$response() — always \$response(data) not Response::json()
2. GET routes are public, POST/PUT/PATCH/DELETE require auth by default
3. Use ->noAuth() to make write routes public, ->secure() to protect GET routes
4. Every template extends base.twig
5. All schema changes via migrations — never create tables in route code
6. Use built-in features — never install packages for things Tina4 already provides
7. Service pattern — complex logic in src/app/, routes stay thin
8. PHPDoc every public method with @param/@return/@throws describing the behaviour, not the fix; no orphaned docblocks

## Built-in Features

Router, ORM, Database (SQLite/PostgreSQL/MySQL/MSSQL/Firebird), Frond templates (Twig-compatible), JWT auth, Sessions (File/Redis/Valkey/MongoDB/DB), GraphQL + GraphiQL, WebSocket + Redis backplane, WSDL/SOAP, Queue (File/RabbitMQ/Kafka/MongoDB), HTTP client, Messenger (SMTP/IMAP), FakeData/Seeder, Migrations, SCSS compiler, Swagger/OpenAPI, i18n, Events, Container/DI, HtmlElement, Inline testing, Error overlay, Dev dashboard, Rate limiter, Response cache, Logging, MCP server

## Documentation

https://tina4.com
CONTEXT;
    }

    /**
     * Check if a command exists on the system.
     */
    private static function commandExists(string $command): bool
    {
        $check = PHP_OS_FAMILY === 'Windows' ? "where {$command} 2>NUL" : "which {$command} 2>/dev/null";
        $output = [];
        $returnCode = 0;
        exec($check, $output, $returnCode);
        return $returnCode === 0;
    }
}
