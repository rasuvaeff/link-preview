<?php

declare(strict_types=1);

use Rasuvaeff\LinkPreview\Adapter\AdapterRegistry;
use Rasuvaeff\LinkPreview\PreviewProvider;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var Closure(string): Rasuvaeff\LinkPreview\Fetch\Fetcher $fetcherFor */
$fetcherFor = require __DIR__ . '/fixture_client.php';

$url = 'https://www.instagram.com/p/CxYz123AbC/';

echo "without a token:\n";
$result = (new PreviewProvider(fetcher: $fetcherFor('instagram')))->preview(url: $url);
echo '  result: ', $result->error()?->value, "\n";

echo "with a token (read it from your secrets store; '<token>' stands for it):\n";
$provider = new PreviewProvider(
    fetcher: $fetcherFor('instagram'),
    adapters: AdapterRegistry::standard(instagramToken: '<token>', facebookToken: '<token>'),
);
$info = $provider->preview(url: $url)->unwrap();
echo '  author: ', $info->authorName, "\n  site: ", $info->siteName, "\n";
