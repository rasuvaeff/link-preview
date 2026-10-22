<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Benchmarks;

use Rasuvaeff\LinkPreview\Extract\Metadata;
use Rasuvaeff\LinkPreview\Extract\MetadataMerger;
use Testo\Bench;

/**
 * Merging one source against merging three.
 */
final class MetadataMergerBench
{
    #[Bench(
        callables: ['three_sources' => [self::class, 'threeSources']],
        calls: 20_000,
        iterations: 5,
    )]
    public static function oneSource(): ?string
    {
        return MetadataMerger::merge([new Metadata(title: 'A', image: 'https://a.example/i.png')])->title;
    }

    public static function threeSources(): ?string
    {
        return MetadataMerger::merge([
            new Metadata(title: 'A', image: 'https://a.example/i.png'),
            new Metadata(title: 'B', description: 'B'),
            new Metadata(title: 'C', favicon: 'https://c.example/f.ico', siteName: 'C'),
        ])->title;
    }
}
