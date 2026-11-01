---
name: rasuvaeff-link-preview
description: >-
  Link previews for PHP with rasuvaeff/link-preview - PreviewProvider facade,
  Fetcher (PSR-18 with its own redirect loop), UrlPolicy (SSRF whitelist),
  PageInfo, Limits (clean text), UserAgentMap, PageDocument, the oEmbed,
  JSON-LD, OpenGraph, Twitter Cards and HTML-fallback extractors and eleven
  site adapters (YouTube, TikTok, Twitter/X, Twitch, Wikipedia, GitHub,
  Instagram, Facebook, Telegram, VK, Rutube) behind AdapterRegistry, the
  LinkPreviewer interface, all returning Result with failure value objects
  (reason, HTTP status, Retry-After, isTransient). Use when writing, reviewing or debugging code that fetches a
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
   Mitigate in the transport: `rasuvaeff/pinned-http-client` as the Fetcher's
   PSR-18 client with `UrlPolicy::allowsAddress()` as its `AddressPolicy`
   (resolves, checks every address, pins the connection; see
   `examples/with_pinned_client.php`) - never wrapped in its
   `RedirectFollower` - or a trusted resolving egress proxy that refuses
   internal destinations. Say so in reviews; do not claim this package
   prevents it on its own.

4. **Bound everything.** `maxBodyBytes`, `maxRedirects`, `chainBudgetSeconds`
   (checked between hops only). PSR-18 has no timeout: set connect/read
   timeouts on the client. Size is checked on the body the client returns, so
   also limit it on the client.

5. **URL schemes are http/https only**, everywhere: fetch targets, redirects,
   `og:image`, favicons. `javascript:`, `data:`, `file:`, credentials in URLs
   are dropped. `allowedNetworks` (private ranges) is for development stands
   only; never enable it for user-supplied URLs in production.

6. **Page text is foreign input.** Title, description and names come out as
   clean plain text (entities decoded, tags removed), but they are still a
   stranger's words: escape them for the output context (HTML, JSON,
   Markdown), never render as markup. Link the card to `PageInfo::$url` (the
   fetched URL), not to `canonicalUrl` (what the page claims, any host).

7. **Adapters are matched by host labels, never by substring** (`str_contains`
   would take `youtube.com.evil.example` for YouTube). A new adapter returns
   only data, reaches the network through `AdapterContext` (so `UrlPolicy`
   applies) and never builds an endpoint from page content without the policy.
   oEmbed endpoints named by a page, and JSON-LD `@context` values, are
   untrusted: the endpoint goes through the same `Fetcher`, a remote
   `@context` is never loaded (JSON-LD is expanded by `rasuvaeff/json-ld`
   through `ProcessorJsonLdReader`; the schema.org context comes from memory).

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
use GuzzleHttp\Psr7\HttpFactory;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\Fetch\UrlPolicy;
use Rasuvaeff\LinkPreview\Fetch\UserAgentMap;
use Rasuvaeff\LinkPreview\Limits;
use Rasuvaeff\LinkPreview\LinkPreviewer;
use Rasuvaeff\LinkPreview\PreviewProvider;
use Rasuvaeff\PinnedHttpClient\AddressPolicy;
use Rasuvaeff\PinnedHttpClient\PinnedClient;

$policy = new UrlPolicy();

$provider = new PreviewProvider(   // type-hint consumers against LinkPreviewer
    fetcher: new Fetcher(
        client: new PinnedClient(
            policy: new class ($policy) implements AddressPolicy {
                public function __construct(private UrlPolicy $policy) {}
                public function allows(string $address): bool { return $this->policy->allowsAddress($address); }
            },
            timeoutSeconds: 5.0,
            connectTimeoutSeconds: 3.0,
            maxBodyBytes: 2_097_153,
        ),
        requestFactory: new HttpFactory(),
        policy: $policy,
        userAgents: UserAgentMap::unfurler(['t.me' => 'TelegramBot (like TwitterBot)']),
        maxRedirects: 5,
        maxBodyBytes: 2_097_152,
        chainBudgetSeconds: 10.0,
    ),
    limits: new Limits(titleLength: 120, descriptionLength: 300, httpsImagesOnly: true),
);

$result = $provider->preview(url: $userUrl);   // Result<PageInfo, PreviewFailure>

if ($result->isErr()) {
    $failure = $result->error();               // reason, status, retryAfterSeconds
    // retry only $failure->isTransient() (network, budget, 408/425/429/5xx), after retryAfterSeconds if set
    return;
}

$info = $result->unwrap();   // url (fetched), canonicalUrl (claimed), title, description, image,
                             // imageWidth, imageHeight, favicon, siteName, providerName,
                             // language, authorName, publishedTime, type, embedUrl
$stored = $info->toArray();  // PageInfo::fromArray($stored) reads it back
```

No network for the page (tests, stored HTML): `$provider->fromHtml(html: $html, pageUrl: $url, charset: 'windows-1251')`;
a `pageUrl` the policy refuses is `PolicyRejected`. Images: `$fetcher->fetchMedia(url: $info->image, acceptedTypes: ['image/*'], maxBytes: ...)`.

## Pitfalls

- JSON answers are shape-checked internally (`rasuvaeff/schema` is an implementation detail, not
  API); a wrong-typed field makes the answer unreadable (`InvalidResponse`), the reason
  (`$.title: ...`, no values) is in `AdapterContext::diagnostics()`.
- Built-in adapters are internal classes: get them from `AdapterRegistry::standard(...)` and put
  custom `Adapter`s in front with `->with(...)` (first matching domain wins).
- Failures are value objects, not enums: compare `$result->error()?->reason` with
  `PreviewFailureReason::...` (`FetchFailureReason::...` for the Fetcher).

- Fields merge per field by priority: site adapter > oEmbed (discovery makes
  an extra request) > JSON-LD > OpenGraph > Twitter Cards > HTML. A custom
  `extractors:` list replaces the default; put the highest priority first.
  `new AdapterRegistry()` turns adapters off.
- A failed adapter does not fail the preview; the generic extractors still
  run. VK answers the default name with a redirect loop and championat.com with a login stub: use `UserAgentMap::unfurler()` (`Twitterbot/1.0`) when reading arbitrary sites.
- `Limits` cleans text before clipping (entities decoded repeatedly, tags removed); do not
  decode or strip again. It drops an over-long or (with `httpsImagesOnly`) plain-http image
  with its dimensions after the merge; the next source is not consulted.
- `PageInfo::$url` is the final fetched URL; `canonicalUrl` is `og:url` / canonical / JSON-LD
  `url` / adapter - foreign-controlled. `siteName` falls back to the host only with
  `hostAsSiteName: true`.
- `UrlPolicy::check()` returns the normalised URL (ASCII host, no fragment,
  default port removed); fetch that, not the raw input.
- Integer/hex/octal IP spellings give `NonCanonicalIp`, IPv6-embedded IPv4
  (`::7f00:1`) is judged as the IPv4 inside.
- Tests: use PSR-18 doubles with scripted responses; no live HTTP in unit tests.

## Full API

The complete reference ships with the package: read
`vendor/rasuvaeff/link-preview/llms.txt` before guessing a method name.
