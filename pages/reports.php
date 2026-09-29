<?php
requirePermission('reports', 'view');
require_once __DIR__ . '/../includes/stock_helper.php';
$pdo = getPDO();

$tab = $_GET['tab'] ?? 'sales';
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$export = $_GET['export'] ?? '';

// ─── Sales Report ──────────────────────────────────────────
$where = []; $params = [];
if ($dateFrom !== '') { $where[] = 's.sale_date >= :date_from'; $params['date_from'] = $dateFrom; }
if ($dateTo !== '') { $where[] = 's.sale_date <= :date_to'; $params['date_to'] = $dateTo; }
$whereClause = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';

$sd = $pdo->prepare("SELECT COUNT(*) AS total_sales, COALESCE(SUM(total_amount),0) AS total_revenue, COALESCE(AVG(total_amount),0) AS avg_sale FROM sales s$whereClause");
$sd->execute($params); $sd = $sd->fetch();
$dailyAll = $pdo->prepare("SELECT s.sale_date, COUNT(*) AS sale_count, SUM(s.total_amount) AS daily_total FROM sales s$whereClause GROUP BY s.sale_date ORDER BY s.sale_date DESC LIMIT 365");
$dailyAll->execute($params); $dailyAll = $dailyAll->fetchAll();
$customerAll = $pdo->prepare("SELECT c.name, COUNT(*) AS sale_count, SUM(s.total_amount) AS total FROM sales s JOIN customers c ON s.customer_id = c.id$whereClause GROUP BY s.customer_id ORDER BY total DESC");
$customerAll->execute($params); $customerAll = $customerAll->fetchAll();
$productAll = $pdo->prepare("SELECT p.name, SUM(si.quantity) AS qty_sold, SUM(si.quantity * si.unit_price) AS revenue FROM sale_items si JOIN products p ON si.product_id = p.id JOIN sales s ON si.sale_id = s.id$whereClause GROUP BY si.product_id ORDER BY revenue DESC");
$productAll->execute($params); $productAll = $productAll->fetchAll();

// ─── Purchase Report ───────────────────────────────────────
$poWhere = []; $poParams = [];
if ($dateFrom !== '') { $poWhere[] = 'po.order_date >= :from'; $poParams['from'] = $dateFrom; }
if ($dateTo !== '') { $poWhere[] = 'po.order_date <= :to'; $poParams['to'] = $dateTo; }
$poWhereClause = !empty($poWhere) ? ' WHERE ' . implode(' AND ', $poWhere) : '';
$poSummary = $pdo->prepare("SELECT COUNT(*) AS total_po, COALESCE(SUM(total_amount),0) AS total_value FROM purchase_orders po$poWhereClause");
$poSummary->execute($poParams); $poSummary = $poSummary->fetch();
$poBySupplier = $pdo->prepare("SELECT s.name, COUNT(*) AS po_count, COALESCE(SUM(po.total_amount),0) AS total FROM purchase_orders po JOIN suppliers s ON po.supplier_id = s.id$poWhereClause GROUP BY po.supplier_id ORDER BY total DESC");
$poBySupplier->execute($poParams); $poBySupplier = $poBySupplier->fetchAll();
$poByStatus = $pdo->prepare("SELECT status, COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS total FROM purchase_orders po$poWhereClause GROUP BY status ORDER BY FIELD(status,'pending','approved','received','cancelled')");
$poByStatus->execute($poParams); $poByStatus = $poByStatus->fetchAll();
$poItems = $pdo->prepare("SELECT p.name AS product, SUM(poi.quantity) AS qty_ordered, SUM(poi.received_qty) AS qty_received, SUM(poi.quantity * poi.unit_price) AS total_cost FROM purchase_order_items poi JOIN products p ON poi.product_id = p.id JOIN purchase_orders po ON poi.purchase_order_id = po.id$poWhereClause GROUP BY poi.product_id ORDER BY total_cost DESC LIMIT 20");
$poItems->execute($poParams); $poItems = $poItems->fetchAll();

