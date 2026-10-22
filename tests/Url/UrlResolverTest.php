<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Url;

use Rasuvaeff\LinkPreview\Url\UrlResolver;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(UrlResolver::class)]
final class UrlResolverTest
{
    #[DataProvider('resolveProvider')]
    public function resolves(string $reference, ?string $expected): void
    {
        Assert::same(UrlResolver::resolve(base: 'https://example.com/a/b/page.html?x=1', reference: $reference), $expected);
    }

    public static function resolveProvider(): iterable
    {
        yield 'absolute' => ['https://other.org/pic.png', 'https://other.org/pic.png'];
        yield 'absolute http' => ['http://other.org', 'http://other.org/'];
        yield 'absolute is lower-cased in scheme and host' => ['HTTPS://Other.ORG/Pic.PNG', 'https://other.org/Pic.PNG'];
        yield 'absolute keeps query, drops fragment' => ['https://other.org/p?a=1#top', 'https://other.org/p?a=1'];
        yield 'absolute keeps port' => ['https://other.org:8443/p', 'https://other.org:8443/p'];
        yield 'absolute path dots' => ['https://other.org/a/./b/../c', 'https://other.org/a/c'];
        yield 'dots above root' => ['https://other.org/../../c', 'https://other.org/c'];
        yield 'trailing dot segment' => ['https://other.org/a/b/..', 'https://other.org/a/'];
        yield 'trailing single dot' => ['https://other.org/a/.', 'https://other.org/a/'];
        yield 'scheme-relative' => ['//cdn.example.net/x.png', 'https://cdn.example.net/x.png'];
        yield 'root-relative' => ['/img/x.png', 'https://example.com/img/x.png'];
        yield 'relative' => ['x.png', 'https://example.com/a/b/x.png'];
        yield 'relative up' => ['../x.png', 'https://example.com/a/x.png'];
        yield 'relative with query' => ['x.png?v=2', 'https://example.com/a/b/x.png?v=2'];
        yield 'query only' => ['?page=2', 'https://example.com/a/b/page.html?page=2'];
        yield 'fragment only' => ['#top', 'https://example.com/a/b/page.html?x=1'];
        yield 'empty query keeps none' => ['x.png?', 'https://example.com/a/b/x.png'];
        yield 'surrounding whitespace' => ["  /x.png \n", 'https://example.com/x.png'];
        yield 'javascript' => ['javascript:alert(1)', null];
        yield 'data' => ['data:image/png;base64,AAAA', null];
        yield 'ftp' => ['ftp://example.com/x.png', null];
        yield 'mailto' => ['mailto:a@example.com', null];
        yield 'file' => ['file:///etc/passwd', null];
        yield 'credentials' => ['https://user:pw@example.com/x', null];
        yield 'user only' => ['https://user@example.com/x', null];
        yield 'scheme without authority' => ['http:foo', null];
        yield 'empty' => ['', null];
        yield 'blank' => ['   ', null];
        yield 'control character' => ["/x\x01.png", null];
        yield 'inner newline' => ["/x\n.png", null];
        yield 'backslash' => ['\\\\evil.com\\x', null];
        yield 'scheme-relative without host' => ['///x', null];
    }

    public function baseWithoutPathGetsRoot(): void
    {
        Assert::same(UrlResolver::resolve(base: 'https://example.com', reference: 'x.png'), 'https://example.com/x.png');
    }

    public function baseSchemeAndHostAreLowerCased(): void
    {
        Assert::same(UrlResolver::resolve(base: 'HTTPS://Example.COM/a/b', reference: 'x'), 'https://example.com/a/x');
    }

    public function baseWithPortIsInherited(): void
    {
        Assert::same(UrlResolver::resolve(base: 'http://example.com:8080/a/', reference: 'x.png'), 'http://example.com:8080/a/x.png');
    }

    public function relativeReferenceNeedsAUsableBase(): void
    {
        Assert::null(UrlResolver::resolve(base: 'not a url', reference: '/x'));
        Assert::null(UrlResolver::resolve(base: 'ftp://example.com/', reference: '/x'));
        Assert::null(UrlResolver::resolve(base: '', reference: '/x'));
    }

    public function schemeRelativeInheritsTheBaseScheme(): void
    {
        Assert::same(UrlResolver::resolve(base: 'http://example.com/', reference: '//cdn.example.net/x'), 'http://cdn.example.net/x');
    }

    public function recognisesAbsoluteHttpUrls(): void
    {
        Assert::true(UrlResolver::isAbsoluteHttp('https://example.com/x'));
        Assert::true(UrlResolver::isAbsoluteHttp('HTTP://example.com'));
        Assert::false(UrlResolver::isAbsoluteHttp('ftp://example.com/'));
        Assert::false(UrlResolver::isAbsoluteHttp('/x'));
        Assert::false(UrlResolver::isAbsoluteHttp('javascript:alert(1)'));
        Assert::false(UrlResolver::isAbsoluteHttp('https://'));
        Assert::false(UrlResolver::isAbsoluteHttp(''));
    }
}
