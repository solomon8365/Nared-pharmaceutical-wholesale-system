<?php
requirePermission('settings', 'view');
$pdo = getPDO();
$messages = [];
$activeTab = $_GET['tab'] ?? 'general';

function sv(string $key, string $default = ''): string {
    global $settings;
    return htmlspecialchars($settings[$key] ?? $default);
}
function sc(string $key, string $val): string {
    global $settings;
    return ($settings[$key] ?? '') === $val ? ' selected' : '';
}
function scb(string $key): string {
    global $settings;
    return !empty($settings[$key]) && $settings[$key] !== '0' ? ' checked' : '';
}
function sOn(string $key): string {
    global $settings;
    return !empty($settings[$key]) && $settings[$key] !== '0' ? '1' : '0';
}

$settings = get_all_settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['_action'] ?? '';

    $errors = [];
    $upsert = $pdo->prepare('INSERT INTO settings (key_name, value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE value = :v2');
    function saveKeys($pdo, $upsert, $keys, &$errors) {
        foreach ($keys as $k) {
            $v = trim($_POST[$k] ?? '');
            if ($k === 'vat_rate' && (!is_numeric($v) || (float)$v < 0 || (float)$v > 100)) {
                $errors[] = 'VAT rate must be between 0 and 100.';
                continue;
            }
            if ($k === 'low_stock_threshold' && (!is_numeric($v) || (int)$v < 1)) {
                $errors[] = 'Low stock threshold must be at least 1.';
                continue;
            }
            if ($k === 'password_min_length' && (!is_numeric($v) || (int)$v < 4 || (int)$v > 32)) {
                $errors[] = 'Password minimum length must be 4–32.';
                continue;
            }
            if ($k === 'session_timeout' && (!is_numeric($v) || (int)$v < 5 || (int)$v > 1440)) {
                $errors[] = 'Session timeout must be 5–1440 minutes.';
                continue;
            }
            if ($k === 'login_attempt_limit' && (!is_numeric($v) || (int)$v < 1 || (int)$v > 100)) {
                $errors[] = 'Login attempt limit must be 1–100.';
                continue;
            }
            if ($k === 'default_credit_days' && (!is_numeric($v) || (int)$v < 0)) {
                $errors[] = 'Default credit days must be 0 or more.';
                continue;
            }
            if ($k === 'customer_credit_limit' && (!is_numeric($v) || (float)$v < 0)) {
                $errors[] = 'Credit limit must be 0 or more.';
                continue;
            }
            if ($k === 'company_name' && $v === '') {
                $errors[] = 'Company name is required.';
                continue;
            }
            $upsert->execute(['k' => $k, 'v' => $v, 'v2' => $v]);
        }
    }
    function saveToggle($pdo, $upsert, $key) {
        $v = !empty($_POST[$key]) ? '1' : '0';
        $upsert->execute(['k' => $key, 'v' => $v, 'v2' => $v]);
    }

    function doSave(&$messages, &$errors, $upsert) {
        clear_settings_cache();
        if (!empty($errors)) {
            $messages[] = ['type' => 'error', 'text' => 'Validation errors: ' . implode('; ', $errors)];
        }
    }
    // Sync credit settings to credit_control_settings (legacy table)
    $creditKeys = ['allow_credit_sales','require_credit_approval','max_credit_limit','default_credit_days','block_new_orders_on_overdue'];
    function syncCreditSettings(PDO $pdo) {
        global $creditKeys;
        $legacy = $pdo->prepare('INSERT INTO credit_control_settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = :v2');
        foreach ($creditKeys as $k) {
            $v = get_setting($k, '');
            if ($v !== '') $legacy->execute(['k' => $k, 'v' => $v, 'v2' => $v]);
        }
    }

    if ($action === 'save_general') {
        saveKeys($pdo, $upsert, ['company_name','company_address','company_phone','company_email','website_url','currency','timezone','language'], $errors);
        if (!empty($_FILES['company_logo']['tmp_name'])) {
            $ext = pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION);
            $logoName = 'logo_' . time() . '.' . $ext;
            $logoStored = storage_put($_FILES['company_logo']['tmp_name'], '', $logoName);
            if ($logoStored !== null) {
                $upsert->execute(['k' => 'company_logo', 'v' => $logoStored, 'v2' => $logoStored]);
            }
        }
        doSave($messages, $errors, $upsert);
        if (empty($errors)) $messages[] = ['type' => 'success', 'text' => 'General settings saved.'];
    } elseif ($action === 'save_system') {
        saveKeys($pdo, $upsert, ['vat_rate','default_discount','low_stock_threshold','default_reorder_level','expiry_warning_days','invoice_prefix','receipt_prefix','records_per_page'], $errors);
        saveToggle($pdo, $upsert, 'auto_generate_sku');
        saveToggle($pdo, $upsert, 'auto_generate_barcode');
        saveToggle($pdo, $upsert, 'auto_generate_invoice');
        saveToggle($pdo, $upsert, 'auto_generate_po');
        saveToggle($pdo, $upsert, 'auto_generate_customer_code');
        saveToggle($pdo, $upsert, 'auto_generate_supplier_code');
        doSave($messages, $errors, $upsert);
        if (empty($errors)) $messages[] = ['type' => 'success', 'text' => 'System settings saved.'];
    } elseif ($action === 'save_product') {
        saveToggle($pdo, $upsert, 'enable_batch_tracking');
        saveToggle($pdo, $upsert, 'enable_fefo');
        saveToggle($pdo, $upsert, 'require_product_image');
        saveToggle($pdo, $upsert, 'require_barcode');
        saveToggle($pdo, $upsert, 'require_shelf_location');
        saveToggle($pdo, $upsert, 'require_manufacturer');
        saveToggle($pdo, $upsert, 'require_country_of_origin');
        saveToggle($pdo, $upsert, 'allow_negative_stock');
        doSave($messages, $errors, $upsert);
        if (empty($errors)) $messages[] = ['type' => 'success', 'text' => 'Product settings saved.'];
    } elseif ($action === 'save_customer') {
        saveToggle($pdo, $upsert, 'customer_registration_approval');
        saveToggle($pdo, $upsert, 'allow_credit_sales');
        saveKeys($pdo, $upsert, ['default_credit_days','default_customer_status','customer_credit_limit'], $errors);
        syncCreditSettings($pdo);
        doSave($messages, $errors, $upsert);
        if (empty($errors)) $messages[] = ['type' => 'success', 'text' => 'Customer settings saved.'];
    } elseif ($action === 'save_supplier') {
        saveToggle($pdo, $upsert, 'supplier_approval_required');
        saveToggle($pdo, $upsert, 'require_supplier_license');
        saveKeys($pdo, $upsert, ['default_supplier_currency','default_payment_terms'], $errors);
        doSave($messages, $errors, $upsert);
        if (empty($errors)) $messages[] = ['type' => 'success', 'text' => 'Supplier settings saved.'];
    } elseif ($action === 'save_order') {
        saveToggle($pdo, $upsert, 'order_approval_required');
        saveToggle($pdo, $upsert, 'auto_reserve_stock');
        saveToggle($pdo, $upsert, 'allow_partial_delivery');
        saveToggle($pdo, $upsert, 'allow_order_cancellation');
        saveToggle($pdo, $upsert, 'enable_order_notifications');
        doSave($messages, $errors, $upsert);
        if (empty($errors)) $messages[] = ['type' => 'success', 'text' => 'Order settings saved.'];
    } elseif ($action === 'save_payment') {
        saveKeys($pdo, $upsert, ['invoice_due_days'], $errors);
        saveToggle($pdo, $upsert, 'accept_cash');
        saveToggle($pdo, $upsert, 'accept_bank_transfer');
        saveToggle($pdo, $upsert, 'accept_mobile_money');
        saveToggle($pdo, $upsert, 'accept_credit');
        saveToggle($pdo, $upsert, 'payment_approval_required');
        doSave($messages, $errors, $upsert);
        if (empty($errors)) $messages[] = ['type' => 'success', 'text' => 'Payment settings saved.'];
    } elseif ($action === 'save_delivery') {
        saveToggle($pdo, $upsert, 'delivery_assignment_required');
        saveToggle($pdo, $upsert, 'delivery_confirmation_required');
        saveToggle($pdo, $upsert, 'proof_of_delivery_required');
        saveToggle($pdo, $upsert, 'auto_update_delivery_status');
        doSave($messages, $errors, $upsert);
        if (empty($errors)) $messages[] = ['type' => 'success', 'text' => 'Delivery settings saved.'];
    } elseif ($action === 'save_notification') {
        saveKeys($pdo, $upsert, ['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from_email','smtp_from_name'], $errors);
        saveToggle($pdo, $upsert, 'email_notifications');
        saveToggle($pdo, $upsert, 'low_stock_alerts');
        saveToggle($pdo, $upsert, 'expiry_alerts');
        saveToggle($pdo, $upsert, 'payment_alerts');
        saveToggle($pdo, $upsert, 'order_alerts');
        doSave($messages, $errors, $upsert);
        if (empty($errors)) $messages[] = ['type' => 'success', 'text' => 'Notification settings saved.'];
    } elseif ($action === 'save_security') {
        saveKeys($pdo, $upsert, ['password_min_length','login_attempt_limit','session_timeout'], $errors);
        saveToggle($pdo, $upsert, 'require_uppercase');
        saveToggle($pdo, $upsert, 'require_numbers');
        saveToggle($pdo, $upsert, 'require_special_chars');
        saveToggle($pdo, $upsert, 'two_factor_auth');
        doSave($messages, $errors, $upsert);
        if (empty($errors)) $messages[] = ['type' => 'success', 'text' => 'Security settings saved.'];
    } elseif ($action === 'save_backup') {
        require_once __DIR__ . '/../includes/backup_helper.php';
        $backupEnabled = !empty($_POST['auto_daily_backup']);
        saveToggle($pdo, $upsert, 'auto_daily_backup');
        doSave($messages, $errors, $upsert);
        if (empty($errors)) {
            $taskErr = $backupEnabled ? pkb_task_register() : pkb_task_unregister();
            if ($taskErr === null) {
                $messages[] = ['type' => 'success', 'text' => 'Backup settings saved. Automatic daily backup is now ' . ($backupEnabled ? 'enabled — Windows Task Scheduler runs "' . pkb_task_name() . '" every day at 00:00.' : 'disabled.')];
            } else {
                $messages[] = ['type' => 'success', 'text' => 'Backup settings saved (' . ($backupEnabled ? 'automatic daily backup enabled' : 'automatic daily backup disabled') . '), but the Windows scheduled task could not be ' . ($backupEnabled ? 'created automatically' : 'removed automatically') . ': ' . $taskErr . '. To finish scheduling, run the following as Administrator: ' . pkb_task_manual_command()];
            }
        }
    } elseif ($action === 'save_report') {
        saveToggle($pdo, $upsert, 'show_dashboard_cards');
        saveToggle($pdo, $upsert, 'show_dashboard_charts');
        saveKeys($pdo, $upsert, ['default_report_period','export_format'], $errors);
        doSave($messages, $errors, $upsert);
        if (empty($errors)) $messages[] = ['type' => 'success', 'text' => 'Report settings saved.'];
    } elseif ($action === 'save_maintenance') {
        saveToggle($pdo, $upsert, 'maintenance_mode');
        doSave($messages, $errors, $upsert);
        if (empty($errors)) $messages[] = ['type' => 'success', 'text' => 'Maintenance settings saved.'];
    } elseif ($action === 'do_backup') {
        require_once __DIR__ . '/../includes/backup_helper.php';
        $backupErr = null;
        $backupFile = pkb_create_backup($backupErr);
        if ($backupFile) {
            while (ob_get_level()) { ob_end_clean(); }
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . basename($backupFile) . '"');
            header('Content-Length: ' . filesize($backupFile));
            readfile($backupFile);
            exit;
        }
        $messages[] = ['type' => 'error', 'text' => 'Backup failed: ' . ($backupErr ?? 'Unknown error.')];
    } elseif ($action === 'clear_cache') {
        clear_settings_cache();
        $messages[] = ['type' => 'success', 'text' => 'Application cache cleared.'];
    } elseif ($action === 'optimize_db') {
        $stmt = $pdo->query('SHOW TABLES');
        $tableList = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $stmt->closeCursor();
        $count = 0;
        foreach ($tableList as $t) {
            $r = $pdo->query("OPTIMIZE TABLE `{$t}`");
            $r->fetchAll();
            $r->closeCursor();
            $count++;
        }
        $messages[] = ['type' => 'success', 'text' => "Database optimized. {$count} tables processed."];
    }

    $settings = get_all_settings();

    $activeTab = $_POST['_tab'] ?? $activeTab;
}

