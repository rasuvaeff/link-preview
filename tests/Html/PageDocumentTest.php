<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Html;

use Rasuvaeff\LinkPreview\Html\PageDocument;
use Rasuvaeff\LinkPreview\Tests\Support\Fixtures;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(PageDocument::class)]
final class PageDocumentTest
{
    public function readsTitleLanguageAndBase(): void
    {
        $document = PageDocument::parse('<html lang=" fr "><head><base href=" https://b.example/x/ "><title>
            Hello  </title></head></html>');

        Assert::same($document->title(), 'Hello');
        Assert::same($document->language(), 'fr');
        Assert::same($document->baseHref(), 'https://b.example/x/');
    }

    public function absentPartsAreNull(): void
    {
        $document = PageDocument::parse('<html><head><title>  </title><base></head></html>');

        Assert::null($document->title());
        Assert::null($document->language());
        Assert::null($document->baseHref());
    }

    public function firstNonEmptyTitleWins(): void
    {
        $document = PageDocument::parse('<html><head><title> </title></head><body><svg><title>Icon</title></svg></body></html>');

        Assert::same($document->title(), 'Icon');
    }

    public function emptyLangAndEmptyBaseAreNull(): void
    {
        $document = PageDocument::parse('<html lang=""><head><base href=" "><base href="/second/"></head></html>');

        Assert::null($document->language());
        Assert::same($document->baseHref(), '/second/');
    }

    public function metaMatchesPropertyAndNameCaseInsensitively(): void
    {
        $document = PageDocument::parse('<head>
            <meta property="OG:Title" content=" One ">
            <meta name="og:title" content="Two">
            <meta property="og:title" content="">
            <meta property="og:title">
            <meta property="og:title" content="   ">
            <meta property="og:other" content="Other">
            <meta content="No key">
        </head>');

        Assert::same($document->meta('og:title'), ['One', 'Two']);
        Assert::same($document->meta('OG:TITLE'), ['One', 'Two']);
        Assert::same($document->meta('og:missing'), []);
    }

    public function linksMatchRelTokens(): void
    {
        $document = PageDocument::parse('<head>
            <link rel="icon" href="/a.ico">
            <link rel="Shortcut  ICON" href=" /b.ico ">
            <link rel="stylesheet icon-ish" href="/c.css">
            <link rel="icon" href="">
            <link rel="icon">
            <link href="/d.ico">
            <link rel="canonical" href="/canon">
        </head>');

        Assert::same($document->links('icon'), ['/a.ico', '/b.ico']);
        Assert::same($document->links('canonical'), ['/canon']);
        Assert::same($document->links('missing'), []);
    }

    public function imagesCarryDeclaredDimensions(): void
    {
        $document = PageDocument::parse('<body>
            <img src="/a.png" width="640" height="480">
            <img src=" /b.png " width="50px">
            <img src="/c.png" width="auto" height="-5">
            <img src="">
            <img>
            <img src="/d.png" width="9999999">
        </body>');

        Assert::same($document->images(), [
            ['src' => '/a.png', 'width' => 640, 'height' => 480],
            ['src' => '/b.png', 'width' => 50, 'height' => null],
            ['src' => '/c.png', 'width' => null, 'height' => null],
            ['src' => '/d.png', 'width' => null, 'height' => null],
        ]);
    }

    #[DataProvider('emptyProvider')]
    public function emptyInputIsAnEmptyDocument(string $html): void
    {
        $document = PageDocument::parse($html);

        Assert::null($document->title());
        Assert::same($document->meta('og:title'), []);
        Assert::same($document->images(), []);
    }

    public static function emptyProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ["  \n\t "];
        yield 'only a nul byte' => ["\0"];
    }

    public function decodesUtf8Entities(): void
    {
        $document = PageDocument::parse('<head><title>Caf&eacute; &amp; Cr&#232;me – 日本語 😀</title></head>');

        Assert::same($document->title(), 'Café & Crème – 日本語 😀');
    }

    public function sniffsTheCharsetFromMeta(): void
    {
        $document = PageDocument::parse(Fixtures::read('windows-1251.html'));

        Assert::same($document->title(), 'Привет, мир');
        Assert::same($document->meta('og:description'), ['Описание страницы в старой кодировке']);
    }

    public function sniffsTheCharsetFromHttpEquivStyleMeta(): void
    {
        $html = mb_convert_encoding('<head><meta http-equiv="Content-Type" content="text/html; charset=windows-1251"><title>Тест</title></head>', 'Windows-1251', 'UTF-8');

        Assert::same(PageDocument::parse($html)->title(), 'Тест');
    }

    public function bomWinsOverMeta(): void
    {
        $html = "\xEF\xBB\xBF<head><meta charset=\"windows-1251\"><title>Тест</title></head>";

        Assert::same(PageDocument::parse($html)->title(), 'Тест');
    }

    public function metaCharsetAtTheVeryStartIsSniffed(): void
    {
        $html = mb_convert_encoding('<META CHARSET=windows-1251><title>Тест</title>', 'Windows-1251', 'UTF-8');

        Assert::same(PageDocument::parse($html)->title(), 'Тест');
    }

    public function nulBytesAreStripped(): void
    {
        Assert::same(PageDocument::parse("<title>a\0b</title>")->title(), 'ab');
    }

    public function metaKeysAndRelsAreTrimmedAndCaseFolded(): void
    {
        $document = PageDocument::parse('<head><meta property=" OG:Title " content="P"><meta name=" OG:TITLE " content="N"><link rel=" ICON " href="/i.ico"></head>');

        Assert::same($document->meta('og:title'), ['P', 'N']);
        Assert::same($document->links('Icon'), ['/i.ico']);
    }

    public function zeroDimensionIsZero(): void
    {
        Assert::same(PageDocument::parse('<img src="/a.png" width="0" height=" 0px ">')->images(), [['src' => '/a.png', 'width' => 0, 'height' => 0]]);
    }

    public function readsScriptsOfAType(): void
    {
        $document = PageDocument::parse('<head>
            <script type="application/ld+json">{"@type":"Article"}</script>
            <script type=" Application/LD+JSON ">{"@type":"WebPage", "name":"Каф\u00e9 é"}</script>
            <script type="application/ld+json">   </script>
            <script type="text/javascript">var a = "<b>";</script>
            <script>no type</script>
        </head>');

        Assert::same($document->scripts('application/ld+json'), ['{"@type":"Article"}', '{"@type":"WebPage", "name":"Каф\u00e9 é"}']);
        Assert::same($document->scripts('text/javascript'), ['var a = "<b>";']);
        Assert::same($document->scripts('text/missing'), []);
    }

    public function scriptTextKeepsEntitiesLiteral(): void
    {
        $document = PageDocument::parse('<script type="application/ld+json">{"name":"a &amp; b &lt; c"}</script>');

        Assert::same($document->scripts('application/ld+json'), ['{"name":"a &amp; b &lt; c"}']);
    }

    public function readsAlternateLinksOfAType(): void
    {
        $document = PageDocument::parse('<head>
            <link rel="alternate" type="application/json+oembed" href="/oembed?format=json">
            <link rel="ALTERNATE nofollow" type="Application/JSON+oEmbed" href=" /second ">
            <link rel="alternate" type="text/xml+oembed" href="/oembed?format=xml">
            <link rel="canonical" type="application/json+oembed" href="/not-alternate">
            <link rel="alternate" type="application/json+oembed">
            <link rel="alternate" href="/no-type">
        </head>');

        Assert::same($document->alternates('application/json+oembed'), ['/oembed?format=json', '/second']);
        Assert::same($document->alternates('text/xml+oembed'), ['/oembed?format=xml']);
    }

    public function transportCharsetWinsOverMeta(): void
    {
        $html = mb_convert_encoding('<head><meta charset="utf-8"><title>Тест</title></head>', 'Windows-1251', 'UTF-8');

        Assert::same(PageDocument::parse($html, 'windows-1251')->title(), 'Тест');
    }

    public function charsetAliasesAreUnderstood(): void
    {
        $html = mb_convert_encoding('<head><title>Тест</title></head>', 'Windows-1251', 'UTF-8');

        Assert::same(PageDocument::parse($html, 'cp1251')->title(), 'Тест');
        Assert::same(PageDocument::parse($html, ' Windows-1251 ')->title(), 'Тест');
    }

    public function latin1IsReadAsWindows1252(): void
    {
        $html = mb_convert_encoding('<head><title>Café “quoted”</title></head>', 'Windows-1252', 'UTF-8');

        Assert::same(PageDocument::parse($html, 'iso-8859-1')->title(), 'Café “quoted”');
        Assert::same(PageDocument::parse($html, 'latin1')->title(), 'Café “quoted”');
        Assert::same(PageDocument::parse($html, 'US-ASCII')->title(), 'Café “quoted”');
        Assert::same(PageDocument::parse($html, 'ascii')->title(), 'Café “quoted”');
    }

    public function blankTransportCharsetFallsBackToSniffing(): void
    {
        $html = mb_convert_encoding('<head><meta charset="windows-1251"><title>Тест</title></head>', 'Windows-1251', 'UTF-8');

        Assert::same(PageDocument::parse($html, '  ')->title(), 'Тест');
    }

    public function unknownCharsetFallsBackToUtf8(): void
    {
        Assert::same(PageDocument::parse('<head><title>Tést</title></head>', 'x-no-such-charset')->title(), 'Tést');
    }

    public function sniffingLooksOnlyAtTheHead(): void
    {
        $html = '<head><title>Tést</title></head>' . str_repeat(' ', 5000) . '<meta charset="windows-1251">';

        Assert::same(PageDocument::parse($html)->title(), 'Tést');
    }

    public function invalidUtf8IsTolerated(): void
    {
        $title = (string) PageDocument::parse("<head><title>ab\xC3\x28cd</title></head>")->title();

        // how a broken byte is replaced differs between PHP versions
        Assert::true(mb_check_encoding($title, 'UTF-8'));
        Assert::true(str_starts_with($title, 'ab') && str_ends_with($title, '(cd'));
    }

    public function readsBomPrefixedUtf8(): void
    {
        Assert::same(PageDocument::parse("\xEF\xBB\xBF<head><title>Tést</title></head>")->title(), 'Tést');
    }

    public function readsUtf16WithBom(): void
    {
        $utf16le = "\xFF\xFE" . mb_convert_encoding('<head><title>Tést</title></head>', 'UTF-16LE', 'UTF-8');
        $utf16be = "\xFE\xFF" . mb_convert_encoding('<head><title>Tést</title></head>', 'UTF-16BE', 'UTF-8');

        Assert::same(PageDocument::parse($utf16le)->title(), 'Tést');
        Assert::same(PageDocument::parse($utf16be)->title(), 'Tést');
    }

    public function scriptContentsAreNotMarkup(): void
    {
        $document = PageDocument::parse(Fixtures::read('broken.html'));

        Assert::same($document->meta('og:title'), []);
        Assert::same($document->meta('og:description'), []);
    }

    public function externalEntitiesAreNotLoaded(): void
    {
        $document = PageDocument::parse(Fixtures::read('xxe.html'));

        Assert::false(str_contains((string) $document->title(), 'root:'));
        Assert::false(str_contains(implode('|', $document->meta('og:title')), 'root:'));
    }

    public function brokenMarkupDoesNotThrow(): void
    {
        $document = PageDocument::parse('<html><head><title>T</title><body><div><p>unclosed <b><i>tags <img src=/x.png width=200>');

        Assert::same($document->title(), 'T');
        Assert::same($document->images(), [['src' => '/x.png', 'width' => 200, 'height' => null]]);
    }

    public function leavesInternalErrorHandlingDisabledWhenItWas(): void
    {
        $before = libxml_use_internal_errors(use_errors: false);

        try {
            PageDocument::parse('<p><nonsense></p></div>');

            Assert::false(libxml_use_internal_errors());
            Assert::same(libxml_get_errors(), []);
        } finally {
            libxml_use_internal_errors($before);
        }
    }

    public function leavesInternalErrorHandlingEnabledWhenItWas(): void
    {
        $before = libxml_use_internal_errors(use_errors: true);

        try {
            PageDocument::parse('<p><nonsense></p></div>');

            Assert::true(libxml_use_internal_errors());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($before);
        }
    }

    public function parseErrorsDoNotWarn(): void
    {
        $warnings = 0;
        set_error_handler(static function () use (&$warnings): bool {
            ++$warnings;

            return true;
        });

        try {
            PageDocument::parse('<p><nonsense></p></div><tag attr=">');
        } finally {
            restore_error_handler();
        }

        Assert::same($warnings, 0);
    }
}
