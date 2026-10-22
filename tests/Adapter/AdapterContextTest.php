<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Adapter;

use Rasuvaeff\LinkPreview\Adapter\AdapterContext;
use Rasuvaeff\LinkPreview\Tests\Support\Adapters;
use Rasuvaeff\LinkPreview\Tests\Support\Http;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(AdapterContext::class)]
final class AdapterContextTest
{
    public function aPageIsFetchedOnce(): void
    {
        $client = Http::client(['https://example.com/a' => Http::html('<title>A</title>')]);
        $context = Adapters::context($client);

        $first = $context->html('https://example.com/a');
        $second = $context->html('https://example.com/a');

        Assert::same($second, $first);
        Assert::same(Http::requestedUrls($client), ['https://example.com/a']);
    }

    public function aFailureIsRememberedToo(): void
    {
        $client = Http::client([]);
        $context = Adapters::context($client);

        $context->html('https://example.com/a');
        $context->html('https://example.com/a');

        Assert::same(\count(Http::requests($client)), 1);
    }

    public function differentPagesAreDifferentRequests(): void
    {
        $client = Http::client(['https://example.com/a' => Http::html('a'), 'https://example.com/b' => Http::html('b')]);
        $context = Adapters::context($client);

        Assert::same($context->html('https://example.com/a')->unwrap()->body, 'a');
        Assert::same($context->html('https://example.com/b')->unwrap()->body, 'b');
    }

    public function jsonIsFetchedAsJson(): void
    {
        $client = Http::client(['https://example.com/api' => Adapters::json('{"a":1}')]);

        $page = Adapters::context($client)->json('https://example.com/api', ['X-Test' => 'yes'])->unwrap();

        Assert::same($page->body, '{"a":1}');
        Assert::same(Http::requests($client)[0]->getHeaderLine('X-Test'), 'yes');
        Assert::same(Http::requests($client)[0]->getHeaderLine('Accept'), 'application/json');
    }
}
