<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Adapter;

use Rasuvaeff\LinkPreview\Extract\Json;
use Rasuvaeff\LinkPreview\Extract\Metadata;
use Rasuvaeff\LinkPreview\Extract\Schemas;
use Rasuvaeff\LinkPreview\Extract\Values;
use Rasuvaeff\Result\Result;

/**
 * Wikipedia articles through the REST summary API of their own language
 * edition (`/api/rest_v1/page/summary/<title>`). Needs nothing.
 *
 * @api
 */
final readonly class WikipediaAdapter implements Adapter
{
    #[\Override]
    public function domains(): array
    {
        return ['wikipedia.org'];
    }

    #[\Override]
    public function preview(string $url, AdapterContext $context): Result
    {
        $parts = OEmbedSupport::parts($url);

        if (
            preg_match('/\A(?:[a-z0-9-]+\.)*?([a-z]{2,12}(?:-[a-z]{2,12})?)(?:\.m)?\.wikipedia\.org\z/', $parts['host'], $host) !== 1
            || preg_match('~\A/wiki/([^/?#]+)\z~', $parts['path'], $title) !== 1
        ) {
            return Result::err(error: AdapterFailure::UnsupportedUrl);
        }

        $response = $context->json('https://' . $host[1] . '.wikipedia.org/api/rest_v1/page/summary/' . $title[1]);

        if ($response->isErr()) {
            return Result::err(error: AdapterFailure::Unavailable);
        }

        $parsed = Schemas::parse(Schemas::wikipediaSummary(), $response->unwrap()->body);

        if ($parsed->isErr()) {
            $context->note('Wikipedia summary ' . (string) $parsed->error());

            return Result::err(error: AdapterFailure::InvalidResponse);
        }

        $data = $parsed->unwrap();
        $thumbnail = Json::object($data['thumbnail'] ?? null);
        $content = Json::object(Json::object($data['content_urls'] ?? null)['desktop'] ?? null);

        return Result::ok(value: new Metadata(
            url: OEmbedSupport::absolute(Json::string($content['page'] ?? null) ?? ''),
            title: Json::string($data['title'] ?? null),
            description: Json::string($data['extract'] ?? null) ?? Json::string($data['description'] ?? null),
            image: OEmbedSupport::absolute(Json::string($thumbnail['source'] ?? null) ?? ''),
            siteName: 'Wikipedia',
            language: Values::language(Json::string($data['lang'] ?? null)),
            type: 'article',
        ));
    }
}
