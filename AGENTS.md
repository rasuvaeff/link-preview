# AGENTS.md — link-preview

Guidance for AI agents working on this package. Read before changing code.

## What this is

PSR-18 link preview: `Fetch\Fetcher` (own redirect loop, `UrlPolicy` on every
hop, size and content-type limits), `Html\PageDocument`, OpenGraph / Twitter
Cards / HTML-fallback extractors, `PageInfo`, `Limits` and the
`PreviewProvider` facade, oEmbed / JSON-LD extractors (`ProcessorJsonLdReader` over `rasuvaeff/json-ld`) and eleven site adapters
(`Adapter/`). Everything returns `Rasuvaeff\Result\Result` with an
enum failure. Namespace `Rasuvaeff\LinkPreview`.

The agent skill ships in `resources/skills/rasuvaeff-link-preview/SKILL.md`
(distributed via `llm/skills`, `extra.skills` in `composer.json`); keep it in
step with the public API.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **UrlPolicy is the SSRF boundary.** Adapters reach the network only through `AdapterContext`; hosts are matched by labels (`HostMatcher`), tokens are headers to the API host only and never in URLs, failures or docs (`'<token>'`). Every URL that is fetched - first, redirect target - goes through `UrlPolicy::check()` before a request is made; never fetch around `Fetcher`, never weaken a range without a test, and never claim DNS-rebinding protection (pure PSR-18 cannot pin).
4. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
make image   # once: composer:2 + ext-intl + pcov -> link-preview-php:local
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

`composer.lock` is gitignored (library).
`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## Invariants & gotchas

- `ext-intl` is required (IDN hosts); the stock `composer:2` image lacks it, so use `make image`.
- `rasuvaeff/json-ld` is a runtime dependency (the JSON-LD reader), from Packagist. The processor gets the schema.org context from `SchemaOrgContextLoader` and nothing else: never give it a loader that touches the network.
- JSON answers are parsed with `Extract\Schemas` (`rasuvaeff/schema`, which brings `property-testing-core` into runtime - accepted by the owner); never echo input values into diagnostics.
- Adapter fixtures in `tests/fixtures/adapters/` are written from documented formats, not recorded; `bin/record-preview-corpus --check` must stay green and `--record` needs `--live`.
- Mutation gate `minMsi` is 90; PageCorpusTest fixtures in `tests/fixtures/` record page intent, edit them deliberately.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.
- **Validation patterns anchor with `\z`, never `$`.** In PCRE `$` also matches
  immediately before a trailing newline, so `/^…$/` silently accepts one `\n` —
  the value the whitelist was supposed to reject then travels on as if it were
  clean. `/D` does the same job. Cover it with a data-provider case carrying
  `"value\n"`, or the regression comes back unnoticed.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment
  (e.g. `actions/checkout@<sha> # v4`). Never revert to floating `@vN` tags.
  Updates go through Dependabot, which bumps the SHA and preserves the comment.
  Workflows also carry `permissions: { contents: read }` at workflow level and
  `persist-credentials: false` on every `actions/checkout` step. Verify with
  `zizmor --persona=auditor .github/` — must report no `unpinned-uses`,
  `excessive-permissions`, or `artipacked` findings.

## When you finish

- Update `README.md` (and `examples/` if usage changed); update `CHANGELOG.md`
  when releasing.
- Re-run `composer build`; if the change affects the public API or release
  process, also run `make release-check`. Paste the output.
