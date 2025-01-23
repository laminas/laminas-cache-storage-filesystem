<?php

declare(strict_types=1);

namespace Laminas\Cache\Storage\Adapter;

use Laminas\Cache\Exception;

use function assert;
use function is_dir;
use function is_readable;
use function is_string;
use function is_writable;
use function octdec;
use function realpath;
use function rtrim;
use function stripos;
use function strlen;
use function sys_get_temp_dir;

use const DIRECTORY_SEPARATOR;
use const PHP_OS;

final class FilesystemOptions extends AdapterOptions
{
    public const KEY_PATTERN = '/^[a-z0-9_\+\-\.]*$/Di';

    /**
     * Directory to store cache files
     *
     * @var string|null The cache directory
     */
    protected ?string $cacheDir = null;

    /**
     * Call clearstatcache enabled?
     */
    protected bool $clearStatCache = true;

    /**
     * How much sub-directaries should be created?
     */
    protected int $dirLevel = 1;

    /**
     * Permission creating new directories
     */
    protected false|int $dirPermission = 0700;

    /**
     * Lock files on writing
     */
    protected bool $fileLocking = true;

    /**
     * Permission creating new files
     */
    protected false|int $filePermission = 0600;

    /**
     * Overwrite default key pattern
     */
    protected string $keyPattern = self::KEY_PATTERN;

    /**
     * Namespace separator
     */
    protected string $namespaceSeparator = '-';

    /**
     * Don't get 'fileatime' as 'atime' on metadata
     */
    protected bool $noAtime = true;

    /**
     * Don't get 'filectime' as 'ctime' on metadata
     */
    protected bool $noCtime = true;

    /**
     * Umask to create files and directories
     */
    protected false|int $umask = false;

    /**
     * Allowed classes to unserialize
     *
     * @var list<class-string>|bool
     */
    protected bool|array $unserializableClasses = true;

    /**
     * @param iterable<string,mixed>|null $options
     */
    public function __construct(iterable|null $options = null)
    {
        // disable file/directory permissions by default on windows systems
        if (stripos(PHP_OS, 'WIN') === 0) {
            $this->filePermission = false;
            $this->dirPermission  = false;
        }

        parent::__construct($options);

        if ($this->cacheDir === null) {
            $this->setCacheDir(null);
        }
    }

    public function setKeyPattern(string $keyPattern): self
    {
        if ($keyPattern !== self::KEY_PATTERN) {
            throw new Exception\InvalidArgumentException(
                'Filesystem adapter does not allow to reconfigure the key pattern as unix filesystem has'
                . ' a concrete expectation on how files have to be named.'
            );
        }

        parent::setKeyPattern($keyPattern);
        return $this;
    }

    /**
     * @throws Exception\InvalidArgumentException
     */
    public function setCacheDir(?string $cacheDir): self
    {
        $cacheDir ??= sys_get_temp_dir();
        $cacheDir   = $this->normalizeCacheDirectory($cacheDir);

        if ($this->cacheDir === $cacheDir) {
            return $this;
        }

        $this->triggerOptionEvent('cache_dir', $cacheDir);
        $this->cacheDir = $cacheDir;
        return $this;
    }

    public function getCacheDir(): string
    {
        assert(is_string($this->cacheDir));

        return $this->cacheDir;
    }

    public function setClearStatCache(bool $clearStatCache): self
    {
        if ($this->clearStatCache === $clearStatCache) {
            return $this;
        }

        $this->triggerOptionEvent('clear_stat_cache', $clearStatCache);
        $this->clearStatCache = $clearStatCache;
        return $this;
    }

    public function getClearStatCache(): bool
    {
        return $this->clearStatCache;
    }

    /**
     * @throws Exception\InvalidArgumentException
     */
    public function setDirLevel(int $dirLevel): self
    {
        if ($dirLevel < 0 || $dirLevel > 16) {
            throw new Exception\InvalidArgumentException(
                "Directory level '{$dirLevel}' must be between 0 and 16"
            );
        }

        if ($this->dirLevel === $dirLevel) {
            return $this;
        }

        $this->triggerOptionEvent('dir_level', $dirLevel);
        $this->dirLevel = $dirLevel;
        return $this;
    }

    public function getDirLevel(): int
    {
        return $this->dirLevel;
    }

    /**
     * Set permission to create directories on unix systems
     *
     * @link http://php.net/manual/function.chmod.php
     * @see FilesystemOptions::setUmask
     * @see FilesystemOptions::setFilePermission
     *
     * @param false|string|int $dirPermission FALSE to disable explicit permission or an octal number
     */
    public function setDirPermission(false|string|int $dirPermission): self
    {
        if ($dirPermission !== false) {
            if (is_string($dirPermission)) {
                $dirPermission = octdec($dirPermission);
            }

            // validate
            if (($dirPermission & 0700) !== 0700) {
                throw new Exception\InvalidArgumentException(
                    'Invalid directory permission: need permission to execute, read and write by owner'
                );
            }
        }

        if ($this->dirPermission === $dirPermission) {
            return $this;
        }

        $this->triggerOptionEvent('dir_permission', $dirPermission);
        $this->dirPermission = $dirPermission;
        return $this;
    }

