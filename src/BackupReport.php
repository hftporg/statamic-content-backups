<?php

namespace Hftp\StatamicContentBackups;

final class BackupReport
{
    public function __construct(
        public string $date,
        public int $files,
        public int $bytes,
        public int $uploaded,
        public int $copied,
        public int $deleted,
    ) {}
}
