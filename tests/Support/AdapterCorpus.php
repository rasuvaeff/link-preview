<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Support;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Rasuvaeff\LinkPreview\Adapter\AdapterRegistry;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\PreviewProvider;
use RuntimeException;

/**
 * The recorded-response corpus of the site adapters: `tests/fixtures/adapters/manifest.json`.
 *
 * Every entry says which URL is previewed, which exact requests the adapter is expected to make
 * and what the service answered (a file next to the manifest), and the preview that must come out.
 * An unlisted request is answered 404, so an adapter that asks for anything else fails the entry.
 *
 * @internal
 *
 * @psalm-type Entry = array{
 *     name: string,
 *     url: non-empty-string,
 *     options: array<string, mixed>,
 *     responses: array<string, array{file: string, type: string}>,
 *     expected: array<string, string>,
 * }
 */
final readonly class AdapterCorpus
{
    private const string DIRECTORY = __DIR__ . '/../fixtures/adapters';

    private const array NOTHING = [
        'url' => null,
        'title' => null,
        'description' => null,
        'image' => null,
        'favicon' => null,
        'siteName' => null,
        'language' => null,
        'authorName' => null,
        'publishedTime' => null,
        'type' => null,
        'embedUrl' => null,
    ];

    private function __construct() {}

    /**
     * @return list<Entry>
     */
    public static function entries(): array
    {
        $json = file_get_contents(self::DIRECTORY . '/manifest.json');

        /** @var list<Entry> $entries */
        $entries = $json === false ? throw new RuntimeException('Missing adapter manifest') : json_decode($json, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

        return $entries;
    }

    /**
     * @param Entry $entry
     *
     * @return array{preview: array<string, string|null>|string, requests: list<string>}
     */
    public static function run(array $entry): array
    {
        $routes = [];

        foreach ($entry['responses'] as $url => $response) {
            $body = file_get_contents(self::DIRECTORY . '/' . $response['file']);
            $routes[$url] = new Response(200, ['Content-Type' => $response['type']], $body === false ? '' : $body);
        }

        $client = new ScriptedClient($routes);
        $options = $entry['options'];
        $parents = $options['twitchParents'] ?? [];
        $provider = new PreviewProvider(
            fetcher: new Fetcher(client: $client, requestFactory: new HttpFactory()),
            adapters: AdapterRegistry::standard(
                twitterToken: \is_string($options['twitterToken'] ?? null) ? $options['twitterToken'] : null,
                instagramToken: \is_string($options['instagramToken'] ?? null) ? $options['instagramToken'] : null,
                facebookToken: \is_string($options['facebookToken'] ?? null) ? $options['facebookToken'] : null,
                twitchParents: \is_array($parents) ? array_values(array_filter($parents, is_string(...))) : [],
            ),
        );

        $result = $provider->preview(url: $entry['url']);

        return [
            'preview' => $result->isOk() ? $result->unwrap()->toArray() : (string) $result->error()?->value,
            'requests' => array_map(static fn($request): string => (string) $request->getUri(), $client->requests),
        ];
    }

    /**
     * @param Entry $entry
     *
     * @return array<string, string|null>
     */
    public static function expected(array $entry): array
    {
        return array_replace(self::NOTHING, $entry['expected']);
    }
}
