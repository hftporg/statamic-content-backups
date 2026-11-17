<?php

namespace Hftp\StatamicContentBackups\Tests;

use Illuminate\Foundation\Application;

class ContentBackupDisabledTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('queue.connections.content-backup', [
            'driver' => 'sync',
            'queue' => 'custom',
            'retry_after' => 50,
        ]);
        $app['config']->set('horizon.defaults.supervisor-content-backup', [
            'timeout' => 999,
            'maxProcesses' => 4,
        ]);
    }

    public function test_an_existing_queue_connection_and_supervisor_are_left_alone(): void
    {
        $this->assertSame(50, config('queue.connections.content-backup.retry_after'));
        $this->assertSame(999, config('horizon.defaults.supervisor-content-backup.timeout'));
        $this->assertSame(4, config('horizon.defaults.supervisor-content-backup.maxProcesses'));
    }
}
