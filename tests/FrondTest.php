<?php

use PHPUnit\Framework\TestCase;
use Tina4\Frond;

class FrondTest extends TestCase
{
    private Frond $engine;
    private string $templateDir;

    protected function setUp(): void
    {
        $this->templateDir = sys_get_temp_dir() . '/tina4-frond-test';
        if (!is_dir($this->templateDir)) {
            mkdir($this->templateDir, 0777, true);
        }
        $this->engine = new Frond($this->templateDir);
    }

    protected function tearDown(): void
    {
        // Clean up template files
        $files = glob($this->templateDir . '/*');
        foreach ($files as $f) {
            if (is_file($f)) unlink($f);
        }
    }

    private function writeTemplate(string $name, string $content): void
    {
        file_put_contents($this->templateDir . '/' . $name, $content);
    }

    /* ═══════════ Variables ═══════════ */

    public function testSimpleVariable(): void
    {
        $this->assertSame('Hello World', $this->engine->renderString('Hello {{ name }}', ['name' => 'World']));
    }

    public function testDottedVariable(): void
    {
        $this->assertSame('Alice', $this->engine->renderString('{{ user.name }}', ['user' => ['name' => 'Alice']]));
    }

    public function testArrayAccess(): void
    {
        $this->assertSame('b', $this->engine->renderString('{{ items[1] }}', ['items' => ['a', 'b', 'c']]));
    }

    public function testNestedDottedAccess(): void
    {
        $data = ['a' => ['b' => ['c' => 'deep']]];
        $this->assertSame('deep', $this->engine->renderString('{{ a.b.c }}', $data));
    }

    public function testAutoEscaping(): void
    {
        $this->assertSame('&lt;b&gt;bold&lt;/b&gt;', $this->engine->renderString('{{ html }}', ['html' => '<b>bold</b>']));
    }

    public function testRawFilter(): void
    {
        $this->assertSame('<b>bold</b>', $this->engine->renderString('{{ html | raw }}', ['html' => '<b>bold</b>']));
    }

    public function testSafeFilter(): void
    {
        $this->assertSame('<b>bold</b>', $this->engine->renderString('{{ html | safe }}', ['html' => '<b>bold</b>']));
    }

    public function testStringConcatenation(): void
    {
        $this->assertSame('Hello World', $this->engine->renderString('{{ "Hello" ~ " " ~ "World" }}', []));
    }

    public function testConcatWithVariable(): void
    {
        $this->assertSame('Hi Bob', $this->engine->renderString('{{ "Hi " ~ name }}', ['name' => 'Bob']));
    }

    public function testTernary(): void
    {
        $this->assertSame('yes', $this->engine->renderString('{{ active ? "yes" : "no" }}', ['active' => true]));
        $this->assertSame('no', $this->engine->renderString('{{ active ? "yes" : "no" }}', ['active' => false]));
    }

    public function testNullCoalescing(): void
    {
        $this->assertSame('fallback', $this->engine->renderString('{{ missing ?? "fallback" }}', []));
        $this->assertSame('found', $this->engine->renderString('{{ name ?? "fallback" }}', ['name' => 'found']));
    }

    public function testUndefinedVariableEmpty(): void
    {
        $this->assertSame('Hello ', $this->engine->renderString('Hello {{ missing }}', []));
    }

    public function testNumericLiteral(): void
    {
        $this->assertSame('42', $this->engine->renderString('{{ 42 }}', []));
    }

    public function testStringLiteral(): void
    {
        $this->assertSame('hello', $this->engine->renderString('{{ "hello" }}', []));
    }

    /* ═══════════ Filters ═══════════ */

    public function testFilterUpper(): void
    {
        $this->assertSame('HELLO', $this->engine->renderString('{{ name | upper }}', ['name' => 'hello']));
    }

    public function testFilterLower(): void
    {
        $this->assertSame('hello', $this->engine->renderString('{{ name | lower }}', ['name' => 'HELLO']));
    }

