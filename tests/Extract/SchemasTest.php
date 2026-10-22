<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Extract;

use Rasuvaeff\LinkPreview\Adapter\AdapterFailure;
use Rasuvaeff\LinkPreview\Adapter\RutubeAdapter;
use Rasuvaeff\LinkPreview\Adapter\WikipediaAdapter;
use Rasuvaeff\LinkPreview\Adapter\YouTubeAdapter;
use Rasuvaeff\LinkPreview\Extract\OEmbedResponse;
use Rasuvaeff\LinkPreview\Extract\Schemas;
use Rasuvaeff\LinkPreview\Limits;
use Rasuvaeff\LinkPreview\PageInfo;
use Rasuvaeff\LinkPreview\Tests\Support\Adapters;
use Rasuvaeff\LinkPreview\Tests\Support\Http;
use Rasuvaeff\LinkPreview\Tests\Support\SchemaGenerators;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Schemas::class)]
#[Covers(OEmbedResponse::class)]
final class SchemasTest
{
    public function anOpenAnswerWithNullsAndExtrasIsReadable(): void
    {
        $parsed = Schemas::parse(Schemas::oEmbed(), '{"type":"video","title":null,"height":null,"version":"1.0","unknown":{"deep":[1,2]}}');

        Assert::same($parsed->unwrap()['type'], 'video');
        Assert::same($parsed->unwrap()['unknown'], ['deep' => [1, 2]]);
    }

    #[DataProvider('wrongProvider')]
    public function wrongShapesAreLocatedWithoutEchoingValues(string $json, string $path): void
    {
        $parsed = Schemas::parse(Schemas::oEmbed(), $json);

        Assert::true($parsed->isErr());
        Assert::string((string) $parsed->error())->contains($path);
        Assert::false(str_contains((string) $parsed->error(), 'secret-value'));
    }

    public static function wrongProvider(): iterable
    {
        yield 'a number as the title' => ['{"title":12345}', '$.title'];
        yield 'an object as the thumbnail' => ['{"thumbnail_url":{"secret-value":1}}', '$.thumbnail_url'];
        yield 'a list as the html' => ['{"html":["secret-value"]}', '$.html'];
        yield 'a bool as the author' => ['{"author_name":true,"title":"secret-value"}', '$.author_name'];
    }

    public function notAnObjectIsDiagnosed(): void
    {
        Assert::same(Schemas::parse(Schemas::oEmbed(), 'not json')->error(), '$: not a JSON object');
        Assert::same(Schemas::parse(Schemas::oEmbed(), '5')->error(), '$: not a JSON object');
        Assert::true(Schemas::parse(Schemas::oEmbed(), '[1,2]')->isErr());
    }

    public function severalProblemsAreAllNamedUpToFive(): void
    {
        $json = '{"type":1,"title":2,"author_name":3,"provider_name":4,"thumbnail_url":5,"url":6,"html":7}';

        $error = (string) Schemas::parse(Schemas::oEmbed(), $json)->error();

        Assert::same(substr_count($error, '; ') + 1, 5);
        Assert::string($error)->contains('$.type');
    }

    public function anAnswerWithNothingToShowIsDiagnosed(): void
    {
        Assert::same(OEmbedResponse::parse('{"version":"1.0"}', 'https://example.com/o')->error(), '$: nothing to show');
    }

    public function adaptersRecordWhyTheyGaveUp(): void
    {
        $context = Adapters::context(Http::sequence(Adapters::json('{"title":{"secret-value":1}}')));

        $result = (new YouTubeAdapter())->preview('https://youtu.be/abcdefghijk', $context);

        Assert::same($result->error(), AdapterFailure::InvalidResponse);
        Assert::same(\count($context->diagnostics()), 1);
        Assert::string($context->diagnostics()[0])->contains('$.title');
        Assert::false(str_contains($context->diagnostics()[0], 'secret-value'));
    }

    public function wikipediaAndRutubeRecordTheirOwn(): void
    {
        $wikipedia = Adapters::context(Http::sequence(Adapters::json('{"title":""}')));
        (new WikipediaAdapter())->preview('https://en.wikipedia.org/wiki/X', $wikipedia);

        $rutube = Adapters::context(Http::sequence(Adapters::json('{"title":5}')));
        (new RutubeAdapter())->preview('https://rutube.ru/video/5ac7c1f0a1b2c3d4e5f60718293a4b5c/', $rutube);

        Assert::string($wikipedia->diagnostics()[0])->contains('Wikipedia summary $.title');
        Assert::string($rutube->diagnostics()[0])->contains('Rutube video $.title');
    }

