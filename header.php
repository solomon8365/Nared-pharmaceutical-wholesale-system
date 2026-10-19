<?php
if (ob_get_level() === 0) ob_start();
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/services/init.php';
$user = currentUser();
$page = $page ?? ($_GET['page'] ?? 'dashboard');
$pdo = getPDO();

$unreadMsgCount = 0;
if ($user && isAdmin()) {
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE reply IS NOT NULL AND is_read = 0');
        $stmt->execute();
        $unreadMsgCount = (int) $stmt->fetchColumn();
    } catch (Exception $e) {}
}

$finUnread = 0;
$pendingTransfers = 0;
if ($user && isAdmin()) {
    try {
        $finUnread = (int) $pdo->query('SELECT COUNT(*) FROM finance_notifications WHERE is_read = 0')->fetchColumn();
    } catch (Exception $e) {}
    try {
        $pendingTransfers = (int) $pdo->query("SELECT COUNT(*) FROM bank_transfers WHERE status = 'submitted'")->fetchColumn();
    } catch (Exception $e) {}
}

// Customer-submitted payments awaiting Finance verification (sidebar badge)
$pendingCustPayments = 0;
if ($user) {
    try {
        $pendingCustPayments = (int) $pdo->query("SELECT COUNT(*) FROM customer_payment_submissions WHERE status = 'pending'")->fetchColumn();
    } catch (Exception $e) {}
}

$pageTitles = [
    'dashboard' => 'Home',
    'super_admin_dashboard' => 'Super Admin',
    'products' => 'Products',
    'categories' => 'Categories',
    'suppliers' => 'Suppliers',
    'customers' => 'Customers',
    'sales' => 'Sales',
    'reports' => 'Reports',
    'stock_movements' => 'Stock Log',
    'price_history' => 'Price Changes',
    'supplier_payments' => 'Supplier Payments',
    'territories' => 'Territories',
    'visits' => 'Visits & Customer Registration',
    'sales_performance' => 'Sales Performance',

    'customer_order_queue' => 'Order Approval',
    'order_fulfillment' => 'Order Fulfillment',
    'expiry_alerts' => 'Expiry Alerts',
    'out_of_stock_queue' => 'Out of Stock Queue',
    'warehouse_inventory' => 'Warehouse Inventory',
    'place_order' => 'Place Order',
    'my_orders' => 'My Orders',
    'delivery_dashboard' => 'Delivery Dashboard',
    'salespeople' => 'Salespeople',
    'delivery_staff' => 'Driver Management',
    'catalog' => 'Product Catalog',
    'receipt' => 'Receipt',
    'invoice' => 'Sales Invoices',
    'finance_dashboard' => 'Finance Dashboard',
    'finance_payments' => 'Customer Payments',
    'finance_refunds' => 'Refunds',
    'finance_reports' => 'Financial Reports',
    'roles' => 'Roles & Permissions',
    'stock' => 'Stock',
    'batch_expiry' => 'Batch & Expiry',
    'purchase_returns' => 'Purchase Returns',
    'sales_returns' => 'Sales Returns',
    'assign_deliveries' => 'Assign Deliveries',
    'delivery_assign' => 'Assign Deliveries',
    'delivery_tracking' => 'Delivery Tracking',
    'delivery_confirmation' => 'Delivery Confirmation',
    'delivery_confirm' => 'Delivery Confirmation',
    'delivery_history' => 'Delivery History',
    'delivery_returns' => 'Delivery Returns',
    'users' => 'Users',
    'backup' => 'Backup & Restore',
    'change_password' => 'Change Password',
    'customer_settings' => 'Profile Settings',
    'cms' => 'Content Management System (CMS)',
    'info' => 'Information Center',
    'messages' => 'Messages',
    'admin_messages' => 'Customer Messages',
    'purchase_payment_approval' => 'Purchase Payment Approval',
    'payments' => 'Payments',
    'help_center' => 'Help Center',
    'credit_management' => 'Credit Management',
    'credit_control' => 'Credit Control & Management',
    'credit_reports' => 'Credit Reports',
    'credit_portal' => 'My Credit',
    'delivery_management' => 'Delivery Module',
    'system_sync' => 'Data Sync',
];
$pageTitle = $pageTitles[$page] ?? ucfirst($page);
$breadcrumbs = $page === 'dashboard' ? [] : [['Home', '?page=dashboard'], [$pageTitle, '']];
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars(APP_NAME) ?></title>
    <?= favicon_tags() ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=16">
    <link rel="stylesheet" href="assets/css/portal.css?v=1">
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="assets/vendor/sweetalert2/sweetalert2.all.min.js?v=1"></script>
    <script>window.Swal || document.write('<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"><\/script>');</script>
    <script src="assets/js/action-notify.js?v=5"></script>
    <script src="assets/js/swal.js?v=9"></script>
    <script src="assets/js/app.js?v=10"></script>
    <script src="assets/js/toast.js?v=2"></script>
    <script src="assets/js/scanner.js?v=2"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script>var csrfToken = '<?= csrf_token() ?>';var SESSION_TIMEOUT_MINUTES = <?= (int) sessionTimeoutMinutes() ?>;document.addEventListener('DOMContentLoaded',function(){var wrap=document.querySelector('.th-profile'),btn=document.getElementById('th-profile-btn'),menu=document.getElementById('th-profile-menu');if(!wrap||!btn||!menu)return;function list(){return Array.prototype.slice.call(menu.querySelectorAll('.th-menu-item')).filter(function(el){return !el.disabled&&el.offsetParent!==null;});}function isOpen(){return !menu.hidden;}function open(){menu.hidden=false;btn.setAttribute('aria-expanded','true');}function close(focus){menu.hidden=true;btn.setAttribute('aria-expanded','false');if(focus)btn.focus();}btn.addEventListener('click',function(e){e.stopPropagation();if(isOpen()){close(false);}else{open();}});document.addEventListener('click',function(e){if(isOpen()&&!e.target.closest('.th-profile'))close(false);});btn.addEventListener('keydown',function(e){var k=e.key,it;if(k==='ArrowDown'||k==='Enter'||k===' '){e.preventDefault();open();it=list();if(it.length)it[0].focus();}else if(k==='ArrowUp'){e.preventDefault();open();it=list();if(it.length)it[it.length-1].focus();}else if(k==='Escape'){close(true);}else if(k==='Tab'&&isOpen()){e.preventDefault();open();it=list();if(it.length)it[0].focus();}});menu.addEventListener('keydown',function(e){var k=e.key,it=list(),i=it.indexOf(document.activeElement);if(k==='Escape'){e.preventDefault();close(true);}else if(k==='ArrowDown'){e.preventDefault();if(it.length)it[(i+1+it.length)%it.length].focus();}else if(k==='ArrowUp'){e.preventDefault();if(it.length)it[(i-1+it.length)%it.length].focus();}else if(k==='Home'){e.preventDefault();if(it.length)it[0].focus();}else if(k==='End'){e.preventDefault();if(it.length)it[it.length-1].focus();}else if(k==='Tab'&&!e.shiftKey&&i===it.length-1){close(false);}});document.addEventListener('keydown',function(e){if(e.key==='Escape'&&isOpen())close(true);});var dm=document.getElementById('th-dark-toggle');if(dm){function sync(){var on=document.body.classList.contains('dark-mode'),i=dm.querySelector('i'),t=dm.querySelector('.th-menu-text');if(i)i.className=on?'fas fa-sun':'fas fa-moon';if(t)t.textContent=on?'Light Mode':'Dark Mode';}sync();dm.addEventListener('click',function(){if(typeof toggleDarkMode==='function')toggleDarkMode();sync();});}});</script>
    <script src="assets/js/ajax-interceptor.js?v=9"></script>
    <style>.ajax-busy{pointer-events:none;opacity:.7}.ajax-busy i{margin-right:4px}</style>
