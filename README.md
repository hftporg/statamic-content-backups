# hftp/statamic-content-backups

Publishes Statamic collection files to an S3-compatible disk. `latest/` is the current tree. Each run also writes a folder named with the full UTC time, such as `2026-11-17T07-05-00Z`. That folder records the run: the files that changed, the files that were removed, and a manifest. Unchanged files stay in `latest/`. `current.json` is written last and is the signal that `latest/` is complete.

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

Point `content-backup.disk` at an `s3` disk in `config/filesystems.php`. The package reads that disk and does not take bucket credentials. DigitalOcean Spaces uses this driver with its own endpoint. `root` is this site's prefix. One bucket holds backups for multiple sites, and an empty root is rejected. `throw` should be true.

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
    'root' => env('CONTENT_BACKUP_ROOT'),
    'visibility' => 'private',
    'throw' => true,
],
```

```dotenv
CONTENT_BACKUP_RETENTION=14
CONTENT_BACKUP_DISK=content_backups
CONTENT_BACKUP_ROOT=
```

Sources are globs relative to `content/`. The default is `collections/**/*.md`. Add another glob for a different directory or extension, such as `taxonomies/**/*.yaml`. `**` crosses directories and `*` stays inside one directory. Keys keep that path, so `content/collections/blog/post.md` is stored as `collections/blog/post.md`. `exclude` prefixes such as `collections/members` are also relative to `content/`.

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

`published: false` stays in the file. A path that disappears from `latest/` was deleted. A timestamp folder is the change for that run, not a second copy of the tree. Its manifest lists `changed` and `removed`. This package does not interpret `published`.

The bucket is private. Grant the ingest read access to that site's prefix.

## Manual run

```bash
php artisan content-backup:run
```

The command runs the publish in the current process. The scheduled job is the one that uses the `content-backup` queue.
