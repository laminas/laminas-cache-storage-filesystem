<?php

declare(strict_types=1);

namespace Laminas\Cache\Storage\Adapter;

use ArrayObject;
use Exception as BaseException;
use GlobIterator;
use Laminas\Cache\Exception;
use Laminas\Cache\Storage\AbstractMetadataCapableAdapter;
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
use Laminas\Cache\Storage\TaggableInterface;
use Laminas\Cache\Storage\TotalSpaceCapableInterface;
use Laminas\Stdlib\ErrorHandler;

use function array_diff;
use function array_unshift;
use function assert;
use function basename;
use function count;
use function dirname;
use function explode;
use function func_num_args;
use function glob;
use function implode;
use function is_bool;
use function is_string;
use function max;
use function md5;
use function preg_replace;
use function rmdir;
use function sprintf;
use function str_repeat;
use function strlen;
use function substr;
use function time;

use const DIRECTORY_SEPARATOR;
use const GLOB_NOESCAPE;
use const GLOB_NOSORT;
use const GLOB_ONLYDIR;

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

    /**
     * @param  null|iterable<string,mixed>|FilesystemOptions $options
     */
    public function __construct(
        null|iterable|FilesystemOptions $options = null,
        FilesystemInteractionInterface|null $filesystem = null,
    ) {
        parent::__construct($options);
        $this->filesystem = $filesystem ?? new LocalFilesystemInteraction();

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
            . '*.' . $this->escapeSuffixForGlob($this->getOptions()->getSuffix());
        $glob  = new GlobIterator($path, $flags);
        $time  = time();
        $ttl   = $options->getTtl();

        ErrorHandler::start();
        foreach ($glob as $pathname) {
            // get last modification time of the file but ignore if the file is missing
            // to fix a possible race condition if another process removed the file already.
            try {
                $mtime = $this->filesystem->lastModifiedTime($pathname);
            } catch (MetadataException $exception) {
                if ($this->filesystem->exists($pathname)) {
                    ErrorHandler::addError(
                        $exception->getErrorSeverity(),
                        $exception->getErrorMessage(),
                        $exception->getErrorFile(),
                        $exception->getErrorLine()
                    );
                }

                continue;
            }

            if ($time >= $mtime + $ttl) {
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

        $this->putFileContent(
            $this->formatTagFilename($filespec),
            implode("\n", $tags)
        );
        return true;
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
            $tags = explode("\n", $this->getFileContent($filespec));
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
            . '*.' . $this->escapeSuffixForGlob($this->getOptions()->getTagSuffix());
        $glob  = new GlobIterator($path, $flags);

        foreach ($glob as $pathname) {
            assert(is_string($pathname));
            try {
                $diff = array_diff($tags, explode("\n", $this->getFileContent($pathname)));
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
            . '*.' . $this->escapeSuffixForGlob($this->getOptions()->getSuffix());
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
            $data     = $this->getFileContent($filespec);

            // use filemtime + filesize as CAS token
            if (func_num_args() > 2) {
                try {
                    $casToken = $this->filesystem->lastModifiedTime($filespec) . $this->filesystem->filesize($filespec);
                } catch (MetadataException $exception) {
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
    protected function internalGetItems(array $normalizedKeys): array
    {
        $keys   = $normalizedKeys; // Don't change argument passed by reference
        $result = [];
        while ($keys) {
            // LOCK_NB if more than one items have to read
            $nonBlocking = count($keys) > 1;
            $wouldblock  = null;

            // read items
            foreach ($keys as $i => $key) {
                if (! $this->internalHasItem((string) $key)) {
                    unset($keys[$i]);
                    continue;
                }

                $filespec = $this->formatFilename($this->getFileSpec((string) $key));
                $data     = $this->getFileContent($filespec, $nonBlocking, $wouldblock);
                if ($nonBlocking && $wouldblock) {
                    continue;
                } else {
                    unset($keys[$i]);
                }

                $result[$key] = $data;
            }

            // TODO: Don't check ttl after first iteration
            // $options['ttl'] = 0;
        }

        return $result;
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
        if (! $this->filesystem->exists($file)) {
            return false;
        }

        $ttl = $this->getOptions()->getTtl();
        if ($ttl) {
            $mtime = $this->filesystem->lastModifiedTime($file);

            if (time() >= $mtime + $ttl) {
                return false;
            }
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
        } catch (Exception\RuntimeException $exception) {
        }

        $ctime = null;
        if (! $options->getNoCtime()) {
            try {
                $ctime = $this->filesystem->createdTime($file);
            } catch (Exception\RuntimeException) {
            }
        }

        $atime = null;
        if (! $options->getNoAtime()) {
            try {
                $atime = $this->filesystem->lastAccessedTime($file);
            } catch (Exception\RuntimeException) {
            }
        }

        $filesize = null;
        try {
            $filesize = $this->filesystem->filesize($file);
        } catch (Exception\RuntimeException) {
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

        // write data in non-blocking mode
        $this->putFileContent($file, (string) $value, true, $wouldblock);

        // delete related tag file (if present)
        $this->filesystem->delete($this->formatTagFilename($filespec));

        // Retry writing data in blocking mode if it was blocked before
        if ($wouldblock) {
            $this->putFileContent($file, (string) $value);
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    protected function internalSetItems(array $normalizedKeyValuePairs): array
    {
        // create an associated array of files and contents to write
        $contents = [];
        foreach ($normalizedKeyValuePairs as $key => &$value) {
            $filespec = $this->getFileSpec((string) $key);
            $this->prepareDirectoryStructure($filespec);

            // *.dat file
            $contents[$this->formatFilename($filespec)] = &$value;

            // *.tag file
            $this->filesystem->delete($this->formatTagFilename($filespec));
        }

        // write to disk
        do {
            $nonBlocking = count($contents) > 1;

            foreach ($contents as $file => &$content) {
                $wouldblock = null;
                $this->putFileContent($file, (string) $content, $nonBlocking, $wouldblock);
                if (! $nonBlocking || ! $wouldblock) {
                    unset($contents[$file]);
                }
            }
        } while ($contents !== []);

        // return OK
        return [];
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
        } catch (MetadataException $exception) {
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
    protected function internalTouchItem(string $normalizedKey): bool
    {
        if (! $this->internalHasItem($normalizedKey)) {
            return false;
        }

        $filespec = $this->getFileSpec($normalizedKey);
        $file     = $this->formatFilename($filespec);

        return $this->filesystem->touch($file);
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
        $filespec = $this->getFileSpec($normalizedKey);
        $file     = $this->formatFilename($filespec);
        if (! $this->filesystem->exists($file)) {
            return false;
        }

        $this->filesystem->delete($file);
        $this->filesystem->delete($this->formatTagFilename($filespec));
        return true;
    }

    /**
     * {@inheritDoc}
     */
    protected function internalGetCapabilities(): Capabilities
    {
        if ($this->capabilities === null) {
            $options = $this->getOptions();

            // Calculate max key length: 255 - strlen(.) - strlen(dat | tag)
            $maxKeyLength = 255 - 1 - max([
                strlen($options->getSuffix()),
                strlen($options->getTagSuffix()),
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
                    'NULL'     => 'string',
                    'boolean'  => 'string',
                    'integer'  => 'string',
                    'double'   => 'string',
                    'string'   => true,
                    'array'    => false,
                    'object'   => false,
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
    private function getFileContent(string $file, bool $nonBlocking = false, ?bool &$wouldblock = null): string
    {
        $options = $this->getOptions();
        $locking = $options->getFileLocking();

        return $this->filesystem->read($file, $locking, $nonBlocking, $wouldblock);
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
     * @param  bool|null $wouldblock  The optional argument is set to true if the lock would block
     * @throws Exception\RuntimeException
     */
    private function putFileContent(
        string $file,
        string $data,
        bool $nonBlocking = false,
        ?bool &$wouldblock = null
    ): void {
        $options     = $this->getOptions();
        $umask       = $options->getUmask();
        $permissions = $options->getFilePermission();
        $this->filesystem->write(
            $file,
            $data,
            $umask !== false ? $umask : null,
            $permissions !== false ? $permissions : null,
            $options->getFileLocking(),
            $nonBlocking,
            $wouldblock
        );
    }

    /**
     * Formats the filename, appending the suffix option
     */
    private function formatFilename(string $filename): string
    {
        return sprintf('%s.%s', $filename, $this->getOptions()->getSuffix());
    }

    /**
     * Formats the filename, appending the tag suffix option
     */
    private function formatTagFilename(string $filename): string
    {
        return sprintf('%s.%s', $filename, $this->getOptions()->getTagSuffix());
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
}