    public function testFilterCapitalize(): void
    {
        $this->assertSame('Hello world', $this->engine->renderString('{{ name | capitalize }}', ['name' => 'hello world']));
    }

    public function testFilterTitle(): void
    {
        $this->assertSame('Hello World', $this->engine->renderString('{{ name | title }}', ['name' => 'hello world']));
    }

    public function testFilterTrim(): void
    {
        $this->assertSame('hello', $this->engine->renderString('{{ name | trim }}', ['name' => '  hello  ']));
    }

    public function testFilterLtrim(): void
    {
        $this->assertSame('hello  ', $this->engine->renderString('{{ name | ltrim }}', ['name' => '  hello  ']));
    }

    public function testFilterRtrim(): void
    {
        $this->assertSame('  hello', $this->engine->renderString('{{ name | rtrim }}', ['name' => '  hello  ']));
    }

    public function testFilterReplace(): void
    {
        $this->assertSame('hi world', $this->engine->renderString('{{ text | replace("hello", "hi") }}', ['text' => 'hello world']));
    }

    public function testFilterStriptags(): void
    {
        $this->assertSame('hello', $this->engine->renderString('{{ html | striptags }}', ['html' => '<b>hello</b>']));
    }

    public function testFilterEscape(): void
    {
        $this->assertSame('&lt;b&gt;hi&lt;/b&gt;', $this->engine->renderString('{{ html | escape }}', ['html' => '<b>hi</b>']));
    }

    public function testFilterJsonEncode(): void
    {
        $result = $this->engine->renderString('{{ data | json_encode }}', ['data' => ['a' => 1]]);
        $this->assertSame('{"a":1}', $result);
    }

    public function testFilterBase64Encode(): void
    {
        $this->assertSame('aGVsbG8=', $this->engine->renderString('{{ text | base64_encode }}', ['text' => 'hello']));
    }

    public function testFilterBase64Decode(): void
    {
        $this->assertSame('hello', $this->engine->renderString('{{ text | base64_decode }}', ['text' => 'aGVsbG8=']));
    }

    public function testFilterUrlEncode(): void
    {
        $this->assertSame('hello%20world', $this->engine->renderString('{{ text | url_encode }}', ['text' => 'hello world']));
    }

    public function testFilterMd5(): void
    {
        $this->assertSame(md5('hello'), $this->engine->renderString('{{ text | md5 }}', ['text' => 'hello']));
    }

    public function testFilterSha256(): void
    {
        $this->assertSame(hash('sha256', 'hello'), $this->engine->renderString('{{ text | sha256 }}', ['text' => 'hello']));
    }

    public function testFilterAbs(): void
    {
        $this->assertSame('5', $this->engine->renderString('{{ num | abs }}', ['num' => -5]));
    }

    public function testFilterRound(): void
    {
        $this->assertSame('3.14', $this->engine->renderString('{{ num | round(2) }}', ['num' => 3.14159]));
    }

    public function testFilterInt(): void
    {
        $this->assertSame('3', $this->engine->renderString('{{ num | int }}', ['num' => 3.7]));
    }

    public function testFilterFloat(): void
    {
        $this->assertSame('3', $this->engine->renderString('{{ num | float }}', ['num' => '3']));
    }

    public function testFilterNumberFormat(): void
    {
        $this->assertSame('1,234.56', $this->engine->renderString('{{ num | number_format(2) }}', ['num' => 1234.56]));
    }

    public function testFilterLength(): void
    {
        $this->assertSame('3', $this->engine->renderString('{{ items | length }}', ['items' => [1, 2, 3]]));
    }

    public function testFilterLengthString(): void
    {
        $this->assertSame('5', $this->engine->renderString('{{ text | length }}', ['text' => 'hello']));
    }

    public function testFilterFirst(): void
    {
        $this->assertSame('a', $this->engine->renderString('{{ items | first }}', ['items' => ['a', 'b', 'c']]));
    }

    public function testFilterLast(): void
    {
        $this->assertSame('c', $this->engine->renderString('{{ items | last }}', ['items' => ['a', 'b', 'c']]));
    }