// ─── Inventory Report ──────────────────────────────────────
$stockSummaryRow = stockTotals($pdo, null, 'purchase_price', 'active');
$stockSummary = ['total_products' => $stockSummaryRow['total'] ?? 0, 'total_stock' => $stockSummaryRow['total_qty'] ?? 0, 'stock_value' => $stockSummaryRow['total_value'] ?? 0];
$expiringBatches = $pdo->query("SELECT pb.*, p.name AS product_name FROM product_batches pb JOIN products p ON pb.product_id = p.id WHERE pb.quantity > 0 AND pb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) ORDER BY pb.expiry_date LIMIT 20")->fetchAll();
$lowStock = $pdo->query("SELECT id, name, stock_quantity, purchase_price FROM products WHERE stock_quantity > 0 AND stock_quantity <= 10 ORDER BY stock_quantity LIMIT 20")->fetchAll();
$zeroStock = $pdo->query("SELECT id, name FROM products WHERE stock_quantity = 0 AND product_status='active' ORDER BY name LIMIT 20")->fetchAll();
$batchCount = $pdo->query("SELECT COUNT(*) FROM product_batches WHERE quantity > 0")->fetchColumn();
$stockByWh = $pdo->query("SELECT w.name, COALESCE(SUM(pb.quantity),0) AS batch_qty FROM warehouses w LEFT JOIN product_batches pb ON pb.warehouse_id = w.id GROUP BY w.id ORDER BY w.name")->fetchAll();

// ─── Customer Report ───────────────────────────────────────
$custWhere = []; $custParams = [];
if ($dateFrom !== '') { $custWhere[] = 'c.created_at >= :from'; $custParams['from'] = $dateFrom; }
if ($dateTo !== '') { $custWhere[] = 'c.created_at <= :to'; $custParams['to'] = $dateTo . ' 23:59:59'; }
$custWhereClause = !empty($custWhere) ? ' WHERE ' . implode(' AND ', $custWhere) : '';
if (!empty($custParams)) {
    $custStmt = $pdo->prepare("SELECT c.*, COALESCE((SELECT SUM(total_amount) FROM customer_orders WHERE customer_id = c.id AND status IN ('pending','approved') AND payment_confirmed=0),0) AS outstanding FROM customers c$custWhereClause ORDER BY c.name");
    $custStmt->execute($custParams);
} else {
    $custStmt = $pdo->query("SELECT c.*, COALESCE((SELECT SUM(total_amount) FROM customer_orders WHERE customer_id = c.id AND status IN ('pending','approved') AND payment_confirmed=0),0) AS outstanding FROM customers c ORDER BY c.name");
}
$custRows = $custStmt->fetchAll();
$custOrders = $pdo->prepare("SELECT c.name, COUNT(co.id) AS order_count, COALESCE(SUM(co.total_amount),0) AS total, COALESCE(SUM(CASE WHEN co.payment_confirmed=1 THEN co.total_amount ELSE 0 END),0) AS paid FROM customers c LEFT JOIN customer_orders co ON co.customer_id = c.id GROUP BY c.id ORDER BY total DESC LIMIT 20");
$custOrders->execute(); $custOrders = $custOrders->fetchAll();

// ─── Delivery Report ──────────────────────────────────────
$deliveryReport = $pdo->query("SELECT co.id, co.order_date, co.total_amount, co.delivery_status, co.delivery_confirmed_at, c.name AS customer_name, dp.full_name AS staff_name FROM customer_orders co JOIN customers c ON co.customer_id = c.id LEFT JOIN driver_profiles dp ON co.delivery_staff_id = dp.id WHERE co.delivery_status IS NOT NULL ORDER BY co.delivery_confirmed_at DESC LIMIT 200")->fetchAll();
$delSummary = $pdo->query("SELECT COUNT(*) AS total, COUNT(CASE WHEN delivery_status = 'delivered' THEN 1 END) AS delivered, COUNT(CASE WHEN delivery_status = 'out_for_delivery' THEN 1 END) AS out_for_delivery, COUNT(CASE WHEN delivery_status = 'assigned' THEN 1 END) AS assigned FROM customer_orders WHERE delivery_status IS NOT NULL")->fetch();

