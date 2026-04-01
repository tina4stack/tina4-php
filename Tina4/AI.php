<?php

/**
 * Tina4 - This is not a 4ramework.
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

        $context = self::generateContext();

        foreach ($indices as $idx) {
            $tool = self::$AI_TOOLS[$idx];
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

        // Always overwrite — user chose to install
        file_put_contents($contextPath, $context);
        $action = file_exists($contextPath) ? "Updated" : "Installed";
        $relative = ltrim(str_replace($root, '', $contextPath), '/');
        $created[] = $relative;
        echo "  \e[32m✓\e[0m {$action} {$relative}\n";

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
                echo "  \e[32m✓\e[0m Installed tina4-ai (mdview)\n";
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
                echo "  \e[32m✓\e[0m Updated {$relative}\n";
            }
        }

        return $created;
    }

    /**
     * Generate the universal Tina4 PHP context document for any AI assistant.
     */
    public static function generateContext(): string
    {
        return <<<'CONTEXT'
# Tina4 PHP — AI Context

This project uses **Tina4 PHP**, a lightweight, batteries-included web framework
with zero third-party dependencies for core features.

**Documentation:** https://tina4.com

## Quick Start

```bash
composer install              # Install dependencies
bin/tina4php serve            # Start dev server on port 7146
bin/tina4php migrate          # Run database migrations
composer test                 # Run test suite
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
Tina4/            — Core framework classes (namespace Tina4\)
```

## Built-in Features (No External Packages Needed)

| Feature | Class | Usage |
|---------|-------|-------|
| Routing | Router | `Router::get("/path", function($request, $response) { ... })` |
| ORM | ORM | `class Widget extends \Tina4\ORM { ... }` |
| Database | Database | `\Tina4\Database\Database::create("sqlite:///app.db")` |
| Templates | Frond | `$response->template("page.twig", $data)` |
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
| Sessions | Session | `\Tina4\Session::get($key)` / `::set($key, $value)` |
| Middleware | Middleware | `->middleware([MyMiddleware::class])` |
| HTML Builder | HtmlElement | `new \Tina4\HtmlElement("div", ["class" => "card"])` |
| Events | Events | `Events::on("user.created", $callback)` |
| DI Container | Container | `$container->singleton("db", fn() => ...)` |
| Response Cache | ResponseCache | `new \Tina4\Middleware\ResponseCache()` |
| Error Overlay | ErrorOverlay | `ErrorOverlay::render($exception)` |
| Inline Testing | Testing | `Testing::tests([...], $fn, "name")` |

## Key Conventions

1. **Routes return `$response()`** — always use `$response($data)` not `json_encode()`
2. **GET routes are public**, POST/PUT/PATCH/DELETE require auth by default
3. **Use `->secure(false)`** to make write routes public, `->secure()` to protect GET routes
4. **Every template extends `base.twig`** — no standalone HTML pages
5. **No inline styles** — use SCSS in `src/scss/` with CSS variables
6. **No hardcoded colors** — use `var(--primary)`, `var(--text)`, etc.
7. **All schema changes via migrations** — never create tables in route code
8. **Service pattern** — complex logic goes in `src/services/` classes, routes stay thin
9. **Use built-in features** — never install packages for things Tina4 already provides
10. **PSR-4 autoloading** — namespace `Tina4\` for core, `\` for user app code

## AI Workflow — Available Skills

When using an AI coding assistant with Tina4, these skills are available:

| Skill | Description |
|-------|-------------|
| `/tina4-route` | Create a new route with proper decorators and auth |
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

## Common Patterns

### Route
```php
use Tina4\Router;

Router::get("/api/widgets", function(\Tina4\Request $request, \Tina4\Response $response) {
    return $response(["items" => []]);
});

Router::post("/api/widgets", function(\Tina4\Request $request, \Tina4\Response $response) {
    $data = $request->body;
    return $response(["created" => true], 201);
})->secure(false);  // Remove ->secure(false) to require Bearer token auth
```

### ORM Model
```php
class Widget extends \Tina4\ORM {
    public $tableName = "widgets";
    public $primaryKey = "id";
    public ?int $id = null;
    public ?string $name = null;
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
