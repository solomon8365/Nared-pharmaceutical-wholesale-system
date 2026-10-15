<?php
requirePermission('products', 'view');

$pdo = getPDO();
$productId = (int) ($_GET['id'] ?? 0);

if ($productId <= 0) {
    header('Location: index.php?page=products');
    exit;
}

$stmt = $pdo->prepare('SELECT p.*, c.name AS category_name, s.name AS supplier_name FROM products p LEFT JOIN categories c ON p.category_id = c.id LEFT JOIN suppliers s ON p.supplier_id = s.id WHERE p.id = :id LIMIT 1');
$stmt->execute(['id' => $productId]);
$product = $stmt->fetch();

if (!$product) {
    header('Location: index.php?page=products');
    exit;
}

function countryFlag2($c) {
    $map = ['Ethiopia'=>"\xF0\x9F\x87\xBA\xF0\x9F\x87\xBC",'India'=>"\xF0\x9F\x87\xAE\xF0\x9F\x87\xB3",'China'=>"\xF0\x9F\x87\xA8\xF0\x9F\x87\xB3",'United Kingdom'=>"\xF0\x9F\x87\xAC\xF0\x9F\x87\xA7",'USA'=>"\xF0\x9F\x87\xBA\xF0\x9F\x87\xB8",'United States'=>"\xF0\x9F\x87\xBA\xF0\x9F\x87\xB8",'Germany'=>"\xF0\x9F\x87\xA9\xF0\x9F\x87\xAA",'France'=>"\xF0\x9F\x87\xAB\xF0\x9F\x87\xB7",'Japan'=>"\xF0\x9F\x87\xAF\xF0\x9F\x87\xB5",'Switzerland'=>"\xF0\x9F\x87\xA8\xF0\x9F\x87\xAD",'UAE'=>"\xF0\x9F\x87\xA6\xF0\x9F\x87\xAA",'Italy'=>"\xF0\x9F\x87\xAE\xF0\x9F\x87\xB9",'Turkey'=>"\xF0\x9F\x87\xB9\xF0\x9F\x87\xB7",'Kenya'=>"\xF0\x9F\x87\xB0\xF0\x9F\x87\xAA",'Nigeria'=>"\xF0\x9F\x87\xB3\xF0\x9F\x87\xAC",'UK'=>"\xF0\x9F\x87\xAC\xF0\x9F\x87\xA7",'Korea'=>"\xF0\x9F\x87\xB0\xF0\x9F\x87\xB7"];
    $c = trim($c ?? '');
    return $map[$c] ?? '';
}

$batches = $pdo->prepare('SELECT *, DATEDIFF(expiry_date, CURDATE()) AS days_left FROM product_batches WHERE product_id = :pid ORDER BY expiry_date DESC');
$batches->execute(['pid' => $productId]);
$batches = $batches->fetchAll();

$stockMovements = $pdo->prepare('SELECT sm.*, u.full_name AS user_name FROM stock_movements sm LEFT JOIN users u ON sm.reference_id = u.id WHERE sm.product_id = :pid ORDER BY sm.created_at DESC LIMIT 50');
$stockMovements->execute(['pid' => $productId]);
$stockMovements = $stockMovements->fetchAll();

$priceHistory = $pdo->prepare('SELECT ph.*, u.full_name AS user_name FROM price_history ph LEFT JOIN users u ON ph.changed_by = u.id WHERE ph.product_id = :pid ORDER BY ph.created_at DESC LIMIT 50');
$priceHistory->execute(['pid' => $productId]);
$priceHistory = $priceHistory->fetchAll();

$salesHistory = $pdo->prepare('SELECT si.*, s.sale_date, s.id AS sale_id FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE si.product_id = :pid ORDER BY s.sale_date DESC LIMIT 50');
$salesHistory->execute(['pid' => $productId]);
$salesHistory = $salesHistory->fetchAll();

$purchaseHistory = $pdo->prepare('SELECT poi.*, po.id AS po_id, po.created_at AS order_date, po.status AS po_status, su.name AS supplier_name FROM purchase_order_items poi JOIN purchase_orders po ON poi.purchase_order_id = po.id LEFT JOIN suppliers su ON po.supplier_id = su.id WHERE poi.product_id = :pid ORDER BY po.created_at DESC LIMIT 50');
$purchaseHistory->execute(['pid' => $productId]);
$purchaseHistory = $purchaseHistory->fetchAll();

