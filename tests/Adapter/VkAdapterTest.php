<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Adapter;

use Rasuvaeff\LinkPreview\Adapter\AdapterFailure;
use Rasuvaeff\LinkPreview\Adapter\VkAdapter;
use Rasuvaeff\LinkPreview\Tests\Support\Adapters;
use Rasuvaeff\LinkPreview\Tests\Support\Http;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(VkAdapter::class)]
final class VkAdapterTest
{
    #[DataProvider('embedProvider')]
    public function buildsThePlayerUrl(string $url, ?string $embed): void
    {
        $client = Http::client([$url => Http::html(Adapters::fixture('vk/video.html'))]);

        $metadata = (new VkAdapter())->preview($url, Adapters::context($client))->unwrap();

        Assert::same($metadata->embedUrl, $embed);
        Assert::same($metadata->siteName, 'VK');
        Assert::same($metadata->title, 'Закат над морем');
    }

    public static function embedProvider(): iterable
    {
        yield 'video of a community' => ['https://vk.com/video-22822305_456239018', 'https://vk.com/video_ext.php?oid=-22822305&id=456239018'];
        yield 'video of a user' => ['https://vk.com/video12345_678', 'https://vk.com/video_ext.php?oid=12345&id=678'];
        yield 'video in a modal link' => ['https://vk.com/wall-1_2?z=video-22822305_456239018%2Fabc', 'https://vk.com/video_ext.php?oid=-22822305&id=456239018'];
        yield 'plain wall post' => ['https://vk.com/wall-1_2', null];
        yield 'community' => ['https://vk.com/durov', null];
    }

    public function unreachablePageIsUnavailable(): void
    {
        Assert::same((new VkAdapter())->preview('https://vk.com/durov', Adapters::context(Http::client([])))->error(), AdapterFailure::Unavailable);
    }

    public function servesItsDomains(): void
    {
        Assert::same((new VkAdapter())->domains(), ['vk.com', 'vk.ru', 'vkvideo.ru']);
    }
}
