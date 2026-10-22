<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Extract;

use Rasuvaeff\LinkPreview\Extract\JsonLdFailure;
use Rasuvaeff\LinkPreview\Extract\SimpleJsonLdReader;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(SimpleJsonLdReader::class)]
final class SimpleJsonLdReaderTest
{
    public function flattensTheGraphAndKeepsTheRoot(): void
    {
        $nodes = (new SimpleJsonLdReader())->nodes('{"@context":"https://schema.org","@type":"Article","@graph":[{"@type":"Person","name":"A"},"junk",{"@type":"Organization"}]}')->unwrap();

        Assert::same(array_column($nodes, '@type'), ['Article', 'Person', 'Organization']);
    }

    public function aRootWithoutATypeIsOnlyAContainer(): void
    {
        $nodes = (new SimpleJsonLdReader())->nodes('{"@context":"https://schema.org","@graph":[{"@type":"Person"}]}')->unwrap();

        Assert::same(array_column($nodes, '@type'), ['Person']);
    }

    public function nonObjectRootsAreIgnored(): void
    {
        Assert::same((new SimpleJsonLdReader())->nodes('["a", 5, null]')->unwrap(), []);
    }

    public function scalarJsonIsInvalid(): void
    {
        Assert::same((new SimpleJsonLdReader())->nodes('5')->error(), JsonLdFailure::InvalidJson);
        Assert::same((new SimpleJsonLdReader())->nodes('')->error(), JsonLdFailure::InvalidJson);
        Assert::same((new SimpleJsonLdReader())->nodes('{"a":')->error(), JsonLdFailure::InvalidJson);
    }

    public function veryDeepNestingIsRefusedNotRecursedInto(): void
    {
        $json = str_repeat('[', 100) . str_repeat(']', 100);

        Assert::same((new SimpleJsonLdReader())->nodes($json)->error(), JsonLdFailure::InvalidJson);
    }

    public function noExternalContextIsEverFetched(): void
    {
        $warnings = 0;
        set_error_handler(static function () use (&$warnings): bool {
            ++$warnings;

            return true;
        });

        try {
            $result = (new SimpleJsonLdReader())->nodes('{"@context":"http://127.0.0.1:1/ctx.jsonld","@type":"Article"}');
        } finally {
            restore_error_handler();
        }

        Assert::same($result->error(), JsonLdFailure::UnsupportedContext);
        Assert::same($warnings, 0);
    }
}
