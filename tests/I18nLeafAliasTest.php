<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\I18n;

/**
 * Tests for I18n leaf-key aliasing and auto-wire into the template engine.
 */
class I18nLeafAliasTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tina4_i18n_leaf_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        putenv('TINA4_LOCALE');
        putenv('TINA4_LOCALE_DIR');
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function writeLocale(string $locale, array $data): void
    {
        file_put_contents(
            $this->tempDir . '/' . $locale . '.json',
            json_encode($data, JSON_PRETTY_PRINT)
        );
    }

    // ── Feature 1: Leaf-key aliasing ──────────────────────────────────────

    public function testLeafKeyResolutionFromNestedJson(): void
    {
        $this->writeLocale('en', [
            'nav' => [
                'home' => 'Home',
                'about' => 'About Us',
            ],
        ]);
        $i18n = new I18n($this->tempDir, 'en');

        // Leaf key should resolve
        $this->assertSame('Home', $i18n->t('home'));
        $this->assertSame('About Us', $i18n->t('about'));
    }

    public function testDotPathStillWorks(): void
    {
        $this->writeLocale('en', [
            'nav' => [
                'home' => 'Home',
                'about' => 'About Us',
            ],
        ]);
        $i18n = new I18n($this->tempDir, 'en');

        // Full dot-path must still work
        $this->assertSame('Home', $i18n->t('nav.home'));
        $this->assertSame('About Us', $i18n->t('nav.about'));
    }

    public function testMixedFlatAndNested(): void
    {
        $this->writeLocale('en', [
            'greeting' => 'Hello',
            'nav' => [
                'home' => 'Home',
            ],
            'errors' => [
                'not_found' => 'Not Found',
            ],
        ]);
        $i18n = new I18n($this->tempDir, 'en');

        // Flat key
        $this->assertSame('Hello', $i18n->t('greeting'));
        // Dot-path
        $this->assertSame('Home', $i18n->t('nav.home'));
        $this->assertSame('Not Found', $i18n->t('errors.not_found'));
        // Leaf aliases
        $this->assertSame('Home', $i18n->t('home'));
        $this->assertSame('Not Found', $i18n->t('not_found'));
    }

    public function testLeafAliasFirstWinsOnConflict(): void
    {
        $this->writeLocale('en', [
            'nav' => [
                'title' => 'Navigation Title',
            ],
            'page' => [
                'title' => 'Page Title',
            ],
        ]);
        $i18n = new I18n($this->tempDir, 'en');

        // Both have a "title" leaf. First-wins: "nav.title" was flattened first.
        $this->assertSame('Navigation Title', $i18n->t('title'));
        // Dot-paths always work
        $this->assertSame('Navigation Title', $i18n->t('nav.title'));
        $this->assertSame('Page Title', $i18n->t('page.title'));
    }

    public function testLeafAliasDoesNotOverwriteFlatKey(): void
    {
        $this->writeLocale('en', [
            'home' => 'Flat Home',
            'nav' => [
                'home' => 'Nested Home',
            ],
        ]);
        $i18n = new I18n($this->tempDir, 'en');

        // The flat "home" key already exists, so the leaf alias from "nav.home" must NOT overwrite it
        $this->assertSame('Flat Home', $i18n->t('home'));
        // Dot-path still resolves nested
        $this->assertSame('Nested Home', $i18n->t('nav.home'));
    }

    public function testDeeplyNestedLeafAlias(): void
    {
        $this->writeLocale('en', [
            'a' => [
                'b' => [
                    'c' => 'deep value',
                ],
            ],
        ]);
        $i18n = new I18n($this->tempDir, 'en');

        $this->assertSame('deep value', $i18n->t('a.b.c'));
        // Leaf alias: "c" should resolve
        $this->assertSame('deep value', $i18n->t('c'));
    }

    public function testLeafAliasWithParameterSubstitution(): void
    {
        $this->writeLocale('en', [
            'messages' => [
                'welcome' => 'Welcome, {name}!',
            ],
        ]);
        $i18n = new I18n($this->tempDir, 'en');

        $this->assertSame('Welcome, Alice!', $i18n->t('welcome', ['name' => 'Alice']));
        $this->assertSame('Welcome, Alice!', $i18n->t('messages.welcome', ['name' => 'Alice']));
    }

    public function testLeafAliasFallbackAcrossLocales(): void
    {
        $this->writeLocale('en', [
            'nav' => ['home' => 'Home'],
        ]);
        $this->writeLocale('fr', [
            'nav' => ['home' => 'Accueil'],
        ]);
        $i18n = new I18n($this->tempDir, 'en');
        $i18n->setLocale('fr');

        // Leaf alias works in active locale
        $this->assertSame('Accueil', $i18n->t('home'));
    }

    // ── Feature 2: Auto-wire i18n → template global t() ──────────────────

    public function testAutoWireRegistersTWhenLocalesExist(): void
    {
        // Set up a temp base path with src/locales containing a JSON file
        $basePath = sys_get_temp_dir() . '/tina4_autowire_test_' . uniqid();
        $localesDir = $basePath . '/src/locales';
        mkdir($localesDir, 0777, true);
        file_put_contents($localesDir . '/en.json', json_encode(['greeting' => 'Hello']));

        // Reset the Frond singleton
        $this->resetFrondSingleton();

        try {
            $app = new \Tina4\App($basePath);
            $app->start();

            $frond = \Tina4\Response::getFrond();
            $globals = $frond->getGlobals();

            $this->assertArrayHasKey('t', $globals, 'Auto-wire should register t() global');
            $this->assertIsCallable($globals['t']);

            // Verify the function actually translates
            $this->assertSame('Hello', ($globals['t'])('greeting'));
        } finally {
            restore_error_handler();
            restore_exception_handler();
            $this->removeDir($basePath);
            $this->resetFrondSingleton();
            $this->clearRoutes();
        }
    }

    public function testAutoWireDoesNothingWhenNoLocales(): void
    {
        $basePath = sys_get_temp_dir() . '/tina4_autowire_nolocales_' . uniqid();
        mkdir($basePath . '/src', 0777, true);
        // No locales directory at all

        $this->resetFrondSingleton();

        try {
            $app = new \Tina4\App($basePath);
            $app->start();

            $frond = \Tina4\Response::getFrond();
            $globals = $frond->getGlobals();

            $this->assertArrayNotHasKey('t', $globals, 'No locale files means no t() global');
        } finally {
            restore_error_handler();
            restore_exception_handler();
            $this->removeDir($basePath);
            $this->resetFrondSingleton();
            $this->clearRoutes();
        }
    }

    public function testAutoWireDoesNothingWhenLocalesDirExistsButEmpty(): void
    {
        $basePath = sys_get_temp_dir() . '/tina4_autowire_empty_' . uniqid();
        $localesDir = $basePath . '/src/locales';
        mkdir($localesDir, 0777, true);
        // No .json files, just an empty directory

        $this->resetFrondSingleton();

        try {
            $app = new \Tina4\App($basePath);
            $app->start();

            $frond = \Tina4\Response::getFrond();
            $globals = $frond->getGlobals();

            $this->assertArrayNotHasKey('t', $globals, 'Empty locales dir means no t() global');
        } finally {
            restore_error_handler();
            restore_exception_handler();
            $this->removeDir($basePath);
            $this->resetFrondSingleton();
            $this->clearRoutes();
        }
    }

    public function testUserRegisteredTIsNotOverwritten(): void
    {
        $basePath = sys_get_temp_dir() . '/tina4_autowire_userT_' . uniqid();
        $localesDir = $basePath . '/src/locales';
        mkdir($localesDir, 0777, true);
        file_put_contents($localesDir . '/en.json', json_encode(['greeting' => 'Hello']));

        $this->resetFrondSingleton();

        try {
            // User registers their own t() before the app starts
            $userT = fn(string $key) => 'USER:' . $key;
            \Tina4\Response::getFrond()->addGlobal('t', $userT);

            $app = new \Tina4\App($basePath);
            $app->start();

            $frond = \Tina4\Response::getFrond();
            $globals = $frond->getGlobals();

            $this->assertSame($userT, $globals['t'], 'User-registered t() must not be overwritten');
            $this->assertSame('USER:greeting', ($globals['t'])('greeting'));
        } finally {
            restore_error_handler();
            restore_exception_handler();
            $this->removeDir($basePath);
            $this->resetFrondSingleton();
            $this->clearRoutes();
        }
    }

    /**
     * Clear all registered routes via reflection.
     */
    private function clearRoutes(): void
    {
        $ref = new \ReflectionClass(\Tina4\Router::class);
        $prop = $ref->getProperty('routes');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    /**
     * Reset the static Frond singleton in Response so tests are isolated.
     */
    private function resetFrondSingleton(): void
    {
        $ref = new \ReflectionClass(\Tina4\Response::class);
        $prop = $ref->getProperty('frond');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $prop2 = $ref->getProperty('frameworkFrond');
        $prop2->setAccessible(true);
        $prop2->setValue(null, null);
    }
}
