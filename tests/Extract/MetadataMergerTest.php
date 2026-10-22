<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Extract;

use DateTimeImmutable;
use Rasuvaeff\LinkPreview\Extract\Metadata;
use Rasuvaeff\LinkPreview\Extract\MetadataMerger;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(MetadataMerger::class)]
#[Covers(Metadata::class)]
final class MetadataMergerTest
{
    public function emptyListMergesToEmptyMetadata(): void
    {
        Assert::true(MetadataMerger::merge([])->isEmpty());
    }

    public function earlierSourceWinsPerField(): void
    {
        $time = new DateTimeImmutable('2026-10-01T00:00:00Z');
        $merged = MetadataMerger::merge([
            new Metadata(title: 'A', image: 'https://a.example/1.png'),
            new Metadata(title: 'B', description: 'B desc', image: 'https://b.example/2.png', siteName: 'B site'),
            new Metadata(
                url: 'https://c.example/',
                description: 'C desc',
                favicon: 'https://c.example/f.ico',
                language: 'en',
                authorName: 'C author',
                publishedTime: $time,
                type: 'article',
            ),
        ]);

        Assert::same($merged->url, 'https://c.example/');
        Assert::same($merged->title, 'A');
        Assert::same($merged->description, 'B desc');
        Assert::same($merged->image, 'https://a.example/1.png');
        Assert::same($merged->favicon, 'https://c.example/f.ico');
        Assert::same($merged->siteName, 'B site');
        Assert::same($merged->language, 'en');
        Assert::same($merged->authorName, 'C author');
        Assert::same($merged->publishedTime, $time);
        Assert::same($merged->type, 'article');
    }

    public function everyFieldOfTheFirstSourceBeatsTheSecond(): void
    {
        $first = new Metadata(
            url: 'https://a.example/',
            title: 'A',
            description: 'A desc',
            image: 'https://a.example/i.png',
            favicon: 'https://a.example/f.ico',
            siteName: 'A site',
            language: 'en',
            authorName: 'A author',
            publishedTime: new DateTimeImmutable('2026-01-01T00:00:00Z'),
            type: 'article',
        );
        $second = new Metadata(
            url: 'https://b.example/',
            title: 'B',
            description: 'B desc',
            image: 'https://b.example/i.png',
            favicon: 'https://b.example/f.ico',
            siteName: 'B site',
            language: 'de',
            authorName: 'B author',
            publishedTime: new DateTimeImmutable('2027-01-01T00:00:00Z'),
            type: 'website',
        );

        Assert::same(MetadataMerger::merge([$first, $second])->url, 'https://a.example/');
        Assert::same(MetadataMerger::merge([$first, $second])->title, 'A');
        Assert::same(MetadataMerger::merge([$first, $second])->description, 'A desc');
        Assert::same(MetadataMerger::merge([$first, $second])->image, 'https://a.example/i.png');
        Assert::same(MetadataMerger::merge([$first, $second])->language, 'en');
        Assert::same(MetadataMerger::merge([$first, $second])->favicon, 'https://a.example/f.ico');
        Assert::same(MetadataMerger::merge([$second, $first])->favicon, 'https://b.example/f.ico');
        Assert::same(MetadataMerger::merge([$second, $first])->type, 'website');
        Assert::same(MetadataMerger::merge([$second, $first])->authorName, 'B author');
        Assert::same(MetadataMerger::merge([$second, $first])->siteName, 'B site');
        Assert::same(MetadataMerger::merge([$second, $first])->publishedTime?->format('Y'), '2027');
    }

    public function metadataIsEmptyOnlyWhenEveryFieldIs(): void
    {
        Assert::true((new Metadata())->isEmpty());

        foreach (
            [
                new Metadata(url: 'https://a.example/'),
                new Metadata(title: 'x'),
                new Metadata(description: 'x'),
                new Metadata(image: 'https://a.example/i.png'),
                new Metadata(favicon: 'https://a.example/f.ico'),
                new Metadata(siteName: 'x'),
                new Metadata(language: 'en'),
                new Metadata(authorName: 'x'),
                new Metadata(publishedTime: new DateTimeImmutable()),
                new Metadata(type: 'x'),
            ] as $metadata
        ) {
            Assert::false($metadata->isEmpty());
        }
    }
}
