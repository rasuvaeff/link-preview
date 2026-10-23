<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Extract;

use Rasuvaeff\JsonLd\Loader\ContextLoaderInterface;
use Rasuvaeff\JsonLd\Loader\LoadedContext;
use RuntimeException;

/**
 * Загрузчик контекстов для процессора JSON-LD: на `https://schema.org` отвечает из памяти (словарь `https://schema.org/` и префикс `schema:`),
 * на любой другой адрес отказывает. В сеть не ходит никогда.
 *
 * @internal
 */
final readonly class SchemaOrgContextLoader implements ContextLoaderInterface
{
    #[\Override]
    public function load(string $reference): LoadedContext
    {
        if (preg_match('~\Ahttps?://(?:www\.)?schema\.org(?:/(?:docs/jsonldcontext\.json)?)?\z~i', $reference) === 1) {
            return new LoadedContext(documentUrl: $reference, document: ['@context' => ['@vocab' => 'https://schema.org/', 'schema' => 'https://schema.org/']]);
        }

        throw new RuntimeException('Only the schema.org context is known');
    }
}
