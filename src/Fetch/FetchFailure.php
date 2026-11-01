<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Fetch;

/**
 * Why a fetch produced nothing: the reason, and for {@see FetchFailureReason::HttpStatus}
 * the status and the `Retry-After` the server sent.
 *
 * @api
 */
final readonly class FetchFailure
{
    /**
     * @param int<100, 599>|null $status the HTTP status, for {@see FetchFailureReason::HttpStatus}
     * @param int<0, max>|null $retryAfterSeconds what `Retry-After` asked for: delta-seconds as sent, an HTTP-date
     *        counted from the response's `Date` header; `null` when absent or unreadable
     */
    public function __construct(
        public FetchFailureReason $reason,
        public ?int $status = null,
        public ?int $retryAfterSeconds = null,
    ) {}

    /**
     * Whether the same request may succeed later: the network, a spent budget, and HTTP 408,
     * 425, 429 and 5xx are transient; everything else is a property of the URL or the page.
     */
    public function isTransient(): bool
    {
        return Transience::of(reason: $this->reason->value, status: $this->status);
    }
}
