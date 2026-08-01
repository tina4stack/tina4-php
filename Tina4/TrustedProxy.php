<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Which upstream hops are allowed to speak for a client (ADR-0019).
 *
 * X-Forwarded-For is written by whoever sends it. Reading it unconditionally
 * lets any client choose its own rate-limit bucket, and - worse - choose
 * SOMEONE ELSE'S, which is a starvation primitive against a third party. So
 * the forwarding headers are believed only when the raw socket peer is a
 * proxy the operator has explicitly declared.
 *
 * Configured by TINA4_TRUSTED_PROXIES: comma-separated exact addresses and/or
 * CIDR ranges, IPv4 and IPv6, e.g. "10.0.0.0/8, 192.168.1.5, ::1, fd00::/8".
 * Empty or unset means trust NOTHING, which is the secure default.
 */
class TrustedProxy
{
    /** @var string|null The raw env value the cache was built from. */
    private static ?string $cachedRaw = null;

    /** @var array<array{0: string, 1: int}> [packed network address, prefix bits] */
    private static array $cachedNetworks = [];

    /**
     * The configured trusted-proxy networks, parsed once per distinct config value.
     *
     * @return array<array{0: string, 1: int}>
     */
    public static function networks(): array
    {
        $raw = (string)DotEnv::getEnv('TINA4_TRUSTED_PROXIES', '');
        if ($raw === self::$cachedRaw) {
            return self::$cachedNetworks;
        }

        $networks = [];
        foreach (explode(',', $raw) as $entry) {
            $entry = self::normalise($entry);
            if ($entry === '') {
                continue;
            }

            $parsed = self::parseNetwork($entry);
            if ($parsed === null) {
                // Loud, and exactly once per distinct config value (the cache
                // below means this parse runs once). A silently-skipped entry
                // would leave a real proxy untrusted, which looks like the app
                // over-limiting every client - a very expensive typo to debug.
                Log::error(
                    "TINA4_TRUSTED_PROXIES: ignoring invalid entry '{$entry}' - "
                    . 'expected an IP address or CIDR range, e.g. 10.0.0.0/8 or 192.168.1.5'
                );
                continue;
            }
            $networks[] = $parsed;
        }

        self::$cachedRaw = $raw;
        self::$cachedNetworks = $networks;
        return $networks;
    }

    /**
     * Is this address a configured trusted proxy?
     */
    public static function isTrusted(string $address): bool
    {
        $networks = self::networks();
        if ($networks === []) {
            return false;
        }

        $packed = self::pack(self::normalise($address));
        if ($packed === null) {
            return false;
        }

        foreach ($networks as [$network, $bits]) {
            if (strlen($network) === strlen($packed) && self::prefixMatches($packed, $network, $bits)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reset the parsed cache. Test hook - config normally changes only at boot.
     */
    public static function reset(): void
    {
        self::$cachedRaw = null;
        self::$cachedNetworks = [];
    }

    /**
     * Strip the decorations a peer address can arrive with: the "[::1]"
     * bracket form and an IPv6 zone id ("fe80::1%eth0").
     */
    private static function normalise(string $value): string
    {
        $value = trim($value);
        if ($value !== '' && $value[0] === '[' && ($close = strpos($value, ']')) !== false) {
            $value = substr($value, 1, $close - 1);
        }
        if (($pct = strpos($value, '%')) !== false) {
            $value = substr($value, 0, $pct);
        }
        return $value;
    }

    /**
     * Pack an address to its binary form, unmapping IPv4-in-IPv6.
     *
     * A peer arriving as ::ffff:10.0.0.1 must match an allow-list entry of
     * 10.0.0.0/8 - dual-stack listeners hand out the mapped form routinely.
     */
    private static function pack(string $address): ?string
    {
        if ($address === '') {
            return null;
        }
        $packed = @inet_pton($address);
        if ($packed === false) {
            return null;
        }
        // ::ffff:a.b.c.d packs to 10 zero bytes, 2 0xff bytes, then the 4 IPv4 bytes.
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\x00", 10) . "\xff\xff")) {
            return substr($packed, 12);
        }
        return $packed;
    }

    /**
     * Parse "addr" or "addr/prefix" into [packed address, prefix bits].
     *
     * @return array{0: string, 1: int}|null
     */
    private static function parseNetwork(string $entry): ?array
    {
        $bits = null;
        if (str_contains($entry, '/')) {
            [$entry, $suffix] = explode('/', $entry, 2);
            if ($suffix === '' || !ctype_digit($suffix)) {
                return null;
            }
            $bits = (int)$suffix;
        }

        $packed = self::pack(trim($entry));
        if ($packed === null) {
            return null;
        }

        $maxBits = strlen($packed) * 8;
        if ($bits === null) {
            $bits = $maxBits;
        }
        if ($bits < 0 || $bits > $maxBits) {
            return null;
        }
        return [$packed, $bits];
    }

    /**
     * Do the first $bits bits of two packed addresses agree?
     */
    private static function prefixMatches(string $candidate, string $network, int $bits): bool
    {
        $wholeBytes = intdiv($bits, 8);
        if ($wholeBytes > 0 && strncmp($candidate, $network, $wholeBytes) !== 0) {
            return false;
        }

        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;
        return (ord($candidate[$wholeBytes]) & $mask) === (ord($network[$wholeBytes]) & $mask);
    }
}
