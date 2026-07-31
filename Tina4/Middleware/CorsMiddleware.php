<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Middleware;

use Tina4\DotEnv;
use Tina4\Log;
use Tina4\Request;
use Tina4\Response;

/**
 * CORS middleware — handles Cross-Origin Resource Sharing headers.
 *
 * Configuration via environment variables:
 *   TINA4_CORS_ORIGINS  — comma-separated allowed origins (default: EMPTY = deny)
 *   TINA4_CORS_METHODS  — comma-separated allowed methods (default: "GET,POST,PUT,PATCH,DELETE,OPTIONS")
 *   TINA4_CORS_HEADERS  — comma-separated allowed headers (default: "Content-Type,Authorization,X-Request-ID")
 *   TINA4_CORS_MAX_AGE  — preflight cache duration in seconds (default: "86400")
 *   TINA4_CORS_CREDENTIALS — allow credentials (default: "false"); never sent with a wildcard origin
 *
 * DENY BY DEFAULT (ADR-0018). With TINA4_CORS_ORIGINS unset, NO
 * Access-Control-Allow-Origin is emitted and the browser's own CORS check
 * blocks the cross-origin request. "*" still works, it just has to be asked
 * for. Breaking change from the old permissive default.
 *
 * CREDENTIALS AND THE WILDCARD ARE MUTUALLY EXCLUSIVE. The Fetch Standard's
 * CORS check treats "*" as a literal (not a wildcard) once the request's
 * credentials mode is "include", so Access-Control-Allow-Origin: * together
 * with Access-Control-Allow-Credentials: true is rejected by every browser.
 * The wildcard wins and credentials are dropped with an actionable warning,
 * rather than shipping a pair no browser accepts.
 *
 * VARY: ORIGIN whenever the ACAO value is COMPUTED from the request's Origin,
 * i.e. whenever an allow-list is configured, on a MISS as well as a match.
 * RFC 9110 s12.5.5: a Vary field name list tells cache recipients they "MUST
 * NOT use this response to satisfy a later request unless the later request
 * has the same values for the listed header fields as the original request".
 * A constant "*" genuinely does not vary and is NOT given a Vary, which would
 * only fragment a CDN's cache per-origin for a response identical to all.
 * Access-Control-Allow-Methods / -Allow-Headers are static configured lists
 * here, never derived from the request's Access-Control-Request-* headers, so
 * those field names do NOT belong in Vary.
 */
class CorsMiddleware
{
    /**
     * CORS runs BEFORE route matching so its headers survive a short-circuited
     * 401/403. A browser shown a 401 without them reports a CORS error, and the
     * real status never reaches the developer debugging it.
     */
    public static bool $preMatch = true;

    /** Warn-once ledger, keyed by reason, so a scripted probe cannot flood the log. */
    private static array $warned = [];

    private readonly string $allowedOrigins;
    private readonly string $allowedMethods;
    private readonly string $allowedHeaders;
    private readonly int $maxAge;
    private readonly bool $credentials;

    public function __construct(
        ?string $origins = null,
        ?string $methods = null,
        ?string $headers = null,
        ?int $maxAge = null,
        ?bool $credentials = null,
    ) {
        // Default is EMPTY, not "*" — deny by default (ADR-0018).
        $this->allowedOrigins = $origins ?? DotEnv::getEnv('TINA4_CORS_ORIGINS', '');
        $this->allowedMethods = $methods ?? DotEnv::getEnv('TINA4_CORS_METHODS', 'GET,POST,PUT,PATCH,DELETE,OPTIONS');
        $this->allowedHeaders = $headers ?? DotEnv::getEnv('TINA4_CORS_HEADERS', 'Content-Type,Authorization,X-Request-ID');
        $this->maxAge = $maxAge ?? (int)DotEnv::getEnv('TINA4_CORS_MAX_AGE', '86400');
        $credentialsEnv = DotEnv::getEnv('TINA4_CORS_CREDENTIALS', 'false');
        $this->credentials = $credentials ?? in_array(strtolower((string)$credentialsEnv), ['true', '1', 'yes'], true);
    }

    /**
     * Reset the warn-once ledger. Test-only seam so one test's warning does not
     * suppress another's.
     *
     * @return void
     */
    public static function resetWarnings(): void
    {
        self::$warned = [];
    }

