<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Rasuvaeff\LinkPreview\Adapter\AdapterRegistry;
use Rasuvaeff\LinkPreview\Extract\TwitterCardsExtractor;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\Fetch\FetchFailure;
use Rasuvaeff\LinkPreview\Fetch\FetchFailureReason;
use Rasuvaeff\LinkPreview\Fetch\Transience;
use Rasuvaeff\LinkPreview\Limits;
use Rasuvaeff\LinkPreview\LinkPreviewer;
use Rasuvaeff\LinkPreview\PageInfo;
use Rasuvaeff\LinkPreview\PreviewFailure;
use Rasuvaeff\LinkPreview\PreviewFailureReason;
use Rasuvaeff\LinkPreview\PreviewProvider;
use Rasuvaeff\LinkPreview\Tests\Support\Adapters;
use Rasuvaeff\LinkPreview\Tests\Support\Fixtures;
use Rasuvaeff\LinkPreview\Tests\Support\Http;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(PreviewProvider::class)]
#[Covers(PreviewFailure::class)]
#[Covers(PreviewFailureReason::class)]
#[Covers(Transience::class)]
final class PreviewProviderTest
{
    public function previewsAFetchedPage(): void
    {
        $client = Http::client(['https://example.com/news/a' => Http::html(Fixtures::read('og-article.html'))]);

        $info = $this->provider($client)->preview(url: 'https://example.com/news/a')->unwrap();

        Assert::same($info->url, 'https://example.com/news/a');
        Assert::same($info->canonicalUrl, 'https://example.com/articles/42');
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

        Assert::same($provider->fromHtml(html: '<title>T</title>', pageUrl: 'https://example.com/')->error()?->reason, PreviewFailureReason::EmptyPage);
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

        Assert::same($this->provider($client)->preview(url: 'https://example.com/')->error()?->reason, PreviewFailureReason::EmptyPage);
    }

    public function pageUrlIsUsedWhenNothingNamesIt(): void
    {
        $info = $this->provider(Http::client([]))->fromHtml(html: '<title>T</title>', pageUrl: 'https://example.com/x?y=1')->unwrap();

        Assert::instanceOf($info, PageInfo::class);
        Assert::same($info->url, 'https://example.com/x?y=1');
    }

    #[DataProvider('failureProvider')]
    public function fetchFailuresAreReported(string $url, ClientInterface $client, PreviewFailureReason $expected): void
    {
        Assert::same($this->provider($client)->preview(url: $url)->error()?->reason, $expected);
    }

    public static function failureProvider(): iterable
    {
        yield 'policy' => ['http://127.0.0.1/', Http::client([]), PreviewFailureReason::PolicyRejected];
        yield 'network' => ['https://example.com/', Http::failing(), PreviewFailureReason::Network];
        yield 'refused by the client' => ['https://example.com/', Http::refusing(), PreviewFailureReason::ClientRefused];
        yield 'unexpected content type' => ['https://example.com/', Http::client(['https://example.com/' => new Response(200, ['Content-Type' => 'image/png'], 'x')]), PreviewFailureReason::UnexpectedContentType];
        yield 'status' => ['https://example.com/', Http::client(['https://example.com/' => Http::html('x', status: 404)]), PreviewFailureReason::HttpStatus];
        yield 'redirect loop' => ['https://example.com/', Http::client(['https://example.com/' => Http::redirect('/')]), PreviewFailureReason::TooManyRedirects];
        yield 'redirect into the intranet' => ['https://example.com/', Http::client(['https://example.com/' => Http::redirect('http://10.0.0.1/')]), PreviewFailureReason::PolicyRejected];
    }

    public function aStatusFailureKeepsTheStatusAndRetryAfter(): void
    {
        $client = Http::client(['https://example.com/' => new Response(429, ['Retry-After' => '60'])]);

        $failure = $this->provider($client)->preview(url: 'https://example.com/')->error();

        Assert::same($failure?->reason, PreviewFailureReason::HttpStatus);
        Assert::same($failure?->status, 429);
        Assert::same($failure?->retryAfterSeconds, 60);
        Assert::true($failure?->isTransient() === true);
    }

    #[DataProvider('everyFailureProvider')]
    public function everyFetchFailureHasAPreviewCounterpart(FetchFailureReason $fetch): void
    {
        $preview = PreviewFailure::fromFetch(new FetchFailure(reason: $fetch, status: 503, retryAfterSeconds: 7));

        Assert::same($preview->reason->value, $fetch->value);
        Assert::same($preview->reason, PreviewFailureReason::fromFetch($fetch));
        Assert::same($preview->status, 503);
        Assert::same($preview->retryAfterSeconds, 7);
        Assert::same($preview->isTransient(), (new FetchFailure(reason: $fetch, status: 503))->isTransient());
    }