$batchCount = count($batches);
$totalSold = 0;
$totalRevenue = 0;
if (!empty($salesHistory)) {
    $soldStmt = $pdo->prepare('SELECT COALESCE(SUM(si.quantity), 0), COALESCE(SUM(si.quantity * si.unit_price), 0) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE si.product_id = :pid');
    $soldStmt->execute(['pid' => $productId]);
    list($totalSold, $totalRevenue) = $soldStmt->fetch(PDO::FETCH_NUM);
}

$totalPurchased = 0;
$totalPurchaseCost = 0;
if (!empty($purchaseHistory)) {
    $purchStmt = $pdo->prepare('SELECT COALESCE(SUM(poi.quantity), 0), COALESCE(SUM(poi.quantity * poi.unit_price), 0) FROM purchase_order_items poi WHERE poi.product_id = :pid');
    $purchStmt->execute(['pid' => $productId]);
    list($totalPurchased, $totalPurchaseCost) = $purchStmt->fetch(PDO::FETCH_NUM);
}

$stock = (int) $product['stock_quantity'];
$purchasePrice = (float) ($product['purchase_price'] ?? 0);
$sellPrice = (float) $product['price'];
$profit = $sellPrice - $purchasePrice;
$margin = $purchasePrice > 0 ? round($profit / $purchasePrice * 100, 1) : 0;
$inventoryValue = $stock * $purchasePrice;
$potentialRevenue = $stock * $sellPrice;
$potentialProfit = $stock * $profit;
$flag = countryFlag2($product['country_of_origin'] ?? '');

