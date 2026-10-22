# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

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
