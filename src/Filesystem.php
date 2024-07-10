<?php

declare(strict_types=1);

namespace Laminas\Cache\Storage\Adapter;

use ArrayObject;
use DateTimeZone;
use Exception as BaseException;
use GlobIterator;
use Laminas\Cache\Exception;
use Laminas\Cache\Storage\AbstractMetadataCapableAdapter;
use Laminas\Cache\Storage\Adapter\Filesystem\Clock;
use Laminas\Cache\Storage\Adapter\Filesystem\Exception\MetadataException;
use Laminas\Cache\Storage\Adapter\Filesystem\Exception\UnlinkException;
use Laminas\Cache\Storage\Adapter\Filesystem\FilesystemInteractionInterface;
use Laminas\Cache\Storage\Adapter\Filesystem\LocalFilesystemInteraction;
use Laminas\Cache\Storage\Adapter\Filesystem\Metadata;
use Laminas\Cache\Storage\AvailableSpaceCapableInterface;
use Laminas\Cache\Storage\Capabilities;
use Laminas\Cache\Storage\ClearByNamespaceInterface;
use Laminas\Cache\Storage\ClearByPrefixInterface;
use Laminas\Cache\Storage\ClearExpiredInterface;
use Laminas\Cache\Storage\Event;
use Laminas\Cache\Storage\FlushableInterface;
use Laminas\Cache\Storage\IterableInterface;
use Laminas\Cache\Storage\OptimizableInterface;
use Laminas\Cache\Storage\Plugin\Serializer;
use Laminas\Cache\Storage\TaggableInterface;
use Laminas\Cache\Storage\TotalSpaceCapableInterface;
use Laminas\Stdlib\ErrorHandler;
use Psr\Clock\ClockInterface;

use function array_diff;
use function array_unshift;
use function assert;
use function basename;
use function count;
use function date_default_timezone_get;
use function dirname;
use function explode;
use function func_num_args;
use function glob;
use function implode;
use function is_bool;
use function is_numeric;
use function is_string;
use function max;
use function md5;
use function preg_replace;
use function preg_split;
use function rmdir;
use function round;
use function serialize;
use function sprintf;
use function str_repeat;
use function strlen;
use function substr;
use function unserialize;

use const DIRECTORY_SEPARATOR;
use const E_NOTICE;
use const E_WARNING;
use const GLOB_NOESCAPE;
use const GLOB_NOSORT;
use const GLOB_ONLYDIR;
use const PHP_INT_MAX;
use const PHP_ROUND_HALF_UP;
use const PREG_SPLIT_NO_EMPTY;

/**
 * @implements IterableInterface<string, mixed>
 * @template-extends AbstractMetadataCapableAdapter<FilesystemOptions,Metadata>
 */
