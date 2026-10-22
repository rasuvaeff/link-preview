<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Adapter;

use Rasuvaeff\LinkPreview\Adapter\AdapterFailure;
use Rasuvaeff\LinkPreview\Adapter\InstagramAdapter;
use Rasuvaeff\LinkPreview\Tests\Support\Adapters;
use Rasuvaeff\LinkPreview\Tests\Support\Http;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(InstagramAdapter::class)]
final class InstagramAdapterTest
{
    private const string URL = 'https://www.instagram.com/p/CxYz123AbC/';

    #[DataProvider('noTokenProvider')]
    public function withoutATokenItRefusesAndAsksNothing(?string $token): void
    {
        $client = Http::client([]);

        $result = (new InstagramAdapter(accessToken: $token))->preview(self::URL, Adapters::context($client));

        Assert::same($result->error(), AdapterFailure::MissingToken);
        Assert::same(Http::requests($client), []);
    }

    public static function noTokenProvider(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
    }

    public function sendsTheTokenAsAHeaderOnly(): void
    {
        $client = Http::sequence(Adapters::json(Adapters::fixture('instagram/oembed.json')));

        $metadata = (new InstagramAdapter(accessToken: 'secret-token'))->preview(self::URL, Adapters::context($client))->unwrap();

        $request = Http::requests($client)[0];
        Assert::same((string) $request->getUri(), 'https://graph.facebook.com/v21.0/instagram_oembed?url=https%3A%2F%2Fwww.instagram.com%2Fp%2FCxYz123AbC%2F&omit_script=true');
        Assert::same($request->getHeaderLine('Authorization'), 'Bearer secret-token');
        Assert::false(str_contains((string) $request->getUri(), 'secret-token'));
        Assert::same($metadata->authorName, 'natgeo');
        Assert::same($metadata->siteName, 'Instagram');
    }

    public function theTokenNeverReachesAnExceptionOrAFailure(): void
    {
        $client = Http::failing();

        $result = (new InstagramAdapter(accessToken: 'secret-token'))->preview(self::URL, Adapters::context($client));

        Assert::same($result->error(), AdapterFailure::Unavailable);
        Assert::false(str_contains(var_export($result, true), 'secret-token'));
    }

    public function unreadableAnswerIsReported(): void
    {
        $client = Http::sequence(Adapters::json('{"error":{"message":"bad token"}}'));

        Assert::same((new InstagramAdapter(accessToken: 'x'))->preview(self::URL, Adapters::context($client))->error(), AdapterFailure::InvalidResponse);
    }

    public function servesItsDomains(): void
    {
        Assert::same((new InstagramAdapter())->domains(), ['instagram.com', 'instagr.am']);
    }
}
