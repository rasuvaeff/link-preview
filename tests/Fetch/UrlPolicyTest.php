<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Fetch;

use InvalidArgumentException;
use Rasuvaeff\LinkPreview\Fetch\UrlPolicy;
use Rasuvaeff\LinkPreview\Fetch\UrlRejection;
use Rasuvaeff\LinkPreview\Tests\Support\UrlGenerators;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(UrlPolicy::class)]
final class UrlPolicyTest
{
    #[DataProvider('rejectedProvider')]
    public function rejects(string $url, UrlRejection $reason): void
    {
        $result = (new UrlPolicy())->check(url: $url);

        Assert::true($result->isErr());
        Assert::same($result->error(), $reason);
    }

    public static function rejectedProvider(): iterable
    {
        yield 'localhost' => ['http://localhost/', UrlRejection::PrivateAddress];
        yield 'localhost with root dot' => ['http://localhost./', UrlRejection::PrivateAddress];
        yield 'localhost upper case' => ['http://LOCALHOST/', UrlRejection::PrivateAddress];
        yield 'subdomain of localhost' => ['http://api.localhost/', UrlRejection::PrivateAddress];
        yield 'loopback v4' => ['http://127.0.0.1/', UrlRejection::PrivateAddress];
        yield 'loopback v4 with root dot' => ['http://127.0.0.1./', UrlRejection::PrivateAddress];
        yield 'whole 127/8' => ['http://127.255.255.254/', UrlRejection::PrivateAddress];
        yield 'loopback v6' => ['http://[::1]/', UrlRejection::PrivateAddress];
        yield 'unspecified v4' => ['http://0.0.0.0/', UrlRejection::PrivateAddress];
        yield 'unspecified v6' => ['http://[::]/', UrlRejection::PrivateAddress];
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data/', UrlRejection::PrivateAddress];
        yield '10/8' => ['http://10.0.0.1/', UrlRejection::PrivateAddress];
        yield '172.16/12' => ['http://172.16.0.1/', UrlRejection::PrivateAddress];
        yield '172.31 edge' => ['http://172.31.255.255/', UrlRejection::PrivateAddress];
        yield '192.168/16' => ['http://192.168.1.1/', UrlRejection::PrivateAddress];
        yield 'carrier-grade NAT' => ['http://100.64.0.1/', UrlRejection::PrivateAddress];
        yield 'benchmark range' => ['http://198.18.0.1/', UrlRejection::PrivateAddress];
        yield 'documentation range' => ['http://203.0.113.9/', UrlRejection::PrivateAddress];
        yield 'multicast' => ['http://224.0.0.1/', UrlRejection::PrivateAddress];
        yield 'broadcast' => ['http://255.255.255.255/', UrlRejection::PrivateAddress];
        yield 'unique local v6' => ['http://[fc00::1]/', UrlRejection::PrivateAddress];
        yield 'link-local v6' => ['http://[fe80::1]/', UrlRejection::PrivateAddress];
        yield 'multicast v6' => ['http://[ff02::1]/', UrlRejection::PrivateAddress];
        yield 'v4-compatible in hex groups' => ['http://[::7f00:1]/', UrlRejection::PrivateAddress];
        yield 'v4-compatible in dotted form' => ['http://[::127.0.0.1]/', UrlRejection::PrivateAddress];
        yield 'v4-mapped dotted' => ['http://[::ffff:127.0.0.1]/', UrlRejection::PrivateAddress];
        yield 'v4-mapped hex' => ['http://[::ffff:7f00:1]/', UrlRejection::PrivateAddress];
        yield 'NAT64 to loopback' => ['http://[64:ff9b::7f00:1]/', UrlRejection::PrivateAddress];
        yield 'local NAT64' => ['http://[64:ff9b:1::1]/', UrlRejection::PrivateAddress];
        yield '6to4 to loopback' => ['http://[2002:7f00:1::]/', UrlRejection::PrivateAddress];
        yield 'documentation v6' => ['http://[2001:db8::1]/', UrlRejection::PrivateAddress];
        yield 'decimal integer ip' => ['http://2130706433/', UrlRejection::NonCanonicalIp];
        yield 'hex integer ip' => ['http://0x7f000001/', UrlRejection::NonCanonicalIp];
        yield 'hex dotted ip' => ['http://0x7f.0.0.1/', UrlRejection::NonCanonicalIp];
        yield 'octal ip' => ['http://0177.0.0.1/', UrlRejection::NonCanonicalIp];
        yield 'shortened ip' => ['http://127.1/', UrlRejection::NonCanonicalIp];
        yield 'five-part ip' => ['http://1.2.3.4.5/', UrlRejection::NonCanonicalIp];
        yield 'numeric tld' => ['http://example.123/', UrlRejection::NonCanonicalIp];
        yield 'userinfo' => ['http://a@127.0.0.1/', UrlRejection::Credentials];
        yield 'userinfo with password' => ['http://user:pass@example.com/', UrlRejection::Credentials];
        yield 'empty userinfo' => ['http://@example.com/', UrlRejection::Credentials];
        yield 'unlisted port' => ['http://example.com:8080/', UrlRejection::Port];
        yield 'ssh port' => ['http://example.com:22/', UrlRejection::Port];
        yield 'unlisted port on a private host' => ['http://127.0.0.1:6379/', UrlRejection::Port];
        yield 'ftp' => ['ftp://example.com/', UrlRejection::Scheme];
        yield 'javascript' => ['javascript:alert(1)', UrlRejection::Scheme];
        yield 'file' => ['file:///etc/passwd', UrlRejection::Scheme];
        yield 'data' => ['data:text/html,<p>', UrlRejection::Scheme];
        yield 'scheme-relative' => ['//example.com/', UrlRejection::Scheme];
        yield 'no scheme' => ['example.com/path', UrlRejection::Scheme];
        yield 'empty' => ['', UrlRejection::Scheme];
        yield 'only a root dot host' => ['http://./', UrlRejection::MissingHost];
        yield 'no host' => ['http:///path', UrlRejection::Malformed];
        yield 'trailing newline' => ["http://example.com/\n", UrlRejection::Malformed];
        yield 'space in host' => ['http://exa mple.com/', UrlRejection::Malformed];
        yield 'leading space' => [' http://example.com/', UrlRejection::Malformed];
        yield 'tab' => ["http://example.com/\t", UrlRejection::Malformed];
        yield 'nul byte' => ["http://example.com/\0", UrlRejection::Malformed];
        yield 'backslash authority trick' => ['http://example.com\\@127.0.0.1/', UrlRejection::Malformed];
        yield 'empty label' => ['http://exam..ple.com/', UrlRejection::InvalidHost];
        yield 'leading dot' => ['http://.example.com/', UrlRejection::InvalidHost];
        yield 'underscore-free charset violation' => ['http://exa%6Dple.com/', UrlRejection::InvalidHost];
        yield 'zone id' => ['http://[fe80::1%25eth0]/', UrlRejection::InvalidHost];
        yield 'bad v6' => ['http://[not-an-ip]/', UrlRejection::InvalidHost];
        yield 'v4 in brackets' => ['http://[127.0.0.1]/', UrlRejection::InvalidHost];
        yield 'invalid idn' => ["http://a\u{0378}b.com/", UrlRejection::InvalidHost];
        yield 'port zero' => ['http://example.com:0/', UrlRejection::Port];
    }

