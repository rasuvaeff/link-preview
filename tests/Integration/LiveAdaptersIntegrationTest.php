<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\PreviewProvider;
use Testo\Assert;
use Testo\CoversNothing;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Real services. Skipped unless LINK_PREVIEW_LIVE=1, because they need the internet and the
 * pages they read change; run them to check that a recorded fixture still looks like the real
 * answer, then refresh it with `bin/record-preview-corpus --record=<name> --live`.
 *
 *   docker run --rm --network host -v "$PWD":/app -w /app -e LINK_PREVIEW_LIVE=1 link-preview-php:local vendor/bin/testo --suite=Integration
 */
#[Test]
#[CoversNothing]
final class LiveAdaptersIntegrationTest
{
    #[DataProvider('urlProvider')]
    public function previewsARealPage(string $url): void
    {
        if (getenv('LINK_PREVIEW_LIVE') !== '1') {
            Assert::true(true);

            return;
        }

        $provider = new PreviewProvider(fetcher: new Fetcher(
            client: new Client(['allow_redirects' => false, 'http_errors' => false, 'timeout' => 10.0]),
            requestFactory: new HttpFactory(),
        ));

        $result = $provider->preview(url: $url);

        Assert::true($result->isOk());
        Assert::true($result->unwrap()->title !== null || $result->unwrap()->description !== null);
    }

    public static function urlProvider(): iterable
    {
        yield 'youtube' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'wikipedia' => ['https://en.wikipedia.org/wiki/HTTP'];
        yield 'github' => ['https://github.com/rasuvaeff/result'];
        yield 'plain page' => ['https://example.com/'];
    }
}