    /**
     * Log an actionable CORS warning at most once per reason per process.
     *
     * A rejected cross-origin request is otherwise invisible: the browser
     * reports a generic CORS failure and the server log says nothing.
     *
     * @param string $key Dedupe key for this warning.
     * @param string $message The actionable message to log.
     * @return void
     */
    private static function warnOnce(string $key, string $message): void
    {
        if (isset(self::$warned[$key])) {
            return;
        }
        self::$warned[$key] = true;
        Log::warning($message);
    }

    /**
     * The configured origins, split and emptied of blanks.
     *
     * @return array<int, string>
     */
    public function getAllowedOriginList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->allowedOrigins)), static fn($o) => $o !== ''));
    }

    /**
     * Whether an operator has actually declared a CORS policy.
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return $this->getAllowedOriginList() !== [];
    }

    /**
     * Get the CORS headers to add to a response.
     *
     * This is the ONE place the policy is computed. beforeCors() applies what
     * this returns, so the static hook and the instance API can never drift
     * apart the way two parallel implementations do.
     *
     * @param string|null $requestOrigin The Origin header from the request
     * @return array<string, string> Headers to set on the response
     */
    public function getHeaders(?string $requestOrigin = null): array
    {
        $allowed = $this->getAllowedOriginList();

        if ($allowed === []) {
            if ($requestOrigin !== null && $requestOrigin !== '') {
                self::warnOnce('unconfigured',
                    "CORS: refused cross-origin request from {$requestOrigin} — no policy is configured. "
                    . 'Set TINA4_CORS_ORIGINS to the origins you want to allow, e.g. '
                    . "TINA4_CORS_ORIGINS=https://app.example.com (or '*' to allow any origin).");
            }
            return [];
        }

        $headers = [];
        $isWildcard = in_array('*', $allowed, true);

        // An allow-list decision reads the request Origin, so the response
        // varies by it — on a MISS too, or a shared cache can serve this
        // no-ACAO response to an origin that should have been allowed.
        if (!$isWildcard) {
            $headers['Vary'] = 'Origin';
        }

        $origin = $this->resolveOrigin($requestOrigin);
        if ($origin === null) {
            if ($requestOrigin !== null && $requestOrigin !== '') {
                self::warnOnce("denied:{$requestOrigin}",
                    "CORS: origin {$requestOrigin} is not in TINA4_CORS_ORIGINS ({$this->allowedOrigins}) "
                    . '— the browser will block this response.');
            }
            return $headers;
        }

        $headers['Access-Control-Allow-Origin'] = $origin;
        $headers['Access-Control-Allow-Methods'] = $this->allowedMethods;
        $headers['Access-Control-Allow-Headers'] = $this->allowedHeaders;
        $headers['Access-Control-Max-Age'] = (string)$this->maxAge;

        if ($this->credentials) {
            if ($origin === '*') {
                self::warnOnce('wildcard-credentials',
                    "CORS: TINA4_CORS_CREDENTIALS is true but TINA4_CORS_ORIGINS is '*'. The Fetch Standard "
                    . 'forbids Access-Control-Allow-Origin: * with credentials, so credentials are NOT being '
                    . 'sent. Credentialed CORS requires an explicit origin list, e.g. '
                    . 'TINA4_CORS_ORIGINS=https://app.example.com.');
            } else {
                $headers['Access-Control-Allow-Credentials'] = 'true';
            }
        }

        return $headers;
    }

    /**
     * Check if the request method is OPTIONS.
     *
     * NOTE: this returns true for ANY OPTIONS, with no Origin check, so the
     * name overstates what it tests. The real preflight short-circuit in
     * beforeCors() does NOT use it — a bare OPTIONS belongs to the RFC 9110
     * s9.3.7 handler. Kept because existing tests pin this meaning.
     *
     * @param string $method The HTTP method
     * @return bool
     */
    public function isPreflight(string $method): bool
    {
        return strtoupper($method) === 'OPTIONS';
    }

    /**
     * Handle the middleware invocation.
     *
     * @param string $method The HTTP method
     * @param string|null $requestOrigin The Origin header from the request
     * @return array{headers: array<string, string>, preflight: bool}
     */
    public function handle(string $method, ?string $requestOrigin = null): array
    {
        return [
            'headers' => $this->getHeaders($requestOrigin),
            'preflight' => $this->isPreflight($method),
        ];
    }

    /**
     * Resolve which origin to include in the response, or null for none.
     *
     * @param string|null $requestOrigin The Origin header from the request.
     * @return string|null The resolved origin, or null when nothing is allowed.
     */
    private function resolveOrigin(?string $requestOrigin): ?string
    {
        $allowed = $this->getAllowedOriginList();
        if ($allowed === []) {
            return null;
        }
        if (in_array('*', $allowed, true)) {
            return '*';
        }
        if ($requestOrigin !== null && $requestOrigin !== '' && in_array($requestOrigin, $allowed, true)) {
            return $requestOrigin;
        }
        return null;
    }

    /**
     * Get the configured allowed origins string.
     *
     * @return string
     */
    public function getAllowedOrigins(): string
    {
        return $this->allowedOrigins;
    }

    /**
     * Get the configured allowed methods string.
     *
     * @return string
     */
    public function getAllowedMethods(): string
    {
        return $this->allowedMethods;
    }

    /**
     * Get the configured allowed headers string.
     *
     * @return string
     */
    public function getAllowedHeaders(): string
    {
        return $this->allowedHeaders;
    }

    /**
     * Fold a new Vary field name into whatever Vary the response already has.
     *
     * @param array<string, string> $existingHeaders Headers already on the response.
     * @param string $fieldName The Vary field name to add (e.g. "Origin").
     * @return string The merged, comma-separated Vary value.
     */
    private static function mergeVary(array $existingHeaders, string $fieldName): string
    {
        $current = '';
        foreach ($existingHeaders as $name => $value) {
            if (strcasecmp($name, 'Vary') === 0) {
                $current = (string)$value;
                break;
            }
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $current)), static fn($p) => $p !== ''));
        foreach ($parts as $part) {
            if (strcasecmp($part, $fieldName) === 0) {
                return implode(', ', $parts);
            }
        }
        $parts[] = $fieldName;
        return implode(', ', $parts);
    }

    /**
     * Standardized middleware hook — sets CORS headers before the route handler.
     *
     * Delegates the whole policy decision to getHeaders() so there is ONE
     * implementation of the rules.
     *
     * @param Request $request
     * @param Response $response
     * @return array{0: Request, 1: Response}
     */
    public static function beforeCors(Request $request, Response $response): array
    {
        // Read the Origin off the REQUEST first and fall back to $_SERVER.
        // Reading only $_SERVER meant the header was invisible to anything not
        // running under a web SAPI — the in-process TestClient, the CLI, and
        // any Request built by hand — so the origin silently resolved to null.
        $requestOrigin = $request->header('Origin') ?? ($_SERVER['HTTP_ORIGIN'] ?? null);

        $existingHeaders = $response->getHeaders();
        foreach ((new self())->getHeaders($requestOrigin) as $name => $value) {
            // Response::header() overwrites by key, so Vary is MERGED rather
            // than clobbering a value another layer already set (gzip sets
            // Vary: Accept-Encoding).
            if (strcasecmp($name, 'Vary') === 0) {
                $value = self::mergeVary($existingHeaders, $value);
            }
            $response->header($name, $value);
        }

        // Handle OPTIONS preflight — return 204 No Content to short-circuit.
        //
        // Only a REAL preflight short-circuits. A preflight carries an Origin
        // (browsers always send one); a bare OPTIONS does not, and belongs to
        // the RFC 9110 s9.3.7 handler in dispatch, which answers 204 WITH an
        // Allow header listing the path's method set.
        //
        // The status is deliberately the SAME whether the origin was allowed
        // or denied — the browser does the blocking, and inventing a 403 here
        // would be a second behaviour change for no gain.
        if (strtoupper($request->method) === 'OPTIONS' && $requestOrigin !== null && $requestOrigin !== '') {
            // Carry the resource's REAL method set as Allow (RFC 9110 s9.3.7)
            // so a preflight answers the same question a bare OPTIONS does, on
            // top of the CORS policy headers. Allow and
            // Access-Control-Allow-Methods are NOT interchangeable: Allow is
            // what the resource supports, ACAM is what the policy permits
            // cross-origin. A policy allowing DELETE on a GET-only route still
            // 405s. This is CONFORMANCE, not a deviation: the frameworks' own
            // OPTIONS handlers already emit Allow (Django's View.options(),
            // Express's router); only the add-on CORS libraries lose it, by
            // short-circuiting first. ADR-0013.
            $response->header('Allow', implode(', ', \Tina4\Router::methodsAllowedForPath($request->path)));
            $response->status(204);
        }

        return [$request, $response];
    }
}
