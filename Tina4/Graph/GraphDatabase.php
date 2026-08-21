<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Graph;

/**
 * URL-selected graph factory — the GraphDatabase sibling of Database.
 *
 * `GraphDatabase::create($url)` parses the scheme, picks the engine adapter and
 * connects lazily; the engine driver is referenced only inside the adapter (first
 * use of that engine), so referencing Tina4\Graph pulls in NO engine driver — the
 * zero-dependency-core rule. A missing driver raises an actionable install error
 * naming the composer package and command, never a bare "class not found".
 */
class GraphDatabase
{
    /**
     * The default engine => [adapter class, composer package, install command]
     * registrations. `bolt` serves BOTH Neo4j and Memgraph (one Bolt/Cypher
     * driver); `arango` serves ArangoDB. Each engine driver is an OPTIONAL
     * community package, referenced only inside its adapter, so a missing driver
     * surfaces as an actionable install error rather than a bare "class not found".
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const DEFAULT_ENGINE_ADAPTERS = [
        'ultipa' => [
            UltipaGraphAdapter::class,
            'tina4stack/ultipa',
            'composer require tina4stack/ultipa',
        ],
        'bolt' => [
            BoltGraphAdapter::class,
            'laudis/neo4j-php-client',
            'composer require laudis/neo4j-php-client',
        ],
        'arango' => [
            ArangoGraphAdapter::class,
            'triagens/arangodb',
            'composer require triagens/arangodb',
        ],
    ];

    /**
     * The live registry, seeded lazily from the default. Mutable so a new engine
     * adapter can register itself (and so the driver-optional test can simulate an
     * absent driver — the exact seam the Python master's _ENGINE_ADAPTERS provides).
     *
     * @var array<string, array{0: string, 1: string, 2: string}>|null
     */
    private static ?array $engineAdapters = null;

    /**
     * Parse the URL, pick the engine adapter, and connect lazily.
     *
     * @param string $url The graph connection URL (scheme selects the engine)
     * @param string $username Fallback username when the URL omits credentials
     * @param string $password Fallback password when the URL omits credentials
     * @return GraphAdapter A connected adapter for the engine
     * @throws \InvalidArgumentException When the URL scheme is not a graph engine
     * @throws GraphError When no adapter exists for the engine, or its driver is not installed
     */
    public static function create(string $url, string $username = '', string $password = ''): GraphAdapter
    {
        $graphUrl = new GraphUrl($url);

        $registry = self::engineAdapters();
        $registration = $registry[$graphUrl->engine] ?? null;
        if ($registration === null) {
            $available = array_keys($registry);
            sort($available);
            throw new GraphError(
                "No graph adapter for engine '{$graphUrl->engine}' yet "
                . "(scheme '{$graphUrl->scheme}'). Available: " . implode(', ', $available) . '.'
            );
        }

        [$adapterClass, $package, $installCommand] = $registration;

        // The adapter's constructor references the engine driver
        // (Tina4\Ultipa\Client). A missing driver surfaces as an Error the
        // autoloader raises when that class can't be found — turn it into an
        // actionable install error, exactly like the Python factory.
        try {
            return new $adapterClass($graphUrl, $username, $password);
        } catch (\Error $exception) {
            if (self::isMissingDriver($exception, $adapterClass)) {
                throw new GraphError(
                    "The graph driver for '{$graphUrl->engine}' is not installed ({$package}). "
                    . "Install it with:\n    {$installCommand}",
                    0,
                    $exception
                );
            }
            throw $exception;
        }
    }

    /**
     * Build from TINA4_GRAPH_URL (plus TINA4_GRAPH_USERNAME/_PASSWORD).
     *
     * @param string $envKey The environment variable holding the URL
     * @return GraphAdapter|null Null when the env var is not set
     * @throws \InvalidArgumentException When the URL scheme is not a graph engine
     * @throws GraphError When the engine driver is not installed
     */
    public static function fromEnv(string $envKey = 'TINA4_GRAPH_URL'): ?GraphAdapter
    {
        $url = \Tina4\DotEnv::getEnv($envKey);
        if ($url === null || $url === '') {
            return null;
        }

        $username = (string) (\Tina4\DotEnv::getEnv('TINA4_GRAPH_USERNAME') ?? '');
        $password = (string) (\Tina4\DotEnv::getEnv('TINA4_GRAPH_PASSWORD') ?? '');

        return self::create($url, $username, $password);
    }

    /**
     * Register (or replace) the adapter for an engine.
     *
     * Called by a new engine adapter to make itself selectable, and by the
     * driver-optional test to point an engine at an absent driver. Passing null
     * for $adapterClass restores the built-in default for that engine (or removes
     * it if there is none), so a test can cleanly undo an override.
     *
     * @param string $engine The canonical engine name (e.g. "ultipa", "bolt")
     * @param string|null $adapterClass The adapter class, or null to restore the default
     * @param string $package The composer package that supplies the driver
     * @param string $installCommand The command that installs it
     */
    public static function registerEngine(
        string $engine,
        ?string $adapterClass = null,
        string $package = '',
        string $installCommand = ''
    ): void {
        $registry = self::engineAdapters();

        if ($adapterClass === null) {
            if (isset(self::DEFAULT_ENGINE_ADAPTERS[$engine])) {
                $registry[$engine] = self::DEFAULT_ENGINE_ADAPTERS[$engine];
            } else {
                unset($registry[$engine]);
            }
        } else {
            $registry[$engine] = [$adapterClass, $package, $installCommand];
        }

        self::$engineAdapters = $registry;
    }

    /**
     * The live engine registry, seeded from the default on first use.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private static function engineAdapters(): array
    {
        return self::$engineAdapters ??= self::DEFAULT_ENGINE_ADAPTERS;
    }

    /**
     * Whether an Error was raised because the engine driver class is absent
     * (autoload could not find it), as opposed to some other runtime error or a
     * framework autoload bug on the adapter class itself.
     *
     * @param \Error $exception The caught error
     * @param string $adapterClass The adapter class being constructed
     */
    private static function isMissingDriver(\Error $exception, string $adapterClass): bool
    {
        // A missing driver class surfaces as "Class \"Tina4\Ultipa\Client\" not
        // found". If the adapter class ITSELF is what's not found, that is a
        // framework autoload problem, not a missing optional driver — let it fly.
        $message = $exception->getMessage();
        if (!str_contains($message, 'not found')) {
            return false;
        }

        return !str_contains($message, $adapterClass);
    }
}
