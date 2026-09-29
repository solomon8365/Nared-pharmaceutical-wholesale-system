<?php
requirePermission('purchase_returns', 'view');
require_once __DIR__ . '/../includes/stock_helper.php';
$pdo = getPDO();
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $poId = (int) ($_POST['purchase_order_id'] ?? 0);
        $returnDate = $_POST['return_date'] ?? date('Y-m-d');
        $reason = trim($_POST['reason'] ?? '');
        $items = $_POST['items'] ?? [];

        if ($poId <= 0 || empty($items)) {
            $messages[] = ['type' => 'error', 'text' => 'Purchase order and at least one item required.'];
        } else {
            $po = $pdo->prepare('SELECT supplier_id FROM purchase_orders WHERE id = :id');
            $po->execute(['id' => $poId]);
            $poData = $po->fetch();
            if (!$poData) {
                $messages[] = ['type' => 'error', 'text' => 'Purchase order not found.'];
            } else {
                $pdo->beginTransaction();
                try {
                    $total = 0;
                    $validItems = [];
                    foreach ($items as $item) {
                        $pid = (int) ($item['product_id'] ?? 0);
                        $qty = (int) ($item['quantity'] ?? 0);
                        $batchId = (int) ($item['batch_id'] ?? 0);
                        $unitPrice = (float) ($item['unit_price'] ?? 0);
                        if ($pid <= 0 || $qty <= 0) continue;
                        $total += $unitPrice * $qty;
                        $validItems[] = ['product_id' => $pid, 'quantity' => $qty, 'unit_price' => $unitPrice, 'batch_id' => $batchId, 'reason' => trim($item['reason'] ?? '')];
                    }
                    if (empty($validItems)) throw new RuntimeException('No valid items.');

                    $stmt = $pdo->prepare('INSERT INTO purchase_returns (purchase_order_id, supplier_id, return_date, total_amount, reason, status) VALUES (:po, :supp, :dt, :total, :reason, \'returned\')');
                    $stmt->execute(['po' => $poId, 'supp' => $poData['supplier_id'], 'dt' => $returnDate, 'total' => $total, 'reason' => $reason ?: null]);
                    $returnId = $pdo->lastInsertId();

                    $ins = $pdo->prepare('INSERT INTO purchase_return_items (purchase_return_id, product_id, quantity, unit_price, batch_id, reason) VALUES (:rid, :pid, :qty, :up, :bid, :r)');
                    foreach ($validItems as $vi) {
                        $ins->execute(['rid' => $returnId, 'pid' => $vi['product_id'], 'qty' => $vi['quantity'], 'up' => $vi['unit_price'], 'bid' => $vi['batch_id'] > 0 ? $vi['batch_id'] : null, 'r' => $vi['reason'] ?: null]);
                        // Deduct from batch and log movement
                        if ($vi['batch_id'] > 0) {
                            $pdo->prepare('UPDATE product_batches SET quantity = quantity - :qty WHERE id = :id AND quantity >= :qty2')->execute(['qty' => $vi['quantity'], 'id' => $vi['batch_id'], 'qty2' => $vi['quantity']]);
                            recalc_product_stock($pdo, $vi['product_id']);
                        } else {
                            $pdo->prepare('UPDATE products SET stock_quantity = stock_quantity - :qty WHERE id = :id AND stock_quantity >= :qty2')->execute(['qty' => $vi['quantity'], 'id' => $vi['product_id'], 'qty2' => $vi['quantity']]);
                        }
                        log_stock_movement($pdo, $vi['product_id'], -$vi['quantity'], 'Purchase Return', 'purchase_return', $returnId);
                    }
                    $pdo->commit();
                    $messages[] = ['type' => 'success', 'text' => 'Purchase return recorded successfully.'];
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $messages[] = ['type' => 'error', 'text' => $e->getMessage()];
                }
            }
        }
    }
}

