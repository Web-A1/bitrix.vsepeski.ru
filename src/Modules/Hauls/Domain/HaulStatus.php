<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Domain;

final class HaulStatus
{
    public const PREPARATION = 0;
    public const IN_PROGRESS = 1;
    public const LOADED = 2;
    public const UNLOADED = 3;
    public const VERIFIED = 4;

    public static function label(int $status): string
    {
        return match ($status) {
            self::PREPARATION => 'Подготовка рейса',
            self::IN_PROGRESS => 'Рейс в работе',
            self::LOADED => 'Загрузился',
            self::UNLOADED => 'Выгрузился',
            self::VERIFIED => 'Проверено',
            default => 'Неизвестно',
        };
    }

    public static function driverVisibleStatuses(): array
    {
        return [self::IN_PROGRESS, self::LOADED, self::UNLOADED];
    }

    public static function isDriverVisible(int $status): bool
    {
        return in_array($status, self::driverVisibleStatuses(), true);
    }

    public static function sanitize(int $status): int
    {
        return match (true) {
            $status <= self::PREPARATION => self::PREPARATION,
            $status >= self::VERIFIED => self::VERIFIED,
            default => $status,
        };
    }

    public static function canTransition(int $current, int $next, string $actorRole): bool
    {
        if ($current === $next) {
            return true;
        }

        $actorRole = strtolower($actorRole);

        if ($actorRole === 'driver') {
            return self::canDriverTransition($current, $next);
        }

        return $next >= self::PREPARATION && $next <= self::VERIFIED;
    }

    private static function canDriverTransition(int $current, int $next): bool
    {
        if ($next < self::IN_PROGRESS || $next > self::UNLOADED) {
            return false;
        }

        return match ($current) {
            self::IN_PROGRESS => $next === self::LOADED,
            self::LOADED => in_array($next, [self::IN_PROGRESS, self::UNLOADED], true),
            self::UNLOADED => false,
            default => false,
        };
    }
}
