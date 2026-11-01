<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Adapter;

use Rasuvaeff\LinkPreview\Adapter\AdapterFailure;
use Rasuvaeff\LinkPreview\Adapter\OEmbedSupport;
use Rasuvaeff\LinkPreview\Tests\Support\Adapters;
use Rasuvaeff\LinkPreview\Tests\Support\Http;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(OEmbedSupport::class)]
final class OEmbedSupportTest
{
    public function partsAreLowerCasedAndDefaulted(): void
    {
        Assert::same(OEmbedSupport::parts('HTTPS://Example.COM/Path?z=1&a[]=2&b=x'), [
            'host' => 'example.com',
            'path' => '/Path',
            'query' => ['z' => '1', 'b' => 'x'],
        ]);
        Assert::same(OEmbedSupport::parts('https://example.com'), ['host' => 'example.com', 'path' => '/', 'query' => []]);
    }

    public function theEndpointCarriesAnEncodedQuery(): void
    {
        Assert::same(OEmbedSupport::endpoint('https://o.example/oembed', ['url' => 'https://a.example/x y?z=1&w=2', 'n' => '1']), 'https://o.example/oembed?url=https%3A%2F%2Fa.example%2Fx%20y%3Fz%3D1%26w%3D2&n=1');
    }

    public function anUnreadableAnswerIsRecordedAndFails(): void
    {
        $context = Adapters::context(Http::sequence(Adapters::json('[]')));

        $result = OEmbedSupport::fetch($context, 'https://o.example/oembed?x=1');

        Assert::same($result->error(), AdapterFailure::InvalidResponse);
        Assert::same($context->diagnostics(), ['oEmbed $: nothing to show']);
    }
}
