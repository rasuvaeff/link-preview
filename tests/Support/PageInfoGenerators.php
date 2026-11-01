<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Rasuvaeff\LinkPreview\PageInfo;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;

/**
 * Valid `PageInfo` arrays, built rather than filtered: the URLs come from a list of
 * absolute http(s) ones, the dimensions only travel with an image.
 *
 * @internal
 */
final readonly class PageInfoGenerators
{
    private const array URLS = [
        'https://example.com/',
        'http://example.com/a?b=1',
        'https://xn--mnchen-3ya.de/p',
        'https://[2001:db8::1]/v',
        'https://cdn.example.net/i.png',
    ];

    private const array OFFSETS = ['UTC', '+03:00', '-05:30', 'Europe/Moscow'];

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function pageArray(): array
    {
        return ['data' => self::data()];
    }

    public static function data(): ArbitraryInterface
    {
        $text = Gen::nullable(Gen::stringFrom("ab &<>\"'é😀\n", 0, 12));
        $url = Gen::elements(self::URLS);
        $side = Gen::nullable(Gen::intBetween(1, 99_999));

        return Gen::map(
            Gen::record([
                'url' => $url,
                'title' => $text,
                'description' => $text,
                'image' => Gen::nullable($url),
                'favicon' => Gen::nullable($url),
                'siteName' => $text,
                'language' => Gen::nullable(Gen::elements(['en', 'ru-RU', 'de-AT'])),
                'authorName' => $text,
                'publishedTime' => Gen::nullable(Gen::tuple(Gen::intBetween(0, 4_000_000_000), Gen::elements(self::OFFSETS))),
                'type' => Gen::nullable(Gen::elements(['article', 'video', 'video.other'])),
                'embedUrl' => Gen::nullable($url),
                'canonicalUrl' => Gen::nullable($url),
                'imageWidth' => $side,
                'imageHeight' => $side,
                'providerName' => $text,
            ]),
            static function (array $data): array {
                /** @var array{0: int, 1: string}|null $time */
                $time = $data['publishedTime'];
                $data['publishedTime'] = $time === null
                    ? null
                    : (new DateTimeImmutable('@' . $time[0]))->setTimezone(new DateTimeZone($time[1]))->format(DateTimeInterface::ATOM);

                if ($data['image'] === null) {
                    $data['imageWidth'] = null;
                    $data['imageHeight'] = null;
                }

                return $data;
            },
        );
    }

    /**
     * The keys of a full `PageInfo::toArray()`, in order.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys((new PageInfo(url: 'https://example.com/'))->toArray());
    }
}
