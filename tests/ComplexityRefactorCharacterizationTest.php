<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

/**
 * Characterization tests for the CC >= 40 functions refactored in
 * refactor/complexity-round1. Each test PINS the current observable behaviour
 * of a function so the decomposition can be proven behaviour-preserving.
 *
 * No mocks: the parseSource / findMathOp / splitStatements cases are pure-logic
 * over their string inputs (reflection reaches the private methods, it does not
 * substitute a collaborator). The eagerLoad cases run against a REAL in-memory
 * SQLite engine. Cross-engine eagerLoad (Postgres/MySQL) is pinned by the
 * existing RelationshipsContractTest on the lab.
 */

declare(strict_types=1);

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tina4\Docs;
use Tina4\Frond;
use Tina4\Migration;
use Tina4\ORM;
use Tina4\Database\Database;
use Tina4\Database\SQLite3Adapter;

final class ComplexityRefactorCharacterizationTest extends TestCase
{
    /** Invoke a private method by name on an instance. */
    private static function callPrivate(object $obj, string $method, array $args): mixed
    {
        // PHP 8.1+ private methods are reachable via Reflection without
        // setAccessible() (deprecated no-op since 8.5).
        return (new ReflectionMethod($obj, $method))->invokeArgs($obj, $args);
    }

    // ─────────────────────────────────────────────────────────────
    // Docs::parseSource
    // ─────────────────────────────────────────────────────────────

    public function testParseSourcePinsClassAndMethodStructure(): void
    {
        $docs = new Docs(getcwd());
        $src = <<<'PHP'
        <?php
        namespace Acme\Widgets;

        /** A widget. */
        class Widget extends Base implements Thing
        {
            /** make a thing */
            public function make(int $n, string $s = "x"): Thing { return new Thing(); }

            protected static function build(): void {}

            private function hidden(array &$x): array { return $x; }
        }
        PHP;

        $out = self::callPrivate($docs, 'parseSource', [$src]);

        $this->assertCount(1, $out);
        $cls = $out[0];
        $this->assertSame('Acme\Widgets\Widget', $cls['fqn']);
        $this->assertSame('Widget', $cls['name']);
        $this->assertSame('class', $cls['kind']);

        $methods = array_column($cls['methods'], null, 'name');
        $this->assertSame(['make', 'build', 'hidden'], array_keys($methods));

        $this->assertSame('public', $methods['make']['visibility']);
        $this->assertFalse($methods['make']['static']);
        $this->assertSame('make(int $n, string $s = "x"): Thing', $methods['make']['signature']);

        $this->assertSame('protected', $methods['build']['visibility']);
        $this->assertTrue($methods['build']['static']);
        $this->assertSame('build(): void', $methods['build']['signature']);

        $this->assertSame('private', $methods['hidden']['visibility']);
        $this->assertSame('hidden(array &$x): array', $methods['hidden']['signature']);
    }

    public function testParseSourceHandlesInterfaceTraitEnumAndNoNamespace(): void
    {
        $docs = new Docs(getcwd());
        $src = <<<'PHP'
        <?php
        interface Speaker { public function say(): string; }
        trait Greets { public function hi(): string { return "hi"; } }
        enum Suit: string { case Hearts = "H"; public function label(): string { return "x"; } }
        PHP;

        $out = self::callPrivate($docs, 'parseSource', [$src]);
        $byName = array_column($out, null, 'name');

        $this->assertSame('interface', $byName['Speaker']['kind']);
        $this->assertSame('Speaker', $byName['Speaker']['fqn']);
        $this->assertSame('trait', $byName['Greets']['kind']);
        $this->assertSame('enum', $byName['Suit']['kind']);
        $this->assertSame(['say'], array_column($byName['Speaker']['methods'], 'name'));
        $this->assertSame(['label'], array_column($byName['Suit']['methods'], 'name'));
    }

    public function testParseSourceIgnoresClassConstFetchAndStringInterpolation(): void
    {
        // `::class` must not be read as a class declaration, and a `{$x}` inside
        // a double-quoted string must not close the class body early.
        $docs = new Docs(getcwd());
        $src = <<<'PHP'
        <?php
        namespace N;
        class Holder
        {
            public function run(): string
            {
                $ref = Widget::class;
                return "value {$ref} end";
            }
        }
        PHP;

        $out = self::callPrivate($docs, 'parseSource', [$src]);
        $this->assertCount(1, $out);
        $this->assertSame('N\Holder', $out[0]['fqn']);
        $this->assertSame(['run'], array_column($out[0]['methods'], 'name'));
    }

