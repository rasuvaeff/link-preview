<?php

declare(strict_types=1);

// A full JSON-LD processor behind the JsonLdReader interface: rasuvaeff/json-ld expands the block, so inline contexts,
// aliases and compact IRIs work (the built-in SimpleJsonLdReader skips them). Remote contexts are never fetched: the
// loader below answers only for schema.org and refuses everything else.
//
// Needs `composer require rasuvaeff/json-ld`; without it the script says so and exits.

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\JsonLd\Exception\InvalidJsonException;
use Rasuvaeff\JsonLd\Exception\JsonLdException;
use Rasuvaeff\JsonLd\ExpandOptions;
use Rasuvaeff\JsonLd\JsonLd;
use Rasuvaeff\JsonLd\Loader\ContextLoaderInterface;
use Rasuvaeff\JsonLd\Loader\LoadedContext;
use Rasuvaeff\LinkPreview\Extract\JsonLdExtractor;
use Rasuvaeff\LinkPreview\Extract\JsonLdFailure;
use Rasuvaeff\LinkPreview\Extract\JsonLdReader;
use Rasuvaeff\LinkPreview\Html\PageDocument;
use Rasuvaeff\Result\Result;

if (!class_exists(JsonLd::class)) {
    echo "Install rasuvaeff/json-ld to run this example.\n";

    return;
}

final readonly class SchemaOrgOnlyLoader implements ContextLoaderInterface
{
    #[\Override]
    public function load(string $reference): LoadedContext
    {
        if (preg_match('~\Ahttps?://(?:www\.)?schema\.org(?:/|\z)~i', $reference) === 1) {
            return new LoadedContext(documentUrl: $reference, document: ['@context' => ['@vocab' => 'https://schema.org/']]);
        }

        throw new RuntimeException('Only schema.org contexts are known');
    }
}

final readonly class ProcessorJsonLdReader implements JsonLdReader
{
    private const array VOCABULARIES = ['https://schema.org/', 'http://schema.org/'];

    private ExpandOptions $options;

    public function __construct(?ContextLoaderInterface $loader = null)
    {
        $this->options = new ExpandOptions(base: 'https://example.com/', loader: $loader ?? new SchemaOrgOnlyLoader());
    }

    #[\Override]
    public function nodes(string $json): Result
    {
        try {
            $expanded = JsonLd::expand($json, $this->options);
        } catch (InvalidJsonException) {
            return Result::err(error: JsonLdFailure::InvalidJson);
        } catch (JsonLdException|RuntimeException) {
            return Result::err(error: JsonLdFailure::UnsupportedContext);
        }

        $nodes = [];

        foreach ($expanded as $node) {
            if (\is_array($node) && isset($node['@type'])) {
                $nodes[] = $this->node($node);
            }
        }

        return Result::ok(value: $nodes);
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<string, mixed>
     */
    private function node(array $node): array
    {
        $out = [];

        foreach ($node as $key => $value) {
            if ($key === '@type') {
                $types = array_map($this->short(...), (array) $value);
                $out['@type'] = \count($types) === 1 ? $types[0] : $types;

                continue;
            }

            if ($key === '@id') {
                $out['@id'] = $value;

                continue;
            }

            $name = $this->short((string) $key);

            if ($name === (string) $key && !str_starts_with($name, '@')) {
                continue; // a property of another vocabulary
            }

            $values = array_map($this->value(...), (array) $value);
            $out[$name] = \count($values) === 1 ? $values[0] : $values;
        }

        return $out;
    }

    private function value(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if (\array_key_exists('@value', $value)) {
            return $value['@value'];
        }

        if (isset($value['@list'])) {
            return array_map($this->value(...), (array) $value['@list']);
        }

        return $this->node($value);
    }

    private function short(string $iri): string
    {
        foreach (self::VOCABULARIES as $vocabulary) {
            if (str_starts_with($iri, $vocabulary)) {
                return substr($iri, \strlen($vocabulary));
            }
        }

        return $iri;
    }
}

$block = <<<'JSON'
    {"@context": {"@vocab": "https://schema.org/", "title": "headline", "by": "author"},
     "@type": "NewsArticle", "title": "CSKA beat Zenit", "by": {"@type": "Person", "name": "A. Reporter"},
     "description": "A summary, with an inline context the built-in reader would skip"}
    JSON;

$document = PageDocument::parse('<html><head><script type="application/ld+json">' . $block . '</script></head></html>', 'utf-8');

foreach (['built-in' => new JsonLdExtractor(), 'processor' => new JsonLdExtractor(new ProcessorJsonLdReader())] as $name => $extractor) {
    $result = $extractor->extract(document: $document, pageUrl: 'https://example.com/a');

    echo $name, ': ', $result->isOk() ? (string) $result->unwrap()->title : 'nothing (' . $result->error()->name . ')', "\n";
}
