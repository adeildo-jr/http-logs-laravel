<?php

use AdeildoJr\HttpLogs\Models\HttpRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

it('automatically records outgoing request and response data', function () {
    Http::fake([
        'example.test/*' => Http::response(
            ['result' => 'accepted'],
            202,
            ['Content-Type' => 'application/json', 'X-Response-Trace' => 'synthetic-response'],
        ),
    ]);

    $response = Http::withHeaders(['X-Request-Trace' => 'synthetic-request'])
        ->post('https://example.test/orders?page=2', ['message' => 'synthetic-body']);

    $record = HttpRequest::sole();
    $requestHeaders = array_change_key_case($record->request_headers, CASE_LOWER);
    $responseHeaders = array_change_key_case($record->response_headers, CASE_LOWER);

    expect($response->status())->toBe(202)
        ->and($record->url)->toBe('https://example.test/orders?page=2')
        ->and($record->method)->toBe('POST')
        ->and($record->status_code)->toBe(202)
        ->and($record->request)->toBe(['message' => 'synthetic-body'])
        ->and($record->response)->toBe(['result' => 'accepted'])
        ->and($requestHeaders['x-request-trace'])->toBe(['synthetic-request'])
        ->and($responseHeaders['x-response-trace'])->toBe(['synthetic-response'])
        ->and($record->created_at)->toBeInstanceOf(Carbon::class)
        ->and($record->updated_at)->toBeInstanceOf(Carbon::class);
});

it('records unsuccessful HTTP responses that have received a response', function () {
    Http::fake(['example.test/*' => Http::response(['message' => 'synthetic failure'], 422)]);

    $response = Http::post('https://example.test/rejected', ['input' => 'synthetic']);

    expect($response->status())->toBe(422)
        ->and(HttpRequest::sole()->status_code)->toBe(422)
        ->and(HttpRequest::sole()->response)->toBe(['message' => 'synthetic failure']);
});

it('stores decoded scalar JSON and excludes undecodable raw bodies', function (string $body, array $request, array $response) {
    Http::fake(['*' => Http::response($body, 200, ['Content-Type' => 'application/json'])]);

    Http::withBody($body, 'application/json')->post('https://example.test/scalars');

    $record = HttpRequest::sole();

    expect($record->request)->toBe($request)
        ->and($record->response)->toBe($response);
})->with([
    'JSON null' => ['null', [], ['empty']],
    'JSON false' => ['false', ['value' => false], ['value' => false]],
    'JSON zero' => ['0', ['value' => 0], ['value' => 0]],
    'JSON string' => ['"synthetic"', ['value' => 'synthetic'], ['value' => 'synthetic']],
    'empty body' => ['', [], ['empty']],
    'non JSON body' => ['raw-synthetic-body-secret', [], ['empty']],
]);

it('masks form request fields', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);

    Http::asForm()->post('https://example.test/form', ['password' => 'form-password-secret', 'safe' => 'kept']);

    expect(HttpRequest::sole()->request)->toBe(['password' => '[REDACTED]', 'safe' => 'kept']);
});

it('masks multipart fields and excludes uploaded file content', function (bool $useStream) {
    Http::fake(['*' => Http::response(['ok' => true])]);
    $contents = 'synthetic-upload-content-secret';

    if ($useStream) {
        $contents = fopen('php://memory', 'r+');
        fwrite($contents, 'synthetic-upload-content-secret');
        rewind($contents);
    }

    try {
        $response = Http::attach('attachment', $contents, 'synthetic.txt', ['Authorization' => 'Bearer part-header-secret'])
            ->post('https://example.test/multipart', ['password' => 'multipart-password-secret', 'safe' => 'kept']);

        $serialized = json_encode(HttpRequest::sole()->request, JSON_THROW_ON_ERROR);

        expect($response->successful())->toBeTrue()
            ->and($serialized)->toContain('kept')
            ->not->toContain('multipart-password-secret')
            ->not->toContain('synthetic-upload-content-secret')
            ->not->toContain('part-header-secret');
    } finally {
        if (is_resource($contents)) {
            fclose($contents);
        }
    }
})->with(['string upload' => false, 'stream upload' => true]);

it('does not invent a log for a connection failure without a response', function () {
    Http::fake(['example.test/*' => Http::failedConnection()]);

    expect(fn () => Http::get('https://example.test/offline'))->toThrow(ConnectionException::class)
        ->and(HttpRequest::count())->toBe(0);
});

