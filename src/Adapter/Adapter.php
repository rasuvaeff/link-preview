<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Adapter;

use Rasuvaeff\LinkPreview\Extract\Metadata;
use Rasuvaeff\Result\Result;

/**
 * Knows one site: asks the service behind it (an oEmbed endpoint, an API) or
 * reads its pages in the way that site needs, and answers with {@see Metadata}.
 *
 * Every request an adapter makes goes through the {@see AdapterContext}, i.e.
 * through the {@see \Rasuvaeff\LinkPreview\Fetch\Fetcher} and its URL policy.
 *
 * @api
 */
interface Adapter
{
    /**
     * Registrable domains the adapter serves, lower-case. A host matches a domain
     * when it is that domain or a subdomain of it, label by label.
     *
     * @return list<non-empty-string>
     */
    public function domains(): array;

    /**
     * @param non-empty-string $url a URL already accepted by the URL policy, normalised
     *
     * @return Result<Metadata, AdapterFailure>
     */
    public function preview(string $url, AdapterContext $context): Result;
}