    public static function everyFailureProvider(): iterable
    {
        foreach (FetchFailureReason::cases() as $case) {
            yield $case->name => [$case];
        }
    }

    /**
     * The taxonomy of the README, row by row.
     */
    #[DataProvider('transienceProvider')]
    public function transientFailuresAreTheOnesWorthRetrying(PreviewFailureReason $reason, ?int $status, bool $transient): void
    {
        Assert::same((new PreviewFailure(reason: $reason, status: $status))->isTransient(), $transient);
    }

    public static function transienceProvider(): iterable
    {
        yield 'policy' => [PreviewFailureReason::PolicyRejected, null, false];
        yield 'network' => [PreviewFailureReason::Network, null, true];
        yield 'client refused' => [PreviewFailureReason::ClientRefused, null, false];
        yield 'too many redirects' => [PreviewFailureReason::TooManyRedirects, null, false];
        yield 'invalid redirect' => [PreviewFailureReason::InvalidRedirect, null, false];
        yield 'budget' => [PreviewFailureReason::BudgetExceeded, null, true];
        yield 'content type' => [PreviewFailureReason::UnexpectedContentType, null, false];
        yield 'too large' => [PreviewFailureReason::TooLarge, null, false];
        yield 'missing token' => [PreviewFailureReason::MissingToken, null, false];
        yield 'empty page' => [PreviewFailureReason::EmptyPage, null, false];
        yield 'status unknown' => [PreviewFailureReason::HttpStatus, null, false];
        yield '304' => [PreviewFailureReason::HttpStatus, 304, false];
        yield '400' => [PreviewFailureReason::HttpStatus, 400, false];
        yield '403' => [PreviewFailureReason::HttpStatus, 403, false];
        yield '404' => [PreviewFailureReason::HttpStatus, 404, false];
        yield '407' => [PreviewFailureReason::HttpStatus, 407, false];
        yield '408' => [PreviewFailureReason::HttpStatus, 408, true];
        yield '410' => [PreviewFailureReason::HttpStatus, 410, false];
        yield '425' => [PreviewFailureReason::HttpStatus, 425, true];
        yield '429' => [PreviewFailureReason::HttpStatus, 429, true];
        yield '499' => [PreviewFailureReason::HttpStatus, 499, false];
        yield '500' => [PreviewFailureReason::HttpStatus, 500, true];
        yield '503' => [PreviewFailureReason::HttpStatus, 503, true];
        yield '599' => [PreviewFailureReason::HttpStatus, 599, true];
        yield 'a status on a non-status failure' => [PreviewFailureReason::TooLarge, 503, false];
    }

    public function aSiteAdapterOutranksTheExtractorsAndTheyFillTheGaps(): void
    {
        $endpoint = 'https://www.youtube.com/oembed?url=https%3A%2F%2Fyoutu.be%2FdQw4w9WgXcQ&format=json';
        $client = Http::client([
            'https://youtu.be/dQw4w9WgXcQ' => Http::html('<title>Page</title><meta property="og:title" content="OG title"><meta property="og:description" content="OG description">'),
            $endpoint => Adapters::json(Adapters::fixture('youtube/oembed.json')),
        ]);

        $info = $this->provider($client)->preview(url: 'https://youtu.be/dQw4w9WgXcQ')->unwrap();

        Assert::same($info->title, 'Never Gonna Give You Up (Official Video)');
        Assert::same($info->description, 'OG description');
        Assert::same($info->embedUrl, 'https://www.youtube.com/embed/dQw4w9WgXcQ');
        Assert::same(Http::requestedUrls($client), [$endpoint, 'https://youtu.be/dQw4w9WgXcQ']);
    }

    public function theAdapterOnlyAnswersAfterThePolicyAcceptedTheUrl(): void
    {
        $client = Http::client([]);

        Assert::same($this->provider($client)->preview(url: 'http://localhost./youtube.com')->error()?->reason, PreviewFailureReason::PolicyRejected);
        Assert::same(Http::requests($client), []);
    }

    public function aLookAlikeHostGetsNoAdapter(): void
    {
        $client = Http::client(['https://youtube.com.evil.example/watch' => Http::html('<title>Evil</title>')]);

        $info = $this->provider($client)->preview(url: 'https://youtube.com.evil.example/watch')->unwrap();

        Assert::same($info->title, 'Evil');
        Assert::same(Http::requestedUrls($client), ['https://youtube.com.evil.example/watch']);
    }

