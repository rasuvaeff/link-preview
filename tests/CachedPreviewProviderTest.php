<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests;

use InvalidArgumentException;
use Rasuvaeff\LinkPreview\CachedPreviewProvider;
use Rasuvaeff\LinkPreview\PageInfo;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(CachedPreviewProvider::class)]
final class CachedPreviewProviderTest
{
    private const string URL_A = 'https://example.com/a';
    private const string URL_B = 'https://example.com/b';

    private Support\ArrayCache $cache;

    private Support\CountingPreviewer $inner;

    private CachedPreviewProvider $fixture;

    private PageInfo $info;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->info = new PageInfo(url: self::URL_A, title: 'Cached & clean');
        $this->cache = new Support\ArrayCache();
        $this->inner = new Support\CountingPreviewer($this->info);
        $this->fixture = new CachedPreviewProvider($this->inner, $this->cache, ttlSeconds: 60);
    }

    private function keyFor(string $namespace, string $url): string
    {
        return 'rasuvaeff.link-preview.preview.' . hash('xxh3', $namespace . "\0" . $url);
    }

    public function secondCallOfTheSameUrlDoesNotHitTheInnerPreviewer(): void
    {
        $first = $this->fixture->preview(self::URL_A)->unwrap();
        $second = $this->fixture->preview(self::URL_A)->unwrap();

        Assert::same($this->inner->previewCalls, 1);
        Assert::same($second->toArray(), $first->toArray());
    }

    public function expiredEntryHitsTheInnerPreviewerAgain(): void
    {
        $this->fixture->preview(self::URL_A);

        $this->cache->now += 61;

        $this->fixture->preview(self::URL_A);

        Assert::same($this->inner->previewCalls, 2);
    }

    public function differentUrlsUseDifferentKeys(): void
    {
        $this->fixture->preview(self::URL_A);
        $this->fixture->preview(self::URL_B);

        Assert::same($this->inner->previewCalls, 2);
        Assert::same(count($this->cache->entries), 2);

        $this->fixture->preview(self::URL_A);

        Assert::same($this->inner->previewCalls, 2, 'URL A is served from the cache, not the inner previewer');
    }

    public function failuresAreNotCached(): void
    {
        $this->inner->fail = true;

        $first = $this->fixture->preview(self::URL_A);
        $second = $this->fixture->preview(self::URL_A);

        Assert::same($this->inner->previewCalls, 2);
        Assert::true($first->isErr());
        Assert::true($second->isErr());
        Assert::same($this->cache->entries, []);
    }

    public function aSuccessfulPreviewAfterAFailureIsCached(): void
    {
        $this->inner->fail = true;
        $this->fixture->preview(self::URL_A);

        $this->inner->fail = false;
        $this->fixture->preview(self::URL_A);
        $third = $this->fixture->preview(self::URL_A);

        Assert::same($this->inner->previewCalls, 2);
        Assert::same($third->unwrap()->toArray(), $this->info->toArray());
    }

    #[DataProvider('garbageValueProvider')]
    public function garbageCacheValueIsAMissAndGetsOverwritten(mixed $garbage): void
    {
        $key = $this->keyFor('preview', self::URL_A);
        $this->cache->entries[$key] = [$garbage, null];

        $this->fixture->preview(self::URL_A);
        $second = $this->fixture->preview(self::URL_A);

        Assert::same($this->inner->previewCalls, 1);
        Assert::same($second->unwrap()->toArray(), $this->info->toArray());
        Assert::same($this->cache->entries[$key][0], $this->info->toArray());
    }

    public static function garbageValueProvider(): iterable
    {
        yield 'wrong type' => ['not an array'];

        yield 'wrong shape' => [['url' => 'not a url']];

        yield 'non-string keys' => [[1 => 'garbage']];
    }

    public function ttlMustBePositive(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new CachedPreviewProvider($this->inner, $this->cache, ttlSeconds: 0);
    }

    public function nullTtlIsTheBackendsDefault(): void
    {
        $fixture = new CachedPreviewProvider($this->inner, $this->cache, ttlSeconds: null);

        $fixture->preview(self::URL_A);

        Assert::same($this->cache->entries[$this->keyFor('preview', self::URL_A)][1], null);
    }

    public function fromHtmlIsPassedThroughWithoutCaching(): void
    {
        $first = $this->fixture->fromHtml('<title>x</title>', self::URL_A);
        $second = $this->fixture->fromHtml('<title>x</title>', self::URL_A);

        Assert::same($this->inner->fromHtmlCalls, 2);
        Assert::same($this->cache->entries, []);
        Assert::same($second->unwrap()->toArray(), $first->unwrap()->toArray());
    }

    public function aCacheThatThrowsOnReadIsAMissNotAnError(): void
    {
        $this->cache->throwOnGet = true;

        $first = $this->fixture->preview(self::URL_A);
        $second = $this->fixture->preview(self::URL_A);

        Assert::same($this->inner->previewCalls, 2, 'a broken cache read falls through to the inner previewer');
        Assert::same($first->unwrap()->toArray(), $this->info->toArray());
        Assert::same($second->unwrap()->toArray(), $this->info->toArray());
    }

    public function aCacheThatThrowsOnWriteDoesNotFailThePreview(): void
    {
        $this->cache->throwOnSet = true;

        $result = $this->fixture->preview(self::URL_A);

        Assert::same($result->unwrap()->toArray(), $this->info->toArray());
        Assert::same($this->cache->entries, [], 'nothing was written, but the preview succeeded');
    }

    public function differentNamespacesUseDifferentKeys(): void
    {
        $other = new CachedPreviewProvider(new Support\CountingPreviewer(new PageInfo(url: self::URL_B, title: 'Other')), $this->cache, ttlSeconds: 60, cacheNamespace: 'strict');

        $this->fixture->preview(self::URL_A);
        $other->preview(self::URL_A);

        Assert::same(count($this->cache->entries), 2);

        $fromDefault = $this->fixture->preview(self::URL_A)->unwrap();
        $fromOther = $other->preview(self::URL_A)->unwrap();

        Assert::same($fromDefault->title, 'Cached & clean', 'the default namespace serves its own entry');
        Assert::same($fromOther->title, 'Other', 'the other namespace does not see the default entry');
    }

    public function defaultNamespaceIsPreview(): void
    {
        $this->fixture->preview(self::URL_A);

        Assert::true(isset($this->cache->entries[$this->keyFor('preview', self::URL_A)]));
    }
}
