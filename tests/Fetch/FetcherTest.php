<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Fetch;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\Fetch\FetchFailure;
use Rasuvaeff\LinkPreview\Fetch\FetchFailureReason;
use Rasuvaeff\LinkPreview\Fetch\RetryAfter;
use Rasuvaeff\LinkPreview\Fetch\Transience;
use Rasuvaeff\LinkPreview\Fetch\UrlPolicy;
use Rasuvaeff\LinkPreview\Fetch\UserAgentMap;
use Rasuvaeff\LinkPreview\Tests\Support\Adapters;
use Rasuvaeff\LinkPreview\Tests\Support\Http;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(Fetcher::class)]
#[Covers(FetchFailure::class)]
#[Covers(RetryAfter::class)]
#[Covers(Transience::class)]
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

        Assert::same($result->error()?->reason, FetchFailureReason::TooManyRedirects);
        Assert::same(Http::requestedUrls($client), ['https://example.com/a', 'https://example.com/b']);
    }

    public function zeroRedirectsMeansNone(): void
    {
        $client = Http::client(['https://example.com/a' => Http::redirect('/b')]);

        Assert::same($this->fetcher($client, maxRedirects: 0)->fetch(url: 'https://example.com/a')->error()?->reason, FetchFailureReason::TooManyRedirects);
    }

    public function redirectLoopEndsAtTheLimit(): void
    {
        $client = Http::client(['https://example.com/a' => Http::redirect('/a')]);

        Assert::same($this->fetcher($client, maxRedirects: 3)->fetch(url: 'https://example.com/a')->error()?->reason, FetchFailureReason::TooManyRedirects);
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

        Assert::same($result->error()?->reason, FetchFailureReason::PolicyRejected);
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

        Assert::same($this->fetcher($client)->fetch(url: 'http://10.0.0.1/')->error()?->reason, FetchFailureReason::PolicyRejected);
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

        Assert::same($this->fetcher($client)->fetch(url: 'https://example.com/a')->error()?->reason, FetchFailureReason::InvalidRedirect);
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

        Assert::same($result->error()?->reason, FetchFailureReason::BudgetExceeded);
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
        Assert::same($this->fetcher(Http::failing())->fetch(url: 'https://example.com/')->error()?->reason, FetchFailureReason::Network);
    }

    public function transportFailureOnALaterHop(): void
    {
        $client = Http::client(['https://example.com/a' => Http::redirect('https://gone.example/')]);

        Assert::same($this->fetcher($client)->fetch(url: 'https://example.com/a')->error()?->reason, FetchFailureReason::Network);
    }

    #[DataProvider('statusProvider')]
    public function nonSuccessStatusFails(int $status): void
    {
        $client = Http::client(['https://example.com/' => Http::html('<p>x</p>', status: $status)]);

        Assert::same($this->fetcher($client)->fetch(url: 'https://example.com/')->error()?->reason, FetchFailureReason::HttpStatus);
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

        Assert::same($this->fetcher($client)->fetch(url: 'https://example.com/')->error()?->reason, FetchFailureReason::UnexpectedContentType);
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

        Assert::same($this->fetcher($client, maxBodyBytes: 100)->fetch(url: 'https://example.com/')->error()?->reason, FetchFailureReason::TooLarge);
    }

    public function actualBodyOverTheLimitFailsEvenWithoutALengthHeader(): void
    {
        $client = Http::client(['https://example.com/' => Http::html(str_repeat('a', 101))]);

        Assert::same($this->fetcher($client, maxBodyBytes: 100)->fetch(url: 'https://example.com/')->error()?->reason, FetchFailureReason::TooLarge);
    }

    public function actualBodyOverTheLimitFailsWhenTheHeaderLies(): void
    {
        $response = new Response(200, ['Content-Type' => 'text/html', 'Content-Length' => '5'], str_repeat('a', 20_000));
        $client = Http::client(['https://example.com/' => $response]);

        Assert::same($this->fetcher($client, maxBodyBytes: 10_000)->fetch(url: 'https://example.com/')->error()?->reason, FetchFailureReason::TooLarge);
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

    public function fetchesJson(): void
    {
        $client = Http::client([
            'https://api.example.com/x' => new Response(200, ['Content-Type' => 'application/json; charset=utf-8'], '{"a":1}'),
            'https://api.example.com/y' => new Response(200, ['Content-Type' => 'application/vnd.api+json'], '{}'),
        ]);

        $fetcher = $this->fetcher($client);

        Assert::same($fetcher->fetchJson(url: 'https://api.example.com/x')->unwrap()->body, '{"a":1}');
        Assert::same($fetcher->fetchJson(url: 'https://api.example.com/y')->unwrap()->contentType, 'application/vnd.api+json');
        Assert::same(Http::requests($client)[0]->getHeaderLine('Accept'), 'application/json');
    }

    #[DataProvider('notJsonProvider')]
    public function jsonFetchRefusesOtherTypes(string $contentType): void
    {
        $client = Http::client(['https://api.example.com/x' => new Response(200, ['Content-Type' => $contentType], '<p>')]);

        Assert::same($this->fetcher($client)->fetchJson(url: 'https://api.example.com/x')->error()?->reason, FetchFailureReason::UnexpectedContentType);
    }

    public static function notJsonProvider(): iterable
    {
        yield 'html' => ['text/html'];
        yield 'plain' => ['text/plain'];
        yield 'json look-alike' => ['application/jsonp'];
        yield 'json inside a parameter' => ['text/plain; x=application/json'];
    }

    public function htmlFetchRefusesJson(): void
    {
        $client = Http::client(['https://api.example.com/x' => Adapters::json('{}')]);

        Assert::same($this->fetcher($client)->fetch(url: 'https://api.example.com/x')->error()?->reason, FetchFailureReason::UnexpectedContentType);
    }

    public function jsonFetchObeysThePolicyOnEveryHop(): void
    {
        $client = Http::client([
            'https://api.example.com/x' => Http::redirect('http://169.254.169.254/latest/meta-data/'),
        ]);

        Assert::same($this->fetcher($client)->fetchJson(url: 'https://api.example.com/x')->error()?->reason, FetchFailureReason::PolicyRejected);
        Assert::same(Http::requestedUrls($client), ['https://api.example.com/x']);
        Assert::same($this->fetcher($client)->fetchJson(url: 'http://127.0.0.1/api')->error()?->reason, FetchFailureReason::PolicyRejected);
    }

    public function customHeadersGoToTheFirstHostOnly(): void
    {
        $client = Http::client([
            'https://api.example.com/x' => Http::redirect('/y'),
            'https://api.example.com/y' => Http::redirect('https://elsewhere.example.net/z'),
            'https://elsewhere.example.net/z' => Http::redirect('https://api.example.com/w'),
            'https://api.example.com/w' => Adapters::json('{}'),
        ]);

        $this->fetcher($client)->fetchJson(url: 'https://api.example.com/x', headers: ['Authorization' => 'Bearer secret']);

        $sent = array_map(static fn($request): string => $request->getHeaderLine('Authorization'), Http::requests($client));
        Assert::same($sent, ['Bearer secret', 'Bearer secret', '', 'Bearer secret']);
    }

    public function checkReturnsTheNormalisedUrl(): void
    {
        $fetcher = $this->fetcher(Http::client([]));

        Assert::same($fetcher->check(url: 'HTTPS://Example.COM:443/a#f')->value(), 'https://example.com/a');
        Assert::same($fetcher->check(url: 'http://127.0.0.1/')->error(), \Rasuvaeff\LinkPreview\Fetch\UrlRejection::PrivateAddress);
    }

    public function aStatusFailureCarriesTheStatusAndRetryAfter(): void
    {
        $client = Http::client(['https://example.com/' => new Response(503, ['Retry-After' => '120'])]);

        $failure = $this->fetcher($client)->fetch(url: 'https://example.com/')->error();

        Assert::same($failure?->reason, FetchFailureReason::HttpStatus);
        Assert::same($failure?->status, 503);
        Assert::same($failure?->retryAfterSeconds, 120);
        Assert::true($failure?->isTransient() === true);
    }

    #[DataProvider('retryAfterProvider')]
    public function retryAfterIsReadAsSecondsOrAsADateAgainstTheResponseDate(array $headers, ?int $expected): void
    {
        $client = Http::client(['https://example.com/' => new Response(429, $headers)]);

        Assert::same($this->fetcher($client)->fetch(url: 'https://example.com/')->error()?->retryAfterSeconds, $expected);
    }

    public static function retryAfterProvider(): iterable
    {
        yield 'none' => [[], null];
        yield 'delta seconds' => [['Retry-After' => ' 30 '], 30];
        yield 'zero' => [['Retry-After' => '0'], 0];
        yield 'capped at a day' => [['Retry-After' => '9999999999'], 86_400];
        yield 'one under the cap' => [['Retry-After' => '86399'], 86_399];
        yield 'negative' => [['Retry-After' => '-5'], null];
        yield 'fraction' => [['Retry-After' => '1.5'], null];
        yield 'date against Date' => [['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT', 'Date' => 'Wed, 21 Oct 2026 07:26:30 GMT'], 90];
        yield 'date in the past' => [['Retry-After' => 'Wed, 21 Oct 2026 07:20:00 GMT', 'Date' => 'Wed, 21 Oct 2026 07:26:30 GMT'], 0];
        yield 'date far ahead is capped' => [['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT', 'Date' => 'Wed, 14 Oct 2026 07:28:00 GMT'], 86_400];
        yield 'date without Date' => [['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT'], null];
        yield 'garbage' => [['Retry-After' => 'soon', 'Date' => 'Wed, 21 Oct 2026 07:26:30 GMT'], null];
        yield 'garbage Date' => [['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT', 'Date' => 'yesterday'], null];
    }

    public function aNetworkErrorIsNetworkAndAnyOtherClientErrorIsARefusal(): void
    {
        $network = $this->fetcher(Http::failing())->fetch(url: 'https://example.com/')->error();
        $refused = $this->fetcher(Http::refusing())->fetch(url: 'https://example.com/')->error();

        Assert::same($network?->reason, FetchFailureReason::Network);
        Assert::true($network?->isTransient() === true);
        Assert::same($refused?->reason, FetchFailureReason::ClientRefused);
        Assert::false($refused?->isTransient() ?? true);
        Assert::null($refused?->status);
    }

    public function otherFailuresCarryNoStatus(): void
    {
        $failure = $this->fetcher(Http::client(['https://example.com/' => Http::redirect('/')]), maxRedirects: 0)->fetch(url: 'https://example.com/')->error();

        Assert::same($failure?->reason, FetchFailureReason::TooManyRedirects);
        Assert::null($failure?->status);
        Assert::null($failure?->retryAfterSeconds);
    }

    public function fetchesMediaOfAnAcceptedType(): void
    {
        $client = Http::client([
            'https://cdn.example.com/a' => Http::redirect('https://cdn.example.com/a.png'),
            'https://cdn.example.com/a.png' => new Response(200, ['Content-Type' => 'Image/PNG'], 'PNGDATA'),
        ]);

        $media = $this->fetcher($client)->fetchMedia(url: 'https://cdn.example.com/a', acceptedTypes: ['image/jpeg', 'image/png'], maxBytes: 7)->unwrap();

        Assert::same($media->url, 'https://cdn.example.com/a.png');
        Assert::same($media->body, 'PNGDATA');
        Assert::same($media->contentType, 'image/png');
        Assert::same($media->redirects, 1);
        Assert::same(Http::requests($client)[0]->getHeaderLine('Accept'), 'image/jpeg, image/png');
        Assert::same(Http::requests($client)[0]->getHeaderLine('User-Agent'), UserAgentMap::DEFAULT);
    }

    #[DataProvider('mediaTypeProvider')]
    public function mediaTypesMatchExactlyOrByFamily(string $contentType, bool $accepted): void
    {
        $client = Http::client(['https://cdn.example.com/x' => new Response(200, ['Content-Type' => $contentType], 'x')]);

        $result = $this->fetcher($client)->fetchMedia(url: 'https://cdn.example.com/x', acceptedTypes: ['image/*', 'video/mp4'], maxBytes: 10);

        Assert::same($result->isOk(), $accepted);
        Assert::same($result->error()?->reason, $accepted ? null : FetchFailureReason::UnexpectedContentType);
    }

    public static function mediaTypeProvider(): iterable
    {
        yield 'family member' => ['image/webp', true];
        yield 'exact' => ['video/mp4', true];
        yield 'with parameters' => ['image/png; q=1', true];
        yield 'other member of an exact family' => ['video/webm', false];
        yield 'family look-alike' => ['imagex/png', false];
        yield 'bare family name' => ['image', false];
        yield 'html' => ['text/html', false];
        yield 'missing' => ['', false];
    }

    public function mediaHasItsOwnSizeLimit(): void
    {
        $client = Http::client([
            'https://cdn.example.com/big' => new Response(200, ['Content-Type' => 'image/png'], str_repeat('x', 11)),
            'https://cdn.example.com/declared' => new Response(200, ['Content-Type' => 'image/png', 'Content-Length' => '11'], 'x'),
            'https://cdn.example.com/fits' => new Response(200, ['Content-Type' => 'image/png'], str_repeat('x', 10)),
        ]);
        $fetcher = $this->fetcher($client, maxBodyBytes: 1);

        Assert::same($fetcher->fetchMedia(url: 'https://cdn.example.com/big', acceptedTypes: ['image/png'], maxBytes: 10)->error()?->reason, FetchFailureReason::TooLarge);
        Assert::same($fetcher->fetchMedia(url: 'https://cdn.example.com/declared', acceptedTypes: ['image/png'], maxBytes: 10)->error()?->reason, FetchFailureReason::TooLarge);
        Assert::same($fetcher->fetchMedia(url: 'https://cdn.example.com/fits', acceptedTypes: ['image/png'], maxBytes: 10)->unwrap()->body, str_repeat('x', 10));
    }

    public function mediaObeysThePolicyOnEveryHop(): void
    {
        $client = Http::client(['https://cdn.example.com/x' => Http::redirect('http://169.254.169.254/latest/meta-data/')]);

        Assert::same($this->fetcher($client)->fetchMedia(url: 'https://cdn.example.com/x', acceptedTypes: ['image/*'], maxBytes: 10)->error()?->reason, FetchFailureReason::PolicyRejected);
        Assert::same(Http::requestedUrls($client), ['https://cdn.example.com/x']);
    }

    #[DataProvider('invalidMediaProvider')]
    public function rejectsAnInvalidMediaRequest(array $types, int $maxBytes): void
    {
        Expect::exception(InvalidArgumentException::class);

        $this->fetcher(Http::client([]))->fetchMedia(url: 'https://cdn.example.com/x', acceptedTypes: $types, maxBytes: $maxBytes);
    }

    public static function invalidMediaProvider(): iterable
    {
        yield 'no types' => [[], 10];
        yield 'zero bytes' => [['image/png'], 0];
        yield 'no subtype' => [['image'], 10];
        yield 'upper case' => [['Image/png'], 10];
        yield 'wildcard type' => [['*/*'], 10];
        yield 'parameter' => [['image/png; q=1'], 10];
        yield 'trailing newline' => [["image/png\n"], 10];
        yield 'second type broken' => [['image/png', 'bad'], 10];
    }


    #[DataProvider('edgeStatusProvider')]
    public function theStatusIsKeptWhenItIsAnHttpStatus(int $status, ?int $kept): void
    {
        $response = Understudy::for(ResponseInterface::class);
        when(fn() => $response->getStatusCode())->returns($status);
        when(fn() => $response->getHeaderLine(Arg::any()))->returns('');
        $client = Understudy::for(ClientInterface::class);
        when(fn() => $client->sendRequest(Arg::any()))->returns($response);

        $failure = $this->fetcher($client)->fetch(url: 'https://example.com/')->error();

        Assert::same($failure?->reason, FetchFailureReason::HttpStatus);
        Assert::same($failure?->status, $kept);
    }

    public static function edgeStatusProvider(): iterable
    {
        yield 'below' => [99, null];
        yield 'first' => [100, 100];
        yield 'last' => [599, 599];
        yield 'above' => [600, null];
    }

    public function retryAfterIsReadFromUntrimmedHeaders(): void
    {
        $delta = Understudy::for(ResponseInterface::class);
        when(fn() => $delta->getStatusCode())->returns(503);
        when(fn() => $delta->getHeaderLine('Retry-After'))->returns(" 30\t");
        $dated = Understudy::for(ResponseInterface::class);
        when(fn() => $dated->getStatusCode())->returns(503);
        when(fn() => $dated->getHeaderLine('Retry-After'))->returns(' Wed, 21 Oct 2026 07:28:00 GMT ');
        when(fn() => $dated->getHeaderLine('Date'))->returns(' Wed, 21 Oct 2026 07:27:00 GMT ');

        Assert::same(RetryAfter::seconds($delta), 30);
        Assert::same(RetryAfter::seconds($dated), 60);
    }

    public function aMediaLimitOfOneByteIsValid(): void
    {
        $client = Http::client(['https://cdn.example.com/x' => new Response(200, ['Content-Type' => 'image/png'], 'x')]);

        Assert::same($this->fetcher($client)->fetchMedia(url: 'https://cdn.example.com/x', acceptedTypes: ['image/png'], maxBytes: 1)->unwrap()->body, 'x');
    }

    public function aFailureIsTransientByItsReasonAndStatus(): void
    {
        Assert::true((new FetchFailure(FetchFailureReason::Network))->isTransient());
        Assert::true((new FetchFailure(FetchFailureReason::BudgetExceeded))->isTransient());
        Assert::true((new FetchFailure(FetchFailureReason::HttpStatus, status: 500))->isTransient());
        Assert::true((new FetchFailure(FetchFailureReason::HttpStatus, status: 408))->isTransient());
        Assert::false((new FetchFailure(FetchFailureReason::HttpStatus, status: 499))->isTransient());
        Assert::false((new FetchFailure(FetchFailureReason::HttpStatus))->isTransient());
        Assert::false((new FetchFailure(FetchFailureReason::ClientRefused))->isTransient());
        Assert::false((new FetchFailure(FetchFailureReason::TooLarge, status: 503))->isTransient());
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
