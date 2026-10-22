<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Adapter;

use Rasuvaeff\LinkPreview\Adapter\AdapterFailure;
use Rasuvaeff\LinkPreview\Adapter\RutubeAdapter;
use Rasuvaeff\LinkPreview\Tests\Support\Adapters;
use Rasuvaeff\LinkPreview\Tests\Support\Http;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(RutubeAdapter::class)]
final class RutubeAdapterTest
{
    private const string ID = '5ac7c1f0a1b2c3d4e5f60718293a4b5c';

    public function readsTheVideoApi(): void
    {
        $client = Http::client(['https://rutube.ru/api/video/' . self::ID . '/?format=json' => Adapters::json(Adapters::fixture('rutube/video.json'))]);

        $metadata = (new RutubeAdapter())->preview('https://rutube.ru/video/' . self::ID . '/', Adapters::context($client))->unwrap();

        Assert::same($metadata->title, 'Видео про котов');
        Assert::same($metadata->authorName, 'Котоканал');
        Assert::same($metadata->embedUrl, 'https://rutube.ru/play/embed/' . self::ID);
        Assert::same($metadata->publishedTime?->format('c'), '2026-09-30T10:15:00+00:00');
        Assert::same($metadata->type, 'video');
    }

    #[DataProvider('unsupportedProvider')]
    public function refusesLinksThatAreNotVideos(string $url): void
    {
        $client = Http::client([]);

        Assert::same((new RutubeAdapter())->preview($url, Adapters::context($client))->error(), AdapterFailure::UnsupportedUrl);
        Assert::same(Http::requests($client), []);
    }

    public static function unsupportedProvider(): iterable
    {
        yield 'channel' => ['https://rutube.ru/channel/123/'];
        yield 'short id' => ['https://rutube.ru/video/abc/'];
        yield 'uppercase id' => ['https://rutube.ru/video/5AC7C1F0A1B2C3D4E5F60718293A4B5C/'];
        yield 'longer id' => ['https://rutube.ru/video/' . '5ac7c1f0a1b2c3d4e5f60718293a4b5c0/'];
    }

    public function acceptsShortsAndIdWithoutTrailingSlash(): void
    {
        $client = Http::sequence(Adapters::json(Adapters::fixture('rutube/video.json')));

        (new RutubeAdapter())->preview('https://rutube.ru/shorts/' . self::ID, Adapters::context($client))->unwrap();

        Assert::same(Http::requestedUrls($client), ['https://rutube.ru/api/video/' . self::ID . '/?format=json']);
    }

    public function failuresAreReported(): void
    {
        Assert::same((new RutubeAdapter())->preview('https://rutube.ru/video/' . self::ID . '/', Adapters::context(Http::client([])))->error(), AdapterFailure::Unavailable);

        $context = Adapters::context(Http::sequence(Adapters::json('{"detail":"Not found"}')));
        Assert::same((new RutubeAdapter())->preview('https://rutube.ru/video/' . self::ID . '/', $context)->error(), AdapterFailure::InvalidResponse);
    }

    public function servesItsDomains(): void
    {
        Assert::same((new RutubeAdapter())->domains(), ['rutube.ru']);
    }
}
