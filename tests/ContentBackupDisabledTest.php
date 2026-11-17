<?php

declare(strict_types=1);

namespace Hftp\StatamicContentBackups\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;

class ContentBackupDisabledTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('content-backup.enabled', false);
        $app['config']->set('queue.connections.content-backup', [
            'driver' => 'sync',
            'queue' => 'custom',
            'retry_after' => 50,
        ]);
        $app['config']->set('horizon.defaults.supervisor-content-backup', [
            'timeout' => 999,
            'maxProcesses' => 4,
        ]);
        $app['config']->set('filesystems.disks.content_backups', [
            'driver' => 's3',
            'throw' => false,
            'visibility' => 'public',
        ]);
    }

    public function test_a_disabled_backup_is_not_scheduled(): void
    {
        $this->assertSame([], app(Schedule::class)->events());
    }

    public function test_an_existing_queue_connection_supervisor_and_disk_are_left_alone(): void
    {
        $this->assertSame(50, config('queue.connections.content-backup.retry_after'));
        $this->assertSame(999, config('horizon.defaults.supervisor-content-backup.timeout'));
        $this->assertSame(4, config('horizon.defaults.supervisor-content-backup.maxProcesses'));
        $this->assertFalse(config('filesystems.disks.content_backups.throw'));
        $this->assertSame('public', config('filesystems.disks.content_backups.visibility'));
    }
}
