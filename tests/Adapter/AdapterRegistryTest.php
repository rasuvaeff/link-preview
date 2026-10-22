<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Adapter;

use Rasuvaeff\LinkPreview\Adapter\Adapter;
use Rasuvaeff\LinkPreview\Adapter\AdapterContext;
use Rasuvaeff\LinkPreview\Adapter\AdapterRegistry;
use Rasuvaeff\LinkPreview\Adapter\HostMatcher;
use Rasuvaeff\LinkPreview\Adapter\RutubeAdapter;
use Rasuvaeff\LinkPreview\Adapter\TikTokAdapter;
use Rasuvaeff\LinkPreview\Adapter\YouTubeAdapter;
use Rasuvaeff\LinkPreview\Tests\Support\AdapterHostGenerators;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(AdapterRegistry::class)]
#[Covers(HostMatcher::class)]
final class AdapterRegistryTest
{
    #[DataProvider('hostProvider')]
    public function findsTheAdapterOfAHost(string $host, ?string $class): void
    {
        $adapter = AdapterRegistry::standard()->find($host);

        Assert::same($adapter === null ? null : $adapter::class, $class);
    }

    public static function hostProvider(): iterable
    {
        yield 'youtube' => ['www.youtube.com', YouTubeAdapter::class];
        yield 'youtube mobile' => ['m.youtube.com', YouTubeAdapter::class];
        yield 'youtu.be' => ['youtu.be', YouTubeAdapter::class];
        yield 'music subdomain' => ['music.youtube.com', YouTubeAdapter::class];
        yield 'upper case' => ['WWW.YouTube.COM', YouTubeAdapter::class];
        yield 'root dot' => ['youtube.com.', YouTubeAdapter::class];
        yield 'rutube is not youtube' => ['rutube.ru', RutubeAdapter::class];
        yield 'tiktok short link' => ['vm.tiktok.com', TikTokAdapter::class];
        yield 'domain as a left label' => ['youtube.com.evil.example', null];
        yield 'domain glued to a prefix' => ['notyoutube.com', null];
        yield 'domain after a dash' => ['evil-youtube.com', null];
        yield 'domain as a path-like host' => ['evil.example/youtube.com', null];
        yield 'userinfo-like host' => ['youtube.com@evil.example', null];
        yield 'unknown' => ['example.com', null];
        yield 'empty' => ['', null];
        yield 'github pages' => ['rasuvaeff.github.io', null];
        yield 'dot only' => ['.', null];
    }

    public function hostMatcherIsLabelWise(): void
    {
        Assert::true(HostMatcher::matches('a.b.example.com', ['example.com']));
        Assert::true(HostMatcher::matches('example.com', ['example.com']));
        Assert::false(HostMatcher::matches('badexample.com', ['example.com']));
        Assert::false(HostMatcher::matches('example.com.evil', ['example.com']));
        Assert::false(HostMatcher::matches('com', ['example.com']));
        Assert::false(HostMatcher::matches('example.com', []));
    }

    public function firstMatchingAdapterWins(): void
    {
        $first = new TikTokAdapter();
        $second = new class implements Adapter {
            public function domains(): array
            {
                return ['tiktok.com'];
            }

            public function preview(string $url, AdapterContext $context): \Rasuvaeff\Result\Result
            {
                throw new \LogicException('not used');
            }
        };

        Assert::same((new AdapterRegistry([$first, $second]))->find('tiktok.com'), $first);
        Assert::null((new AdapterRegistry())->find('tiktok.com'));
    }

    public function standardRegistryServesElevenAdapters(): void
    {
        $classes = [];

        foreach (array_keys(AdapterHostGenerators::OWNER) as $domain) {
            $classes[(string) AdapterRegistry::standard()->find($domain)?->domains()[0]] = true;
        }

        Assert::same(\count(array_unique(array_values(AdapterHostGenerators::OWNER))), 11);
        Assert::same(\count($classes), 11);
    }

    /**
     * A host belongs to an adapter exactly when it is the adapter's domain or one of its
     * subdomains, in any letter case; look-alikes belong to nobody.
     */
    #[Property(runs: 500, generators: [AdapterHostGenerators::class, 'hosts'], timeoutMs: 250)]
    public function hostsAreMatchedByLabelsNotBySubstring(string $domain, string $kind, string $label): void
    {
        $host = AdapterHostGenerators::host($domain, $kind, $label);
        $found = AdapterRegistry::standard()->find($host);
        $genuine = \in_array($kind, AdapterHostGenerators::GENUINE, true);

        Assert::same($found === null ? null : $found::class, $genuine ? AdapterHostGenerators::OWNER[$domain] : null);

        Classify::cover(condition: $genuine, label: 'genuine host', minPercent: 30.0);
        Classify::cover(condition: !$genuine, label: 'look-alike host', minPercent: 30.0);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function hostsAreMatchedByLabelsNotBySubstringExamples(): iterable
    {
        yield 'suffix label' => ['youtube.com', 'suffix label', 'evil'];
        yield 'glued prefix' => ['x.com', 'glued prefix', 'bo'];
        yield 'middle label' => ['t.me', 'middle label', 'a'];
        yield 'subdomain' => ['wikipedia.org', 'subdomain', 'en'];
        yield 'upper' => ['fb.watch', 'upper', 'a'];
    }
}
