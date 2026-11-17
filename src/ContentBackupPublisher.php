<?php

declare(strict_types=1);

namespace Hftp\StatamicContentBackups;

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToListContents;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class ContentBackupPublisher
{
    public function publish(): BackupReport
    {
        $disk = $this->disk();
        $files = $this->files();
        $date = now()->utc()->format('Y-m-d\TH-i-s\Z');
        $generatedAt = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $latestChecksums = $this->checksums($disk, 'latest');

        $uploaded = 0;
        $copied = 0;
        $keep = [];

        foreach ($files as $file) {
            $keep[$file['key']] = true;
            $destination = $date.'/'.$file['key'];

            if (Etag::matchesContent($file['md5'], $latestChecksums[$file['key']] ?? null)) {
                $this->copy($disk, 'latest/'.$file['key'], $destination);
                $copied++;

                continue;
            }

            $this->write($disk, $destination, $file['absolute_path']);
            $uploaded++;
        }

        $deleted = $this->deleteMissing($disk, $date, $keep);

        $payload = [
            'date' => $date,
            'generated_at' => $generatedAt,
            'files' => count($files),
            'bytes' => array_sum(array_column($files, 'bytes')),
        ];

        $this->writeJson($disk, $date.'/_manifest.json', $payload);

        foreach ($files as $file) {
            if (Etag::matchesContent($file['md5'], $latestChecksums[$file['key']] ?? null)) {
                continue;
            }

            $this->copy($disk, $date.'/'.$file['key'], 'latest/'.$file['key']);
            $copied++;
        }

        $deleted += $this->deleteMissing($disk, 'latest', $keep);
        $this->writeJson($disk, 'latest/_manifest.json', $payload);
        $this->writeJson($disk, 'current.json', $payload);
        $this->prune($disk, (int) config('content-backup.retention'));

        return new BackupReport(
            date: $date,
            files: $payload['files'],
            bytes: $payload['bytes'],
            uploaded: $uploaded,
            copied: $copied,
            deleted: $deleted,
        );
    }

    private function disk(): FilesystemAdapter
    {
        $name = (string) config('content-backup.disk');
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
        $paths = config('content-backup.paths');

        if (! is_array($paths) || $paths === []) {
            throw new RuntimeException('Content backup has no source paths configured.');
        }

        $extensions = array_map(
            fn (mixed $extension): string => strtolower((string) $extension),
            (array) config('content-backup.extensions', ['md']),
        );

        $files = [];

        foreach ($paths as $path) {
            $directory = (string) $path;

            if (! is_dir($directory)) {
                throw new RuntimeException("Content backup source [{$directory}] is not a directory.");
            }

            $finder = (new Finder)
                ->files()
                ->in($directory)
                ->ignoreDotFiles(true)
                ->ignoreVCS(true)
                ->sortByName();

            foreach ($extensions as $extension) {
                $finder->name('*.'.$extension);
            }

            foreach ($finder as $file) {
                $key = $this->key($file);

                if ($this->excluded($key) || ! in_array(strtolower($file->getExtension()), $extensions, true)) {
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
            throw new RuntimeException('Content backup found no files to publish. Check content-backup.paths, extensions, and exclude.');
        }

        ksort($files);

        return array_values($files);
    }

    private function key(SplFileInfo $file): string
    {
        return str_replace('\\', '/', $file->getRelativePathname());
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

    private function copy(FilesystemAdapter $disk, string $from, string $to): void
    {
        if ($disk->copy($from, $to) === false) {
            throw new RuntimeException("Content backup could not copy [{$from}] to [{$to}].");
        }
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
     * @param  array<string, true>  $keep
     */
    private function deleteMissing(FilesystemAdapter $disk, string $directory, array $keep): int
    {
        try {
            $paths = $disk->allFiles($directory);
        } catch (UnableToListContents) {
            return 0;
        }

        $deleted = 0;

        foreach ($paths as $path) {
            $relative = $this->relative($path, $directory);

            if ($relative === '' || $relative === '_manifest.json' || isset($keep[$relative])) {
                continue;
            }

            if ($disk->delete($path) === false) {
                throw new RuntimeException("Content backup could not delete [{$path}].");
            }

            $deleted++;
        }

        return $deleted;
    }

    /**
     * @param  array{date: string, generated_at: string, files: int, bytes: int}  $payload
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
