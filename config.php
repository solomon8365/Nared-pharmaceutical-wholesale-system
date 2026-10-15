<?php
// Database and application configuration

// ── Server-side secrets/configuration ────────────────────────────────────────
// Credentials are read from the process environment first, then from a
// git-ignored project-root .env file. The literal defaults below keep the
// existing local XAMPP behaviour when neither is present. No secret is ever
// committed to the repository.
require_once __DIR__ . '/includes/env.php';
nared_env_load(__DIR__ . '/.env');

// MySQL — the PRIMARY database for all business data (products, inventory,
// batches, customers, orders, invoices, payments, delivery).
define('DB_HOST', nared_env('DB_HOST', '127.0.0.1'));
define('DB_NAME', nared_env('DB_NAME', 'pharma'));
define('DB_USER', nared_env('DB_USER', 'root'));
define('DB_PASS', (string) nared_env('DB_PASS', ''));

define('APP_NAME', 'NARED PHARMACON');
define('APP_BASE', '');
define('PRODUCT_UPLOAD_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products');
define('PRODUCT_UPLOAD_URL', 'uploads/products');
define('PAYMENT_UPLOAD_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'payments');
define('PAYMENT_UPLOAD_URL', 'uploads/payments');
define('MAX_UPLOAD_SIZE', 2 * 1024 * 1024);

// ── Supabase — OPTIONAL object storage for uploaded files ─────────────────────
// Supabase is used ONLY for binary file storage (product images, payment
// screenshots, delivery POD, receipts). It is reached through its HTTPS REST
// API (the PHP build has no pdo_pgsql driver), and it is never used as the
// business database or as the authentication provider. See docs/SUPABASE.md.
define('SUPABASE_URL', rtrim((string) nared_env('SUPABASE_URL', ''), '/'));
define('SUPABASE_ANON_KEY', (string) nared_env('SUPABASE_ANON_KEY', ''));
define('SUPABASE_SERVICE_ROLE_KEY', (string) nared_env('SUPABASE_SERVICE_ROLE_KEY', ''));
define('SUPABASE_STORAGE_BUCKET', (string) nared_env('SUPABASE_STORAGE_BUCKET', 'nared-uploads'));
define('SUPABASE_STORAGE_ENABLED', nared_env_bool('SUPABASE_STORAGE_ENABLED', false));

define('IS_PRODUCTION', nared_env_bool('APP_PRODUCTION', false));

if (IS_PRODUCTION) {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
} else {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

define('CURRENCY', 'ETB');

function currency_sym(): string
{
    return get_setting('currency', 'ETB');
}

function company_name(): string
{
    static $name = null;
    if ($name === null) {
        $name = APP_NAME;
        if (function_exists('getPDO')) {
            try {
                $pdo = getPDO();
                $stmt = $pdo->prepare("SELECT value FROM settings WHERE key_name = 'company_name'");
                $stmt->execute();
                $v = $stmt->fetchColumn();
                if ($v) $name = $v;
            } catch (\Throwable $e) {}
        }
    }
    return $name;
}

function brand_logo(): string
{
    static $logo = null;
    if ($logo === null) {
        $logo = 'assets/img/nared-logo.jpg';
        $v = function_exists('get_setting') ? get_setting('company_logo', '') : '';
        if ($v !== '' && function_exists('storage_exists') && storage_exists($v)) {
            $logo = $v;
        } elseif ($v !== '' && !function_exists('storage_exists') && is_file(__DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $v))) {
            $logo = $v;
        }
    }
    return $logo;
}

function brand_favicon(): string
{
    static $fav = null;
    if ($fav === null) {
        foreach (['assets/img/favicon-32.png', 'assets/img/nared-logo.jpg'] as $cand) {
            if (is_file(__DIR__ . '/' . $cand)) { $fav = $cand; break; }
        }
        if ($fav === null) $fav = brand_logo();
    }
    return $fav;
}

function favicon_tags(): string
{
    $f = htmlspecialchars(brand_favicon());
    $apple = __DIR__ . '/assets/img/apple-touch-icon.png';
    $html = '<link rel="icon" type="image/png" href="' . $f . '">';
    if (is_file($apple)) {
        $html .= '<link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">';
    }
    return $html;
}

function &_settings_cache(): array
{
    static $cache = [];
    return $cache;
}

function get_setting(string $key, string $default = ''): string
{
    $cache = &_settings_cache();
    if (empty($cache) && function_exists('getPDO')) {
        try {
            $pdo = getPDO();
            $rows = $pdo->query('SELECT key_name, value FROM settings')->fetchAll();
            foreach ($rows as $r) {
                $cache[$r['key_name']] = $r['value'];
            }
        } catch (\Throwable $e) {}
    }
    return $cache[$key] ?? $default;
}

function get_all_settings(): array
{
    $cache = &_settings_cache();
    if (empty($cache) && function_exists('getPDO')) {
        try {
            $pdo = getPDO();
            $rows = $pdo->query('SELECT key_name, value FROM settings')->fetchAll();
            foreach ($rows as $r) {
                $cache[$r['key_name']] = $r['value'];
            }
        } catch (\Throwable $e) {}
    }
    return $cache;
}

function clear_settings_cache(): void
{
    $cache = &_settings_cache();
    $cache = [];
}

function fmt(float $amount): string
{
    $dec = (int) (get_setting('rounding_precision', '2') ?: 2);
    return currency_sym() . ' ' . number_format($amount, $dec);
}

// ── Unified storage layer ─────────────────────────────────────────────────────
// Local disk by default; Supabase Storage when SUPABASE_STORAGE_ENABLED=true and
// credentials are present. Included here so every entry point has it.
require_once __DIR__ . '/includes/storage.php';
