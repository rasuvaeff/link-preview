<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Fetch;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\Fetch\FetchFailure;
use Rasuvaeff\LinkPreview\Fetch\UrlPolicy;
use Rasuvaeff\LinkPreview\Fetch\UserAgentMap;
use Rasuvaeff\LinkPreview\Tests\Support\Http;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Fetcher::class)]
final class FetcherTest
{
    public function fetchesAPage(): void
    {
        $client = Http::client(['https://example.com/' => Http::html('<p>hi</p>', 'text/HTML; charset=Windows-1251')]);

        $page = $this->fetcher($client)->fetch(url: 'https://example.com')->unwrap();

        Assert::same($page->url, 'https://example.com/');
        Assert::same($page->body, '<p>hi</p>');
        Assert::same($page->contentType, 'text/html');
        Assert::same($page->charset, 'Windows-1251');
        Assert::same($page->redirects, 0);
    }

    public function charsetIsOptional(): void
    {
        $client = Http::client(['https://example.com/' => Http::html('x', 'application/xhtml+xml')]);

        $page = $this->fetcher($client)->fetch(url: 'https://example.com/')->unwrap();

        Assert::same($page->contentType, 'application/xhtml+xml');
        Assert::null($page->charset);
    }

    public function quotedCharsetIsUnquoted(): void
    {
        $client = Http::client(['https://example.com/' => Http::html('x', 'text/html; charset="utf-8"; format=flowed')]);

        Assert::same($this->fetcher($client)->fetch(url: 'https://example.com/')->unwrap()->charset, 'utf-8');
    }

    public function sendsGetWithAcceptAndUserAgent(): void
    {
        $client = Http::client(['https://example.com/x?y=1' => Http::html('x')]);

        $this->fetcher($client)->fetch(url: 'https://Example.com/x?y=1#frag');

        $request = Http::requests($client)[0];
        Assert::same($request->getMethod(), 'GET');
        Assert::same((string) $request->getUri(), 'https://example.com/x?y=1');
        Assert::same($request->getHeaderLine('User-Agent'), UserAgentMap::DEFAULT);
        Assert::string($request->getHeaderLine('Accept'))->contains('text/html');
    }

    public function presentsTheUserAgentOfTheHostOfEachHop(): void
    {
        $client = Http::client([
            'https://short.example/a' => Http::redirect('https://www.vk.com/video'),
            'https://www.vk.com/video' => Http::html('x'),
        ]);
        $fetcher = new Fetcher(
            client: $client,
            requestFactory: new HttpFactory(),
            userAgents: new UserAgentMap(byHost: ['vk.com' => 'VKBot/1'], default: 'Default/1'),
        );

        $fetcher->fetch(url: 'https://short.example/a');

        $agents = array_map(
            static fn($request): string => $request->getHeaderLine('User-Agent'),
            Http::requests($client),
        );
        Assert::same($agents, ['Default/1', 'VKBot/1']);
    }

    public function followsRedirectsAndResolvesRelativeLocations(): void
    {
        $client = Http::client([
            'https://example.com/a' => Http::redirect('/b', 301),
            'https://example.com/b' => Http::redirect('c', 303),
            'https://example.com/c' => Http::redirect('https://other.org/d?x=1#f', 307),
            'https://other.org/d?x=1' => Http::redirect('//third.net/e', 308),
            'https://third.net/e' => Http::html('done'),
        ]);

        $page = $this->fetcher($client, maxRedirects: 4)->fetch(url: 'https://example.com/a')->unwrap();

        Assert::same($page->url, 'https://third.net/e');
        Assert::same($page->redirects, 4);
        Assert::same(Http::requestedUrls($client), [
            'https://example.com/a',
            'https://example.com/b',
            'https://example.com/c',
            'https://other.org/d?x=1',
            'https://third.net/e',
        ]);
    }

    public function stopsAtTheRedirectLimit(): void
    {
        $client = Http::client([
            'https://example.com/a' => Http::redirect('/b'),
            'https://example.com/b' => Http::redirect('/c'),
            'https://example.com/c' => Http::html('done'),
        ]);

        $result = $this->fetcher($client, maxRedirects: 1)->fetch(url: 'https://example.com/a');

        Assert::same($result->error(), FetchFailure::TooManyRedirects);
        Assert::same(Http::requestedUrls($client), ['https://example.com/a', 'https://example.com/b']);
    }

