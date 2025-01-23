<?php

declare(strict_types=1);

namespace Laminas\Cache\Storage\Adapter\Filesystem;

use Laminas\Cache\Exception\RuntimeException;
use Laminas\Cache\Storage\Adapter\Filesystem\Exception\MetadataException;
use Laminas\Cache\Storage\Adapter\Filesystem\Exception\UnlinkException;

interface FilesystemInteractionInterface
{
    /**
     * @throws UnlinkException If the file could not be deleted and still exists.
     */
    public function delete(string $file): bool;

    /**
     * @throws RuntimeException If the file could not be written or locked.
     */
    public function write(
        string $file,
        string $contents,
        ?int $umask,
        ?int $permissions,
        bool $lock,
        bool $block,
        ?bool &$wouldBlock
    ): bool;

    /**
     * @throws RuntimeException If the file could not be read or locked.
     */
    public function getFirstLineOfFile(string $file, bool $lock, bool $block, ?bool &$wouldBlock): string;

    /**
     * @throws RuntimeException If the file could not be read or locked.
     */
    public function read(string $file, bool $lock, bool $block, ?bool &$wouldBlock): string;

    public function exists(string $file): bool;

    /**
     * @throws MetadataException If the metadata could not be read.
     */
    public function lastModifiedTime(string $file): int;

    /**
     * @throws MetadataException If the metadata could not be read.
     */
    public function lastAccessedTime(string $file): int;

    /**
     * @throws MetadataException If the metadata could not be read.
     */
    public function createdTime(string $file): int;

    /**
     * @throws MetadataException If the metadata could not be read.
     */
    public function filesize(string $file): int;

    public function clearStatCache(): void;

    /**
     * @throws RuntimeException If the free space could not be detected.
     */
    public function availableBytes(string $directory): int;

    /**
     * @throws RuntimeException If the total bytes could not be detected.
     */
    public function totalBytes(string $directory): int;

    public function createDirectory(
        string $directory,
        int $permissions,
        bool $recursive,
        ?int $umask = null
    ): void;
}
