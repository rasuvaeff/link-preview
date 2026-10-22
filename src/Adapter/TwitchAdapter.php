<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Adapter;

use InvalidArgumentException;
use Rasuvaeff\LinkPreview\Extract\Metadata;
use Rasuvaeff\Result\Result;

/**
 * Twitch channels, videos and clips through oEmbed. A Twitch player refuses to
 * load unless the embedding page names itself in `parent`, so the embed URL is
 * built only when the application passes the domains it embeds from.
 *
 * Needs nothing.
 *
 * @api
 */
final readonly class TwitchAdapter implements Adapter
{
    private const string ENDPOINT = 'https://api.twitch.tv/v4/oembed';

    /** @var list<string> */
    private array $parents;

    /**
     * @param list<string> $parents domains the player will be embedded from
     */
    public function __construct(array $parents = [])
    {
        foreach ($parents as $parent) {
            if (preg_match('/\A[a-z0-9]([a-z0-9.-]*[a-z0-9])?\z/', $parent) !== 1) {
                throw new InvalidArgumentException(\sprintf('Invalid Twitch parent "%s"', $parent));
            }
        }

        $this->parents = $parents;
    }

    #[\Override]
    public function domains(): array
    {
        return ['twitch.tv'];
    }

    #[\Override]
    public function preview(string $url, AdapterContext $context): Result
    {
        $service = OEmbedSupport::fetch($context, OEmbedSupport::endpoint(self::ENDPOINT, ['url' => $url]));

        if ($service->isErr()) {
            return $service;
        }

        return Result::ok(value: OEmbedSupport::with(new Metadata(embedUrl: $this->embed($url)), $service->unwrap()));
    }

    /**
     * @return non-empty-string|null
     */
    private function embed(string $url): ?string
    {
        if ($this->parents === []) {
            return null;
        }

        $parts = OEmbedSupport::parts($url);
        $parent = implode('', array_map(static fn(string $domain): string => '&parent=' . $domain, $this->parents));

        if ($parts['host'] === 'clips.twitch.tv' && preg_match('~\A/([A-Za-z0-9_-]+)\z~', $parts['path'], $m) === 1) {
            return 'https://clips.twitch.tv/embed?clip=' . $m[1] . $parent;
        }

        if (preg_match('~\A/(?:[A-Za-z0-9_]+/)?clip/([A-Za-z0-9_-]+)\z~', $parts['path'], $m) === 1) {
            return 'https://clips.twitch.tv/embed?clip=' . $m[1] . $parent;
        }

        if (preg_match('~\A/videos/(\d+)\z~', $parts['path'], $m) === 1) {
            return 'https://player.twitch.tv/?video=v' . $m[1] . $parent;
        }

        if (preg_match('~\A/([A-Za-z0-9_]{1,25})\z~', $parts['path'], $m) === 1) {
            return 'https://player.twitch.tv/?channel=' . strtolower($m[1]) . $parent;
        }

        return null;
    }
}
