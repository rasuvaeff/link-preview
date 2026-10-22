<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview;

use InvalidArgumentException;

/**
 * Bounds on what a preview may carry into a template or a database.
 *
 * Text is scrubbed of invalid UTF-8 and control characters, runs of whitespace
 * collapse to one space, and what is still too long is cut at a character (not
 * byte) boundary with a trailing `…` that counts toward the limit. Applying
 * the limits twice changes nothing. An image or favicon that is not http(s),
 * is longer than `maxUrlLength`, or — with `httpsImagesOnly` — is plain http,
 * is dropped.
 *
 * @api
 */
final readonly class Limits
{
    private const string ELLIPSIS = '…';

    /**
     * @param int $titleLength in characters, at least 1
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
        return new PageInfo(
            url: $info->url,
            title: $this->text($info->title, $this->titleLength),
            description: $this->text($info->description, $this->descriptionLength),
            image: $this->imageUrl($info->image),
            favicon: $this->imageUrl($info->favicon),
            siteName: $this->text($info->siteName, $this->siteNameLength),
            language: $info->language,
            authorName: $this->text($info->authorName, $this->authorNameLength),
            publishedTime: $info->publishedTime,
            type: $info->type,
            embedUrl: $this->imageUrl($info->embedUrl),
        );
    }

    /**
     */
    private function text(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim(preg_replace('/[\s\p{Cc}]+/u', ' ', mb_scrub($value, 'UTF-8')) ?? '');

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text, 'UTF-8') <= $limit) {
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
