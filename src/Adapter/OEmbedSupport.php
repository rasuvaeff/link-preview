<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Adapter;

use Rasuvaeff\LinkPreview\Extract\Metadata;
use Rasuvaeff\LinkPreview\Extract\MetadataMerger;
use Rasuvaeff\LinkPreview\Extract\OEmbedResponse;
use Rasuvaeff\LinkPreview\Url\UrlResolver;
use Rasuvaeff\Result\Result;

/**
 * Shared plumbing of the adapters.
 *
 * @internal
 */
final readonly class OEmbedSupport
{
    private function __construct() {}

    /**
     * @param array<string, string> $query
     *
     * @return non-empty-string
     */
    public static function endpoint(string $base, array $query): string
    {
        return $base . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param non-empty-string $endpoint
     * @param array<string, string> $headers
     *
     * @return Result<Metadata, AdapterFailure>
     */
    public static function fetch(AdapterContext $context, string $endpoint, #[\SensitiveParameter] array $headers = []): Result
    {
        $response = $context->json($endpoint, $headers);

        if ($response->isErr()) {
            return Result::err(error: AdapterFailure::Unavailable);
        }

        $metadata = OEmbedResponse::metadata(json: $response->unwrap()->body, baseUrl: $endpoint);

        return $metadata === null ? Result::err(error: AdapterFailure::InvalidResponse) : Result::ok(value: $metadata);
    }

    /**
     * What the adapter worked out by itself beats what the service said.
     */
    public static function with(Metadata $own, Metadata $service): Metadata
    {
        return MetadataMerger::merge([$own, $service]);
    }

    /**
     * @return non-empty-string|null
     */
    public static function absolute(string $url): ?string
    {
        return UrlResolver::resolve(base: '', reference: $url);
    }

    /**
     * @return array{host: string, path: string, query: array<string, string>}
     */
    public static function parts(string $url): array
    {
        $parts = parse_url($url);
        $parsed = [];
        parse_str($parts['query'] ?? '', $parsed);
        $strings = array_filter($parsed, 'is_string');
        $query = array_combine(array_map('strval', array_keys($strings)), $strings);

        return [
            'host' => strtolower($parts['host'] ?? ''),
            'path' => $parts['path'] ?? '/',
            'query' => $query,
        ];
    }
}
