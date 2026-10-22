<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\Fetch\UserAgentMap;
use Rasuvaeff\LinkPreview\Limits;
use Rasuvaeff\LinkPreview\PreviewProvider;

require dirname(__DIR__) . '/vendor/autoload.php';

$url = getenv('LINK_PREVIEW_URL');

if ($url === false || $url === '') {
    echo "skipped: set LINK_PREVIEW_URL=https://example.com/ to fetch a real page\n";

    return;
}

$client = new Client([
    'allow_redirects' => false,
    'http_errors' => false,
    'timeout' => 5.0,
    'connect_timeout' => 3.0,
]);

$provider = new PreviewProvider(
    fetcher: new Fetcher(
        client: $client,
        requestFactory: new HttpFactory(),
        userAgents: new UserAgentMap(byHost: ['t.me' => 'TelegramBot (like TwitterBot)']),
        maxRedirects: 5,
        maxBodyBytes: 1_048_576,
        chainBudgetSeconds: 10.0,
    ),
    limits: new Limits(titleLength: 120, descriptionLength: 300, httpsImagesOnly: true),
);

$result = $provider->preview(url: $url);

if ($result->isErr()) {
    fwrite(STDERR, 'No preview: ' . $result->error()?->value . "\n");
    exit(1);
}

echo json_encode($result->unwrap()->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
