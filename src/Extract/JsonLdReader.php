<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Extract;

use Rasuvaeff\Result\Result;

/**
 * Turns the text of a `<script type="application/ld+json">` block into schema.org
 * node objects. {@see JsonLdExtractor} depends on this interface only, so the
 * built-in {@see ProcessorJsonLdReader} (the processor of `rasuvaeff/json-ld`) can be replaced
 * without touching the extractor or the public API.
 *
 * An implementation must not perform I/O on its own: a remote `@context` is
 * either refused or supplied from memory.
 *
 * @api
 */
interface JsonLdReader
{
    /**
     * @return Result<list<array<string, mixed>>, JsonLdFailure> the nodes of the block, `@graph` flattened,
     *         with the vocabulary of schema.org (`headline`, `author`, ...)
     */
    public function nodes(string $json): Result;
}
