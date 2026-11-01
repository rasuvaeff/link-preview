<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Rasuvaeff\LinkPreview\PageInfo;
use Rasuvaeff\LinkPreview\Tests\Support\PageInfoGenerators;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(PageInfo::class)]
final class PageInfoTest
{
    public function exposesEveryField(): void
    {
        $time = new DateTimeImmutable('2026-10-01T12:30:00+03:00');
        $info = new PageInfo(
            url: 'https://example.com/a',
            title: 'T',
            description: 'D',
            image: 'https://example.com/i.png',
            favicon: 'http://example.com/f.ico',
            siteName: 'S',
            language: 'en',
            authorName: 'A',
            publishedTime: $time,
            type: 'article',
            embedUrl: 'https://example.com/embed/1',
            canonicalUrl: 'https://example.com/canonical',
            imageWidth: 1200,
            imageHeight: 630,
            providerName: 'P',
        );

        Assert::same($info->toArray(), [
            'url' => 'https://example.com/a',
            'title' => 'T',
            'description' => 'D',
            'image' => 'https://example.com/i.png',
            'favicon' => 'http://example.com/f.ico',
            'siteName' => 'S',
            'language' => 'en',
            'authorName' => 'A',
            'publishedTime' => '2026-10-01T12:30:00+03:00',
            'type' => 'article',
            'embedUrl' => 'https://example.com/embed/1',
            'canonicalUrl' => 'https://example.com/canonical',
            'imageWidth' => 1200,
            'imageHeight' => 630,
            'providerName' => 'P',
        ]);
    }

    public function everythingButTheUrlIsOptional(): void
    {
        Assert::same((new PageInfo(url: 'https://example.com/'))->toArray(), [
            'url' => 'https://example.com/',
            'title' => null,
            'description' => null,
            'image' => null,
            'favicon' => null,
            'siteName' => null,
            'language' => null,
            'authorName' => null,
            'publishedTime' => null,
            'type' => null,
            'embedUrl' => null,
            'canonicalUrl' => null,
            'imageWidth' => null,
            'imageHeight' => null,
            'providerName' => null,
        ]);
    }

    #[DataProvider('invalidProvider')]
    public function rejectsUrlsThatAreNotAbsoluteHttp(string $url, ?string $image, ?string $favicon, ?string $embedUrl = null): void
    {
        Expect::exception(InvalidArgumentException::class);

        new PageInfo(url: $url, image: $image, favicon: $favicon, embedUrl: $embedUrl);
    }

    public static function invalidProvider(): iterable
    {
        yield 'relative page url' => ['/a', null, null];
        yield 'empty page url' => ['', null, null];
        yield 'javascript page url' => ['javascript:alert(1)', null, null];
        yield 'relative image' => ['https://example.com/', '/i.png', null];
        yield 'data image' => ['https://example.com/', 'data:image/png;base64,AA', null];
        yield 'ftp favicon' => ['https://example.com/', null, 'ftp://example.com/f.ico'];
        yield 'hostless image' => ['https://example.com/', 'https://', null];
        yield 'relative embed' => ['https://example.com/', null, null, '/embed'];
    }

    public function aRelativeCanonicalUrlIsRejected(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new PageInfo(url: 'https://example.com/', canonicalUrl: '/canonical');
    }

    #[DataProvider('invalidDimensionProvider')]
    public function dimensionsArePositiveAndNeedAnImage(?string $image, ?int $width, ?int $height): void
    {
        Expect::exception(InvalidArgumentException::class);

        new PageInfo(url: 'https://example.com/', image: $image, imageWidth: $width, imageHeight: $height);
    }

    public static function invalidDimensionProvider(): iterable
    {
        yield 'width without an image' => [null, 10, null];
        yield 'height without an image' => [null, null, 10];
        yield 'zero width' => ['https://example.com/i.png', 0, 10];
        yield 'negative height' => ['https://example.com/i.png', 10, -1];
    }

    public function dimensionsOfOneAreFine(): void
    {
        $info = new PageInfo(url: 'https://example.com/', image: 'https://example.com/i.png', imageWidth: 1, imageHeight: 1);

        Assert::same([$info->imageWidth, $info->imageHeight], [1, 1]);
    }

    public function fromArrayReadsWhatToArrayWrote(): void
    {
        $data = [
            'url' => 'https://example.com/a',
            'title' => 'T',
            'description' => 'D',
            'image' => 'https://example.com/i.png',
            'favicon' => 'http://example.com/f.ico',
            'siteName' => 'S',
            'language' => 'en',
            'authorName' => 'A',
            'publishedTime' => '2026-10-01T12:30:00+03:00',
            'type' => 'article',
            'embedUrl' => 'https://example.com/embed/1',
            'canonicalUrl' => 'https://example.com/c',
            'imageWidth' => 1200,
            'imageHeight' => 630,
            'providerName' => 'P',
        ];

        $info = PageInfo::fromArray($data);

        Assert::same($info->toArray(), $data);
        Assert::same($info->publishedTime?->getOffset(), 10_800);
    }

    public function fromArrayReadsAnArrayOfAnOlderVersion(): void
    {
        $info = PageInfo::fromArray(['url' => 'https://example.com/', 'title' => 'Old', 'unknown' => 1]);

        Assert::same($info->title, 'Old');
        Assert::null($info->canonicalUrl);
        Assert::null($info->imageWidth);
        Assert::null($info->providerName);
    }

    #[DataProvider('invalidArrayProvider')]
    public function fromArrayRejectsWhatToArrayCannotHaveWritten(array $data): void
    {
        Expect::exception(InvalidArgumentException::class);

        PageInfo::fromArray($data);
    }

    public static function invalidArrayProvider(): iterable
    {
        yield 'no url' => [[]];
        yield 'empty url' => [['url' => '']];
        yield 'url not a string' => [['url' => 1]];
        yield 'relative url' => [['url' => '/a']];
        yield 'title not a string' => [['url' => 'https://example.com/', 'title' => 1]];
        yield 'provider not a string' => [['url' => 'https://example.com/', 'providerName' => ['P']]];
        yield 'width as a string' => [['url' => 'https://example.com/', 'image' => 'https://example.com/i.png', 'imageWidth' => '10']];
        yield 'zero height' => [['url' => 'https://example.com/', 'image' => 'https://example.com/i.png', 'imageHeight' => 0]];
        yield 'time not ATOM' => [['url' => 'https://example.com/', 'publishedTime' => '2026-10-01']];
        yield 'time not a string' => [['url' => 'https://example.com/', 'publishedTime' => 1_700_000_000]];
    }

    /**
     * `toArray()` and `fromArray()` are inverse: what is stored reads back as the same array.
     */
    #[Property(runs: 200, generators: [PageInfoGenerators::class, 'pageArray'])]
    public function arraysRoundTrip(array $data): void
    {
        $info = PageInfo::fromArray($data);

        Assert::same($info->toArray(), $data);
        Assert::same(PageInfo::fromArray($info->toArray())->toArray(), $info->toArray());
        Assert::same(array_keys($info->toArray()), PageInfoGenerators::keys());

        Classify::cover(condition: $data['image'] !== null && $data['imageWidth'] !== null, label: 'image with a width', minPercent: 10.0);
        Classify::cover(condition: $data['publishedTime'] !== null, label: 'with a time', minPercent: 20.0);
    }
}
