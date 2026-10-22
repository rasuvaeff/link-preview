<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Adapter;

use GuzzleHttp\Psr7\Response;
use Rasuvaeff\LinkPreview\Adapter\AdapterFailure;
use Rasuvaeff\LinkPreview\Adapter\TikTokAdapter;
use Rasuvaeff\LinkPreview\Tests\Support\Adapters;
use Rasuvaeff\LinkPreview\Tests\Support\Http;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(TikTokAdapter::class)]
final class TikTokAdapterTest
{
    public function previewsThroughOEmbed(): void
    {
        $url = 'https://www.tiktok.com/@scout2015/video/6718335390845095173';
        $endpoint = 'https://www.tiktok.com/oembed?url=https%3A%2F%2Fwww.tiktok.com%2F%40scout2015%2Fvideo%2F6718335390845095173';
        $client = Http::client([$endpoint => Adapters::json(Adapters::fixture('tiktok/oembed.json'))]);

        $metadata = (new TikTokAdapter())->preview($url, Adapters::context($client))->unwrap();

        Assert::same($metadata->title, 'Funny cat compilation');
        Assert::same($metadata->authorName, 'Scout');
        Assert::same($metadata->siteName, 'TikTok');
        Assert::same(Http::requestedUrls($client), [$endpoint]);
    }

    public function failuresAreReported(): void
    {
        $context = Adapters::context(Http::sequence(new Response(500)));
        Assert::same((new TikTokAdapter())->preview('https://www.tiktok.com/@a/video/1', $context)->error(), AdapterFailure::Unavailable);

        $context = Adapters::context(Http::sequence(Adapters::json('[]')));
        Assert::same((new TikTokAdapter())->preview('https://www.tiktok.com/@a/video/1', $context)->error(), AdapterFailure::InvalidResponse);
    }

    public function servesItsDomains(): void
    {
        Assert::same((new TikTokAdapter())->domains(), ['tiktok.com']);
    }
}
