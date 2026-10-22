<?php

declare(strict_types=1);

use Rasuvaeff\LinkPreview\PreviewProvider;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var Closure(string): Rasuvaeff\LinkPreview\Fetch\Fetcher $fetcherFor */
$fetcherFor = require __DIR__ . '/fixture_client.php';

// GitHub, Telegram and VK are read from the page's own tags; Wikipedia and Rutube have JSON APIs.
foreach ([
    'github' => 'https://github.com/rasuvaeff/result',
    'telegram' => 'https://t.me/durov/123',
    'vk' => 'https://vk.com/video-22822305_456239018',
    'wikipedia' => 'https://en.m.wikipedia.org/wiki/HTTP',
    'rutube' => 'https://rutube.ru/video/5ac7c1f0a1b2c3d4e5f60718293a4b5c/',
] as $entry => $url) {
    echo $entry, "\n";

    $info = (new PreviewProvider(fetcher: $fetcherFor($entry)))->preview(url: $url)->unwrap();

    echo '  title: ', $info->title, "\n  site: ", $info->siteName, "\n  embed: ", $info->embedUrl ?? '-', "\n";
}
