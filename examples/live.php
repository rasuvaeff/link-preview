<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\Fetch\UrlPolicy;
use Rasuvaeff\LinkPreview\Fetch\UserAgentMap;
use Rasuvaeff\LinkPreview\Limits;
use Rasuvaeff\LinkPreview\PreviewProvider;
use Rasuvaeff\PinnedHttpClient\AddressPolicy;
use Rasuvaeff\PinnedHttpClient\PinnedClient;

require dirname(__DIR__) . '/vendor/autoload.php';

$url = getenv('LINK_PREVIEW_URL');

if ($url === false || $url === '') {
    echo "skipped: set LINK_PREVIEW_URL=https://example.com/ to fetch a real page\n";

    return;
}

$policy = new UrlPolicy();

// rasuvaeff/pinned-http-client when it is installed (DNS pinning), a plain Guzzle client otherwise
$client = class_exists(PinnedClient::class)
    ? new PinnedClient(
        policy: new class ($policy) implements AddressPolicy {
            public function __construct(private UrlPolicy $policy) {}

            public function allows(string $address): bool
            {
                return $this->policy->allowsAddress($address);
            }
        },
        timeoutSeconds: 5.0,
        connectTimeoutSeconds: 3.0,
        maxBodyBytes: 1_048_577,
    )
    : new Client([
        'allow_redirects' => false,
        'http_errors' => false,
        'timeout' => 5.0,
        'connect_timeout' => 3.0,
    ]);

$provider = new PreviewProvider(
    fetcher: new Fetcher(
        client: $client,
        requestFactory: new HttpFactory(),
        policy: $policy,
        userAgents: UserAgentMap::unfurler(['t.me' => 'TelegramBot (like TwitterBot)']),
        maxRedirects: 5,
        maxBodyBytes: 1_048_576,
        chainBudgetSeconds: 10.0,
    ),
    limits: new Limits(titleLength: 120, descriptionLength: 300, httpsImagesOnly: true),
);

$result = $provider->preview(url: $url);

if ($result->isErr()) {
    $failure = $result->error();
    fwrite(STDERR, 'No preview: ' . $failure?->reason->value . ($failure?->isTransient() === true ? ' (transient)' : '') . "\n");
    exit(1);
}

echo json_encode($result->unwrap()->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
