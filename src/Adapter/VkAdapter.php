<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Adapter;

use Rasuvaeff\LinkPreview\Extract\Metadata;
use Rasuvaeff\Result\Result;

/**
 * VK walls, videos and communities from the page's OpenGraph tags. A video
 * link (`/video-123_456`, `?z=video-123_456`) also gets its embeddable player,
 * `https://vk.com/video_ext.php?oid=-123&id=456`. Needs nothing, but VK serves
 * rich pages to known crawlers only: set a `UserAgentMap` entry for `vk.com`.
 *
 * @api
 */
final readonly class VkAdapter implements Adapter
{
    #[\Override]
    public function domains(): array
    {
        return ['vk.com', 'vk.ru', 'vkvideo.ru'];
    }

    #[\Override]
    public function preview(string $url, AdapterContext $context): Result
    {
        $page = PageMetadata::read($context, $url);

        if ($page->isErr()) {
            return $page;
        }

        return Result::ok(value: OEmbedSupport::with(new Metadata(siteName: 'VK', embedUrl: $this->embed($url)), $page->unwrap()));
    }

    /**
     * @return non-empty-string|null
     */
    private function embed(string $url): ?string
    {
        $parts = OEmbedSupport::parts($url);
        $subject = $parts['path'] . ' ' . ($parts['query']['z'] ?? '');

        if (preg_match('/video(-?\d+)_(\d+)/', $subject, $m) !== 1) {
            return null;
        }

        return 'https://vk.com/video_ext.php?oid=' . $m[1] . '&id=' . $m[2];
    }
}
