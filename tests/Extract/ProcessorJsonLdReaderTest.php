<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Extract;

use Rasuvaeff\LinkPreview\Extract\JsonLdFailure;
use Rasuvaeff\LinkPreview\Extract\ProcessorJsonLdReader;
use Rasuvaeff\LinkPreview\Extract\SchemaOrgContextLoader;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ProcessorJsonLdReader::class)]
#[Covers(SchemaOrgContextLoader::class)]
final class ProcessorJsonLdReaderTest
{
    public function flattensTheGraphAndKeepsTheRoot(): void
    {
        $nodes = (new ProcessorJsonLdReader())->nodes('{"@context":"https://schema.org","@type":"Article","@graph":[{"@type":"Person","name":"A"},"junk",{"@type":"Organization"}]}')->unwrap();

        Assert::same(array_column($nodes, '@type'), ['Article', 'Person', 'Organization']);
    }

    public function aRootWithoutATypeIsOnlyAContainer(): void
    {
        $nodes = (new ProcessorJsonLdReader())->nodes('{"@context":"https://schema.org","@graph":[{"@type":"Person"}]}')->unwrap();

        Assert::same(array_column($nodes, '@type'), ['Person']);
    }

    public function nonObjectRootsAreIgnored(): void
    {
        Assert::same((new ProcessorJsonLdReader())->nodes('["a", 5, null]')->unwrap(), []);
    }

    public function invalidJsonIsReportedAsSuch(): void
    {
        Assert::same((new ProcessorJsonLdReader())->nodes('5')->error(), JsonLdFailure::InvalidJson);
        Assert::same((new ProcessorJsonLdReader())->nodes('')->error(), JsonLdFailure::InvalidJson);
        Assert::same((new ProcessorJsonLdReader())->nodes('{"a":')->error(), JsonLdFailure::InvalidJson);
    }

    public function veryDeepNestingIsRefusedNotRecursedInto(): void
    {
        $json = str_repeat('[', 100) . str_repeat(']', 100);

        Assert::same((new ProcessorJsonLdReader())->nodes($json)->error(), JsonLdFailure::InvalidJson);
    }

    public function noExternalContextIsEverFetched(): void
    {
        $warnings = 0;
        set_error_handler(static function () use (&$warnings): bool {
            ++$warnings;

            return true;
        });

        try {
            $result = (new ProcessorJsonLdReader())->nodes('{"@context":"http://127.0.0.1:1/ctx.jsonld","@type":"Article"}');
        } finally {
            restore_error_handler();
        }

        Assert::same($result->error(), JsonLdFailure::UnsupportedContext);
        Assert::same($warnings, 0);
    }

    public function schemaOrgInEitherSchemeAndSpellingIsKnown(): void
    {
        foreach (['https://schema.org', 'http://schema.org', 'https://schema.org/', 'http://www.schema.org/', 'https://schema.org/docs/jsonldcontext.json'] as $context) {
            $nodes = (new ProcessorJsonLdReader())->nodes('{"@context":"' . $context . '","@type":"Article","headline":"H"}')->unwrap();

            Assert::same($nodes, [['@type' => 'Article', 'headline' => 'H']]);
        }
    }

    public function anInlineContextAndAliasesAreUnderstood(): void
    {
        $nodes = (new ProcessorJsonLdReader())->nodes('{"@context":{"@vocab":"https://schema.org/","title":"headline","by":"author"},"@type":"NewsArticle","title":"T","by":{"@type":"Person","name":"N"}}')->unwrap();

        Assert::same($nodes, [['@type' => 'NewsArticle', 'headline' => 'T', 'author' => ['@type' => 'Person', 'name' => 'N']]]);
    }

    public function listsMultipleTypesAndIdsKeepTheirShape(): void
    {
        $nodes = (new ProcessorJsonLdReader())->nodes('{"@context":"https://schema.org","@id":"https://example.com/a","@type":["Article","CreativeWork"],"keywords":["a","b"],"publisher":{"@id":"https://example.com/org"}}')->unwrap();

        Assert::same($nodes, [[
            '@id' => 'https://example.com/a',
            '@type' => ['Article', 'CreativeWork'],
            'keywords' => ['a', 'b'],
            'publisher' => ['@id' => 'https://example.com/org'],
        ]]);
    }

    public function propertiesOfOtherVocabulariesAreDropped(): void
    {
        $nodes = (new ProcessorJsonLdReader())->nodes('{"@context":["https://schema.org",{"ex":"https://example.org/ns#"}],"@type":"Article","headline":"H","ex:secret":"x"}')->unwrap();

        Assert::same($nodes, [['@type' => 'Article', 'headline' => 'H']]);
    }
}
