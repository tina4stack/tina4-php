<?php

namespace Tina4;

/**
 * Outbound SSRF guard (ADR-0084).
 *
 * The Api client and Web Push refuse, by default, to connect to a private or
 * internal address. Before each connection - the initial URL and every redirect
 * hop the Api client follows - the host is resolved to its IP address(es) and
 * the request is refused if any resolved address is loopback, private,
 * link-local (including the cloud metadata address 169.254.169.254), unspecified
 * or CGNAT. A non-http(s) scheme is refused.
 *
 * Off by default; TINA4_ALLOW_PRIVATE_REQUESTS (truthy) or an explicit allow-list
 * of hosts / host:port / CIDRs opts out. Zero external dependencies - PHP core
 * inet_pton / gethostbynamel / dns_get_record.
 */
final class Ssrf
{
    public const ALLOW_PRIVATE_ENV = 'TINA4_ALLOW_PRIVATE_REQUESTS';

    /** Truthiness identical to ADR-0070's set (trimmed, lower-cased). */
    private const TRUTHY = ['1', 'true', 'yes', 'on'];

    /**
     * The blocked address space, one definition shared with the contract fixture.
     * 0.0.0.0/8 covers the unspecified address and the "this network" block.
     */
    private const BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10',
        '::1/128', '::/128', 'fc00::/7', 'fe80::/10',
    ];

    /** True when TINA4_ALLOW_PRIVATE_REQUESTS opts out of the guard. */
    public static function allowPrivateRequests(): bool
    {
        $raw = strtolower(trim((string)(getenv(self::ALLOW_PRIVATE_ENV) ?: '')));
        return in_array($raw, self::TRUTHY, true);
    }

    /**
     * Classify one IP string: true = private/internal, refuse it. An IPv4-mapped
     * IPv6 address is classified by its embedded IPv4. An unparseable value is
     * treated as blocked - the guard refuses what it cannot classify.
     */
    public static function isBlockedAddress(string $ip): bool
    {
        $ip = explode('%', $ip, 2)[0];
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return true;
        }
        // Unwrap IPv4-mapped IPv6 (::ffff:a.b.c.d) to its 4-byte IPv4.
        if (strlen($packed) === 16 && substr($packed, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
            $packed = substr($packed, 12);
        }
        foreach (self::BLOCKED as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Refuse $url when it targets a private/internal address.
     *
     * @param array<int,string>|null $allowHosts hosts / host:port / CIDRs to permit
     * @throws SsrfError for a non-http(s) scheme, an unresolvable host, or any
     *                   resolved address in the blocked space
     */
    public static function guardUrl(string $url, ?array $allowHosts = null): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new SsrfError("Blocked request: URL scheme '" . ($scheme ?: '(none)') . "' is not http or https");
        }
        $host = (string)($parts['host'] ?? '');
        if ($host === '') {
            throw new SsrfError('Blocked request: URL has no host');
        }
        // parse_url keeps IPv6 hosts in brackets.
        $host = trim($host, '[]');
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (self::allowPrivateRequests()) {
            return;
        }

        $resolved = self::resolve($host);
        if (self::matchesAllowList($host, $port, $resolved, $allowHosts)) {
            return;
        }
        foreach ($resolved as $ip) {
            if (self::isBlockedAddress($ip)) {
                self::logBlock($host, $ip);
                throw new SsrfError(
                    "Blocked request to private/internal address {$ip} (host {$host}): "
                    . 'set ' . self::ALLOW_PRIVATE_ENV . '=true to allow, or pass an allow-list.'
                );
            }
        }
    }

    /**
     * Resolve a host to its IP address(es). An IP literal resolves to itself.
     *
     * @return array<int,string>
     * @throws SsrfError when the host cannot be resolved
     */
    private static function resolve(string $host): array
    {
        if (@inet_pton($host) !== false) {
            return [$host];
        }
        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }
        if ($ips === []) {
            $one = @gethostbyname($host);
            if (is_string($one) && $one !== '' && $one !== $host) {
                $ips[] = $one;
            }
        }
        if ($ips === []) {
            throw new SsrfError("Blocked request to {$host}: cannot resolve host");
        }
        return $ips;
    }

    /**
     * @param array<int,string>      $resolved
     * @param array<int,string>|null $allowHosts
     */
    private static function matchesAllowList(string $host, int $port, array $resolved, ?array $allowHosts): bool
    {
        $hostLower = strtolower($host);
        foreach ($allowHosts ?? [] as $raw) {
            $entry = strtolower(trim((string)$raw));
            if ($entry === '') {
                continue;
            }
            if ($entry === $hostLower || $entry === "{$hostLower}:{$port}") {
                return true;
            }
            if (str_contains($entry, '/') && self::cidrContainsAny($entry, $resolved)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int,string> $resolved
     */
    private static function cidrContainsAny(string $cidr, array $resolved): bool
    {
        foreach ($resolved as $ip) {
            $packed = @inet_pton(explode('%', $ip, 2)[0]);
            if ($packed !== false && self::inCidr($packed, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /** True when the packed address (4 or 16 bytes) falls inside the CIDR. */
    private static function inCidr(string $packed, string $cidr): bool
    {
        [$netStr, $bitsStr] = array_pad(explode('/', $cidr, 2), 2, '');
        $net = @inet_pton($netStr);
        if ($net === false || strlen($net) !== strlen($packed)) {
            return false;
        }
        $bits = (int)$bitsStr;
        $wholeBytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if ($wholeBytes > 0 && substr($packed, 0, $wholeBytes) !== substr($net, 0, $wholeBytes)) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }
        $mask = 0xff << (8 - $remainder) & 0xff;
        return (ord($packed[$wholeBytes]) & $mask) === (ord($net[$wholeBytes]) & $mask);
    }

    private static function logBlock(string $host, string $ip): void
    {
        if (class_exists('\\Tina4\\Log')) {
            Log::warning("SSRF guard blocked outbound request to {$host} ({$ip})");
        }
    }
}

class SsrfError extends \RuntimeException
{
}
