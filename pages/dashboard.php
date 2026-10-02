<?php
$pdo = getPDO();

if (getUserRoleId() === 5) {
    require_once __DIR__ . '/store_dashboard.php';
    return;
}

if (isFinanceOfficer()) {
    require_once __DIR__ . '/finance_dashboard.php';
    return;
}

if (isCustomer()) {
    require_once __DIR__ . '/customer_dashboard.php';
    return;
}

$user = currentUser();
$dashUid = (int) ($user['id'] ?? 0);
$dashName = trim($user['full_name'] ?? '') !== '' ? $user['full_name'] : ($user['username'] ?? 'User');
$dashFirstName = explode(' ', $dashName)[0];
$dashRoleId = getUserRoleId();

// ── Permission flags (drive role-based visibility) ─────────────────────────
$canProducts  = hasPermission('products', 'view');
$canStock     = hasPermission('stock', 'view');
$canExpiry    = hasPermission('expiry_management', 'view') || hasPermission('expiry_alerts', 'view') || hasPermission('batch_expiry', 'view');
$canOrders    = hasPermission('sales', 'view') || hasPermission('customer_order_queue', 'view') || hasPermission('order_fulfillment', 'view');
$canPO        = hasPermission('purchase_orders', 'view');
$canDelivery  = hasPermission('delivery_tracking', 'view') || hasPermission('delivery_dashboard', 'view') || hasPermission('assign_deliveries', 'view');
$canCredit    = hasPermission('credit_management', 'view') || hasPermission('credit_control', 'view') || isAdmin();
$canReports   = hasPermission('reports', 'view');
$canStockMove = hasPermission('stock_movements', 'view');
$canFinance   = isAdmin() || isFinanceOfficer() || hasPermission('finance_dashboard', 'view');
$canApprovals = isAdmin() || hasPermission('customer_order_queue', 'view');

$isAdminUser = isAdmin();

// ── KPI + Inventory queries ────────────────────────────────────────────────
$dashProducts = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE product_status='active'")->fetchColumn();
$dashLowStock = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE product_status='active' AND stock_quantity > 0 AND stock_quantity <= COALESCE(NULLIF(reorder_level,0),10)")->fetchColumn();
$dashOutStock = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE product_status='active' AND stock_quantity <= 0")->fetchColumn();

// Available stock = total sellable units. products.stock_quantity is kept in sync
// with active batch quantities (recalc_product_stock) and also covers products that
// are not batch-tracked, matching the stock page / catalog totals exactly.
$dashAvailUnits = (int) $pdo->query("SELECT COALESCE(SUM(stock_quantity),0) FROM products WHERE product_status='active'")->fetchColumn();

// Non-sellable breakdown comes from batch statuses (expired/quarantined/disposed excluded from available).
$batchStats = [];
foreach ($pdo->query("SELECT status, COUNT(*) AS batches, COUNT(DISTINCT product_id) AS products, COALESCE(SUM(quantity),0) AS units FROM product_batches WHERE quantity > 0 GROUP BY status") as $row) {
    $batchStats[$row['status']] = $row;
}
$dashQuarUnits  = (int) ($batchStats['quarantined']['units'] ?? 0);
$dashQuarCount  = (int) ($batchStats['quarantined']['batches'] ?? 0);
$dashExpiredUnits = (int) ($batchStats['expired']['units'] ?? 0);
$dashExpiredCount = (int) ($batchStats['expired']['batches'] ?? 0);
// Disposal history is authoritative in product_disposals (batch quantity is zeroed after disposal).
$dashDisposedUnits = (int) $pdo->query("SELECT COALESCE(SUM(quantity),0) FROM product_disposals")->fetchColumn();

// Expiry buckets from real batch expiry dates (active batches only, quantity > 0).
$expBuckets = ['expired' => ['batches' => 0, 'products' => 0, 'units' => 0], 'd30' => ['batches' => 0, 'products' => 0, 'units' => 0], 'd60' => ['batches' => 0, 'products' => 0, 'units' => 0], 'd90' => ['batches' => 0, 'products' => 0, 'units' => 0], 'beyond' => ['batches' => 0, 'products' => 0, 'units' => 0]];
foreach ($pdo->query("SELECT CASE WHEN expiry_date < CURDATE() THEN 'expired' WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'd30' WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY) THEN 'd60' WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN 'd90' ELSE 'beyond' END AS bucket, COUNT(*) AS batches, COUNT(DISTINCT product_id) AS products, COALESCE(SUM(quantity),0) AS units FROM product_batches WHERE quantity > 0 AND status='active' GROUP BY bucket") as $row) {
    if (isset($expBuckets[$row['bucket']])) {
        $expBuckets[$row['bucket']] = ['batches' => (int)$row['batches'], 'products' => (int)$row['products'], 'units' => (int)$row['units']];
    }
}
$dashExpiring90 = $expBuckets['d30']['batches'] + $expBuckets['d60']['batches'] + $expBuckets['d90']['batches'];

// ── Orders / Procurement / Delivery / Credit ───────────────────────────────
$dashPendingOrders   = (int) $pdo->query("SELECT COUNT(*) FROM customer_orders WHERE status='pending'")->fetchColumn();
$dashUnconfirmedPay  = (int) $pdo->query("SELECT COUNT(*) FROM customer_orders WHERE status='approved' AND payment_confirmed=0")->fetchColumn();
$dashPendingPOs      = (int) $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('pending','approved','payment_pending','payment_approved')")->fetchColumn();
$dashPOFinance       = (int) $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status='payment_pending'")->fetchColumn();
$dashPOReceive       = (int) $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status='payment_approved'")->fetchColumn();
$dashPORecent        = (int) $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status='received' AND received_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();

$dashPendingDeliveries = (int) $pdo->query("SELECT COUNT(*) FROM customer_orders WHERE status='approved' AND COALESCE(delivery_status,'') NOT IN ('delivered','failed','returned')")->fetchColumn();
$dashDeliveredToday    = (int) $pdo->query("SELECT COUNT(*) FROM customer_orders WHERE delivery_status='delivered' AND DATE(COALESCE(delivered_at, created_at))=CURDATE()")->fetchColumn();

$deliveryRows = [];
foreach ($pdo->query("SELECT COALESCE(delivery_status,'') AS ds, COUNT(*) AS c FROM customer_orders WHERE status='approved' GROUP BY ds") as $r) {
    $deliveryRows[$r['ds']] = (int) $r['c'];
}

$dashCreditOutstanding = (float) $pdo->query("SELECT COALESCE(SUM(outstanding_balance),0) FROM invoices WHERE payment_status IN ('unpaid','partial')")->fetchColumn();
$dashCreditOverdue     = (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE payment_status IN ('unpaid','partial') AND credit_due_date IS NOT NULL AND credit_due_date < CURDATE()")->fetchColumn();

$dashPendingTransfers = 0;
if ($isAdminUser) {
    try { $dashPendingTransfers = (int) $pdo->query("SELECT COUNT(*) FROM bank_transfers WHERE status='submitted'")->fetchColumn(); } catch (Exception $e) {}
}

// ── Sales Overview (real data from sales ledger) ───────────────────────────
$dashSalesToday = $pdo->query("SELECT COUNT(*) AS c, COALESCE(SUM(total_amount),0) AS t FROM sales WHERE sale_date=CURDATE()")->fetch();
$dashSalesWeek  = $pdo->query("SELECT COUNT(*) AS c, COALESCE(SUM(total_amount),0) AS t FROM sales WHERE sale_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND CURDATE()")->fetch();
$dashSalesMonth = $pdo->query("SELECT COUNT(*) AS c, COALESCE(SUM(total_amount),0) AS t FROM sales WHERE YEAR(sale_date)=YEAR(CURDATE()) AND MONTH(sale_date)=MONTH(CURDATE())")->fetch();

$salesRanges = [
    'today' => ['labels' => [date('D, M j')], 'totals' => [(float)$dashSalesToday['t']], 'counts' => [(int)$dashSalesToday['c']]],
    'week'  => ['labels' => [], 'totals' => [], 'counts' => []],
    'month' => ['labels' => [], 'totals' => [], 'counts' => []],
];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $salesRanges['week']['labels'][] = date('D j', strtotime("-$i days"));
    $salesRanges['week']['totals'][] = 0;
    $salesRanges['week']['counts'][] = 0;
}
foreach ($pdo->query("SELECT sale_date, COUNT(*) AS c, COALESCE(SUM(total_amount),0) AS t FROM sales WHERE sale_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND CURDATE() GROUP BY sale_date") as $r) {
    $idx = 6 - (int)((strtotime(date('Y-m-d')) - strtotime($r['sale_date'])) / 86400);
    if (isset($salesRanges['week']['totals'][$idx])) {
        $salesRanges['week']['totals'][$idx] = (float)$r['t'];
        $salesRanges['week']['counts'][$idx] = (int)$r['c'];
    }
}
$lastDay = (int) date('j');
for ($d = 1; $d <= $lastDay; $d++) {
    $salesRanges['month']['labels'][] = (string) $d;
    $salesRanges['month']['totals'][] = 0;
    $salesRanges['month']['counts'][] = 0;
}
foreach ($pdo->query("SELECT DAY(sale_date) AS d, COUNT(*) AS c, COALESCE(SUM(total_amount),0) AS t FROM sales WHERE YEAR(sale_date)=YEAR(CURDATE()) AND MONTH(sale_date)=MONTH(CURDATE()) GROUP BY DAY(sale_date)") as $r) {
    $idx = (int)$r['d'] - 1;
    if (isset($salesRanges['month']['totals'][$idx])) {
        $salesRanges['month']['totals'][$idx] = (float)$r['t'];
        $salesRanges['month']['counts'][$idx] = (int)$r['c'];
    }
}
$salesStats = [
    'today' => ['total' => (float)$dashSalesToday['t'], 'count' => (int)$dashSalesToday['c']],
    'week'  => ['total' => (float)$dashSalesWeek['t'], 'count' => (int)$dashSalesWeek['c']],
    'month' => ['total' => (float)$dashSalesMonth['t'], 'count' => (int)$dashSalesMonth['c']],
];

