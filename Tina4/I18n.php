<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Zero-dependency internationalization (i18n) support with JSON translation files.
 *
 * Locale files: src/locales/en.json, src/locales/fr.json, etc.
 * Format: {"key": "translated value", "nested": {"key": "value"}}
 * Parameter substitution: "Hello {name}" with ['name' => 'World']
 */
class I18n
{
    /** @var string Directory containing locale JSON files */
    private string $localeDir;

    /** @var string Default locale code */
    private string $defaultLocale;

    /** @var string Currently active locale */
    private string $currentLocale;

    /** @var array<string, array<string, string>> Loaded translations keyed by locale */
    private array $translations = [];

    /**
     * Constructor argument order is (locale, path) to match the Python master
     * I18n(locale, path). Precedence per argument is explicit > env > default:
     * an explicit constructor value wins over the TINA4_LOCALE / TINA4_LOCALE_DIR
     * env var, which in turn wins over the built-in default.
     *
     * @param string|null $locale Default locale code (e.g. 'en'). Null falls back
     *                            to TINA4_LOCALE, then 'en'.
     * @param string|null $path   Path to directory containing locale JSON files.
     *                            Null falls back to TINA4_LOCALE_DIR, then
     *                            'src/locales'.
     */
    public function __construct(?string $locale = null, ?string $path = null)
    {
        $envDir = getenv('TINA4_LOCALE_DIR');
        $envLocale = getenv('TINA4_LOCALE');

        // Explicit arg > env > default.
        $this->localeDir = $path
            ?? ($envDir !== false && $envDir !== '' ? $envDir : 'src/locales');
        $this->defaultLocale = $locale
            ?? ($envLocale !== false && $envLocale !== '' ? $envLocale : 'en');
        $this->currentLocale = $this->defaultLocale;

        $this->loadLocale($this->defaultLocale);
    }

    /**
     * Set the active locale.
     */
    public function setLocale(string $locale): void
    {
        $this->currentLocale = $locale;
        $this->loadLocale($locale);
    }

    /**
     * Get the active locale code.
     */
    public function getLocale(): string
    {
        return $this->currentLocale;
    }

    /**
     * Translate a key with optional parameter substitution.
     *
     * Falls back to default locale if key is missing, then to the key itself.
     *
     * @param string      $key    Translation key (supports dot notation: "errors.not_found")
     * @param array       $params Associative array for {placeholder} substitution
     * @param string|null $locale Override locale for this call
     * @return string
     */
    public function translate(string $key, array $params = [], ?string $locale = null): string
    {
        $targetLocale = $locale ?? $this->currentLocale;

        // Ensure the target locale is loaded
        $this->loadLocale($targetLocale);

        // Try target locale
        $value = $this->resolve($key, $targetLocale);

        // Fallback to default locale
        if ($value === null && $targetLocale !== $this->defaultLocale) {
            $value = $this->resolve($key, $this->defaultLocale);
        }

        // Fallback to key itself
        if ($value === null) {
            $value = $key;
        }

        // Parameter substitution: replace {param} with values
        if (!empty($params)) {
            foreach ($params as $paramKey => $paramValue) {
                $value = str_replace('{' . $paramKey . '}', (string)$paramValue, $value);
            }
        }

        return $value;
    }

    /**
     * Alias for translate().
     */
    public function t(string $key, array $params = [], ?string $locale = null): string
    {
        return $this->translate($key, $params, $locale);
    }

    /**
     * Load and return the translations for a given locale.
     *
     * @return array<string, string> Flattened translation map
     */
    public function loadTranslations(string $locale): array
    {
        $this->loadLocale($locale);
        return $this->translations[$locale] ?? [];
    }

    /**
     * Add or override a single translation key for a locale.
     */
    public function addTranslation(string $locale, string $key, string $value): void
    {
        if (!isset($this->translations[$locale])) {
            $this->translations[$locale] = [];
        }
        $this->translations[$locale][$key] = $value;
    }

