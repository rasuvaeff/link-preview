<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Extract;

use Rasuvaeff\LinkPreview\Html\PageDocument;
use Rasuvaeff\Result\Result;

/**
 * Reads Twitter (X) Cards: `twitter:title`, `twitter:description`, `twitter:image`
 * with `twitter:image:width` / `twitter:image:height`.
 *
 * @api
 */
final readonly class TwitterCardsExtractor implements Extractor
{
    #[\Override]
    public function extract(PageDocument $document, string $pageUrl): Result
    {
        $urls = new UrlContext($document, $pageUrl);

        $image = $urls->first([...$document->meta('twitter:image'), ...$document->meta('twitter:image:src')]);

        $metadata = new Metadata(
            title: $document->meta('twitter:title')[0] ?? null,
            description: $document->meta('twitter:description')[0] ?? null,
            image: $image,
            imageWidth: $image === null ? null : Values::dimension($document->meta('twitter:image:width')[0] ?? null),
            imageHeight: $image === null ? null : Values::dimension($document->meta('twitter:image:height')[0] ?? null),
        );

        return $metadata->isEmpty() ? Result::err(error: ExtractFailure::NoMetadata) : Result::ok(value: $metadata);
    }
}
