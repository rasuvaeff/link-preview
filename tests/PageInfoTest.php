<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Rasuvaeff\LinkPreview\PageInfo;
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
}
