<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Extract;

use Rasuvaeff\LinkPreview\Extract\ExtractFailure;
use Rasuvaeff\LinkPreview\Extract\JsonLdExtractor;
use Rasuvaeff\LinkPreview\Extract\JsonLdFailure;
use Rasuvaeff\LinkPreview\Extract\JsonLdReader;
use Rasuvaeff\LinkPreview\Extract\Metadata;
use Rasuvaeff\LinkPreview\Html\PageDocument;
use Rasuvaeff\LinkPreview\Tests\Support\Fixtures;
use Rasuvaeff\Result\Result;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(JsonLdExtractor::class)]
final class JsonLdExtractorTest
{
    private const string PAGE = 'https://example.com/news/a.html';

    public function readsAnArticleWithAGraph(): void
    {
        $metadata = $this->extract(Fixtures::read('json-ld-graph.html'))->unwrap();

        Assert::same($metadata->title, 'The headline');
        Assert::same($metadata->description, 'Article description');
        Assert::same($metadata->image, 'https://example.com/img/hero.jpg');
        Assert::same($metadata->url, 'https://example.com/news/a');
        Assert::same($metadata->authorName, 'Jane Doe');
        Assert::same($metadata->siteName, 'Example News');
        Assert::same($metadata->language, 'en-GB');
        Assert::same($metadata->publishedTime?->format('c'), '2026-10-01T12:30:00+03:00');
        Assert::same($metadata->type, 'NewsArticle');
    }

    public function readsAVideoObject(): void
    {
        $metadata = $this->extract($this->block('{"@context":"https://schema.org","@type":"VideoObject","name":"Clip","description":"D","thumbnailUrl":["/t.jpg"],"uploadDate":"2026-01-02","embedUrl":"https://example.com/embed/1","author":{"@type":"Person","name":"V"}}'))->unwrap();

        Assert::same($metadata->title, 'Clip');
        Assert::same($metadata->image, 'https://example.com/t.jpg');
        Assert::same($metadata->embedUrl, 'https://example.com/embed/1');
        Assert::same($metadata->publishedTime?->format('Y-m-d'), '2026-01-02');
        Assert::same($metadata->authorName, 'V');
        Assert::same($metadata->type, 'VideoObject');
    }

    public function organizationContributesOnlyItsName(): void
    {
        $metadata = $this->extract($this->block('{"@context":"https://schema.org","@graph":[{"@type":"Organization","name":"Org","description":"not used","image":"/org.png"},{"@type":"WebPage","name":"Page"}]}'))->unwrap();

        Assert::same($metadata->siteName, 'Org');
        Assert::same($metadata->title, 'Page');
        Assert::null($metadata->description);
        Assert::null($metadata->image);
    }

    public function betterRankedNodesWinPerField(): void
    {
        $json = '{"@context":"https://schema.org","@graph":['
            . '{"@type":"WebPage","name":"Page name","description":"Page description","url":"/page"},'
            . '{"@type":"Article","headline":"Article headline"}'
            . ']}';

        $metadata = $this->extract($this->block($json))->unwrap();

        Assert::same($metadata->title, 'Article headline');
        Assert::same($metadata->description, 'Page description');
        Assert::same($metadata->url, 'https://example.com/page');
    }

    #[DataProvider('typeProvider')]
    public function recognisesTypes(string $type, bool $known): void
    {
        $result = $this->extract($this->block('{"@context":"https://schema.org","@type":' . $type . ',"name":"N"}'));

        Assert::same($result->isOk(), $known);
    }

    public static function typeProvider(): iterable
    {
        yield 'article' => ['"Article"', true];
        yield 'blog posting' => ['"BlogPosting"', true];
        yield 'full iri' => ['"https://schema.org/NewsArticle"', true];
        yield 'prefixed' => ['"schema:WebPage"', true];
        yield 'list with a known type' => ['["Thing","VideoObject"]', true];
        yield 'web site' => ['"WebSite"', true];
        yield 'person' => ['"Person"', false];
        yield 'product' => ['"Product"', false];
        yield 'unknown' => ['"Banana"', false];
        yield 'not a string' => ['5', false];
    }

