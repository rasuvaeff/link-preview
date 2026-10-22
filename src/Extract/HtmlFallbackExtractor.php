<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Extract;

use Rasuvaeff\LinkPreview\Html\PageDocument;
use Rasuvaeff\Result\Result;

/**
 * Plain HTML, for pages without OpenGraph or Twitter Cards: `<title>`,
 * `<meta name="description">`, `<link rel="canonical">`, `<link rel="icon">`,
 * `<meta name="author">`, the `lang` of the root and the first image large
 * enough to be content.
 *
 * Images with a declared side under {@see self::MIN_IMAGE_SIDE} pixels are
 * skipped as icons and tracking pixels; an image without declared dimensions
 * is taken on trust.
 *
 * @api
 */
final readonly class HtmlFallbackExtractor implements Extractor
{
    public const int MIN_IMAGE_SIDE = 100;

    #[\Override]
    public function extract(PageDocument $document, string $pageUrl): Result
    {
        $urls = new UrlContext($document, $pageUrl);

        $metadata = new Metadata(
            url: $urls->first($document->links('canonical')),
            title: $document->title(),
            description: $document->meta('description')[0] ?? null,
            image: $this->image($document, $urls),
            favicon: $urls->first([...$document->links('icon'), ...$document->links('apple-touch-icon')]),
            language: Values::language($document->language()),
            authorName: Values::name($document->meta('author')[0] ?? null),
        );

        return $metadata->isEmpty() ? Result::err(error: ExtractFailure::NoMetadata) : Result::ok(value: $metadata);
    }

    /**
     * @return non-empty-string|null
     */
    private function image(PageDocument $document, UrlContext $urls): ?string
    {
        foreach ($document->images() as $image) {
            if (
                ($image['width'] !== null && $image['width'] < self::MIN_IMAGE_SIDE)
                || ($image['height'] !== null && $image['height'] < self::MIN_IMAGE_SIDE)
            ) {
                continue;
            }

            $resolved = $urls->resolve($image['src']);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }
}