$navGroups = [
    'Company' => [
        ['type' => 'tab', 'id' => 'general', 'icon' => 'fa-building', 'label' => 'Company Profile'],
    ],
    'System' => [
        ['type' => 'tab', 'id' => 'system', 'icon' => 'fa-cog', 'label' => 'System Settings'],
        ['type' => 'tab', 'id' => 'product', 'icon' => 'fa-pills', 'label' => 'Products'],
        ['type' => 'tab', 'id' => 'customer', 'icon' => 'fa-users', 'label' => 'Customers'],
        ['type' => 'tab', 'id' => 'supplier', 'icon' => 'fa-truck', 'label' => 'Suppliers'],
        ['type' => 'tab', 'id' => 'security', 'icon' => 'fa-shield-alt', 'label' => 'Security'],
        ['type' => 'tab', 'id' => 'report', 'icon' => 'fa-chart-bar', 'label' => 'Reports'],
        ['type' => 'tab', 'id' => 'maintenance', 'icon' => 'fa-wrench', 'label' => 'Maintenance'],
    ],
    'User Management' => [
        ['type' => 'page', 'page' => 'users', 'icon' => 'fa-user-tie', 'label' => 'Users'],
        ['type' => 'page', 'page' => 'roles', 'icon' => 'fa-user-shield', 'label' => 'Roles'],
        ['type' => 'page', 'page' => 'roles', 'icon' => 'fa-key', 'label' => 'Permissions'],
        ['type' => 'page', 'page' => 'departments', 'icon' => 'fa-sitemap', 'label' => 'Departments'],
        ['type' => 'page', 'page' => 'login_history', 'icon' => 'fa-history', 'label' => 'Login History'],
    ],
    'Workflow Settings' => [
        ['type' => 'tab', 'id' => 'order', 'icon' => 'fa-shopping-cart', 'label' => 'Orders'],
        ['type' => 'tab', 'id' => 'payment', 'icon' => 'fa-credit-card', 'label' => 'Payments'],
        ['type' => 'tab', 'id' => 'delivery', 'icon' => 'fa-truck-loading', 'label' => 'Delivery'],
    ],
    'Notifications' => [
        ['type' => 'tab', 'id' => 'notification', 'icon' => 'fa-envelope', 'label' => 'Notifications'],
    ],
    'Tax & Currency' => [
        ['type' => 'page', 'page' => 'tax_currency', 'icon' => 'fa-percent', 'label' => 'Tax & Currency'],
    ],
    'Backup & Restore' => [
        ['type' => 'tab', 'id' => 'backup', 'icon' => 'fa-database', 'label' => 'Backup & Restore'],
    ],
    'Audit Logs' => [
        ['type' => 'page', 'page' => 'audit_logs', 'icon' => 'fa-scroll', 'label' => 'Audit Logs'],
    ],
];
?>
<style>
/* Shared admin-console (as-*) styles now live in assets/css/style.css so all
   admin pages (Audit Logs, Login History, Roles, Departments, Tax & Currency)
   render consistently. */
