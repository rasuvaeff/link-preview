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
            'text' => Gen::stringFrom("ab c\t\n\u{00A0}\u{0007}é中😀  ", 0, 80),
            'limit' => Gen::intBetween(1, 40),
        ];
    }
}
