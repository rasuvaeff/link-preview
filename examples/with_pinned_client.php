<?php

declare(strict_types=1);

// DNS pinning for the Fetcher: rasuvaeff/pinned-http-client resolves the host, checks every address and pins the
// connection to the checked addresses (CURLOPT_RESOLVE). The address rules come from the same UrlPolicy that checks the
// URLs - UrlPolicy::allowsAddress() is the AddressPolicy - so the two cannot disagree. Nothing is fetched here: every
// address below is refused before a socket is opened.
//
// Needs `composer require rasuvaeff/pinned-http-client`; without it the script says so and exits.

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Psr7\HttpFactory;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\Fetch\UrlPolicy;
use Rasuvaeff\PinnedHttpClient\AddressPolicy;
use Rasuvaeff\PinnedHttpClient\PinnedClient;
use Rasuvaeff\PinnedHttpClient\Resolver;

if (!class_exists(PinnedClient::class)) {
    echo "Install rasuvaeff/pinned-http-client to run this example.\n";

    return;
}

$policy = new UrlPolicy();
$maxBodyBytes = 2_097_152;

$fetcher = new Fetcher(
    client: new PinnedClient(
        policy: new class ($policy) implements AddressPolicy {
            public function __construct(private UrlPolicy $policy) {}

            public function allows(string $address): bool
            {
                return $this->policy->allowsAddress($address);
            }
        },
        // a stand-in for SystemResolver, so that the example needs no DNS: rebound.test "resolves" to loopback
        resolver: new class implements Resolver {
            public function resolve(string $host): array
            {
                return $host === 'rebound.test' ? ['127.0.0.1'] : [];
            }
        },
        timeoutSeconds: 5.0,
        connectTimeoutSeconds: 3.0,
        // one byte above the Fetcher's limit: an oversized page comes back as TooLarge from the Fetcher,
        // while the client still never buffers more than that
        maxBodyBytes: $maxBodyBytes + 1,
    ),
    requestFactory: new HttpFactory(),
    policy: $policy,
    maxBodyBytes: $maxBodyBytes,
);

foreach (['http://127.0.0.1/', 'http://169.254.169.254/latest/meta-data/', 'http://rebound.test/', 'http://nowhere.test/'] as $url) {
    $failure = $fetcher->fetch($url)->error();

    echo str_pad($url, 45), $failure === null ? 'fetched' : 'refused (' . $failure->reason->value . ($failure->isTransient() ? ', transient' : ', permanent') . ')', "\n";
}