    public function zeroRedirectsMeansNone(): void
    {
        $client = Http::client(['https://example.com/a' => Http::redirect('/b')]);

        Assert::same($this->fetcher($client, maxRedirects: 0)->fetch(url: 'https://example.com/a')->error(), FetchFailure::TooManyRedirects);
    }

    public function redirectLoopEndsAtTheLimit(): void
    {
        $client = Http::client(['https://example.com/a' => Http::redirect('/a')]);

        Assert::same($this->fetcher($client, maxRedirects: 3)->fetch(url: 'https://example.com/a')->error(), FetchFailure::TooManyRedirects);
        Assert::same(\count(Http::requests($client)), 4);
    }

    #[DataProvider('unsafeTargetProvider')]
    public function policyJudgesEveryHopBeforeRequestingIt(string $location): void
    {
        $client = Http::client([
            'https://example.com/a' => Http::redirect($location),
            'http://127.0.0.1/' => Http::html('secret'),
        ]);

        $result = $this->fetcher($client)->fetch(url: 'https://example.com/a');

        Assert::same($result->error(), FetchFailure::PolicyRejected);
        Assert::same(Http::requestedUrls($client), ['https://example.com/a']);
    }

    public static function unsafeTargetProvider(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1/'];
        yield 'metadata endpoint' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'localhost' => ['http://localhost./'];
        yield 'v6 loopback in hex' => ['http://[::7f00:1]/'];
        yield 'integer ip' => ['http://2130706433/'];
        yield 'other port' => ['https://example.com:6379/'];
    }

    public function rejectsAnUnsafeFirstUrlWithoutARequest(): void
    {
        $client = Http::client([]);

        Assert::same($this->fetcher($client)->fetch(url: 'http://10.0.0.1/')->error(), FetchFailure::PolicyRejected);
        Assert::same(Http::requests($client), []);
    }

    public function anAllowedPrivateNetworkCanBeFetched(): void
    {
        $client = Http::client(['http://10.0.0.5/' => Http::html('internal')]);
        $fetcher = new Fetcher(
            client: $client,
            requestFactory: new HttpFactory(),
            policy: new UrlPolicy(allowedNetworks: ['10.0.0.0/24']),
        );

        Assert::same($fetcher->fetch(url: 'http://10.0.0.5/')->unwrap()->body, 'internal');
    }

    #[DataProvider('badRedirectProvider')]
    public function redirectWithoutAUsableLocationFails(string $location): void
    {
        $client = Http::client(['https://example.com/a' => new Response(302, $location === '' ? [] : ['Location' => $location])]);

        Assert::same($this->fetcher($client)->fetch(url: 'https://example.com/a')->error(), FetchFailure::InvalidRedirect);
    }

    public static function badRedirectProvider(): iterable
    {
        yield 'missing' => [''];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'ftp' => ['ftp://example.com/'];
        yield 'no host' => ['http://'];
        yield 'credentials' => ['https://user:pw@example.com/'];
    }

    public function budgetBoundsTheChain(): void
    {
        $client = Http::client([
            'https://example.com/a' => Http::redirect('/b'),
            'https://example.com/b' => Http::html('done'),
        ]);

        $result = $this->fetcher($client, chainBudgetSeconds: 0.0)->fetch(url: 'https://example.com/a');

        Assert::same($result->error(), FetchFailure::BudgetExceeded);
        Assert::same(Http::requestedUrls($client), ['https://example.com/a']);
    }

    public function budgetDoesNotCutASingleRequest(): void
    {
        $client = Http::client(['https://example.com/a' => Http::html('done')]);

        Assert::same($this->fetcher($client, chainBudgetSeconds: 0.0)->fetch(url: 'https://example.com/a')->unwrap()->body, 'done');
    }

    public function generousBudgetDoesNotInterfere(): void
    {
        $client = Http::client([
            'https://example.com/a' => Http::redirect('/b'),
            'https://example.com/b' => Http::html('done'),
        ]);

        Assert::same($this->fetcher($client, chainBudgetSeconds: 60.0)->fetch(url: 'https://example.com/a')->unwrap()->body, 'done');
    }

