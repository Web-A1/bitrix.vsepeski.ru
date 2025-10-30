<?php

declare(strict_types=1);

use B24\Center\Core\Application;
use B24\Center\Infrastructure\Http\Kernel;
use Dotenv\Dotenv;

$rootPath = dirname(__DIR__);

if (file_exists($rootPath . '/.env')) {
    Dotenv::createImmutable($rootPath)->safeLoad();
}

$app = new Application();

$app->singleton(Application::class, static fn (Application $container) => $container);
$app->singleton(Kernel::class, static fn (Application $container) => new Kernel());

return $app;
