# Sereno for PHP

Sends logs and errors from plain PHP, Symfony and any Monolog setup to a Sereno project channel. It provides a
Monolog handler (`Sereno\SerenoHandler`) and a one-line setup for plain PHP (`Sereno::init`) that posts events to the
Sereno ingest endpoint (`POST {SERENO_URL}/api/ingest/events`) with a source API key. Laravel has its own package,
`ascariodev/sereno-laravel`.

## Requirements

- PHP 8.1 or newer, with the `curl`, `json` and `mbstring` extensions
- Monolog 3 (`monolog/monolog` `^3.0`, installed as a dependency)

## Installation

Create a project in Sereno, open its Integrations page and choose "Create log source". The dialog shows the source
key (`wsk_...`) only once; if you lose it, rotate the key. Then, in your application:

```bash
composer require ascariodev/sereno-php
```

Set `SERENO_URL` (the base URL of the Sereno API, without a path) and `SERENO_KEY` in the environment, as described
in Configuration, and check them with `vendor/bin/sereno-test` (see Testing the connection).

## Plain PHP

Call `Sereno::init()` once, as early as possible in your entry point (after `vendor/autoload.php`):

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Sereno\Sereno;

$logger = Sereno::init(); // reads SERENO_KEY and SERENO_URL from the environment

$logger->error('Payment failed', ['order_id' => 42]);
```

You can also pass the settings: `Sereno::init('wsk_your_key', 'https://your-sereno-api.example.com', $options)`.
`init` returns a Monolog `Logger` (channel `sereno`) that you can use right away; `Sereno::logger()` returns the same
instance later (and `null` before `init`), and `Sereno::flush()` sends what is buffered. Calling `init` again returns
the same logger only once it is active (URL and key present, `handlers` not `false`); otherwise each call creates a new one.

Options (all optional):

| Option | Default | Description |
|---|---|---|
| `level` | `debug` | Minimum level sent (a Monolog `Level`, a level name or a level number). |
| `timeout` | `2` | Seconds per request, see Configuration. |
| `errorLevel` | warnings and recoverable errors | `E_*` bitmask of the PHP errors that are sent. |
| `handlers` | `true` | Set to `false` to build the logger without installing the PHP handlers below. |

### What it captures

`init` installs three PHP hooks, chaining to whatever hooks were installed before:

- **Uncaught exceptions** (`set_exception_handler`): sent at level `error`, with the exception in the context
  (class, message, location and trace). The event is flushed immediately, then the previous handler runs. If there
  was none, the exception is written to `error_log` and the process exits with code 255, as PHP does.
- **PHP errors** (`set_error_handler`): sent when they are enabled in `error_reporting()` and in `errorLevel`. By
  default `E_WARNING`, `E_USER_WARNING`, `E_CORE_WARNING`, `E_COMPILE_WARNING`, `E_USER_ERROR` and
  `E_RECOVERABLE_ERROR`. `E_NOTICE`, `E_DEPRECATED` and `E_STRICT` are not sent unless you add them to `errorLevel`. The
  context carries `file`, `line` and `errno`. The previous error handler is called afterwards; without one, PHP's
  standard handling continues.
- **Fatal errors** (`register_shutdown_function`): `E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR` and
  `E_USER_ERROR` found at shutdown are sent at level `critical`, and the buffer is flushed.

It does not capture exceptions you catch yourself (log them with the logger), errors in processes that are killed
(`SIGKILL`, out of memory kills) or anything while the process dies before shutdown.

Without a URL or a key, `init` still returns a working logger, but it is inert: nothing is buffered, no hook is
installed and no request is made.

## Monolog

If you manage Monolog yourself, add the handler to any logger:

```php
use Monolog\Logger;
use Sereno\SerenoHandler;

$logger = new Logger('app');
$logger->pushHandler(new SerenoHandler('https://your-sereno-api.example.com', 'wsk_your_key'));

