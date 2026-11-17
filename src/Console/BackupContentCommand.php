<?php

declare(strict_types=1);

namespace Hftp\StatamicContentBackups\Console;

use Hftp\StatamicContentBackups\ContentBackupPublisher;
use Illuminate\Console\Command;

class BackupContentCommand extends Command
{
    protected $signature = 'content-backup:run';

    protected $description = 'Publish collection markdown to the content backup disk';

    public function handle(ContentBackupPublisher $publisher): int
    {
        $report = $publisher->publish();

        $this->components->info("Published {$report->files} files ({$report->bytes} bytes) to {$report->date}. Uploaded {$report->uploaded}, copied {$report->copied}, deleted {$report->deleted}.");

        return self::SUCCESS;
    }
}
