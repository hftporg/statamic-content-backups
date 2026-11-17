<?php

declare(strict_types=1);

namespace Hftp\StatamicContentBackups;

use Hftp\StatamicContentBackups\Console\BackupContentCommand;
use Hftp\StatamicContentBackups\Jobs\PublishContentBackup;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class ContentBackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/content-backup.php', 'content-backup');
    }

    public function boot(): void
    {
        $this->registerDisk();
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

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $this->schedule($schedule);
        });
    }

    private function registerDisk(): void
    {
        $name = (string) config('content-backup.disk');

        if (config("filesystems.disks.{$name}") !== null) {
            return;
        }

        $filesystem = config('content-backup.filesystem');

        config([
            "filesystems.disks.{$name}" => [
                'driver' => 's3',
                'key' => $filesystem['key'] ?? null,
                'secret' => $filesystem['secret'] ?? null,
                'region' => $filesystem['region'] ?? null,
                'bucket' => $filesystem['bucket'] ?? null,
                'url' => $filesystem['url'] ?? null,
                'endpoint' => $filesystem['endpoint'] ?? null,
                'use_path_style_endpoint' => (bool) ($filesystem['use_path_style_endpoint'] ?? false),
                'root' => (string) ($filesystem['prefix'] ?? ''),
                'throw' => true,
                'visibility' => 'private',
            ],
        ]);
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

    private function bucketConfigured(): bool
    {
        $bucket = config('content-backup.filesystem.bucket');

        if (is_string($bucket) && $bucket !== '') {
            return true;
        }

        $disk = config('filesystems.disks.'.config('content-backup.disk').'.bucket');

        return is_string($disk) && $disk !== '';
    }

    private function schedule(Schedule $schedule): void
    {
        if (! config('content-backup.enabled') || ! config('content-backup.schedule.enabled')) {
            return;
        }

        if (! $this->bucketConfigured()) {
            return;
        }

        $schedule->job(PublishContentBackup::class)
            ->dailyAt((string) config('content-backup.schedule.time'))
            ->timezone((string) config('content-backup.schedule.timezone'))
            ->onOneServer()
            ->withoutOverlapping(120);
    }
}
