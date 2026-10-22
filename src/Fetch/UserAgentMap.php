<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Fetch;

use InvalidArgumentException;

/**
 * Which `User-Agent` to present to which host. Some sites serve metadata only
 * to the crawlers they know; a key matches its host and every subdomain.
 *
 * @api
 */
final readonly class UserAgentMap
{
    public const string DEFAULT = 'Mozilla/5.0 (compatible; rasuvaeff-link-preview/0.1; +https://github.com/rasuvaeff/link-preview)';

    /** @var array<non-empty-string, non-empty-string> */
    private array $byHost;

    /**
     * @param array<string, string> $byHost
     */
    public function __construct(
        array $byHost = [],
        private string $default = self::DEFAULT,
    ) {
        self::assertValid($default);

        $normalized = [];

        foreach ($byHost as $host => $agent) {
            self::assertValid($agent);
            $key = strtolower(trim((string) $host, '.'));

            if ($key === '') {
                throw new InvalidArgumentException('User agent map host must not be empty');
            }

            $normalized[$key] = $agent;
        }

        $this->byHost = $normalized;
    }

    public function forHost(string $host): string
    {
        $host = strtolower(trim($host, '.'));

        while ($host !== '') {
            if (isset($this->byHost[$host])) {
                return $this->byHost[$host];
            }

            $dot = strpos($host, '.');

            if ($dot === false) {
                break;
            }

            $host = substr($host, $dot + 1);
        }

        return $this->default;
    }

    /**
     * @phpstan-assert non-empty-string $agent
     * @psalm-assert non-empty-string $agent
     */
    private static function assertValid(string $agent): void
    {
        if ($agent === '' || preg_match('/[\x00-\x1F\x7F]/', $agent) === 1) {
            throw new InvalidArgumentException('User agent must be a non-empty single-line string');
        }
    }
}
