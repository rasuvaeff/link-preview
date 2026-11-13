<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview;

use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Result\Result;
use Throwable;

/**
 * PSR-16 cache decorator over any {@see LinkPreviewer} ({@see PreviewProvider}
 * included): successful previews are cached, a failed fetch never is. A page
 * that is down right now gets a fresh chance on the next call instead of a
 * cached error - the cache only ever shortens a wait, never lengthens one.
 *
 * A hit is stored as the {@see PageInfo::toArray()} shape and read back with
 * {@see PageInfo::fromArray()}, so any PSR-16 backend works (array, Redis, ...)
 * and an array stored by an older version still reads.
 *
 * Keys are dot-separated (PSR-16 reserves `{}()/\@:`) and hash the namespace
 * and the URL string exactly as it was passed to {@see self::preview()}: two
 * spellings of the same page are two keys until the TTL expires, so normalise
 * upstream if your callers are sloppy. `null` TTL is the backend's default
 * lifetime. The cache namespace (`cacheNamespace`, default `'preview'`) scopes
 * entries: two decorators sharing one backend but configured differently
 * (different providers, limits or policies) must use different namespaces, or
 * one serves the other's cached previews.
 *
 * The cache is never a single point of failure: a backend that throws during
 * a read is a miss, and one that throws during a write is skipped - the
 * preview result from the inner previewer is returned either way. A garbage
 * cache value (wrong type or wrong shape after a backend hiccup) is a miss,
 * never an error, and is overwritten by the next successful preview.
 *
 * {@see self::fromHtml()} is passed through uncached: the document is already
 * in hand, so there is no fetch to save.
 *
 * @api
 */
final readonly class CachedPreviewProvider implements LinkPreviewer
{
    private const string CACHE_KEY_PREFIX = 'rasuvaeff.link-preview.preview.';
    private const int DEFAULT_TTL_SECONDS = 3_600;

    public function __construct(
        private LinkPreviewer $inner,
        private CacheInterface $cache,
        private ?int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        private string $cacheNamespace = 'preview',
    ) {
        if ($ttlSeconds !== null && $ttlSeconds <= 0) {
            throw new InvalidArgumentException('TTL must be positive');
        }
    }

    #[\Override]
    public function preview(string $url): Result
    {
        $key = $this->cacheKey($url);

        $cached = null;

        try {
            /** @var mixed $cached */
            $cached = $this->cache->get($key);
        } catch (Throwable) {
            // A broken cache read is a miss: fall through and fetch.
        }

        if (\is_array($cached)) {
            try {
                return Result::ok(value: PageInfo::fromArray($cached));
            } catch (InvalidArgumentException|\ValueError) {
                // A wrong shape is a miss: fall through and re-fetch.
            }
        }

        $result = $this->inner->preview($url);
        $info = $result->value();

        if ($info instanceof PageInfo) {
            try {
                $this->cache->set($key, $info->toArray(), $this->ttlSeconds);
            } catch (Throwable) {
                // A broken cache write must not fail the preview.
            }
        }

        return $result;
    }

    #[\Override]
    public function fromHtml(string $html, string $pageUrl, ?string $charset = null): Result
    {
        return $this->inner->fromHtml($html, $pageUrl, $charset);
    }

    private function cacheKey(string $url): string
    {
        return self::CACHE_KEY_PREFIX . hash('xxh3', $this->cacheNamespace . "\0" . $url);
    }
}
