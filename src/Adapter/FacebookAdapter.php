<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Adapter;

use Rasuvaeff\Result\Result;

/**
 * Facebook posts, videos and pages through the Graph API oEmbed endpoints
 * (`oembed_post`, `oembed_video`, `oembed_page`), which require an access
 * token. Without one the adapter answers {@see AdapterFailure::MissingToken}
 * and makes no request. The token is sent as a bearer header to
 * `graph.facebook.com` only, never in a URL.
 *
 * @api
 */
final readonly class FacebookAdapter implements Adapter
{
    private const string BASE = 'https://graph.facebook.com/v21.0/';

    public function __construct(
        #[\SensitiveParameter]
        private ?string $accessToken = null,
    ) {}

    #[\Override]
    public function domains(): array
    {
        return ['facebook.com', 'fb.com', 'fb.watch'];
    }

    #[\Override]
    public function preview(string $url, AdapterContext $context): Result
    {
        if ($this->accessToken === null || $this->accessToken === '') {
            return Result::err(error: AdapterFailure::MissingToken);
        }

        return OEmbedSupport::fetch(
            $context,
            OEmbedSupport::endpoint(self::BASE . $this->resource($url), ['url' => $url, 'omit_script' => 'true']),
            ['Authorization' => 'Bearer ' . $this->accessToken],
        );
    }

    private function resource(string $url): string
    {
        $parts = OEmbedSupport::parts($url);

        if ($parts['host'] === 'fb.watch' || preg_match('~/(?:videos?|watch|reel)(?:/|\z)~', $parts['path']) === 1) {
            return 'oembed_video';
        }

        if (preg_match('~/(?:posts|permalink|photos?|story)(?:/|\.php|\z)~', $parts['path']) === 1) {
            return 'oembed_post';
        }

        return 'oembed_page';
    }
}
