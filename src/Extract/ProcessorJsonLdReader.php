<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Extract;

use Rasuvaeff\JsonLd\Exception\JsonLdException;
use Rasuvaeff\JsonLd\ExpandOptions;
use Rasuvaeff\JsonLd\JsonLd;
use Rasuvaeff\Result\Result;
use RuntimeException;

/**
 * Reads `<script type="application/ld+json">` with the JSON-LD 1.1 processor of `rasuvaeff/json-ld`: the block is
 * expanded, so an inline `@context`, aliases (`"title": "headline"`) and compact IRIs work, and the expanded schema.org
 * IRIs are mapped back to the short names the extractor reads (`headline`, `author`, `Article`).
 *
 * Nothing is fetched: the `https://schema.org` context is answered from memory, any other remote context is refused
 * (the block is then reported as {@see JsonLdFailure::UnsupportedContext}). Properties of other vocabularies are dropped.
 *
 * @psalm-import-type JsonValue from Json
 *
 * @api
 */
final readonly class ProcessorJsonLdReader implements JsonLdReader
{
    private const array VOCABULARIES = ['https://schema.org/', 'http://schema.org/'];

    private ExpandOptions $options;

    public function __construct()
    {
        $this->options = new ExpandOptions(loader: new SchemaOrgContextLoader());
    }

    #[\Override]
    public function nodes(string $json): Result
    {
        // the depth limit of Json::decode keeps absurdly nested input out of the processor
        $data = Json::decode($json);

        if ($data === null) {
            return Result::err(error: JsonLdFailure::InvalidJson);
        }

        try {
            $expanded = JsonLd::expand($data, $this->options);
        } catch (JsonLdException|RuntimeException) {
            return Result::err(error: JsonLdFailure::UnsupportedContext);
        }

        $nodes = [];

        foreach (Json::items($expanded) as $item) {
            $node = Json::object($item);

            if ($node !== null) {
                $this->collect($node, $nodes);
            }
        }

        return Result::ok(value: $nodes);
    }

    /**
     * A node with a `@type` is a result; the members of its `@graph` are results of their own.
     *
     * @param array<string, mixed> $node
     * @param list<array<string, mixed>> $nodes
     */
    private function collect(array $node, array &$nodes): void
    {
        $result = $this->node($node);

        if (isset($result['@type'])) {
            $nodes[] = $result;
        }

        foreach (Json::items($node['@graph'] ?? null) as $item) {
            $member = Json::object($item);

            if ($member !== null) {
                $this->collect($member, $nodes);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private function node(array $node): array
    {
        $out = [];

        /** @var JsonValue $value */
        foreach ($node as $key => $value) {

            if ($key === '@type') {
                // a type outside schema.org (a relative term of a block without a context, another vocabulary) is not ours
                $types = [];

                foreach (Json::items($value) as $type) {
                    $name = Json::string($type);

                    if ($name !== null && $this->short($name) !== $name) {
                        $types[] = $this->short($name);
                    }
                }

                if ($types !== []) {
                    $out['@type'] = \count($types) === 1 ? $types[0] : $types;
                }
            } elseif ($key === '@id') {
                $out['@id'] = Json::string($value);
            } elseif (!str_starts_with($key, '@') && $this->short($key) !== $key) {
                $values = array_map($this->value(...), Json::items($value));
                $out[$this->short($key)] = \count($values) === 1 ? $values[0] : $values;
            }
        }

        return $out;
    }

    /**
     * @param JsonValue $value
     *
     * @return JsonValue
     */
    private function value(mixed $value): mixed
    {
        $object = Json::object($value);

        if ($object === null) {
            return $value;
        }

        if (\array_key_exists('@value', $object)) {
            /** @var JsonValue $plain */
            $plain = $object['@value'];

            return $plain;
        }

        if (isset($object['@list'])) {
            return array_map($this->value(...), Json::items($object['@list']));
        }

        return $this->node($object);
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
