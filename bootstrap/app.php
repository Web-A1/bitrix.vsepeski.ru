<?php

declare(strict_types=1);

use B24\Center\Core\Application;
use B24\Center\Infrastructure\Auth\ActorContextResolver;
use B24\Center\Infrastructure\Auth\LocalDriverAuthenticator;
use B24\Center\Infrastructure\Auth\SessionAuthManager;
use B24\Center\Infrastructure\Http\Kernel;
use B24\Center\Infrastructure\Persistence\Database\DatabaseServiceProvider;
use B24\Center\Modules\Hauls\HaulsServiceProvider;
use B24\Center\Modules\Hauls\Infrastructure\DriverAccountRepository;
use B24\Center\Infrastructure\Bitrix\BitrixUserResolver;
use Dotenv\Dotenv;

$rootPath = dirname(__DIR__);

if (file_exists($rootPath . '/.env')) {
    Dotenv::createImmutable($rootPath)->safeLoad();
}

if (file_exists($rootPath . '/.env.local')) {
    Dotenv::createImmutable($rootPath, ['.env.local'])->safeLoad();
}

$app = new Application();

(new DatabaseServiceProvider())->register($app);
(new HaulsServiceProvider())->register($app);

$app->singleton(Application::class, static fn (Application $container) => $container);
$app->singleton(SessionAuthManager::class, static fn (): SessionAuthManager => new SessionAuthManager());
$app->singleton(ActorContextResolver::class, static function (Application $container): ActorContextResolver {
    return new ActorContextResolver($container->get(SessionAuthManager::class));
});
$app->singleton(LocalDriverAuthenticator::class, static function (Application $container): LocalDriverAuthenticator {
    /** @var DriverAccountRepository $repository */
    $repository = $container->get(DriverAccountRepository::class);

    return new LocalDriverAuthenticator($repository);
});
$app->singleton(BitrixUserResolver::class, static function (): BitrixUserResolver {
    $config = require dirname(__DIR__) . '/config/bitrix.php';
    $portalUrl = $config['portal_url'] ?? '';

    return new BitrixUserResolver($portalUrl);
});
$app->singleton(Kernel::class, static fn (Application $container) => new Kernel($container));

return $app;
