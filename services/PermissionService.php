<?php
/**
 * PermissionService
 *
 * Centralized role & permission checking. Pages must call these methods
 * instead of rolling their own role logic or touching permission tables.
 */
class PermissionService
{
    public static function role(): string
    {
        require_once __DIR__ . '/../auth.php';
        return userRole();
    }

    public static function isAdmin(): bool
    {
        require_once __DIR__ . '/../auth.php';
        return isAdmin();
    }

    public static function isCustomer(): bool
    {
        require_once __DIR__ . '/../auth.php';
        return isCustomer();
    }

    public static function isDeliveryStaff(): bool
    {
        require_once __DIR__ . '/../auth.php';
        return isDeliveryStaff();
    }

    public static function isFinanceOfficer(): bool
    {
        require_once __DIR__ . '/../auth.php';
        return isFinanceOfficer();
    }

    public static function isSalesperson(): bool
    {
        require_once __DIR__ . '/../auth.php';
        return isSalesperson();
    }

    public static function requireAdmin(): void
    {
        require_once __DIR__ . '/../auth.php';
        requireAdmin();
    }

    public static function requireCustomer(): void
    {
        require_once __DIR__ . '/../auth.php';
        requireCustomer();
    }

    public static function requireDeliveryStaff(): void
    {
        require_once __DIR__ . '/../auth.php';
        requireDeliveryStaff();
    }

    public static function requireFinanceOfficer(): void
    {
        require_once __DIR__ . '/../auth.php';
        requireFinanceOfficer();
    }

    public static function has(string $module, string $permission = 'view'): bool
    {
        require_once __DIR__ . '/../auth.php';
        return hasPermission($module, $permission);
    }

    public static function hasAnyModule(array $modules, string $permission = 'view'): bool
    {
        require_once __DIR__ . '/../auth.php';
        return hasAnyModulePermission($modules, $permission);
    }

    public static function require(string $module, string $permission = 'view'): void
    {
        require_once __DIR__ . '/../auth.php';
        requirePermission($module, $permission);
    }

    public static function roleId(): ?int
    {
        require_once __DIR__ . '/../auth.php';
        return getUserRoleId();
    }

    public static function permissions(): array
    {
        require_once __DIR__ . '/../auth.php';
        return getPermissions();
    }

    /** True when the current user may override credit limits (finance/admin). */
    public static function canOverrideCredit(): bool
    {
        require_once __DIR__ . '/../includes/credit_helpers.php';
        return creditCanOverride();
    }

    public static function loadPermissionsForRole(int $roleId): array
    {
        require_once __DIR__ . '/../auth.php';
        return loadPermissionsForRole($roleId);
    }

    public static function copyRolePermissions(int $fromRoleId, int $toRoleId): void
    {
        require_once __DIR__ . '/../auth.php';
        copyRolePermissions($fromRoleId, $toRoleId);
    }

    public static function saveRolePermissions(int $roleId, array $submittedPerms, array $modules, array $allPermissions): void
    {
        require_once __DIR__ . '/../auth.php';
        saveRolePermissions($roleId, $submittedPerms, $modules, $allPermissions);
    }

    public static function permissionVersion(): int
    {
        require_once __DIR__ . '/../auth.php';
        return getPermVersion();
    }

    public static function bumpPermissionVersion(): void
    {
        require_once __DIR__ . '/../auth.php';
        bumpPermVersion();
    }
}
