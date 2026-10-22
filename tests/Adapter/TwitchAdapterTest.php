<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Adapter;

use InvalidArgumentException;
use Rasuvaeff\LinkPreview\Adapter\AdapterFailure;
use Rasuvaeff\LinkPreview\Adapter\TwitchAdapter;
use Rasuvaeff\LinkPreview\Tests\Support\Adapters;
use Rasuvaeff\LinkPreview\Tests\Support\Http;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(TwitchAdapter::class)]
final class TwitchAdapterTest
{
    #[DataProvider('embedProvider')]
    public function buildsThePlayerUrl(string $url, ?string $embed): void
    {
        $client = Http::sequence(Adapters::json(Adapters::fixture('twitch/oembed.json')));

        $metadata = (new TwitchAdapter(parents: ['example.com', 'www.example.com']))->preview($url, Adapters::context($client))->unwrap();

        Assert::same($metadata->embedUrl, $embed === null ? 'https://player.twitch.tv/?channel=shroud&parent=oembed' : $embed . '&parent=example.com&parent=www.example.com');
    }

    public static function embedProvider(): iterable
    {
        yield 'channel' => ['https://www.twitch.tv/Shroud', 'https://player.twitch.tv/?channel=shroud'];
        yield 'video' => ['https://www.twitch.tv/videos/1234567', 'https://player.twitch.tv/?video=v1234567'];
        yield 'clip on the clips host' => ['https://clips.twitch.tv/FunnySlug-abc_1', 'https://clips.twitch.tv/embed?clip=FunnySlug-abc_1'];
        yield 'clip under a channel' => ['https://www.twitch.tv/shroud/clip/FunnySlug-abc_1', 'https://clips.twitch.tv/embed?clip=FunnySlug-abc_1'];
        yield 'directory page has no player' => ['https://www.twitch.tv/directory/game/Chess', null];
        yield 'long name has no player' => ['https://www.twitch.tv/' . 'a-really-too-long-channel-name-xyz', null];
    }

    public function withoutParentsThereIsNoEmbedOfItsOwn(): void
    {
        $client = Http::sequence(Adapters::json(Adapters::fixture('twitch/oembed.json')));

        $metadata = (new TwitchAdapter())->preview('https://www.twitch.tv/shroud', Adapters::context($client))->unwrap();

        Assert::same($metadata->embedUrl, 'https://player.twitch.tv/?channel=shroud&parent=oembed');
        Assert::same($metadata->title, 'Late night stream');
        Assert::same($metadata->authorName, 'shroud');
    }

    public function asksTheOEmbedEndpoint(): void
    {
        $client = Http::sequence(Adapters::json(Adapters::fixture('twitch/oembed.json')));

        (new TwitchAdapter())->preview('https://www.twitch.tv/shroud', Adapters::context($client));

        Assert::same(Http::requestedUrls($client), ['https://api.twitch.tv/v4/oembed?url=https%3A%2F%2Fwww.twitch.tv%2Fshroud']);
    }

    #[DataProvider('badParentProvider')]
    public function rejectsParentsThatAreNotHostNames(string $parent): void
    {
        Expect::exception(InvalidArgumentException::class);

        new TwitchAdapter(parents: [$parent]);
    }

    public static function badParentProvider(): iterable
    {
        yield 'with a scheme' => ['https://example.com'];
        yield 'with a path' => ['example.com/x'];
        yield 'with a query injection' => ['example.com&autoplay=1'];
        yield 'empty' => [''];
        yield 'trailing newline' => ["example.com\n"];
        yield 'upper case' => ['Example.com'];
    }

    public function failuresAreReported(): void
    {
        Assert::same((new TwitchAdapter())->preview('https://www.twitch.tv/a', Adapters::context(Http::client([])))->error(), AdapterFailure::Unavailable);
    }

    public function servesItsDomains(): void
    {
        Assert::same((new TwitchAdapter())->domains(), ['twitch.tv']);
    }
}
