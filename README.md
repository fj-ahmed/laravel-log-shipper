# laravel-fyxify-logs

Laravel plugin for the fyxify-logs service. It sends API request/response logs, artisan/queue runs and `Log::` channel
entries, with sensitive data masked before anything leaves the app. Everything is opt-in from `config/logging.php`;
no other app code changes.

Requires PHP 8.2+, Laravel 11–13, Monolog 3.

## Install

```json
"require": { "fj-ahmed/laravel-fyxify-logs": "^1.0" },
"repositories": [{ "type": "vcs", "url": "https://github.com/fj-ahmed/laravel-fyxify-logs" }]
```
```bash
composer update fj-ahmed/laravel-fyxify-logs
```

`.env`:
```
FYXIFY_LOGS_PROJECT=msreporting      # must be in the service's PROJECTS
FYXIFY_LOGS_URL=http://localhost:4000
FYXIFY_LOGS_KEY=<INGEST_KEY>
FYXIFY_LOGS_ENABLED=true             # optional kill switch
```

## Opt channels in (`config/logging.php`)

```php
'twilio'       => ['driver' => 'daily', 'path' => storage_path('logs/twilio.log'), 'fyxify' => true], // keep the file AND send
'microservice' => ['driver' => 'fyxify'],                                 // send only, no file
'webservice'   => ['driver' => 'fyxify', 'requests' => ['api']],          // request/response of these middleware groups
'runs'         => ['driver' => 'fyxify', 'runs' => true],                 // every artisan command (crons) + queue job
```

A channel that isn't opted in sends nothing. The stored channel name is the log file name
(`logs/twilio.log` → `twilio`), or set it with `'fyxify' => 'name'`.

**Request channels**: the plugin adds one global middleware; the **first** request channel whose rule matches gets
the request.

| key | meaning |
|---|---|
| `requests` | middleware groups, aliases or classes on the route: `['api']`, `['web']`, `['api.logs']` |
| `paths`    | only these paths (`$request->is()` patterns) |
| `except`   | never these paths |
| `ajax`     | `true` = AJAX only, `false` = non-AJAX only |

Optional: `php artisan vendor:publish --tag=fyxify-logs` to edit masked keys, excluded commands and guards.

## Behaviour

- **Masking**: sensitive keys (password, token, secret, authorization, cookie, ssn, card, birth_date, licenses, otp...)
  plus JWTs, `Bearer` values, `key=value` / `'key' => 'value'` text and `print_r` lines, in input, output, headers,
  URLs and log messages. The service masks again on ingest.
- **Request linking**: every request gets an `X-Request-Id` (an incoming one is honoured, so calls link across
  services); `Log::` lines written during the request or run carry it.
- **Runs**: artisan commands and queue jobs are recorded as `CLI`/`JOB` rows with exit status and duration. Long runs
  send every 5s; a fatal error still sends on shutdown. Wrapper commands are skipped (`except_commands`).
- **Service down**: batches go to `storage/logs/fyxify-logs-pending-Y-m-d.ndjson`; re-ingest them with
  `npm run replay -- <file>` in the fyxify-logs service. Nothing is dropped and the app is never blocked
  (1s connect timeout).

## Development

Masking must stay in sync with the service's `src/mask.js`:
```bash
php tests/masker-check.php .
```
To try unreleased plugin changes in an app, temporarily symlink a local checkout (don't commit this):
```bash
composer config repositories.fyxify-logs-local '{"type":"path","url":"../nodejs/fyxify-logs/laravel-plugin","options":{"symlink":true}}'
composer require "fj-ahmed/laravel-fyxify-logs:dev-main as 1.0.99"
```
Release: commit, `git tag v1.x.y && git push --tags`, then `composer update fj-ahmed/laravel-fyxify-logs` in each app.
