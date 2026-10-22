<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Adapter;

/**
 * Label-wise host matching: `www.youtube.com` and `m.youtube.com` are YouTube,
 * `youtube.com.evil.example` and `notyoutube.com` are not. Never a substring test.
 *
 * @internal
 */
final readonly class HostMatcher
{
    private function __construct() {}

    /**
     * @param list<non-empty-string> $domains
     */
    public static function matches(string $host, array $domains): bool
    {
        $host = strtolower($host);

        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }

        foreach ($domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }
}
