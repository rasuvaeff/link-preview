<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Rasuvaeff\LinkPreview\Extract\TwitterCardsExtractor;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\Fetch\FetchFailure;
use Rasuvaeff\LinkPreview\Limits;
use Rasuvaeff\LinkPreview\PageInfo;
use Rasuvaeff\LinkPreview\PreviewFailure;
use Rasuvaeff\LinkPreview\PreviewProvider;
use Rasuvaeff\LinkPreview\Tests\Support\Fixtures;
use Rasuvaeff\LinkPreview\Tests\Support\Http;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(PreviewProvider::class)]
#[Covers(PreviewFailure::class)]
final class PreviewProviderTest
{
    public function previewsAFetchedPage(): void
    {
        $client = Http::client(['https://example.com/news/a' => Http::html(Fixtures::read('og-article.html'))]);

        $info = $this->provider($client)->preview(url: 'https://example.com/news/a')->unwrap();

        Assert::same($info->url, 'https://example.com/articles/42');
        Assert::same($info->title, 'The OpenGraph title & more');
        Assert::same($info->description, 'Line one. Line two.');
        Assert::same($info->image, 'https://example.com/img/cover.jpg');
        Assert::same($info->favicon, 'https://example.com/favicon.ico');
        Assert::same($info->siteName, 'Example News');
    }

    public function relativeReferencesResolveAgainstTheFinalUrl(): void
    {
        $client = Http::client([
            'https://short.example/x' => Http::redirect('https://example.com/deep/page.html'),
            'https://example.com/deep/page.html' => Http::html('<title>T</title><meta property="og:image" content="pic.png">'),
        ]);

        $info = $this->provider($client)->preview(url: 'https://short.example/x')->unwrap();

        Assert::same($info->url, 'https://example.com/deep/page.html');
        Assert::same($info->image, 'https://example.com/deep/pic.png');
    }

    public function transportCharsetDecodesTheBody(): void
    {
        $body = mb_convert_encoding('<title>Привет</title>', 'Windows-1251', 'UTF-8');
        $client = Http::client(['https://example.com/' => Http::html($body, 'text/html; charset=windows-1251')]);

        Assert::same($this->provider($client)->preview(url: 'https://example.com/')->unwrap()->title, 'Привет');
    }

    public function earlierExtractorsHaveHigherPriority(): void
    {
        $html = '<title>HTML</title><meta property="og:title" content="OG"><meta name="twitter:title" content="TW">';

        $default = $this->provider(Http::client([]))->fromHtml(html: $html, pageUrl: 'https://example.com/')->unwrap();
        $reordered = new PreviewProvider(
            fetcher: $this->fetcher(Http::client([])),
            extractors: [new TwitterCardsExtractor(), ...$this->defaultExtractors()],
        );

        Assert::same($default->title, 'OG');
        Assert::same($reordered->fromHtml(html: $html, pageUrl: 'https://example.com/')->unwrap()->title, 'TW');
    }

    public function missingFieldsFallThroughToLowerPriorities(): void
    {
        $html = '<title>HTML</title><meta property="og:image" content="/i.png"><meta name="twitter:description" content="TW desc"><meta name="description" content="HTML desc">';

        $info = $this->provider(Http::client([]))->fromHtml(html: $html, pageUrl: 'https://example.com/p')->unwrap();

        Assert::same($info->title, 'HTML');
        Assert::same($info->description, 'TW desc');
        Assert::same($info->image, 'https://example.com/i.png');
    }

    public function noExtractorsMeansNoPreview(): void
    {
        $provider = new PreviewProvider(fetcher: $this->fetcher(Http::client([])), extractors: []);

        Assert::same($provider->fromHtml(html: '<title>T</title>', pageUrl: 'https://example.com/')->error(), PreviewFailure::EmptyPage);
    }

    public function limitsAreApplied(): void
    {
        $provider = new PreviewProvider(fetcher: $this->fetcher(Http::client([])), limits: new Limits(titleLength: 5));

        $info = $provider->fromHtml(html: '<title>Long title here</title>', pageUrl: 'https://example.com/')->unwrap();

        Assert::same($info->title, 'Long…');
    }

    public function pageWithoutMetadataIsEmpty(): void
    {
        $client = Http::client(['https://example.com/' => Http::html(Fixtures::read('none.html'))]);

        Assert::same($this->provider($client)->preview(url: 'https://example.com/')->error(), PreviewFailure::EmptyPage);
    }

    public function pageUrlIsUsedWhenNothingNamesIt(): void
    {
        $info = $this->provider(Http::client([]))->fromHtml(html: '<title>T</title>', pageUrl: 'https://example.com/x?y=1')->unwrap();

        Assert::instanceOf($info, PageInfo::class);
        Assert::same($info->url, 'https://example.com/x?y=1');
    }

    #[DataProvider('failureProvider')]
    public function fetchFailuresAreReported(string $url, ClientInterface $client, PreviewFailure $expected): void
    {
        Assert::same($this->provider($client)->preview(url: $url)->error(), $expected);
    }

    public static function failureProvider(): iterable
    {
        yield 'policy' => ['http://127.0.0.1/', Http::client([]), PreviewFailure::PolicyRejected];
        yield 'network' => ['https://example.com/', Http::failing(), PreviewFailure::Network];
        yield 'not html' => ['https://example.com/', Http::client(['https://example.com/' => new Response(200, ['Content-Type' => 'image/png'], 'x')]), PreviewFailure::NotHtml];
        yield 'status' => ['https://example.com/', Http::client(['https://example.com/' => Http::html('x', status: 404)]), PreviewFailure::HttpStatus];
        yield 'redirect loop' => ['https://example.com/', Http::client(['https://example.com/' => Http::redirect('/')]), PreviewFailure::TooManyRedirects];
        yield 'redirect into the intranet' => ['https://example.com/', Http::client(['https://example.com/' => Http::redirect('http://10.0.0.1/')]), PreviewFailure::PolicyRejected];
    }

    #[DataProvider('everyFailureProvider')]
    public function everyFetchFailureHasAPreviewCounterpart(FetchFailure $fetch): void
    {
        Assert::same(PreviewFailure::fromFetch($fetch)->value, $fetch->value);
    }

    public static function everyFailureProvider(): iterable
    {
        foreach (FetchFailure::cases() as $case) {
            yield $case->name => [$case];
        }
    }

    private function provider(ClientInterface $client): PreviewProvider
    {
        return new PreviewProvider(fetcher: $this->fetcher($client, maxRedirects: 3));
    }

    private function fetcher(ClientInterface $client, int $maxRedirects = 5): Fetcher
    {
        return new Fetcher(client: $client, requestFactory: new HttpFactory(), maxRedirects: $maxRedirects);
    }

    /**
     * @return list<\Rasuvaeff\LinkPreview\Extract\Extractor>
     */
    private function defaultExtractors(): array
    {
        return [new \Rasuvaeff\LinkPreview\Extract\OpenGraphExtractor(), new \Rasuvaeff\LinkPreview\Extract\HtmlFallbackExtractor()];
    }
}
