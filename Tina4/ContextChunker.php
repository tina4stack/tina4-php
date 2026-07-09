<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Text folding + chunking for the Context subsystem.
 *
 * A faithful PHP port of the proven slice of tina4-python's
 * tina4_python/context/chunker.py (itself a port of neemee's
 * longmem-harness tokenizer/chunkers). Pure stdlib — PCRE + a little
 * mbstring/intl. Nothing here touches SQLite; it produces the
 * (folded body, raw text) pairs the FTS5 index stores.
 */

namespace Tina4;

class ContextChunker
{
    // Chunk boundaries across languages: python defs/classes/decorators, php/js
    // functions and methods, ts exports/interfaces, php traits, and Object Pascal
    // unit/type/routine headers (Delphi is case-insensitive, accept either case).
    // Matched per-line, anchored at the start of the line.
    private const TOPLEVEL =
        '/^(async def |def |class |@\w'
        . '|\s*(?:public |private |protected |static |final |abstract )*function \w'
        . '|(?:export )?(?:default )?(?:abstract )?class '
        . '|interface |trait '
        . '|export (?:const|function|interface|type|async) '
        . '|[Uu]nit |[Pp]rocedure |[Ff]unction |[Cc]onstructor |[Dd]estructor '
        . '|[Tt]ype$)/';

    /**
     * Lowercase, strip diacritics, join comma-grouped numbers, split camelCase.
     *
     * Applied symmetrically to the indexed `body` and to query tokens so
     * matching is consistent: a query for `field` reaches `IntegerField`.
     */
    public static function fold(string $text): string
    {
        // join comma-grouped numbers ("24,601" -> "24601")
        $text = (string) preg_replace('/(?<=\d),(?=\d)/', '', $text);
        // split camelCase ("ForeignKeyField" -> "foreign key field") BEFORE lower
        $text = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $text);
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        // NFKD decomposition then drop non-ascii — mirrors Python's
        // unicodedata.normalize("NFKD", t).encode("ascii", "ignore"): é -> e.
        // tina4: falls back to a plain non-ascii strip when ext-intl is absent
        // (accented chars lose their base letter — rare, and symmetric on both
        // sides so matching stays internally consistent).
        if (class_exists('\Normalizer')) {
            $normalized = \Normalizer::normalize($text, \Normalizer::FORM_KD);
            if ($normalized !== false) {
                $text = $normalized;
            }
        }
        return (string) preg_replace('/[^\x00-\x7F]+/', '', $text);
    }

    /**
     * Fold simple plurals: strip one trailing 's', never from ss/us/is
     * endings (class, status, axis). QUERY-side only — so `fields` in a
     * question also reaches `Field` definitions.
     */
    public static function lightStem(string $token): string
    {
        if (strlen($token) > 3
            && str_ends_with($token, 's')
            && !str_ends_with($token, 'ss')
            && !str_ends_with($token, 'us')
            && !str_ends_with($token, 'is')) {
            return substr($token, 0, -1);
        }
        return $token;
    }

    /**
     * Accent-folded lowercase alphanumeric tokens (digits kept).
     *
     * @return string[]
     */
    public static function terms(string $text): array
    {
        preg_match_all('/[a-z0-9]+/', self::fold($text), $matches);
        return $matches[0];
    }

    /**
     * Chunk source on top-level def/class/decorator/function boundaries
     * (sentence chunking shreds code). Segments are packed up to `$maxLines`,
     * and every chunk starts with a `# file: <path>` line so the path's tokens
     * are indexed — 'where is the router?' should match core/Router.php by name.
     *
     * @return array<int, array{0:int, 1:string}> list of [index, chunkText] pairs
     */
    public static function chunkCode(string $text, string $path = '', int $maxLines = 60): array
    {
        $lines = self::splitLines($text);
        $bounds = [];
        foreach ($lines as $i => $line) {
            if (preg_match(self::TOPLEVEL, $line) === 1) {
                $bounds[] = $i;
            }
        }
        if (empty($bounds) || $bounds[0] !== 0) {
            array_unshift($bounds, 0);
        }
        // Build segments between consecutive boundaries.
        $segments = [];
        $ends = array_slice($bounds, 1);
        $ends[] = count($lines);
        foreach ($bounds as $k => $start) {
            $segments[] = array_slice($lines, $start, $ends[$k] - $start);
        }

        $header = $path !== '' ? "# file: {$path}" : null;
        $out = [];
        foreach (self::pack($segments, $maxLines) as $i => $chunkLines) {
            $body = implode("\n", $header !== null ? array_merge([$header], $chunkLines) : $chunkLines);
            $out[] = [$i, $body];
        }
        return $out;
    }

    /**
     * Chunk prose/docs into sentence-packed windows of at most `$maxWords`
     * words.
     *
     * @return array<int, array{0:int, 1:string}> list of [index, chunkText] pairs
     */
    public static function chunkText(string $text, int $maxWords = 350): array
    {
        // Sentence boundary = end punctuation FOLLOWED BY whitespace/EOL, or a
        // newline. NOT a bare '.', which would shred embedded code in prose
        // ("db.fetch()" -> "db. fetch()").
        $parts = preg_split('/(?<=[.!?])\s+|\n+/', $text);
        if ($parts === false) {
            $parts = [$text];
        }
        $sentences = [];
        foreach ($parts as $part) {
            $trimmed = trim($part);
            if ($trimmed !== '') {
                $sentences[] = $trimmed;
            }
        }

        $chunks = [];
        $cur = [];
        $curWords = 0;
        foreach ($sentences as $sentence) {
            $wordCount = count(preg_split('/\s+/', $sentence, -1, PREG_SPLIT_NO_EMPTY) ?: []);
            if (!empty($cur) && $curWords + $wordCount > $maxWords) {
                $chunks[] = implode(' ', $cur);
                $cur = [];
                $curWords = 0;
            }
            $cur[] = $sentence;
            $curWords += $wordCount;
        }
        if (!empty($cur)) {
            $chunks[] = implode(' ', $cur);
        }

        $out = [];
        foreach ($chunks as $i => $chunk) {
            $out[] = [$i, $chunk];
        }
        return $out;
    }

    /**
     * Pack line-segments into chunks of at most `$maxLines` lines,
     * hard-splitting any single oversized segment.
     *
     * @param array<int, string[]> $segments
     * @return array<int, string[]>
     */
    private static function pack(array $segments, int $maxLines): array
    {
        $chunks = [];
        $cur = [];
        foreach ($segments as $seg) {
            while (count($seg) > $maxLines) {          // oversized segment: hard split
                if (!empty($cur)) {
                    $chunks[] = $cur;
                    $cur = [];
                }
                $chunks[] = array_slice($seg, 0, $maxLines);
                $seg = array_slice($seg, $maxLines);
            }
            if (!empty($cur) && count($cur) + count($seg) > $maxLines) {
                $chunks[] = $cur;
                $cur = [];
            }
            $cur = array_merge($cur, $seg);
        }
        if (!empty($cur)) {
            $chunks[] = $cur;
        }
        return $chunks;
    }

    /**
     * Split text into lines the way Python's str.splitlines() does: break on
     * \r\n / \r / \n and drop exactly one trailing empty produced by a final
     * newline (so "a\n" -> ["a"], not ["a", ""]).
     *
     * @return string[]
     */
    private static function splitLines(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $lines = preg_split('/\r\n|\r|\n/', $text);
        if ($lines === false) {
            return [$text];
        }
        if (count($lines) > 0 && end($lines) === '' && preg_match('/(\r\n|\r|\n)$/', $text) === 1) {
            array_pop($lines);
        }
        return $lines;
    }
}
