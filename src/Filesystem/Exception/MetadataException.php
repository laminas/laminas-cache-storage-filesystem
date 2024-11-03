<?php

declare(strict_types=1);

namespace Laminas\Cache\Storage\Adapter\Filesystem\Exception;

use ErrorException;
use Laminas\Cache\Exception\RuntimeException;

use function sprintf;

final class MetadataException extends RuntimeException
{
    public const METADATA_ATIME    = 'atime';
    public const METADATA_CTIME    = 'ctime';
    public const METADATA_MTIME    = 'mtime';
    public const METADATA_FILESIZE = 'filesize';

    private readonly ErrorException $error;

    /**
     * @psalm-param MetadataException::METADATA_* $metadata
     */
    public function __construct(string $metadata, ErrorException $error)
    {
        parent::__construct(sprintf('Could not detected metadata "%s"', $metadata), 0, $error);
        $this->error = $error;
    }

    public function getErrorSeverity(): int
    {
        return $this->error->getSeverity();
    }

    public function getErrorMessage(): string
    {
        return $this->error->getMessage();
    }

    public function getErrorFile(): string
    {
        return $this->error->getFile();
    }

    public function getErrorLine(): int
    {
        return $this->error->getLine();
    }
}