    public function unclosedBracketIsRejected(): void
    {
        Assert::true((new UrlPolicy())->check(url: 'http://[::1/')->isErr());
    }

    #[DataProvider('acceptedProvider')]
    public function accepts(string $url, string $normalized): void
    {
        $result = (new UrlPolicy())->check(url: $url);

        Assert::same($result->value(), $normalized);
    }

    public static function acceptedProvider(): iterable
    {
        yield 'bare host' => ['http://example.com', 'http://example.com/'];
        yield 'host case, default port, fragment' => ['HTTPS://Example.COM:443/a?b=c#frag', 'https://example.com/a?b=c'];
        yield 'default http port' => ['http://example.com:80/', 'http://example.com/'];
        yield 'https on port 80 keeps it' => ['https://example.com:80/', 'https://example.com:80/'];
        yield 'http on port 443 keeps it' => ['http://example.com:443/', 'http://example.com:443/'];
        yield 'root dot' => ['http://example.com./x', 'http://example.com/x'];
        yield 'empty query is dropped' => ['http://example.com/x?', 'http://example.com/x'];
        yield 'idn host' => ['https://münchen.de/x', 'https://xn--mnchen-3ya.de/x'];
        yield 'idn with upper case' => ['https://MÜNCHEN.de/', 'https://xn--mnchen-3ya.de/'];
        yield 'cyrillic host' => ['https://пример.рф/', 'https://xn--e1afmkfd.xn--p1ai/'];
        yield 'public v4' => ['http://8.8.8.8/', 'http://8.8.8.8/'];
        yield 'public v6' => ['http://[2606:4700::1111]/', 'http://[2606:4700::1111]/'];
        yield 'v6 is compressed' => ['http://[2606:4700:0:0:0:0:0:1111]/', 'http://[2606:4700::1111]/'];
        yield 'mapped public v4' => ['http://[::ffff:8.8.8.8]/', 'http://[::ffff:8.8.8.8]/'];
        yield 'edge below 172.16' => ['http://172.15.255.255/', 'http://172.15.255.255/'];
        yield 'edge above 172.31' => ['http://172.32.0.0/', 'http://172.32.0.0/'];
        yield 'edge below CGNAT' => ['http://100.63.255.255/', 'http://100.63.255.255/'];
        yield 'edge above CGNAT' => ['http://100.128.0.0/', 'http://100.128.0.0/'];
        yield 'edge below multicast' => ['http://223.255.255.255/', 'http://223.255.255.255/'];
        yield 'host that merely contains localhost' => ['http://notlocalhost.com/', 'http://notlocalhost.com/'];
        yield 'host with localhost as a label' => ['http://localhost.example.com/', 'http://localhost.example.com/'];
        yield 'name with a dash and underscore' => ['http://my_host-1.example.com/', 'http://my_host-1.example.com/'];
        yield 'host ending in a hex-looking label' => ['http://a.0xyz.com/', 'http://a.0xyz.com/'];
        yield 'path is kept verbatim' => ['http://example.com/a//b/../c', 'http://example.com/a//b/../c'];
    }

