<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Extract;

use Rasuvaeff\LinkPreview\Extract\Values;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Values::class)]
final class ValuesTest
{
    #[DataProvider('timeProvider')]
    public function parsesTime(?string $value, ?string $expected): void
    {
        Assert::same(Values::time($value)?->format('Y-m-d\TH:i:sP'), $expected);
    }

    public static function timeProvider(): iterable
    {
        yield 'null' => [null, null];
        yield 'offset' => ['2026-10-01T12:30:00+03:00', '2026-10-01T12:30:00+03:00'];
        yield 'zulu' => ['2026-10-01T12:30:00Z', '2026-10-01T12:30:00+00:00'];
        yield 'no offset is UTC' => ['2026-10-01T12:30:00', '2026-10-01T12:30:00+00:00'];
        yield 'space separator' => ['2026-10-01 12:30', '2026-10-01T12:30:00+00:00'];
        yield 'date only' => ['2026-10-01', '2026-10-01T00:00:00+00:00'];
        yield 'fractional seconds' => ['2026-10-01T12:30:00.123Z', '2026-10-01T12:30:00+00:00'];
        yield 'relative word' => ['tomorrow', null];
        yield 'garbage after the date' => ['2026-10-01 nonsense', null];
        yield 'impossible month' => ['2026-13-45T00:00:00Z', null];
        yield 'not a date' => ['yesterday', null];
        yield 'empty' => ['', null];
        yield 'leading text' => ['at 2026-10-01', null];
        yield 'trailing newline after a date' => ["2026-10-01\n", null];
    }

    #[DataProvider('languageProvider')]
    public function normalisesLanguage(?string $value, ?string $expected): void
    {
        Assert::same(Values::language($value), $expected);
    }

    public static function languageProvider(): iterable
    {
        yield 'null' => [null, null];
        yield 'og locale' => ['ru_RU', 'ru-RU'];
        yield 'lower region' => ['en-us', 'en-US'];
        yield 'upper language' => ['EN', 'en'];
        yield 'three letters' => ['fil', 'fil'];
        yield 'script subtag' => ['zh-Hant-TW', 'zh-Hant-TW'];
        yield 'numeric region' => ['es-419', 'es-419'];
        yield 'surrounding space' => [' de ', 'de'];
        yield 'one letter' => ['e', null];
        yield 'garbage' => ['not a locale!', null];
        yield 'empty' => ['', null];
        yield 'trailing newline' => ["en\n", 'en'];
        yield 'embedded newline' => ["en\nUS", null];
        yield 'dangling dash' => ['en-', null];
        yield 'long subtag' => ['en-abcdefghi', null];
    }

    #[DataProvider('nameProvider')]
    public function acceptsNamesNotLinks(?string $value, ?string $expected): void
    {
        Assert::same(Values::name($value), $expected);
    }

    public static function nameProvider(): iterable
    {
        yield 'null' => [null, null];
        yield 'name' => ['Jane Doe', 'Jane Doe'];
        yield 'https link' => ['https://example.com/jane', null];
        yield 'http link' => ['HTTP://example.com/jane', null];
        yield 'any scheme' => ['ftp://example.com', null];
        yield 'name with a colon' => ['Doe: Jane', 'Doe: Jane'];
        yield 'handle' => ['@jane', '@jane'];
    }
}
