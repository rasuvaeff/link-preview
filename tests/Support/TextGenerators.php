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
}
