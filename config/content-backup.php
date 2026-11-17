<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Sources
    |--------------------------------------------------------------------------
    |
    | Globs relative to the content directory. Each glob selects one set of
    | files, so one directory can contribute markdown while another
    | contributes yaml. ** crosses directories. A star stays inside one
    | directory. Keys keep the path under content/, and two globs must not
    | select the same file.
    |
    */

    'base' => base_path('content'),

    'sources' => [
        'collections/**/*.md',
    ],

    /*
    | Prefixes to skip, relative to the content directory, such as
    | "collections/members". A prefix matches that path and everything
    | inside it.
    |
    */

    'exclude' => [],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How many snapshot folders to keep. Each run writes a new folder named
    | with the full UTC time, so a later run does not overwrite an earlier
    | one. latest/ and current.json are never deleted.
    |
    */

    'retention' => (int) env('CONTENT_BACKUP_RETENTION', 14),

    /*
    |--------------------------------------------------------------------------
    | Disk
    |--------------------------------------------------------------------------
    |
    | Name of an s3 disk in the application's filesystems config. That disk
    | owns the bucket, endpoint, and credentials. Its root is this site's
    | prefix inside a bucket shared by multiple sites. An empty root is
    | rejected. The driver must be s3, which is what S3-compatible services
    | such as DigitalOcean Spaces use.
    |
    */

    'disk' => env('CONTENT_BACKUP_DISK', 'content_backups'),

    /*
    | How many S3 requests to run at once. The files are small, so the wait
    | is the round trip. 24 keeps a full upload moving without a large burst.
    |
    */

    'concurrency' => 24,

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | The worker timeout sits between the job timeout and retry_after so
    | Horizon can finish the job before the queue releases it to a second
    | worker. The connection is registered on the app's default queue driver.
    |
    */

    'queue' => [
        'connection' => 'content-backup',
        'name' => 'content-backup',
        'timeout' => (int) env('CONTENT_BACKUP_TIMEOUT', 1800),
        'worker_timeout' => (int) env('CONTENT_BACKUP_WORKER_TIMEOUT', 1860),
        'retry_after' => (int) env('CONTENT_BACKUP_RETRY_AFTER', 2100),
        'memory' => (int) env('CONTENT_BACKUP_MEMORY', 256),
    ],

];
