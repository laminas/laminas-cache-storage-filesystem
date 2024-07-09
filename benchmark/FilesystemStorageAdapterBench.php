<?php

declare(strict_types=1);

namespace LaminasBench\Cache;

use Laminas\Cache\Storage\Adapter\Benchmark\AbstractStorageAdapterBenchmark;
use Laminas\Cache\Storage\Adapter\Filesystem;
use Laminas\Cache\Storage\Adapter\FilesystemOptions;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

/**
 * @template-extends AbstractStorageAdapterBenchmark<FilesystemOptions>
 */
#[Revs(100)]
#[Iterations(10)]
#[Warmup(1)]
final class FilesystemStorageAdapterBench extends AbstractStorageAdapterBenchmark
{
    public function __construct()
    {
        parent::__construct(new Filesystem());
    }
}
