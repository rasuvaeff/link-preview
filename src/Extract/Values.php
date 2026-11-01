<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Extract;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * @internal
 */
final readonly class Values
{
    private function __construct() {}

    /**
     * ISO 8601 date or date-time; a value without an offset is read as UTC.
     */
    public static function time(?string $value): ?DateTimeImmutable
    {
        if ($value === null || preg_match('/\A\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}.*)?\z/', $value) !== 1) {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }

    /**
     * `ru_RU` and `en-us` become BCP 47 style `ru-RU`, `en-US`; anything else is dropped.
     */
    public static function language(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $tag = str_replace('_', '-', trim($value));

        if (preg_match('/\A[A-Za-z]{2,3}(?:-[A-Za-z0-9]{1,8})*\z/', $tag) !== 1) {
            return null;
        }

        $parts = explode('-', $tag);
        $normalized = [strtolower($parts[0])];

        foreach (\array_slice($parts, 1) as $part) {
            $normalized[] = strlen($part) === 2 && ctype_alpha($part) ? strtoupper($part) : $part;
        }

        return implode('-', $normalized);
    }

    /**
     * An image side in pixels: a positive integer below 100 000, written as a number,
     * as digits (`"1200"`, `"1200px"`) or as a schema.org `QuantitativeValue`
     * (`{"value": 1200}`). Anything else is unknown.
     *
     * @return int<1, max>|null
     */
    public static function dimension(mixed $value): ?int
    {
        if (\is_array($value)) {
            return \is_array($value['value'] ?? null) ? null : self::dimension($value['value'] ?? null);
        }

        if (\is_float($value)) {
            $value = fmod($value, 1.0) === 0.0 ? \sprintf('%.0F', $value) : null;
        }

        if (\is_int($value)) {
            $value = (string) $value;
        }

        if (!\is_string($value) || preg_match('/\A\s*\d{1,5}(?:px)?\s*\z/', $value) !== 1) {
            return null;
        }

        $side = (int) $value;

        return $side >= 1 ? $side : null;
    }

    /**
     * A name, not a profile link: `article:author` is a URL on many sites.
     */
    public static function name(?string $value): ?string
    {
        if ($value === null || preg_match('/\A[a-z][a-z0-9+.-]*:\/\//i', $value) === 1) {
            return null;
        }

        return $value;
    }
}
