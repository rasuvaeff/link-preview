<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Adapter;

use Rasuvaeff\LinkPreview\Adapter\AdapterFailure;
use Rasuvaeff\LinkPreview\Adapter\YouTubeAdapter;
use Rasuvaeff\LinkPreview\Tests\Support\Adapters;
use Rasuvaeff\LinkPreview\Tests\Support\Http;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(YouTubeAdapter::class)]
final class YouTubeAdapterTest
{
    private const string ENDPOINT = 'https://www.youtube.com/oembed?url=https%3A%2F%2Fwww.youtube.com%2Fwatch%3Fv%3DdQw4w9WgXcQ&format=json';

    public function previewsThroughOEmbed(): void
    {
        $client = Http::client([self::ENDPOINT => Adapters::json(Adapters::fixture('youtube/oembed.json'))]);

        $metadata = (new YouTubeAdapter())->preview('https://www.youtube.com/watch?v=dQw4w9WgXcQ', Adapters::context($client))->unwrap();

        Assert::same($metadata->title, 'Never Gonna Give You Up (Official Video)');
        Assert::same($metadata->authorName, 'Rick Astley');
        Assert::same($metadata->siteName, 'YouTube');
        Assert::same($metadata->image, 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg');
        Assert::same($metadata->embedUrl, 'https://www.youtube.com/embed/dQw4w9WgXcQ');
        Assert::same(Http::requestedUrls($client), [self::ENDPOINT]);
    }

    #[DataProvider('videoUrlProvider')]
    public function buildsTheEmbedFromTheVideoId(string $url, ?string $embed): void
    {
        $client = Http::client([]);
        $routes = Http::sequence(Adapters::json(Adapters::fixture('youtube/oembed.json')));

        $metadata = (new YouTubeAdapter())->preview($url, Adapters::context($routes))->unwrap();

        Assert::same($metadata->embedUrl, $embed ?? 'https://www.youtube.com/embed/dQw4w9WgXcQ?feature=oembed');
    }

    public static function videoUrlProvider(): iterable
    {
        yield 'watch' => ['https://www.youtube.com/watch?v=abcdefghijk&t=5', 'https://www.youtube.com/embed/abcdefghijk'];
        yield 'short link' => ['https://youtu.be/abcdefghijk?si=x', 'https://www.youtube.com/embed/abcdefghijk'];
        yield 'shorts' => ['https://www.youtube.com/shorts/abcdefghijk', 'https://www.youtube.com/embed/abcdefghijk'];
        yield 'live' => ['https://www.youtube.com/live/abcdefghijk', 'https://www.youtube.com/embed/abcdefghijk'];
        yield 'embed' => ['https://www.youtube.com/embed/abcdefghijk', 'https://www.youtube.com/embed/abcdefghijk'];
        yield 'id with dash and underscore' => ['https://youtu.be/a-_Z09xyz12', 'https://www.youtube.com/embed/a-_Z09xyz12'];
        yield 'channel falls back to the service embed' => ['https://www.youtube.com/@RickAstleyYT', null];
        yield 'id of the wrong length' => ['https://www.youtube.com/watch?v=short', null];
        yield 'id with a bad character' => ['https://www.youtube.com/watch?v=abcdefghij!', null];
    }

    public function serviceFailureIsReported(): void
    {
        Assert::same((new YouTubeAdapter())->preview('https://youtu.be/abcdefghijk', Adapters::context(Http::client([])))->error(), AdapterFailure::Unavailable);
    }

    public function unreadableAnswerIsReported(): void
    {
        $client = Http::sequence(Adapters::json('not json'));

        Assert::same((new YouTubeAdapter())->preview('https://youtu.be/abcdefghijk', Adapters::context($client))->error(), AdapterFailure::InvalidResponse);
    }

    public function servesItsDomains(): void
    {
        Assert::same((new YouTubeAdapter())->domains(), ['youtube.com', 'youtu.be', 'youtube-nocookie.com']);
    }
}
