<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview;

use Rasuvaeff\LinkPreview\Adapter\AdapterContext;
use Rasuvaeff\LinkPreview\Adapter\AdapterFailure;
use Rasuvaeff\LinkPreview\Adapter\AdapterRegistry;
use Rasuvaeff\LinkPreview\Extract\Extractor;
use Rasuvaeff\LinkPreview\Extract\HtmlFallbackExtractor;
use Rasuvaeff\LinkPreview\Extract\JsonLdExtractor;
use Rasuvaeff\LinkPreview\Extract\Metadata;
use Rasuvaeff\LinkPreview\Extract\MetadataMerger;
use Rasuvaeff\LinkPreview\Extract\OEmbedExtractor;
use Rasuvaeff\LinkPreview\Extract\OpenGraphExtractor;
use Rasuvaeff\LinkPreview\Extract\TwitterCardsExtractor;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\Html\PageDocument;
use Rasuvaeff\Result\Result;

/**
 * The entry point: ask the adapter of the URL's site, fetch the page, read it
 * with every extractor, merge by fixed priority, apply the limits.
 *
 * Priority, highest first: the site adapter, then the extractors in the order
 * given (by default oEmbed, JSON-LD, OpenGraph, Twitter Cards, plain HTML).
 * For every field the first source that has it wins.
 *
 * An adapter that fails does not fail the preview: the generic extractors still
 * run. If the page cannot be fetched either, an adapter's missing token is
 * reported as such ({@see PreviewFailure::MissingToken}).
 *
 * @api
 */
final readonly class PreviewProvider
{
    /** @var list<Extractor> */
    private array $extractors;

    private AdapterRegistry $adapters;

    /**
     * @param list<Extractor>|null $extractors highest priority first
     * @param AdapterRegistry|null $adapters site adapters; `null` is {@see AdapterRegistry::standard()}
     *        without tokens, an empty `new AdapterRegistry()` turns adapters off
     */
    public function __construct(
        private Fetcher $fetcher,
        ?array $extractors = null,
        private Limits $limits = new Limits(),
        ?AdapterRegistry $adapters = null,
    ) {
        $this->extractors = $extractors ?? [
            new OEmbedExtractor($fetcher),
            new JsonLdExtractor(),
            new OpenGraphExtractor(),
            new TwitterCardsExtractor(),
            new HtmlFallbackExtractor(),
        ];
        $this->adapters = $adapters ?? AdapterRegistry::standard();
    }

    /**
     * @return Result<PageInfo, PreviewFailure>
     */
    public function preview(string $url): Result
    {
        $checked = $this->fetcher->check(url: $url);

        if ($checked->isErr()) {
            return Result::err(error: PreviewFailure::PolicyRejected);
        }

        $url = $checked->unwrap();
        $context = new AdapterContext($this->fetcher);
        $host = parse_url($url, PHP_URL_HOST);
        $adapter = $this->adapters->find(\is_string($host) ? $host : '');
        $siteResult = $adapter?->preview($url, $context);
        $site = $siteResult?->value();
        $fetched = $context->html($url);

        if ($fetched->isErr()) {
            if ($site instanceof Metadata) {
                return $this->build(first: $site, document: null, pageUrl: $url);
            }

            $failure = $fetched->error();

            return Result::err(
                error: $siteResult?->error() === AdapterFailure::MissingToken || $failure === null
                    ? PreviewFailure::MissingToken
                    : PreviewFailure::fromFetch($failure),
            );
        }

        $page = $fetched->unwrap();

        return $this->build(
            first: $site instanceof Metadata ? $site : null,
            document: PageDocument::parse(html: $page->body, charset: $page->charset),
            pageUrl: $page->url,
        );
    }

    /**
     * The same, for a document that is already in hand. Adapters are not
     * consulted; extractors that need the network (oEmbed) still make their requests.
     *
     * @param non-empty-string $pageUrl
     *
     * @return Result<PageInfo, PreviewFailure>
     */
    public function fromHtml(string $html, string $pageUrl, ?string $charset = null): Result
    {
        return $this->build(first: null, document: PageDocument::parse(html: $html, charset: $charset), pageUrl: $pageUrl);
    }

    /**
     * @param non-empty-string $pageUrl
     *
     * @return Result<PageInfo, PreviewFailure>
     */
    private function build(?Metadata $first, ?PageDocument $document, string $pageUrl): Result
    {
        $found = $first === null ? [] : [$first];

        if ($document !== null) {
            foreach ($this->extractors as $extractor) {
                $metadata = $extractor->extract(document: $document, pageUrl: $pageUrl)->value();

                if ($metadata instanceof Metadata) {
                    $found[] = $metadata;
                }
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
            embedUrl: $merged->embedUrl,
        )));
    }
}
