# rasuvaeff/yii3-filestorage-web

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/yii3-filestorage-web/v)](https://packagist.org/packages/rasuvaeff/yii3-filestorage-web)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-filestorage-web/downloads)](https://packagist.org/packages/rasuvaeff/yii3-filestorage-web)
[![Build](https://github.com/rasuvaeff/yii3-filestorage-web/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-filestorage-web/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/yii3-filestorage-web/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-filestorage-web/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/yii3-filestorage-web/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-filestorage-web/php)](https://packagist.org/packages/rasuvaeff/yii3-filestorage-web)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[English version](README.md)

HTTP-половина [`rasuvaeff/yii3-filestorage`](https://github.com/rasuvaeff/yii3-filestorage):
подписанные URL для файлов, которые объектное хранилище не может отдать само, и
экшен, отдающий их без всех тех способов, которыми это обычно ломается.

> Используете AI-ассистента? [llms.txt](llms.txt) — компактный справочник по API, который можно отдать модели.
> Проекты с Composer-плагином [llm/skills](https://github.com/roxblnfk/skills) получают skill пакета в `.agents/skills/` автоматически при установке.

**Статус: `0.x`.**

## Требования

- PHP 8.3+
- `rasuvaeff/yii3-filestorage` ^0.1 плюс backend хранилища
- `rasuvaeff/yii3-filestorage-db` — ради `ScopedFileResolverInterface`, который
  нужен этому пакету и которого он не предоставляет
- `yiisoft/http` ^1.2, реализация PSR-17 и PSR-20-часы

## Установка

```bash
composer require rasuvaeff/yii3-filestorage-web
```

```php
// config/common/params.php
return [
    'rasuvaeff/yii3-filestorage-web' => [
        'route' => '/files/{token}',
        'tokenAttribute' => 'token',
        'cacheControl' => 'private, max-age=3600',
        'signingKeys' => [
            'active' => '2026-08',
            'keys' => ['2026-08' => $_ENV['FILESTORAGE_SIGNING_KEY']],
        ],
        'extraActiveMediaTypes' => [],
    ],
];
```

```php
// config/common/routes.php
use Rasuvaeff\Yii3FilestorageWeb\Action\FileDownloadAction;
use Yiisoft\Router\Route;

return [
    Route::get('/files/{token}')->action(FileDownloadAction::class)->name('file/download'),
];
```

Держите маршрут и параметр `route` в согласии: первый — то, что матчит роутер,
второй — то, что попадает в каждый URL. Ключ: `php -r "echo
bin2hex(random_bytes(32));"`; короче 32 байт отклоняется.

Это вся проводка. `Storage::urlFor()` начинает возвращать подписанные URL для
приватных хранилищ сразу после установки пакета — ядро объявляет
`ProxyUrlGeneratorInterface` опциональным и оставляет его несвязанным именно
для этого.

## Что гарантирует экшен

| Правило | Почему |
|---|---|
| **Всё, что не сработало, — 404**: плохая подпись, истёкший токен, чужой scope, пропавшие байты | 403 подтверждает, что id существует. Непрозрачный токен нужен ровно затем, чтобы этого не произошло |
| **Scope берётся из токена, а не из запроса** | Подписанный URL сознательно отдаётся без сессии. Тенант едет внутри HMAC и сверяется вторым предикатом, поэтому утёкший id в чужом тенанте не резолвится |
| **Условные запросы отвечаются до открытия хранилища** | `304` стоит одного чтения метаданных. Заодно клиент с актуальной копией получит `304` даже если сам объект уже исчез |
| **Активный контент — вложение, что бы ни говорила политика** | HTML, SVG, XML и подобное, отданное inline с вашего origin, — это stored XSS. `nosniff` не даёт браузеру найти его там, где media type утверждает обратное |
| **Media type нормализуется один раз, до всех проверок** | CR, LF и NUL вырезаются до того, как тип сверяется со списком активных, попадает в валидатор и пишется в `Content-Type`. Решать по сырому значению, а чистить только на выходе — это и есть способ, которым сохранённый `text/ht\r\nml` минует список и всё равно приходит клиенту как `text/html`, inline |
| **Токен, выписанный на variant, не получает оригинал** | Variant внутри подписи, поэтому URL превью нельзя переиграть на полноразмерный файл |

## Диапазоны (Range)

Объявляются и отдаются тогда, когда диапазон реально дёшев, а это вопрос про
*хранилище*:

| Хранилище | Результат |
|---|---|
| Реализует `RangeReadableStoreInterface` | У хранилища запрашивается окно — одно ranged-чтение |
| Не реализует, но тело seekable и сообщает свой размер (локальный файл) | Окно через seek |
| Ни то ни другое: нет range-примитива, а тело forward-only или неизвестной длины | Никакого `Accept-Ranges`, а `Range`-запрос получает корректный полный `200` |

Последняя строка — это то, что сегодня отдаёт `readStream()` объектного
хранилища, поэтому туда попадает группа на S3 или Flysystem. Но проверяются
именно две возможности, а не разновидность хранилища.

Только одиночные диапазоны: `206`, `Content-Range`, `Content-Length` и `416` с
`bytes */size` для запроса за пределами файла. Multi-range получает
представление целиком — RFC 9110 это разрешает. `If-Range` учитывается, поэтому
возобновление загрузки изменившегося файла начинается заново, а не склеивает два
файла.

Для S3 лучший ответ — presigned URL: Range по нему обрабатывает сам S3. Решение
принимает политика доставки — `urlFor()` возвращает presigned URL, если политика
разрешает его выдавать и хранилище способно выдать URL, который эту политику
соблюдает (нужную диспозицию и `Content-Type`); иначе остаётся подписанный
proxy-URL этого пакета.

> **Почему не `yiisoft/response-download`?** Он был рассмотрен. В master-ветке
> он покрывает большую часть половины с ответом, включая Range, — но ничего из
> этого нет в выпущенном `1.1.0`, чей `sendStreamAsFile()` делает два заголовка
> и тело. Собственная реализация к тому же позволяет брать диапазон у
> *хранилища*, чего фабрика, получившая только поток, не может. Стоит вернуться
> к вопросу, когда выйдет релиз с `processRange()`.

## Ротация ключей

Идентификатор ключа входит в подписанный конверт, поэтому ротация не ломает
URL, уже лежащие в почтовых ящиках:

```php
'signingKeys' => [
    'active' => '2026-09',                    // подписывает начиная с этого момента
    'keys' => [
        '2026-09' => $_ENV['FILESTORAGE_SIGNING_KEY'],
        '2026-08' => $_ENV['FILESTORAGE_SIGNING_KEY_PREVIOUS'],  // всё ещё проверяет
    ],
],
```

Держите старый ключ хотя бы столько, сколько живёт самый долгий выписываемый
TTL, потом убирайте.

## Примеры

Исполняемые и самодостаточные — см. [`examples/`](examples/). Сервер не нужен.

## Разработка

PHP и Composer на хосте нет — всё через Docker.

```bash
make build
make cs-fix
make mutation
make release-check
```

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
