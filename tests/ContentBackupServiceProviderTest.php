<?php

declare(strict_types=1);

namespace Hftp\StatamicContentBackups\Tests;

use Hftp\StatamicContentBackups\Jobs\PublishContentBackup;
use Illuminate\Console\Scheduling\Schedule;

class ContentBackupServiceProviderTest extends TestCase
{
    public function test_the_backup_job_is_scheduled_daily_at_two_eastern(): void
    {
        $events = app(Schedule::class)->events();

        $this->assertCount(1, $events);

        $event = $events[0];

        $this->assertSame(PublishContentBackup::class, $event->description);
        $this->assertSame('0 2 * * *', $event->expression);
        $this->assertSame('America/New_York', $event->timezone);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);
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

    public function test_the_disk_is_private_and_throws(): void
    {
        $disk = config('filesystems.disks.content_backups');

        $this->assertSame('s3', $disk['driver']);
        $this->assertTrue($disk['throw']);
        $this->assertSame('private', $disk['visibility']);
    }
}
