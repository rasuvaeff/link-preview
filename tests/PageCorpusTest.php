<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\PreviewFailure;
use Rasuvaeff\LinkPreview\PreviewProvider;
use Rasuvaeff\LinkPreview\Tests\Support\Fixtures;
use Rasuvaeff\LinkPreview\Tests\Support\Http;
use Testo\Assert;
use Testo\CoversNothing;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * The page corpus: HTML as sites really serve it, and what a preview must make of it.
 */
#[Test]
#[CoversNothing]
final class PageCorpusTest
{
    /**
     * @param array<string, string|null> $expected
     */
    #[DataProvider('pageProvider')]
    public function previews(string $fixture, string $pageUrl, ?string $charset, array $expected): void
    {
        $info = $this->provider()->fromHtml(html: Fixtures::read($fixture), pageUrl: $pageUrl, charset: $charset)->unwrap();

        Assert::same($info->toArray(), array_replace([
            'url' => $pageUrl,
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
        ], $expected));
    }

    public static function pageProvider(): iterable
    {
        yield 'OpenGraph beats Twitter and HTML' => ['og-article.html', 'https://example.com/news/article.html', null, [
            'url' => 'https://example.com/articles/42',
            'title' => 'The OpenGraph title & more',
            'description' => 'Line one. Line two.',
            'image' => 'https://example.com/img/cover.jpg',
            'favicon' => 'https://example.com/favicon.ico',
            'siteName' => 'Example News',
            'language' => 'ru-RU',
            'authorName' => 'Jane Doe',
            'publishedTime' => '2026-10-01T12:30:00+03:00',
            'type' => 'article',
        ]];
        yield 'Twitter Cards only' => ['twitter-only.html', 'https://example.com/a/b', null, [
            'title' => 'Twitter title',
            'description' => 'Twitter description',
            'image' => 'https://example.com/a/img/card.png',
        ]];
        yield 'plain HTML' => ['plain.html', 'https://example.com/de/plain.html', null, [
            'url' => 'https://example.com/de/plain',
            'title' => 'Plain page',
            'description' => 'A page with no social metadata',
            'image' => 'https://example.com/hero.jpg',
            'favicon' => 'https://example.com/fav.ico',
            'language' => 'de-AT',
            'authorName' => 'Max Mustermann',
        ]];
        yield 'relative URLs against <base>' => ['relative-urls.html', 'https://example.com/p/page', null, [
            'url' => 'https://static.example.com/canonical?x=1',
            'title' => 'Relative',
            'image' => 'https://static.example.com/assets/img/a.png',
            'favicon' => 'https://cdn.example.com/i.ico',
        ]];
        yield 'windows-1251, charset sniffed' => ['windows-1251.html', 'https://example.ru/', null, [
            'title' => 'Привет, мир',
            'description' => 'Описание страницы в старой кодировке',
        ]];
        yield 'windows-1251, charset from the transport' => ['windows-1251.html', 'https://example.ru/', 'windows-1251', [
            'title' => 'Привет, мир',
            'description' => 'Описание страницы в старой кодировке',
        ]];
        yield 'hostile image schemes' => ['hostile-images.html', 'https://example.com/h', null, [
            'title' => 'Hostile',
        ]];
        yield 'first usable image' => ['first-valid-image.html', 'https://example.com/f', null, [
            'image' => 'https://example.com/second.png',
        ]];
    }

    public function hugeValuesAreBounded(): void
    {
        $info = $this->provider()->fromHtml(html: Fixtures::read('huge.html'), pageUrl: 'https://example.com/')->unwrap();

        Assert::same(mb_strlen((string) $info->title), 200);
        Assert::true(str_ends_with((string) $info->title, '…'));
        Assert::true(str_starts_with((string) $info->title, 'Заголовок с переносом и управляющим символом длинный'));
        Assert::false(preg_match('/[\n\t\x07]/', (string) $info->title) === 1);
        Assert::same(mb_strlen((string) $info->description), 500);
        Assert::true(str_ends_with((string) $info->description, '…'));
        Assert::same(mb_strlen((string) $info->siteName), 100);
        Assert::null($info->image);
    }

    public function brokenMarkupIsReadWithoutTheScriptsContents(): void
    {
        $info = $this->provider()->fromHtml(html: Fixtures::read('broken.html'), pageUrl: 'https://example.com/')->unwrap();

        Assert::same($info->title, 'Broken markup');
        Assert::same($info->siteName, 'Still found');
        Assert::null($info->description);
        Assert::same($info->image, 'https://example.com/loose.png');
    }

    public function pageWithoutMetadataIsRefused(): void
    {
        $result = $this->provider()->fromHtml(html: Fixtures::read('none.html'), pageUrl: 'https://example.com/');

        Assert::same($result->error(), PreviewFailure::EmptyPage);
    }

    public function externalEntityDoesNotLeakAFile(): void
    {
        $result = $this->provider()->fromHtml(html: Fixtures::read('xxe.html'), pageUrl: 'https://example.com/');

        Assert::false(str_contains((string) $result->value()?->title, 'root:'));
    }

    private function provider(): PreviewProvider
    {
        return new PreviewProvider(fetcher: new Fetcher(client: Http::client([]), requestFactory: new HttpFactory()));
    }
}
