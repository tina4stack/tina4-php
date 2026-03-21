<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Detect AI coding assistants and scaffold framework-aware context files.
 *
 * Supports: Claude Code, Cursor, GitHub Copilot, Windsurf, Aider, Cline, OpenAI Codex.
 */
class AI
{
    /** @var array<string, array{description: string, config_dir: ?string, context_file: string, detect: array}> */
    private static array $tools = [
        "claude-code" => [
            "description" => "Claude Code (Anthropic CLI)",
            "config_dir" => ".claude",
            "context_file" => "CLAUDE.md",
            "detect" => ["dir" => ".claude", "file" => "CLAUDE.md"],
        ],
        "cursor" => [
            "description" => "Cursor IDE",
            "config_dir" => ".cursor",
            "context_file" => ".cursorules",
            "detect" => ["dir" => ".cursor", "file" => ".cursorules"],
        ],
        "copilot" => [
            "description" => "GitHub Copilot",
            "config_dir" => ".github",
            "context_file" => ".github/copilot-instructions.md",
            "detect" => ["dir" => ".github", "file" => ".github/copilot-instructions.md"],
        ],
        "windsurf" => [
            "description" => "Windsurf (Codeium)",
            "config_dir" => null,
            "context_file" => ".windsurfrules",
            "detect" => ["file" => ".windsurfrules"],
        ],
        "aider" => [
            "description" => "Aider",
            "config_dir" => null,
            "context_file" => "CONVENTIONS.md",
            "detect" => ["file" => ".aider.conf.yml", "file2" => "CONVENTIONS.md"],
        ],
        "cline" => [
            "description" => "Cline (VS Code)",
            "config_dir" => null,
            "context_file" => ".clinerules",
            "detect" => ["file" => ".clinerules"],
        ],
        "codex" => [
            "description" => "OpenAI Codex CLI",
            "config_dir" => null,
            "context_file" => "AGENTS.md",
            "detect" => ["file" => "AGENTS.md", "file2" => "codex.md"],
        ],
    ];

    /**
     * Check whether a single AI tool is detected at the given root.
     */
    private static function isDetected(string $root, array $detect): bool
    {
        if (isset($detect['dir']) && is_dir($root . '/' . $detect['dir'])) {
            return true;
        }
        if (isset($detect['file']) && file_exists($root . '/' . $detect['file'])) {
            return true;
        }
        if (isset($detect['file2']) && file_exists($root . '/' . $detect['file2'])) {
            return true;
        }
        return false;
    }

    /**
     * Detect which AI coding tools are present in the project.
     *
     * @param string $root Project root directory
     * @return array<int, array{name: string, description: string, installed: bool}>
     */
    public static function detectAi(string $root = "."): array
    {
        $root = realpath($root) ?: $root;
        $detected = [];

        foreach (self::$tools as $name => $tool) {
            $detected[] = [
                "name" => $name,
                "description" => $tool["description"],
                "installed" => self::isDetected($root, $tool["detect"]),
            ];
        }

        return $detected;
    }

    /**
     * Return just the names of detected AI tools.
     *
     * @param string $root Project root directory
     * @return string[]
     */
    public static function detectAiNames(string $root = "."): array
    {
        $names = [];
        foreach (self::detectAi($root) as $tool) {
            if ($tool["installed"]) {
                $names[] = $tool["name"];
            }
        }
        return $names;
    }

