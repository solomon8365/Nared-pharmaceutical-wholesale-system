<?php
requirePermission('expiry_alerts', 'view');
require_once __DIR__ . '/../includes/stock_helper.php';
$pdo = getPDO();

$filterProduct = trim($_GET['product'] ?? '');
$filterDays = (int) ($_GET['days'] ?? 90);
$filterStatus = trim($_GET['status'] ?? 'all');

$where = ['pb.quantity > 0'];
$params = [];

if ($filterProduct !== '') {
    $where[] = 'p.name LIKE :prod';
    $params['prod'] = '%' . $filterProduct . '%';
}
if ($filterStatus === 'expired') {
    $where[] = 'pb.expiry_date < CURDATE()';
} elseif ($filterStatus === 'expiring') {
    $where[] = 'pb.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY)';
    $params['days'] = $filterDays;
} elseif ($filterStatus === 'valid') {
    $where[] = 'pb.expiry_date > DATE_ADD(CURDATE(), INTERVAL :days DAY)';
    $params['days'] = $filterDays;
} else {
    $where[] = 'pb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL :days DAY)';
    $params['days'] = $filterDays;
}

$sql = "SELECT pb.id, pb.batch_number, pb.expiry_date, pb.quantity, pb.purchase_price, p.name AS product_name, p.id AS product_id
    FROM product_batches pb
    JOIN products p ON pb.product_id = p.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY pb.expiry_date ASC";
$batches = $pdo->prepare($sql);
$batches->execute($params);
$rows = $batches->fetchAll();

$expiryCounts = batchExpiryCounts($pdo, 90, true);
$expiredCount = $expiryCounts['expired_count'] ?? 0;
$expiringCount = $expiryCounts['expiring_count'] ?? 0;
?>
<section>
    <h1>Expiry Date Alerts</h1>

    <div class="grid cards cards-3">
        <div class="card" style="border-top-color:var(--red-600)"><h2>Expired</h2><p><?= $expiredCount ?></p></div>
        <div class="card" style="border-top-color:var(--red-600)"><h2>Expiring (90 days)</h2><p><?= $expiringCount ?></p></div>
        <div class="card"><h2>Showing</h2><p><?= count($rows) ?></p></div>
    </div>

    <div class="panel">
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
            <input type="hidden" name="page" value="expiry_alerts">
            <input type="text" name="product" placeholder="Product name" value="<?= htmlspecialchars($filterProduct) ?>" style="flex:1;min-width:140px;margin:0">
            <select name="status" style="margin:0">
                <option value="all"<?= $filterStatus === 'all' ? ' selected' : '' ?>>All alerts</option>
                <option value="expired"<?= $filterStatus === 'expired' ? ' selected' : '' ?>>Expired</option>
                <option value="expiring"<?= $filterStatus === 'expiring' ? ' selected' : '' ?>>Expiring soon</option>
                <option value="valid"<?= $filterStatus === 'valid' ? ' selected' : '' ?>>Valid</option>
            </select>
            <label style="display:flex;align-items:center;gap:4px;margin:0;font-size:0.85rem">
                Within
                <input type="number" name="days" value="<?= $filterDays ?>" min="1" style="width:60px;margin:0;font-size:0.85rem;padding:4px 6px">
                days
            </label>
            <button type="submit" style="margin:0">Filter</button>
            <?php if ($filterProduct !== '' || $filterStatus !== 'all' || $filterDays !== 90): ?>
                <a href="?page=expiry_alerts" style="display:inline-flex;align-items:center;padding:0 12px;color:var(--brown-800);font-weight:600">Clear</a>
            <?php endif; ?>
        </form>

        <table>
            <thead><tr><th>Product</th><th>Batch #</th><th>Expiry Date</th><th>Qty</th><th>Cost</th><th>Status</th></tr></thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="6">No batches found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $b): ?>
                        <?php
                        $expiry = strtotime($b['expiry_date']);
                        $now = time();
                        $daysLeft = $expiry ? round(($expiry - $now) / 86400) : null;
                        if ($daysLeft !== null && $daysLeft < 0) {
                            $statusText = 'Expired';
                            $statusColor = 'var(--red-700)';
                            $statusBg = 'var(--red-100)';
                        } elseif ($daysLeft !== null && $daysLeft <= 30) {
                            $statusText = $daysLeft . ' days';
                            $statusColor = 'var(--red-700)';
                            $statusBg = 'var(--red-100)';
                        } elseif ($daysLeft !== null && $daysLeft <= 90) {
                            $statusText = $daysLeft . ' days';
                            $statusColor = 'var(--red-600)';
                            $statusBg = '#fff3e0';
                        } else {
                            $statusText = 'OK';
                            $statusColor = 'var(--brown-600)';
                            $statusBg = 'var(--brown-100)';
                        }
                        ?>
                        <tr>
                            <td><a href="?page=products&edit=<?= $b['product_id'] ?>" style="font-weight:600;color:var(--brown-800);text-decoration:none"><?= htmlspecialchars($b['product_name']) ?></a></td>
                            <td><?= htmlspecialchars($b['batch_number']) ?></td>
                            <td><?= htmlspecialchars($b['expiry_date']) ?></td>
                            <td><?= (int) $b['quantity'] ?></td>
                            <td><?= $b['purchase_price'] ? fmt((float) $b['purchase_price']) : '-' ?></td>
                            <td><span style="display:inline-block;padding:2px 8px;border-radius:4px;font-weight:600;font-size:0.82rem;background:<?= $statusBg ?>;color:<?= $statusColor ?>"><?= $statusText ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
