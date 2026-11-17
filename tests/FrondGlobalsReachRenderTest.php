<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

/**
 * A template global registered through the public static API reaches every
 * page $response->render() produces, whenever it is registered.
 *
 * The engine copied Frond::$classGlobals once, in its constructor, so a
 * Frond::addGlobal() made after the response engine existed (after the first
 * render) never reached a page. Every case boots a real Tina4\App in a
 * throwaway project and renders a real template through a real route and the
 * real front controller (Tina4\TestClient). No mocks.
 *
 * Same case names in all four:
 *   tina4-python/tests/test_frond_globals_reach_render.py
 *   tina4-ruby/spec/frond_globals_reach_render_spec.rb
 *   tina4-nodejs/test/frondGlobalsReachRender.test.ts
 */

use PHPUnit\Framework\TestCase;
use Tina4\App;
use Tina4\Frond;
use Tina4\Middleware;
use Tina4\Response;
use Tina4\Router;
use Tina4\TestClient;

class FrondGlobalsReachRenderTest extends TestCase
{
    private string $projectDir;
    private string $originalCwd;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/tina4_globals_render_' . getmypid() . '_' . uniqid();
        mkdir($this->projectDir . '/src/templates', 0755, true);
        mkdir($this->projectDir . '/src/locales', 0755, true);
        $templates = [
            'globals.twig' => '[{{ early }}][{{ late }}][{{ shared }}]',
            'call.twig' => '{{ shout("hi") }}',
            'flag.twig' => '{% if is_admin %}admin{% else %}guest{% endif %}',
            'greeting.twig' => '{{ t("greeting") }}',
            'welcome.twig' => '{{ t("welcome", {"name": "Ana"}) }}',
        ];
        foreach ($templates as $name => $source) {
            file_put_contents($this->projectDir . '/src/templates/' . $name, $source);
        }
        file_put_contents(
            $this->projectDir . '/src/locales/en.json',
            json_encode(['greeting' => 'Hello', 'welcome' => 'Welcome, {name}!'])
        );

        $this->originalCwd = getcwd();
        chdir($this->projectDir);
        putenv('TINA4_LOCALE=en');
        putenv('TINA4_LOCALE_DIR=' . $this->projectDir . '/src/locales');

        Router::clear();
        Middleware::reset();
        Frond::clearRegistry();
        Response::setFrond(new Frond('src/templates')); // a fresh response engine

        foreach (array_keys($templates) as $name) {
            Router::get('/' . basename($name, '.twig'), fn ($request, $response) => $response->render($name));
        }
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        putenv('TINA4_LOCALE');
        putenv('TINA4_LOCALE_DIR');
        Router::clear();
        Middleware::reset();
        Frond::clearRegistry();
        Response::setFrond(new Frond('src/templates'));
        $this->removeDirectory($this->projectDir);
    }

    private function removeDirectory(string $dir): void
    {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /** Boot the real App: start() runs the i18n auto-wire. */
    private function bootApp(): void
    {
        $app = new App(basePath: $this->projectDir);
        $app->start();
        AppTestSupport::releaseHandlers($app);
    }

    private function page(string $path): string
    {
        $response = (new TestClient())->get($path);
        $this->assertSame(200, $response->status);
        return $response->body;
    }

    public function testClassGlobalRegisteredBeforeFirstRenderReachesRender(): void
    {
        Frond::addGlobal('early', 'E');
        $this->assertSame('[E][][]', $this->page('/globals'));
    }

    public function testClassGlobalRegisteredAfterFirstRenderReachesRender(): void
    {
        $this->assertSame('[][][]', $this->page('/globals')); // the response engine now exists
        Frond::addGlobal('late', 'L');
        $this->assertSame('[][L][]', $this->page('/globals'));
    }

    public function testZeroArgClassGlobalRegisteredAfterFirstRenderIsCalled(): void
    {
        $this->assertSame('guest', $this->page('/flag'));
        Frond::addGlobal('is_admin', fn () => true); // ADR-0085 auto-call
        $this->assertSame('admin', $this->page('/flag'));
    }

    public function testCallableClassGlobalRegisteredAfterFirstRenderIsCalled(): void
    {
        $this->assertSame('', $this->page('/call'));
        Frond::addGlobal('shout', fn (string $text) => strtoupper($text));
        $this->assertSame('HI', $this->page('/call'));
    }

    public function testAutoWiredTTranslatesAKey(): void
    {
        $this->bootApp();
        $this->assertSame('Hello', $this->page('/greeting'));
    }

    public function testAutoWiredTAcceptsAParamsMap(): void
    {
        $this->bootApp();
        $this->assertSame('Welcome, Ana!', $this->page('/welcome'));
    }

    public function testAutoWiredTReachesEveryEngine(): void
    {
        $this->bootApp();
        $this->assertSame('Hello', (new Frond('src/templates'))->render('greeting.twig'));
    }

    public function testAutoWiredTRegisteredAfterFirstRenderReachesRender(): void
    {
        $this->assertSame('[][][]', $this->page('/globals'));
        $this->bootApp();
        $this->assertSame('Hello', $this->page('/greeting'));
    }

    public function testInstanceGlobalOnAnotherEngineDoesNotLeakIntoRender(): void
    {
        $otherEngine = new Frond('src/templates');
        $otherEngine->addGlobal('late', 'LEAK');
        $this->assertSame('[][][]', $this->page('/globals'));
        $this->assertSame('[][LEAK][]', $otherEngine->render('globals.twig'));
    }

    public function testInstanceGlobalOverridesClassGlobal(): void
    {
        Frond::addGlobal('shared', 'class');
        $this->assertSame('[][][class]', $this->page('/globals'));
        Response::getFrond()->addGlobal('shared', 'instance');
        $this->assertSame('[][][instance]', $this->page('/globals'));
        $this->assertSame('[][][class]', (new Frond('src/templates'))->render('globals.twig'));
    }
}
