<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Extract;

use Rasuvaeff\LinkPreview\Html\PageDocument;
use Rasuvaeff\LinkPreview\Url\UrlResolver;

/**
 * Turns the references of one page into absolute http(s) URLs, honouring `<base href>`.
 *
 * @internal
 */
final readonly class UrlContext
{
    private string $base;

    /**
     * @param non-empty-string $pageUrl
     */
    public function __construct(PageDocument $document, string $pageUrl)
    {
        $href = $document->baseHref();

        $this->base = ($href === null ? null : UrlResolver::resolve(base: $pageUrl, reference: $href)) ?? $pageUrl;
    }

    /**
     * @return non-empty-string|null
     */
    public function resolve(?string $reference): ?string
    {
        return $reference === null ? null : UrlResolver::resolve(base: $this->base, reference: $reference);
    }

    /**
     * The first reference that resolves to an acceptable URL.
     *
     * @param list<string> $references
     *
     * @return non-empty-string|null
     */
    public function first(array $references): ?string
    {
        foreach ($references as $reference) {
            $resolved = $this->resolve($reference);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }
}
