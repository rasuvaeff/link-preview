<?php

declare(strict_types=1);

// DNS pinning for the Fetcher: rasuvaeff/pinned-http-client resolves the host, checks every address and pins the
// connection to the checked addresses (CURLOPT_RESOLVE). The address rules come from the same UrlPolicy that checks the
// URLs, so the two cannot disagree. Nothing is fetched here: every address below is refused before a socket is opened.
//
// Needs `composer require rasuvaeff/pinned-http-client`; without it the script says so and exits.

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Psr7\HttpFactory;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\Fetch\UrlPolicy;
use Rasuvaeff\PinnedHttpClient\AddressPolicy;
use Rasuvaeff\PinnedHttpClient\PinnedClient;

if (!class_exists(PinnedClient::class)) {
    echo "Install rasuvaeff/pinned-http-client to run this example.\n";

    return;
}

final readonly class UrlPolicyAddresses implements AddressPolicy
{
    public function __construct(private UrlPolicy $policy) {}

    #[\Override]
    public function allows(string $address): bool
    {
        return $this->policy->check('http://' . (str_contains($address, ':') ? '[' . $address . ']' : $address) . '/')->isOk();
    }
}

$policy = new UrlPolicy();
$fetcher = new Fetcher(
    client: new PinnedClient(policy: new UrlPolicyAddresses($policy), resolver: static fn(string $host): array => $host === 'rebound.test' ? ['127.0.0.1'] : []),
    requestFactory: new HttpFactory(),
    policy: $policy,
);

foreach (['http://127.0.0.1/', 'http://169.254.169.254/latest/meta-data/', 'http://rebound.test/', 'http://nowhere.test/'] as $url) {
    $result = $fetcher->fetch($url);

    echo str_pad($url, 45), $result->isErr() ? 'refused (' . $result->error()->name . ')' : 'fetched', "\n";
}
