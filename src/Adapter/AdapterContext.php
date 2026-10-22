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

    public function __construct(private readonly Fetcher $fetcher) {}

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
