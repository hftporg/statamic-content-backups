# hftp/statamic-content-backups

Publishes Statamic collection files to a private S3 bucket every night. `latest/` is the current tree. Older nights are UTC date folders. `current.json` is written last and is the signal that the tree is complete.

The package reads files from disk. It does not load Statamic entries.

## Install

Add the private repository and require the package:

```bash
composer config repositories.statamic-content-backups vcs git@github.com:hftporg/statamic-content-backups.git
composer config preferred-install.hftp/statamic-content-backups source
composer require hftp/statamic-content-backups
```

Install from source over SSH. A GitHub token that cannot read this private repository makes Composer's zip download return 404, and Composer then refuses the git fallback.

Publish the config when a site needs paths or excludes that differ from the defaults:

```bash
php artisan vendor:publish --tag=content-backup-config
```

## Environment

```dotenv
CONTENT_BACKUP_ENABLED=true
CONTENT_BACKUP_RETENTION=14
CONTENT_BACKUP_TIME=02:00
CONTENT_BACKUP_TIMEZONE=America/New_York
CONTENT_BACKUP_BUCKET=
CONTENT_BACKUP_AWS_ACCESS_KEY_ID=
CONTENT_BACKUP_AWS_SECRET_ACCESS_KEY=
CONTENT_BACKUP_AWS_DEFAULT_REGION=us-east-1
CONTENT_BACKUP_PREFIX=
```

`CONTENT_BACKUP_PREFIX` is the disk root. Sites that share a bucket set a different prefix. Leave it empty when the bucket is dedicated to one site.

The default source is `content/collections` and the default extension is `md`. `config/content-backup.php` can add paths or set `exclude` prefixes such as `members`.

Date folders are UTC. The schedule timezone only decides when the job starts.

## What the ingest reads

Wait until `current.json` matches the tree you are reading.

```json
{
  "date": "2026-11-17",
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

The command runs even when `CONTENT_BACKUP_ENABLED` is false. The schedule does not. The schedule also stays off until `CONTENT_BACKUP_BUCKET` is set, so a site without credentials does not enqueue a failing job.