    public function widerPortsAreOptIn(): void
    {
        $policy = new UrlPolicy(allowedPorts: [80, 443, 8443]);

        Assert::same($policy->check(url: 'https://example.com:8443/')->value(), 'https://example.com:8443/');
        Assert::same($policy->check(url: 'https://example.com:8080/')->error(), UrlRejection::Port);
    }

    public function noPortsMeansOnlyImplicitOnes(): void
    {
        $policy = new UrlPolicy(allowedPorts: []);

        Assert::same($policy->check(url: 'http://example.com/')->value(), 'http://example.com/');
        Assert::same($policy->check(url: 'http://example.com:80/')->error(), UrlRejection::Port);
    }

    public function allowlistOpensANetwork(): void
    {
        $policy = new UrlPolicy(allowedNetworks: ['10.0.0.0/24', '2606:4700::/32']);

        Assert::same($policy->check(url: 'http://10.0.0.5/')->value(), 'http://10.0.0.5/');
        Assert::same($policy->check(url: 'http://10.0.1.5/')->error(), UrlRejection::PrivateAddress);
        Assert::same($policy->check(url: 'http://127.0.0.1/')->error(), UrlRejection::PrivateAddress);
        Assert::same($policy->check(url: 'http://localhost/')->error(), UrlRejection::PrivateAddress);
    }

    public function allowlistOfLoopbackOpensLocalhostNames(): void
    {
        $policy = new UrlPolicy(allowedNetworks: ['127.0.0.1']);

        Assert::same($policy->check(url: 'http://localhost/')->value(), 'http://localhost/');
        Assert::same($policy->check(url: 'http://app.localhost./x')->value(), 'http://app.localhost/x');
        Assert::same($policy->check(url: 'http://127.0.0.1/')->value(), 'http://127.0.0.1/');
        Assert::same($policy->check(url: 'http://127.0.0.2/')->error(), UrlRejection::PrivateAddress);
    }

    public function allowlistDoesNotWidenSchemesOrPorts(): void
    {
        $policy = new UrlPolicy(allowedNetworks: ['10.0.0.0/8']);

        Assert::same($policy->check(url: 'ftp://10.0.0.1/')->error(), UrlRejection::Scheme);
        Assert::same($policy->check(url: 'http://10.0.0.1:8080/')->error(), UrlRejection::Port);
    }

    #[DataProvider('invalidNetworkProvider')]
    public function rejectsInvalidAllowlist(string $rule): void
    {
        Expect::exception(InvalidArgumentException::class);

        new UrlPolicy(allowedNetworks: [$rule]);
    }

    public static function invalidNetworkProvider(): iterable
    {
        yield 'not an address' => ['example.com'];
        yield 'prefix too long' => ['10.0.0.0/33'];
        yield 'empty rule' => [''];
    }

