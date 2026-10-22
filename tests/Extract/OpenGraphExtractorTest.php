<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Extract;

use Rasuvaeff\LinkPreview\Extract\ExtractFailure;
use Rasuvaeff\LinkPreview\Extract\Metadata;
use Rasuvaeff\LinkPreview\Extract\OpenGraphExtractor;
use Rasuvaeff\LinkPreview\Html\PageDocument;
use Rasuvaeff\LinkPreview\Tests\Support\Fixtures;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(OpenGraphExtractor::class)]
final class OpenGraphExtractorTest
{
    private const string PAGE = 'https://example.com/news/article.html';

    public function readsEveryField(): void
    {
        $metadata = $this->extract(Fixtures::read('og-article.html'))->unwrap();

        Assert::same($metadata->url, 'https://example.com/articles/42');
        Assert::same($metadata->title, 'The OpenGraph title & more');
        Assert::same($metadata->description, "Line one.\n   Line   two.");
        Assert::same($metadata->image, 'https://example.com/img/cover.jpg');
        Assert::same($metadata->siteName, 'Example News');
        Assert::same($metadata->language, 'ru-RU');
        Assert::same($metadata->authorName, 'Jane Doe');
        Assert::same($metadata->publishedTime?->format('c'), '2026-10-01T12:30:00+03:00');
        Assert::same($metadata->type, 'article');
        Assert::null($metadata->favicon);
    }

    public function ignoresOtherSources(): void
    {
        $metadata = $this->extract('<title>Only HTML</title><meta name="description" content="x"><meta name="twitter:title" content="t">');

        Assert::true($metadata->isErr());
        Assert::same($metadata->error(), ExtractFailure::NoMetadata);
    }

    public function secureImageWins(): void
    {
        $metadata = $this->extract('<meta property="og:image" content="http://a.example/1.png"><meta property="og:image:secure_url" content="https://a.example/2.png">')->unwrap();

        Assert::same($metadata->image, 'https://a.example/2.png');
    }

    public function imageUrlPropertyIsAnAlias(): void
    {
        Assert::same($this->extract('<meta property="og:image:url" content="/u.png">')->unwrap()->image, 'https://example.com/u.png');
    }

    public function firstUsableImageWins(): void
    {
        Assert::same($this->extract(Fixtures::read('first-valid-image.html'))->unwrap()->image, 'https://example.com/second.png');
    }

    public function hostileImageSchemesYieldNothing(): void
    {
        $result = $this->extract('<meta property="og:image" content="javascript:alert(1)"><meta property="og:image" content="data:image/png;base64,AA">');

        Assert::true($result->isErr());
    }

    public function otherFieldsSurviveAHostileImage(): void
    {
        $metadata = $this->extract('<meta property="og:title" content="T"><meta property="og:image" content="javascript:alert(1)">')->unwrap();

        Assert::same($metadata->title, 'T');
        Assert::null($metadata->image);
    }

    public function authorThatIsAProfileLinkIsNotAName(): void
    {
        $metadata = $this->extract('<meta property="og:title" content="T"><meta property="article:author" content="https://facebook.com/jane">')->unwrap();

        Assert::null($metadata->authorName);
    }

    public function unparseableTimeAndLocaleAreDropped(): void
    {
        $metadata = $this->extract('<meta property="og:title" content="T"><meta property="article:published_time" content="yesterday"><meta property="og:locale" content="not a locale!">')->unwrap();

        Assert::null($metadata->publishedTime);
        Assert::null($metadata->language);
    }

    public function relativeUrlTakesTheBaseElementIntoAccount(): void
    {
        $metadata = $this->extract('<base href="https://cdn.example.net/x/"><meta property="og:image" content="a.png"><meta property="og:url" content="/canon">')->unwrap();

        Assert::same($metadata->image, 'https://cdn.example.net/x/a.png');
        Assert::same($metadata->url, 'https://cdn.example.net/canon');
    }

    public function emptyPageHasNoMetadata(): void
    {
        Assert::same($this->extract('')->error(), ExtractFailure::NoMetadata);
    }

    /**
     * @return \Rasuvaeff\Result\Result<Metadata, ExtractFailure>
     */
    private function extract(string $html): \Rasuvaeff\Result\Result
    {
        return (new OpenGraphExtractor())->extract(document: PageDocument::parse($html), pageUrl: self::PAGE);
    }
}