    /**
     * List available locale codes by scanning the locale directory for JSON files.
     *
     * @return string[]
     */
    public function availableLocales(): array
    {
        if (!is_dir($this->localeDir)) {
            return [$this->defaultLocale];
        }

        $locales = [];
        $files = scandir($this->localeDir);
        foreach ($files as $file) {
            if (str_ends_with($file, '.json') || str_ends_with($file, '.yml') || str_ends_with($file, '.yaml')) {
                $locales[] = pathinfo($file, PATHINFO_FILENAME);
            }
        }
        $locales = array_unique($locales);
        sort($locales);
        return $locales;
    }

    /**
     * Load a locale file if not already loaded.
     */
    private function loadLocale(string $locale): void
    {
        if (isset($this->translations[$locale])) {
            return;
        }

        $path = $this->localeDir . '/' . $locale . '.json';
        if (is_file($path)) {
            $content = file_get_contents($path);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    $this->translations[$locale] = $this->flatten($data);
                    return;
                }
            }
        }

        // Try YAML (.yml or .yaml)
        foreach (['.yml', '.yaml'] as $ext) {
            $yamlPath = $this->localeDir . '/' . $locale . $ext;
            if (is_file($yamlPath)) {
                $content = file_get_contents($yamlPath);
                if ($content !== false) {
                    $data = self::parseSimpleYaml($content);
                    $this->translations[$locale] = $this->flatten($data);
                    return;
                }
            }
        }

        $this->translations[$locale] = [];
    }

    /**
     * Zero-dep YAML parser for simple key: value locale files.
     */
    private static function parseSimpleYaml(string $text): array
    {
        $result = [];
        $currentParent = null;
        foreach (explode("\n", $text) as $line) {
            $stripped = trim($line);
            if ($stripped === '' || str_starts_with($stripped, '#')) {
                continue;
            }
            $indent = strlen($line) - strlen(ltrim($line));
            $colonPos = strpos($stripped, ':');
            if ($colonPos === false) {
                continue;
            }
            $key = trim(substr($stripped, 0, $colonPos));
            $value = trim(substr($stripped, $colonPos + 1));
            // Strip quotes
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            if ($value === '') {
                $currentParent = $key;
                $result[$key] = [];
            } elseif ($indent > 0 && $currentParent !== null && is_array($result[$currentParent] ?? null)) {
                $result[$currentParent][$key] = $value;
            } else {
                $currentParent = null;
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * Resolve a key from a locale's translations.
     */
    private function resolve(string $key, string $locale): ?string
    {
        return $this->translations[$locale][$key] ?? null;
    }

    /**
     * Flatten a nested associative array using dot notation.
     *
     * {"a": {"b": "c"}} becomes {"a.b": "c"}
     *
     * Also stores the leaf key as an alias so both "a.b" and "b" resolve.
     * First-wins on conflict: if two branches define the same leaf key,
     * the first one encountered keeps the alias.
     */
    private function flatten(array $data, string $prefix = '', array &$leafAliases = []): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $fullKey = $prefix !== '' ? $prefix . '.' . $key : (string)$key;
            if (is_array($value)) {
                $result = array_merge($result, $this->flatten($value, $fullKey, $leafAliases));
            } else {
                $scalar = self::coerceScalar($value);
                $result[$fullKey] = $scalar;
                // Store leaf key alias (first-wins)
                $leafKey = (string)$key;
                if ($leafKey !== $fullKey && !isset($leafAliases[$leafKey])) {
                    $leafAliases[$leafKey] = $scalar;
                }
            }
        }
        // At the top level, merge leaf aliases into result (first-wins: don't overwrite existing keys)
        if ($prefix === '') {
            foreach ($leafAliases as $alias => $aliasValue) {
                if (!isset($result[$alias])) {
                    $result[$alias] = $aliasValue;
                }
            }
        }
        return $result;
    }

    /**
     * Render a non-string locale scalar JSON-natively.
     *
     * JSON-decoded booleans/null must render as their JSON form so a template
     * never sees PHP's "1"/"" cast: true -> "true", false -> "false",
     * null -> "null". Numbers and strings fall through to their plain form
     * ("42", not "42.0").
     */
    private static function coerceScalar(mixed $value): string
    {
        if ($value === true) {
            return 'true';
        }
        if ($value === false) {
            return 'false';
        }
        if ($value === null) {
            return 'null';
        }
        return (string)$value;
    }
}
