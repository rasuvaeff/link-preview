<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Support;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * In-memory PSR-16 cache with a controllable clock, so a TTL test does not
 * sleep: an entry expires when `now` passes its deadline.
 *
 * @psalm-type Entry array{mixed, int|null}
 */
final class ArrayCache implements CacheInterface
{
    public int $now = 1_000;

    /** @var array<string, Entry> */
    public array $entries = [];

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        $entry = $this->entries[$key] ?? null;

        if ($entry === null || ($entry[1] !== null && $entry[1] <= $this->now)) {
            return $default;
        }

        return $entry[0];
    }

    #[\Override]
    public function set(string $key, mixed $value, int|DateInterval|null $ttl = null): bool
    {
        $this->entries[$key] = [$value, \is_int($ttl) ? $this->now + $ttl : null];

        return true;
    }

    #[\Override]
    public function delete(string $key): bool
    {
        unset($this->entries[$key]);

        return true;
    }

    #[\Override]
    public function clear(): bool
    {
        $this->entries = [];

        return true;
    }

    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->get($key, $default);
        }
    }

    #[\Override]
    public function setMultiple(iterable $values, int|DateInterval|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    #[\Override]
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }
}