    public function testFilterReverse(): void
    {
        $result = $this->engine->renderString('{{ items | reverse | join(",") }}', ['items' => ['a', 'b', 'c']]);
        $this->assertSame('c,b,a', $result);
    }

    public function testFilterSort(): void
    {
        $result = $this->engine->renderString('{{ items | sort | join(",") }}', ['items' => ['c', 'a', 'b']]);
        $this->assertSame('a,b,c', $result);
    }

    public function testFilterUnique(): void
    {
        $result = $this->engine->renderString('{{ items | unique | join(",") }}', ['items' => ['a', 'b', 'a', 'c']]);
        $this->assertSame('a,b,c', $result);
    }

    public function testFilterJoin(): void
    {
        $this->assertSame('a-b-c', $this->engine->renderString('{{ items | join("-") }}', ['items' => ['a', 'b', 'c']]));
    }

    public function testFilterSplit(): void
    {
        $result = $this->engine->renderString('{{ text | split(",") | join(":") }}', ['text' => 'a,b,c']);
        $this->assertSame('a:b:c', $result);
    }

    public function testFilterSlice(): void
    {
        $result = $this->engine->renderString('{{ items | slice(1, 3) | join(",") }}', ['items' => ['a', 'b', 'c', 'd']]);
        $this->assertSame('b,c', $result);
    }

    public function testFilterBatch(): void
    {
        $result = $this->engine->renderString('{{ items | batch(2) | length }}', ['items' => [1, 2, 3, 4, 5]]);
        $this->assertSame('3', $result);
    }

    public function testFilterKeys(): void
    {
        $result = $this->engine->renderString('{{ data | keys | join(",") }}', ['data' => ['x' => 1, 'y' => 2]]);
        $this->assertSame('x,y', $result);
    }

    public function testFilterValues(): void
    {
        $result = $this->engine->renderString('{{ data | values | join(",") }}', ['data' => ['x' => 1, 'y' => 2]]);
        $this->assertSame('1,2', $result);
    }

    public function testFilterMerge(): void
    {
        $result = $this->engine->renderString('{{ a | merge(b) | join(",") }}', ['a' => [1, 2], 'b' => [3, 4]]);
        $this->assertSame('1,2,3,4', $result);
    }

    public function testFilterDefault(): void
    {
        $this->assertSame('N/A', $this->engine->renderString('{{ missing | default("N/A") }}', []));
        $this->assertSame('found', $this->engine->renderString('{{ name | default("N/A") }}', ['name' => 'found']));
    }

    public function testFilterTruncate(): void
    {
        $this->assertSame('Hello...', $this->engine->renderString('{{ text | truncate(5) }}', ['text' => 'Hello World']));
    }

    public function testFilterSlug(): void
    {
        $this->assertSame('hello-world', $this->engine->renderString('{{ text | slug }}', ['text' => 'Hello World']));
    }

    public function testFilterNl2br(): void
    {
        $result = $this->engine->renderString('{{ text | nl2br }}', ['text' => "a\nb"]);
        $this->assertSame("a<br />\nb", $result);
    }

    public function testFilterFormat(): void
    {
        $result = $this->engine->renderString('{{ "%s has %d items" | format("Cart", 3) }}', []);
        $this->assertSame('Cart has 3 items', $result);
    }

    public function testFilterChaining(): void
    {
        $this->assertSame('HELLO', $this->engine->renderString('{{ name | trim | upper }}', ['name' => '  hello  ']));
    }

    public function testFilterMap(): void
    {
        $data = ['users' => [['name' => 'Alice'], ['name' => 'Bob']]];
        $result = $this->engine->renderString('{{ users | map("name") | join(", ") }}', $data);
        $this->assertSame('Alice, Bob', $result);
    }

    public function testFilterColumn(): void
    {
        $data = ['users' => [['name' => 'Alice', 'age' => 30], ['name' => 'Bob', 'age' => 25]]];
        $result = $this->engine->renderString('{{ users | column("name") | join(", ") }}', $data);
        $this->assertSame('Alice, Bob', $result);
    }

