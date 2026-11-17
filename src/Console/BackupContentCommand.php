<?php

namespace Hftp\StatamicContentBackups\Console;

use Hftp\StatamicContentBackups\ContentBackupPublisher;
use Illuminate\Console\Command;

class BackupContentCommand extends Command
{
    protected $signature = 'content-backup:run {--force : Publish even when content backups are disabled}';

    protected $description = 'Publish collection markdown to the content backup disk';

    public function handle(ContentBackupPublisher $publisher): int
    {
        if (! config('content-backup.enabled') && ! $this->option('force')) {
            $this->components->info('Content backup is disabled.');

            return self::SUCCESS;
        }

        $report = $publisher->publish();

        $this->components->info("Published {$report->files} files ({$report->bytes} bytes) to {$report->date}. Uploaded {$report->uploaded}, copied {$report->copied}, deleted {$report->deleted}.");

        return self::SUCCESS;
    }
}
