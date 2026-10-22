<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Extract;

use JsonException;

/**
 * Narrowing helpers for decoded, untrusted JSON.
 *
 * @internal
 *
 * @psalm-type JsonValue = scalar|null|array<array-key, mixed>
 */
final readonly class Json
{
    private const int DEPTH = 32;

    private function __construct() {}

    /**
     * @return array<array-key, mixed>|null the decoded object or list; null for anything else
     */
    public static function decode(string $json): ?array
    {
        try {
            /** @var JsonValue $data */
            $data = json_decode($json, true, self::DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return \is_array($data) ? $data : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function object(mixed $value): ?array
    {
        if (!\is_array($value) || array_is_list($value) && $value !== []) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @return non-empty-string|null a trimmed, non-empty string
     */
    public static function string(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<JsonValue> the value itself when it is a list, otherwise a one-element list
     */
    public static function items(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (\is_array($value) && array_is_list($value)) {
            /** @var list<JsonValue> $list */
            $list = $value;

            return $list;
        }

        /** @var JsonValue $single */
        $single = $value;

        return [$single];
    }
}
