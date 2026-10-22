<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;

require dirname(__DIR__) . '/vendor/autoload.php';

$client = new class implements ClientInterface {
    /** @var list<string> */
    public array $requested = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $url = (string) $request->getUri();
        $this->requested[] = $url;

        return match ($url) {
            'https://short.example/a' => new Response(302, ['Location' => 'https://short.example/b']),
            'https://short.example/b' => new Response(301, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            default => new Response(200, ['Content-Type' => 'text/html'], '<title>never reached</title>'),
        };
    }
};

$result = (new Fetcher(client: $client, requestFactory: new HttpFactory()))->fetch(url: 'https://short.example/a');

echo 'result: ', $result->error()?->value, "\n";
echo "requests actually sent:\n  ", implode("\n  ", $client->requested), "\n";