    public function transportFailureIsAFailureNotAnException(): void
    {
        Assert::same($this->fetcher(Http::failing())->fetch(url: 'https://example.com/')->error(), FetchFailure::Network);
    }

    public function transportFailureOnALaterHop(): void
    {
        $client = Http::client(['https://example.com/a' => Http::redirect('https://gone.example/')]);

        Assert::same($this->fetcher($client)->fetch(url: 'https://example.com/a')->error(), FetchFailure::Network);
    }

    #[DataProvider('statusProvider')]
    public function nonSuccessStatusFails(int $status): void
    {
        $client = Http::client(['https://example.com/' => Http::html('<p>x</p>', status: $status)]);

        Assert::same($this->fetcher($client)->fetch(url: 'https://example.com/')->error(), FetchFailure::HttpStatus);
    }

    public static function statusProvider(): iterable
    {
        yield '199' => [199];
        yield '300' => [300];
        yield '304' => [304];
        yield '404' => [404];
        yield '500' => [500];
    }

    #[DataProvider('successStatusProvider')]
    public function everySuccessStatusIsAccepted(int $status): void
    {
        $client = Http::client(['https://example.com/' => Http::html('x', status: $status)]);

        Assert::true($this->fetcher($client)->fetch(url: 'https://example.com/')->isOk());
    }

    public static function successStatusProvider(): iterable
    {
        yield '200' => [200];
        yield '203' => [203];
        yield '299' => [299];
    }

    #[DataProvider('notHtmlProvider')]
    public function onlyHtmlIsFetched(string $contentType): void
    {
        $client = Http::client(['https://example.com/' => new Response(200, $contentType === '' ? [] : ['Content-Type' => $contentType], 'body')]);

        Assert::same($this->fetcher($client)->fetch(url: 'https://example.com/')->error(), FetchFailure::NotHtml);
    }

    public static function notHtmlProvider(): iterable
    {
        yield 'json' => ['application/json'];
        yield 'image' => ['image/png'];
        yield 'plain' => ['text/plain'];
        yield 'missing' => [''];
        yield 'look-alike' => ['text/htmlx'];
        yield 'html in a parameter' => ['text/plain; x=text/html'];
    }

    public function declaredLengthOverTheLimitFailsWithoutReading(): void
    {
        $response = new Response(200, ['Content-Type' => 'text/html', 'Content-Length' => '101'], 'x');
        $client = Http::client(['https://example.com/' => $response]);

        Assert::same($this->fetcher($client, maxBodyBytes: 100)->fetch(url: 'https://example.com/')->error(), FetchFailure::TooLarge);
    }

    public function actualBodyOverTheLimitFailsEvenWithoutALengthHeader(): void
    {
        $client = Http::client(['https://example.com/' => Http::html(str_repeat('a', 101))]);

        Assert::same($this->fetcher($client, maxBodyBytes: 100)->fetch(url: 'https://example.com/')->error(), FetchFailure::TooLarge);
    }

    public function actualBodyOverTheLimitFailsWhenTheHeaderLies(): void
    {
        $response = new Response(200, ['Content-Type' => 'text/html', 'Content-Length' => '5'], str_repeat('a', 20_000));
        $client = Http::client(['https://example.com/' => $response]);

        Assert::same($this->fetcher($client, maxBodyBytes: 10_000)->fetch(url: 'https://example.com/')->error(), FetchFailure::TooLarge);
    }

    public function declaredLengthExactlyAtTheLimitPasses(): void
    {
        $response = new Response(200, ['Content-Type' => 'text/html', 'Content-Length' => '100'], str_repeat('a', 100));
        $client = Http::client(['https://example.com/' => $response]);

        Assert::true($this->fetcher($client, maxBodyBytes: 100)->fetch(url: 'https://example.com/')->isOk());
    }

    public function malformedContentLengthIsIgnored(): void
    {
        $response = new Response(200, ['Content-Type' => 'text/html', 'Content-Length' => '999999999x'], 'small');
        $client = Http::client(['https://example.com/' => $response]);

        Assert::same($this->fetcher($client, maxBodyBytes: 100)->fetch(url: 'https://example.com/')->unwrap()->body, 'small');
    }

