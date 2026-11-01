<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview;

use Rasuvaeff\LinkPreview\Fetch\FetchFailure;
use Rasuvaeff\LinkPreview\Fetch\Transience;

/**
 * Why {@see LinkPreviewer} produced no preview: the reason, and for
 * {@see PreviewFailureReason::HttpStatus} the status and `Retry-After` of the answer.
 *
 * {@see self::isTransient()} tells a failure worth retrying (the network, a spent
 * budget, HTTP 408, 425, 429 and 5xx) from one that will repeat (everything else).
 *
 * @api
 */
final readonly class PreviewFailure
{
    /**
     * @param int<100, 599>|null $status the HTTP status, for {@see PreviewFailureReason::HttpStatus}
     * @param int<0, max>|null $retryAfterSeconds the server's `Retry-After`, when it sent a readable one
     */
    public function __construct(
        public PreviewFailureReason $reason,
        public ?int $status = null,
        public ?int $retryAfterSeconds = null,
    ) {}

    public static function fromFetch(FetchFailure $failure): self
    {
        return new self(
            reason: PreviewFailureReason::fromFetch($failure->reason),
            status: $failure->status,
            retryAfterSeconds: $failure->retryAfterSeconds,
        );
    }

    public function isTransient(): bool
    {
        return Transience::of(reason: $this->reason->value, status: $this->status);
    }
}
