<?php
requirePermission('stock_adjustments', 'view');
require_once __DIR__ . '/../includes/stock_helper.php';
$pdo = getPDO();
$messages = [];

$warehouses = $pdo->query('SELECT id, name FROM warehouses WHERE warehouse_status = \'active\' ORDER BY name')->fetchAll();
$products = $pdo->query('SELECT id, name, stock_quantity, purchase_price FROM products ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['_action'] ?? '';

    if ($action === 'adjust') {
        requirePermission('stock_adjustments', 'create');
        $productId = (int) ($_POST['product_id'] ?? 0);
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $warehouseId = !empty($_POST['warehouse_id']) ? (int)$_POST['warehouse_id'] : null;
        $batchId = !empty($_POST['batch_id']) ? (int)$_POST['batch_id'] : null;

        if ($productId <= 0 || $quantity === 0 || $reason === '') {
            $messages[] = ['type' => 'error', 'text' => 'Product, non-zero quantity, and reason are required.'];
        } else {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('SELECT id, stock_quantity FROM products WHERE id = :id FOR UPDATE');
                $stmt->execute(['id' => $productId]);
                $product = $stmt->fetch();
                if (!$product) throw new RuntimeException('Product not found.');

                if ($quantity < 0) {
                    $deduct = abs($quantity);
                    $currentStock = (int)$product['stock_quantity'];

                    if ($batchId) {
                        $bstmt = $pdo->prepare('SELECT quantity, product_id FROM product_batches WHERE id = :id FOR UPDATE');
                        $bstmt->execute(['id' => $batchId]);
                        $batch = $bstmt->fetch();
                        if (!$batch || (int)$batch['product_id'] !== $productId) throw new RuntimeException('Batch not found or does not belong to this product.');
                        if ((int)$batch['quantity'] < $deduct) throw new RuntimeException('Insufficient stock in selected batch.');
                        $pdo->prepare('UPDATE product_batches SET quantity = quantity - :qty WHERE id = :id')
                            ->execute(['qty' => $deduct, 'id' => $batchId]);
                        recalc_product_stock($pdo, $productId);
                    } elseif ($currentStock < $deduct) {
                        throw new RuntimeException('Insufficient stock.');
                    } else {
                        $pdo->prepare('UPDATE products SET stock_quantity = stock_quantity - :qty WHERE id = :id')
                            ->execute(['qty' => $deduct, 'id' => $productId]);
                    }
                } else {
                    if ($batchId) {
                        $pdo->prepare('UPDATE product_batches SET quantity = quantity + :qty WHERE id = :id')
                            ->execute(['qty' => $quantity, 'id' => $batchId]);
                        recalc_product_stock($pdo, $productId);
                    } else {
                        $pdo->prepare('UPDATE products SET stock_quantity = stock_quantity + :qty WHERE id = :id')
                            ->execute(['qty' => $quantity, 'id' => $productId]);
                    }
                }

                $refType = $warehouseId ? 'warehouse' : null;
                $refId = $warehouseId ?: null;
                log_stock_movement($pdo, $productId, $quantity, $reason, $refType, $refId);

                $pdo->commit();
                $messages[] = ['type' => 'success', 'text' => "Stock adjusted by " . ($quantity > 0 ? '+' : '') . "$quantity for product #$productId."];
            } catch (Exception $e) {
                $pdo->rollBack();
                $messages[] = ['type' => 'error', 'text' => 'Failed: ' . $e->getMessage()];
            }
        }
    }
}

// Fetch recent adjustments for display
$adjQuery = 'SELECT sm.*, p.name AS product_name FROM stock_movements sm JOIN products p ON sm.product_id = p.id ORDER BY sm.created_at DESC LIMIT 100';
$adjustments = $pdo->query($adjQuery)->fetchAll();
?>
<section>
    <h1>Stock Adjustments</h1>
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <div class="tab-bar">
        <?php if (hasPermission('stock_adjustments', 'create')): ?>
        <button class="tab-btn active" onclick="switchTab(this,'adj-create')"><i class="fas fa-plus"></i> New Adjustment</button>
        <?php endif; ?>
        <button class="tab-btn <?= hasPermission('stock_adjustments', 'create') ? '' : 'active' ?>" onclick="switchTab(this,'adj-history')"><i class="fas fa-history"></i> Adjustment History</button>
    </div>

    <div class="tab-content">
        <?php if (hasPermission('stock_adjustments', 'create')): ?>
        <div id="adj-create" class="tab-panel active">
            <div class="panel">
                <h2>New Stock Adjustment</h2>
                <form method="post" class="form-grid" style="max-width:500px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="adjust">
                    <label>Product
                        <select name="product_id" id="adj-product" required>
                            <option value="">Select product</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= $p['id'] ?>" data-batch="<?= has_batches($pdo, $p['id']) ? '1' : '0' ?>"><?= htmlspecialchars($p['name']) ?> (Stock: <?= $p['stock_quantity'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Warehouse
                        <select name="warehouse_id">
                            <option value="">— None —</option>
                            <?php foreach ($warehouses as $w): ?>
                                <option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Batch (if batch-tracked)
                        <select name="batch_id" id="adj-batch">
                            <option value="">— None —</option>
                        </select>
                    </label>
                    <label>Quantity (use negative for deduction)
                        <input type="number" name="quantity" value="0" required>
                    </label>
                    <label>Reason
                        <input type="text" name="reason" required placeholder="e.g. Damage, Theft, Return, Count adjustment">
                    </label>
                    <button type="submit">Apply Adjustment</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div id="adj-history" class="tab-panel">
            <div class="panel">
                <h2>Recent Adjustments</h2>
                <div style="overflow-x:auto">
                <table>
                    <thead><tr><th>Date</th><th>Product</th><th>Qty Change</th><th>Reason</th><th>Reference</th></tr></thead>
                    <tbody>
                        <?php if (empty($adjustments)): ?>
                            <tr><td colspan="5">No adjustments recorded yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($adjustments as $a): ?>
                                <tr>
                                    <td><?= htmlspecialchars($a['created_at']) ?></td>
                                    <td><?= htmlspecialchars($a['product_name']) ?></td>
                                    <td style="font-weight:700;color:<?= (int)$a['quantity_change'] >= 0 ? '#059669' : '#dc2626' ?>"><?= (int)$a['quantity_change'] >= 0 ? '+' : '' ?><?= (int)$a['quantity_change'] ?></td>
                                    <td><?= htmlspecialchars($a['reason']) ?></td>
                                    <td><?= $a['reference_type'] ? htmlspecialchars($a['reference_type'] . '#' . $a['reference_id']) : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</section>
<script>
document.getElementById('adj-product')?.addEventListener('change', function() {
    var opt = this.options[this.selectedIndex];
    var batchSelect = document.getElementById('adj-batch');
    batchSelect.innerHTML = '<option value="">— None —</option>';
    if (opt && opt.dataset.batch === '1') {
        var pid = this.value;
        if (!pid) return;
        fetch('ajax_get_batches.php?product_id=' + pid)
            .then(function(r){ return r.json(); })
            .then(function(data){
                data.forEach(function(b){
                    var o = document.createElement('option');
                    o.value = b.id;
                    o.textContent = b.batch_number + ' (Exp: ' + b.expiry_date + ', Qty: ' + b.quantity + ')';
                    batchSelect.appendChild(o);
                });
            })
            .catch(function(){});
    }
});
</script>
