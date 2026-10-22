<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Url;

use Rasuvaeff\LinkPreview\Url\Cidr;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Cidr::class)]
final class CidrTest
{
    #[DataProvider('containsProvider')]
    public function contains(string $rule, string $address, bool $expected): void
    {
        $cidr = Cidr::parse($rule);

        Assert::same($cidr?->contains((string) inet_pton($address)), $expected);
    }

    public static function containsProvider(): iterable
    {
        yield 'host route hit' => ['10.0.0.1', '10.0.0.1', true];
        yield 'host route miss' => ['10.0.0.1', '10.0.0.2', false];
        yield 'byte aligned hit' => ['10.0.0.0/8', '10.255.1.2', true];
        yield 'byte aligned miss' => ['10.0.0.0/8', '11.0.0.0', false];
        yield 'unaligned hit at the end' => ['172.16.0.0/12', '172.31.255.255', true];
        yield 'unaligned miss after the end' => ['172.16.0.0/12', '172.32.0.0', false];
        yield 'unaligned miss before the start' => ['172.16.0.0/12', '172.15.255.255', false];
        yield 'one bit' => ['128.0.0.0/1', '200.0.0.1', true];
        yield 'one bit miss' => ['128.0.0.0/1', '100.0.0.1', false];
        yield 'zero prefix matches all of its family' => ['0.0.0.0/0', '8.8.8.8', true];
        yield 'v4 rule never matches v6' => ['0.0.0.0/0', '::1', false];
        yield 'v6 rule never matches v4' => ['::/0', '1.2.3.4', false];
        yield 'v6 hit' => ['fc00::/7', 'fd12:3456::1', true];
        yield 'v6 miss' => ['fc00::/7', 'fe80::1', false];
        yield 'v6 host route' => ['::1', '::1', true];
    }

    #[DataProvider('invalidProvider')]
    public function parsesNothingFromGarbage(string $rule): void
    {
        Assert::null(Cidr::parse($rule));
    }

    public static function invalidProvider(): iterable
    {
        yield 'name' => ['example.com'];
        yield 'empty' => [''];
        yield 'empty prefix' => ['10.0.0.0/'];
        yield 'negative prefix' => ['10.0.0.0/-1'];
        yield 'v4 prefix too long' => ['10.0.0.0/33'];
        yield 'v6 prefix too long' => ['::1/129'];
        yield 'letters in prefix' => ['10.0.0.0/8a'];
        yield 'prefix with newline' => ["10.0.0.0/8\n"];
        yield 'four-digit prefix' => ['10.0.0.0/0008'];
    }

    public function acceptsTheLongestPrefixOfEachFamily(): void
    {
        Assert::true(Cidr::parse('10.0.0.0/32') instanceof Cidr);
        Assert::true(Cidr::parse('::1/128') instanceof Cidr);
        Assert::true(Cidr::parse('10.0.0.0/0') instanceof Cidr);
    }
}
