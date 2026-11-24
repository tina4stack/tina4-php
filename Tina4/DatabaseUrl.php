<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 */

namespace Tina4;

/**
 * Parses DATABASE_URL connection strings into structured connection parameters.
 *
 * Supported formats:
 *   sqlite:///path/to/database.db
 *   sqlite::memory:
 *   postgres://user:pass@host:port/dbname
 *   postgresql://user:pass@host:port/dbname
 *   mysql://user:pass@host:port/dbname
 *   mssql://user:pass@host:port/dbname
 *   firebird://user:pass@host:port/path/to/database.fdb
 *
 * DISPLAY REDACTS, FIDELITY DOES NOT. print_r(), var_dump(), json_encode(),
 * __toString() and toSafeString() all replace the password with REDACTED, so a
 * log line, a dump or a status payload is safe. var_export() and serialize()
 * DELIBERATELY do not: their contract is a faithful round trip, and a masked
 * serialize() would unserialize into an object whose password is the literal
 * "***".
 *
 * The consequence: DO NOT PERSIST THIS OBJECT. A DatabaseUrl written into a
 * session file, a cache entry or a queue payload puts the password on disk in
 * cleartext. Record toSafeString() instead, or store the redacted parts.
 * DatabaseCredentialLeakTest enforces this - it fails the build if framework
 * code ever serialize()s or var_export()s one.
 */
class DatabaseUrl implements \JsonSerializable
{
    /**
     * What a secret is replaced by, everywhere. One spelling so a test can pin
     * it and a reader can recognise a redacted value at a glance.
     */
    public const REDACTED = '***';

    /**
     * @var array<string, string> URL scheme to CANONICAL engine name.
     *
     * Alias resolution happens ONCE, here, at parse time. Everything downstream
     * sees the canonical name, so `postgresql://`, `pgsql://` and `postgres://`
     * are the same connection as far as the rest of the framework is concerned.
     */
    private const ENGINE_ALIASES = [
        'sqlite' => 'sqlite',
        'sqlite3' => 'sqlite',
        'postgres' => 'postgres',
        'postgresql' => 'postgres',
        'pgsql' => 'postgres',
        'mysql' => 'mysql',
        'mssql' => 'mssql',
        'sqlserver' => 'mssql',
        'firebird' => 'firebird',
    ];

    /** @var array<string, string> Canonical engine to Tina4 adapter class. */
    private const ENGINE_DRIVER_CLASS = [
        'sqlite' => 'DataSQLite3',
        'postgres' => 'DataPostgresql',
        'mysql' => 'DataMySQL',
        'mssql' => 'DataMSSQL',
        'firebird' => 'DataFirebird',
    ];

    /**
     * The canonical engine name: sqlite, postgres, mysql, mssql or firebird.
     *
     * Breaking (feature 5): replaces BOTH `$driver` and `$scheme`. `$driver` held
     * an internal class name (`DataPostgresql`, `DataSQLite3`) on a public
     * readonly property, leaking implementation into the value; `$scheme` held
     * the RAW scheme, so `pgsql://` and `postgres://` compared unequal for the
     * same connection. The adapter class is now looked up FROM the engine by
     * getDriverClass(); a public property never holds a class name.
     */
    public readonly string $engine;

    /** Null for sqlite - a file has no host. */
    public readonly ?string $host;

    /** Null for sqlite. Otherwise always set: the engine default applies at parse. */
    public readonly ?int $port;

    public readonly string $database;

    /** Null when absent, never an empty string - absent and blank are different. */
    public readonly ?string $username;
    public readonly ?string $password;