    public function aFailedAdapterFallsBackToThePage(): void
    {
        $client = Http::client(['https://youtu.be/dQw4w9WgXcQ' => Http::html('<title>Page title</title>')]);

        Assert::same($this->provider($client)->preview(url: 'https://youtu.be/dQw4w9WgXcQ')->unwrap()->title, 'Page title');
    }

    public function aSitePageThatIsDownStillPreviewsFromTheAdapter(): void
    {
        $client = Http::client(['https://www.youtube.com/oembed?url=https%3A%2F%2Fyoutu.be%2FdQw4w9WgXcQ&format=json' => Adapters::json(Adapters::fixture('youtube/oembed.json'))]);

        $info = $this->provider($client)->preview(url: 'https://youtu.be/dQw4w9WgXcQ')->unwrap();

        Assert::same($info->title, 'Never Gonna Give You Up (Official Video)');
        Assert::same($info->url, 'https://youtu.be/dQw4w9WgXcQ');
    }

    public function aMissingTokenIsReportedWhenThePageIsUnavailableToo(): void
    {
        $client = Http::client([]);

        Assert::same($this->provider($client)->preview(url: 'https://www.instagram.com/p/CxYz123AbC/')->error()?->reason, PreviewFailureReason::MissingToken);
    }

    public function aMissingTokenStillLetsThePageSpeak(): void
    {
        $client = Http::client(['https://www.instagram.com/p/CxYz123AbC/' => Http::html('<meta property="og:title" content="Public title">')]);

        Assert::same($this->provider($client)->preview(url: 'https://www.instagram.com/p/CxYz123AbC/')->unwrap()->title, 'Public title');
    }

    public function aConfiguredTokenOpensTheAdapter(): void
    {
        $client = Http::client([
            'https://graph.facebook.com/v21.0/instagram_oembed?url=https%3A%2F%2Fwww.instagram.com%2Fp%2FCxYz123AbC%2F&omit_script=true' => Adapters::json(Adapters::fixture('instagram/oembed.json')),
        ]);
        $provider = new PreviewProvider(
            fetcher: $this->fetcher($client),
            adapters: AdapterRegistry::standard(instagramToken: 'test-token'),
        );

        $info = $provider->preview(url: 'https://www.instagram.com/p/CxYz123AbC/')->unwrap();

        Assert::same($info->authorName, 'natgeo');
    }

    public function anEmptyRegistryTurnsAdaptersOff(): void
    {
        $client = Http::client(['https://youtu.be/dQw4w9WgXcQ' => Http::html('<title>Page title</title>')]);
        $provider = new PreviewProvider(fetcher: $this->fetcher($client), adapters: new AdapterRegistry());

        Assert::same($provider->preview(url: 'https://youtu.be/dQw4w9WgXcQ')->unwrap()->title, 'Page title');
        Assert::same(Http::requestedUrls($client), ['https://youtu.be/dQw4w9WgXcQ']);
    }

    public function discoveredOEmbedOutranksJsonLdWhichOutranksOpenGraph(): void
    {
        $page = '<head><link rel="alternate" type="application/json+oembed" href="/oembed">'
            . '<meta property="og:title" content="OG title"><meta property="og:description" content="OG description"><meta property="og:site_name" content="OG site">'
            . '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Article","headline":"LD title","description":"LD description"}</script></head>';
        $client = Http::client([
            'https://example.com/p' => Http::html($page),
            'https://example.com/oembed' => Adapters::json('{"type":"rich","title":"oEmbed title"}'),
        ]);

        $info = $this->provider($client)->preview(url: 'https://example.com/p')->unwrap();

        Assert::same($info->title, 'oEmbed title');
        Assert::same($info->description, 'LD description');
        Assert::same($info->siteName, 'OG site');
    }

    public function aBrokenOEmbedEndpointDoesNotSpoilThePreview(): void
    {
        $page = '<head><link rel="alternate" type="application/json+oembed" href="http://10.0.0.1/oembed"><title>Fine</title></head>';
        $client = Http::client(['https://example.com/p' => Http::html($page)]);

        Assert::same($this->provider($client)->preview(url: 'https://example.com/p')->unwrap()->title, 'Fine');
        Assert::same(Http::requestedUrls($client), ['https://example.com/p']);
    }

