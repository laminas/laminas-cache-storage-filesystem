<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter\Filesystem;

use Laminas\Cache\Storage\Adapter\Filesystem;
use Laminas\Cache\Storage\Adapter\Filesystem\AdapterPluginManagerDelegatorFactory;
use LaminasTest\Cache\Storage\Adapter\PluginManagerDelegatorFactoryTestTrait;
use PHPUnit\Framework\TestCase;

final class AdapterPluginManagerDelegatorFactoryTest extends TestCase
{
    use PluginManagerDelegatorFactoryTestTrait;

    private AdapterPluginManagerDelegatorFactory $delegator;

    public static function getCommonAdapterNamesProvider(): iterable
    {
        return [
            'lowercase'    => ['filesystem'],
            'ucfirst'      => ['Filesystem'],
            'class-string' => [Filesystem::class],
        ];
    }

    public function getDelegatorFactory(): callable
    {
        return $this->delegator;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->delegator = new AdapterPluginManagerDelegatorFactory();
    }
}
