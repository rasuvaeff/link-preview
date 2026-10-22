<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Adapter;

use Rasuvaeff\Result\Result;

/**
 * TikTok through its public oEmbed endpoint. Needs nothing.
 *
 * @api
 */
final readonly class TikTokAdapter implements Adapter
{
    private const string ENDPOINT = 'https://www.tiktok.com/oembed';

    #[\Override]
    public function domains(): array
    {
        return ['tiktok.com'];
    }

    #[\Override]
    public function preview(string $url, AdapterContext $context): Result
    {
        return OEmbedSupport::fetch($context, OEmbedSupport::endpoint(self::ENDPOINT, ['url' => $url]));
    }
}
