<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';
use Laminas\Cache\Storage\Adapter\FilesystemOptions;

$option = new FilesystemOptions(['cacheDir' => '/./tmp']);
echo $option->getCacheDir();