    /**
     * Accept/reject against an independent reference: integer intervals for the
     * blocked IPv4 ranges, plus the scheme and port rules stated directly.
     */
    #[Property(runs: 400, generators: [UrlGenerators::class, 'endpoint'], timeoutMs: 250)]
    public function verdictMatchesReference(int $a, int $b, int $c, int $d, string $scheme, ?int $port): void
    {
        $url = $scheme . '://' . $a . '.' . $b . '.' . $c . '.' . $d . ($port === null ? '' : ':' . $port) . '/';
        $blocked = UrlGenerators::isBlockedIpv4($a, $b, $c, $d);
        $expected = ($scheme === 'http' || $scheme === 'https')
            && (in_array($port, [null, 80, 443], strict: true))
            && !$blocked;

        Assert::same((new UrlPolicy())->check(url: $url)->isOk(), $expected);

        Classify::cover(condition: $expected, label: 'accepted', minPercent: 4.0);
        Classify::cover(condition: $blocked && ($scheme === 'http' || $scheme === 'https'), label: 'blocked address', minPercent: 4.0);
        Classify::cover(condition: !$blocked && $port !== null && $port !== 80 && $port !== 443, label: 'refused port', minPercent: 4.0);
        Classify::cover(condition: !$blocked && (in_array($scheme, ['ftp', 'file', 'gopher'], strict: true)), label: 'refused scheme', minPercent: 5.0);
    }

    /** @return iterable<string, array{int, int, int, int, string, int|null}> */
    public static function verdictMatchesReferenceExamples(): iterable
    {
        yield 'metadata endpoint' => [169, 254, 169, 254, 'http', null];
        yield 'last CGNAT address' => [100, 127, 255, 255, 'https', 443];
        yield 'first public after CGNAT' => [100, 128, 0, 0, 'https', null];
        yield 'public on a bad port' => [8, 8, 8, 8, 'http', 8080];
        yield 'public over ftp' => [8, 8, 8, 8, 'ftp', null];
        yield 'public' => [8, 8, 8, 8, 'https', 443];
    }

    /**
     * An IPv4 address carried inside an IPv6 literal is judged as the IPv4 address it is.
     */
    #[Property(runs: 400, generators: [UrlGenerators::class, 'embedded'], timeoutMs: 250)]
    public function embeddedIpv4IsJudgedAsIpv4(int $a, int $b, int $c, int $d, string $form): void
    {
        $high = sprintf('%x', $a * 256 + $b);
        $low = sprintf('%x', $c * 256 + $d);
        $host = match ($form) {
            'compatible' => '::' . $high . ':' . $low,
            'mapped' => '::ffff:' . $high . ':' . $low,
            'nat64' => '64:ff9b::' . $high . ':' . $low,
            default => '2002:' . $high . ':' . $low . '::',
        };
        $blocked = UrlGenerators::isBlockedIpv4($a, $b, $c, $d);

        Assert::same((new UrlPolicy())->check(url: 'http://[' . $host . ']/')->isOk(), !$blocked);

        Classify::cover(condition: $blocked, label: 'blocked inside v6', minPercent: 10.0);
        Classify::cover(condition: !$blocked, label: 'public inside v6', minPercent: 10.0);
    }

    /** @return iterable<string, array{int, int, int, int, string}> */
    public static function embeddedIpv4IsJudgedAsIpv4Examples(): iterable
    {
        yield 'loopback as ::7f00:1' => [127, 0, 0, 1, 'compatible'];
        yield 'loopback mapped' => [127, 0, 0, 1, 'mapped'];
        yield 'loopback via NAT64' => [127, 0, 0, 1, 'nat64'];
        yield 'loopback via 6to4' => [127, 0, 0, 1, 'sixToFour'];
        yield 'public mapped' => [8, 8, 8, 8, 'mapped'];
    }

    /**
     * What the policy returns is a fixed point: checking it again changes nothing.
     */
    #[Property(runs: 300, generators: [UrlGenerators::class, 'normalizable'], timeoutMs: 250)]
    public function normalizationIsIdempotent(string $scheme, string $host, ?int $port, string $path, ?string $query, ?string $fragment): void
    {
        $url = $scheme . '://' . $host . '.example' . ($port === null ? '' : ':' . $port)
            . '/' . $path . ($query === null ? '' : '?' . $query) . ($fragment === null ? '' : '#' . $fragment);
        $policy = new UrlPolicy();
        $once = $policy->check(url: $url);

        Assert::true($once->isOk());
        Assert::same($policy->check(url: (string) $once->value())->value(), $once->value());
        Assert::false(str_contains((string) $once->value(), '#'));
    }
}