final class Filesystem extends AbstractMetadataCapableAdapter implements
    AvailableSpaceCapableInterface,
    ClearByNamespaceInterface,
    ClearByPrefixInterface,
    ClearExpiredInterface,
    FlushableInterface,
    IterableInterface,
    OptimizableInterface,
    TaggableInterface,
    TotalSpaceCapableInterface
{
    public const FILENAME_SUFFIX     = 'cache';
    public const TAG_FILENAME_SUFFIX = 'tag';
    private const SERIALIZED_FALSE   = 'b:0;';
    private const SERIALIZED_NULL    = 'N;';

    /**
     * Buffered total space in bytes
     */
    private ?int $totalSpace = null;

    /**
     * An identity for the last filespec
     * (cache directory + namespace prefix + key + directory level)
     */
    private string $lastFileSpecId = '';

    /**
     * The last used filespec
     */
    private string $lastFileSpec = '';

    private FilesystemInteractionInterface $filesystem;
    private ClockInterface $clock;

    /**
     * @param  null|iterable<string,mixed>|FilesystemOptions $options
     */
    public function __construct(
        null|iterable|FilesystemOptions $options = null,
        FilesystemInteractionInterface|null $filesystem = null,
        ClockInterface|null $clock = null,
    ) {
        parent::__construct($options);
        $this->filesystem = $filesystem ?? new LocalFilesystemInteraction();
        $this->clock      = $clock ?? new Clock(new DateTimeZone(date_default_timezone_get()));

        // clean total space buffer on change cache_dir
        $events   = $this->getEventManager();
        $callback = function (Event $event) use ($events): void {
            $params = $event->getParams();
            if (isset($params['cache_dir'])) {
                $this->totalSpace = null;
                $events->detach(static fn () => null);
            }
        };

        $events->attach('option', $callback);
    }

    /**
     * {@inheritDoc}
     */
    public function setOptions(iterable|AdapterOptions $options): self
    {
        if (! $options instanceof FilesystemOptions) {
            $options = new FilesystemOptions($options);
        }

        parent::setOptions($options);
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getOptions(): FilesystemOptions
    {
        $options = $this->options;
        if ($options === null) {
            $options = new FilesystemOptions();
            $this->setOptions($options);
        }

        return $options;
    }

    /**
     * {@inheritDoc}
     */
    public function flush(): bool
    {
        $flags       = GlobIterator::SKIP_DOTS | GlobIterator::CURRENT_AS_PATHNAME;
        $dir         = $this->getOptions()->getCacheDir();
        $clearFolder = null;
        $clearFolder = function ($dir) use (&$clearFolder, $flags): void {
            $it = new GlobIterator($dir . DIRECTORY_SEPARATOR . '*', $flags);
            foreach ($it as $pathname) {
                if ($it->isDir()) {
                    $clearFolder($pathname);
                    rmdir($pathname);
                } else {
                    // remove the file by ignoring errors if the file doesn't exist afterwards
                    // to fix a possible race condition if another process removed the file already.
                    try {
                        $this->filesystem->delete($pathname);
                    } catch (UnlinkException $exception) {
                        if ($this->filesystem->exists($pathname)) {
                            ErrorHandler::addError(
                                $exception->getErrorSeverity(),
                                $exception->getErrorMessage(),
                                $exception->getErrorFile(),
                                $exception->getErrorLine()
                            );
                        }
                    }
                }
            }
        };

        ErrorHandler::start();
        $clearFolder($dir);
        $error = ErrorHandler::stop();
        if ($error) {
            throw new Exception\RuntimeException("Flushing directory '{$dir}' failed", 0, $error);
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function clearExpired(): bool
    {
        $options   = $this->getOptions();
        $namespace = $options->getNamespace();
        $prefix    = $namespace === '' ? '' : $namespace . $options->getNamespaceSeparator();

        $flags = GlobIterator::SKIP_DOTS | GlobIterator::CURRENT_AS_PATHNAME;
        $path  = $options->getCacheDir()
            . str_repeat(DIRECTORY_SEPARATOR . $prefix . '*', $options->getDirLevel())
            . DIRECTORY_SEPARATOR . $prefix
            . '*.' . $this->escapeSuffixForGlob(self::FILENAME_SUFFIX);
        $glob  = new GlobIterator($path, $flags);
        $time  = $this->clock->now()->getTimestamp();

        ErrorHandler::start();
        foreach ($glob as $pathname) {
            // get last modification time of the file but ignore if the file is missing
            // to fix a possible race condition if another process removed the file already.
            try {
                $expiresAt = $this->getFirstLineOfFile($pathname);
            } catch (Exception\RuntimeException) {
                continue;
            }
            if (! is_numeric($expiresAt)) {
                ErrorHandler::addError(0, 'First line of cache file does not contain expiry information.');
                continue;
            }

            $expiresAt = (int) $expiresAt;

            if ($time >= $expiresAt) {
                // remove the file by ignoring errors if the file doesn't exist afterwards
                // to fix a possible race condition if another process removed the file already.
                try {
                    $this->filesystem->delete($pathname);
                } catch (UnlinkException $exception) {
                    if ($this->filesystem->exists($pathname)) {
                        ErrorHandler::addError(
                            $exception->getErrorSeverity(),
                            $exception->getErrorMessage(),
                            $exception->getErrorFile(),
                            $exception->getErrorLine()
                        );
                    } else {
                        $tagPathname = $this->formatTagFilename(substr($pathname, 0, -4));
                        try {
                            $this->filesystem->delete($tagPathname);
                        } catch (UnlinkException $exception) {
                            ErrorHandler::addError(
                                $exception->getErrorSeverity(),
                                $exception->getErrorMessage(),
                                $exception->getErrorFile(),
                                $exception->getErrorLine()
                            );
                        }
                    }
                }
            }
        }
        $error = ErrorHandler::stop();
        if ($error) {
            $result = $this->triggerThrowable(
                __FUNCTION__,
                new ArrayObject(),
                false,
                new Exception\RuntimeException('Failed to clear expired items', 0, $error)
            );

            if (! is_bool($result)) {
                throw new Exception\RuntimeException("Failed to remove files of '{$path}'", 0, $error);
            }

            return $result;
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function clearByNamespace(string $namespace): bool
    {
        /** @psalm-suppress TypeDoesNotContainType To prevent deleting unexpected files, we should double validate */
        if ($namespace === '') {
            throw new Exception\InvalidArgumentException('No namespace given');
        }

        $options = $this->getOptions();
        $prefix  = $namespace . $options->getNamespaceSeparator();

        $flags = GlobIterator::SKIP_DOTS | GlobIterator::CURRENT_AS_PATHNAME;
        $path  = $options->getCacheDir()
            . str_repeat(DIRECTORY_SEPARATOR . $prefix . '*', $options->getDirLevel())
            . DIRECTORY_SEPARATOR . $prefix . '*.*';
        $glob  = new GlobIterator($path, $flags);

        ErrorHandler::start();
        foreach ($glob as $pathname) {
            // remove the file by ignoring errors if the file doesn't exist afterwards
            // to fix a possible race condition if another process removed the file already.
            try {
                $this->filesystem->delete($pathname);
            } catch (UnlinkException $exception) {
                if ($this->filesystem->exists($pathname)) {
                    ErrorHandler::addError(
                        $exception->getErrorSeverity(),
                        $exception->getErrorMessage(),
                        $exception->getErrorFile(),
                        $exception->getErrorLine()
                    );
                }
            }
        }

        $err = ErrorHandler::stop();
        if ($err) {
            $result = $this->triggerThrowable(
                __FUNCTION__,
                new ArrayObject(),
                false,
                new Exception\RuntimeException("Failed to clear items of namespace '{$namespace}'", 0, $err)
            );

            if (! is_bool($result)) {
                throw new Exception\RuntimeException("Failed to remove files of '{$path}'", 0, $err);
            }

            return $result;
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function clearByPrefix(string $prefix): bool
    {
        /** @psalm-suppress TypeDoesNotContainType To prevent deleting unexpected files, we should double validate */
        if ($prefix === '') {
            throw new Exception\InvalidArgumentException('No prefix given');
        }

        $options   = $this->getOptions();
        $namespace = $options->getNamespace();
        $nsPrefix  = $namespace === '' ? '' : $namespace . $options->getNamespaceSeparator();

        $flags = GlobIterator::SKIP_DOTS | GlobIterator::CURRENT_AS_PATHNAME;
        $path  = $options->getCacheDir()
            . str_repeat(DIRECTORY_SEPARATOR . $nsPrefix . '*', $options->getDirLevel())
            . DIRECTORY_SEPARATOR . $nsPrefix . $prefix . '*.*';
        $glob  = new GlobIterator($path, $flags);

        ErrorHandler::start();
        foreach ($glob as $pathname) {
            assert(is_string($pathname));
            // remove the file by ignoring errors if the file doesn't exist afterwards
            // to fix a possible race condition if another process removed the file already.
            try {
                $this->filesystem->delete($pathname);
            } catch (UnlinkException $exception) {
                if ($this->filesystem->exists($pathname)) {
                    ErrorHandler::addError(
                        $exception->getErrorSeverity(),
                        $exception->getErrorMessage(),
                        $exception->getErrorFile(),
                        $exception->getErrorLine()
                    );
                }
            }
        }
        $err = ErrorHandler::stop();
        if ($err) {
            $result    = false;
            $throwable = new Exception\RuntimeException("Failed to remove files of '{$path}'", 0, $err);
            $result    = $this->triggerThrowable(
                __FUNCTION__,
                new ArrayObject(),
                $result,
                $throwable,
            );

            if (! is_bool($result)) {
                throw new Exception\RuntimeException("Failed to remove files of '{$path}'", 0, $err);
            }

            return $result;
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function setTags(string $key, array $tags): bool
    {
        if (! $this->internalHasItem($key)) {
            return false;
        }

        $filespec = $this->getFileSpec($key);

        if (! $tags) {
            $this->filesystem->delete($this->formatTagFilename($filespec));
            return true;
        }

        return $this->putFileContent(
            $this->formatTagFilename($filespec),
            implode("\n", $tags)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getTags(string $key): array|false
    {
        if (! $this->internalHasItem($key)) {
            return false;
        }

        $filespec = $this->formatTagFilename($this->getFileSpec($key));
        $tags     = [];
        if ($this->filesystem->exists($filespec)) {
            $tags = explode("\n", $this->getFileContents($filespec));
        }

        return $tags;
    }

    /**
     * {@inheritDoc}
     */
    public function clearByTags(array $tags, bool $disjunction = false): bool
    {
        if (! $tags) {
            return true;
        }

        $tagCount  = count($tags);
        $options   = $this->getOptions();
        $namespace = $options->getNamespace();
        $prefix    = $namespace === '' ? '' : $namespace . $options->getNamespaceSeparator();

        $flags = GlobIterator::SKIP_DOTS | GlobIterator::CURRENT_AS_PATHNAME;
        $path  = $options->getCacheDir()
            . str_repeat(DIRECTORY_SEPARATOR . $prefix . '*', $options->getDirLevel())
            . DIRECTORY_SEPARATOR . $prefix
            . '*.' . $this->escapeSuffixForGlob(self::TAG_FILENAME_SUFFIX);
        $glob  = new GlobIterator($path, $flags);

        foreach ($glob as $pathname) {
            assert(is_string($pathname));
            try {
                $diff = array_diff($tags, explode("\n", $this->getFileContents($pathname)));
            } catch (Exception\RuntimeException $exception) {
                // ignore missing files because of possible raise conditions
                // e.g. another process already deleted that item
                if (! $this->filesystem->exists($pathname)) {
                    continue;
                }
                throw $exception;
            }

            $rem = false;
            if ($disjunction && count($diff) < $tagCount) {
                $rem = true;
            } elseif (! $disjunction && ! $diff) {
                $rem = true;
            }

            if ($rem) {
                $this->filesystem->delete($pathname);

                $datPathname = $this->formatFilename(substr($pathname, 0, -4));
                if ($this->filesystem->exists($datPathname)) {
                    $this->filesystem->delete($datPathname);
                }
            }
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function getIterator(): FilesystemIterator
    {
        $options   = $this->getOptions();
        $namespace = $options->getNamespace();
        $prefix    = $namespace === '' ? '' : $namespace . $options->getNamespaceSeparator();
        $path      = $options->getCacheDir()
            . str_repeat(DIRECTORY_SEPARATOR . $prefix . '*', $options->getDirLevel())
            . DIRECTORY_SEPARATOR . $prefix
            . '*.' . $this->escapeSuffixForGlob(self::FILENAME_SUFFIX);
        return new FilesystemIterator($this, $path, $prefix);
    }

    /**
     * {@inheritDoc}
     */
    public function optimize(): bool
    {
        $options = $this->getOptions();
        if ($options->getDirLevel()) {
            $namespace = $options->getNamespace();
            $prefix    = $namespace === '' ? '' : $namespace . $options->getNamespaceSeparator();

            // removes only empty directories
            $this->clearAndDeleteDirectory($options->getCacheDir(), $prefix);
        }
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function getTotalSpace(): int
    {
        if ($this->totalSpace === null) {
            $path = $this->getOptions()->getCacheDir();

            $this->totalSpace = $this->filesystem->totalBytes($path);
        }

        return $this->totalSpace;
    }

    /**
     * {@inheritDoc}
     */
    public function getAvailableSpace(): int
    {
        $path = $this->getOptions()->getCacheDir();

        return $this->filesystem->availableBytes($path);
    }

    /**
     * {@inheritDoc}
     */
    public function getItem(string $key, ?bool &$success = null, mixed &$casToken = null): mixed
    {
        $options = $this->getOptions();
        if ($options->getReadable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        $argn = func_num_args();
        if ($argn > 2) {
            return parent::getItem($key, $success, $casToken);
        } elseif ($argn > 1) {
            return parent::getItem($key, $success);
        }

        return parent::getItem($key);
    }

    /**
     * {@inheritDoc}
     */
    public function getItems(array $keys): array
    {
        $options = $this->getOptions();
        if ($options->getReadable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::getItems($keys);
    }

    /**
     * {@inheritDoc}
     */
    protected function internalGetItem(string $normalizedKey, ?bool &$success = null, mixed &$casToken = null): mixed
    {
        if (! $this->internalHasItem($normalizedKey)) {
            $success = false;
            return null;
        }

        try {
            $filespec = $this->formatFilename($this->getFileSpec($normalizedKey));
            $data     = $this->getCacheValue($filespec);

            // use filemtime + filesize as CAS token
            if (func_num_args() > 2) {
                try {
                    $casToken = $this->filesystem->lastModifiedTime($filespec) . $this->filesystem->filesize($filespec);
                } catch (MetadataException) {
                    $casToken = "";
                }
            }
            $success = true;
            return $data;
        } catch (BaseException $e) {
            $success = false;
            throw $e;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function hasItem(string $key): bool
    {
        $options = $this->getOptions();
        if ($options->getReadable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::hasItem($key);
    }

    /**
     * {@inheritDoc}
     */
    public function hasItems(array $keys): array
    {
        $options = $this->getOptions();
        if ($options->getReadable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::hasItems($keys);
    }

    /**
     * {@inheritDoc}
     */
    protected function internalHasItem(string $normalizedKey): bool
    {
        $file = $this->formatFilename($this->getFileSpec($normalizedKey));

        try {
            $expiresAt = $this->getFirstLineOfFile($file);
        } catch (Exception\RuntimeException) {
            return false;
        }

        if (! is_numeric($expiresAt)) {
            throw new Exception\RuntimeException('First line of cache file does not contain expiry information.');
        }

        $expired = $this->clock->now()->getTimestamp() >= (int) $expiresAt;

        if ($expired) {
            $this->deleteCacheRelatedFiles($normalizedKey);
            return false;
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function getMetadata(string $key): Metadata|null
    {
        $options = $this->getOptions();
        if ($options->getReadable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::getMetadata($key);
    }

    /**
     * {@inheritDoc}
     */
    public function getMetadatas(array $keys, array $options = []): array
    {
        $options = $this->getOptions();
        if ($options->getReadable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::getMetadatas($keys);
    }

    /**
     * {@inheritDoc}
     */
    protected function internalGetMetadata(string $normalizedKey): Metadata|null
    {
        if (! $this->internalHasItem($normalizedKey)) {
            return null;
        }

        $options  = $this->getOptions();
        $filespec = $this->getFileSpec($normalizedKey);
        $file     = $this->formatFilename($filespec);

        $mtime = null;
        try {
            $mtime = $this->filesystem->lastModifiedTime($file);
        } catch (MetadataException $exception) {
            ErrorHandler::addError(
                $exception->getErrorSeverity(),
                $exception->getErrorMessage(),
                $exception->getErrorFile(),
                $exception->getErrorLine(),
            );
        }

        $ctime = null;
        if (! $options->getNoCtime()) {
            try {
                $ctime = $this->filesystem->createdTime($file);
            } catch (MetadataException $exception) {
                ErrorHandler::addError(
                    $exception->getErrorSeverity(),
                    $exception->getErrorMessage(),
                    $exception->getErrorFile(),
                    $exception->getErrorLine(),
                );
            }
        }

        $atime = null;
        if (! $options->getNoAtime()) {
            try {
                $atime = $this->filesystem->lastAccessedTime($file);
            } catch (MetadataException $exception) {
                ErrorHandler::addError(
                    $exception->getErrorSeverity(),
                    $exception->getErrorMessage(),
                    $exception->getErrorFile(),
                    $exception->getErrorLine(),
                );
            }
        }

        $filesize = null;
        try {
            $filesize = $this->filesystem->filesize($file);
        } catch (MetadataException $exception) {
            ErrorHandler::addError(
                $exception->getErrorSeverity(),
                $exception->getErrorMessage(),
                $exception->getErrorFile(),
                $exception->getErrorLine(),
            );
        }

        return new Metadata($atime, $ctime, $mtime, $filesize, $filespec);
    }

    /**
     * {@inheritDoc}
     */
    public function setItem(string $key, mixed $value): bool
    {
        $options = $this->getOptions();
        if ($options->getWritable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }
        return parent::setItem($key, $value);
    }

    /**
     * {@inheritDoc}
     */
    public function setItems(array $keyValuePairs): array
    {
        $options = $this->getOptions();
        if ($options->getWritable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::setItems($keyValuePairs);
    }

    /**
     * {@inheritDoc}
     */
    public function addItem(string $key, mixed $value): bool
    {
        $options = $this->getOptions();
        if ($options->getWritable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::addItem($key, $value);
    }

    /**
     * {@inheritDoc}
     */
    public function addItems(array $keyValuePairs): array
    {
        $options = $this->getOptions();
        if ($options->getWritable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::addItems($keyValuePairs);
    }

    /**
     * {@inheritDoc}
     */
    public function replaceItem(string $key, mixed $value): bool
    {
        $options = $this->getOptions();
        if ($options->getWritable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::replaceItem($key, $value);
    }

    /**
     * {@inheritDoc}
     */
    public function replaceItems(array $keyValuePairs): array
    {
        $options = $this->getOptions();
        if ($options->getWritable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::replaceItems($keyValuePairs);
    }

    /**
     * {@inheritDoc}
     */
    protected function internalSetItem(string $normalizedKey, mixed $value): bool
    {
        $filespec = $this->getFileSpec($normalizedKey);
        $file     = $this->formatFilename($filespec);
        $this->prepareDirectoryStructure($filespec);
        $ttl = $this->getOptions()->getTtl();

        $valueForFile = $this->createCacheValue($value, $ttl);
        // write data in non-blocking mode
        $written = $this->putFileContent(
            $file,
            $valueForFile,
            true,
            $wouldBlock,
        );

        // delete related tag file (if present)
        $this->filesystem->delete($this->formatTagFilename($filespec));

        // Retry writing data in blocking mode if it was blocked before
        if ($wouldBlock) {
            $written = $this->putFileContent($file, $valueForFile);
        }

        return $written;
    }

    /**
     * {@inheritDoc}
     */
    public function checkAndSetItem(mixed $token, string $key, mixed $value): bool
    {
        $options = $this->getOptions();
        if ($options->getWritable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::checkAndSetItem($token, $key, $value);
    }

    /**
     * {@inheritDoc}
     */
    protected function internalCheckAndSetItem(mixed $token, string $normalizedKey, mixed $value): bool
    {
        if (! $this->internalHasItem($normalizedKey)) {
            return false;
        }

        // use filemtime + filesize as CAS token
        $file = $this->formatFilename($this->getFileSpec($normalizedKey));
        try {
            $check = $this->filesystem->lastModifiedTime($file) . $this->filesystem->filesize($file);
        } catch (MetadataException) {
            $check = "";
        }

        if ($token !== $check) {
            return false;
        }

        return $this->internalSetItem($normalizedKey, $value);
    }

    /**
     * {@inheritDoc}
     */
    public function touchItem(string $key): bool
    {
        $options = $this->getOptions();
        if ($options->getWritable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::touchItem($key);
    }

    /**
     * {@inheritDoc}
     */
    public function touchItems(array $keys): array
    {
        $options = $this->getOptions();
        if ($options->getWritable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::touchItems($keys);
    }

    /**
     * {@inheritDoc}
     */
    public function removeItem(string $key): bool
    {
        $options = $this->getOptions();
        if ($options->getWritable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::removeItem($key);
    }

    /**
     * {@inheritDoc}
     */
    public function removeItems(array $keys): array
    {
        $options = $this->getOptions();
        if ($options->getWritable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::removeItems($keys);
    }

    /**
     * {@inheritDoc}
     */
    protected function internalRemoveItem(string $normalizedKey): bool
    {
        if (! $this->internalHasItem($normalizedKey)) {
            return false;
        }

        return $this->deleteCacheRelatedFiles($normalizedKey);
    }

    /**
     * {@inheritDoc}
     */
    protected function internalGetCapabilities(): Capabilities
    {
        if ($this->capabilities === null) {
            $options = $this->getOptions();

            // Calculate max key length: 255 - strlen(.) - strlen(<filename suffix or tag suffix, whatever is longer>)
            $maxKeyLength = 255 - 1 - max([
                strlen(self::FILENAME_SUFFIX),
                strlen(self::TAG_FILENAME_SUFFIX),
            ]);

            $namespace = $options->getNamespace();
            if ($namespace !== '') {
                $maxKeyLength -= strlen($namespace) + strlen($options->getNamespaceSeparator());
            }

            if ($maxKeyLength < 1) {
                throw new Exception\RuntimeException(
                    'Invalid maximum key length was calculated.'
                    . ' This usually happens if the used namespace is too long.'
                );
            }

            $this->capabilities = new Capabilities(
                $maxKeyLength,
                true,
                true,
                [
                    'NULL'     => true,
                    'boolean'  => true,
                    'integer'  => true,
                    'double'   => true,
                    'string'   => true,
                    'array'    => true,
                    'object'   => 'object',
                    'resource' => false,
                ],
                1,
                false,
            );

            // update capabilities on change options
            $this->getEventManager()->attach('option', fn () => $this->capabilities = null);
        }

        return $this->capabilities;
    }

    /**
     * Removes directories recursive by namespace
     */
    private function clearAndDeleteDirectory(string $dir, string $prefix): bool
    {
        $glob = glob(
            $dir . DIRECTORY_SEPARATOR . $prefix . '*',
            GLOB_ONLYDIR | GLOB_NOESCAPE | GLOB_NOSORT
        );
        if ($glob === false) {
            // On some systems glob returns false even on empty result
            return true;
        }

        $ret = true;
        foreach ($glob as $subdir) {
            // skip removing current directory if removing of sub-directory failed
            if ($this->clearAndDeleteDirectory($subdir, $prefix)) {
                // ignore not empty directories
                ErrorHandler::start();
                $ret = rmdir($subdir) && $ret;
                ErrorHandler::stop();
            } else {
                $ret = false;
            }
        }

        return $ret;
    }

    /**
     * Get file spec of the given key and namespace
     */
    private function getFileSpec(string $normalizedKey): string
    {
        $options   = $this->getOptions();
        $namespace = $options->getNamespace();
        $prefix    = $namespace === '' ? '' : $namespace . $options->getNamespaceSeparator();
        $path      = $options->getCacheDir() . DIRECTORY_SEPARATOR;
        $level     = $options->getDirLevel();

        $fileSpecId = $path . $prefix . $normalizedKey . '/' . $level;
        if ($this->lastFileSpecId !== $fileSpecId) {
            if ($level > 0) {
                // create up to 256 directories per directory level
                $hash = md5($normalizedKey);
                for ($i = 0, $max = $level * 2; $i < $max; $i += 2) {
                    $path .= $prefix . $hash[$i] . $hash[$i + 1] . DIRECTORY_SEPARATOR;
                }
            }

            $this->lastFileSpecId = $fileSpecId;
            $this->lastFileSpec   = $path . $prefix . $normalizedKey;
        }

        return $this->lastFileSpec;
    }

    /**
     * Read a complete file
     *
     * @param  string  $file        File complete path
     * @param  bool $nonBlocking Don't block script if file is locked
     * @param  bool $wouldblock  The optional argument is set to TRUE if the lock would block
     * @throws Exception\RuntimeException
     */
    private function getCacheValue(string $file, bool $nonBlocking = false, ?bool &$wouldblock = null): mixed
    {
        $data  = $this->getFileContents($file, $nonBlocking, $wouldblock);
        $parts = preg_split("#\n#", $data, 2, PREG_SPLIT_NO_EMPTY);
        if (! isset($parts[1])) {
            throw new Exception\RuntimeException('Malformed cache file contents.');
        }

        $serializedCacheValue = $parts[1];

        if ($this->isSerializerAttached()) {
            return $serializedCacheValue;
        }

        return $this->unserializeCacheValue($serializedCacheValue);
    }

    /**
     * Prepares a directory structure for the given file(spec)
     * using the configured directory level.
     *
     * @throws Exception\RuntimeException
     */
    private function prepareDirectoryStructure(string $file): void
    {
        $options = $this->getOptions();
        $level   = $options->getDirLevel();

        // Directory structure is required only if directory level > 0
        if (! $level) {
            return;
        }

        // Directory structure already exists
        $pathname = dirname($file);
        if ($this->filesystem->exists($pathname)) {
            return;
        }

        $perm  = $options->getDirPermission();
        $umask = $options->getUmask();
        if ($umask !== false && $perm !== false) {
            $perm &= ~$umask;
        }

        ErrorHandler::start();

        if ($perm === false || $level === 1) {
            $this->filesystem->createDirectory(
                $pathname,
                $perm !== false ? $perm : 0775,
                true,
                $umask !== false ? $umask : null
            );
        } else {
            // built-in mkdir function sets permission together with current umask
            // which doesn't work well on multi threaded webservers
            // -> create directories one by one and set permissions

            // find existing path and missing path parts
            $parts = [];
            $path  = $pathname;
            while (! $this->filesystem->exists($path)) {
                array_unshift($parts, basename($path));
                $nextPath = dirname($path);
                if ($nextPath === $path) {
                    break;
                }
                $path = $nextPath;
            }

            // make all missing path parts
            foreach ($parts as $part) {
                $path .= DIRECTORY_SEPARATOR . $part;

                // create a single directory, set and reset umask immediately
                $this->filesystem->createDirectory(
                    $path,
                    0775,
                    false,
                    $umask !== false ? $umask : null
                );
            }
        }

        ErrorHandler::stop();
    }

    /**
     * Write content to a file
     *
     * @param  bool $nonBlocking Don't block script if file is locked
     * @param  bool|null $wouldBlock  The optional argument is set to true if the lock would block
     * @throws Exception\RuntimeException
     */
    private function putFileContent(
        string $file,
        string $data,
        bool $nonBlocking = false,
        ?bool &$wouldBlock = null
    ): bool {
        $options     = $this->getOptions();
        $umask       = $options->getUmask();
        $permissions = $options->getFilePermission();
        return $this->filesystem->write(
            $file,
            $data,
            $umask !== false ? $umask : null,
            $permissions !== false ? $permissions : null,
            $options->getFileLocking(),
            $nonBlocking,
            $wouldBlock
        );
    }

    /**
     * Formats the filename, appending the suffix option
     */
    private function formatFilename(string $filename): string
    {
        return sprintf('%s.%s', $filename, self::FILENAME_SUFFIX);
    }

    /**
     * Formats the filename, appending the tag suffix option
     */
    private function formatTagFilename(string $filename): string
    {
        return sprintf('%s.%s', $filename, self::TAG_FILENAME_SUFFIX);
    }

    /**
     * Escapes a filename suffix to be safe for glob operations
     *
     * Wraps any of *, ?, or [ characters within [] brackets.
     */
    private function escapeSuffixForGlob(string $suffix): string
    {
        return preg_replace('#([*?\[])#', '[$1]', $suffix);
    }

    /**
     * @throws Exception\RuntimeException If the file could not be read or locked.
     */
    private function getFirstLineOfFile(string $file): string
    {
        $options = $this->getOptions();
        $locking = $options->getFileLocking();

        return $this->filesystem->getFirstLineOfFile($file, $locking, false, $wouldBlock);
    }

    private function createCacheValue(mixed $value, int|float $ttl): string
    {
        $expiresAt = $this->calculateExpireTimestampBasedOnTtl($ttl);
        return $expiresAt . "\n" . $this->normalizeCacheValue($value);
    }

    /**
     * @return non-negative-int
     */
    private function calculateExpireTimestampBasedOnTtl(float|int $ttl): int
    {
        if ($ttl < 1) {
            return PHP_INT_MAX;
        }

        $ttl       = (int) round($ttl, PHP_ROUND_HALF_UP);
        $timestamp = $this->clock->now()->getTimestamp() + $ttl;

        assert($timestamp >= 0);
        return $timestamp;
    }

    private function deleteCacheRelatedFiles(string $normalizedKey): bool
    {
        $filespec = $this->getFileSpec($normalizedKey);
        $file     = $this->formatFilename($filespec);
        $tagFile  = $this->formatTagFilename($filespec);

        try {
            $removed = $this->filesystem->delete($file);
        } catch (UnlinkException) {
            $removed = false;
        }

        try {
            $this->filesystem->delete($tagFile);
        } catch (UnlinkException) {
        }

        return $removed;
    }

    private function getFileContents(string $file, bool $nonBlocking = false, ?bool &$wouldblock = null): string
    {
        $options = $this->getOptions();
        $locking = $options->getFileLocking();

        return $this->filesystem->read($file, $locking, $nonBlocking, $wouldblock);
    }

    private function normalizeCacheValue(mixed $value): string
    {
        if ($this->isSerializerAttached()) {
            assert(
                is_string($value),
                'In case the serializer plugin is attached, the value should already be a string.',
            );
            return $value;
        }

        return serialize($value);
    }

    private function isSerializerAttached(): bool
    {
        foreach ($this->getPluginRegistry() as $plugin) {
            if ($plugin instanceof Serializer) {
                return true;
            }
        }

        return false;
    }

    private function unserializeCacheValue(string $serializedCacheValue): mixed
    {
        $options = $this->getOptions();
        ErrorHandler::start(E_NOTICE | E_WARNING);

        $cachedValue = match ($serializedCacheValue) {
            self::SERIALIZED_FALSE => false,
            self::SERIALIZED_NULL => null,
            default => unserialize($serializedCacheValue, ['allowed_classes' => $options->getUnserializableClasses()]),
        };

        $error = ErrorHandler::stop();
        if ($error !== null) {
            throw new Exception\RuntimeException('Cached value contains invalid data.', 0, $error);
        }

        return $cachedValue;
    }
}