    #[DataProvider('refusedPageUrlProvider')]
    public function fromHtmlRefusesAPageUrlThePolicyRefuses(string $pageUrl): void
    {
        $client = Http::client([]);

        $result = $this->provider($client)->fromHtml(html: '<title>T</title><link rel="alternate" type="application/json+oembed" href="/o">', pageUrl: $pageUrl);

        Assert::same($result->error()?->reason, PreviewFailureReason::PolicyRejected);
        Assert::same(Http::requests($client), []);
    }

    public static function refusedPageUrlProvider(): iterable
    {
        yield 'relative' => ['/a'];
        yield 'empty' => [''];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'private' => ['http://10.0.0.1/'];
        yield 'loopback' => ['http://localhost/'];
    }

    public function fromHtmlNormalisesThePageUrl(): void
    {
        $info = $this->provider(Http::client([]))->fromHtml(html: '<title>T</title>', pageUrl: 'HTTPS://Example.COM:443/a#frag')->unwrap();

        Assert::same($info->url, 'https://example.com/a');
    }

    public function aPageCannotMoveWhereTheCardPoints(): void
    {
        $html = '<title>T</title><meta property="og:url" content="https://evil.example/elsewhere"><link rel="canonical" href="https://other.example/">';

        $info = $this->provider(Http::client([]))->fromHtml(html: $html, pageUrl: 'https://example.com/a')->unwrap();

        Assert::same($info->url, 'https://example.com/a');
        Assert::same($info->canonicalUrl, 'https://evil.example/elsewhere');
    }

    public function theHostNamesTheSiteOnlyWhenAskedAndOnlyWhenNothingElseDoes(): void
    {
        $fetcher = $this->fetcher(Http::client([]));
        $plain = new PreviewProvider(fetcher: $fetcher);
        $withHost = new PreviewProvider(fetcher: $fetcher, hostAsSiteName: true);

        Assert::null($plain->fromHtml(html: '<title>T</title>', pageUrl: 'https://www.example.com/')->unwrap()->siteName);
        Assert::same($withHost->fromHtml(html: '<title>T</title>', pageUrl: 'https://www.example.com/')->unwrap()->siteName, 'example.com');
        Assert::same($withHost->fromHtml(html: '<title>T</title>', pageUrl: 'https://news.example.com/')->unwrap()->siteName, 'news.example.com');
        Assert::same($withHost->fromHtml(html: '<title>T</title>', pageUrl: 'https://wwwexample.com/')->unwrap()->siteName, 'wwwexample.com');
        Assert::same($withHost->fromHtml(html: '<meta property="og:site_name" content="Own">', pageUrl: 'https://www.example.com/')->unwrap()->siteName, 'Own');
        Assert::same($withHost->fromHtml(html: '<p>nothing</p>', pageUrl: 'https://www.example.com/')->error()?->reason, PreviewFailureReason::EmptyPage);
    }

    public function itIsALinkPreviewer(): void
    {
        Assert::instanceOf($this->provider(Http::client([])), LinkPreviewer::class);
    }

    public function markupOnlyTextFallsThroughToTheNextSource(): void
    {
        $html = '<meta property="og:title" content="&lt;b&gt;&lt;/b&gt;"><meta name="twitter:title" content="Real &amp;amp; title">';

        $info = $this->provider(Http::client([]))->fromHtml(html: $html, pageUrl: 'https://example.com/')->unwrap();

        Assert::same($info->title, 'Real & title');
    }

    public function theImageKeepsItsOwnDimensions(): void
    {
        $html = '<meta property="og:image" content="/og.png"><meta name="twitter:image" content="/tw.png">'
            . '<meta name="twitter:image:width" content="800"><meta name="twitter:image:height" content="400">';

        $info = $this->provider(Http::client([]))->fromHtml(html: $html, pageUrl: 'https://example.com/')->unwrap();

        Assert::same($info->image, 'https://example.com/og.png');
        Assert::null($info->imageWidth);
        Assert::null($info->imageHeight);
    }

    public function anAdapterNamesTheProvider(): void
    {
        $client = Http::client(['https://www.youtube.com/oembed?url=https%3A%2F%2Fyoutu.be%2FdQw4w9WgXcQ&format=json' => Adapters::json(Adapters::fixture('youtube/oembed.json'))]);

        $info = $this->provider($client)->preview(url: 'https://youtu.be/dQw4w9WgXcQ')->unwrap();

        Assert::same($info->providerName, 'YouTube');
        Assert::same($info->imageWidth, 480);
        Assert::same($info->imageHeight, 360);
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
