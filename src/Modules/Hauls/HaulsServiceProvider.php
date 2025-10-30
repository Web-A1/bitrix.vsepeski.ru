<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls;

use B24\Center\Core\Application;
use B24\Center\Modules\Hauls\Infrastructure\HaulRepository;
use B24\Center\Modules\Hauls\Application\Services\HaulService;
use B24\Center\Modules\Hauls\Infrastructure\MaterialRepository;
use B24\Center\Modules\Hauls\Infrastructure\TruckRepository;
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

        $app->singleton(HaulService::class, static function (Application $container): HaulService {
            /** @var HaulRepository $repository */
            $repository = $container->get(HaulRepository::class);

            return new HaulService($repository);
        });
    }
}
