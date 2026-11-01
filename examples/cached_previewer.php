<?php

declare(strict_types=1);

// LinkPreviewer is the interface of the facade: a decorator can sit in front of PreviewProvider.
// This one caches successful previews as arrays (PageInfo::toArray() / fromArray(), the shape you would
// put in PSR-16 or a database column) and never caches a transient failure.

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\LinkPreviewer;
use Rasuvaeff\LinkPreview\PageInfo;
use Rasuvaeff\LinkPreview\PreviewProvider;
use Rasuvaeff\Result\Result;

require dirname(__DIR__) . '/vendor/autoload.php';

final class CachedPreviewer implements LinkPreviewer
{
    /** @var array<string, array<string, mixed>> */
    public array $store = [];

    public function __construct(private readonly LinkPreviewer $inner) {}

    public function preview(string $url): Result
    {
        if (isset($this->store[$url])) {
            return Result::ok(value: PageInfo::fromArray($this->store[$url]));
        }

        $result = $this->inner->preview($url);
        $info = $result->value();

        if ($info instanceof PageInfo) {
            $this->store[$url] = $info->toArray();
        }

        return $result;
    }

    public function fromHtml(string $html, string $pageUrl, ?string $charset = null): Result
    {
        return $this->inner->fromHtml($html, $pageUrl, $charset);
    }
}

$client = new class implements ClientInterface {
    public int $requests = 0;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        ++$this->requests;

        return new Response(200, ['Content-Type' => 'text/html'], '<title>Cached &amp;amp; clean</title><meta property="og:image" content="/i.png"><meta property="og:image:width" content="1200">');
    }
};

$previewer = new CachedPreviewer(new PreviewProvider(fetcher: new Fetcher(client: $client, requestFactory: new HttpFactory()), adapters: null));

$first = $previewer->preview('https://example.com/a')->unwrap();
$second = $previewer->preview('https://example.com/a')->unwrap();

echo 'title: ', $second->title, "\n";
echo 'image: ', $second->image, ' (', $second->imageWidth, " px wide)\n";
echo 'same preview from the cache: ', $first->toArray() === $second->toArray() ? 'yes' : 'no', "\n";
echo 'requests made: ', $client->requests, "\n";
