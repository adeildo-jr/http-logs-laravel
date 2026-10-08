<?php

use AdeildoJr\HttpLogs\Support\LogSanitizer;

it('normalizes scalar JSON values and empty bodies to arrays', function (mixed $input, array $expected) {
    expect(app(LogSanitizer::class)->body($input))->toBe($expected);
})->with([
    'JSON null' => [null, ['empty']],
    'false' => [false, ['value' => false]],
    'zero' => [0, ['value' => 0]],
    'empty string' => ['', ['value' => '']],
    'string' => ['synthetic', ['value' => 'synthetic']],
    'empty array' => [[], []],
]);

it('does not serialize objects or uploaded file streams', function () {
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, 'synthetic-file-content');

    try {
        $result = app(LogSanitizer::class)->body([
            'upload' => $stream,
            'object' => (object) ['token' => 'object-secret-value'],
            'safe' => 'synthetic-safe',
        ]);

        expect($result)->toBe([
            'upload' => '[UNSUPPORTED]',
            'object' => '[UNSUPPORTED]',
            'safe' => 'synthetic-safe',
        ])->and(json_encode($result, JSON_THROW_ON_ERROR))
            ->not->toContain('synthetic-file-content')
            ->not->toContain('object-secret-value');
    } finally {
        fclose($stream);
    }
});

it('preserves multipart keys and treats present null file metadata as unsupported', function () {
    expect(app(LogSanitizer::class)->multipart([
        2 => ['name' => 'safe', 'contents' => 'kept'],
        'upload' => ['name' => 'upload', 'contents' => 'synthetic-file-secret', 'filename' => null],
        7 => ['name' => 'empty', 'headers' => null],
        'protected' => [
            'name' => 'Password_Confirmation',
            'contents' => 'synthetic-password-secret',
            'headers' => ['Authorization' => ['Bearer synthetic-secret'], 'X-Public' => ['kept']],
        ],
    ]))->toBe([
        2 => ['name' => 'safe', 'contents' => 'kept'],
        'upload' => ['name' => 'upload', 'contents' => '[UNSUPPORTED]'],
        7 => ['name' => 'empty', 'contents' => null, 'headers' => '[UNSUPPORTED]'],
        'protected' => [
            'name' => 'Password_Confirmation',
            'contents' => '[REDACTED]',
            'headers' => ['Authorization' => '[REDACTED]', 'X-Public' => ['kept']],
        ],
    ]);
});

it('discards invalid multipart parts and masks names containing control characters', function () {
    expect(app(LogSanitizer::class)->multipart([
        'missing' => [],
        4 => ['name' => null],
        8 => ['name' => 17],
        'non-array' => 'synthetic',
        'control' => ['name' => "safe\0name", 'contents' => 'synthetic-control-secret'],
        'empty-name' => ['name' => '', 'contents' => null],
    ]))->toBe([
        'missing' => '[UNSUPPORTED]',
        4 => '[UNSUPPORTED]',
        8 => '[UNSUPPORTED]',
        'non-array' => '[UNSUPPORTED]',
        'control' => ['name' => '[REDACTED]', 'contents' => '[REDACTED]'],
        'empty-name' => ['name' => '', 'contents' => null],
    ]);
});

it('accepts only nonempty string redaction names and normalizes duplicate names', function () {
    $configured = [null, false, 0, '', [], (object) ['key' => 'token'], 'SECRET_TOKEN', 'secret-token', 'private_note'];
    config()->set('http-logs-laravel.redact_fields', $configured);
    config()->set('http-logs-laravel.redact_headers', $configured);

    $payload = [
        '' => 'empty-key-kept',
        'secret-token' => 'synthetic-token-secret',
        'PRIVATE_NOTE' => 'synthetic-note-secret',
        'password' => 'kept-by-custom-config',
    ];
    $expected = [
        '' => 'empty-key-kept',
        'secret-token' => '[REDACTED]',
        'PRIVATE_NOTE' => '[REDACTED]',
        'password' => 'kept-by-custom-config',
    ];
    $sanitizer = app(LogSanitizer::class);

    expect($sanitizer->body($payload))->toBe($expected)
        ->and($sanitizer->headers($payload))->toBe($expected);
});

