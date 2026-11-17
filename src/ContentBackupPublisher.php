<?php

namespace Hftp\StatamicContentBackups;

use Aws\CommandInterface;
use Aws\CommandPool;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToListContents;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

class ContentBackupPublisher
{
    public function publish(): BackupReport
    {
        $disk = $this->disk();
        $files = $this->files();
        $date = now()->utc()->format('Y-m-d\TH-i-s\Z');
        $generatedAt = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $latestChecksums = $this->checksums($disk, 'latest');

        $changed = [];
        $keep = [];

        foreach ($files as $file) {
            $keep[$file['key']] = true;

            if (! Etag::matchesContent($file['md5'], $latestChecksums[$file['key']] ?? null)) {
                $changed[] = $file;
            }
        }

        $removed = [];

        foreach (array_keys($latestChecksums) as $key) {
            if (! isset($keep[$key])) {
                $removed[] = $key;
            }
        }

        $this->copyAll($disk, array_map(
            fn (string $key): array => ['from' => 'latest/'.$key, 'to' => $date.'/'.$key],
            $removed,
        ));
        $this->writeAll($disk, array_map(
            fn (array $file): array => ['key' => $date.'/'.$file['key'], 'path' => $file['absolute_path']],
            $changed,
        ));

        $summary = [
            'date' => $date,
            'generated_at' => $generatedAt,
            'files' => count($files),
            'bytes' => array_sum(array_column($files, 'bytes')),
        ];

        $this->writeJson($disk, $date.'/_manifest.json', [
            ...$summary,
            'changed' => array_column($changed, 'key'),
            'removed' => $removed,
        ]);
        $this->copyAll($disk, array_map(
            fn (array $file): array => ['from' => $date.'/'.$file['key'], 'to' => 'latest/'.$file['key']],
            $changed,
        ));
        $this->deleteAll($disk, array_map(
            fn (string $key): string => 'latest/'.$key,
            $removed,
        ));
        $this->writeJson($disk, 'latest/_manifest.json', $summary);
        $this->writeJson($disk, 'current.json', $summary);
        $this->prune($disk, (int) config('content-backup.retention'));

        return new BackupReport(
            date: $date,
            files: $summary['files'],
            bytes: $summary['bytes'],
            uploaded: count($changed),
            copied: count($changed) + count($removed),
            deleted: count($removed),
        );
    }

    private function disk(): FilesystemAdapter
    {
        $name = (string) config('content-backup.disk');
        $configured = config("filesystems.disks.{$name}");

        if (! is_array($configured)) {
            throw new RuntimeException("Content backup disk [{$name}] is not configured.");
        }

        $driver = $configured['driver'] ?? null;

        if ($driver !== 's3' && ! (app()->runningUnitTests() && $driver === 'local')) {
            throw new RuntimeException("Content backup disk [{$name}] must use the s3 driver.");
        }

        if ($driver === 's3' && trim((string) ($configured['root'] ?? ''), '/') === '') {
            throw new RuntimeException("Content backup disk [{$name}] must set a root. An empty root would store objects at the bucket root.");
        }

        $disk = Storage::disk($name);

        if (! $disk instanceof FilesystemAdapter) {
            throw new RuntimeException("Content backup disk [{$name}] is not a filesystem adapter.");
        }

        return $disk;
    }

