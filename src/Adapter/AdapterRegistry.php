<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Adapter;

/**
 * Host -> adapter. The first adapter whose domains match the host wins.
 *
 * @api
 */
final readonly class AdapterRegistry
{
    /**
     * @param list<Adapter> $adapters
     */
    public function __construct(private array $adapters = []) {}

    /**
     * The eleven built-in adapters. Tokens are optional here: without one,
     * Twitter/X still works and Instagram/Facebook answer
     * {@see AdapterFailure::MissingToken}.
     *
     * @param list<string> $twitchParents domains allowed to embed Twitch players (the `parent` parameter)
     */
    public static function standard(
        #[\SensitiveParameter]
        ?string $twitterToken = null,
        #[\SensitiveParameter]
        ?string $instagramToken = null,
        #[\SensitiveParameter]
        ?string $facebookToken = null,
        array $twitchParents = [],
    ): self {
        return new self([
            new YouTubeAdapter(),
            new TikTokAdapter(),
            new TwitterAdapter(accessToken: $twitterToken),
            new TwitchAdapter(parents: $twitchParents),
            new WikipediaAdapter(),
            new GitHubAdapter(),
            new InstagramAdapter(accessToken: $instagramToken),
            new FacebookAdapter(accessToken: $facebookToken),
            new TelegramAdapter(),
            new VkAdapter(),
            new RutubeAdapter(),
        ]);
    }

    public function find(string $host): ?Adapter
    {
        foreach ($this->adapters as $adapter) {
            if (HostMatcher::matches($host, $adapter->domains())) {
                return $adapter;
            }
        }

        return null;
    }
}
