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
     * @param string $localeDir    Path to directory containing locale JSON files
     * @param string $defaultLocale Default locale code (e.g. 'en')
     */
    public function __construct(string $localeDir = 'src/locales', string $defaultLocale = 'en')
    {
        $envDir = getenv('TINA4_LOCALE_DIR');
        $envLocale = getenv('TINA4_LOCALE');

        $this->localeDir = $envDir !== false && $envDir !== '' ? $envDir : $localeDir;
        $this->defaultLocale = $envLocale !== false && $envLocale !== '' ? $envLocale : $defaultLocale;
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
    public function getAvailableLocales(): array
    {
        if (!is_dir($this->localeDir)) {
            return [$this->defaultLocale];
        }

        $locales = [];
        $files = scandir($this->localeDir);
        foreach ($files as $file) {
            if (str_ends_with($file, '.json')) {
                $locales[] = substr($file, 0, -5);
            }
        }
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

        $this->translations[$locale] = [];
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
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $fullKey = $prefix !== '' ? $prefix . '.' . $key : $key;
            if (is_array($value)) {
                $result = array_merge($result, $this->flatten($value, $fullKey));
            } else {
                $result[$fullKey] = (string)$value;
            }
        }
        return $result;
    }
}
