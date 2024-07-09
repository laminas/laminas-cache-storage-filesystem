<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter;

use Exception;
use Laminas\Cache\Exception\InvalidArgumentException;
use Laminas\Cache\Storage\Adapter\AdapterOptions;
use Laminas\Cache\Storage\Adapter\FilesystemOptions;

use function array_values;
use function assert;
use function chmod;
use function error_get_last;
use function exec;
use function implode;
use function is_array;
use function mkdir;
use function realpath;
use function rmdir;
use function str_replace;
use function strpos;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const PHP_EOL;
use const PHP_OS;

/**
 * @template-extends AbstractAdapterOptionsTest<FilesystemOptions>
 */
final class FilesystemOptionsTest extends AbstractAdapterOptionsTest
{
    protected string $keyPattern = FilesystemOptions::KEY_PATTERN;

    /**
     * @param array $out
     * @psalm-assert list<string> $out
     */
    private static function assertAllString(array $out): void
    {
        assert($out === array_values($out));
        foreach ($out as $value) {
            self::assertIsString($value);
        }
    }

    protected function createAdapterOptions(): AdapterOptions
    {
        return new FilesystemOptions();
    }

    public function testSetCacheDirToSystemsTempDirWithNull(): void
    {
        $this->options->setCacheDir(null);
        self::assertEquals(realpath(sys_get_temp_dir()), $this->options->getCacheDir());
    }

    public function testSetCacheDirNoDirectoryException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->options->setCacheDir(__FILE__);
    }

    public function testNormalizeCacheDir(): void
    {
        $cacheDir = $cacheDirExpected = realpath(sys_get_temp_dir());

        if (DIRECTORY_SEPARATOR !== '/') {
            $cacheDir = str_replace(DIRECTORY_SEPARATOR, '/', $cacheDir);
        }

        $firstSlash = strpos($cacheDir, '/');
        assert($firstSlash !== false);
        $cacheDir = substr($cacheDir, 0, $firstSlash + 1)
            . '..//../'
            . substr($cacheDir, $firstSlash)
            . '///';

        $this->options->setCacheDir($cacheDir);
        $cacheDir = $this->options->getCacheDir();

        self::assertEquals($cacheDirExpected, $cacheDir);
    }

    public function testSetCacheDirNotWritableException(): void
    {
        if (substr(PHP_OS, 0, 3) === 'WIN') {
            self::markTestSkipped('Not testable on windows');
        } else {
            $out = [];
            @exec('whoami 2>&1', $out, $ret);
            if ($ret) {
                $err = error_get_last();
                assert(is_array($err));
                self::markTestSkipped('Not testable:' . $err['message']);
            } elseif (isset($out[0]) && $out[0] === 'root') {
                self::markTestSkipped('Not testable as root');
            }
        }

        $this->expectException(InvalidArgumentException::class);

        // create a not writable temporaty directory
        $testDir = tempnam(sys_get_temp_dir(), 'LaminasTest');
        unlink($testDir);
        mkdir($testDir);
        chmod($testDir, 0557);

        try {
            $this->options->setCacheDir($testDir);
        } catch (Exception $e) {
            rmdir($testDir);
            throw $e;
        }
    }

    public function testSetCacheDirNotReadableException(): void
    {
        if (substr(PHP_OS, 0, 3) === 'WIN') {
            self::markTestSkipped('Not testable on windows');
        } else {
            @exec('whoami 2>&1', $out, $ret);
            self::assertAllString($out);
            if ($ret !== 0) {
                self::markTestSkipped('Not testable: ' . implode(PHP_EOL, $out));
            } elseif (isset($out[0]) && $out[0] === 'root') {
                self::markTestSkipped('Not testable as root');
            }
        }

        $this->expectException(InvalidArgumentException::class);

        // create a not readable temporaty directory
        $testDir = tempnam(sys_get_temp_dir(), 'LaminasTest');
        unlink($testDir);
        mkdir($testDir);
        chmod($testDir, 0337);

        try {
            $this->options->setCacheDir($testDir);
        } catch (Exception $e) {
            rmdir($testDir);
            throw $e;
        }
    }

    public function testSetFilePermissionThrowsExceptionIfNotWritable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->options->setFilePermission(0466);
    }

    public function testSetFilePermissionThrowsExceptionIfNotReadable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->options->setFilePermission(0266);
    }

    public function testSetFilePermissionThrowsExceptionIfExecutable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->options->setFilePermission(0661);
    }

    public function testSetDirPermissionThrowsExceptionIfNotWritable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->options->setDirPermission(0577);
    }

    public function testSetDirPermissionThrowsExceptionIfNotReadable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->options->setDirPermission(0377);
    }

    public function testSetDirPermissionThrowsExceptionIfNotExecutable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->options->setDirPermission(0677);
    }

    public function testSetDirLevelInvalidException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->options->setDirLevel(17); // must between 0-16
    }

    public function testSetUmask(): void
    {
        $this->options->setUmask(023);
        self::assertSame(021, $this->options->getUmask());

        $this->options->setUmask(false);
        self::assertFalse($this->options->getUmask());
    }

    public function testSetUmaskThrowsExceptionIfNotWritable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->options->setUmask(0300);
    }

    public function testSetUmaskThrowsExceptionIfNotReadable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->options->setUmask(0200);
    }

    public function testSetUmaskThrowsExceptionIfNotExecutable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->options->setUmask(0100);
    }

    public function testSuffixIsMutable(): void
    {
        $this->options->setSuffix('.cache');
        self::assertSame('.cache', $this->options->getSuffix());
    }

    public function testTagSuffixIsMutable(): void
    {
        $this->options->setTagSuffix('.cache');
        self::assertSame('.cache', $this->options->getTagSuffix());
    }

    public function testSetKeyPatternThrowsInvalidArgumentExceptionWhenCalledWithNonPredefinedPattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->options->setKeyPattern('abc123');
    }

    public function testSetKeyPatternAllowEmptyString(): void
    {
        self::markTestSkipped('Test modifies key pattern which cannot be modified for filesystem adapter.');
    }

    public function testSetFromArrayWithoutPrioritizedOptions(): void
    {
        self::markTestSkipped('Test modifies key pattern which cannot be modified for filesystem adapter.');
    }

    public function testKeyPattern(): void
    {
        self::markTestSkipped('Test modifies key pattern which cannot be modified for filesystem adapter.');
    }
}
