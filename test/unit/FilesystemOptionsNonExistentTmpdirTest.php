<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter;

use Laminas\Cache\Storage\Adapter\FilesystemOptions;
use PHPUnit\Framework\TestCase;

use function is_writable;
use function sys_get_temp_dir;

use const PHP_OS_FAMILY;

final class FilesystemOptionsNonExistentTmpdirTest extends TestCase
{
    private const MACOS_FAMILY = 'Darwin';

    public function testWillNotWriteToSystemTempWhenCacheDirIsProvided(): void
    {
        if (is_writable(sys_get_temp_dir())) {
            self::markTestSkipped('Test has to be executed with a non existent TMPDIR');
        }

        /**
         * Due to the usage of `realpath` {@see FilesystemOptions::normalizeCacheDirectory()}, the directory is changed
         * depending on the host system. As there might be developers using MacOS brew PHP to execute these tests, we
         * allow MacOS `/private/tmp` as well.
         */
        $expectedTemporaryDirectory = match (PHP_OS_FAMILY) {
            default => '/tmp',
            self::MACOS_FAMILY => '/private/tmp',
        };

        $option = new FilesystemOptions(['cacheDir' => '/./tmp']);

        self::assertSame($expectedTemporaryDirectory, $option->getCacheDir());
    }
}
