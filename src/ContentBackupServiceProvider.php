<?php

declare(strict_types=1);

namespace Hftp\StatamicContentBackups;

use Hftp\StatamicContentBackups\Console\BackupContentCommand;
use Illuminate\Support\ServiceProvider;

class ContentBackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/content-backup.php', 'content-backup');
    }

    public function boot(): void
    {
        $this->registerQueueConnection();
        $this->registerHorizonSupervisor();

        if ($this->app->runningInConsole()) {
            $this->commands([
                BackupContentCommand::class,
            ]);

            $this->publishes([
                dirname(__DIR__).'/config/content-backup.php' => config_path('content-backup.php'),
            ], 'content-backup-config');
        }
    }

    private function registerQueueConnection(): void
    {
        $name = (string) config('content-backup.queue.connection');

        if (config("queue.connections.{$name}") !== null) {
            return;
        }

        $default = (string) config('queue.default');
        $base = config("queue.connections.{$default}", ['driver' => $default]);

        config([
            "queue.connections.{$name}" => array_merge(is_array($base) ? $base : [], [
                'queue' => config('content-backup.queue.name'),
                'retry_after' => (int) config('content-backup.queue.retry_after'),
                'after_commit' => false,
            ]),
        ]);
    }

    private function registerHorizonSupervisor(): void
    {
        $defaults = config('horizon.defaults');

        if (! is_array($defaults)) {
            return;
        }

        $supervisor = 'supervisor-content-backup';

        if (! isset($defaults[$supervisor])) {
            config([
                "horizon.defaults.{$supervisor}" => [
                    'connection' => config('content-backup.queue.connection'),
                    'queue' => [config('content-backup.queue.name')],
                    'balance' => false,
                    'minProcesses' => 1,
                    'maxProcesses' => 1,
                    'maxTime' => 0,
                    'maxJobs' => 0,
                    'memory' => (int) config('content-backup.queue.memory'),
                    'tries' => 1,
                    'timeout' => (int) config('content-backup.queue.worker_timeout'),
                    'nice' => 0,
                ],
            ]);
        }

        foreach (array_keys(config('horizon.environments', [])) as $environment) {
            $key = "horizon.environments.{$environment}.{$supervisor}";

            if (config($key) === null) {
                config([
                    $key => [
                        'maxProcesses' => 1,
                    ],
                ]);
            }
        }
    }
}
