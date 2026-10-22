<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Adapter;

use Rasuvaeff\LinkPreview\Fetch\FetchedPage;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\Fetch\FetchFailure;
use Rasuvaeff\Result\Result;

/**
 * What an adapter may do to the network, for the duration of one preview. Pages
 * are fetched once: the adapter and the generic extractors share the answer.
 *
 * @api
 */
final class AdapterContext
{
    /** @var array<string, Result<FetchedPage, FetchFailure>> */
    private array $pages = [];

    /** @var list<string> */
    private array $diagnostics = [];

    public function __construct(private readonly Fetcher $fetcher) {}

    /**
     * Why adapters could not read an answer: schema paths and kinds (`$.thumbnail_url: ...`),
     * never a value from the answer.
     *
     * @return list<string>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    public function note(string $diagnostic): void
    {
        $this->diagnostics[] = $diagnostic;
    }

    /**
     * @return Result<FetchedPage, FetchFailure>
     */
    public function html(string $url): Result
    {
        return $this->pages[$url] ??= $this->fetcher->fetch(url: $url);
    }

    /**
     * @param array<string, string> $headers sent to the host of `$url` only
     *
     * @return Result<FetchedPage, FetchFailure>
     */
    public function json(string $url, #[\SensitiveParameter] array $headers = []): Result
    {
        return $this->fetcher->fetchJson(url: $url, headers: $headers);
    }
}
