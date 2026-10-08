<?php

namespace AdeildoJr\HttpLogs\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $url
 * @property string $method
 * @property int $status_code
 * @property array<mixed> $request
 * @property array<mixed> $response
 * @property array<mixed> $request_headers
 * @property array<mixed> $response_headers
 */
class HttpRequest extends Model
{
    use MassPrunable;

    protected $fillable = [
        'url',
        'method',
        'status_code',
        'request',
        'response',
        'request_headers',
        'response_headers',
    ];

    public function getTable(): string
    {
        return config('http-logs-laravel.table_name', 'http_requests');
    }

    public function getConnectionName(): ?string
    {
        return config('http-logs-laravel.connection');
    }

    protected function casts(): array
    {
        return [
            'request' => 'array',
            'response' => 'array',
            'request_headers' => 'array',
            'response_headers' => 'array',
        ];
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        $days = (int) config('http-logs-laravel.retention_days', 30);

        return $days > 0
            ? static::query()->where('created_at', '<=', now()->subDays($days))
            : static::query()->whereRaw('1 = 0');
    }
}
