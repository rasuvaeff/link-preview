<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that answers from a URL -> response table, and remembers what it was asked.
 * It needs no test framework, so the corpus runner script can use it too.
 *
 * @internal
 */
final class ScriptedClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /**
     * @param array<string, ResponseInterface> $routes
     */
    public function __construct(private readonly array $routes) {}

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return $this->routes[(string) $request->getUri()] ?? new Response(404);
    }
}