    public function referencesToNodesAreResolved(): void
    {
        $json = '{"@context":"https://schema.org","@graph":['
            . '{"@type":"Article","headline":"H","author":{"@id":"#me"},"publisher":{"@id":"#org"},"image":{"@id":"#img"}},'
            . '{"@type":"Person","@id":"#me","name":"Me"},'
            . '{"@type":"Organization","@id":"#org","name":"Org Inc"},'
            . '{"@type":"ImageObject","@id":"#img","url":"/i.png"}'
            . ']}';

        $metadata = $this->extract($this->block($json))->unwrap();

        Assert::same($metadata->authorName, 'Me');
        Assert::same($metadata->siteName, 'Org Inc');
        Assert::same($metadata->image, 'https://example.com/i.png');
    }

    public function imageAndAuthorMayBeObjectsOrLists(): void
    {
        $json = '{"@context":"https://schema.org","@type":"Article","headline":"H","image":[{"@type":"ImageObject","url":"javascript:alert(1)"},{"url":"/b.png"}],"author":[{"name":"First"},{"name":"Second"}],"publisher":{"name":"Pub"}}';

        $metadata = $this->extract($this->block($json))->unwrap();

        Assert::same($metadata->image, 'https://example.com/b.png');
        Assert::same($metadata->authorName, 'First');
        Assert::same($metadata->siteName, 'Pub');
    }

    public function mainEntityOfPageIsTheUrlWhenThereIsNoUrl(): void
    {
        $metadata = $this->extract($this->block('{"@context":"https://schema.org","@type":"Article","headline":"H","mainEntityOfPage":{"@type":"WebPage","@id":"https://example.com/canonical"}}'))->unwrap();

        Assert::same($metadata->url, 'https://example.com/canonical');
    }

    public function hostileUrlsAreDropped(): void
    {
        $json = '{"@context":"https://schema.org","@type":"VideoObject","name":"N","thumbnailUrl":"data:image/png;base64,AA","embedUrl":"javascript:1","url":"file:///etc/passwd"}';

        $metadata = $this->extract($this->block($json))->unwrap();

        Assert::null($metadata->image);
        Assert::null($metadata->embedUrl);
        Assert::null($metadata->url);
    }

    public function authorThatIsALinkIsNotAName(): void
    {
        $metadata = $this->extract($this->block('{"@context":"https://schema.org","@type":"Article","headline":"H","author":"https://example.com/jane"}'))->unwrap();

        Assert::null($metadata->authorName);
    }

    #[DataProvider('foreignContextProvider')]
    public function skipsBlocksWithAForeignContext(string $context): void
    {
        $result = $this->extract($this->block('{"@context":' . $context . ',"@type":"Article","headline":"H"}'));

        Assert::same($result->error(), ExtractFailure::UnsupportedContext);
    }

    public static function foreignContextProvider(): iterable
    {
        yield 'remote context' => ['"https://evil.example/context.jsonld"'];
        yield 'look-alike of schema.org' => ['"https://schema.org.evil.example/"'];
        yield 'schema.org as a subdomain of evil' => ['"https://evil.example/schema.org"'];
        yield 'list with a remote entry' => ['["https://schema.org","https://evil.example/c"]'];
        yield 'number' => ['5'];
    }

    public function inlineDefinitionsOutsideSchemaOrgGiveNoSchemaOrgNode(): void
    {
        Assert::same($this->extract($this->block('{"@context":{"headline":"http://evil.example/x"},"@type":"Article","headline":"H"}'))->error(), ExtractFailure::NoMetadata);
        Assert::same($this->extract($this->block('{"@context":[],"@type":"Article","headline":"H"}'))->error(), ExtractFailure::NoMetadata);
    }

    public function aTermRedefinedToAForeignIriIsNotRead(): void
    {
        $metadata = $this->extract($this->block('{"@context":["https://schema.org",{"headline":"http://evil.example/x"}],"@type":"Article","headline":"H","description":"D"}'))->unwrap();

        Assert::null($metadata->title);
        Assert::same($metadata->description, 'D');
    }

