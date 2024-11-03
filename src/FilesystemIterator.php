<?php

declare(strict_types=1);

namespace Laminas\Cache\Storage\Adapter;

use GlobIterator;
use Laminas\Cache\Storage\Adapter\Filesystem;
use Laminas\Cache\Storage\IteratorInterface;

use function strlen;
use function substr;

/**
 * @implements IteratorInterface<string, mixed>
 */
final class FilesystemIterator implements IteratorInterface
{
    /**
     * The iterator mode
     *
     * @var IteratorInterface::CURRENT_AS_*
     */
    private int $mode = IteratorInterface::CURRENT_AS_KEY;

    /**
     * The GlobIterator instance
     */
    private readonly GlobIterator $globIterator;

    /**
     * String length of namespace prefix
     */
    private readonly int $prefixLength;

    public function __construct(
        /**
         * The Filesystem storage instance
         */
        private readonly Filesystem $storage,
        string $path,
        /**
         * The namespace sprefix
         */
        private readonly string $prefix
    ) {
        $this->globIterator = new GlobIterator($path, GlobIterator::KEY_AS_FILENAME);
        $this->prefixLength = strlen($this->prefix);
    }

    /**
     * {@inheritDoc}
     */
    public function getStorage(): Filesystem
    {
        return $this->storage;
    }

    /**
     * {@inheritDoc}
     */
    public function getMode(): int
    {
        return $this->mode;
    }

    /**
     * {@inheritDoc}
     */
    public function setMode(int $mode): self
    {
        $this->mode = (int) $mode;
        return $this;
    }

    /* Iterator */

    /**
     * {@inheritDoc}
     */
    public function current(): mixed
    {
        if ($this->mode === IteratorInterface::CURRENT_AS_SELF) {
            return $this;
        }

        $key = $this->key();

        if ($this->mode === IteratorInterface::CURRENT_AS_VALUE) {
            return $this->storage->getItem($key);
        }

        return $key;
    }

    /**
     * Get current key
     */
    public function key(): string
    {
        $filename = $this->globIterator->key();

        // return without namespace prefix and file suffix
        return substr($filename, $this->prefixLength, -4);
    }

    /**
     * Move forward to next element
     */
    public function next(): void
    {
        $this->globIterator->next();
    }

    /**
     * Checks if current position is valid
     */
    public function valid(): bool
    {
        return $this->globIterator->valid();
    }

    /**
     * Rewind the Iterator to the first element.
     */
    public function rewind(): void
    {
        $this->globIterator->rewind();
    }
}
