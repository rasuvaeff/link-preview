<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests;

use Rasuvaeff\LinkPreview\Tests\Support\AdapterCorpus;
use Testo\Assert;
use Testo\CoversNothing;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Each adapter against the responses its service gives (recorded files, never the network).
 */
#[Test]
#[CoversNothing]
final class AdapterCorpusTest
{
    /**
     * @param array<string, mixed> $entry
     */
    #[DataProvider('entryProvider')]
    public function previewsFromRecordedResponses(array $entry): void
    {
        /** @var \Rasuvaeff\LinkPreview\Tests\Support\Entry $entry */
        $outcome = AdapterCorpus::run($entry);

        Assert::same($outcome['preview'], AdapterCorpus::expected($entry));
    }

    /**
     * @param array<string, mixed> $entry
     */
    #[DataProvider('entryProvider')]
    public function onlyListedRequestsAreMade(array $entry): void
    {
        /** @var \Rasuvaeff\LinkPreview\Tests\Support\Entry $entry */
        $outcome = AdapterCorpus::run($entry);
        $listed = array_keys($entry['responses']);

        Assert::same(array_values(array_unique(array_diff($outcome['requests'], $listed, [$entry['url']]))), []);
        Assert::true(array_diff($listed, $outcome['requests']) === []);
    }

    public static function entryProvider(): iterable
    {
        foreach (AdapterCorpus::entries() as $entry) {
            yield $entry['name'] => [$entry];
        }
    }

    public function everyAdapterHasAnEntry(): void
    {
        $names = array_map(static fn(array $entry): string => preg_replace('/-.*/', '', $entry['name']) ?? '', AdapterCorpus::entries());

        foreach (['youtube', 'tiktok', 'twitter', 'twitch', 'wikipedia', 'github', 'instagram', 'facebook', 'telegram', 'vk', 'rutube'] as $adapter) {
            Assert::true(\in_array($adapter, $names, strict: true));
        }
    }
}
