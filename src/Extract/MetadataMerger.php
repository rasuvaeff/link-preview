<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Extract;

/**
 * Field-wise merge: for every field the first source, in the order given, that has it.
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
            $merged = new Metadata(
                url: $merged->url ?? $source->url,
                title: $merged->title ?? $source->title,
                description: $merged->description ?? $source->description,
                image: $merged->image ?? $source->image,
                favicon: $merged->favicon ?? $source->favicon,
                siteName: $merged->siteName ?? $source->siteName,
                language: $merged->language ?? $source->language,
                authorName: $merged->authorName ?? $source->authorName,
                publishedTime: $merged->publishedTime ?? $source->publishedTime,
                type: $merged->type ?? $source->type,
            );
        }

        return $merged;
    }
}
