<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Text;

use Rasuvaeff\LinkPreview\Tests\Support\TextGenerators;
use Rasuvaeff\LinkPreview\Text\PlainText;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(PlainText::class)]
final class PlainTextTest
{
    /** the tag pattern of the cleaner, restated: what must never survive */
    private const string TAG = '~<(?:/?[A-Za-z]|[!?])[^<>]*>~';

    #[DataProvider('cleanProvider')]
    public function cleans(?string $raw, ?string $expected): void
    {
        Assert::same(PlainText::clean($raw), $expected);
    }

    public static function cleanProvider(): iterable
    {
        yield 'null' => [null, null];
        yield 'empty' => ['', null];
        yield 'plain' => ['Hello, world', 'Hello, world'];
        yield 'entities' => ['Tom &amp; Jerry &quot;live&quot; &#39;now&#39;', 'Tom & Jerry "live" \'now\''];
        yield 'double escaped quote' => ['&amp;quot;Hi&amp;quot;', '"Hi"'];
        yield 'escaped markup is markup' => ['&lt;b&gt;bold&lt;/b&gt; text', 'bold text'];
        yield 'tags become spaces' => ['Line one.<br>Line two.', 'Line one. Line two.'];
        yield 'a tag cannot glue an entity' => ['&am<b>p;', '&am p;'];
        yield 'attributes with quotes' => ['<a href="https://x.example/" title="t">link</a>', 'link'];
        yield 'comment, doctype, processing instruction' => ['a<!-- hidden -->b<!DOCTYPE html>c<?xml x?>d', 'a b c d'];
        yield 'script text stays text' => ['<script>alert(1)</script>', 'alert(1)'];
        yield 'a lone less-than is text' => ['a < b > c', 'a < b > c'];
        yield 'a heart is text' => ['I <3 PHP', 'I <3 PHP'];
        yield 'an unclosed tag is text' => ['a <b c', 'a <b c'];
        yield 'unknown entity stays' => ['&foo; &#0;', '&foo; &#0;'];
        yield 'entity without semicolon stays' => ['&amp &lt', '&amp &lt'];
        yield 'nbsp collapses' => ["a&nbsp;&nbsp;b\u{00A0}c", 'a b c'];
        yield 'control characters' => ["a\u{0007}b\u{0000}c\n\td", 'a b c d'];
        yield 'only markup' => ['<b></b> &nbsp; <br/>', null];
        yield 'only escaped markup' => ['&lt;b&gt;&lt;/b&gt;', null];
        yield 'invalid UTF-8 is scrubbed' => ["ab\xC3\x28cd", 'ab?(cd'];
        yield 'four levels decode' => ['&amp;amp;amp;lt;b&amp;amp;amp;gt;x', 'x'];
        yield 'five levels fall back to full width' => ['&amp;amp;amp;amp;lt;b&amp;amp;amp;amp;gt;x', '＆lt;b＆gt;x'];
        yield 'emoji with a joiner' => ["👨\u{200D}👩", "👨\u{200D}👩"];
    }

    /**
     * Cleaning is final: no entity left to decode, no tag left to strip, no
     * control character or run of whitespace, and cleaning again changes nothing.
     */
    #[Property(runs: 400, generators: [TextGenerators::class, 'markup'], timeoutMs: 250)]
    public function cleanTextIsFinal(string $text): void
    {
        $clean = PlainText::clean($text);

        Assert::same(PlainText::clean($clean), $clean);

        if ($clean !== null) {
            Assert::same(html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $clean);
            Assert::same(preg_match(self::TAG, $clean), 0);
            Assert::same(preg_match('/[\p{Cc}\t\n\r\x{00A0}]|  |\A\s|\s\z/u', $clean), 0);
            Assert::true(mb_check_encoding($clean, 'UTF-8'));
        }

        $neutralised = $clean !== null && (str_contains($clean, '＆') || str_contains($clean, '＜'));

        Classify::cover(condition: $clean === null, label: 'nothing left', minPercent: 1.0);
        Classify::cover(condition: $clean !== null && $clean !== trim($text), label: 'changed', minPercent: 30.0);
        Classify::cover(condition: $neutralised, label: 'full-width fallback', minPercent: 3.0);
        Classify::when(condition: preg_match(self::TAG, $text) === 1, label: 'had a tag');
        Classify::when(condition: str_contains($text, '&amp;'), label: 'double escaped');
    }

    /** @return iterable<string, array{string}> */
    public static function cleanTextIsFinalExamples(): iterable
    {
        yield 'at the bound' => ['&amp;amp;amp;lt;b&amp;amp;amp;gt;x'];
        yield 'one past the bound' => ['&amp;amp;amp;amp;lt;b&amp;amp;amp;amp;gt;x'];
        yield 'a tag hiding an entity' => ['&am<b>p;'];
        yield 'an entity that builds a tag' => ['&lt;br&gt;'];
        yield 'a decoded char that builds an entity' => ['&&#97;mp;lt;'];
        yield 'only markup' => ['<b></b>'];
        yield 'nul' => ["\u{0000}"];
    }
}
