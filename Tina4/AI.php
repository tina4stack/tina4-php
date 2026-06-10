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
class AI
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
                . "- **tina4-developer** — Read `.claude/skills/tina4-developer/SKILL.md` before building features.\n"
                . "- **tina4-js** — Read `.claude/skills/tina4-js/SKILL.md` for frontend work.\n"
                . "- **tina4-maintainer** — Read `.claude/skills/tina4-maintainer/SKILL.md` for framework-level changes.\n\n"
                . "See https://tina4.com for full docs.";
        } else {
            $body = "Tina4 Skills — read these files before working on this project:\n"
                . "  .claude/skills/tina4-developer/SKILL.md   (feature development)\n"
                . "  .claude/skills/tina4-js/SKILL.md          (frontend / tina4-js)\n"
                . "  .claude/skills/tina4-maintainer/SKILL.md  (framework-level changes)\n"
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
     * Copy Claude Code skill files from the framework's directories.
     *
     * @return string[] Files created (relative paths)
     */
    private static function installClaudeSkills(string $root): array
    {
        $created = [];

        // Determine the framework root (where Tina4/ lives)
        $frameworkRoot = dirname(__DIR__);

        // Copy skill directories from .claude/skills/ in the framework to the project
        $frameworkSkillsDir = $frameworkRoot . '/.claude/skills';
        if (is_dir($frameworkSkillsDir)) {
            $targetSkillsDir = $root . '/.claude/skills';
            if (!is_dir($targetSkillsDir)) {
                mkdir($targetSkillsDir, 0755, true);
            }
            $dirs = array_filter(glob($frameworkSkillsDir . '/*'), 'is_dir');
            foreach ($dirs as $skillDir) {
                $dirName = basename($skillDir);
                $targetDir = $targetSkillsDir . '/' . $dirName;
                if (is_dir($targetDir)) {
                    self::removeDirectory($targetDir);
                }
                self::copyDirectory($skillDir, $targetDir);
                $relative = ltrim(str_replace($root, '', $targetDir), '/');
                $created[] = $relative;
                echo "  \e[32m[OK]\e[0m Updated {$relative}\n";
            }
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
     * Cline context (~42 lines): like cursor.
     */
    private static function generateClineContext(string $version): string
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

## Built-in Features

Router, ORM, Database (SQLite/PostgreSQL/MySQL/MSSQL/Firebird), Frond templates (Twig-compatible), JWT auth, Sessions (File/Redis/Valkey/MongoDB/DB), GraphQL + GraphiQL, WebSocket + Redis backplane, WSDL/SOAP, Queue (File/RabbitMQ/Kafka/MongoDB), HTTP client, Messenger (SMTP/IMAP), FakeData/Seeder, Migrations, SCSS compiler, Swagger/OpenAPI, i18n, Events, Container/DI, HtmlElement, Inline testing, Error overlay, Dev dashboard, Rate limiter, Response cache, Logging, MCP server

## Documentation

https://tina4.com
CONTEXT;
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
bin/tina4php serve            # Start dev server on port 7146
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

    /**
     * Recursively copy a directory.
     */
    private static function copyDirectory(string $source, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }
        $items = scandir($source);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $srcPath = $source . '/' . $item;
            $dstPath = $dest . '/' . $item;
            if (is_dir($srcPath)) {
                self::copyDirectory($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
    }

    /**
     * Recursively remove a directory.
     */
    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
