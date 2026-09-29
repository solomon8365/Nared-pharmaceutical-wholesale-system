<?php
/**
 * SettingsService
 *
 * Centralized access to application settings. Pages must use this service
 * instead of reaching into settings tables directly.
 */
class SettingsService
{
    public static function get(string $key, string $default = ''): string
    {
        require_once __DIR__ . '/../config.php';
        return get_setting($key, $default);
    }

    public static function all(): array
    {
        require_once __DIR__ . '/../config.php';
        return get_all_settings();
    }

    public static function set(string $key, string $value): void
    {
        require_once __DIR__ . '/../db.php';
        $pdo = getPDO();
        $pdo->prepare('INSERT INTO settings (key_name, value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE value = VALUES(value)')
            ->execute(['k' => $key, 'v' => $value]);
        clear_settings_cache();
    }

    public static function setMany(array $pairs): void
    {
        require_once __DIR__ . '/../db.php';
        $pdo = getPDO();
        $stmt = $pdo->prepare('INSERT INTO settings (key_name, value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE value = VALUES(value)');
        foreach ($pairs as $k => $v) {
            $stmt->execute(['k' => $k, 'v' => (string) $v]);
        }
        clear_settings_cache();
    }

    public static function currencySymbol(): string
    {
        require_once __DIR__ . '/../config.php';
        return currency_sym();
    }

    public static function formatMoney(float $amount): string
    {
        require_once __DIR__ . '/../config.php';
        return fmt($amount);
    }

    public static function companyName(): string
    {
        require_once __DIR__ . '/../config.php';
        return company_name();
    }

    /** VAT rate (%) with a single '15' fallback. */
    public static function vatRate(): float
    {
        return (float) static::get('vat_rate', '15');
    }

    /** Invoice number prefix with a single 'INV-' fallback. */
    public static function invoicePrefix(): string
    {
        return static::get('invoice_prefix', 'INV-');
    }

    /** Receipt number prefix with a single 'RCP-' fallback. */
    public static function receiptPrefix(): string
    {
        return static::get('receipt_prefix', 'RCP-');
    }

    /** Default credit terms in days with a single '30' fallback. */
    public static function defaultCreditDays(): int
    {
        return (int) static::get('default_credit_days', '30');
    }

    /** Maximum allowed credit limit with a single '500000' fallback. */
    public static function maxCreditLimit(): float
    {
        return (float) static::get('max_credit_limit', '500000');
    }

    /** Whether the whole system is in maintenance mode. */
    public static function maintenanceMode(): bool
    {
        return static::get('maintenance_mode', '0') === '1';
    }

    /** Configured timezone, defaulting to Africa/Addis_Ababa. */
    public static function timezone(): string
    {
        return static::get('timezone', 'Africa/Addis_Ababa');
    }

    /** Global selling markup percentage used to derive selling prices. */
    public static function sellingMarkup(): float
    {
        return (float) (static::get('pricing_selling_markup', '20') ?: 20);
    }

    /** Global wholesale markup percentage used to derive wholesale prices. */
    public static function wholesaleMarkup(): float
    {
        return (float) (static::get('pricing_wholesale_markup', '5') ?: 5);
    }
}
