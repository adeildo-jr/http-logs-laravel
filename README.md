# HttpLogsLaravel

Store outgoing Laravel HTTP client requests and responses in a database, with configurable storage, payload capture, redaction, size limits, and retention.

This package was built using Spatie's [Laravel package skeleton](https://github.com/spatie/package-skeleton-laravel).

The package listens to `Illuminate\Http\Client\Events\ResponseReceived`, so calls made with `Http::get()`, `Http::post()`, and the other Laravel HTTP client methods are logged automatically. No middleware is required. HTTP error responses are included; incoming application requests and connection failures without a response do not create records.

## Requirements

- PHP 8.4 or later (`^8.4`).
- Laravel 12 or 13.

## Installation

Install the package and publish its configuration:

```bash
composer require adeildo-jr/http-logs-laravel
php artisan vendor:publish --tag=http-logs-laravel-config
```

Laravel discovers the service provider automatically. Set your table name and database connection in `config/http-logs-laravel.php` before publishing and running the migration:

```php
'table_name' => 'outgoing_http_logs',
'connection' => null,
```

```bash
php artisan vendor:publish --tag=http-logs-laravel-migrations
php artisan migrate
```

Installation and publishing do not run the migration automatically.

## Usage

Use Laravel's HTTP client as usual, then query the records through the package model:

```php
use AdeildoJr\HttpLogs\Models\HttpRequest;
use Illuminate\Support\Facades\Http;

$response = Http::post('https://example.com/api/ping', [
    'message' => 'Hello',
]);

$latestLog = HttpRequest::query()->latest('id')->first();
```

Logging runs synchronously when a response is received. If saving a record fails, the listener attempts to write the generic warning `HTTP request logging failed.` without payloads, SQL, or exception details. Logging failures, including a failure to write that warning, do not change the HTTP client's result.

Each record contains:

| Columns | Contents |
| --- | --- |
| `id`, `url`, `method`, `status_code` | Request identity, sanitized URL, HTTP method, and response status. |
| `request`, `response` | Sanitized structured request data and decoded JSON response. |
| `request_headers`, `response_headers` | Sanitized HTTP headers. |
| `created_at`, `updated_at` | Timestamps; `created_at` is indexed for pruning. |

The four JSON columns are cast to arrays. Deleting or pruning records removes them permanently.

## Configuration

Publish and edit [config/http-logs-laravel.php](config/http-logs-laravel.php):

| Option | Default | Behavior |
| --- | --- | --- |
| `enabled` | `true` | Enables automatic logging. Also configurable with `HTTP_LOGS_ENABLED`. |
| `table_name` | `http_requests` | Table used by the model and migration. Also configurable with `HTTP_LOGS_TABLE`. |
| `connection` | `null` | Uses Laravel's default connection, or a named application connection. Also configurable with `HTTP_LOGS_CONNECTION`. |
| `model` | `AdeildoJr\HttpLogs\Models\HttpRequest::class` | Model used to save records; must extend the package model. |
| `capture.request_body` | `true` | Captures structured request data. |
| `capture.response_body` | `true` | Captures decoded JSON responses. |
| `capture.request_headers` | `true` | Captures request headers. |
| `capture.response_headers` | `true` | Captures response headers. |
| `redact_headers` | List in configuration | Header names whose values become `[REDACTED]`. |
| `redact_fields` | List in configuration | Field names masked in request data, response data, and URL query parameters. |
| `max_payload_bytes` | `16384` | Maximum serialized JSON size per payload column; effective minimum is 19 bytes. |
| `max_url_bytes` | `2048` | Maximum stored URL size; effective minimum is 11 bytes. |
| `max_depth` | `20` | Maximum traversal depth for payload sanitization, capped at 100. |
| `retention_days` | `30` | Age threshold used when pruning is explicitly run or scheduled. |

Turning off a capture option stores an empty array in that column while retaining the request URL, method, and status. To disable all logging, set `enabled` to `false`.

Table and connection settings also control migration rollback. Changing them after migration does not rename an existing table or move its records; subsequent writes use the new destination. Use an explicit migration for any rename or transfer, coordinate the configuration change, and retain the original settings when rolling back the original migration.

If your application caches configuration, rebuild the cache after changes:

```bash
php artisan config:cache
```

### Custom model

Extend the package model to add application-specific behavior:

```php
namespace App\Models;

use AdeildoJr\HttpLogs\Models\HttpRequest;

class OutgoingHttpLog extends HttpRequest
{
}
```

Set the published configuration to use it:

```php
'model' => App\Models\OutgoingHttpLog::class,
```

## Captured data and redaction

Requests use the structured data exposed by Laravel's HTTP client; responses use decoded JSON. Scalar JSON values are wrapped as `['value' => $value]`. Null or undecodable responses become `['empty']`. Raw non-JSON response bodies are not stored. Unsupported objects and resources become `[UNSUPPORTED]`.

Multipart fields are captured by part name, including bracketed names. File contents and filename metadata are omitted, and each part's headers are sanitized. Ordinary multipart field contents remain subject to redaction and size limits.

Redaction compares configured names without regard to case and treats hyphens and underscores as equivalent. It traverses nested arrays, masks matching URL query parameters, removes URL credentials, and drops URL fragments. Default sensitive names include passwords, tokens, API keys, authorization headers, and cookies. `Location`, `Referer`, and `Referrer` headers are masked entirely by default because they can carry credential-bearing URLs.

Add integration-specific sensitive names to `redact_fields` and `redact_headers`, keeping the default entries you still need. Redaction matches configured keys; it does not automatically identify every personal detail or secrets embedded in arbitrary values or URL paths. Disable body or header capture when that data should not be stored.

Payloads exceeding `max_payload_bytes`, or containing invalid JSON string data, are replaced entirely with `['_truncated' => true]`. Values at the traversal limit become `[TRUNCATED]`. Oversized URLs become `[TRUNCATED]`; malformed URLs and query names containing control characters become `[REDACTED]`. URLs are checked for size both before and after sanitization. Limits must be integers; values below the minimum are raised to the minimum, and non-integer values use the default.

## Retention

`retention_days` does not schedule deletion. To prune old records daily, add an explicit schedule to your application, for example in `routes/console.php`:

```php
use AdeildoJr\HttpLogs\Models\HttpRequest;
use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune', [
    '--model' => [HttpRequest::class],
])->daily();
```

Keep Laravel's scheduler running. If you configured a custom model, use that class in the schedule. Records at least `retention_days` old are deleted permanently; values of zero or less disable pruning.
