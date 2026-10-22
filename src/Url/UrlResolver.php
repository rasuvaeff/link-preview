<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Url;

/**
 * Resolves a reference taken from a page against the page's URL (RFC 3986 §5.2)
 * and keeps only what a preview may carry: an absolute http(s) URL with a host,
 * without credentials and without a fragment.
 *
 * @internal
 */
final readonly class UrlResolver
{
    private function __construct() {}

    /**
     * @return non-empty-string|null
     */
    public static function resolve(string $base, string $reference): ?string
    {
        $reference = trim($reference);

        if ($reference === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $reference) === 1) {
            return null;
        }

        if (preg_match('/\A([a-z][a-z0-9+.-]*):/i', $reference, $scheme) === 1) {
            return self::fromParts(self::parse($reference), strtolower($scheme[1]));
        }

        $baseParts = self::parse($base);

        if ($baseParts === null || !isset($baseParts['scheme'], $baseParts['host'])) {
            return null;
        }

        if (str_starts_with($reference, '//')) {
            return self::fromParts(self::parse($baseParts['scheme'] . ':' . $reference), $baseParts['scheme']);
        }

        if (preg_match('/\A([^?#]*)(?:\?([^#]*))?(?:#.*)?\z/s', $reference, $split) !== 1) {
            return null;
        }

        $path = $split[1];
        $query = $split[2] ?? null;
        $basePath = $baseParts['path'] ?? '';

        if ($path === '') {
            $path = $basePath;
            $query ??= $baseParts['query'] ?? null;
        } elseif ($path[0] !== '/') {
            $slash = strrpos($basePath, '/');
            $path = ($slash === false ? '/' : substr($basePath, 0, $slash + 1)) . $path;
        }

        $parts = [
            'scheme' => $baseParts['scheme'],
            'host' => $baseParts['host'],
            'path' => $path,
        ];

        if (isset($baseParts['port'])) {
            $parts['port'] = $baseParts['port'];
        }

        if ($query !== null) {
            $parts['query'] = $query;
        }

        return self::fromParts($parts, $baseParts['scheme']);
    }

    public static function isAbsoluteHttp(string $url): bool
    {
        return preg_match('/\Ahttps?:\/\//i', $url) === 1 && self::resolve(base: '', reference: $url) !== null;
    }

    /**
     * @return array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, path?: string, query?: string}|null
     */
    private static function parse(string $url): ?array
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        if (isset($parts['scheme'])) {
            $parts['scheme'] = strtolower($parts['scheme']);
        }

        if (isset($parts['host'])) {
            $parts['host'] = strtolower($parts['host']);
        }

        return $parts;
    }

    /**
     * @param array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, path?: string, query?: string}|null $parts
     *
     * @return non-empty-string|null
     */
    private static function fromParts(?array $parts, string $scheme): ?string
    {
        if ($parts === null || !isset($parts['host']) || $parts['host'] === '') {
            return null;
        }

        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $url = $scheme . '://' . $parts['host'];

        if (isset($parts['port'])) {
            $url .= ':' . $parts['port'];
        }

        $path = self::removeDotSegments($parts['path'] ?? '');
        $url .= $path === '' ? '/' : $path;

        if (isset($parts['query']) && $parts['query'] !== '') {
            $url .= '?' . $parts['query'];
        }

        return $url;
    }

    private static function removeDotSegments(string $path): string
    {
        if ($path === '' || $path[0] !== '/') {
            return $path;
        }

        $output = [];
        $segments = explode('/', $path);
        $last = array_key_last($segments);

        foreach ($segments as $index => $segment) {
            if ($segment === '.' || $segment === '..') {
                if ($segment === '..' && \count($output) > 1) {
                    array_pop($output);
                }

                if ($index === $last) {
                    $output[] = '';
                }

                continue;
            }

            $output[] = $segment;
        }

        return implode('/', $output);
    }
}
