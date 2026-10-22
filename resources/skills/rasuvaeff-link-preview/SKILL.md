---
name: rasuvaeff-link-preview
description: >-
  Link previews for PHP with rasuvaeff/link-preview - PreviewProvider facade,
  Fetcher (PSR-18 with its own redirect loop), UrlPolicy (SSRF whitelist),
  PageInfo, Limits, UserAgentMap, PageDocument, the oEmbed, JSON-LD,
  OpenGraph, Twitter Cards and HTML-fallback extractors and eleven site
  adapters (YouTube, TikTok, Twitter/X, Twitch, Wikipedia, GitHub,
  Instagram, Facebook, Telegram, VK, Rutube), all returning Result with enum
  failures. Use when writing, reviewing or debugging code that fetches a
  user-supplied URL and extracts title/description/image metadata in a
  project that has this package installed.
---

# rasuvaeff/link-preview

Fetch a URL through an injected PSR-18 client and read OpenGraph, Twitter
Cards and plain-HTML metadata. Namespace `Rasuvaeff\LinkPreview\`.

## Safety rules - verify these on every change

1. **The URL is attacker-controlled and the fetch runs inside your network.**
   `UrlPolicy` is checked on the first URL and on every redirect target,
   before the request is sent. Never bypass `Fetcher` with a direct client
   call, and never follow a URL from a page (images, canonical links) without
   `UrlPolicy::check()`.

2. **The package never touches the network on its own.** Only the injected
   PSR-18 client does. That client MUST NOT follow redirects
   (Guzzle `allow_redirects => false`, Symfony `max_redirects => 0`);
   a redirect followed inside the client skips the policy.

3. **No DNS pinning in pure PSR-18.** `UrlPolicy` never resolves names, so a
   name pointing at an internal address passes and rebinding is possible.
   Mitigate in the transport: a trusted resolving egress proxy that refuses
   internal destinations, or a client-level connection hook applying the same
   ranges to the connected address. Say so in reviews; do not claim the
   package prevents it.

4. **Bound everything.** `maxBodyBytes`, `maxRedirects`, `chainBudgetSeconds`
   (checked between hops only). PSR-18 has no timeout: set connect/read
   timeouts on the client. Size is checked on the body the client returns, so
   also limit it on the client.

5. **URL schemes are http/https only**, everywhere: fetch targets, redirects,
   `og:image`, favicons. `javascript:`, `data:`, `file:`, credentials in URLs
   are dropped. `allowedNetworks` (private ranges) is for development stands
   only; never enable it for user-supplied URLs in production.

6. **Page text is foreign input.** Title, description and names are plain text
   from a stranger: escape them for the output context (HTML, JSON, Markdown),
   never render as markup.

7. **Adapters are matched by host labels, never by substring** (`str_contains`
   would take `youtube.com.evil.example` for YouTube). A new adapter returns
   only data, reaches the network through `AdapterContext` (so `UrlPolicy`
   applies) and never builds an endpoint from page content without the policy.
   oEmbed endpoints named by a page, and JSON-LD `@context` values, are
   untrusted: the endpoint goes through the same `Fetcher`, a remote
   `@context` is never loaded.

8. **Tokens (Instagram/Facebook required, Twitter optional)** are constructor
   arguments marked `#[\SensitiveParameter]`, sent as an `Authorization`
   header to the API host only, never placed in a URL, a failure, an
   exception, a log or a README example (use `'<token>'`). Without a token the
   adapter answers `AdapterFailure::MissingToken` and makes no request.

9. **Do not log or display secrets.** URLs may carry tokens in the query
   string; redact query and fragment before logging, metrics or error reports.
   Never put credentials in `UserAgentMap` values or URLs.

## Canonical usage

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\Fetch\UrlPolicy;
use Rasuvaeff\LinkPreview\Fetch\UserAgentMap;
use Rasuvaeff\LinkPreview\Limits;
use Rasuvaeff\LinkPreview\PreviewProvider;

$provider = new PreviewProvider(
    fetcher: new Fetcher(
        client: new Client(['allow_redirects' => false, 'http_errors' => false, 'timeout' => 5.0]),
        requestFactory: new HttpFactory(),
        policy: new UrlPolicy(),
        userAgents: new UserAgentMap(byHost: ['t.me' => 'TelegramBot (like TwitterBot)']),
        maxRedirects: 5,
        maxBodyBytes: 2_097_152,
        chainBudgetSeconds: 10.0,
    ),
    limits: new Limits(titleLength: 120, descriptionLength: 300, httpsImagesOnly: true),
);

$result = $provider->preview(url: $userUrl);   // Result<PageInfo, PreviewFailure>

if ($result->isErr()) {
    // PolicyRejected, Network, TooManyRedirects, InvalidRedirect,
    // BudgetExceeded, HttpStatus, NotHtml, TooLarge, EmptyPage
    return;
}

$info = $result->unwrap();   // url, title, description, image, favicon, siteName,
                             // language, authorName, publishedTime, type
```

No network (tests, stored HTML): `$provider->fromHtml(html: $html, pageUrl: $url, charset: 'windows-1251')`.

## Pitfalls

- JSON answers are parsed through `Extract\Schemas` (`rasuvaeff/schema`); a wrong-typed field
  makes the answer unreadable (`InvalidResponse`), the reason (`$.title: ...`, no values) is in
  `AdapterContext::diagnostics()`. Extend the schema, don't add `isset` chains; in tests use
  `Schemas::oEmbed()->arbitrary()` for generated answers.

- Fields merge per field by priority: site adapter > oEmbed (discovery makes
  an extra request) > JSON-LD > OpenGraph > Twitter Cards > HTML. A custom
  `extractors:` list replaces the default; put the highest priority first.
  `new AdapterRegistry()` turns adapters off.
- A failed adapter does not fail the preview; the generic extractors still
  run. Telegram and VK need a `UserAgentMap` entry for their host.
- `Limits` drops an over-long or (with `httpsImagesOnly`) plain-http image
  after the merge; the next source is not consulted.
- `PageInfo::$url` is `og:url`, else `canonical`, else the final fetched URL.
- `UrlPolicy::check()` returns the normalised URL (ASCII host, no fragment,
  default port removed); fetch that, not the raw input.
- Integer/hex/octal IP spellings give `NonCanonicalIp`, IPv6-embedded IPv4
  (`::7f00:1`) is judged as the IPv4 inside.
- Tests: use PSR-18 doubles with scripted responses; no live HTTP in unit tests.

## Full API

The complete reference ships with the package: read
`vendor/rasuvaeff/link-preview/llms.txt` before guessing a method name.
