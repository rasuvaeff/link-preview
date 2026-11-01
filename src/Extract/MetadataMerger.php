<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Extract;

use Rasuvaeff\LinkPreview\Text\PlainText;

/**
 * Field-wise merge: for every field the first source, in the order given, that has it.
 * The image and its dimensions are one field. Text fields are cleaned
 * ({@see PlainText}) on the way in, so a value that is nothing but markup or
 * entities does not hide the next source's.
 *
 * @internal
 */
final readonly class MetadataMerger
{
    private function __construct() {}

    /**
     * @param list<Metadata> $byPriority
     */
    public static function merge(array $byPriority): Metadata
    {
        $merged = new Metadata();

        foreach ($byPriority as $source) {
            $takesImage = $merged->image === null && $source->image !== null;

            $merged = new Metadata(
                url: $merged->url ?? $source->url,
                title: $merged->title ?? PlainText::clean($source->title),
                description: $merged->description ?? PlainText::clean($source->description),
                image: $takesImage ? $source->image : $merged->image,
                favicon: $merged->favicon ?? $source->favicon,
                siteName: $merged->siteName ?? PlainText::clean($source->siteName),
                language: $merged->language ?? $source->language,
                authorName: $merged->authorName ?? PlainText::clean($source->authorName),
                publishedTime: $merged->publishedTime ?? $source->publishedTime,
                type: $merged->type ?? $source->type,
                embedUrl: $merged->embedUrl ?? $source->embedUrl,
                imageWidth: $takesImage ? $source->imageWidth : $merged->imageWidth,
                imageHeight: $takesImage ? $source->imageHeight : $merged->imageHeight,
                providerName: $merged->providerName ?? PlainText::clean($source->providerName),
            );
        }

        return $merged;
    }
}
