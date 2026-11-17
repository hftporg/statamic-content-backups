<?php

declare(strict_types=1);

namespace Hftp\StatamicContentBackups\Tests;

use Hftp\StatamicContentBackups\ContentBackupServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ContentBackupServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('content-backup.filesystem.bucket', 'testing-bucket');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');

        $app['config']->set('horizon.defaults', [
            'supervisor-default' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'maxProcesses' => 1,
                'timeout' => 60,
            ],
        ]);

        $app['config']->set('horizon.environments', [
            'production' => [
                'supervisor-default' => [
                    'maxProcesses' => 5,
                ],
            ],
            'local' => [
                'supervisor-default' => [
                    'maxProcesses' => 3,
                ],
            ],
            '*' => [
                'supervisor-default' => [
                    'maxProcesses' => 3,
                ],
            ],
        ]);
    }
}
