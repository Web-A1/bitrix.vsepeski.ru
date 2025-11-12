<?php

declare(strict_types=1);

namespace B24\Center\Infrastructure\Auth;

use B24\Center\Modules\Hauls\Application\DTO\ActorContext;

final class ActorContextResolver
{
    public function __construct(private readonly SessionAuthManager $authManager)
    {
    }

    public function resolve(string $defaultRole = 'manager'): ActorContext
    {
        $user = $this->authManager->user();

        if ($user === null) {
            return new ActorContext(null, null, $defaultRole);
        }

        return new ActorContext(
            $this->extractId($user),
            $this->extractName($user),
            $this->determineRole($user, $defaultRole)
        );
    }

    /**
     * @param array<string,mixed> $user
     */
    private function determineRole(array $user, string $defaultRole): string
    {
        $explicitRole = $user['role'] ?? $user['ROLE'] ?? null;
        if (is_string($explicitRole) && $explicitRole !== '') {
            $normalized = $this->normalizeRole($explicitRole);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        if ($this->hasAdminFlag($user)) {
            return 'admin';
        }

        return $defaultRole;
    }

    private function normalizeRole(string $role): ?string
    {
        $normalized = strtolower(trim($role));

        return match ($normalized) {
            'admin', 'driver', 'manager' => $normalized,
            default => null,
        };
    }

    /**
     * @param array<string,mixed> $user
     */
    private function hasAdminFlag(array $user): bool
    {
        $candidates = [
            $user['admin'] ?? null,
            $user['ADMIN'] ?? null,
            $user['is_admin'] ?? null,
            $user['IS_ADMIN'] ?? null,
            $user['isAdmin'] ?? null,
            $user['ISADMIN'] ?? null,
            $user['is_administrator'] ?? null,
            $user['IS_ADMINISTRATOR'] ?? null,
            $user['isAdministrator'] ?? null,
            $user['is_super_admin'] ?? null,
            $user['IS_SUPER_ADMIN'] ?? null,
            $user['isSuperAdmin'] ?? null,
            $user['IS_SUPERADMIN'] ?? null,
            $user['is_portal_admin'] ?? null,
            $user['IS_PORTAL_ADMIN'] ?? null,
            $user['isPortalAdmin'] ?? null,
        ];

        $rightsList = $user['rights'] ?? $user['RIGHTS'] ?? null;
        if (is_array($rightsList)) {
            $normalizedRights = array_map(
                static fn ($value): string => is_string($value) ? strtolower($value) : '',
                $rightsList
            );
            $candidates[] = in_array('admin', $normalizedRights, true);
        }

        foreach ($candidates as $value) {
            if ($this->isTruthyFlag($value)) {
                return true;
            }
        }

        return false;
    }

    private function isTruthyFlag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return $normalized !== '' && in_array($normalized, ['1', 'y', 'yes', 'true', 'admin'], true);
        }

        return false;
    }

    /**
     * @param array<string,mixed> $user
     */
    private function extractId(array $user): ?int
    {
        $candidates = [
            $user['id'] ?? null,
            $user['ID'] ?? null,
            $user['bitrix_user_id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            $numeric = filter_var($candidate, FILTER_VALIDATE_INT);
            if ($numeric !== false && $numeric > 0) {
                return (int) $numeric;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $user
     */
    private function extractName(array $user): ?string
    {
        $fields = [
            $user['name'] ?? null,
            $user['NAME'] ?? null,
            $user['login'] ?? null,
            $user['LOGIN'] ?? null,
            $user['email'] ?? null,
            $user['EMAIL'] ?? null,
        ];

        foreach ($fields as $field) {
            if (!is_string($field)) {
                continue;
            }

            $candidate = trim($field);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
