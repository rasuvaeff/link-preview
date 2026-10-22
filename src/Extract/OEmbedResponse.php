<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Extract;

use Rasuvaeff\LinkPreview\Url\UrlResolver;

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
     */
    public static function metadata(string $json, string $baseUrl): ?Metadata
    {
        $data = Json::object(Json::decode($json));

        if ($data === null) {
            return null;
        }

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
