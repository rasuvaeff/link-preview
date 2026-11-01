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

    #[DataProvider('dimensionProvider')]
    public function readsImageSides(mixed $value, ?int $expected): void
    {
        Assert::same(Values::dimension($value), $expected);
    }

    public static function dimensionProvider(): iterable
    {
        yield 'null' => [null, null];
        yield 'int' => [1200, 1200];
        yield 'one' => [1, 1];
        yield 'zero' => [0, null];
        yield 'negative' => [-5, null];
        yield 'largest' => [99_999, 99_999];
        yield 'too large' => [100_000, null];
        yield 'integral float' => [480.0, 480];
        yield 'float one' => [1.0, 1];
        yield 'float below one' => [0.5, null];
        yield 'fractional float' => [480.5, null];
        yield 'zero float' => [0.0, null];
        yield 'huge float' => [1e9, null];
        yield 'digits' => ['630', 630];
        yield 'pixels' => ['630px', 630];
        yield 'padded' => [' 630 ', 630];
        yield 'zero string' => ['0', null];
        yield 'percent' => ['100%', null];
        yield 'trailing newline' => ["630\n", 630];
        yield 'words' => ['wide', null];
        yield 'quantitative value' => [['@type' => 'QuantitativeValue', 'value' => 1200], 1200];
        yield 'quantitative value as text' => [['value' => '1200'], 1200];
        yield 'nested value' => [['value' => ['value' => 1]], null];
        yield 'object without value' => [['unitCode' => 'E37'], null];
        yield 'bool' => [true, null];
    }
}
