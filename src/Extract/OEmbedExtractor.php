<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Extract;

use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\Html\PageDocument;
use Rasuvaeff\Result\Result;

/**
 * oEmbed discovery: follows `<link rel="alternate" type="application/json+oembed">`
 * to the endpoint the page advertises and reads the JSON it returns.
 *
 * The endpoint is an address chosen by the page, so it is fetched through the
 * same {@see Fetcher} as the page itself: the same `UrlPolicy`, the same
 * redirect checks, the same size limit. A page cannot point this extractor at
 * a place the original URL could not have reached. Only the first endpoint is
 * tried. This extractor performs a request of its own.
 *
 * @api
 */
final readonly class OEmbedExtractor implements Extractor
{
    public const string DISCOVERY_TYPE = 'application/json+oembed';

    public function __construct(private Fetcher $fetcher) {}

    #[\Override]
    public function extract(PageDocument $document, string $pageUrl): Result
    {
        $endpoint = (new UrlContext($document, $pageUrl))->first($document->alternates(self::DISCOVERY_TYPE));

        if ($endpoint === null) {
            return Result::err(error: ExtractFailure::NoMetadata);
        }

        $fetched = $this->fetcher->fetchJson(url: $endpoint);

        if ($fetched->isErr()) {
            return Result::err(error: ExtractFailure::Unavailable);
        }

        $page = $fetched->unwrap();
        $metadata = OEmbedResponse::metadata(json: $page->body, baseUrl: $page->url);

        return $metadata === null ? Result::err(error: ExtractFailure::InvalidData) : Result::ok(value: $metadata);
    }
}
