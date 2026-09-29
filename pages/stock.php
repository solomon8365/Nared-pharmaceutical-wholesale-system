<?php
requirePermission('stock', 'view');
require_once __DIR__ . '/../includes/stock_helper.php';
$pdo = getPDO();

$lowThreshold = (int) get_setting('low_stock_threshold', '10');

$search = trim($_GET['search'] ?? '');
$categoryId = (int) ($_GET['category_id'] ?? 0);
$lowStock = isset($_GET['low_stock']);

$where = ['1=1'];
$params = [];
if ($search !== '') {
    $where[] = '(p.name LIKE :s1 OR p.brand LIKE :s2 OR p.barcode LIKE :s3)';
    $sv = "%$search%";
    $params['s1'] = $sv;
    $params['s2'] = $sv;
    $params['s3'] = $sv;
}
if ($categoryId > 0) {
    $where[] = 'p.category_id = :cat';
    $params['cat'] = $categoryId;
}
if ($lowStock) {
    $where[] = 'p.stock_quantity <= :thresh';
    $params['thresh'] = $lowThreshold;
}

$sql = 'SELECT p.id, p.name, p.brand, p.barcode, p.stock_quantity, ' . $lowThreshold . ' AS low_stock_qty, p.price, p.purchase_price, c.name AS category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE ' . implode(' AND ', $where) . ' ORDER BY p.name ASC';
$products = $pdo->prepare($sql);
$products->execute($params);
$products = $products->fetchAll();

$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$summary = stockTotals($pdo, $lowThreshold, 'purchase_price');
?>
<section>
    <h1>Stock Overview</h1>
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px">
        <div class="card"><h2>Total Products</h2><p><?= (int) ($summary['total'] ?? 0) ?></p></div>
        <div class="card"><h2>Total Stock</h2><p><?= (int) ($summary['total_qty'] ?? 0) ?></p></div>
        <div class="card"><h2>Stock Value</h2><p><?= fmt((float) ($summary['total_value'] ?? 0)) ?></p></div>
        <div class="card"><h2>Low Stock</h2><p style="color:var(--amber-700)"><?= (int) ($summary['low_count'] ?? 0) ?></p></div>
        <div class="card"><h2>Out of Stock</h2><p style="color:var(--red-700)"><?= (int) ($summary['out_count'] ?? 0) ?></p></div>
    </div>
    <div class="panel">
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
            <input type="hidden" name="page" value="stock">
            <input type="text" name="search" placeholder="Search by name, brand, barcode…" value="<?= htmlspecialchars($search) ?>" style="flex:1;min-width:160px;margin:0">
            <select name="category_id" style="margin:0">
                <option value="">All categories</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"<?= $categoryId === (int) $c['id'] ? ' selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label style="display:flex;align-items:center;gap:4px;margin:0;white-space:nowrap"><input type="checkbox" name="low_stock" value="1"<?= $lowStock ? ' checked' : '' ?>> Low stock only</label>
            <button type="submit" style="margin:0">Filter</button>
            <?php if ($search !== '' || $categoryId > 0 || $lowStock): ?><a href="?page=stock" style="display:inline-flex;align-items:center;padding:0 12px;color:var(--brown-800);font-weight:600">Clear</a><?php endif; ?>
        </form>
        <table>
            <thead><tr><th>Product</th><th>Brand</th><th>Category</th><th>Stock</th><th>Low Stock</th><th>Value</th></tr></thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr><td colspan="6">No products found.</td></tr>
                <?php else: ?>
                    <?php foreach ($products as $p): ?>
                        <?php $qty = (int) $p['stock_quantity']; $low = (int) $p['low_stock_qty']; ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($p['name']) ?></strong><?= $p['barcode'] ? '<br><span class="cell-sub">' . htmlspecialchars($p['barcode']) . '</span>' : '' ?></td>
                            <td><?= htmlspecialchars((string) ($p['brand'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($p['category_name'] ?? '')) ?></td>
                            <td><span class="status-badge status-<?= $qty <= 0 ? 'rejected' : ($qty <= $low ? 'pending' : 'approved') ?>"><?= $qty ?></span></td>
                            <td><?= $low ?></td>
                            <td><?= fmt((float) ($qty * (float) $p['purchase_price'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
