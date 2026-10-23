<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Extract;

use Rasuvaeff\LinkPreview\Html\PageDocument;
use Rasuvaeff\Result\Result;

/**
 * Reads schema.org JSON-LD (`<script type="application/ld+json">`): Article
 * (NewsArticle, BlogPosting, ...), VideoObject, WebPage and Organization/WebSite
 * nodes, `@graph` included.
 *
 * Nodes are ranked article, video, page, organization; for each field the best
 * ranked node that has it wins. `publisher` and `author` given as `{"@id": ...}`
 * references are resolved inside the same block. A block whose `@context` is not
 * schema.org is skipped and never fetched; when nothing else yields data the
 * result says why ({@see ExtractFailure::UnsupportedContext}).
 *
 * @api
 */
final readonly class JsonLdExtractor implements Extractor
{
    private const array RANKS = [
        'Article' => 0, 'NewsArticle' => 0, 'BlogPosting' => 0, 'TechArticle' => 0, 'ScholarlyArticle' => 0, 'Report' => 0,
        'VideoObject' => 1, 'Clip' => 1, 'Movie' => 1,
        'WebPage' => 2, 'ItemPage' => 2, 'CollectionPage' => 2, 'AboutPage' => 2, 'ProfilePage' => 2, 'FAQPage' => 2,
        'Organization' => 3, 'NewsMediaOrganization' => 3, 'Corporation' => 3, 'WebSite' => 3,
    ];

    private JsonLdReader $reader;

    public function __construct(?JsonLdReader $reader = null)
    {
        $this->reader = $reader ?? new ProcessorJsonLdReader();
    }

    #[\Override]
    public function extract(PageDocument $document, string $pageUrl): Result
    {
        $nodes = [];
        $failure = ExtractFailure::NoMetadata;

        foreach ($document->scripts('application/ld+json') as $block) {
            $read = $this->reader->nodes($block);

            if ($read->isErr()) {
                $failure = $read->error() === JsonLdFailure::UnsupportedContext || $failure === ExtractFailure::UnsupportedContext
                    ? ExtractFailure::UnsupportedContext
                    : ExtractFailure::InvalidData;

                continue;
            }

            array_push($nodes, ...$read->unwrap());
        }

        $urls = new UrlContext($document, $pageUrl);
        $byId = [];

        foreach ($nodes as $node) {
            $id = Json::string($node['@id'] ?? null);

            if ($id !== null) {
                $byId[$id] = $node;
            }
        }

        $ranked = [];

        foreach ($nodes as $node) {
            $rank = $this->rank($node);

            if ($rank !== null) {
                $ranked[] = [$rank, $this->metadata($node, $rank, $byId, $urls)];
            }
        }

        usort($ranked, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = MetadataMerger::merge(array_map(static fn(array $entry): Metadata => $entry[1], $ranked));

        return $merged->isEmpty() ? Result::err(error: $failure) : Result::ok(value: $merged);
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return int<0, 3>|null
     */
    private function rank(array $node): ?int
    {
        $best = null;

        foreach (Json::items($node['@type'] ?? null) as $type) {
            $name = $this->shortName($type);
            $rank = $name === null ? null : (self::RANKS[$name] ?? null);

            if ($rank !== null && ($best === null || $rank < $best)) {
                $best = $rank;
            }
        }

        return $best;
    }

    private function shortName(mixed $type): ?string
    {
        $type = Json::string($type);

        return $type === null ? null : preg_replace('~\A.*[/#:]~', '', $type);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, array<string, mixed>> $byId
     */
    private function metadata(array $node, int $rank, array $byId, UrlContext $urls): Metadata
    {
        $name = Json::string($node['name'] ?? null);

        if ($rank === 3) {
            return new Metadata(siteName: $name);
        }

        $type = null;

        foreach (Json::items($node['@type'] ?? null) as $candidate) {
            $type = $this->shortName($candidate);

            if ($type !== null) {
                break;
            }
        }

        return new Metadata(
            url: $urls->first($this->strings($node['url'] ?? null, $byId, ['url', '@id']) ?: $this->strings($node['mainEntityOfPage'] ?? null, $byId, ['@id', 'url'])),
            title: Json::string($node['headline'] ?? null) ?? $name,
            description: Json::string($node['description'] ?? null),
            image: $urls->first($this->strings($node['image'] ?? $node['thumbnailUrl'] ?? null, $byId, ['url', 'contentUrl'])),
            siteName: $this->strings($node['publisher'] ?? null, $byId, ['name'])[0] ?? null,
            language: Values::language(Json::string($node['inLanguage'] ?? null)),
            authorName: Values::name($this->strings($node['author'] ?? null, $byId, ['name'])[0] ?? null),
            publishedTime: Values::time(Json::string($node['datePublished'] ?? $node['uploadDate'] ?? null)),
            type: $type,
            embedUrl: $urls->first($this->strings($node['embedUrl'] ?? null, $byId, ['url'])),
        );
    }

    /**
     * Strings found in a value that may be a string, an object (looked up by $keys, after
     * resolving an `@id` reference) or a list of either.
     *
     * @param array<string, array<string, mixed>> $byId
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function strings(mixed $value, array $byId, array $keys): array
    {
        $strings = [];

        foreach (Json::items($value) as $item) {
            $string = Json::string($item);

            if ($string !== null) {
                $strings[] = $string;

                continue;
            }

            $object = Json::object($item);

            if ($object === null) {
                continue;
            }

            $reference = Json::string($object['@id'] ?? null);

            if ($reference !== null && isset($byId[$reference]) && array_keys($object) === ['@id']) {
                $object = $byId[$reference];
            }

            foreach ($keys as $key) {
                $found = Json::string($object[$key] ?? null);

                if ($found !== null) {
                    $strings[] = $found;

                    break;
                }
            }
        }

        return $strings;
    }
}
