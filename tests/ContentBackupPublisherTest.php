<?php

namespace Hftp\StatamicContentBackups\Tests;

use Carbon\Carbon;
use Hftp\StatamicContentBackups\ContentBackupPublisher;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ContentBackupPublisherTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/content-backup-'.uniqid();
        mkdir($this->root.'/content/collections/blog', 0777, true);
        mkdir($this->root.'/content/collections/members', 0777, true);

        config([
            'content-backup.base' => $this->root.'/content',
            'content-backup.sources' => ['collections/**/*.md'],
            'content-backup.retention' => 14,
            'content-backup.exclude' => [],
            'filesystems.disks.content_backups' => [
                'driver' => 'local',
            ],
        ]);

        Storage::fake('content_backups');
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_published_and_unpublished_markdown_is_stored_under_the_utc_date_and_latest(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 12:00:00', 'UTC'));
        $this->writeFile('collections/blog/post.md', "---\ntitle: Hello\n---\n");
        $this->writeFile('collections/blog/draft.md', "---\npublished: false\n---\n");
        $this->writeFile('collections/notes.txt', 'not markdown');

        $report = $this->publisher()->publish();
        $disk = Storage::disk('content_backups');

        $this->assertSame('2026-11-17T12-00-00Z', $report->date);
        $this->assertSame(2, $report->files);
        $this->assertSame(2, $report->uploaded);
        $this->assertSame("---\npublished: false\n---\n", $disk->get('latest/collections/blog/draft.md'));
        $this->assertSame("---\ntitle: Hello\n---\n", $disk->get('2026-11-17T12-00-00Z/collections/blog/post.md'));
        $this->assertFalse($disk->exists('latest/collections/notes.txt'));
        $this->assertFalse($disk->exists('2026-11-17T12-00-00Z/collections/notes.txt'));
    }

    public function test_manifests_report_the_file_count_bytes_and_date(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 07:05:00', 'UTC'));
        $this->writeFile('collections/blog/post.md', 'hello');

        $this->publisher()->publish();

        $disk = Storage::disk('content_backups');
        $current = json_decode($disk->get('current.json'), true);
        $latest = json_decode($disk->get('latest/_manifest.json'), true);
        $dated = json_decode($disk->get('2026-11-17T07-05-00Z/_manifest.json'), true);

        $expected = [
            'date' => '2026-11-17T07-05-00Z',
            'generated_at' => '2026-11-17T07:05:00Z',
            'files' => 1,
            'bytes' => 5,
        ];

        $this->assertSame($expected, $current);
        $this->assertSame($expected, $latest);
        $this->assertSame([...$expected, 'changed' => ['collections/blog/post.md'], 'removed' => []], $dated);
    }

    public function test_sources_can_mix_markdown_and_yaml_globs(): void
    {
        config(['content-backup.sources' => [
            'collections/**/*.md',
            'taxonomies/**/*.yaml',
        ]]);
        $this->writeFile('collections/blog/post.md', 'post');
        $this->writeFile('collections/blog/nested/part.md', 'part');
        $this->writeFile('collections/blog/meta.yaml', 'meta');
        $this->writeFile('taxonomies/topics/topic.yaml', 'topic');
        $this->writeFile('taxonomies/topics/note.md', 'note');

        $this->publisher()->publish();

        $disk = Storage::disk('content_backups');

        $this->assertSame('post', $disk->get('latest/collections/blog/post.md'));
        $this->assertSame('part', $disk->get('latest/collections/blog/nested/part.md'));
        $this->assertSame('topic', $disk->get('latest/taxonomies/topics/topic.yaml'));
        $this->assertFalse($disk->exists('latest/collections/blog/meta.yaml'));
        $this->assertFalse($disk->exists('latest/taxonomies/topics/note.md'));
    }

    public function test_a_single_star_does_not_cross_directories(): void
    {
        config(['content-backup.sources' => ['collections/blog/*.md']]);
        $this->writeFile('collections/blog/post.md', 'post');
        $this->writeFile('collections/blog/nested/part.md', 'part');

        $this->publisher()->publish();

        $disk = Storage::disk('content_backups');

        $this->assertSame('post', $disk->get('latest/collections/blog/post.md'));
        $this->assertFalse($disk->exists('latest/collections/blog/nested/part.md'));
    }

    public function test_excluded_prefixes_are_left_out(): void
    {
        config(['content-backup.exclude' => ['collections/members']]);
        $this->writeFile('collections/blog/post.md', 'post');
        $this->writeFile('collections/members/person.md', 'secret');

        $this->publisher()->publish();

        $disk = Storage::disk('content_backups');

        $this->assertTrue($disk->exists('latest/collections/blog/post.md'));
        $this->assertFalse($disk->exists('latest/collections/members/person.md'));
    }

    public function test_a_removed_file_leaves_the_earlier_snapshot_and_drops_out_of_latest(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 12:00:00', 'UTC'));
        $this->writeFile('collections/blog/kept.md', 'kept');
        $this->writeFile('collections/blog/gone.md', 'gone');
        $this->publisher()->publish();

        unlink($this->root.'/content/collections/blog/gone.md');
        $this->travelTo(Carbon::parse('2026-11-17 18:00:00', 'UTC'));
        $report = $this->publisher()->publish();

        $disk = Storage::disk('content_backups');

        $this->assertSame(1, $report->deleted);
        $this->assertSame(1, $report->copied);
        $this->assertSame(0, $report->uploaded);
        $this->assertSame('gone', $disk->get('2026-11-17T12-00-00Z/collections/blog/gone.md'));
        $this->assertSame('gone', $disk->get('2026-11-17T18-00-00Z/collections/blog/gone.md'));
        $this->assertFalse($disk->exists('2026-11-17T18-00-00Z/collections/blog/kept.md'));
        $this->assertFalse($disk->exists('latest/collections/blog/gone.md'));
        $this->assertSame('kept', $disk->get('latest/collections/blog/kept.md'));
        $this->assertSame(1, json_decode($disk->get('current.json'), true)['files']);
    }

    public function test_retention_deletes_older_date_prefixes_and_leaves_latest(): void
    {
        config(['content-backup.retention' => 1]);
        $this->writeFile('collections/blog/post.md', 'hello');

        $this->travelTo(Carbon::parse('2026-11-17 12:00:00', 'UTC'));
        $this->publisher()->publish();

        $this->travelTo(Carbon::parse('2026-11-18 12:00:00', 'UTC'));
        $this->publisher()->publish();

        $disk = Storage::disk('content_backups');
        $directories = $disk->directories();
        sort($directories);

        $this->assertSame(['2026-11-18T12-00-00Z', 'latest'], $directories);
        $this->assertSame('hello', $disk->get('latest/collections/blog/post.md'));
        $this->assertSame('2026-11-18T12-00-00Z', json_decode($disk->get('current.json'), true)['date']);
    }

    public function test_an_unchanged_file_stays_in_latest_and_is_not_copied_again(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 12:00:00', 'UTC'));
        $this->writeFile('collections/blog/post.md', 'hello');
        $this->writeFile('collections/blog/other.md', 'other');

        $first = $this->publisher()->publish();
        $this->travelTo(Carbon::parse('2026-11-17 18:00:00', 'UTC'));
        $second = $this->publisher()->publish();

        $disk = Storage::disk('content_backups');

        $this->assertSame('hello', $disk->get('latest/collections/blog/post.md'));
        $this->assertSame('hello', $disk->get('2026-11-17T12-00-00Z/collections/blog/post.md'));
        $this->assertFalse($disk->exists('2026-11-17T18-00-00Z/collections/blog/post.md'));
        $this->assertFalse($disk->exists('2026-11-17T18-00-00Z/collections/blog/other.md'));
        $this->assertSame(2, $first->uploaded);
        $this->assertSame(2, $first->copied);
        $this->assertSame(0, $second->uploaded);
        $this->assertSame(0, $second->copied);
    }

    public function test_a_changed_file_is_uploaded(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 12:00:00', 'UTC'));
        $this->writeFile('collections/blog/post.md', 'hello');
        $this->writeFile('collections/blog/other.md', 'other');
        $this->publisher()->publish();

        $this->writeFile('collections/blog/post.md', 'hello!');
        $this->travelTo(Carbon::parse('2026-11-17 18:00:00', 'UTC'));
        $report = $this->publisher()->publish();

        $disk = Storage::disk('content_backups');
        $this->assertSame(1, $report->uploaded);
        $this->assertSame(1, $report->copied);
        $this->assertSame('hello', $disk->get('2026-11-17T12-00-00Z/collections/blog/post.md'));
        $this->assertSame('hello!', $disk->get('2026-11-17T18-00-00Z/collections/blog/post.md'));
        $this->assertFalse($disk->exists('2026-11-17T18-00-00Z/collections/blog/other.md'));
        $this->assertSame('hello!', $disk->get('latest/collections/blog/post.md'));
    }

    public function test_the_date_prefix_uses_utc_when_eastern_time_is_still_the_previous_day(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 23:30:00', 'America/New_York'));
        $this->writeFile('collections/blog/post.md', 'hello');

        $report = $this->publisher()->publish();

        $this->assertSame('2026-11-18T04-30-00Z', $report->date);
        $this->assertTrue(Storage::disk('content_backups')->exists('2026-11-18T04-30-00Z/collections/blog/post.md'));
    }

    public function test_publish_fails_when_the_source_directory_is_missing(): void
    {
        config(['content-backup.sources' => ['missing/**/*.md']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not a directory');

        $this->publisher()->publish();
    }

    public function test_publish_fails_when_the_disk_is_not_configured(): void
    {
        config(['filesystems.disks.content_backups' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Content backup disk [content_backups] is not configured.');

        $this->publisher()->publish();
    }

    public function test_publish_fails_when_the_disk_is_not_s3(): void
    {
        config(['filesystems.disks.content_backups.driver' => 'ftp']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Content backup disk [content_backups] must use the s3 driver.');

        $this->publisher()->publish();
    }

    public function test_publish_fails_when_an_s3_disk_has_no_root(): void
    {
        config([
            'filesystems.disks.content_backups' => [
                'driver' => 's3',
                'root' => '',
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Content backup disk [content_backups] must set a root.');

        $this->publisher()->publish();
    }

    public function test_publish_fails_when_a_source_is_outside_the_content_base(): void
    {
        $outside = sys_get_temp_dir().'/content-backup-outside-'.uniqid();
        mkdir($outside.'/blog', 0777, true);
        file_put_contents($outside.'/blog/post.md', 'outside');
        config(['content-backup.sources' => [$outside.'/**/*.md']]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('is outside');

            $this->publisher()->publish();
        } finally {
            $this->deleteDirectory($outside);
        }
    }

    public function test_publish_fails_when_two_sources_share_a_relative_path(): void
    {
        $this->writeFile('collections/blog/post.md', 'first');
        config(['content-backup.sources' => [
            'collections/**/*.md',
            'collections/blog/*.md',
        ]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('collections/blog/post.md');

        $this->publisher()->publish();
    }

    public function test_the_command_publishes_the_snapshot(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 12:00:00', 'UTC'));
        $this->writeFile('collections/blog/post.md', 'hello');

        $this->artisan('content-backup:run')
            ->expectsOutputToContain('Published 1 files')
            ->assertSuccessful();

        $this->assertSame('hello', Storage::disk('content_backups')->get('latest/collections/blog/post.md'));
    }

    private function publisher(): ContentBackupPublisher
    {
        return $this->app->make(ContentBackupPublisher::class);
    }

    private function writeFile(string $path, string $contents): void
    {
        $absolute = $this->root.'/content/'.$path;
        $directory = dirname($absolute);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($absolute, $contents);
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.'/'.$item;

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
