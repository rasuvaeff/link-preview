<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Adapter;

use Rasuvaeff\Result\Result;

/**
 * Instagram posts through the Graph API `instagram_oembed` endpoint, which
 * requires an access token. Without one the adapter answers
 * {@see AdapterFailure::MissingToken} and makes no request.
 *
 * The token travels as a bearer `Authorization` header to `graph.facebook.com`
 * only; it is not part of any URL, so it cannot leak into a log of requests or
 * into a redirect.
 *
 * @api
 */
final readonly class InstagramAdapter implements Adapter
{
    private const string ENDPOINT = 'https://graph.facebook.com/v21.0/instagram_oembed';

    public function __construct(
        #[\SensitiveParameter]
        private ?string $accessToken = null,
    ) {}

    #[\Override]
    public function domains(): array
    {
        return ['instagram.com', 'instagr.am'];
    }

    #[\Override]
    public function preview(string $url, AdapterContext $context): Result
    {
        if ($this->accessToken === null || $this->accessToken === '') {
            return Result::err(error: AdapterFailure::MissingToken);
        }

        return OEmbedSupport::fetch(
            $context,
            OEmbedSupport::endpoint(self::ENDPOINT, ['url' => $url, 'omit_script' => 'true']),
            ['Authorization' => 'Bearer ' . $this->accessToken],
        );
    }
}