    /**
     * @return list<array{key: string, absolute_path: string, bytes: int, md5: string}>
     */
    private function files(): array
    {
        $sources = config('content-backup.sources');

        if (! is_array($sources) || $sources === []) {
            throw new RuntimeException('Content backup has no sources configured.');
        }

        $base = $this->basePath();
        $files = [];

        foreach ($sources as $source) {
            $pattern = $this->sourcePattern((string) $source, $base);
            $regex = $this->globToRegex($pattern);

            $finder = (new Finder)
                ->files()
                ->in($this->sourceDirectory($base, $pattern))
                ->ignoreDotFiles(true)
                ->ignoreVCS(true)
                ->sortByName();

            foreach ($finder as $file) {
                $key = $this->key($file, $base);

                if (preg_match($regex, $key) !== 1 || $this->excluded($key)) {
                    continue;
                }

                if (isset($files[$key])) {
                    throw new RuntimeException("Content backup path [{$key}] is produced by more than one source.");
                }

                $absolutePath = $file->getRealPath();

                if ($absolutePath === false) {
                    throw new RuntimeException("Content backup could not read [{$file->getPathname()}].");
                }

                $md5 = hash_file('md5', $absolutePath);

                if ($md5 === false) {
                    throw new RuntimeException("Content backup could not hash [{$absolutePath}].");
                }

                $files[$key] = [
                    'key' => $key,
                    'absolute_path' => $absolutePath,
                    'bytes' => $file->getSize(),
                    'md5' => $md5,
                ];
            }
        }

        if ($files === []) {
            throw new RuntimeException('Content backup found no files to publish. Check content-backup.sources and exclude.');
        }

        ksort($files);

        return array_values($files);
    }

    private function sourcePattern(string $source, string $base): string
    {
        $source = trim(str_replace('\\', '/', $source));

        if ($source === '') {
            throw new RuntimeException('Content backup source is empty.');
        }

        if (str_contains($source, '..')) {
            throw new RuntimeException("Content backup source [{$source}] cannot contain '..'.");
        }

        $configured = rtrim(str_replace('\\', '/', (string) config('content-backup.base')), '/');

        foreach ([$configured, $base] as $prefix) {
            if ($prefix !== '' && ($source === $prefix || str_starts_with($source, $prefix.'/'))) {
                $source = ltrim(substr($source, strlen($prefix)), '/');

                break;
            }
        }

        if ($this->isAbsolute($source)) {
            throw new RuntimeException("Content backup source [{$source}] is outside [{$base}].");
        }

        return ltrim($source, '/');
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1;
    }

    private function sourceDirectory(string $base, string $pattern): string
    {
        $prefix = [];

        foreach (explode('/', $pattern) as $segment) {
            if ($segment === '' || strpbrk($segment, '*?') !== false) {
                break;
            }

            $prefix[] = $segment;
        }

        $directory = $prefix === [] ? $base : $base.'/'.implode('/', $prefix);

        if (! is_dir($directory)) {
            throw new RuntimeException("Content backup source [{$directory}] is not a directory.");
        }

        return $directory;
    }

    private function globToRegex(string $glob): string
    {
        $regex = '';
        $length = strlen($glob);

        for ($offset = 0; $offset < $length; $offset++) {
            $character = $glob[$offset];

            if ($character === '*' && ($glob[$offset + 1] ?? '') === '*') {
                $offset++;

                if (($glob[$offset + 1] ?? '') === '/') {
                    $offset++;
                    $regex .= '(?:.*/)?';

                    continue;
                }

                $regex .= '.*';

                continue;
            }

            if ($character === '*') {
                $regex .= '[^/]*';

                continue;
            }

            if ($character === '?') {
                $regex .= '[^/]';

                continue;
            }

            $regex .= preg_quote($character, '#');
        }

        return '#^'.$regex.'$#';
    }

    private function basePath(): string
    {
        $configured = config('content-backup.base');

        if (! is_string($configured) || $configured === '') {
            throw new RuntimeException('Content backup has no content base configured.');
        }

        $base = realpath($configured);

        if ($base === false || ! is_dir($base)) {
            throw new RuntimeException("Content backup base [{$configured}] is not a directory.");
        }

        return str_replace('\\', '/', $base);
    }

    private function key(SplFileInfo $file, string $base): string
    {
        $absolute = $file->getRealPath() ?: $file->getPathname();
        $path = str_replace('\\', '/', $absolute);

        if ($path !== $base && ! str_starts_with($path, $base.'/')) {
            throw new RuntimeException("Content backup file [{$path}] is outside [{$base}].");
        }

        return ltrim(substr($path, strlen($base)), '/');
    }

