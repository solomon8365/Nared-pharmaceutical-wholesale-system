<?php
requirePoCapability('receive');
require_once __DIR__ . '/../includes/stock_helper.php';
require_once __DIR__ . '/../includes/workflow_engine.php';
require_once __DIR__ . '/../includes/auto_migrate.php';
require_once __DIR__ . '/../services/init.php';
$pdo = getPDO();
ensureInventoryWorkflowMigration($pdo);
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['_action'] ?? '';

    if ($action === 'receive') {
        $poId = (int) ($_POST['po_id'] ?? 0);
        $receivedQty = $_POST['received_qty'] ?? [];
        $batchNos = $_POST['batch_no'] ?? [];
        $expiryDates = $_POST['expiry_date'] ?? [];
        $warehouseIds = $_POST['warehouse_id'] ?? [];

        if ($poId <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Invalid PO.'];
        } else {
            WorkflowEngine::ensureStatusHistoryTable($pdo);
            $pdo->beginTransaction();
            try {
                $poSt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = :id AND status = 'payment_approved' FOR UPDATE");
                $poSt->execute(['id' => $poId]);
                $po = $poSt->fetch();
                if (!$po) throw new RuntimeException('PO not found or not approved.');

                $itemsSt = $pdo->prepare('SELECT poi.*, p.name AS pname FROM purchase_order_items poi JOIN products p ON poi.product_id = p.id WHERE poi.purchase_order_id = :poid');
                $itemsSt->execute(['poid' => $poId]);
                $items = $itemsSt->fetchAll();

                $anyReceived = false;
                $uid = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
                foreach ($items as $it) {
                    $itemId = $it['id'];
                    $qty = (int) ($receivedQty[$itemId] ?? 0);
                    if ($qty <= 0) continue;

                    $prevRec = (int) $it['received_qty'];
                    $newRec = $prevRec + $qty;
                    if ($newRec > (int) $it['quantity']) {
                        throw new RuntimeException("Receiving {$qty} of {$it['pname']} exceeds ordered quantity.");
                    }

                    $pdo->prepare("UPDATE purchase_order_items SET received_qty = :rq WHERE id = :id")
                        ->execute(['rq' => $newRec, 'id' => $itemId]);

                    $pid = (int) $it['product_id'];
                    $pp = (float) $it['unit_price'];
                    $batchNo = trim($batchNos[$itemId] ?? '');
                    $expiry = trim($expiryDates[$itemId] ?? '');
                    $whId = !empty($warehouseIds[$itemId]) ? (int) $warehouseIds[$itemId] : null;

                    // Every received quantity MUST land in a batch. If no batch
                    // number was entered, generate one so stock stays batch-tracked.
                    if ($batchNo === '') {
                        $batchNo = 'AUTO-' . $poId . '-' . $pid . '-' . date('YmdHis');
                    }

                    $pdo->prepare("INSERT INTO product_batches (product_id, batch_number, expiry_date, quantity, purchase_price, warehouse_id, workflow_status, workflow_assigned_to, workflow_updated_at) VALUES (:pid, :bn, :exp, :qty, :pp, :wh, :wfs, :wfu, NOW())")
                        ->execute(['pid' => $pid, 'bn' => $batchNo, 'exp' => $expiry !== '' ? $expiry : null, 'qty' => $qty, 'pp' => $pp, 'wh' => $whId, 'wfs' => StatusCatalog::INV_AWAITING_BATCH_VERIFICATION, 'wfu' => ($uid !== null && $uid > 0) ? $uid : null]);
                    $batchId = (int) $pdo->lastInsertId();
                    WorkflowEngine::logStatusChange($pdo, 'inventory', $batchId, null, StatusCatalog::INV_AWAITING_BATCH_VERIFICATION, ($uid !== null && $uid > 0) ? $uid : null, "Batch received on PO #{$poId}");

                    // Available stock = sum of active batches; recompute instead
                    // of a blind increment so stock can never drift from batches.
                    recalc_product_stock($pdo, $pid);
                    log_stock_movement($pdo, $pid, $qty, "PO #{$poId} goods received", 'purchase_order', $poId);
                    $anyReceived = true;
                }

                $totalRec = $pdo->prepare("SELECT COALESCE(SUM(received_qty),0) = COALESCE(SUM(quantity),0) AS fully_received FROM purchase_order_items WHERE purchase_order_id = :poid");
                $totalRec->execute(['poid' => $poId]);
                $fullyReceived = (bool) $totalRec->fetchColumn();

                if ($fullyReceived) {
                    $pdo->prepare("UPDATE purchase_orders SET status = 'received', received_at = NOW() WHERE id = :id")
                        ->execute(['id' => $poId]);
                    WorkflowEngine::logStatusChange($pdo, 'purchase', $poId, $po['status'], 'received', ($uid !== null && $uid > 0) ? $uid : null, 'Goods fully received');
                }

                // Auto-fulfill OOS queue items for received products
                foreach ($items as $it) {
                    $qty = (int) ($receivedQty[$it['id']] ?? 0);
                    if ($qty <= 0) continue;
                    $pid = (int) $it['product_id'];
                    // Find pending OOS queue items for this product
                    $oosStmt = $pdo->prepare("SELECT id, customer_order_id, quantity_needed, quantity_fulfilled FROM out_of_stock_queue WHERE product_id = :pid AND status IN ('pending','purchase_ordered') ORDER BY created_at ASC");
                    $oosStmt->execute(['pid' => $pid]);
                    $oosQueue = $oosStmt->fetchAll();
                    foreach ($oosQueue as $oosItem) {
                        $remaining = (int) $oosItem['quantity_needed'] - (int) $oosItem['quantity_fulfilled'];
                        if ($remaining <= 0) continue;
                        $fulfillQty = min($qty, $remaining);
                        $newFulfilled = (int) $oosItem['quantity_fulfilled'] + $fulfillQty;
                        $newStatus = $newFulfilled >= (int) $oosItem['quantity_needed'] ? 'fulfilled' : 'receiving';
                        $pdo->prepare("UPDATE out_of_stock_queue SET quantity_fulfilled = :fq, status = :st, fulfilled_at = IF(:st2 = 'fulfilled', NOW(), fulfilled_at) WHERE id = :id")->execute([
                            'fq' => $newFulfilled, 'st' => $newStatus, 'st2' => $newStatus, 'id' => $oosItem['id']
                        ]);
                        $qty -= $fulfillQty;
                        if ($qty <= 0) break;
                    }
                }

                $pdo->commit();
                redirect_with_flash('?page=goods_receiving', "PO #{$poId} — goods received successfully.");
            } catch (Exception $e) {
                $pdo->rollBack();
                $messages[] = ['type' => 'error', 'text' => $e->getMessage()];
            }
        }
    }
}