    public function testFilterDate(): void
    {
        $result = $this->engine->renderString('{{ dt | date("Y") }}', ['dt' => '2025-06-15']);
        $this->assertSame('2025', $result);
    }

    public function testFilterWordwrap(): void
    {
        $result = $this->engine->renderString('{{ text | wordwrap(5) }}', ['text' => 'Hello World']);
        $this->assertStringContainsString("\n", $result);
    }

    public function testFilterString(): void
    {
        $this->assertSame('42', $this->engine->renderString('{{ num | string }}', ['num' => 42]));
    }

    public function testCustomFilter(): void
    {
        $this->engine->addFilter('double', fn($v) => $v * 2);
        $this->assertSame('10', $this->engine->renderString('{{ num | double }}', ['num' => 5]));
    }

    /* ═══════════ If / ElseIf / Else ═══════════ */

    public function testIfTrue(): void
    {
        $this->assertSame('yes', $this->engine->renderString('{% if show %}yes{% endif %}', ['show' => true]));
    }

    public function testIfFalse(): void
    {
        $this->assertSame('', $this->engine->renderString('{% if show %}yes{% endif %}', ['show' => false]));
    }

    public function testIfElse(): void
    {
        $this->assertSame('no', $this->engine->renderString('{% if show %}yes{% else %}no{% endif %}', ['show' => false]));
    }

    public function testIfElseif(): void
    {
        $tpl = '{% if x == 1 %}one{% elseif x == 2 %}two{% else %}other{% endif %}';
        $this->assertSame('one', $this->engine->renderString($tpl, ['x' => 1]));
        $this->assertSame('two', $this->engine->renderString($tpl, ['x' => 2]));
        $this->assertSame('other', $this->engine->renderString($tpl, ['x' => 3]));
    }

    public function testIfElifAlias(): void
    {
        $tpl = '{% if x == 1 %}one{% elif x == 2 %}two{% endif %}';
        $this->assertSame('two', $this->engine->renderString($tpl, ['x' => 2]));
    }

    public function testIfComparison(): void
    {
        $this->assertSame('yes', $this->engine->renderString('{% if age > 18 %}yes{% endif %}', ['age' => 21]));
        $this->assertSame('', $this->engine->renderString('{% if age > 18 %}yes{% endif %}', ['age' => 16]));
    }

    public function testIfAnd(): void
    {
        $this->assertSame('yes', $this->engine->renderString(
            '{% if a and b %}yes{% endif %}', ['a' => true, 'b' => true]
        ));
        $this->assertSame('', $this->engine->renderString(
            '{% if a and b %}yes{% endif %}', ['a' => true, 'b' => false]
        ));
    }

    public function testIfOr(): void
    {
        $this->assertSame('yes', $this->engine->renderString(
            '{% if a or b %}yes{% endif %}', ['a' => false, 'b' => true]
        ));
    }

    public function testIfNot(): void
    {
        $this->assertSame('yes', $this->engine->renderString(
            '{% if not hidden %}yes{% endif %}', ['hidden' => false]
        ));
    }

    public function testIfIn(): void
    {
        $this->assertSame('yes', $this->engine->renderString(
            '{% if "b" in items %}yes{% endif %}', ['items' => ['a', 'b', 'c']]
        ));
    }

    public function testIfNotIn(): void
    {
        $this->assertSame('yes', $this->engine->renderString(
            '{% if "z" not in items %}yes{% endif %}', ['items' => ['a', 'b', 'c']]
        ));
    }

    public function testNestedIf(): void
    {
        $tpl = '{% if a %}{% if b %}both{% endif %}{% endif %}';
        $this->assertSame('both', $this->engine->renderString($tpl, ['a' => true, 'b' => true]));
        $this->assertSame('', $this->engine->renderString($tpl, ['a' => true, 'b' => false]));
    }

