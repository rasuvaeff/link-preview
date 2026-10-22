# Examples

Run examples from the package root after installing dependencies. None of them
needs the network (except `live.php`, which skips itself by default):

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/parse_html.php
```

| Script | Shows | Needs server? |
|---|---|---|
| `parse_html.php` | Redirect, merge and policy refusals against an in-memory PSR-18 client | No |
| `url_policy.php` | What `UrlPolicy` accepts and rejects (SSRF edge cases, dev allowlist) | No |
| `redirects.php` | A redirect chain refused on the second hop, with the requests actually sent | No |
| `limits_merge.php` | Source priority, custom order and `Limits` on an inline document | No |
| `live.php` | A real fetch with Guzzle, only when `LINK_PREVIEW_URL` is set | Yes (optional) |
