<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Support;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;

/**
 * @internal
 */
final readonly class TextGenerators
{
    /**
     * Text with runs of whitespace, control characters and multibyte letters.
     *
     * @return array<string, ArbitraryInterface>
     */
    public static function clip(): array
    {
        return [
            // Blank text is its own branch: drawn from the full alphabet it
            // only appears by chance (~1%), below the 1% coverage gate.
            'text' => Gen::frequency([
                [9, Gen::stringFrom("ab c\t\n\u{00A0}\u{0007}é中😀  ", 0, 80)],
                [1, Gen::stringFrom(" \t\n\u{0007}", 0, 8)],
            ]),
            'limit' => Gen::intBetween(1, 40),
        ];
    }

    /**
     * Text the way pages write it: words, whitespace and control characters, entities
     * (double-escaped ones too), tags, and the loose `<`, `>`, `&`, `;` and letters that
     * glue into new tags and entities. One draw in ten is escaped so deep that only the
     * full-width fallback can finish it.
     *
     * @return array<string, ArbitraryInterface>
     */
    public static function markup(): array
    {
        return ['text' => self::markupText()];
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function clippedMarkup(): array
    {
        return ['text' => self::markupText(), 'limit' => Gen::intBetween(1, 40)];
    }

    public static function markupText(): ArbitraryInterface
    {
        return Gen::frequency([
            [9, Gen::map(Gen::arrayOf(Gen::elements(self::TOKENS), 0, 40), static fn(array $tokens): string => implode('', $tokens))],
            [1, Gen::map(
                Gen::intBetween(3, 8),
                static fn(int $depth): string => '&' . str_repeat('amp;', $depth) . 'lt;b&' . str_repeat('amp;', $depth) . 'gt; x',
            )],
        ]);
    }

    private const array TOKENS = [
        'a', 'b', 'p', 'é', '中', '😀', ' ', '  ', "\t", "\n", "\u{00A0}", "\u{0007}", "\u{0000}",
        '&amp;', '&lt;', '&gt;', '&quot;', '&#39;', '&#60;', '&#x3E;', '&nbsp;', '&amp;amp;', '&amp;lt;', '&amp;quot;', '&foo;', '&#0;',
        '<b>', '</b>', '<br/>', '<p class="x">', '<!-- c -->', '<!DOCTYPE html>', '<?php ?>',
        '<', '>', '&', ';', 'amp', 'lt', 'gt', '#', '/', '=', '"', '!', '?', '<3', 'a < b',
    ];
}