</style>

<section class="as-wrap">
    <aside class="as-sidebar">
        <div class="as-sidebar-header">
            <h2><i class="fas fa-cog" style="color:#3b82f6;margin-right:4px"></i> Settings</h2>
            <p>System Configuration</p>
        </div>
        <nav class="as-nav">
            <?php foreach ($navGroups as $groupTitle => $groupItems): ?>
                <div class="as-nav-group"><?= htmlspecialchars($groupTitle) ?></div>
                <?php foreach ($groupItems as $it): ?>
                    <?php
                    if ($it['type'] === 'tab') {
                        $href = '?page=settings&tab=' . $it['id'];
                        $active = $activeTab === $it['id'] ? ' active' : '';
                    } else {
                        $href = '?page=' . $it['page'];
                        $active = ($page ?? '') === $it['page'] ? ' active' : '';
                    }
                    ?>
                    <a href="<?= $href ?>" class="as-nav-item<?= $active ?>"><i class="fas <?= $it['icon'] ?>"></i> <?= $it['label'] ?></a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>
    </aside>

    <main class="as-content">
        <?php foreach ($messages as $m): ?>
            <div class="alert alert-<?= htmlspecialchars($m['type']) ?>" style="margin-bottom:16px"><?= htmlspecialchars($m['text']) ?></div>
        <?php endforeach; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error" style="margin-bottom:16px;padding:12px 16px;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-radius:8px">
                <strong>Validation errors:</strong><br>
                <?= htmlspecialchars(implode('<br>', $errors)) ?>
            </div>
        <?php endif; ?>

        <?php if ($activeTab === 'general'): ?>
        <div class="as-page-title"><i class="fas fa-building" style="color:#3b82f6"></i> General Settings</div>
        <div class="as-page-sub">Company information, branding, and localization preferences</div>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_general">
            <input type="hidden" name="_tab" value="general">
            <div class="as-card">
                <div class="as-card-title"><i class="fas fa-building"></i> Company Information</div>
                <div class="as-grid as-g2">
                    <div class="as-field"><label>Company Name</label><input type="text" name="company_name" value="<?= sv('company_name', 'NARED PHARMACON') ?>" required></div>
                    <div class="as-field"><label>Company Logo</label><input type="file" name="company_logo" accept="image/*" style="padding:6px"><div style="margin-top:8px;display:flex;align-items:center;gap:10px"><img src="<?= htmlspecialchars(brand_logo()) ?>" alt="Current logo" style="width:48px;height:48px;object-fit:contain;border-radius:10px;background:#fff;border:1px solid #e2e8f0;padding:3px"><div class="as-hint">Used across the sidebar, login, invoices, receipts &amp; reports.<?php if (!empty($settings['company_logo'])): ?> <a href="<?= htmlspecialchars($settings['company_logo']) ?>" target="_blank" style="color:#3b82f6">View full size</a><?php endif; ?></div></div></div>
                    <div class="as-field" style="grid-column:span 2"><label>Company Address</label><textarea name="company_address" rows="2"><?= sv('company_address') ?></textarea></div>
                    <div class="as-field"><label>Email</label><input type="email" name="company_email" value="<?= sv('company_email') ?>"></div>
                    <div class="as-field"><label>Phone Number</label><input type="text" name="company_phone" value="<?= sv('company_phone') ?>"></div>
                    <div class="as-field"><label>Website</label><input type="url" name="website_url" value="<?= sv('website_url') ?>" placeholder="https://example.com"></div>
                    <div class="as-field"><label>Currency</label><select name="currency"><option value="ETB"<?= sc('currency','ETB') ?>>ETB - Ethiopian Birr</option><option value="USD"<?= sc('currency','USD') ?>>USD - US Dollar</option><option value="EUR"<?= sc('currency','EUR') ?>>EUR - Euro</option><option value="GBP"<?= sc('currency','GBP') ?>>GBP - British Pound</option><option value="KES"<?= sc('currency','KES') ?>>KES - Kenyan Shilling</option><option value="NGN"<?= sc('currency','NGN') ?>>NGN - Nigerian Naira</option></select></div>
                    <div class="as-field"><label>Time Zone</label><select name="timezone"><option value="Africa/Addis_Ababa"<?= sc('timezone','Africa/Addis_Ababa') ?>>Africa/Addis Ababa</option><option value="Africa/Nairobi"<?= sc('timezone','Africa/Nairobi') ?>>Africa/Nairobi</option><option value="Africa/Cairo"<?= sc('timezone','Africa/Cairo') ?>>Africa/Cairo</option><option value="Africa/Lagos"<?= sc('timezone','Africa/Lagos') ?>>Africa/Lagos</option><option value="Africa/Johannesburg"<?= sc('timezone','Africa/Johannesburg') ?>>Africa/Johannesburg</option><option value="Asia/Dubai"<?= sc('timezone','Asia/Dubai') ?>>Asia/Dubai</option><option value="Europe/London"<?= sc('timezone','Europe/London') ?>>Europe/London</option><option value="America/New_York"<?= sc('timezone','America/New_York') ?>>America/New York</option><option value="UTC"<?= sc('timezone','UTC') ?>>UTC</option></select></div>
                    <div class="as-field"><label>Language</label><select name="language"><option value="en"<?= sc('language','en') ?>>English</option><option value="am"<?= sc('language','am') ?>>Amharic</option><option value="sw"<?= sc('language','sw') ?>>Swahili</option><option value="ar"<?= sc('language','ar') ?>>Arabic</option><option value="fr"<?= sc('language','fr') ?>>French</option></select></div>
                </div>
            </div>
            <div class="as-save-bar"><button type="submit" class="as-btn as-btn-primary"><i class="fas fa-save"></i> Save General Settings</button></div>
        </form>

        <?php elseif ($activeTab === 'system'): ?>
        <div class="as-page-title"><i class="fas fa-cog" style="color:#6366f1"></i> System Settings</div>
        <div class="as-page-sub">Tax rates, auto-generation, thresholds, and system defaults</div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_system">
            <input type="hidden" name="_tab" value="system">
            <div class="as-card">
                <div class="as-card-title"><i class="fas fa-percent"></i> Tax &amp; Pricing</div>
                <div class="as-grid as-g4">
                    <div class="as-field"><label>VAT Percentage (%)</label><input type="number" step="0.01" min="0" name="vat_rate" value="<?= sv('vat_rate', '15') ?>"><div class="as-hint">Applied on taxable products</div></div>
                    <div class="as-field"><label>Default Discount (%)</label><input type="number" step="0.01" min="0" name="default_discount" value="<?= sv('default_discount', '0') ?>"><div class="as-hint">Applied on new orders</div></div>
                    <div class="as-field"><label>Low Stock Threshold</label><input type="number" min="1" name="low_stock_threshold" value="<?= sv('low_stock_threshold', '10') ?>"><div class="as-hint">Alert when stock falls below</div></div>
                    <div class="as-field"><label>Default Reorder Level</label><input type="number" min="0" name="default_reorder_level" value="<?= sv('default_reorder_level', '10') ?>"><div class="as-hint">For new products</div></div>
                </div>
            </div>
            <div class="as-card">
                <div class="as-card-title"><i class="fas fa-clock"></i> Expiry Alerts</div>
                <div class="as-grid as-g3">
                    <div class="as-field"><label>Expiry Warning Days</label><select name="expiry_warning_days"><option value="30"<?= sc('expiry_warning_days','30') ?>>30 days</option><option value="60"<?= sc('expiry_warning_days','60') ?>>60 days</option><option value="90"<?= sc('expiry_warning_days','90') ?>>90 days</option><option value="120"<?= sc('expiry_warning_days','120') ?>>120 days</option><option value="180"<?= sc('expiry_warning_days','180') ?>>180 days</option></select><div class="as-hint">Flag batches expiring within</div></div>
                    <div class="as-field"><label>Records Per Page</label><input type="number" min="10" max="500" name="records_per_page" value="<?= sv('records_per_page', '50') ?>"></div>
                    <div class="as-field"><label>Rounding Precision</label><select name="rounding_precision"><option value="0"<?= sc('rounding_precision','0') ?>>0 (whole numbers)</option><option value="2"<?= sc('rounding_precision','2') ?>>2 (standard)</option><option value="4"<?= sc('rounding_precision','4') ?>>4 (high precision)</option></select></div>
                </div>
            </div>
            <div class="as-card">
                <div class="as-card-title"><i class="fas fa-robot"></i> Auto-Generation</div>
                <div class="as-grid as-g2">
                    <label class="as-toggle"><input type="checkbox" name="auto_generate_sku" value="1"<?= scb('auto_generate_sku') ?>><div class="as-toggle-text"><div class="as-toggle-label">Auto Generate SKU</div><div class="as-toggle-desc">Automatically assign SKU codes to new products</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="auto_generate_barcode" value="1"<?= scb('auto_generate_barcode') ?>><div class="as-toggle-text"><div class="as-toggle-label">Auto Generate Barcode</div><div class="as-toggle-desc">Create barcode when adding new products</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="auto_generate_invoice" value="1"<?= scb('auto_generate_invoice') ?>><div class="as-toggle-text"><div class="as-toggle-label">Auto Generate Invoice Number</div><div class="as-toggle-desc">Sequential invoice numbering</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="auto_generate_po" value="1"<?= scb('auto_generate_po') ?>><div class="as-toggle-text"><div class="as-toggle-label">Auto Generate PO Number</div><div class="as-toggle-desc">Sequential purchase order numbering</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="auto_generate_customer_code" value="1"<?= scb('auto_generate_customer_code') ?>><div class="as-toggle-text"><div class="as-toggle-label">Auto Generate Customer Code</div><div class="as-toggle-desc">Assign codes to new customers</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="auto_generate_supplier_code" value="1"<?= scb('auto_generate_supplier_code') ?>><div class="as-toggle-text"><div class="as-toggle-label">Auto Generate Supplier Code</div><div class="as-toggle-desc">Assign codes to new suppliers</div></div></label>
                </div>
            </div>
            <div class="as-save-bar"><button type="submit" class="as-btn as-btn-primary"><i class="fas fa-save"></i> Save System Settings</button></div>
        </form>

        <?php elseif ($activeTab === 'product'): ?>
        <div class="as-page-title"><i class="fas fa-pills" style="color:#059669"></i> Product Settings</div>
        <div class="as-page-sub">Control product fields, validation, and inventory rules</div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_product">
            <input type="hidden" name="_tab" value="product">
            <div class="as-card">
                <div class="as-card-title"><i class="fas fa-box"></i> Batch &amp; Inventory</div>
                <div class="as-grid as-g2">
                    <label class="as-toggle"><input type="checkbox" name="enable_batch_tracking" value="1"<?= scb('enable_batch_tracking') ?>><div class="as-toggle-text"><div class="as-toggle-label">Enable Batch Tracking</div><div class="as-toggle-desc">Track products by batch numbers and expiry dates</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="enable_fefo" value="1"<?= scb('enable_fefo') ?>><div class="as-toggle-text"><div class="as-toggle-label">Enable FEFO (First Expiry First Out)</div><div class="as-toggle-desc">Prioritize selling batches with nearest expiry</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="allow_negative_stock" value="1"<?= scb('allow_negative_stock') ?>><div class="as-toggle-text"><div class="as-toggle-label">Allow Negative Stock</div><div class="as-toggle-desc">Allow sales even when stock is insufficient</div></div></label>
                </div>
            </div>
            <div class="as-card">
                <div class="as-card-title"><i class="fas fa-check-double"></i> Required Fields</div>
                <div class="as-grid as-g2">
                    <label class="as-toggle"><input type="checkbox" name="require_product_image" value="1"<?= scb('require_product_image') ?>><div class="as-toggle-text"><div class="as-toggle-label">Require Product Image</div><div class="as-toggle-desc">Image is mandatory when adding products</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="require_barcode" value="1"<?= scb('require_barcode') ?>><div class="as-toggle-text"><div class="as-toggle-label">Require Barcode</div><div class="as-toggle-desc">Barcode is mandatory when adding products</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="require_shelf_location" value="1"<?= scb('require_shelf_location') ?>><div class="as-toggle-text"><div class="as-toggle-label">Require Shelf Location</div><div class="as-toggle-desc">Warehouse shelf location is mandatory</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="require_manufacturer" value="1"<?= scb('require_manufacturer') ?>><div class="as-toggle-text"><div class="as-toggle-label">Require Manufacturer</div><div class="as-toggle-desc">Manufacturer name is mandatory</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="require_country_of_origin" value="1"<?= scb('require_country_of_origin') ?>><div class="as-toggle-text"><div class="as-toggle-label">Require Country of Origin</div><div class="as-toggle-desc">Country is mandatory when adding products</div></div></label>
                </div>
            </div>
            <div class="as-save-bar"><button type="submit" class="as-btn as-btn-primary"><i class="fas fa-save"></i> Save Product Settings</button></div>
        </form>

        <?php elseif ($activeTab === 'customer'): ?>
        <div class="as-page-title"><i class="fas fa-users" style="color:#8b5cf6"></i> Customer Settings</div>
        <div class="as-page-sub">Customer registration, credit, and approval rules</div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_customer">
            <input type="hidden" name="_tab" value="customer">
            <div class="as-card">
                <div class="as-card-title"><i class="fas fa-user-plus"></i> Registration &amp; Approval</div>
                <div class="as-grid as-g2">
                    <label class="as-toggle"><input type="checkbox" name="customer_registration_approval" value="1"<?= scb('customer_registration_approval') ?>><div class="as-toggle-text"><div class="as-toggle-label">Customer Registration Approval</div><div class="as-toggle-desc">New customers require admin approval before ordering</div></div></label>
                    <div class="as-field"><label>Default Customer Status</label><select name="default_customer_status"><option value="active"<?= sc('default_customer_status','active') ?>>Active</option><option value="inactive"<?= sc('default_customer_status','inactive') ?>>Inactive</option><option value="pending"<?= sc('default_customer_status','pending') ?>>Pending Approval</option></select></div>
                </div>
            </div>
            <div class="as-card">
                <div class="as-card-title"><i class="fas fa-hand-holding-usd"></i> Credit Settings</div>
                <div class="as-grid as-g3">
                    <label class="as-toggle"><input type="checkbox" name="allow_credit_sales" value="1"<?= scb('allow_credit_sales') ?>><div class="as-toggle-text"><div class="as-toggle-label">Allow Credit Sales</div><div class="as-toggle-desc">Enable credit-based purchases for customers</div></div></label>
                    <div class="as-field"><label>Default Credit Days</label><input type="number" min="0" name="default_credit_days" value="<?= sv('default_credit_days', '30') ?>"><div class="as-hint">Payment due within X days</div></div>
                    <div class="as-field"><label>Default Credit Limit (<?= currency_sym() ?>)</label><input type="number" step="0.01" min="0" name="customer_credit_limit" value="<?= sv('customer_credit_limit', '0') ?>"><div class="as-hint">0 = no limit</div></div>
                </div>
            </div>
            <div class="as-info-box as-info-blue"><i class="fas fa-info-circle"></i> After enabling credit sales, set individual credit limits in <a href="?page=customers" style="color:#2563eb;font-weight:600">Customers</a> and approve customers in <a href="?page=credit_management" style="color:#2563eb;font-weight:600">Credit Management</a>.</div>
            <div class="as-save-bar"><button type="submit" class="as-btn as-btn-primary"><i class="fas fa-save"></i> Save Customer Settings</button></div>
        </form>

        <?php elseif ($activeTab === 'supplier'): ?>
        <div class="as-page-title"><i class="fas fa-truck" style="color:#ea580c"></i> Supplier Settings</div>
        <div class="as-page-sub">Supplier onboarding, approval, and defaults</div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_supplier">
            <input type="hidden" name="_tab" value="supplier">
            <div class="as-card">
                <div class="as-card-title"><i class="fas fa-clipboard-check"></i> Approval &amp; Compliance</div>
                <div class="as-grid as-g2">
                    <label class="as-toggle"><input type="checkbox" name="supplier_approval_required" value="1"<?= scb('supplier_approval_required') ?>><div class="as-toggle-text"><div class="as-toggle-label">Supplier Approval Required</div><div class="as-toggle-desc">New suppliers need admin approval before use</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="require_supplier_license" value="1"<?= scb('require_supplier_license') ?>><div class="as-toggle-text"><div class="as-toggle-label">Require Supplier License</div><div class="as-toggle-desc">License number is mandatory for new suppliers</div></div></label>
                </div>
            </div>
            <div class="as-card">
                <div class="as-card-title"><i class="fas fa-file-invoice"></i> Defaults</div>
                <div class="as-grid as-g3">
                    <div class="as-field"><label>Default Supplier Currency</label><select name="default_supplier_currency"><option value="ETB"<?= sc('default_supplier_currency','ETB') ?>>ETB</option><option value="USD"<?= sc('default_supplier_currency','USD') ?>>USD</option><option value="EUR"<?= sc('default_supplier_currency','EUR') ?>>EUR</option><option value="CNY"<?= sc('default_supplier_currency','CNY') ?>>CNY</option><option value="INR"<?= sc('default_supplier_currency','INR') ?>>INR</option></select></div>
                    <div class="as-field"><label>Default Payment Terms</label><select name="default_payment_terms"><option value="COD"<?= sc('default_payment_terms','COD') ?>>Cash on Delivery</option><option value="Net 15"<?= sc('default_payment_terms','Net 15') ?>>Net 15</option><option value="Net 30"<?= sc('default_payment_terms','Net 30') ?>>Net 30</option><option value="Net 60"<?= sc('default_payment_terms','Net 60') ?>>Net 60</option><option value="Prepaid"<?= sc('default_payment_terms','Prepaid') ?>>Prepaid</option></select></div>
                </div>
            </div>
            <div class="as-save-bar"><button type="submit" class="as-btn as-btn-primary"><i class="fas fa-save"></i> Save Supplier Settings</button></div>
        </form>

        <?php elseif ($activeTab === 'order'): ?>
        <div class="as-page-title"><i class="fas fa-shopping-cart" style="color:#0ea5e9"></i> Order Settings</div>
        <div class="as-page-sub">Order workflow, approval, stock reservation, and notifications</div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_order">
            <input type="hidden" name="_tab" value="order">
            <div class="as-card">
                <div class="as-card-title"><i class="fas fa-clipboard-list"></i> Order Workflow</div>
                <div class="as-grid as-g2">
                    <label class="as-toggle"><input type="checkbox" name="order_approval_required" value="1"<?= scb('order_approval_required') ?>><div class="as-toggle-text"><div class="as-toggle-label">Order Approval Required</div><div class="as-toggle-desc">Orders need admin approval before processing</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="auto_reserve_stock" value="1"<?= scb('auto_reserve_stock') ?>><div class="as-toggle-text"><div class="as-toggle-label">Auto Reserve Stock</div><div class="as-toggle-desc">Reserve stock when orders are placed</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="allow_partial_delivery" value="1"<?= scb('allow_partial_delivery') ?>><div class="as-toggle-text"><div class="as-toggle-label">Allow Partial Delivery</div><div class="as-toggle-desc">Deliver available items when some are out of stock</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="allow_order_cancellation" value="1"<?= scb('allow_order_cancellation') ?>><div class="as-toggle-text"><div class="as-toggle-label">Allow Order Cancellation</div><div class="as-toggle-desc">Customers can cancel pending orders</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="enable_order_notifications" value="1"<?= scb('enable_order_notifications') ?>><div class="as-toggle-text"><div class="as-toggle-label">Enable Order Notifications</div><div class="as-toggle-desc">Send notifications on order status changes</div></div></label>
                </div>
            </div>
            <div class="as-save-bar"><button type="submit" class="as-btn as-btn-primary"><i class="fas fa-save"></i> Save Order Settings</button></div>
        </form>

        <?php elseif ($activeTab === 'payment'): ?>
        <div class="as-page-title"><i class="fas fa-credit-card" style="color:#059669"></i> Payment Settings</div>
        <div class="as-page-sub">Accepted payment methods, approval workflows, and invoicing</div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_payment">
            <input type="hidden" name="_tab" value="payment">
            <div class="as-card">
                <div class="as-card-title"><i class="fas fa-money-check-alt"></i> Accepted Payment Methods</div>
                <div class="as-grid as-g2">
                    <label class="as-toggle"><input type="checkbox" name="accept_cash" value="1"<?= scb('accept_cash') ?>><div class="as-toggle-text"><div class="as-toggle-label">Cash</div><div class="as-toggle-desc">Accept cash payments at counter</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="accept_bank_transfer" value="1"<?= scb('accept_bank_transfer') ?>><div class="as-toggle-text"><div class="as-toggle-label">Bank Transfer</div><div class="as-toggle-desc">Accept direct bank transfers</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="accept_mobile_money" value="1"<?= scb('accept_mobile_money') ?>><div class="as-toggle-text"><div class="as-toggle-label">Mobile Money (Telebirr, CBE Birr)</div><div class="as-toggle-desc">Accept mobile payment platforms</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="accept_credit" value="1"<?= scb('accept_credit') ?>><div class="as-toggle-text"><div class="as-toggle-label">Credit</div><div class="as-toggle-desc">Allow credit-based sales to customers</div></div></label>
                </div>
            </div>
            <div class="as-card">
                <div class="as-card-title"><i class="fas fa-file-invoice-dollar"></i> Invoice &amp; Approval</div>
                <div class="as-grid as-g3">
                    <label class="as-toggle"><input type="checkbox" name="payment_approval_required" value="1"<?= scb('payment_approval_required') ?>><div class="as-toggle-text"><div class="as-toggle-label">Payment Approval Required</div><div class="as-toggle-desc">Large payments need admin approval</div></div></label>
                    <div class="as-field"><label>Invoice Due Days</label><input type="number" min="0" name="invoice_due_days" value="<?= sv('invoice_due_days', '30') ?>"><div class="as-hint">Payment deadline from invoice date</div></div>
                </div>
            </div>
            <div class="as-save-bar"><button type="submit" class="as-btn as-btn-primary"><i class="fas fa-save"></i> Save Payment Settings</button></div>
        </form>

        <?php elseif ($activeTab === 'delivery'): ?>
        <div class="as-page-title"><i class="fas fa-truck-loading" style="color:#7c3aed"></i> Delivery Settings</div>
        <div class="as-page-sub">Delivery workflow, assignment, and confirmation rules</div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_delivery">
            <input type="hidden" name="_tab" value="delivery">
            <div class="as-card">
                <div class="as-card-title"><i class="fas fa-shipping-fast"></i> Delivery Workflow</div>
                <div class="as-grid as-g2">
                    <label class="as-toggle"><input type="checkbox" name="delivery_assignment_required" value="1"<?= scb('delivery_assignment_required') ?>><div class="as-toggle-text"><div class="as-toggle-label">Delivery Assignment Required</div><div class="as-toggle-desc">Orders must be assigned to a delivery staff before dispatch</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="delivery_confirmation_required" value="1"<?= scb('delivery_confirmation_required') ?>><div class="as-toggle-text"><div class="as-toggle-label">Delivery Confirmation Required</div><div class="as-toggle-desc">Delivery staff must confirm delivery completion</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="proof_of_delivery_required" value="1"<?= scb('proof_of_delivery_required') ?>><div class="as-toggle-text"><div class="as-toggle-label">Proof of Delivery Required</div><div class="as-toggle-desc">Require signature or photo as delivery proof</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="auto_update_delivery_status" value="1"<?= scb('auto_update_delivery_status') ?>><div class="as-toggle-text"><div class="as-toggle-label">Auto Update Delivery Status</div><div class="as-toggle-desc">Automatically update order status on delivery</div></div></label>
                </div>
            </div>
            <div class="as-save-bar"><button type="submit" class="as-btn as-btn-primary"><i class="fas fa-save"></i> Save Delivery Settings</button></div>
        </form>

        <?php elseif ($activeTab === 'notification'): ?>
        <div class="as-page-title"><i class="fas fa-envelope" style="color:#dc2626"></i> Email &amp; Notification Settings</div>
        <div class="as-page-sub">SMTP configuration and alert preferences</div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_notification">
            <input type="hidden" name="_tab" value="notification">
            <div class="as-card">
                <div class="as-card-title"><i class="fas fa-server"></i> SMTP Settings</div>
                <div class="as-grid as-g3">
                    <div class="as-field"><label>SMTP Host</label><input type="text" name="smtp_host" value="<?= sv('smtp_host') ?>" placeholder="smtp.gmail.com"></div>
                    <div class="as-field"><label>SMTP Port</label><input type="number" name="smtp_port" value="<?= sv('smtp_port', '587') ?>" placeholder="587"></div>
                    <div class="as-field"><label>SMTP Username</label><input type="text" name="smtp_user" value="<?= sv('smtp_user') ?>" placeholder="your@email.com"></div>
                    <div class="as-field"><label>SMTP Password</label><input type="password" name="smtp_pass" value="<?= sv('smtp_pass') ?>" placeholder="App password"></div>
                    <div class="as-field"><label>From Email</label><input type="email" name="smtp_from_email" value="<?= sv('smtp_from_email') ?>" placeholder="noreply@example.com"></div>
                    <div class="as-field"><label>From Name</label><input type="text" name="smtp_from_name" value="<?= sv('smtp_from_name', sv('company_name','NARED PHARMACON')) ?>" placeholder="NARED PHARMACON"></div>
                </div>
            </div>
            <div class="as-card">
                <div class="as-card-title"><i class="fas fa-bell"></i> Alert Preferences</div>
                <div class="as-grid as-g2">
                    <label class="as-toggle"><input type="checkbox" name="email_notifications" value="1"<?= scb('email_notifications') ?>><div class="as-toggle-text"><div class="as-toggle-label">Email Notifications</div><div class="as-toggle-desc">Enable system email notifications</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="low_stock_alerts" value="1"<?= scb('low_stock_alerts') ?>><div class="as-toggle-text"><div class="as-toggle-label">Low Stock Alerts</div><div class="as-toggle-desc">Notify when products fall below threshold</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="expiry_alerts" value="1"<?= scb('expiry_alerts') ?>><div class="as-toggle-text"><div class="as-toggle-label">Expiry Alerts</div><div class="as-toggle-desc">Notify about expiring batches</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="payment_alerts" value="1"<?= scb('payment_alerts') ?>><div class="as-toggle-text"><div class="as-toggle-label">Payment Alerts</div><div class="as-toggle-desc">Notify on payments received or due</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="order_alerts" value="1"<?= scb('order_alerts') ?>><div class="as-toggle-text"><div class="as-toggle-label">Order Alerts</div><div class="as-toggle-desc">Notify on new orders and status changes</div></div></label>
                </div>
            </div>
            <div class="as-save-bar"><button type="submit" class="as-btn as-btn-primary"><i class="fas fa-save"></i> Save Notification Settings</button></div>
        </form>

        <?php elseif ($activeTab === 'security'): ?>
        <div class="as-page-title"><i class="fas fa-shield-alt" style="color:#dc2626"></i> Security Settings</div>
        <div class="as-page-sub">Password policies, session management, and access controls</div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_security">
            <input type="hidden" name="_tab" value="security">
            <div class="as-card">
                <div class="as-card-title"><i class="fas fa-key"></i> Password Policy</div>
                <div class="as-grid as-g3">
                    <div class="as-field"><label>Minimum Password Length</label><input type="number" min="4" max="32" name="password_min_length" value="<?= sv('password_min_length', '6') ?>"><div class="as-hint">Minimum characters required</div></div>
                    <label class="as-toggle"><input type="checkbox" name="require_uppercase" value="1"<?= scb('require_uppercase') ?>><div class="as-toggle-text"><div class="as-toggle-label">Require Uppercase Letters</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="require_numbers" value="1"<?= scb('require_numbers') ?>><div class="as-toggle-text"><div class="as-toggle-label">Require Numbers</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="require_special_chars" value="1"<?= scb('require_special_chars') ?>><div class="as-toggle-text"><div class="as-toggle-label">Require Special Characters</div></div></label>
                </div>
            </div>
            <div class="as-card">
                <div class="as-card-title"><i class="fas fa-lock"></i> Session &amp; Access</div>
                <div class="as-grid as-g3">
                    <div class="as-field"><label>Session Timeout (minutes)</label><input type="number" min="5" max="1440" name="session_timeout" value="<?= sv('session_timeout', '120') ?>"><div class="as-hint">Auto-logout after idle</div></div>
                    <div class="as-field"><label>Login Attempt Limit</label><input type="number" min="1" max="100" name="login_attempt_limit" value="<?= sv('login_attempt_limit', '5') ?>"><div class="as-hint">Lock after X failed attempts</div></div>
                    <label class="as-toggle"><input type="checkbox" name="two_factor_auth" value="1"<?= scb('two_factor_auth') ?>><div class="as-toggle-text"><div class="as-toggle-label">Two-Factor Authentication</div><div class="as-toggle-desc">Require 2FA for admin logins</div></div></label>
                </div>
            </div>
            <div class="as-card">
                <div class="as-card-title"><i class="fas fa-user-shield"></i> Role-Based Permissions</div>
                <div class="as-info-box as-info-blue"><i class="fas fa-info-circle"></i> Manage detailed permissions in the <a href="?page=roles" style="color:#2563eb;font-weight:600;margin-left:4px">Roles &amp; Permissions</a> page.</div>
            </div>
            <div class="as-save-bar"><button type="submit" class="as-btn as-btn-primary"><i class="fas fa-save"></i> Save Security Settings</button></div>
        </form>

        <?php elseif ($activeTab === 'backup'): ?>
        <div class="as-page-title"><i class="fas fa-database" style="color:#0891b2"></i> Backup &amp; Restore</div>
        <div class="as-page-sub">Database backup, restore, and scheduling</div>
        <div class="as-card">
            <div class="as-card-title"><i class="fas fa-download"></i> Manual Backup</div>
            <div class="as-info-box as-info-blue"><i class="fas fa-info-circle"></i> Create a full database backup instantly. The backup file is downloaded to your computer and a copy is kept in the <code>backups/</code> directory.</div>
            <form method="post" style="display:inline" data-ajax-off="true">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="do_backup">
                <input type="hidden" name="_tab" value="backup">
                <button type="submit" class="as-btn as-btn-success"><i class="fas fa-download"></i> Create Backup Now</button>
            </form>
        </div>
        <div class="as-card">
            <div class="as-card-title"><i class="fas fa-calendar-check"></i> Automatic Backup</div>
            <form method="post" data-ajax-off="true">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="save_backup">
                <input type="hidden" name="_tab" value="backup">
                <div class="as-grid as-g2">
                    <label class="as-toggle"><input type="checkbox" name="auto_daily_backup" value="1"<?= scb('auto_daily_backup') ?>><div class="as-toggle-text"><div class="as-toggle-label">Automatic Daily Backup</div><div class="as-toggle-desc">Run daily at 00:00 via the Windows Task Scheduler task "<?= htmlspecialchars(function_exists('pkb_task_name') ? pkb_task_name() : 'NARED_Pharma_DailyBackup') ?>"</div></div></label>
                </div>
                <div class="as-save-bar"><button type="submit" class="as-btn as-btn-primary"><i class="fas fa-save"></i> Save Backup Settings</button></div>
            </form>
            <?php
            require_once __DIR__ . '/../includes/backup_helper.php';
            $pkbEnabled      = !empty($settings['auto_daily_backup']) && $settings['auto_daily_backup'] !== '0';
            $pkbRegistered   = pkb_task_registered();
            $pkbTaskName     = pkb_task_name();
            ?>
            <div class="as-info-box <?= $pkbEnabled && $pkbRegistered ? 'as-info-green' : ($pkbEnabled ? 'as-info-amber' : 'as-info-blue') ?>">
                <i class="fas <?= $pkbEnabled && $pkbRegistered ? 'fa-check-circle' : ($pkbEnabled ? 'fa-exclamation-triangle' : 'fa-info-circle') ?>"></i>
                <?php if ($pkbEnabled && $pkbRegistered): ?>
                    Automatic daily backup is ON. Windows Task Scheduler task <code><?= htmlspecialchars($pkbTaskName) ?></code> is registered and runs <?= htmlspecialchars((new DateTimeImmutable('00:00'))->format('H:i')) ?> every day.
                <?php elseif ($pkbEnabled): ?>
                    Automatic daily backup is ON, but the Windows scheduled task <code><?= htmlspecialchars($pkbTaskName) ?></code> is not registered (creating it requires an Administrator command prompt). Run this once as Administrator:
                    <code style="display:block;margin-top:6px;padding:6px 8px;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;font-size:0.75rem"><?= htmlspecialchars(pkb_task_manual_command()) ?></code>
                <?php else: ?>
                    Automatic daily backup is OFF. Enable the toggle above and save to schedule a backup every day at 00:00.
                <?php endif; ?>
            </div>
        </div>
        <?php
        $backupDir = __DIR__ . '/../backups';
        $backups = [];
        if (is_dir($backupDir)) {
            $files = glob($backupDir . '/backup_*.sql');
            foreach ($files as $f) $backups[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
            usort($backups, function($a, $b) { return $b['time'] - $a['time']; });
        }
        ?>
        <div class="as-card">
            <div class="as-card-title"><i class="fas fa-history"></i> Recent Backups (<?= count($backups) ?>)</div>
            <?php if (empty($backups)): ?>
                <div class="as-empty"><i class="fas fa-database"></i> No backups found. Create your first backup above.</div>
            <?php else: ?>
            <table style="width:100%;border-collapse:collapse;font-size:0.82rem">
                <thead><tr style="border-bottom:1px solid #e2e8f0"><th style="padding:8px 12px;text-align:left;color:#64748b;font-size:0.72rem;text-transform:uppercase">File</th><th style="padding:8px 12px;text-align:right;color:#64748b;font-size:0.72rem;text-transform:uppercase">Size</th><th style="padding:8px 12px;text-align:right;color:#64748b;font-size:0.72rem;text-transform:uppercase">Date</th><th style="padding:8px 12px;text-align:right;color:#64748b;font-size:0.72rem;text-transform:uppercase">Actions</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($backups, 0, 10) as $b): ?>
                <tr style="border-bottom:1px solid #f1f5f9"><td style="padding:8px 12px;font-family:monospace;color:#1e293b"><?= htmlspecialchars($b['name']) ?></td><td style="padding:8px 12px;text-align:right;color:#64748b"><?= number_format($b['size'] / 1024, 1) ?> KB</td><td style="padding:8px 12px;text-align:right;color:#64748b"><?= date('Y-m-d H:i', $b['time']) ?></td>
                    <td style="padding:8px 12px;text-align:right;white-space:nowrap">
                        <a href="?page=backup&download_file=<?= urlencode($b['name']) ?>&csrf_token=<?= htmlspecialchars(csrf_token()) ?>" title="Download"><i class="fas fa-download" style="color:#0d9488;margin-right:8px"></i></a>
                        <?php if (hasPermission('backup', 'restore')): ?>
                        <a href="?page=backup&restore=<?= urlencode($b['name']) ?>&csrf_token=<?= htmlspecialchars(csrf_token()) ?>" title="Restore" onclick="return confirm('Restore database from this backup? This will overwrite all data.')" style="margin-right:8px"><i class="fas fa-undo" style="color:#059669"></i></a>
                        <?php endif; ?>
                        <?php if (hasPermission('backup', 'delete')): ?>
                        <a href="?page=backup&delete=<?= urlencode($b['name']) ?>&csrf_token=<?= htmlspecialchars(csrf_token()) ?>" title="Delete" onclick="return confirm('Delete this backup?')"><i class="fas fa-trash" style="color:#dc2626"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <?php elseif ($activeTab === 'report'): ?>
        <div class="as-page-title"><i class="fas fa-chart-bar" style="color:#f59e0b"></i> Report Settings</div>
        <div class="as-page-sub">Dashboard layout, default reports, and export formats</div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_report">
            <input type="hidden" name="_tab" value="report">
            <div class="as-card">
                <div class="as-card-title"><i class="fas fa-th-large"></i> Dashboard Layout</div>
                <div class="as-grid as-g2">
                    <label class="as-toggle"><input type="checkbox" name="show_dashboard_cards" value="1"<?= scb('show_dashboard_cards') ?>><div class="as-toggle-text"><div class="as-toggle-label">Show Dashboard Cards</div><div class="as-toggle-desc">Display summary stat cards on the dashboard</div></div></label>
                    <label class="as-toggle"><input type="checkbox" name="show_dashboard_charts" value="1"<?= scb('show_dashboard_charts') ?>><div class="as-toggle-text"><div class="as-toggle-label">Show Dashboard Charts</div><div class="as-toggle-desc">Display revenue and sales charts</div></div></label>
                </div>
            </div>
            <div class="as-card">
                <div class="as-card-title"><i class="fas fa-file-export"></i> Export Defaults</div>
                <div class="as-grid as-g3">
                    <div class="as-field"><label>Default Report Period</label><select name="default_report_period"><option value="today"<?= sc('default_report_period','today') ?>>Today</option><option value="week"<?= sc('default_report_period','week') ?>>This Week</option><option value="month"<?= sc('default_report_period','month') ?>>This Month</option><option value="quarter"<?= sc('default_report_period','quarter') ?>>This Quarter</option><option value="year"<?= sc('default_report_period','year') ?>>This Year</option></select></div>
                    <div class="as-field"><label>Export Format</label><select name="export_format"><option value="excel"<?= sc('export_format','excel') ?>>Excel (.xlsx)</option><option value="csv"<?= sc('export_format','csv') ?>>CSV</option><option value="pdf"<?= sc('export_format','pdf') ?>>PDF</option></select></div>
                </div>
            </div>
            <div class="as-save-bar"><button type="submit" class="as-btn as-btn-primary"><i class="fas fa-save"></i> Save Report Settings</button></div>
        </form>

        <?php elseif ($activeTab === 'maintenance'): ?>
        <div class="as-page-title"><i class="fas fa-wrench" style="color:#64748b"></i> System Maintenance</div>
        <div class="as-page-sub">Cache management, database optimization, and system info</div>
        <div class="as-card">
            <div class="as-card-title"><i class="fas fa-broom"></i> Cache &amp; Optimization</div>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="_action" value="clear_cache"><input type="hidden" name="_tab" value="maintenance"><button type="submit" class="as-btn as-btn-warning"><i class="fas fa-broom"></i> Clear Cache</button></form>
                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="_action" value="optimize_db"><input type="hidden" name="_tab" value="maintenance"><button type="submit" class="as-btn as-btn-outline"><i class="fas fa-compress-alt"></i> Optimize Database</button></form>
            </div>
        </div>
        <div class="as-card">
            <div class="as-card-title"><i class="fas fa-tools"></i> Maintenance Mode</div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="save_maintenance">
                <input type="hidden" name="_tab" value="maintenance">
                <label class="as-toggle" style="max-width:400px"><input type="checkbox" name="maintenance_mode" value="1"<?= scb('maintenance_mode') ?>><div class="as-toggle-text"><div class="as-toggle-label">Enable Maintenance Mode</div><div class="as-toggle-desc">Only administrators can access the system</div></div></label>
                <div class="as-save-bar"><button type="submit" class="as-btn as-btn-primary"><i class="fas fa-save"></i> Save</button></div>
            </form>
        </div>
        <div class="as-card">
            <div class="as-card-title"><i class="fas fa-info-circle"></i> System Information</div>
            <div class="as-grid as-g2">
                <div class="as-field"><label>PHP Version</label><div style="padding:8px 12px;background:#f8fafc;border:1px solid #f1f5f9;border-radius:8px;font-size:0.85rem;font-weight:600;color:#1e293b"><?= phpversion() ?></div></div>
                <div class="as-field"><label>MySQL Version</label><div style="padding:8px 12px;background:#f8fafc;border:1px solid #f1f5f9;border-radius:8px;font-size:0.85rem;font-weight:600;color:#1e293b"><?= $pdo->query('SELECT VERSION()')->fetchColumn() ?></div></div>
                <div class="as-field"><label>Server Software</label><div style="padding:8px 12px;background:#f8fafc;border:1px solid #f1f5f9;border-radius:8px;font-size:0.85rem;font-weight:600;color:#1e293b"><?= $_SERVER['SERVER_SOFTWARE'] ?? 'N/A' ?></div></div>
                <div class="as-field"><label>Application Version</label><div style="padding:8px 12px;background:#f8fafc;border:1px solid #f1f5f9;border-radius:8px;font-size:0.85rem;font-weight:600;color:#1e293b">v1.0.0</div></div>
                <div class="as-field"><label>Upload Max Filesize</label><div style="padding:8px 12px;background:#f8fafc;border:1px solid #f1f5f9;border-radius:8px;font-size:0.85rem;font-weight:600;color:#1e293b"><?= ini_get('upload_max_filesize') ?></div></div>
                <div class="as-field"><label>Max Execution Time</label><div style="padding:8px 12px;background:#f8fafc;border:1px solid #f1f5f9;border-radius:8px;font-size:0.85rem;font-weight:600;color:#1e293b"><?= ini_get('max_execution_time') ?>s</div></div>
            </div>
            <?php
            $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            $totalRows = 0;
            $totalSize = 0;
            foreach ($tables as $t) {
                $info = $pdo->query("SHOW TABLE STATUS LIKE '$t'")->fetch(PDO::FETCH_ASSOC);
                $totalRows += (int)($info['Rows'] ?? 0);
                $totalSize += (int)($info['Data_length'] ?? 0) + (int)($info['Index_length'] ?? 0);
            }
            ?>
            <div class="as-stat-grid" style="margin-top:16px">
                <div class="as-stat"><div class="as-stat-val"><?= count($tables) ?></div><div class="as-stat-label">Tables</div></div>
                <div class="as-stat"><div class="as-stat-val"><?= number_format($totalRows) ?></div><div class="as-stat-label">Total Rows</div></div>
                <div class="as-stat"><div class="as-stat-val"><?= number_format($totalSize / 1024 / 1024, 2) ?> MB</div><div class="as-stat-label">Database Size</div></div>
                <div class="as-stat"><div class="as-stat-val"><?= count($backups ?? []) ?></div><div class="as-stat-label">Backups</div></div>
            </div>
        </div>
        <?php endif; ?>
    </main>
</section>
