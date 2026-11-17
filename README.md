# hftp/statamic-content-backups

Publishes Statamic collection files to a private S3 bucket. `latest/` is the current tree. Each run also writes a new folder named with the full UTC time, such as `2026-11-17T07-05-00Z`, so earlier runs stay intact. `current.json` is written last and is the signal that the tree is complete.

The package reads files from disk. It does not load Statamic entries.

## Install

Add the repository and require the package:

```bash
composer config repositories.statamic-content-backups vcs https://github.com/hftporg/statamic-content-backups.git
composer require hftp/statamic-content-backups:dev-main
```

Require `dev-main` until the package is tagged `v1.0.0`.

Publish the config when a site needs paths or excludes that differ from the defaults:

```bash
php artisan vendor:publish --tag=content-backup-config
```

## Environment

```dotenv
CONTENT_BACKUP_RETENTION=14
CONTENT_BACKUP_BUCKET=
CONTENT_BACKUP_AWS_ACCESS_KEY_ID=
CONTENT_BACKUP_AWS_SECRET_ACCESS_KEY=
CONTENT_BACKUP_AWS_DEFAULT_REGION=us-east-1
CONTENT_BACKUP_PREFIX=
```

`CONTENT_BACKUP_PREFIX` is the disk root. Sites that share a bucket set a different prefix. Leave it empty when the bucket is dedicated to one site.

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