    public function anInlineVocabularyContextAndAliasesAreUnderstood(): void
    {
        $metadata = $this->extract($this->block('{"@context":{"@vocab":"https://schema.org/","title":"headline"},"@type":"NewsArticle","title":"Aliased"}'))->unwrap();

        Assert::same($metadata->title, 'Aliased');
    }

    #[DataProvider('knownContextProvider')]
    public function acceptsSchemaOrgContexts(string $context): void
    {
        Assert::true($this->extract($this->block('{"@context":' . $context . ',"@type":"Article","headline":"H"}'))->isOk());
    }

    public static function knownContextProvider(): iterable
    {
        yield 'https' => ['"https://schema.org"'];
        yield 'https with slash' => ['"https://schema.org/"'];
        yield 'http' => ['"http://schema.org"'];
        yield 'www' => ['"https://www.schema.org/"'];
        yield 'upper case' => ['"HTTPS://Schema.org"'];
        yield 'jsonld context file' => ['"https://schema.org/docs/jsonldcontext.json"'];
        yield 'list of known' => ['["https://schema.org","http://schema.org/"]'];
    }

    public function aMissingContextIsNotSchemaOrg(): void
    {
        Assert::same($this->extract($this->block('{"@type":"Article","headline":"H"}'))->error(), ExtractFailure::NoMetadata);
    }

    public function aGoodBlockSurvivesABadOne(): void
    {
        $html = $this->block('{"@context":"https://evil.example/c","@type":"Article","headline":"Bad"}')
            . $this->block('not json at all')
            . $this->block('{"@context":"https://schema.org","@type":"Article","headline":"Good"}');

        Assert::same($this->extract($html)->unwrap()->title, 'Good');
    }

    public function brokenJsonIsInvalidData(): void
    {
        Assert::same($this->extract($this->block('{"@context":'))->error(), ExtractFailure::InvalidData);
    }

    public function aForeignContextOutranksBrokenJsonInTheDiagnosis(): void
    {
        $html = $this->block('not json') . $this->block('{"@context":"https://evil.example/c","@type":"Article"}') . $this->block('also not json');

        Assert::same($this->extract($html)->error(), ExtractFailure::UnsupportedContext);
    }

    public function noBlocksMeansNoMetadata(): void
    {
        Assert::same($this->extract('<title>x</title>')->error(), ExtractFailure::NoMetadata);
    }

    public function readsAListOfRoots(): void
    {
        $json = '[{"@context":"https://schema.org","@type":"WebSite","name":"Site"},{"@context":"https://schema.org","@type":"Article","headline":"H"}]';

        $metadata = $this->extract($this->block($json))->unwrap();

        Assert::same($metadata->siteName, 'Site');
        Assert::same($metadata->title, 'H');
    }

    public function theReaderCanBeReplaced(): void
    {
        $reader = new class implements JsonLdReader {
            public function nodes(string $json): Result
            {
                return Result::ok(value: [['@type' => 'Article', 'headline' => 'From a custom reader']]);
            }
        };

        $result = (new JsonLdExtractor($reader))->extract(PageDocument::parse($this->block('{}')), self::PAGE);

        Assert::same($result->unwrap()->title, 'From a custom reader');
    }

    public function aReaderFailureIsDiagnosed(): void
    {
        $reader = new class implements JsonLdReader {
            public function nodes(string $json): Result
            {
                return Result::err(error: JsonLdFailure::InvalidJson);
            }
        };

        $result = (new JsonLdExtractor($reader))->extract(PageDocument::parse($this->block('{}')), self::PAGE);

        Assert::same($result->error(), ExtractFailure::InvalidData);
    }

    private function block(string $json): string
    {
        return '<script type="application/ld+json">' . $json . '</script>';
    }

    /**
     * @return Result<Metadata, ExtractFailure>
     */
    private function extract(string $html): Result
    {
        return (new JsonLdExtractor())->extract(document: PageDocument::parse($html), pageUrl: self::PAGE);
    }
}
