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

    public function testSetArithmeticAddition(): void
    {
        $result = $this->engine->renderString('{% set x = 5 + 3 %}{{ x }}', []);
        $this->assertSame('8', $result);
    }

    public function testSetArithmeticSubtraction(): void
    {
        $result = $this->engine->renderString('{% set x = 10 - 4 %}{{ x }}', []);
        $this->assertSame('6', $result);
    }

    public function testSetArithmeticWithContext(): void
    {
        $result = $this->engine->renderString('{% set x = a * b %}{{ x }}', ['a' => 3, 'b' => 7]);
        $this->assertSame('21', $result);
    }

    public function testArithmeticFloorDivision(): void
    {
        $result = $this->engine->renderString('{{ 7 // 2 }}', []);
        $this->assertSame('3', $result);
    }

    public function testArithmeticPower(): void
    {
        $result = $this->engine->renderString('{{ 2 ** 3 }}', []);
        $this->assertSame('8', $result);
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

    public function testExtendsWithLeadingWhitespace(): void
    {
        $this->writeTemplate('base3.html', '<html><body>{% block content %}default{% endblock %}</body></html>');
        $this->writeTemplate('child3.html', "  {% extends \"base3.html\" %}\n{% block content %}<h1>Hello</h1>{% endblock %}");
        $result = $this->engine->render('child3.html', []);
        $this->assertStringContainsString('<html><body>', $result);
        $this->assertStringContainsString('<h1>Hello</h1>', $result);
    }

    public function testExtendsWithLeadingNewlines(): void
    {
        $this->writeTemplate('base4.html', '<html><body>{% block content %}default{% endblock %}</body></html>');
        $this->writeTemplate('child4.html', "\n\n{% extends \"base4.html\" %}\n{% block content %}<h1>Hello</h1>{% endblock %}");
        $result = $this->engine->render('child4.html', []);
        $this->assertStringContainsString('<html><body>', $result);
        $this->assertStringContainsString('<h1>Hello</h1>', $result);
    }

    public function testExtendsWithVariablesInBlocks(): void
    {
        $this->writeTemplate('base5.html', "<head><title>{% block title %}Default{% endblock %}</title></head>\n<body>{% block content %}{% endblock %}</body>");
        $this->writeTemplate('error5.html', "\n{% extends \"base5.html\" %}\n{% block title %}Error {{ code }}{% endblock %}\n{% block content %}<div class=\"card\"><h1>{{ code }}</h1><p>{{ msg }}</p></div>{% endblock %}");
        $result = $this->engine->render('error5.html', ['code' => 500, 'msg' => 'Internal Server Error']);
        $this->assertStringContainsString('<title>Error 500</title>', $result);
        $this->assertStringContainsString('<h1>500</h1>', $result);
        $this->assertStringContainsString('Internal Server Error', $result);
    }

    public function testParentIncludesParentBlockContent(): void
    {
        $this->writeTemplate('base_p1.html', '{% block content %}Parent Content{% endblock %}');
        $this->writeTemplate('child_p1.html', '{% extends "base_p1.html" %}{% block content %}{{ parent() }} + Child Content{% endblock %}');
        $result = $this->engine->render('child_p1.html', []);
        $this->assertStringContainsString('Parent Content', $result);
        $this->assertStringContainsString('+ Child Content', $result);
    }

    public function testSuperIsAliasForParent(): void
    {
        $this->writeTemplate('base_p2.html', '{% block content %}Parent Content{% endblock %}');
        $this->writeTemplate('child_p2.html', '{% extends "base_p2.html" %}{% block content %}{{ super() }} + Child Content{% endblock %}');
        $result = $this->engine->render('child_p2.html', []);
        $this->assertStringContainsString('Parent Content', $result);
        $this->assertStringContainsString('+ Child Content', $result);
    }

    public function testNoParentMeansFullReplacement(): void
    {
        $this->writeTemplate('base_p3.html', '{% block content %}Parent Content{% endblock %}');
        $this->writeTemplate('child_p3.html', '{% extends "base_p3.html" %}{% block content %}Child Only{% endblock %}');
        $result = $this->engine->render('child_p3.html', []);
        $this->assertSame('Child Only', $result);
    }

    public function testParentWithTemplateVariables(): void
    {
        $this->writeTemplate('base_p4.html', '{% block content %}Hello {{ name }}{% endblock %}');
        $this->writeTemplate('child_p4.html', '{% extends "base_p4.html" %}{% block content %}{{ parent() }} and Goodbye {{ name }}{% endblock %}');
        $result = $this->engine->render('child_p4.html', ['name' => 'World']);
        $this->assertStringContainsString('Hello World', $result);
        $this->assertStringContainsString('and Goodbye World', $result);
    }

    public function testUnreplacedBlocksKeepParentDefault(): void
    {
        $this->writeTemplate('base_p5.html', '{% block title %}Default Title{% endblock %} - {% block content %}Default Content{% endblock %}');
        $this->writeTemplate('child_p5.html', '{% extends "base_p5.html" %}{% block content %}Custom Content{% endblock %}');
        $result = $this->engine->render('child_p5.html', []);
        $this->assertStringContainsString('Default Title', $result);
        $this->assertStringContainsString('Custom Content', $result);
        $this->assertStringNotContainsString('Default Content', $result);
    }

    public function testMultipleBlocksWithParent(): void
    {
        $this->writeTemplate('base_p6.html', '{% block header %}Base Header{% endblock %}|{% block footer %}Base Footer{% endblock %}');
        $this->writeTemplate('child_p6.html', '{% extends "base_p6.html" %}{% block header %}{{ parent() }} + Extra Header{% endblock %}{% block footer %}{{ parent() }} + Extra Footer{% endblock %}');
        $result = $this->engine->render('child_p6.html', []);
        $this->assertStringContainsString('Base Header', $result);
        $this->assertStringContainsString('+ Extra Header', $result);
        $this->assertStringContainsString('Base Footer', $result);
        $this->assertStringContainsString('+ Extra Footer', $result);
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

    public function testMacroHtmlOutput(): void
    {
        $tpl = '{% macro link(url, text) %}<a href="{{ url }}">{{ text }}</a>{% endmacro %}{{ link("https://tina4.com", "Tina4") }}';
        $result = $this->engine->renderString($tpl, []);
        $this->assertSame('<a href="https://tina4.com">Tina4</a>', $result);
    }

    public function testMacroNested(): void
    {
        $tpl = '{% macro wrap(x) %}<b>{{ x }}</b>{% endmacro %}{% macro btn(label) %}{{ wrap(label) }}{% endmacro %}{{ btn("test") }}';
        $result = $this->engine->renderString($tpl, []);
        $this->assertSame('<b>test</b>', $result);
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

    // ── Form Token Tests ──────────────────────────────────────

    public function testFormTokenFunctionRendersHiddenInput(): void
    {
        $result = $this->engine->renderString('{{ form_token() | raw }}', []);
        $this->assertStringContainsString('<input type="hidden" name="formToken" value="', $result);

        // Extract JWT and verify structure
        preg_match('/value="([^"]+)"/', $result, $matches);
        $this->assertNotEmpty($matches);
        $token = $matches[1];
        $parts = explode('.', $token);
        $this->assertCount(3, $parts, 'JWT should have 3 dot-separated parts');
    }

    public function testFormTokenCamelCaseAlias(): void
    {
        $result = $this->engine->renderString('{{ formToken() | raw }}', []);
        $this->assertStringContainsString('<input type="hidden" name="formToken" value="', $result);
    }

    public function testFormTokenFilter(): void
    {
        $result = $this->engine->renderString('{{ "" | form_token | raw }}', []);
        $this->assertStringContainsString('<input type="hidden" name="formToken" value="', $result);
    }

    public function testFormTokenIsValidJwt(): void
    {
        $result = $this->engine->renderString('{{ form_token() | raw }}', []);
        preg_match('/value="([^"]+)"/', $result, $matches);
        $token = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');

        $this->assertTrue(\Tina4\Auth::validToken($token), 'Token should be valid');
        $payload = \Tina4\Auth::getPayload($token);
        $this->assertSame('form', $payload['type']);
    }

    public function testFormTokenWithDescriptor(): void
    {
        $result = $this->engine->renderString('{{ "admin" | form_token | raw }}', []);
        preg_match('/value="([^"]+)"/', $result, $matches);
        $token = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');

        $payload = \Tina4\Auth::getPayload($token);
        $this->assertNotNull($payload);
        $this->assertSame('form', $payload['type']);
        $this->assertSame('admin', $payload['context']);
    }

    /* ═══════════ Raw Block ═══════════ */

    public function testRawPreservesVarSyntax(): void
    {
        $this->assertSame('{{ name }}', $this->engine->renderString('{% raw %}{{ name }}{% endraw %}', ['name' => 'Alice']));
    }

    public function testRawPreservesBlockSyntax(): void
    {
        $this->assertSame('{% if true %}yes{% endif %}', $this->engine->renderString('{% raw %}{% if true %}yes{% endif %}{% endraw %}'));
    }

    public function testRawMixedWithNormal(): void
    {
        $result = $this->engine->renderString('Hello {{ name }}! {% raw %}{{ not_parsed }}{% endraw %} done', ['name' => 'World']);
        $this->assertSame('Hello World! {{ not_parsed }} done', $result);
    }

    public function testMultipleRawBlocks(): void
    {
        $result = $this->engine->renderString('{% raw %}{{ a }}{% endraw %} mid {% raw %}{{ b }}{% endraw %}');
        $this->assertSame('{{ a }} mid {{ b }}', $result);
    }

    public function testRawBlockMultiline(): void
    {
        $src = "{% raw %}\n{{ var }}\n{% tag %}\n{% endraw %}";
        $this->assertSame("\n{{ var }}\n{% tag %}\n", $this->engine->renderString($src));
    }

    /* ═══════════ From Import ═══════════ */

    public function testFromImportBasic(): void
    {
        $this->writeTemplate('macros.twig', '{% macro greeting(name) %}Hello {{ name }}!{% endmacro %}');
        $result = $this->engine->renderString('{% from "macros.twig" import greeting %}{{ greeting("World") }}');
        $this->assertSame('Hello World!', $result);
    }

    public function testFromImportMultiple(): void
    {
        $this->writeTemplate('helpers.twig', '{% macro bold(t) %}B{{ t }}B{% endmacro %}{% macro italic(t) %}I{{ t }}I{% endmacro %}');
        $result = $this->engine->renderString('{% from "helpers.twig" import bold, italic %}{{ bold("hi") }} {{ italic("there") }}');
        $this->assertStringContainsString('BhiB', $result);
        $this->assertStringContainsString('IthereI', $result);
    }

    public function testFromImportSelective(): void
    {
        $this->writeTemplate('mix.twig', '{% macro used(x) %}[{{ x }}]{% endmacro %}{% macro unused(x) %}{{{ x }}}{% endmacro %}');
        $result = $this->engine->renderString('{% from "mix.twig" import used %}{{ used("ok") }}');
        $this->assertStringContainsString('[ok]', $result);
    }

    public function testFromImportSubdirectory(): void
    {
        $subdir = $this->templateDir . '/macros';
        if (!is_dir($subdir)) mkdir($subdir, 0777, true);
        file_put_contents($subdir . '/forms.twig', '{% macro field(label, name) %}{{ label }}:{{ name }}{% endmacro %}');
        $result = $this->engine->renderString('{% from "macros/forms.twig" import field %}{{ field("Name", "name") }}');
        $this->assertStringContainsString('Name:name', $result);
        // Clean up subdirectory
        unlink($subdir . '/forms.twig');
        rmdir($subdir);
    }

    /* ═══════════ Spaceless ═══════════ */

    public function testSpacelessRemovesWhitespaceBetweenTags(): void
    {
        $result = $this->engine->renderString('{% spaceless %}<div>  <p>  Hello  </p>  </div>{% endspaceless %}');
        $this->assertSame('<div><p>  Hello  </p></div>', $result);
    }

    public function testSpacelessPreservesContentWhitespace(): void
    {
        $result = $this->engine->renderString('{% spaceless %}<span>  text  </span>{% endspaceless %}');
        $this->assertSame('<span>  text  </span>', $result);
    }

    public function testSpacelessMultiline(): void
    {
        $src = "{% spaceless %}\n<div>\n    <p>Hi</p>\n</div>\n{% endspaceless %}";
        $result = $this->engine->renderString($src);
        $this->assertStringContainsString('<div><p>', $result);
        $this->assertStringContainsString('</p></div>', $result);
    }

    public function testSpacelessWithVariables(): void
    {
        $result = $this->engine->renderString(
            '{% spaceless %}<div>  <span>{{ name }}</span>  </div>{% endspaceless %}',
            ['name' => 'Alice']
        );
        $this->assertSame('<div><span>Alice</span></div>', $result);
    }

    /* ═══════════ Autoescape ═══════════ */

    public function testAutoescapeFalseDisablesEscaping(): void
    {
        $result = $this->engine->renderString(
            '{% autoescape false %}{{ html }}{% endautoescape %}',
            ['html' => '<b>bold</b>']
        );
        $this->assertSame('<b>bold</b>', $result);
    }

    public function testAutoescapeTrueKeepsEscaping(): void
    {
        $result = $this->engine->renderString(
            '{% autoescape true %}{{ html }}{% endautoescape %}',
            ['html' => '<b>bold</b>']
        );
        $this->assertStringContainsString('&lt;b&gt;', $result);
    }

    public function testAutoescapeFalseWithFilters(): void
    {
        $result = $this->engine->renderString(
            '{% autoescape false %}{{ name | upper }}{% endautoescape %}',
            ['name' => 'alice']
        );
        $this->assertSame('ALICE', $result);
    }

    public function testAutoescapeFalseMultipleVariables(): void
    {
        $result = $this->engine->renderString(
            '{% autoescape false %}{{ a }} {{ b }}{% endautoescape %}',
            ['a' => '<i>x</i>', 'b' => '<b>y</b>']
        );
        $this->assertSame('<i>x</i> <b>y</b>', $result);
    }

    /* ═══════════ Inline If ═══════════ */

    public function testInlineIfTrueBranch(): void
    {
        $result = $this->engine->renderString(
            "{{ 'yes' if active else 'no' }}",
            ['active' => true]
        );
        $this->assertSame('yes', $result);
    }

    public function testInlineIfFalseBranch(): void
    {
        $result = $this->engine->renderString(
            "{{ 'yes' if active else 'no' }}",
            ['active' => false]
        );
        $this->assertSame('no', $result);
    }

    public function testInlineIfWithVariable(): void
    {
        $result = $this->engine->renderString(
            "{{ name if name else 'Anonymous' }}",
            ['name' => 'Alice']
        );
        $this->assertSame('Alice', $result);
    }

    public function testInlineIfWithMissingVariable(): void
    {
        $result = $this->engine->renderString(
            "{{ name if name else 'Anonymous' }}",
            []
        );
        $this->assertSame('Anonymous', $result);
    }

    public function testInlineIfWithNumeric(): void
    {
        $result = $this->engine->renderString(
            "{{ count if count else 0 }}",
            ['count' => 5]
        );
        $this->assertSame('5', $result);
    }

    // ── Token Pre-Compilation (Cache) Tests ─────────────────────────

    public function testRenderStringCacheSameOutput(): void
    {
        $src = 'Hello {{ name }}!';
        $first = $this->engine->renderString($src, ['name' => 'World']);
        $second = $this->engine->renderString($src, ['name' => 'World']);
        $this->assertSame('Hello World!', $first);
        $this->assertSame($first, $second);
    }

    public function testRenderStringCacheDifferentData(): void
    {
        $src = '{{ greeting }}, {{ name }}!';
        $r1 = $this->engine->renderString($src, ['greeting' => 'Hi', 'name' => 'Alice']);
        $r2 = $this->engine->renderString($src, ['greeting' => 'Bye', 'name' => 'Bob']);
        $this->assertSame('Hi, Alice!', $r1);
        $this->assertSame('Bye, Bob!', $r2);
    }

    public function testRenderFileCacheSameOutput(): void
    {
        $this->writeTemplate('cached.html', '<p>{{ msg }}</p>');
        $first = $this->engine->render('cached.html', ['msg' => 'hello']);
        $second = $this->engine->render('cached.html', ['msg' => 'hello']);
        $this->assertSame('<p>hello</p>', $first);
        $this->assertSame($first, $second);
    }

    public function testRenderFileCacheDifferentData(): void
    {
        $this->writeTemplate('cached2.html', '{{ x }} + {{ y }}');
        $r1 = $this->engine->render('cached2.html', ['x' => 1, 'y' => 2]);
        $r2 = $this->engine->render('cached2.html', ['x' => 10, 'y' => 20]);
        $this->assertSame('1 + 2', $r1);
        $this->assertSame('10 + 20', $r2);
    }

    public function testCacheInvalidationOnFileChange(): void
    {
        putenv('TINA4_DEBUG=true');
        try {
            $this->writeTemplate('changing.html', 'Version 1: {{ v }}');
            $r1 = $this->engine->render('changing.html', ['v' => 'a']);
            $this->assertSame('Version 1: a', $r1);

            // Change file content (touch to update mtime)
            sleep(1); // Ensure mtime changes (filesystem resolution is 1s on some OS)
            $this->writeTemplate('changing.html', 'Version 2: {{ v }}');
            clearstatcache();
            $r2 = $this->engine->render('changing.html', ['v' => 'b']);
            $this->assertSame('Version 2: b', $r2);
        } finally {
            putenv('TINA4_DEBUG');
        }
    }

    public function testClearCache(): void
    {
        $this->engine->renderString('{{ x }}', ['x' => 1]);
        $this->engine->clearCache();
        // After clearing, rendering still works (re-tokenizes)
        $result = $this->engine->renderString('{{ x }}', ['x' => 2]);
        $this->assertSame('2', $result);
    }

    public function testRenderStringWithForLoopCached(): void
    {
        $src = '{% for i in items %}{{ i }},{% endfor %}';
        $data = ['items' => [1, 2, 3]];
        $first = $this->engine->renderString($src, $data);
        $second = $this->engine->renderString($src, $data);
        $this->assertSame('1,2,3,', $first);
        $this->assertSame($first, $second);
    }

    public function testRenderStringWithIfCached(): void
    {
        $src = '{% if show %}visible{% else %}hidden{% endif %}';
        $r1 = $this->engine->renderString($src, ['show' => true]);
        $r2 = $this->engine->renderString($src, ['show' => false]);
        $this->assertSame('visible', $r1);
        $this->assertSame('hidden', $r2);
    }

    /* ═══════════ Filters in if-conditions ═══════════ */

    public function testIfFilterLengthGreaterThanZeroNonEmpty(): void
    {
        $src = '{% if items|length > 0 %}yes{% else %}no{% endif %}';
        $this->assertSame('yes', $this->engine->renderString($src, ['items' => ['a', 'b', 'c']]));
    }

    public function testIfFilterLengthGreaterThanZeroEmpty(): void
    {
        $src = '{% if items|length > 0 %}yes{% else %}no{% endif %}';
        $this->assertSame('no', $this->engine->renderString($src, ['items' => []]));
    }

    public function testIfFilterLengthEqualsExact(): void
    {
        $src = '{% if items|length == 3 %}three{% else %}other{% endif %}';
        $this->assertSame('three', $this->engine->renderString($src, ['items' => ['a', 'b', 'c']]));
        $this->assertSame('other', $this->engine->renderString($src, ['items' => ['a', 'b']]));
    }

    public function testIfFilterUpperEqualsString(): void
    {
        $src = '{% if name|upper == "ALICE" %}match{% else %}no{% endif %}';
        $this->assertSame('match', $this->engine->renderString($src, ['name' => 'alice']));
        $this->assertSame('no', $this->engine->renderString($src, ['name' => 'bob']));
    }

    public function testIfCompoundFilterConditions(): void
    {
        $src = '{% if items|length >= 2 and name|upper == "ALICE" %}yes{% else %}no{% endif %}';
        $this->assertSame('yes', $this->engine->renderString($src, ['items' => ['a', 'b', 'c'], 'name' => 'alice']));
        $this->assertSame('no', $this->engine->renderString($src, ['items' => ['a'], 'name' => 'alice']));
        $this->assertSame('no', $this->engine->renderString($src, ['items' => ['a', 'b', 'c'], 'name' => 'bob']));
    }

    public function testIfNonFilterConditionStillWorks(): void
    {
        $src = '{% if x > 5 %}big{% else %}small{% endif %}';
        $this->assertSame('big', $this->engine->renderString($src, ['x' => 10]));
        $this->assertSame('small', $this->engine->renderString($src, ['x' => 3]));
    }

    /* ═══════════ Comparison Operators in If ═══════════ */

    public function testIfGreaterThan(): void
    {
        $this->assertSame('yes', $this->engine->renderString('{% if x > 5 %}yes{% endif %}', ['x' => 10]));
    }

    public function testIfLessThan(): void
    {
        $this->assertSame('yes', $this->engine->renderString('{% if x < 5 %}yes{% endif %}', ['x' => 3]));
    }

    public function testIfGreaterThanOrEqual(): void
    {
        $this->assertSame('yes', $this->engine->renderString('{% if x >= 5 %}yes{% endif %}', ['x' => 5]));
    }

    public function testIfNotEqual(): void
    {
        $this->assertSame('yes', $this->engine->renderString('{% if x != 5 %}yes{% endif %}', ['x' => 3]));
    }

    public function testIfAndOperator(): void
    {
        $this->assertSame('yes', $this->engine->renderString('{% if a and b %}yes{% endif %}', ['a' => true, 'b' => true]));
        $this->assertSame('', $this->engine->renderString('{% if a and b %}yes{% endif %}', ['a' => true, 'b' => false]));
    }

    public function testIfOrOperator(): void
    {
        $this->assertSame('yes', $this->engine->renderString('{% if a or b %}yes{% endif %}', ['a' => false, 'b' => true]));
    }

    public function testIfNotOperator(): void
    {
        $this->assertSame('show', $this->engine->renderString('{% if not hidden %}show{% endif %}', ['hidden' => false]));
    }

    public function testIfNestedIf(): void
    {
        $tpl = '{% if a %}{% if b %}both{% endif %}{% endif %}';
        $this->assertSame('both', $this->engine->renderString($tpl, ['a' => true, 'b' => true]));
    }

    /* ═══════════ For Loop Extended ═══════════ */

    public function testForLoopKeyValue(): void
    {
        $tpl = '{% for k, v in data %}{{ k }}={{ v }} {% endfor %}';
        $result = $this->engine->renderString($tpl, ['data' => ['a' => 1, 'b' => 2]]);
        $this->assertStringContainsString('a=1', $result);
        $this->assertStringContainsString('b=2', $result);
    }

    public function testForLoopEvenOddExtended(): void
    {
        $tpl = '{% for i in items %}{% if loop.even %}E{% else %}O{% endif %}{% endfor %}';
        $this->assertSame('OEOE', $this->engine->renderString($tpl, ['items' => [1, 2, 3, 4]]));
    }

    /* ═══════════ Filter Edge Cases ═══════════ */

    public function testFilterLengthEqualsZero(): void
    {
        $src = '{% if items|length == 0 %}empty{% else %}has{% endif %}';
        $this->assertSame('empty', $this->engine->renderString($src, ['items' => []]));
    }

    public function testFilterLengthNotEquals(): void
    {
        $src = '{% if items|length != 1 %}multi{% else %}single{% endif %}';
        $this->assertSame('multi', $this->engine->renderString($src, ['items' => [1, 2]]));
    }

    public function testFilterStringLength(): void
    {
        $src = '{% if name|length > 3 %}long{% else %}short{% endif %}';
        $this->assertSame('long', $this->engine->renderString($src, ['name' => 'Alice']));
    }

    public function testFilterFirstComparison(): void
    {
        $src = '{% if items|first == 1 %}yes{% else %}no{% endif %}';
        $this->assertSame('yes', $this->engine->renderString($src, ['items' => [1, 2, 3]]));
    }

    public function testFilterLastComparison(): void
    {
        $src = '{% if items|last == 3 %}yes{% else %}no{% endif %}';
        $this->assertSame('yes', $this->engine->renderString($src, ['items' => [1, 2, 3]]));
    }

    public function testFilterWithOrOperator(): void
    {
        $src = '{% if items|length == 0 or name|upper == "BOB" %}match{% else %}nope{% endif %}';
        $this->assertSame('match', $this->engine->renderString($src, ['items' => [1], 'name' => 'Bob']));
    }

    public function testFilterWithSpaces(): void
    {
        $src = '{% if items | length > 0 %}yes{% else %}no{% endif %}';
        $this->assertSame('yes', $this->engine->renderString($src, ['items' => [1]]));
    }

    public function testFilterTruthyCheck(): void
    {
        $this->assertSame('yes', $this->engine->renderString('{% if items %}yes{% else %}no{% endif %}', ['items' => [1]]));
        $this->assertSame('no', $this->engine->renderString('{% if items %}yes{% else %}no{% endif %}', ['items' => []]));
    }

    public function testFilterInElseif(): void
    {
        $src = '{% if items|length == 0 %}empty{% elseif items|length == 1 %}one{% else %}many{% endif %}';
        $this->assertSame('one', $this->engine->renderString($src, ['items' => [42]]));
    }

    /* ═══════════ Set with concat ═══════════ */

    public function testSetWithConcat(): void
    {
        $result = $this->engine->renderString('{% set msg = "Hi " ~ name %}{{ msg }}', ['name' => 'Alice']);
        $this->assertSame('Hi Alice', $result);
    }

    /* ═══════════ Dynamic Dict Key Access ═══════════ */

    public function testVariableKeyViaSet(): void
    {
        $ctx = ['balances' => ['9600.000' => 342120.0]];
        $result = $this->engine->renderString('{% set k = "9600.000" %}{{ balances[k] }}', $ctx);
        $this->assertSame('342120', $result);
    }

    public function testVariableKeyFromLoop(): void
    {
        $ctx = [
            'balances' => ['A' => 100, 'B' => 200],
            'items' => [['code' => 'A'], ['code' => 'B']],
        ];
        $result = $this->engine->renderString('{% for i in items %}{{ balances[i.code] }},{% endfor %}', $ctx);
        $this->assertSame('100,200,', $result);
    }

    public function testStringLiteralKeyStillWorks(): void
    {
        $ctx = ['data' => ['key' => 'val']];
        $this->assertSame('val', $this->engine->renderString('{{ data["key"] }}', $ctx));
    }

    public function testIntKeyStillWorks(): void
    {
        $this->assertSame('20', $this->engine->renderString('{{ items[1] }}', ['items' => [10, 20, 30]]));
    }

    public function testNestedVariableKey(): void
    {
        $ctx = ['lookup' => ['x' => 'found'], 'config' => ['key' => 'x']];
        $result = $this->engine->renderString('{{ lookup[config.key] }}', $ctx);
        $this->assertSame('found', $result);
    }

    /* ═══════════ Tilde Concatenation with Ternary ═══════════ */

    public function testTildeWithTernary(): void
    {
        $ctx = ['contact_type' => 'customer'];
        $result = $this->engine->renderString('{{ "/path?type=" ~ (contact_type ? contact_type : "all") }}', $ctx);
        $this->assertSame('/path?type=customer', $result);
    }

    public function testTildeTernaryFalseBranch(): void
    {
        $result = $this->engine->renderString('{{ "/path?type=" ~ (contact_type ? contact_type : "all") }}', []);
        $this->assertSame('/path?type=all', $result);
    }

    public function testQuestionMarkInUrlString(): void
    {
        $result = $this->engine->renderString('{{ "/search?q=hello" }}', []);
        $this->assertSame('/search?q=hello', $result);
    }

    public function testTildeConcatWithUrlQuery(): void
    {
        $ctx = ['base' => '/api', 'sort' => 'name'];
        $result = $this->engine->renderString('{{ base ~ "?sort=" ~ sort }}', $ctx);
        $this->assertSame('/api?sort=name', $result);
    }

    /* ═══════════ Parent / Super in Block Inheritance ═══════════ */

    public function testParentIncludesParentContent(): void
    {
        $this->writeTemplate('base_p.html', '<head>{% block head %}<title>Base</title>{% endblock %}</head>');
        $this->writeTemplate('child_p.html', '{% extends "base_p.html" %}{% block head %}{{ parent() }}<link href="/app.css">{% endblock %}');
        $result = $this->engine->render('child_p.html', []);
        $this->assertStringContainsString('<title>Base</title>', $result);
        $this->assertStringContainsString('/app.css', $result);
    }

    public function testSuperIsAlias(): void
    {
        $this->writeTemplate('base_s.html', '{% block footer %}Base Footer{% endblock %}');
        $this->writeTemplate('child_s.html', '{% extends "base_s.html" %}{% block footer %}{{ super() }} | Child{% endblock %}');
        $result = $this->engine->render('child_s.html', []);
        $this->assertStringContainsString('Base Footer', $result);
        $this->assertStringContainsString('Child', $result);
    }

    public function testNoParentFullReplace(): void
    {
        $this->writeTemplate('base_r.html', '{% block content %}OLD{% endblock %}');
        $this->writeTemplate('child_r.html', '{% extends "base_r.html" %}{% block content %}NEW{% endblock %}');
        $result = $this->engine->render('child_r.html', []);
        $this->assertStringContainsString('NEW', $result);
        $this->assertStringNotContainsString('OLD', $result);
    }

    public function testParentWithVariables(): void
    {
        $this->writeTemplate('base_v.html', '{% block greeting %}Hello {{ name }}{% endblock %}');
        $this->writeTemplate('child_v.html', '{% extends "base_v.html" %}{% block greeting %}{{ parent() }}! Welcome back.{% endblock %}');
        $result = $this->engine->render('child_v.html', ['name' => 'Alice']);
        $this->assertStringContainsString('Hello Alice', $result);
        $this->assertStringContainsString('Welcome back', $result);
    }

    public function testParentNotCalledReturnsChildOnly(): void
    {
        $this->writeTemplate('base_nc.html', '{% block nav %}Parent Nav{% endblock %} | {% block body %}Parent Body{% endblock %}');
        $this->writeTemplate('child_nc.html', '{% extends "base_nc.html" %}{% block body %}Child Body{% endblock %}');
        $result = $this->engine->render('child_nc.html', []);
        $this->assertStringContainsString('Parent Nav', $result);
        $this->assertStringContainsString('Child Body', $result);
        $this->assertStringNotContainsString('Parent Body', $result);
    }

    public function testMultipleBlocksWithParentExtended(): void
    {
        $this->writeTemplate('base_m2.html', '{% block css %}base.css{% endblock %} {% block js %}base.js{% endblock %}');
        $this->writeTemplate('child_m2.html', '{% extends "base_m2.html" %}{% block css %}{{ parent() }} app.css{% endblock %}{% block js %}{{ parent() }} app.js{% endblock %}');
        $result = $this->engine->render('child_m2.html', []);
        $this->assertStringContainsString('base.css', $result);
        $this->assertStringContainsString('app.css', $result);
        $this->assertStringContainsString('base.js', $result);
        $this->assertStringContainsString('app.js', $result);
    }

    /* ═══════════ Default Filter ═══════════ */

    public function testDefaultFilterNone(): void
    {
        $this->assertSame('N/A', $this->engine->renderString('{{ v|default("N/A") }}', []));
    }

    public function testDefaultFilterEmptyString(): void
    {
        $this->assertSame('N/A', $this->engine->renderString('{{ v|default("N/A") }}', ['v' => '']));
    }

    public function testDefaultFilterHasValue(): void
    {
        $this->assertSame('ok', $this->engine->renderString('{{ v|default("N/A") }}', ['v' => 'ok']));
    }

    /* ═══════════ Slug Filter ═══════════ */

    public function testSlugFilter(): void
    {
        $result = $this->engine->renderString('{{ text|slug }}', ['text' => 'Hello World!']);
        $this->assertStringContainsString('hello', $result);
        $this->assertStringContainsString('-', $result);
    }

    /* ═══════════ Truncate Filter ═══════════ */

    public function testTruncateFilterLong(): void
    {
        $result = $this->engine->renderString('{{ text|truncate(5) }}', ['text' => 'Hello World']);
        $this->assertTrue(strlen($result) < strlen('Hello World'));
    }

    public function testTruncateFilterShort(): void
    {
        $result = $this->engine->renderString('{{ text|truncate(100) }}', ['text' => 'Hi']);
        $this->assertSame('Hi', $result);
    }

    /* ═══════════ Wordwrap Filter ═══════════ */

    public function testWordwrapFilter(): void
    {
        $result = $this->engine->renderString('{{ text|wordwrap(5) }}', ['text' => 'Hello World Test']);
        $this->assertStringContainsString("\n", $result);
    }

    /* ═══════════ Number Format Filter ═══════════ */

    public function testNumberFormatFilter(): void
    {
        $result = $this->engine->renderString('{{ val|number_format(2) }}', ['val' => 1234.5]);
        $this->assertStringContainsString('1', $result);
        $this->assertStringContainsString('.', $result);
    }

    /* ═══════════ URL Encode Filter ═══════════ */

    public function testUrlEncodeFilter(): void
    {
        $result = $this->engine->renderString('{{ text|url_encode }}', ['text' => 'hello world']);
        // PHP rawurlencode uses %20, urlencode uses +
        $this->assertTrue(
            str_contains($result, '%20') || str_contains($result, '+'),
            'URL encode should replace spaces with %20 or +'
        );
    }

    /* ═══════════ Base64 Filters ═══════════ */

    public function testBase64EncodeFilter(): void
    {
        $result = $this->engine->renderString('{{ text|base64_encode }}', ['text' => 'hello']);
        $this->assertSame('aGVsbG8=', $result);
    }

    public function testBase64DecodeFilter(): void
    {
        $result = $this->engine->renderString('{{ text|base64_decode }}', ['text' => 'aGVsbG8=']);
        $this->assertSame('hello', $result);
    }

    /* ═══════════ SHA256 Filter ═══════════ */

    public function testSha256Filter(): void
    {
        $result = $this->engine->renderString('{{ text|sha256 }}', ['text' => 'hello']);
        $this->assertEquals(64, strlen($result));
    }

    /* ═══════════ Int/Float/String Cast Filters ═══════════ */

    public function testIntFilter(): void
    {
        $this->assertSame('42', $this->engine->renderString('{{ val|int }}', ['val' => '42.7']));
    }

    public function testFloatFilter(): void
    {
        $result = $this->engine->renderString('{{ val|float }}', ['val' => '42.5']);
        $this->assertStringContainsString('42.5', $result);
    }

    public function testStringFilter(): void
    {
        $this->assertSame('42', $this->engine->renderString('{{ val|string }}', ['val' => 42]));
    }

    /* ═══════════ Unique / Map / Column / Batch Filters ═══════════ */

    public function testUniqueFilter(): void
    {
        $result = $this->engine->renderString('{{ items|unique|join(", ") }}', ['items' => [1, 2, 2, 3, 3]]);
        $this->assertStringContainsString('1', $result);
        $this->assertStringContainsString('2', $result);
        $this->assertStringContainsString('3', $result);
    }

    public function testMapFilter(): void
    {
        $result = $this->engine->renderString(
            '{{ items|map("name")|join(", ") }}',
            ['items' => [['name' => 'Alice'], ['name' => 'Bob']]]
        );
        $this->assertStringContainsString('Alice', $result);
        $this->assertStringContainsString('Bob', $result);
    }

    public function testColumnFilter(): void
    {
        $result = $this->engine->renderString(
            '{{ items|column("id")|join(", ") }}',
            ['items' => [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]]
        );
        $this->assertStringContainsString('1', $result);
        $this->assertStringContainsString('2', $result);
    }

    public function testBatchFilter(): void
    {
        $result = $this->engine->renderString(
            '{% for batch in items|batch(2) %}{{ batch|join(",") }};{% endfor %}',
            ['items' => [1, 2, 3, 4, 5]]
        );
        $this->assertStringContainsString('1,2', $result);
        $this->assertStringContainsString('3,4', $result);
    }

    /* ═══════════ Abs / Round Filters ═══════════ */

    public function testAbsFilter(): void
    {
        $this->assertSame('5', $this->engine->renderString('{{ val|abs }}', ['val' => -5]));
    }

    public function testRoundFilter(): void
    {
        $result = $this->engine->renderString('{{ val|round(1) }}', ['val' => 3.456]);
        $this->assertSame('3.5', $result);
    }

    public function testRoundNoDecimalsFilter(): void
    {
        $result = $this->engine->renderString('{{ val|round }}', ['val' => 3.7]);
        $this->assertSame('4', $result);
    }

    /* ═══════════ Keys / Values Filters ═══════════ */

    public function testKeysFilter(): void
    {
        $result = $this->engine->renderString('{{ v|keys|join(", ") }}', ['v' => ['a' => 1, 'b' => 2]]);
        $this->assertStringContainsString('a', $result);
        $this->assertStringContainsString('b', $result);
    }

    public function testValuesFilter(): void
    {
        $result = $this->engine->renderString('{{ v|values|join(", ") }}', ['v' => ['a' => 1, 'b' => 2]]);
        $this->assertStringContainsString('1', $result);
        $this->assertStringContainsString('2', $result);
    }

    /* ═══════════ dump filter + dump() function ═══════════ */

    public function testDumpFilterDebugMode(): void
    {
        $prev = getenv('TINA4_DEBUG');
        putenv('TINA4_DEBUG=true');
        try {
            $engine = new \Tina4\Frond(sys_get_temp_dir());
            $result = $engine->renderString('{{ val | dump | raw }}', ['val' => ['a' => 1]]);
            $this->assertStringContainsString('<pre>', $result);
            $this->assertStringContainsString('array(1)', $result);
        } finally {
            putenv($prev === false ? 'TINA4_DEBUG' : "TINA4_DEBUG={$prev}");
        }
    }

    public function testDumpFilterProductionMode(): void
    {
        $prev = getenv('TINA4_DEBUG');
        putenv('TINA4_DEBUG=false');
        try {
            $engine = new \Tina4\Frond(sys_get_temp_dir());
            $result = $engine->renderString('{{ val | dump | raw }}', ['val' => ['secret' => 'hunter2']]);
            $this->assertSame('', $result);
        } finally {
            putenv($prev === false ? 'TINA4_DEBUG' : "TINA4_DEBUG={$prev}");
        }
    }

    public function testDumpFunctionFormMatchesFilter(): void
    {
        $prev = getenv('TINA4_DEBUG');
        putenv('TINA4_DEBUG=true');
        try {
            $engine = new \Tina4\Frond(sys_get_temp_dir());
            $data = ['val' => ['a' => 1, 'b' => 'hi']];
            $filterOut = $engine->renderString('{{ val | dump | raw }}', $data);
            $fnOut = $engine->renderString('{{ dump(val) | raw }}', $data);
            $this->assertSame($filterOut, $fnOut);
            $this->assertStringContainsString('<pre>', $fnOut);
        } finally {
            putenv($prev === false ? 'TINA4_DEBUG' : "TINA4_DEBUG={$prev}");
        }
    }

    public function testDumpFunctionSilentInProduction(): void
    {
        $prev = getenv('TINA4_DEBUG');
        putenv('TINA4_DEBUG=false');
        try {
            $engine = new \Tina4\Frond(sys_get_temp_dir());
            $result = $engine->renderString('{{ dump(val) | raw }}', ['val' => ['secret' => 'x']]);
            $this->assertSame('', $result);
        } finally {
            putenv($prev === false ? 'TINA4_DEBUG' : "TINA4_DEBUG={$prev}");
        }
    }
}
