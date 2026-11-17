<?php

namespace Hftp\StatamicContentBackups\Tests;

use Hftp\StatamicContentBackups\Jobs\PublishContentBackup;
use Illuminate\Console\Scheduling\Schedule;

class ContentBackupServiceProviderTest extends TestCase
{
    public function test_the_package_does_not_register_a_schedule(): void
    {
        $this->assertSame([], app(Schedule::class)->events());
    }

    public function test_horizon_supervisor_timeout_fits_between_the_job_and_retry_after(): void
    {
        $job = new PublishContentBackup;
        $workerTimeout = config('horizon.defaults.supervisor-content-backup.timeout');
        $retryAfter = config('queue.connections.content-backup.retry_after');

        $this->assertSame(1, config('horizon.defaults.supervisor-content-backup.maxProcesses'));
        $this->assertSame(1, config('horizon.environments.production.supervisor-content-backup.maxProcesses'));
        $this->assertSame(1, config('horizon.environments.local.supervisor-content-backup.maxProcesses'));
        $this->assertSame(['content-backup'], config('horizon.defaults.supervisor-content-backup.queue'));
        $this->assertGreaterThan($job->timeout, $workerTimeout);
        $this->assertLessThan($retryAfter, $workerTimeout);
    }

    public function test_the_package_does_not_register_a_filesystem_disk(): void
    {
        $this->assertNull(config('filesystems.disks.content_backups'));
    }
}
