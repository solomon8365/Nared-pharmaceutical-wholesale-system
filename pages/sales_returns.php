<?php
requirePermission('sales_returns', 'view');
require_once __DIR__ . '/../includes/stock_helper.php';
$pdo = getPDO();
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    requirePermission('sales_returns', 'create');
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $saleId = (int) ($_POST['sale_id'] ?? 0);
        $returnDate = $_POST['return_date'] ?? date('Y-m-d');
        $reason = trim($_POST['reason'] ?? '');
        $refundAmount = (float) ($_POST['refund_amount'] ?? 0);
        $items = $_POST['items'] ?? [];

        if ($saleId <= 0 || empty($items)) {
            $messages[] = ['type' => 'error', 'text' => 'Sale and at least one item required.'];
        } else {
            $sale = $pdo->prepare('SELECT customer_id, total_amount FROM sales WHERE id = :id');
            $sale->execute(['id' => $saleId]);
            $saleData = $sale->fetch();
            if (!$saleData) {
                $messages[] = ['type' => 'error', 'text' => 'Sale not found.'];
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

                    $stmt = $pdo->prepare('INSERT INTO sales_returns (sale_id, customer_id, return_date, total_amount, reason, refund_amount, status) VALUES (:sid, :cid, :dt, :total, :reason, :refund, \'returned\')');
                    $stmt->execute(['sid' => $saleId, 'cid' => $saleData['customer_id'], 'dt' => $returnDate, 'total' => $total, 'reason' => $reason ?: null, 'refund' => $refundAmount]);
                    $returnId = $pdo->lastInsertId();

                    $ins = $pdo->prepare('INSERT INTO sales_return_items (sales_return_id, product_id, quantity, unit_price, batch_id, reason) VALUES (:rid, :pid, :qty, :up, :bid, :r)');
                    foreach ($validItems as $vi) {
                        $ins->execute(['rid' => $returnId, 'pid' => $vi['product_id'], 'qty' => $vi['quantity'], 'up' => $vi['unit_price'], 'bid' => $vi['batch_id'] > 0 ? $vi['batch_id'] : null, 'r' => $vi['reason'] ?: null]);
                        if ($vi['batch_id'] > 0) {
                            $bchk = $pdo->prepare('SELECT product_id FROM product_batches WHERE id = :id FOR UPDATE');
                            $bchk->execute(['id' => $vi['batch_id']]);
                            $brow = $bchk->fetch();
                            if (!$brow || (int) $brow['product_id'] !== (int) $vi['product_id']) {
                                throw new RuntimeException('Selected batch does not belong to the product.');
                            }
                            $pdo->prepare('UPDATE product_batches SET quantity = quantity + :qty WHERE id = :id')->execute(['qty' => $vi['quantity'], 'id' => $vi['batch_id']]);
                            recalc_product_stock($pdo, $vi['product_id']);
                        } else {
                            // A batch-tracked product must be returned against a batch,
                            // otherwise products.stock_quantity would silently desync.
                            if (has_batches($pdo, $vi['product_id'])) {
                                throw new RuntimeException('This product is batch-tracked — select a batch to return.');
                            }
                            $pdo->prepare('UPDATE products SET stock_quantity = stock_quantity + :qty WHERE id = :id')->execute(['qty' => $vi['quantity'], 'id' => $vi['product_id']]);
                        }
                        log_stock_movement($pdo, $vi['product_id'], $vi['quantity'], 'Sales Return', 'sales_return', $returnId);
                    }
                    $pdo->commit();
                    $messages[] = ['type' => 'success', 'text' => 'Sales return recorded successfully.'];
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $messages[] = ['type' => 'error', 'text' => $e->getMessage()];
                }
            }
        }
    }
}

