<?php

namespace Tina4;

/** Provider-neutral, configuration-first OpenID Connect SSO. */
class Sso
{
    public const PENDING_KEY = '_tina4_sso_pending';
    public const SESSION_KEY = '_tina4_sso';
    private array $metadata = [];
    public readonly string $issuer;
    public readonly string $clientId;
    public readonly ?string $clientSecret;
    public readonly string $redirectUri;
    public readonly array $scopes;
    public readonly string $verify;
    public readonly ?string $postLogoutRedirectUri;
    public readonly array $claimMap;
    private static bool $mounted = false;

    public function __construct(array $options = [])
    {
        $this->issuer = rtrim((string)($options['issuer'] ?? DotEnv::getEnv('TINA4_SSO_ISSUER', '')), '/');
        $this->clientId = (string)($options['client_id'] ?? DotEnv::getEnv('TINA4_SSO_CLIENT_ID', ''));
        $this->clientSecret = $options['client_secret'] ?? DotEnv::getEnv('TINA4_SSO_CLIENT_SECRET');
        $this->redirectUri = (string)($options['redirect_uri'] ?? DotEnv::getEnv('TINA4_SSO_REDIRECT_URI', ''));
        $this->scopes = $options['scopes'] ?? $this->jsonEnv('TINA4_SSO_SCOPES', ['openid', 'profile', 'email']);
        $this->verify = strtolower((string)($options['verify'] ?? DotEnv::getEnv('TINA4_SSO_VERIFY', 'introspection')));
        $this->postLogoutRedirectUri = $options['post_logout_redirect_uri'] ?? DotEnv::getEnv('TINA4_SSO_POST_LOGOUT_REDIRECT_URI');
        $this->claimMap = $options['claim_map'] ?? $this->jsonEnv('TINA4_SSO_CLAIM_MAP', []);
        $this->validateConfig();
    }

    public static function fromIssuer(array $options = []): self
    {
        $value = new self($options);
        $value->discover();
        return $value;
    }

    public static function configured(): bool
    {
        foreach (['TINA4_SSO_ISSUER', 'TINA4_SSO_CLIENT_ID', 'TINA4_SSO_REDIRECT_URI'] as $key) {
            if (!DotEnv::getEnv($key)) {
                return false;
            }
        }
        return true;
    }

    private function jsonEnv(string $name, mixed $fallback): mixed
    {
        $raw = DotEnv::getEnv($name);
        if ($raw === null || $raw === '') {
            return $fallback;
        }
        try {
            return json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SsoError("{$name} must be valid JSON", 0, $e);
        }
    }

