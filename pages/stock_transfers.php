<?php
requirePermission('stock_transfers', 'view');
require_once __DIR__ . '/../includes/stock_helper.php';
$pdo = getPDO();
$messages = [];

$warehouses = $pdo->query('SELECT id, name FROM warehouses WHERE warehouse_status = \'active\' ORDER BY name')->fetchAll();
$products = $pdo->query('SELECT id, name, stock_quantity, purchase_price FROM products ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['_action'] ?? '';

    if ($action === 'create') {
        requirePermission('stock_transfers', 'create');
        $fromId = (int) ($_POST['from_warehouse_id'] ?? 0);
        $toId = (int) ($_POST['to_warehouse_id'] ?? 0);
        $tDate = $_POST['transfer_date'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');
        $items = $_POST['items'] ?? [];

        if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
            $messages[] = ['type' => 'error', 'text' => 'Select different source and destination warehouses.'];
        } elseif (empty($items)) {
            $messages[] = ['type' => 'error', 'text' => 'At least one item is required.'];
        } else {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('INSERT INTO stock_transfers (from_warehouse_id, to_warehouse_id, transfer_date, status, notes, created_by) VALUES (:fw, :tw, :dt, \'draft\', :notes, :uid)');
                $stmt->execute(['fw' => $fromId, 'tw' => $toId, 'dt' => $tDate, 'notes' => $notes, 'uid' => (int)($_SESSION['user_id'] ?? 0)]);
                $tId = (int) $pdo->lastInsertId();

                $insItem = $pdo->prepare('INSERT INTO stock_transfer_items (stock_transfer_id, product_id, batch_id, quantity, unit_cost) VALUES (:tid, :pid, :bid, :qty, :uc)');
                foreach ($items as $item) {
                    $pid = (int) ($item['product_id'] ?? 0);
                    $qty = (int) ($item['quantity'] ?? 0);
                    $bid = !empty($item['batch_id']) ? (int)$item['batch_id'] : null;
                    $uc = (float) ($item['unit_cost'] ?? 0);
                    if ($pid <= 0 || $qty <= 0) continue;
                    $insItem->execute(['tid' => $tId, 'pid' => $pid, 'bid' => $bid, 'qty' => $qty, 'uc' => $uc]);
                }

                $pdo->commit();
                $messages[] = ['type' => 'success', 'text' => "Transfer #{$tId} created as draft."];
            } catch (Exception $e) {
                $pdo->rollBack();
                $messages[] = ['type' => 'error', 'text' => 'Failed: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'complete') {
        requirePermission('stock_transfers', 'edit');
        $tId = (int) ($_POST['transfer_id'] ?? 0);
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("SELECT * FROM stock_transfers WHERE id = :id AND status = 'draft' FOR UPDATE");
            $st->execute(['id' => $tId]);
            $transfer = $st->fetch();
            if (!$transfer) throw new RuntimeException('Transfer not found or not in draft status.');

            $items = $pdo->prepare('SELECT * FROM stock_transfer_items WHERE stock_transfer_id = :tid');
            $items->execute(['tid' => $tId]);

            foreach ($items->fetchAll() as $item) {
                $pid = (int)$item['product_id'];
                $qty = (int)$item['quantity'];
                $bid = $item['batch_id'] ? (int)$item['batch_id'] : null;

                // Resolve the SOURCE batch. Items saved without an explicit batch
                // auto-select the oldest active batch physically located in the
                // FROM warehouse, so pooled/unallocated stock is never touched.
                if (!$bid) {
                    $abst = $pdo->prepare("SELECT id FROM product_batches WHERE product_id = :pid AND warehouse_id = :wid AND status = 'active' AND quantity > 0 ORDER BY expiry_date ASC, id ASC LIMIT 1");
                    $abst->execute(['pid' => $pid, 'wid' => (int)$transfer['from_warehouse_id']]);
                    $bid = (int) ($abst->fetchColumn() ?: 0);
                    if (!$bid) throw new RuntimeException("No batch stock allocated to the source warehouse for product #{$pid}.");
                }

                $bst = $pdo->prepare('SELECT * FROM product_batches WHERE id = :id AND product_id = :pid FOR UPDATE');
                $bst->execute(['id' => $bid, 'pid' => $pid]);
                $batch = $bst->fetch();
                if (!$batch) throw new RuntimeException("Batch #{$bid} not found for product #{$pid}.");
                if ((int)$batch['warehouse_id'] !== (int)$transfer['from_warehouse_id']) {
                    throw new RuntimeException('Batch #'.$bid.' is not allocated to the source warehouse.');
                }
                if ((int)$batch['quantity'] < $qty) throw new RuntimeException("Insufficient stock in batch #{$bid}.");

                // 1) Remove units from the source warehouse batch.
                $pdo->prepare('UPDATE product_batches SET quantity = quantity - :qty WHERE id = :id')
                    ->execute(['qty' => $qty, 'id' => $bid]);
                log_stock_movement($pdo, $pid, -$qty, 'Stock transfer out', 'stock_transfer', $tId);

                // 2) Add them to the DESTINATION warehouse: reuse its batch with
                //    the same batch number, otherwise create one copied from the
                //    source batch (never touch other warehouses or global pool).
                $dbst = $pdo->prepare('SELECT id, quantity FROM product_batches WHERE product_id = :pid AND warehouse_id = :wid AND batch_number = :bn FOR UPDATE');
                $dbst->execute(['pid' => $pid, 'wid' => (int)$transfer['to_warehouse_id'], 'bn' => $batch['batch_number']]);
                $destBatch = $dbst->fetch();
                if ($destBatch) {
                    $pdo->prepare('UPDATE product_batches SET quantity = quantity + :qty WHERE id = :id')
                        ->execute(['qty' => $qty, 'id' => (int)$destBatch['id']]);
                } else {
                    $pdo->prepare('INSERT INTO product_batches (product_id, warehouse_id, batch_number, expiry_date, quantity, status, workflow_status, purchase_price, created_at)
                                   VALUES (:pid, :wid, :bn, :exp, :qty, \'active\', :wfs, :pp, NOW())')
                        ->execute([
                            'pid' => $pid,
                            'wid' => (int)$transfer['to_warehouse_id'],
                            'bn'  => $batch['batch_number'],
                            'exp' => $batch['expiry_date'],
                            'qty' => $qty,
                            'wfs' => $batch['workflow_status'],
                            'pp'  => $batch['purchase_price'],
                        ]);
                }
                log_stock_movement($pdo, $pid, $qty, 'Stock transfer in', 'stock_transfer', $tId);
                recalc_product_stock($pdo, $pid);
            }

            $pdo->prepare("UPDATE stock_transfers SET status = 'completed', completed_at = NOW() WHERE id = :id")
                ->execute(['id' => $tId]);
            $pdo->commit();
            $messages[] = ['type' => 'success', 'text' => "Transfer #{$tId} completed."];
        } catch (Exception $e) {
            $pdo->rollBack();
            $messages[] = ['type' => 'error', 'text' => 'Failed: ' . $e->getMessage()];
        }
    } elseif ($action === 'cancel') {
        requirePermission('stock_transfers', 'delete');
        $tId = (int) ($_POST['transfer_id'] ?? 0);
        $pdo->prepare("UPDATE stock_transfers SET status = 'cancelled' WHERE id = :id AND status = 'draft'")
            ->execute(['id' => $tId]);
        $messages[] = ['type' => 'success', 'text' => "Transfer #{$tId} cancelled."];
    }
}

$filterStatus = $_GET['status'] ?? '';
$where = []; $params = [];
if ($filterStatus !== '') { $where[] = 't.status = :s'; $params['s'] = $filterStatus; }
$sql = 'SELECT t.*, fw.name AS from_name, tw.name AS to_name FROM stock_transfers t LEFT JOIN warehouses fw ON t.from_warehouse_id = fw.id LEFT JOIN warehouses tw ON t.to_warehouse_id = tw.id';
if (!empty($where)) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY t.created_at DESC';
$transfers = $pdo->prepare($sql);
$transfers->execute($params);
$transfers = $transfers->fetchAll();
?>
<section>
    <h1>Stock Transfers</h1>
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <div class="tab-bar">
        <?php if (hasPermission('stock_transfers', 'create')): ?>
        <button class="tab-btn active" onclick="switchTab(this,'tr-create')"><i class="fas fa-plus"></i> New Transfer</button>
        <?php endif; ?>
        <button class="tab-btn <?= hasPermission('stock_transfers', 'create') ? '' : 'active' ?>" onclick="switchTab(this,'tr-list')"><i class="fas fa-list"></i> Transfer History</button>
    </div>

    <div class="tab-content">
        <?php if (hasPermission('stock_transfers', 'create')): ?>
        <div id="tr-create" class="tab-panel active">
            <div class="panel">
                <h2>Create Stock Transfer</h2>
                <?php if (count($warehouses) < 2): ?>
                    <p class="hint">You need at least 2 active warehouses to create transfers. <a href="?page=settings">Manage warehouses in Settings</a></p>
                <?php else: ?>
                <form method="post" class="form-grid" style="max-width:600px" id="tr-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="create">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                        <label>From Warehouse
                            <select name="from_warehouse_id" required>
                                <option value="">Select</option>
                                <?php foreach ($warehouses as $w): ?>
                                    <option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>To Warehouse
                            <select name="to_warehouse_id" required>
                                <option value="">Select</option>
                                <?php foreach ($warehouses as $w): ?>
                                    <option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <label>Transfer Date<input type="date" name="transfer_date" value="<?= date('Y-m-d') ?>"></label>
                    <label>Notes<textarea name="notes" rows="2"></textarea></label>
                    <div id="tr-items">
                        <div class="tr-item-row" style="display:flex;gap:6px;align-items:end;margin-bottom:6px">
                            <select name="items[0][product_id]" required style="flex:2;min-width:120px">
                                <option value="">Select product</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?= $p['id'] ?>" data-price="<?= $p['purchase_price'] ?>"><?= htmlspecialchars($p['name']) ?> (Stock: <?= $p['stock_quantity'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <select name="items[0][batch_id]" style="flex:1;min-width:90px" class="tr-batch">
                                <option value="">— No batch —</option>
                            </select>
                            <input type="number" name="items[0][quantity]" value="1" min="1" required style="flex:0.7;min-width:60px" placeholder="Qty">
                            <input type="number" step="0.01" name="items[0][unit_cost]" value="0" style="flex:0.7;min-width:70px" placeholder="Cost">
                            <button type="button" class="remove-item" style="padding:6px 10px;font-size:0.78rem">Remove</button>
                        </div>
                    </div>
                    <button type="button" id="add-tr-item" style="padding:6px 14px;font-size:0.82rem;background:var(--green-600);color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600"><i class="fas fa-plus"></i> Add Item</button>
                    <button type="submit" style="margin-top:6px">Save as Draft</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div id="tr-list" class="tab-panel">
            <div class="panel">
                <h2>Transfer History</h2>
                <form method="get" style="margin-bottom:12px;display:flex;gap:8px">
                    <input type="hidden" name="page" value="stock_transfers">
                    <select name="status" style="min-width:120px;margin:0">
                        <option value="">All Statuses</option>
                        <?php foreach (['draft' => 'Draft','completed' => 'Completed','cancelled' => 'Cancelled'] as $v => $l): ?>
                            <option value="<?= $v ?>"<?= $filterStatus === $v ? ' selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" style="margin:0">Filter</button>
                    <?php if ($filterStatus !== ''): ?><a href="?page=stock_transfers" style="display:inline-flex;align-items:center;padding:0 12px;color:var(--brown-800);font-weight:600">Clear</a><?php endif; ?>
                </form>
                <div style="overflow-x:auto">
                <table>
                    <thead><tr><th>#</th><th>Date</th><th>From</th><th>To</th><th>Status</th><th>Notes</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php if (empty($transfers)): ?>
                            <tr><td colspan="7">No transfers found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($transfers as $t):
                                $s = $t['status'];
                                $sc = $s === 'completed' ? '#059669' : ($s === 'cancelled' ? '#dc2626' : '#f59e0b');
                            ?>
                                <tr>
                                    <td><?= (int)$t['id'] ?></td>
                                    <td><?= htmlspecialchars($t['transfer_date']) ?></td>
                                    <td><?= htmlspecialchars($t['from_name'] ?? '?') ?></td>
                                    <td><?= htmlspecialchars($t['to_name'] ?? '?') ?></td>
                                    <td><span style="display:inline-block;padding:2px 8px;border-radius:4px;font-weight:700;font-size:0.75rem;background:<?= $sc ?>20;color:<?= $sc ?>"><?= ucfirst($s) ?></span></td>
                                    <td style="font-size:0.82rem"><?= htmlspecialchars($t['notes'] ?? '—') ?></td>
                                    <td style="white-space:nowrap">
                                        <?php if ($s === 'draft'): ?>
                                            <?php if (hasPermission('stock_transfers', 'edit')): ?>
                                            <form method="post" style="display:inline" onsubmit="return confirm('Complete this transfer? Stock will be moved.')">
                                                <?= csrf_field() ?><input type="hidden" name="_action" value="complete"><input type="hidden" name="transfer_id" value="<?= (int)$t['id'] ?>">
                                                <button type="submit" style="padding:3px 8px;font-size:0.78rem;background:#059669;color:#fff;border:none;border-radius:4px;cursor:pointer">Complete</button>
                                            </form>
                                            <?php endif; ?>
                                            <?php if (hasPermission('stock_transfers', 'delete')): ?>
                                            <form method="post" style="display:inline" onsubmit="return confirm('Cancel this transfer?')">
                                                <?= csrf_field() ?><input type="hidden" name="_action" value="cancel"><input type="hidden" name="transfer_id" value="<?= (int)$t['id'] ?>">
                                                <button type="submit" style="padding:3px 8px;font-size:0.78rem;background:#dc2626;color:#fff;border:none;border-radius:4px;cursor:pointer">Cancel</button>
                                            </form>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="font-size:0.78rem;color:var(--neutral-500)"><?= $s === 'completed' && $t['completed_at'] ? 'Done ' . htmlspecialchars(date('Y-m-d', strtotime($t['completed_at']))) : '—' ?></span>
                                        <?php endif; ?>
                                    </td>
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
document.getElementById('add-tr-item')?.addEventListener('click', function() {
    var container = document.getElementById('tr-items');
    var idx = container.children.length;
    var row = document.createElement('div');
    row.className = 'tr-item-row';
    row.style.cssText = 'display:flex;gap:6px;align-items:end;margin-bottom:6px';
    row.innerHTML = '<select name="items[' + idx + '][product_id]" required style="flex:2;min-width:120px"><option value="">Select product</option><?php foreach ($products as $p): ?><option value="<?= $p['id'] ?>" data-price="<?= $p['purchase_price'] ?>"><?= htmlspecialchars(addslashes($p['name'])) ?> (Stock: <?= $p['stock_quantity'] ?>)</option><?php endforeach; ?></select><select name="items[' + idx + '][batch_id]" style="flex:1;min-width:90px" class="tr-batch"><option value="">— No batch —</option></select><input type="number" name="items[' + idx + '][quantity]" value="1" min="1" required style="flex:0.7;min-width:60px" placeholder="Qty"><input type="number" step="0.01" name="items[' + idx + '][unit_cost]" value="0" style="flex:0.7;min-width:70px" placeholder="Cost"><button type="button" class="remove-item" style="padding:6px 10px;font-size:0.78rem">Remove</button>';
    container.appendChild(row);
    row.querySelector('.remove-item').addEventListener('click', function() { row.remove(); });
});
document.querySelectorAll('#tr-items .remove-item').forEach(function(btn) {
    btn.addEventListener('click', function() { this.closest('.tr-item-row').remove(); });
});
// Load batches on product / source-warehouse change
function trRefreshBatches(row) {
    var pidSelect = row.querySelector('select[name$="[product_id]"]');
    var batchSelect = row.querySelector('.tr-batch');
    if (!pidSelect || !batchSelect) return;
    var pid = pidSelect.value;
    var fw = document.getElementById('tr-form').querySelector('[name="from_warehouse_id"]');
    batchSelect.innerHTML = '<option value="">— No batch —</option>';
    if (!pid) return;
    fetch('ajax_get_batches.php?product_id=' + pid + '&warehouse_id=' + (fw ? fw.value : ''))
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
document.getElementById('tr-form')?.addEventListener('change', function(e) {
    if (e.target.tagName === 'SELECT' && e.target.name.includes('product_id')) {
        trRefreshBatches(e.target.closest('.tr-item-row'));
    }
    if (e.target.tagName === 'SELECT' && e.target.name === 'from_warehouse_id') {
        document.querySelectorAll('#tr-items .tr-item-row').forEach(trRefreshBatches);
    }
});
</script>
