<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Benchmarks;

use Rasuvaeff\LinkPreview\Html\PageDocument;
use Testo\Bench;

/**
 * Parsing cost grows with the page: a 50-block page against a 3000-block one.
 */
final class PageDocumentBench
{
    #[Bench(
        callables: ['large_page' => [self::class, 'parseLargePage']],
        calls: 50,
        iterations: 5,
    )]
    public static function parseMediumPage(): int
    {
        return \count(PageDocument::parse(self::page(items: 50))->images());
    }

    public static function parseLargePage(): int
    {
        return \count(PageDocument::parse(self::page(items: 3000))->images());
    }

    private static function page(int $items): string
    {
        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Bench page</title>'
            . '<meta property="og:title" content="Title"><meta property="og:image" content="/i.png">'
            . '<link rel="icon" href="/f.ico"></head><body>';

        for ($i = 0; $i < $items; ++$i) {
            $html .= '<div class="row"><p>Paragraph ' . $i . ' with <b>markup</b> and текст</p><img src="/img/' . $i . '.png" width="200" height="200"></div>';
        }

        return $html . '</body></html>';
    }
}
