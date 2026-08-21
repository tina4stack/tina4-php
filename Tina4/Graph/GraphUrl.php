<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Graph;

/**
 * Parses a graph connection URL into its parts — the DatabaseUrl sibling for
 * graph engines.
 *
 * Engine is the CANONICAL name the factory selects an adapter by; a scheme alias
 * resolves to its engine here. `bolt`/`neo4j`/`memgraph` all speak Bolt/Cypher and
 * share ONE adapter ("bolt"); the engine label only tunes per-engine defaults.
 *
 *   ultipa://user:pass@host:60061/mygraph
 *   ultipas://host/mygraph        (TLS variant)
 *   neo4j://host/db               (engine: bolt)
 *   arango://host/db              (engine: arango)
 */
class GraphUrl
{
    /** @var array<string, string> URL scheme to CANONICAL engine name. */
    private const SCHEME_ENGINE = [
        'ultipa' => 'ultipa',
        'ultipas' => 'ultipa',   // TLS variant
        'neo4j' => 'bolt',
        'neo4j+s' => 'bolt',
        'bolt' => 'bolt',
        'bolt+s' => 'bolt',
        'memgraph' => 'bolt',
        'arango' => 'arango',
        'arangodb' => 'arango',
    ];

    /** @var array<string, int> Default port per engine when the URL omits one. */
    private const ENGINE_DEFAULT_PORT = [
        'ultipa' => 60061,
        'bolt' => 7687,
        'arango' => 8529,
    ];

    /** The raw URL as supplied. */
    public readonly string $raw;

    /** The raw scheme, lowercased (e.g. "ultipa", "ultipas", "neo4j"). */
    public readonly string $scheme;

    /** The canonical engine name: ultipa, bolt or arango. */
    public readonly string $engine;

    public readonly string $host;

    /** The engine default applies when the URL omits a port. */
    public readonly ?int $port;

    /** The graph/database name (leading slash stripped), or null when absent. */
    public readonly ?string $graph;

    /** Null when absent, never an empty string — absent and blank are different. */
    public readonly ?string $username;
    public readonly ?string $password;

    /** @var array<string, string> Query-string params. */
    public readonly array $params;

    /** True when the scheme ends in "s" (…s) or ?tls=1|true. */
    public readonly bool $useTls;

    /**
     * Parse a graph connection URL.
     *
     * @param string $url The graph URL to parse
     * @throws \InvalidArgumentException When the scheme is not a supported graph engine
     */
    public function __construct(string $url)
    {
        $this->raw = $url;
        $parts = parse_url($url);

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!isset(self::SCHEME_ENGINE[$scheme])) {
            $supported = array_keys(self::SCHEME_ENGINE);
            sort($supported);
            throw new \InvalidArgumentException(
                "Unsupported graph URL scheme '{$scheme}'. Supported: "
                . implode(', ', $supported)
                . ' (e.g. ultipa://host:60061/mygraph).'
            );
        }

        $this->scheme = $scheme;
        $this->engine = self::SCHEME_ENGINE[$scheme];
        $this->host = $parts['host'] ?? 'localhost';
        $this->port = $parts['port'] ?? (self::ENGINE_DEFAULT_PORT[$this->engine] ?? null);

        // The path is the graph/database name (leading slash stripped).
        $path = isset($parts['path']) ? ltrim($parts['path'], '/') : '';
        $this->graph = $path !== '' ? $path : null;

        $this->username = isset($parts['user']) ? urldecode($parts['user']) : null;
        $this->password = isset($parts['pass']) ? urldecode($parts['pass']) : null;

        $params = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $params);
        }
        /** @var array<string, string> $params */
        $this->params = $params;

        $this->useTls = str_ends_with($scheme, 's')
            || in_array((string) ($params['tls'] ?? ''), ['1', 'true'], true);
    }

    /**
     * A DSN-style host:port/graph for messages — never carrying credentials.
     */
    public function getDsn(): string
    {
        $target = $this->port !== null ? "{$this->host}:{$this->port}" : $this->host;

        return $this->graph !== null ? "{$target}/{$this->graph}" : $target;
    }

    /**
     * Build from an environment variable (default TINA4_GRAPH_URL).
     *
     * @param string $envKey The environment variable name
     * @return self|null Null when the env var is not set
     * @throws \InvalidArgumentException When the URL scheme is unsupported
     */
    public static function fromEnv(string $envKey = 'TINA4_GRAPH_URL'): ?self
    {
        $url = \Tina4\DotEnv::getEnv($envKey);

        if ($url === null || $url === '') {
            return null;
        }

        return new self($url);
    }
}
