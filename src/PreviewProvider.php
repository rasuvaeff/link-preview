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
use Rasuvaeff\LinkPreview\Fetch\FetchFailure;
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
 * reported as such ({@see PreviewFailureReason::MissingToken}).
 *
 * `PageInfo::$url` is always the fetched URL (after redirects); what the page
 * claims about its own address goes to `PageInfo::$canonicalUrl`.
 *
 * @api
 */
final readonly class PreviewProvider implements LinkPreviewer
{
    /** @var list<Extractor> */
    private array $extractors;

    private AdapterRegistry $adapters;

    /**
     * @param list<Extractor>|null $extractors highest priority first
     * @param AdapterRegistry|null $adapters site adapters; `null` is {@see AdapterRegistry::standard()}
     *        without tokens, an empty `new AdapterRegistry()` turns adapters off
     * @param bool $hostAsSiteName when no source names the site, use the host of `PageInfo::$url`
     *        without `www.` (ASCII, as the policy normalised it). Off by default, so that a site name is
     *        always something the page or its service said
     */
    public function __construct(
        private Fetcher $fetcher,
        ?array $extractors = null,
        private Limits $limits = new Limits(),
        ?AdapterRegistry $adapters = null,
        private bool $hostAsSiteName = false,
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

    #[\Override]
    public function preview(string $url): Result
    {
        $checked = $this->fetcher->check(url: $url);

        if ($checked->isErr()) {
            return $this->failure(PreviewFailureReason::PolicyRejected);
        }

        $url = $checked->unwrap();
        $context = new AdapterContext($this->fetcher);
        $host = parse_url($url, PHP_URL_HOST);
        $adapter = $this->adapters->find(\is_string($host) ? $host : '');
        $siteResult = $adapter?->preview($url, $context);
        $site = $siteResult?->value();
        $fetched = $context->html($url);
        $failure = $fetched->error();

        if ($failure instanceof FetchFailure) {
            if ($site instanceof Metadata) {
                return $this->build(first: $site, document: null, pageUrl: $url);
            }

            return $siteResult?->error() === AdapterFailure::MissingToken
                ? $this->failure(PreviewFailureReason::MissingToken)
                : Result::err(error: PreviewFailure::fromFetch($failure));
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
     * `$pageUrl` passes the URL policy first, and `PageInfo::$url` is its normalised form.
     */
    #[\Override]
    public function fromHtml(string $html, string $pageUrl, ?string $charset = null): Result
    {
        $checked = $this->fetcher->check(url: $pageUrl);

        if ($checked->isErr()) {
            return $this->failure(PreviewFailureReason::PolicyRejected);
        }

        return $this->build(first: null, document: PageDocument::parse(html: $html, charset: $charset), pageUrl: $checked->unwrap());
    }

    /**
     * @return Result<PageInfo, PreviewFailure>
     */
    private function failure(PreviewFailureReason $reason): Result
    {
        return Result::err(error: new PreviewFailure($reason));
    }

    /**
     * @param non-empty-string $pageUrl
     *
     * @return Result<PageInfo, PreviewFailure>
     */
    private function build(?Metadata $first, ?PageDocument $document, string $pageUrl): Result
    {
        $found = $first instanceof Metadata ? [$first] : [];

        if ($document instanceof PageDocument) {
            foreach ($this->extractors as $extractor) {
                $metadata = $extractor->extract(document: $document, pageUrl: $pageUrl)->value();

                if ($metadata instanceof Metadata) {
                    $found[] = $metadata;
                }
            }
        }

        if ($found === []) {
            return $this->failure(PreviewFailureReason::EmptyPage);
        }

        $merged = MetadataMerger::merge(byPriority: $found);

        return Result::ok(value: $this->limits->apply(new PageInfo(
            url: $pageUrl,
            title: $merged->title,
            description: $merged->description,
            image: $merged->image,
            favicon: $merged->favicon,
            siteName: $merged->siteName ?? ($this->hostAsSiteName ? $this->host($pageUrl) : null),
            language: $merged->language,
            authorName: $merged->authorName,
            publishedTime: $merged->publishedTime,
            type: $merged->type,
            embedUrl: $merged->embedUrl,
            canonicalUrl: $merged->url,
            imageWidth: $merged->imageWidth,
            imageHeight: $merged->imageHeight,
            providerName: $merged->providerName,
        )));
    }

    private function host(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
