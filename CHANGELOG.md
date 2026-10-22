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