    /* ═══════════ Tests (is ...) ═══════════ */

    public function testIsDefined(): void
    {
        $tpl = '{% if x is defined %}yes{% else %}no{% endif %}';
        $this->assertSame('yes', $this->engine->renderString($tpl, ['x' => 'hi']));
        $this->assertSame('no', $this->engine->renderString($tpl, []));
    }

    public function testIsEmpty(): void
    {
        $tpl = '{% if items is empty %}empty{% else %}not{% endif %}';
        $this->assertSame('empty', $this->engine->renderString($tpl, ['items' => []]));
        $this->assertSame('not', $this->engine->renderString($tpl, ['items' => [1]]));
    }

    public function testIsEven(): void
    {
        $this->assertSame('yes', $this->engine->renderString('{% if n is even %}yes{% endif %}', ['n' => 4]));
        $this->assertSame('', $this->engine->renderString('{% if n is even %}yes{% endif %}', ['n' => 3]));
    }

    public function testIsOdd(): void
    {
        $this->assertSame('yes', $this->engine->renderString('{% if n is odd %}yes{% endif %}', ['n' => 3]));
    }

    public function testIsDivisibleBy(): void
    {
        $this->assertSame('yes', $this->engine->renderString('{% if n is divisible by(3) %}yes{% endif %}', ['n' => 9]));
        $this->assertSame('', $this->engine->renderString('{% if n is divisible by(3) %}yes{% endif %}', ['n' => 7]));
    }

    public function testIsNull(): void
    {
        $tpl = '{% if x is null %}null{% else %}val{% endif %}';
        $this->assertSame('null', $this->engine->renderString($tpl, ['x' => null]));
    }

    public function testIsIterable(): void
    {
        $this->assertSame('yes', $this->engine->renderString('{% if x is iterable %}yes{% endif %}', ['x' => [1]]));
        $this->assertSame('', $this->engine->renderString('{% if x is iterable %}yes{% endif %}', ['x' => 5]));
    }

    public function testIsString(): void
    {
        $this->assertSame('yes', $this->engine->renderString('{% if x is string %}yes{% endif %}', ['x' => 'hi']));
        $this->assertSame('', $this->engine->renderString('{% if x is string %}yes{% endif %}', ['x' => 5]));
    }

    public function testIsNumber(): void
    {
        $this->assertSame('yes', $this->engine->renderString('{% if x is number %}yes{% endif %}', ['x' => 42]));
        $this->assertSame('', $this->engine->renderString('{% if x is number %}yes{% endif %}', ['x' => 'abc']));
    }

    public function testIsBoolean(): void
    {
        $this->assertSame('yes', $this->engine->renderString('{% if x is boolean %}yes{% endif %}', ['x' => true]));
    }

    /* ═══════════ For Loops ═══════════ */

    public function testForLoop(): void
    {
        $result = $this->engine->renderString('{% for item in items %}{{ item }}{% endfor %}', ['items' => ['a', 'b', 'c']]);
        $this->assertSame('abc', $result);
    }

    public function testForLoopIndex(): void
    {
        $result = $this->engine->renderString(
            '{% for item in items %}{{ loop.index }}{% endfor %}',
            ['items' => ['a', 'b', 'c']]
        );
        $this->assertSame('123', $result);
    }

    public function testForLoopIndex0(): void
    {
        $result = $this->engine->renderString(
            '{% for item in items %}{{ loop.index0 }}{% endfor %}',
            ['items' => ['a', 'b', 'c']]
        );
        $this->assertSame('012', $result);
    }

    public function testForLoopFirst(): void
    {
        $result = $this->engine->renderString(
            '{% for item in items %}{% if loop.first %}F{% endif %}{{ item }}{% endfor %}',
            ['items' => ['a', 'b', 'c']]
        );
        $this->assertSame('Fabc', $result);
    }

    public function testForLoopLast(): void
    {
        $result = $this->engine->renderString(
            '{% for item in items %}{{ item }}{% if loop.last %}L{% endif %}{% endfor %}',
            ['items' => ['a', 'b', 'c']]
        );
        $this->assertSame('abcL', $result);
    }

