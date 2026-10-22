<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests;

use InvalidArgumentException;
use Rasuvaeff\LinkPreview\Limits;
use Rasuvaeff\LinkPreview\PageInfo;
use Rasuvaeff\LinkPreview\Tests\Support\TextGenerators;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Limits::class)]
final class LimitsTest
{
    public function shortTextPassesThrough(): void
    {
        $info = (new Limits())->apply($this->info(title: 'Title', description: 'Description', siteName: 'Site', authorName: 'Author'));

        Assert::same($info->title, 'Title');
        Assert::same($info->description, 'Description');
        Assert::same($info->siteName, 'Site');
        Assert::same($info->authorName, 'Author');
    }

    public function untouchedFieldsAreCarriedOver(): void
    {
        $time = new \DateTimeImmutable('2026-10-01T00:00:00Z');
        $info = (new Limits())->apply(new PageInfo(
            url: 'https://example.com/a',
            image: 'https://example.com/i.png',
            favicon: 'https://example.com/f.ico',
            language: 'en',
            publishedTime: $time,
            type: 'article',
        ));

        Assert::same($info->url, 'https://example.com/a');
        Assert::same($info->image, 'https://example.com/i.png');
        Assert::same($info->favicon, 'https://example.com/f.ico');
        Assert::same($info->language, 'en');
        Assert::same($info->publishedTime, $time);
        Assert::same($info->type, 'article');
        Assert::null($info->title);
    }

    public function eachFieldHasItsOwnLimit(): void
    {
        $limits = new Limits(titleLength: 5, descriptionLength: 6, siteNameLength: 7, authorNameLength: 8);
        $long = str_repeat('x', 20);
        $info = $limits->apply($this->info(title: $long, description: $long, siteName: $long, authorName: $long));

        Assert::same($info->title, 'xxxx…');
        Assert::same($info->description, 'xxxxx…');
        Assert::same($info->siteName, 'xxxxxx…');
        Assert::same($info->authorName, 'xxxxxxx…');
    }

    public function textAtTheLimitIsKeptAndOneOverIsCut(): void
    {
        $limits = new Limits(titleLength: 5);

        Assert::same($limits->apply($this->info(title: 'abcde'))->title, 'abcde');
        Assert::same($limits->apply($this->info(title: 'abcdef'))->title, 'abcd…');
    }

    public function cutsAtCharactersNotBytes(): void
    {
        $limits = new Limits(titleLength: 4);

        Assert::same($limits->apply($this->info(title: 'Привет мир'))->title, 'При…');
        Assert::same($limits->apply($this->info(title: '😀😀😀😀😀'))->title, '😀😀😀…');
    }

    public function trailingSpaceBeforeTheEllipsisIsDropped(): void
    {
        Assert::same((new Limits(titleLength: 4))->apply($this->info(title: 'ab cdefgh'))->title, 'ab…');
    }

    public function limitOfOneLeavesJustTheEllipsis(): void
    {
        Assert::same((new Limits(titleLength: 1))->apply($this->info(title: 'abc'))->title, '…');
    }

    #[DataProvider('cleanProvider')]
    public function cleansText(string $raw, ?string $expected): void
    {
        Assert::same((new Limits())->apply($this->info(title: $raw))->title, $expected);
    }

    public static function cleanProvider(): iterable
    {
        yield 'whitespace runs collapse' => ["  a \n\t b   c  ", 'a b c'];
        yield 'non-breaking space' => ["a\u{00A0}\u{00A0}b", 'a b'];
        yield 'control characters become spaces' => ["a\u{0007}b\u{0000}c", 'a b c'];
        yield 'only whitespace is nothing' => ["  \n\t ", null];
        yield 'empty is nothing' => ['', null];
        yield 'emoji are kept' => ['Party 😀', 'Party 😀'];
        yield 'zero-width joiner is kept' => ["👨\u{200D}👩", "👨\u{200D}👩"];
    }

    public function invalidUtf8IsScrubbed(): void
    {
        $title = (new Limits())->apply($this->info(title: "ab\xC3\x28cd"))->title;

        Assert::true(mb_check_encoding((string) $title, 'UTF-8'));
        Assert::true(str_starts_with((string) $title, 'ab') && str_ends_with((string) $title, '(cd'));
    }