    private function excluded(string $key): bool
    {
        foreach ((array) config('content-backup.exclude', []) as $prefix) {
            $prefix = trim(str_replace('\\', '/', (string) $prefix), '/');

            if ($prefix === '') {
                continue;
            }

            if ($key === $prefix || str_starts_with($key, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function checksums(FilesystemAdapter $disk, string $directory): array
    {
        if ($disk instanceof AwsS3V3Adapter) {
            return $this->s3Checksums($disk, $directory);
        }

        try {
            $paths = $disk->allFiles($directory);
        } catch (UnableToListContents) {
            return [];
        }

        $checksums = [];

        foreach ($paths as $path) {
            $relative = $this->relative($path, $directory);

            if ($relative === '' || $relative === '_manifest.json') {
                continue;
            }

            $checksum = $disk->checksum($path);

            if (is_string($checksum) && $checksum !== '') {
                $checksums[$relative] = $checksum;
            }
        }

        return $checksums;
    }

    /**
     * @return array<string, string>
     */
    private function s3Checksums(AwsS3V3Adapter $disk, string $directory): array
    {
        $config = $disk->getConfig();
        $root = trim((string) ($config['root'] ?? ''), '/');
        $directory = trim($directory, '/');
        $prefix = ($root === '' ? '' : $root.'/').$directory.'/';
        $client = $disk->getClient();
        $checksums = [];
        $continuation = null;

        do {
            $parameters = [
                'Bucket' => $config['bucket'],
                'Prefix' => $prefix,
            ];

            if ($continuation !== null) {
                $parameters['ContinuationToken'] = $continuation;
            }

            $result = $client->listObjectsV2($parameters);

            foreach ($result['Contents'] ?? [] as $object) {
                $relative = $this->relative((string) $object['Key'], $prefix);

                if ($relative === '' || $relative === '_manifest.json' || str_ends_with($relative, '/')) {
                    continue;
                }

                $checksums[$relative] = trim((string) $object['ETag'], '"');
            }

            $continuation = ($result['IsTruncated'] ?? false) ? $result['NextContinuationToken'] : null;
        } while ($continuation !== null);

        return $checksums;
    }

    private function relative(string $path, string $directory): string
    {
        $prefix = trim($directory, '/').'/';

        if (str_starts_with($path, $prefix)) {
            return substr($path, strlen($prefix));
        }

        return $path;
    }

    /**
     * @param  list<array{from: string, to: string}>  $copies
     */
    private function copyAll(FilesystemAdapter $disk, array $copies): void
    {
        if ($copies === []) {
            return;
        }

        if ($disk instanceof AwsS3V3Adapter) {
            $bucket = $this->bucket($disk);
            $client = $disk->getClient();

            $this->pooled($disk, array_map(
                fn (array $copy): CommandInterface => $client->getCommand('CopyObject', [
                    'Bucket' => $bucket,
                    'Key' => $this->objectKey($disk, $copy['to']),
                    'CopySource' => '/'.$bucket.'/'.rawurlencode($this->objectKey($disk, $copy['from'])),
                    'ACL' => 'private',
                    'MetadataDirective' => 'COPY',
                ]),
                $copies,
            ));

            return;
        }

        foreach ($copies as $copy) {
            if ($disk->copy($copy['from'], $copy['to']) === false) {
                throw new RuntimeException("Content backup could not copy [{$copy['from']}] to [{$copy['to']}].");
            }
        }
    }

    /**
     * @param  list<array{key: string, path: string}>  $writes
     */
    private function writeAll(FilesystemAdapter $disk, array $writes): void
    {
        if ($writes === []) {
            return;
        }

        if ($disk instanceof AwsS3V3Adapter) {
            $bucket = $this->bucket($disk);
            $client = $disk->getClient();

            $this->pooled($disk, array_map(
                fn (array $write): CommandInterface => $client->getCommand('PutObject', [
                    'Bucket' => $bucket,
                    'Key' => $this->objectKey($disk, $write['key']),
                    'SourceFile' => $write['path'],
                    'ACL' => 'private',
                ]),
                $writes,
            ));

            return;
        }

        foreach ($writes as $write) {
            $this->write($disk, $write['key'], $write['path']);
        }
    }

    /**
     * @param  list<string>  $keys
     */
    private function deleteAll(FilesystemAdapter $disk, array $keys): void
    {
        if ($keys === []) {
            return;
        }

        if ($disk instanceof AwsS3V3Adapter) {
            $client = $disk->getClient();
            $bucket = $this->bucket($disk);

            foreach (array_chunk($keys, 1000) as $chunk) {
                $result = $client->deleteObjects([
                    'Bucket' => $bucket,
                    'Delete' => [
                        'Objects' => array_map(
                            fn (string $key): array => ['Key' => $this->objectKey($disk, $key)],
                            $chunk,
                        ),
                        'Quiet' => true,
                    ],
                ]);

                $errors = $result['Errors'] ?? [];

                if ($errors !== []) {
                    $key = (string) ($errors[0]['Key'] ?? '');
                    $message = (string) ($errors[0]['Message'] ?? 'delete failed');

                    throw new RuntimeException("Content backup could not delete [{$key}]: {$message}");
                }
            }

            return;
        }

        foreach ($keys as $key) {
            if ($disk->delete($key) === false) {
                throw new RuntimeException("Content backup could not delete [{$key}].");
            }
        }
    }

    /**
     * @param  list<CommandInterface>  $commands
     */
    private function pooled(AwsS3V3Adapter $disk, array $commands): void
    {
        $failure = null;

        $pool = new CommandPool($disk->getClient(), $commands, [
            'concurrency' => max(1, (int) config('content-backup.concurrency', 24)),
            'rejected' => function (mixed $reason) use (&$failure): void {
                if ($failure === null) {
                    $failure = $reason;
                }
            },
        ]);

        $pool->promise()->wait();

        if ($failure instanceof Throwable) {
            throw new RuntimeException('Content backup could not complete an S3 request: '.$failure->getMessage(), previous: $failure);
        }
    }

    private function bucket(AwsS3V3Adapter $disk): string
    {
        $bucket = $disk->getConfig()['bucket'] ?? null;

        if (! is_string($bucket) || $bucket === '') {
            throw new RuntimeException('Content backup disk has no bucket.');
        }

        return $bucket;
    }

    private function objectKey(AwsS3V3Adapter $disk, string $key): string
    {
        $root = trim((string) ($disk->getConfig()['root'] ?? ''), '/');
        $key = ltrim($key, '/');

        return $root === '' ? $key : $root.'/'.$key;
    }

    private function write(FilesystemAdapter $disk, string $key, string $absolutePath): void
    {
        $stream = fopen($absolutePath, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Content backup could not read [{$absolutePath}].");
        }

        try {
            $written = $disk->writeStream($key, $stream, ['visibility' => 'private']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written === false) {
            throw new RuntimeException("Content backup could not write [{$key}].");
        }
    }

    /**
     * @param  array{date: string, generated_at: string, files: int, bytes: int, changed?: list<string>, removed?: list<string>}  $payload
     */
    private function writeJson(FilesystemAdapter $disk, string $path, array $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if ($disk->put($path, $json."\n", 'private') === false) {
            throw new RuntimeException("Content backup could not write [{$path}].");
        }
    }

    private function prune(FilesystemAdapter $disk, int $retention): void
    {
        $retention = max(0, $retention);

        try {
            $directories = $disk->directories();
        } catch (UnableToListContents) {
            return;
        }

        $dates = [];

        foreach ($directories as $directory) {
            $name = trim($directory, '/');

            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}-\d{2}-\d{2}Z$/', $name) === 1) {
                $dates[] = $name;
            }
        }

        sort($dates);

        foreach (array_slice($dates, 0, max(0, count($dates) - $retention)) as $directory) {
            if ($disk->deleteDirectory($directory) === false) {
                throw new RuntimeException("Content backup could not delete [{$directory}].");
            }
        }
    }
}