    public function testForLoopLength(): void
    {
        $result = $this->engine->renderString(
            '{% for item in items %}{{ loop.length }}{% endfor %}',
            ['items' => ['a', 'b']]
        );
        $this->assertSame('22', $result);
    }

    public function testForLoopEvenOdd(): void
    {
        $result = $this->engine->renderString(
            '{% for item in items %}{% if loop.odd %}O{% else %}E{% endif %}{% endfor %}',
            ['items' => [1, 2, 3]]
        );
        $this->assertSame('OEO', $result);
    }

    public function testForKeyValue(): void
    {
        $result = $this->engine->renderString(
            '{% for key, val in data %}{{ key }}={{ val }} {% endfor %}',
            ['data' => ['x' => 1, 'y' => 2]]
        );
        $this->assertSame('x=1 y=2 ', $result);
    }

    public function testForElse(): void
    {
        $result = $this->engine->renderString(
            '{% for item in items %}{{ item }}{% else %}empty{% endfor %}',
            ['items' => []]
        );
        $this->assertSame('empty', $result);
    }

    public function testForElseWithItems(): void
    {
        $result = $this->engine->renderString(
            '{% for item in items %}{{ item }}{% else %}empty{% endfor %}',
            ['items' => ['a']]
        );
        $this->assertSame('a', $result);
    }

    public function testNestedForLoops(): void
    {
        $result = $this->engine->renderString(
            '{% for row in rows %}{% for col in row %}{{ col }}{% endfor %};{% endfor %}',
            ['rows' => [[1, 2], [3, 4]]]
        );
        $this->assertSame('12;34;', $result);
    }

    public function testForLoopRevindex(): void
    {
        $result = $this->engine->renderString(
            '{% for item in items %}{{ loop.revindex }}{% endfor %}',
            ['items' => ['a', 'b', 'c']]
        );
        $this->assertSame('321', $result);
    }

    /* ═══════════ Set ═══════════ */

    public function testSet(): void
    {
        $result = $this->engine->renderString('{% set x = 42 %}{{ x }}', []);
        $this->assertSame('42', $result);
    }

    public function testSetString(): void
    {
        $result = $this->engine->renderString('{% set name = "Alice" %}Hello {{ name }}', []);
        $this->assertSame('Hello Alice', $result);
    }

    public function testSetExpression(): void
    {
        $result = $this->engine->renderString('{% set x = a + b %}{{ x }}', ['a' => 3, 'b' => 4]);
        $this->assertSame('7', $result);
    }

    /* ═══════════ Include ═══════════ */

    public function testInclude(): void
    {
        $this->writeTemplate('_partial.html', 'Hello {{ name }}');
        $result = $this->engine->renderString('{% include "_partial.html" %}', ['name' => 'World']);
        $this->assertSame('Hello World', $result);
    }

    public function testIncludeIgnoreMissing(): void
    {
        $result = $this->engine->renderString('{% include "nonexistent.html" ignore missing %}', []);
        $this->assertSame('', $result);
    }

    public function testIncludeWithData(): void
    {
        $this->writeTemplate('_hello.html', 'Hi {{ who }}');
        $result = $this->engine->renderString('{% include "_hello.html" with {"who": "Bob"} %}', []);
        $this->assertSame('Hi Bob', $result);
    }

    /* ═══════════ Extends / Block ═══════════ */

    public function testExtendsBlock(): void
    {
        $this->writeTemplate('base.html', '<h1>{% block title %}Default{% endblock %}</h1><div>{% block content %}{% endblock %}</div>');
        $this->writeTemplate('child.html', '{% extends "base.html" %}{% block title %}My Page{% endblock %}{% block content %}Hello{% endblock %}');
        $result = $this->engine->render('child.html', []);
        $this->assertSame('<h1>My Page</h1><div>Hello</div>', $result);
    }