    /**
     * Parse a DATABASE_URL string.
     *
     * @param string $url The database URL to parse
     * @throws \InvalidArgumentException If the URL format is invalid
     */
    public function __construct(#[\SensitiveParameter] string $url)
    {
        // Handle sqlite special cases — :memory: check must come first
        // `sqlite3:` is accepted input and normalises to `sqlite:`. The driver is
        // literally named sqlite3 in every framework (Python's sqlite3 module,
        // Ruby's sqlite3 gem, PHP's ext-sqlite3, Node's node:sqlite), so people
        // type it. The "3" is the file-format version, not a different engine, so
        // the canonical ENGINE name stays `sqlite` and only the input is widened.
        if (str_starts_with($url, 'sqlite3:')) {
            $url = 'sqlite:' . substr($url, 8);
        }

        if ($this->initSqlite($url)) {
            return;
        }

        $parts = self::constructorParts($url);

        $scheme = strtolower($parts['scheme']);

        if (!isset(self::ENGINE_ALIASES[$scheme])) {
            throw new \InvalidArgumentException(
                "DatabaseUrl: Unsupported database scheme '{$scheme}'. Supported: " .
                implode(', ', array_keys(self::ENGINE_ALIASES))
            );
        }

        $this->engine = self::ENGINE_ALIASES[$scheme];
        $this->host = $parts['host'] ?? 'localhost';
        $this->port = $parts['port'] ?? $this->defaultPort($this->engine);
        $this->username = isset($parts['user']) ? urldecode($parts['user']) : null;
        $this->password = isset($parts['pass']) ? urldecode($parts['pass']) : null;

        // Strip EXACTLY ONE leading slash - the URL path separator - never all of
        // them. `ltrim($path, '/')` ate every slash, which turned the documented
        // absolute Firebird form `firebird://host:3050//var/lib/db.fdb` into the
        // RELATIVE `var/lib/db.fdb`. Verified against live Firebird 5.0.4: the
        // driver accepts one or two leading slashes and rejects a relative path
        // outright, so the value we published would not open the database it
        // claimed to name. PHP still connected because the adapter rebuilt the
        // path downstream, which is exactly why nobody reported it.
        $path = $parts['path'] ?? '';
        $this->database = str_starts_with($path, '/') ? substr($path, 1) : $path;
    }

    /**
     * Fill the fields for an in-memory or file SQLite URL. Returns false for
     * any other engine, which the constructor then parses as a network URL.
     */
    private function initSqlite(#[\SensitiveParameter] string $url): bool
    {
        if ($url === 'sqlite::memory:' || $url === 'sqlite:///:memory:') {
            $this->engine = 'sqlite';
            $this->host = null;
            $this->port = null;
            $this->database = ':memory:';
            $this->username = null;
            $this->password = null;
            return true;
        }

        if (!str_starts_with($url, 'sqlite:')) {
            return false;
        }

        // Strip the sqlite scheme on the RAW string (mirrors tina4-python/ruby/nodejs).
        // parse_url() collapses "sqlite:/x" and "sqlite:///x", losing the distinction
        // between a one-slash ABSOLUTE path and the documented three-slash RELATIVE form —
        // that was the "sqlite:<abspath> silently goes relative" footgun.
        //   sqlite:///app.db        → "app.db"       (three slashes = relative to cwd)
        //   sqlite:///data/app.db   → "data/app.db"
        //   sqlite:////abs/app.db   → "/abs/app.db"  (four slashes = absolute)
        //   sqlite:///C:/Users/app  → "C:/Users/app" (Windows absolute)
        //   sqlite:/abs/app.db      → "/abs/app.db"  (one slash = a real absolute path)
        //   sqlite://rel/app.db     → "rel/app.db"   (two-slash legacy = relative)
        //   sqlite:app.db           → "app.db"
        if (str_starts_with($url, 'sqlite:///')) {
            $rest = substr($url, 10);
        } elseif (str_starts_with($url, 'sqlite://')) {
            $rest = substr($url, 9);
        } else {
            $rest = substr($url, 7); // "sqlite:"
        }

        $this->engine = 'sqlite';
        $this->host = null;
        $this->port = null;
        // Absolute vs relative is decided by the adapter at connect time
        // (a leading "/" or a Windows drive letter → absolute).
        $this->database = $rest;
        $this->username = null;
        $this->password = null;
        return true;
    }

    /**
     * parseParts() for the constructor, with the refusal re-worded for it.
     *
     * @return array{scheme: string, user?: string, pass?: string, host?: string, port?: int, path?: string, query?: string, fragment?: string}
     */
    private static function constructorParts(#[\SensitiveParameter] string $url): array
    {
        // Parse standard URL format - by the framework's one URL rule
        // (parseParts), never parse_url(): see GHSA-h2mr-m3v3-cq8c there.
        try {
            $parts = self::parseParts($url);
        } catch (\InvalidArgumentException $e) {
            // NEVER interpolate the raw URL, and never chain: the reason is
            // either generic or carries only the REDACTED value. Measured
            // 2026-08-02 in all four frameworks: a malformed TINA4_DATABASE_URL
            // put the PASSWORD into this message verbatim, and this message
            // reaches the boot log, the error overlay, a crash report and a CI
            // log.
            throw new \InvalidArgumentException(
                'DatabaseUrl: Invalid URL format - ' . $e->getMessage()
                . " - expected 'engine://user:password@host:port/database'."
            );
        }
        return $parts;
    }

    /**
     * Parse a TINA4_DATABASE_URL from environment variable.
     *
     * @param string $envKey The environment variable name (default: TINA4_DATABASE_URL)
     * @return self|null Null if the env var is not set
     * @throws \InvalidArgumentException If the URL format is invalid
     */
    public static function fromEnv(string $envKey = 'TINA4_DATABASE_URL'): ?self
    {
        $url = DotEnv::getEnv($envKey);

        if ($url === null || $url === '') {
            return null;
        }

        return new self($url);
    }

    /**
     * Get the fully qualified Tina4 driver class name.
     */
    public function getDriverClass(): string
    {
        return 'Tina4\\' . self::ENGINE_DRIVER_CLASS[$this->engine];
    }

    /**
     * Get a DSN-style connection string for the database.
     */
    public function getDsn(): string
    {
        if ($this->engine === 'sqlite') {
            return $this->database;
        }

        $dsn = (string) $this->host;

        if ($this->port !== null) {
            $dsn .= ':' . $this->port;
        }

        if ($this->database !== '') {
            $dsn .= '/' . $this->database;
        }

        return $dsn;
    }

    /**
     * Convert back to a URL string (password masked).
     */
    public function toSafeString(): string
    {
        if ($this->engine === 'sqlite') {
            return "sqlite:///{$this->database}";
        }

        $url = $this->engine . '://';

        if ($this->username !== null) {
            $url .= $this->username;
            if ($this->password !== null) {
                $url .= ':***';
            }
            $url .= '@';
        }

        $url .= (string) $this->host;

        if ($this->port !== null) {
            $url .= ':' . $this->port;
        }

        if ($this->database !== '') {
            $url .= '/' . $this->database;
        }

        return $url;
    }

    /**
     * Redact the credentials out of ANY connection string, in any shape.
     *
     * This is the single string-level redaction primitive for the framework.
     * `toSafeString()` is its struct-level twin (used when the URL parsed);
     * this one is what an ERROR path calls, because an error path by definition
     * holds a string that may not parse. Every message, log line and status
     * payload that has to name a connection goes through one of the two - the
     * measured failure was that the helper existed and NOTHING called it.
     *
     * Three shapes, because Tina4 accepts three:
     *   URL userinfo   postgres://user:secret@host  -> postgres://user:***@host
     *   libpq kv       password='se cret' / password=secret -> password=***
     *   ODBC           PWD=secret; / PWD={se;cret}  -> PWD=***;
     *
     * It FAILS CLOSED. A string with no recognisable structure is replaced
     * wholesale, because a malformed TINA4_DATABASE_URL such as
     * "notaurl-with-<password>" is ENTIRELY secret - that exact value put the
     * password into an exception message in all four frameworks.
     *
     * @param string $connectionString A URL, a libpq/ODBC DSN, or junk
     * @return string The same string with every credential replaced by ***
     */
    public static function redact(#[\SensitiveParameter] string $connectionString): string
    {
        $value = trim($connectionString);

        if ($value === '') {
            return '(empty)';
        }

        $authorityStart = self::authorityStart($value);
        // A libpq / ODBC keyword=value string opens with "keyword=", possibly
        // behind a driver prefix: "odbc:DRIVER={..};PWD={a@b};" or the PDO
        // form "pgsql:host=..;password=..". Those go to the keyword step,
        // which understands {braced} and 'quoted' values - an '@' inside one
        // must not be read as the end of URL userinfo.
        $looksLikeKeywordDsn = (bool) preg_match('/^(?:[A-Za-z][A-Za-z0-9+.\-]*:)?\s*[A-Za-z_][A-Za-z0-9_]*\s*=/', $value);
        if ($authorityStart !== null
            && preg_match('/^\s*[A-Za-z_][A-Za-z0-9_]*\s*=/', substr($value, $authorityStart))
        ) {
            // "odbc:///DRIVER={..};SERVER=tcp:h;PWD={a@b}" - a keyword string
            // behind a URL-looking prefix: keyword rules, not userinfo rules.
            $authorityStart = null;
            $looksLikeKeywordDsn = true;
        }

        if ($authorityStart !== null) {
            $value = self::maskCredentials($value, $authorityStart);
        } elseif (!$looksLikeKeywordDsn && str_contains($value, '@') && str_contains($value, ':')) {
            // No recognisable scheme, but "something:secret@host" - a URL with
            // its scheme mangled or missing ("postgres:u:pw@h", "u:pw@h").
            // Treat the whole value as the authority: everything from the
            // first ':' to the last '@' is masked. Over-redaction (the scheme
            // can be swallowed) is safe.
            $value = self::maskCredentials($value, 0);
        } elseif (!$looksLikeKeywordDsn) {
            // FAIL CLOSED: not a URL, not keyword=value, no user:secret@host.
            // "u:secret?x=1" used to pass straight through because it held an
            // '=' somewhere; it is junk that may be entirely secret.
            return '(redacted - unrecognised connection string)';
        }

        // Quoted forms are matched FIRST and as whole units. The old PHP-only
        // redaction was /\bpassword=\S*/ , and \S* stops at the first SPACE, so
        // a password containing a space had its TAIL survive into the logged
        // message ("password=*** word"). A quoted libpq value, a double-quoted
        // value and an ODBC {braced} value all end on their closing delimiter,
        // never on a space.
        // The UNQUOTED alternative (last) keeps eating space-separated tokens
        // until one looks like the next `keyword=` - so an unquoted password
        // with a space is swallowed whole instead of leaving its tail behind.
        return preg_replace(
            '/\b(password|passwd|pwd)\s*=\s*('
            . '\'(?:\\\\.|[^\'\\\\])*\''            // libpq single-quoted, backslash-escaped
            . '|"[^"]*"'                            // double-quoted
            . '|\{[^}]*\}'                          // ODBC braced
            . '|[^;\s]*(?:\s+(?![A-Za-z_][A-Za-z0-9_]*\s*=)[^;\s]+)*'  // bare
            . ')/i',
            '$1=' . self::REDACTED,
            $value
        ) ?? $value;
    }

    // -- GHSA-h2mr-m3v3-cq8c: ONE rule for where a URL's password is -------------
    //
    // redact(), parseParts() and every connection parser in the framework use
    // the rule below, so the string a log line masks and the fields a parser
    // returns can never disagree about which characters are the password.
    //
    //   - The authority starts after "scheme://", "scheme:/" or "scheme//".
    //   - Userinfo ends at the LAST '@' in the value; the password starts at
    //     the first ':' after the authority start. A '/' before that ':' means
    //     the text is a path, not a password.
    //   - With no '@', a non-numeric port is treated as the password and is
    //     masked to the end of the value (a trailing ?key=value query is kept).
    //     So is a NUMERIC port followed directly by '?' or '#' that is not a
    //     ?key=value query - a digit-led password, "u:12?secret" (masked by
    //     redact(), refused by parseParts()).
    //
    // KNOWN LIMIT (deliberately not solved): a host-less URL whose password is
    // all digits - postgres://u:123/x - is indistinguishable from host "u",
    // port 123, so it is neither masked by redact() nor refused by the parser.
    // Likewise a host-less URL whose password holds an unencoded '@' -
    // postgres://u:abc@def - IS user "u", password "abc", host "def" by the
    // grammar; the text after the '@' is shown as the host.

    /** Message used when a value is refused without being echoed. */
    private const NOT_ECHOED = 'the value is not shown because it may hold a password';

    /** parseParts() refusal codes (the exception's getCode()). */
    public const REFUSED_NO_SCHEME = 1;
    public const REFUSED_AMBIGUOUS_AT = 2;
    public const REFUSED_PORT = 3;
    public const REFUSED_HOST = 4;
    public const REFUSED_AMBIGUOUS_PORT = 5;

    /**
     * Where the authority starts: the offset just past "scheme://",
     * "scheme:/" or "scheme//", or null when the value opens with none.
     *
     * A longer run of slashes ("postgres:///u:pw@h") is skipped as well, so
     * its first segment is not mistaken for a path by the '/'-before-':'
     * test and left unmasked. That can only mask MORE (an '@' inside a
     * sqlite:/// file path, say), never less.
     */
    private static function authorityStart(#[\SensitiveParameter] string $value): ?int
    {
        if (preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*(?::/+|/{2,})#', $value, $match)) {
            return strlen($match[0]);
        }
        return null;
    }

    /**
     * Mask the password of a URL-shaped value whose authority starts at
     * $start, keeping the scheme, user, host and the rest.
     */
    private static function maskCredentials(#[\SensitiveParameter] string $value, int $start): string
    {
        $at = strrpos($value, '@', $start);
        if ($at !== false) {
            return self::maskUserinfoPassword($value, $start, $at);
        }
        return self::maskPortPassword($value, $start);
    }

    /**
     * With an '@': mask from the first ':' of the userinfo to the last '@'.
     */
    private static function maskUserinfoPassword(#[\SensitiveParameter] string $value, int $start, int $at): string
    {
        $userinfo = substr($value, $start, $at - $start);
        $colon = strpos($userinfo, ':');
        if ($colon === false) {
            return $value; // a user, no password
        }
        $slash = strpos($userinfo, '/');
        if ($slash !== false && $slash < $colon) {
            return $value; // "host/path...@..." - a path, not a password
        }
        return substr($value, 0, $start + $colon + 1) . self::REDACTED . substr($value, $at);
    }

    /**
     * No '@': a non-numeric "port" is a password ("scheme://u:secret"), and
     * so is a digit-led one ("scheme://u:12?secret").
     */
    private static function maskPortPassword(#[\SensitiveParameter] string $value, int $start): string
    {
        $authority = substr($value, $start, strcspn($value, '/?#', $start));
        $portOffset = self::portOffset($authority);
        if ($portOffset === null) {
            return $value;
        }
        $port = substr($authority, $portOffset + 1);
        $secretStart = $start + $portOffset + 1;
        if ($port === '') {
            return $value;
        }
        if (ctype_digit($port)) {
            return self::maskDigitLedPassword($value, $start + strlen($authority), $secretStart);
        }

        $tail = substr($value, $secretStart);
        $keep = '';
        $query = strpos($tail, '?');
        if ($query !== false && self::isKeyValueQuery(substr($tail, $query))) {
            $keep = substr($tail, $query);
        }
        return substr($value, 0, $secretStart) . self::REDACTED . $keep;
    }

    /**
     * Offset of the ':' before the port inside an authority ("host:port" or
     * "[ipv6]:port"), or null when there is none.
     */
    private static function portOffset(#[\SensitiveParameter] string $authority): ?int
    {
        if (!str_starts_with($authority, '[')) {
            $colon = strpos($authority, ':');
            return $colon === false ? null : $colon;
        }
        $close = strpos($authority, ']');
        if ($close !== false && ($authority[$close + 1] ?? '') === ':') {
            return $close + 1;
        }
        return null;
    }

    /**
     * A digit-led password: "scheme://u:12?secret" or "u:12#secret" reads as
     * port 12 plus a query/fragment. A real query after a bare port is
     * key=value; anything else directly after the digits is the rest of the
     * password, so it is masked to the end.
     *
     * @param int $afterAuthority Offset just past the authority
     * @param int $secretStart    Offset of the first character of the "port"
     */
    private static function maskDigitLedPassword(#[\SensitiveParameter] string $value, int $afterAuthority, int $secretStart): string
    {
        $after = substr($value, $afterAuthority);
        if ($after !== '' && ($after[0] === '?' || $after[0] === '#') && !self::isKeyValueQuery($after)) {
            return substr($value, 0, $secretStart) . self::REDACTED;
        }
        return $value; // a real port - or the KNOWN LIMIT, an all-digit password
    }

    /**
     * True when $text is a whole "?key=value[&key=value][#fragment]" query -
     * the only tail that is kept beside a masked password, and the only tail
     * a bare numeric port may be followed by when the URL has no '@'.
     */
    private static function isKeyValueQuery(#[\SensitiveParameter] string $text): bool
    {
        $pair = '[A-Za-z_][A-Za-z0-9_.\-]*=[^?#&\s@]*';
        return (bool) preg_match('/^\?' . $pair . '(?:&' . $pair . ')*(?:#[^?#\s@]*)?$/', $text);
    }

    /**
     * Split a connection URL into its parts by the ONE rule above.
     *
     * A drop-in for parse_url() on connection strings: the same keys (scheme,
     * user, pass, host, port, path, query, fragment), absent ones omitted,
     * user and pass still percent-ENCODED (callers decode, as they did with
     * parse_url()). parse_url() cannot be used here: it ends the authority at
     * the first '/', '?' or '#', so a password holding one of them became a
     * host, a port or a path - "postgres:/u:pw@h" even came back as the path
     * "/u:pw@h", which the adapters then printed as the database name.
     *
     * REFUSES, with a message that never contains the value:
     *   - a value that does not open with "scheme://";
     *   - an '@' the parser cannot take as the userinfo delimiter: one in the
     *     path or query (encode it as %40), or a password holding both an
     *     unencoded '@' and a later '/', '?' or '#';
     *   - with no '@', a numeric port followed directly by a '?' or '#' that
     *     is not a ?key=value query ("u:12?secret", a digit-led password).
     * Refuses a non-numeric port or an unclosed IPv6 bracket with the
     * REDACTED value, which by the rule above never shows the password.
     *
     * @param string $url The connection URL
     * @return array{scheme: string, user?: string, pass?: string, host?: string, port?: int, path?: string, query?: string, fragment?: string}
     * @throws \InvalidArgumentException When the value is not a usable URL
     */
    public static function parseParts(#[\SensitiveParameter] string $url): array
    {
        if (!preg_match('#^([A-Za-z][A-Za-z0-9+.\-]*)://#', $url, $match)) {
            throw new \InvalidArgumentException(
                "expected 'scheme://' at the start (" . self::NOT_ECHOED . ')',
                self::REFUSED_NO_SCHEME
            );
        }
        $parts = ['scheme' => $match[1]];
        $rest = substr($url, strlen($match[0]));

        $hasUserinfo = self::takeUserinfo($rest, $parts);
        $rest = self::takePathQueryAndFragment($rest, $parts);
        self::takeHostAndPort($rest, $url, $parts);
        if (!$hasUserinfo) {
            self::refuseDigitLedPassword($parts);
        }

        return $parts;
    }

    /**
     * Split the userinfo off at the LAST '@' into $parts['user'] / ['pass'],
     * leaving the rest in $rest. Refuses an '@' it cannot place.
     *
     * @param array<string, int|string> $parts
     * @return bool Whether the value had userinfo
     */
    private static function takeUserinfo(#[\SensitiveParameter] string &$rest, array &$parts): bool
    {
        $at = strrpos($rest, '@');
        if ($at === false) {
            return false;
        }
        $userinfo = substr($rest, 0, $at);
        $rest = substr($rest, $at + 1);
        $colon = strpos($userinfo, ':');
        $user = $colon === false ? $userinfo : substr($userinfo, 0, $colon);
        if (self::isAmbiguousAt($user, $userinfo)) {
            throw new \InvalidArgumentException(
                "an '@' outside the user:password part must be percent-encoded as %40 ("
                . self::NOT_ECHOED . ')',
                self::REFUSED_AMBIGUOUS_AT
            );
        }
        $parts['user'] = $user;
        if ($colon !== false) {
            $parts['pass'] = substr($userinfo, $colon + 1);
        }
        return true;
    }

    /**
     * True when the '@' the userinfo ends at cannot be the delimiter: the
     * "user" holds a '/', '?' or '#' (the '@' is in the path or query), or an
     * earlier unencoded '@' is followed by one.
     */
    private static function isAmbiguousAt(#[\SensitiveParameter] string $user, #[\SensitiveParameter] string $userinfo): bool
    {
        $innerAt = strpos($userinfo, '@');
        return strpbrk($user, '/?#') !== false
            || ($innerAt !== false && strpbrk(substr($userinfo, $innerAt), '/?#') !== false);
    }

    /**
     * Move the "#fragment", "?query" and "/path" of what follows the userinfo
     * into $parts, returning the authority that is left.
     *
     * @param array<string, int|string> $parts
     */
    private static function takePathQueryAndFragment(#[\SensitiveParameter] string $rest, array &$parts): string
    {
        $hash = strpos($rest, '#');
        if ($hash !== false) {
            $parts['fragment'] = substr($rest, $hash + 1);
            $rest = substr($rest, 0, $hash);
        }
        $question = strpos($rest, '?');
        if ($question !== false) {
            $parts['query'] = substr($rest, $question + 1);
            $rest = substr($rest, 0, $question);
        }
        $slash = strpos($rest, '/');
        if ($slash !== false) {
            $parts['path'] = substr($rest, $slash);
            $rest = substr($rest, 0, $slash);
        }
        return $rest;
    }

    /**
     * Read the authority - host[:port] or [ipv6][:port] - into $parts,
     * refusing a non-numeric port with only the redacted value.
     *
     * @param array<string, int|string> $parts
     */
    private static function takeHostAndPort(#[\SensitiveParameter] string $authority, #[\SensitiveParameter] string $url, array &$parts): void
    {
        [$host, $port] = self::splitHostAndPort($authority, $url);
        if ($port !== null && $port !== '') {
            if (!ctype_digit($port) || (int) $port > 65535) {
                throw new \InvalidArgumentException('port must be numeric in ' . self::redact($url), self::REFUSED_PORT);
            }
            $parts['port'] = (int) $port;
        }
        if ($host !== '') {
            $parts['host'] = $host;
        }
    }

    /**
     * @return array{0: string, 1: ?string} The host and the raw port text (null when absent)
     */
    private static function splitHostAndPort(#[\SensitiveParameter] string $authority, #[\SensitiveParameter] string $url): array
    {
        if (!str_starts_with($authority, '[')) {
            $colon = strpos($authority, ':');
            return $colon === false
                ? [$authority, null]
                : [substr($authority, 0, $colon), substr($authority, $colon + 1)];
        }
        $close = strpos($authority, ']');
        if ($close === false) {
            throw new \InvalidArgumentException('unclosed IPv6 bracket in ' . self::redact($url), self::REFUSED_HOST);
        }
        $after = substr($authority, $close + 1);
        if ($after !== '' && $after[0] !== ':') {
            throw new \InvalidArgumentException('malformed host in ' . self::redact($url), self::REFUSED_HOST);
        }
        return [substr($authority, 0, $close + 1), $after === '' ? null : substr($after, 1)];
    }

    /**
     * No '@', a numeric port and no path, followed straight by '?' or '#'
     * that is not a key=value query: "u:12?secret" is a digit-led PASSWORD,
     * not host "u", port 12 and a query. parse_url() split it that way and
     * put the password in the query/fragment (GHSA-h2mr-m3v3-cq8c).
     *
     * @param array<string, int|string> $parts
     */
    private static function refuseDigitLedPassword(array $parts): void
    {
        if (!isset($parts['port']) || isset($parts['path'])) {
            return;
        }
        $tail = (isset($parts['query']) ? '?' . $parts['query'] : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
        if ($tail !== '' && !self::isKeyValueQuery($tail)) {
            throw new \InvalidArgumentException(
                "a ':' followed by digits and then '?' or '#' cannot be told from a password - "
                . 'percent-encode the password (' . self::NOT_ECHOED . ')',
                self::REFUSED_AMBIGUOUS_PORT
            );
        }
    }

    /**
     * parseParts(), or null when the value is refused - for the callers that
     * already fall back silently (cache and session backends, query-option
     * lookups) and print nothing about the URL.
     *
     * @param string $url The connection URL
     * @return array<string, int|string>|null
     */
    public static function tryParseParts(#[\SensitiveParameter] string $url): ?array
    {
        try {
            return self::parseParts($url);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Remove a connection URL's secrets from a message a DRIVER wrote.
     *
     * Some drivers quote the URL they were handed (the MongoDB driver's
     * "Failed to parse MongoDB URI: '<uri>'"), so a message Tina4 re-throws
     * can carry the password even though Tina4 never interpolated it. The raw
     * URL is replaced by its redacted form, and the password itself - raw and
     * decoded - by the mask wherever else it appears.
     */
    public static function scrubMessage(string $message, #[\SensitiveParameter] string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return $message;
        }
        $message = str_replace($url, self::redact($url), $message);
        $parts = self::tryParseParts($url);
        $secret = $parts['pass'] ?? null;
        // A one- or two-character password would mask every occurrence of
        // that letter in the message; the URL itself is already replaced.
        if (is_string($secret) && strlen($secret) >= 3) {
            $message = str_replace([$secret, rawurldecode($secret)], self::REDACTED, $message);
        }
        return $message;
    }

    /**
     * What print_r()/var_dump() show. The password is a secret in a dump too.
     *
     * Python guards the same object with __repr__ and Ruby with #inspect; PHP
     * had no guard at all, so `print_r($url)` printed "[password] => pass".
     *
     * @return array<string, mixed> The public state with the password masked
     */
    public function __debugInfo(): array
    {
        return [
            'engine' => $this->engine,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password === null ? null : self::REDACTED,
        ];
    }

    /**
     * What json_encode() emits. Tina4 auto-serialises a returned object to JSON,
     * so an un-guarded DatabaseUrl in a route response is a credential on the
     * wire; json_encode() emitted "password":"pass" before this.
     *
     * @return array<string, mixed> The public state with the password masked
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /**
     * Get the default port for a database scheme.
     */
    private function defaultPort(string $scheme): ?int
    {
        return match ($scheme) {
            'postgres' => 5432,
            'mysql' => 3306,
            'mssql' => 1433,
            'firebird' => 3050,
            default => null,
        };
    }
}
