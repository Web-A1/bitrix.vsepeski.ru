<?php

declare(strict_types=1);

namespace B24\Center\Infrastructure\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class PlacementJobLoggerFactory
{
    public static function create(string $projectRoot): LoggerInterface
    {
        $enabled = InstallLoggerFactory::boolEnv('PLACEMENT_LOG_ENABLED', true);
        if (!$enabled) {
            return new NullLogger();
        }

        $path = $_ENV['PLACEMENT_LOG_PATH'] ?? $projectRoot . '/storage/logs/placement-jobs.log';
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return new NullLogger();
        }

        $levelName = strtoupper((string) ($_ENV['PLACEMENT_LOG_LEVEL'] ?? 'INFO'));
        try {
            $level = Level::fromName($levelName);
        } catch (\Throwable) {
            $level = Level::Info;
        }

        $maxFiles = (int) ($_ENV['PLACEMENT_LOG_MAX_FILES'] ?? 14);
        $handler = new RotatingFileHandler($path, $maxFiles, $level);
        $handler->setFormatter(new JsonFormatter());

        $logger = new Logger('placement');
        $logger->pushHandler($handler);

        return $logger;
    }
}
