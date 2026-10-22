<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Support;

use Rasuvaeff\LinkPreview\Extract\Schemas;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;

/**
 * Answers generated from the very schemas the package parses them with.
 *
 * @internal
 */
final readonly class SchemaGenerators
{
    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function oEmbed(): array
    {
        return ['answer' => Schemas::oEmbed()->arbitrary()];
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function wikipedia(): array
    {
        return ['answer' => Schemas::wikipediaSummary()->arbitrary()];
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function rutube(): array
    {
        return ['answer' => Schemas::rutubeVideo()->arbitrary()];
    }
}