    private static function secureUrl(string $value, string $name): void
    {
        $parts = parse_url($value);
        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';
        if ($scheme === '' || $host === '') {
            throw new SsoError("{$name} must be an absolute URL");
        }
        $loopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $loopback)) {
            throw new SsoError("{$name} must use HTTPS except on loopback");
        }
    }

    private function validateConfig(): void
    {
        if ($this->issuer === '' || $this->clientId === '' || $this->redirectUri === '') {
            throw new SsoError('TINA4_SSO_ISSUER, TINA4_SSO_CLIENT_ID and TINA4_SSO_REDIRECT_URI are required');
        }
        self::secureUrl($this->issuer, 'issuer');
        self::secureUrl($this->redirectUri, 'redirect URI');
        if (!in_array($this->verify, ['introspection', 'jwks'], true)) {
            throw new SsoError('TINA4_SSO_VERIFY must be introspection or jwks');
        }
        if ($this->verify === 'jwks') {
            throw new SsoError('jwks verification requires an installed cryptography capability');
        }
        if ($this->verify === 'introspection' && empty($this->clientSecret)) {
            throw new SsoError('introspection verification requires TINA4_SSO_CLIENT_SECRET');
        }
        if (!in_array('openid', $this->scopes, true)) {
            throw new SsoError('TINA4_SSO_SCOPES must be a list containing openid');
        }
    }

    private function requestJson(string $url, ?array $form = null, ?string $bearer = null, bool $basic = false): array
    {
        $headers = ['Accept: application/json'];
        $method = $form === null ? 'GET' : 'POST';
        $content = $form === null ? '' : http_build_query($form, '', '&', PHP_QUERY_RFC3986);
        if ($form !== null) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        if ($bearer !== null) {
            $headers[] = "Authorization: Bearer {$bearer}";
        }
        if ($basic) {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret);
        }
        $context = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers),
            'content' => $content, 'timeout' => 10, 'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $context);
        $status = $http_response_header[0] ?? '';
        if ($raw === false || !preg_match('/\s2\d\d\s/', $status)) {
            throw new SsoError('OIDC provider request failed');
        }
        try {
            $result = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SsoError('OIDC provider returned invalid JSON', 0, $e);
        }
        if (!is_array($result)) {
            throw new SsoError('OIDC provider returned a non-object response');
        }
        return $result;
    }

    public function discover(bool $force = false): array
    {
        if ($this->metadata !== [] && !$force) {
            return $this->metadata;
        }
        $result = $this->requestJson($this->issuer . '/.well-known/openid-configuration');
        if (($result['issuer'] ?? null) !== $this->issuer) {
            throw new SsoError('OIDC discovery issuer does not exactly match configuration');
        }
        $required = ['authorization_endpoint', 'token_endpoint'];
        if ($this->verify === 'introspection') {
            $required[] = 'introspection_endpoint';
        }
        foreach ($required as $key) {
            if (empty($result[$key])) {
                throw new SsoError("OIDC discovery is missing {$key}");
            }
            self::secureUrl($result[$key], $key);
        }
        return $this->metadata = $result;
    }

    public static function safeReturn(?string $value): string
    {
        if (!$value || !str_starts_with($value, '/') || str_starts_with($value, '//') || str_contains($value, '\\')) {
            return '/';
        }
        return preg_match('/[\x00-\x1F]/', $value) ? '/' : $value;
    }

    private function session(mixed $value): ?Session
    {
        if ($value instanceof Session) {
            return $value;
        }
        return $value->session ?? null;
    }

    private static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public function login(mixed $requestOrSession, string $returnTo = '/'): string
    {
        $session = $this->session($requestOrSession);
        if ($session === null) {
            throw new SsoError('SSO login requires a Tina4 Session');
        }
        $state = self::randomToken();
        $nonce = self::randomToken();
        $verifier = self::randomToken(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $session->set(self::PENDING_KEY, [
            'state' => $state, 'nonce' => $nonce, 'verifier' => $verifier,
            'return_to' => self::safeReturn($returnTo), 'created_at' => time(),
        ]);
        $query = http_build_query([
            'client_id' => $this->clientId, 'redirect_uri' => $this->redirectUri,
            'response_type' => 'code', 'scope' => implode(' ', $this->scopes),
            'state' => $state, 'nonce' => $nonce, 'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
        return $this->discover()['authorization_endpoint'] . '?' . $query;
    }

    private static function jwtPayload(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) < 2) {
            throw new SsoError('provider returned an invalid ID token');
        }
        $raw = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $value = $raw === false ? null : json_decode($raw, true);
        if (!is_array($value)) {
            throw new SsoError('provider returned an invalid ID token');
        }
        return $value;
    }

    private function introspect(string $accessToken): array
    {
        $result = $this->requestJson($this->discover()['introspection_endpoint'], [
            'token' => $accessToken, 'token_type_hint' => 'access_token',
        ], null, true);
        if (($result['active'] ?? false) !== true || ($result['iss'] ?? null) !== $this->issuer) {
            throw new SsoError('OIDC access token is inactive or has the wrong issuer');
        }
        $audience = $result['aud'] ?? ($result['client_id'] ?? null);
        $valid = is_array($audience) ? in_array($this->clientId, $audience, true) : $audience === $this->clientId;
        if (!$valid && ($result['client_id'] ?? null) !== $this->clientId) {
            throw new SsoError('OIDC token audience mismatch');
        }
        return $result;
    }

    private function claim(array $claims, ?string $configured, string $fallback): mixed
    {
        $value = $claims;
        foreach (explode('.', $configured ?: $fallback) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }
        return $value;
    }

    private function normalize(array $claims): array
    {
        $subject = $this->claim($claims, $this->claimMap['subject'] ?? null, 'sub');
        $issuer = $this->claim($claims, $this->claimMap['issuer'] ?? null, 'iss') ?: $this->issuer;
        if (!$subject || $issuer !== $this->issuer) {
            throw new SsoError('OIDC identity is missing a valid issuer or subject');
        }
        $roles = $this->claim($claims, $this->claimMap['roles'] ?? null, 'realm_access.roles') ?: [];
        $roles = array_merge($roles, $claims['resource_access'][$this->clientId]['roles'] ?? []);
        $groups = $this->claim($claims, $this->claimMap['groups'] ?? null, 'groups') ?: [];
        $roles = array_values(array_unique(array_map('strval', $roles)));
        $groups = array_values(array_unique(array_map('strval', $groups)));
        sort($roles); sort($groups);
        return [
            'issuer' => $issuer, 'subject' => $subject,
            'username' => $this->claim($claims, $this->claimMap['username'] ?? null, 'preferred_username'),
            'email' => $this->claim($claims, $this->claimMap['email'] ?? null, 'email'),
            'name' => $this->claim($claims, $this->claimMap['name'] ?? null, 'name'),
            'roles' => $roles, 'groups' => $groups,
        ];
    }

    public function callback(mixed $requestOrSession, ?array $query = null): array
    {
        $session = $this->session($requestOrSession);
        $query ??= $requestOrSession->query ?? [];
        $pending = $session?->get(self::PENDING_KEY);
        $session?->delete(self::PENDING_KEY);
        if (!is_array($pending) || empty($query['code']) || !hash_equals((string)$pending['state'], (string)($query['state'] ?? ''))) {
            throw new SsoError('OIDC callback state is invalid or already consumed');
        }
        if (time() - (int)($pending['created_at'] ?? 0) > 600) {
            throw new SsoError('OIDC callback state has expired');
        }
        $metadata = $this->discover();
        $tokens = $this->requestJson($metadata['token_endpoint'], [
            'grant_type' => 'authorization_code', 'code' => $query['code'],
            'redirect_uri' => $this->redirectUri, 'client_id' => $this->clientId,
            'code_verifier' => $pending['verifier'],
        ], null, !empty($this->clientSecret));
        if (empty($tokens['access_token']) || empty($tokens['id_token'])) {
            throw new SsoError('OIDC token response is incomplete');
        }
        if ($this->verify === 'jwks') {
            throw new SsoError('JWKS verification requires an installed cryptography capability');
        }
        $claims = $this->introspect($tokens['access_token']);
        if (!hash_equals((string)$pending['nonce'], (string)(self::jwtPayload($tokens['id_token'])['nonce'] ?? ''))) {
            throw new SsoError('OIDC ID token nonce mismatch');
        }
        if (!empty($metadata['userinfo_endpoint'])) {
            $claims = array_replace($claims, $this->requestJson($metadata['userinfo_endpoint'], null, $tokens['access_token']));
        }
        $identity = $this->normalize($claims);
        $session->regenerate();
        $session->set(self::SESSION_KEY, [
            'version' => 1, 'identity' => $identity, 'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? null, 'id_token' => $tokens['id_token'],
            'expires_at' => time() + (int)($tokens['expires_in'] ?? 0),
        ]);
        return ['identity' => $identity, 'return_to' => self::safeReturn($pending['return_to'] ?? '/')];
    }

    public function identity(mixed $requestOrSession): ?array
    {
        $stored = $this->session($requestOrSession)?->get(self::SESSION_KEY);
        $identity = is_array($stored) ? ($stored['identity'] ?? null) : null;
        if (is_array($identity) && !($requestOrSession instanceof Session)) {
            $requestOrSession->user = $identity;
        }
        return is_array($identity) ? $identity : null;
    }

    public function refresh(mixed $requestOrSession): array
    {
        $session = $this->session($requestOrSession);
        $stored = $session?->get(self::SESSION_KEY);
        if (!is_array($stored) || empty($stored['refresh_token'])) {
            $session?->delete(self::SESSION_KEY);
            throw new SsoError('OIDC session cannot be refreshed');
        }
        try {
            $metadata = $this->discover();
            $tokens = $this->requestJson($metadata['token_endpoint'], [
                'grant_type' => 'refresh_token', 'refresh_token' => $stored['refresh_token'],
                'client_id' => $this->clientId,
            ], null, !empty($this->clientSecret));
            if (empty($tokens['access_token'])) {
                throw new SsoError('OIDC refresh response is incomplete');
            }
            $claims = $this->introspect($tokens['access_token']);
            if (!empty($metadata['userinfo_endpoint'])) {
                $claims = array_replace($claims, $this->requestJson($metadata['userinfo_endpoint'], null, $tokens['access_token']));
            }
            $identity = $this->normalize($claims);
            $session->set(self::SESSION_KEY, array_replace($stored, [
                'identity' => $identity, 'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? $stored['refresh_token'],
                'id_token' => $tokens['id_token'] ?? ($stored['id_token'] ?? null),
                'expires_at' => time() + (int)($tokens['expires_in'] ?? 0),
            ]));
            return $identity;
        } catch (\Throwable $e) {
            $session?->delete(self::SESSION_KEY);
            throw $e;
        }
    }

    public function logout(mixed $requestOrSession, string $returnTo = '/'): string
    {
        $session = $this->session($requestOrSession);
        $stored = $session?->get(self::SESSION_KEY);
        $session?->destroy();
        $endpoint = $this->discover()['end_session_endpoint'] ?? null;
        $target = $this->postLogoutRedirectUri ?: self::safeReturn($returnTo);
        if (!$endpoint) {
            return $target;
        }
        $params = ['post_logout_redirect_uri' => $target, 'client_id' => $this->clientId];
        if (!empty($stored['id_token'])) {
            $params['id_token_hint'] = $stored['id_token'];
        }
        return $endpoint . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public static function mountConfigured(): bool
    {
        if (self::$mounted || !self::configured()) {
            return false;
        }
        $owned = ['GET /auth/login', 'GET /auth/callback', 'POST /auth/logout'];
        $collisions = [];
        foreach (Router::getRoutes() as $route) {
            $key = $route['method'] . ' ' . $route['path'];
            if (in_array($key, $owned, true)) {
                $collisions[] = $key;
            }
        }
        if ($collisions !== []) {
            throw new SsoError('SSO route collision: ' . implode(', ', $collisions));
        }
        $sso = self::fromIssuer();
        Router::get('/auth/login', static function ($request, $response) use ($sso) {
            return $response->redirect($sso->login($request, $request->query['return_to'] ?? '/'));
        });
        Router::get('/auth/callback', static function ($request, $response) use ($sso) {
            try {
                return $response->redirect($sso->callback($request)['return_to']);
            } catch (SsoError $e) {
                return $response->json(['error' => 'SSO_CALLBACK_FAILED', 'message' => $e->getMessage()], 400);
            }
        });
        Router::post('/auth/logout', static function ($request, $response) use ($sso) {
            return $response->redirect($sso->logout($request, $request->query['return_to'] ?? '/'));
        });
        self::$mounted = true;
        return true;
    }
}
