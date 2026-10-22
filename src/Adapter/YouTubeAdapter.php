<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Adapter;

use Rasuvaeff\LinkPreview\Extract\Metadata;
use Rasuvaeff\Result\Result;

/**
 * YouTube through its public oEmbed endpoint. The embed URL is built from the
 * video id found in `watch?v=`, `youtu.be/`, `/shorts/`, `/live/` and `/embed/` links.
 *
 * Needs nothing.
 *
 * @api
 */
final readonly class YouTubeAdapter implements Adapter
{
    private const string ENDPOINT = 'https://www.youtube.com/oembed';

    #[\Override]
    public function domains(): array
    {
        return ['youtube.com', 'youtu.be', 'youtube-nocookie.com'];
    }

    #[\Override]
    public function preview(string $url, AdapterContext $context): Result
    {
        $service = OEmbedSupport::fetch($context, OEmbedSupport::endpoint(self::ENDPOINT, ['url' => $url, 'format' => 'json']));

        if ($service->isErr()) {
            return $service;
        }

        $id = $this->videoId($url);
        $own = new Metadata(embedUrl: $id === null ? null : 'https://www.youtube.com/embed/' . $id);

        return Result::ok(value: OEmbedSupport::with($own, $service->unwrap()));
    }

    private function videoId(string $url): ?string
    {
        $parts = OEmbedSupport::parts($url);
        $path = $parts['path'];

        if ($parts['host'] === 'youtu.be') {
            $candidate = explode('/', ltrim($path, '/'))[0];
        } elseif (preg_match('~\A/(?:shorts|live|embed|v)/([^/?#]+)~', $path, $matches) === 1) {
            $candidate = $matches[1];
        } else {
            $candidate = $parts['query']['v'] ?? '';
        }

        return preg_match('/\A[A-Za-z0-9_-]{11}\z/', $candidate) === 1 ? $candidate : null;
    }
}
