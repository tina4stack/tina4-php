<?php

namespace Tina4;

/**
 * FrondCompiler - Ahead-of-time compiler for the Frond template engine (ADR-0001 / ADR-0003).
 *
 * Compiles a parsed Frond AST ONCE into a native PHP render closure so that a
 * cached render just CALLS the closure and pays no per-render tree-walk
 * (`Frond::execute()` / `Frond::executeNode()` recursion + per-node dispatch +
 * string concatenation). This is the PHP port of the Python master's
 * `tina4_python/frond/compiler.py`.
 *
 * Behaviour-safe by construction:
 *
 * - The existing `Frond::tokenize()` + `Frond::parse()` still produce the AST;
 *   this class only adds a codegen pass over it. Because `tokenize()` applies
 *   `applyWhitespaceControl()` BEFORE `parse()`, every TEXT node the compiler
 *   sees already carries the interpreter's structural whitespace trims — the
 *   compiler emits those values verbatim, so a whitespace-controlled template
 *   compiles and renders byte-identically. (PHP has no render-dependent
 *   whitespace case; see the note in `compile()`.)
 *
 * - The emitted closure reuses Frond's OWN value primitives for every
 *   value-producing operation: `executeOutput()` for `{{ ... }}` (escaping,
 *   SafeString, raw-marker, `valueToString` all identical by construction),
 *   `evaluateExpression()` + `isTruthy()` for `if`/`elseif`/`else` conditions
 *   and the `for` iterable, and `executeSet()` for `set`. Only the STRUCTURE
 *   (which nodes belong to which branch/body, the literal TEXT runs, the loop
 *   bookkeeping and its save/restore of the overlaid keys) is baked into native
 *   PHP control flow. Because every value is produced by the identical primitive
 *   the interpreter calls, a compiled template renders byte-identically to the
 *   interpreted one.
 *
 * - The `for` codegen mirrors `Frond::executeFor()` EXACTLY: the same
 *   `!is_iterable || (is_array && empty)` short-circuit, `iterator_to_array`
 *   for a non-array Traversable, the second empty check, the nine `loop.*` keys,
 *   and the save/overlay/restore of `valueVar`/`keyVar`/`loop` on `$data`. The
 *   `if` codegen mirrors `Frond::executeIf()`: each branch (including the `else`
 *   branch, which the parser records as `condition => 'true'`) becomes an
 *   `if`/`elseif` guarded by `isTruthy(evaluateExpression(condition))`, so the
 *   first truthy branch wins exactly as the interpreter's foreach does.
 *
 * - Only the common hot-path constructs are compiled (text, output, if, for,
 *   set). ANY other node (extends/block/macro/include/from_import/cache/live/
 *   spaceless/autoescape or an unknown type) makes `compile()` return null so
 *   the caller falls back to the interpreter for that whole template. A
 *   codegen/`eval` error also returns null. A render is therefore never broken
 *   by the compiler.
 */
final class FrondCompiler
{
    /**
     * Compile a parsed Frond AST into a `function ($engine, array $data): string`
     * render closure, or null when the template uses a construct that is not
     * compiled yet / when codegen or `eval` fails - in which case the caller
     * must render with the interpreter.
     *
     * A NOTE ON WHITESPACE: unlike the Python master, PHP has no render-dependent
     * whitespace case to fall back on. Python trims the live output buffer at
     * render time for a strip-before marker whose left neighbour is not a literal
     * TEXT token; PHP's `Frond::applyWhitespaceControl()` is a pure structural
     * pass over the flat token list inside `tokenize()` that only ever trims a
     * neighbouring TEXT token, with no runtime buffer trim. The AST therefore
     * arrives already-trimmed and whitespace-controlled templates compile.
     *
     * @param array<int, array<string, mixed>> $ast Parsed Frond AST (from `Frond::parse()`).
     * @return \Closure|null The compiled render closure, or null to use the interpreter.
     */
    public static function compile(array $ast): ?\Closure
    {
        try {
            if (!self::isCompilable($ast)) {
                return null;
            }
            $counter = 0;
            $source = "function (\$engine, array \$data) {\n"
                . "\$out = '';\n"
                . self::emitNodes($ast, $counter)
                . "return \$out;\n"
                . "}";
            $fn = eval('return ' . $source . ';');
            if (!($fn instanceof \Closure)) {
                return null;
            }
            // Bind the closure's SCOPE (not $this) to Frond so it may call
            // Frond's private value primitives on the $engine instance passed at
            // call time. PHP visibility is class-level, so scope Frond grants
            // access to any Frond instance's private members; $this stays unused.
            return \Closure::bind($fn, null, Frond::class);
        } catch (\Throwable $e) {
            // A codegen / eval / analysis error must never break a render.
            return null;
        }
    }

