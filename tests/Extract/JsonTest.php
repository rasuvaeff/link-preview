<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Extract;

use Rasuvaeff\LinkPreview\Extract\Json;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Json::class)]
final class JsonTest
{
    public function decodesObjectsAndListsOnly(): void
    {
        Assert::same(Json::decode('{"a":1}'), ['a' => 1]);
        Assert::same(Json::decode('[1,2]'), [1, 2]);
        Assert::null(Json::decode('5'));
        Assert::null(Json::decode('{"a":'));
    }

    #[DataProvider('stringProvider')]
    public function stringsAreTrimmedAndNeverEmpty(mixed $value, ?string $expected): void
    {
        Assert::same(Json::string($value), $expected);
    }

    public static function stringProvider(): iterable
    {
        yield 'padded' => ["  a b \n", 'a b'];
        yield 'blank' => [" \t\n", null];
        yield 'empty' => ['', null];
        yield 'number' => [5, null];
        yield 'null' => [null, null];
    }

    public function itemsWrapScalarsAndKeepLists(): void
    {
        Assert::same(Json::items(null), []);
        Assert::same(Json::items('a'), ['a']);
        Assert::same(Json::items(0), [0]);
        Assert::same(Json::items([1, 2]), [1, 2]);
        Assert::same(Json::items(['a' => 1]), [['a' => 1]]);
    }

    public function objectsAreNotLists(): void
    {
        Assert::same(Json::object(['a' => 1]), ['a' => 1]);
        Assert::same(Json::object([]), []);
        Assert::null(Json::object([1, 2]));
        Assert::null(Json::object('a'));
    }
}
