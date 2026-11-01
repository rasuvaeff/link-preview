<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview;

use InvalidArgumentException;
use Rasuvaeff\LinkPreview\Text\PlainText;

/**
 * Bounds on what a preview may carry into a template or a database.
 *
 * Text comes out clean: invalid UTF-8 is scrubbed, HTML entities are decoded
 * (double escaping such as `&amp;quot;` included), tags are removed, control
 * characters and runs of whitespace collapse to one space - and only then is
 * what is still too long cut at a character (not byte) boundary with a
 * trailing `…` that counts toward the limit. Applying the limits twice changes
 * nothing. An image, favicon or embed URL that is longer than `maxUrlLength`,
 * or - with `httpsImagesOnly` - is plain http, is dropped (an image together
 * with its dimensions); an over-long canonical URL is dropped as well.
 *
 * @api
 */
final readonly class Limits
{
    private const string ELLIPSIS = '…';

    /**
     * @param int $titleLength in characters, at least 1; every length counts the text after cleaning
     * @param int $siteNameLength also bounds `providerName`
     */
    public function __construct(
        private int $titleLength = 200,
        private int $descriptionLength = 500,
        private int $siteNameLength = 100,
        private int $authorNameLength = 100,
        private int $maxUrlLength = 2048,
        private bool $httpsImagesOnly = false,
    ) {
        foreach ([$titleLength, $descriptionLength, $siteNameLength, $authorNameLength, $maxUrlLength] as $limit) {
            if ($limit < 1) {
                throw new InvalidArgumentException('Limits must be positive');
            }
        }
    }

    public function apply(PageInfo $info): PageInfo
    {
        $image = $this->imageUrl($info->image);

        return new PageInfo(
            url: $info->url,
            title: $this->text($info->title, $this->titleLength),
            description: $this->text($info->description, $this->descriptionLength),
            image: $image,
            favicon: $this->imageUrl($info->favicon),
            siteName: $this->text($info->siteName, $this->siteNameLength),
            language: $info->language,
            authorName: $this->text($info->authorName, $this->authorNameLength),
            publishedTime: $info->publishedTime,
            type: $info->type,
            embedUrl: $this->imageUrl($info->embedUrl),
            canonicalUrl: $info->canonicalUrl === null || \strlen($info->canonicalUrl) > $this->maxUrlLength ? null : $info->canonicalUrl,
            imageWidth: $image === null ? null : $info->imageWidth,
            imageHeight: $image === null ? null : $info->imageHeight,
            providerName: $this->text($info->providerName, $this->siteNameLength),
        );
    }

    /**
     * @return non-empty-string|null
     */
    private function text(?string $value, int $limit): ?string
    {
        $text = PlainText::clean($value);

        if ($text === null || mb_strlen($text, 'UTF-8') <= $limit) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $limit - 1, 'UTF-8')) . self::ELLIPSIS;
    }

    private function imageUrl(?string $url): ?string
    {
        if ($url === null || \strlen($url) > $this->maxUrlLength) {
            return null;
        }

        if ($this->httpsImagesOnly && !str_starts_with(strtolower($url), 'https://')) {
            return null;
        }

        return $url;
    }
}
