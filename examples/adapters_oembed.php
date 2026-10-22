<?php

declare(strict_types=1);

use Rasuvaeff\LinkPreview\Adapter\AdapterRegistry;
use Rasuvaeff\LinkPreview\PreviewProvider;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var Closure(string): Rasuvaeff\LinkPreview\Fetch\Fetcher $fetcherFor */
$fetcherFor = require __DIR__ . '/fixture_client.php';

// YouTube, TikTok, Twitter/X and Twitch are asked through oEmbed; the page itself is not needed.
foreach ([
    'youtube' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'tiktok' => 'https://www.tiktok.com/@scout2015/video/6718335390845095173',
    'twitter' => 'https://x.com/jack/status/20',
    'twitch' => 'https://www.twitch.tv/shroud',
] as $entry => $url) {
    echo $entry, "\n";

    $provider = new PreviewProvider(
        fetcher: $fetcherFor($entry),
        adapters: AdapterRegistry::standard(twitchParents: ['example.com']),
    );
    $info = $provider->preview(url: $url)->unwrap();

    echo '  title: ', $info->title ?? $info->description, "\n  author: ", $info->authorName, "\n  embed: ", $info->embedUrl ?? '-', "\n";
}
