# rasuvaeff/link-preview

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/link-preview/v)](https://packagist.org/packages/rasuvaeff/link-preview)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/link-preview/downloads)](https://packagist.org/packages/rasuvaeff/link-preview)
[![Build](https://github.com/rasuvaeff/link-preview/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/link-preview/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/link-preview/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/link-preview/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/link-preview/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/link-preview/php)](https://packagist.org/packages/rasuvaeff/link-preview)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

Превью ссылок для PHP: загрузка пользовательского URL через любой PSR-18 клиент с SSRF-безопасным циклом редиректов и чтение заголовка, описания, картинки, иконки и прочего из oEmbed, JSON-LD, OpenGraph, Twitter Cards и обычного HTML - со встроенными адаптерами для одиннадцати сайтов.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.

> Проекты с Composer-плагином [llm/skills](https://github.com/roxblnfk/skills) получают навык агента этого пакета в `.agents/skills/` автоматически при установке.

English version: [README.md](README.md).

## Требования

- PHP 8.3-8.5 (8.6 проверяется в CI как экспериментальная) с `dom`, `libxml`, `mbstring`, `intl`, `ctype`, `filter`
- `psr/http-client`, `psr/http-factory`, `psr/http-message`
- `rasuvaeff/json-ld` (процессор JSON-LD 1.1 за источником JSON-LD), `rasuvaeff/result`, `rasuvaeff/schema` (внутри разбирает JSON-ответы oEmbed, Wikipedia и Rutube; тянет `rasuvaeff/property-testing-core` в runtime)
- PSR-18 клиент и PSR-17 фабрика запросов. Рекомендуемый клиент - [`rasuvaeff/pinned-http-client`](https://github.com/rasuvaeff/pinned-http-client) (DNS-пиннинг, приносит PSR-17 фабрику Guzzle); подойдёт и любой другой (Guzzle, Symfony HttpClient + Nyholm PSR-7, ...)

## Установка

```bash
composer require rasuvaeff/link-preview
composer require rasuvaeff/pinned-http-client   # рекомендуемый PSR-18 клиент: пиннит соединение к проверенным адресам
```

## Использование

```php
use GuzzleHttp\Psr7\HttpFactory;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\Fetch\UrlPolicy;
use Rasuvaeff\LinkPreview\Fetch\UserAgentMap;
use Rasuvaeff\LinkPreview\PreviewProvider;
use Rasuvaeff\PinnedHttpClient\AddressPolicy;
use Rasuvaeff\PinnedHttpClient\PinnedClient;

$policy = new UrlPolicy();

$provider = new PreviewProvider(
    fetcher: new Fetcher(
        client: new PinnedClient(
            // адреса, в которые разрешается хост, судятся по тем же правилам, что и URL
            policy: new class ($policy) implements AddressPolicy {
                public function __construct(private UrlPolicy $policy) {}

                public function allows(string $address): bool
                {
                    return $this->policy->allowsAddress($address);
                }
            },
            timeoutSeconds: 5.0,
            connectTimeoutSeconds: 3.0,
            maxBodyBytes: 2_097_153, // больше лимита Fetcher: слишком большая страница - это TooLarge от Fetcher
        ),
        requestFactory: new HttpFactory(),
        policy: $policy,
        userAgents: UserAgentMap::unfurler(),
    ),
);

$result = $provider->preview(url: 'https://example.com/article');

if ($result->isOk()) {
    $info = $result->unwrap();   // PageInfo
    echo $info->title, ' - ', $info->image;
} else {
    $failure = $result->error(); // PreviewFailure
    echo $failure?->reason->value;                          // policy_rejected, network, http_status, ...
    echo $failure?->isTransient() ? ' (повторить позже)' : ''; // см. «Отказы» ниже
}
```

Не оборачивайте `PinnedClient` в его `RedirectFollower`: `Fetcher` сам следует редиректам и проверяет каждый хоп. С обычным клиентом Guzzle передайте `new Client(['allow_redirects' => false, 'http_errors' => false, 'timeout' => 5.0])` - и прочитайте раздел «Безопасность» про DNS rebinding.

Конвейер: `UrlPolicy` -> адаптер сайта (YouTube, Wikipedia, ...) -> `Fetcher` (политика на каждом хопе, лимиты размера и content-type) -> `PageDocument` -> экстракторы -> слияние по фиксированному приоритету -> `Limits` (чистый текст, границы) -> `PageInfo`. Каждый этап возвращает `Result`; на плохой ввод от пользователя или сети ничего не бросается.

`PreviewProvider` реализует `LinkPreviewer` (`preview()`, `fromHtml()`). Зависьте от интерфейса, чтобы поставить перед ним кеш, rate limit или circuit breaker - пакет несёт один такой декоратор, `CachedPreviewProvider` (PSR-16, [`psr/simple-cache`](https://www.php-fig.org/psr/psr-16/) ^3.0, suggested - бэкенд любой):

```php
use Rasuvaeff\LinkPreview\CachedPreviewProvider;

$previewer = new CachedPreviewProvider($provider, $psr16Cache, ttlSeconds: 900); // null = дефолт бэкенда
```

Кешируются только успешные превью - в форме `PageInfo::toArray()`, читаются обратно через `PageInfo::fromArray()`. Неудачный fetch **не** кешируется: страница, которая сейчас недоступна, на следующем вызове получает новый шанс - кеш может только сократить ожидание, но не удлинить его. `fromHtml()` проходит насквозь без кеша (документ уже в руках). Ключ - хеш строки URL ровно в том виде, в каком её передали в `preview()` (префикс `rasuvaeff.link-preview.preview.`), поэтому два написания одной страницы - два ключа до истечения TTL; нормализуйте URL выше по стеку, если ваши вызывающие пишут разно. Мусорное значение в кеше (не тот тип или форма после сбоя бэкенда) - промах, а не ошибка, и перезаписывается следующим успешным превью. См. [`examples/cached_previewer.php`](examples/cached_previewer.php).

### PageInfo

| Поле | Что это |
|---|---|
| `url` | откуда снято превью: загруженный URL после редиректов в нормализованном политикой виде (для `fromHtml()` - нормализованный `pageUrl`). Карточка ссылается сюда |
| `canonicalUrl` | какой адрес заявляет страница или её сервис (`og:url`, `<link rel="canonical">`, JSON-LD `url`, адаптер). Страница пишет его сама и может назвать любой хост: используйте для дедупликации, но не как цель ссылки без своей проверки |
| `title`, `description`, `siteName`, `authorName`, `providerName` | чистый plain text (см. «Limits») |
| `image`, `imageWidth`, `imageHeight` | абсолютный http(s) URL и его заявленный размер в пикселях; размер берётся из того же источника, что и картинка, и равен `null` без неё |
| `favicon`, `embedUrl` | абсолютные http(s) URL (`embedUrl`: встраиваемый плеер) |
| `language`, `publishedTime`, `type` | тег BCP 47, `DateTimeImmutable`, тип из `og:type` / oEmbed / JSON-LD |

`providerName` - сервис за контентом: `provider_name` из oEmbed или адаптер сайта (`Wikipedia`, `GitHub`, ...). `toArray()` возвращает все пятнадцать ключей; `PageInfo::fromArray($array)` читает их обратно (`fromArray($info->toArray())->toArray() === $info->toArray()`), отсутствующие ключи считает `null` - массив, сохранённый версией 0.1, читается - и бросает `InvalidArgumentException` на неверный тип.

`siteName` - только то, что сказал источник: собственное имя сайта страницы, а если его нет - `providerName` из oEmbed или адаптера (всегда, с опцией ниже или без неё). Чтобы показывать хост, когда сайт никто не назвал, включите явно: `new PreviewProvider(fetcher: $fetcher, hostAsSiteName: true)` - хост `url` без `www.`, в ASCII-форме (`xn--mnchen-3ya.de`, а не Unicode-двойник). Опция выключена по умолчанию, чтобы сохраняемый или сравниваемый `siteName` никогда не был догадкой.

### Отказы

Неудавшееся превью - это `PreviewFailure`: `reason` (`PreviewFailureReason`), `status` и `retryAfterSeconds` (для ответа с HTTP-статусом) и `isTransient()`, который говорит, может ли тот же URL дать превью позже. `Fetcher` сообщает так же, через `FetchFailure` / `FetchFailureReason`.

| `reason` | Когда | `isTransient()` |
|---|---|---|
| `PolicyRejected` | URL, цель редиректа или `pageUrl` у `fromHtml()` отвергнуты `UrlPolicy` | нет |
| `Network` | клиент бросил `NetworkExceptionInterface` (соединение, DNS — включая `ResolutionFailed` у pinned-http-client —, таймаут) | **да** |
| `ClientRefused` | клиент бросил любой другой `ClientExceptionInterface` (пиннинг-клиент нашёл запрещённый адрес) | нет |
| `TooManyRedirects`, `InvalidRedirect` | цепочка редиректов слишком длинная или `Location` непригоден | нет |
| `BudgetExceeded` | `chainBudgetSeconds` исчерпан между хопами | **да** |
| `HttpStatus` | статус не 2xx и не редирект, которому можно следовать; он лежит в `status` | **да** для 408, 425, 429 и 5xx, иначе нет |
| `UnexpectedContentType` | ответ не HTML (или не JSON, или не запрошенные типы медиа) | нет |
| `TooLarge` | тело больше лимита (Fetcher, `fetchMedia()` или собственного лимита пиннинг-клиента) | нет |
| `EmptyBody` | только `fetchMedia()`: ответ 2xx с пустым телом | нет |
| `MissingToken` | адаптеру сайта нужен токен, который не задан, и сама страница недоступна | нет |
| `EmptyPage` | страница загружена, но ни один источник ничего в ней не нашёл | нет |

`retryAfterSeconds` - `Retry-After` сервера: delta-seconds как прислано, HTTP-дата отсчитывается от собственного заголовка `Date` ответа (без него HTTP-дата не читается - у пакета нет часов), не больше суток. Повторяйте временный отказ через `retryAfterSeconds`, если он есть, иначе по своему backoff; постоянный не повторяйте.

### Источники и приоритет

Каждое поле берётся из первого источника, в котором оно есть, в таком порядке: **адаптер сайта**, **oEmbed** (обнаруженный через `<link rel="alternate" type="application/json+oembed">`), **JSON-LD** (schema.org Article, VideoObject, WebPage, Organization), **OpenGraph**, **Twitter Cards**, **обычный HTML**. Чтобы изменить порядок, передайте свой список `extractors:` (высший приоритет первым, после адаптера). Текст чистится при слиянии, поэтому значение из одной разметки (`<b></b>`, `&nbsp;`) не заслоняет значение следующего источника; картинка и её размеры сливаются как одно поле.

Обнаружение oEmbed делает один дополнительный запрос: эндпоинт - адрес, выбранный страницей, поэтому он загружается тем же `Fetcher` - та же `UrlPolicy`, те же проверки редиректов - и никогда не достанет то, что не мог достать исходный URL. JSON-LD читает `ProcessorJsonLdReader` (за интерфейсом `JsonLdReader`, его можно заменить): блок разворачивается процессором JSON-LD 1.1 из [`rasuvaeff/json-ld`](https://github.com/rasuvaeff/json-ld), свойства schema.org сводятся обратно к коротким именам, которые читает экстрактор. См. «JSON-LD» ниже.

#### JSON-LD

`ProcessorJsonLdReader` разворачивает каждый блок `<script type="application/ld+json">` через `rasuvaeff/json-ld`, поэтому работают inline-контексты, алиасы (`"title": "headline"`) и compact IRI, вместе с `@graph`, списками, несколькими типами и ссылками `{"@id": ...}` между узлами одного блока. Из сети ничего не загружается: контекст `https://schema.org` (в любом написании: `http`, `www`, `/docs/jsonldcontext.json`) отдаётся из памяти вместе с префиксом `schema:`; любой другой удалённый контекст делает блок `UnsupportedContext`, и он пропускается. Тип или свойство вне schema.org отбрасываются, поэтому термин, переопределённый на чужой IRI (`{"headline": "http://evil.example/x"}`), не читается. Ввод глубже 32 уровней отвергается до процессора. Проверено на 14 блоках с реальных страниц (Wikipedia, YouTube, BBC, The New York Times, IMDb, Коммерсантъ, Чемпионат и другие).

Чтобы читать JSON-LD иначе, реализуйте `JsonLdReader` (`nodes(string $json): Result<list<array<string, mixed>>, JsonLdFailure>`: узлы schema.org с короткими именами свойств, без собственного I/O) и передайте `new JsonLdExtractor(new YourReader())` в `extractors:`.

| Поле | OpenGraph | Twitter Cards | HTML fallback |
|---|---|---|---|
| `title` | `og:title` | `twitter:title` | `<title>` |
| `description` | `og:description` | `twitter:description` | `<meta name="description">` |
| `image` | `og:image:secure_url`, `og:image`, `og:image:url` | `twitter:image`, `twitter:image:src` | первый `<img>`, не объявленный меньше 100 px |
| `imageWidth` / `imageHeight` | первые `og:image:width` / `og:image:height` | `twitter:image:width` / `twitter:image:height` | `width` / `height` этого `<img>` |
| `favicon` | | | `<link rel="icon">`, затем `apple-touch-icon` |
| `canonicalUrl` | `og:url` | | `<link rel="canonical">` |
| `siteName` | `og:site_name` | | |
| `language` | `og:locale` (`ru_RU` -> `ru-RU`) | | `lang` у `<html>` |
| `authorName` | `article:author` (только имена, ссылки игнорируются) | | `<meta name="author">` |
| `publishedTime` | `article:published_time` | | |
| `type` | `og:type` | | |

oEmbed даёт `title`, `author_name`, `provider_name` (как `providerName` и `siteName`), `thumbnail_url` с `thumbnail_width` / `thumbnail_height` (у `photo`: `url`, `width`, `height`), `<iframe src>` из `html` как `embedUrl` и `type`. JSON-LD даёт `width` / `height` объекта `ImageObject` (число, цифры или `QuantitativeValue`) вместе с его `url`. Сторона - положительное целое меньше 100 000; всё остальное (`"100%"`) неизвестно.

Все URL абсолютные (`<base href>` учитывается) и только `http`/`https`: `javascript:`, `data:`, `file:` и URL с учётными данными отбрасываются, пробуется следующий кандидат.

### UrlPolicy

```php
use Rasuvaeff\LinkPreview\Fetch\UrlPolicy;

$policy = new UrlPolicy();
$policy->check(url: 'HTTPS://Example.COM:443/a#frag')->unwrap(); // 'https://example.com/a'
$policy->check(url: 'http://[::7f00:1]/')->error();               // UrlRejection::PrivateAddress
$policy->allowsAddress('169.254.169.254');                         // false: те же диапазоны, для разрешённого адреса

// стенд разработки: разрешить одну сеть (имена localhost считаются 127.0.0.1)
$dev = new UrlPolicy(allowedNetworks: ['10.20.0.0/16'], allowedPorts: [80, 443, 8080]);
```

| Отвергается | Причина |
|---|---|
| схема кроме http/https, `javascript:`, `file:`, `//host` | `Scheme` |
| `user:pass@host`, `a@127.0.0.1` | `Credentials` |
| порт вне `allowedPorts` (по умолчанию 80, 443) | `Port` |
| `localhost`, `*.localhost`, `localhost.` | `PrivateAddress` |
| loopback, private, link-local, shared (CGNAT), documentation, benchmark, multicast, reserved - IPv4 и IPv6 | `PrivateAddress` |
| IPv6 с IPv4 внутри: `::7f00:1`, `::ffff:127.0.0.1`, `64:ff9b::7f00:1`, `2002:7f00:1::` | `PrivateAddress` (судится как вложенный IPv4) |
| `2130706433`, `0x7f000001`, `0177.0.0.1`, `127.1`, любой хост с числовой последней меткой | `NonCanonicalIp` |
| пробелы, управляющие символы, обратные слэши, `exa%6Dple.com`, пустые метки, неверный IDN | `Malformed` / `InvalidHost` |

Интернационализированные хосты становятся ASCII (`münchen.de` -> `xn--mnchen-3ya.de`). При успехе `check()` возвращает нормализованный URL.

`allowsAddress(string $address): bool` судит один разрешённый адрес - голый литерал IPv4 или IPv6, без скобок и порта - ровно так, как `check()` судит URL с этим IP в качестве хоста, включая `allowedNetworks`; всё прочее - `false`. Это `AddressPolicy` для `rasuvaeff/pinned-http-client`: мост - анонимный класс в три строки из «Использования».

### Fetcher

Многие сайты отдают метаданные только знакомым краулерам: проверено вживую, `vk.com` отвечает имени по умолчанию бесконечным циклом редиректов, а `championat.com` - заглушкой входа. `UserAgentMap::unfurler()` везде представляется `Twitterbot/1.0` (отдельные хосты всё ещё можно переопределить): передавайте его как `userAgents:`, когда читаете произвольные сайты.

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
$json = $fetcher->fetchJson(url: 'https://api.example.com/x');  // application/json или +json

// картинка превью: та же политика, проверки редиректов и User-Agent, свои типы и лимит размера
$media = $fetcher->fetchMedia(url: $info->image, acceptedTypes: ['image/*'], maxBytes: 5_242_880);
```

Редиректам следует `Fetcher`, а не клиент, и `UrlPolicy` судит каждую цель **до** любого запроса к ней. Отказ - это `FetchFailure` (`reason`, `status`, `retryAfterSeconds`, `isTransient()`; причины как в «Отказах», без `MissingToken` и `EmptyPage`, которые есть только у превью). `fetch()` принимает только `text/html` и `application/xhtml+xml`. `fetchMedia()` заменяет `maxBodyBytes` у Fetcher своим `maxBytes`, и собственный лимит клиента продолжает действовать: тело больше `maxBodyBytes` у `PinnedClient` - это тоже `TooLarge`. Если медиа бывают больше страниц, заведите для них `Fetcher` поверх `$pinned->withMaxBodyBytes($mediaMaxBytes)`. Пустое тело 2xx - это `EmptyBody`. Он принимает типы медиа (`image/png`) или семейства (`image/*`) в нижнем регистре и бросает `InvalidArgumentException` на пустой или кривой список или лимит меньше 1 - это ошибки программиста, а не ввод. `UserAgentMap` сопоставляет хост и его поддомены.

### Limits

```php
use Rasuvaeff\LinkPreview\Limits;

new PreviewProvider(fetcher: $fetcher, limits: new Limits(titleLength: 120, descriptionLength: 300, httpsImagesOnly: true));
```

Пакет отдаёт **чистый текст**. Для `title`, `description`, `siteName`, `authorName` и `providerName` `Limits`:

1. вычищает невалидный UTF-8;
2. декодирует HTML-сущности - повторно, так что двойное экранирование вроде `&amp;quot;` превращается в `"`;
3. убирает теги, комментарии и doctype, заменяя каждый пробелом (`Line one.<br>Line two.` -> `Line one. Line two.`); `<`, который не открывает тег (`a < b`, `I <3 PHP`), остаётся текстом;
4. схлопывает управляющие символы и серии пробелов в один пробел;
5. и только после этого обрезает слишком длинное по символам с завершающим `…`, который входит в лимит (по умолчанию: заголовок 200, описание 500, имя сайта и провайдера 100, автор 100).

Декодирование и снятие тегов повторяются, пока что-то меняется, но не больше четырёх раундов; у текста, экранированного глубже, оставшиеся `&` и `<` заменяются на полноширинные `＆` и `＜`, так что результат окончателен в любом случае. Двойное применение `Limits` равно одинарному, а в выводе нет ни сущности для декодирования, ни тега. Экранированная разметка - это разметка: заголовок `&lt;b&gt;bold&lt;/b&gt;` становится `bold`. Каждый шаг линеен по длине ввода.

Картинка, иконка или embed-URL длиннее `maxUrlLength` (2048) отбрасывается, картинка - вместе с размерами; с `httpsImagesOnly` отбрасываются и plain-http (следующий источник после слияния *не* опрашивается). Слишком длинный `canonicalUrl` тоже отбрасывается.

### Адаптеры сайтов

`PreviewProvider` выбирает адаптер по хосту URL (после того как политика URL его приняла) и сливает его ответ впереди всего остального. Хосты сопоставляются по меткам: `www.youtube.com` и `m.youtube.com` - это YouTube, `youtube.com.evil.example` и `notyoutube.com` - нет. Если адаптер не справился, общие экстракторы всё равно работают; если и страница недоступна, отсутствующий токен сообщается как `PreviewFailureReason::MissingToken`.

| Сайт | Механизм | Нужно |
|---|---|---|
| YouTube | oEmbed + embed URL из id видео | ничего |
| TikTok | oEmbed | ничего |
| Twitter / X | `publish.twitter.com/oembed`, текст поста как описание | токен по желанию |
| Twitch | oEmbed + URL плеера с `parent` | ничего (передайте `twitchParents:`, чтобы получить embed URL) |
| Wikipedia | REST summary API языка самой ссылки | ничего |
| GitHub | OpenGraph страницы, понимает gist | ничего |
| Instagram | Graph API `instagram_oembed` | **access token** |
| Facebook | Graph API `oembed_post` / `oembed_video` / `oembed_page` | **access token** |
| Telegram | OpenGraph + URL виджета | ничего (рекомендуется `UserAgentMap::unfurler()`) |
| VK | OpenGraph + плеер `video_ext.php` | ничего (**нужен** `UserAgentMap::unfurler()`: на имя по умолчанию VK отвечает циклом редиректов) |
| Rutube | JSON API видео | ничего |

Встроенные адаптеры - не публичные классы: их даёт `AdapterRegistry::standard()`. Свои адаптеры (реализуйте `Adapter`) ставятся перед ними через `with()`; выигрывает первый адаптер, чьи домены совпали с хостом, поэтому ваш адаптер для `youtube.com` заменяет встроенный.

```php
use Rasuvaeff\LinkPreview\Adapter\AdapterRegistry;

$provider = new PreviewProvider(
    fetcher: $fetcher,
    adapters: AdapterRegistry::standard(
        instagramToken: '<token>',
        facebookToken: '<token>',
        twitchParents: ['example.com'],
    )->with(new MyIntranetWikiAdapter()), // опрашивается первым; затем одиннадцать встроенных
);
```

Адаптер отвечает `Result<Metadata, AdapterFailure>` и выходит в сеть только через свой `AdapterContext` (`html()`, `json()` - это `Fetcher` под политикой URL). JSON-ответы oEmbed, Wikipedia и Rutube сверяются с их формой до чтения: поле неверного типа делает ответ нечитаемым (`AdapterFailure::InvalidResponse`), а `AdapterContext::diagnostics()` говорит где (`oEmbed $.title: ...`), не повторяя значение.

Токены - аргументы `standard()` (`#[\SensitiveParameter]`), передаются заголовком `Authorization: Bearer` только хосту API (редирект на другой хост их отбрасывает) и никогда не попадают в URL, отказ или исключение. Без токена Instagram и Facebook отвечают `AdapterFailure::MissingToken`, не делая запросов. `new AdapterRegistry()` выключает адаптеры.

Фикстуры тестов адаптеров лежат в `tests/fixtures/adapters/`: они написаны по документированным форматам ответов сервисов, а не записаны вживую. `bin/record-preview-corpus --check` заново разбирает каждую закоммиченную запись (без сети); `bin/record-preview-corpus --record=<name> --live` обновляет файлы одной записи с настоящего сервиса, а `LINK_PREVIEW_LIVE=1 vendor/bin/testo --suite=Integration` сверяет настоящие сервисы с тем, чего ждёт код.

### Публичный API

| Класс | Описание |
|---|---|
| `LinkPreviewer` | Интерфейс фасада: `preview(url)` и `fromHtml(html, pageUrl, charset)` -> `Result<PageInfo, PreviewFailure>` |
| `PreviewProvider` | Его реализация (fetcher, extractors, limits, adapters, `hostAsSiteName`) |
| `PageInfo` | Value object результата, `toArray()` / `fromArray()` |
| `Limits` | Чистый текст, лимиты длины и схем |
| `PreviewFailure`, `PreviewFailureReason` | Почему превью нет: причина, статус, `Retry-After`, `isTransient()` |
| `Fetch\Fetcher`, `FetchedPage`, `FetchFailure`, `FetchFailureReason` | PSR-18 загрузка с циклом редиректов: `fetch()`, `fetchJson()`, `fetchMedia()`, `check()` |
| `Fetch\UrlPolicy`, `UrlRejection` | SSRF-политика: `check()` для URL, `allowsAddress()` для разрешённых адресов |
| `Fetch\UserAgentMap` | Хост -> `User-Agent` |
| `Html\PageDocument` | Read-only DOM: `title()`, `meta()`, `links()`, `images()`, `language()`, `baseHref()` |
| `Extract\Extractor`, `OEmbedExtractor`, `JsonLdExtractor`, `OpenGraphExtractor`, `TwitterCardsExtractor`, `HtmlFallbackExtractor` | Источники; возвращают `Result<Metadata, ExtractFailure>` |
| `Extract\JsonLdReader`, `ProcessorJsonLdReader`, `JsonLdFailure` | Заменяемый читатель JSON-LD; по умолчанию построен на `rasuvaeff/json-ld` |
| `Adapter\Adapter`, `AdapterRegistry`, `AdapterContext`, `AdapterFailure` | Адаптеры сайтов: `AdapterRegistry::standard()` (одиннадцать встроенных) и `with()` для своих |
| `Extract\Metadata` | Что знает один источник (все поля необязательны) |

## Безопасность

Превью ссылки загружает выбранный пользователем URL изнутри вашей сети. Что делает пакет:

- Политика-whitelist на первом URL и на каждой цели редиректа, проверяется до отправки запроса; `fromHtml()` тоже проверяет свой `pageUrl`.
- Альтернативные написания IP, IPv4 внутри IPv6, `localhost.`, трюки с userinfo и управляющие символы отвергаются или классифицируются как адрес, которым являются.
- HTML разбирается libxml (`LIBXML_NONET`) и никогда не исполняется; внешние сущности не загружаются; скрипты - это текст.
- Размер ответа ограничен; читаются только запрошенные content-type.
- Каждый URL со страницы - абсолютный http(s) без учётных данных. oEmbed-эндпоинт, названный страницей, загружается под той же политикой и проверками редиректов, что и страница; JSON-LD `@context` никогда не загружается.
- `url` - всегда то, откуда превью загружено; заявленное страницей (`og:url`, canonical) хранится отдельно в `canonicalUrl`, поэтому страница не может перенаправить карточку.
- Хосты адаптеров сопоставляются по меткам, а не по подстрокам; токены адаптеров отправляются заголовками только хосту API и никогда не попадают в URL, отказы или исключения.

Чего он не может, потому что PSR-18 не даёт средств:

- **Нет DNS-пиннинга в самом пакете.** Политика никогда не разрешает имена; с обычным PSR-18 клиентом имя, указывающее на внутренний адрес, проходит, а атакующий может перепривязать имя между проверкой и соединением. Закрывайте это в транспорте: используйте [`rasuvaeff/pinned-http-client`](https://github.com/rasuvaeff/pinned-http-client) как в «Использовании» (он разрешает хост, проверяет каждый адрес через `UrlPolicy::allowsAddress()` и пиннит соединение; [`examples/with_pinned_client.php`](examples/with_pinned_client.php)) или пускайте трафик клиента через доверенный резолвящий прокси (egress-прокси, отказывающий внутренним адресам, например Smokescreen).
- **Клиент не должен следовать редиректам** (`PinnedClient` и не следует; `allow_redirects => false` для Guzzle, `max_redirects => 0` для Symfony). Редирект, пройденный внутри клиента, минует политику.
- **Таймауты - забота клиента.** В PSR-18 их нет; настройте таймауты соединения и чтения у клиента. `chainBudgetSeconds` проверяется только между хопами и не прерывает запрос в полёте.
- **Размер тела** проверяется на теле, которое вернул клиент; буферизующий клиент его уже прочитал. Задайте лимит и у клиента (`maxBodyBytes` у `PinnedClient`).
- URL картинок превью не загружает. Чтобы скачать картинку, используйте `Fetcher::fetchMedia()`, который применяет ту же политику.
- Вывод - чистый plain text, а не безопасная разметка. Экранируйте его для контекста, в котором выводите.

## Примеры

См. [examples/](examples/) - исполняемые скрипты.
Примеры должны выполняться без фатальных ошибок и соответствовать
документированному публичному API.

| Скрипт | Показывает | Нужен сервер? |
|---|---|---|
| `parse_html.php` | Редирект, слияние и отказы политики на in-memory PSR-18 клиенте | Нет |
| `url_policy.php` | Что `UrlPolicy` принимает и отвергает, dev-allowlist | Нет |
| `redirects.php` | Цепочка редиректов, отвергнутая на втором хопе | Нет |
| `limits_merge.php` | Приоритет источников, свой порядок и `Limits` | Нет |
| `failures.php` | `PreviewFailure`: причина, статус, `Retry-After`, `isTransient()` | Нет |
| `cached_previewer.php` | `CachedPreviewProvider` - штатный PSR-16-декоратор (успехи кешируются, неудачи нет) | Нет |
| `media.php` | `Fetcher::fetchMedia()`: типы, лимит размера, политика на редиректах | Нет |
| `adapters_oembed.php` | YouTube, TikTok, Twitter/X, Twitch по записанным ответам oEmbed | Нет |
| `adapters_pages.php` | GitHub, Telegram, VK (теги страницы) и Wikipedia, Rutube (API) по записанным ответам | Нет |
| `adapters_tokens.php` | Instagram: отказ без токена, затем с токеном | Нет |
| `with_pinned_client.php` | Fetcher поверх `rasuvaeff/pinned-http-client` (DNS-пиннинг) с `UrlPolicy::allowsAddress()`; нужен пакет | Нет |
| `live.php` | Настоящая загрузка (`PinnedClient`, если установлен), только при заданном `LINK_PREVIEW_URL` | Да (по желанию) |

## Разработка

На хосте нет PHP/Composer - всё запускается в Docker. В образе `composer:2` нет ни `ext-intl` (IDN-хосты, стабы Psalm), ни `pcov`, поэтому `make image` собирает локальный образ (`link-preview-php:local`) с обоими:

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