$sales = $pdo->query("SELECT s.id, s.sale_date, c.name AS customer_name FROM sales s JOIN customers c ON s.customer_id = c.id ORDER BY s.sale_date DESC LIMIT 100")->fetchAll();
$returns = $pdo->query("SELECT sr.*, c.name AS customer_name, s.id AS sale_id FROM sales_returns sr JOIN customers c ON sr.customer_id = c.id JOIN sales s ON sr.sale_id = s.id ORDER BY sr.created_at DESC LIMIT 50")->fetchAll();
?>
<section>
    <h1>Sales Returns</h1>
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>
    <div class="tab-bar">
        <button class="tab-btn active" onclick="switchTab(this,'sr-new')"><i class="fas fa-undo"></i> New Return</button>
        <button class="tab-btn" onclick="switchTab(this,'sr-history')"><i class="fas fa-history"></i> Return History</button>
    </div>
    <div class="tab-content">
        <div id="sr-new" class="tab-panel active">
            <div class="panel">
                <h2>Customer Return</h2>
                <form method="post" class="form-grid" id="sr-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <label>Sale
                        <select name="sale_id" id="sr-sale" required>
                            <option value="">— Select sale —</option>
                            <?php foreach ($sales as $s): ?>
                                <option value="<?= (int) $s['id'] ?>">#<?= (int) $s['id'] ?> — <?= htmlspecialchars($s['customer_name']) ?> (<?= htmlspecialchars($s['sale_date']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Return date<input type="date" name="return_date" value="<?= date('Y-m-d') ?>"></label>
                    <label>Refund amount (<?= currency_sym() ?>)<input type="number" name="refund_amount" step="0.01" value="0"></label>
                    <div style="grid-column:1/-1">
                        <label>Reason<textarea name="reason" rows="2"></textarea></label>
                    </div>
                    <fieldset style="border:1px solid var(--brown-300);padding:12px;border-radius:6px;grid-column:1/-1">
                        <legend style="font-weight:600;color:var(--brown-800)">Returned Items</legend>
                        <div id="sr-items">
                            <div class="order-item-row" style="flex-wrap:wrap">
                                <select name="items[0][product_id]" required style="min-width:140px">
                                    <option value="">— Select product —</option>
                                </select>
                                <input type="number" name="items[0][quantity]" value="1" min="1" required style="width:90px;min-width:70px" placeholder="Qty">
                                <input type="number" name="items[0][unit_price]" step="0.01" value="0" style="width:110px;min-width:90px" placeholder="Price">
                                <select name="items[0][batch_id]" style="max-width:140px;min-width:100px">
                                    <option value="">Batch</option>
                                </select>
                                <input type="text" name="items[0][reason]" placeholder="Reason" style="flex:1;min-width:100px">
                                <button type="button" class="remove-item">Remove</button>
                            </div>
                        </div>
                        <button type="button" id="add-sr-item" style="margin-top:8px">+ Add item</button>
                    </fieldset>
                    <button type="submit" style="grid-column:1/-1">Record Return</button>
                </form>
            </div>
        </div>
        <div id="sr-history" class="tab-panel">
            <div class="panel">
                <h2>Return History</h2>
                <div style="overflow-x:auto">
                <table>
                    <thead><tr><th>ID</th><th>Sale #</th><th>Customer</th><th>Date</th><th>Total</th><th>Refund</th><th>Status</th><th>Created</th></tr></thead>
                    <tbody>
                        <?php if (empty($returns)): ?>
                            <tr><td colspan="8">No returns recorded.</td></tr>
                        <?php else: ?>
                            <?php foreach ($returns as $r): ?>
                                <tr>
                                    <td><?= (int) $r['id'] ?></td>
                                    <td>#<?= (int) $r['sale_id'] ?></td>
                                    <td><?= htmlspecialchars($r['customer_name']) ?></td>
                                    <td><?= htmlspecialchars($r['return_date']) ?></td>
                                    <td><?= fmt((float) $r['total_amount']) ?></td>
                                    <td><?= fmt((float) $r['refund_amount']) ?></td>
                                    <td><span class="status-badge status-<?= $r['status'] === 'returned' ? 'approved' : 'pending' ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                                    <td><?= htmlspecialchars($r['created_at']) ?></td>
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
function loadSrBatches(row) {
    var prodSel = row.querySelector('select[name^="items["][name$="[product_id]"]');
    var batchSel = row.querySelector('select[name^="items["][name$="[batch_id]"]');
    if (!prodSel || !batchSel) return;
    batchSel.innerHTML = '<option value="">Batch</option>';
    var pid = prodSel.value;
    if (!pid) return;
    fetch('ajax_get_batches.php?product_id=' + encodeURIComponent(pid))
        .then(function(r){ return r.json(); })
        .then(function(data){
            data.forEach(function(b){
                var o = document.createElement('option');
                o.value = b.id;
                o.textContent = b.batch_number + ' (Exp: ' + b.expiry_date + ', Qty: ' + b.quantity + ')';
                batchSel.appendChild(o);
            });
        })
        .catch(function(){});
}
function bindSrRow(row) {
    var prodSel = row.querySelector('select[name^="items["][name$="[product_id]"]');
    if (prodSel) {
        prodSel.onchange = function() {
            var opt = this.options[this.selectedIndex];
            var priceInput = row.querySelector('input[name^="items["][name$="[unit_price]"]');
            if (opt && opt.dataset.price !== undefined && priceInput && (priceInput.value === '' || priceInput.value === '0')) {
                priceInput.value = opt.dataset.price;
            }
            loadSrBatches(row);
        };
    }
    var rm = row.querySelector('.remove-item');
    if (rm) { rm.onclick = function(){ this.closest('.order-item-row').remove(); }; }
}
document.getElementById('sr-sale').addEventListener('change', function() {
    var saleId = this.value;
    if (!saleId) return;
    $.getJSON('ajax_get_sale_items.php?sale_id=' + saleId, function(data) {
        document.querySelectorAll('#sr-items .order-item-row').forEach(function(row) {
            var sel = row.querySelector('select[name^="items["][name$="[product_id]"]');
            if (sel) {
                sel.innerHTML = '<option value="">— Select product —</option>';
                data.forEach(function(item) {
                    sel.innerHTML += '<option value="' + item.product_id + '" data-price="' + item.unit_price + '">' + item.product_name + ' (' + item.quantity_sold + ' sold)</option>';
                });
            }
            var batchSel = row.querySelector('select[name^="items["][name$="[batch_id]"]');
            if (batchSel) batchSel.innerHTML = '<option value="">Batch</option>';
        });
    });
});
document.getElementById('add-sr-item').addEventListener('click', function() {
    var container = document.getElementById('sr-items');
    var idx = container.querySelectorAll('.order-item-row').length;
    var row = document.createElement('div');
    row.className = 'order-item-row';
    row.style.flexWrap = 'wrap';
    row.innerHTML = '<select name="items[' + idx + '][product_id]" required style="min-width:140px"><option value="">— Select product —</option></select><input type="number" name="items[' + idx + '][quantity]" value="1" min="1" required style="width:90px;min-width:70px" placeholder="Qty"><input type="number" name="items[' + idx + '][unit_price]" step="0.01" value="0" style="width:110px;min-width:90px" placeholder="Price"><select name="items[' + idx + '][batch_id]" style="max-width:140px;min-width:100px"><option value="">Batch</option></select><input type="text" name="items[' + idx + '][reason]" placeholder="Reason" style="flex:1;min-width:100px"><button type="button" class="remove-item">Remove</button>';
    container.appendChild(row);
    bindSrRow(row);
});
document.querySelectorAll('#sr-items .order-item-row').forEach(bindSrRow);
</script>
