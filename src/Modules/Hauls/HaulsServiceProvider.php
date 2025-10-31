<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls;

use B24\Center\Core\Application;
use B24\Center\Modules\Hauls\Infrastructure\HaulRepository;
use B24\Center\Modules\Hauls\Application\Services\HaulService;
use B24\Center\Modules\Hauls\Infrastructure\MaterialRepository;
use B24\Center\Modules\Hauls\Infrastructure\TruckRepository;
use B24\Center\Infrastructure\Bitrix\BitrixRestClient;
use B24\Center\Modules\Hauls\Application\Services\DriverLookupService;
use PDO;

class HaulsServiceProvider
{
    public function register(Application $app): void
    {
        $app->singleton(HaulRepository::class, static function (Application $container): HaulRepository {
            /** @var PDO $connection */
            $connection = $container->get(PDO::class);

            return new HaulRepository($connection);
        });

        $app->singleton(MaterialRepository::class, static function (Application $container): MaterialRepository {
            /** @var PDO $connection */
            $connection = $container->get(PDO::class);

            return new MaterialRepository($connection);
        });

        $app->singleton(TruckRepository::class, static function (Application $container): TruckRepository {
            /** @var PDO $connection */
            $connection = $container->get(PDO::class);

            return new TruckRepository($connection);
        });

        $app->singleton(BitrixRestClient::class, static function (): BitrixRestClient {
            $config = require dirname(__DIR__, 3) . '/config/bitrix.php';
            $webhookUrl = rtrim($config['webhook_url'] ?? '', '/');

            return new BitrixRestClient($webhookUrl);
        });

        $app->singleton(DriverLookupService::class, static function (Application $container): DriverLookupService {
            $config = require dirname(__DIR__, 3) . '/config/bitrix.php';
            $department = $config['drivers_department'] ?? 'Водители';

            return new DriverLookupService($container->get(BitrixRestClient::class), $department);
        });

        $app->singleton(HaulService::class, static function (Application $container): HaulService {
            /** @var HaulRepository $repository */
            $repository = $container->get(HaulRepository::class);

            return new HaulService($repository);
        });
    }
}
