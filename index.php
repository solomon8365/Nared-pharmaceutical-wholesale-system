<?php
ob_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
// Handle exports before any includes or output
$page = $_GET['page'] ?? 'dashboard';

// Handle products JSON API endpoints (batch_data, stock_history, price_history) before any layout
if ($page === 'products' && (isset($_GET['batch_data']) || isset($_GET['stock_history']) || isset($_GET['price_history']))) {
    require_once __DIR__ . '/auth.php';
    requireLogin();
    require_once __DIR__ . '/pages/products.php';
    exit;
}

if (isset($_GET['export']) && in_array($page, ['reports', 'finance_reports', 'my_orders', 'warehouse_reports'], true)) {
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/pages/' . $page . '.php';
    exit;
}

// Handle backup download before any output
if ($page === 'backup' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_backup'], $_POST['download'])) {
    require_once __DIR__ . '/auth.php';
    requireLogin();
    requireAdmin();
    require_csrf();
    require_once __DIR__ . '/pages/backup.php';
    exit;
}

// Handle individual backup file download via GET
if ($page === 'backup' && isset($_GET['download_file'])) {
    require_once __DIR__ . '/auth.php';
    requireLogin();
    requireAdmin();
    $token = $_GET['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        http_response_code(419);
        die('CSRF token validation failed.');
    }
    $file = basename($_GET['download_file']);
    $path = __DIR__ . '/backups/' . $file;
    if (!file_exists($path)) {
        header('Location: index.php?page=backup&error=' . urlencode('Backup file not found.'));
        exit;
    }
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/services/init.php';

// Apply the configured timezone once, early.
try {
    if (function_exists('date_default_timezone_set')) {
        date_default_timezone_set(SettingsService::timezone());
    }
} catch (Throwable $e) { /* keep server default if invalid */ }

// Single maintenance-mode gate: only the Super Admin keeps access so a
// Manager account cannot lock the business out of its own system.
if (SettingsService::maintenanceMode() && !isSuperAdmin()) {
    if ($page !== 'login') {
        header('Location: index.php?page=login');
        exit;
    }
}

$publicPages = ['login', 'register', 'catalog'];
$standalonePages = ['login', 'register', 'catalog', 'receipt', 'invoice', 'lock'];

if ($page === 'logout') {
    logout(isset($_GET['expired']) ? '&expired=1' : '');
}

if (!in_array($page, $publicPages, true)) {
    requireLogin();
}

$allowedPages = [
    'dashboard',
    'store_dashboard',
    'products',
    'categories',
    'suppliers',
    'purchase_orders',
    'goods_receiving',
    'purchase_returns',
    'orders',
    'reports',
    'warehouse_reports',
    'stock',
    'batch_expiry',
    'stock_movements',
    'stock_adjustments',
    'stock_transfers',
    'physical_count',
    'price_history',
    'expiry_alerts',
    'expiry_management',
    'supplier_payments',
    'salespeople',
    'territories',
    'visits',
    'sales_performance',
    'sales_returns',
    'commissions',
    'receipt',
    'customers',
    'sales',
    'customer_order_queue',
    'warehouse_picking',
    'order_fulfillment',
    'place_order',
    'my_orders',
    'delivery_dashboard',
    'delivery_staff',
    'assign_deliveries',
    'delivery_tracking',
    'delivery_confirmation',
    'delivery_assign',
    'delivery_confirm',
    'delivery_history',
    'delivery_returns',
    'invoice',
    'login',
    'register',
    'catalog',
    '404',
    'finance_dashboard',
    'finance_payments',
    'finance_refunds',
    'finance_reports',
    'finance_bank',
    'finance_cash',
    'finance_expenses',
    'finance_accounting',
    'finance_charts',
    'expenses',
    'income',
    'credit_management',
    'out_of_stock_queue',
    'settings',
    'departments',
    'login_history',
    'audit_logs',
    'tax_currency',
    'backup',
    'change_password',
    'customer_settings',
    'users',
    'roles',
    'cms',
    'cms_edit',
    'info',
    'purchase_payment_approval',
    'messages',
    'admin_messages',
    'product_detail',
    'payments',
    'help_center',
    'credit_management',
    'credit_control',
    'credit_reports',
    'credit_portal',
    'ledger',
    'system_sync',
    'delivery_management',
    'customer_statement',
    'warehouse_inventory',
    'lock',
];
$page = in_array($page, $allowedPages, true) ? $page : '404';

// Redirect consolidated pages to salespeople tabs
$salespeopleRedirects = ['territories' => 'territories', 'visits' => 'visits', 'commissions' => 'commissions', 'sales_performance' => 'performance'];
if (isset($salespeopleRedirects[$page])) {
    header('Location: index.php?page=salespeople&tab=' . $salespeopleRedirects[$page]);
    exit;
}

// Consolidated: the DeliveryHub standalone template is retired.
// ?page=delivery_management now maps to the single NARED Delivery Dashboard.
if ($page === 'delivery_management') {
    header('Location: index.php?page=delivery_dashboard');
    exit;
}

$customerPages = [    'dashboard', 'place_order', 'my_orders', 'catalog', 'invoice', 'customer_settings', 'change_password', 'info', 'messages', 'payments', 'help_center', 'credit_portal', 'ledger'];
if (currentUser() && isCustomer() && $page !== 'lock' && !in_array($page, $customerPages, true)) {
    header('Location: index.php?page=dashboard');
    exit;
}
if (currentUser() && isAdmin() && in_array($page, ['place_order', 'my_orders'], true)) {
    header('Location: index.php?page=dashboard');
    exit;
}
if (currentUser() && isDeliveryStaff() && $page !== 'lock' && !in_array($page, ['delivery_dashboard', 'delivery_assign', 'delivery_tracking', 'delivery_confirm', 'delivery_history', 'delivery_returns', 'delivery_staff', 'assign_deliveries', 'delivery_confirmation', 'change_password', 'info'], true)) {
    header('Location: index.php?page=delivery_dashboard');
    exit;
}
if (currentUser() && isFinanceOfficer() && $page !== 'lock' && !in_array($page, ['finance_dashboard', 'finance_payments', 'finance_refunds', 'finance_reports', 'finance_bank', 'finance_cash', 'finance_expenses', 'finance_accounting', 'finance_charts', 'info', 'supplier_payments', 'purchase_payment_approval', 'purchase_orders', 'credit_management', 'credit_reports', 'customer_statement', 'sales', 'receipt', 'invoice', 'ledger'], true)) {
    header('Location: index.php?page=finance_dashboard');
    exit;
}
if (currentUser() && isSalesperson() && $page !== 'lock' && !in_array($page, ['dashboard', 'sales', 'receipt', 'customers', 'products', 'change_password', 'info'], true)) {
    header('Location: index.php?page=sales');
    exit;
}
if (currentUser() && isStoreManager() && $page !== 'lock' && !in_array($page, ['store_dashboard', 'products', 'categories', 'stock', 'stock_movements', 'stock_adjustments', 'stock_transfers', 'physical_count', 'batch_expiry', 'expiry_alerts', 'expiry_management', 'out_of_stock_queue', 'suppliers', 'purchase_orders', 'goods_receiving', 'purchase_returns', 'sales', 'customers', 'customer_order_queue', 'order_fulfillment', 'warehouse_picking', 'invoice', 'warehouse_reports', 'dashboard', 'change_password', 'customer_settings', 'info', 'help_center', 'warehouse_inventory', '404'], true)) {
    header('Location: index.php?page=store_dashboard');
    exit;
}

// Guard: prevent wrong roles from accessing role-specific pages
$financePages = ['finance_dashboard', 'finance_payments', 'finance_refunds', 'finance_reports', 'finance_bank', 'finance_cash', 'finance_expenses', 'finance_accounting', 'finance_charts'];
if (currentUser() && in_array($page, $financePages, true) && !isFinanceOfficer() && !isAdmin()) {
    header('Location: index.php?page=dashboard');
    exit;
}
if (currentUser() && $page === 'delivery_dashboard' && !isDeliveryStaff() && !isAdmin()) {
    header('Location: index.php?page=dashboard');
    exit;
}
if (currentUser() && in_array($page, ['delivery_staff','delivery_assign','delivery_confirm','delivery_history','delivery_returns']) && !isDeliveryStaff() && !isAdmin()) {
    header('Location: index.php?page=dashboard');
    exit;
}

// Dynamic permission guard for custom roles (and all non-admin roles)
if (currentUser() && (!isAdmin() || getUserRoleId() !== 1) && !in_array($page, $publicPages, true) && !in_array($page, $standalonePages, true)) {
    $pageModule = $page;
    if (in_array($pageModule, ['receipt', 'login', 'register', 'catalog', '404', 'dashboard', 'customer_settings', 'change_password', 'info', 'messages', 'place_order', 'my_orders', 'invoice', 'payments', 'help_center', 'credit_portal', 'credit_control', 'credit_reports', 'customer_statement', 'finance_dashboard', 'finance_payments', 'finance_refunds', 'finance_reports', 'finance_bank', 'finance_cash', 'finance_expenses', 'finance_accounting', 'finance_charts', 'delivery_dashboard', 'delivery_assign', 'delivery_tracking', 'delivery_confirm', 'delivery_history', 'delivery_returns', 'delivery_staff', 'assign_deliveries', 'delivery_confirmation', 'departments', 'login_history', 'audit_logs', 'tax_currency', 'warehouse_reports', 'ledger'], true)) {
        // Skip permission check for dashboard (redirect target) and public/standalone pages
    } elseif (!hasPermission($pageModule, 'view')) {
        // If user has a custom role without this page permission, redirect to dashboard
        header('Location: index.php?page=dashboard');
        exit;
    }
}

if (!in_array($page, $standalonePages, true)) {
    require_once __DIR__ . '/header.php';
}

// ── AJAX_MID: transparent JSON responder for AJAX POSTs ──────────────────
$__isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest'
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
// ─────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/pages/' . $page . '.php';

// ── AJAX_MID: capture all output, extract messages, return pure JSON ──────
if ($__isAjax) {
    // Discard everything buffered (header.php at L239 + page output) so the
    // response body is JSON only. index.php:2 opened the top-level buffer.
    $__html = '';
    while (ob_get_level()) { $__html .= ob_get_clean(); }

    // Extract <div class="alert alert-success">…</div>  (46 pages)
    preg_match_all('/<div\s+class="alert alert-(success|error)"[^>]*>(.*?)<\/div>/si', $__html, $__a);
    // Extract <div class="notice notice-success">…</div>  (2 pages)
    preg_match_all('/<div\s+class="notice notice-(success|error)"[^>]*>(.*?)<\/div>/si', $__html, $__n, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
    $__alerts = [];
    foreach ($__a[1] as $__i => $__type) {
        $__alerts[] = ['type' => $__type, 'text' => trim(strip_tags(html_entity_decode($__a[2][$__i])))];
    }
    foreach ($__n as $__m) {
        $__alerts[] = ['type' => $__m[1], 'text' => trim(strip_tags(html_entity_decode($__m[2])))];
    }

    $__hasError = false;
    foreach ($__alerts as $__a) { if ($__a['type'] === 'error') $__hasError = true; }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'  => !$__hasError && !empty($__alerts),
        'messages' => $__alerts,
    ]);
    exit;
}
// ─────────────────────────────────────────────────────────────────────────

if (!in_array($page, $standalonePages, true)) {
    require_once __DIR__ . '/footer.php';
} else {
    while (ob_get_level()) { ob_end_flush(); }
}
