# rasuvaeff/link-preview

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/link-preview/v)](https://packagist.org/packages/rasuvaeff/link-preview)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/link-preview/downloads)](https://packagist.org/packages/rasuvaeff/link-preview)
[![Build](https://github.com/rasuvaeff/link-preview/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/link-preview/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/link-preview/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/link-preview/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/link-preview/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/link-preview/php)](https://packagist.org/packages/rasuvaeff/link-preview)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

Link preview for PHP: fetch a user-supplied URL through any PSR-18 client with an SSRF-aware redirect loop, and read title, description, image, favicon and more from OpenGraph, Twitter Cards and plain HTML.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.

> Projects using the [llm/skills](https://github.com/roxblnfk/skills) Composer plugin also get this package's agent skill synced into `.agents/skills/` automatically on install.

Russian version: [README.ru.md](README.ru.md).

## Requirements

- PHP 8.3-8.5 (8.6 is checked in CI as experimental) with `dom`, `libxml`, `mbstring`, `intl`, `ctype`, `filter`
- `psr/http-client`, `psr/http-factory`, `psr/http-message`
- `rasuvaeff/result`
- A PSR-18 client and PSR-17 request factory of your choice (Guzzle, Symfony HttpClient + Nyholm PSR-7, ...)

## Installation

```bash
composer require rasuvaeff/link-preview
composer require guzzlehttp/guzzle   # or any PSR-18 client
```

## Usage

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\PreviewProvider;

$provider = new PreviewProvider(
    fetcher: new Fetcher(
        client: new Client(['allow_redirects' => false, 'http_errors' => false, 'timeout' => 5.0]),
        requestFactory: new HttpFactory(),
    ),
);

$result = $provider->preview(url: 'https://example.com/article');

if ($result->isOk()) {
    $info = $result->unwrap();   // PageInfo
    echo $info->title, ' - ', $info->image;
} else {
    echo $result->error()?->value; // PreviewFailure: policy_rejected, network, not_html, ...
}
```

The pipeline is `Fetcher` (policy on every hop, size and content-type limits) -> `PageDocument` -> extractors -> fixed-priority merge -> `Limits` -> `PageInfo`. Every stage returns a `Result` with an enum failure; nothing throws on bad input from the network.

### Sources and priority

Each field is taken from the first source that has it: **OpenGraph**, then **Twitter Cards**, then **plain HTML**. Pass your own `extractors:` list (highest priority first) to change that.

| Field | OpenGraph | Twitter Cards | HTML fallback |
|---|---|---|---|
| `title` | `og:title` | `twitter:title` | `<title>` |
| `description` | `og:description` | `twitter:description` | `<meta name="description">` |
| `image` | `og:image:secure_url`, `og:image`, `og:image:url` | `twitter:image`, `twitter:image:src` | first `<img>` not declared smaller than 100 px |
| `favicon` | | | `<link rel="icon">`, then `apple-touch-icon` |
| `url` | `og:url` | | `<link rel="canonical">`; otherwise the final fetched URL |
| `siteName` | `og:site_name` | | |
| `language` | `og:locale` (`ru_RU` -> `ru-RU`) | | `lang` of `<html>` |
| `authorName` | `article:author` (names only, links are ignored) | | `<meta name="author">` |
| `publishedTime` | `article:published_time` | | |
| `type` | `og:type` | | |

All URLs are absolute (`<base href>` is honoured) and only `http`/`https`: `javascript:`, `data:`, `file:` and URLs with credentials are dropped, and the next candidate is tried.

### UrlPolicy

```php
use Rasuvaeff\LinkPreview\Fetch\UrlPolicy;

$policy = new UrlPolicy();
$policy->check(url: 'HTTPS://Example.COM:443/a#frag')->unwrap(); // 'https://example.com/a'
$policy->check(url: 'http://[::7f00:1]/')->error();               // UrlRejection::PrivateAddress

// development stand: allow one network (localhost names count as 127.0.0.1)
$dev = new UrlPolicy(allowedNetworks: ['10.20.0.0/16'], allowedPorts: [80, 443, 8080]);
```

| Rejected | Reason |
|---|---|
| scheme other than http/https, `javascript:`, `file:`, `//host` | `Scheme` |
| `user:pass@host`, `a@127.0.0.1` | `Credentials` |
| a port outside `allowedPorts` (default 80, 443) | `Port` |
| `localhost`, `*.localhost`, `localhost.` | `PrivateAddress` |
| loopback, private, link-local, shared (CGNAT), documentation, benchmark, multicast, reserved ranges - IPv4 and IPv6 | `PrivateAddress` |
| IPv6 that carries IPv4: `::7f00:1`, `::ffff:127.0.0.1`, `64:ff9b::7f00:1`, `2002:7f00:1::` | `PrivateAddress` (judged as the IPv4 inside) |
| `2130706433`, `0x7f000001`, `0177.0.0.1`, `127.1`, any host ending in a numeric label | `NonCanonicalIp` |
| spaces, control characters, backslashes, `exa%6Dple.com`, empty labels, invalid IDN | `Malformed` / `InvalidHost` |

Internationalised hosts become ASCII (`münchen.de` -> `xn--mnchen-3ya.de`). On success `check()` returns the normalised URL.

### Fetcher

```php
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\Fetch\UserAgentMap;

$fetcher = new Fetcher(
    client: $psr18Client,
    requestFactory: $psr17Factory,
    policy: new UrlPolicy(),
    userAgents: new UserAgentMap(byHost: ['t.me' => 'TelegramBot (like TwitterBot)']),
    maxRedirects: 5,
    maxBodyBytes: 2_097_152,
    chainBudgetSeconds: 10.0,
);

$page = $fetcher->fetch(url: 'https://example.com/')->unwrap(); // FetchedPage: url, body, contentType, charset, redirects
```

Redirects are followed by `Fetcher`, not by the client, and `UrlPolicy` judges every target **before** any request is made to it. Failures (`FetchFailure`): `PolicyRejected`, `Network`, `TooManyRedirects`, `InvalidRedirect`, `BudgetExceeded`, `HttpStatus`, `NotHtml`, `TooLarge`. Only `text/html` and `application/xhtml+xml` are accepted. `UserAgentMap` matches a host and its subdomains.

### Limits

```php
use Rasuvaeff\LinkPreview\Limits;

new PreviewProvider(fetcher: $fetcher, limits: new Limits(titleLength: 120, descriptionLength: 300, httpsImagesOnly: true));
```

Text is scrubbed of invalid UTF-8 and control characters, whitespace runs collapse to one space, and what is still too long is cut by characters with a trailing `…` that counts toward the limit (defaults: title 200, description 500, site name 100, author 100). Applying `Limits` twice equals applying it once. An image or favicon longer than `maxUrlLength` (2048) is dropped; with `httpsImagesOnly` plain-http ones are dropped too (the next source is *not* consulted after the merge).

### Public API

| Class | Description |
|---|---|
| `PreviewProvider` | Facade: `preview(url)` and `fromHtml(html, pageUrl, charset)` -> `Result<PageInfo, PreviewFailure>` |
| `PageInfo` | Result value object, `toArray()` |
| `Limits` | Length and scheme limits |
| `PreviewFailure` | Enum of refusals, including `EmptyPage` |
| `Fetch\Fetcher`, `FetchedPage`, `FetchFailure` | PSR-18 fetching with the redirect loop |
| `Fetch\UrlPolicy`, `UrlRejection` | SSRF policy |
| `Fetch\UserAgentMap` | Host -> `User-Agent` |
| `Html\PageDocument` | Read-only DOM view: `title()`, `meta()`, `links()`, `images()`, `language()`, `baseHref()` |
| `Extract\Extractor`, `OpenGraphExtractor`, `TwitterCardsExtractor`, `HtmlFallbackExtractor` | Sources; return `Result<Metadata, ExtractFailure>` |
| `Extract\Metadata` | What one source knows (all fields optional) |

## Security

A link preview fetches a URL chosen by a user from inside your network. What the package does:

- Whitelist policy on the first URL and on every redirect target, checked before the request is sent.
- Alternative IP spellings, IPv6-embedded IPv4, `localhost.`, userinfo tricks and control characters are rejected or classified as the address they really are.
- The HTML is parsed with libxml (`LIBXML_NONET`), never executed; external entities are not loaded; scripts are text.
- Response size is bounded; only HTML content types are read.
- Every URL taken from the page is absolute http(s) without credentials.

What it cannot do, because PSR-18 does not give it the means:

- **No DNS pinning.** The policy never resolves names; a name that resolves to an internal address passes, and an attacker can rebind a name between the check and the connection. Close this in the transport: send the client's traffic through a trusted resolving proxy (an egress proxy that refuses internal destinations, e.g. Smokescreen), or give the client a resolver/connection hook of its own that applies the same ranges to the connected address.
- **The client must not follow redirects** (`allow_redirects => false` for Guzzle, `max_redirects => 0` for Symfony). A redirect followed inside the client skips the policy.
- **Timeouts belong to the client.** PSR-18 has none; configure connect and read timeouts on the client. `chainBudgetSeconds` is checked between hops only and cannot interrupt a request in flight.
- **Body size** is checked on the body the client returns; a client that buffers has already read it. Set a limit on the client too.
- Image URLs are not fetched by this package. If you proxy or download them, run them through `UrlPolicy` as well.
- Output is plain text. Escape it for the context you render it in.

## Examples

See [examples/](examples/) for runnable scripts.
Examples are expected to execute without fatal errors and stay aligned with the
documented public API.

| Script | Shows | Needs server? |
|---|---|---|
| `parse_html.php` | Redirect, merge and policy refusals against an in-memory PSR-18 client | No |
| `url_policy.php` | What `UrlPolicy` accepts and rejects, dev allowlist | No |
| `redirects.php` | A redirect chain refused on the second hop | No |
| `limits_merge.php` | Source priority, custom order and `Limits` | No |
| `live.php` | A real fetch with Guzzle, only when `LINK_PREVIEW_URL` is set | Yes (optional) |

## Development

No PHP/Composer on the host - everything runs in Docker. The `composer:2` image has neither `ext-intl` (IDN hosts, Psalm stubs) nor `pcov`, so `make image` builds a local image (`link-preview-php:local`) that has both:

```bash
make image
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

## License

[BSD-3-Clause](LICENSE.md)