</head>
<body class="<?= !empty($_COOKIE['dark_mode']) ? 'dark-mode' : '' ?>">
<div id="toast-container"></div>
<?php if ($f = get_flash()): ?>
<script>
(function () {
    if (typeof showToast === 'function' && <?= json_encode($f['message']) ?>) {
        showToast(<?= json_encode($f['message']) ?>, <?= json_encode($f['type']) ?>);
    }
})();
</script>
<?php endif; ?>

<?php if ($user): ?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-logo">
            <img src="<?= htmlspecialchars(brand_logo()) ?>" alt="Logo" class="sidebar-brand-img">
            <span class="sidebar-title">NARED</span>
        </div>
        <button class="sidebar-toggle" id="sidebar-toggle" onclick="collapseSidebar()"><i class="fas fa-bars"></i></button>
    </div>
    <nav class="sidebar-nav">
        <a href="?page=dashboard" class="sidebar-link<?= $page === 'dashboard' ? ' active' : '' ?>"><i class="fas fa-home"></i><span>Home</span></a>
        <?php if (isSuperAdmin()): ?>
            <div class="sidebar-group<?= in_array($page, ['super_admin_dashboard','users','roles','settings','backup','audit_logs','login_history','departments','tax_currency','system_sync']) ? ' open' : '' ?>">
                <div class="sidebar-group-header" onclick="toggleGroup('sadm')"><i class="fas fa-shield-halved"></i><span>Super Admin</span><i class="fas fa-chevron-right chevron"></i></div>
                <div class="sidebar-group-content" id="group-sadm">
                    <a href="?page=super_admin_dashboard" class="sidebar-link sub<?= $page === 'super_admin_dashboard' ? ' active' : '' ?>"><i class="fas fa-gauge-high"></i>Dashboard</a>
                    <a href="?page=users" class="sidebar-link sub<?= $page === 'users' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Users</a>
                    <a href="?page=roles" class="sidebar-link sub<?= $page === 'roles' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Roles &amp; Permissions</a>
                    <div class="sidebar-divider"></div>
                    <a href="?page=settings" class="sidebar-link sub<?= $page === 'settings' ? ' active' : '' ?>"><i class="fas fa-circle"></i>System Settings</a>
                    <a href="?page=backup" class="sidebar-link sub<?= $page === 'backup' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Backup &amp; Restore</a>
                    <a href="?page=audit_logs" class="sidebar-link sub<?= $page === 'audit_logs' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Audit Logs</a>
                    <a href="?page=login_history" class="sidebar-link sub<?= $page === 'login_history' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Login History</a>
                </div>
            </div>
        <?php endif; ?>
        <?php if (isAdmin()): ?>
            <?php if (hasPermission('store_dashboard', 'view') || hasPermission('products', 'view') || hasPermission('categories', 'view') || hasPermission('stock', 'view') || hasPermission('batch_expiry', 'view') || hasPermission('out_of_stock_queue', 'view') || hasPermission('expiry_management', 'view')): ?>
            <div class="sidebar-group<?= in_array($page, ['store_dashboard','products','categories','stock','batch_expiry','stock_movements','stock_adjustments','stock_transfers','physical_count','expiry_alerts','out_of_stock_queue','expiry_management']) ? ' open' : '' ?>">
                <div class="sidebar-group-header" onclick="toggleGroup('inv')"><i class="fas fa-capsules"></i><span>Inventory</span><i class="fas fa-chevron-right chevron"></i></div>
                <div class="sidebar-group-content" id="group-inv">
                    <?php if (hasPermission('store_dashboard', 'view')): ?><a href="?page=store_dashboard" class="sidebar-link sub<?= $page === 'store_dashboard' ? ' active' : '' ?>"><i class="fas fa-store"></i>Store Dashboard</a><?php endif; ?>
                    <?php if (hasPermission('products', 'view')): ?><a href="?page=products" class="sidebar-link sub<?= $page === 'products' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Products</a><?php endif; ?>
                    <?php if (hasPermission('categories', 'view')): ?><a href="?page=categories" class="sidebar-link sub<?= $page === 'categories' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Categories</a><?php endif; ?>
                    <?php if (hasPermission('stock', 'view')): ?><a href="?page=stock" class="sidebar-link sub<?= $page === 'stock' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Stock</a><?php endif; ?>
                    <?php if (hasPermission('stock', 'view')): ?><a href="?page=warehouse_inventory" class="sidebar-link sub<?= $page === 'warehouse_inventory' ? ' active' : '' ?>"><i class="fas fa-warehouse"></i>Warehouse Inventory</a><?php endif; ?>
                    <?php if (hasPermission('batch_expiry', 'view')): ?><a href="?page=batch_expiry" class="sidebar-link sub<?= $page === 'batch_expiry' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Batch &amp; Expiry</a><?php endif; ?>
                    <?php if (hasPermission('out_of_stock_queue', 'view')): ?><a href="?page=out_of_stock_queue" class="sidebar-link sub<?= $page === 'out_of_stock_queue' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Out of Stock Queue</a><?php endif; ?>
                    <?php if (hasPermission('expiry_management', 'view')): ?><a href="?page=expiry_management" class="sidebar-link sub<?= $page === 'expiry_management' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Expiry Management</a><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if (hasPermission('suppliers', 'view') || canViewPurchaseOrder() || canReceivePurchaseOrder() || hasPermission('purchase_returns', 'view') || canProcessPurchasePayment()): ?>
            <div class="sidebar-group<?= in_array($page, ['suppliers','supplier_payments','purchase_orders','goods_receiving','purchase_returns']) ? ' open' : '' ?>">
                <div class="sidebar-group-header" onclick="toggleGroup('proc')"><i class="fas fa-truck"></i><span>Procurement</span><i class="fas fa-chevron-right chevron"></i></div>
                <div class="sidebar-group-content" id="group-proc">
                    <?php if (hasPermission('suppliers', 'view')): ?><a href="?page=suppliers" class="sidebar-link sub<?= $page === 'suppliers' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Suppliers</a><?php endif; ?>
                    <?php if (canViewPurchaseOrder()): ?><a href="?page=purchase_orders" class="sidebar-link sub<?= $page === 'purchase_orders' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Purchase Orders</a><?php endif; ?>
                    <?php if (canReceivePurchaseOrder()): ?><a href="?page=goods_receiving" class="sidebar-link sub<?= $page === 'goods_receiving' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Goods Receiving</a><?php endif; ?>
                    <?php if (hasPermission('purchase_returns', 'view')): ?><a href="?page=purchase_returns" class="sidebar-link sub<?= $page === 'purchase_returns' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Purchase Returns</a><?php endif; ?>
                    <?php if (canProcessPurchasePayment()): ?><a href="?page=purchase_payment_approval" class="sidebar-link sub<?= $page === 'purchase_payment_approval' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Payment Approval</a><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if (hasPermission('customers', 'view') || hasPermission('sales', 'view') || hasPermission('customer_order_queue', 'view') || hasPermission('order_fulfillment', 'view') || hasPermission('invoice', 'view') || hasPermission('sales_returns', 'view') || hasPermission('salespeople', 'view')): ?>
            <div class="sidebar-group<?= in_array($page, ['customers','sales','customer_order_queue','order_fulfillment','invoice','sales_returns','warehouse_picking','salespeople','finance_bank']) ? ' open' : '' ?>">
                <div class="sidebar-group-header" onclick="toggleGroup('sales')"><i class="fas fa-cash-register"></i><span>Sales</span><i class="fas fa-chevron-right chevron"></i></div>
                <div class="sidebar-group-content" id="group-sales">
                    <?php if (hasPermission('customers', 'view')): ?><a href="?page=customers" class="sidebar-link sub<?= $page === 'customers' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Business Customers</a><?php endif; ?>
                    <?php if (hasPermission('sales', 'view')): ?><a href="?page=sales" class="sidebar-link sub<?= $page === 'sales' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Sales Orders</a><?php endif; ?>
                    <?php if (hasPermission('customer_order_queue', 'view')): ?><a href="?page=customer_order_queue" class="sidebar-link sub<?= $page === 'customer_order_queue' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Order Approval</a><?php endif; ?>
                    <?php if (hasPermission('order_fulfillment', 'view')): ?><a href="?page=order_fulfillment" class="sidebar-link sub<?= $page === 'order_fulfillment' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Order Fulfillment</a><?php endif; ?>
                    <?php if (hasPermission('invoice', 'view')): ?><a href="?page=invoice" class="sidebar-link sub<?= $page === 'invoice' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Sales Invoices</a><?php endif; ?>
                    <?php if (hasPermission('sales_returns', 'view')): ?><a href="?page=sales_returns" class="sidebar-link sub<?= $page === 'sales_returns' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Sales Returns</a><?php endif; ?>
                    <div class="sidebar-divider"></div>
                    <?php if (hasPermission('salespeople', 'view')): ?><a href="?page=salespeople" class="sidebar-link sub<?= $page === 'salespeople' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Salespeople</a><?php endif; ?>
                    <?php if (isAdmin()): ?><a href="?page=finance_bank&tab=transfers&tf_status=submitted" class="sidebar-link sub<?= $page === 'finance_bank' && ($_GET['tab'] ?? '') === 'transfers' ? ' active' : '' ?>"><i class="fas fa-exchange-alt"></i>Transfer Approvals<?php if ($pendingTransfers > 0): ?> <span style="background:var(--red-600);color:#fff;border-radius:10px;font-size:0.65rem;padding:1px 7px;font-weight:700"><?= $pendingTransfers ?></span><?php endif; ?></a><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if (hasPermission('credit_control', 'view') || hasPermission('credit_reports', 'view')): ?>
            <div class="sidebar-group<?= in_array($page, ['credit_control','credit_reports','ledger']) ? ' open' : '' ?>">
                <div class="sidebar-group-header" onclick="toggleGroup('cred')"><i class="fas fa-hand-holding-usd"></i><span>Credit</span><i class="fas fa-chevron-right chevron"></i></div>
                <div class="sidebar-group-content" id="group-cred">
                    <?php if (hasPermission('credit_control', 'view')): ?><a href="?page=credit_control" class="sidebar-link sub<?= $page === 'credit_control' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Credit Control</a><?php endif; ?>
                    <?php if (hasPermission('credit_reports', 'view')): ?><a href="?page=credit_reports" class="sidebar-link sub<?= $page === 'credit_reports' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Credit Reports</a><?php endif; ?>
                    <a href="?page=ledger" class="sidebar-link sub<?= $page === 'ledger' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Customer Ledger</a>
                </div>
            </div>
            <?php endif; ?>
            <?php if (hasPermission('delivery_history', 'view') || hasPermission('delivery_staff', 'view') || hasPermission('assign_deliveries', 'view')): ?>
            <div class="sidebar-group<?= in_array($page, ['delivery_history']) ? ' open' : '' ?>">
                <div class="sidebar-group-header" onclick="toggleGroup('del')"><i class="fas fa-truck"></i><span>Delivery</span><i class="fas fa-chevron-right chevron"></i></div>
                <div class="sidebar-group-content" id="group-del">
                    <?php if (isAdmin()): ?>
                        <a href="?page=delivery_history" class="sidebar-link sub<?= $page === 'delivery_history' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Delivery Reports</a>
                    <?php else: ?>
                    <?php if (hasPermission('assign_deliveries', 'view')): ?><a href="?page=delivery_assign" class="sidebar-link sub<?= $page === 'delivery_assign' && ($_GET['tab'] ?? '') !== 'reassign' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Assign Driver</a><?php endif; ?>
                    <?php if (hasPermission('assign_deliveries', 'view')): ?><a href="?page=delivery_assign&tab=reassign" class="sidebar-link sub<?= $page === 'delivery_assign' && ($_GET['tab'] ?? '') === 'reassign' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Reassign Driver</a><?php endif; ?>
                    <?php if (hasPermission('delivery_staff', 'view')): ?><a href="?page=delivery_staff" class="sidebar-link sub<?= $page === 'delivery_staff' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Driver Management</a><?php endif; ?>
                    <div class="sidebar-divider"></div>
                    <?php if (hasPermission('assign_deliveries', 'view')): ?><a href="?page=delivery_assign" class="sidebar-link sub<?= $page === 'delivery_assign' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Delivery Queue</a><?php endif; ?>
                    <?php if (hasPermission('delivery_tracking', 'view')): ?><a href="?page=delivery_tracking&status=assigned" class="sidebar-link sub<?= $page === 'delivery_tracking' && ($_GET['status'] ?? '') === 'assigned' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Assigned Orders</a><?php endif; ?>
                    <?php if (hasPermission('delivery_tracking', 'view')): ?><a href="?page=delivery_tracking&status=out_for_delivery" class="sidebar-link sub<?= $page === 'delivery_tracking' && ($_GET['status'] ?? '') === 'out_for_delivery' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Out for Delivery</a><?php endif; ?>
                    <?php if (hasPermission('delivery_tracking', 'view')): ?><a href="?page=delivery_tracking" class="sidebar-link sub<?= $page === 'delivery_tracking' && ($_GET['status'] ?? '') === '' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Delivery Tracking</a><?php endif; ?>
                    <?php if (hasPermission('delivery_tracking', 'view')): ?><a href="?page=delivery_tracking&status=delivered" class="sidebar-link sub<?= $page === 'delivery_tracking' && ($_GET['status'] ?? '') === 'delivered' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Completed Deliveries</a><?php endif; ?>
                    <?php if (hasPermission('delivery_tracking', 'view')): ?><a href="?page=delivery_tracking&status=failed" class="sidebar-link sub<?= $page === 'delivery_tracking' && ($_GET['status'] ?? '') === 'failed' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Failed Deliveries</a><?php endif; ?>
                    <?php if (hasPermission('delivery_returns', 'view')): ?><a href="?page=delivery_returns" class="sidebar-link sub<?= $page === 'delivery_returns' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Delivery Returns</a><?php endif; ?>
                    <div class="sidebar-divider"></div>
                    <?php if (hasPermission('delivery_history', 'view')): ?><a href="?page=delivery_history" class="sidebar-link sub<?= $page === 'delivery_history' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Delivery Reports</a><?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if (hasPermission('reports', 'view') || hasPermission('finance_reports', 'view')): ?>
            <div class="sidebar-group<?= in_array($page, ['reports','finance_reports']) ? ' open' : '' ?>">
                <div class="sidebar-group-header" onclick="toggleGroup('rpt')"><i class="fas fa-chart-bar"></i><span>Reports</span><i class="fas fa-chevron-right chevron"></i></div>
                <div class="sidebar-group-content" id="group-rpt">
                    <a href="?page=reports&tab=sales" class="sidebar-link sub<?= $page === 'reports' && ($_GET['tab'] ?? '') === 'sales' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Sales Reports</a>
                    <a href="?page=reports&tab=purchase" class="sidebar-link sub<?= $page === 'reports' && ($_GET['tab'] ?? '') === 'purchase' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Purchase Reports</a>
                    <a href="?page=reports&tab=inventory" class="sidebar-link sub<?= $page === 'reports' && ($_GET['tab'] ?? '') === 'inventory' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Inventory Reports</a>
                    <?php if (hasPermission('finance_reports', 'view')): ?><a href="?page=finance_reports" class="sidebar-link sub<?= $page === 'finance_reports' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Financial Reports</a><?php endif; ?>
                    <a href="?page=reports&tab=delivery" class="sidebar-link sub<?= $page === 'reports' && ($_GET['tab'] ?? '') === 'delivery' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Delivery Reports</a>
                </div>
            </div>
            <?php endif; ?>
            <?php if (hasPermission('users', 'view') || hasPermission('roles', 'view')): ?>
            <div class="sidebar-group<?= in_array($page, ['users','roles']) ? ' open' : '' ?>">
                <div class="sidebar-group-header" onclick="toggleGroup('umg')"><i class="fas fa-users-cog"></i><span>User Management</span><i class="fas fa-chevron-right chevron"></i></div>
                <div class="sidebar-group-content" id="group-umg">
                    <?php if (hasPermission('users', 'view')): ?><a href="?page=users" class="sidebar-link sub<?= $page === 'users' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Users</a><?php endif; ?>
                    <?php if (hasPermission('roles', 'view')): ?><a href="?page=roles" class="sidebar-link sub<?= $page === 'roles' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Roles &amp; Permissions</a><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if (hasPermission('cms', 'view') || hasPermission('system_sync', 'view')): ?>
            <div class="sidebar-group<?= in_array($page, ['cms','system_sync']) ? ' open' : '' ?>">
                <div class="sidebar-group-header" onclick="toggleGroup('set')"><i class="fas fa-cog"></i><span>Content &amp; Data</span><i class="fas fa-chevron-right chevron"></i></div>
                <div class="sidebar-group-content" id="group-set">
                    <?php if (hasPermission('cms', 'view')): ?><a href="?page=cms" class="sidebar-link sub<?= $page === 'cms' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Content Management</a><?php endif; ?>
                    <?php if (hasPermission('system_sync', 'view')): ?><a href="?page=system_sync" class="sidebar-link sub<?= $page === 'system_sync' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Data Sync</a><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        <?php elseif (isStoreManager()): ?>
            <a href="?page=store_dashboard" class="sidebar-link<?= $page === 'store_dashboard' ? ' active' : '' ?>"><i class="fas fa-store"></i><span>Store Dashboard</span></a>
            <?php if (hasPermission('products', 'view') || hasPermission('categories', 'view') || hasPermission('stock', 'view') || hasPermission('stock_movements', 'view') || hasPermission('stock_adjustments', 'view') || hasPermission('stock_transfers', 'view') || hasPermission('physical_count', 'view') || hasPermission('batch_expiry', 'view') || hasPermission('expiry_management', 'view') || hasPermission('expiry_alerts', 'view') || hasPermission('out_of_stock_queue', 'view')): ?>
            <div class="sidebar-group<?= in_array($page, ['products','categories','stock','stock_movements','stock_adjustments','stock_transfers','physical_count','batch_expiry','expiry_management','expiry_alerts','out_of_stock_queue']) ? ' open' : '' ?>">
                <div class="sidebar-group-header" onclick="toggleGroup('sm-inv')"><i class="fas fa-capsules"></i><span>Inventory</span><i class="fas fa-chevron-right chevron"></i></div>
                <div class="sidebar-group-content" id="group-sm-inv">
                    <?php if (hasPermission('products', 'view')): ?><a href="?page=products" class="sidebar-link sub<?= $page === 'products' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Products</a><?php endif; ?>
                    <?php if (hasPermission('categories', 'view')): ?><a href="?page=categories" class="sidebar-link sub<?= $page === 'categories' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Categories</a><?php endif; ?>
                    <?php if (hasPermission('stock', 'view')): ?><a href="?page=stock" class="sidebar-link sub<?= $page === 'stock' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Stock</a><?php endif; ?>
                    <?php if (hasPermission('stock', 'view')): ?><a href="?page=warehouse_inventory" class="sidebar-link sub<?= $page === 'warehouse_inventory' ? ' active' : '' ?>"><i class="fas fa-warehouse"></i>Warehouse Inventory</a><?php endif; ?>
                    <?php if (hasPermission('stock_movements', 'view')): ?><a href="?page=stock_movements" class="sidebar-link sub<?= $page === 'stock_movements' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Stock Log</a><?php endif; ?>
                    <?php if (hasPermission('stock_adjustments', 'view')): ?><a href="?page=stock_adjustments" class="sidebar-link sub<?= $page === 'stock_adjustments' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Stock Adjustments</a><?php endif; ?>
                    <?php if (hasPermission('stock_transfers', 'view')): ?><a href="?page=stock_transfers" class="sidebar-link sub<?= $page === 'stock_transfers' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Stock Transfers</a><?php endif; ?>
                    <?php if (hasPermission('physical_count', 'view')): ?><a href="?page=physical_count" class="sidebar-link sub<?= $page === 'physical_count' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Physical Count</a><?php endif; ?>
                    <?php if (hasPermission('batch_expiry', 'view')): ?><a href="?page=batch_expiry" class="sidebar-link sub<?= $page === 'batch_expiry' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Batch &amp; Expiry</a><?php endif; ?>
                    <?php if (hasPermission('expiry_management', 'view')): ?><a href="?page=expiry_management" class="sidebar-link sub<?= $page === 'expiry_management' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Expiry Management</a><?php endif; ?>
                    <?php if (hasPermission('expiry_alerts', 'view')): ?><a href="?page=expiry_alerts" class="sidebar-link sub<?= $page === 'expiry_alerts' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Expiry Alerts</a><?php endif; ?>
                    <?php if (hasPermission('out_of_stock_queue', 'view')): ?><a href="?page=out_of_stock_queue" class="sidebar-link sub<?= $page === 'out_of_stock_queue' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Out of Stock Queue</a><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if (hasPermission('suppliers', 'view') || canViewPurchaseOrder() || canReceivePurchaseOrder() || hasPermission('purchase_returns', 'view')): ?>
            <div class="sidebar-group<?= in_array($page, ['suppliers','purchase_orders','goods_receiving','purchase_returns']) ? ' open' : '' ?>">
                <div class="sidebar-group-header" onclick="toggleGroup('sm-proc')"><i class="fas fa-truck"></i><span>Procurement</span><i class="fas fa-chevron-right chevron"></i></div>
                <div class="sidebar-group-content" id="group-sm-proc">
                    <?php if (hasPermission('suppliers', 'view')): ?><a href="?page=suppliers" class="sidebar-link sub<?= $page === 'suppliers' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Suppliers</a><?php endif; ?>
                    <?php if (canViewPurchaseOrder()): ?><a href="?page=purchase_orders" class="sidebar-link sub<?= $page === 'purchase_orders' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Purchase Orders</a><?php endif; ?>
                    <?php if (canReceivePurchaseOrder()): ?><a href="?page=goods_receiving" class="sidebar-link sub<?= $page === 'goods_receiving' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Goods Receiving</a><?php endif; ?>
                    <?php if (hasPermission('purchase_returns', 'view')): ?><a href="?page=purchase_returns" class="sidebar-link sub<?= $page === 'purchase_returns' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Purchase Returns</a><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if (hasPermission('order_fulfillment', 'view') || hasPermission('warehouse_picking', 'view') || hasPermission('customer_order_queue', 'view') || hasPermission('sales', 'view') || hasPermission('invoice', 'view') || hasPermission('customers', 'view')): ?>
            <div class="sidebar-group<?= in_array($page, ['order_fulfillment','warehouse_picking','customer_order_queue','sales','invoice','customers']) ? ' open' : '' ?>">
                <div class="sidebar-group-header" onclick="toggleGroup('sm-sales')"><i class="fas fa-cash-register"></i><span>Sales</span><i class="fas fa-chevron-right chevron"></i></div>
                <div class="sidebar-group-content" id="group-sm-sales">
                    <?php if (hasPermission('order_fulfillment', 'view')): ?><a href="?page=order_fulfillment" class="sidebar-link sub<?= $page === 'order_fulfillment' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Order Fulfillment</a><?php endif; ?>
                    <?php if (hasPermission('warehouse_picking', 'view')): ?><a href="?page=warehouse_picking" class="sidebar-link sub<?= $page === 'warehouse_picking' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Warehouse Picking</a><?php endif; ?>
                    <?php if (hasPermission('customer_order_queue', 'view')): ?><a href="?page=customer_order_queue" class="sidebar-link sub<?= $page === 'customer_order_queue' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Order Approval</a><?php endif; ?>
                    <?php if (hasPermission('sales', 'view')): ?><a href="?page=sales" class="sidebar-link sub<?= $page === 'sales' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Sales Orders</a><?php endif; ?>
                    <?php if (hasPermission('customers', 'view')): ?><a href="?page=customers" class="sidebar-link sub<?= $page === 'customers' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Business Customers</a><?php endif; ?>
                    <?php if (hasPermission('invoice', 'view')): ?><a href="?page=invoice" class="sidebar-link sub<?= $page === 'invoice' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Sales Invoices</a><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if (hasPermission('reports', 'view')): ?>
            <a href="?page=warehouse_reports" class="sidebar-link sub<?= $page === 'warehouse_reports' ? ' active' : '' ?>"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
            <?php endif; ?>

        <?php elseif (isCustomer()): ?>
            <div class="sidebar-group<?= in_array($page, ['catalog','place_order','my_orders']) ? ' open' : '' ?>">
                <div class="sidebar-group-header" onclick="toggleGroup('shop')"><i class="fas fa-store"></i><span>Shop</span><i class="fas fa-chevron-right chevron"></i></div>
                <div class="sidebar-group-content" id="group-shop">
                    <a href="?page=catalog" class="sidebar-link sub<?= $page === 'catalog' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Product Catalog</a>
                    <a href="?page=place_order" class="sidebar-link sub<?= $page === 'place_order' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Place Order</a>
                    <a href="?page=my_orders" class="sidebar-link sub<?= $page === 'my_orders' ? ' active' : '' ?>"><i class="fas fa-circle"></i>My Orders</a>
                </div>
            </div>
            <div class="sidebar-group<?= in_array($page, ['payments','invoice','credit_portal','ledger']) ? ' open' : '' ?>">
                <div class="sidebar-group-header" onclick="toggleGroup('fin')"><i class="fas fa-credit-card"></i><span>Finance</span><i class="fas fa-chevron-right chevron"></i></div>
                <div class="sidebar-group-content" id="group-fin">
                    <a href="?page=payments" class="sidebar-link sub<?= $page === 'payments' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Payments</a>
                    <a href="?page=invoice" class="sidebar-link sub<?= $page === 'invoice' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Invoices</a>
                    <a href="?page=credit_portal" class="sidebar-link sub<?= $page === 'credit_portal' ? ' active' : '' ?>"><i class="fas fa-circle"></i>My Credit</a>
                    <a href="?page=ledger" class="sidebar-link sub<?= $page === 'ledger' ? ' active' : '' ?>"><i class="fas fa-circle"></i>My Ledger</a>
                </div>
            </div>

        <?php elseif (isDeliveryStaff()): ?>
            <div class="sidebar-group<?= in_array($page, ['delivery_dashboard','delivery_staff','delivery_assign','delivery_tracking','delivery_confirm','delivery_history','delivery_returns','assign_deliveries','delivery_confirmation']) ? ' open' : '' ?>">
                <div class="sidebar-group-header" onclick="toggleGroup('del2')"><i class="fas fa-tasks"></i><span>Delivery Operations</span><i class="fas fa-chevron-right chevron"></i></div>
                <div class="sidebar-group-content" id="group-del2">
                    <a href="?page=delivery_dashboard" class="sidebar-link sub<?= $page === 'delivery_dashboard' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Dashboard</a>
                    <a href="?page=delivery_assign" class="sidebar-link sub<?= $page === 'delivery_assign' && ($_GET['tab'] ?? '') !== 'reassign' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Assign Driver</a>
                    <a href="?page=delivery_assign&tab=reassign" class="sidebar-link sub<?= $page === 'delivery_assign' && ($_GET['tab'] ?? '') === 'reassign' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Reassign Driver</a>
                    <a href="?page=delivery_staff" class="sidebar-link sub<?= $page === 'delivery_staff' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Driver Management</a>
                    <div class="sidebar-divider"></div>
                    <a href="?page=delivery_assign" class="sidebar-link sub<?= $page === 'delivery_assign' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Delivery Queue</a>
                    <a href="?page=delivery_tracking&status=assigned" class="sidebar-link sub<?= $page === 'delivery_tracking' && ($_GET['status'] ?? '') === 'assigned' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Assigned Orders</a>
                    <a href="?page=delivery_tracking&status=out_for_delivery" class="sidebar-link sub<?= $page === 'delivery_tracking' && ($_GET['status'] ?? '') === 'out_for_delivery' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Out for Delivery</a>
                    <a href="?page=delivery_tracking" class="sidebar-link sub<?= $page === 'delivery_tracking' && ($_GET['status'] ?? '') === '' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Delivery Tracking</a>
                    <a href="?page=delivery_tracking&status=delivered" class="sidebar-link sub<?= $page === 'delivery_tracking' && ($_GET['status'] ?? '') === 'delivered' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Completed Deliveries</a>
                    <a href="?page=delivery_tracking&status=failed" class="sidebar-link sub<?= $page === 'delivery_tracking' && ($_GET['status'] ?? '') === 'failed' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Failed Deliveries</a>
                    <a href="?page=delivery_returns" class="sidebar-link sub<?= $page === 'delivery_returns' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Delivery Returns</a>
                    <div class="sidebar-divider"></div>
                    <a href="?page=delivery_history" class="sidebar-link sub<?= $page === 'delivery_history' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Delivery Reports</a>
                </div>
            </div>

        <?php elseif (isFinanceOfficer()): ?>
            <?php if (hasPermission('sales', 'view') || hasPermission('customers', 'view') || hasPermission('invoice', 'view')): ?>
            <div class="sidebar-group<?= in_array($page, ['sales','customers','invoice']) ? ' open' : '' ?>">
                <div class="sidebar-group-header" onclick="toggleGroup('fsales')"><i class="fas fa-cash-register"></i><span>Sales</span><i class="fas fa-chevron-right chevron"></i></div>
                <div class="sidebar-group-content" id="group-fsales">
                    <?php if (hasPermission('customers', 'view')): ?><a href="?page=customers" class="sidebar-link sub<?= $page === 'customers' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Business Customers</a><?php endif; ?>
                    <?php if (hasPermission('sales', 'view')): ?><a href="?page=sales" class="sidebar-link sub<?= $page === 'sales' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Sales Orders</a><?php endif; ?>
                    <?php if (hasPermission('finance_payments', 'view')): ?><a href="?page=finance_payments" class="sidebar-link sub<?= $page === 'finance_payments' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Payment Confirmation</a><?php endif; ?>
                    <?php if (hasPermission('invoice', 'view')): ?><a href="?page=invoice" class="sidebar-link sub<?= $page === 'invoice' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Sales Invoices</a><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="sidebar-group<?= in_array($page, ['finance_dashboard','supplier_payments','finance_bank','finance_cash','finance_expenses','finance_refunds','finance_accounting','finance_charts','finance_reports','credit_control','credit_reports','customer_statement','ledger']) ? ' open' : '' ?>">
                <div class="sidebar-group-header" onclick="toggleGroup('ffin')"><i class="fas fa-file-invoice-dollar"></i><span>Finance</span><i class="fas fa-chevron-right chevron"></i></div>
                <div class="sidebar-group-content" id="group-ffin">
                    <a href="?page=finance_dashboard" class="sidebar-link sub<?= $page === 'finance_dashboard' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Dashboard</a>
                    <a href="?page=finance_payments&tab=customer" class="sidebar-link sub<?= $page === 'finance_payments' ? ' active' : '' ?>"><i class="fas fa-user-check"></i>Customer Payments<?php if ($pendingCustPayments > 0): ?> <span style="background:#7c3aed;color:#fff;border-radius:10px;font-size:0.65rem;padding:1px 7px;font-weight:700"><?= $pendingCustPayments ?></span><?php endif; ?></a>
                    <a href="?page=supplier_payments" class="sidebar-link sub<?= $page === 'supplier_payments' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Supplier Payments</a>
                    <a href="?page=finance_bank" class="sidebar-link sub<?= $page === 'finance_bank' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Bank Management</a>
                    <a href="?page=finance_cash" class="sidebar-link sub<?= $page === 'finance_cash' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Cash Management</a>
                    <a href="?page=finance_expenses" class="sidebar-link sub<?= $page === 'finance_expenses' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Expenses</a>
                    <a href="?page=finance_refunds" class="sidebar-link sub<?= $page === 'finance_refunds' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Refunds</a>
                    <a href="?page=finance_accounting" class="sidebar-link sub<?= $page === 'finance_accounting' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Accounting</a>
                    <a href="?page=finance_charts" class="sidebar-link sub<?= $page === 'finance_charts' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Charts</a>
                    <a href="?page=finance_reports" class="sidebar-link sub<?= $page === 'finance_reports' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Reports</a>
                    <a href="?page=customer_statement" class="sidebar-link sub<?= $page === 'customer_statement' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Customer Statement</a>
                    <a href="?page=ledger" class="sidebar-link sub<?= $page === 'ledger' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Customer Ledger</a>
                    <?php if (isAdmin()): ?><a href="?page=credit_control" class="sidebar-link sub<?= $page === 'credit_control' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Credit Control</a><?php endif; ?>
                </div>
            </div>

        <?php elseif (isSalesperson()): ?>
            <div class="sidebar-group<?= in_array($page, ['sales','customers','products']) ? ' open' : '' ?>">
                <div class="sidebar-group-header" onclick="toggleGroup('ssales')"><i class="fas fa-cash-register"></i><span>Sales</span><i class="fas fa-chevron-right chevron"></i></div>
                <div class="sidebar-group-content" id="group-ssales">
                    <a href="?page=sales" class="sidebar-link sub<?= $page === 'sales' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Sales Orders</a>
                    <a href="?page=customers" class="sidebar-link sub<?= $page === 'customers' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Business Customers</a>
                    <a href="?page=products" class="sidebar-link sub<?= $page === 'products' ? ' active' : '' ?>"><i class="fas fa-circle"></i>Products</a>
                </div>
            </div>
        <?php endif; ?>
    </nav>
