<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\Fetch\UrlPolicy;
use Rasuvaeff\LinkPreview\PreviewProvider;

require dirname(__DIR__) . '/vendor/autoload.php';

$page = <<<'HTML'
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <title>Fallback title</title>
        <meta property="og:title" content="An article worth sharing">
        <meta property="og:description" content="Open Graph wins over the plain HTML description.">
        <meta property="og:image" content="/img/cover.jpg">
        <meta name="twitter:title" content="Ignored: OpenGraph has a title">
        <link rel="icon" href="/favicon.ico">
    </head>
    <body></body>
    </html>
    HTML;

$client = new class ($page) implements ClientInterface {
    public function __construct(private string $page) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return match ((string) $request->getUri()) {
            'https://example.com/moved' => new Response(301, ['Location' => '/article']),
            'https://example.com/article' => new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $this->page),
            default => new Response(404),
        };
    }
};

$provider = new PreviewProvider(
    fetcher: new Fetcher(client: $client, requestFactory: new HttpFactory()),
);

$info = $provider->preview(url: 'https://example.com/moved')->unwrap();

echo json_encode($info->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";

$refused = $provider->preview(url: 'http://169.254.169.254/latest/meta-data/');
echo 'metadata endpoint: ', $refused->error()?->value, "\n";

$policy = new UrlPolicy();
echo 'policy on 0x7f000001: ', $policy->check(url: 'http://0x7f000001/')->error()?->value, "\n";
