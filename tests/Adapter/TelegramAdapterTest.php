<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Adapter;

use Rasuvaeff\LinkPreview\Adapter\AdapterFailure;
use Rasuvaeff\LinkPreview\Adapter\TelegramAdapter;
use Rasuvaeff\LinkPreview\Tests\Support\Adapters;
use Rasuvaeff\LinkPreview\Tests\Support\Http;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(TelegramAdapter::class)]
final class TelegramAdapterTest
{
    #[DataProvider('embedProvider')]
    public function buildsTheWidgetUrl(string $url, ?string $embed): void
    {
        $client = Http::client([$url => Http::html(Adapters::fixture('telegram/post.html'))]);

        $metadata = (new TelegramAdapter())->preview($url, Adapters::context($client))->unwrap();

        Assert::same($metadata->embedUrl, $embed);
        Assert::same($metadata->siteName, 'Telegram');
        Assert::same($metadata->title, 'Pavel Durov');
    }

    public static function embedProvider(): iterable
    {
        yield 'post' => ['https://t.me/durov/123', 'https://t.me/durov/123?embed=1&mode=tme'];
        yield 'public view' => ['https://t.me/s/durov/123', 'https://t.me/durov/123?embed=1&mode=tme'];
        yield 'channel' => ['https://t.me/durov', null];
        yield 'invite link' => ['https://t.me/+AbCdEf123', null];
        yield 'short channel name' => ['https://t.me/abc/1', null];
        yield 'non-numeric post' => ['https://t.me/durov/abc', null];
    }

    public function unreachablePageIsUnavailable(): void
    {
        Assert::same((new TelegramAdapter())->preview('https://t.me/durov/1', Adapters::context(Http::client([])))->error(), AdapterFailure::Unavailable);
    }

    public function servesItsDomains(): void
    {
        Assert::same((new TelegramAdapter())->domains(), ['t.me', 'telegram.me']);
    }
}