$purchaseOrders = $pdo->query("SELECT po.id, po.order_date, s.name AS supplier_name FROM purchase_orders po JOIN suppliers s ON po.supplier_id = s.id WHERE po.status = 'received' ORDER BY po.order_date DESC")->fetchAll();
$returns = $pdo->query("SELECT pr.*, s.name AS supplier_name, po.id AS po_id FROM purchase_returns pr JOIN suppliers s ON pr.supplier_id = s.id JOIN purchase_orders po ON pr.purchase_order_id = po.id ORDER BY pr.created_at DESC LIMIT 50")->fetchAll();
?>
<section>
    <h1>Purchase Returns</h1>
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>
    <div class="tab-bar">
        <button class="tab-btn active" onclick="switchTab(this,'pr-new')"><i class="fas fa-undo"></i> New Return</button>
        <button class="tab-btn" onclick="switchTab(this,'pr-history')"><i class="fas fa-history"></i> Return History</button>
    </div>
    <div class="tab-content">
        <div id="pr-new" class="tab-panel active">
            <div class="panel">
                <h2>Return Goods to Supplier</h2>
                <form method="post" class="form-grid" id="pr-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <label>Purchase Order (received)
                        <select name="purchase_order_id" id="pr-po" required>
                            <option value="">— Select PO —</option>
                            <?php foreach ($purchaseOrders as $po): ?>
                                <option value="<?= (int) $po['id'] ?>">#<?= (int) $po['id'] ?> — <?= htmlspecialchars($po['supplier_name']) ?> (<?= htmlspecialchars($po['order_date']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Return date<input type="date" name="return_date" value="<?= date('Y-m-d') ?>"></label>
                    <div style="grid-column:1/-1">
                        <label>Reason (optional)<textarea name="reason" rows="2"></textarea></label>
                    </div>
                    <fieldset style="border:1px solid var(--brown-300);padding:12px;border-radius:6px;grid-column:1/-1">
                        <legend style="font-weight:600;color:var(--brown-800)">Items to Return</legend>
                        <div id="pr-items">
                            <div class="order-item-row">
                                <select name="items[0][product_id]" required>
                                    <option value="">— Select product —</option>
                                </select>
                                <input type="number" name="items[0][quantity]" value="1" min="1" required style="width:80px" placeholder="Qty">
                                <input type="number" name="items[0][unit_price]" step="0.01" value="0" style="width:100px" placeholder="Price">
                                <select name="items[0][batch_id]" style="max-width:140px">
                                    <option value="">Batch</option>
                                </select>
                                <input type="text" name="items[0][reason]" placeholder="Reason" style="flex:1">
                                <button type="button" class="remove-item">Remove</button>
                            </div>
                        </div>
                        <button type="button" id="add-pr-item" style="margin-top:8px">+ Add item</button>
                    </fieldset>
                    <button type="submit" style="grid-column:1/-1">Record Return</button>
                </form>
            </div>
        </div>
        <div id="pr-history" class="tab-panel">
            <div class="panel">
                <h2>Return History</h2>
                <table>
                    <thead><tr><th>ID</th><th>PO #</th><th>Supplier</th><th>Date</th><th>Total</th><th>Status</th><th>Reason</th><th>Created</th></tr></thead>
                    <tbody>
                        <?php if (empty($returns)): ?>
                            <tr><td colspan="8">No returns recorded.</td></tr>
                        <?php else: ?>
                            <?php foreach ($returns as $r): ?>
                                <tr>
                                    <td><?= (int) $r['id'] ?></td>
                                    <td>#<?= (int) $r['po_id'] ?></td>
                                    <td><?= htmlspecialchars($r['supplier_name']) ?></td>
                                    <td><?= htmlspecialchars($r['return_date']) ?></td>
                                    <td><?= fmt((float) $r['total_amount']) ?></td>
                                    <td><span class="status-badge status-<?= $r['status'] === 'returned' ? 'approved' : 'pending' ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                                    <td><?= htmlspecialchars((string) ($r['reason'] ?? '-')) ?></td>
                                    <td><?= htmlspecialchars($r['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
<script>
document.getElementById('pr-po').addEventListener('change', function() {
    var poId = this.value;
    if (!poId) return;
    $.getJSON('ajax_get_po_items.php?po_id=' + poId, function(data) {
        var rows = document.querySelectorAll('#pr-items .order-item-row');
        rows.forEach(function(row, i) {
            var sel = row.querySelector('select[name^="items["]');
            if (sel) {
                sel.innerHTML = '<option value="">— Select product —</option>';
                data.forEach(function(item) {
                    sel.innerHTML += '<option value="' + item.product_id + '" data-price="' + item.unit_price + '">' + item.product_name + ' (' + item.quantity_received + ' received)</option>';
                });
            }
        });
    });
});
document.getElementById('add-pr-item').addEventListener('click', function() {
    var container = document.getElementById('pr-items');
    var idx = container.querySelectorAll('.order-item-row').length;
    var row = document.createElement('div');
    row.className = 'order-item-row';
    row.innerHTML = '<select name="items[' + idx + '][product_id]" required><option value="">— Select product —</option></select><input type="number" name="items[' + idx + '][quantity]" value="1" min="1" required style="width:80px" placeholder="Qty"><input type="number" name="items[' + idx + '][unit_price]" step="0.01" value="0" style="width:100px" placeholder="Price"><select name="items[' + idx + '][batch_id]" style="max-width:140px"><option value="">Batch</option></select><input type="text" name="items[' + idx + '][reason]" placeholder="Reason" style="flex:1"><button type="button" class="remove-item">Remove</button>';
    container.appendChild(row);
    attachRemoveEvents();
});
function attachRemoveEvents() {
    document.querySelectorAll('#pr-items .remove-item').forEach(function(btn) {
        btn.onclick = function() { this.closest('.order-item-row').remove(); };
    });
}
attachRemoveEvents();
</script>
