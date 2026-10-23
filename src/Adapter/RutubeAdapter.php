<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Adapter;

use Rasuvaeff\LinkPreview\Extract\Json;
use Rasuvaeff\LinkPreview\Extract\Metadata;
use Rasuvaeff\LinkPreview\Extract\Schemas;
use Rasuvaeff\LinkPreview\Extract\Values;
use Rasuvaeff\Result\Result;

/**
 * Rutube videos (`rutube.ru/video/<32 hex>/`) through the video API
 * (`/api/video/<id>/?format=json`). Needs nothing.
 *
 * @api
 */
final readonly class RutubeAdapter implements Adapter
{
    #[\Override]
    public function domains(): array
    {
        return ['rutube.ru'];
    }

    #[\Override]
    public function preview(string $url, AdapterContext $context): Result
    {
        if (preg_match('~\A/(?:video|shorts)/([0-9a-f]{32})(?:/|\z)~', OEmbedSupport::parts($url)['path'], $m) !== 1) {
            return Result::err(error: AdapterFailure::UnsupportedUrl);
        }

        $response = $context->json('https://rutube.ru/api/video/' . $m[1] . '/?format=json');

        if ($response->isErr()) {
            return Result::err(error: AdapterFailure::Unavailable);
        }

        $parsed = Schemas::parse(Schemas::rutubeVideo(), $response->unwrap()->body);

        if ($parsed->isErr()) {
            $context->note('Rutube video ' . ($parsed->error() ?? ''));

            return Result::err(error: AdapterFailure::InvalidResponse);
        }

        $data = $parsed->unwrap();
        $author = Json::object($data['author'] ?? null);

        return Result::ok(value: new Metadata(
            title: Json::string($data['title'] ?? null),
            description: Json::string($data['description'] ?? null),
            image: OEmbedSupport::absolute(Json::string($data['thumbnail_url'] ?? null) ?? ''),
            siteName: 'Rutube',
            authorName: Json::string($author['name'] ?? null),
            publishedTime: Values::time(Json::string($data['created'] ?? $data['publication_ts'] ?? null)),
            type: 'video',
            embedUrl: OEmbedSupport::absolute(Json::string($data['embed_url'] ?? null) ?? ''),
        ));
    }
}
