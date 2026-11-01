<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Fetch;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;

/**
 * Reads `Retry-After` (RFC 9110 §10.2.3): delta-seconds, or an HTTP-date counted
 * from the response's own `Date` header. Without a `Date` header an HTTP-date is
 * not read: the package keeps no clock.
 *
 * @internal
 */
final readonly class RetryAfter
{
    /** a day is as long as anyone should be told to wait */
    public const int MAX_SECONDS = 86_400;

    private const string HTTP_DATE = 'D, d M Y H:i:s \G\M\T';

    private function __construct() {}

    /**
     * @return int<0, max>|null
     */
    public static function seconds(ResponseInterface $response): ?int
    {
        $value = trim($response->getHeaderLine('Retry-After'));

        if (preg_match('/\A\d{1,10}\z/', $value) === 1) {
            return max(0, min((int) $value, self::MAX_SECONDS));
        }

        $at = self::date($value);
        $now = self::date(trim($response->getHeaderLine('Date')));

        if (!$at instanceof DateTimeImmutable || !$now instanceof DateTimeImmutable) {
            return null;
        }

        return min(max(0, $at->getTimestamp() - $now->getTimestamp()), self::MAX_SECONDS);
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(self::HTTP_DATE, $value, new DateTimeZone('UTC'));

        return $date === false ? null : $date;
    }
}
