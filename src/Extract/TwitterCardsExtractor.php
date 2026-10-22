<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Extract;

use Rasuvaeff\LinkPreview\Html\PageDocument;
use Rasuvaeff\Result\Result;

/**
 * Reads Twitter (X) Cards: `twitter:title`, `twitter:description`, `twitter:image`.
 *
 * @api
 */
final readonly class TwitterCardsExtractor implements Extractor
{
    #[\Override]
    public function extract(PageDocument $document, string $pageUrl): Result
    {
        $urls = new UrlContext($document, $pageUrl);

        $metadata = new Metadata(
            title: $document->meta('twitter:title')[0] ?? null,
            description: $document->meta('twitter:description')[0] ?? null,
            image: $urls->first([...$document->meta('twitter:image'), ...$document->meta('twitter:image:src')]),
        );

        return $metadata->isEmpty() ? Result::err(error: ExtractFailure::NoMetadata) : Result::ok(value: $metadata);
    }
}
