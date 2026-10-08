<?php

namespace AdeildoJr\HttpLogs\Listeners;

use AdeildoJr\HttpLogs\HttpLogsLaravel;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Log;
use Throwable;

class LogHttpResponse
{
    public function __construct(protected HttpLogsLaravel $logger) {}

    public function handle(ResponseReceived $event): void
    {
        try {
            $this->logger->record($event);
        } catch (Throwable) {
            try {
                Log::warning('HTTP request logging failed.');
            } catch (Throwable) {
                // Logging failures must never change the HTTP client's result.
            }
        }
    }
}
