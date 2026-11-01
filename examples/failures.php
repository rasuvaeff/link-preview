<?php

declare(strict_types=1);

// What a failed preview says: the reason, the HTTP status and Retry-After when there was an answer,
// and isTransient() - whether retrying later makes sense. A 404 is permanent, a 503 with Retry-After is not.

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\PreviewProvider;

require dirname(__DIR__) . '/vendor/autoload.php';

$client = new class implements ClientInterface {
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return match ((string) $request->getUri()) {
            'https://example.com/gone' => new Response(404),
            'https://example.com/busy' => new Response(503, ['Retry-After' => '120']),
            'https://example.com/slow-down' => new Response(429, ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT', 'Date' => 'Wed, 21 Oct 2026 07:27:00 GMT']),
            'https://example.com/image' => new Response(200, ['Content-Type' => 'image/png'], 'PNG'),
            default => throw new class ('connection refused') extends RuntimeException implements NetworkExceptionInterface {
                public function getRequest(): RequestInterface
                {
                    return new Request('GET', 'https://example.com/');
                }
            },
        };
    }
};

$provider = new PreviewProvider(fetcher: new Fetcher(client: $client, requestFactory: new HttpFactory()), adapters: null);

foreach (['https://example.com/gone', 'https://example.com/busy', 'https://example.com/slow-down', 'https://example.com/image', 'https://example.com/down', 'http://10.0.0.1/'] as $url) {
    $failure = $provider->preview(url: $url)->error();

    if ($failure === null) {
        continue;
    }

    printf(
        "%-32s %-24s status %-4s retry after %-5s %s\n",
        $url,
        $failure->reason->value,
        $failure->status ?? '-',
        $failure->retryAfterSeconds ?? '-',
        $failure->isTransient() ? 'transient: try again later' : 'permanent: do not retry',
    );
}
