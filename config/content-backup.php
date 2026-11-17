<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Sources
    |--------------------------------------------------------------------------
    |
    | Directories whose files are copied. Object keys are the path relative
    | to each directory, so content/collections/blog/post.md is stored as
    | blog/post.md. Two directories must not produce the same key.
    |
    */

    'paths' => [
        base_path('content/collections'),
    ],

    'extensions' => ['md'],

    /*
    | Relative prefixes to skip, such as "members" or "staff_directory".
    | A prefix matches that path and everything inside it.
    |
    */

    'exclude' => [],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How many UTC date folders to keep. latest/ and current.json are never
    | deleted. Date folder names are always UTC.
    |
    */

    'retention' => (int) env('CONTENT_BACKUP_RETENTION', 14),

    'disk' => env('CONTENT_BACKUP_DISK', 'content_backups'),

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

    /*
    |--------------------------------------------------------------------------
    | Filesystem
    |--------------------------------------------------------------------------
    |
    | prefix is the disk root. Sites that share a bucket use a different
    | prefix (hftp, hitec, and so on). Leave it empty for a dedicated bucket.
    |
    */

    'filesystem' => [
        'key' => env('CONTENT_BACKUP_AWS_ACCESS_KEY_ID'),
        'secret' => env('CONTENT_BACKUP_AWS_SECRET_ACCESS_KEY'),
        'region' => env('CONTENT_BACKUP_AWS_DEFAULT_REGION', 'us-east-1'),
        'bucket' => env('CONTENT_BACKUP_BUCKET'),
        'prefix' => env('CONTENT_BACKUP_PREFIX', ''),
        'url' => env('CONTENT_BACKUP_AWS_URL'),
        'endpoint' => env('CONTENT_BACKUP_AWS_ENDPOINT'),
        'use_path_style_endpoint' => filter_var(
            env('CONTENT_BACKUP_AWS_USE_PATH_STYLE_ENDPOINT', false),
            FILTER_VALIDATE_BOOL,
        ),
    ],

];
