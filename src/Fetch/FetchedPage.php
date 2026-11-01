<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Fetch;

/**
 * A fetched body - an HTML page, a JSON answer or a media file - and where it came from.
 *
 * @api
 */
final readonly class FetchedPage
{
    /**
     * @param non-empty-string $url the URL the body came from, after redirects
     * @param string $contentType media type without parameters, lower-case
     * @param int<0, max> $redirects number of redirects followed
     */
    public function __construct(
        public string $url,
        public string $body,
        public string $contentType,
        public ?string $charset = null,
        public int $redirects = 0,
    ) {}
}
