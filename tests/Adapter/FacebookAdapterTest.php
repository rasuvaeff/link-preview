<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Adapter;

use Rasuvaeff\LinkPreview\Adapter\AdapterFailure;
use Rasuvaeff\LinkPreview\Adapter\FacebookAdapter;
use Rasuvaeff\LinkPreview\Tests\Support\Adapters;
use Rasuvaeff\LinkPreview\Tests\Support\Http;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(FacebookAdapter::class)]
final class FacebookAdapterTest
{
    #[DataProvider('resourceProvider')]
    public function picksTheOEmbedResource(string $url, string $resource): void
    {
        $client = Http::sequence(Adapters::json(Adapters::fixture('facebook/oembed.json')));

        (new FacebookAdapter(accessToken: 'secret-token'))->preview($url, Adapters::context($client));

        Assert::same(Http::requestedUrls($client), ['https://graph.facebook.com/v21.0/' . $resource . '?url=' . rawurlencode($url) . '&omit_script=true']);
    }

    public static function resourceProvider(): iterable
    {
        yield 'post' => ['https://www.facebook.com/natgeo/posts/123456789', 'oembed_post'];
        yield 'permalink' => ['https://www.facebook.com/permalink.php?story_fbid=1&id=2', 'oembed_post'];
        yield 'photo' => ['https://www.facebook.com/natgeo/photos/a.1/2/', 'oembed_post'];
        yield 'video' => ['https://www.facebook.com/natgeo/videos/123456789/', 'oembed_video'];
        yield 'watch' => ['https://www.facebook.com/watch/?v=1', 'oembed_video'];
        yield 'reel' => ['https://www.facebook.com/reel/123', 'oembed_video'];
        yield 'fb.watch' => ['https://fb.watch/abc123/', 'oembed_video'];
        yield 'page' => ['https://www.facebook.com/natgeo', 'oembed_page'];
        yield 'page with a post-like name' => ['https://www.facebook.com/postsandvideos', 'oembed_page'];
    }

    #[DataProvider('noTokenProvider')]
    public function withoutATokenItRefusesAndAsksNothing(?string $token): void
    {
        $client = Http::client([]);

        $result = (new FacebookAdapter(accessToken: $token))->preview('https://www.facebook.com/natgeo', Adapters::context($client));

        Assert::same($result->error(), AdapterFailure::MissingToken);
        Assert::same(Http::requests($client), []);
    }

    public static function noTokenProvider(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
    }

    public function sendsTheTokenAsAHeaderOnly(): void
    {
        $client = Http::sequence(Adapters::json(Adapters::fixture('facebook/oembed.json')));

        $metadata = (new FacebookAdapter(accessToken: 'secret-token'))->preview('https://www.facebook.com/natgeo/posts/1', Adapters::context($client))->unwrap();

        $request = Http::requests($client)[0];
        Assert::same($request->getHeaderLine('Authorization'), 'Bearer secret-token');
        Assert::false(str_contains((string) $request->getUri(), 'secret-token'));
        Assert::same($metadata->authorName, 'National Geographic');
    }

    public function failuresAreReported(): void
    {
        Assert::same((new FacebookAdapter(accessToken: 'x'))->preview('https://www.facebook.com/a', Adapters::context(Http::client([])))->error(), AdapterFailure::Unavailable);
    }

    public function servesItsDomains(): void
    {
        Assert::same((new FacebookAdapter())->domains(), ['facebook.com', 'fb.com', 'fb.watch']);
    }
}
