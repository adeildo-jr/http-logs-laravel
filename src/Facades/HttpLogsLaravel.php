<?php

namespace AdeildoJr\HttpLogs\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \AdeildoJr\HttpLogs\HttpLogsLaravel
 */
class HttpLogsLaravel extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \AdeildoJr\HttpLogs\HttpLogsLaravel::class;
    }
}
