<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls;

use B24\Center\Core\Application;
use B24\Center\Modules\Hauls\Infrastructure\DriverAccountRepository;
use B24\Center\Modules\Hauls\Infrastructure\HaulRepository;
use B24\Center\Modules\Hauls\Infrastructure\HaulChangeHistoryRepository;
use B24\Center\Modules\Hauls\Infrastructure\HaulStatusHistoryRepository;
use B24\Center\Modules\Hauls\Application\Services\HaulService;
use B24\Center\Modules\Hauls\Infrastructure\MaterialRepository;
use B24\Center\Modules\Hauls\Infrastructure\TruckRepository;
use B24\Center\Infrastructure\Bitrix\BitrixRestClient;
use B24\Center\Modules\Hauls\Application\Services\DriverLookupService;
use B24\Center\Modules\Hauls\Application\Services\CompanyDirectoryService;
use B24\Center\Modules\Hauls\Application\Services\DealInfoService;
use B24\Center\Modules\Hauls\Ui\HaulPlacementPageRenderer;
use B24\Center\Support\FileCache;
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

        $app->singleton(HaulStatusHistoryRepository::class, static function (Application $container): HaulStatusHistoryRepository {
            /** @var PDO $connection */
            $connection = $container->get(PDO::class);

            return new HaulStatusHistoryRepository($connection);
        });

        $app->singleton(HaulChangeHistoryRepository::class, static function (Application $container): HaulChangeHistoryRepository {
            /** @var PDO $connection */
            $connection = $container->get(PDO::class);

            return new HaulChangeHistoryRepository($connection);
        });

        $app->singleton(DriverAccountRepository::class, static function (Application $container): DriverAccountRepository {
            /** @var PDO $connection */
            $connection = $container->get(PDO::class);

            return new DriverAccountRepository($connection);
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

        $app->singleton(FileCache::class, static function (): FileCache {
            $projectRoot = dirname(__DIR__, 3);
            $directory = $projectRoot . '/storage/cache';

            return new FileCache($directory);
        });

        $app->singleton(DriverLookupService::class, static function (Application $container): DriverLookupService {
            $config = require dirname(__DIR__, 3) . '/config/bitrix.php';
            $department = $config['drivers_department'] ?? 'Водители';

            return new DriverLookupService(
                $container->get(BitrixRestClient::class),
                $department,
                $container->get(FileCache::class)
            );
        });

        $app->singleton(CompanyDirectoryService::class, static function (Application $container): CompanyDirectoryService {
            $config = require dirname(__DIR__, 3) . '/config/bitrix.php';
            $types = $config['company_types'] ?? [];

            return new CompanyDirectoryService(
                $container->get(BitrixRestClient::class),
                $container->get(FileCache::class),
                $types
            );
        });

        $app->singleton(DealInfoService::class, static function (Application $container): DealInfoService {
            $config = require dirname(__DIR__, 3) . '/config/bitrix.php';
            $field = $config['deal_material_field'] ?? '';

            return new DealInfoService(
                $container->get(BitrixRestClient::class),
                is_string($field) ? $field : ''
            );
        });

        $app->singleton(HaulService::class, static function (Application $container): HaulService {
            /** @var HaulRepository $repository */
            $repository = $container->get(HaulRepository::class);
            /** @var HaulStatusHistoryRepository $history */
            $history = $container->get(HaulStatusHistoryRepository::class);
            /** @var HaulChangeHistoryRepository $changeHistory */
            $changeHistory = $container->get(HaulChangeHistoryRepository::class);

            return new HaulService($repository, $history, $changeHistory);
        });

        $app->singleton(HaulPlacementPageRenderer::class, static function (): HaulPlacementPageRenderer {
            $projectRoot = dirname(__DIR__, 3);

            return new HaulPlacementPageRenderer($projectRoot);
        });
    }
}