</aside>
<div class="sidebar-overlay" id="sidebar-overlay" onclick="toggleSidebar()"></div>
<?php endif; ?>

<?php if ($user): ?>
<div class="main-wrapper">
    <header class="top-header">
        <div class="th-left">
            <button class="th-toggle" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
            <img src="<?= htmlspecialchars(brand_logo()) ?>" alt="Logo" class="th-brand">
            <?php if (!empty($breadcrumbs)): ?>
            <nav class="breadcrumbs">
                <?php foreach ($breadcrumbs as $i => $bc): ?>
                    <?php if ($i > 0): ?><i class="fas fa-chevron-right breadcrumb-sep"></i><?php endif; ?>
                    <?php if (!empty($bc[1])): ?><a href="<?= htmlspecialchars($bc[1]) ?>"><?= htmlspecialchars($bc[0]) ?></a><?php else: ?><span><?= htmlspecialchars($bc[0]) ?></span><?php endif; ?>
                <?php endforeach; ?>
            </nav>
            <?php endif; ?>
        </div>
        <div class="th-right">
            <?php if (!isDeliveryStaff()): ?><span class="scanner-indicator"><i class="fas fa-barcode"></i> Scanner</span><?php endif; ?>
            <?php
            $profileRoleLabel = match ((string) ($user['role'] ?? '')) {
                'admin' => 'Super Admin',
                'manager' => 'Manager',
                'finance' => 'Finance Officer',
                'delivery' => 'Delivery Staff',
                'store_manager' => 'Store Manager',
                'customer' => 'Customer',
                'salesperson' => 'Salesperson',
                default => ucfirst((string) ($user['role'] ?: 'User')),
            };
            $profileName = (string) ($user['full_name'] ?? $user['username'] ?? 'User');
            $mi = function (string $href, string $icon, string $label, int $badge = 0, bool $danger = false): string {
                return '<a class="th-menu-item' . ($danger ? ' is-danger' : '') . '" role="menuitem" href="' . htmlspecialchars($href) . '">'
                    . '<i class="fas ' . $icon . '" aria-hidden="true"></i>'
                    . '<span class="th-menu-text">' . $label . '</span>'
                    . ($badge > 0 ? '<span class="th-menu-badge">' . $badge . '</span>' : '')
                    . '</a>';
            };
            $msgHref = isCustomer() ? '?page=messages' : '?page=admin_messages';
            $helpHref = isCustomer() ? '?page=help_center' : '?page=info&slug=company-services';

            $gProfile = (isCustomer() ? $mi('?page=customer_settings', 'fa-user', 'My Profile') : '')
                . $mi('?page=change_password', 'fa-key', 'Account');

            $gComms = (isCustomer() || hasPermission('users', 'view') ? $mi($msgHref, 'fa-envelope', 'Messages', $unreadMsgCount) : '')
                . (hasPermission('finance_dashboard', 'view') ? $mi('?page=finance_dashboard', 'fa-bell', 'Notifications', $finUnread) : '');

            $gSupport = $mi($helpHref, 'fa-question-circle', 'Help Center')
                . (isCustomer() ? '' :
                    $mi('?page=info&slug=frequently-asked-questions', 'fa-book', 'FAQ')
                    . $mi('?page=info&slug=contact-information', 'fa-envelope', 'Contact Support')
                    . $mi('?page=info&slug=privacy-policy', 'fa-shield-alt', 'Privacy Policy')
                    . $mi('?page=info&slug=terms-and-conditions', 'fa-file-contract', 'Terms &amp; Conditions'));

            $gSystem = (hasPermission('settings', 'view') ? $mi('?page=settings', 'fa-cog', 'Settings') : '')
                . '<button type="button" class="th-menu-item" role="menuitem" id="th-dark-toggle"><i class="fas fa-moon" aria-hidden="true"></i><span class="th-menu-text">Dark Mode</span></button>';

            $gSecurity = $mi('?page=logout', 'fa-sign-out-alt', 'Logout', 0, true);
            ?>
            <div class="th-profile">
                <button type="button" class="th-profile-btn" id="th-profile-btn" title="Account menu" aria-haspopup="true" aria-expanded="false" aria-controls="th-profile-menu">
                    <i class="fas fa-user-circle" aria-hidden="true"></i>
                    <span class="th-profile-name"><?= htmlspecialchars($profileName) ?></span>
                    <i class="fas fa-chevron-down th-profile-caret" aria-hidden="true"></i>
                </button>
                <div class="th-menu" id="th-profile-menu" role="menu" aria-labelledby="th-profile-btn" hidden>
                    <div class="th-menu-head" role="presentation">
                        <i class="fas fa-user-circle" aria-hidden="true"></i>
                        <span class="th-menu-id">
                            <strong><?= htmlspecialchars($profileName) ?></strong>
                            <small><?= htmlspecialchars($profileRoleLabel) ?></small>
                        </span>
                    </div>

                    <div class="th-menu-group" role="group" aria-label="Profile">
                        <div class="th-menu-label">Profile</div>
                        <?= $gProfile ?>
                    </div>

                    <?php if ($gComms !== ''): ?>
                    <div class="th-menu-group" role="group" aria-label="Communication">
                        <div class="th-menu-label">Communication</div>
                        <?= $gComms ?>
                    </div>
                    <?php endif; ?>

                    <div class="th-menu-group" role="group" aria-label="Support">
                        <div class="th-menu-label">Support</div>
                        <?= $gSupport ?>
                    </div>

                    <div class="th-menu-group" role="group" aria-label="System">
                        <div class="th-menu-label">System</div>
                        <?= $gSystem ?>
                    </div>

                    <div class="th-menu-group" role="group" aria-label="Security">
                        <div class="th-menu-label">Security</div>
                        <?= $gSecurity ?>
                    </div>
                </div>
            </div>
        </div>
    </header>
    <main class="main-content">
<?php else: ?>
<div class="main-wrapper main-wrapper-full">
    <main class="main-content">
<?php endif; ?>