// ─── Fulfillment Report (pickup vs delivery lanes) ─────────
$fulfillmentReport = $pdo->query("
    SELECT co.id, co.order_date, co.total_amount, co.fulfillment_method, co.fulfillment_status,
           co.payment_method, co.payment_confirmed, co.prepared_at, co.pickup_time, co.received_by,
           co.delivery_status, c.name AS customer_name
    FROM customer_orders co
    LEFT JOIN customers c ON co.customer_id = c.id
    WHERE co.fulfillment_method IS NOT NULL AND co.fulfillment_method <> ''
    ORDER BY co.created_at DESC LIMIT 300
")->fetchAll();
$fulfillmentSummary = $pdo->query("
    SELECT
        COALESCE(SUM(fulfillment_method = 'pickup'), 0) AS pickup_total,
        COALESCE(SUM(fulfillment_method = 'delivery'), 0) AS delivery_total,
        COALESCE(SUM(fulfillment_method = 'pickup' AND fulfillment_status = 'fulfillment_completed'), 0) AS pickup_completed,
        COALESCE(SUM(fulfillment_method = 'delivery' AND (delivery_status = 'delivered' OR fulfillment_status = 'fulfillment_completed')), 0) AS delivery_completed,
        COALESCE(SUM(fulfillment_method = 'pickup' AND fulfillment_status = 'waiting_customer_pickup'), 0) AS pickup_waiting,
        COALESCE(SUM(fulfillment_method = 'delivery' AND fulfillment_status = 'ready_for_delivery'), 0) AS delivery_ready
    FROM customer_orders
    WHERE fulfillment_method IS NOT NULL AND fulfillment_method <> ''
")->fetch();
$fulfLabels = [
    'pickup' => 'Pickup',
    'delivery' => 'Delivery',
    'waiting_stock_check' => 'Waiting Stock Check',
    'ready_to_prepare' => 'Ready to Prepare',
    'reserved' => 'Reserved',
    'out_of_stock' => 'Out of Stock',
    'preparing' => 'Preparing',
    'prepared' => 'Waiting Finance',
    'paid' => 'Paid',
    'credit_approved' => 'Credit Approved',
    'waiting_customer_pickup' => 'Waiting Customer Pickup',
    'ready_for_delivery' => 'Ready for Delivery',
    'fulfillment_completed' => 'Completed',
    'fulfillment_cancelled' => 'Cancelled',
];

// ─── Export ────────────────────────────────────────────────
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $tab . '_report_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    if ($tab === 'sales') {
        fputcsv($out, ['Total Sales', 'Total Revenue', 'Average Sale']);
        fputcsv($out, [$sd['total_sales'], $sd['total_revenue'], $sd['avg_sale']]);
        fputcsv($out, []); fputcsv($out, ['Date', 'Sales Count', 'Daily Total']);
        foreach ($dailyAll as $d) fputcsv($out, [$d['sale_date'], $d['sale_count'], $d['daily_total']]);
    } elseif ($tab === 'purchase') {
        fputcsv($out, ['Total POs', 'Total Value']);
        fputcsv($out, [$poSummary['total_po'], $poSummary['total_value']]);
        fputcsv($out, []); fputcsv($out, ['Supplier', 'PO Count', 'Total']);
        foreach ($poBySupplier as $r) fputcsv($out, [$r['name'], $r['po_count'], $r['total']]);
    } elseif ($tab === 'inventory') {
        fputcsv($out, ['Total Products', 'Total Stock', 'Stock Value']);
        fputcsv($out, [$stockSummary['total_products'], $stockSummary['total_stock'], $stockSummary['stock_value']]);
    } elseif ($tab === 'customer') {
        fputcsv($out, ['Customer', 'Type', 'Orders', 'Total', 'Paid', 'Outstanding']);
        foreach ($custOrders as $r) fputcsv($out, [$r['name'], '', $r['order_count'], $r['total'], $r['paid'], (float)$r['total'] - (float)$r['paid']]);
    } elseif ($tab === 'delivery') {
        fputcsv($out, ['Order#', 'Customer', 'Staff', 'Total', 'Date', 'Status', 'Confirmed At']);
        foreach ($deliveryReport as $r) fputcsv($out, [$r['id'], $r['customer_name'], $r['staff_name'] ?? '', $r['total_amount'], $r['order_date'], $r['delivery_status'] ?? '', $r['delivery_confirmed_at'] ?? '']);
    } elseif ($tab === 'fulfillment') {
        fputcsv($out, ['Order#', 'Customer', 'Total', 'Lane', 'Status', 'Payment', 'Paid', 'Prepared At', 'Pickup Time', 'Received By', 'Delivery Status']);
        foreach ($fulfillmentReport as $r) fputcsv($out, [$r['id'], $r['customer_name'], $r['total_amount'], $fulfLabels[$r['fulfillment_method']] ?? $r['fulfillment_method'], $fulfLabels[$r['fulfillment_status'] ?? ''] ?? ($r['fulfillment_status'] ?? ''), $r['payment_method'], $r['payment_confirmed'] ? 'Yes' : 'No', $r['prepared_at'] ?? '', $r['pickup_time'] ?? '', $r['received_by'] ?? '', $r['delivery_status'] ?? '']);
    }
    fclose($out); exit;
}

