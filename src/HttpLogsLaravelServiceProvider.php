<?php

namespace AdeildoJr\HttpLogs;

use AdeildoJr\HttpLogs\Listeners\LogHttpResponse;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Events\ResponseReceived;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class HttpLogsLaravelServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('http-logs-laravel')
            ->hasConfigFile()
            ->hasMigration('create_http_requests_table');
    }

    public function packageRegistered(): void
    {
        $this->app->bind(HttpLogsLaravel::class);
    }

    public function packageBooted(): void
    {
        $this->app->make(Dispatcher::class)->listen(ResponseReceived::class, LogHttpResponse::class);
    }
}
