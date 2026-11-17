# hftp/statamic-content-backups

Publishes Statamic collection files to an S3-compatible disk. `latest/` is the current tree. Each run also writes a new folder named with the full UTC time, such as `2026-11-17T07-05-00Z`, so earlier runs stay intact. `current.json` is written last and is the signal that the tree is complete.

The package reads files from disk. It does not load Statamic entries.

## Install

Add the repository and require the package:

```bash
composer config repositories.statamic-content-backups vcs https://github.com/hftporg/statamic-content-backups.git
composer require hftp/statamic-content-backups:dev-main
```

Publish the config when a site needs paths or excludes that differ from the defaults:

```bash
php artisan vendor:publish --tag=content-backup-config
```

## Disk

Point `content-backup.disk` at an `s3` disk in `config/filesystems.php`. The package reads that disk and does not take bucket credentials. DigitalOcean Spaces uses this driver with its own endpoint. Set `root` when sites share a bucket and need different prefixes. Leave `root` empty for a dedicated bucket. `throw` should be true.

```php
'content_backups' => [
    'driver' => 's3',
    'key' => env('CONTENT_BACKUP_ACCESS_KEY_ID'),
    'secret' => env('CONTENT_BACKUP_SECRET_ACCESS_KEY'),
    'region' => env('CONTENT_BACKUP_DEFAULT_REGION'),
    'bucket' => env('CONTENT_BACKUP_BUCKET'),
    'endpoint' => env('CONTENT_BACKUP_ENDPOINT'),
    'url' => env('CONTENT_BACKUP_URL'),
    'use_path_style_endpoint' => false,
    'root' => env('CONTENT_BACKUP_ROOT', ''),
    'visibility' => 'private',
    'throw' => true,
],
```

```dotenv
CONTENT_BACKUP_RETENTION=14
CONTENT_BACKUP_DISK=content_backups
```

The default source is `content/collections` and the default extension is `md`. `config/content-backup.php` can add paths or set `exclude` prefixes such as `members`.

`CONTENT_BACKUP_RETENTION` counts snapshot folders. A run every 6 hours with a retention of 14 keeps 14 snapshots, about three and a half days. The package does not register a schedule. Each site decides when it runs:

```php
use Hftp\StatamicContentBackups\Jobs\PublishContentBackup;
use Illuminate\Support\Facades\Schedule;

Schedule::job(PublishContentBackup::class)
    ->dailyAt('02:00')
    ->onOneServer()
    ->withoutOverlapping(120);
```

`dailyAt()` uses `config('app.timezone')`. Add `->timezone('America/New_York')` when that zone is different from the one you want the clock read in.

## What the ingest reads

Wait until `current.json` matches the tree you are reading.

```json
{
  "date": "2026-11-17T07-05-00Z",
  "generated_at": "2026-11-17T07:05:00Z",
  "files": 5767,
  "bytes": 34000000
}
```

`published: false` stays in the file. A path that disappears from `latest/` was deleted. This package does not interpret either signal.

The bucket is private. Grant the ingest read access to the bucket or prefix.

## Manual run

```bash
php artisan content-backup:run
```

The command runs the publish in the current process. The scheduled job is the one that uses the `content-backup` queue.
