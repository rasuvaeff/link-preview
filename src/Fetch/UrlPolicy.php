<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Fetch;

use InvalidArgumentException;
use Rasuvaeff\LinkPreview\Url\Cidr;
use Rasuvaeff\Result\Result;

/**
 * Decides whether a URL may be fetched on behalf of a user.
 *
 * The URL of a link preview is attacker-controlled input and the fetch is made
 * from inside the perimeter, so the policy is a whitelist: http or https, no
 * credentials, ports 80 and 443 only (unless widened), a well-formed host name
 * or an IP literal outside every loopback, private, link-local, shared,
 * documentation and multicast range. Spellings a resolver would read as an
 * address but a parser would not (`2130706433`, `0x7f.1`, `127.1`,
 * `localhost.`, `::7f00:1`) are rejected or classified as the address they are.
 *
 * The policy never resolves a name: an answer taken now says nothing about the
 * answer at connection time, and a plain PSR-18 client cannot be told which
 * address to use. A name that resolves into the internal network passes —
 * close that gap in the transport (see the Security section of the README).
 *
 * On success the result is the normalised URL: lower-case ASCII host without
 * the root dot, default port and fragment removed, empty path as `/`.
 *
 * @api
 */
final readonly class UrlPolicy
{
    /** @var list<Cidr> */
    private array $allowedNetworks;

    /**
     * @param list<string> $allowedNetworks CIDR blocks or addresses that are fetchable even though
     *        they are private — for development stands. `localhost` names count as `127.0.0.1`
     * @param list<int<1, 65535>> $allowedPorts explicit ports that may appear in a URL
     */
    public function __construct(
        array $allowedNetworks = [],
        private array $allowedPorts = [80, 443],
    ) {
        $networks = [];

        foreach ($allowedNetworks as $rule) {
            $cidr = Cidr::parse($rule);

            if (!$cidr instanceof \Rasuvaeff\LinkPreview\Url\Cidr) {
                throw new InvalidArgumentException(\sprintf('Invalid network "%s"', $rule));
            }

            $networks[] = $cidr;
        }

        $this->allowedNetworks = $networks;
    }

    /**
     * @return Result<non-empty-string, UrlRejection>
     */
    public function check(string $url): Result
    {
        if (preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return Result::err(error: UrlRejection::Malformed);
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return Result::err(error: UrlRejection::Malformed);
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if ($scheme !== 'http' && $scheme !== 'https') {
            return Result::err(error: UrlRejection::Scheme);
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return Result::err(error: UrlRejection::Credentials);
        }

        $host = $parts['host'] ?? '';

        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }

        if ($host === '') {
            return Result::err(error: UrlRejection::MissingHost);
        }

        $port = $parts['port'] ?? null;

        if ($port !== null && !\in_array($port, $this->allowedPorts, strict: true)) {
            return Result::err(error: UrlRejection::Port);
        }

        $target = $this->target($host);

        if ($target instanceof UrlRejection) {
            return Result::err(error: $target);
        }

        [$authority, $packed] = $target;

        if ($this->isBlocked($authority, $packed)) {
            return Result::err(error: UrlRejection::PrivateAddress);
        }

        $normalized = $scheme . '://' . $authority;

        if ($port !== null && $port !== ($scheme === 'https' ? 443 : 80)) {
            $normalized .= ':' . $port;
        }

        $path = $parts['path'] ?? '';
        $normalized .= $path === '' ? '/' : $path;

        if (isset($parts['query']) && $parts['query'] !== '') {
            $normalized .= '?' . $parts['query'];
        }

        /** @var Result<non-empty-string, UrlRejection> $result */
        $result = Result::ok(value: $normalized);

        return $result;
    }

    /**
     * @return UrlRejection|array{non-empty-string, string|null} the URL authority and the packed
     *         address when the host is an IP literal
     */
    private function target(string $host): UrlRejection|array
    {
        if (preg_match('/\A\[(?<address>.+)]\z/', $host, $matches) === 1) {
            $packed = filter_var($matches['address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                ? inet_pton($matches['address'])
                : false;

            if ($packed === false) {
                return UrlRejection::InvalidHost;
            }

            $address = inet_ntop($packed);

            return $address === false ? UrlRejection::InvalidHost : ['[' . $address . ']', $packed];
        }

        $name = strtolower($host);

        if (preg_match('/[^\x00-\x7F]/', $name) === 1) {
            $ascii = idn_to_ascii($name, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

            if ($ascii === false) {
                return UrlRejection::InvalidHost;
            }

            $name = $ascii;
        }

        if (
            $name === ''
            || preg_match('/\A[a-z0-9._-]+\z/', $name) !== 1
            || str_contains($name, '..')
            || $name[0] === '.'
        ) {
            return UrlRejection::InvalidHost;
        }

        if (preg_match('/(?:\A|\.)(?:0x[0-9a-f]*|[0-9]+)\z/', $name) !== 1) {
            return [$name, null];
        }

        if (filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return UrlRejection::NonCanonicalIp;
        }

        $packed = inet_pton($name);

        return $packed === false ? UrlRejection::InvalidHost : [$name, $packed];
    }

    private function isBlocked(string $authority, ?string $packed): bool
    {
        if ($packed === null) {
            if ($authority !== 'localhost' && !str_ends_with($authority, '.localhost')) {
                return false;
            }

            return !$this->isAllowed("\x7F\0\0\x01");
        }

        return BlockedRanges::contains($packed) && !$this->isAllowed($packed);
    }

    private function isAllowed(string $packed): bool
    {
        foreach ($this->allowedNetworks as $network) {
            if ($network->contains($packed)) {
                return true;
            }
        }

        return false;
    }
}
