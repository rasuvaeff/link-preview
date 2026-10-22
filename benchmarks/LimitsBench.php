<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Benchmarks;

use Rasuvaeff\LinkPreview\Limits;
use Rasuvaeff\LinkPreview\PageInfo;
use Testo\Bench;

/**
 * Applying limits to short text against clipping a long one.
 */
final class LimitsBench
{
    #[Bench(
        callables: ['clip_long_text' => [self::class, 'clipLongText']],
        calls: 2_000,
        iterations: 5,
    )]
    public static function shortText(): ?string
    {
        return (new Limits())->apply(new PageInfo(url: 'https://example.com/', description: 'A short description'))->description;
    }

    public static function clipLongText(): ?string
    {
        $text = str_repeat("Длинный  текст\twith mixed words ", 40);

        return (new Limits())->apply(new PageInfo(url: 'https://example.com/', description: $text))->description;
    }
}
