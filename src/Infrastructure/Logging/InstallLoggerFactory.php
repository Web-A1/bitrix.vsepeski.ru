<?php

declare(strict_types=1);

namespace B24\Center\Infrastructure\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class InstallLoggerFactory
{
    public static function create(string $projectRoot): LoggerInterface
    {
        $enabled = self::boolEnv('INSTALL_LOG_ENABLED', true);
        if (!$enabled) {
            return new NullLogger();
        }

        $targetPath = $_ENV['INSTALL_LOG_PATH'] ?? $projectRoot . '/storage/logs/install.log';
        $directory = dirname($targetPath);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return new NullLogger();
        }

        $levelName = strtoupper((string) ($_ENV['INSTALL_LOG_LEVEL'] ?? 'INFO'));
        try {
            $level = Level::fromName($levelName);
        } catch (\Throwable) {
            $level = Level::Info;
        }

        try {
            $maxFiles = (int) ($_ENV['INSTALL_LOG_MAX_FILES'] ?? 14);
            $handler = new RotatingFileHandler($targetPath, $maxFiles, $level);
        } catch (\Throwable) {
            return new NullLogger();
        }

        $handler->setFormatter(new JsonFormatter());

        $logger = new Logger('install');
        $logger->pushHandler($handler);

        $logger->pushProcessor(static function (LogRecord $record): LogRecord {
            if (isset($record->context['exception']) && $record->context['exception'] instanceof \Throwable) {
                $exception = $record->context['exception'];
                $record->context['exception'] = [
                    'message' => $exception->getMessage(),
                    'code' => $exception->getCode(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ];
            }

            return $record;
        });

        return $logger;
    }

    public static function boolEnv(string $key, bool $default): bool
    {
        $value = $_ENV[$key] ?? $default;

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower($value);
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return (bool) $value;
    }
}
