<?php
requirePermission('price_history', 'view');
$pdo = getPDO();

$productId = (int) ($_GET['product_id'] ?? 0);
$from  = $_GET['from'] ?? date('Y-m-d', strtotime('-90 days'));
$to    = $_GET['to'] ?? date('Y-m-d');

$sql = 'SELECT ph.*, p.name AS product_name FROM price_history ph LEFT JOIN products p ON ph.product_id = p.id WHERE DATE(ph.created_at) >= :from AND DATE(ph.created_at) <= :to';
$params = ['from' => $from, 'to' => $to];

if ($productId > 0) {
    $sql .= ' AND ph.product_id = :pid';
    $params['pid'] = $productId;
}

$sql .= ' ORDER BY ph.created_at DESC';
$rows = $pdo->prepare($sql);
$rows->execute($params);
$history = $rows->fetchAll();

$products = $pdo->query('SELECT id, name FROM products ORDER BY name')->fetchAll();
?>
<section>
    <h1>Price Change History</h1>
    <div class="panel">
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
            <input type="hidden" name="page" value="price_history">
            <label style="margin:0;font-size:0.85rem">Product
                <select name="product_id" style="min-width:140px;margin:0">
                    <option value="">All products</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $productId === (int) $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="margin:0;font-size:0.85rem">From<input type="date" name="from" value="<?= htmlspecialchars($from) ?>" style="margin:0"></label>
            <label style="margin:0;font-size:0.85rem">To<input type="date" name="to" value="<?= htmlspecialchars($to) ?>" style="margin:0"></label>
            <button type="submit" style="margin:0">Filter</button>
            <?php if ($productId > 0 || $from !== date('Y-m-d', strtotime('-90 days')) || $to !== date('Y-m-d')): ?>
                <a href="?page=price_history" style="display:inline-flex;align-items:center;padding:0 12px;color:var(--brown-800);font-weight:600">Reset</a>
            <?php endif; ?>
        </form>
        <table>
            <thead>
                <tr><th>Date/Time</th><th>Product</th><th>Old Price</th><th>New Price</th><th>Change</th></tr>
            </thead>
            <tbody>
            <?php if (empty($history)): ?>
                <tr><td colspan="5">No price changes found.</td></tr>
            <?php else: ?>
                <?php foreach ($history as $r): ?>
                    <?php
                        $old = (float) $r['old_price'];
                        $new = (float) $r['new_price'];
                        $diff = $new - $old;
                        $diffClass = $diff > 0 ? 'text-danger' : ($diff < 0 ? 'text-success' : '');
                        $diffSign = $diff > 0 ? '+' : '';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($r['created_at']) ?></td>
                        <td><a href="?page=products&edit=<?= $r['product_id'] ?>" style="color:var(--brown-800);font-weight:600;text-decoration:none"><?= htmlspecialchars($r['product_name'] ?? 'Deleted #'.$r['product_id']) ?></a></td>
                        <td><?= fmt($old) ?></td>
                        <td><?= fmt($new) ?></td>
                        <td class="<?= $diffClass ?>" style="font-weight:700"><?= $diffSign ?><?= fmt($diff) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