    /**
     * True when every node in the AST (recursively, through if-branches and
     * for-bodies) is a construct the codegen supports (text, output, if, for,
     * set). Any other node type makes the whole template non-compilable so the
     * caller uses the interpreter.
     *
     * @param array<int, array<string, mixed>> $nodes AST node list to check.
     * @return bool True when the whole list is compilable.
     */
    private static function isCompilable(array $nodes): bool
    {
        foreach ($nodes as $node) {
            $type = $node['type'] ?? '';
            if ($type === 'text' || $type === 'output' || $type === 'set') {
                continue;
            }
            if ($type === 'if') {
                foreach ($node['branches'] as $branch) {
                    if (!self::isCompilable($branch['body'])) {
                        return false;
                    }
                }
                continue;
            }
            if ($type === 'for') {
                if (!self::isCompilable($node['body'])) {
                    return false;
                }
                if (!empty($node['elseBody']) && !self::isCompilable($node['elseBody'])) {
                    return false;
                }
                continue;
            }
            // extends / block / macro / include / from_import / cache / live /
            // spaceless / autoescape / unknown -> not compiled.
            return false;
        }
        return true;
    }

    /**
     * Emit PHP source for a flat list of AST nodes (mirrors `Frond::execute()`).
     *
     * @param array<int, array<string, mixed>> $nodes AST node list to emit.
     * @param int $counter Monotonic suffix source for unique per-loop variable names (by ref).
     * @return string Generated PHP statements that append to `$out`.
     */
    private static function emitNodes(array $nodes, int &$counter): string
    {
        $code = '';
        foreach ($nodes as $node) {
            $code .= self::emitNode($node, $counter);
        }
        return $code;
    }

    /**
     * Emit PHP source for a single AST node (mirrors `Frond::executeNode()` for
     * the compiled subset).
     *
     * @param array<string, mixed> $node AST node to emit.
     * @param int $counter Unique-name suffix source (by ref).
     * @return string Generated PHP statements.
     */
    private static function emitNode(array $node, int &$counter): string
    {
        switch ($node['type']) {
            case 'text':
                // Empty text is a no-op append; skip it (parity with the Python compiler).
                if ($node['value'] === '') {
                    return '';
                }
                return "\$out .= " . var_export($node['value'], true) . ";\n";

            case 'output':
                // Reuse the exact primitive so escaping / SafeString / raw-marker /
                // valueToString are byte-identical to the interpreter.
                return "\$out .= \$engine->executeOutput(" . var_export($node['expr'], true) . ", \$data);\n";

            case 'set':
                // Reuse executeSet with the reconstructed node: evaluates the expr,
                // strips the raw marker, assigns onto $data (returns '').
                return "\$engine->executeSet(" . var_export($node, true) . ", \$data);\n";

            case 'if':
                return self::emitIf($node, $counter);

            case 'for':
                return self::emitFor($node, $counter);

            default:
                // isCompilable() already rejected these; unreachable in practice.
                // Signal fallback if somehow reached.
                throw new \RuntimeException('Frond compiler: unsupported node ' . ($node['type'] ?? '?'));
        }
    }

    /**
     * Emit an if/elseif chain. Each branch (including the parser's `else` branch,
     * recorded with `condition => 'true'`) becomes a guard evaluated by the
     * engine's own `isTruthy(evaluateExpression(...))`, so the first truthy
     * branch wins exactly as `Frond::executeIf()` does.
     *
     * @param array<string, mixed> $node The `if` AST node.
     * @param int $counter Unique-name suffix source (by ref).
     * @return string Generated PHP `if`/`elseif` blocks.
     */
    private static function emitIf(array $node, int &$counter): string
    {
        $code = '';
        $first = true;
        foreach ($node['branches'] as $branch) {
            $keyword = $first ? 'if' : 'elseif';
            $cond = var_export($branch['condition'], true);
            $code .= $keyword . " (\$engine->isTruthy(\$engine->evaluateExpression($cond, \$data))) {\n";
            $code .= self::emitNodes($branch['body'], $counter);
            $code .= "}\n";
            $first = false;
        }
        return $code;
    }

