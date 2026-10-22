<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Extract;

use Rasuvaeff\LinkPreview\Extract\ExtractFailure;
use Rasuvaeff\LinkPreview\Extract\HtmlFallbackExtractor;
use Rasuvaeff\LinkPreview\Html\PageDocument;
use Rasuvaeff\LinkPreview\Tests\Support\Fixtures;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(HtmlFallbackExtractor::class)]
final class HtmlFallbackExtractorTest
{
    private const string PAGE = 'https://example.com/de/plain.html';

    public function readsPlainHtml(): void
    {
        $metadata = $this->extract(Fixtures::read('plain.html'))->unwrap();

        Assert::same($metadata->title, 'Plain page');
        Assert::same($metadata->description, 'A page with no social metadata');
        Assert::same($metadata->url, 'https://example.com/de/plain');
        Assert::same($metadata->favicon, 'https://example.com/fav.ico');
        Assert::same($metadata->image, 'https://example.com/hero.jpg');
        Assert::same($metadata->language, 'de-AT');
        Assert::same($metadata->authorName, 'Max Mustermann');
        Assert::null($metadata->siteName);
        Assert::null($metadata->publishedTime);
    }

    public function appleTouchIconIsTheLastResort(): void
    {
        $metadata = $this->extract('<link rel="apple-touch-icon" href="/apple.png">')->unwrap();

        Assert::same($metadata->favicon, 'https://example.com/apple.png');
    }

    public function iconWinsOverAppleTouchIcon(): void
    {
        $metadata = $this->extract('<link rel="apple-touch-icon" href="/apple.png"><link rel="icon" href="/i.png">')->unwrap();

        Assert::same($metadata->favicon, 'https://example.com/i.png');
    }

    public function imageWithoutDimensionsIsTakenOnTrust(): void
    {
        Assert::same($this->extract('<img src="/a.png">')->unwrap()->image, 'https://example.com/a.png');
    }

    public function smallImagesAreSkipped(): void
    {
        $metadata = $this->extract('<img src="/a.png" width="99" height="500"><img src="/b.png" width="500" height="99"><img src="/c.png" width="100" height="100">')->unwrap();

        Assert::same($metadata->image, 'https://example.com/c.png');
    }

    public function imageWithOnlyOneDeclaredSideIsJudgedByIt(): void
    {
        Assert::same($this->extract('<img src="/a.png" width="10"><img src="/b.png" height="300">')->unwrap()->image, 'https://example.com/b.png');
    }

    public function pageWithOnlySmallImagesHasNoMetadata(): void
    {
        Assert::same($this->extract(Fixtures::read('none.html'))->error(), ExtractFailure::NoMetadata);
    }

    public function hostileImagesAndIconsYieldNothingButTheTitle(): void
    {
        $metadata = $this->extract(Fixtures::read('hostile-images.html'))->unwrap();

        Assert::same($metadata->title, 'Hostile');
        Assert::null($metadata->image);
        Assert::null($metadata->favicon);
    }

    public function authorThatIsALinkIsNotAName(): void
    {
        $metadata = $this->extract('<title>T</title><meta name="author" content="https://example.com/me">')->unwrap();

        Assert::null($metadata->authorName);
    }

    public function languageIsOptional(): void
    {
        Assert::null($this->extract('<html lang="???"><head><title>T</title></head></html>')->unwrap()->language);
    }

    public function baseElementShiftsRelativeReferences(): void
    {
        $metadata = $this->extract(Fixtures::read('relative-urls.html'))->unwrap();

        Assert::same($metadata->favicon, 'https://cdn.example.com/i.ico');
        Assert::same($metadata->url, 'https://static.example.com/canonical?x=1');
    }

    public function emptyPageHasNoMetadata(): void
    {
        Assert::same($this->extract('')->error(), ExtractFailure::NoMetadata);
    }

    /**
     * @return \Rasuvaeff\Result\Result<\Rasuvaeff\LinkPreview\Extract\Metadata, ExtractFailure>
     */
    private function extract(string $html): \Rasuvaeff\Result\Result
    {
        return (new HtmlFallbackExtractor())->extract(document: PageDocument::parse($html), pageUrl: self::PAGE);
    }
}