// ── Growth comparisons (current vs previous period) ────────────────────────
$dashPrevWeek  = (float) $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 13 DAY) AND DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
$dashPrevMonth = (float) $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH) AND sale_date < DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();
$calcGrowth = function (float $cur, float $prev): ?int {
    if ($prev <= 0) { return null; }
    return (int) round(($cur - $prev) / $prev * 100);
};
$wkGrowth = $calcGrowth((float)$dashSalesWeek['t'], $dashPrevWeek);
$moGrowth = $calcGrowth((float)$dashSalesMonth['t'], $dashPrevMonth);

$dashOrdersToday = (int) $pdo->query("SELECT COUNT(*) FROM customer_orders WHERE DATE(created_at)=CURDATE()")->fetchColumn();

// ── Top sellers / top customers (last 30 days, finalized sales only) ───────
$topProducts = $pdo->query("
    SELECT p.id, p.name, SUM(coi.quantity) AS qty, SUM(coi.quantity * coi.unit_price) AS revenue
    FROM customer_order_items coi
    JOIN customer_orders co ON co.id = coi.customer_order_id
    JOIN products p ON p.id = coi.product_id
    WHERE co.sale_id IS NOT NULL AND co.order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY p.id, p.name ORDER BY qty DESC LIMIT 6
")->fetchAll();

$topCustomers = $pdo->query("
    SELECT c.id, c.name, COUNT(*) AS orders, SUM(co.total_amount) AS total
    FROM customer_orders co
    JOIN customers c ON c.id = co.customer_id
    WHERE co.sale_id IS NOT NULL AND co.order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY c.id, c.name ORDER BY total DESC LIMIT 5
")->fetchAll();

$lowStockList = $pdo->query("
    SELECT id, name, sku, stock_quantity, reorder_level
    FROM products
    WHERE product_status='active' AND stock_quantity <= COALESCE(NULLIF(reorder_level,0),10)
    ORDER BY stock_quantity ASC, name LIMIT 8
")->fetchAll();

// ── Expected Profit (current sellable inventory margin) ────────────────────
// Only active batches with qty > 0; excludes expired / quarantined / disposed.
$dashExpectedProfit = (float) $pdo->query("
    SELECT COALESCE(SUM((p.price - pb.purchase_price) * pb.quantity), 0)
    FROM product_batches pb
    JOIN products p ON pb.product_id = p.id
    WHERE pb.status = 'active'
      AND pb.quantity > 0
      AND p.product_status = 'active'
")->fetchColumn();

// ── Realized Profit (all-time from fulfilled sales) ────────────────────────
// Revenue: fulfilled orders (sale_id NOT NULL = sale record created at finalization).
$dashRealizedRevenue = (float) $pdo->query("
    SELECT COALESCE(SUM(co.total_amount), 0)
    FROM customer_orders co
    WHERE co.sale_id IS NOT NULL
")->fetchColumn();
// COGS: order items × product purchase_price (matches finGetMonthCOGS pattern).
$dashRealizedCOGS = (float) $pdo->query("
    SELECT COALESCE(SUM(coi.quantity * p.purchase_price), 0)
    FROM customer_order_items coi
    JOIN customer_orders co ON coi.customer_order_id = co.id
    JOIN products p ON coi.product_id = p.id
    WHERE co.sale_id IS NOT NULL
")->fetchColumn();
// Returns: confirmed returns deducted from realized profit.
$dashRealizedReturns = (float) $pdo->query("
    SELECT COALESCE(SUM(sr.total_amount), 0)
    FROM sales_returns sr
    WHERE sr.status = 'returned'
")->fetchColumn();
$dashRealizedProfit = $dashRealizedRevenue - $dashRealizedCOGS - $dashRealizedReturns;

// ── Financial snapshot (bank + cash) ───────────────────────────────────────
$dashBankBalance = 0.0; $dashCashBalance = 0.0; $dashBankAccounts = []; $dashCashAccounts = [];
if ($canFinance) {
    try {
        $dashBankBalance = (float) $pdo->query("SELECT COALESCE(SUM(balance),0) FROM bank_accounts")->fetchColumn();
        $dashCashBalance = (float) $pdo->query("SELECT COALESCE(SUM(balance),0) FROM cash_accounts")->fetchColumn();
        $dashBankAccounts = $pdo->query("SELECT bank_name, account_name, balance, status FROM bank_accounts WHERE status IN ('active','frozen') ORDER BY bank_name")->fetchAll();
        $dashCashAccounts = $pdo->query("SELECT account_name, balance FROM cash_accounts WHERE status='active' ORDER BY account_name")->fetchAll();
    } catch (Exception $e) {}
}

// ── Recent orders + stock movements ────────────────────────────────────────
$recentOrders = $pdo->query("SELECT co.*, c.name AS customer FROM customer_orders co LEFT JOIN customers c ON c.id=co.customer_id ORDER BY co.created_at DESC LIMIT 8")->fetchAll();
$recentMovements = $pdo->query("SELECT sm.*, p.name AS product_name FROM stock_movements sm LEFT JOIN products p ON sm.product_id=p.id ORDER BY sm.created_at DESC LIMIT 10")->fetchAll();

// ── Alerts (only nonzero, permission-aware) ────────────────────────────────
$alerts = [];
if ($canExpiry && $dashExpiredCount > 0) $alerts[] = ['tone' => 'red', 'icon' => 'fa-skull-crossbones', 'title' => $dashExpiredCount . ' Expired Batches', 'desc' => 'Require quarantine / disposal action', 'url' => 'index.php?page=expiry_management&tab=expired'];
if ($canExpiry && $dashQuarCount > 0) $alerts[] = ['tone' => 'red', 'icon' => 'fa-biohazard', 'title' => $dashQuarCount . ' Quarantined Batches', 'desc' => 'Awaiting review or disposal', 'url' => 'index.php?page=expiry_management&tab=quarantine'];
if ($canExpiry && $expBuckets['d30']['batches'] > 0) $alerts[] = ['tone' => 'orange', 'icon' => 'fa-clock', 'title' => $expBuckets['d30']['batches'] . ' Batches Expiring in 30 Days', 'desc' => 'Prioritise sales / redistribution', 'url' => 'index.php?page=expiry_management&tab=expiring'];
if ($canStock && $dashLowStock > 0) $alerts[] = ['tone' => 'orange', 'icon' => 'fa-boxes-stacked', 'title' => $dashLowStock . ' Products Below Reorder Level', 'desc' => 'Consider creating purchase orders', 'url' => 'index.php?page=stock'];
if ($canStock && $dashOutStock > 0) $alerts[] = ['tone' => 'red', 'icon' => 'fa-box-open', 'title' => $dashOutStock . ' Products Out of Stock', 'desc' => 'Restock to avoid lost sales', 'url' => 'index.php?page=out_of_stock_queue'];
if ($canPO && $dashPOFinance > 0) $alerts[] = ['tone' => 'orange', 'icon' => 'fa-money-check-dollar', 'title' => $dashPOFinance . ' Purchase Orders Awaiting Finance Approval', 'desc' => 'Review payment requests', 'url' => 'index.php?page=purchase_payment_approval'];
if ($canPO && $dashPOReceive > 0) $alerts[] = ['tone' => 'blue', 'icon' => 'fa-truck-ramp-box', 'title' => $dashPOReceive . ' Purchase Orders Awaiting Receiving', 'desc' => 'Goods expected — receive into stock', 'url' => 'index.php?page=goods_receiving'];
if ($canOrders && $dashPendingOrders > 0) $alerts[] = ['tone' => 'orange', 'icon' => 'fa-clipboard-list', 'title' => $dashPendingOrders . ' Customer Orders Awaiting Approval', 'desc' => 'Review and approve customer orders', 'url' => 'index.php?page=customer_order_queue'];
if ($canDelivery && $dashPendingDeliveries > 0) $alerts[] = ['tone' => 'blue', 'icon' => 'fa-truck', 'title' => $dashPendingDeliveries . ' Deliveries Awaiting Completion', 'desc' => 'Assign or track pending deliveries', 'url' => 'index.php?page=delivery_assign'];
if ($canApprovals && $dashUnconfirmedPay > 0) $alerts[] = ['tone' => 'blue', 'icon' => 'fa-credit-card', 'title' => $dashUnconfirmedPay . ' Payments Awaiting Confirmation', 'desc' => 'Confirm customer payment screenshots', 'url' => 'index.php?page=finance_payments'];
if ($canCredit && $dashCreditOutstanding > 0) $alerts[] = ['tone' => 'red', 'icon' => 'fa-file-invoice-dollar', 'title' => 'Customer Credit Outstanding ' . fmt($dashCreditOutstanding), 'desc' => ($dashCreditOverdue > 0 ? $dashCreditOverdue . ' overdue invoices' : 'Open balances awaiting settlement'), 'url' => 'index.php?page=credit_management'];
if ($isAdminUser && $dashPendingTransfers > 0) $alerts[] = ['tone' => 'purple', 'icon' => 'fa-arrow-right-arrow-left', 'title' => $dashPendingTransfers . ' Bank Transfer(s) Awaiting Approval', 'desc' => 'Approve or reject submitted transfers', 'url' => 'index.php?page=finance_bank&tab=transfers&tf_status=submitted'];

// ── Hero greeting + live tiles ─────────────────────────────────────────────
$dHour = (int) date('G');
$dGreeting = $dHour < 12 ? 'Good morning' : ($dHour < 17 ? 'Good afternoon' : 'Good evening');
$dGreetIcon = $dHour < 12 ? 'fa-sun' : ($dHour < 17 ? 'fa-cloud-sun' : 'fa-moon');

$heroTiles = [];
if ($canReports || $canFinance) $heroTiles[] = ['label' => 'Sales Today', 'value' => fmt((float)$dashSalesToday['t']), 'icon' => 'fa-sack-dollar', 'url' => 'index.php?page=sales'];
if ($canOrders) $heroTiles[] = ['label' => 'Orders Today', 'value' => number_format($dashOrdersToday), 'icon' => 'fa-cart-plus', 'url' => 'index.php?page=customer_order_queue'];
if ($canApprovals) $heroTiles[] = ['label' => 'Pending Approval', 'value' => number_format($dashPendingOrders), 'icon' => 'fa-hourglass-half', 'url' => 'index.php?page=customer_order_queue'];
if ($canExpiry) $heroTiles[] = ['label' => 'Expiring ≤ 30d', 'value' => number_format($expBuckets['d30']['batches']), 'icon' => 'fa-clock', 'url' => 'index.php?page=expiry_management&tab=expiring'];
if ($canStock && count($heroTiles) < 4) $heroTiles[] = ['label' => 'Low Stock Items', 'value' => number_format($dashLowStock + $dashOutStock), 'icon' => 'fa-boxes-stacked', 'url' => 'index.php?page=stock'];

// ── KPI cards ──────────────────────────────────────────────────────────────
$kpis = [];
if ($canProducts) $kpis[] = ['label' => 'Total Products', 'value' => number_format($dashProducts), 'icon' => 'fa-capsules', 'tone' => 'navy', 'sub' => 'Active SKUs', 'url' => 'index.php?page=products'];
if ($canStock) $kpis[] = ['label' => 'Low Stock', 'value' => number_format($dashLowStock), 'icon' => 'fa-boxes-stacked', 'tone' => 'orange', 'sub' => 'Below reorder level', 'url' => 'index.php?page=stock'];
if ($canExpiry) $kpis[] = ['label' => 'Expiring Soon', 'value' => number_format($dashExpiring90), 'icon' => 'fa-clock', 'tone' => 'orange', 'sub' => 'Within 90 days', 'url' => 'index.php?page=expiry_management&tab=expiring'];
if ($canExpiry) $kpis[] = ['label' => 'Expired Products', 'value' => number_format($dashExpiredCount), 'icon' => 'fa-skull-crossbones', 'tone' => 'red', 'sub' => $dashExpiredUnits > 0 ? number_format($dashExpiredUnits) . ' units' : 'No expired stock', 'url' => 'index.php?page=expiry_management&tab=expired'];
if ($canOrders) $kpis[] = ['label' => 'Pending Orders', 'value' => number_format($dashPendingOrders), 'icon' => 'fa-clipboard-list', 'tone' => 'navy', 'sub' => 'Awaiting approval', 'url' => 'index.php?page=customer_order_queue'];
if ($canPO) $kpis[] = ['label' => 'Pending Purchase Orders', 'value' => number_format($dashPendingPOs), 'icon' => 'fa-file-invoice', 'tone' => 'green', 'sub' => 'In procurement pipeline', 'url' => 'index.php?page=purchase_orders'];
if ($canDelivery) $kpis[] = ['label' => 'Pending Deliveries', 'value' => number_format($dashPendingDeliveries), 'icon' => 'fa-truck', 'tone' => 'green', 'sub' => 'In transit / awaiting dispatch', 'url' => 'index.php?page=delivery_assign'];
if ($canCredit) $kpis[] = ['label' => 'Outstanding Credit', 'value' => fmt($dashCreditOutstanding), 'icon' => 'fa-file-invoice-dollar', 'tone' => 'red', 'sub' => $dashCreditOverdue > 0 ? $dashCreditOverdue . ' overdue' : 'Customer balances', 'url' => 'index.php?page=credit_management'];
if ($canReports) $kpis[] = ['label' => 'Expected Profit', 'value' => fmt($dashExpectedProfit), 'icon' => 'fa-chart-line', 'tone' => 'green', 'sub' => 'On current sellable stock', 'url' => 'index.php?page=reports'];
if ($canReports) $kpis[] = ['label' => 'Realized Profit', 'value' => fmt($dashRealizedProfit), 'icon' => 'fa-sack-dollar', 'tone' => 'purple', 'sub' => 'Actual sales margin (all time)', 'url' => 'index.php?page=reports'];

// ── Quick actions ──────────────────────────────────────────────────────────
$quickActions = [];
if (hasPermission('products', 'create')) $quickActions[] = ['label' => 'Add Product', 'icon' => 'fa-plus', 'tone' => 'navy', 'url' => 'index.php?page=products&add=1'];
if (hasPermission('purchase_orders', 'create')) $quickActions[] = ['label' => 'Create Purchase Order', 'icon' => 'fa-file-invoice', 'tone' => 'navy', 'url' => 'index.php?page=purchase_orders'];
if (hasPermission('goods_receiving', 'edit')) $quickActions[] = ['label' => 'Receive Goods', 'icon' => 'fa-truck-ramp-box', 'tone' => 'green', 'url' => 'index.php?page=goods_receiving'];
if (hasPermission('customer_order_queue', 'view') || hasPermission('sales', 'create')) $quickActions[] = ['label' => 'Customer Order', 'icon' => 'fa-cart-plus', 'tone' => 'green', 'url' => 'index.php?page=customer_order_queue'];
if ($canStock) $quickActions[] = ['label' => 'View Stock', 'icon' => 'fa-boxes', 'tone' => 'navy', 'url' => 'index.php?page=stock'];
if ($canReports) $quickActions[] = ['label' => 'View Reports', 'icon' => 'fa-chart-line', 'tone' => 'purple', 'url' => 'index.php?page=reports'];

// ── Operations hub links ───────────────────────────────────────────────────
$opsLinks = [];
if ($canProducts) $opsLinks[] = ['Products', 'fa-capsules', 'index.php?page=products'];
if ($canPO) $opsLinks[] = ['Purchase Orders', 'fa-file-invoice', 'index.php?page=purchase_orders'];
if (hasPermission('goods_receiving', 'edit')) $opsLinks[] = ['Goods Receiving', 'fa-truck-ramp-box', 'index.php?page=goods_receiving'];
if ($canOrders) $opsLinks[] = ['Order Fulfillment', 'fa-clipboard-check', 'index.php?page=order_fulfillment'];
if ($canDelivery) $opsLinks[] = ['Deliveries', 'fa-truck-fast', 'index.php?page=delivery_assign'];
if ($canCredit) $opsLinks[] = ['Credit Management', 'fa-file-invoice-dollar', 'index.php?page=credit_management'];
if ($canFinance) $opsLinks[] = ['Finance Center', 'fa-piggy-bank', 'index.php?page=finance_dashboard'];
if ($canReports) $opsLinks[] = ['Reports', 'fa-chart-line', 'index.php?page=reports'];

$dashRoleLabel = match ((string) ($user['role'] ?? '')) { 'admin' => 'Super Admin', 'manager' => 'Manager', 'finance' => 'Finance Officer', 'delivery' => 'Delivery Staff', 'store_manager' => 'Store Manager', 'customer' => 'Customer', 'salesperson' => 'Salesperson', default => ucfirst((string) ($user['role'] ?: 'User')) };
?>
<section class="dash">
<style>
.dash{font-family:inherit;color:#1f2937}
.dash *{box-sizing:border-box}
@keyframes dashFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}

/* ── Hero banner ── */
.dash-hero{position:relative;overflow:hidden;display:flex;justify-content:space-between;align-items:center;gap:20px;background:linear-gradient(130deg,#0b1f3f 0%,#16337a 48%,#1d4ed8 100%);border-radius:16px;padding:20px 24px;margin-bottom:16px;color:#fff;box-shadow:0 12px 32px -14px rgba(29,78,216,.5);animation:dashFadeUp .35s ease both}
.dash-hero::before,.dash-hero::after{content:"";position:absolute;border-radius:50%;pointer-events:none}
.dash-hero::before{width:240px;height:240px;top:-100px;right:-70px;background:rgba(255,255,255,.07)}
.dash-hero::after{width:160px;height:160px;bottom:-80px;left:34%;background:rgba(255,255,255,.05)}
.dh-left{position:relative;z-index:1;min-width:0}
.dh-hello{display:flex;align-items:center;gap:12px}
.dh-hello .dh-brand{width:42px;height:42px;object-fit:contain;border-radius:10px;background:#fff;padding:3px;box-shadow:0 2px 10px rgba(0,0,0,.22);flex-shrink:0}
.dh-hello .dh-greet-ic{width:38px;height:38px;flex:0 0 38px;border-radius:11px;background:rgba(255,255,255,.16);display:flex;align-items:center;justify-content:center;font-size:1rem}
.dh-hello h1{margin:0;font-size:1.3rem;font-weight:800;letter-spacing:-.01em;color:#fff;line-height:1.2}
.dh-sub{margin:5px 0 0;font-size:.78rem;color:rgba(255,255,255,.75)}
.dh-chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.dh-chip{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:999px;padding:4px 11px;font-size:.68rem;font-weight:600;color:#fff;white-space:nowrap}
.dh-chip i{opacity:.85}
.dh-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.dh-actions a{display:inline-flex;align-items:center;gap:7px;font-size:.72rem;font-weight:700;color:#fff;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.24);border-radius:9px;padding:7px 13px;text-decoration:none;transition:background .15s,transform .15s;white-space:nowrap}
.dh-actions a:hover{background:rgba(255,255,255,.26);transform:translateY(-1px);color:#fff}
.dh-tiles{position:relative;z-index:1;display:grid;grid-template-columns:repeat(2,minmax(122px,1fr));gap:9px;flex:0 0 auto}
.dh-tile{display:flex;flex-direction:column;gap:3px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.17);border-radius:12px;padding:11px 13px;text-decoration:none;color:#fff;transition:background .15s,transform .15s;min-width:0}
.dh-tile:hover{background:rgba(255,255,255,.19);transform:translateY(-2px);color:#fff}
.dh-tile .ic{font-size:.74rem;color:rgba(255,255,255,.85)}
.dh-tile .v{font-size:1.05rem;font-weight:800;line-height:1.15;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-variant-numeric:tabular-nums}
.dh-tile .l{font-size:.61rem;color:rgba(255,255,255,.72);text-transform:uppercase;letter-spacing:.05em;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* ── KPI cards ── */
.dash-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px;margin-bottom:16px}
.dash-kpi{display:flex;flex-direction:column;gap:0;background:#fff;border:1px solid #eef0f3;border-radius:14px;padding:14px 15px 13px;text-decoration:none;color:inherit;box-shadow:0 1px 3px rgba(15,23,42,.05);border-top:3px solid var(--kc,#2563eb);transition:transform .15s ease,box-shadow .15s ease;animation:dashFadeUp .4s ease both;min-width:0;min-height:128px}
.dash-kpi:nth-child(2){animation-delay:.04s}.dash-kpi:nth-child(3){animation-delay:.08s}.dash-kpi:nth-child(4){animation-delay:.12s}.dash-kpi:nth-child(5){animation-delay:.16s}.dash-kpi:nth-child(6){animation-delay:.2s}.dash-kpi:nth-child(7){animation-delay:.24s}.dash-kpi:nth-child(8){animation-delay:.28s}.dash-kpi:nth-child(9){animation-delay:.32s}.dash-kpi:nth-child(10){animation-delay:.36s}
.dash-kpi:hover{transform:translateY(-3px);box-shadow:0 10px 24px -8px rgba(15,23,42,.14);color:inherit}
.dash-kpi-top{display:flex;flex-direction:column;align-items:flex-start;gap:0}
.dash-kpi-ic{width:36px;height:36px;flex:0 0 36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:.95rem;color:#fff;background:var(--kc,#2563eb);box-shadow:0 4px 10px -3px var(--kc,#2563eb)}
.dash-kpi-val{font-size:1.28rem;font-weight:800;color:#111827;line-height:1.15;letter-spacing:-.02em;margin-top:10px;font-variant-numeric:tabular-nums;overflow-wrap:anywhere;word-break:break-word}
.dash-kpi-label{font-size:.75rem;font-weight:700;color:#374151;margin-top:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dash-kpi-sub{font-size:.66rem;color:#9ca3af;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dash-kpi.t-navy{--kc:#2563eb}.dash-kpi.t-navy .dash-kpi-ic{background:#1e40af}
.dash-kpi.t-green{--kc:#059669}.dash-kpi.t-green .dash-kpi-ic{background:#047857}
.dash-kpi.t-orange{--kc:#d97706}.dash-kpi.t-orange .dash-kpi-ic{background:#b45309}
.dash-kpi.t-red{--kc:#dc2626}.dash-kpi.t-red .dash-kpi-ic{background:#b91c1c}
.dash-kpi.t-purple{--kc:#7c3aed}.dash-kpi.t-purple .dash-kpi-ic{background:#6d28d9}
.dash-kpi.t-blue{--kc:#2563eb}.dash-kpi.t-blue .dash-kpi-ic{background:#1d4ed8}

/* ── Panels ── */
.dash-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
.dash-grid-3{display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:16px;margin-bottom:16px}
.dash-col-main{display:flex;flex-direction:column;gap:16px;min-width:0}
.dash-panel{background:#fff;border:1px solid #eef0f3;border-radius:14px;padding:18px;box-shadow:0 1px 3px rgba(15,23,42,.04);animation:dashFadeUp .4s ease both}
.dash-panel:hover{box-shadow:0 4px 16px -6px rgba(15,23,42,.1)}
.dash-panel-title{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px;flex-wrap:wrap}
.dash-panel-title h2,.dash-panel-title h3{margin:0;font-size:.95rem;font-weight:800;color:#111827;display:flex;align-items:center;gap:8px}
.dash-panel-title h2 i,.dash-panel-title h3 i{color:var(--primary-600,#2563eb)}
.dash-link{font-size:.75rem;font-weight:600;color:var(--primary-600,#2563eb);text-decoration:none;white-space:nowrap}
.dash-link:hover{text-decoration:underline}
.dash-growth{display:inline-flex;align-items:center;gap:4px;font-size:.62rem;font-weight:800;padding:3px 9px;border-radius:999px;margin-left:6px}
.dash-growth.up{background:#ecfdf5;color:#047857}
.dash-growth.down{background:#fef2f2;color:#b91c1c}

/* ── Alerts ── */
.dash-alerts{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}
.dash-alert{display:flex;gap:12px;align-items:flex-start;background:#fff;border:1px solid #eef0f3;border-radius:12px;padding:14px;box-shadow:0 1px 3px rgba(15,23,42,.04);border-left:4px solid #9ca3af;transition:transform .12s,box-shadow .12s}
.dash-alert:hover{transform:translateY(-2px);box-shadow:0 6px 16px -6px rgba(15,23,42,.12)}
.dash-alert .dash-ai{width:38px;height:38px;flex:0 0 38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1rem;color:#fff}
.dash-alert .dash-at{font-size:.82rem;font-weight:800;color:#111827}
.dash-alert .dash-ad{font-size:.72rem;color:#6b7280;margin-top:2px}
.dash-alert .dash-av{margin-top:8px}
.dash-alert.a-red{border-left-color:#dc2626}.a-red .dash-ai{background:#b91c1c}
.dash-alert.a-orange{border-left-color:#d97706}.a-orange .dash-ai{background:#b45309}
.dash-alert.a-blue{border-left-color:#2563eb}.a-blue .dash-ai{background:#1d4ed8}
.dash-alert.a-purple{border-left-color:#7c3aed}.a-purple .dash-ai{background:#6d28d9}
.dash-alert.a-green{border-left-color:#059669}.a-green .dash-ai{background:#047857}
.dash-btn{display:inline-block;font-size:.7rem;font-weight:700;padding:5px 12px;min-height:44px;border-radius:8px;text-decoration:none;color:#1f2937;background:#f3f4f6;border:1px solid #e5e7eb}
.dash-btn:hover{background:#e5e7eb;color:#111827}
.dash-btn.btn-primary{background:#1e40af;border-color:#1e40af;color:#fff}
.dash-btn.btn-primary:hover{background:#1e3a5f}
.dash-btn.btn-outline{background:#fff}
.dash-btn.btn-sm{min-height:36px;padding:6px 12px}

/* ── Stat mini rows ── */
.dash-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-bottom:14px}
.dash-stat{background:#f8fafc;border:1px solid #eef0f3;border-radius:10px;padding:10px 12px}
.dash-stat .v{font-size:1.05rem;font-weight:800;color:#111827}
.dash-stat .l{font-size:.68rem;color:#6b7280;margin-top:2px}
.dash-stat .l i{margin-right:4px;color:#94a3b8}

/* ── Bars ── */
.dash-bars{display:flex;flex-direction:column;gap:12px}
.dash-bar-row .bar-label{display:flex;justify-content:space-between;font-size:.74rem;margin-bottom:5px}
.dash-bar-row .bar-label b{font-weight:700;color:#374151}
.dash-bar-row .bar-label span{color:#6b7280}
.dash-bar-track{height:9px;background:#f1f5f9;border-radius:6px;overflow:hidden}
.dash-bar-fill{height:100%;border-radius:6px;transition:width .5s ease}
.dash-bar-fill.g{background:linear-gradient(90deg,#059669,#34d399)}.dash-bar-fill.o{background:linear-gradient(90deg,#d97706,#fbbf24)}.dash-bar-fill.r{background:linear-gradient(90deg,#dc2626,#f87171)}.dash-bar-fill.n{background:linear-gradient(90deg,#94a3b8,#cbd5e1)}.dash-bar-fill.b{background:linear-gradient(90deg,#2563eb,#60a5fa)}.dash-bar-fill.a{background:linear-gradient(90deg,#ca8a04,#eab308)}

/* ── Inventory split (donut + bars) ── */
.inv-flex{display:flex;gap:18px;align-items:center}
.inv-donut-wrap{position:relative;width:150px;height:150px;flex:0 0 150px}
.inv-donut-center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none}
.inv-donut-center .dv{font-size:1.02rem;font-weight:800;color:#111827;line-height:1}
.inv-donut-center .dl{font-size:.6rem;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-top:3px;font-weight:700}
.donut-legend{display:flex;flex-wrap:wrap;gap:6px 14px;margin-top:12px}
.donut-legend span{display:inline-flex;align-items:center;gap:6px;font-size:.68rem;color:#6b7280;font-weight:600}
.donut-legend span b{color:#111827}
.donut-legend i{width:9px;height:9px;border-radius:3px;display:inline-block}

/* ── Expiry buckets ── */
.dash-expgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px}
.dash-exp{display:block;background:#f8fafc;border:1px solid #eef0f3;border-radius:10px;padding:12px;text-decoration:none;color:inherit;border-top:3px solid #94a3b8;transition:transform .1s}
.dash-exp:hover{transform:translateY(-2px);color:inherit;background:#f1f5f9}
.dash-exp .n{font-size:1.2rem;font-weight:800;color:#111827}
.dash-exp .p{font-size:.78rem;font-weight:600;color:#374151}
.dash-exp .m{font-size:.68rem;color:#9ca3af;margin-top:2px}
.dash-exp.e-red{border-top-color:#dc2626}.e-red .n{color:#b91c1c}
.dash-exp.e-orange{border-top-color:#d97706}.e-orange .n{color:#b45309}
.dash-exp.e-amber{border-top-color:#ca8a04}.e-amber .n{color:#a16207}
.dash-exp.e-green{border-top-color:#059669}.e-green .n{color:#047857}

/* ── Rank lists (top products / customers) ── */
.rank-list{display:flex;flex-direction:column}
.rank-row{display:flex;align-items:center;gap:11px;padding:9px 0;border-bottom:1px dashed #eef0f3}
.rank-row:last-child{border-bottom:none}
.rank-num{width:26px;height:26px;flex:0 0 26px;border-radius:8px;background:#f1f5f9;font-weight:800;font-size:.7rem;display:flex;align-items:center;justify-content:center;color:#475569}
.rank-row:first-child .rank-num{background:linear-gradient(135deg,#f59e0b,#fbbf24);color:#fff}
.rank-main{flex:1;min-width:0}
.rank-name{font-size:.79rem;font-weight:700;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rank-meta{font-size:.66rem;color:#9ca3af;margin-top:1px}
.rank-bar{height:5px;background:#f1f5f9;border-radius:4px;margin-top:6px;overflow:hidden}
.rank-fill{height:100%;border-radius:4px;background:linear-gradient(90deg,#2563eb,#60a5fa)}
.rank-fill.gold{background:linear-gradient(90deg,#d97706,#fbbf24)}
.rank-side{text-align:right;flex:0 0 auto}
.rank-v{font-weight:800;font-size:.8rem;color:#111827;white-space:nowrap}
.rank-s{font-size:.65rem;color:#9ca3af;white-space:nowrap}

/* ── Operations hub ── */
.ops-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px}
.ops-link{display:flex;align-items:center;gap:10px;padding:11px 12px;background:#f8fafc;border:1px solid #eef0f3;border-radius:10px;text-decoration:none;color:#1f2937;font-size:.76rem;font-weight:700;transition:all .13s}
.ops-link:hover{border-color:#bfdbfe;background:#eff6ff;color:#1e40af;transform:translateY(-1px)}
.ops-link i:first-child{width:28px;height:28px;flex:0 0 28px;border-radius:8px;background:#fff;border:1px solid #e5e7eb;display:flex;align-items:center;justify-content:center;font-size:.78rem;color:#2563eb}
.ops-link .ops-arrow{margin-left:auto;font-size:.6rem;color:#cbd5e1}
.ops-link:hover .ops-arrow{color:#1e40af}

/* ── Tables ── */
.dash-tbl-wrap{overflow-x:auto;margin:0 -18px;padding:0 18px}
.dash-table{width:100%;border-collapse:collapse;font-size:.78rem}
.dash-table th{text-align:left;font-size:.68rem;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;font-weight:700;padding:8px 10px;border-bottom:1px solid #e5e7eb;white-space:nowrap}
.dash-table td{padding:10px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.dash-table tbody tr:last-child td{border-bottom:none}
.dash-table tbody tr:hover{background:#f8fafc}
.dash-table .num{font-weight:700;color:#111827;white-space:nowrap}
.dash-empty{padding:26px;text-align:center;color:#9ca3af;font-size:.8rem}
.dash-empty i{display:block;font-size:1.4rem;margin-bottom:6px;color:#cbd5e1}
.dash-empty.ok i{color:#34d399}

/* ── Badges ── */
.dash-badge{display:inline-block;font-size:.66rem;font-weight:700;padding:3px 9px;border-radius:20px;white-space:nowrap}
.b-navy{background:#eff6ff;color:#1e40af}
.b-green{background:#ecfdf5;color:#047857}
.b-orange{background:#fffbeb;color:#b45309}
.b-red{background:#fef2f2;color:#b91c1c}
.b-purple{background:#f5f3ff;color:#6d28d9}
.b-blue{background:#eff6ff;color:#1d4ed8}
.b-amber{background:#fffbeb;color:#a16207}
.b-gray{background:#f3f4f6;color:#4b5563}

.chart-box{position:relative;height:230px}
.chart-box canvas{max-width:100%}
.dash-range{display:inline-flex;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:9px;padding:2px;gap:2px}
.dash-range button{border:none;background:transparent;font-size:.72rem;font-weight:700;color:#6b7280;padding:5px 12px;min-height:36px;border-radius:7px;cursor:pointer;font-family:inherit}
.dash-range button.on{background:#fff;color:#1e40af;box-shadow:0 1px 2px rgba(15,23,42,.08)}

@media(max-width:1280px){.dash-kpis{grid-template-columns:repeat(4,minmax(0,1fr))}}
@media(max-width:1000px){.dash-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:1100px){.dash-grid-3{grid-template-columns:1fr 1fr}}
@media(max-width:980px){.dash-hero{flex-direction:column;align-items:stretch;gap:16px}.dh-tiles{grid-template-columns:repeat(4,minmax(0,1fr))}.inv-flex{flex-direction:column;align-items:stretch}.inv-donut-wrap{margin:0 auto}}
@media(max-width:900px){.dash-grid{grid-template-columns:1fr}}
@media(max-width:860px){.dash-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.dash-kpi{min-height:112px}}
@media(max-width:700px){.dh-tiles{grid-template-columns:repeat(2,minmax(0,1fr))}.ops-grid{grid-template-columns:1fr}}
@media(max-width:560px){.dash-grid-3{grid-template-columns:1fr}}
@media(max-width:420px){.dash-hero{padding:16px;border-radius:13px}.dh-hello h1{font-size:1.12rem}.dh-hello .dh-brand{width:38px;height:38px}.dh-actions a{padding:7px 11px;font-size:.68rem}.dash-kpi{padding:12px 12px 11px;min-height:104px}.dash-kpi-val{font-size:1.12rem}.chart-box{height:200px}}
@media(max-width:360px){.dash-kpi-val{font-size:1.02rem}.dh-chip{font-size:.62rem;padding:4px 9px}}
</style>
<div class="dash-hero">
    <div class="dh-left">
        <div class="dh-hello">
            <img src="<?= htmlspecialchars(brand_logo()) ?>" alt="Logo" class="dh-brand">
            <span class="dh-greet-ic"><i class="fas <?= $dGreetIcon ?>"></i></span>
            <h1><?= htmlspecialchars($dGreeting) ?>, <?= htmlspecialchars($dashFirstName) ?></h1>
        </div>
        <p class="dh-sub">Here's what's happening across your pharmacy today.</p>
        <div class="dh-chips">
            <span class="dh-chip"><i class="far fa-calendar-alt"></i><?= htmlspecialchars(date('l, F j, Y')) ?></span>
            <span class="dh-chip"><i class="fas fa-user-shield"></i><?= htmlspecialchars($dashRoleLabel) ?></span>
        </div>
        <?php if ($quickActions): ?>
        <div class="dh-actions">
            <?php foreach ($quickActions as $qa): ?>
            <a href="<?= htmlspecialchars($qa['url']) ?>"><i class="fas <?= $qa['icon'] ?>"></i><?= htmlspecialchars($qa['label']) ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($heroTiles): ?>
    <div class="dh-tiles">
        <?php foreach ($heroTiles as $t): ?>
        <a class="dh-tile" href="<?= htmlspecialchars($t['url']) ?>">
            <span class="ic"><i class="fas <?= $t['icon'] ?>"></i></span>
            <span class="v"><?= htmlspecialchars($t['value']) ?></span>
            <span class="l"><?= htmlspecialchars($t['label']) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($kpis): ?>
<div class="dash-kpis">
    <?php foreach ($kpis as $k): ?>
    <a href="<?= htmlspecialchars($k['url']) ?>" class="dash-kpi t-<?= $k['tone'] ?>">
        <div class="dash-kpi-top">
            <span class="dash-kpi-ic"><i class="fas <?= $k['icon'] ?>"></i></span>
            <span class="dash-kpi-val"><?= htmlspecialchars($k['value']) ?></span>
        </div>
        <div class="dash-kpi-label"><?= htmlspecialchars($k['label']) ?></div>
        <div class="dash-kpi-sub"><?= htmlspecialchars($k['sub']) ?></div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($alerts): ?>
<div class="dash-panel" style="border-left:3px solid #dc2626;margin-bottom:16px">
    <div class="dash-panel-title">
        <h2><i class="fas fa-exclamation-triangle" style="color:#b45309"></i> Attention Required</h2>
        <span class="dash-badge b-orange"><?= count($alerts) ?> item<?= count($alerts) > 1 ? 's' : '' ?></span>
    </div>
    <div class="dash-alerts">
        <?php foreach ($alerts as $a): ?>
        <div class="dash-alert a-<?= $a['tone'] ?>">
            <span class="dash-ai"><i class="fas <?= $a['icon'] ?>"></i></span>
            <div style="flex:1;min-width:0">
                <div class="dash-at"><?= htmlspecialchars($a['title']) ?></div>
                <div class="dash-ad"><?= htmlspecialchars($a['desc']) ?></div>
                <div class="dash-av"><a class="dash-btn btn-sm" href="<?= htmlspecialchars($a['url']) ?>">View</a></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="dash-grid">
    <div class="dash-col-main">
        <?php if ($canOrders): ?>
        <div class="dash-panel">
            <div class="dash-panel-title">
                <h3><i class="fas fa-chart-line"></i> Sales Overview
                    <span class="dash-growth" id="salesGrowth" style="display:none"></span>
                </h3>
                <span class="dash-range" id="salesRange">
                    <button data-r="today">Today</button>
                    <button data-r="week" class="on">Week</button>
                    <button data-r="month">Month</button>
                </span>
            </div>
            <div class="dash-stats" id="salesStats"></div>
            <div class="chart-box"><canvas id="salesChart"></canvas></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($topProducts)): ?>
        <div class="dash-panel">
            <div class="dash-panel-title">
                <h3><i class="fas fa-fire" style="color:#ea580c"></i> Top Selling Products</h3>
                <span class="dash-badge b-gray">Last 30 days</span>
            </div>
            <div class="rank-list">
                <?php
                $tpMax = max(1, max(array_map(fn($r) => (int)$r['qty'], $topProducts)));
                foreach ($topProducts as $i => $tp): ?>
                <div class="rank-row">
                    <span class="rank-num"><?= $i + 1 ?></span>
                    <div class="rank-main">
                        <div class="rank-name"><?= htmlspecialchars($tp['name']) ?></div>
                        <div class="rank-bar"><div class="rank-fill<?= $i === 0 ? ' gold' : '' ?>" style="width:<?= round((int)$tp['qty'] / $tpMax * 100) ?>%"></div></div>
                    </div>
                    <div class="rank-side">
                        <div class="rank-v"><?= number_format((int)$tp['qty']) ?> units</div>
                        <div class="rank-s"><?= fmt((float)$tp['revenue']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($recentOrders)): ?>
        <div class="dash-panel">
            <div class="dash-panel-title">
                <h3><i class="fas fa-clipboard-check"></i> Recent Customer Orders</h3>
                <a class="dash-link" href="index.php?page=customer_order_queue">View All Orders →</a>
            </div>
            <div class="dash-tbl-wrap">
            <table class="dash-table">
                <thead><tr><th>Order #</th><th>Customer</th><th>Date</th><th>Amount</th><th>Payment</th><th>Fulfillment</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($recentOrders as $ro):
                        $orderBadge = ['badge' => 'b-gray', 'label' => 'Approved'];
                        if ($ro['status'] === 'pending') { $orderBadge = ['badge' => 'b-orange', 'label' => 'Pending Approval']; }
                        elseif ($ro['status'] === 'rejected') { $orderBadge = ['badge' => 'b-red', 'label' => 'Rejected']; }
                        elseif (in_array($ro['delivery_status'] ?? '', ['delivered'], true)) { $orderBadge = ['badge' => 'b-green', 'label' => 'Delivered']; }
                        elseif (in_array($ro['delivery_status'] ?? '', ['failed', 'returned'], true)) { $orderBadge = ['badge' => 'b-red', 'label' => ucfirst($ro['delivery_status'])]; }
                        elseif (in_array($ro['delivery_status'] ?? '', ['out_for_delivery', 'picked_up'], true)) { $orderBadge = ['badge' => 'b-blue', 'label' => 'Out for Delivery']; }
                        elseif (in_array($ro['delivery_status'] ?? '', ['assigned', 'accepted'], true)) { $orderBadge = ['badge' => 'b-purple', 'label' => 'Assigned']; }
                        elseif (in_array($ro['delivery_status'] ?? '', ['pod_pending'], true)) { $orderBadge = ['badge' => 'b-amber', 'label' => 'Awaiting Verification']; }
                        elseif (($ro['fulfillment_status'] ?? '') === 'fulfillment_completed') { $orderBadge = ['badge' => 'b-green', 'label' => 'Completed']; }
                        elseif (($ro['fulfillment_status'] ?? '') === 'ready_for_delivery') { $orderBadge = ['badge' => 'b-blue', 'label' => 'Ready for Delivery']; }
                        elseif (($ro['fulfillment_status'] ?? '') !== '') { $orderBadge = ['badge' => 'b-navy', 'label' => 'Preparing']; }

                        $payBadge = ['badge' => 'b-gray', 'label' => '—'];
                        if (($ro['sales_type'] ?? '') === 'credit') { $payBadge = ['badge' => 'b-purple', 'label' => 'Credit']; }
                        elseif ((int)($ro['payment_confirmed'] ?? 0) === 1) { $payBadge = ['badge' => 'b-green', 'label' => 'Paid']; }
                        else { $payBadge = ['badge' => 'b-orange', 'label' => 'Unconfirmed']; }

                        $fulfLabel = '—';
                        $fm = $ro['fulfillment_status'] ?? '';
                        if ($fm === 'fulfillment_completed') $fulfLabel = 'Completed';
                        elseif ($fm === 'ready_for_delivery') $fulfLabel = 'Ready for Delivery';
                        elseif ($fm === 'prepared') $fulfLabel = 'Prepared';
                        elseif ($fm === 'ready_to_prepare' || $fm === 'waiting_stock_check') $fulfLabel = 'To Prepare';
                        elseif ($fm === 'reserved') $fulfLabel = 'Stock Reserved';
                        elseif ($fm === 'out_of_stock') $fulfLabel = 'Out of Stock';
                        elseif ($fm === 'fulfillment_cancelled') $fulfLabel = 'Cancelled';
                    ?>
                    <tr>
                        <td class="num">#<?= (int)$ro['id'] ?></td>
                        <td><?= htmlspecialchars($ro['customer'] ?? 'Customer #'.$ro['customer_id']) ?></td>
                        <td style="white-space:nowrap"><?= htmlspecialchars($ro['order_date']) ?></td>
                        <td class="num"><?= fmt((float)$ro['total_amount']) ?></td>
                        <td><span class="dash-badge <?= $payBadge['badge'] ?>"><?= $payBadge['label'] ?></span></td>
                        <td style="font-size:.72rem;color:#4b5563"><?= htmlspecialchars($fulfLabel) ?></td>
                        <td><span class="dash-badge <?= $orderBadge['badge'] ?>"><?= $orderBadge['label'] ?></span></td>
                        <td><a class="dash-link" href="index.php?page=<?= hasPermission('customer_order_queue','view') ? 'customer_order_queue' : (hasPermission('order_fulfillment','view') ? 'order_fulfillment' : 'sales') ?>">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($recentMovements)): ?>
        <div class="dash-panel">
            <div class="dash-panel-title">
                <h3><i class="fas fa-arrows-spin"></i> Recent Stock Movements</h3>
                <a class="dash-link" href="index.php?page=stock_movements">View Stock Movements →</a>
            </div>
            <div class="dash-tbl-wrap">
            <table class="dash-table">
                <thead><tr><th>Product</th><th>Type</th><th>Quantity</th><th>Reference</th><th>Date</th></tr></thead>
                <tbody>
                    <?php
                    $mvMap = [
                        'purchase_order' => ['Purchase Received', 'in'],
                        'sale' => ['Sale', 'out'],
                        'adjustment' => ['Adjustment', 'adj'],
                        'order' => ['Supplier Order', 'in'],
                        'transfer' => ['Transfer', 'out'],
                        'SYNC' => ['System Sync', 'in'],
                        'disposal' => ['Disposal', 'out'],
                        'return' => ['Return', 'in'],
                        'physical_count' => ['Count', 'adj'],
                    ];
                    foreach ($recentMovements as $mv):
                        $type = $mv['reference_type'] ?? '';
                        $mvInfo = $mvMap[$type] ?? ['Movement', 'adj'];
                        $q = (int) $mv['quantity_change'];
                        $qCls = $q > 0 ? 'color:#047857' : ($q < 0 ? 'color:#b91c1c' : 'color:#6b7280');
                        $qTxt = $q > 0 ? '+' . $q : (string) $q;
                    ?>
                    <tr>
                        <td style="font-weight:600"><?= htmlspecialchars($mv['product_name'] ?? 'Product #'.$mv['product_id']) ?></td>
                        <td><span class="dash-badge <?= $mvInfo[1]==='in'?'b-green':($mvInfo[1]==='out'?'b-red':'b-gray') ?>"><?= htmlspecialchars($mvInfo[0]) ?></span></td>
                        <td class="num" style="<?= $qCls ?>"><?= $qTxt ?></td>
                        <td style="font-size:.72rem;color:#6b7280"><?= $mv['reference_id'] ? htmlspecialchars(ucwords(str_replace('_',' ',$type)).' #'.$mv['reference_id']) : htmlspecialchars($mv['reason']) ?></td>
                        <td style="font-size:.72rem;color:#6b7280;white-space:nowrap"><?= htmlspecialchars(date('M j, g:ia', strtotime($mv['created_at']))) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="dash-col-main">
        <?php if ($canStock): ?>
        <div class="dash-panel">
            <div class="dash-panel-title">
                <h3><i class="fas fa-boxes-stacked"></i> Inventory Overview</h3>
                <a class="dash-link" href="index.php?page=stock">Manage Stock →</a>
            </div>
            <div class="inv-flex">
                <div class="inv-donut-wrap" id="invDonutWrap">
                    <canvas id="invDonut"></canvas>
                    <div class="inv-donut-center">
                        <div class="dv"><?= number_format($dashAvailUnits) ?></div>
                        <div class="dl">Available</div>
                    </div>
                </div>
                <div class="dash-bars" style="flex:1;min-width:190px">
                    <div class="dash-bar-row">
                        <div class="bar-label"><b>In Stock</b><span><?= number_format($dashAvailUnits) ?> units</span></div>
                        <div class="dash-bar-track"><div class="dash-bar-fill g" style="width:100%"></div></div>
                    </div>
                    <div class="dash-bar-row">
                        <div class="bar-label"><b>Quarantine</b><span><?= number_format($dashQuarUnits) ?> units</span></div>
                        <div class="dash-bar-track"><div class="dash-bar-fill o" style="width:<?= $dashAvailUnits>0?min(100,round($dashQuarUnits/$dashAvailUnits*100)):0 ?>%"></div></div>
                    </div>
                    <div class="dash-bar-row">
                        <div class="bar-label"><b>Expired</b><span><?= number_format($dashExpiredUnits) ?> units</span></div>
                        <div class="dash-bar-track"><div class="dash-bar-fill r" style="width:<?= $dashAvailUnits>0?min(100,round($dashExpiredUnits/$dashAvailUnits*100)):0 ?>%"></div></div>
                    </div>
                    <div class="dash-bar-row">
                        <div class="bar-label"><b>Disposed</b><span><?= number_format($dashDisposedUnits) ?> units</span></div>
                        <div class="dash-bar-track"><div class="dash-bar-fill n" style="width:<?= $dashAvailUnits>0?min(100,round($dashDisposedUnits/$dashAvailUnits*100)):0 ?>%"></div></div>
                    </div>
                </div>
            </div>
            <div class="donut-legend">
                <span><i style="background:#059669"></i>Available <b><?= number_format($dashAvailUnits) ?></b></span>
                <span><i style="background:#d97706"></i>Quarantined <b><?= number_format($dashQuarUnits) ?></b></span>
                <span><i style="background:#dc2626"></i>Expired <b><?= number_format($dashExpiredUnits) ?></b></span>
                <span><i style="background:#94a3b8"></i>Disposed <b><?= number_format($dashDisposedUnits) ?></b></span>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($canExpiry): ?>
        <div class="dash-panel">
            <div class="dash-panel-title">
                <h3><i class="fas fa-calendar-xmark"></i> Expiry Monitoring</h3>
                <a class="dash-link" href="index.php?page=expiry_management">Expiry Management →</a>
            </div>
            <div class="dash-expgrid">
                <a class="dash-exp e-red" href="index.php?page=expiry_management&tab=expired"><div class="n"><?= $expBuckets['expired']['batches'] ?></div><div class="p">Expired</div><div class="m"><?= number_format($expBuckets['expired']['units']) ?> units</div></a>
                <a class="dash-exp e-red" href="index.php?page=expiry_management&tab=expiring"><div class="n"><?= $expBuckets['d30']['batches'] ?></div><div class="p">≤ 30 Days</div><div class="m"><?= number_format($expBuckets['d30']['units']) ?> units</div></a>
                <a class="dash-exp e-orange" href="index.php?page=expiry_management&tab=expiring"><div class="n"><?= $expBuckets['d60']['batches'] ?></div><div class="p">31–60 Days</div><div class="m"><?= number_format($expBuckets['d60']['units']) ?> units</div></a>
                <a class="dash-exp e-amber" href="index.php?page=expiry_management&tab=expiring"><div class="n"><?= $expBuckets['d90']['batches'] ?></div><div class="p">61–90 Days</div><div class="m"><?= number_format($expBuckets['d90']['units']) ?> units</div></a>
                <a class="dash-exp e-green" href="index.php?page=expiry_management"><div class="n"><?= $expBuckets['beyond']['batches'] ?></div><div class="p">&gt; 90 Days</div><div class="m"><?= number_format($expBuckets['beyond']['units']) ?> units</div></a>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($canPO): ?>
        <div class="dash-panel">
            <div class="dash-panel-title">
                <h3><i class="fas fa-truck"></i> Procurement Overview</h3>
                <a class="dash-link" href="index.php?page=purchase_orders">View Purchase Orders →</a>
            </div>
            <div class="dash-bars">
                <div class="dash-bar-row">
                    <div class="bar-label"><b>Pending</b><span><?= $dashPendingPOs ?> PO(s)</span></div>
                    <div class="dash-bar-track"><div class="dash-bar-fill b" style="width:<?= $dashPendingPOs>0?100:0 ?>%"></div></div>
                </div>
                <div class="dash-bar-row">
                    <div class="bar-label"><b>Awaiting Finance Approval</b><span><?= $dashPOFinance ?></span></div>
                    <div class="dash-bar-track"><div class="dash-bar-fill o" style="width:<?= $dashPendingPOs>0?min(100,round($dashPOFinance/$dashPendingPOs*100)):0 ?>%"></div></div>
                </div>
                <div class="dash-bar-row">
                    <div class="bar-label"><b>Payment Approved — Awaiting Receiving</b><span><?= $dashPOReceive ?></span></div>
                    <div class="dash-bar-track"><div class="dash-bar-fill g" style="width:<?= $dashPendingPOs>0?min(100,round($dashPOReceive/$dashPendingPOs*100)):0 ?>%"></div></div>
                </div>
                <div class="dash-bar-row">
                    <div class="bar-label"><b>Received (last 30 days)</b><span><?= $dashPORecent ?></span></div>
                    <div class="dash-bar-track"><div class="dash-bar-fill g" style="width:<?= $dashPORecent>0?100:0 ?>%"></div></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($canDelivery): ?>
        <div class="dash-panel">
            <div class="dash-panel-title">
                <h3><i class="fas fa-truck-fast"></i> Delivery Overview</h3>
                <a class="dash-link" href="index.php?page=delivery_assign">Manage Deliveries →</a>
            </div>
            <div class="dash-bars">
                <?php
                $delReady = (int)($deliveryRows[''] ?? 0);
                $delAssigned = (int)($deliveryRows['assigned'] ?? 0) + (int)($deliveryRows['accepted'] ?? 0);
                $delOut = (int)($deliveryRows['out_for_delivery'] ?? 0) + (int)($deliveryRows['picked_up'] ?? 0);
                $delPod = (int)($deliveryRows['pod_pending'] ?? 0);
                $delDelayed = (int)($deliveryRows['failed'] ?? 0) + (int)($deliveryRows['returned'] ?? 0);
                $delTotal = max(1, $delReady + $delAssigned + $delOut + $delPod + $delDelayed + $dashDeliveredToday);
                $delRow = [
                    ['Ready for Dispatch', $delReady, 'b'],
                    ['Assigned', $delAssigned, 'b'],
                    ['Out for Delivery', $delOut, 'o'],
                    ['Awaiting Verification', $delPod, 'a'],
                    ['Delivered Today', $dashDeliveredToday, 'g'],
                ];
                if ($delDelayed > 0) $delRow[] = ['Delayed / Returned', $delDelayed, 'r'];
                foreach ($delRow as $dr):
                    $pct = min(100, (int)round($dr[1] / $delTotal * 100));
                ?>
                <div class="dash-bar-row">
                    <div class="bar-label"><b><?= $dr[0] ?></b><span><?= $dr[1] ?></span></div>
                    <div class="dash-bar-track"><div class="dash-bar-fill <?= $dr[2] ?>" style="width:<?= $pct ?>%"></div></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($canFinance && ($dashBankAccounts || $dashCashAccounts)): ?>
        <div class="dash-panel">
            <div class="dash-panel-title">
                <h3><i class="fas fa-piggy-bank"></i> Bank &amp; Cash Position</h3>
                <a class="dash-link" href="index.php?page=finance_bank">Bank Management →</a>
            </div>
            <div class="dash-stats">
                <div class="dash-stat"><div class="v"><?= fmt($dashBankBalance) ?></div><div class="l"><i class="fas fa-university"></i>Total Bank</div></div>
                <div class="dash-stat"><div class="v"><?= fmt($dashCashBalance) ?></div><div class="l"><i class="fas fa-money-bill-wave"></i>Total Cash</div></div>
                <div class="dash-stat"><div class="v"><?= fmt($dashBankBalance + $dashCashBalance) ?></div><div class="l"><i class="fas fa-coins"></i>Total Liquid</div></div>
            </div>
            <?php if ($dashBankAccounts): ?>
            <div class="dash-tbl-wrap">
            <table class="dash-table">
                <thead><tr><th>Bank Account</th><th>Balance</th></tr></thead>
                <tbody>
                    <?php foreach ($dashBankAccounts as $ba): ?>
                    <tr>
                        <td><?= htmlspecialchars($ba['bank_name'].' - '.$ba['account_name']) ?><?= $ba['status'] === 'frozen' ? ' <span style="color:#b91c1c;font-size:.68rem">(frozen)</span>' : '' ?></td>
                        <td class="num"><?= fmt((float)$ba['balance']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<div class="dash-grid-3">
    <?php if (!empty($topCustomers)): ?>
    <div class="dash-panel">
        <div class="dash-panel-title">
            <h3><i class="fas fa-users" style="color:#7c3aed"></i> Top Customers</h3>
            <span class="dash-badge b-gray">Last 30 days</span>
        </div>
        <div class="rank-list">
            <?php
            $tcMax = max(1.0, max(array_map(fn($r) => (float)$r['total'], $topCustomers)));
            foreach ($topCustomers as $i => $tc): ?>
            <div class="rank-row">
                <span class="rank-num"><?= $i + 1 ?></span>
                <div class="rank-main">
                    <div class="rank-name"><?= htmlspecialchars($tc['name']) ?></div>
                    <div class="rank-meta"><?= (int)$tc['orders'] ?> order<?= (int)$tc['orders'] > 1 ? 's' : '' ?></div>
                    <div class="rank-bar"><div class="rank-fill<?= $i === 0 ? ' gold' : '' ?>" style="width:<?= round((float)$tc['total'] / $tcMax * 100) ?>%"></div></div>
                </div>
                <div class="rank-side">
                    <div class="rank-v"><?= fmt((float)$tc['total']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($canStock): ?>
    <div class="dash-panel">
        <div class="dash-panel-title">
            <h3><i class="fas fa-triangle-exclamation" style="color:#d97706"></i> Low Stock Watchlist</h3>
            <a class="dash-link" href="index.php?page=stock">Full Stock List →</a>
        </div>
        <?php if (empty($lowStockList)): ?>
        <div class="dash-empty ok"><i class="fas fa-circle-check"></i>All products are comfortably stocked</div>
        <?php else: ?>
        <div class="dash-tbl-wrap">
        <table class="dash-table">
            <thead><tr><th>Product</th><th>Available</th><th>Reorder At</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($lowStockList as $ls):
                    $avail = (int)$ls['stock_quantity'];
                    $isOut = $avail <= 0;
                ?>
                <tr>
                    <td style="font-weight:600"><?= htmlspecialchars($ls['name']) ?><div style="font-size:.66rem;color:#9ca3af"><?= htmlspecialchars($ls['sku']) ?></div></td>
                    <td class="num" style="<?= $isOut ? 'color:#b91c1c' : 'color:#b45309' ?>"><?= $avail ?></td>
                    <td><?= (int)$ls['reorder_level'] > 0 ? (int)$ls['reorder_level'] : '10 <span style="font-size:.62rem;color:#9ca3af">(default)</span>' ?></td>
                    <td><span class="dash-badge <?= $isOut ? 'b-red' : 'b-orange' ?>"><?= $isOut ? 'Out of Stock' : 'Low Stock' ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($opsLinks): ?>
    <div class="dash-panel">
        <div class="dash-panel-title">
            <h3><i class="fas fa-bolt" style="color:#ca8a04"></i> Operations Hub</h3>
        </div>
        <div class="ops-grid">
            <?php foreach ($opsLinks as $ol): ?>
            <a class="ops-link" href="<?= htmlspecialchars($ol[2]) ?>">
                <i class="fas <?= $ol[1] ?>"></i>
                <span><?= htmlspecialchars($ol[0]) ?></span>
                <i class="fas fa-chevron-right ops-arrow"></i>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    if (typeof Chart === 'undefined') return;
    var ranges = <?= json_encode($salesRanges) ?>;
    var stats = <?= json_encode($salesStats) ?>;
    var growth = { today: null, week: <?= json_encode($wkGrowth) ?>, month: <?= json_encode($moGrowth) ?> };
    var sym = <?= json_encode(currency_sym()) ?>;

    function numFmt(v) { return sym + ' ' + Number(v).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); }

    var ctx = document.getElementById('salesChart');
    if (!ctx) return;
    var g2d = ctx.getContext('2d');
    var grad = g2d.createLinearGradient(0, 0, 0, 230);
    grad.addColorStop(0, 'rgba(37, 99, 235, 0.22)');
    grad.addColorStop(1, 'rgba(37, 99, 235, 0)');
    var chart = new Chart(g2d, {
        type: 'line',
        data: {
            labels: ranges.week.labels,
            datasets: [{
                label: 'Revenue',
                data: ranges.week.totals,
                borderColor: '#2563eb',
                backgroundColor: grad,
                fill: true,
                tension: 0.38,
                borderWidth: 2.5,
                pointRadius: 3,
                pointHoverRadius: 5,
                pointBackgroundColor: '#ffffff',
                pointBorderColor: '#2563eb',
                pointBorderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#0f172a',
                    padding: 10,
                    cornerRadius: 8,
                    displayColors: false,
                    callbacks: {
                        label: function (c) {
                            var i = c.dataIndex;
                            return numFmt(c.parsed.y) + '  ·  ' + ((ranges[chart.currentRange] || ranges.week).counts[i] || 0) + ' sale(s)';
                        }
                    }
                }
            },
            scales: {
                y: { beginAtZero: true, ticks: { font: { size: 10 }, callback: function (v) { return Number(v).toLocaleString(); } }, grid: { color: 'rgba(15,23,42,0.06)' } },
                x: { ticks: { font: { size: 10 }, autoSkip: true, maxTicksLimit: 10, maxRotation: 0 }, grid: { display: false } }
            }
        }
    });
    chart.currentRange = 'week';

    var statEl = document.getElementById('salesStats');
    var growthEl = document.getElementById('salesGrowth');

    function setGrowth(r) {
        if (!growthEl) return;
        var gv = growth[r];
        if (gv === null || gv === undefined) { growthEl.style.display = 'none'; return; }
        growthEl.style.display = 'inline-flex';
        growthEl.className = 'dash-growth ' + (gv >= 0 ? 'up' : 'down');
        growthEl.innerHTML = '<i class="fas fa-arrow-' + (gv >= 0 ? 'up' : 'down') + '"></i> ' + Math.abs(gv) + '% vs ' + (r === 'month' ? 'last month' : 'last week');
    }

    function renderStats(r) {
        var s = stats[r] || { total: 0, count: 0 };
        var avg = s.count > 0 ? s.total / s.count : 0;
        statEl.innerHTML =
            '<div class="dash-stat"><div class="v">' + numFmt(s.total) + '</div><div class="l"><i class="fas fa-sack-dollar"></i>Total Sales</div></div>' +
            '<div class="dash-stat"><div class="v">' + s.count + '</div><div class="l"><i class="fas fa-receipt"></i>Orders</div></div>' +
            '<div class="dash-stat"><div class="v">' + numFmt(avg) + '</div><div class="l"><i class="fas fa-chart-simple"></i>Avg Order Value</div></div>';
    }
    renderStats('week');
    setGrowth('week');

    var btnWrap = document.getElementById('salesRange');
    if (btnWrap) {
        btnWrap.querySelectorAll('button').forEach(function (btn) {
            btn.addEventListener('click', function () {
                btnWrap.querySelectorAll('button').forEach(function (b) { b.classList.remove('on'); });
                btn.classList.add('on');
                var r = btn.getAttribute('data-r');
                chart.currentRange = r;
                chart.data.labels = ranges[r].labels;
                chart.data.datasets[0].data = ranges[r].totals;
                chart.update();
                renderStats(r);
                setGrowth(r);
            });
        });
    }

    var donutEl = document.getElementById('invDonut');
    if (donutEl) {
        var dData = [<?= (int)$dashAvailUnits ?>, <?= (int)$dashQuarUnits ?>, <?= (int)$dashExpiredUnits ?>, <?= (int)$dashDisposedUnits ?>];
        if (dData.some(function (v) { return v > 0; })) {
            new Chart(donutEl.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['Available', 'Quarantined', 'Expired', 'Disposed'],
                    datasets: [{
                        data: dData,
                        backgroundColor: ['#059669', '#d97706', '#dc2626', '#94a3b8'],
                        borderWidth: 3,
                        borderColor: '#ffffff',
                        hoverOffset: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '70%',
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            padding: 10,
                            cornerRadius: 8,
                            callbacks: {
                                label: function (c) { return c.label + ': ' + Number(c.parsed).toLocaleString() + ' units'; }
                            }
                        }
                    }
                }
            });
        } else {
            var dw = document.getElementById('invDonutWrap');
            if (dw) dw.style.display = 'none';
        }
    }
})();
</script>
</section>