it('masks sensitive headers fields and URL components without losing safe values', function () {
    Http::fake([
        '*' => Http::response([
            'result' => 'safe-response',
            'token' => 'response-token-secret',
            'nested' => ['password' => 'response-password-secret'],
        ], 200, [
            'Set-Cookie' => 'session=response-cookie-secret',
            'X-Response-Visible' => 'safe-response-header',
        ]),
    ]);

    Http::withHeaders([
        'Authorization' => 'Bearer authorization-secret',
        'x-API-KEY' => 'api-key-secret',
        'Cookie' => 'session=request-cookie-secret',
        'X-Request-Visible' => 'safe-request-header',
    ])->post(
        'https://synthetic-user:synthetic-password@example.test/path?access_token=url-token-secret&filter[token]=nested-url-secret&page=2#fragment-secret',
        [
            'PASSWORD' => 'request-password-secret',
            'safe' => 'safe-request',
            'nested' => [
                'client_secret' => 'client-secret-value',
                'items' => [['refresh_token' => 'refresh-secret-value', 'safe' => 'nested-safe']],
            ],
        ],
    );

    $record = HttpRequest::sole();
    $serialized = json_encode($record->toArray(), JSON_THROW_ON_ERROR);
    $requestHeaders = array_change_key_case($record->request_headers, CASE_LOWER);
    $responseHeaders = array_change_key_case($record->response_headers, CASE_LOWER);
    parse_str(parse_url($record->url, PHP_URL_QUERY), $query);

    foreach ([
        'authorization-secret', 'api-key-secret', 'request-cookie-secret',
        'response-cookie-secret', 'request-password-secret', 'client-secret-value',
        'refresh-secret-value', 'response-token-secret', 'response-password-secret',
        'synthetic-user', 'synthetic-password', 'url-token-secret', 'nested-url-secret', 'fragment-secret',
    ] as $secret) {
        expect($serialized)->not->toContain($secret);
    }

    expect($record->request['PASSWORD'])->toBe('[REDACTED]')
        ->and($record->request['safe'])->toBe('safe-request')
        ->and($record->request['nested']['client_secret'])->toBe('[REDACTED]')
        ->and($record->request['nested']['items'][0]['refresh_token'])->toBe('[REDACTED]')
        ->and($record->request['nested']['items'][0]['safe'])->toBe('nested-safe')
        ->and($record->response['token'])->toBe('[REDACTED]')
        ->and($requestHeaders['authorization'])->toBe('[REDACTED]')
        ->and($requestHeaders['x-api-key'])->toBe('[REDACTED]')
        ->and($requestHeaders['x-request-visible'])->toBe(['safe-request-header'])
        ->and($responseHeaders['set-cookie'])->toBe('[REDACTED]')
        ->and($responseHeaders['x-response-visible'])->toBe(['safe-response-header'])
        ->and($query)->toBe(['access_token' => '[REDACTED]', 'filter' => ['token' => '[REDACTED]'], 'page' => '2'])
        ->and(parse_url($record->url, PHP_URL_USER))->toBeNull()
        ->and(parse_url($record->url, PHP_URL_FRAGMENT))->toBeNull();
});

it('honors custom sensitive fields and header names', function () {
    config()->set('http-logs-laravel.redact_fields', ['private_note']);
    config()->set('http-logs-laravel.redact_headers', ['x-private-note']);
    Http::fake(['*' => Http::response(['private_note' => 'response-private-secret'])]);

    Http::withHeaders(['X-PRIVATE-NOTE' => 'header-private-secret'])
        ->post('https://example.test/custom?private_note=query-private-secret', ['PRIVATE_NOTE' => 'request-private-secret']);

    $record = HttpRequest::sole();
    $serialized = json_encode($record->toArray(), JSON_THROW_ON_ERROR);

    expect($record->request['PRIVATE_NOTE'])->toBe('[REDACTED]')
        ->and($record->response['private_note'])->toBe('[REDACTED]');

    foreach (['header-private-secret', 'query-private-secret', 'request-private-secret', 'response-private-secret'] as $secret) {
        expect($serialized)->not->toContain($secret);
    }
});

