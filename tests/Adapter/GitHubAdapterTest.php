<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Adapter;

use Rasuvaeff\LinkPreview\Adapter\AdapterFailure;
use Rasuvaeff\LinkPreview\Adapter\GitHubAdapter;
use Rasuvaeff\LinkPreview\Tests\Support\Adapters;
use Rasuvaeff\LinkPreview\Tests\Support\Http;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(GitHubAdapter::class)]
final class GitHubAdapterTest
{
    public function readsTheRepositoryPage(): void
    {
        $client = Http::client(['https://github.com/rasuvaeff/result' => Http::html(Adapters::fixture('github/repo.html'))]);

        $metadata = (new GitHubAdapter())->preview('https://github.com/rasuvaeff/result', Adapters::context($client))->unwrap();

        Assert::same($metadata->title, 'rasuvaeff/result: Typed Result and Option primitives for PHP');
        Assert::same($metadata->siteName, 'GitHub');
        Assert::same(Http::requestedUrls($client), ['https://github.com/rasuvaeff/result']);
    }

    public function namesGists(): void
    {
        $client = Http::client(['https://gist.github.com/octocat/6cad' => Http::html(Adapters::fixture('github/gist.html'))]);

        $metadata = (new GitHubAdapter())->preview('https://gist.github.com/octocat/6cad', Adapters::context($client))->unwrap();

        Assert::same($metadata->siteName, 'GitHub Gist');
        Assert::same($metadata->title, 'Example gist');
    }

    public function onlyTheLeadingPrefixIsStripped(): void
    {
        $html = '<meta property="og:title" content="GitHub - a/b: GitHub - tool">';
        $client = Http::client(['https://github.com/a/b' => Http::html($html)]);

        Assert::same((new GitHubAdapter())->preview('https://github.com/a/b', Adapters::context($client))->unwrap()->title, 'a/b: GitHub - tool');
    }

    public function aPageWithoutMetadataIsAnInvalidResponse(): void
    {
        $client = Http::client(['https://github.com/a/b' => Http::html('<html><body>login</body></html>')]);

        Assert::same((new GitHubAdapter())->preview('https://github.com/a/b', Adapters::context($client))->error(), AdapterFailure::InvalidResponse);
    }

    public function unreachablePageIsUnavailable(): void
    {
        Assert::same((new GitHubAdapter())->preview('https://github.com/a/b', Adapters::context(Http::client([])))->error(), AdapterFailure::Unavailable);
    }

    public function servesItsDomains(): void
    {
        Assert::same((new GitHubAdapter())->domains(), ['github.com']);
    }
}
