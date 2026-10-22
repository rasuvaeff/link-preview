<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Adapter;

use Rasuvaeff\LinkPreview\Extract\Metadata;
use Rasuvaeff\Result\Result;

/**
 * GitHub repositories, issues, pull requests and gists from the page's own
 * OpenGraph tags. The `GitHub - ` prefix of repository titles is dropped and the
 * site name is filled in (`GitHub`, `GitHub Gist`). Needs nothing.
 *
 * @api
 */
final readonly class GitHubAdapter implements Adapter
{
    #[\Override]
    public function domains(): array
    {
        return ['github.com'];
    }

    #[\Override]
    public function preview(string $url, AdapterContext $context): Result
    {
        $page = PageMetadata::read($context, $url);

        if ($page->isErr()) {
            return $page;
        }

        $metadata = $page->unwrap();
        $isGist = OEmbedSupport::parts($url)['host'] === 'gist.github.com';
        $title = $metadata->title === null ? null : preg_replace('/\AGitHub - /', '', $metadata->title);

        return Result::ok(value: OEmbedSupport::with(
            new Metadata(title: $title === '' ? null : $title, siteName: $isGist ? 'GitHub Gist' : 'GitHub'),
            $metadata,
        ));
    }
}
