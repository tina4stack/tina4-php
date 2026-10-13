<?php
/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A bracketed multipart field (`tags[]`, `user[name]`) arrives nested, the way
 * the same form does urlencoded.
 *
 * A multi-select or checkbox group posts `tags[]` once per ticked value. The
 * socket server's multipart parser stored each text field under its raw name,
 * so `tags[]=red` then `tags[]=blue` arrived as `['tags[]' => 'blue']` - the
 * first value gone - while the urlencoded path (parse_str) and PHP's own $_POST
 * gave `['tags' => ['red', 'blue']]`. The multipart fields now nest with
 * parse_str's semantics, without its max_input_vars cut-off or its renaming
 * of `.` and spaces in a name.
 *
 * No mocks: a real multipart body through Server::parseMultipartBody, compared
 * with parse_str on the same fields urlencoded.
 */
class MultipartArrayFieldsTest extends TestCase
{
    private const BOUNDARY = '----Tina4MultipartArrayFields';

    /**
     * @param array<int, array{string,string}> $pairs [name, value]
     * @param array<int, array{string,string,string}> $files [name, filename, content]
     */
    private function multipart(array $pairs, array $files = []): array
    {
        $body = '';
        foreach ($pairs as [$name, $value]) {
            $body .= "--" . self::BOUNDARY . "\r\n"
                . "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n"
                . $value . "\r\n";
        }
        foreach ($files as [$name, $filename, $content]) {
            $body .= "--" . self::BOUNDARY . "\r\n"
                . "Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\n"
                . "Content-Type: application/octet-stream\r\n\r\n"
                . $content . "\r\n";
        }
        $body .= "--" . self::BOUNDARY . "--\r\n";
        return \Tina4\Server::parseMultipartBody($body, 'multipart/form-data; boundary=' . self::BOUNDARY);
    }

    /** @param array<int, array{string,string}> $pairs */
    private function urlencoded(array $pairs): array
    {
        $encoded = array_map(fn($pair) => rawurlencode($pair[0]) . '=' . rawurlencode($pair[1]), $pairs);
        parse_str(implode('&', $encoded), $fields);
        return $fields;
    }

    public static function forms(): array
    {
        return [
            'a checkbox group' => [[['tags[]', 'red'], ['tags[]', 'blue']], ['tags' => ['red', 'blue']]],
            'one ticked box' => [[['tags[]', 'red']], ['tags' => ['red']]],
            'a nested field' => [[['user[name]', 'Ann'], ['user[roles][]', 'admin']],
                ['user' => ['name' => 'Ann', 'roles' => ['admin']]]],
            'a repeated plain name' => [[['p', '1'], ['p', '2']], ['p' => '2']],
            'plain fields alongside' => [[['name', 'x'], ['tags[]', 'red']], ['name' => 'x', 'tags' => ['red']]],
            'a value with & and =' => [[['q[]', 'a&b=c']], ['q' => ['a&b=c']]],
            'a value then a nested name' => [[['a', '1'], ['a[b]', '2']], ['a' => ['b' => '2']]],
            'a nested value then a deeper name' => [[['c[d]', '1'], ['c[d][e]', '2']], ['c' => ['d' => ['e' => '2']]]],
        ];
    }

    #[DataProvider('forms')]
    public function testMultipartNestsLikeTheSameFormUrlencoded(array $pairs, array $expected): void
    {
        $fields = $this->multipart($pairs)['fields'];
        $this->assertSame($expected, $fields);
        $this->assertSame($this->urlencoded($pairs), $fields);
    }

    public function testEveryFieldOfALargeFormIsKept(): void
    {
        $pairs = [];
        for ($i = 0; $i < 1500; $i++) {
            $pairs[] = ["f{$i}", 'v'];
        }
        $this->assertCount(1500, $this->multipart($pairs)['fields']);
    }

    public function testAFormPastTheFieldLimitKeepsFlatKeys(): void
    {
        $pairs = [];
        for ($i = 0; $i < 4097; $i++) {
            $pairs[] = ['x[][k]', (string)$i];
        }
        $this->assertSame(['x[][k]' => '4096'], $this->multipart($pairs)['fields']);
    }

    public function testAListAtTheLastIndexKeepsTheNextFieldFlat(): void
    {
        $this->assertSame(['a' => [PHP_INT_MAX => '1'], 'a[]' => '2'],
            $this->multipart([['a[' . PHP_INT_MAX . ']', '1'], ['a[]', '2']])['fields']);
    }

    public function testAFieldNameKeepsItsDotsAndSpaces(): void
    {
        $this->assertSame(['first.name' => 'Ann', 'last name' => ['Lee']],
            $this->multipart([['first.name', 'Ann'], ['last name[]', 'Lee']])['fields']);
    }

    public function testANameStartingWithABracketKeepsItsRawKey(): void
    {
        $this->assertSame(['[x]' => '1'], $this->multipart([['[x]', '1']])['fields']);
    }

    public function testOnlyTheLeadingBracketsNest(): void
    {
        $this->assertSame(['a' => ['b' => 'v'], 'c[d' => 'w'],
            $this->multipart([['a[b]x[y]', 'v'], ['c[d', 'w']])['fields']);
    }

    public function testNestingPastTheDepthLimitKeepsTheFlatKey(): void
    {
        $name = 'x' . str_repeat('[k]', 40);
        $this->assertSame([$name => 'v'], $this->multipart([[$name, 'v']])['fields']);
    }

    public function testAHugeBracketNameKeepsItsFlatKeyWithinTheMemoryLimit(): void
    {
        // Matched out, these 600k bracket pairs cost ~45MB; counted first, a few.
        $name = 'a' . str_repeat('[]', 600000);
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $fields = $this->multipart([[$name, 'v']])['fields'];
        $this->assertSame([$name], array_keys($fields));
        $this->assertLessThan(16 * 1024 * 1024, memory_get_peak_usage() - $before);
    }

    public function testANamePastTheLengthLimitKeepsItsFlatKey(): void
    {
        $name = 'a[k][' . str_repeat('x', 2000) . ']';
        $this->assertSame([$name => 'v', 't' => ['1']], $this->multipart([[$name, 'v'], ['t[]', '1']])['fields']);
    }

    public function testFilesStillArriveBesideNestedFields(): void
    {
        $parsed = $this->multipart([['tags[]', 'red'], ['tags[]', 'blue']], [['photo', 'a.txt', 'AAAA']]);
        $this->assertSame(['tags' => ['red', 'blue']], $parsed['fields']);
        $this->assertSame('AAAA', $parsed['files']['photo']['content']);
    }
}
