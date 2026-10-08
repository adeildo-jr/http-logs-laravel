<?php

namespace AdeildoJr\HttpLogs\Tests;

use AdeildoJr\HttpLogs\HttpLogsLaravelServiceProvider;
use Illuminate\Database\Migrations\Migration;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createConfiguredTable();
    }

    protected function getPackageProviders($app)
    {
        return [
            HttpLogsLaravelServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        $sqlite = [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ];

        // Replace every database connection so test configuration cannot reach a host database.
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections', [
            'testing' => $sqlite,
            'alternate' => $sqlite,
        ]);
        $app['config']->set('logging.default', 'null');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
    }

    public function migration(): Migration
    {
        return require __DIR__.'/../database/migrations/create_http_requests_table.php.stub';
    }

    public function createConfiguredTable(): void
    {
        $this->migration()->up();
    }
}