it('masks entire URL-bearing headers that can contain credentials', function () {
    Http::fake([
        '*' => Http::response(['ok' => true], 302, [
            'lOcAtIoN' => 'https://example.test/redirect?token=location-secret',
        ]),
    ]);

    Http::withoutRedirecting()->withHeaders(['rEfErEr' => 'https://example.test/source?token=referer-secret'])
        ->get('https://example.test/header-urls');

    $record = HttpRequest::sole();
    $requestHeaders = array_change_key_case($record->request_headers, CASE_LOWER);
    $responseHeaders = array_change_key_case($record->response_headers, CASE_LOWER);

    expect($requestHeaders['referer'])->toBe('[REDACTED]')
        ->and($responseHeaders['location'])->toBe('[REDACTED]')
        ->and(json_encode($record->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain('referer-secret')
        ->not->toContain('location-secret');
});

it('can disable each captured payload independently', function (string $option, string $column) {
    config()->set('http-logs-laravel.capture.'.$option, false);
    Http::fake(['*' => Http::response(['safe' => 'response'], 200, ['X-Response' => 'safe'])]);

    Http::withHeaders(['X-Request' => 'safe'])->post('https://example.test/capture', ['safe' => 'request']);

    $record = HttpRequest::sole();

    expect($record->{$column})->toBe([])
        ->and($record->method)->toBe('POST')
        ->and($record->status_code)->toBe(200);
})->with([
    'request body' => ['request_body', 'request'],
    'response body' => ['response_body', 'response'],
    'request headers' => ['request_headers', 'request_headers'],
    'response headers' => ['response_headers', 'response_headers'],
]);

it('caps stored payloads using a safe whole-payload marker', function () {
    config()->set('http-logs-laravel.max_payload_bytes', 64);
    Http::fake(['*' => Http::response(['large' => str_repeat('response-', 50)], 200, ['X-Large' => str_repeat('header-', 50)])]);

    Http::withHeaders(['X-Large' => str_repeat('header-', 50)])
        ->post('https://example.test/large', ['large' => str_repeat('request-', 50)]);

    $record = HttpRequest::sole();

    foreach (['request', 'response', 'request_headers', 'response_headers'] as $column) {
        expect($record->{$column})->toBe(['_truncated' => true])
            ->and(strlen(json_encode($record->{$column}, JSON_THROW_ON_ERROR)))->toBeLessThanOrEqual(64);
    }
});

it('replaces oversized URLs with a marker', function () {
    config()->set('http-logs-laravel.max_url_bytes', 64);
    Http::fake(['*' => Http::response(['ok' => true])]);

    Http::get('https://example.test/'.str_repeat('safe-path', 40));

    expect(HttpRequest::sole()->url)->toBe('[TRUNCATED]');
});

it('stores URLs longer than the source varchar limit within the configured bound', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    $url = 'https://example.test/'.str_repeat('safe', 100);

    Http::get($url);

    expect(HttpRequest::sole()->url)->toBe($url);
});

it('bounds nested payloads before storing them', function () {
    config()->set('http-logs-laravel.max_depth', 2);
    Http::fake(['*' => Http::response(['nested' => ['next' => ['token' => 'depth-secret', 'safe' => ['deeper' => 'public-data']]]])]);

    Http::post('https://example.test/depth', ['nested' => ['next' => ['password' => 'depth-password-secret', 'safe' => ['deeper' => 'public-data']]]]);

    $serialized = json_encode(HttpRequest::sole()->toArray(), JSON_THROW_ON_ERROR);

    expect($serialized)->toContain('[TRUNCATED]')
        ->not->toContain('depth-secret')
        ->not->toContain('depth-password-secret');
});

it('keeps HTTP responses usable when database logging fails and emits no secret context', function () {
    Schema::drop('http_requests');
    Log::spy();
    Http::fake(['*' => Http::response(['token' => 'response-failure-secret'], 201)]);

    $response = Http::withToken('authorization-failure-secret')
        ->post('https://example.test/failure?token=query-failure-secret', ['password' => 'body-failure-secret']);

    expect($response->status())->toBe(201)
        ->and($response->json('token'))->toBe('response-failure-secret');

    Log::shouldHaveReceived('warning')->once()->with('HTTP request logging failed.');
});

it('keeps HTTP responses usable even when the failure logger also throws', function () {
    Schema::drop('http_requests');
    Log::shouldReceive('warning')->once()->with('HTTP request logging failed.')
        ->andThrow(new RuntimeException('logger-secret-value'));
    Http::fake(['*' => Http::response(['ok' => true], 202)]);

    $response = Http::post('https://example.test/logger-failure', ['token' => 'request-secret-value']);

    expect($response->status())->toBe(202)
        ->and($response->json())->toBe(['ok' => true]);
});