    public function testExtendsBlockDefault(): void
    {
        $this->writeTemplate('base2.html', 'Title: {% block title %}Default Title{% endblock %}');
        $this->writeTemplate('child2.html', '{% extends "base2.html" %}');
        $result = $this->engine->render('child2.html', []);
        $this->assertSame('Title: Default Title', $result);
    }

    /* ═══════════ Whitespace Control ═══════════ */

    public function testWhitespaceControlVar(): void
    {
        $result = $this->engine->renderString('  {{- name -}}  ', ['name' => 'Hi']);
        $this->assertSame('Hi', $result);
    }

    public function testWhitespaceControlBlock(): void
    {
        $result = $this->engine->renderString('  {%- if true -%}  yes  {%- endif -%}  ', []);
        $this->assertSame('yes', $result);
    }

    /* ═══════════ Comments ═══════════ */

    public function testComment(): void
    {
        $result = $this->engine->renderString('Hello{# this is a comment #} World', []);
        $this->assertSame('Hello World', $result);
    }

    public function testCommentWhitespace(): void
    {
        $result = $this->engine->renderString('Hello {#- comment -#} World', []);
        $this->assertSame('HelloWorld', $result);
    }

    /* ═══════════ Globals ═══════════ */

    public function testGlobal(): void
    {
        $this->engine->addGlobal('site', 'Tina4');
        $result = $this->engine->renderString('{{ site }}', []);
        $this->assertSame('Tina4', $result);
    }

    public function testGlobalOverriddenByData(): void
    {
        $this->engine->addGlobal('site', 'Default');
        $result = $this->engine->renderString('{{ site }}', ['site' => 'Override']);
        $this->assertSame('Override', $result);
    }

    /* ═══════════ Macros ═══════════ */

    public function testMacro(): void
    {
        $tpl = '{% macro greet(name) %}Hello {{ name }}{% endmacro %}{{ greet("Alice") }}';
        $result = $this->engine->renderString($tpl, []);
        $this->assertSame('Hello Alice', $result);
    }

    public function testMacroDefault(): void
    {
        $tpl = '{% macro greet(name="World") %}Hi {{ name }}{% endmacro %}{{ greet() }}';
        $result = $this->engine->renderString($tpl, []);
        $this->assertSame('Hi World', $result);
    }

    /* ═══════════ Sandboxing ═══════════ */

    public function testSandboxAllowedVariable(): void
    {
        $this->engine->sandbox(null, null, ['safe']);
        $result = $this->engine->renderString('{{ safe }}', ['safe' => 'ok']);
        $this->assertSame('ok', $result);
        $this->engine->unsandbox();
    }

    public function testSandboxBlockedVariable(): void
    {
        $this->engine->sandbox(null, null, ['safe']);
        $result = $this->engine->renderString('{{ secret }}', ['secret' => 'hidden']);
        $this->assertSame('', $result);
        $this->engine->unsandbox();
    }

    public function testSandboxAllowedFilter(): void
    {
        $this->engine->sandbox(['upper'], null, null);
        $result = $this->engine->renderString('{{ name | upper }}', ['name' => 'hello']);
        $this->assertSame('HELLO', $result);
        $this->engine->unsandbox();
    }

    public function testSandboxBlockedFilter(): void
    {
        $this->engine->sandbox(['upper'], null, null);
        $result = $this->engine->renderString('{{ name | lower }}', ['name' => 'HELLO']);
        $this->assertSame('', $result);
        $this->engine->unsandbox();
    }

    public function testSandboxAllowedTag(): void
    {
        $this->engine->sandbox(null, ['if'], null);
        $result = $this->engine->renderString('{% if true %}yes{% endif %}', []);
        $this->assertSame('yes', $result);
        $this->engine->unsandbox();
    }

    public function testSandboxBlockedTag(): void
    {
        $this->engine->sandbox(null, ['if'], null);
        $result = $this->engine->renderString('{% for x in items %}{{ x }}{% endfor %}', ['items' => [1, 2]]);
        $this->assertSame('', $result);
        $this->engine->unsandbox();
    }

