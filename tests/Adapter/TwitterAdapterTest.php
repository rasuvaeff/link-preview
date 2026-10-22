<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Adapter;

use Rasuvaeff\LinkPreview\Adapter\AdapterFailure;
use Rasuvaeff\LinkPreview\Adapter\TwitterAdapter;
use Rasuvaeff\LinkPreview\Tests\Support\Adapters;
use Rasuvaeff\LinkPreview\Tests\Support\Http;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(TwitterAdapter::class)]
final class TwitterAdapterTest
{
    private const string ENDPOINT = 'https://publish.twitter.com/oembed?url=https%3A%2F%2Ftwitter.com%2Fjack%2Fstatus%2F20&omit_script=1&dnt=true';

    public function askedAboutAsTwitterWhenTheLinkIsX(): void
    {
        $client = Http::client([self::ENDPOINT => Adapters::json(Adapters::fixture('twitter/oembed.json'))]);

        $metadata = (new TwitterAdapter())->preview('https://x.com/jack/status/20', Adapters::context($client))->unwrap();

        Assert::same(Http::requestedUrls($client), [self::ENDPOINT]);
        Assert::same($metadata->description, 'just setting up my twttr & more second line');
        Assert::same($metadata->authorName, 'jack');
        Assert::same($metadata->siteName, 'Twitter');
        Assert::null($metadata->title);
    }

    #[DataProvider('hostProvider')]
    public function onlyTheXHostIsRewritten(string $url, string $asked): void
    {
        $client = Http::sequence(Adapters::json(Adapters::fixture('twitter/oembed.json')));

        (new TwitterAdapter())->preview($url, Adapters::context($client));

        $query = [];
        parse_str((string) parse_url(Http::requestedUrls($client)[0], PHP_URL_QUERY), $query);
        Assert::same($query['url'], $asked);
    }

    public static function hostProvider(): iterable
    {
        yield 'x.com' => ['https://x.com/a/status/1', 'https://twitter.com/a/status/1'];
        yield 'www.x.com' => ['https://www.x.com/a/status/1', 'https://twitter.com/a/status/1'];
        yield 'mobile.x.com with port' => ['https://mobile.x.com:443/a/status/1', 'https://twitter.com/a/status/1'];
        yield 'twitter.com stays' => ['https://twitter.com/a/status/1', 'https://twitter.com/a/status/1'];
        yield 'path mentioning x.com stays' => ['https://twitter.com/x.com/status/1', 'https://twitter.com/x.com/status/1'];
        yield 'host that merely ends in x.com stays' => ['https://box.com/a', 'https://box.com/a'];
    }

    public function tokenIsAHeaderNotAUrlPart(): void
    {
        $client = Http::sequence(Adapters::json(Adapters::fixture('twitter/oembed.json')));

        (new TwitterAdapter(accessToken: 'secret-token'))->preview('https://twitter.com/jack/status/20', Adapters::context($client));

        $request = Http::requests($client)[0];
        Assert::same($request->getHeaderLine('Authorization'), 'Bearer secret-token');
        Assert::false(str_contains((string) $request->getUri(), 'secret-token'));
    }

    #[DataProvider('noTokenProvider')]
    public function noTokenMeansNoHeader(?string $token): void
    {
        $client = Http::sequence(Adapters::json(Adapters::fixture('twitter/oembed.json')));

        (new TwitterAdapter(accessToken: $token))->preview('https://twitter.com/jack/status/20', Adapters::context($client));

        Assert::false(Http::requests($client)[0]->hasHeader('Authorization'));
    }

    public static function noTokenProvider(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
    }

    #[DataProvider('htmlProvider')]
    public function extractsThePostText(string $html, ?string $expected): void
    {
        $client = Http::sequence(Adapters::json((string) json_encode(['type' => 'rich', 'author_name' => 'a', 'html' => $html])));

        $metadata = (new TwitterAdapter())->preview('https://twitter.com/a/status/1', Adapters::context($client))->unwrap();

        Assert::same($metadata->description, $expected);
    }

    public static function htmlProvider(): iterable
    {
        yield 'plain' => ['<blockquote><p>Hello</p></blockquote>', 'Hello'];
        yield 'entities and quotes' => ['<blockquote><p>a &lt;b&gt; &quot;c&quot; &#39;d&#39;</p></blockquote>', 'a <b> "c" \'d\''];
        yield 'link inside' => ['<blockquote><p>see <a href="https://t.co/x">t.co/x</a></p></blockquote>', 'see t.co/x'];
        yield 'line breaks' => ['<blockquote><p>one<br>two<br/>three</p></blockquote>', 'one two three'];
        yield 'first paragraph only' => ['<blockquote><p>first</p><p>second</p></blockquote>', 'first'];
        yield 'paragraph with attributes' => ['<blockquote><p lang="en" dir="ltr">text</p></blockquote>', 'text'];
        yield 'multiline' => ["<blockquote><p>a\nb</p></blockquote>", "a\nb"];
        yield 'empty paragraph' => ['<blockquote><p> </p></blockquote>', null];
        yield 'no paragraph' => ['<blockquote>none</blockquote>', null];
    }

    public function failuresAreReported(): void
    {
        Assert::same((new TwitterAdapter())->preview('https://twitter.com/a/status/1', Adapters::context(Http::client([])))->error(), AdapterFailure::Unavailable);

        $context = Adapters::context(Http::sequence(Adapters::json('{}')));
        Assert::same((new TwitterAdapter())->preview('https://twitter.com/a/status/1', $context)->error(), AdapterFailure::InvalidResponse);
    }

    public function servesItsDomains(): void
    {
        Assert::same((new TwitterAdapter())->domains(), ['twitter.com', 'x.com']);
    }
}
