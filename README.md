# rasuvaeff/link-preview

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/link-preview/v)](https://packagist.org/packages/rasuvaeff/link-preview)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/link-preview/downloads)](https://packagist.org/packages/rasuvaeff/link-preview)
[![Build](https://github.com/rasuvaeff/link-preview/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/link-preview/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/link-preview/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/link-preview/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/link-preview/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/link-preview/php)](https://packagist.org/packages/rasuvaeff/link-preview)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

Link preview for PHP: fetch a user-supplied URL through any PSR-18 client with an SSRF-aware redirect loop, and read title, description, image, favicon and more from oEmbed, JSON-LD, OpenGraph, Twitter Cards and plain HTML - with built-in adapters for eleven sites.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.

> Projects using the [llm/skills](https://github.com/roxblnfk/skills) Composer plugin also get this package's agent skill synced into `.agents/skills/` automatically on install.

Russian version: [README.ru.md](README.ru.md).

## Requirements

- PHP 8.3-8.5 (8.6 is checked in CI as experimental) with `dom`, `libxml`, `mbstring`, `intl`, `ctype`, `filter`
- `psr/http-client`, `psr/http-factory`, `psr/http-message`
- `rasuvaeff/result`, `rasuvaeff/schema` (reads the JSON answers of oEmbed, Wikipedia and Rutube; pulls `rasuvaeff/property-testing-core` in at runtime)
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

The pipeline is `UrlPolicy` -> site adapter (YouTube, Wikipedia, ...) -> `Fetcher` (policy on every hop, size and content-type limits) -> `PageDocument` -> extractors -> fixed-priority merge -> `Limits` -> `PageInfo`. Every stage returns a `Result` with an enum failure; nothing throws on bad input from the network.

### Sources and priority

Each field is taken from the first source that has it, in this order: **site adapter**, **oEmbed** (discovered through `<link rel="alternate" type="application/json+oembed">`), **JSON-LD** (schema.org Article, VideoObject, WebPage, Organization), **OpenGraph**, **Twitter Cards**, **plain HTML**. Pass your own `extractors:` list (highest priority first, after the adapter) to change that.

oEmbed discovery makes one extra request: the endpoint is an address chosen by the page, so it is fetched through the same `Fetcher` - same `UrlPolicy`, same redirect checks - and can never reach what the original URL could not. JSON-LD is read by a small built-in reader (`SimpleJsonLdReader`, behind the `JsonLdReader` interface so a full processor can replace it): only schema.org contexts are understood, a remote or inline `@context` is never fetched and the block is skipped.

#### A full JSON-LD processor

The built-in reader is a convenience, not a processor: it understands pages whose `@context` is a schema.org URL and skips every other block (an inline context, aliases such as `"title": "headline"`, compact IRIs). When that matters, implement `JsonLdReader` on top of [`rasuvaeff/json-ld`](https://github.com/rasuvaeff/json-ld), a JSON-LD 1.1 processor that does no I/O of its own: it expands the block and loads a remote context only through a loader you give it. [`examples/json_ld_processor.php`](examples/json_ld_processor.php) is a complete reader (about 100 lines, runnable): a loader that answers for schema.org and refuses everything else, the expansion, and the mapping of the expanded schema.org IRIs back to the short names the extractor reads. Pass it as `new JsonLdExtractor(new ProcessorJsonLdReader())` in `extractors:`. On 14 JSON-LD blocks collected from real pages (Wikipedia, YouTube, BBC, The New York Times, IMDb, Kommersant, Championat and others) it gives exactly the same previews as the built-in reader, and it also reads the blocks the built-in reader skips.

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

Many sites serve metadata only to crawlers they know: checked live, `vk.com` answers the default name with an endless redirect loop and `championat.com` with a login stub. `UserAgentMap::unfurler()` presents `Twitterbot/1.0` everywhere (single hosts can still be overridden): pass it as `userAgents:` when you read arbitrary sites.

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

### Site adapters

`PreviewProvider` picks an adapter by the host of the URL (after the URL policy accepted it) and merges its answer in front of everything else. Hosts are matched label by label: `www.youtube.com` and `m.youtube.com` are YouTube, `youtube.com.evil.example` and `notyoutube.com` are not. If an adapter fails the generic extractors still run; if the page is unavailable too, a missing token is reported as `PreviewFailure::MissingToken`.

| Site | Adapter | Mechanism | Needs |
|---|---|---|---|
| YouTube | `YouTubeAdapter` | oEmbed + embed URL from the video id | nothing |
| TikTok | `TikTokAdapter` | oEmbed | nothing |
| Twitter / X | `TwitterAdapter` | `publish.twitter.com/oembed`, post text as description | optional token |
| Twitch | `TwitchAdapter` | oEmbed + player URL with `parent` | nothing (pass `parents:` to get an embed URL) |
| Wikipedia | `WikipediaAdapter` | REST summary API of the link's own language | nothing |
| GitHub | `GitHubAdapter` | OpenGraph of the page, gist aware | nothing |
| Instagram | `InstagramAdapter` | Graph API `instagram_oembed` | **access token** |
| Facebook | `FacebookAdapter` | Graph API `oembed_post` / `oembed_video` / `oembed_page` | **access token** |
| Telegram | `TelegramAdapter` | OpenGraph + embed widget URL | nothing (`UserAgentMap::unfurler()` recommended) |
| VK | `VkAdapter` | OpenGraph + `video_ext.php` player | nothing (**needs** `UserAgentMap::unfurler()`: with the default name VK answers with a redirect loop) |
| Rutube | `RutubeAdapter` | video API JSON | nothing |

```php
use Rasuvaeff\LinkPreview\Adapter\AdapterRegistry;

$provider = new PreviewProvider(
    fetcher: $fetcher,
    adapters: AdapterRegistry::standard(
        instagramToken: '<token>',
        facebookToken: '<token>',
        twitchParents: ['example.com'],
    ),
);
```

JSON answers (oEmbed, Wikipedia summary, Rutube video) are read through `rasuvaeff/schema` schemas (`Extract\Schemas`): open objects, listed fields optional or `null`, a field of the wrong type makes the answer unreadable (`AdapterFailure::InvalidResponse`) and `AdapterContext::diagnostics()` says where (`oEmbed $.title: ...`) without echoing a value. GitHub, Telegram and VK are read from HTML, and JSON-LD is open-ended, so neither uses a schema.

Tokens are constructor arguments (`#[\SensitiveParameter]`), travel as an `Authorization: Bearer` header to the API host only (a redirect to another host drops them) and never appear in a URL, a failure or an exception. Without a token, Instagram and Facebook answer `AdapterFailure::MissingToken` without making any request. `new AdapterRegistry()` turns adapters off; implement `Adapter` to add a site.

The fixtures behind the adapter tests are in `tests/fixtures/adapters/`: they are written from the services' documented response formats, not live recordings. `bin/record-preview-corpus --check` re-parses every committed entry (no network); `bin/record-preview-corpus --record=<name> --live` refreshes one entry's files from the real service, and `LINK_PREVIEW_LIVE=1 vendor/bin/testo --suite=Integration` compares the real services with what the code expects.

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
| `Extract\Extractor`, `OEmbedExtractor`, `JsonLdExtractor`, `OpenGraphExtractor`, `TwitterCardsExtractor`, `HtmlFallbackExtractor` | Sources; return `Result<Metadata, ExtractFailure>` |
| `Extract\Schemas` | `rasuvaeff/schema` schemas of the JSON answers |
| `Extract\JsonLdReader`, `SimpleJsonLdReader`, `JsonLdFailure` | Replaceable JSON-LD reader |
| `Adapter\Adapter`, `AdapterRegistry`, `AdapterContext`, `AdapterFailure` | Site adapters and their registry; eleven built in |
| `Extract\Metadata` | What one source knows (all fields optional) |

## Security

A link preview fetches a URL chosen by a user from inside your network. What the package does:

- Whitelist policy on the first URL and on every redirect target, checked before the request is sent.
- Alternative IP spellings, IPv6-embedded IPv4, `localhost.`, userinfo tricks and control characters are rejected or classified as the address they really are.
- The HTML is parsed with libxml (`LIBXML_NONET`), never executed; external entities are not loaded; scripts are text.
- Response size is bounded; only HTML content types are read.
- Every URL taken from the page is absolute http(s) without credentials. An oEmbed endpoint named by a page is fetched under the same policy and redirect checks as the page; a JSON-LD `@context` is never fetched.
- Adapter hosts are matched by labels, not substrings; adapter tokens are sent as headers to the API host only and never appear in URLs, failures or exceptions.

What it cannot do, because PSR-18 does not give it the means:

- **No DNS pinning in the package.** The policy never resolves names; a name that resolves to an internal address passes, and an attacker can rebind a name between the check and the connection. Close this in the transport: either send the client's traffic through a trusted resolving proxy (an egress proxy that refuses internal destinations, e.g. Smokescreen), or pin the connection yourself. [`examples/pinned_client.php`](examples/pinned_client.php) is a ready PSR-18 client over Guzzle that does the second: it resolves the host, checks every address with the same `UrlPolicy`, and passes the checked addresses to cURL (`CURLOPT_RESOLVE`), so the connection can only go where the check looked. A host with any non-public address is refused before a socket is opened; redirects are left to the `Fetcher`. (It is a recipe and not a class of the package because it needs Guzzle with the cURL handler; copy it into your application.)
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
| `adapters_oembed.php` | YouTube, TikTok, Twitter/X, Twitch from recorded oEmbed answers | No |
| `adapters_pages.php` | GitHub, Telegram, VK (page tags) and Wikipedia, Rutube (APIs) from recorded answers | No |
| `adapters_tokens.php` | Instagram: the missing-token failure, then a configured token | No |
| `json_ld_processor.php` | A JSON-LD 1.1 processor behind `JsonLdReader` (needs `rasuvaeff/json-ld`; says so and exits without it) | No |
| `pinned_client.php` | A PSR-18 client with DNS pinning over Guzzle, refusing hosts with non-public addresses | No |
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
