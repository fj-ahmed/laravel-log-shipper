# Laravel Log Shipper

Ships a Laravel app's API request/response logs, artisan/queue runs and `Log::` channel entries to a central log
collector. Sensitive data is masked before anything leaves the app, and everything is opt-in from
`config/logging.php`. No other app code changes.

Requires PHP 8.2+, Laravel 11–13, Monolog 3.

## Install

```json
"require": { "fj-ahmed/laravel-log-shipper": "^2.0" },
"repositories": [{ "type": "vcs", "url": "https://github.com/fj-ahmed/laravel-log-shipper" }]
```
```bash
composer update fj-ahmed/laravel-log-shipper
```

`.env`:
```
LOG_SHIPPER_URL=http://localhost:4000   # collector base URL
LOG_SHIPPER_KEY=<api key>               # sent as X-Api-Key
LOG_SHIPPER_PROJECT=myapp               # this app's name at the collector
LOG_SHIPPER_ENABLED=true                # optional kill switch
LOG_SHIPPER_SOURCE_HEADER=X-Source      # optional: request header naming the calling client
```

## Opt channels in (`config/logging.php`)

```php
'twilio'       => ['driver' => 'daily', 'path' => storage_path('logs/twilio.log'), 'shipper' => true], // keep the file AND ship
'microservice' => ['driver' => 'shipper'],                                 // ship only, no file
'webservice'   => ['driver' => 'shipper', 'requests' => ['api']],          // request/response of these middleware groups
'runs'         => ['driver' => 'shipper', 'runs' => true],                 // every artisan command (crons) + queue job
```

A channel that isn't opted in ships nothing. The stored channel name is the log file name
(`logs/twilio.log` → `twilio`), or set it with `'shipper' => 'name'`.

**Request channels**: the plugin adds one global middleware; the **first** request channel whose rule matches gets
the request.

| key | meaning |
|---|---|
| `requests` | middleware groups, aliases or classes on the route: `['api']`, `['web']`, `['api.logs']` |
| `paths`    | only these paths (`$request->is()` patterns) |
| `except`   | never these paths |
| `ajax`     | `true` = AJAX only, `false` = non-AJAX only |

Optional: `php artisan vendor:publish --tag=log-shipper` to edit masked keys, excluded commands and guards.

## Collector protocol

One `POST {LOG_SHIPPER_URL}/ingest/{LOG_SHIPPER_PROJECT}` per request or run, header `X-Api-Key`, JSON body
`{"requests": [...], "logs": [...]}`. Any 2xx counts as delivered.

- **requests**: `request_id, created_at ('Y-m-d H:i:s.v', app timezone), channel, method, url, path, route_name,
  status, duration_ms, ip, user_id, source, headers, input, output` (runs use method `CLI`/`JOB` and the exit code as status)
- **logs**: `request_id, created_at, channel, level, message, context, extra`

## Behaviour

- **Masking**: sensitive keys (password, token, secret, authorization, cookie, ssn, card, birth_date, licenses, otp...)
  plus JWTs, `Bearer` values, `key=value` / `'key' => 'value'` text and `print_r` lines, in input, output, headers,
  URLs and log messages.
- **Request linking**: every request gets an `X-Request-Id` (an incoming one is honoured, so calls link across
  services); `Log::` lines written during the request or run carry it.
- **Runs**: artisan commands and queue jobs are recorded with exit status and duration. Long runs ship every 5s; a
  fatal error still ships on shutdown. Wrapper commands are skipped (`except_commands`).
- **Collector down**: batches go to `storage/logs/log-shipper-pending-Y-m-d.ndjson` (one `{project, requests, logs}`
  per line) to re-send later. Nothing is dropped, and the app is never blocked (1s connect timeout).

## Development

```bash
php tests/masker-check.php .
```
To try unreleased changes in an app, temporarily symlink a local checkout (don't commit this):
```bash
composer config repositories.log-shipper-local '{"type":"path","url":"../path/to/laravel-log-shipper","options":{"symlink":true}}'
composer require "fj-ahmed/laravel-log-shipper:dev-main as 2.0.99"
```
Release: commit, `git tag v2.x.y && git push --tags`, then `composer update fj-ahmed/laravel-log-shipper` in each app.
