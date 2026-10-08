<?php

use AdeildoJr\HttpLogs\HttpLogsLaravel;
use AdeildoJr\HttpLogs\HttpLogsLaravelServiceProvider;
use AdeildoJr\HttpLogs\Models\HttpRequest;
use AdeildoJr\HttpLogs\Tests\Fixtures\CustomHttpRequest;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

it('uses the configured table and isolated connection for migration and writes', function () {
    config()->set('http-logs-laravel.table_name', 'custom_http_logs');
    config()->set('http-logs-laravel.connection', 'alternate');

    $migration = $this->migration();
    $migration->up();

    expect(Schema::connection('alternate')->hasTable('custom_http_logs'))->toBeTrue()
        ->and(Schema::connection('testing')->hasTable('custom_http_logs'))->toBeFalse()
        ->and((new HttpRequest)->getTable())->toBe('custom_http_logs')
        ->and((new HttpRequest)->getConnectionName())->toBe('alternate');

    Http::fake(['example.test/*' => Http::response(['ok' => true])]);
    Http::get('https://example.test/configured');

    expect(HttpRequest::count())->toBe(1);

    $migration->down();

    expect(Schema::connection('alternate')->hasTable('custom_http_logs'))->toBeFalse()
        ->and(Schema::connection('testing')->hasTable('http_requests'))->toBeTrue();
});

it('provides the log columns and supports migrating down and up', function () {
    expect(Schema::hasTable('http_requests'))->toBeTrue()
        ->and(Schema::hasColumns('http_requests', [
            'id', 'url', 'method', 'request', 'response', 'status_code',
            'request_headers', 'response_headers', 'created_at', 'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('http_requests', 'deleted_at'))->toBeFalse();

    $migration = $this->migration();
    $migration->down();
    expect(Schema::hasTable('http_requests'))->toBeFalse();
    $migration->up();
    expect(Schema::hasTable('http_requests'))->toBeTrue();
});

it('registers the configuration and migration publisher groups', function () {
    $configuration = HttpLogsLaravelServiceProvider::pathsToPublish(
        HttpLogsLaravelServiceProvider::class,
        'http-logs-laravel-config',
    );
    $migrations = HttpLogsLaravelServiceProvider::pathsToPublish(
        HttpLogsLaravelServiceProvider::class,
        'http-logs-laravel-migrations',
    );

    expect($configuration)->toHaveCount(1)
        ->and(array_key_first($configuration))->toEndWith('/config/http-logs-laravel.php')
        ->and($migrations)->toHaveCount(1)
        ->and(array_key_first($migrations))->toEndWith('/database/migrations/create_http_requests_table.php.stub');
});

it('can disable automatic logging', function () {
    config()->set('http-logs-laravel.enabled', false);
    Http::fake(['example.test/*' => Http::response(['ok' => true])]);

    $response = Http::post('https://example.test/disabled', ['safe' => 'value']);

    expect($response->successful())->toBeTrue()
        ->and(HttpRequest::count())->toBe(0);
});

it('returns an instance of a configured model subclass', function () {
    config()->set('http-logs-laravel.model', CustomHttpRequest::class);
    $event = new ResponseReceived(
        new Request(new PsrRequest('GET', 'https://example.test/custom-model')),
        new Response(new PsrResponse(200, [], '{"ok":true}')),
    );
    $record = app(HttpLogsLaravel::class)->record($event);

    expect(CustomHttpRequest::count())->toBe(1)
        ->and($record)->toBeInstanceOf(CustomHttpRequest::class);
});

it('permanently prunes old records while preserving recent records', function () {
    $this->travelTo(now()->startOfSecond());
    config()->set('http-logs-laravel.retention_days', 30);
    Http::fake(['example.test/*' => Http::response(['ok' => true])]);

    Http::get('https://example.test/old');
    $old = HttpRequest::first();
    $old->forceFill(['created_at' => now()->subDays(31)])->save();

    Http::get('https://example.test/recent');

    expect((new HttpRequest)->prunable()->pluck('id')->all())->toBe([$old->id])
        ->and(HttpRequest::count())->toBe(2);

    $this->artisan('model:prune', ['--model' => [HttpRequest::class]])->assertExitCode(0);

    expect(HttpRequest::count())->toBe(1)
        ->and(HttpRequest::sole()->url)->toBe('https://example.test/recent')
        ->and(DB::table('http_requests')->where('id', $old->id)->exists())->toBeFalse();
});

it('disables pruning when retention is nonpositive', function (int $days) {
    config()->set('http-logs-laravel.retention_days', $days);
    Http::fake(['example.test/*' => Http::response(['ok' => true])]);
    Http::get('https://example.test/no-pruning');
    HttpRequest::query()->update(['created_at' => now()->subYears(5)]);

    expect((new HttpRequest)->prunable()->count())->toBe(0);
})->with([0, -1]);
