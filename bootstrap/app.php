<?php

declare(strict_types=1);

use B24\Center\Core\Application;
use B24\Center\Infrastructure\Auth\SessionAuthManager;
use B24\Center\Infrastructure\Bitrix\BitrixPortalAuthenticator;
use B24\Center\Infrastructure\Http\Kernel;
use B24\Center\Infrastructure\Persistence\Database\DatabaseServiceProvider;
use B24\Center\Modules\Hauls\HaulsServiceProvider;
use Dotenv\Dotenv;

$rootPath = dirname(__DIR__);

if (file_exists($rootPath . '/.env')) {
    Dotenv::createImmutable($rootPath)->safeLoad();
}

$app = new Application();

$bitrixConfig = require $rootPath . '/config/bitrix.php';

(new DatabaseServiceProvider())->register($app);
(new HaulsServiceProvider())->register($app);

$app->singleton(Application::class, static fn (Application $container) => $container);
$app->singleton(SessionAuthManager::class, static fn (): SessionAuthManager => new SessionAuthManager());
$app->singleton(BitrixPortalAuthenticator::class, static fn (): BitrixPortalAuthenticator => new BitrixPortalAuthenticator($bitrixConfig['portal_url'] ?? ''));
$app->singleton(Kernel::class, static fn (Application $container) => new Kernel($container));

return $app;
