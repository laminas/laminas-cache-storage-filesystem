<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Psr\SimpleCache;

use Laminas\Cache\Storage\Adapter\Filesystem;
use Laminas\Cache\Storage\Adapter\FilesystemOptions;
use Laminas\Cache\Storage\Plugin\Serializer;
use Laminas\Cache\Storage\StorageInterface;
use Laminas\Serializer\AdapterPluginManager;
use Laminas\ServiceManager\ServiceManager;
use LaminasTest\Cache\Storage\Adapter\AbstractSimpleCacheIntegrationTest;
use LaminasTest\Cache\Storage\Adapter\ModifiableClockTrait;

use function assert;
use function getenv;
use function mkdir;
use function sys_get_temp_dir;
use function tempnam;
use function umask;
use function unlink;

final class FilesystemIntegrationTest extends AbstractSimpleCacheIntegrationTest
{
    use ModifiableClockTrait;

    private string $tmpCacheDir;

    protected int $umask;

    private ?FilesystemOptions $options = null;

    protected function setUp(): void
    {
        $this->skippedTests = [
            'testBasicUsageWithLongKey' => 'Filesystem does only support a maximum of 255 characters for the'
                . ' cache key but test generates a cache key with 300 characters which exceeds that limit',
        ];

        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->umask !== umask()) {
            umask($this->umask);
            $this->fail('Umask was not reset');
        }

        assert($this->options instanceof FilesystemOptions);

        if ($this->options->getCacheDir() !== $this->tmpCacheDir) {
            $this->options->setCacheDir($this->tmpCacheDir);
        }

        parent::tearDown();
    }

    protected function createStorage(): StorageInterface
    {
        $this->umask = umask();

        if (getenv('TESTS_LAMINAS_CACHE_FILESYSTEM_DIR') !== false) {
            $cacheDir = getenv('TESTS_LAMINAS_CACHE_FILESYSTEM_DIR');
        } else {
            $cacheDir = sys_get_temp_dir();
        }

        $this->tmpCacheDir = tempnam($cacheDir, 'laminas_cache_test_');

        if ($this->tmpCacheDir === false) {
            $this->fail("Can't create temporary cache directory-file.");
        } elseif (! unlink($this->tmpCacheDir)) {
            $this->fail("Can't remove temporary cache directory-file: {$this->tmpCacheDir}");
        } elseif (! mkdir($this->tmpCacheDir, 0777)) {
            $this->fail("Can't create temporary cache directory.");
        }

        $this->options = new FilesystemOptions([
            'cache_dir' => $this->tmpCacheDir,
        ]);
        $storage       = new Filesystem($this->options, clock: $this->getClock());

        $storage->addPlugin(new Serializer(new AdapterPluginManager(new ServiceManager())));

        return $storage;
    }
}
