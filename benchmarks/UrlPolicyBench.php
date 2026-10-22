<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Benchmarks;

use Rasuvaeff\LinkPreview\Fetch\UrlPolicy;
use Testo\Bench;

/**
 * An early refusal (scheme) against the full path: IDN conversion, and an IPv6
 * literal that has to be unwrapped.
 */
final class UrlPolicyBench
{
    #[Bench(
        callables: [
            'idn_host' => [self::class, 'idnHost'],
            'embedded_ipv6_rejected' => [self::class, 'embeddedIpv6Rejected'],
            'metadata_endpoint_rejected' => [self::class, 'metadataEndpointRejected'],
        ],
        calls: 10_000,
        iterations: 5,
    )]
    public static function schemeRejected(): bool
    {
        return (new UrlPolicy())->check(url: 'ftp://example.com/')->isErr();
    }

    public static function idnHost(): bool
    {
        return (new UrlPolicy())->check(url: 'https://münchen.de/x')->isOk();
    }

    public static function embeddedIpv6Rejected(): bool
    {
        return (new UrlPolicy())->check(url: 'http://[::ffff:7f00:1]/')->isErr();
    }

    public static function metadataEndpointRejected(): bool
    {
        return (new UrlPolicy())->check(url: 'http://169.254.169.254/latest/meta-data/')->isErr();
    }
}
