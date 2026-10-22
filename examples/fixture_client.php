<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;

/**
 * Shared by the adapter examples: a PSR-18 client that answers from the recorded
 * responses of tests/fixtures/adapters/manifest.json instead of the network.
 */
return static function (string $entryName): Fetcher {
    $directory = dirname(__DIR__) . '/tests/fixtures/adapters';
    /** @var list<array{name: string, responses: array<string, array{file: string, type: string}>}> $manifest */
    $manifest = json_decode((string) file_get_contents($directory . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $routes = [];

    foreach ($manifest as $entry) {
        if ($entry['name'] === $entryName) {
            foreach ($entry['responses'] as $url => $response) {
                $routes[$url] = [$response['type'], (string) file_get_contents($directory . '/' . $response['file'])];
            }
        }
    }

    $client = new class ($routes) implements ClientInterface {
        /** @param array<string, array{string, string}> $routes */
        public function __construct(private array $routes) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $route = $this->routes[(string) $request->getUri()] ?? null;

            echo '  GET ', $request->getUri(), $route === null ? "  (not recorded: 404)\n" : "\n";

            return $route === null ? new Response(404) : new Response(200, ['Content-Type' => $route[0]], $route[1]);
        }
    };

    return new Fetcher(client: $client, requestFactory: new HttpFactory());
};
