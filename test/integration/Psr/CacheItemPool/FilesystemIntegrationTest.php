<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Psr\CacheItemPool;

use Laminas\Cache\Storage\Adapter\Filesystem;
use Laminas\Cache\Storage\Adapter\FilesystemOptions;
use Laminas\Cache\Storage\FlushableInterface;
use Laminas\Cache\Storage\Plugin\Serializer;
use Laminas\Cache\Storage\StorageInterface;
use Laminas\Serializer\AdapterPluginManager;
use Laminas\ServiceManager\ServiceManager;
use LaminasTest\Cache\Storage\Adapter\AbstractCacheItemPoolIntegrationTest;

use function assert;
use function mkdir;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * @uses FlushableInterface
 *
 * @template-extends AbstractCacheItemPoolIntegrationTest<FilesystemOptions>
 */
final class FilesystemIntegrationTest extends AbstractCacheItemPoolIntegrationTest
{
    private string $cacheDirectory;

    protected function setUp(): void
    {
        $this->skippedTests = [
            'testBasicUsageWithLongKey' => 'Filesystem does only support a maximum of 255 characters for the'
                . ' cache key but test generates a cache key with 300 characters which exceeds that limit',
            'testExpiration'            => 'Filesystem adapter does not auto expire cache items',
        ];

        $cacheDirectory = tempnam(sys_get_temp_dir(), '');
        unlink($cacheDirectory);
        assert(mkdir($cacheDirectory, 0777, true) === true);
        $this->cacheDirectory = $cacheDirectory;

        parent::setUp();
    }

    protected function createStorage(): StorageInterface&FlushableInterface
    {
        $storage = new Filesystem([
            'cache_dir' => $this->cacheDirectory,
        ]);

        $storage->addPlugin(new Serializer(new AdapterPluginManager(new ServiceManager())));
        return $storage;
    }
}