    public function testParseSourceReturnsEmptyForInvalidSource(): void
    {
        $docs = new Docs(getcwd());
        $this->assertSame([], self::callPrivate($docs, 'parseSource', ['not php at all']));
    }

    // ─────────────────────────────────────────────────────────────
    // Frond::findMathOp
    // ─────────────────────────────────────────────────────────────

    /** @return array<string, array{string, string, int|false}> */
    public static function mathOpCases(): array
    {
        return [
            'plus single'              => ['1+2', '+', 1],
            'plus last of two (l-assoc)' => ['1+2+3', '+', 3],
            'minus single'             => ['1-2', '-', 1],
            'unary minus at start'     => ['-5', '-', false],
            'minus after open paren'   => ['(-5)', '-', false],
            'minus after comma'        => ['f(a, -5)', '-', false],
            'plus inside parens'       => ['(1+2)', '+', false],
            'plus inside string'       => ["'a+b'", '+', false],
            'plus with spaces'         => ['1 + 2', '+', 2],
            'times first'              => ['a*b*c', '*', 1],
            'divide position'          => ['a/b', '/', 1],
            'floordiv not single slash'=> ['6//2', '/', false],
            'power not single star'    => ['2**3', '*', false],
            'floordiv matches double'  => ['6//2', '//', 1],
            'modulo'                   => ['7%3', '%', 1],
            'no operator present'      => ['abc', '+', false],
        ];
    }

    #[DataProvider('mathOpCases')]
    public function testFindMathOpPinsPositions(string $expr, string $op, int|false $expected): void
    {
        $frond = new Frond(getcwd());
        $this->assertSame($expected, self::callPrivate($frond, 'findMathOp', [$expr, $op]));
    }

    // ─────────────────────────────────────────────────────────────
    // Migration::splitStatements
    // ─────────────────────────────────────────────────────────────

    private function split(string $sql, string $delimiter = ';'): array
    {
        $migration = new Migration(new SQLite3Adapter(':memory:'), sys_get_temp_dir(), $delimiter);
        return self::callPrivate($migration, 'splitStatements', [$sql]);
    }

    public function testSplitStatementsBasicSemicolon(): void
    {
        $this->assertSame(
            ['SELECT 1', 'SELECT 2'],
            $this->split('SELECT 1; SELECT 2;')
        );
    }

    public function testSplitStatementsKeepsSemicolonInsideString(): void
    {
        $this->assertSame(
            ["INSERT INTO t VALUES ('a;b')", 'SELECT 2'],
            $this->split("INSERT INTO t VALUES ('a;b'); SELECT 2;")
        );
    }

    public function testSplitStatementsDoubledQuoteEscapes(): void
    {
        $this->assertSame(
            ["INSERT INTO t VALUES ('it''s; ok')"],
            $this->split("INSERT INTO t VALUES ('it''s; ok');")
        );
    }

    public function testSplitStatementsStripsLineAndBlockComments(): void
    {
        $sql = "SELECT 1; -- a ; comment\nSELECT 2; /* block ; comment */ SELECT 3;";
        $out = $this->split($sql);
        $this->assertSame('SELECT 1', $out[0]);
        $this->assertSame('SELECT 2', trim($out[1]));
        $this->assertStringContainsString('SELECT 3', $out[2]);
        $this->assertCount(3, $out);
    }

    public function testSplitStatementsKeepsDollarBlockIntact(): void
    {
        $sql = "CREATE FUNCTION f() RETURNS int AS \$\$ BEGIN; RETURN 1; END; \$\$ LANGUAGE plpgsql; SELECT 1;";
        $out = $this->split($sql);
        $this->assertCount(2, $out);
        $this->assertStringContainsString('BEGIN; RETURN 1; END;', $out[0]);
        $this->assertSame('SELECT 1', $out[1]);
    }

    public function testSplitStatementsUrlSchemeNotTreatedAsSlashBlock(): void
    {
        // A bare `://` must NOT open a `//` stored-proc block, otherwise the
        // scanner would swallow the `;` and merge two statements into one.
        $sql = "UPDATE t SET u = a://b WHERE x = 1; SELECT 2;";
        $out = $this->split($sql);
        $this->assertCount(2, $out);
        $this->assertStringContainsString('a://b', $out[0]);
        $this->assertSame('SELECT 2', $out[1]);
    }

