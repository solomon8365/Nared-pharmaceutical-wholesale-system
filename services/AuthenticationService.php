<?php
/**
 * AuthenticationService
 *
 * Centralized authentication and session management.
 * Wraps the legacy auth.php helpers so pages call one consistent API.
 */
class AuthenticationService
{
    public static function currentUser(): ?array
    {
        require_once __DIR__ . '/../auth.php';
        return currentUser();
    }

    public static function requireLogin(): void
    {
        require_once __DIR__ . '/../auth.php';
        requireLogin();
    }

    public static function login(string $username, string $password): bool
    {
        require_once __DIR__ . '/../auth.php';
        return login($username, $password);
    }

    /** Login against the customer/delivery portal. Returns null on success or an error message. */
    public static function portalLogin(string $username, string $password, ?string $portal): ?string
    {
        require_once __DIR__ . '/../auth.php';
        return loginForPortal($username, $password, $portal);
    }

    public static function logout(): void
    {
        require_once __DIR__ . '/../auth.php';
        logout();
    }

    public static function customerId(): ?int
    {
        require_once __DIR__ . '/../auth.php';
        return customerAccountCustomerId();
    }

    public static function checkLoginAttempts(): ?string
    {
        require_once __DIR__ . '/../auth.php';
        return check_login_attempts();
    }

    public static function recordLoginAttempt(): void
    {
        require_once __DIR__ . '/../auth.php';
        record_login_attempt();
    }

    public static function clearLoginAttempts(): void
    {
        require_once __DIR__ . '/../auth.php';
        clear_login_attempts();
    }

    public static function csrfToken(): string
    {
        require_once __DIR__ . '/../auth.php';
        return csrf_token();
    }

    public static function csrfField(): string
    {
        require_once __DIR__ . '/../auth.php';
        return csrf_field();
    }

    public static function verifyCsrf(?string $token): bool
    {
        require_once __DIR__ . '/../auth.php';
        return verify_csrf_token($token);
    }

    public static function requireCsrf(): void
    {
        require_once __DIR__ . '/../auth.php';
        require_csrf();
    }

    public static function safeRedirect(string $url): void
    {
        require_once __DIR__ . '/../auth.php';
        safeRedirect($url);
    }

    public static function createUser(PDO $pdo, string $username, string $rawPassword, string $fullName, string $role, int $roleId, ?int $customerId = null): int
    {
        require_once __DIR__ . '/../auth.php';
        return userCreate($pdo, $username, $rawPassword, $fullName, $role, $roleId, $customerId);
    }

    public static function updatePassword(PDO $pdo, int $userId, string $rawPassword): void
    {
        require_once __DIR__ . '/../auth.php';
        userUpdatePassword($pdo, $userId, $rawPassword);
    }

    public static function fullName(PDO $pdo, int $userId): string
    {
        require_once __DIR__ . '/../auth.php';
        return userFullName($pdo, $userId);
    }
}
