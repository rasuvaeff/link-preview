<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview;

use Rasuvaeff\Result\Result;

/**
 * What a link preview service does. {@see PreviewProvider} is the implementation;
 * depend on this interface to put a cache or a rate limit in front of it.
 *
 * Neither method throws on input from a user or from the network.
 *
 * @api
 */
interface LinkPreviewer
{
    /**
     * Previews a URL a user gave.
     *
     * @return Result<PageInfo, PreviewFailure>
     */
    public function preview(string $url): Result;

    /**
     * Previews a document already in hand, as if it had been fetched from `$pageUrl`.
     * A `$pageUrl` the URL policy refuses (relative, private, not http(s)) is
     * {@see PreviewFailureReason::PolicyRejected}.
     *
     * @return Result<PageInfo, PreviewFailure>
     */
    public function fromHtml(string $html, string $pageUrl, ?string $charset = null): Result;
}