$logger->error('Payment failed', ['order_id' => 42]);
```

The constructor is `SerenoHandler(?string $url, ?string $key, int|string|Level $level = Level::Debug, bool $bubble = true,
int|float|string|null $timeout = null)` (the remaining parameters are for tests). Without a URL or a key the handler is
inert. To also report exceptions, log them under the `exception` key: `$logger->error($e->getMessage(), ['exception' => $e])`.

## Symfony

Install the package and MonologBundle (`composer require symfony/monolog-bundle`, already present in most apps), then:

1. Add the credentials to `.env.local` (not committed), or to the real environment of the server:

   ```dotenv
   SERENO_URL=https://your-sereno-api.example.com
   SERENO_KEY=wsk_your_key
   ```

   In `.env` you can commit empty placeholders (`SERENO_URL=` and `SERENO_KEY=`), so the variables always exist. With
   empty values the handler is inert.

2. Register the handler as a service in `config/services.yaml`:

   ```yaml
   services:
       Sereno\SerenoHandler:
           autowire: false
           arguments:
               $url: '%env(SERENO_URL)%'
               $key: '%env(SERENO_KEY)%'
   ```

   The default level is `debug`, so Symfony's own debug records (for example "Matched route") are sent too. Add
   `$level: 'warning'` to `arguments` to send only warnings and above; `$timeout: 3` sets the timeout.

3. Use it as a handler in `config/packages/monolog.yaml`, adding `handlers` to the existing `monolog` key:

   ```yaml
   monolog:
       handlers:
           sereno:
               type: service
               id: Sereno\SerenoHandler
   ```

   At the top level it applies to all environments; put it under `when@prod` to send only from production. Symfony logs the exceptions that escape a
   controller, with the exception in the context, so they reach Sereno grouped by class, file and line.

Buffered events are sent at PHP shutdown (after the response with PHP-FPM) or when the handler is reset.

## Configuration

| Variable | Default | Description |
|---|---|---|
| `SERENO_URL` | none | Base URL of the Sereno API, without a path (for example `https://api.example.com`). |
| `SERENO_KEY` | none | The source API key, sent as a Bearer token. |
| `SERENO_TIMEOUT` | `2` | Connect and total timeout in seconds per request (a batch makes at most two, see Delivery behavior). Capped at `10`; a missing, non numeric or non positive value falls back to `2`. |
| `SERENO_LEVEL` | `debug` | Minimum level sent (any Monolog/PSR level name). An invalid value falls back to `debug`. |

`Sereno::init` reads the four variables (from `getenv`, `$_ENV` or `$_SERVER`), and the options passed in code win over
them. `bin/sereno-test` reads `SERENO_URL` and `SERENO_KEY`. `SerenoHandler` reads no variables: pass the values to its
constructor (in Symfony, through `%env()%`).

## Testing the connection

```bash
vendor/bin/sereno-test
vendor/bin/sereno-test --key=wsk_your_key --url=https://your-sereno-api.example.com
```

It sends one test event (level `info`, message "Sereno connection test") to `{url}/api/ingest/events` with a 5 second
timeout. Without `--key` and `--url` it uses `SERENO_KEY` and `SERENO_URL` from the process environment (it does not read
`.env` files: export them or pass the options). The outcome is one of:

- Connected: Sereno answered `202`. Exit code 0.
- Unauthorized: `401`, the key is invalid or has been revoked.
- Missing URL or key: nothing is sent.
- Failed: network failure, timeout or any other HTTP status (a redirect hints at a wrong URL scheme).

Every outcome other than "Connected" exits with code 1.

## Events

Each record becomes an event with `id`, `level`, `message`, `occurred_at` (UTC), optional `context` and optional
`fingerprint`. The fingerprint groups repeated events: pass `['fingerprint' => '...']` in the context, or log an
exception under the `exception` key, which uses the exception class, file and line (`Class|file:line`), or its class
and message (`Class|message`) when it has no file. Fingerprints
over 255 characters are replaced by their SHA-1. Limits keep the payload valid for the API: messages are cut at 8192
characters, context at 32 KB, 8 levels of depth and 50 items per array, strings in the context at 2048 characters and
exception traces at 2000. When anything is cut, the context carries `_truncated: true`. An empty message is sent as `-`.

The `id` is a UUID generated once when the record is formatted, so it stays the same when the handler retries the
batch. In the API the `id` is optional (1 to 64 characters, unique per log source): an event whose `id` was already
received from the same source is accepted but discarded, so resending a batch never duplicates events or inflates the
group counters.

## Delivery behavior

- **Buffered and batched.** Records are buffered in memory and sent in batches of at most 100 events per request.
  Reaching 100 buffered events flushes immediately.
- **One retry, only on transient failures.** If a request fails with a network error, a timeout or a 5xx response, the
  same batch, with the same event ids, is sent once more; the server discards the events it already stored, so the
  retry never duplicates. Any 4xx response (for example `401`, `413`, `422` or `429`) is not retried.
  Both attempts share a budget of 10 seconds: the retry uses the configured timeout or what is left of the budget,
  whichever is lower, and is skipped when less than half a second is left. With the default timeout a batch can block
  for up to 4 seconds. Logging never throws.
- **Buffer cap.** At most 1000 events are buffered between flushes. Beyond that, new records are dropped and one
  notice with the number of dropped events is reported at the next flush.
- **Flush points.** The buffer is flushed when it reaches 100 events, when the handler is closed or reset, on
  `Sereno::flush()`, and in a PHP shutdown function (also after a fatal error). Events still buffered when the process
  is killed are lost. In a long-running worker, call `flush()` (or `reset()`) between jobs.
- **Failure notices.** If a batch is not delivered (network error, timeout or a non 2xx response), or an event cannot be
  formatted, a message with the reason is written with `error_log` and the events of that batch are dropped. While
  Sereno is sending or reporting a failure, records that reach a Sereno handler are ignored by it, so a failure can
  never loop.

## License

MIT. See [LICENSE](LICENSE).
