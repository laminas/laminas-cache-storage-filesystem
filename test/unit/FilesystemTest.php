<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter;

use __PHP_Incomplete_Class;
use DateTimeZone;
use Laminas\Cache\Exception\RuntimeException;
use Laminas\Cache\Storage\Adapter\Filesystem;
use Laminas\Cache\Storage\Adapter\FilesystemOptions;
use Laminas\Cache\Storage\Plugin\ExceptionHandler;
use Laminas\Cache\Storage\Plugin\PluginOptions;
use LaminasTest\Cache\Storage\Adapter\Filesystem\TestAsset\ModifiableClock;
use LaminasTest\Cache\Storage\Adapter\Filesystem\TestAsset\SerializableObject;
use stdClass;

use function assert;
use function chmod;
use function count;
use function error_get_last;
use function fileatime;
use function filectime;
use function filemtime;
use function filesize;
use function getenv;
use function glob;
use function md5;
use function mkdir;
use function str_repeat;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function umask;
use function unlink;

/**
 * @template-extends AbstractCommonAdapterTest<FilesystemOptions,Filesystem>
 */
final class FilesystemTest extends AbstractCommonAdapterTest
{
    protected string $tmpCacheDir;

    protected int $umask;

    private ModifiableClock $clock;

    protected function setUp(): void
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
        } elseif (! @unlink($this->tmpCacheDir)) {
            $err = error_get_last();
            $this->fail("Can't remove temporary cache directory-file: {$err['message']}");
        } elseif (! @mkdir($this->tmpCacheDir, 0777)) {
            $err = error_get_last();
            $this->fail("Can't create temporary cache directory: {$err['message']}");
        }

        $this->clock   = new ModifiableClock(new DateTimeZone('UTC'));
        $this->options = new FilesystemOptions([
            'cache_dir' => $this->tmpCacheDir,
        ]);
        $this->storage = new Filesystem($this->options, clock: $this->clock);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->umask !== umask()) {
            umask($this->umask);
            $this->fail("Umask wasn't reset");
        }

        if ($this->options->getCacheDir() !== $this->tmpCacheDir) {
            $this->options->setCacheDir($this->tmpCacheDir);
        }

        parent::tearDown();
    }

    public function testFileSystemeOptionIsUpdatedWhenFileSystemeOptionIsChange(): void
    {
        $storage = new Filesystem();
        $options = new FilesystemOptions();
        $storage->setOptions($options);
        $options->setCacheDir($this->tmpCacheDir);

        self::assertSame($this->tmpCacheDir, $storage->getOptions()->getCacheDir());
    }

    public function testGetMetadataWithCtime(): void
    {
        $this->options->setNoCtime(false);

        self::assertTrue($this->storage->setItem('test', 'v'));

        $meta = $this->storage->getMetadata('test');
        self::assertNotNull($meta);

        $expectedCtime = filectime($meta->filespec . '.' . Filesystem::FILENAME_SUFFIX);
        self::assertEquals($expectedCtime, $meta->creationTime);
    }

    public function testGetMetadataWithAtime(): void
    {
        $this->options->setNoAtime(false);

        self::assertTrue($this->storage->setItem('test', 'v'));

        $meta = $this->storage->getMetadata('test');
        self::assertNotNull($meta);

        $expectedAtime = fileatime($meta->filespec . '.' . Filesystem::FILENAME_SUFFIX);
        self::assertEquals($expectedAtime, $meta->lastAccessTime);
    }

    public function testGetMetadataWithFilesize(): void
    {
        $this->options->setNoAtime(false);

        self::assertTrue($this->storage->setItem('test', 'v'));

        $meta = $this->storage->getMetadata('test');
        self::assertNotNull($meta);

        $expectedAtime = filesize($meta->filespec . '.' . Filesystem::FILENAME_SUFFIX);
        self::assertEquals($expectedAtime, $meta->filesize);
    }

    public function testGetMetadataWithMtime(): void
    {
        $this->options->setNoAtime(false);

        self::assertTrue($this->storage->setItem('test', 'v'));

        $meta = $this->storage->getMetadata('test');
        self::assertNotNull($meta);

        $expectedAtime = filemtime($meta->filespec . '.' . Filesystem::FILENAME_SUFFIX);
        self::assertEquals($expectedAtime, $meta->lastModifiedTime);
    }

    public function testClearExpiredExceptionTriggersEvent(): void
    {
        $this->options->setTtl(1);
        $this->storage->setItem('k', 'v');
        $dirs = glob($this->tmpCacheDir . '/*');
        if (count($dirs) === 0) {
            $this->fail('Could not find cache dir');
        }
        chmod($dirs[0], 0500); //make directory rx, unlink should fail
        $this->clock->addSeconds(1);

        $callbackWasCalled = false;
        $callback          = static function () use (&$callbackWasCalled): void {
            $callbackWasCalled = true;
        };
        $plugin            = new ExceptionHandler();
        $options           = new PluginOptions(['throw_exceptions' => false, 'exception_callback' => $callback]);
        $plugin->setOptions($options);
        $this->storage->addPlugin($plugin);
        $this->storage->clearExpired();
        chmod($dirs[0], 0700); //set dir back to writable for tearDown
        self::assertTrue($callbackWasCalled);
    }

    public function testClearByNamespaceWithUnexpectedDirectory(): void
    {
        // create cache items at 2 different directory levels
        $this->options->setDirLevel(2);
        $this->storage->setItem('a_key', 'a_value');
        $this->options->setDirLevel(1);
        $this->storage->setItem('b_key', 'b_value');
        $namespace = $this->options->getNamespace();
        self::assertNotEmpty($namespace);
        $this->storage->clearByNamespace($namespace);
    }

    public function testClearByPrefixWithUnexpectedDirectory(): void
    {
        // create cache items at 2 different directory levels
        $this->options->setDirLevel(2);
        $this->storage->setItem('a_key', 'a_value');
        $this->options->setDirLevel(1);
        $this->storage->setItem('b_key', 'b_value');
        $glob = glob($this->tmpCacheDir . '/*');
        //contrived prefix which will collide with an existing directory
        $prefix = substr(md5('a_key'), 2, 2);
        assert($prefix !== '');
        self::assertTrue($this->storage->clearByPrefix($prefix));
    }

    public function testEmptyTagsArrayClearsTags(): void
    {
        $key  = 'key';
        $tags = ['tag1', 'tag2', 'tag3'];
        self::assertTrue($this->storage->setItem($key, 100));
        self::assertTrue($this->storage->setTags($key, $tags));
        self::assertNotEmpty($this->storage->getTags($key));
        self::assertTrue($this->storage->setTags($key, []));
        self::assertEmpty($this->storage->getTags($key));
    }

    public function testWillThrowRuntimeExceptionIfNamespaceIsTooLong(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid maximum key length was calculated.');

        $options = new FilesystemOptions([
            'namespace'           => str_repeat('a', 249),
            'namespace_separator' => '::',
        ]);

        $storage = new Filesystem($options);
        $storage->getCapabilities();
    }

    public function testRespectsSerializableClassesWhenReadingItems(): void
    {
        $this->options->setUnserializableClasses([stdClass::class]);

        $object = new stdClass();
        self::assertTrue($this->storage->setItem('key', $object));
        self::assertInstanceOf(stdClass::class, $this->storage->getItem('key'));

        $object = new SerializableObject();
        self::assertTrue($this->storage->setItem('key', $object));
        self::assertInstanceOf(__PHP_Incomplete_Class::class, $this->storage->getItem('key'));
    }
}
