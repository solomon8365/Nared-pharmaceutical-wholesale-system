<?php
requirePermission('stock_movements', 'view');
require_once __DIR__ . '/../includes/stock_helper.php';
$pdo = getPDO();

$from  = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to    = $_GET['to'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';

$sql = 'SELECT sm.*, p.name AS product_name FROM stock_movements sm LEFT JOIN products p ON sm.product_id = p.id WHERE DATE(sm.created_at) >= :from AND DATE(sm.created_at) <= :to';
$params = ['from' => $from, 'to' => $to];

if ($search !== '') {
    $sql .= ' AND (p.name LIKE :search OR sm.reason LIKE :search2)';
    $params['search'] = "%$search%";
    $params['search2'] = "%$search%";
}

$sql .= ' ORDER BY sm.created_at DESC';
$movements = $pdo->prepare($sql);
$movements->execute($params);
$rows = $movements->fetchAll();
?>
<section>
    <h1>Stock Movement Log</h1>
    <div class="panel">
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
            <input type="hidden" name="page" value="stock_movements">
            <label style="margin:0;font-size:0.85rem">From<input type="date" name="from" value="<?= htmlspecialchars($from) ?>" style="margin:0"></label>
            <label style="margin:0;font-size:0.85rem">To<input type="date" name="to" value="<?= htmlspecialchars($to) ?>" style="margin:0"></label>
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Product or reason..." style="flex:1;min-width:140px;margin:0">
            <button type="submit" style="margin:0">Filter</button>
            <?php if ($search !== '' || $from !== date('Y-m-d', strtotime('-30 days')) || $to !== date('Y-m-d')): ?>
                <a href="?page=stock_movements" style="display:inline-flex;align-items:center;padding:0 12px;color:var(--brown-800);font-weight:600">Reset</a>
            <?php endif; ?>
        </form>
        <table>
            <thead>
                <tr><th>Date/Time</th><th>Product</th><th>Quantity</th><th>Reason</th><th>Reference</th></tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="5">No stock movements found.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <?php
                        $qty = (int) $r['quantity_change'];
                        $qtyClass = $qty >= 0 ? 'text-success' : 'text-danger';
                        $qtyDisplay = $qty >= 0 ? '+' . $qty : (string) $qty;
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($r['created_at']) ?></td>
                        <td><a href="?page=products&edit=<?= $r['product_id'] ?>" style="color:var(--brown-800);font-weight:600;text-decoration:none"><?= htmlspecialchars($r['product_name'] ?? 'Deleted #'.$r['product_id']) ?></a></td>
                        <td class="<?= $qtyClass ?>" style="font-weight:700"><?= $qtyDisplay ?></td>
                        <td><?= htmlspecialchars($r['reason']) ?></td>
                        <td>
                            <?php if ($r['reference_type'] && $r['reference_id']): ?>
                                <?= htmlspecialchars($r['reference_type']) ?> #<?= (int) $r['reference_id'] ?>
                            <?php else: ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
