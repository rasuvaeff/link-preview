<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview;

use Rasuvaeff\LinkPreview\Extract\Extractor;
use Rasuvaeff\LinkPreview\Extract\HtmlFallbackExtractor;
use Rasuvaeff\LinkPreview\Extract\Metadata;
use Rasuvaeff\LinkPreview\Extract\MetadataMerger;
use Rasuvaeff\LinkPreview\Extract\OpenGraphExtractor;
use Rasuvaeff\LinkPreview\Extract\TwitterCardsExtractor;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\Html\PageDocument;
use Rasuvaeff\Result\Result;

/**
 * The entry point: fetch a page, read it with every extractor, merge by fixed
 * priority (the order of `$extractors`; by default OpenGraph, then Twitter
 * Cards, then plain HTML), apply the limits.
 *
 * @api
 */
final readonly class PreviewProvider
{
    /** @var list<Extractor> */
    private array $extractors;

    /**
     * @param list<Extractor>|null $extractors highest priority first
     */
    public function __construct(
        private Fetcher $fetcher,
        ?array $extractors = null,
        private Limits $limits = new Limits(),
    ) {
        $this->extractors = $extractors ?? [
            new OpenGraphExtractor(),
            new TwitterCardsExtractor(),
            new HtmlFallbackExtractor(),
        ];
    }

    /**
     * @return Result<PageInfo, PreviewFailure>
     */
    public function preview(string $url): Result
    {
        $fetched = $this->fetcher->fetch(url: $url);

        if ($fetched->isErr()) {
            $failure = $fetched->error();

            return Result::err(error: $failure === null ? PreviewFailure::Network : PreviewFailure::fromFetch($failure));
        }

        $page = $fetched->unwrap();

        return $this->fromHtml(html: $page->body, pageUrl: $page->url, charset: $page->charset);
    }

    /**
     * The same, for a document that is already in hand.
     *
     * @param non-empty-string $pageUrl
     *
     * @return Result<PageInfo, PreviewFailure>
     */
    public function fromHtml(string $html, string $pageUrl, ?string $charset = null): Result
    {
        $document = PageDocument::parse(html: $html, charset: $charset);
        $found = [];

        foreach ($this->extractors as $extractor) {
            $metadata = $extractor->extract(document: $document, pageUrl: $pageUrl)->value();

            if ($metadata instanceof Metadata) {
                $found[] = $metadata;
            }
        }

        if ($found === []) {
            return Result::err(error: PreviewFailure::EmptyPage);
        }

        $merged = MetadataMerger::merge(byPriority: $found);

        return Result::ok(value: $this->limits->apply(new PageInfo(
            url: $merged->url ?? $pageUrl,
            title: $merged->title,
            description: $merged->description,
            image: $merged->image,
            favicon: $merged->favicon,
            siteName: $merged->siteName,
            language: $merged->language,
            authorName: $merged->authorName,
            publishedTime: $merged->publishedTime,
            type: $merged->type,
        )));
    }
}
