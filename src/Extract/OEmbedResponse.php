<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Extract;

use Rasuvaeff\LinkPreview\Url\UrlResolver;
use Rasuvaeff\Result\Result;

/**
 * Reads an oEmbed JSON response (oembed.com): `title`, `author_name`,
 * `provider_name`, `thumbnail_url` (or `url` of a photo), the `<iframe src>` of
 * `html` as the embed, `type` as the page type.
 *
 * @internal
 */
final readonly class OEmbedResponse
{
    private function __construct() {}

    /**
     * @param non-empty-string $baseUrl what relative or protocol-relative references resolve against
     *
     * @return Result<Metadata, string> the metadata, or a diagnostic from the schema ({@see Schemas::parse()}),
     *         or `$: nothing to show` for an answer with none of the fields that matter
     */
    public static function parse(string $json, string $baseUrl): Result
    {
        $data = Schemas::parse(Schemas::oEmbed(), $json);

        if ($data->isErr()) {
            return Result::err(error: (string) $data->error());
        }

        $metadata = self::fromData($data->unwrap(), $baseUrl);

        return $metadata === null ? Result::err(error: '$: nothing to show') : Result::ok(value: $metadata);
    }

    /**
     * @param array<string, mixed> $data an answer parsed by {@see Schemas::oEmbed()}
     * @param non-empty-string $baseUrl
     */
    public static function fromData(array $data, string $baseUrl): ?Metadata
    {
        $type = Json::string($data['type'] ?? null);
        $image = Json::string($data['thumbnail_url'] ?? null);

        if ($image === null && $type === 'photo') {
            $image = Json::string($data['url'] ?? null);
        }

        $html = Json::string($data['html'] ?? null);
        $embed = $html !== null && preg_match('/<iframe\b[^>]*?\ssrc\s*=\s*["\']([^"\']+)["\']/i', $html, $matches) === 1
            ? UrlResolver::resolve(base: $baseUrl, reference: html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            : null;

        $metadata = new Metadata(
            title: Json::string($data['title'] ?? null),
            image: $image === null ? null : UrlResolver::resolve(base: $baseUrl, reference: $image),
            siteName: Json::string($data['provider_name'] ?? null),
            authorName: Json::string($data['author_name'] ?? null),
            type: $type,
            embedUrl: $embed,
        );

        return $metadata->isEmpty() ? null : $metadata;
    }
}
