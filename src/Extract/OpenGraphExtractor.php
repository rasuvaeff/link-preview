<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Extract;

use Rasuvaeff\LinkPreview\Html\PageDocument;
use Rasuvaeff\Result\Result;

/**
 * Reads the OpenGraph protocol: `og:*` and `article:*` meta tags.
 *
 * @api
 */
final readonly class OpenGraphExtractor implements Extractor
{
    #[\Override]
    public function extract(PageDocument $document, string $pageUrl): Result
    {
        $urls = new UrlContext($document, $pageUrl);

        $metadata = new Metadata(
            url: $urls->first($document->meta('og:url')),
            title: $document->meta('og:title')[0] ?? null,
            description: $document->meta('og:description')[0] ?? null,
            image: $urls->first([...$document->meta('og:image:secure_url'), ...$document->meta('og:image'), ...$document->meta('og:image:url')]),
            siteName: $document->meta('og:site_name')[0] ?? null,
            language: Values::language($document->meta('og:locale')[0] ?? null),
            authorName: Values::name($document->meta('article:author')[0] ?? null),
            publishedTime: Values::time($document->meta('article:published_time')[0] ?? null),
            type: $document->meta('og:type')[0] ?? null,
        );

        return $metadata->isEmpty() ? Result::err(error: ExtractFailure::NoMetadata) : Result::ok(value: $metadata);
    }
}