    /**
     * Generate a universal Tina4 PHP context document for any AI assistant.
     *
     * @param bool $includeSkills Whether to include the AI skills table
     * @return string Markdown context document
     */
    public static function generateContext(bool $includeSkills = true): string
    {
        $skillsSection = "";
        if ($includeSkills) {
            $skillsSection = <<<'SKILLS'


## AI Workflow — Available Skills

When using an AI coding assistant with Tina4, these skills are available:

| Skill | Description |
|-------|-------------|
| `/tina4-route` | Create a new route with proper auth |
| `/tina4-orm` | Create an ORM model with migration |
| `/tina4-crud` | Generate complete CRUD (migration, ORM, routes, template, tests) |
| `/tina4-auth` | Set up JWT authentication with login/register |
| `/tina4-api` | Create an external API integration |
| `/tina4-queue` | Set up background job processing |
| `/tina4-template` | Create a server-rendered template page |
| `/tina4-graphql` | Set up a GraphQL endpoint |
| `/tina4-websocket` | Set up WebSocket communication |
| `/tina4-wsdl` | Create a SOAP/WSDL service |
| `/tina4-messenger` | Set up email send/receive |
| `/tina4-test` | Write tests for a feature |
| `/tina4-migration` | Create a database migration |
| `/tina4-seed` | Generate fake data for development |
| `/tina4-i18n` | Set up internationalization |
| `/tina4-scss` | Set up SCSS stylesheets |
| `/tina4-frontend` | Set up a frontend framework |
SKILLS;
        }

        return <<<CONTEXT
# Tina4 PHP — AI Context

This project uses **Tina4 PHP**, a lightweight, batteries-included web framework
with zero third-party dependencies for core features.

**Documentation:** https://tina4.com

## Quick Start

```bash
composer install              # Install dependencies
composer start                # Start dev server
composer run-tests            # Run test suite
bin/tina4php migrate          # Run database migrations
bin/tina4php routes           # List all registered routes
```

## Project Structure

```
src/routes/       — Route handlers (auto-discovered, one per resource)
src/orm/          — ORM models (one per file, filename = class name)
src/templates/    — Twig templates (extends base.twig)
src/app/          — Shared helpers and service classes
src/scss/         — SCSS files (auto-compiled to public/css/)
src/public/       — Static assets served at /
src/locales/      — Translation JSON files
src/seeds/        — Database seeder scripts
migrations/       — SQL migration files (sequential numbered)
tests/            — PHPUnit test files
```

## Built-in Features (No External Packages Needed)

| Feature | Class | Usage |
|---------|-------|-------|
| Routing | Router | `Route::get("/path", function(\$request, \$response) { ... })` |
| ORM | ORM | `class Widget extends \Tina4\ORM { ... }` |
| Database | DataBase | `new \Tina4\DataBase("sqlite3:app.db")` |
| Templates | Twig | `\$response->render("page.twig", \$data)` |
| JWT Auth | Auth | `new \Tina4\Auth()` |
| REST API Client | Api | `new \Tina4\Api("https://api.example.com")` |
| GraphQL | GraphQL | `new \Tina4\GraphQL()` |
| WebSocket | WebSocket | `new \Tina4\WebSocket()` |
| SOAP/WSDL | WSDL | `new \Tina4\WSDL()` |
| Email (SMTP+IMAP) | Messenger | `new \Tina4\Messenger()` |
| Background Queue | Queue | `new \Tina4\Queue()` |
| SCSS Compilation | ScssCompiler | Auto-compiled from src/scss/ |
| Migrations | Migration | `new \Tina4\Migration()` |
| Seeder | FakeData | `new \Tina4\FakeData()` |
| i18n | I18n | `new \Tina4\I18n()` |
| Swagger/OpenAPI | Swagger | Auto-generated at /swagger |
| Sessions | Session | `\Tina4\Session::get(\$key)` / `::set(\$key, \$value)` |
| Middleware | Middleware | `->middleware([MyMiddleware::class])` |

## Key Conventions

1. **Routes return `\$response()`** — always use `\$response(\$data)` not `json_encode()`
2. **GET routes are public**, POST/PUT/PATCH/DELETE require auth by default
3. **Use `->secure(false)`** to make write routes public, `->secure()` to protect GET routes
4. **Every template extends `base.twig`** — no standalone HTML pages
5. **No inline styles** — use SCSS in `src/scss/` with CSS variables
6. **No hardcoded colors** — use `var(--primary)`, `var(--text)`, etc.
7. **All schema changes via migrations** — never create tables in route code
8. **Service pattern** — complex logic goes in `src/services/` classes, routes stay thin
9. **Use built-in features** — never install packages for things Tina4 already provides
10. **PSR-4 autoloading** — namespace `Tina4\` for core, `\` for user app code
{$skillsSection}

## Common Patterns

### Route
```php
Route::post("/api/widgets", function(\$request, \$response) {
    \$data = \$request->body;
    return \$response(["created" => true], 201);
})->secure(false);
```

### ORM Model
```php
class Widget extends \Tina4\ORM {
    public \$tableName = "widgets";
    public \$primaryKey = "id";
    public ?int \$id = null;
    public ?string \$name = null;
}
```

### Template
```twig
{% extends "base.twig" %}
{% block content %}
<div class="container">
    <h1>{{ title }}</h1>
    {% for item in items %}
        <p>{{ item.name }}</p>
    {% endfor %}
</div>
{% endblock %}
```
CONTEXT;
    }

    /**
     * Install Tina4 context files for detected (or specified) AI tools.
     *
     * @param string $root  Project root directory
     * @param string[]|null $tools Specific tool names to install for (null = auto-detect)
     * @param bool $force   Overwrite existing context files
     * @return string[] List of files created/updated (relative to root)
     */
    public static function installContext(string $root = ".", ?array $tools = null, bool $force = false): array
    {
        $root = realpath($root) ?: $root;
        $created = [];

        if ($tools === null) {
            $tools = self::detectAiNames($root);
        }

        $context = self::generateContext(true);

        foreach ($tools as $toolName) {
            if (!isset(self::$tools[$toolName])) {
                continue;
            }
            $files = self::installForTool($root, $toolName, self::$tools[$toolName], $context, $force);
            $created = array_merge($created, $files);
        }

        return $created;
    }

    /**
     * Install Tina4 context for ALL known AI tools (not just detected ones).
     *
     * @param string $root  Project root directory
     * @param bool $force   Overwrite existing context files
     * @return string[] List of files created/updated (relative to root)
     */
    public static function installAll(string $root = ".", bool $force = false): array
    {
        $root = realpath($root) ?: $root;
        $created = [];
        $context = self::generateContext(true);

        foreach (self::$tools as $toolName => $tool) {
            $files = self::installForTool($root, $toolName, $tool, $context, $force);
            $created = array_merge($created, $files);
        }

        return $created;
    }

    /**
     * Install context files for a specific AI tool.
     *
     * @return string[] Files created (relative paths)
     */
    private static function installForTool(string $root, string $name, array $tool, string $context, bool $force): array
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

        if (!file_exists($contextPath) || $force) {
            $adapted = self::adaptContext($name, $context);
            file_put_contents($contextPath, $adapted);
            // Store relative path
            $relative = ltrim(str_replace($root, '', $contextPath), '/');
            $created[] = $relative;
        }

        // Install Claude Code skills if it's Claude
        if ($name === 'claude-code') {
            $skills = self::installClaudeSkills($root, $force);
            $created = array_merge($created, $skills);
        }

        return $created;
    }

    /**
     * Copy Claude Code skill files from the framework's directories.
     *
     * @return string[] Files created (relative paths)
     */
    private static function installClaudeSkills(string $root, bool $force): array
    {
        $created = [];

        // Determine the framework root (where Tina4/ lives)
        $frameworkRoot = dirname(__DIR__);

        // Copy .skill files from the framework's skills/ directory to project root
        $skillsSource = $frameworkRoot . '/skills';
        if (is_dir($skillsSource)) {
            $skillFiles = glob($skillsSource . '/*.skill');
            foreach ($skillFiles as $skillFile) {
                $target = $root . '/' . basename($skillFile);
                if (!file_exists($target) || $force) {
                    copy($skillFile, $target);
                    $created[] = basename($skillFile);
                }
            }
        }

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
                if (!is_dir($targetDir) || $force) {
                    self::copyDirectory($skillDir, $targetDir);
                    $relative = ltrim(str_replace($root, '', $targetDir), '/');
                    $created[] = $relative;
                }
            }
        }

        return $created;
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
     * Adapt the universal context document for a specific tool's format.
     */
    private static function adaptContext(string $toolName, string $context): string
    {
        // All tools currently use the same markdown format
        return $context;
    }

    /**
     * Generate a human-readable report of AI tool detection.
     *
     * @param string $root Project root directory
     * @return string Formatted status report
     */
    public static function statusReport(string $root = "."): string
    {
        $tools = self::detectAi($root);
        $installed = array_filter($tools, fn($t) => $t['installed']);
        $missing = array_filter($tools, fn($t) => !$t['installed']);

        $lines = ["\nTina4 AI Context Status\n"];

        if (!empty($installed)) {
            $lines[] = "Detected AI tools:";
            foreach ($installed as $t) {
                $lines[] = "  [x] {$t['description']} ({$t['name']})";
            }
        } else {
            $lines[] = "No AI coding tools detected.";
        }

        if (!empty($missing)) {
            $lines[] = "\nNot detected (install context with `bin/tina4php ai --all`):";
            foreach ($missing as $t) {
                $lines[] = "  [ ] {$t['description']} ({$t['name']})";
            }
        }

        $lines[] = "";
        return implode("\n", $lines);
    }
}
