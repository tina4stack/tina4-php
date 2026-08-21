<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Graph;

/**
 * A graph connect exceeded TINA4_GRAPH_CONNECT_TIMEOUT.
 *
 * The sibling of the relational connect-timeout contract
 * (TINA4_DATABASE_CONNECT_TIMEOUT): an unbounded graph connect hangs the app with
 * no signal, so a connect to an unreachable host raises this within the bound,
 * naming the host, port and elapsed seconds.
 *
 * It is deliberately NOT a subclass of GraphError, so a caller can distinguish a
 * connect timeout from a statement error — the same split as the Python master
 * (GraphConnectTimeout is a TimeoutError, GraphError is not).
 */
class GraphConnectTimeout extends \RuntimeException
{
    /** The env var that bounds a graph connect. */
    public const TIMEOUT_VARIABLE = 'TINA4_GRAPH_CONNECT_TIMEOUT';

    /** Seconds a graph connect may block when the env var is unset. */
    public const DEFAULT_TIMEOUT_SECONDS = 10.0;

    /**
     * Resolve TINA4_GRAPH_CONNECT_TIMEOUT to seconds.
     *
     * Mirrors the relational resolver: a value <= 0 disables the bound (returns
     * null — unbounded), an unset var uses the default, and a non-numeric value
     * warns and falls back to the default rather than waiting forever.
     *
     * @return float|null Seconds to bound the connect, or null for unbounded (<= 0)
     */
    public static function resolveSeconds(): ?float
    {
        $raw = trim((string) \Tina4\DotEnv::getEnv(self::TIMEOUT_VARIABLE));

        if ($raw === '') {
            return self::DEFAULT_TIMEOUT_SECONDS;
        }

        if (!is_numeric($raw)) {
            \Tina4\Log::warning(
                self::TIMEOUT_VARIABLE . "='{$raw}' is not a number of seconds — bounding graph "
                . 'connects at the ' . self::DEFAULT_TIMEOUT_SECONDS . 's default instead'
            );
            return self::DEFAULT_TIMEOUT_SECONDS;
        }

        $seconds = (float) $raw;

        return $seconds <= 0 ? null : $seconds;
    }
}