    public function charsetParameterIsCaseInsensitive(): void
    {
        $client = Http::client(['https://example.com/' => Http::html('x', 'TEXT/HTML ; CHARSET=UTF-8')]);

        $page = $this->fetcher($client)->fetch(url: 'https://example.com/')->unwrap();

        Assert::same($page->charset, 'UTF-8');
        Assert::same($page->contentType, 'text/html');
    }

    public function aBodyLimitOfOneByteIsValid(): void
    {
        $client = Http::client(['https://example.com/' => Http::html('a')]);

        Assert::same($this->fetcher($client, maxBodyBytes: 1)->fetch(url: 'https://example.com/')->unwrap()->body, 'a');
    }

    public function bodyExactlyAtTheLimitPasses(): void
    {
        $client = Http::client(['https://example.com/' => Http::html(str_repeat('a', 100))]);

        Assert::same(\strlen($this->fetcher($client, maxBodyBytes: 100)->fetch(url: 'https://example.com/')->unwrap()->body), 100);
    }

    public function bodyLargerThanOneChunkIsRead(): void
    {
        $client = Http::client(['https://example.com/' => Http::html(str_repeat('ab', 20_000))]);

        Assert::same(\strlen($this->fetcher($client)->fetch(url: 'https://example.com/')->unwrap()->body), 40_000);
    }

    #[DataProvider('invalidConfigProvider')]
    public function rejectsInvalidConfiguration(int $maxRedirects, int $maxBodyBytes, ?float $budget): void
    {
        Expect::exception(InvalidArgumentException::class);

        new Fetcher(
            client: Http::client([]),
            requestFactory: new HttpFactory(),
            maxRedirects: $maxRedirects,
            maxBodyBytes: $maxBodyBytes,
            chainBudgetSeconds: $budget,
        );
    }

    public static function invalidConfigProvider(): iterable
    {
        yield 'negative redirects' => [-1, 10, null];
        yield 'zero body' => [1, 0, null];
        yield 'negative body' => [1, -1, null];
        yield 'negative budget' => [1, 10, -0.1];
    }

    /**
     * A chain of N redirects ends in a page exactly when N does not exceed the limit,
     * and a redirect past the limit is never requested.
     */
    #[Property(runs: 200, generators: [self::class, 'chainGenerators'], timeoutMs: 500)]
    public function redirectLimitIsExact(int $chain, int $limit): void
    {
        $routes = [];

        for ($hop = 0; $hop < $chain; ++$hop) {
            $routes['https://example.com/' . $hop] = Http::redirect('/' . ($hop + 1));
        }

        $routes['https://example.com/' . $chain] = Http::html('end');
        $client = Http::client($routes);

        $result = $this->fetcher($client, maxRedirects: $limit)->fetch(url: 'https://example.com/0');

        Assert::same($result->isOk(), $chain <= $limit);
        Assert::same(\count(Http::requests($client)), min($chain, $limit) + 1);
        Classify::cover(condition: $chain <= $limit, label: 'within the limit', minPercent: 20.0);
        Classify::cover(condition: $chain > $limit, label: 'past the limit', minPercent: 20.0);
    }

    /** @return array<string, \Rasuvaeff\PropertyTesting\ArbitraryInterface> */
    public static function chainGenerators(): array
    {
        return [
            'chain' => Gen::intBetween(0, 8),
            'limit' => Gen::intBetween(0, 8),
        ];
    }

    /** @return iterable<string, array{int, int}> */
    public static function redirectLimitIsExactExamples(): iterable
    {
        yield 'no redirect, no allowance' => [0, 0];
        yield 'one redirect, one allowed' => [1, 1];
        yield 'one redirect, none allowed' => [1, 0];
        yield 'at the default limit' => [5, 5];
        yield 'one past the default limit' => [6, 5];
    }

    private function fetcher(
        ClientInterface $client,
        int $maxRedirects = 5,
        int $maxBodyBytes = 2_097_152,
        ?float $chainBudgetSeconds = null,
    ): Fetcher {
        return new Fetcher(
            client: $client,
            requestFactory: new HttpFactory(),
            maxRedirects: $maxRedirects,
            maxBodyBytes: $maxBodyBytes,
            chainBudgetSeconds: $chainBudgetSeconds,
        );
    }
}
