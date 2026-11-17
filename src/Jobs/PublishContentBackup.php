<?php

declare(strict_types=1);

namespace Hftp\StatamicContentBackups\Jobs;

use Hftp\StatamicContentBackups\ContentBackupPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One publisher runs at a time. A second worker picking this up mid-run
 * would write a mixed latest/ tree, so the job does not retry and the
 * queue's retry_after stays above the worker timeout.
 */
class PublishContentBackup implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function __construct()
    {
        $this->timeout = (int) config('content-backup.queue.timeout');

        $this->onConnection((string) config('content-backup.queue.connection'));
        $this->onQueue((string) config('content-backup.queue.name'));
    }

    public function uniqueId(): string
    {
        return 'content-backup';
    }

    public function uniqueFor(): int
    {
        return (int) config('content-backup.queue.retry_after');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->dontRelease()
                ->expireAfter($this->uniqueFor()),
        ];
    }

    public function handle(ContentBackupPublisher $publisher): void
    {
        $report = $publisher->publish();

        Log::info('Content backup published.', [
            'date' => $report->date,
            'files' => $report->files,
            'bytes' => $report->bytes,
            'uploaded' => $report->uploaded,
            'copied' => $report->copied,
            'deleted' => $report->deleted,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Content backup failed.', [
            'message' => $exception?->getMessage(),
        ]);
    }
}