if ($export === 'xls') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $tab . '_report_' . date('Ymd') . '.xls"');
    echo '<html><head><meta charset="utf-8"><style>td,th{border:1px solid #ccc;padding:4px 8px;font-family:Arial}th{background:#1e3a5f;color:#fff}.rp-hdr{font-size:11px;color:#555}</style></head><body>';
    echo '<table style="border:none;margin-bottom:6px"><tr>';
    echo '<td style="border:none"><img src="' . htmlspecialchars(brand_logo()) . '" style="height:52px;width:auto;object-fit:contain"></td>';
    echo '<td style="border:none"><div style="font-size:16px;font-weight:bold;color:#1e3a5f">' . htmlspecialchars(company_name()) . '</div><div class="rp-hdr">' . ucfirst($tab) . ' Report · ' . htmlspecialchars($dateFrom ?: 'All') . ' — ' . htmlspecialchars($dateTo ?: 'All') . ' · Generated ' . date('M j, Y H:i') . '</div></td>';
    echo '</tr></table>';
    if ($tab === 'sales') {
        echo '<table><tr><th colspan="2">Summary</th></tr><tr><td>Total Sales</td><td>' . $sd['total_sales'] . '</td></tr><tr><td>Total Revenue</td><td>' . $sd['total_revenue'] . '</td></tr></table>';
        echo '<table><tr><th>Date</th><th>Sales</th><th>Total</th></tr>';
        foreach ($dailyAll as $d) echo '<tr><td>' . $d['sale_date'] . '</td><td>' . $d['sale_count'] . '</td><td>' . $d['daily_total'] . '</td></tr>';
        echo '</table>';
    } elseif ($tab === 'purchase') {
        echo '<table><tr><th colspan="2">Summary</th></tr><tr><td>Total POs</td><td>' . $poSummary['total_po'] . '</td></tr><tr><td>Total Value</td><td>' . $poSummary['total_value'] . '</td></tr></table>';
        echo '<table><tr><th>Supplier</th><th>POs</th><th>Total</th></tr>';
        foreach ($poBySupplier as $r) echo '<tr><td>' . htmlspecialchars($r['name']) . '</td><td>' . $r['po_count'] . '</td><td>' . $r['total'] . '</td></tr>';
        echo '</table>';
    } elseif ($tab === 'inventory') {
        echo '<table><tr><th>Metric</th><th>Value</th></tr>';
        echo '<tr><td>Active Products</td><td>' . $stockSummary['total_products'] . '</td></tr>';
        echo '<tr><td>Total Stock</td><td>' . $stockSummary['total_stock'] . '</td></tr>';
        echo '<tr><td>Stock Value</td><td>' . $stockSummary['stock_value'] . '</td></tr></table>';
        echo '<table><tr><th>Product</th><th>Batch</th><th>Expiry</th><th>Qty</th></tr>';
        foreach ($expiringBatches as $b) echo '<tr><td>' . htmlspecialchars($b['product_name']) . '</td><td>' . htmlspecialchars($b['batch_number']) . '</td><td>' . $b['expiry_date'] . '</td><td>' . $b['quantity'] . '</td></tr>';
        echo '</table>';
    } elseif ($tab === 'customer') {
        echo '<table><tr><th>Customer</th><th>Orders</th><th>Total</th><th>Paid</th><th>Outstanding</th></tr>';
        foreach ($custOrders as $r) echo '<tr><td>' . htmlspecialchars($r['name']) . '</td><td>' . $r['order_count'] . '</td><td>' . $r['total'] . '</td><td>' . $r['paid'] . '</td><td>' . ((float)$r['total'] - (float)$r['paid']) . '</td></tr>';
        echo '</table>';
    } elseif ($tab === 'delivery') {
        echo '<table><tr><th>Order#</th><th>Customer</th><th>Staff</th><th>Total</th><th>Date</th><th>Status</th><th>Confirmed</th></tr>';
        foreach ($deliveryReport as $r) echo '<tr><td>#' . $r['id'] . '</td><td>' . htmlspecialchars($r['customer_name']) . '</td><td>' . htmlspecialchars((string)($r['staff_name'] ?? '')) . '</td><td>' . $r['total_amount'] . '</td><td>' . $r['order_date'] . '</td><td>' . ($r['delivery_status'] ?? '') . '</td><td>' . ($r['delivery_confirmed_at'] ?? '') . '</td></tr>';
        echo '</table>';
    } elseif ($tab === 'fulfillment') {
        echo '<table><tr><th>Order#</th><th>Customer</th><th>Total</th><th>Lane</th><th>Status</th><th>Payment</th><th>Paid</th><th>Pickup Time</th><th>Received By</th></tr>';
        foreach ($fulfillmentReport as $r) echo '<tr><td>#' . $r['id'] . '</td><td>' . htmlspecialchars($r['customer_name']) . '</td><td>' . $r['total_amount'] . '</td><td>' . ($fulfLabels[$r['fulfillment_method']] ?? $r['fulfillment_method']) . '</td><td>' . ($fulfLabels[$r['fulfillment_status'] ?? ''] ?? ($r['fulfillment_status'] ?? '')) . '</td><td>' . $r['payment_method'] . '</td><td>' . ($r['payment_confirmed'] ? 'Yes' : 'No') . '</td><td>' . ($r['pickup_time'] ?? '') . '</td><td>' . ($r['received_by'] ?? '') . '</td></tr>';
        echo '</table>';
    }
    echo '</body></html>'; exit;
}

