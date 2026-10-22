<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Extract;

use GuzzleHttp\Psr7\HttpFactory;
use Rasuvaeff\LinkPreview\Extract\ExtractFailure;
use Rasuvaeff\LinkPreview\Extract\OEmbedExtractor;
use Rasuvaeff\LinkPreview\Extract\OEmbedResponse;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\Html\PageDocument;
use Rasuvaeff\LinkPreview\Tests\Support\Adapters;
use Rasuvaeff\LinkPreview\Tests\Support\Http;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(OEmbedExtractor::class)]
#[Covers(OEmbedResponse::class)]
final class OEmbedExtractorTest
{
    private const string PAGE = 'https://example.com/post/1';

    private const string LINK = '<link rel="alternate" type="application/json+oembed" href="%s">';

    public function followsTheDiscoveredEndpoint(): void
    {
        $client = Http::client(['https://example.com/oembed?url=1' => Adapters::json('{"type":"video","title":"T","author_name":"A","provider_name":"P","thumbnail_url":"/t.png","html":"<iframe src=\"//player.example.net/v/1\"></iframe>"}')]);

        $metadata = $this->extract($client, sprintf(self::LINK, '/oembed?url=1'))->unwrap();

        Assert::same($metadata->title, 'T');
        Assert::same($metadata->authorName, 'A');
        Assert::same($metadata->siteName, 'P');
        Assert::same($metadata->image, 'https://example.com/t.png');
        Assert::same($metadata->embedUrl, 'https://player.example.net/v/1');
        Assert::same($metadata->type, 'video');
        Assert::same(Http::requestedUrls($client), ['https://example.com/oembed?url=1']);
    }

    public function noLinkMeansNoRequest(): void
    {
        $client = Http::client([]);

        Assert::same($this->extract($client, '<title>x</title>')->error(), ExtractFailure::NoMetadata);
        Assert::same(Http::requests($client), []);
    }

    #[DataProvider('refusedEndpointProvider')]
    public function theEndpointAnswersToTheSamePolicyAsThePage(string $href): void
    {
        $client = Http::client([]);

        $result = $this->extract($client, sprintf(self::LINK, $href));

        Assert::true($result->isErr());
        Assert::same(Http::requests($client), []);
    }

    public static function refusedEndpointProvider(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1/oembed'];
        yield 'metadata endpoint' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'localhost with root dot' => ['http://localhost./oembed'];
        yield 'integer ip' => ['http://2130706433/oembed'];
        yield 'ipv6 embedded' => ['http://[::7f00:1]/oembed'];
        yield 'other port' => ['https://example.com:6379/oembed'];
        yield 'credentials' => ['https://user:pw@example.com/oembed'];
        yield 'javascript' => ['javascript:alert(1)'];
    }

    public function aRedirectOfTheEndpointIsCheckedToo(): void
    {
        $client = Http::client(['https://example.com/oembed' => Http::redirect('http://10.0.0.1/secret')]);

        $result = $this->extract($client, sprintf(self::LINK, '/oembed'));

        Assert::same($result->error(), ExtractFailure::Unavailable);
        Assert::same(Http::requestedUrls($client), ['https://example.com/oembed']);
    }

    public function onlyTheFirstEndpointIsTried(): void
    {
        $client = Http::client([]);

        $this->extract($client, sprintf(self::LINK, '/a') . sprintf(self::LINK, '/b'));

        Assert::same(Http::requestedUrls($client), ['https://example.com/a']);
    }

    public function anEndpointThatReturnsHtmlIsUnavailable(): void
    {
        $client = Http::client(['https://example.com/oembed' => Http::html('<p>x</p>')]);

        Assert::same($this->extract($client, sprintf(self::LINK, '/oembed'))->error(), ExtractFailure::Unavailable);
    }

    #[DataProvider('invalidBodyProvider')]
    public function unreadableAnswersAreInvalidData(string $body): void
    {
        $client = Http::client(['https://example.com/oembed' => Adapters::json($body)]);

        Assert::same($this->extract($client, sprintf(self::LINK, '/oembed'))->error(), ExtractFailure::InvalidData);
    }

    public static function invalidBodyProvider(): iterable
    {
        yield 'not json' => ['<html>'];
        yield 'empty object' => ['{}'];
        yield 'unrelated object' => ['{"status":"ok"}'];
        yield 'scalar' => ['5'];
    }

    public function aPhotoUsesItsUrlAsTheImage(): void
    {
        $metadata = OEmbedResponse::metadata('{"type":"photo","url":"https://cdn.example.net/p.jpg","title":"P"}', 'https://example.com/oembed')?->image;

        Assert::same($metadata, 'https://cdn.example.net/p.jpg');
    }

    public function aNonPhotoDoesNotUseUrlAsTheImage(): void
    {
        Assert::null(OEmbedResponse::metadata('{"type":"rich","url":"https://cdn.example.net/p.jpg","title":"P"}', 'https://example.com/oembed')?->image);
    }

    public function theEmbedIsTheIframeSourceOnly(): void
    {
        $embed = static fn(string $html): ?string => OEmbedResponse::metadata((string) json_encode(['title' => 'T', 'html' => $html]), 'https://example.com/o')?->embedUrl;

        Assert::same($embed('<iframe width="1" src="https://p.example.net/e?a=1&amp;b=2"></iframe>'), 'https://p.example.net/e?a=1&b=2');
        Assert::same($embed("<iframe\n src='/rel'></iframe>"), 'https://example.com/rel');
        Assert::null($embed('<blockquote><a href="https://x.example/">x</a></blockquote>'));
        Assert::null($embed('<iframe src="javascript:alert(1)"></iframe>'));
        Assert::null($embed('<iframe data-src="https://p.example.net/e"></iframe>'));
    }

    private function extract(\Psr\Http\Client\ClientInterface $client, string $head): \Rasuvaeff\Result\Result
    {
        $extractor = new OEmbedExtractor(new Fetcher(client: $client, requestFactory: new HttpFactory()));

        return $extractor->extract(document: PageDocument::parse('<head>' . $head . '</head>'), pageUrl: self::PAGE);
    }
}