$activeTab = $_GET['tab'] ?? 'overview';
?>
<style>
.pd-wrap{max-width:1200px;margin:0 auto;padding:0 4px}
.pd-back{display:inline-flex;align-items:center;gap:6px;font-size:0.82rem;color:#3b82f6;text-decoration:none;font-weight:500;margin-bottom:16px;padding:6px 12px;border-radius:8px;transition:background .15s}
.pd-back:hover{background:#eff6ff}
.pd-hero{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:28px;margin-bottom:16px;display:flex;gap:24px;align-items:flex-start}
.pd-hero-img{width:120px;height:120px;border-radius:14px;object-fit:cover;border:2px solid #e2e8f0;flex-shrink:0;background:#f1f5f9;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:2.2rem}
.pd-hero-info{flex:1;min-width:0}
.pd-hero-name{font-size:1.5rem;font-weight:700;color:#1e293b;margin-bottom:4px}
.pd-hero-sub{font-size:0.9rem;color:#64748b;margin-bottom:8px}
.pd-hero-badges{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
.pd-hero-stats{display:flex;gap:16px;flex-wrap:wrap}
.pd-hero-stat{text-align:center;padding:10px 18px;background:#f8fafc;border-radius:10px;min-width:100px}
.pd-hero-stat-val{font-size:1.2rem;font-weight:700;color:#1e293b}
.pd-hero-stat-label{font-size:0.68rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.3px}

.pd-tabs{display:flex;gap:2px;background:#f1f5f9;border-radius:10px;padding:3px;margin-bottom:16px;overflow-x:auto}
.pd-tab{padding:9px 16px;border-radius:8px;font-size:0.82rem;font-weight:600;color:#64748b;text-decoration:none;white-space:nowrap;transition:all .15s;border:none;background:none;cursor:pointer}
.pd-tab:hover{color:#1e293b;background:rgba(255,255,255,.5)}
.pd-tab.active{background:#fff;color:#1e293b;box-shadow:0 1px 3px rgba(0,0,0,.06)}

.pd-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin-bottom:16px}
.pd-card-title{font-size:0.9rem;font-weight:700;color:#1e293b;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.pd-card-title i{font-size:0.85rem;color:#3b82f6}

.pd-grid{display:grid;gap:12px}
.pd-grid-2{grid-template-columns:1fr 1fr}
.pd-grid-3{grid-template-columns:1fr 1fr 1fr}
.pd-grid-4{grid-template-columns:1fr 1fr 1fr 1fr}
.pd-field{padding:12px 14px;background:#f8fafc;border-radius:8px;border:1px solid #f1f5f9}
.pd-field-label{font-size:0.68rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.3px;margin-bottom:3px}
.pd-field-val{font-size:0.88rem;font-weight:600;color:#1e293b}

.pd-table{width:100%;border-collapse:collapse;font-size:0.82rem}
.pd-table th{padding:9px 12px;text-align:left;font-size:0.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;border-bottom:1px solid #e2e8f0;background:#f8fafc}
.pd-table td{padding:9px 12px;border-bottom:1px solid #f1f5f9;color:#334155;vertical-align:middle}
.pd-table tbody tr:hover{background:#f8fafc}

.pd-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:600;white-space:nowrap}
.pd-badge-green{background:#dcfce7;color:#16a34a}
.pd-badge-orange{background:#fff7ed;color:#ea580c}
.pd-badge-red{background:#fef2f2;color:#dc2626}
.pd-badge-black{background:#1e293b;color:#fff}
.pd-badge-gray{background:#f1f5f9;color:#64748b}
.pd-badge-blue{background:#eff6ff;color:#3b82f6}
.pd-badge-purple{background:#f5f3ff;color:#7c3aed}

.pd-empty{text-align:center;padding:40px;color:#94a3b8;font-size:0.88rem}
.pd-empty i{display:block;font-size:1.8rem;margin-bottom:8px;color:#cbd5e1}

@media(max-width:768px){.pd-hero{flex-direction:column;align-items:center;text-align:center}.pd-hero-stats{justify-content:center}.pd-grid-2,.pd-grid-3,.pd-grid-4{grid-template-columns:1fr}}
@media print{.pd-tabs,.pd-back{display:none!important}.pd-card{break-inside:avoid}}
</style>

<section class="pd-wrap">
    <a href="?page=products" class="pd-back"><i class="fas fa-arrow-left"></i> Back to Products</a>

    <div class="pd-hero">
        <?php if (!empty($product['image_path'])): ?>
            <img src="<?= htmlspecialchars(storage_url($product['image_path'], 'products')) ?>" alt="" style="width:120px;height:120px;border-radius:14px;object-fit:cover;border:2px solid #e2e8f0">
        <?php else: ?>
            <div class="pd-hero-img"><i class="fas fa-pills"></i></div>
        <?php endif; ?>
        <div class="pd-hero-info">
            <div class="pd-hero-name"><?= htmlspecialchars($product['name']) ?></div>
            <div class="pd-hero-sub">
                <?php if (!empty($product['brand'])): ?><?= htmlspecialchars($product['brand']) ?><?php endif; ?>
                <?php if (!empty($product['generic_name'])): ?> &middot; <?= htmlspecialchars($product['generic_name']) ?><?php endif; ?>
                <?php if (!empty($product['dosage_form'])): ?> &middot; <?= htmlspecialchars($product['dosage_form']) ?><?php endif; ?>
                <?php if (!empty($product['strength'])): ?> &middot; <?= htmlspecialchars($product['strength']) ?><?php endif; ?>
            </div>
            <div class="pd-hero-badges">
                <span class="pd-badge pd-badge-blue" style="font-family:monospace"><?= htmlspecialchars($product['sku'] ?? '--') ?></span>
                <span class="pd-badge pd-badge-purple" style="font-family:monospace"><?= htmlspecialchars($product['product_code'] ?? '--') ?></span>
                <?php if (!empty($product['requires_prescription'])): ?>
                    <span class="pd-badge pd-badge-red"><i class="fas fa-prescription"></i> Rx</span>
                <?php endif; ?>
                <?php
                $pSt = $product['product_status'] ?? 'active';
                $stMap = ['active' => ['pd-badge-green', 'Active'], 'inactive' => ['pd-badge-gray', 'Inactive'], 'discontinued' => ['pd-badge-red', 'Discontinued']];
                $stInfo = $stMap[$pSt] ?? ['pd-badge-gray', $pSt];
                ?>
                <span class="pd-badge <?= $stInfo[0] ?>"><?= $stInfo[1] ?></span>
            </div>
            <div class="pd-hero-stats">
                <div class="pd-hero-stat"><div class="pd-hero-stat-val" style="color:<?= $stock == 0 ? '#dc2626' : ($stock <= ($product['reorder_level'] ?? 0) ? '#ea580c' : '#16a34a') ?>"><?= number_format($stock) ?></div><div class="pd-hero-stat-label">Stock</div></div>
                <div class="pd-hero-stat"><div class="pd-hero-stat-val" style="color:#16a34a"><?= fmt($sellPrice) ?></div><div class="pd-hero-stat-label">Sell Price</div></div>
                <div class="pd-hero-stat"><div class="pd-hero-stat-val" style="color:#f59e0b"><?= fmt($purchasePrice) ?></div><div class="pd-hero-stat-label">Cost Price</div></div>
                <div class="pd-hero-stat"><div class="pd-hero-stat-val" style="color:#7c3aed"><?= $margin ?>%</div><div class="pd-hero-stat-label">Margin</div></div>
                <div class="pd-hero-stat"><div class="pd-hero-stat-val"><?= fmt($inventoryValue) ?></div><div class="pd-hero-stat-label">Inventory Value</div></div>
            </div>
        </div>
    </div>

    <div class="pd-tabs">
        <a href="?page=product_detail&id=<?= $productId ?>&tab=overview" class="pd-tab <?= $activeTab === 'overview' ? 'active' : '' ?>"><i class="fas fa-info-circle"></i> Overview</a>
        <a href="?page=product_detail&id=<?= $productId ?>&tab=batches" class="pd-tab <?= $activeTab === 'batches' ? 'active' : '' ?>"><i class="fas fa-layer-group"></i> Batches (<?= $batchCount ?>)</a>
        <a href="?page=product_detail&id=<?= $productId ?>&tab=stock" class="pd-tab <?= $activeTab === 'stock' ? 'active' : '' ?>"><i class="fas fa-exchange-alt"></i> Stock Movements</a>
        <a href="?page=product_detail&id=<?= $productId ?>&tab=purchases" class="pd-tab <?= $activeTab === 'purchases' ? 'active' : '' ?>"><i class="fas fa-truck"></i> Purchase History</a>
        <a href="?page=product_detail&id=<?= $productId ?>&tab=sales" class="pd-tab <?= $activeTab === 'sales' ? 'active' : '' ?>"><i class="fas fa-shopping-cart"></i> Sales History</a>
        <a href="?page=product_detail&id=<?= $productId ?>&tab=expiry" class="pd-tab <?= $activeTab === 'expiry' ? 'active' : '' ?>"><i class="fas fa-clock"></i> Expiry Timeline</a>
        <a href="?page=product_detail&id=<?= $productId ?>&tab=profit" class="pd-tab <?= $activeTab === 'profit' ? 'active' : '' ?>"><i class="fas fa-chart-line"></i> Profit Analysis</a>
    </div>

    <?php if ($activeTab === 'overview'): ?>
    <div class="pd-card">
        <div class="pd-card-title"><i class="fas fa-box"></i> Product Information</div>
        <div class="pd-grid pd-grid-3">
            <div class="pd-field"><div class="pd-field-label">Product Name</div><div class="pd-field-val"><?= htmlspecialchars($product['name']) ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Brand</div><div class="pd-field-val"><?= htmlspecialchars($product['brand'] ?: '--') ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Generic Name</div><div class="pd-field-val"><?= htmlspecialchars($product['generic_name'] ?: '--') ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Dosage Form</div><div class="pd-field-val"><?= htmlspecialchars($product['dosage_form'] ?: '--') ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Strength</div><div class="pd-field-val"><?= htmlspecialchars($product['strength'] ?: '--') ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Pack Size</div><div class="pd-field-val"><?= htmlspecialchars($product['pack_size'] ?: '--') ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Unit of Measure</div><div class="pd-field-val"><?= htmlspecialchars($product['unit_of_measure'] ?: '--') ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Category</div><div class="pd-field-val"><?= htmlspecialchars($product['category_name'] ?: 'No Category') ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Product Type</div><div class="pd-field-val"><?= htmlspecialchars(ucfirst($product['product_type'] ?? 'otc')) ?></div></div>
        </div>
    </div>

    <div class="pd-card">
        <div class="pd-card-title"><i class="fas fa-tag"></i> Pricing & Stock</div>
        <div class="pd-grid pd-grid-4">
            <div class="pd-field" style="background:#f0fdf4"><div class="pd-field-label">Selling Price</div><div class="pd-field-val" style="font-size:1.05rem;color:#16a34a"><?= fmt($sellPrice) ?></div></div>
            <div class="pd-field" style="background:#fff7ed"><div class="pd-field-label">Purchase Price</div><div class="pd-field-val" style="font-size:1.05rem;color:#f59e0b"><?= fmt($purchasePrice) ?></div></div>
            <div class="pd-field" style="background:#f5f3ff"><div class="pd-field-label">Wholesale Price</div><div class="pd-field-val" style="font-size:1.05rem;color:#7c3aed"><?= fmt((float)($product['wholesale_price'] ?? 0)) ?></div></div>
            <div class="pd-field"><div class="pd-field-label">VAT %</div><div class="pd-field-val"><?= htmlspecialchars((string) ($product['vat_percent'] ?? SettingsService::vatRate())) ?>%</div></div>
            <div class="pd-field"><div class="pd-field-label">Profit per Unit</div><div class="pd-field-val" style="color:<?= $profit > 0 ? '#16a34a' : '#dc2626' ?>"><?= fmt($profit) ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Profit Margin</div><div class="pd-field-val" style="color:<?= $margin > 0 ? '#16a34a' : '#dc2626' ?>"><?= $margin ?>%</div></div>
            <div class="pd-field"><div class="pd-field-label">Stock Quantity</div><div class="pd-field-val" style="font-weight:700"><?= number_format($stock) ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Reorder Level</div><div class="pd-field-val"><?= number_format((int)($product['reorder_level'] ?? 0)) ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Min Order Qty</div><div class="pd-field-val"><?= number_format((int)($product['min_order_qty'] ?? 0)) ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Max Stock Level</div><div class="pd-field-val"><?= number_format((int)($product['max_stock_level'] ?? 0)) ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Inventory Value</div><div class="pd-field-val"><?= fmt($inventoryValue) ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Potential Revenue</div><div class="pd-field-val" style="color:#16a34a"><?= fmt($potentialRevenue) ?></div></div>
        </div>
    </div>

    <div class="pd-card">
        <div class="pd-card-title"><i class="fas fa-truck"></i> Supply Chain & Storage</div>
        <div class="pd-grid pd-grid-3">
            <div class="pd-field"><div class="pd-field-label">Supplier</div><div class="pd-field-val"><?= htmlspecialchars($product['supplier_name'] ?: 'Not Assigned') ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Supplier Product Code</div><div class="pd-field-val"><?= htmlspecialchars($product['supplier_product_code'] ?: '--') ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Internal Code</div><div class="pd-field-val"><?= htmlspecialchars($product['internal_product_code'] ?: '--') ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Manufacturer</div><div class="pd-field-val"><?= htmlspecialchars($product['manufacturer'] ?: '--') ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Country of Origin</div><div class="pd-field-val"><?php if ($flag): ?><span style="margin-right:6px;font-size:1.1rem"><?= $flag ?></span><?php endif; ?><?= htmlspecialchars($product['country_of_origin'] ?: '--') ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Barcode</div><div class="pd-field-val" style="font-family:monospace"><?= htmlspecialchars($product['barcode'] ?: '--') ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Storage Condition</div><div class="pd-field-val"><?= htmlspecialchars($product['storage_condition'] ?: '--') ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Shelf Life</div><div class="pd-field-val"><?= !empty($product['shelf_life']) ? $product['shelf_life'] . ' months' : '--' ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Shelf Location</div><div class="pd-field-val"><?= htmlspecialchars($product['shelf_location'] ?: '--') ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Product Weight</div><div class="pd-field-val"><?= htmlspecialchars($product['product_weight'] ?: '--') ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Package Dimensions</div><div class="pd-field-val"><?= htmlspecialchars($product['package_dimensions'] ?: '--') ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Manufacturing Date</div><div class="pd-field-val"><?= htmlspecialchars($product['manufacturing_date'] ?: '--') ?></div></div>
        </div>
    </div>

    <div class="pd-card">
        <div class="pd-card-title"><i class="fas fa-robot"></i> System Information</div>
        <div class="pd-grid pd-grid-3">
            <div class="pd-field"><div class="pd-field-label">Product Code</div><div class="pd-field-val" style="font-family:monospace"><?= htmlspecialchars($product['product_code'] ?? '--') ?></div></div>
            <div class="pd-field"><div class="pd-field-label">SKU</div><div class="pd-field-val" style="font-family:monospace"><?= htmlspecialchars($product['sku'] ?? '--') ?></div></div>
            <div class="pd-field"><div class="pd-field-label">QR Code</div><div class="pd-field-val"><?php if (!empty($product['qr_code'])): ?><img src="<?= htmlspecialchars($product['qr_code']) ?>" style="width:60px;height:60px;border-radius:6px;border:1px solid #e2e8f0"><?php else: ?>--<?php endif; ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Created At</div><div class="pd-field-val"><?= htmlspecialchars($product['created_at'] ?? '--') ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Updated At</div><div class="pd-field-val"><?= htmlspecialchars($product['updated_at'] ?? 'Never') ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Created By</div><div class="pd-field-val"><?php if (!empty($product['created_by'])): ?><?php echo htmlspecialchars(userFullName($pdo, (int) $product['created_by']) ?: 'User #' . $product['created_by']); ?><?php else: ?>--<?php endif; ?></div></div>
        </div>
    </div>

    <?php if (!empty($product['description'])): ?>
    <div class="pd-card">
        <div class="pd-card-title"><i class="fas fa-align-left"></i> Description</div>
        <p style="font-size:0.88rem;color:#475569;line-height:1.6;margin:0"><?= nl2br(htmlspecialchars($product['description'])) ?></p>
    </div>
    <?php endif; ?>

    <?php elseif ($activeTab === 'batches'): ?>
    <div class="pd-card">
        <div class="pd-card-title"><i class="fas fa-layer-group"></i> Batch History (<?= $batchCount ?> batches)</div>
        <?php if (empty($batches)): ?>
            <div class="pd-empty"><i class="fas fa-layer-group"></i> No batches recorded for this product.</div>
        <?php else: ?>
        <div style="overflow-x:auto">
            <table class="pd-table">
                <thead><tr><th>Batch #</th><th>Expiry Date</th><th>Quantity</th><th>Purchase Price</th><th>Days Left</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($batches as $b):
                    $dl = (int)$b['days_left'];
                    if ($dl < 0) { $bst = 'Expired'; $bcls = 'pd-badge-black'; }
                    elseif ($dl <= 30) { $bst = $dl . 'd left'; $bcls = 'pd-badge-red'; }
                    elseif ($dl <= 90) { $bst = $dl . 'd left'; $bcls = 'pd-badge-orange'; }
                    else { $bst = $dl . 'd left'; $bcls = 'pd-badge-green'; }
                ?>
                <tr>
                    <td style="font-weight:600;font-family:monospace"><?= htmlspecialchars($b['batch_number']) ?></td>
                    <td style="<?= $dl <= 30 ? 'font-weight:700;color:#dc2626' : '' ?>"><?= htmlspecialchars($b['expiry_date']) ?></td>
                    <td style="font-weight:600"><?= number_format((int)$b['quantity']) ?></td>
                    <td><?= fmt((float)$b['purchase_price']) ?></td>
                    <td><?= $dl ?>d</td>
                    <td><span class="pd-badge <?= $bcls ?>"><?= $bst ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php elseif ($activeTab === 'stock'): ?>
    <div class="pd-card">
        <div class="pd-card-title"><i class="fas fa-exchange-alt"></i> Stock Movements</div>
        <?php if (empty($stockMovements)): ?>
            <div class="pd-empty"><i class="fas fa-exchange-alt"></i> No stock movements recorded.</div>
        <?php else: ?>
        <div style="overflow-x:auto">
            <table class="pd-table">
                <thead><tr><th>Date</th><th>Change</th><th>Reason</th><th>Reference</th><th>By</th></tr></thead>
                <tbody>
                <?php foreach ($stockMovements as $sm): ?>
                <tr>
                    <td><?= htmlspecialchars($sm['created_at']) ?></td>
                    <td style="font-weight:700;color:<?= (int)$sm['quantity_change'] >= 0 ? '#16a34a' : '#dc2626' ?>"><?= ((int)$sm['quantity_change'] >= 0 ? '+' : '') . (int)$sm['quantity_change'] ?></td>
                    <td><?= htmlspecialchars($sm['reason'] ?? '--') ?></td>
                    <td style="font-family:monospace;font-size:0.78rem"><?= htmlspecialchars($sm['reference_type'] ?? '--') ?></td>
                    <td><?= htmlspecialchars($sm['user_name'] ?? 'System') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php elseif ($activeTab === 'purchases'): ?>
    <div class="pd-card">
        <div class="pd-card-title"><i class="fas fa-truck"></i> Purchase History</div>
        <?php if (empty($purchaseHistory)): ?>
            <div class="pd-empty"><i class="fas fa-truck"></i> No purchase history found.</div>
        <?php else: ?>
        <div style="overflow-x:auto">
            <table class="pd-table">
                <thead><tr><th>PO #</th><th>Date</th><th>Supplier</th><th>Qty</th><th>Unit Price</th><th>Total</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($purchaseHistory as $ph):
                    $pos = htmlspecialchars($ph['po_status'] ?? '');
                    $posCls = $pos === 'received' ? 'pd-badge-green' : ($pos === 'pending' ? 'pd-badge-orange' : 'pd-badge-gray');
                ?>
                <tr>
                    <td style="font-weight:600;font-family:monospace">PO-<?= str_pad((int)$ph['po_id'], 5, '0', STR_PAD_LEFT) ?></td>
                    <td><?= htmlspecialchars($ph['order_date']) ?></td>
                    <td><?= htmlspecialchars($ph['supplier_name'] ?: 'Not Assigned') ?></td>
                    <td style="font-weight:600"><?= number_format((int)$ph['quantity']) ?></td>
                    <td><?= fmt((float)$ph['unit_price']) ?></td>
                    <td style="font-weight:600"><?= fmt((float)$ph['quantity'] * (float)$ph['unit_price']) ?></td>
                    <td><span class="pd-badge <?= $posCls ?>"><?= ucfirst(htmlspecialchars($pos)) ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php elseif ($activeTab === 'sales'): ?>
    <div class="pd-card">
        <div class="pd-card-title"><i class="fas fa-shopping-cart"></i> Sales History</div>
        <?php if (empty($salesHistory)): ?>
            <div class="pd-empty"><i class="fas fa-shopping-cart"></i> No sales recorded for this product.</div>
        <?php else: ?>
        <div style="overflow-x:auto">
            <table class="pd-table">
                <thead><tr><th>Sale #</th><th>Date</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr></thead>
                <tbody>
                <?php foreach ($salesHistory as $sh): ?>
                <tr>
                    <td style="font-weight:600;font-family:monospace">SALE-<?= str_pad((int)$sh['sale_id'], 5, '0', STR_PAD_LEFT) ?></td>
                    <td><?= htmlspecialchars($sh['sale_date']) ?></td>
                    <td style="font-weight:600"><?= number_format((int)$sh['quantity']) ?></td>
                    <td><?= fmt((float)$sh['unit_price']) ?></td>
                    <td style="font-weight:600"><?= fmt((float)$sh['quantity'] * (float)$sh['unit_price']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php elseif ($activeTab === 'expiry'): ?>
    <div class="pd-card">
        <div class="pd-card-title"><i class="fas fa-clock"></i> Expiry Timeline</div>
        <?php if (empty($batches)): ?>
            <div class="pd-empty"><i class="fas fa-clock"></i> No batch/expiry data available.</div>
        <?php else: ?>
        <div style="position:relative;padding-left:24px">
            <?php foreach ($batches as $b):
                $dl = (int)$b['days_left'];
                $dotColor = $dl < 0 ? '#dc2626' : ($dl <= 30 ? '#ea580c' : ($dl <= 90 ? '#f59e0b' : '#16a34a'));
                $lineColor = $dl < 0 ? '#fecaca' : ($dl <= 30 ? '#fed7aa' : ($dl <= 90 ? '#fde68a' : '#bbf7d0'));
            ?>
            <div style="position:relative;padding:12px 0 12px 20px;border-left:2px solid <?= $lineColor ?>">
                <div style="position:absolute;left:-7px;top:16px;width:12px;height:12px;border-radius:50%;background:<?= $dotColor ?>;border:2px solid #fff"></div>
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
                        <div>
                            <strong style="font-family:monospace;color:#1e293b"><?= htmlspecialchars($b['batch_number']) ?></strong>
                            <span style="font-size:0.78rem;color:#64748b;margin-left:8px"><?= number_format((int)$b['quantity']) ?> units</span>
                        </div>
                        <div style="text-align:right">
                            <div style="font-weight:600;color:<?= $dotColor ?>"><?= htmlspecialchars($b['expiry_date']) ?></div>
                            <div style="font-size:0.72rem;color:#94a3b8"><?= $dl < 0 ? 'Expired ' . abs($dl) . ' days ago' : ($dl == 0 ? 'Expires today!' : $dl . ' days remaining') ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php elseif ($activeTab === 'profit'): ?>
    <div class="pd-card">
        <div class="pd-card-title"><i class="fas fa-chart-line"></i> Profit Analysis</div>
        <div class="pd-grid pd-grid-3" style="margin-bottom:16px">
            <div class="pd-field" style="background:#f0fdf4;border:1px solid #bbf7d0"><div class="pd-field-label">Profit per Unit</div><div class="pd-field-val" style="font-size:1.1rem;color:#16a34a"><?= fmt($profit) ?></div></div>
            <div class="pd-field" style="background:#f0fdf4;border:1px solid #bbf7d0"><div class="pd-field-label">Profit Margin</div><div class="pd-field-val" style="font-size:1.1rem;color:#16a34a"><?= $margin ?>%</div></div>
            <div class="pd-field" style="background:#f5f3ff;border:1px solid #c4b5fd"><div class="pd-field-label">Potential Profit (Current Stock)</div><div class="pd-field-val" style="font-size:1.1rem;color:#7c3aed"><?= fmt($potentialProfit) ?></div></div>
        </div>
        <div class="pd-grid pd-grid-2" style="margin-bottom:16px">
            <div class="pd-field"><div class="pd-field-label">Total Units Sold</div><div class="pd-field-val" style="font-size:1.05rem;font-weight:700"><?= number_format((int)$totalSold) ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Total Sales Revenue</div><div class="pd-field-val" style="font-size:1.05rem;font-weight:700;color:#16a34a"><?= fmt((float)$totalRevenue) ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Total Units Purchased</div><div class="pd-field-val" style="font-size:1.05rem;font-weight:700"><?= number_format((int)$totalPurchased) ?></div></div>
            <div class="pd-field"><div class="pd-field-label">Total Purchase Cost</div><div class="pd-field-val" style="font-size:1.05rem;font-weight:700;color:#f59e0b"><?= fmt((float)$totalPurchaseCost) ?></div></div>
        </div>
        <?php
        $totalProfitRealized = (float)$totalRevenue - (float)$totalPurchaseCost;
        $realizedMargin = (float)$totalPurchaseCost > 0 ? round($totalProfitRealized / (float)$totalPurchaseCost * 100, 1) : 0;
        ?>
        <div style="background:<?= $totalProfitRealized >= 0 ? '#f0fdf4' : '#fef2f2' ?>;border:1px solid <?= $totalProfitRealized >= 0 ? '#bbf7d0' : '#fecaca' ?>;border-radius:10px;padding:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
            <div>
                <div style="font-size:0.72rem;color:#94a3b8;text-transform:uppercase;font-weight:600;letter-spacing:.3px">Total Realized Profit</div>
                <div style="font-size:1.4rem;font-weight:700;color:<?= $totalProfitRealized >= 0 ? '#16a34a' : '#dc2626' ?>"><?= fmt($totalProfitRealized) ?></div>
            </div>
            <div>
                <div style="font-size:0.72rem;color:#94a3b8;text-transform:uppercase;font-weight:600;letter-spacing:.3px">Realized Margin</div>
                <div style="font-size:1.4rem;font-weight:700;color:<?= $realizedMargin >= 0 ? '#16a34a' : '#dc2626' ?>"><?= $realizedMargin ?>%</div>
            </div>
        </div>
    </div>

    <?php if (!empty($priceHistory)): ?>
    <div class="pd-card">
        <div class="pd-card-title"><i class="fas fa-history"></i> Price Change History</div>
        <div style="overflow-x:auto">
            <table class="pd-table">
                <thead><tr><th>Date</th><th>Old Price</th><th>New Price</th><th>Change</th><th>Changed By</th></tr></thead>
                <tbody>
                <?php foreach ($priceHistory as $ph):
                    $change = (float)$ph['new_price'] - (float)$ph['old_price'];
                    $changePct = (float)$ph['old_price'] > 0 ? round($change / (float)$ph['old_price'] * 100, 1) : 0;
                ?>
                <tr>
                    <td><?= htmlspecialchars($ph['created_at']) ?></td>
                    <td><?= fmt((float)$ph['old_price']) ?></td>
                    <td style="font-weight:600"><?= fmt((float)$ph['new_price']) ?></td>
                    <td style="font-weight:700;color:<?= $change >= 0 ? '#16a34a' : '#dc2626' ?>"><?= $change >= 0 ? '+' : '' ?><?= fmt($change) ?> (<?= $change >= 0 ? '+' : '' ?><?= $changePct ?>%)</td>
                    <td><?= htmlspecialchars($ph['user_name'] ?? 'System') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</section>
