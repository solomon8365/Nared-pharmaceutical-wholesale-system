<?php
requirePermission('stock', 'view');
require_once __DIR__ . '/../includes/stock_helper.php';
$pdo = getPDO();

$detailId = (int)($_GET['detail'] ?? 0);

function wiMovementType(string $refType, string $reason): string {
    $r = strtolower($reason);
    if ($refType === 'purchase_order') return 'Purchase';
    if ($refType === 'sale') return 'Sale';
    if (stripos($r, 'reservation') !== false || stripos($r, 'reserved') !== false) return 'Order Reservation';
    if (stripos($r, 'release') !== false || stripos($r, 'released') !== false) return 'Order Release';
    if ($refType === 'adjustment') return 'Stock Adjustment';
    if ($refType === 'transfer') return 'Transfer';
    if (stripos($r, 'quarantine') !== false) return 'Quarantine';
    if (stripos($r, 'disposal') !== false || $refType === 'disposal') return 'Disposal';
    if ($refType === 'return') return 'Return';
    if ($refType === 'SYNC') return 'System Sync';
    return 'Other';
}

function wiBadge(string $type): string {
    $map = [
        'Available' => ['#ecfdf5','#047857'],['Low Stock' => ['#fffbeb','#b45309']],['Out of Stock' => ['#fef2f2','#b91c1c']],
        'Expiring Soon' => ['#fff7ed','#c2410c'],['Expired' => ['#fef2f2','#991b1b']],['Quarantine' => ['#fdf4ff','#9333ea']],
    ];
    [$bg,$fg] = $map[$type] ?? ['#f3f4f6','#374151'];
    return '<span class="status-badge" style="background:'.$bg.';color:'.$fg.';border:1px solid '.$fg.'">'.htmlspecialchars($type).'</span>';
}

function wiRefLabel(string $refType, $refId): string {
    $labels = ['purchase_order'=>'PO','sale'=>'Sale','adjustment'=>'Adjustment','transfer'=>'Transfer','return'=>'Return','disposal'=>'Disposal','product_batch'=>'Batch','product_disposal'=>'Disposal','SYNC'=>'Sync','warehouse'=>'Warehouse'];
    $label = $labels[$refType] ?? ucfirst(str_replace('_',' ',$refType));
    return $refId ? $label.' #'.(int)$refId : '—';
}