    public function nullStaysNull(): void
    {
        $info = (new Limits())->apply($this->info());

        Assert::null($info->title);
        Assert::null($info->description);
        Assert::null($info->siteName);
        Assert::null($info->authorName);
    }

    public function overlongImageUrlsAreDropped(): void
    {
        $limits = new Limits(maxUrlLength: 30);
        $fits = 'https://example.com/' . str_repeat('a', 10);
        $tooLong = 'https://example.com/' . str_repeat('a', 11);

        $kept = $limits->apply(new PageInfo(url: 'https://example.com/', image: $fits, favicon: $fits));
        $dropped = $limits->apply(new PageInfo(url: 'https://example.com/', image: $tooLong, favicon: $tooLong));

        Assert::same(\strlen($fits), 30);
        Assert::same($kept->image, $fits);
        Assert::same($kept->favicon, $fits);
        Assert::null($dropped->image);
        Assert::null($dropped->favicon);
    }

    public function pageUrlIsNeverDropped(): void
    {
        $url = 'https://example.com/' . str_repeat('a', 5000);

        Assert::same((new Limits(maxUrlLength: 30))->apply(new PageInfo(url: $url))->url, $url);
    }

    public function httpsImagesOnlyDropsPlainHttp(): void
    {
        $limits = new Limits(httpsImagesOnly: true);
        $info = $limits->apply(new PageInfo(url: 'http://example.com/', image: 'http://example.com/i.png', favicon: 'HTTPS://example.com/f.ico'));

        Assert::null($info->image);
        Assert::same($info->favicon, 'HTTPS://example.com/f.ico');
    }

    public function httpImagesAreKeptByDefault(): void
    {
        Assert::same((new Limits())->apply(new PageInfo(url: 'http://example.com/', image: 'http://example.com/i.png'))->image, 'http://example.com/i.png');
    }

    #[DataProvider('invalidProvider')]
    public function rejectsNonPositiveLimits(array $limits): void
    {
        Expect::exception(InvalidArgumentException::class);

        new Limits(...$limits);
    }

    public static function invalidProvider(): iterable
    {
        yield 'title' => [['titleLength' => 0]];
        yield 'description' => [['descriptionLength' => 0]];
        yield 'site name' => [['siteNameLength' => 0]];
        yield 'author name' => [['authorNameLength' => 0]];
        yield 'url' => [['maxUrlLength' => 0]];
        yield 'negative' => [['titleLength' => -1]];
    }

    /**
     * The limit bounds the length, nothing is cut that fits, and applying the
     * limits twice is the same as applying them once.
     */
    #[Property(runs: 300, generators: [TextGenerators::class, 'clip'], timeoutMs: 250)]
    public function clippingIsBoundedAndIdempotent(string $text, int $limit): void
    {
        $limits = new Limits(titleLength: $limit);
        $once = $limits->apply($this->info(title: $text))->title;
        $twice = $limits->apply($this->info(title: $once))->title;
        $normalized = trim((string) preg_replace('/[\s\p{Cc}]+/u', ' ', $text));

        Assert::same($twice, $once);

        if ($normalized === '') {
            Assert::null($once);
        } else {
            Assert::true(mb_strlen((string) $once) <= $limit);
            Assert::same($once === $normalized, mb_strlen($normalized) <= $limit);
        }

        Classify::cover(condition: mb_strlen($normalized) > $limit, label: 'cut', minPercent: 15.0);
        Classify::cover(condition: $normalized !== '' && mb_strlen($normalized) <= $limit, label: 'kept', minPercent: 15.0);
        Classify::cover(condition: $normalized === '', label: 'blank', minPercent: 1.0);
    }

    /** @return iterable<string, array{string, int}> */
    public static function clippingIsBoundedAndIdempotentExamples(): iterable
    {
        yield 'blank' => ["\t\n ", 3];
        yield 'one over' => ['abcd', 3];
        yield 'trailing space at the cut' => ['ab cd', 3];
        yield 'emoji at the cut' => ['😀😀😀😀', 3];
        yield 'limit one' => ['ab', 1];
    }

    private function info(
        ?string $title = null,
        ?string $description = null,
        ?string $siteName = null,
        ?string $authorName = null,
    ): PageInfo {
        return new PageInfo(
            url: 'https://example.com/',
            title: $title,
            description: $description,
            siteName: $siteName,
            authorName: $authorName,
        );
    }
}
