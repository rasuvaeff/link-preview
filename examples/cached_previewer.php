<?php

declare(strict_types=1);

// CachedPreviewProvider is the shipped PSR-16 decorator: successful previews are cached as the
// PageInfo::toArray() shape, a failed fetch is not cached at all, and fromHtml() passes through.
// The example uses a tiny in-memory PSR-16 cache; any psr/simple-cache ^3.0 backend works.

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\LinkPreview\CachedPreviewProvider;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\PreviewProvider;

require dirname(__DIR__) . '/vendor/autoload.php';

final class ArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $values = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->values = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->get($key, $default);
        }
    }

    public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]);
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

$provider = new PreviewProvider(fetcher: new Fetcher(client: $client, requestFactory: new HttpFactory()), adapters: null);
$cache = new ArrayCache();
$previewer = new CachedPreviewProvider($provider, $cache, ttlSeconds: 900);

$first = $previewer->preview('https://example.com/a')->unwrap();
$second = $previewer->preview('https://example.com/a')->unwrap();

echo 'title: ', $second->title, "\n";
echo 'image: ', $second->image, ' (', $second->imageWidth, " px wide)\n";
echo 'same preview from the cache: ', $first->toArray() === $second->toArray() ? 'yes' : 'no', "\n";
echo 'cache entries: ', count($cache->values), "\n";
echo 'requests made: ', $client->requests, "\n";