$exportBase = '?page=reports&tab=' . urlencode($tab) . ($dateFrom !== '' || $dateTo !== '' ? '&date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo) : '');
?>
<section>
    <h1>Reports</h1>

    <div class="tab-bar">
        <a href="?page=reports&tab=sales" class="tab-btn<?= $tab === 'sales' ? ' active' : '' ?>"><i class="fas fa-chart-line"></i> Sales</a>
        <a href="?page=reports&tab=purchase" class="tab-btn<?= $tab === 'purchase' ? ' active' : '' ?>"><i class="fas fa-shopping-cart"></i> Purchase</a>
        <a href="?page=reports&tab=inventory" class="tab-btn<?= $tab === 'inventory' ? ' active' : '' ?>"><i class="fas fa-warehouse"></i> Inventory</a>
        <a href="?page=reports&tab=customer" class="tab-btn<?= $tab === 'customer' ? ' active' : '' ?>"><i class="fas fa-users"></i> Customer</a>
        <a href="?page=reports&tab=delivery" class="tab-btn<?= $tab === 'delivery' ? ' active' : '' ?>"><i class="fas fa-truck"></i> Delivery</a>
        <a href="?page=reports&tab=fulfillment" class="tab-btn<?= $tab === 'fulfillment' ? ' active' : '' ?>"><i class="fas fa-store"></i> Fulfillment</a>
    </div>

    <div class="panel">
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="page" value="reports">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
            <label style="margin:0">From<input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" style="margin:0"></label>
            <label style="margin:0">To<input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" style="margin:0"></label>
            <button type="submit" style="margin:0">Filter</button>
            <?php if (hasPermission('reports', 'export')): ?>
            <div class="export-dropdown">
                <button type="button" class="export-btn" onclick="toggleExport(this)"><i class="fas fa-download"></i> Export</button>
                <div class="export-dropdown-menu">
                    <a href="<?= $exportBase ?>&export=csv"><i class="fas fa-file-csv"></i> CSV</a>
                    <a href="<?= $exportBase ?>&export=xls"><i class="fas fa-file-excel"></i> Excel</a>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($dateFrom !== '' || $dateTo !== ''): ?><a href="?page=reports&tab=<?= urlencode($tab) ?>" style="display:inline-flex;align-items:center;padding:12px;color:var(--brown-800);font-weight:600">Clear</a><?php endif; ?>
        </form>
    </div>

    <?php if ($tab === 'sales'): ?>
    <div class="grid cards cards-3">
        <div class="card"><h2>Total sales</h2><p><?= $sd['total_sales'] ?></p></div>
        <div class="card"><h2>Revenue</h2><p><?= fmt((float)$sd['total_revenue']) ?></p></div>
        <div class="card"><h2>Average</h2><p><?= fmt((float)$sd['avg_sale']) ?></p></div>
    </div>
    <div class="panel split">
        <div class="panel-block">
            <h2>Sales by customer</h2>
            <table><thead><tr><th>Customer</th><th>Sales</th><th>Total</th></tr></thead>
                <tbody><?php foreach (array_slice($customerAll, 0, 20) as $r): ?><tr><td><?= htmlspecialchars($r['name']) ?></td><td><?= $r['sale_count'] ?></td><td><?= fmt((float)$r['total']) ?></td></tr><?php endforeach; ?></tbody>
            </table>
        </div>
        <div class="panel-block">
            <h2>Top products</h2>
            <table><thead><tr><th>Product</th><th>Qty</th><th>Revenue</th></tr></thead>
                <tbody><?php foreach (array_slice($productAll, 0, 20) as $r): ?><tr><td><?= htmlspecialchars($r['name']) ?></td><td><?= $r['qty_sold'] ?></td><td><?= fmt((float)$r['revenue']) ?></td></tr><?php endforeach; ?></tbody>
            </table>
        </div>
    </div>
    <div class="panel">
        <h2>Daily breakdown</h2>
        <div style="max-height:400px;overflow-y:auto">
        <table><thead><tr><th>Date</th><th>Sales</th><th>Total</th></tr></thead>
            <tbody><?php foreach (array_slice($dailyAll, 0, 60) as $r): ?><tr><td><?= $r['sale_date'] ?></td><td><?= $r['sale_count'] ?></td><td><?= fmt((float)$r['daily_total']) ?></td></tr><?php endforeach; ?></tbody>
        </table>
        </div>
    </div>

    <?php elseif ($tab === 'purchase'): ?>
    <div class="grid cards cards-3">
        <div class="card"><h2>Total POs</h2><p><?= $poSummary['total_po'] ?></p></div>
        <div class="card"><h2>Total value</h2><p><?= fmt((float)$poSummary['total_value']) ?></p></div>
    </div>
    <div class="panel split">
        <div class="panel-block">
            <h2>By Supplier</h2>
            <table><thead><tr><th>Supplier</th><th>POs</th><th>Total</th></tr></thead>
                <tbody><?php foreach ($poBySupplier as $r): ?><tr><td><?= htmlspecialchars($r['name']) ?></td><td><?= $r['po_count'] ?></td><td><?= fmt((float)$r['total']) ?></td></tr><?php endforeach; ?></tbody>
            </table>
        </div>
        <div class="panel-block">
            <h2>By Status</h2>
            <table><thead><tr><th>Status</th><th>Count</th><th>Total</th></tr></thead>
                <tbody><?php foreach ($poByStatus as $r): ?><tr><td style="font-weight:700"><?= ucfirst($r['status']) ?></td><td><?= $r['cnt'] ?></td><td><?= fmt((float)$r['total']) ?></td></tr><?php endforeach; ?></tbody>
            </table>
        </div>
    </div>
    <div class="panel">
        <h2>Top Purchased Products</h2>
        <table><thead><tr><th>Product</th><th>Ordered</th><th>Received</th><th>Total Cost</th></tr></thead>
            <tbody><?php foreach ($poItems as $r): ?><tr><td><?= htmlspecialchars($r['product']) ?></td><td><?= $r['qty_ordered'] ?></td><td><?= $r['qty_received'] ?></td><td><?= fmt((float)$r['total_cost']) ?></td></tr><?php endforeach; ?></tbody>
        </table>
    </div>

    <?php elseif ($tab === 'inventory'): ?>
    <div class="grid cards cards-3">
        <div class="card"><h2>Active products</h2><p><?= $stockSummary['total_products'] ?></p></div>
        <div class="card"><h2>Total stock</h2><p><?= $stockSummary['total_stock'] ?> units</p></div>
        <div class="card"><h2>Stock value</h2><p><?= fmt((float)$stockSummary['stock_value']) ?></p></div>
        <div class="card"><h2>Active batches</h2><p><?= $batchCount ?></p></div>
    </div>
    <div style="display:flex;gap:16px;flex-wrap:wrap">
        <div class="panel" style="flex:1;min-width:260px">
            <h2>⚠ Expiring (90 days)</h2>
            <table><thead><tr><th>Product</th><th>Batch</th><th>Expiry</th><th>Qty</th></tr></thead>
                <tbody><?php foreach ($expiringBatches as $b): ?><tr><td><?= htmlspecialchars($b['product_name']) ?></td><td><?= htmlspecialchars($b['batch_number']) ?></td><td style="color:<?= strtotime($b['expiry_date']) <= time() ? '#dc2626' : '#f59e0b' ?>;font-weight:700"><?= $b['expiry_date'] ?></td><td><?= (int)$b['quantity'] ?></td></tr><?php endforeach; ?></tbody>
            </table>
        </div>
        <div class="panel" style="flex:1;min-width:260px">
            <h2>⚠ Low Stock (≤ 10)</h2>
            <table><thead><tr><th>Product</th><th>Stock</th><th>Value</th></tr></thead>
                <tbody><?php foreach ($lowStock as $p): ?><tr><td><?= htmlspecialchars($p['name']) ?></td><td style="color:#dc2626;font-weight:700"><?= $p['stock_quantity'] ?></td><td><?= fmt((float)$p['purchase_price'] * (int)$p['stock_quantity']) ?></td></tr><?php endforeach; ?></tbody>
            </table>
        </div>
    </div>
    <?php if (!empty($zeroStock)): ?>
    <div class="panel">
        <h2>Out of Stock</h2>
        <table><thead><tr><th>Product</th></tr></thead>
            <tbody><?php foreach ($zeroStock as $p): ?><tr><td><?= htmlspecialchars($p['name']) ?></td></tr><?php endforeach; ?></tbody>
        </table>
    </div>
    <?php endif; ?>
    <?php if (!empty($stockByWh)): ?>
    <div class="panel">
        <h2>Stock by Warehouse</h2>
        <table><thead><tr><th>Warehouse</th><th>Batch Stock</th></tr></thead>
            <tbody><?php foreach ($stockByWh as $r): ?><tr><td><?= htmlspecialchars($r['name']) ?></td><td><?= $r['batch_qty'] ?> units</td></tr><?php endforeach; ?></tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php elseif ($tab === 'customer'): ?>
    <div class="grid cards cards-2">
        <div class="card"><h2>Total customers</h2><p><?= count($custRows) ?></p></div>
        <div class="card"><h2>With credit</h2><p><?= count(array_filter($custRows, fn($c) => (float)$c['credit_limit'] > 0)) ?></p></div>
    </div>
    <div class="panel">
        <h2>Customer Order Summary</h2>
        <table><thead><tr><th>Customer</th><th>Orders</th><th>Total</th><th>Paid</th><th>Outstanding</th></tr></thead>
            <tbody><?php foreach ($custOrders as $r): $out = (float)$r['total'] - (float)$r['paid']; ?><tr><td><?= htmlspecialchars($r['name']) ?></td><td><?= $r['order_count'] ?></td><td><?= fmt((float)$r['total']) ?></td><td><?= fmt((float)$r['paid']) ?></td><td style="font-weight:700;color:<?= $out > 0 ? '#dc2626' : '#059669' ?>"><?= fmt($out) ?></td></tr><?php endforeach; ?></tbody>
        </table>
    </div>
    <div class="panel">
        <h2>Customer Details</h2>
        <div style="max-height:500px;overflow-y:auto">
        <table><thead><tr><th>Name</th><th>Type</th><th>License</th><th>TIN</th><th>Phone</th><th>Credit Limit</th><th>Outstanding</th><th>Status</th></tr></thead>
            <tbody><?php foreach ($custRows as $c): ?><tr><td><?= htmlspecialchars($c['name']) ?></td><td><?= htmlspecialchars($c['customer_type'] ?? '—') ?></td><td><?= htmlspecialchars($c['license_number'] ?? '—') ?></td><td><?= htmlspecialchars($c['tin_number'] ?? '—') ?></td><td><?= htmlspecialchars($c['phone'] ?? '—') ?></td><td><?= fmt((float)$c['credit_limit']) ?></td><td style="font-weight:700;color:<?= (float)$c['outstanding'] > 0 ? '#dc2626' : '#059669' ?>"><?= fmt((float)$c['outstanding']) ?></td><td><span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:0.75rem;font-weight:700;background:<?= ($c['customer_status'] ?? 'active') === 'active' ? '#059669' : '#dc2626' ?>20;color:<?= ($c['customer_status'] ?? 'active') === 'active' ? '#059669' : '#dc2626' ?>"><?= ucfirst($c['customer_status'] ?? 'active') ?></span></td></tr><?php endforeach; ?></tbody>
        </table>
        </div>
    </div>
    <?php elseif ($tab === 'delivery'): ?>
    <div class="grid cards cards-4">
        <div class="card"><h2>Total Deliveries</h2><p><?= (int) ($delSummary['total'] ?? 0) ?></p></div>
        <div class="card"><h2>Delivered</h2><p style="color:var(--green-700)"><?= (int) ($delSummary['delivered'] ?? 0) ?></p></div>
        <div class="card"><h2>Out for Delivery</h2><p style="color:var(--brown-700)"><?= (int) ($delSummary['out_for_delivery'] ?? 0) ?></p></div>
        <div class="card"><h2>Assigned</h2><p><?= (int) ($delSummary['assigned'] ?? 0) ?></p></div>
    </div>
    <div class="panel">
        <h2>Delivery History</h2>
        <table><thead><tr><th>Order#</th><th>Customer</th><th>Staff</th><th>Total</th><th>Date</th><th>Status</th><th>Confirmed</th></tr></thead>
            <tbody><?php foreach ($deliveryReport as $r): ?><tr><td>#<?= (int)$r['id'] ?></td><td><?= htmlspecialchars($r['customer_name']) ?></td><td><?= htmlspecialchars($r['staff_name'] ?? '-') ?></td><td><?= fmt((float)$r['total_amount']) ?></td><td><?= htmlspecialchars($r['order_date']) ?></td><td><span class="status-badge status-<?= $r['delivery_status'] === 'delivered' ? 'approved' : ($r['delivery_status'] === 'out_for_delivery' ? 'pending' : '') ?>"><?= htmlspecialchars((string)($r['delivery_status'] ?? '')) ?></span></td><td><?= htmlspecialchars((string)($r['delivery_confirmed_at'] ?? '-')) ?></td></tr><?php endforeach; ?></tbody>
        </table>
    </div>

    <?php elseif ($tab === 'fulfillment'): ?>
    <div class="grid cards cards-4">
        <div class="card"><h2>Pickup Orders</h2><p style="color:var(--brown-700)"><i class="fas fa-store"></i> <?= (int) ($fulfillmentSummary['pickup_total'] ?? 0) ?></p></div>
        <div class="card"><h2>Delivery Orders</h2><p style="color:var(--blue-700,#1d4ed8)"><i class="fas fa-truck"></i> <?= (int) ($fulfillmentSummary['delivery_total'] ?? 0) ?></p></div>
        <div class="card"><h2>Pickup Completed</h2><p style="color:var(--green-700)"><?= (int) ($fulfillmentSummary['pickup_completed'] ?? 0) ?></p></div>
        <div class="card"><h2>Delivery Completed</h2><p style="color:var(--green-700)"><?= (int) ($fulfillmentSummary['delivery_completed'] ?? 0) ?></p></div>
    </div>
    <div class="grid cards cards-2">
        <div class="card"><h2>Awaiting Pickup</h2><p><?= (int) ($fulfillmentSummary['pickup_waiting'] ?? 0) ?></p></div>
        <div class="card"><h2>Ready for Delivery</h2><p><?= (int) ($fulfillmentSummary['delivery_ready'] ?? 0) ?></p></div>
    </div>
    <div class="panel">
        <h2>Fulfillment Order Log (Pickup vs Delivery)</h2>
        <div style="max-height:500px;overflow-y:auto">
        <table><thead><tr><th>Order#</th><th>Customer</th><th>Total</th><th>Lane</th><th>Status</th><th>Payment</th><th>Paid</th><th>Prepared</th><th>Pickup Time</th><th>Received By</th></tr></thead>
            <tbody><?php foreach ($fulfillmentReport as $r):
                $lane = $r['fulfillment_method'] === StatusCatalog::FULFILLMENT_METHOD_PICKUP ? '<span style="display:inline-block;padding:2px 8px;border-radius:20px;font-size:0.72rem;font-weight:700;background:#fef3c7;color:#92400e">Pickup</span>' : '<span style="display:inline-block;padding:2px 8px;border-radius:20px;font-size:0.72rem;font-weight:700;background:#dbeafe;color:#1e40af">Delivery</span>';
                $stKey = $r['fulfillment_status'] ?? '';
                $stLabel = $fulfLabels[$stKey] ?? ($stKey !== '' ? $stKey : '—');
                $stColor = in_array($stKey, ['fulfillment_completed'], true) ? '#059669' : (in_array($stKey, ['out_of_stock', 'fulfillment_cancelled'], true) ? '#dc2626' : '#0f172a');
            ?><tr>
                <td style="font-weight:700">#<?= (int)$r['id'] ?></td>
                <td><?= htmlspecialchars($r['customer_name']) ?></td>
                <td><?= fmt((float)$r['total_amount']) ?></td>
                <td><?= $lane ?></td>
                <td style="font-weight:600;color:<?= $stColor ?>"><?= htmlspecialchars($stLabel) ?></td>
                <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $r['payment_method'] ?? ''))) ?></td>
                <td><?= $r['payment_confirmed'] ? 'Yes' : 'No' ?></td>
                <td style="font-size:0.78rem;color:var(--neutral-500)"><?= !empty($r['prepared_at']) ? date('M d, H:i', strtotime($r['prepared_at'])) : '—' ?></td>
                <td style="font-size:0.78rem;color:var(--neutral-500)"><?= !empty($r['pickup_time']) ? date('M d, H:i', strtotime($r['pickup_time'])) : '—' ?></td>
                <td><?= htmlspecialchars((string)($r['received_by'] ?? '—')) ?></td>
            </tr><?php endforeach; ?></tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>
</section>
