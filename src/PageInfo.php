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
 * }
 */
final readonly class PageInfo
{
    /**
     * @param non-empty-string $url absolute http(s) URL of the page
     * @param string|null $image absolute http(s) URL
     * @param string|null $favicon absolute http(s) URL
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
    ) {
        foreach (['url' => $url, 'image' => $image, 'favicon' => $favicon] as $field => $value) {
            if ($value !== null && !UrlResolver::isAbsoluteHttp($value)) {
                throw new InvalidArgumentException(\sprintf('Page %s must be an absolute http(s) URL', $field));
            }
        }
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
        ];
    }
}
