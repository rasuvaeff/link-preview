<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Adapter;

use Rasuvaeff\LinkPreview\Adapter\AdapterFailure;
use Rasuvaeff\LinkPreview\Adapter\WikipediaAdapter;
use Rasuvaeff\LinkPreview\Tests\Support\Adapters;
use Rasuvaeff\LinkPreview\Tests\Support\Http;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(WikipediaAdapter::class)]
final class WikipediaAdapterTest
{
    public function readsTheRestSummary(): void
    {
        $client = Http::client(['https://en.wikipedia.org/api/rest_v1/page/summary/HTTP' => Adapters::json(Adapters::fixture('wikipedia/summary.json'))]);

        $metadata = (new WikipediaAdapter())->preview('https://en.m.wikipedia.org/wiki/HTTP', Adapters::context($client))->unwrap();

        Assert::same($metadata->title, 'Hypertext Transfer Protocol');
        Assert::same($metadata->url, 'https://en.wikipedia.org/wiki/HTTP');
        Assert::same($metadata->siteName, 'Wikipedia');
        Assert::same($metadata->language, 'en');
        Assert::same($metadata->type, 'article');
        Assert::string((string) $metadata->description)->contains('application layer protocol');
    }

    #[DataProvider('editionProvider')]
    public function asksTheEditionOfTheLink(string $url, string $request): void
    {
        $client = Http::sequence(Adapters::json(Adapters::fixture('wikipedia/summary.json')));

        (new WikipediaAdapter())->preview($url, Adapters::context($client));

        Assert::same(Http::requestedUrls($client), [$request]);
    }

    public static function editionProvider(): iterable
    {
        yield 'english' => ['https://en.wikipedia.org/wiki/HTTP', 'https://en.wikipedia.org/api/rest_v1/page/summary/HTTP'];
        yield 'german mobile' => ['https://de.m.wikipedia.org/wiki/Hypertext_Transfer_Protocol', 'https://de.wikipedia.org/api/rest_v1/page/summary/Hypertext_Transfer_Protocol'];
        yield 'language with a subtag' => ['https://zh-yue.wikipedia.org/wiki/HTTP', 'https://zh-yue.wikipedia.org/api/rest_v1/page/summary/HTTP'];
        yield 'percent-encoded title is kept as written' => ['https://ru.wikipedia.org/wiki/%D0%9F%D1%80%D0%B8%D0%B2%D0%B5%D1%82', 'https://ru.wikipedia.org/api/rest_v1/page/summary/%D0%9F%D1%80%D0%B8%D0%B2%D0%B5%D1%82'];
        yield 'query and fragment are dropped' => ['https://en.wikipedia.org/wiki/HTTP?action=x#History', 'https://en.wikipedia.org/api/rest_v1/page/summary/HTTP'];
    }

    #[DataProvider('unsupportedProvider')]
    public function refusesPagesThatAreNotArticles(string $url): void
    {
        $client = Http::client([]);

        Assert::same((new WikipediaAdapter())->preview($url, Adapters::context($client))->error(), AdapterFailure::UnsupportedUrl);
        Assert::same(Http::requests($client), []);
    }

    public static function unsupportedProvider(): iterable
    {
        yield 'index.php' => ['https://en.wikipedia.org/w/index.php?title=HTTP'];
        yield 'nested path' => ['https://en.wikipedia.org/wiki/Talk/HTTP'];
        yield 'portal host without a language' => ['https://wikipedia.org/wiki/HTTP'];
        yield 'empty title' => ['https://en.wikipedia.org/wiki/'];
        yield 'single-letter language' => ['https://e.wikipedia.org/wiki/HTTP'];
    }

    public function failuresAreReported(): void
    {
        Assert::same((new WikipediaAdapter())->preview('https://en.wikipedia.org/wiki/X', Adapters::context(Http::client([])))->error(), AdapterFailure::Unavailable);

        $context = Adapters::context(Http::sequence(Adapters::json('{"type":"disambiguation"}')));
        Assert::same((new WikipediaAdapter())->preview('https://en.wikipedia.org/wiki/X', $context)->error(), AdapterFailure::InvalidResponse);
    }

    public function fallsBackToTheShortDescription(): void
    {
        $context = Adapters::context(Http::sequence(Adapters::json('{"title":"X","description":"short one"}')));

        Assert::same((new WikipediaAdapter())->preview('https://en.wikipedia.org/wiki/X', $context)->unwrap()->description, 'short one');
    }

    public function servesItsDomains(): void
    {
        Assert::same((new WikipediaAdapter())->domains(), ['wikipedia.org']);
    }
}
