<?php

declare(strict_types=1);

namespace Laminas\Cache\Storage\Adapter\Filesystem;

final class Metadata
{
    public function __construct(
        public readonly int|null $lastAccessTime,
        public readonly int|null $creationTime,
        public readonly int|null $lastModifiedTime,
        public readonly int|null $filesize,
        public readonly string $filespec,
    ) {
    }
}
