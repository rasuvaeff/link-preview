<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Adapter;

use Rasuvaeff\LinkPreview\Extract\HtmlFallbackExtractor;
use Rasuvaeff\LinkPreview\Extract\Metadata;
use Rasuvaeff\LinkPreview\Extract\MetadataMerger;
use Rasuvaeff\LinkPreview\Extract\OpenGraphExtractor;
use Rasuvaeff\LinkPreview\Extract\TwitterCardsExtractor;
use Rasuvaeff\LinkPreview\Html\PageDocument;
use Rasuvaeff\Result\Result;

/**
 * The page-reading adapters (GitHub, Telegram, VK) start from what the page
 * itself says in OpenGraph, Twitter Cards and HTML.
 *
 * @internal
 */
final readonly class PageMetadata
{
    private function __construct() {}

    /**
     * @param non-empty-string $url
     *
     * @return Result<Metadata, AdapterFailure>
     */
    public static function read(AdapterContext $context, string $url): Result
    {
        $fetched = $context->html($url);

        if ($fetched->isErr()) {
            return Result::err(error: AdapterFailure::Unavailable);
        }

        $page = $fetched->unwrap();
        $document = PageDocument::parse(html: $page->body, charset: $page->charset);
        $found = [];

        foreach ([new OpenGraphExtractor(), new TwitterCardsExtractor(), new HtmlFallbackExtractor()] as $extractor) {
            $metadata = $extractor->extract(document: $document, pageUrl: $page->url)->value();

            if ($metadata instanceof Metadata) {
                $found[] = $metadata;
            }
        }

        $merged = MetadataMerger::merge($found);

        return $merged->isEmpty() ? Result::err(error: AdapterFailure::InvalidResponse) : Result::ok(value: $merged);
    }
}
