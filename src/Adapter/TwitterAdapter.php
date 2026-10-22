<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Adapter;

use Rasuvaeff\LinkPreview\Extract\Json;
use Rasuvaeff\LinkPreview\Extract\Metadata;
use Rasuvaeff\LinkPreview\Extract\OEmbedResponse;
use Rasuvaeff\Result\Result;

/**
 * Twitter / X posts through `publish.twitter.com/oembed`. The post text becomes
 * the description. `x.com` links are asked about as `twitter.com`, which is what
 * the endpoint knows.
 *
 * Works without a token. A token, when given, is sent as a bearer
 * `Authorization` header to `publish.twitter.com` only - never in the URL.
 *
 * @api
 */
final readonly class TwitterAdapter implements Adapter
{
    private const string ENDPOINT = 'https://publish.twitter.com/oembed';

    public function __construct(
        #[\SensitiveParameter]
        private ?string $accessToken = null,
    ) {}

    #[\Override]
    public function domains(): array
    {
        return ['twitter.com', 'x.com'];
    }

    #[\Override]
    public function preview(string $url, AdapterContext $context): Result
    {
        $asked = preg_replace('~\Ahttps?://(?:[a-z0-9-]+\.)*x\.com(?::\d+)?(?=[/?#]|\z)~i', 'https://twitter.com', $url) ?? $url;
        $headers = $this->accessToken === null || $this->accessToken === '' ? [] : ['Authorization' => 'Bearer ' . $this->accessToken];

        $response = $context->json(OEmbedSupport::endpoint(self::ENDPOINT, ['url' => $asked, 'omit_script' => '1', 'dnt' => 'true']), $headers);

        if ($response->isErr()) {
            return Result::err(error: AdapterFailure::Unavailable);
        }

        $body = $response->unwrap()->body;
        $service = OEmbedResponse::parse(json: $body, baseUrl: self::ENDPOINT);

        if ($service->isErr()) {
            $context->note('oEmbed ' . (string) $service->error());

            return Result::err(error: AdapterFailure::InvalidResponse);
        }

        return Result::ok(value: OEmbedSupport::with(new Metadata(description: $this->text($body)), $service->unwrap()));
    }

    private function text(string $json): ?string
    {
        $data = Json::object(Json::decode($json));
        $html = Json::string($data['html'] ?? null);

        if ($html === null || preg_match('~<p\b[^>]*>(.*?)</p>~is', $html, $matches) !== 1) {
            return null;
        }

        $text = strip_tags((string) preg_replace('~<br\s*/?>~i', ' ', $matches[1]));
        $text = trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text === '' ? null : $text;
    }
}
