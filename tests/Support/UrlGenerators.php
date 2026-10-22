<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Support;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;

/**
 * Generators and the reference predicate behind the {@see \Rasuvaeff\LinkPreview\Fetch\UrlPolicy}
 * properties. The reference is written the other way round from the policy: integer
 * intervals instead of CIDR masks.
 *
 * @internal
 */
final readonly class UrlGenerators
{
    private const array BLOCKED_INTERVALS = [
        ['0.0.0.0', '0.255.255.255'],
        ['10.0.0.0', '10.255.255.255'],
        ['100.64.0.0', '100.127.255.255'],
        ['127.0.0.0', '127.255.255.255'],
        ['169.254.0.0', '169.254.255.255'],
        ['172.16.0.0', '172.31.255.255'],
        ['192.0.0.0', '192.0.0.255'],
        ['192.0.2.0', '192.0.2.255'],
        ['192.168.0.0', '192.168.255.255'],
        ['198.18.0.0', '198.19.255.255'],
        ['198.51.100.0', '198.51.100.255'],
        ['203.0.113.0', '203.0.113.255'],
        ['224.0.0.0', '255.255.255.255'],
    ];

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function endpoint(): array
    {
        return [
            ...self::octets(),
            'scheme' => Gen::elements(['http', 'https', 'ftp', 'file', 'gopher']),
            'port' => Gen::elements([null, null, 80, 443, 8080, 22, 6379]),
        ];
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function embedded(): array
    {
        return [
            ...self::octets(),
            'form' => Gen::elements(['compatible', 'mapped', 'nat64', 'sixToFour']),
        ];
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function normalizable(): array
    {
        return [
            'scheme' => Gen::elements(['http', 'https', 'HTTP', 'Https']),
            'host' => Gen::stringFrom('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-', 1, 12),
            'port' => Gen::elements([null, 80, 443]),
            'path' => Gen::stringFrom('abc/._-', 0, 12),
            'query' => Gen::elements([null, '', 'a=b', 'a=1&b=2']),
            'fragment' => Gen::elements([null, 'top']),
        ];
    }

    public static function isBlockedIpv4(int $a, int $b, int $c, int $d): bool
    {
        $address = ip2long($a . '.' . $b . '.' . $c . '.' . $d);

        foreach (self::BLOCKED_INTERVALS as [$from, $to]) {
            if ($address >= ip2long($from) && $address <= ip2long($to)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    private static function octets(): array
    {
        return [
            'a' => Gen::frequency([
                [4, Gen::elements([0, 10, 100, 127, 169, 172, 192, 198, 203, 224, 240, 255])],
                [2, Gen::elements([1, 8, 23, 44, 93, 151, 185, 212])],
                [1, Gen::intBetween(0, 255)],
            ]),
            'b' => Gen::frequency([
                [2, Gen::elements([0, 2, 16, 18, 19, 31, 32, 51, 63, 64, 100, 127, 128, 168, 254])],
                [2, Gen::intBetween(0, 255)],
            ]),
            'c' => Gen::frequency([
                [2, Gen::elements([0, 2, 100, 113])],
                [1, Gen::intBetween(0, 255)],
            ]),
            'd' => Gen::intBetween(0, 255),
        ];
    }
}
