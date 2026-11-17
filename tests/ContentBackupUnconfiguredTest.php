<?php

declare(strict_types=1);

namespace Hftp\StatamicContentBackups\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;

class ContentBackupUnconfiguredTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('content-backup.filesystem.bucket', null);
    }

    public function test_a_backup_without_a_bucket_is_not_scheduled(): void
    {
        $this->assertSame([], app(Schedule::class)->events());
    }
}
