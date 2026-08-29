<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * The mssql dialect wires booleanToInt into SQLTranslator::translate, so a bare
 * TRUE/FALSE reaches BIT-backed SQL Server as 1/0. A TRUE/FALSE inside a string
 * literal is data and must survive untouched.
 *
 * No mocks: translate() is a pure function over its input string. Regression
 * guard for the wiring gap where the mssql case applied autoIncrementSyntax +
 * ddlTypes but never booleanToInt (firebird already did).
 *
 * Mirrors the Python master's tests/test_mssql_boolean_to_int_wiring.py.
 */

use PHPUnit\Framework\TestCase;
use Tina4\SQLTranslator;

class MssqlBooleanToIntTest extends TestCase
{
    private function t(string $sql): string
    {
        return SQLTranslator::translate($sql, 'mssql');
    }

    public function testBareTrueAndFalseBecome1And0(): void
    {
        $ins = $this->t('INSERT INTO flags (active) VALUES (TRUE)');
        $this->assertStringContainsString('(1)', $ins);
        $this->assertStringNotContainsStringIgnoringCase('TRUE', $ins);
        $this->assertMatchesRegularExpression('/=\s*0/', $this->t('UPDATE flags SET active = FALSE'));
    }

    public function testTrueInsideStringLiteralIsPreserved(): void
    {
        $out = $this->t("SELECT id FROM flags WHERE label = 'TRUE'");
        $this->assertStringContainsString("'TRUE'", $out);
    }

    public function testFirebirdParityGuard(): void
    {
        $ins = SQLTranslator::translate('INSERT INTO flags (active) VALUES (TRUE)', 'firebird');
        $this->assertStringContainsString('(1)', $ins);
        $this->assertStringNotContainsStringIgnoringCase('TRUE', $ins);
        $this->assertStringContainsString(
            "'TRUE'",
            SQLTranslator::translate("SELECT id FROM flags WHERE label = 'TRUE'", 'firebird')
        );
    }
}
