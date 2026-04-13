<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Zero-dependency SCSS-to-CSS compiler.
 *
 * Supported SCSS subset:
 *   - Variables: $color: #333;
 *   - Nesting with & parent selector
 *   - Single-line comments (//, stripped) and multi-line comments preserved
 *   - @import with file resolution
 *   - @mixin / @include with parameters
 *   - Basic math in values (+, -, *, /)
 */
class ScssCompiler
{
    /** @var string[] Import search paths */
    private array $importPaths = [];

    /** @var array<string, string> Preset variables */
    private array $presetVariables = [];

    /**
     * @param array $config Optional configuration: 'import_paths' => string[], 'variables' => array
     */
    public function __construct(array $config = [])
    {
        if (isset($config['import_paths']) && is_array($config['import_paths'])) {
            $this->importPaths = $config['import_paths'];
        }
        if (isset($config['variables']) && is_array($config['variables'])) {
            $this->presetVariables = $config['variables'];
        }
    }

    /**
     * Compile an SCSS string to CSS.
     */
    public function compile(string $source): string
    {
        return $this->doCompile($source);
    }

    /**
     * Compile an SCSS file to CSS.
     */
    public function compileFile(string $path): string
    {
        if (!is_file($path)) {
            return '';
        }

        // Add the file's directory as an import path
        $dir = dirname(realpath($path));
        if (!in_array($dir, $this->importPaths, true)) {
            array_unshift($this->importPaths, $dir);
        }

        $source = file_get_contents($path);
        if ($source === false) {
            return '';
        }

        $imported = [realpath($path)];
        $source = $this->resolveImports($source, $dir, $imported);

        return $this->doCompile($source);
    }

    /**
     * Add a path to search for @import files.
     */
    public function addImportPath(string $path): void
    {
        $this->importPaths[] = $path;
    }

    /**
     * Set a variable that will be available during compilation.
     */
    public function setVariable(string $name, string $value): void
    {
        // Strip leading $ if provided
        $name = ltrim($name, '$');
        $this->presetVariables[$name] = $value;
    }

    /**
     * Compile all .scss files in a directory into a single CSS output file.
     *
     * Non-partial files (not starting with _) are compiled in alphabetical order.
     * Returns the compiled CSS string.
     */
    public function compileScss(string $scssDir = 'src/scss', string $output = 'src/public/css/default.css', bool $minify = false): string
    {
        if (!is_dir($scssDir)) {
            return '';
        }

        $files = glob($scssDir . '/*.scss');
        if ($files === false || empty($files)) {
            return '';
        }

        // Filter out partials and sort
        $files = array_filter($files, function ($f) {
            return !str_starts_with(basename($f), '_');
        });
        sort($files);

        if (empty($files)) {
            return '';
        }

        // Add the scss dir as an import path
        if (!in_array($scssDir, $this->importPaths, true)) {
            array_unshift($this->importPaths, $scssDir);
        }

        // Merge all files, resolving imports
        $merged = '';
        $imported = [];
        foreach ($files as $file) {
            $real = realpath($file);
            if ($real === false) {
                continue;
            }
            $imported[] = $real;
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            $content = $this->resolveImports($content, dirname($real), $imported);
            $merged .= $content . "\n";
        }

        $css = $this->doCompile($merged);

        if ($minify) {
            $css = $this->minify($css);
        }

        // Write output only if content changed (avoids triggering DevReload loops)
        $outDir = dirname($output);
        if (!is_dir($outDir)) {
            mkdir($outDir, 0777, true);
        }
        $existing = file_exists($output) ? file_get_contents($output) : null;
        if ($existing !== $css) {
            file_put_contents($output, $css);
        }

        return $css;
    }

    // ── Main compilation pipeline ───────────────────────────────

    private function doCompile(string $scss): string
    {
        // 1. Strip single-line comments (but not inside strings or URLs)
        $scss = preg_replace('#(?<![:\"\'])//[^\n]*#', '', $scss);

        // 2. Extract and store variables
        $variables = $this->presetVariables;
        $scss = $this->extractVariables($scss, $variables);

        // 3. Extract mixins
        $mixins = [];
        $scss = $this->extractMixins($scss, $mixins);

        // 4. Resolve @include
        $scss = $this->resolveIncludes($scss, $mixins);

        // 5. Substitute variables
        $scss = $this->substituteVariables($scss, $variables);

        // 6. Evaluate math expressions
        $scss = $this->evalMath($scss);

        // 7. Flatten nesting
        $css = $this->flattenNesting($scss);

        // 8. Clean up
        $css = $this->cleanup($css);

        return $css;
    }

    // ── Import resolution ───────────────────────────────────────

    private function resolveImports(string $content, string $baseDir, array &$imported): string
    {
        return preg_replace_callback(
            '#@import\s+["\']?([^"\';\n]+)["\']?\s*;#',
            function ($m) use ($baseDir, &$imported) {
                $name = trim($m[1], "\"' ");
                $candidates = [
                    $baseDir . '/' . $name . '.scss',
                    $baseDir . '/_' . $name . '.scss',
                    $baseDir . '/' . $name,
                ];
                foreach ($this->importPaths as $importPath) {
                    $candidates[] = $importPath . '/' . $name . '.scss';
                    $candidates[] = $importPath . '/_' . $name . '.scss';
                    $candidates[] = $importPath . '/' . $name;
                }

                foreach ($candidates as $candidate) {
                    if (is_file($candidate)) {
                        $real = realpath($candidate);
                        if (in_array($real, $imported, true)) {
                            return '';
                        }
                        $imported[] = $real;
                        $fileContent = file_get_contents($candidate);
                        if ($fileContent === false) {
                            return "/* IMPORT NOT FOUND: $name */";
                        }
                        return $this->resolveImports($fileContent, dirname($real), $imported);
                    }
                }
                return "/* IMPORT NOT FOUND: $name */";
            },
            $content
        );
    }

    // ── Variable extraction ─────────────────────────────────────

    private function extractVariables(string $scss, array &$variables): string
    {
        return preg_replace_callback(
            '#\$([a-zA-Z_][\w-]*)\s*:\s*([^;]+);#',
            function ($m) use (&$variables) {
                $name = $m[1];
                $value = trim($m[2]);
                // Resolve variable references in the value
                foreach ($variables as $varName => $varVal) {
                    $value = str_replace('$' . $varName, $varVal, $value);
                }
                $variables[$name] = $value;
                return '';
            },
            $scss
        );
    }

    private function substituteVariables(string $scss, array $variables): string
    {
        // Sort by longest name first to avoid partial matches
        $names = array_keys($variables);
        usort($names, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        foreach ($names as $name) {
            $scss = str_replace('$' . $name, $variables[$name], $scss);
        }
        return $scss;
    }

    // ── Mixin extraction ────────────────────────────────────────

    private function extractMixins(string $scss, array &$mixins): string
    {
        // Find all @mixin definitions
        $pattern = '#@mixin\s+([\w-]+)\s*(?:\(([^)]*)\))?\s*\{#';
        preg_match_all($pattern, $scss, $matches, PREG_OFFSET_CAPTURE);

        for ($i = count($matches[0]) - 1; $i >= 0; $i--) {
            $name = $matches[1][$i][0];
            $paramsStr = $matches[2][$i][0] ?? '';
            $params = [];
            if ($paramsStr !== '') {
                foreach (explode(',', $paramsStr) as $p) {
                    $p = trim($p);
                    if ($p !== '') {
                        $params[] = ltrim($p, '$');
                    }
                }
            }

            $bracePos = $matches[0][$i][1] + strlen($matches[0][$i][0]);
            $body = $this->findBlock($scss, $bracePos);
            if ($body !== null) {
                $mixins[$name] = ['params' => $params, 'body' => $body];
                // Remove the mixin definition from source
                $fullLength = strlen($matches[0][$i][0]) + strlen($body) + 1; // +1 for closing }
                $scss = substr($scss, 0, $matches[0][$i][1]) . substr($scss, $matches[0][$i][1] + $fullLength);
            }
        }

        return $scss;
    }

    private function resolveIncludes(string $scss, array $mixins): string
    {
        return preg_replace_callback(
            '#@include\s+([\w-]+)\s*(?:\(([^)]*)\))?\s*;#',
            function ($m) use ($mixins) {
                $name = $m[1];
                $argsStr = $m[2] ?? '';
                $args = [];
                if ($argsStr !== '') {
                    $args = array_map('trim', explode(',', $argsStr));
                }

                if (!isset($mixins[$name])) {
                    return "/* MIXIN NOT FOUND: $name */";
                }

                $mixin = $mixins[$name];
                $body = $mixin['body'];

                // Substitute parameters
                foreach ($mixin['params'] as $i => $param) {
                    $paramName = explode(':', $param)[0];
                    $paramName = trim($paramName);
                    $default = strpos($param, ':') !== false ? trim(explode(':', $param, 2)[1]) : '';
                    $value = $args[$i] ?? $default;
                    $body = str_replace('$' . $paramName, $value, $body);
                }

                return $body;
            },
            $scss
        );
    }

    // ── Math evaluation ─────────────────────────────────────────

    private function evalMath(string $scss): string
    {
        return preg_replace_callback(
            '#([\d.]+[a-z%]*)\s*([+\-*/])\s*([\d.]+[a-z%]*)#',
            function ($m) {
                try {
                    preg_match('#[\d.]+#', $m[1], $num1Match);
                    preg_match('#[\d.]+#', $m[3], $num2Match);
                    if (empty($num1Match) || empty($num2Match)) {
                        return $m[0];
                    }
                    $num1 = (float)$num1Match[0];
                    $num2 = (float)$num2Match[0];

                    preg_match('#[a-z%]+#', $m[1], $unitMatch);
                    $unit = $unitMatch[0] ?? '';

                    $op = trim($m[2]);
                    switch ($op) {
                        case '+': $result = $num1 + $num2; break;
                        case '-': $result = $num1 - $num2; break;
                        case '*': $result = $num1 * $num2; break;
                        case '/': $result = $num2 != 0 ? $num1 / $num2 : 0; break;
                        default: return $m[0];
                    }

                    if ($result == (int)$result) {
                        return (int)$result . $unit;
                    }
                    return sprintf('%.2f', $result) . $unit;
                } catch (\Throwable $e) {
                    return $m[0];
                }
            },
            $scss
        );
    }

    // ── Nesting flattener ───────────────────────────────────────

    private function flattenNesting(string $scss): string
    {
        $output = [];
        $this->flattenBlock($scss, [], $output);
        return implode("\n", $output);
    }

    /**
     * Recursively flatten nested SCSS blocks into flat CSS rules.
     *
     * @param string   $content          The SCSS content to process
     * @param string[] $parentSelectors  Accumulated parent selectors
     * @param string[] $output           Output lines (by reference)
     */
    private function flattenBlock(string $content, array $parentSelectors, array &$output): void
    {
        $pos = 0;
        $properties = [];
        $len = strlen($content);

        while ($pos < $len) {
            // Skip whitespace
            while ($pos < $len && strpos(" \t\n\r", $content[$pos]) !== false) {
                $pos++;
            }
            if ($pos >= $len) {
                break;
            }

            // Block comment — preserve
            if ($pos + 1 < $len && $content[$pos] === '/' && $content[$pos + 1] === '*') {
                $end = strpos($content, '*/', $pos + 2);
                if ($end === false) {
                    break;
                }
                $comment = substr($content, $pos, $end + 2 - $pos);
                $output[] = $comment;
                $pos = $end + 2;
                continue;
            }

            // @media — special handling
            if (substr($content, $pos, 6) === '@media') {
                $brace = strpos($content, '{', $pos);
                if ($brace === false) {
                    break;
                }
                $mediaQuery = trim(substr($content, $pos, $brace - $pos));
                $body = $this->findBlock($content, $brace + 1);
                if ($body === null) {
                    break;
                }
                $pos = $brace + 1 + strlen($body) + 1;

                $innerOutput = [];
                $this->flattenBlock($body, $parentSelectors, $innerOutput);
                if (!empty($innerOutput)) {
                    $output[] = $mediaQuery . ' {';
                    foreach ($innerOutput as $line) {
                        $output[] = '  ' . $line;
                    }
                    $output[] = '}';
                }
                continue;
            }

            // Find next { or ;
            $bracePos = strpos($content, '{', $pos);
            $semiPos = strpos($content, ';', $pos);

            // Property (has ; before {, or no { at all)
            if ($semiPos !== false && ($bracePos === false || $semiPos < $bracePos)) {
                $prop = trim(substr($content, $pos, $semiPos - $pos));
                if ($prop !== '' && $prop[0] !== '@') {
                    $properties[] = $prop;
                }
                $pos = $semiPos + 1;
                continue;
            }

            // Nested block
            if ($bracePos !== false) {
                $selectorText = trim(substr($content, $pos, $bracePos - $pos));
                $body = $this->findBlock($content, $bracePos + 1);
                if ($body === null) {
                    break;
                }
                $pos = $bracePos + 1 + strlen($body) + 1;

                if ($selectorText === '') {
                    continue;
                }

                // Expand selectors with parent reference (&)
                $selectors = array_map('trim', explode(',', $selectorText));
                $newSelectors = [];
                foreach ($selectors as $sel) {
                    if (!empty($parentSelectors)) {
                        foreach ($parentSelectors as $parent) {
                            if (strpos($sel, '&') !== false) {
                                $newSelectors[] = str_replace('&', $parent, $sel);
                            } else {
                                $newSelectors[] = $parent . ' ' . $sel;
                            }
                        }
                    } else {
                        $newSelectors[] = $sel;
                    }
                }

                $this->flattenBlock($body, $newSelectors, $output);
                continue;
            }

            // Remaining text — treat as property
            $remaining = trim(substr($content, $pos));
            if ($remaining !== '') {
                $properties[] = $remaining;
            }
            break;
        }

        // Emit properties for current selector
        if (!empty($properties) && !empty($parentSelectors)) {
            $selectorStr = implode(', ', $parentSelectors);
            $output[] = $selectorStr . ' {';
            foreach ($properties as $prop) {
                $output[] = '  ' . $prop . ';';
            }
            $output[] = '}';
        }
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * Find content between matched braces starting after the opening brace.
     */
    private function findBlock(string $content, int $start): ?string
    {
        $depth = 1;
        $pos = $start;
        $len = strlen($content);
        while ($pos < $len && $depth > 0) {
            if ($content[$pos] === '{') {
                $depth++;
            } elseif ($content[$pos] === '}') {
                $depth--;
            }
            if ($depth > 0) {
                $pos++;
            }
        }
        if ($depth === 0) {
            return substr($content, $start, $pos - $start);
        }
        return null;
    }

    /**
     * Minify CSS output.
     */
    private function minify(string $css): string
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $css);  // Remove comments
        $css = preg_replace('#\s+#', ' ', $css);         // Collapse whitespace
        $css = preg_replace('#\s*([{}:;,])\s*#', '$1', $css);  // Remove space around punctuation
        $css = str_replace(';}', '}', $css);             // Remove last semicolon before }
        return trim($css);
    }

    /**
     * Clean up the output CSS.
     */
    private function cleanup(string $css): string
    {
        // Remove empty rulesets
        $css = preg_replace('#[^{}]+\{\s*\}#', '', $css);
        // Remove multiple blank lines
        $css = preg_replace('#\n{3,}#', "\n\n", $css);
        // Remove trailing whitespace per line
        $lines = explode("\n", $css);
        $lines = array_map('rtrim', $lines);
        $css = implode("\n", $lines);
        $css = trim($css);
        if ($css !== '') {
            $css .= "\n";
        }
        return $css;
    }
}
