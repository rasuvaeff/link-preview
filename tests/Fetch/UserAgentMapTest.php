<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Fetch;

use InvalidArgumentException;
use Rasuvaeff\LinkPreview\Fetch\UserAgentMap;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(UserAgentMap::class)]
final class UserAgentMapTest
{
    public function emptyMapAnswersTheDefault(): void
    {
        Assert::same((new UserAgentMap())->forHost('example.com'), UserAgentMap::DEFAULT);
    }

    public function customDefault(): void
    {
        Assert::same((new UserAgentMap(default: 'Bot/1'))->forHost('example.com'), 'Bot/1');
    }

    #[DataProvider('hostProvider')]
    public function matchesHostAndSubdomains(string $host, string $expected): void
    {
        $map = new UserAgentMap(byHost: ['Example.com' => 'A/1', 'cdn.example.com' => 'B/1', 't.me.' => 'C/1'], default: 'D/1');

        Assert::same($map->forHost($host), $expected);
    }

    public static function hostProvider(): iterable
    {
        yield 'exact' => ['example.com', 'A/1'];
        yield 'case' => ['EXAMPLE.COM', 'A/1'];
        yield 'subdomain' => ['www.example.com', 'A/1'];
        yield 'deep subdomain' => ['a.b.example.com', 'A/1'];
        yield 'more specific entry wins' => ['cdn.example.com', 'B/1'];
        yield 'below the more specific entry' => ['x.cdn.example.com', 'B/1'];
        yield 'root dot on the host' => ['example.com.', 'A/1'];
        yield 'root dot on the key' => ['t.me', 'C/1'];
        yield 'suffix without a dot is another host' => ['badexample.com', 'D/1'];
        yield 'parent is not matched by a child key' => ['com', 'D/1'];
        yield 'unrelated' => ['other.org', 'D/1'];
        yield 'empty' => ['', 'D/1'];
    }

    #[DataProvider('invalidProvider')]
    public function rejectsInvalidAgents(string $agent): void
    {
        Expect::exception(InvalidArgumentException::class);

        new UserAgentMap(default: $agent);
    }

    public static function invalidProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'newline' => ["Bot\nX-Injected: 1"];
        yield 'carriage return' => ["Bot\r"];
        yield 'nul' => ["Bot\0"];
    }

    public function rejectsInvalidMappedAgent(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new UserAgentMap(byHost: ['example.com' => "a\nb"]);
    }

    public function rejectsEmptyHostKey(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new UserAgentMap(byHost: ['.' => 'Bot/1']);
    }

    public function theUnfurlerPresetUsesTheUnfurlerNameEverywhereAndKeepsOverrides(): void
    {
        $map = UserAgentMap::unfurler(['t.me' => 'TelegramBot (like TwitterBot)']);

        Assert::same($map->forHost('vk.com'), UserAgentMap::UNFURLER);
        Assert::same($map->forHost('www.championat.com'), 'Twitterbot/1.0');
        Assert::same($map->forHost('t.me'), 'TelegramBot (like TwitterBot)');
    }
}
