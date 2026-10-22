<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Understudy;

use function Rasuvaeff\Understudy\when;

/**
 * PSR-18 understudies and PSR-7 responses.
 *
 * @internal
 */
final class Http
{
    /**
     * A client that answers by URL; a URL it was not told about is a transport failure.
     *
     * @param array<string, ResponseInterface> $routes
     */
    public static function client(array $routes): ClientInterface
    {
        $client = Understudy::for(ClientInterface::class);

        when(fn() => $client->sendRequest(Arg::any()))->answers(
            static function (Invocation $call) use ($routes): ResponseInterface {
                /** @var RequestInterface $request */
                $request = $call->arg('request');

                return $routes[(string) $request->getUri()] ?? throw new TransportFailure('No route for ' . $request->getUri());
            },
        );

        return $client;
    }

    /**
     * A client that answers the n-th request with the n-th response and the last one afterwards.
     */
    public static function sequence(ResponseInterface ...$responses): ClientInterface
    {
        $client = Understudy::for(ClientInterface::class);
        when(fn() => $client->sendRequest(Arg::any()))->returns(...$responses);

        return $client;
    }

    /**
     * A client that fails like a dropped connection.
     */
    public static function failing(): ClientInterface
    {
        $client = Understudy::for(ClientInterface::class);
        when(fn() => $client->sendRequest(Arg::any()))->throws(new TransportFailure('connection refused'));

        return $client;
    }

    /**
     * @return list<RequestInterface>
     */
    public static function requests(ClientInterface $client): array
    {
        return array_map(
            static fn(Invocation $call): RequestInterface => $call->arg('request'),
            Understudy::calls(fn() => $client->sendRequest(Arg::any())),
        );
    }

    /**
     * @return list<string>
     */
    public static function requestedUrls(ClientInterface $client): array
    {
        return array_map(
            static fn(RequestInterface $request): string => (string) $request->getUri(),
            self::requests($client),
        );
    }

    public static function html(string $body, string $contentType = 'text/html; charset=utf-8', int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => $contentType], $body);
    }

    public static function redirect(string $location, int $status = 302): Response
    {
        return new Response($status, ['Location' => $location]);
    }
}
