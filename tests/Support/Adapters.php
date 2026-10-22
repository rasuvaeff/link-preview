<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Support;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Rasuvaeff\LinkPreview\Adapter\AdapterContext;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;

/**
 * @internal
 */
final readonly class Adapters
{
    private function __construct() {}

    public static function fixture(string $path): string
    {
        return Fixtures::read('adapters/' . $path);
    }

    public static function context(ClientInterface $client): AdapterContext
    {
        return new AdapterContext(new Fetcher(client: $client, requestFactory: new HttpFactory()));
    }

    public static function json(string $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], $body);
    }
}
