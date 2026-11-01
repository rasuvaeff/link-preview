<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Fetch;

/**
 * Which failures are worth retrying, shared by {@see FetchFailure} and
 * {@see \Rasuvaeff\LinkPreview\PreviewFailure}.
 *
 * @internal
 */
final readonly class Transience
{
    private const array TRANSIENT_STATUSES = [408, 425, 429];

    private function __construct() {}

    public static function of(string $reason, ?int $status): bool
    {
        return match ($reason) {
            FetchFailureReason::Network->value, FetchFailureReason::BudgetExceeded->value => true,
            FetchFailureReason::HttpStatus->value => $status !== null && self::status($status),
            default => false,
        };
    }

    public static function status(int $status): bool
    {
        return $status >= 500 || \in_array($status, self::TRANSIENT_STATUSES, strict: true);
    }
}
