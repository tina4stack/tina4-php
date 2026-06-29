<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\I18n;

class I18nV3Test extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tina4_i18n_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        // Clean up env vars
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

    public function testBasicTranslation(): void
    {
        $this->writeLocale('en', ['greeting' => 'Hello']);
        $i18n = new I18n('en', $this->tempDir);
        $this->assertSame('Hello', $i18n->t('greeting'));
    }

    public function testParameterSubstitution(): void
    {
        $this->writeLocale('en', ['greeting' => 'Hello {name}']);
        $i18n = new I18n('en', $this->tempDir);
        $this->assertSame('Hello World', $i18n->t('greeting', ['name' => 'World']));
    }

    public function testMultipleParameters(): void
    {
        $this->writeLocale('en', ['welcome' => 'Welcome {first} {last}']);
        $i18n = new I18n('en', $this->tempDir);
        $this->assertSame(
            'Welcome John Doe',
            $i18n->t('welcome', ['first' => 'John', 'last' => 'Doe'])
        );
    }

    public function testFallbackToKey(): void
    {
        $this->writeLocale('en', ['greeting' => 'Hello']);
        $i18n = new I18n('en', $this->tempDir);
        $this->assertSame('missing.key', $i18n->t('missing.key'));
    }

    public function testLocaleSwitch(): void
    {
        $this->writeLocale('en', ['greeting' => 'Hello']);
        $this->writeLocale('fr', ['greeting' => 'Bonjour']);
        $i18n = new I18n('en', $this->tempDir);

        $this->assertSame('Hello', $i18n->t('greeting'));
        $i18n->setLocale('fr');
        $this->assertSame('Bonjour', $i18n->t('greeting'));
    }

    public function testFallbackToDefaultLocale(): void
    {
        $this->writeLocale('en', ['greeting' => 'Hello', 'farewell' => 'Goodbye']);
        $this->writeLocale('fr', ['greeting' => 'Bonjour']);
        $i18n = new I18n('en', $this->tempDir);
        $i18n->setLocale('fr');

        // 'farewell' not in fr, should fall back to en
        $this->assertSame('Goodbye', $i18n->t('farewell'));
    }

    public function testNestedKeys(): void
    {
        $this->writeLocale('en', [
            'errors' => [
                'not_found' => 'Not Found',
                'forbidden' => 'Access Denied',
            ],
        ]);
        $i18n = new I18n('en', $this->tempDir);
        $this->assertSame('Not Found', $i18n->t('errors.not_found'));
        $this->assertSame('Access Denied', $i18n->t('errors.forbidden'));
    }

    public function testGetSetLocale(): void
    {
        $this->writeLocale('en', []);
        $i18n = new I18n('en', $this->tempDir);
        $this->assertSame('en', $i18n->getLocale());
        $i18n->setLocale('de');
        $this->assertSame('de', $i18n->getLocale());
    }

    public function testGetAvailableLocales(): void
    {
        $this->writeLocale('en', ['a' => 'b']);
        $this->writeLocale('fr', ['a' => 'c']);
        $this->writeLocale('de', ['a' => 'd']);
        $i18n = new I18n('en', $this->tempDir);
        $locales = $i18n->availableLocales();
        $this->assertSame(['de', 'en', 'fr'], $locales);
    }

    public function testAddTranslation(): void
    {
        $this->writeLocale('en', ['greeting' => 'Hello']);
        $i18n = new I18n('en', $this->tempDir);
        $i18n->addTranslation('en', 'farewell', 'Goodbye');
        $this->assertSame('Goodbye', $i18n->t('farewell'));
    }

    public function testLoadTranslations(): void
    {
        $this->writeLocale('en', ['greeting' => 'Hello', 'farewell' => 'Goodbye']);
        $i18n = new I18n('en', $this->tempDir);
        $translations = $i18n->loadTranslations('en');
        $this->assertArrayHasKey('greeting', $translations);
        $this->assertArrayHasKey('farewell', $translations);
        $this->assertSame('Hello', $translations['greeting']);
    }

    public function testTranslateAlias(): void
    {
        $this->writeLocale('en', ['greeting' => 'Hello']);
        $i18n = new I18n('en', $this->tempDir);
        $this->assertSame($i18n->translate('greeting'), $i18n->t('greeting'));
    }

    public function testOverrideLocalePerCall(): void
    {
        $this->writeLocale('en', ['greeting' => 'Hello']);
        $this->writeLocale('es', ['greeting' => 'Hola']);
        $i18n = new I18n('en', $this->tempDir);
        // Current locale is en, but we ask for es
        $this->assertSame('Hola', $i18n->t('greeting', [], 'es'));
        // Current locale should still be en
        $this->assertSame('en', $i18n->getLocale());
    }

    public function testMissingLocaleDir(): void
    {
        $i18n = new I18n('en', '/nonexistent/path');
        $this->assertSame('greeting', $i18n->t('greeting'));
        $locales = $i18n->availableLocales();
        $this->assertSame(['en'], $locales);
    }

    public function testDeeplyNestedKeys(): void
    {
        $this->writeLocale('en', [
            'a' => [
                'b' => [
                    'c' => 'deep value',
                ],
            ],
        ]);
        $i18n = new I18n('en', $this->tempDir);
        $this->assertSame('deep value', $i18n->t('a.b.c'));
    }

    // -- Fallback from non-default locale to default --------------------------

    public function testFallbackNestedKeyFromNonDefault(): void
    {
        $this->writeLocale('en', [
            'nav' => ['home' => 'Home', 'about' => 'About'],
        ]);
        $this->writeLocale('fr', [
            'nav' => ['home' => 'Accueil'],
        ]);
        $i18n = new I18n('en', $this->tempDir);
        $i18n->setLocale('fr');
        // 'nav.about' not in fr, should fall back to en
        $this->assertSame('About', $i18n->t('nav.about'));
    }

    // -- Missing key returns key in non-default locale -----------------------

    public function testMissingKeyReturnsKeyInNonDefaultLocale(): void
    {
        $this->writeLocale('en', ['greeting' => 'Hello']);
        $this->writeLocale('fr', ['greeting' => 'Bonjour']);
        $i18n = new I18n('en', $this->tempDir);
        $i18n->setLocale('fr');
        $this->assertSame('totally.missing.key', $i18n->t('totally.missing.key'));
    }

    // -- Third locale with fallback -------------------------------------------

    public function testThirdLocaleGreeting(): void
    {
        $this->writeLocale('en', ['greeting' => 'Hello', 'farewell' => 'Goodbye']);
        $this->writeLocale('de', ['greeting' => 'Hallo']);
        $i18n = new I18n('en', $this->tempDir);
        $i18n->setLocale('de');
        $this->assertSame('Hallo', $i18n->t('greeting'));
    }

    public function testThirdLocaleFallsBackToDefault(): void
    {
        $this->writeLocale('en', ['greeting' => 'Hello', 'farewell' => 'Goodbye']);
        $this->writeLocale('de', ['greeting' => 'Hallo']);
        $i18n = new I18n('en', $this->tempDir);
        $i18n->setLocale('de');
        // 'farewell' not in de, should fall back to en
        $this->assertSame('Goodbye', $i18n->t('farewell'));
    }

    // -- Interpolation missing placeholder ----------------------------------

    public function testInterpolationMissingPlaceholder(): void
    {
        $this->writeLocale('en', ['welcome' => 'Welcome, {name}!']);
        $i18n = new I18n('en', $this->tempDir);
        // Pass nothing for {name} — should not crash
        $result = $i18n->t('welcome');
        $this->assertStringContainsString('Welcome', $result);
    }

    // -- French interpolation ------------------------------------------------

    public function testFrenchInterpolation(): void
    {
        $this->writeLocale('en', ['welcome' => 'Welcome, {name}!']);
        $this->writeLocale('fr', ['welcome' => 'Bienvenue, {name}!']);
        $i18n = new I18n('en', $this->tempDir);
        $i18n->setLocale('fr');
        $this->assertSame('Bienvenue, Alice!', $i18n->t('welcome', ['name' => 'Alice']));
    }

    // -- Available locales sorted --------------------------------------------

    public function testAvailableLocalesSorted(): void
    {
        $this->writeLocale('en', ['a' => 'b']);
        $this->writeLocale('fr', ['a' => 'c']);
        $this->writeLocale('de', ['a' => 'd']);
        $i18n = new I18n('en', $this->tempDir);
        $locales = $i18n->availableLocales();
        $sorted = $locales;
        sort($sorted);
        $this->assertSame($sorted, $locales);
    }

    // ── Lock-in regression tests for the cross-framework i18n bug batch ─────
    //
    // Each writes real locale files on disk and exercises the real I18n class
    // (no mocks). Mirrors the Python master's named contract tests.

    // BUG-2: leaf-alias must be first-wins and never clobber an explicit flat key
    public function testLeafAliasFirstWinsOnConflict(): void
    {
        $this->writeLocale('en', [
            'nav' => ['title' => 'Navigation Title'],
            'page' => ['title' => 'Page Title'],
        ]);
        $i18n = new I18n('en', $this->tempDir);
        $this->assertSame('Navigation Title', $i18n->t('title')); // first dot-path wins
        $this->assertSame('Navigation Title', $i18n->t('nav.title'));
        $this->assertSame('Page Title', $i18n->t('page.title'));
    }

    public function testLeafAliasDoesNotOverwriteExplicitFlatKey(): void
    {
        $this->writeLocale('en', [
            'home' => 'Flat Home',
            'nav' => ['home' => 'Nested Home'],
        ]);
        $i18n = new I18n('en', $this->tempDir);
        $this->assertSame('Flat Home', $i18n->t('home')); // explicit flat wins, no data loss
        $this->assertSame('Nested Home', $i18n->t('nav.home'));
    }

    // BUG-3/BUG-9: partial interpolation; missing/malformed left literal; never throws
    public function testInterpolationPartialLeavesMissingLiteral(): void
    {
        $this->writeLocale('en', ['pair' => 'Hi {first} and {second}']);
        $i18n = new I18n('en', $this->tempDir);
        $this->assertSame('Hi A and {second}', $i18n->t('pair', ['first' => 'A']));
    }

    public function testInterpolationNeverCrashesOnStrayBrace(): void
    {
        $this->writeLocale('en', ['oops' => 'Set { and forget']);
        $i18n = new I18n('en', $this->tempDir);
        $this->assertSame('Set { and forget', $i18n->t('oops', ['name' => 'Alice']));
    }

    public function testInterpolationNeverCrashesOnAttrOrSpec(): void
    {
        $this->writeLocale('en', [
            'attr' => 'Hello {name.first}',
            'spec' => 'You have {n:d} items',
        ]);
        $i18n = new I18n('en', $this->tempDir);
        $this->assertSame('Hello {name.first}', $i18n->t('attr', ['name' => 'Alice']));
        $this->assertSame('You have {n:d} items', $i18n->t('spec', ['n' => 'five']));
    }

    // BUG-6: non-string scalars render JSON-native (true/false/null)
    public function testNonStringScalarCoercionJsonNative(): void
    {
        $this->writeLocale('en', ['flag' => true, 'off' => false, 'nil' => null, 'num' => 42]);
        $i18n = new I18n('en', $this->tempDir);
        $this->assertSame('true', $i18n->t('flag'));
        $this->assertSame('false', $i18n->t('off'));
        $this->assertSame('null', $i18n->t('nil'));
        $this->assertSame('42', $i18n->t('num'));
    }

    // BUG-7 (BREAKING): constructor argument order is (locale, path) — matches Python I18n(locale, path)
    public function testConstructorPositionalLocaleThenPath(): void
    {
        $this->writeLocale('de', ['greeting' => 'Hallo']);
        // First positional arg is the locale, second is the path.
        $i18n = new I18n('de', $this->tempDir);
        $this->assertSame('de', $i18n->getLocale());
        $this->assertSame('Hallo', $i18n->t('greeting'));
    }

    // BUG-8: explicit constructor arg must win over env (explicit > env > default)
    public function testExplicitArgsOverrideEnv(): void
    {
        $this->writeLocale('en', ['greeting' => 'Hello']);
        $this->writeLocale('fr', ['greeting' => 'Bonjour']);
        putenv('TINA4_LOCALE=fr');
        $otherDir = sys_get_temp_dir() . '/tina4_i18n_otherdir_' . uniqid();
        mkdir($otherDir, 0777, true);
        putenv('TINA4_LOCALE_DIR=' . $otherDir);
        try {
            // Explicit 'en' + explicit tempDir must beat the env fr/$otherDir.
            $i18n = new I18n('en', $this->tempDir);
            $this->assertSame('en', $i18n->getLocale());
            $this->assertSame('Hello', $i18n->t('greeting'));
        } finally {
            putenv('TINA4_LOCALE');
            putenv('TINA4_LOCALE_DIR');
            rmdir($otherDir);
        }
    }

    // FP-7: translate() with a locale override restores the previous locale
    public function testTranslateOverrideRestoresLocale(): void
    {
        $this->writeLocale('en', ['greeting' => 'Hello']);
        $this->writeLocale('fr', ['greeting' => 'Bonjour']);
        $i18n = new I18n('en', $this->tempDir);
        $this->assertSame('Bonjour', $i18n->translate('greeting', [], 'fr'));
        $this->assertSame('en', $i18n->getLocale()); // restored
    }
}
