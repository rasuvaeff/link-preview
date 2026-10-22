# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

- Documented and exampled the two things the package leaves to the application: `examples/pinned_client.php` (a PSR-18 client over Guzzle that pins the connection to the addresses checked by `UrlPolicy`, tested against `localhost` and the metadata address) and `examples/json_ld_processor.php` (a `JsonLdReader` on top of `rasuvaeff/json-ld`; same previews as the built-in reader on 14 real-page blocks, and it also reads inline contexts and aliases).
- `UserAgentMap::unfurler()` (and `UserAgentMap::UNFURLER`): the unfurler name `Twitterbot/1.0` for every host with per-host overrides; checked live that VK and championat.com refuse the default name.
- `UrlPolicy`: http(s)-only, port, public-IP and IDN policy with CIDR allowlist, no DNS resolution.
- `Fetcher`: PSR-18 fetching with its own redirect loop (policy on every hop), redirect limit, between-hop budget, response size and content-type limits, per-host `UserAgentMap`.
- `PageDocument`: read-only DOM view with charset detection.
- `OpenGraphExtractor`, `TwitterCardsExtractor`, `HtmlFallbackExtractor` returning `Result`.
- `PageInfo`, `Limits`, `PreviewProvider` facade with fixed-priority merge.
- `OEmbedExtractor`: oEmbed discovery; the endpoint a page names is fetched under the same `UrlPolicy` and redirect checks as the page.
- `JsonLdExtractor` with a replaceable `JsonLdReader` (built-in `SimpleJsonLdReader`, schema.org contexts only, nothing remote is ever loaded).
- `Fetcher::fetchJson()` and `Fetcher::check()`; custom request headers go to the first host only.
- Site adapters behind `Adapter` / `AdapterRegistry`: YouTube, TikTok, Twitter/X, Twitch, Wikipedia, GitHub, Instagram, Facebook, Telegram, VK, Rutube. Hosts are matched label by label; Instagram and Facebook require an access token and fail with `MissingToken` without one.
- JSON answers of oEmbed, Wikipedia and Rutube are parsed with `rasuvaeff/schema` (`Extract\Schemas`); `AdapterContext::diagnostics()` reports unreadable shapes without values.
- `PageInfo::$embedUrl`; `PreviewFailure::MissingToken`.
- Recorded adapter corpus in `tests/fixtures/adapters/`, `bin/record-preview-corpus`, env-gated live integration suite.
