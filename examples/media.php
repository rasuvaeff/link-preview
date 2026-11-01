<?php

declare(strict_types=1);

// Fetcher::fetchMedia(): download the image of a preview under the same URL policy, redirect checks and
// User-Agent as the page, with its own type list and size limit.

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;

require dirname(__DIR__) . '/vendor/autoload.php';

$client = new class implements ClientInterface {
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return match ((string) $request->getUri()) {
            'https://cdn.example.com/cover' => new Response(302, ['Location' => '/cover.webp']),
            'https://cdn.example.com/cover.webp' => new Response(200, ['Content-Type' => 'image/webp'], str_repeat('w', 2_000)),
            'https://cdn.example.com/huge.png' => new Response(200, ['Content-Type' => 'image/png', 'Content-Length' => '50000000'], ''),
            'https://cdn.example.com/page' => new Response(200, ['Content-Type' => 'text/html'], '<p>not an image</p>'),
            'https://cdn.example.com/to-metadata' => new Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            default => new Response(404),
        };
    }
};

$fetcher = new Fetcher(client: $client, requestFactory: new HttpFactory());

foreach (['https://cdn.example.com/cover', 'https://cdn.example.com/huge.png', 'https://cdn.example.com/page', 'https://cdn.example.com/to-metadata'] as $url) {
    $result = $fetcher->fetchMedia(url: $url, acceptedTypes: ['image/*'], maxBytes: 1_048_576);
    $media = $result->value();

    printf(
        "%-38s %s\n",
        $url,
        $media === null
            ? 'refused: ' . $result->error()?->reason->value
            : \sprintf('%s, %d bytes, from %s', $media->contentType, \strlen($media->body), $media->url),
    );
}
