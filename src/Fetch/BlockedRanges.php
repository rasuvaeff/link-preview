<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Fetch;

use Rasuvaeff\LinkPreview\Url\Cidr;

/**
 * Address ranges a preview must never connect to: everything that is not the
 * public internet.
 *
 * PHP's own `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` misses
 * carrier-grade NAT (100.64/10), the benchmark and documentation ranges and
 * multicast, so the table is explicit.
 *
 * @internal
 */
final class BlockedRanges
{
    /** `::/96` — an IPv4 address in the low 32 bits (IPv4-compatible). */
    private const string COMPATIBLE_PREFIX = "\0\0\0\0\0\0\0\0\0\0\0\0";

    /** `::ffff:0:0/96` — IPv4-mapped. */
    private const string MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    /** `64:ff9b::/96` — NAT64 well-known prefix. */
    private const string NAT64_PREFIX = "\x00\x64\xff\x9b\0\0\0\0\0\0\0\0";

    private const array IPV4 = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    private const array IPV6 = [
        '::/128',
        '::1/128',
        '100::/64',
        '64:ff9b:1::/48',
        '2001::/32',
        '2001:db8::/32',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ];

    private function __construct() {}

    /**
     * @param string $packed an address in its `inet_pton()` form
     */
    public static function contains(string $packed): bool
    {
        if (\strlen($packed) === 4) {
            return self::inAny(self::IPV4, $packed);
        }

        if (self::inAny(self::IPV6, $packed)) {
            return true;
        }

        $embedded = self::embeddedIpv4($packed);

        return $embedded !== null && self::inAny(self::IPV4, $embedded);
    }

    /**
     * The IPv4 address an IPv6 one carries, which is where a connection to it
     * actually goes: `::a.b.c.d`, `::ffff:a.b.c.d`, `64:ff9b::a.b.c.d` and the
     * 6to4 form `2002:aabb:ccdd::`.
     *
     * `::7f00:1` is the same address as `::127.0.0.1`, but PHP's validators see
     * a plain IPv6 address in it.
     */
    private static function embeddedIpv4(string $packed): ?string
    {
        $prefix = substr($packed, 0, 12);

        if ($prefix === self::COMPATIBLE_PREFIX || $prefix === self::MAPPED_PREFIX || $prefix === self::NAT64_PREFIX) {
            return substr($packed, 12);
        }

        if (str_starts_with($packed, "\x20\x02")) {
            return substr($packed, 2, 4);
        }

        return null;
    }

    /**
     * @param list<string> $rules
     */
    private static function inAny(array $rules, string $packed): bool
    {
        foreach ($rules as $rule) {
            if (Cidr::parse($rule)?->contains($packed) === true) {
                return true;
            }
        }

        return false;
    }
}