$warehouses = $pdo->query('SELECT id, name FROM warehouses WHERE warehouse_status = "active" ORDER BY name')->fetchAll();
$pendingPos = $pdo->query("
    SELECT po.*, s.name AS supplier_name, u.full_name AS created_by_name
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN users u ON po.created_by = u.id
    WHERE po.status = 'payment_approved'
    ORDER BY po.created_at ASC
")->fetchAll();

$recentReceives = $pdo->query("
    SELECT po.*, s.name AS supplier_name
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    WHERE po.status = 'received'
    ORDER BY po.received_at DESC
    LIMIT 20
")->fetchAll();
?>
<section>
    <h1>Goods Receiving</h1>
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <div class="tab-bar">
        <button class="tab-btn active" onclick="switchTab(this,'gr-pending')"><i class="fas fa-clock"></i> Ready to Receive (<?= count($pendingPos) ?>)</button>
        <button class="tab-btn" onclick="switchTab(this,'gr-history')"><i class="fas fa-history"></i> Receiving History</button>
    </div>

    <div class="tab-content">
        <div id="gr-pending" class="tab-panel active">
            <?php if (empty($pendingPos)): ?>
                <div class="panel"><p class="hint">No purchase orders ready for receiving.</p></div>
            <?php endif; ?>
            <?php foreach ($pendingPos as $po): $poid = (int) $po['id']; ?>
                <div class="panel" style="margin-bottom:12px">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                        <div>
                            <strong style="font-size:1.05rem">PO #<?= $poid ?></strong>
                            <span style="color:var(--neutral-500);font-size:0.85rem;margin-left:10px"><?= htmlspecialchars($po['supplier_name']) ?></span>
                            <span style="color:var(--neutral-500);font-size:0.82rem;margin-left:10px"><?= htmlspecialchars($po['order_date']) ?></span>
                        </div>
                        <span style="font-size:0.85rem;color:var(--neutral-600)">Total: <strong><?= fmt((float) $po['total_amount']) ?></strong></span>
                    </div>
                    <?php
                    $itemsSt = $pdo->prepare('SELECT poi.*, p.name AS pname, p.barcode FROM purchase_order_items poi JOIN products p ON poi.product_id = p.id WHERE poi.purchase_order_id = :poid');
                    $itemsSt->execute(['poid' => $poid]);
                    $poItems = $itemsSt->fetchAll();
                    $allRec = true;
                    foreach ($poItems as $it) { if ((int)$it['received_qty'] < (int)$it['quantity']) { $allRec = false; break; } }
                    ?>
                    <form method="post" style="margin-top:10px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="_action" value="receive">
                        <input type="hidden" name="po_id" value="<?= $poid ?>">
                        <table style="font-size:0.82rem">
                            <thead><tr><th>Product</th><th>Barcode</th><th>Ordered</th><th>Previously Received</th><th>Receive Qty</th><th>Batch No.</th><th>Expiry</th><th>Warehouse</th></tr></thead>
                            <tbody>
                                <?php foreach ($poItems as $it): $iid = $it['id']; $ord = (int)$it['quantity']; $rec = (int)$it['received_qty']; $remaining = $ord - $rec; ?>
                                    <tr>
                                        <td><?= htmlspecialchars($it['pname']) ?></td>
                                        <td style="font-size:0.75rem;color:var(--neutral-500)"><?= htmlspecialchars($it['barcode'] ?? '—') ?></td>
                                        <td><?= $ord ?></td>
                                        <td><?= $rec ?></td>
                                        <td><input type="number" name="received_qty[<?= $iid ?>]" value="<?= $remaining > 0 ? $remaining : 0 ?>" min="0" max="<?= $remaining ?>" style="width:60px;padding:3px 4px;font-size:0.82rem"></td>
                                        <td><input type="text" name="batch_no[<?= $iid ?>]" value="" placeholder="Auto" style="width:100px;padding:3px 4px;font-size:0.82rem"></td>
                                        <td><input type="date" name="expiry_date[<?= $iid ?>]" value="" style="width:110px;padding:3px 4px;font-size:0.82rem"></td>
                                        <td><select name="warehouse_id[<?= $iid ?>]" style="padding:3px 4px;font-size:0.82rem">
                                            <option value="">Default</option>
                                            <?php foreach ($warehouses as $wh): ?>
                                                <option value="<?= $wh['id'] ?>"><?= htmlspecialchars($wh['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div style="margin-top:8px">
                            <button type="submit" style="padding:6px 20px;background:#059669;color:#fff;border:none;border-radius:999px;cursor:pointer;font-weight:600"><i class="fas fa-check"></i> Receive Goods</button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>

        <div id="gr-history" class="tab-panel">
            <?php if (empty($recentReceives)): ?>
                <div class="panel"><p class="hint">No receiving history yet.</p></div>
            <?php endif; ?>
            <div class="panel">
                <table>
                    <thead><tr><th></th><th>PO #</th><th>Supplier</th><th>Date</th><th>Total</th><th>Received At</th></tr></thead>
                    <tbody>
                        <?php foreach ($recentReceives as $po): ?>
                            <?php
                            $poid = (int) $po['id'];
                            $itemsSt = $pdo->prepare('SELECT poi.*, p.name AS pname, p.barcode FROM purchase_order_items poi JOIN products p ON poi.product_id = p.id WHERE poi.purchase_order_id = :poid');
                            $itemsSt->execute(['poid' => $poid]);
                            $poItems = $itemsSt->fetchAll();
                            ?>
                            <tr id="gr-row-<?= $poid ?>" style="cursor:pointer" onclick="togglePoDetail(<?= $poid ?>)">
                                <td><button type="button" onclick="event.stopPropagation();togglePoDetail(<?= $poid ?>)" style="padding:2px 8px;font-size:0.78rem;background:#2563eb;color:#fff;border:none;border-radius:4px;cursor:pointer"><i class="fas fa-eye"></i> View</button></td>
                                <td><strong>#<?= $poid ?></strong></td>
                                <td><?= htmlspecialchars($po['supplier_name']) ?></td>
                                <td><?= htmlspecialchars($po['order_date']) ?></td>
                                <td><?= fmt((float) $po['total_amount']) ?></td>
                                <td><?= htmlspecialchars($po['received_at'] ? date('Y-m-d H:i', strtotime($po['received_at'])) : '—') ?></td>
                            </tr>
                            <tr id="gr-detail-<?= $poid ?>" class="gr-detail-row" style="display:none">
                                <td colspan="6" style="background:#f8fafc">
                                    <div style="padding:10px">
                                        <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:8px">
                                            <strong>PO #<?= $poid ?></strong>
                                            <span style="color:var(--neutral-600);font-size:0.85rem">Supplier: <?= htmlspecialchars($po['supplier_name']) ?> · Order Date: <?= htmlspecialchars($po['order_date']) ?> · Received: <?= htmlspecialchars($po['received_at'] ? date('Y-m-d H:i', strtotime($po['received_at'])) : '—') ?></span>
                                        </div>
                                        <?php if (empty($poItems)): ?>
                                            <p class="hint">No items on this PO.</p>
                                        <?php else: ?>
                                        <table style="font-size:0.82rem;margin:0">
                                            <thead><tr><th>Product</th><th>Barcode</th><th>Ordered</th><th>Received</th><th>Remaining</th><th>Unit Price</th><th>Subtotal</th></tr></thead>
                                            <tbody>
                                                <?php foreach ($poItems as $it): $ord = (int)$it['quantity']; $rec = (int)$it['received_qty']; $rem = $ord - $rec; ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($it['pname']) ?></td>
                                                        <td style="font-size:0.75rem;color:var(--neutral-500)"><?= htmlspecialchars($it['barcode'] ?? '—') ?></td>
                                                        <td><?= $ord ?></td>
                                                        <td><span style="font-weight:600;color:#059669"><?= $rec ?></span></td>
                                                        <td><?= $rem > 0 ? $rem : '<span style="color:#059669">Complete</span>' ?></td>
                                                        <td><?= fmt((float) $it['unit_price']) ?></td>
                                                        <td><?= fmt((float) $it['unit_price'] * $rec) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
<script>
function togglePoDetail(id) {
    var row = document.getElementById('gr-row-' + id);
    var detail = document.getElementById('gr-detail-' + id);
    if (!row || !detail) return;
    var isHidden = detail.style.display === 'none' || detail.style.display === '';
    detail.style.display = isHidden ? '' : 'none';
    row.style.background = isHidden ? '#f1f5f9' : '';
}
</script>
