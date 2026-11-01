# Examples

Run examples from the package root after installing dependencies. None of them
needs the network (except `live.php`, which skips itself by default):

```bash
docker run --rm -v "$PWD":/app -w /app link-preview-php:local php examples/parse_html.php   # make image
```

| Script | Shows | Needs server? |
|---|---|---|
| `parse_html.php` | Redirect, merge and policy refusals against an in-memory PSR-18 client | No |
| `url_policy.php` | What `UrlPolicy` accepts and rejects (SSRF edge cases, dev allowlist) | No |
| `redirects.php` | A redirect chain refused on the second hop, with the requests actually sent | No |
| `limits_merge.php` | Source priority, custom order and `Limits` on an inline document | No |
| `failures.php` | `PreviewFailure`: reason, HTTP status, `Retry-After`, `isTransient()` for 404, 503, 429, network, policy | No |
| `cached_previewer.php` | A caching `LinkPreviewer` decorator storing `PageInfo::toArray()`, reading `fromArray()` | No |
| `media.php` | `Fetcher::fetchMedia()`: accepted types, size limit, the policy on a redirect | No |
| `adapters_oembed.php` | YouTube, TikTok, Twitter/X, Twitch from recorded oEmbed answers | No |
| `adapters_pages.php` | GitHub, Telegram, VK (page tags) and Wikipedia, Rutube (APIs) from recorded answers | No |
| `adapters_tokens.php` | Instagram: the missing-token failure, then a configured token | No |
| `with_pinned_client.php` | The Fetcher over `rasuvaeff/pinned-http-client` (DNS pinning) with `UrlPolicy::allowsAddress()` as its address policy; needs the package | No |
| `live.php` | A real fetch (`PinnedClient` when installed, Guzzle otherwise), only when `LINK_PREVIEW_URL` is set | Yes (optional) |
