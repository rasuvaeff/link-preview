<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Rasuvaeff\LinkPreview\Url\UrlResolver;

/**
 * What is known about a page: the result of a preview.
 *
 * `url` is where the preview was taken from - the fetched URL after redirects,
 * as the URL policy normalised it - and is what a card should link to.
 * `canonicalUrl` is what the page (or the site's service) claims its address
 * is: `og:url`, `<link rel="canonical">`, a JSON-LD `url`. A page writes that
 * itself, so it may name any host; use it to deduplicate, not as a link target.
 *
 * `imageWidth` and `imageHeight` belong to `image`: they come from the same
 * source and are absent without it.
 *
 * @api
 *
 * @psalm-type PageInfoArray = array{
 *     url: non-empty-string,
 *     title: string|null,
 *     description: string|null,
 *     image: string|null,
 *     favicon: string|null,
 *     siteName: string|null,
 *     language: string|null,
 *     authorName: string|null,
 *     publishedTime: string|null,
 *     type: string|null,
 *     embedUrl: string|null,
 *     canonicalUrl: string|null,
 *     imageWidth: int|null,
 *     imageHeight: int|null,
 *     providerName: string|null,
 * }
 */
final readonly class PageInfo
{
    private const array TEXT_FIELDS = ['title', 'description', 'image', 'favicon', 'siteName', 'language', 'authorName', 'type', 'embedUrl', 'canonicalUrl', 'providerName'];

    /**
     * @param non-empty-string $url absolute http(s) URL the preview was taken from
     * @param string|null $image absolute http(s) URL
     * @param string|null $favicon absolute http(s) URL
     * @param string|null $embedUrl absolute http(s) URL of an embeddable player
     * @param string|null $canonicalUrl absolute http(s) URL the page claims as its own
     * @param int<1, max>|null $imageWidth pixels, as the source declares them
     * @param int<1, max>|null $imageHeight pixels, as the source declares them
     * @param string|null $providerName the service behind the content (oEmbed `provider_name`, a site adapter)
     */
    public function __construct(
        public string $url,
        public ?string $title = null,
        public ?string $description = null,
        public ?string $image = null,
        public ?string $favicon = null,
        public ?string $siteName = null,
        public ?string $language = null,
        public ?string $authorName = null,
        public ?DateTimeImmutable $publishedTime = null,
        public ?string $type = null,
        public ?string $embedUrl = null,
        public ?string $canonicalUrl = null,
        public ?int $imageWidth = null,
        public ?int $imageHeight = null,
        public ?string $providerName = null,
    ) {
        foreach (['url' => $url, 'image' => $image, 'favicon' => $favicon, 'embedUrl' => $embedUrl, 'canonicalUrl' => $canonicalUrl] as $field => $value) {
            if ($value !== null && !UrlResolver::isAbsoluteHttp($value)) {
                throw new InvalidArgumentException(\sprintf('Page %s must be an absolute http(s) URL', $field));
            }
        }

        foreach (['imageWidth' => $imageWidth, 'imageHeight' => $imageHeight] as $field => $value) {
            if ($value !== null && ($value < 1 || $image === null)) {
                throw new InvalidArgumentException(\sprintf('Page %s must be positive and come with an image', $field));
            }
        }
    }

    /**
     * The inverse of {@see self::toArray()}, for previews kept in a cache or a
     * database: `fromArray($info->toArray())->toArray() === $info->toArray()`.
     * Missing keys are `null` (an array stored by an older version still reads);
     * unknown keys are ignored.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException when a field has the wrong type or an invalid value
     */
    public static function fromArray(array $data): self
    {
        $url = $data['url'] ?? null;

        if (!\is_string($url) || $url === '') {
            throw new InvalidArgumentException('Page url must be a non-empty string');
        }

        $text = [];

        foreach (self::TEXT_FIELDS as $field) {
            $value = $data[$field] ?? null;

            if ($value !== null && !\is_string($value)) {
                throw new InvalidArgumentException(\sprintf('Page %s must be a string or null', $field));
            }

            $text[$field] = $value;
        }

        return new self(
            url: $url,
            title: $text['title'],
            description: $text['description'],
            image: $text['image'],
            favicon: $text['favicon'],
            siteName: $text['siteName'],
            language: $text['language'],
            authorName: $text['authorName'],
            publishedTime: self::time($data['publishedTime'] ?? null),
            type: $text['type'],
            embedUrl: $text['embedUrl'],
            canonicalUrl: $text['canonicalUrl'],
            imageWidth: self::dimension('imageWidth', $data['imageWidth'] ?? null),
            imageHeight: self::dimension('imageHeight', $data['imageHeight'] ?? null),
            providerName: $text['providerName'],
        );
    }

    /**
     * @return PageInfoArray
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'title' => $this->title,
            'description' => $this->description,
            'image' => $this->image,
            'favicon' => $this->favicon,
            'siteName' => $this->siteName,
            'language' => $this->language,
            'authorName' => $this->authorName,
            'publishedTime' => $this->publishedTime?->format(DateTimeInterface::ATOM),
            'type' => $this->type,
            'embedUrl' => $this->embedUrl,
            'canonicalUrl' => $this->canonicalUrl,
            'imageWidth' => $this->imageWidth,
            'imageHeight' => $this->imageHeight,
            'providerName' => $this->providerName,
        ];
    }

    private static function time(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $time = \is_string($value) ? DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $value) : false;

        if ($time === false) {
            throw new InvalidArgumentException('Page publishedTime must be an ATOM date-time string or null');
        }

        return $time;
    }

    /**
     * @return int<1, max>|null
     */
    private static function dimension(string $field, mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (!\is_int($value) || $value < 1) {
            throw new InvalidArgumentException(\sprintf('Page %s must be a positive integer or null', $field));
        }

        return $value;
    }
}
