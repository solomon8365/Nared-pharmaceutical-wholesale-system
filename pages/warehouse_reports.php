<?php
requirePermission('reports', 'view');
require_once __DIR__ . '/../includes/stock_helper.php';
$pdo = getPDO();

$tab = $_GET['tab'] ?? 'stock_summary';
$allowedTabs = ['stock_summary', 'stock_movement', 'purchase_receiving', 'expiry', 'quarantine', 'disposal', 'low_stock', 'order_fulfillment'];
if (!in_array($tab, $allowedTabs, true)) { $tab = 'stock_summary'; }

$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$productFilter = (int)($_GET['product_id'] ?? 0);
$typeFilter = trim($_GET['type'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$search = trim($_GET['q'] ?? '');
$export = $_GET['export'] ?? '';
$perPage = 25;
$wrPage = max(1, (int)($_GET['pg'] ?? 1));

$users = [];
foreach ($pdo->query("SELECT id, full_name FROM users")->fetchAll() as $r) { $users[(int)$r['id']] = $r['full_name']; }
$products = $pdo->query("SELECT id, name, sku, stock_quantity, reorder_level, purchase_price, product_status FROM products ORDER BY name")->fetchAll();
$prodMap = [];
foreach ($products as $p) { $prodMap[(int)$p['id']] = $p; }

function wrH($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function wrBadge(string $label, string $color): string {
    return '<span class="status-badge" style="background:' . $color . '20;color:' . $color . ';border:1px solid ' . $color . '">' . wrH($label) . '</span>';
}
function wrCard(string $label, $value, string $color = ''): string {
    return '<div class="card"><h2>' . wrH($label) . '</h2><p' . ($color ? ' style="color:' . $color . '"' : '') . '>' . $value . '</p></div>';
}
function wrPager(int $total, int $perPage, int $page, string $base): string {
    $pages = max(1, (int)ceil($total / $perPage));
    if ($pages <= 1) return '';
    $out = '<div class="pager" style="display:flex;gap:6px;align-items:center;justify-content:flex-end;margin-top:12px;flex-wrap:wrap">';
    $prev = max(1, $page - 1); $next = min($pages, $page + 1);
    $out .= '<a class="pg-btn" href="' . $base . '&pg=1">«</a>';
    $out .= '<a class="pg-btn" href="' . $base . '&pg=' . $prev . '">‹</a>';
    for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++) {
        $out .= '<a class="pg-btn' . ($p === $page ? ' active' : '') . '" href="' . $base . '&pg=' . $p . '">' . $p . '</a>';
    }
    $out .= '<a class="pg-btn" href="' . $base . '&pg=' . $next . '">›</a>';
    $out .= '<a class="pg-btn" href="' . $base . '&pg=' . $pages . '">»</a>';
    $out .= '<span style="font-size:0.78rem;color:var(--neutral-500);margin-left:8px">' . $total . ' records</span></div>';
    return $out;
}
function wrSlice(array $rows, int $perPage, int $page): array {
    return array_slice($rows, ($page - 1) * $perPage, $perPage);
}

$base = '?page=warehouse_reports&tab=' . urlencode($tab);
$baseFull = $base;
foreach (['date_from' => $dateFrom, 'date_to' => $dateTo, 'product_id' => $productFilter, 'type' => $typeFilter, 'status' => $statusFilter, 'q' => $search] as $k => $v) {
    if ($v !== '' && $v !== 0) { $baseFull .= '&' . $k . '=' . urlencode((string)$v); }
}

$fulfLabels = [
    'waiting_stock_check' => 'Waiting Stock Check', 'ready_to_prepare' => 'Ready to Prepare',
    'reserved' => 'Reserved', 'out_of_stock' => 'Out of Stock', 'preparing' => 'Preparing',
    'prepared' => 'Prepared', 'paid' => 'Paid', 'credit_approved' => 'Credit Approved',
    'waiting_customer_pickup' => 'Waiting Customer Pickup', 'ready_for_delivery' => 'Ready for Delivery',
    'fulfillment_completed' => 'Completed', 'fulfillment_cancelled' => 'Cancelled',
];

// ─── Data layer per tab ───────────────────────────────────
$tblHeaders = [];
$tblRows = [];
$cards = [];

if ($tab === 'stock_summary') {
    $reservedMap = [];
    foreach ($pdo->query("SELECT coi.product_id, SUM(coi.quantity) q FROM customer_order_items coi JOIN customer_orders co ON co.id = coi.customer_order_id WHERE co.reserved_status = 'reserved' GROUP BY coi.product_id")->fetchAll() as $r) { $reservedMap[(int)$r['product_id']] = (int)$r['q']; }
    $quarantineMap = [];
    foreach ($pdo->query("SELECT product_id, SUM(quantity) q FROM product_batches WHERE status = 'quarantined' GROUP BY product_id")->fetchAll() as $r) { $quarantineMap[(int)$r['product_id']] = (int)$r['q']; }
    $expiredMap = [];
    foreach ($pdo->query("SELECT product_id, SUM(quantity) q FROM product_batches WHERE quantity > 0 AND expiry_date < CURDATE() AND status <> 'disposed' GROUP BY product_id")->fetchAll() as $r) { $expiredMap[(int)$r['product_id']] = (int)$r['q']; }
    $disposedTotal = (int)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM product_disposals")->fetchColumn();

    $rows = [];
    $totalAvail = 0; $totalValue = 0; $lowCount = 0;
    foreach ($products as $p) {
        $pid = (int)$p['id'];
        $avail = (int)$p['stock_quantity'];
        $quar = $quarantineMap[$pid] ?? 0;
        $exp = $expiredMap[$pid] ?? 0;
        $res = $reservedMap[$pid] ?? 0;
        $reorder = (int)$p['reorder_level'];
        $thr = $reorder > 0 ? $reorder : 10;
        $status = $avail <= 0 ? 'Out' : ($avail <= $thr ? 'Low' : 'In Stock');
        $rows[] = ['id' => $pid, 'name' => $p['name'], 'sku' => $p['sku'], 'available' => $avail, 'physical' => $avail + $quar + $exp, 'reserved' => $res, 'quarantined' => $quar, 'expired' => $exp, 'reorder' => $reorder, 'status' => $status, 'value' => (float)$avail * (float)$p['purchase_price']];
        $totalAvail += $avail; $totalValue += $avail * (float)$p['purchase_price'];
        if ($status !== 'In Stock') { $lowCount++; }
    }
    $totalReserved = array_sum($reservedMap);
    $totalQuar = array_sum($quarantineMap);
    $totalExpired = array_sum($expiredMap);

    if ($productFilter) { $rows = array_values(array_filter($rows, fn($r) => $r['id'] === $productFilter)); }
    if ($search !== '') { $rows = array_values(array_filter($rows, fn($r) => stripos($r['name'] . ' ' . $r['sku'], $search) !== false)); }

    $cards = [
        ['label' => 'Active Products', 'value' => count($products)],
        ['label' => 'Available Units', 'value' => number_format($totalAvail)],
        ['label' => 'Stock Value', 'value' => fmt($totalValue)],
        ['label' => 'Reserved Units', 'value' => number_format($totalReserved), 'color' => '#1d4ed8'],
        ['label' => 'Quarantined', 'value' => number_format($totalQuar), 'color' => '#d97706'],
        ['label' => 'Expired', 'value' => number_format($totalExpired), 'color' => '#dc2626'],
        ['label' => 'Disposed', 'value' => number_format($disposedTotal), 'color' => '#6b7280'],
        ['label' => 'Low / Out of Stock', 'value' => $lowCount, 'color' => '#dc2626'],
    ];
    $tblHeaders = ['Product', 'SKU', 'Physical', 'Available', 'Reserved', 'Quarantined', 'Expired', 'Reorder Level', 'Value', 'Status'];
    foreach ($rows as $r) {
        $badge = $r['status'] === 'Out' ? wrBadge('Out of Stock', '#dc2626') : ($r['status'] === 'Low' ? wrBadge('Low Stock', '#d97706') : wrBadge('In Stock', '#059669'));
        $tblRows[] = [$r['name'], $r['sku'], number_format($r['physical']), number_format($r['available']), number_format($r['reserved']), number_format($r['quarantined']), number_format($r['expired']), $r['reorder'] > 0 ? $r['reorder'] : '-', fmt($r['value']), $badge];
    }
} elseif ($tab === 'stock_movement') {
    $movements = $pdo->query("SELECT sm.*, p.name product_name, p.sku FROM stock_movements sm JOIN products p ON p.id = sm.product_id ORDER BY sm.id")->fetchAll();
    $byProduct = [];
    foreach ($movements as $m) { $byProduct[(int)$m['product_id']][] = $m; }
    $offset = [];
    foreach ($byProduct as $pid => $list) {
        $sum = 0;
        foreach ($list as $m) { $sum += (float)$m['quantity_change']; }
        $current = isset($prodMap[$pid]) ? (float)$prodMap[$pid]['stock_quantity'] : 0;
        $offset[$pid] = $current - $sum;
    }
    $typeLabels = [
        'sale' => 'Sale', 'purchase_order' => 'Purchase', 'order' => 'Restock', 'SYNC' => 'Sync',
        'physical_count' => 'Count', 'product_disposal' => 'Disposal', 'product_batch' => 'Quarantine',
        'stock_transfer' => 'Transfer', 'purchase_return' => 'Purchase Return', 'sales_return' => 'Sales Return',
    ];
    $performerCache = [];
    function performerFor(array $m, array $users): string {
        global $pdo, $performerCache;
        $key = $m['reference_type'] . ':' . $m['reference_id'];
        if (isset($performerCache[$key])) { return $performerCache[$key]; }
        $rt = $m['reference_type'];
        if ($rt === 'SYNC') { return $performerCache[$key] = 'System Sync'; }
        if ($rt === 'sale') {
            $stmt = $pdo->prepare("SELECT agent_name FROM sales WHERE id = ?"); $stmt->execute([$m['reference_id']]);
            $name = $stmt->fetchColumn();
            return $performerCache[$key] = $name ?: 'Sales';
        }
        if ($rt === 'order' || $rt === 'purchase_order') {
            $stmt = $pdo->prepare("SELECT created_by FROM purchase_orders WHERE id = ?"); $stmt->execute([$m['reference_id']]);
            $uid = (int)$stmt->fetchColumn();
            return $performerCache[$key] = ($uid > 0 ? ($users[$uid] ?? '') : '') ?: 'Purchasing';
        }
        if ($rt === 'physical_count') {
            $stmt = $pdo->prepare("SELECT created_by FROM physical_counts WHERE id = ?"); $stmt->execute([$m['reference_id']]);
            $uid = (int)$stmt->fetchColumn();
            return $performerCache[$key] = ($uid > 0 ? ($users[$uid] ?? '') : '') ?: 'Stock Count';
        }
        if ($rt === 'product_disposal') {
            $stmt = $pdo->prepare("SELECT created_by FROM product_disposals WHERE id = ?"); $stmt->execute([$m['reference_id']]);
            $uid = (int)$stmt->fetchColumn();
            return $performerCache[$key] = ($uid > 0 ? ($users[$uid] ?? '') : '') ?: 'Disposal';
        }
        if ($rt === 'product_batch') {
            $stmt = $pdo->prepare("SELECT workflow_assigned_to FROM product_batches WHERE id = ?"); $stmt->execute([$m['reference_id']]);
            $uid = (int)$stmt->fetchColumn();
            return $performerCache[$key] = ($uid > 0 ? ($users[$uid] ?? '') : '') ?: 'Quarantine';
        }
        if ($rt === 'stock_transfer') {
            $stmt = $pdo->prepare("SELECT created_by FROM stock_transfers WHERE id = ?"); $stmt->execute([$m['reference_id']]);
            $uid = (int)$stmt->fetchColumn();
            return $performerCache[$key] = ($uid > 0 ? ($users[$uid] ?? '') : '') ?: 'Transfer';
        }
        return $performerCache[$key] = 'System';
    }
    $running = [];
    $rows = [];
    foreach ($movements as $m) {
        $pid = (int)$m['product_id'];
        if (!isset($running[$pid])) { $running[$pid] = 0; }
        $after = $offset[$pid] + $running[$pid] + (float)$m['quantity_change'];
        $before = $after - (float)$m['quantity_change'];
        $running[$pid] += (float)$m['quantity_change'];
        $type = $typeLabels[$m['reference_type']] ?? ($m['reason'] ?: 'Other');
        $rows[] = [
            'id' => (int)$m['id'], 'date' => $m['created_at'], 'product' => $m['product_name'], 'sku' => $m['sku'],
            'pid' => $pid, 'type' => $type, 'reftype' => $m['reference_type'], 'change' => (float)$m['quantity_change'], 'before' => $before,
            'after' => $after, 'ref' => $m['reference_type'] . '#' . $m['reference_id'], 'reason' => $m['reason'],
            'performer' => performerFor($m, $users),
        ];
    }
    if ($dateFrom !== '') { $rows = array_values(array_filter($rows, fn($r) => $r['date'] >= $dateFrom)); }
    if ($dateTo !== '') { $rows = array_values(array_filter($rows, fn($r) => substr($r['date'], 0, 10) <= $dateTo)); }
    if ($productFilter) { $rows = array_values(array_filter($rows, fn($r) => $r['pid'] === $productFilter)); }
    if ($typeFilter !== '') { $rows = array_values(array_filter($rows, fn($r) => $r['reftype'] === $typeFilter)); }
    if ($search !== '') { $rows = array_values(array_filter($rows, fn($r) => stripos($r['product'] . ' ' . $r['reason'] . ' ' . $r['performer'], $search) !== false)); }
    $in = array_sum(array_map(fn($r) => $r['change'] > 0 ? $r['change'] : 0, $rows));
    $out = array_sum(array_map(fn($r) => $r['change'] < 0 ? abs($r['change']) : 0, $rows));

    $cards = [
        ['label' => 'Movements', 'value' => count($rows)],
        ['label' => 'Stock In', 'value' => number_format($in), 'color' => '#059669'],
        ['label' => 'Stock Out', 'value' => number_format($out), 'color' => '#dc2626'],
        ['label' => 'Net Change', 'value' => number_format($in - $out)],
    ];
    $tblHeaders = ['Date', 'Product', 'SKU', 'Type', 'Qty', 'Before', 'After', 'Reference', 'Reason', 'Performed By'];
    foreach ($rows as $r) {
        $change = $r['change'] > 0 ? '<span style="color:#059669;font-weight:700">+' . number_format($r['change']) . '</span>' : '<span style="color:#dc2626;font-weight:700">' . number_format($r['change']) . '</span>';
        $tblRows[] = [date('Y-m-d H:i', strtotime($r['date'])), $r['product'], $r['sku'], wrBadge($r['type'], '#1e3a5f'), $change, number_format($r['before']), number_format($r['after']), wrH($r['ref']), wrH($r['reason']), wrH($r['performer'])];
    }
} elseif ($tab === 'purchase_receiving') {
    $sql = "SELECT po.id po_id, po.order_date, po.received_at, po.status, po.created_by,
            s.name supplier_name, poi.product_id, p.name product_name, p.sku,
            poi.quantity ordered_qty, poi.received_qty received_qty, poi.unit_price,
            (SELECT u.full_name FROM status_history sh JOIN users u ON u.id = sh.changed_by
             WHERE sh.workflow = 'purchase' AND sh.entity_id = po.id AND sh.to_status = 'received'
             ORDER BY sh.id DESC LIMIT 1) received_by,
            (SELECT pb.batch_number FROM product_batches pb JOIN stock_movements sm
             ON sm.product_id = pb.product_id AND sm.reference_type = 'purchase_order'
             AND sm.reference_id = po.id AND sm.created_at = pb.created_at
             WHERE pb.product_id = poi.product_id ORDER BY pb.id DESC LIMIT 1) batch_no,
            (SELECT pb.expiry_date FROM product_batches pb JOIN stock_movements sm
             ON sm.product_id = pb.product_id AND sm.reference_type = 'purchase_order'
             AND sm.reference_id = po.id AND sm.created_at = pb.created_at
             WHERE pb.product_id = poi.product_id ORDER BY pb.id DESC LIMIT 1) expiry_date
            FROM purchase_orders po
            JOIN suppliers s ON s.id = po.supplier_id
            JOIN purchase_order_items poi ON poi.purchase_order_id = po.id
            JOIN products p ON p.id = poi.product_id
            WHERE poi.received_qty > 0";
    $rows = $pdo->query($sql)->fetchAll();
    if ($dateFrom !== '') { $rows = array_values(array_filter($rows, fn($r) => $r['received_at'] >= $dateFrom || ($r['received_at'] === null && $r['order_date'] >= $dateFrom))); }
    if ($dateTo !== '') { $rows = array_values(array_filter($rows, fn($r) => ($r['received_at'] !== null ? substr($r['received_at'], 0, 10) : substr($r['order_date'], 0, 10)) <= $dateTo)); }
    if ($statusFilter !== '') { $rows = array_values(array_filter($rows, fn($r) => $r['status'] === $statusFilter)); }
    if ($productFilter) { $rows = array_values(array_filter($rows, fn($r) => (int)$r['product_id'] === $productFilter)); }
    if ($search !== '') { $rows = array_values(array_filter($rows, fn($r) => stripos($r['supplier_name'] . ' ' . $r['product_name'] . ' #' . $r['po_id'], $search) !== false)); }
    $totalRecv = 0; $totalVal = 0;
    foreach ($rows as $r) { $totalRecv += (int)$r['received_qty']; $totalVal += (float)$r['received_qty'] * (float)$r['unit_price']; }

    $cards = [
        ['label' => 'Receiving Lines', 'value' => count($rows)],
        ['label' => 'Units Received', 'value' => number_format($totalRecv)],
        ['label' => 'Received Value', 'value' => fmt($totalVal)],
        ['label' => 'Open Lines', 'value' => count(array_filter($rows, fn($r) => (int)$r['received_qty'] < (int)$r['ordered_qty'])), 'color' => '#d97706'],
    ];
    $tblHeaders = ['PO#', 'Date', 'Supplier', 'Product', 'Ordered', 'Received', 'Remaining', 'Batch/Lot', 'Expiry', 'Received By', 'Received Date', 'Status'];
    foreach ($rows as $r) {
        $remaining = max(0, (int)$r['ordered_qty'] - (int)$r['received_qty']);
        $stColor = in_array($r['status'], ['received', 'payment_approved'], true) ? '#059669' : '#d97706';
        $tblRows[] = ['#' . $r['po_id'], $r['order_date'], $r['supplier_name'], $r['product_name'], $r['ordered_qty'], $r['received_qty'], $remaining, $r['batch_no'] ?: '-', $r['expiry_date'] ?: '-', $r['received_by'] ?: 'System', $r['received_at'] ?: '-', wrBadge((string)$r['status'], $stColor)];
    }
} elseif ($tab === 'expiry') {
    $rows = $pdo->query("SELECT pb.*, p.name product_name, p.sku, w.name warehouse_name,
            DATEDIFF(pb.expiry_date, CURDATE()) days_left
            FROM product_batches pb JOIN products p ON p.id = pb.product_id
            LEFT JOIN warehouses w ON w.id = pb.warehouse_id
            WHERE pb.quantity > 0 AND pb.status <> 'disposed' ORDER BY pb.expiry_date")->fetchAll();
    if ($productFilter) { $rows = array_values(array_filter($rows, fn($r) => (int)$r['product_id'] === $productFilter)); }
    if ($search !== '') { $rows = array_values(array_filter($rows, fn($r) => stripos($r['product_name'] . ' ' . $r['batch_number'], $search) !== false)); }
    $expired = array_filter($rows, fn($r) => (int)$r['days_left'] < 0);
    $soon30 = array_filter($rows, fn($r) => (int)$r['days_left'] >= 0 && (int)$r['days_left'] <= 30);
    $soon90 = array_filter($rows, fn($r) => (int)$r['days_left'] > 30 && (int)$r['days_left'] <= 90);
    $valid = array_filter($rows, fn($r) => (int)$r['days_left'] > 90);
    if ($statusFilter !== '') {
        $rows = array_values(match ($statusFilter) {
            'expired' => $expired, 'soon30' => $soon30, 'soon90' => $soon90, 'valid' => $valid, default => $rows,
        });
    }
    $cards = [
        ['label' => 'Expired', 'value' => count($expired) . ' batches', 'color' => '#dc2626'],
        ['label' => 'Expiring ≤ 30d', 'value' => count($soon30) . ' batches', 'color' => '#d97706'],
        ['label' => 'Expiring 31–90d', 'value' => count($soon90) . ' batches', 'color' => '#ca8a04'],
        ['label' => 'Valid', 'value' => count($valid) . ' batches', 'color' => '#059669'],
    ];
    $tblHeaders = ['Product', 'SKU', 'Batch', 'Qty', 'Expiry', 'Days Left', 'Location', 'Status'];
    foreach ($rows as $r) {
        $d = (int)$r['days_left'];
        $color = $d < 0 ? '#dc2626' : ($d <= 30 ? '#d97706' : ($d <= 90 ? '#ca8a04' : '#059669'));
        $label = $d < 0 ? 'Expired' : ($d <= 30 ? 'Expiring Soon' : ($d <= 90 ? 'Expiring' : 'Valid'));
        $tblRows[] = [$r['product_name'], $r['sku'], $r['batch_number'], $r['quantity'], $r['expiry_date'] ?: '-', '<span style="color:' . $color . ';font-weight:700">' . $d . '</span>', $r['warehouse_name'] ?: '-', wrBadge($label, $color)];
    }
} elseif ($tab === 'quarantine') {
    $rows = $pdo->query("SELECT pb.*, p.name product_name, p.sku, w.name warehouse_name,
            DATEDIFF(pb.expiry_date, CURDATE()) days_left, DATEDIFF(CURDATE(), pb.quarantined_at) days_q
            FROM product_batches pb JOIN products p ON p.id = pb.product_id
            LEFT JOIN warehouses w ON w.id = pb.warehouse_id
            WHERE pb.status = 'quarantined' AND pb.quantity > 0 ORDER BY pb.quarantined_at DESC")->fetchAll();
    if ($search !== '') { $rows = array_values(array_filter($rows, fn($r) => stripos($r['product_name'] . ' ' . $r['batch_number'], $search) !== false)); }
    $totalQ = 0; $totalVal = 0; $expiredQ = 0;
    foreach ($rows as $r) {
        $q = (int)$r['quantity'];
        $totalQ += $q;
        $pp = isset($prodMap[(int)$r['product_id']]) ? (float)$prodMap[(int)$r['product_id']]['purchase_price'] : 0;
        $totalVal += $q * $pp;
        if ((int)$r['days_left'] < 0) { $expiredQ += $q; }
    }
    $cards = [
        ['label' => 'Quarantined Units', 'value' => number_format($totalQ), 'color' => '#d97706'],
        ['label' => 'Quarantined Batches', 'value' => count($rows)],
        ['label' => 'Expired in Quarantine', 'value' => number_format($expiredQ), 'color' => '#dc2626'],
        ['label' => 'Value', 'value' => fmt($totalVal)],
    ];
    $tblHeaders = ['Product', 'SKU', 'Batch', 'Qty', 'Expiry', 'Quarantined At', 'Days in Q', 'Location'];
    foreach ($rows as $r) {
        $tblRows[] = [$r['product_name'], $r['sku'], $r['batch_number'], $r['quantity'], $r['expiry_date'] ?: '-', $r['quarantined_at'] ?: '-', $r['days_q'] !== null ? $r['days_q'] : '-', $r['warehouse_name'] ?: '-'];
    }
} elseif ($tab === 'disposal') {
    $rows = $pdo->query("SELECT pd.*, p.name product_name, p.sku, u.full_name approved_name
            FROM product_disposals pd JOIN products p ON p.id = pd.product_id
            LEFT JOIN users u ON u.id = pd.approved_by ORDER BY pd.disposal_date DESC, pd.id DESC")->fetchAll();
    if ($dateFrom !== '') { $rows = array_values(array_filter($rows, fn($r) => $r['disposal_date'] >= $dateFrom)); }
    if ($dateTo !== '') { $rows = array_values(array_filter($rows, fn($r) => substr($r['disposal_date'], 0, 10) <= $dateTo)); }
    if ($search !== '') { $rows = array_values(array_filter($rows, fn($r) => stripos($r['product_name'] . ' ' . $r['disposal_reason'] . ' ' . $r['batch_number'], $search) !== false)); }
    $totalQ = 0; $totalVal = 0;
    foreach ($rows as $r) {
        $q = (int)$r['quantity'];
        $totalQ += $q;
        $pp = isset($prodMap[(int)$r['product_id']]) ? (float)$prodMap[(int)$r['product_id']]['purchase_price'] : 0;
        $totalVal += $q * $pp;
    }
    $cards = [
        ['label' => 'Disposals', 'value' => count($rows)],
        ['label' => 'Units Disposed', 'value' => number_format($totalQ), 'color' => '#dc2626'],
        ['label' => 'Disposed Value', 'value' => fmt($totalVal), 'color' => '#6b7280'],
        ['label' => 'Avg/Disposal', 'value' => count($rows) ? fmt($totalVal / count($rows)) : fmt(0)],
    ];
    $tblHeaders = ['Date', 'Product', 'Batch', 'Qty', 'Reason', 'Approved By', 'Remarks'];
    foreach ($rows as $r) {
        $tblRows[] = [$r['disposal_date'], $r['product_name'], $r['batch_number'] ?: '-', $r['quantity'], $r['disposal_reason'], $r['approved_name'] ?: '-', $r['remarks'] ?: '-'];
    }
} elseif ($tab === 'low_stock') {
    $rows = [];
    foreach ($products as $p) {
        $avail = (int)$p['stock_quantity'];
        $reorder = (int)$p['reorder_level'];
        $thr = $reorder > 0 ? $reorder : 10;
        if ($avail <= $thr) {
            $rows[] = ['id' => (int)$p['id'], 'name' => $p['name'], 'sku' => $p['sku'], 'available' => $avail, 'threshold' => $thr, 'reorder' => $reorder, 'shortage' => max($thr - $avail, 0), 'status' => $avail <= 0 ? 'Out' : 'Low'];
        }
    }
    if ($productFilter) { $rows = array_values(array_filter($rows, fn($r) => $r['id'] === $productFilter)); }
    if ($search !== '') { $rows = array_values(array_filter($rows, fn($r) => stripos($r['name'] . ' ' . $r['sku'], $search) !== false)); }
    $outCount = count(array_filter($rows, fn($r) => $r['status'] === 'Out'));
    $cards = [
        ['label' => 'Out of Stock', 'value' => $outCount, 'color' => '#dc2626'],
        ['label' => 'Low Stock', 'value' => count($rows) - $outCount, 'color' => '#d97706'],
        ['label' => 'Shortage Units', 'value' => number_format(array_sum(array_map(fn($r) => $r['shortage'], $rows)))],
        ['label' => 'Items Flagged', 'value' => count($rows)],
    ];
    $tblHeaders = ['Product', 'SKU', 'Available', 'Reorder Level', 'Threshold', 'Shortage', 'Status'];
    foreach ($rows as $r) {
        $badge = $r['status'] === 'Out' ? wrBadge('Out of Stock', '#dc2626') : wrBadge('Low Stock', '#d97706');
        $tblRows[] = ['<span style="font-weight:700">' . wrH($r['name']) . '</span>', $r['sku'], '<span style="color:' . ($r['status'] === 'Out' ? '#dc2626' : '#d97706') . ';font-weight:700">' . $r['available'] . '</span>', $r['reorder'] > 0 ? $r['reorder'] : '-', $r['threshold'], $r['shortage'], $badge];
    }
} elseif ($tab === 'order_fulfillment') {
    $sql = "SELECT co.id, co.order_date, co.fulfillment_method, co.fulfillment_status, co.picking_status,
            co.prepared_at, co.picked_at, co.prepared_quantity, co.picker_id, c.name customer_name,
            coi.product_id, p.name product_name, coi.quantity ordered_qty, coi.batch_no, u.full_name picker_name
            FROM customer_orders co
            JOIN customers c ON c.id = co.customer_id
            JOIN customer_order_items coi ON coi.customer_order_id = co.id
            JOIN products p ON p.id = coi.product_id
            LEFT JOIN users u ON u.id = co.picker_id
            WHERE co.fulfillment_method IS NOT NULL AND co.fulfillment_method <> ''";
    $rows = $pdo->query($sql)->fetchAll();
    if ($dateFrom !== '') { $rows = array_values(array_filter($rows, fn($r) => $r['order_date'] >= $dateFrom)); }
    if ($dateTo !== '') { $rows = array_values(array_filter($rows, fn($r) => substr($r['order_date'], 0, 10) <= $dateTo)); }
    if ($statusFilter !== '') { $rows = array_values(array_filter($rows, fn($r) => $r['fulfillment_status'] === $statusFilter)); }
    if ($search !== '') { $rows = array_values(array_filter($rows, fn($r) => stripos('#' . $r['id'] . ' ' . $r['customer_name'] . ' ' . $r['product_name'], $search) !== false)); }
    $pickedOrders = count(array_filter($rows, fn($r) => in_array($r['picking_status'], ['picked', 'ready_for_delivery'], true)));

    $cards = [
        ['label' => 'Order Lines', 'value' => count($rows)],
        ['label' => 'Pickup', 'value' => count(array_filter($rows, fn($r) => $r['fulfillment_method'] === 'pickup')), 'color' => '#1e3a5f'],
        ['label' => 'Delivery', 'value' => count(array_filter($rows, fn($r) => $r['fulfillment_method'] === 'delivery')), 'color' => '#1d4ed8'],
        ['label' => 'Picked Orders', 'value' => $pickedOrders, 'color' => '#059669'],
    ];
    $tblHeaders = ['Order#', 'Date', 'Customer', 'Method', 'Product', 'Ordered', 'Picked', 'Batch', 'Picker', 'Status', 'Ready Date'];
    foreach ($rows as $r) {
        $picked = in_array($r['picking_status'], ['picked', 'ready_for_delivery'], true) ? $r['ordered_qty'] : 0;
        $pickedHtml = $picked ? '<span style="color:#059669;font-weight:700">' . $picked . '</span>' : '<span style="color:var(--neutral-400)">0</span>';
        $lane = $r['fulfillment_method'] === 'pickup' ? wrBadge('Pickup', '#1e3a5f') : wrBadge('Delivery', '#1d4ed8');
        $stKey = $r['fulfillment_status'] ?? '';
        $stLabel = $fulfLabels[$stKey] ?? ($stKey !== '' ? $stKey : '—');
        $stColor = in_array($stKey, ['fulfillment_completed'], true) ? '#059669' : (in_array($stKey, ['out_of_stock', 'fulfillment_cancelled'], true) ? '#dc2626' : '#0f172a');
        $ready = $r['picked_at'] ?: ($r['prepared_at'] ?: '-');
        $tblRows[] = ['<span style="font-weight:700">#' . (int)$r['id'] . '</span>', $r['order_date'], $r['customer_name'], $lane, $r['product_name'], $r['ordered_qty'], $pickedHtml, $r['batch_no'] ?: '-', $r['picker_name'] ?: '-', '<span style="font-weight:600;color:' . $stColor . '">' . wrH($stLabel) . '</span>', $ready];
    }
}
// ─── Export ───────────────────────────────────────────────
$plainRows = array_map(function ($r) {
    return array_map(function ($cell) { return trim(strip_tags((string)$cell)); }, $r);
}, $tblRows);

if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $tab . '_report_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $tblHeaders);
    foreach ($plainRows as $r) { fputcsv($out, $r); }
    fclose($out);
    exit;
}
if ($export === 'xls') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $tab . '_report_' . date('Ymd') . '.xls"');
    echo '<html><head><meta charset="utf-8"><style>td,th{border:1px solid #ccc;padding:4px 8px;font-family:Arial}th{background:#1e3a5f;color:#fff}.rp-hdr{font-size:11px;color:#555}</style></head><body>';
    echo '<table style="border:none;margin-bottom:6px"><tr>';
    echo '<td style="border:none"><img src="' . htmlspecialchars(brand_logo()) . '" style="height:52px;width:auto;object-fit:contain"></td>';
    echo '<td style="border:none"><div style="font-size:16px;font-weight:bold;color:#1e3a5f">' . htmlspecialchars(company_name()) . '</div><div class="rp-hdr">' . ucfirst(str_replace('_', ' ', $tab)) . ' Report · Generated ' . date('M j, Y H:i') . '</div></td>';
    echo '</tr></table>';
    echo '<table><tr>';
    foreach ($tblHeaders as $h) { echo '<th>' . htmlspecialchars($h) . '</th>'; }
    echo '</tr>';
    foreach ($plainRows as $r) {
        echo '<tr>';
        foreach ($r as $cell) { echo '<td>' . htmlspecialchars($cell) . '</td>'; }
        echo '</tr>';
    }
    echo '</table></body></html>';
    exit;
}

$total = count($tblRows);
$pagedRows = wrSlice($tblRows, $perPage, $wrPage);
$statusOptions = [];
if ($tab === 'purchase_receiving') {
    $statusOptions = ['received' => 'Received', 'payment_approved' => 'Payment Approved', 'partially_received' => 'Partially Received', 'pending' => 'Pending', 'approved' => 'Approved'];
} elseif ($tab === 'expiry') {
    $statusOptions = ['expired' => 'Expired', 'soon30' => 'Expiring ≤ 30 days', 'soon90' => 'Expiring 31–90 days', 'valid' => 'Valid'];
} elseif ($tab === 'order_fulfillment') {
    $statusOptions = $fulfLabels;
}
$typeOptions = ['sale' => 'Sale', 'purchase_order' => 'Purchase', 'order' => 'Restock', 'SYNC' => 'Sync', 'physical_count' => 'Count', 'product_disposal' => 'Disposal', 'product_batch' => 'Quarantine', 'stock_transfer' => 'Transfer'];
?>
<section>
    <h1><i class="fas fa-warehouse"></i> Warehouse Reports</h1>

    <style>@media print{.wr-print-head{display:flex!important}}</style>
    <div class="wr-print-head" style="display:none;align-items:center;gap:14px;border-bottom:2px solid #1e3a5f;padding-bottom:10px;margin-bottom:14px">
        <img src="<?= htmlspecialchars(brand_logo()) ?>" alt="Logo" style="height:52px;width:auto;max-width:100px;object-fit:contain">
        <div>
            <div style="font-size:1.05rem;font-weight:800;color:#1e3a5f"><?= htmlspecialchars(company_name()) ?></div>
            <div style="font-size:.8rem;color:#475569">Warehouse Report<?= $tab !== '' ? ' — ' . ucfirst(str_replace('_', ' ', $tab)) : '' ?> · Printed <?= htmlspecialchars(date('M j, Y H:i')) ?></div>
        </div>
    </div>

    <div class="tab-bar no-print">
        <a href="?page=warehouse_reports&tab=stock_summary" class="tab-btn<?= $tab === 'stock_summary' ? ' active' : '' ?>"><i class="fas fa-boxes"></i> Stock Summary</a>
        <a href="?page=warehouse_reports&tab=stock_movement" class="tab-btn<?= $tab === 'stock_movement' ? ' active' : '' ?>"><i class="fas fa-right-left"></i> Stock Movement</a>
        <a href="?page=warehouse_reports&tab=purchase_receiving" class="tab-btn<?= $tab === 'purchase_receiving' ? ' active' : '' ?>"><i class="fas fa-truck-loading"></i> Purchase Receiving</a>
        <a href="?page=warehouse_reports&tab=expiry" class="tab-btn<?= $tab === 'expiry' ? ' active' : '' ?>"><i class="fas fa-calendar-times"></i> Expiry</a>
        <a href="?page=warehouse_reports&tab=quarantine" class="tab-btn<?= $tab === 'quarantine' ? ' active' : '' ?>"><i class="fas fa-triangle-exclamation"></i> Quarantine</a>
        <a href="?page=warehouse_reports&tab=disposal" class="tab-btn<?= $tab === 'disposal' ? ' active' : '' ?>"><i class="fas fa-trash-alt"></i> Disposal</a>
        <a href="?page=warehouse_reports&tab=low_stock" class="tab-btn<?= $tab === 'low_stock' ? ' active' : '' ?>"><i class="fas fa-exclamation"></i> Low Stock</a>
        <a href="?page=warehouse_reports&tab=order_fulfillment" class="tab-btn<?= $tab === 'order_fulfillment' ? ' active' : '' ?>"><i class="fas fa-clipboard-check"></i> Order Fulfillment</a>
    </div>

    <div class="panel no-print">
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="page" value="warehouse_reports">
            <input type="hidden" name="tab" value="<?= wrH($tab) ?>">
            <?php if (in_array($tab, ['stock_movement', 'purchase_receiving', 'disposal', 'order_fulfillment'], true)): ?>
            <label style="margin:0">From<input type="date" name="date_from" value="<?= wrH($dateFrom) ?>" style="margin:0"></label>
            <label style="margin:0">To<input type="date" name="date_to" value="<?= wrH($dateTo) ?>" style="margin:0"></label>
            <?php endif; ?>
            <?php if (in_array($tab, ['stock_summary', 'stock_movement', 'purchase_receiving', 'expiry', 'low_stock'], true)): ?>
            <label style="margin:0">Product<select name="product_id" style="margin:0;max-width:200px">
                <option value="0">All Products</option>
                <?php foreach ($products as $p): ?><option value="<?= (int)$p['id'] ?>"<?= $productFilter === (int)$p['id'] ? ' selected' : '' ?>><?= wrH($p['name']) ?></option><?php endforeach; ?>
            </select></label>
            <?php endif; ?>
            <?php if ($tab === 'stock_movement'): ?>
            <label style="margin:0">Type<select name="type" style="margin:0">
                <option value="">All Types</option>
                <?php foreach ($typeOptions as $k => $v): ?><option value="<?= wrH($k) ?>"<?= $typeFilter === $k ? ' selected' : '' ?>><?= wrH($v) ?></option><?php endforeach; ?>
            </select></label>
            <?php endif; ?>
            <?php if ($tab === 'purchase_receiving'): ?>
            <label style="margin:0">Status<select name="status" style="margin:0">
                <option value="">All Statuses</option>
                <?php foreach ($statusOptions as $k => $v): ?><option value="<?= wrH($k) ?>"<?= $statusFilter === $k ? ' selected' : '' ?>><?= wrH($v) ?></option><?php endforeach; ?>
            </select></label>
            <?php endif; ?>
            <?php if ($tab === 'order_fulfillment'): ?>
            <label style="margin:0">Status<select name="status" style="margin:0;max-width:200px">
                <option value="">All Statuses</option>
                <?php foreach ($statusOptions as $k => $v): ?><option value="<?= wrH($k) ?>"<?= $statusFilter === $k ? ' selected' : '' ?>><?= wrH($v) ?></option><?php endforeach; ?>
            </select></label>
            <?php endif; ?>
            <?php if ($tab === 'expiry'): ?>
            <label style="margin:0">Group<select name="status" style="margin:0">
                <option value="">All Groups</option>
                <?php foreach ($statusOptions as $k => $v): ?><option value="<?= wrH($k) ?>"<?= $statusFilter === $k ? ' selected' : '' ?>><?= wrH($v) ?></option><?php endforeach; ?>
            </select></label>
            <?php endif; ?>
            <label style="margin:0">Search<input type="text" name="q" value="<?= wrH($search) ?>" placeholder="Search…" style="margin:0"></label>
            <button type="submit" style="margin:0">Filter</button>
            <?php if (hasPermission('reports', 'export')): ?>
            <div class="export-dropdown">
                <button type="button" class="export-btn" onclick="toggleExport(this)"><i class="fas fa-download"></i> Export</button>
                <div class="export-dropdown-menu">
                    <a href="<?= $baseFull ?>&export=csv"><i class="fas fa-file-csv"></i> CSV</a>
                    <a href="<?= $baseFull ?>&export=xls"><i class="fas fa-file-excel"></i> Excel</a>
                </div>
            </div>
            <?php endif; ?>
            <button type="button" class="export-btn" onclick="window.print()" style="padding:5px 12px;border:1px solid var(--neutral-300);border-radius:8px;background:#fff;font-size:0.78rem;cursor:pointer"><i class="fas fa-print"></i> Print</button>
            <?php if ($baseFull !== $base): ?><a href="<?= $base ?>" style="display:inline-flex;align-items:center;padding:12px;color:var(--brown-800);font-weight:600">Clear</a><?php endif; ?>
        </form>
    </div>

    <?php if (!empty($cards)): ?>
    <div class="grid cards cards-4"><?php foreach ($cards as $c) { echo wrCard($c['label'], $c['value'], $c['color'] ?? ''); } ?></div>
    <?php endif; ?>

    <div class="panel">
        <h2><?= ucfirst(str_replace('_', ' ', $tab)) ?></h2>
        <?php if (empty($tblRows)): ?>
        <p style="color:var(--neutral-500)">No records found for the selected filters.</p>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table>
            <thead><tr><?php foreach ($tblHeaders as $h) { echo '<th>' . wrH($h) . '</th>'; } ?></tr></thead>
            <tbody>
                <?php foreach ($pagedRows as $r): ?><tr><?php foreach ($r as $cell) { echo '<td>' . $cell . '</td>'; } ?></tr><?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php echo wrPager($total, $perPage, $wrPage, $baseFull); ?>
        <?php endif; ?>
    </div>

    <?php if ($tab === 'quarantine'): ?>
    <div class="panel" style="border-left:4px solid #d97706;color:var(--neutral-600);font-size:0.85rem">
        <strong>Note:</strong> The system does not record a per-batch quarantine reason (damaged / returned / other).
        Only quantities derived from real batch data are shown above.
    </div>
    <?php elseif ($tab === 'expiry'): ?>
    <div class="panel" style="border-left:4px solid #ca8a04;color:var(--neutral-600);font-size:0.85rem">
        <strong>Note:</strong> "Expiring Soon" = 30 days or less; "Expiring" = 31–90 days; "Valid" = beyond 90 days. Figures come from active batches only.
    </div>
    <?php endif; ?>
</section>