    public function testSplitStatementsSetTermSwitchesTerminator(): void
    {
        $sql = "SET TERM !! ;\nCREATE TRIGGER t AS BEGIN a; b; END !! SET TERM ; !!";
        $out = $this->split($sql);
        $this->assertCount(1, $out);
        $this->assertStringContainsString('BEGIN a; b; END', $out[0]);
    }

    // ─────────────────────────────────────────────────────────────
    // ORM::eagerLoad — belongsTo eager + nested eager (real SQLite)
    // ─────────────────────────────────────────────────────────────

    private function relDb(): Database
    {
        $db = Database::create('sqlite::memory:');
        ORM::bindDatabase($db);
        (new CharAuthor($db))->createTable();
        (new CharPost($db))->createTable();
        (new CharComment($db))->createTable();
        return $db;
    }

    public function testEagerBelongsToLoadsParent(): void
    {
        $db = $this->relDb();
        $a = (new CharAuthor($db, ['name' => 'Ann']))->save();
        (new CharPost($db, ['title' => 'p1', 'author_id' => $a->id]))->save();
        (new CharPost($db, ['title' => 'p2', 'author_id' => $a->id]))->save();

        $posts = (new CharPost($db))->all()->toArray();
        CharPost::eagerLoad($posts, ['author'], $db);

        $this->assertCount(2, $posts);
        foreach ($posts as $p) {
            $this->assertInstanceOf(CharAuthor::class, $p->author);
            $this->assertSame('Ann', $p->author->name);
        }
    }

    public function testEagerNestedIncludeLoadsGrandchildren(): void
    {
        // Uses a REAL SQLite adapter that counts queries touching char_comment.
        // Reading the grandchildren AFTER eager load must trigger ZERO extra
        // queries — that is what proves the nested eager path actually ran,
        // rather than lazy access quietly fetching the same rows.
        $db = new CharCountingSqlite(':memory:');
        ORM::bindDatabase($db);
        (new CharAuthor($db))->createTable();
        (new CharPost($db))->createTable();
        (new CharComment($db))->createTable();

        $a = (new CharAuthor($db, ['name' => 'Ann']))->save();
        $p = (new CharPost($db, ['title' => 'p1', 'author_id' => $a->id]))->save();
        (new CharComment($db, ['body' => 'c1', 'post_id' => $p->id]))->save();
        (new CharComment($db, ['body' => 'c2', 'post_id' => $p->id]))->save();

        $authors = (new CharAuthor($db))->all()->toArray();
        $db->watch = 'char_comment';
        $db->watched = 0;
        CharAuthor::eagerLoad($authors, ['posts.comments'], $db);
        $this->assertSame(1, $db->watched, 'nested comments must load in exactly one eager query');

        $this->assertCount(1, $authors);
        $posts = $authors[0]->posts;
        $this->assertCount(1, $posts);
        $db->watched = 0;
        $comments = $posts[0]->comments;
        $this->assertSame(0, $db->watched, 'grandchildren already eager-loaded — no lazy query');
        $this->assertCount(2, $comments);
        $this->assertSame(['c1', 'c2'], array_map(static fn($c) => $c->body, $comments));
    }
}

/**
 * A REAL SQLite adapter that counts fetches touching a watched table. Every
 * counted call still runs the real query via parent::fetch — instrumentation,
 * not a mock.
 */
class CharCountingSqlite extends SQLite3Adapter
{
    public int $watched = 0;
    public string $watch = '';

    public function fetch(string $sql, array $params = [], int $limit = 100, int $offset = 0): array
    {
        if ($this->watch !== '' && stripos($sql, $this->watch) !== false) {
            $this->watched++;
        }
        return parent::fetch($sql, $params, $limit, $offset);
    }
}

class CharAuthor extends ORM
{
    public string $tableName = 'char_author';
    public ?int $id = null;
    public string $name = '';
    public array $hasMany = ['posts' => CharPost::class . '.author_id'];
}

class CharPost extends ORM
{
    public string $tableName = 'char_post';
    public ?int $id = null;
    public string $title = '';
    public ?int $author_id = null;
    public array $belongsTo = ['author' => CharAuthor::class . '.author_id'];
    public array $hasMany = ['comments' => CharComment::class . '.post_id'];
}

class CharComment extends ORM
{
    public string $tableName = 'char_comment';
    public ?int $id = null;
    public string $body = '';
    public ?int $post_id = null;
}
