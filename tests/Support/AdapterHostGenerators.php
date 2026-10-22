<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Support;

use Rasuvaeff\LinkPreview\Adapter\FacebookAdapter;
use Rasuvaeff\LinkPreview\Adapter\GitHubAdapter;
use Rasuvaeff\LinkPreview\Adapter\InstagramAdapter;
use Rasuvaeff\LinkPreview\Adapter\RutubeAdapter;
use Rasuvaeff\LinkPreview\Adapter\TelegramAdapter;
use Rasuvaeff\LinkPreview\Adapter\TikTokAdapter;
use Rasuvaeff\LinkPreview\Adapter\TwitchAdapter;
use Rasuvaeff\LinkPreview\Adapter\TwitterAdapter;
use Rasuvaeff\LinkPreview\Adapter\VkAdapter;
use Rasuvaeff\LinkPreview\Adapter\WikipediaAdapter;
use Rasuvaeff\LinkPreview\Adapter\YouTubeAdapter;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;

/**
 * The adapter host table, written out independently of the adapters, and the
 * ways a host can be a site or only look like one.
 *
 * @internal
 */
final readonly class AdapterHostGenerators
{
    /** @var array<string, class-string> */
    public const array OWNER = [
        'youtube.com' => YouTubeAdapter::class,
        'youtu.be' => YouTubeAdapter::class,
        'youtube-nocookie.com' => YouTubeAdapter::class,
        'tiktok.com' => TikTokAdapter::class,
        'twitter.com' => TwitterAdapter::class,
        'x.com' => TwitterAdapter::class,
        'twitch.tv' => TwitchAdapter::class,
        'wikipedia.org' => WikipediaAdapter::class,
        'github.com' => GitHubAdapter::class,
        'instagram.com' => InstagramAdapter::class,
        'instagr.am' => InstagramAdapter::class,
        'facebook.com' => FacebookAdapter::class,
        'fb.com' => FacebookAdapter::class,
        'fb.watch' => FacebookAdapter::class,
        't.me' => TelegramAdapter::class,
        'telegram.me' => TelegramAdapter::class,
        'vk.com' => VkAdapter::class,
        'vk.ru' => VkAdapter::class,
        'vkvideo.ru' => VkAdapter::class,
        'rutube.ru' => RutubeAdapter::class,
    ];

    /** kinds of host that are the site */
    public const array GENUINE = ['exact', 'www', 'mobile', 'subdomain', 'upper', 'root dot'];

    /** kinds of host that only look like the site */
    public const array LOOKALIKE = ['suffix label', 'glued prefix', 'middle label', 'dashed prefix', 'name only', 'swapped tld'];

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function hosts(): array
    {
        return [
            'domain' => Gen::elements(array_keys(self::OWNER)),
            'kind' => Gen::elements([...self::GENUINE, ...self::LOOKALIKE]),
            'label' => Gen::stringFrom('abcdefghijklmnopqrstuvwxyz0123456789', 1, 8),
        ];
    }

    public static function host(string $domain, string $kind, string $label): string
    {
        return match ($kind) {
            'exact' => $domain,
            'www' => 'www.' . $domain,
            'mobile' => 'm.' . $domain,
            'subdomain' => $label . '.' . $domain,
            'upper' => strtoupper('www.' . $domain),
            'root dot' => $domain . '.',
            'suffix label' => $domain . '.' . $label . '.example',
            'glued prefix' => $label . $domain,
            'middle label' => $label . '.' . $domain . '.' . $label . '.example',
            'dashed prefix' => $label . '-' . $domain,
            'name only' => explode('.', $domain)[0] . '.' . $label . '.example',
            default => explode('.', $domain)[0] . '.invalid',
        };
    }
}