    /**
     * Whatever the oEmbed schema generates is read without an exception, and what comes out is
     * safe to show: URLs absolute http(s), text within the limits.
     *
     * @param array<string, mixed> $answer
     */
    #[Property(runs: 300, generators: [SchemaGenerators::class, 'oEmbed'], timeoutMs: 500)]
    public function generatedOEmbedAnswersBecomeSafePreviews(array $answer): void
    {
        $read = OEmbedResponse::parse($this->encode($answer), 'https://example.com/oembed');
        $metadata = $read->value();

        Classify::cover(condition: $metadata !== null, label: 'readable', minPercent: 20.0);
        Classify::cover(condition: $metadata === null, label: 'nothing to show', minPercent: 1.0);

        if ($metadata === null) {
            Assert::same($read->error(), '$: nothing to show');

            return;
        }

        $this->assertSafe(new PageInfo(
            url: 'https://example.com/',
            title: $metadata->title,
            image: $metadata->image,
            siteName: $metadata->siteName,
            authorName: $metadata->authorName,
            type: $metadata->type,
            embedUrl: $metadata->embedUrl,
        ));
    }

    /**
     * @param array<string, mixed> $answer
     */
    #[Property(runs: 200, generators: [SchemaGenerators::class, 'wikipedia'], timeoutMs: 500)]
    public function generatedWikipediaAnswersBecomeSafePreviews(array $answer): void
    {
        $context = Adapters::context(Http::sequence(Adapters::json($this->encode($answer))));

        $result = (new WikipediaAdapter())->preview('https://en.wikipedia.org/wiki/X', $context);

        Classify::cover(condition: $result->isOk(), label: 'readable', minPercent: 20.0);

        if ($result->isOk()) {
            $metadata = $result->unwrap();
            $this->assertSafe(new PageInfo(url: 'https://en.wikipedia.org/wiki/X', title: $metadata->title, description: $metadata->description, image: $metadata->image, siteName: $metadata->siteName));
        } else {
            Assert::same($result->error(), AdapterFailure::InvalidResponse);
        }
    }

    /**
     * @param array<string, mixed> $answer
     */
    #[Property(runs: 200, generators: [SchemaGenerators::class, 'rutube'], timeoutMs: 500)]
    public function generatedRutubeAnswersBecomeSafePreviews(array $answer): void
    {
        $context = Adapters::context(Http::sequence(Adapters::json($this->encode($answer))));

        $result = (new RutubeAdapter())->preview('https://rutube.ru/video/5ac7c1f0a1b2c3d4e5f60718293a4b5c/', $context);

        Classify::cover(condition: $result->isOk(), label: 'readable', minPercent: 20.0);

        if ($result->isOk()) {
            $metadata = $result->unwrap();
            $this->assertSafe(new PageInfo(url: 'https://rutube.ru/', title: $metadata->title, description: $metadata->description, image: $metadata->image, siteName: $metadata->siteName, authorName: $metadata->authorName, embedUrl: $metadata->embedUrl));
        } else {
            Assert::same($result->error(), AdapterFailure::InvalidResponse);
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function generatedOEmbedAnswersBecomeSafePreviewsExamples(): iterable
    {
        yield 'photo with only a url' => [['type' => 'photo', 'url' => 'javascript:alert(1)']];
        yield 'iframe pointing at a script' => [['html' => '<iframe src="javascript:alert(1)"></iframe>']];
        yield 'huge title' => [['title' => str_repeat('я', 5000)]];
        yield 'nothing' => [[]];
    }

    private function assertSafe(PageInfo $info): void
    {
        $limited = (new Limits())->apply($info);

        foreach ([$limited->image, $limited->embedUrl] as $url) {
            Assert::true($url === null || (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')));
        }

        Assert::true(mb_strlen((string) $limited->title) <= 200);
        Assert::true(mb_strlen((string) $limited->description) <= 500);
        Assert::true(mb_strlen((string) $limited->siteName) <= 100);
        Assert::true(mb_check_encoding((string) $limited->title, 'UTF-8'));
    }

    /**
     * @param array<array-key, mixed> $answer
     */
    private function encode(array $answer): string
    {
        return json_encode($answer, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION) ?: '{}';
    }
}
