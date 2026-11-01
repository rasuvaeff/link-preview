# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.2.0 — 2026-11-01

- Requires `rasuvaeff/json-ld` ^0.2 (`ProcessingOptions`; loader failures arrive as
  `DocumentLoadingException`). No change in what is read.

The pre-1.0 contract: what 1.0 will freeze. Breaking changes are marked **BC:**.

- **BC:** failures are value objects. `PreviewProvider::preview()` / `fromHtml()` return `Result<PageInfo, PreviewFailure>` where `PreviewFailure` is now a class with `reason` (the new enum `PreviewFailureReason`), `status` (the HTTP status of an `HttpStatus` failure), `retryAfterSeconds` (the server's `Retry-After`: delta-seconds, or an HTTP-date counted from the response's `Date`; capped at a day) and `isTransient()` (network, spent budget, HTTP 408/425/429/5xx). The `Fetcher` does the same with `FetchFailure` / `FetchFailureReason`. Migration: `$result->error() === PreviewFailure::Network` becomes `$result->error()?->reason === PreviewFailureReason::Network` (the old form now fails loudly: the constants are gone); `->error()?->value` becomes `->error()?->reason->value`; retry only when `isTransient()`.
- **BC:** `NotHtml` is renamed `UnexpectedContentType` (`unexpected_content_type`) in both reason enums - it is also what `fetchJson()` and `fetchMedia()` answer. Migration: rename; stored `not_html` codes map to `unexpected_content_type`.
- **BC:** a client exception that is not a `NetworkExceptionInterface` is the new, permanent `ClientRefused` (was `Network`): a pinning client refusing a forbidden address must not be retried. Migration: handle `ClientRefused` where you handled `Network` for non-network errors.
- **BC:** `PageInfo::$url` is the fetched URL (after redirects, policy-normalised) and nothing else; what the page claims (`og:url`, `<link rel="canonical">`, JSON-LD `url`, an adapter's URL) moves to the new `PageInfo::$canonicalUrl`, so a page cannot make a card point at another host. Migration: link to `url` as before; read `canonicalUrl` where you relied on the page's own address (deduplication).
- **BC:** text is clean. `Limits` (and the merge, so markup-only values fall through to the next source) decodes HTML entities repeatedly (`&amp;quot;` -> `"`), replaces tags, comments and doctypes with a space, collapses control characters and whitespace, and only then clips; text escaped more than four levels deep keeps its `&`/`<` as full-width `＆`/`＜`. Applies to `title`, `description`, `siteName`, `authorName`, `providerName`. Migration: drop your own decoding/stripping (it would now double-decode); still escape on output.
- **BC:** `fromHtml()` takes its `pageUrl` through the URL policy: a relative, private or non-http(s) one is `Err(PolicyRejected)` instead of an exception, and `PageInfo::$url` is its normalised form. Its parameter is `string` (was `non-empty-string`). Migration: handle the `Err`; pass an allowed URL.
- **BC:** the eleven concrete adapters (`YouTubeAdapter`, ..., `RutubeAdapter`) and `Extract\Schemas` are `@internal`, so neither their constructors nor `rasuvaeff/schema` types are part of the contract. Migration: get the adapters from `AdapterRegistry::standard(...)`; put your own in front with the new `AdapterRegistry::with(Adapter ...$adapters)` (first match wins, so yours override a built-in for the same domain); build test answers from your own fixtures instead of `Schemas::*()->arbitrary()`.
- **BC:** `PageInfo::toArray()` has four more keys (`canonicalUrl`, `imageWidth`, `imageHeight`, `providerName`; fifteen in all) and `Extract\Metadata` three more constructor parameters (`imageWidth`, `imageHeight`, `providerName`, appended). Migration: compare arrays by key, not by count.
- `PageInfo::$imageWidth` / `$imageHeight`: the declared size of the chosen image, from `og:image:width`/`height`, `twitter:image:width`/`height`, the `<img>` of the HTML fallback, oEmbed `thumbnail_width`/`height` (a photo's `width`/`height`) and a JSON-LD `ImageObject` (number, digits or `QuantitativeValue`). The size travels with its image through the merge and `Limits`.
- `PageInfo::$providerName`: oEmbed `provider_name`, or the site adapter (`Wikipedia`, `GitHub`, `Telegram`, `VK`, `Rutube`).
- `PageInfo::fromArray()`: the inverse of `toArray()` for caches and databases; missing keys are `null` (0.1 arrays still read), wrong types throw `InvalidArgumentException`.
- `LinkPreviewer`: the interface of the facade (`preview()`, `fromHtml()`), implemented by `PreviewProvider`, for cache / rate-limit decorators (`examples/cached_previewer.php`).
- `Fetcher::fetchMedia(string $url, list<string> $acceptedTypes, int $maxBytes)`: a bounded download (an image of a preview) under the same policy, redirect checks and `User-Agent` as a page; `type/subtype` or `type/*`, its own size limit.
- `UrlPolicy::allowsAddress(string $address)`: judges a resolved IP exactly as `check()` judges a URL with that IP; it is the `AddressPolicy` of `rasuvaeff/pinned-http-client` (README *Usage*).
- `PreviewProvider(hostAsSiteName: true)`: opt-in `siteName` fallback to the host of `url` without `www.` (ASCII). Off by default so that a stored `siteName` is always something a source said.
- Docs: `PinnedClient` is the client of the quickstart (README, llms.txt, skill); the failure taxonomy is a README table; new examples `failures.php`, `cached_previewer.php`, `media.php`; `with_pinned_client.php` and `live.php` use `rasuvaeff/pinned-http-client` ^0.2 (dev and suggest constraint raised from ^0.1).

## 0.1.2 — 2026-11-01

- Requires `rasuvaeff/schema` ^0.5: the open answer schemas are declared with
  `unknown: Unknown::Passthrough` (was `additionalProperties: true`); parsing
  and the parsed arrays are unchanged.
- CI: the property regression corpus is restored before and saved after the
  coverage run, so a falsified property replays on later runs.
- Tests: the broken-markup fixture no longer puts a tag inside `<title>` —
  libxml 2.14+ (the `ubuntu-26.04` runners) reads the title as RCDATA, as
  HTML5 does, so the expected text depended on the libxml version; the
  clipping property draws blank text as its own branch, so its 1% "blank"
  coverage gate no longer flakes.

## 0.1.1 — 2026-10-23

- DNS pinning is now `rasuvaeff/pinned-http-client` (suggested, dev-required for the example): `examples/with_pinned_client.php` feeds it the rules of `UrlPolicy`; README and agent skill point to it instead of a recipe.

## 0.1.0 — 2026-10-23

- JSON-LD is read by `ProcessorJsonLdReader` on top of the new dependency `rasuvaeff/json-ld` (JSON-LD 1.1 expansion): inline contexts, aliases and compact IRIs work; the schema.org context is answered from memory (`SchemaOrgContextLoader`, internal), any other remote context skips the block, types and properties outside schema.org are dropped. Same previews as the former reader on 14 real-page blocks.
- `UserAgentMap::unfurler()` (and `UserAgentMap::UNFURLER`): the unfurler name `Twitterbot/1.0` for every host with per-host overrides; checked live that VK and championat.com refuse the default name.
- `UrlPolicy`: http(s)-only, port, public-IP and IDN policy with CIDR allowlist, no DNS resolution.
- `Fetcher`: PSR-18 fetching with its own redirect loop (policy on every hop), redirect limit, between-hop budget, response size and content-type limits, per-host `UserAgentMap`.
- `PageDocument`: read-only DOM view with charset detection.
- `OpenGraphExtractor`, `TwitterCardsExtractor`, `HtmlFallbackExtractor` returning `Result`.
- `PageInfo`, `Limits`, `PreviewProvider` facade with fixed-priority merge.
- `OEmbedExtractor`: oEmbed discovery; the endpoint a page names is fetched under the same `UrlPolicy` and redirect checks as the page.
- `JsonLdExtractor` with a replaceable `JsonLdReader` (default `ProcessorJsonLdReader`; nothing remote is ever loaded).
- `Fetcher::fetchJson()` and `Fetcher::check()`; custom request headers go to the first host only.
- Site adapters behind `Adapter` / `AdapterRegistry`: YouTube, TikTok, Twitter/X, Twitch, Wikipedia, GitHub, Instagram, Facebook, Telegram, VK, Rutube. Hosts are matched label by label; Instagram and Facebook require an access token and fail with `MissingToken` without one.
- JSON answers of oEmbed, Wikipedia and Rutube are parsed with `rasuvaeff/schema` (`Extract\Schemas`); `AdapterContext::diagnostics()` reports unreadable shapes without values.
- `PageInfo::$embedUrl`; `PreviewFailure::MissingToken`.
- Recorded adapter corpus in `tests/fixtures/adapters/`, `bin/record-preview-corpus`, env-gated live integration suite.