    public function testSandboxLoopVarAlwaysAllowed(): void
    {
        $this->engine->sandbox(null, null, ['items']);
        $result = $this->engine->renderString(
            '{% for item in items %}{{ loop.index }}{% endfor %}',
            ['items' => ['a', 'b']]
        );
        $this->assertSame('12', $result);
        $this->engine->unsandbox();
    }

    /* ═══════════ Fragment Caching ═══════════ */

    public function testCacheBasic(): void
    {
        $result1 = $this->engine->renderString('{% cache "test" 60 %}Hello {{ name }}{% endcache %}', ['name' => 'World']);
        $this->assertSame('Hello World', $result1);

        // Second call should return cached
        $result2 = $this->engine->renderString('{% cache "test" 60 %}Hello {{ name }}{% endcache %}', ['name' => 'Changed']);
        $this->assertSame('Hello World', $result2);
    }

    /* ═══════════ Render from file ═══════════ */

    public function testRenderFromFile(): void
    {
        $this->writeTemplate('simple.html', 'Hello {{ name }}!');
        $result = $this->engine->render('simple.html', ['name' => 'Frond']);
        $this->assertSame('Hello Frond!', $result);
    }

    public function testRenderFileMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->engine->render('missing.html', []);
    }

    /* ═══════════ Custom Tests ═══════════ */

    public function testCustomTest(): void
    {
        $this->engine->addTest('positive', fn($v) => $v > 0);
        $this->assertSame('yes', $this->engine->renderString('{% if x is positive %}yes{% endif %}', ['x' => 5]));
        $this->assertSame('', $this->engine->renderString('{% if x is positive %}yes{% endif %}', ['x' => -1]));
    }

    /* ═══════════ Math ═══════════ */

    public function testMathAdd(): void
    {
        $this->assertSame('7', $this->engine->renderString('{{ a + b }}', ['a' => 3, 'b' => 4]));
    }

    public function testMathMultiply(): void
    {
        $this->assertSame('12', $this->engine->renderString('{{ a * b }}', ['a' => 3, 'b' => 4]));
    }

    /* ═══════════ Range ═══════════ */

    public function testRange(): void
    {
        $result = $this->engine->renderString('{% for i in 1..3 %}{{ i }}{% endfor %}', []);
        $this->assertSame('123', $result);
    }

    /* ═══════════ Array Literal ═══════════ */

    public function testArrayLiteral(): void
    {
        $result = $this->engine->renderString('{{ [1, 2, 3] | join(",") }}', []);
        $this->assertSame('1,2,3', $result);
    }

    /* ═══════════ Dict Literal ═══════════ */

    public function testDictLiteral(): void
    {
        $result = $this->engine->renderString('{{ {"a": 1, "b": 2} | keys | join(",") }}', []);
        $this->assertSame('a,b', $result);
    }

    /* ═══════════ Filter: filter ═══════════ */

    public function testFilterFilter(): void
    {
        $result = $this->engine->renderString('{{ items | filter | join(",") }}', ['items' => [0, 1, '', 2, null, 3]]);
        $this->assertSame('1,2,3', $result);
    }

    /* ═══════════ Complex Integration ═══════════ */

    public function testComplexTemplate(): void
    {
        $tpl = <<<'TPL'
{% for user in users %}{% if user.active %}{{ user.name | upper }}{% endif %}{% endfor %}
TPL;
        $users = [
            ['name' => 'Alice', 'active' => true],
            ['name' => 'Bob', 'active' => false],
            ['name' => 'Carol', 'active' => true],
        ];
        $result = $this->engine->renderString($tpl, ['users' => $users]);
        $this->assertSame('ALICECAROL', $result);
    }

    public function testJsonDecodeFilter(): void
    {
        $result = $this->engine->renderString('{% set data = json | json_decode %}{{ data.name }}', ['json' => '{"name":"Bob"}']);
        $this->assertSame('Bob', $result);
    }
}
