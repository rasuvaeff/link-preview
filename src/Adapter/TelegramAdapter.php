<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Adapter;

use Rasuvaeff\LinkPreview\Extract\Metadata;
use Rasuvaeff\Result\Result;

/**
 * Telegram channels and posts (`t.me/<channel>/<id>`) from the page's
 * OpenGraph tags, plus the embeddable post widget
 * (`https://t.me/<channel>/<id>?embed=1&mode=tme`). Needs nothing, but Telegram
 * serves its OpenGraph tags to link-preview crawlers only: give the `Fetcher` a
 * `UserAgentMap` entry for `t.me` such as `TelegramBot (like TwitterBot)`.
 *
 * @api
 */
final readonly class TelegramAdapter implements Adapter
{
    #[\Override]
    public function domains(): array
    {
        return ['t.me', 'telegram.me'];
    }

    #[\Override]
    public function preview(string $url, AdapterContext $context): Result
    {
        $page = PageMetadata::read($context, $url);

        if ($page->isErr()) {
            return $page;
        }

        $parts = OEmbedSupport::parts($url);
        $embed = preg_match('~\A/(?:s/)?([A-Za-z][A-Za-z0-9_]{3,31})/(\d+)\z~', $parts['path'], $m) === 1
            ? 'https://t.me/' . $m[1] . '/' . $m[2] . '?embed=1&mode=tme'
            : null;

        return Result::ok(value: OEmbedSupport::with(new Metadata(siteName: 'Telegram', embedUrl: $embed), $page->unwrap()));
    }
}
