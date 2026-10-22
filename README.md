# rasuvaeff/{{PACKAGE_KEBAB}}

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/{{PACKAGE_KEBAB}}/v)](https://packagist.org/packages/rasuvaeff/{{PACKAGE_KEBAB}})
[![Total Downloads](https://poser.pugx.org/rasuvaeff/{{PACKAGE_KEBAB}}/downloads)](https://packagist.org/packages/rasuvaeff/{{PACKAGE_KEBAB}})
[![Build](https://github.com/rasuvaeff/{{PACKAGE_KEBAB}}/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/{{PACKAGE_KEBAB}}/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/{{PACKAGE_KEBAB}}/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/{{PACKAGE_KEBAB}}/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/{{PACKAGE_KEBAB}}/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/{{PACKAGE_KEBAB}}/php)](https://packagist.org/packages/rasuvaeff/{{PACKAGE_KEBAB}})
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

{{DESCRIPTION}}

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.

## Requirements

- PHP 8.3+
<!-- Add runtime dependencies here, e.g.:
- `yiisoft/db` ^2.0
-->

## Installation

```bash
composer require rasuvaeff/{{PACKAGE_KEBAB}}
```

## Usage

<!-- Add usage examples here. For each public class, show:
- How to create/construct
- Key methods with examples
- Table of public API
-->

```php
use Rasuvaeff\{{PACKAGE_PASCAL}}\SomeClass;

$object = new SomeClass(
    param: 'value',
);
```

### Public API

| Class | Description |
|---|---|
| `SomeClass` | <!-- description --> |

## Security

<!-- Describe what is safe and what requires caution.
For SQL/query packages:
- User values are bound as parameters (safe)
- Identifiers (table/column names) must be trusted/hard-coded
- Raw SQL only in trusted context
-->

## Examples

See [examples/](examples/) for runnable scripts.
Examples are expected to execute without fatal errors and stay aligned with the
documented public API.

| Script | Shows | Needs server? |
|---|---|---|
| <!-- script --> | <!-- description --> | <!-- yes/no --> |

## Development

No PHP/Composer on the host — run in Docker via the `composer:2` image:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## License

[BSD-3-Clause](LICENSE.md)
