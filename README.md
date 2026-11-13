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
- `rasuvaeff/json-ld` (the JSON-LD 1.1 processor behind the JSON-LD source), `rasuvaeff/result`, `rasuvaeff/schema` (reads the JSON answers of oEmbed, Wikipedia and Rutube internally; pulls `rasuvaeff/property-testing-core` in at runtime)
- A PSR-18 client and a PSR-17 request factory. The recommended client is [`rasuvaeff/pinned-http-client`](https://github.com/rasuvaeff/pinned-http-client) (DNS pinning, brings Guzzle's PSR-17 factory); any other works (Guzzle, Symfony HttpClient + Nyholm PSR-7, ...)

## Installation

```bash
composer require rasuvaeff/link-preview
composer require rasuvaeff/pinned-http-client   # recommended PSR-18 client: pins the connection to checked addresses
```

## Usage

```php
use GuzzleHttp\Psr7\HttpFactory;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\Fetch\UrlPolicy;
use Rasuvaeff\LinkPreview\Fetch\UserAgentMap;
use Rasuvaeff\LinkPreview\PreviewProvider;
use Rasuvaeff\PinnedHttpClient\AddressPolicy;
use Rasuvaeff\PinnedHttpClient\PinnedClient;

$policy = new UrlPolicy();

$provider = new PreviewProvider(
    fetcher: new Fetcher(
        client: new PinnedClient(
            // the addresses a host resolves to are judged by the same rules as the URL
            policy: new class ($policy) implements AddressPolicy {
                public function __construct(private UrlPolicy $policy) {}

                public function allows(string $address): bool
                {
                    return $this->policy->allowsAddress($address);
                }
            },
            timeoutSeconds: 5.0,
            connectTimeoutSeconds: 3.0,
            maxBodyBytes: 2_097_153, // above the Fetcher's limit: an oversized page is the Fetcher's TooLarge
        ),
        requestFactory: new HttpFactory(),
        policy: $policy,
        userAgents: UserAgentMap::unfurler(),
    ),
);

$result = $provider->preview(url: 'https://example.com/article');

if ($result->isOk()) {
    $info = $result->unwrap();   // PageInfo
    echo $info->title, ' - ', $info->image;
} else {
    $failure = $result->error(); // PreviewFailure
    echo $failure?->reason->value;                       // policy_rejected, network, http_status, ...
    echo $failure?->isTransient() ? ' (retry later)' : ''; // see "Failures" below
}
```

Do not wrap `PinnedClient` in its `RedirectFollower`: the `Fetcher` follows redirects itself and checks every hop. With a plain Guzzle client instead, pass `new Client(['allow_redirects' => false, 'http_errors' => false, 'timeout' => 5.0])` - and read the Security section about DNS rebinding.

The pipeline is `UrlPolicy` -> site adapter (YouTube, Wikipedia, ...) -> `Fetcher` (policy on every hop, size and content-type limits) -> `PageDocument` -> extractors -> fixed-priority merge -> `Limits` (clean text, bounds) -> `PageInfo`. Every stage returns a `Result`; nothing throws on bad input from a user or the network.

`PreviewProvider` implements `LinkPreviewer` (`preview()`, `fromHtml()`). Depend on the interface to put a cache, a rate limit or a circuit breaker in front of it - the package ships one such decorator, `CachedPreviewProvider` (PSR-16, [`psr/simple-cache`](https://www.php-fig.org/psr/psr-16/) ^3.0, suggested - bring your own backend):

```php
use Rasuvaeff\LinkPreview\CachedPreviewProvider;

$previewer = new CachedPreviewProvider($provider, $psr16Cache, ttlSeconds: 900); // null = the backend's default
```

Only successful previews are cached, as the `PageInfo::toArray()` shape read back through `PageInfo::fromArray()`. A failed fetch is **not** cached: a page that is down right now gets a fresh chance on the next call - the cache only ever shortens a wait, never lengthens one. `fromHtml()` passes through uncached (the document is already in hand). The key is a hash of the URL string exactly as passed to `preview()` (prefix `rasuvaeff.link-preview.preview.`), so two spellings of the same page are two keys until the TTL expires - normalise upstream if your callers are sloppy. A garbage cache value (wrong type or shape after a backend hiccup) is a miss, never an error, and is overwritten by the next successful preview. See [`examples/cached_previewer.php`](examples/cached_previewer.php).

### PageInfo

| Field | What it is |
|---|---|
| `url` | where the preview was taken from: the fetched URL after redirects, as the policy normalised it (for `fromHtml()`, the normalised `pageUrl`). Link the card here |
| `canonicalUrl` | what the page or its service claims its address is (`og:url`, `<link rel="canonical">`, JSON-LD `url`, an adapter). A page writes this itself and may name any host: use it to deduplicate, never as the link target without your own check |
| `title`, `description`, `siteName`, `authorName`, `providerName` | clean plain text (see *Limits*) |
| `image`, `imageWidth`, `imageHeight` | absolute http(s) URL and its declared size in pixels; the size comes from the same source as the image and is `null` without one |
| `favicon`, `embedUrl` | absolute http(s) URLs (`embedUrl`: an embeddable player) |
| `language`, `publishedTime`, `type` | BCP 47 tag, `DateTimeImmutable`, `og:type` / oEmbed / JSON-LD type |

`providerName` is the service behind the content: oEmbed `provider_name`, or the site adapter (`Wikipedia`, `GitHub`, ...). `toArray()` returns all fifteen keys; `PageInfo::fromArray($array)` reads them back (`fromArray($info->toArray())->toArray() === $info->toArray()`), treats missing keys as `null` - an array stored by 0.1 still reads - and throws `InvalidArgumentException` on a wrong type.

`siteName` is only what a source said — the page's own site name, or else the oEmbed/adapter `providerName` (always, with or without the option below). To show the host when nothing names the site, opt in: `new PreviewProvider(fetcher: $fetcher, hostAsSiteName: true)` - the host of `url` without `www.`, in its ASCII form (`xn--mnchen-3ya.de`, not a Unicode look-alike). It is opt-in so that a `siteName` you store or compare is never a guess.

### Failures

A failed preview is a `PreviewFailure`: `reason` (`PreviewFailureReason`), `status` and `retryAfterSeconds` (for an HTTP status answer) and `isTransient()`, which says whether the same URL may preview later. The `Fetcher` reports the same way through `FetchFailure` / `FetchFailureReason`.

| `reason` | When | `isTransient()` |
|---|---|---|
| `PolicyRejected` | the URL, a redirect target or the `pageUrl` of `fromHtml()` is refused by `UrlPolicy` | no |
| `Network` | the client threw a `NetworkExceptionInterface` (connect, DNS — pinned-http-client's `ResolutionFailed` included —, timeout) | **yes** |
| `ClientRefused` | the client threw any other `ClientExceptionInterface` (a pinning client found a forbidden address) | no |
| `TooManyRedirects`, `InvalidRedirect` | the redirect chain is too long, or a `Location` is unusable | no |
| `BudgetExceeded` | `chainBudgetSeconds` ran out between hops | **yes** |
| `HttpStatus` | a status that is neither 2xx nor a followable redirect; `status` holds it | **yes** for 408, 425, 429 and 5xx, no otherwise |
| `UnexpectedContentType` | the answer is not HTML (or JSON, or the media types asked for) | no |
| `TooLarge` | the body is over the size limit (the Fetcher's, `fetchMedia()`'s or a pinning client's own) | no |
| `EmptyBody` | `fetchMedia()` only: a 2xx answer with an empty body | no |
| `MissingToken` | the site's adapter needs a token that was not configured, and the page itself was unavailable | no |
| `EmptyPage` | the page was fetched but no source found anything in it | no |

`retryAfterSeconds` is the server's `Retry-After`: delta-seconds as sent, an HTTP-date counted from the response's own `Date` header (without one an HTTP-date is not read - the package keeps no clock), capped at a day. Retry a transient failure after `retryAfterSeconds` when it is there, after your own backoff otherwise; do not retry a permanent one.

### Sources and priority

Each field is taken from the first source that has it, in this order: **site adapter**, **oEmbed** (discovered through `<link rel="alternate" type="application/json+oembed">`), **JSON-LD** (schema.org Article, VideoObject, WebPage, Organization), **OpenGraph**, **Twitter Cards**, **plain HTML**. Pass your own `extractors:` list (highest priority first, after the adapter) to change that. Text is cleaned as it is merged, so a value that is nothing but markup (`<b></b>`, `&nbsp;`) does not hide the next source's; the image and its dimensions are merged as one field.

oEmbed discovery makes one extra request: the endpoint is an address chosen by the page, so it is fetched through the same `Fetcher` - same `UrlPolicy`, same redirect checks - and can never reach what the original URL could not. JSON-LD is read by `ProcessorJsonLdReader` (behind the `JsonLdReader` interface, so you can replace it): the block is expanded by the JSON-LD 1.1 processor of [`rasuvaeff/json-ld`](https://github.com/rasuvaeff/json-ld), the schema.org properties are mapped back to the short names the extractor reads. See *JSON-LD* below.

#### JSON-LD

`ProcessorJsonLdReader` expands every `<script type="application/ld+json">` block with `rasuvaeff/json-ld`, so inline contexts, aliases (`"title": "headline"`) and compact IRIs work, together with `@graph`, lists, several types and `{"@id": ...}` references between nodes of one block. Nothing is fetched from the network: the `https://schema.org` context (any spelling: `http`, `www`, `/docs/jsonldcontext.json`) is answered from memory, with its `schema:` prefix; any other remote context makes the block `UnsupportedContext` and it is skipped. A type or a property outside schema.org is dropped, so a term redefined to a foreign IRI (`{"headline": "http://evil.example/x"}`) is not read. Input nested deeper than 32 levels is refused before the processor sees it. Tested on 14 blocks collected from real pages (Wikipedia, YouTube, BBC, The New York Times, IMDb, Kommersant, Championat and others).

To read JSON-LD some other way, implement `JsonLdReader` (`nodes(string $json): Result<list<array<string, mixed>>, JsonLdFailure>`: schema.org nodes with short property names, no I/O of its own) and pass `new JsonLdExtractor(new YourReader())` in `extractors:`.

| Field | OpenGraph | Twitter Cards | HTML fallback |
|---|---|---|---|
| `title` | `og:title` | `twitter:title` | `<title>` |
| `description` | `og:description` | `twitter:description` | `<meta name="description">` |
| `image` | `og:image:secure_url`, `og:image`, `og:image:url` | `twitter:image`, `twitter:image:src` | first `<img>` not declared smaller than 100 px |
| `imageWidth` / `imageHeight` | first `og:image:width` / `og:image:height` | `twitter:image:width` / `twitter:image:height` | the `width` / `height` of that `<img>` |
| `favicon` | | | `<link rel="icon">`, then `apple-touch-icon` |
| `canonicalUrl` | `og:url` | | `<link rel="canonical">` |
| `siteName` | `og:site_name` | | |
| `language` | `og:locale` (`ru_RU` -> `ru-RU`) | | `lang` of `<html>` |
| `authorName` | `article:author` (names only, links are ignored) | | `<meta name="author">` |
| `publishedTime` | `article:published_time` | | |
| `type` | `og:type` | | |

oEmbed gives `title`, `author_name`, `provider_name` (as `providerName` and `siteName`), `thumbnail_url` with `thumbnail_width` / `thumbnail_height` (a `photo`: `url`, `width`, `height`), the `<iframe src>` of `html` as `embedUrl`, and `type`. JSON-LD gives an `ImageObject`'s `width` / `height` (a number, digits or a `QuantitativeValue`) with its `url`. A side is a positive integer below 100 000; anything else (`"100%"`) is unknown.

All URLs are absolute (`<base href>` is honoured) and only `http`/`https`: `javascript:`, `data:`, `file:` and URLs with credentials are dropped, and the next candidate is tried.

### UrlPolicy

```php
use Rasuvaeff\LinkPreview\Fetch\UrlPolicy;

$policy = new UrlPolicy();
$policy->check(url: 'HTTPS://Example.COM:443/a#frag')->unwrap(); // 'https://example.com/a'
$policy->check(url: 'http://[::7f00:1]/')->error();               // UrlRejection::PrivateAddress
$policy->allowsAddress('169.254.169.254');                         // false: the same ranges, for a resolved address

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

`allowsAddress(string $address): bool` judges one resolved address - a plain IPv4 or IPv6 literal, no brackets, no port - exactly as `check()` judges a URL with that IP as its host, `allowedNetworks` included; anything else is `false`. It is the `AddressPolicy` of `rasuvaeff/pinned-http-client`: the bridge is the three-line anonymous class in *Usage*.

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
$json = $fetcher->fetchJson(url: 'https://api.example.com/x');  // application/json or +json

// an image of a preview: same policy, redirect checks and User-Agent, its own types and size limit
$media = $fetcher->fetchMedia(url: $info->image, acceptedTypes: ['image/*'], maxBytes: 5_242_880);
```

Redirects are followed by `Fetcher`, not by the client, and `UrlPolicy` judges every target **before** any request is made to it. A failure is a `FetchFailure` (`reason`, `status`, `retryAfterSeconds`, `isTransient()`; reasons as in *Failures*, without the preview-only `MissingToken` and `EmptyPage`). `fetch()` accepts only `text/html` and `application/xhtml+xml`. `fetchMedia()` replaces the Fetcher's `maxBodyBytes` with its own `maxBytes`, and the client's own limit still applies: a body over `PinnedClient`'s `maxBodyBytes` is `TooLarge` as well. Give media a `Fetcher` over `$pinned->withMaxBodyBytes($mediaMaxBytes)` when media may be larger than pages. An empty 2xx body is `EmptyBody`. It takes media types (`image/png`) or families (`image/*`), lower-case, and throws `InvalidArgumentException` for an empty or malformed list or a limit below 1 - those are programming errors, not input. `UserAgentMap` matches a host and its subdomains.

### Limits

```php
use Rasuvaeff\LinkPreview\Limits;

new PreviewProvider(fetcher: $fetcher, limits: new Limits(titleLength: 120, descriptionLength: 300, httpsImagesOnly: true));
```

The package returns **clean text**. For `title`, `description`, `siteName`, `authorName` and `providerName`, `Limits`:

1. scrubs invalid UTF-8;
2. decodes HTML entities - repeatedly, so double escaping such as `&amp;quot;` comes out as `"`;
3. removes tags, comments and doctypes, each replaced by a space (`Line one.<br>Line two.` -> `Line one. Line two.`); a `<` that opens no tag (`a < b`, `I <3 PHP`) is text;
4. collapses control characters and runs of whitespace to one space;
5. and only then cuts what is too long by characters, with a trailing `…` that counts toward the limit (defaults: title 200, description 500, site and provider name 100, author 100).

Decoding and stripping repeat until nothing changes, at most four rounds; text escaped deeper than that gets its remaining `&` and `<` replaced by full-width `＆` and `＜`, so the result is final either way. Applying `Limits` twice equals applying it once, and the output never contains an entity to decode or a tag. Escaped markup is markup: a title written as `&lt;b&gt;bold&lt;/b&gt;` becomes `bold`. Every step is linear in the input.

An image, favicon or embed URL longer than `maxUrlLength` (2048) is dropped, an image together with its dimensions; with `httpsImagesOnly` plain-http ones are dropped too (the next source is *not* consulted after the merge). An over-long `canonicalUrl` is dropped as well.

### Site adapters

`PreviewProvider` picks an adapter by the host of the URL (after the URL policy accepted it) and merges its answer in front of everything else. Hosts are matched label by label: `www.youtube.com` and `m.youtube.com` are YouTube, `youtube.com.evil.example` and `notyoutube.com` are not. If an adapter fails the generic extractors still run; if the page is unavailable too, a missing token is reported as `PreviewFailureReason::MissingToken`.

| Site | Mechanism | Needs |
|---|---|---|
| YouTube | oEmbed + embed URL from the video id | nothing |
| TikTok | oEmbed | nothing |
| Twitter / X | `publish.twitter.com/oembed`, post text as description | optional token |
| Twitch | oEmbed + player URL with `parent` | nothing (pass `twitchParents:` to get an embed URL) |
| Wikipedia | REST summary API of the link's own language | nothing |
| GitHub | OpenGraph of the page, gist aware | nothing |
| Instagram | Graph API `instagram_oembed` | **access token** |
| Facebook | Graph API `oembed_post` / `oembed_video` / `oembed_page` | **access token** |
| Telegram | OpenGraph + embed widget URL | nothing (`UserAgentMap::unfurler()` recommended) |
| VK | OpenGraph + `video_ext.php` player | nothing (**needs** `UserAgentMap::unfurler()`: with the default name VK answers with a redirect loop) |
| Rutube | video API JSON | nothing |

The built-in adapters are not public classes: they come from `AdapterRegistry::standard()`. Your own adapters (implement `Adapter`) go in front of them with `with()`; the first adapter whose domains match the host wins, so an adapter of yours for `youtube.com` replaces the built-in one.

```php
use Rasuvaeff\LinkPreview\Adapter\AdapterRegistry;

$provider = new PreviewProvider(
    fetcher: $fetcher,
    adapters: AdapterRegistry::standard(
        instagramToken: '<token>',
        facebookToken: '<token>',
        twitchParents: ['example.com'],
    )->with(new MyIntranetWikiAdapter()), // asked first; then the eleven built-in ones
);
```

An adapter answers `Result<Metadata, AdapterFailure>` and reaches the network only through its `AdapterContext` (`html()`, `json()` - the `Fetcher`, under the URL policy). JSON answers of oEmbed, Wikipedia and Rutube are checked against their shapes before they are read: a field of the wrong type makes the answer unreadable (`AdapterFailure::InvalidResponse`) and `AdapterContext::diagnostics()` says where (`oEmbed $.title: ...`) without echoing a value.

Tokens are arguments of `standard()` (`#[\SensitiveParameter]`), travel as an `Authorization: Bearer` header to the API host only (a redirect to another host drops them) and never appear in a URL, a failure or an exception. Without a token, Instagram and Facebook answer `AdapterFailure::MissingToken` without making any request. `new AdapterRegistry()` turns adapters off.

The fixtures behind the adapter tests are in `tests/fixtures/adapters/`: they are written from the services' documented response formats, not live recordings. `bin/record-preview-corpus --check` re-parses every committed entry (no network); `bin/record-preview-corpus --record=<name> --live` refreshes one entry's files from the real service, and `LINK_PREVIEW_LIVE=1 vendor/bin/testo --suite=Integration` compares the real services with what the code expects.

### Public API

| Class | Description |
|---|---|
| `LinkPreviewer` | The facade interface: `preview(url)` and `fromHtml(html, pageUrl, charset)` -> `Result<PageInfo, PreviewFailure>` |
| `PreviewProvider` | Its implementation (fetcher, extractors, limits, adapters, `hostAsSiteName`) |
| `PageInfo` | Result value object, `toArray()` / `fromArray()` |
| `Limits` | Clean text, length and scheme limits |
| `PreviewFailure`, `PreviewFailureReason` | Why there is no preview: reason, status, `Retry-After`, `isTransient()` |
| `Fetch\Fetcher`, `FetchedPage`, `FetchFailure`, `FetchFailureReason` | PSR-18 fetching with the redirect loop: `fetch()`, `fetchJson()`, `fetchMedia()`, `check()` |
| `Fetch\UrlPolicy`, `UrlRejection` | SSRF policy: `check()` for URLs, `allowsAddress()` for resolved addresses |
| `Fetch\UserAgentMap` | Host -> `User-Agent` |
| `Html\PageDocument` | Read-only DOM view: `title()`, `meta()`, `links()`, `images()`, `language()`, `baseHref()` |
| `Extract\Extractor`, `OEmbedExtractor`, `JsonLdExtractor`, `OpenGraphExtractor`, `TwitterCardsExtractor`, `HtmlFallbackExtractor` | Sources; return `Result<Metadata, ExtractFailure>` |
| `Extract\JsonLdReader`, `ProcessorJsonLdReader`, `JsonLdFailure` | Replaceable JSON-LD reader; the default is built on `rasuvaeff/json-ld` |
| `Adapter\Adapter`, `AdapterRegistry`, `AdapterContext`, `AdapterFailure` | Site adapters: `AdapterRegistry::standard()` (eleven built in) and `with()` for your own |
| `Extract\Metadata` | What one source knows (all fields optional) |

## Security

A link preview fetches a URL chosen by a user from inside your network. What the package does:

- Whitelist policy on the first URL and on every redirect target, checked before the request is sent; `fromHtml()` checks its `pageUrl` too.
- Alternative IP spellings, IPv6-embedded IPv4, `localhost.`, userinfo tricks and control characters are rejected or classified as the address they really are.
- The HTML is parsed with libxml (`LIBXML_NONET`), never executed; external entities are not loaded; scripts are text.
- Response size is bounded; only the content types asked for are read.
- Every URL taken from the page is absolute http(s) without credentials. An oEmbed endpoint named by a page is fetched under the same policy and redirect checks as the page; a JSON-LD `@context` is never fetched.
- `url` is always where the preview was fetched from; what the page claims (`og:url`, canonical) is kept apart in `canonicalUrl`, so a page cannot make a card point elsewhere.
- Adapter hosts are matched by labels, not substrings; adapter tokens are sent as headers to the API host only and never appear in URLs, failures or exceptions.

What it cannot do, because PSR-18 does not give it the means:

- **No DNS pinning in the package.** The policy never resolves names; with a plain PSR-18 client a name that resolves to an internal address passes, and an attacker can rebind a name between the check and the connection. Close this in the transport: use [`rasuvaeff/pinned-http-client`](https://github.com/rasuvaeff/pinned-http-client) as in *Usage* (it resolves the host, checks every address with `UrlPolicy::allowsAddress()` and pins the connection; [`examples/with_pinned_client.php`](examples/with_pinned_client.php)), or send the client's traffic through a trusted resolving proxy (an egress proxy that refuses internal destinations, e.g. Smokescreen).
- **The client must not follow redirects** (`PinnedClient` does not; `allow_redirects => false` for Guzzle, `max_redirects => 0` for Symfony). A redirect followed inside the client skips the policy.
- **Timeouts belong to the client.** PSR-18 has none; configure connect and read timeouts on the client. `chainBudgetSeconds` is checked between hops only and cannot interrupt a request in flight.
- **Body size** is checked on the body the client returns; a client that buffers has already read it. Set a limit on the client too (`PinnedClient`'s `maxBodyBytes`).
- Image URLs are not fetched by the preview. To download one, use `Fetcher::fetchMedia()`, which applies the same policy.
- Output is clean plain text, not safe markup. Escape it for the context you render it in.

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
| `failures.php` | `PreviewFailure`: reason, status, `Retry-After`, `isTransient()` | No |
| `cached_previewer.php` | A caching `LinkPreviewer` decorator over `toArray()` / `fromArray()` | No |
| `media.php` | `Fetcher::fetchMedia()`: types, size limit, policy on redirects | No |
| `adapters_oembed.php` | YouTube, TikTok, Twitter/X, Twitch from recorded oEmbed answers | No |
| `adapters_pages.php` | GitHub, Telegram, VK (page tags) and Wikipedia, Rutube (APIs) from recorded answers | No |
| `adapters_tokens.php` | Instagram: the missing-token failure, then a configured token | No |
| `with_pinned_client.php` | The Fetcher over `rasuvaeff/pinned-http-client` (DNS pinning) with `UrlPolicy::allowsAddress()`; needs the package | No |
| `live.php` | A real fetch (`PinnedClient` when installed), only when `LINK_PREVIEW_URL` is set | Yes (optional) |

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
