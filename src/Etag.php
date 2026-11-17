<?php

namespace Hftp\StatamicContentBackups;

/**
 * A single-part S3 ETag is the object's MD5. Multipart ETags contain a
 * hyphen and are not comparable to a local hash.
 */
final class Etag
{
    public static function matchesContent(string $md5, ?string $etag): bool
    {
        if ($etag === null || $etag === '' || str_contains($etag, '-')) {
            return false;
        }

        return hash_equals($md5, strtolower($etag));
    }
}
