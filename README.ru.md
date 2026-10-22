# rasuvaeff/link-preview

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/link-preview/v)](https://packagist.org/packages/rasuvaeff/link-preview)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/link-preview/downloads)](https://packagist.org/packages/rasuvaeff/link-preview)
[![Build](https://github.com/rasuvaeff/link-preview/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/link-preview/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/link-preview/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/link-preview/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/link-preview/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/link-preview/php)](https://packagist.org/packages/rasuvaeff/link-preview)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

Превью ссылок для PHP: загрузка пользовательского URL через любой PSR-18 клиент с SSRF-безопасным циклом редиректов и чтение заголовка, описания, картинки, иконки и прочего из OpenGraph, Twitter Cards и обычного HTML.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.

> Проекты с Composer-плагином [llm/skills](https://github.com/roxblnfk/skills) получают навык агента этого пакета в `.agents/skills/` автоматически при установке.

English version: [README.md](README.md).

## Требования

- PHP 8.3-8.5 (8.6 проверяется в CI как экспериментальная) с `dom`, `libxml`, `mbstring`, `intl`, `ctype`, `filter`
- `psr/http-client`, `psr/http-factory`, `psr/http-message`
- `rasuvaeff/result`
- PSR-18 клиент и PSR-17 фабрика запросов на ваш выбор (Guzzle, Symfony HttpClient + Nyholm PSR-7, ...)

## Установка

```bash
composer require rasuvaeff/link-preview
composer require guzzlehttp/guzzle   # или любой PSR-18 клиент
```

## Использование

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\PreviewProvider;

$provider = new PreviewProvider(
    fetcher: new Fetcher(
        client: new Client(['allow_redirects' => false, 'http_errors' => false, 'timeout' => 5.0]),
        requestFactory: new HttpFactory(),
    ),
);

$result = $provider->preview(url: 'https://example.com/article');

if ($result->isOk()) {
    $info = $result->unwrap();   // PageInfo
    echo $info->title, ' - ', $info->image;
} else {
    echo $result->error()?->value; // PreviewFailure: policy_rejected, network, not_html, ...
}
```

Конвейер: `Fetcher` (политика на каждом хопе, лимиты размера и content-type) -> `PageDocument` -> экстракторы -> слияние по фиксированному приоритету -> `Limits` -> `PageInfo`. Каждый этап возвращает `Result` с enum-отказом; на плохой ответ сети ничего не бросается.

### Источники и приоритет

Каждое поле берётся из первого источника, где оно есть: **OpenGraph**, затем **Twitter Cards**, затем **обычный HTML**. Свой порядок задаётся списком `extractors:` (сначала самый приоритетный).

| Поле | OpenGraph | Twitter Cards | HTML fallback |
|---|---|---|---|
| `title` | `og:title` | `twitter:title` | `<title>` |
| `description` | `og:description` | `twitter:description` | `<meta name="description">` |
| `image` | `og:image:secure_url`, `og:image`, `og:image:url` | `twitter:image`, `twitter:image:src` | первая `<img>`, не объявленная меньше 100 px |
| `favicon` | | | `<link rel="icon">`, затем `apple-touch-icon` |
| `url` | `og:url` | | `<link rel="canonical">`; иначе итоговый загруженный URL |
| `siteName` | `og:site_name` | | |
| `language` | `og:locale` (`ru_RU` -> `ru-RU`) | | `lang` у `<html>` |
| `authorName` | `article:author` (только имена, ссылки игнорируются) | | `<meta name="author">` |
| `publishedTime` | `article:published_time` | | |
| `type` | `og:type` | | |

Все URL абсолютные (`<base href>` учитывается) и только `http`/`https`: `javascript:`, `data:`, `file:` и URL с учётными данными отбрасываются, берётся следующий кандидат.

### UrlPolicy

```php
use Rasuvaeff\LinkPreview\Fetch\UrlPolicy;

$policy = new UrlPolicy();
$policy->check(url: 'HTTPS://Example.COM:443/a#frag')->unwrap(); // 'https://example.com/a'
$policy->check(url: 'http://[::7f00:1]/')->error();               // UrlRejection::PrivateAddress

// стенд разработки: разрешить одну сеть (имена localhost считаются 127.0.0.1)
$dev = new UrlPolicy(allowedNetworks: ['10.20.0.0/16'], allowedPorts: [80, 443, 8080]);
```

| Отклоняется | Причина |
|---|---|
| схема не http/https, `javascript:`, `file:`, `//host` | `Scheme` |
| `user:pass@host`, `a@127.0.0.1` | `Credentials` |
| порт вне `allowedPorts` (по умолчанию 80, 443) | `Port` |
| `localhost`, `*.localhost`, `localhost.` | `PrivateAddress` |
| loopback, приватные, link-local, shared (CGNAT), документационные, benchmark, multicast, зарезервированные диапазоны - IPv4 и IPv6 | `PrivateAddress` |
| IPv6 с вложенным IPv4: `::7f00:1`, `::ffff:127.0.0.1`, `64:ff9b::7f00:1`, `2002:7f00:1::` | `PrivateAddress` (оценивается вложенный IPv4) |
| `2130706433`, `0x7f000001`, `0177.0.0.1`, `127.1`, любой хост с числовой последней меткой | `NonCanonicalIp` |
| пробелы, управляющие символы, обратный слэш, `exa%6Dple.com`, пустые метки, неверный IDN | `Malformed` / `InvalidHost` |

Интернационализированные хосты приводятся к ASCII (`münchen.de` -> `xn--mnchen-3ya.de`). При успехе `check()` возвращает нормализованный URL.

### Fetcher

```php
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\Fetch\UserAgentMap;

$fetcher = new Fetcher(
    client: $psr18Client,
    requestFactory: $psr17Factory,
    policy: new UrlPolicy(),
    userAgents: new UserAgentMap(byHost: ['t.me' => 'TelegramBot (like TwitterBot)']),
    maxRedirects: 5,
    maxBodyBytes: 2_097_152,
    chainBudgetSeconds: 10.0,
);

$page = $fetcher->fetch(url: 'https://example.com/')->unwrap(); // FetchedPage: url, body, contentType, charset, redirects
```

Редиректы ведёт `Fetcher`, а не клиент, и `UrlPolicy` оценивает каждую цель **до** того, как к ней уйдёт запрос. Отказы (`FetchFailure`): `PolicyRejected`, `Network`, `TooManyRedirects`, `InvalidRedirect`, `BudgetExceeded`, `HttpStatus`, `NotHtml`, `TooLarge`. Принимаются только `text/html` и `application/xhtml+xml`. `UserAgentMap` совпадает с хостом и его поддоменами.

### Limits

```php
use Rasuvaeff\LinkPreview\Limits;

new PreviewProvider(fetcher: $fetcher, limits: new Limits(titleLength: 120, descriptionLength: 300, httpsImagesOnly: true));
```

Из текста вычищаются невалидный UTF-8 и управляющие символы, серии пробелов схлопываются в один, слишком длинное обрезается по символам с `…` на конце, входящим в лимит (по умолчанию: заголовок 200, описание 500, сайт 100, автор 100). Повторное применение `Limits` ничего не меняет. Картинка или иконка длиннее `maxUrlLength` (2048) отбрасывается; с `httpsImagesOnly` отбрасываются и http-адреса (после слияния следующий источник *не* опрашивается).

### Публичный API

| Класс | Описание |
|---|---|
| `PreviewProvider` | Фасад: `preview(url)` и `fromHtml(html, pageUrl, charset)` -> `Result<PageInfo, PreviewFailure>` |
| `PageInfo` | Результат (value object), `toArray()` |
| `Limits` | Ограничения длины и схемы |
| `PreviewFailure` | Enum отказов, включая `EmptyPage` |
| `Fetch\Fetcher`, `FetchedPage`, `FetchFailure` | Загрузка через PSR-18 с циклом редиректов |
| `Fetch\UrlPolicy`, `UrlRejection` | SSRF-политика |
| `Fetch\UserAgentMap` | Хост -> `User-Agent` |
| `Html\PageDocument` | DOM только для чтения: `title()`, `meta()`, `links()`, `images()`, `language()`, `baseHref()` |
| `Extract\Extractor`, `OpenGraphExtractor`, `TwitterCardsExtractor`, `HtmlFallbackExtractor` | Источники; возвращают `Result<Metadata, ExtractFailure>` |
| `Extract\Metadata` | Что знает один источник (все поля необязательны) |

## Безопасность

Превью ссылки загружает URL, выбранный пользователем, изнутри вашей сети. Что делает пакет:

- Политика-белый список на первый URL и на каждую цель редиректа, до отправки запроса.
- Альтернативные записи IP, IPv4 внутри IPv6, `localhost.`, трюки с userinfo и управляющие символы отклоняются или классифицируются по реальному адресу.
- HTML разбирается libxml (`LIBXML_NONET`) и не исполняется; внешние сущности не загружаются; скрипты - просто текст.
- Размер ответа ограничен; читаются только HTML-типы содержимого.
- Любой URL со страницы - абсолютный http(s) без учётных данных.

Чего пакет сделать не может, потому что PSR-18 не даёт для этого средств:

- **Нет DNS-пиннинга.** Политика не резолвит имена; имя, указывающее на внутренний адрес, пройдёт, а атакующий может переключить имя между проверкой и соединением. Закрывайте это на транспорте: пропускайте трафик клиента через доверенный резолвящий прокси (egress-прокси, отказывающий внутренним адресам, например Smokescreen) или дайте клиенту собственный хук резолва/соединения, применяющий те же диапазоны к адресу соединения.
- **Клиент не должен сам следовать редиректам** (`allow_redirects => false` у Guzzle, `max_redirects => 0` у Symfony). Редирект, выполненный внутри клиента, обходит политику.
- **Таймауты - дело клиента.** В PSR-18 их нет; задайте connect- и read-таймауты в клиенте. `chainBudgetSeconds` проверяется только между хопами и не прервёт запрос в полёте.
- **Размер тела** проверяется на теле, которое вернул клиент; буферизующий клиент его уже прочитал. Лимит нужен и в клиенте.
- Пакет не загружает картинки. Если вы их проксируете или скачиваете, пропускайте их через `UrlPolicy` тоже.
- Вывод - простой текст. Экранируйте его под контекст, в который выводите.

## Примеры

Исполняемые скрипты - в [examples/](examples/).
Примеры должны выполняться без фатальных ошибок и соответствовать
документированному публичному API.

| Скрипт | Что показывает | Нужен сервер? |
|---|---|---|
| `parse_html.php` | Редирект, слияние и отказы политики на PSR-18 клиенте в памяти | Нет |
| `url_policy.php` | Что `UrlPolicy` принимает и отклоняет, allowlist для разработки | Нет |
| `redirects.php` | Цепочка редиректов, отклонённая на втором хопе | Нет |
| `limits_merge.php` | Приоритет источников, свой порядок и `Limits` | Нет |
| `live.php` | Реальная загрузка через Guzzle, только если задан `LINK_PREVIEW_URL` | Да (необязательно) |

## Разработка

PHP/Composer на хосте нет - всё через Docker. В образе `composer:2` нет ни `ext-intl` (IDN-хосты, стабы Psalm), ни `pcov`, поэтому `make image` собирает локальный образ (`link-preview-php:local`) с обоими:

```bash
make image
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

## Лицензия

[BSD-3-Clause](LICENSE.md)