it('ignores non-array redaction configuration', function (mixed $configured) {
    config()->set('http-logs-laravel.redact_fields', $configured);
    config()->set('http-logs-laravel.redact_headers', $configured);
    $payload = ['password' => 'kept', 'Authorization' => 'kept'];
    $sanitizer = app(LogSanitizer::class);

    expect($sanitizer->body($payload))->toBe($payload)
        ->and($sanitizer->headers($payload))->toBe($payload);
})->with([
    'null' => [null],
    'string' => ['password'],
    'boolean' => [false],
    'integer' => [42],
]);

it('replaces invalid UTF-8 payloads without exposing the original bytes', function () {
    expect(app(LogSanitizer::class)->body(['binary' => "\xB1\x31"]))->toBe(['_truncated' => true]);
});

it('preserves duplicate query parameters while masking all sensitive occurrences', function () {
    $result = app(LogSanitizer::class)->url(
        'https://example.test/path?tag=first&token=first-secret&tag=second&token=second-secret;access_token=third-secret',
    );

    expect($result)->toBe(
        'https://example.test/path?tag=first&token=%5BREDACTED%5D&tag=second&token=%5BREDACTED%5D;access_token=%5BREDACTED%5D',
    );
});

it('discards malformed or non-HTTP URLs', function (string $url) {
    expect(app(LogSanitizer::class)->url($url))->toBe('[REDACTED]');
})->with([
    'relative' => '/relative?token=synthetic-secret',
    'invalid host' => 'https:///path?token=synthetic-secret',
    'non HTTP scheme' => 'file://localhost/synthetic-secret',
    'embedded newline' => "https://example.test/path\n?token=synthetic-secret",
]);

it('recognizes mixed case and equivalent hyphenated sensitive field names', function () {
    expect(app(LogSanitizer::class)->body([
        'ACCESS-TOKEN' => 'synthetic-token-secret',
        'Password_Confirmation' => 'synthetic-password-secret',
        'safe' => ['ordinary' => 'kept'],
    ]))->toBe([
        'ACCESS-TOKEN' => '[REDACTED]',
        'Password_Confirmation' => '[REDACTED]',
        'safe' => ['ordinary' => 'kept'],
    ]);
});

it('discards query names containing encoded control characters', function (string $key) {
    $result = app(LogSanitizer::class)->url('https://example.test/path?'.$key.'=synthetic-control-secret');

    expect($result)->toBe('[REDACTED]');
})->with(['password%00suffix', 'password%0Asuffix', 'password%7Fsuffix']);

it('keeps truncation markers within the effective minimum limits', function (int $limit) {
    config()->set('http-logs-laravel.max_payload_bytes', $limit);
    config()->set('http-logs-laravel.max_url_bytes', $limit);

    $sanitizer = app(LogSanitizer::class);
    $payload = $sanitizer->body(['large' => str_repeat('synthetic', 100)]);
    $url = $sanitizer->url('https://example.test/'.str_repeat('path', 100));

    expect($payload)->toBe(['_truncated' => true])
        ->and(strlen(json_encode($payload, JSON_THROW_ON_ERROR)))->toBe(19)
        ->and($url)->toBe('[TRUNCATED]')
        ->and(strlen($url))->toBe(11);
})->with([-1, 0, 1]);

it('measures payload bounds using the serialized JSON byte size', function () {
    $payload = ['message' => 'synthetic-value'];
    $size = strlen(json_encode($payload, JSON_THROW_ON_ERROR));
    $sanitizer = app(LogSanitizer::class);

    config()->set('http-logs-laravel.max_payload_bytes', $size);
    expect($sanitizer->body($payload))->toBe($payload);

    config()->set('http-logs-laravel.max_payload_bytes', $size - 1);
    expect($sanitizer->body($payload))->toBe(['_truncated' => true]);
});

it('accepts a URL at the exact byte limit and truncates the next byte', function () {
    $url = 'https://example.test/path';
    $sanitizer = app(LogSanitizer::class);

    config()->set('http-logs-laravel.max_url_bytes', strlen($url));
    expect($sanitizer->url($url))->toBe($url);

    config()->set('http-logs-laravel.max_url_bytes', strlen($url) - 1);
    expect($sanitizer->url($url))->toBe('[TRUNCATED]');
});