    /**
     * Emit a native foreach that mirrors `Frond::executeFor()` line-for-line: the
     * iterable short-circuit + for-else, the `iterator_to_array` normalisation,
     * the nine `loop.*` keys, and the save/overlay/restore of the overlaid keys
     * on `$data`. The body is inlined compiled code in place of the interpreter's
     * `execute($node['body'], $data)`.
     *
     * @param array<string, mixed> $node The `for` AST node.
     * @param int $counter Unique-name suffix source (by ref).
     * @return string Generated PHP foreach construct.
     */
    private static function emitFor(array $node, int &$counter): string
    {
        $suffix = $counter++;
        $iter = var_export($node['iterExpr'], true);
        $valueVar = var_export($node['valueVar'], true);
        $keyVar = $node['keyVar'];
        $hasKey = $keyVar !== null;
        $keyVarExp = $hasKey ? var_export($keyVar, true) : '';

        // Names unique per loop-nesting level.
        $iterable = "\$iterable{$suffix}";
        $items = "\$items{$suffix}";
        $length = "\$length{$suffix}";
        $idx = "\$idx{$suffix}";
        $key = "\$key{$suffix}";
        $value = "\$value{$suffix}";
        $savedValue = "\$savedValue{$suffix}";
        $hadValue = "\$hadValue{$suffix}";
        $savedKey = "\$savedKey{$suffix}";
        $hadKey = "\$hadKey{$suffix}";
        $savedLoop = "\$savedLoop{$suffix}";
        $hadLoop = "\$hadLoop{$suffix}";

        $else = self::emitElse($node, $counter);

        $code = "";
        $code .= "$iterable = \$engine->evaluateExpression($iter, \$data);\n";
        $code .= "if (!is_iterable($iterable) || (is_array($iterable) && empty($iterable))) {\n";
        $code .= $else;
        $code .= "} else {\n";
        $code .= "$items = is_array($iterable) ? $iterable : iterator_to_array($iterable);\n";
        $code .= "if (empty($items)) {\n";
        $code .= $else;
        $code .= "} else {\n";
        $code .= "$length = count($items);\n";
        $code .= "$idx = 0;\n";
        // Save the keys the loop overlays, so they are restored afterwards.
        $code .= "$savedValue = array_key_exists($valueVar, \$data) ? \$data[$valueVar] : null;\n";
        $code .= "$hadValue = array_key_exists($valueVar, \$data);\n";
        if ($hasKey) {
            $code .= "$savedKey = array_key_exists($keyVarExp, \$data) ? \$data[$keyVarExp] : null;\n";
            $code .= "$hadKey = array_key_exists($keyVarExp, \$data);\n";
        }
        $code .= "$savedLoop = array_key_exists('loop', \$data) ? \$data['loop'] : null;\n";
        $code .= "$hadLoop = array_key_exists('loop', \$data);\n";
        $code .= "foreach ($items as $key => $value) {\n";
        $code .= "\$data[$valueVar] = $value;\n";
        if ($hasKey) {
            $code .= "\$data[$keyVarExp] = $key;\n";
        }
        $code .= "\$data['loop'] = ["
            . "'index' => $idx + 1, "
            . "'index0' => $idx, "
            . "'first' => $idx === 0, "
            . "'last' => $idx === $length - 1, "
            . "'length' => $length, "
            . "'revindex' => $length - $idx, "
            . "'revindex0' => $length - $idx - 1, "
            . "'even' => ($idx + 1) % 2 === 0, "
            . "'odd' => ($idx + 1) % 2 === 1"
            . "];\n";
        $code .= self::emitNodes($node['body'], $counter);
        $code .= "$idx++;\n";
        $code .= "}\n";
        // Restore original context - loop-overlaid keys do not leak out.
        $code .= "if ($hadValue) { \$data[$valueVar] = $savedValue; } else { unset(\$data[$valueVar]); }\n";
        if ($hasKey) {
            $code .= "if ($hadKey) { \$data[$keyVarExp] = $savedKey; } else { unset(\$data[$keyVarExp]); }\n";
        }
        $code .= "if ($hadLoop) { \$data['loop'] = $savedLoop; } else { unset(\$data['loop']); }\n";
        $code .= "}\n"; // inner else
        $code .= "}\n"; // outer else
        return $code;
    }

    /**
     * Emit the `for` else-body (or nothing when absent), mirroring the two sites
     * in `Frond::executeFor()` where a non-iterable / empty iterable renders the
     * else body or an empty string.
     *
     * @param array<string, mixed> $node The `for` AST node.
     * @param int $counter Unique-name suffix source (by ref).
     * @return string Generated PHP for the else body, or an empty string.
     */
    private static function emitElse(array $node, int &$counter): string
    {
        if (empty($node['elseBody'])) {
            return '';
        }
        return self::emitNodes($node['elseBody'], $counter);
    }
}
