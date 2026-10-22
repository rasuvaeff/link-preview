<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Extract;

use DateTimeImmutable;

/**
 * What one source — OpenGraph, Twitter Cards, plain HTML — knows about a page.
 * Every field may be missing. URLs are absolute http(s).
 *
 * @api
 */
final readonly class Metadata
{
    /**
     * @param non-empty-string|null $url
     * @param non-empty-string|null $image
     * @param non-empty-string|null $favicon
     * @param non-empty-string|null $embedUrl absolute http(s) URL of an embeddable player
     */
    public function __construct(
        public ?string $url = null,
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
    ) {}

    public function isEmpty(): bool
    {
        return $this->url === null
            && $this->title === null
            && $this->description === null
            && $this->image === null
            && $this->favicon === null
            && $this->siteName === null
            && $this->language === null
            && $this->authorName === null
            && $this->publishedTime === null
            && $this->type === null
            && $this->embedUrl === null;
    }
}
