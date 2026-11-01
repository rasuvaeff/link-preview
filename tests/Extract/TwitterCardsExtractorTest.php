<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Extract;

use Rasuvaeff\LinkPreview\Extract\ExtractFailure;
use Rasuvaeff\LinkPreview\Extract\TwitterCardsExtractor;
use Rasuvaeff\LinkPreview\Extract\UrlContext;
use Rasuvaeff\LinkPreview\Html\PageDocument;
use Rasuvaeff\LinkPreview\Tests\Support\Fixtures;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(TwitterCardsExtractor::class)]
#[Covers(UrlContext::class)]
final class TwitterCardsExtractorTest
{
    private const string PAGE = 'https://example.com/a/b';

    public function readsTheCard(): void
    {
        $metadata = $this->extract(Fixtures::read('twitter-only.html'))->unwrap();

        Assert::same($metadata->title, 'Twitter title');
        Assert::same($metadata->description, 'Twitter description');
        Assert::same($metadata->image, 'https://example.com/a/img/card.png');
        Assert::null($metadata->url);
        Assert::null($metadata->siteName);
    }

    public function imageBeatsImageSrc(): void
    {
        $metadata = $this->extract('<meta name="twitter:image:src" content="/b.png"><meta name="twitter:image" content="/a.png">')->unwrap();

        Assert::same($metadata->image, 'https://example.com/a.png');
    }

    public function propertyAttributeIsAccepted(): void
    {
        Assert::same($this->extract('<meta property="twitter:title" content="T">')->unwrap()->title, 'T');
    }

    public function ignoresOpenGraphAndHtml(): void
    {
        $result = $this->extract('<title>x</title><meta property="og:title" content="y">');

        Assert::same($result->error(), ExtractFailure::NoMetadata);
    }

    public function hostileImageIsDropped(): void
    {
        $metadata = $this->extract('<meta name="twitter:title" content="T"><meta name="twitter:image" content="javascript:1">')->unwrap();

        Assert::null($metadata->image);
        Assert::same($metadata->title, 'T');
    }

    /**
     * @return \Rasuvaeff\Result\Result<\Rasuvaeff\LinkPreview\Extract\Metadata, ExtractFailure>
     */

    public function readsTheImageDimensions(): void
    {
        $metadata = $this->extract('<meta name="twitter:image" content="/i.png"><meta name="twitter:image:width" content="800"><meta name="twitter:image:height" content="418">')->unwrap();

        Assert::same([$metadata->imageWidth, $metadata->imageHeight], [800, 418]);
    }

    public function dimensionsWithoutAnImageAreDropped(): void
    {
        $metadata = $this->extract('<meta name="twitter:title" content="T"><meta name="twitter:image:width" content="800"><meta name="twitter:image:height" content="418">')->unwrap();

        Assert::null($metadata->imageWidth);
        Assert::null($metadata->imageHeight);
    }


    public function everyImageCandidateIsTriedInOrder(): void
    {
        $image = $this->extract('<meta name="twitter:image" content="javascript:a"><meta name="twitter:image" content="/a.png"><meta name="twitter:image:src" content="/src.png">')->unwrap();
        $src = $this->extract('<meta name="twitter:image:src" content="javascript:a"><meta name="twitter:image:src" content="/src.png">')->unwrap();

        Assert::same($image->image, 'https://example.com/a.png');
        Assert::same($src->image, 'https://example.com/src.png');
    }

    private function extract(string $html): \Rasuvaeff\Result\Result
    {
        return (new TwitterCardsExtractor())->extract(document: PageDocument::parse($html), pageUrl: self::PAGE);
    }
}
