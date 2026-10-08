<?php

namespace AdeildoJr\HttpLogs;

use AdeildoJr\HttpLogs\Models\HttpRequest;
use AdeildoJr\HttpLogs\Support\LogSanitizer;
use Illuminate\Http\Client\Events\ResponseReceived;

class HttpLogsLaravel
{
    public function __construct(protected LogSanitizer $sanitizer) {}

    public function record(ResponseReceived $event): ?HttpRequest
    {
        if (! config('http-logs-laravel.enabled', true)) {
            return null;
        }

        $modelClass = config('http-logs-laravel.model', HttpRequest::class);

        if (! is_string($modelClass) || ! is_a($modelClass, HttpRequest::class, true)) {
            throw new \InvalidArgumentException('The HTTP log model must extend HttpRequest.');
        }

        $log = new $modelClass;
        $log->fill([
            'url' => $this->sanitizer->url($event->request->url()),
            'method' => $event->request->method(),
            'status_code' => $event->response->status(),
            'request' => config('http-logs-laravel.capture.request_body', true)
                ? ($event->request->isMultipart()
                    ? $this->sanitizer->multipart($event->request->data())
                    : $this->sanitizer->body($event->request->data())) : [],
            'response' => config('http-logs-laravel.capture.response_body', true)
                ? $this->sanitizer->body($event->response->json(default: [])) : [],
            'request_headers' => config('http-logs-laravel.capture.request_headers', true)
                ? $this->sanitizer->headers($event->request->headers()) : [],
            'response_headers' => config('http-logs-laravel.capture.response_headers', true)
                ? $this->sanitizer->headers($event->response->headers()) : [],
        ]);
        $log->save();

        return $log;
    }
}