// ═══════════════════════════════════════════════════════════════════════════
//  DETAIL VIEW
// ═══════════════════════════════════════════════════════════════════════════
if ($detailId > 0) {
    $stmt = $pdo->prepare("SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = :id");
    $stmt->execute(['id' => $detailId]);
    $product = $stmt->fetch();
    if (!$product) { echo '<section><div class="alert alert-error">Product not found.</div></section>'; return; }

    $batches = $pdo->prepare("SELECT * FROM product_batches WHERE product_id = :pid ORDER BY expiry_date ASC");
    $batches->execute(['pid' => $detailId]);
    $batches = $batches->fetchAll();

    $rStmt = $pdo->prepare("SELECT COALESCE(SUM(coi.quantity),0) FROM customer_order_items coi JOIN customer_orders co ON co.id=coi.customer_order_id WHERE co.reserved_status='reserved' AND coi.product_id=:pid");
    $rStmt->execute(['pid' => $detailId]);
    $reserved = (int)$rStmt->fetchColumn();
    $qStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM product_batches WHERE product_id=:pid AND status='quarantined'");
    $qStmt->execute(['pid' => $detailId]);
    $quarantine = (int)$qStmt->fetchColumn();
    $eStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM product_batches WHERE product_id=:pid AND quantity>0 AND expiry_date<CURDATE()");
    $eStmt->execute(['pid' => $detailId]);
    $expired = (int)$eStmt->fetchColumn();

    $mStmt = $pdo->prepare("SELECT * FROM stock_movements WHERE product_id=:pid ORDER BY created_at ASC, id ASC");
    $mStmt->execute(['pid'=>$detailId]);
    $movements = $mStmt->fetchAll();
    $bal = 0;
    foreach ($movements as &$mv) { $bal += (int)$mv['quantity_change']; $mv['balance'] = $bal; }
    unset($mv);

    $avail = max(0, (int)$product['stock_quantity'] - $reserved);
    $reorder = (int)$product['reorder_level'] ?: 10;
    $thr = $reorder;
    $pStatus = (int)$product['stock_quantity'] <= 0 ? 'Out of Stock' : ((int)$product['stock_quantity'] <= $thr ? 'Low Stock' : 'Available');
    ?>
    <section>
    <style>.wi-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px}.wi-info-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--neutral-200);font-size:.88rem}.wi-info-row .lbl{color:var(--neutral-600);font-weight:600}.wi-info-row .val{font-weight:700;color:var(--neutral-900)}.wi-mov-in{color:var(--green-700);font-weight:700}.wi-mov-out{color:var(--red-700);font-weight:700}.wi-back{display:inline-flex;align-items:center;gap:6px;font-weight:700;color:var(--brown-700);margin-bottom:16px;font-size:.9rem;text-decoration:none}.wi-back:hover{text-decoration:underline}@media(max-width:800px){.wi-detail-grid{grid-template-columns:1fr}}</style>
    <a class="wi-back" href="?page=warehouse_inventory"><i class="fas fa-arrow-left"></i> Back to Inventory</a>
    <h1 style="margin:0 0 4px;font-size:1.4rem;font-weight:800"><i class="fas fa-capsules" style="color:var(--brown-600);margin-right:8px"></i><?= htmlspecialchars($product['name']) ?></h1>
    <p style="margin:0 0 20px;color:var(--neutral-600);font-size:.88rem"><?= htmlspecialchars((string)($product['brand'] ?: '')) ?><?= $product['category_name'] ? ' · '.htmlspecialchars($product['category_name']) : '' ?><?= $product['sku'] ? ' · SKU: '.htmlspecialchars($product['sku']) : '' ?></p>

    <div class="wi-detail-grid">
        <div class="panel" style="margin-bottom:0">
            <h2><i class="fas fa-info-circle"></i> Product Information</h2>
            <div class="wi-info-row"><span class="lbl">Category</span><span class="val"><?= htmlspecialchars($product['category_name'] ?? '—') ?></span></div>
            <div class="wi-info-row"><span class="lbl">Brand</span><span class="val"><?= htmlspecialchars((string)($product['brand'] ?: '—')) ?></span></div>
            <div class="wi-info-row"><span class="lbl">Generic Name</span><span class="val"><?= htmlspecialchars((string)($product['generic_name'] ?: '—')) ?></span></div>
            <div class="wi-info-row"><span class="lbl">Dosage Form</span><span class="val"><?= htmlspecialchars((string)($product['dosage_form'] ?: '—')) ?></span></div>
            <div class="wi-info-row"><span class="lbl">Strength</span><span class="val"><?= htmlspecialchars((string)($product['strength'] ?: '—')) ?></span></div>
            <div class="wi-info-row"><span class="lbl">Reorder Level</span><span class="val"><?= $reorder ?></span></div>
            <div class="wi-info-row"><span class="lbl">Purchase Cost</span><span class="val"><?= fmt((float)$product['purchase_price']) ?></span></div>
            <div class="wi-info-row"><span class="lbl">Selling Price</span><span class="val"><?= fmt((float)$product['price']) ?></span></div>
            <div class="wi-info-row" style="border-bottom:none"><span class="lbl">Stock Status</span><span class="val"><?= wiBadge($pStatus) ?></span></div>
        </div>
        <div class="panel" style="margin-bottom:0">
            <h2><i class="fas fa-chart-pie"></i> Stock Summary</h2>
            <div class="wi-info-row"><span class="lbl">Current Stock</span><span class="val" style="font-size:1.1rem"><?= number_format((int)$product['stock_quantity']) ?> units</span></div>
            <div class="wi-info-row"><span class="lbl">Available Stock</span><span class="val" style="color:var(--green-700)"><?= number_format($avail) ?> units</span></div>
            <div class="wi-info-row"><span class="lbl">Reserved Stock</span><span class="val" style="color:#7c3aed"><?= number_format($reserved) ?> units</span></div>
            <div class="wi-info-row"><span class="lbl">Quarantine Stock</span><span class="val" style="color:<?= $quarantine>0?'#b45309':'var(--neutral-900)' ?>"><?= number_format($quarantine) ?> units</span></div>
            <div class="wi-info-row"><span class="lbl">Expired Stock</span><span class="val" style="color:<?= $expired>0?'#b91c1c':'var(--neutral-900)' ?>"><?= number_format($expired) ?> units</span></div>
            <div class="wi-info-row"><span class="lbl">Batch Count</span><span class="val"><?= count($batches) ?></span></div>
            <div class="wi-info-row" style="border-bottom:none"><span class="lbl">Stock Value</span><span class="val"><?= fmt((float)$product['stock_quantity'] * (float)$product['purchase_price']) ?></span></div>
        </div>
    </div>

    <?php if ($batches): ?>
    <div class="panel" style="margin-top:20px">
        <h2><i class="fas fa-layer-group"></i> Batch Information</h2>
        <table>
            <thead><tr><th>Batch #</th><th>Expiry Date</th><th>Quantity</th><th>Purchase Cost</th><th>Status</th><th>Days to Expiry</th></tr></thead>
            <tbody>
            <?php foreach ($batches as $b):
                $bStatus = $b['status'] === 'quarantined' ? 'Quarantine' : ($b['expiry_date'] && $b['expiry_date'] < date('Y-m-d') ? 'Expired' : ($b['status'] === 'active' ? 'Active' : ucfirst($b['status'])));
                $daysLeft = $b['expiry_date'] ? (int)((strtotime($b['expiry_date']) - strtotime('today')) / 86400) : null;
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($b['batch_number']) ?></strong></td>
                <td><?= $b['expiry_date'] ? htmlspecialchars($b['expiry_date']) : '—' ?></td>
                <td style="font-weight:700"><?= number_format((int)$b['quantity']) ?></td>
                <td><?= fmt((float)$b['purchase_price']) ?></td>
                <td><?= wiBadge($bStatus) ?></td>
                <td style="color:<?= $daysLeft!==null && $daysLeft<=30?'#b91c1c':($daysLeft!==null && $daysLeft<=90?'#b45309':'var(--neutral-900)') ?>"><?= $daysLeft!==null ? ($daysLeft < 0 ? 'Expired '.abs($daysLeft).'d ago' : $daysLeft.' days') : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="panel" style="margin-top:20px">
        <h2><i class="fas fa-arrows-spin"></i> Stock Movement History</h2>
        <?php if (empty($movements)): ?>
            <p style="text-align:center;color:var(--neutral-500);padding:20px">No stock movements recorded for this product.</p>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table>
            <thead><tr><th>Date / Time</th><th>Movement Type</th><th>Reference</th><th>Qty In</th><th>Qty Out</th><th>Balance</th><th>Reason</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($movements) as $mv):
                $qty = (int)$mv['quantity_change'];
                $mType = wiMovementType($mv['reference_type'] ?? '', $mv['reason'] ?? '');
            ?>
            <tr>
                <td style="white-space:nowrap;font-size:.82rem"><?= htmlspecialchars(date('M d, Y g:ia', strtotime($mv['created_at']))) ?></td>
                <td><?= wiBadge($mType) ?></td>
                <td style="font-size:.82rem"><?= wiRefLabel($mv['reference_type'] ?? '', $mv['reference_id'] ?? null) ?></td>
                <td class="wi-mov-in"><?= $qty > 0 ? '+'.number_format($qty) : '' ?></td>
                <td class="wi-mov-out"><?= $qty < 0 ? number_format(abs($qty)) : '' ?></td>
                <td style="font-weight:700"><?= number_format($mv['balance']) ?></td>
                <td style="font-size:.82rem;color:var(--neutral-600);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($mv['reason'] ?? '') ?>"><?= htmlspecialchars($mv['reason'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
    </section>
    <?php
    return;
}

// ═══════════════════════════════════════════════════════════════════════════
//  LIST VIEW
// ═══════════════════════════════════════════════════════════════════════════
$search = trim($_GET['q'] ?? '');
$categoryFilter = (int)($_GET['category_id'] ?? 0);
$batchSearch = trim($_GET['batch'] ?? '');
$expiryFilter = trim($_GET['expiry'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$pg = max(1, (int)($_GET['pg'] ?? 1));
$perPage = 30;

// Summary cards
$totalProducts = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE product_status='active'")->fetchColumn();
$totalStockUnits = (int)$pdo->query("SELECT COALESCE(SUM(stock_quantity),0) FROM products WHERE product_status='active'")->fetchColumn();
$availStock = (int)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM product_batches WHERE status='active' AND quantity>0")->fetchColumn();
$totalReserved = (int)$pdo->query("SELECT COALESCE(SUM(coi.quantity),0) FROM customer_order_items coi JOIN customer_orders co ON co.id=coi.customer_order_id WHERE co.reserved_status='reserved'")->fetchColumn();
$lowStockCount = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE product_status='active' AND stock_quantity>0 AND stock_quantity<=COALESCE(NULLIF(reorder_level,0),10)")->fetchColumn();
$outOfStockCount = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE product_status='active' AND stock_quantity<=0")->fetchColumn();
$expiringSoon = (int)$pdo->query("SELECT COUNT(DISTINCT pb.id) FROM product_batches pb WHERE pb.quantity>0 AND pb.status='active' AND pb.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)")->fetchColumn();
$quarantineUnits = (int)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM product_batches WHERE status='quarantined'")->fetchColumn();
$expiredUnits = (int)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM product_batches WHERE quantity>0 AND expiry_date<CURDATE()")->fetchColumn();

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

// Reserved map
$reservedMap = [];
foreach ($pdo->query("SELECT coi.product_id, SUM(coi.quantity) q FROM customer_order_items coi JOIN customer_orders co ON co.id=coi.customer_order_id WHERE co.reserved_status='reserved' GROUP BY coi.product_id")->fetchAll() as $r) { $reservedMap[(int)$r['product_id']]=(int)$r['q']; }

// Inventory data
$sql = "SELECT p.id, p.name, p.brand, p.stock_quantity, p.reorder_level, p.purchase_price, p.price, p.sku,
               c.name AS category_name,
               pb.id AS batch_id, pb.batch_number, pb.expiry_date, pb.quantity AS batch_qty, pb.purchase_price AS batch_cost, pb.status AS batch_status
        FROM products p
        LEFT JOIN categories c ON c.id=p.category_id
        LEFT JOIN product_batches pb ON pb.product_id=p.id AND pb.quantity>0 AND pb.status!='disposed'
        WHERE p.product_status='active'";
$params = [];
if ($search !== '') { $sql .= " AND (p.name LIKE :s1 OR p.brand LIKE :s2 OR p.sku LIKE :s3 OR p.barcode LIKE :s4)"; $sv="%$search%"; $params['s1']=$sv; $params['s2']=$sv; $params['s3']=$sv; $params['s4']=$sv; }
if ($categoryFilter > 0) { $sql .= " AND p.category_id=:cat"; $params['cat']=$categoryFilter; }
if ($batchSearch !== '') { $sql .= " AND pb.batch_number LIKE :bn"; $params['bn']="%$batchSearch%"; }
if ($expiryFilter === 'expired') { $sql .= " AND pb.expiry_date<CURDATE()"; }
elseif ($expiryFilter === '30') { $sql .= " AND pb.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"; }
elseif ($expiryFilter === '60') { $sql .= " AND pb.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)"; }
elseif ($expiryFilter === '90') { $sql .= " AND pb.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)"; }
elseif ($expiryFilter === 'valid') { $sql .= " AND (pb.expiry_date IS NULL OR pb.expiry_date >= CURDATE())"; }
$sql .= " ORDER BY p.name ASC, pb.expiry_date ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allRows = $stmt->fetchAll();

// PHP-side status filter
function wiRowStatus(array $row): string {
    if ($row['batch_status'] === 'quarantined') return 'Quarantine';
    if ($row['batch_status'] === 'expired' || ($row['expiry_date'] && $row['expiry_date'] < date('Y-m-d'))) return 'Expired';
    if ($row['batch_status'] === 'active' && $row['expiry_date'] && $row['expiry_date'] <= date('Y-m-d', strtotime('+90 days'))) return 'Expiring Soon';
    if ((int)$row['stock_quantity'] <= 0) return 'Out of Stock';
    $reorder = (int)$row['reorder_level'] ?: 10;
    if ((int)$row['stock_quantity'] > 0 && (int)$row['stock_quantity'] <= $reorder) return 'Low Stock';
    return 'Available';
}
if ($statusFilter !== '') {
    $allRows = array_values(array_filter($allRows, function($r) use ($statusFilter) { return wiRowStatus($r) === $statusFilter; }));
}

$total = count($allRows);
$totalPages = max(1, (int)ceil($total / $perPage));
if ($pg > $totalPages) $pg = $totalPages;
$rows = array_slice($allRows, ($pg - 1) * $perPage, $perPage);
?>
<section>
<style>
.wi-filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:flex-end}
.wi-filters label{margin:0;font-size:.8rem;font-weight:600;color:var(--neutral-700)}
.wi-filters input,.wi-filters select{margin:0;padding:8px 10px;border:2px solid var(--neutral-400);border-radius:var(--radius-sm);font:inherit;font-size:.85rem;background:var(--white);color:var(--neutral-900)}
.wi-filters input:focus,.wi-filters select:focus{outline:none;border-color:var(--brown-500);box-shadow:var(--focus-ring)}
.wi-filters button{margin:0;padding:8px 16px}
.wi-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-bottom:20px}
.wi-card{background:var(--white);border-radius:var(--radius-md);padding:14px;border:1px solid var(--neutral-300);box-shadow:var(--shadow-sm);text-align:center;transition:transform .15s}
.wi-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-md)}
.wi-card .n{font-size:1.5rem;font-weight:800;line-height:1.2}
.wi-card .l{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--neutral-500);margin-top:4px}
.wi-act{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:999px;font-size:.75rem;font-weight:700;text-decoration:none;background:var(--brown-600);color:#fff;transition:all .15s}
.wi-act:hover{background:var(--brown-800);color:#fff;transform:translateY(-1px)}
.wi-pager{display:flex;gap:5px;align-items:center;justify-content:flex-end;margin-top:14px;flex-wrap:wrap}
.wi-pager a,.wi-pager span{display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:30px;border-radius:6px;font-size:.78rem;font-weight:700;text-decoration:none;color:var(--neutral-700);background:var(--neutral-100);border:1px solid var(--neutral-300);transition:all .12s}
.wi-pager a:hover{background:var(--brown-600);color:#fff;border-color:var(--brown-600)}
.wi-pager .on{background:var(--brown-600);color:#fff;border-color:var(--brown-600)}
.wi-empty{text-align:center;padding:40px;color:var(--neutral-500)}
.wi-empty i{font-size:2rem;margin-bottom:8px;display:block;color:var(--neutral-400)}
</style>

<h1 style="margin:0 0 6px;font-size:1.4rem;font-weight:800"><i class="fas fa-warehouse" style="color:var(--brown-600);margin-right:8px"></i> Warehouse Inventory Overview</h1>
<p style="margin:0 0 20px;color:var(--neutral-600);font-size:.88rem">Complete view of all products and batches currently in the warehouse</p>

<div class="wi-cards">
    <div class="wi-card"><div class="n" style="color:var(--brown-700)"><?= number_format($totalProducts) ?></div><div class="l"><i class="fas fa-capsules" style="margin-right:3px"></i>Total Products</div></div>
    <div class="wi-card"><div class="n" style="color:var(--brown-700)"><?= number_format($totalStockUnits) ?></div><div class="l"><i class="fas fa-boxes-stacked" style="margin-right:3px"></i>Total Stock Units</div></div>
    <div class="wi-card"><div class="n" style="color:#047857"><?= number_format($availStock) ?></div><div class="l"><i class="fas fa-check-circle" style="margin-right:3px"></i>Available Stock</div></div>
    <div class="wi-card"><div class="n" style="color:#7c3aed"><?= number_format($totalReserved) ?></div><div class="l"><i class="fas fa-lock" style="margin-right:3px"></i>Reserved Stock</div></div>
    <div class="wi-card"><div class="n" style="color:#b45309"><?= number_format($lowStockCount) ?></div><div class="l"><i class="fas fa-triangle-exclamation" style="margin-right:3px"></i>Low Stock</div></div>
    <div class="wi-card"><div class="n" style="color:#b91c1c"><?= number_format($outOfStockCount) ?></div><div class="l"><i class="fas fa-box-open" style="margin-right:3px"></i>Out of Stock</div></div>
    <div class="wi-card"><div class="n" style="color:#c2410c"><?= number_format($expiringSoon) ?></div><div class="l"><i class="fas fa-clock" style="margin-right:3px"></i>Expiring Soon</div></div>
    <div class="wi-card"><div class="n" style="color:#9333ea"><?= number_format($quarantineUnits) ?></div><div class="l"><i class="fas fa-biohazard" style="margin-right:3px"></i>Quarantine Stock</div></div>
    <div class="wi-card"><div class="n" style="color:#991b1b"><?= number_format($expiredUnits) ?></div><div class="l"><i class="fas fa-skull-crossbones" style="margin-right:3px"></i>Expired Stock</div></div>
</div>

<div class="panel">
    <form method="get" class="wi-filters">
        <input type="hidden" name="page" value="warehouse_inventory">
        <label>Product<input type="text" name="q" placeholder="Name, brand, SKU…" value="<?= htmlspecialchars($search) ?>" style="min-width:150px"></label>
        <label>Category<select name="category_id"><option value="">All</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $categoryFilter===(int)$c['id']?' selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?></select></label>
        <label>Batch<input type="text" name="batch" placeholder="Batch #" value="<?= htmlspecialchars($batchSearch) ?>" style="min-width:110px"></label>
        <label>Expiry<select name="expiry"><option value="">All</option><option value="expired"<?= $expiryFilter==='expired'?' selected':'' ?>>Expired</option><option value="30"<?= $expiryFilter==='30'?' selected':'' ?>>≤ 30 Days</option><option value="60"<?= $expiryFilter==='60'?' selected':'' ?>>≤ 60 Days</option><option value="90"<?= $expiryFilter==='90'?' selected':'' ?>>≤ 90 Days</option><option value="valid"<?= $expiryFilter==='valid'?' selected':'' ?>>Valid Only</option></select></label>
        <label>Status<select name="status"><option value="">All</option><option value="Available"<?= $statusFilter==='Available'?' selected':'' ?>>Available</option><option value="Low Stock"<?= $statusFilter==='Low Stock'?' selected':'' ?>>Low Stock</option><option value="Out of Stock"<?= $statusFilter==='Out of Stock'?' selected':'' ?>>Out of Stock</option><option value="Expiring Soon"<?= $statusFilter==='Expiring Soon'?' selected':'' ?>>Expiring Soon</option><option value="Expired"<?= $statusFilter==='Expired'?' selected':'' ?>>Expired</option><option value="Quarantine"<?= $statusFilter==='Quarantine'?' selected':'' ?>>Quarantine</option></select></label>
        <button type="submit"><i class="fas fa-search"></i> Filter</button>
        <?php if ($search||$categoryFilter||$batchSearch||$expiryFilter||$statusFilter): ?><a href="?page=warehouse_inventory" style="padding:8px 14px;font-weight:700;color:var(--brown-700);font-size:.85rem">Clear</a><?php endif; ?>
    </form>

    <?php if (empty($rows)): ?>
        <div class="wi-empty"><i class="fas fa-box-open"></i>No products found matching your filters.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table>
        <thead><tr>
            <th>Product</th><th>Category</th><th>Batch / Lot</th><th>Expiry</th>
            <th>Stock</th><th>Available</th><th>Reserved</th><th>Reorder</th>
            <th>Cost</th><th>Selling Price</th><th>Status</th><th>Action</th>
        </tr></thead>
        <tbody>
        <?php
        $shown = [];
        foreach ($rows as $r) {
            $pid = (int)$r['id'];
            $res = $reservedMap[$pid] ?? 0;
            $stock = (int)$r['stock_quantity'];
            $avail = max(0, $stock - $res);
            $reorder = (int)$r['reorder_level'] ?: 10;
            $st = wiRowStatus($r);
            $hasBatch = $r['batch_id'] !== null;
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($r['name']) ?></strong><?= $r['sku'] ? '<br><span style="font-size:.72rem;color:var(--neutral-500)">'.htmlspecialchars($r['sku']).'</span>' : '' ?></td>
                <td style="font-size:.84rem"><?= htmlspecialchars((string)($r['category_name'] ?: '—')) ?></td>
                <td style="font-size:.84rem"><?= $hasBatch ? htmlspecialchars($r['batch_number']) : '<span style="color:var(--neutral-400)">No batch</span>' ?></td>
                <td style="font-size:.84rem;white-space:nowrap<?= $r['expiry_date'] && $r['expiry_date'] < date('Y-m-d') ? ';color:#b91c1c;font-weight:700' : ($r['expiry_date'] && $r['expiry_date'] <= date('Y-m-d', strtotime('+30 days')) ? ';color:#b45309;font-weight:600' : '') ?>"><?= $r['expiry_date'] ? htmlspecialchars($r['expiry_date']) : '—' ?></td>
                <td style="font-weight:700"><?= number_format($stock) ?></td>
                <td style="color:#047857;font-weight:700"><?= number_format($avail) ?></td>
                <td style="color:#7c3aed;font-weight:700"><?= $res > 0 ? number_format($res) : '—' ?></td>
                <td><?= $reorder ?></td>
                <td style="white-space:nowrap"><?= fmt((float)($hasBatch ? $r['batch_cost'] : $r['purchase_price'])) ?></td>
                <td style="white-space:nowrap"><?= fmt((float)$r['price']) ?></td>
                <td><?= wiBadge($st) ?></td>
                <td><a class="wi-act" href="?page=warehouse_inventory&detail=<?= $pid ?>"><i class="fas fa-eye"></i> Manage</a></td>
            </tr>
            <?php
        }
        ?>
        </tbody>
    </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="wi-pager">
        <?php
        $base = '?page=warehouse_inventory';
        foreach (['q'=>$search,'category_id'=>$categoryFilter,'batch'=>$batchSearch,'expiry'=>$expiryFilter,'status'=>$statusFilter] as $k=>$v) { if ($v !== '' && $v !== 0) $base .= '&'.$k.'='.urlencode((string)$v); }
        if ($pg > 1) echo '<a href="'.$base.'&pg='.($pg-1).'">&lsaquo;</a>';
        for ($p = max(1,$pg-2); $p <= min($totalPages,$pg+2); $p++) {
            echo '<a href="'.$base.'&pg='.$p.'" class="'.($p===$pg?'on':'').'">'.$p.'</a>';
        }
        if ($pg < $totalPages) echo '<a href="'.$base.'&pg='.($pg+1).'">&rsaquo;</a>';
        echo '<span style="font-size:.75rem;color:var(--neutral-500);margin-left:6px">'.$total.' items</span>';
        ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
</section>