    /**
     * Get permission to create directories on unix systems
     */
    public function getDirPermission(): false|int
    {
        return $this->dirPermission;
    }

    public function setFileLocking(bool $fileLocking): self
    {
        $this->triggerOptionEvent('file_locking', $fileLocking);
        $this->fileLocking = $fileLocking;
        return $this;
    }

    public function getFileLocking(): bool
    {
        return $this->fileLocking;
    }

    /**
     * Set permission to create files on unix systems
     *
     * @link http://php.net/manual/function.chmod.php
     * @see FilesystemOptions::setUmask
     * @see FilesystemOptions::setDirPermission
     *
     * @param false|string|int $filePermission FALSE to disable explicit permission or an octal number
     */
    public function setFilePermission(false|string|int $filePermission): self
    {
        if ($filePermission !== false) {
            if (is_string($filePermission)) {
                $filePermission = octdec($filePermission);
            }

            // validate
            if (($filePermission & 0600) !== 0600) {
                throw new Exception\InvalidArgumentException(
                    'Invalid file permission: need permission to read and write by owner'
                );
            } elseif ($filePermission & 0111) {
                throw new Exception\InvalidArgumentException(
                    "Invalid file permission: Cache files shouldn't be executable"
                );
            }
        }

        if ($this->filePermission === $filePermission) {
            return $this;
        }

        $this->triggerOptionEvent('file_permission', $filePermission);
        $this->filePermission = $filePermission;

        return $this;
    }

    /**
     * Get permission to create files on unix systems
     */
    public function getFilePermission(): false|int
    {
        return $this->filePermission;
    }

    public function setNamespace(string $namespace): self
    {
        if (strlen($namespace) >= 250) {
            throw new Exception\InvalidArgumentException('Provided namespace is too long.');
        }

        parent::setNamespace($namespace);
        return $this;
    }

    public function setNamespaceSeparator(string $namespaceSeparator): self
    {
        $this->triggerOptionEvent('namespace_separator', $namespaceSeparator);
        $this->namespaceSeparator = $namespaceSeparator;
        return $this;
    }

    public function getNamespaceSeparator(): string
    {
        return $this->namespaceSeparator;
    }

    public function setNoAtime(bool $noAtime): self
    {
        if ($this->noAtime === $noAtime) {
            return $this;
        }

        $this->triggerOptionEvent('no_atime', $noAtime);
        $this->noAtime = $noAtime;
        return $this;
    }

    public function getNoAtime(): bool
    {
        return $this->noAtime;
    }

    public function setNoCtime(bool $noCtime): self
    {
        if ($this->noCtime === $noCtime) {
            return $this;
        }

        $this->triggerOptionEvent('no_ctime', $noCtime);
        $this->noCtime = $noCtime;
        return $this;
    }

    public function getNoCtime(): bool
    {
        return $this->noCtime;
    }

    /**
     * Set the umask to create files and directories on unix systems
     *
     * Note: On multithreaded webservers it's better to explicit set file and dir permission.
     *
     * @link http://php.net/manual/function.umask.php
     * @link http://en.wikipedia.org/wiki/Umask
     * @see FilesystemOptions::setFilePermission
     * @see FilesystemOptions::setDirPermission
     *
     * @param false|string|int $umask false to disable umask or an octal number
     */
    public function setUmask(false|string|int $umask): self
    {
        if ($umask !== false) {
            if (is_string($umask)) {
                $umask = octdec($umask);
            }

            // validate
            if ($umask & 0700) {
                throw new Exception\InvalidArgumentException(
                    'Invalid umask: need permission to execute, read and write by owner'
                );
            }

            // normalize
            $umask &= ~0002;
        }

        if ($this->umask === $umask) {
            return $this;
        }

        $this->triggerOptionEvent('umask', $umask);
        $this->umask = $umask;
        return $this;
    }

    /**
     * Get the umask to create files and directories on unix systems
     */
    public function getUmask(): false|int
    {
        return $this->umask;
    }

    /**
     * @return list<class-string>|bool
     */
    public function getUnserializableClasses(): bool|array
    {
        return $this->unserializableClasses;
    }

    /**
     * @param non-empty-list<class-string>|bool $unserializableClasses
     */
    public function setUnserializableClasses(bool|array $unserializableClasses): self
    {
        $this->unserializableClasses = $unserializableClasses;
        return $this;
    }

    /**
     * @throws Exception\InvalidArgumentException
     */
    private function normalizeCacheDirectory(string $cacheDir): string
    {
        if (! is_dir($cacheDir)) {
            throw new Exception\InvalidArgumentException(
                "Cache directory '{$cacheDir}' not found or not a directory"
            );
        } elseif (! is_writable($cacheDir)) {
            throw new Exception\InvalidArgumentException(
                "Cache directory '{$cacheDir}' not writable"
            );
        } elseif (! is_readable($cacheDir)) {
            throw new Exception\InvalidArgumentException(
                "Cache directory '{$cacheDir}' not readable"
            );
        }

        return rtrim(realpath($cacheDir), DIRECTORY_SEPARATOR);
    }
}
