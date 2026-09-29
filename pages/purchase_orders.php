<?php
requirePoCapability('view');
require_once __DIR__ . '/../includes/stock_helper.php';
require_once __DIR__ . '/../includes/workflow_engine.php';
$pdo = getPDO();
$messages = [];

$products = $pdo->query('SELECT id, name, price, purchase_price, stock_quantity FROM products ORDER BY name')->fetchAll();
$suppliers = $pdo->query('SELECT id, name FROM suppliers WHERE supplier_status = \'active\' ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['_action'] ?? '';

    if ($action === 'create') {
        requirePoCapability('create');
        $supplierId = (int) ($_POST['supplier_id'] ?? 0);
        $orderDate = $_POST['order_date'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');
        $items = $_POST['items'] ?? [];

        if ($supplierId <= 0 || empty($items)) {
            $messages[] = ['type' => 'error', 'text' => 'Supplier and at least one item are required.'];
        } else {
            $pdo->beginTransaction();
            try {
                $total = 0;
                foreach ($items as $item) {
                    $productId = (int) ($item['product_id'] ?? 0);
                    $quantity = (int) ($item['quantity'] ?? 0);
                    $unitPrice = (float) ($item['unit_price'] ?? 0);
                    if ($productId <= 0 || $quantity <= 0) continue;
                    $total += $unitPrice * $quantity;
                }

                $stmt = $pdo->prepare('INSERT INTO purchase_orders (supplier_id, order_date, status, total_amount, notes, created_by, created_at) VALUES (:sid, :dt, \'pending\', :total, :notes, :uid, NOW())');
                $stmt->execute(['sid' => $supplierId, 'dt' => $orderDate, 'total' => $total, 'notes' => $notes, 'uid' => (int) ($_SESSION['user_id'] ?? 0)]);
                $poId = (int) $pdo->lastInsertId();

                $insItem = $pdo->prepare('INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, unit_price) VALUES (:poid, :pid, :qty, :up)');
                foreach ($items as $item) {
                    $productId = (int) ($item['product_id'] ?? 0);
                    $quantity = (int) ($item['quantity'] ?? 0);
                    $unitPrice = (float) ($item['unit_price'] ?? 0);
                    if ($productId <= 0 || $quantity <= 0) continue;
                    $insItem->execute(['poid' => $poId, 'pid' => $productId, 'qty' => $quantity, 'up' => $unitPrice]);
                }

                $pdo->commit();
                redirect_with_flash('?page=purchase_orders', "Purchase Order #{$poId} created.");
            } catch (Exception $e) {
                $pdo->rollBack();
                $messages[] = ['type' => 'error', 'text' => 'Failed: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'approve') {
        requirePoCapability('approve');
        $poId = (int) ($_POST['po_id'] ?? 0);
        $result = WorkflowEngine::transition($pdo, 'purchase', $poId, 'payment_pending', [
            'changed_by' => (int) ($_SESSION['user_id'] ?? 0),
        ]);
        if ($result['success']) {
            redirect_with_flash('?page=purchase_orders', "PO #{$poId} approved, now awaiting finance approval.");
        } else {
            $messages[] = ['type' => 'error', 'text' => $result['error']];
        }
    } elseif ($action === 'receive') {
        requirePoCapability('receive');
        $poId = (int) ($_POST['po_id'] ?? 0);
        $receivedQty = $_POST['received_qty'] ?? [];

        // Validate transition before transaction
        $curStmt = $pdo->prepare("SELECT status FROM purchase_orders WHERE id = :id");
        $curStmt->execute(['id' => $poId]);
        $curStatus = $curStmt->fetchColumn();
        if (!WorkflowEngine::canTransition('purchase', $curStatus ?: null, 'received')) {
            $messages[] = ['type' => 'error', 'text' => "PO #{$poId} cannot be received from its current status."];
        } else {
        WorkflowEngine::ensureStatusHistoryTable($pdo);
        $pdo->beginTransaction();
        try {
            foreach ($receivedQty as $itemId => $qty) {
                $qty = (int) $qty;
                if ($qty < 0) continue;
                $stmt = $pdo->prepare("UPDATE purchase_order_items SET received_qty = :rq WHERE id = :id AND purchase_order_id = :poid");
                $stmt->execute(['rq' => $qty, 'id' => (int)$itemId, 'poid' => $poId]);

                $stmt = $pdo->prepare('SELECT product_id, quantity, unit_price FROM purchase_order_items WHERE id = :id');
                $stmt->execute(['id' => (int)$itemId]);
                $item = $stmt->fetch();
                if ($item) {
                    $addedQty = $qty;
                    // Every received quantity must land in a batch so stock never
                    // drifts from the batch ledger.
                    $bno = 'AUTO-' . $poId . '-' . $item['product_id'] . '-' . date('YmdHis');
                    $pdo->prepare("INSERT INTO product_batches (product_id, batch_number, expiry_date, quantity, purchase_price, workflow_status, workflow_updated_at) VALUES (:pid, :bno, NULL, :qty, :pp, 'awaiting_batch_verification', NOW())")
                        ->execute(['pid' => $item['product_id'], 'bno' => $bno, 'qty' => $addedQty, 'pp' => $item['unit_price']]);
                    recalc_product_stock($pdo, (int)$item['product_id']);
                    log_stock_movement($pdo, $item['product_id'], $addedQty, 'PO receive goods', 'purchase_order', $poId);
                }
            }
            $pdo->prepare("UPDATE purchase_orders SET status = 'received', received_at = NOW() WHERE id = :id")
                ->execute(['id' => $poId]);
            WorkflowEngine::logStatusChange($pdo, 'purchase', $poId, $curStatus, 'received', (int) ($_SESSION['user_id'] ?? 0), 'Goods received via PO');
            $pdo->commit();
            redirect_with_flash('?page=purchase_orders', "PO #{$poId} goods received.");
        } catch (Exception $e) {
            $pdo->rollBack();
            $messages[] = ['type' => 'error', 'text' => 'Failed to receive: ' . $e->getMessage()];
        }
        }
    } elseif ($action === 'cancel') {
        requirePoCapability('cancel');
        $poId = (int) ($_POST['po_id'] ?? 0);
        $result = WorkflowEngine::transition($pdo, 'purchase', $poId, 'cancelled', [
            'changed_by' => (int) ($_SESSION['user_id'] ?? 0),
        ]);
        if ($result['success']) {
            redirect_with_flash('?page=purchase_orders', "PO #{$poId} cancelled.");
        } else {
            $messages[] = ['type' => 'error', 'text' => $result['error']];
        }
    }
}

// Filters
$viewPoId = (int) ($_GET['view'] ?? 0);
$viewPo = null;
$viewItems = [];
$viewHistory = [];
if ($viewPoId > 0) {
    $viewStmt = $pdo->prepare('SELECT po.*, s.name AS supplier_name, u.full_name AS created_by_name FROM purchase_orders po LEFT JOIN suppliers s ON po.supplier_id = s.id LEFT JOIN users u ON po.created_by = u.id WHERE po.id = :id');
    $viewStmt->execute(['id' => $viewPoId]);
    $viewPo = $viewStmt->fetch();
    if ($viewPo) {
        $vit = $pdo->prepare('SELECT poi.*, p.name AS pname, p.barcode FROM purchase_order_items poi JOIN products p ON poi.product_id = p.id WHERE poi.purchase_order_id = :poid');
        $vit->execute(['poid' => $viewPoId]);
        $viewItems = $vit->fetchAll();
        try {
            $viewHistory = WorkflowEngine::getStatusHistory($pdo, 'purchase', $viewPoId);
        } catch (Exception $e) {
            $viewHistory = [];
        }
    }
}
$filterStatus = $_GET['status'] ?? '';
$filterSupplier = (int) ($_GET['supplier_id'] ?? 0);
$where = [];
$params = [];
if ($filterStatus !== '') { $where[] = 'po.status = :status'; $params['status'] = $filterStatus; }
if ($filterSupplier > 0) { $where[] = 'po.supplier_id = :sid'; $params['sid'] = $filterSupplier; }
$sql = 'SELECT po.*, s.name AS supplier_name FROM purchase_orders po LEFT JOIN suppliers s ON po.supplier_id = s.id';
if (!empty($where)) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY po.created_at DESC';
$orders = $pdo->prepare($sql);
$orders->execute($params);
$orders = $orders->fetchAll();
?>
<section>
    <h1>Purchase Orders</h1>
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <?php if ($viewPoId > 0): ?>
        <?php if (!$viewPo): ?>
            <div class="panel"><p class="hint">Purchase Order #<?= $viewPoId ?> not found.</p></div>
            <p><a href="?page=purchase_orders"><i class="fas fa-arrow-left"></i> Back to list</a></p>
        <?php else: ?>
            <?php
                $sid = (int)$viewPo['id'];
                $s = $viewPo['status'];
                $colorMap = ['received'=>'#059669','payment_approved'=>'#2563eb','payment_pending'=>'#f59e0b','payment_rejected'=>'#dc2626','pending'=>'#f59e0b','cancelled'=>'#dc2626','approved'=>'#2563eb'];
                $sc = $colorMap[$s] ?? '#6b7280';
                $labelMap = ['pending'=>'Pending','approved'=>'Approved','payment_pending'=>'Awaiting Finance','payment_approved'=>'Payment Approved','payment_rejected'=>'Payment Rejected','received'=>'Received','cancelled'=>'Cancelled'];
            ?>
            <div style="margin-bottom:12px"><a href="?page=purchase_orders" style="color:var(--brown-700);font-weight:600"><i class="fas fa-arrow-left"></i> Back to list</a></div>

            <div class="panel" style="margin-bottom:16px">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
                    <div>
                        <strong style="font-size:1.1rem">PO #<?= $sid ?></strong>
                        <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-weight:700;font-size:0.75rem;background:<?= $sc ?>20;color:<?= $sc ?>;margin-left:10px"><?= $labelMap[$s] ?? ucfirst($s) ?></span>
                    </div>
                    <span style="font-size:0.9rem;color:var(--neutral-600)">Total: <strong><?= fmt((float)$viewPo['total_amount']) ?></strong></span>
                </div>

                <table style="font-size:0.85rem;margin:0">
                    <tbody>
                        <tr><td style="font-weight:600;width:140px">Supplier</td><td><?= htmlspecialchars($viewPo['supplier_name'] ?? '?') ?></td></tr>
                        <tr><td style="font-weight:600">Order Date</td><td><?= htmlspecialchars($viewPo['order_date']) ?></td></tr>
                        <tr><td style="font-weight:600">Created By</td><td><?= htmlspecialchars($viewPo['created_by_name'] ?? '—') ?></td></tr>
                        <?php if ($viewPo['notes']): ?>
                        <tr><td style="font-weight:600">Notes</td><td><?= htmlspecialchars($viewPo['notes']) ?></td></tr>
                        <?php endif; ?>
                        <?php if ($viewPo['payment_voucher_no']): ?>
                        <tr><td style="font-weight:600">Voucher No</td><td><?= htmlspecialchars($viewPo['payment_voucher_no']) ?></td></tr>
                        <?php endif; ?>
                        <?php if ($viewPo['payment_method']): ?>
                        <tr><td style="font-weight:600">Payment Method</td><td><?= htmlspecialchars($viewPo['payment_method']) ?></td></tr>
                        <?php endif; ?>
                        <?php if ($viewPo['received_at']): ?>
                        <tr><td style="font-weight:600">Received At</td><td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($viewPo['received_at']))) ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="panel" style="margin-bottom:16px">
                <h2 style="font-size:1rem;margin-bottom:8px">Order Items</h2>
                <?php if (empty($viewItems)): ?>
                    <p class="hint">No items on this PO.</p>
                <?php else: ?>
                <table>
                    <thead><tr><th>Product</th><th>Barcode</th><th>Ordered</th><th>Received</th><th>Remaining</th><th>Unit Price</th><th>Subtotal</th></tr></thead>
                    <tbody>
                        <?php foreach ($viewItems as $it):
                            $ord = (int)$it['quantity'];
                            $rec = (int)$it['received_qty'];
                            $rem = $ord - $rec;
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($it['pname']) ?></td>
                                <td style="font-size:0.78rem;color:var(--neutral-500)"><?= htmlspecialchars($it['barcode'] ?? '—') ?></td>
                                <td><?= $ord ?></td>
                                <td><?= $rec ?></td>
                                <td><?= $rem > 0 ? $rem : '<span style="color:#059669">Complete</span>' ?></td>
                                <td><?= fmt((float)$it['unit_price']) ?></td>
                                <td><?= fmt((float)$it['unit_price'] * $ord) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <div class="panel">
                <h2 style="font-size:1rem;margin-bottom:8px">Status History</h2>
                <?php if (empty($viewHistory)): ?>
                    <p class="hint">No status history.</p>
                <?php else: ?>
                <table>
                    <thead><tr><th>From</th><th>To</th><th>Changed By</th><th>Date</th><th>Notes</th></tr></thead>
                    <tbody>
                        <?php foreach ($viewHistory as $h): ?>
                            <tr>
                                <td><span style="font-size:0.78rem"><?= htmlspecialchars($h['from_status'] ?? '—') ?></span></td>
                                <td><span style="font-weight:600;font-size:0.78rem"><?= htmlspecialchars($h['to_status'] ?? '—') ?></span></td>
                                <td style="font-size:0.82rem"><?= htmlspecialchars($h['changed_by_name'] ?? '—') ?></td>
                                <td style="font-size:0.82rem"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($h['created_at']))) ?></td>
                                <td style="font-size:0.78rem;max-width:250px;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($h['notes'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <div style="margin-top:12px">
                <a href="?page=purchase_orders" style="padding:6px 16px;background:var(--neutral-200);color:var(--neutral-700);border-radius:6px;text-decoration:none;font-weight:600"><i class="fas fa-arrow-left"></i> Back to List</a>
                <?php if ($s === 'pending' && canApprovePurchaseOrder()): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Send this PO to finance for payment approval?')">
                    <?= csrf_field() ?><input type="hidden" name="_action" value="approve"><input type="hidden" name="po_id" value="<?= $sid ?>">
                    <button type="submit" style="padding:6px 16px;background:#2563eb;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600"><i class="fas fa-paper-plane"></i> Send to Finance</button>
                </form>
                <?php endif; ?>
                <?php if ($s === 'payment_approved' && canReceivePurchaseOrder()): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Mark this PO as fully received?')">
                    <?= csrf_field() ?><input type="hidden" name="_action" value="receive"><input type="hidden" name="po_id" value="<?= $sid ?>">
                    <?php foreach ($viewItems as $it): ?>
                        <input type="hidden" name="received_qty[<?= $it['id'] ?>]" value="<?= (int)$it['quantity'] ?>">
                    <?php endforeach; ?>
                    <button type="submit" style="padding:6px 16px;background:#059669;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600"><i class="fas fa-check"></i> Confirm Receive</button>
                </form>
                <?php endif; ?>
                <?php if (in_array($s, ['pending','payment_pending','payment_approved']) && canCancelPurchaseOrder()): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Cancel this PO?')">
                    <?= csrf_field() ?><input type="hidden" name="_action" value="cancel"><input type="hidden" name="po_id" value="<?= $sid ?>">
                    <button type="submit" style="padding:6px 16px;background:#dc2626;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600"><i class="fas fa-times"></i> Cancel</button>
                </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php else: ?>

    <div class="tab-bar">
        <?php if (canCreatePurchaseOrder()): ?>
        <button class="tab-btn active" onclick="switchTab(this,'po-create')"><i class="fas fa-plus"></i> Create PO</button>
        <?php endif; ?>
        <button class="tab-btn <?= canCreatePurchaseOrder() ? '' : 'active' ?>" onclick="switchTab(this,'po-list')"><i class="fas fa-list"></i> Purchase Orders</button>
    </div>
    <div class="tab-content">
        <?php if (canCreatePurchaseOrder()): ?>
        <div id="po-create" class="tab-panel active">
            <div class="panel">
                <h2>Create Purchase Order</h2>
                <form method="post" class="form-grid" id="po-form" style="max-width:700px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="create">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                        <label>Supplier
                            <select name="supplier_id" required>
                                <option value="">Select supplier</option>
                                <?php foreach ($suppliers as $sup): ?>
                                    <option value="<?= $sup['id'] ?>"><?= htmlspecialchars($sup['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Order Date<input type="date" name="order_date" value="<?= date('Y-m-d') ?>"></label>
                    </div>
                    <label>Notes<textarea name="notes"></textarea></label>
                    <div class="po-items" id="po-items">
                        <div class="po-item-row" style="display:flex;gap:6px;align-items:end;margin-bottom:6px">
                            <select name="items[0][product_id]" required style="flex:3;min-width:120px">
                                <option value="">Select product</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?= $p['id'] ?>" data-price="<?= (float)$p['purchase_price'] > 0 ? (float)$p['purchase_price'] : '' ?>"><?= htmlspecialchars($p['name']) ?> (Stock: <?= $p['stock_quantity'] ?>)<?= (float)$p['purchase_price'] > 0 ? ' [' . fmt((float)$p['purchase_price']) . ']' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" name="items[0][quantity]" value="1" min="1" required style="flex:1;min-width:60px" placeholder="Qty">
                            <input type="number" step="0.01" name="items[0][unit_price]" value="" required style="flex:1;min-width:80px" placeholder="Unit price">
                            <button type="button" class="remove-item" style="padding:6px 10px;font-size:0.78rem">Remove</button>
                        </div>
                    </div>
                    <button type="button" id="add-po-item" style="padding:6px 14px;font-size:0.82rem;background:var(--green-600);color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600"><i class="fas fa-plus"></i> Add Item</button>
                    <button type="submit" style="margin-top:6px">Save Purchase Order</button>
                </form>
            </div>
            <script>
            document.getElementById('add-po-item')?.addEventListener('click', function() {
                var container = document.getElementById('po-items');
                var idx = container.children.length;
                var row = document.createElement('div');
                row.className = 'po-item-row';
                row.style.cssText = 'display:flex;gap:6px;align-items:end;margin-bottom:6px';
                row.innerHTML = '<select name="items[' + idx + '][product_id]" required style="flex:3;min-width:120px"><option value="">Select product</option><?php foreach ($products as $p): ?><option value="<?= $p['id'] ?>" data-price="<?= (float)$p['purchase_price'] > 0 ? (float)$p['purchase_price'] : '' ?>"><?= htmlspecialchars(addslashes($p['name'])) ?> (Stock: <?= $p['stock_quantity'] ?>)<?= (float)$p['purchase_price'] > 0 ? ' [' . fmt((float)$p['purchase_price']) . ']' : '' ?></option><?php endforeach; ?></select><input type="number" name="items[' + idx + '][quantity]" value="1" min="1" required style="flex:1;min-width:60px" placeholder="Qty"><input type="number" step="0.01" name="items[' + idx + '][unit_price]" value="" required style="flex:1;min-width:80px" placeholder="Unit price"><button type="button" class="remove-item" style="padding:6px 10px;font-size:0.78rem">Remove</button>';
                container.appendChild(row);
                row.querySelector('.remove-item').addEventListener('click', function() { row.remove(); });
            });
            document.querySelectorAll('#po-items .remove-item').forEach(function(btn) {
                btn.addEventListener('click', function() { this.closest('.po-item-row').remove(); });
            });
            document.getElementById('po-form')?.addEventListener('change', function(e) {
                if (e.target.tagName === 'SELECT' && e.target.name.includes('product_id')) {
                    var opt = e.target.options[e.target.selectedIndex];
                    var priceInput = e.target.closest('.po-item-row').querySelector('[name*="unit_price"]');
                    if (opt && opt.dataset.price && priceInput) {
                        priceInput.value = opt.dataset.price;
                    }
                }
            });
            </script>
        </div>
        <?php endif; ?>

        <div id="po-list" class="tab-panel">
            <div class="panel">
                <h2>Purchase Order History</h2>
                <form method="get" style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap">
                    <input type="hidden" name="page" value="purchase_orders">
                    <select name="status" style="min-width:130px;margin:0">
                        <option value="">All Statuses</option>
                        <?php foreach (['pending' => 'Pending','payment_pending' => 'Awaiting Finance','payment_approved' => 'Payment Approved','payment_rejected' => 'Payment Rejected','received' => 'Received','cancelled' => 'Cancelled'] as $v => $l): ?>
                            <option value="<?= $v ?>"<?= $filterStatus === $v ? ' selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="supplier_id" style="min-width:130px;margin:0">
                        <option value="">All Suppliers</option>
                        <?php foreach ($suppliers as $sup): ?>
                            <option value="<?= $sup['id'] ?>"<?= $filterSupplier === (int)$sup['id'] ? ' selected' : '' ?>><?= htmlspecialchars($sup['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" style="margin:0">Filter</button>
                    <?php if ($filterStatus !== '' || $filterSupplier > 0): ?><a href="?page=purchase_orders" style="display:inline-flex;align-items:center;padding:0 12px;color:var(--brown-800);font-weight:600">Clear</a><?php endif; ?>
                </form>
                <div style="overflow-x:auto">
                <table>
                    <thead><tr><th>PO#</th><th>Date</th><th>Supplier</th><th>Total</th><th>Status</th><th>Notes</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php if (empty($orders)): ?>
                            <tr><td colspan="7">No purchase orders found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($orders as $po):
                                $sid = (int)$po['id'];
                                $s = $po['status'];
                                $colorMap = ['received'=>'#059669','payment_approved'=>'#2563eb','payment_pending'=>'#f59e0b','payment_rejected'=>'#dc2626','pending'=>'#f59e0b','cancelled'=>'#dc2626'];
                                $sc = $colorMap[$s] ?? '#6b7280';
                                $labelMap = ['pending'=>'Pending','payment_pending'=>'Awaiting Finance','payment_approved'=>'Payment Approved','payment_rejected'=>'Payment Rejected','received'=>'Received','cancelled'=>'Cancelled'];
                            ?>
                                <tr>
                                    <td><?= $sid ?></td>
                                    <td><?= htmlspecialchars($po['order_date']) ?></td>
                                    <td><?= htmlspecialchars($po['supplier_name'] ?? '?') ?></td>
                                    <td><?= fmt((float)$po['total_amount']) ?></td>
                                    <td><span style="display:inline-block;padding:2px 8px;border-radius:4px;font-weight:700;font-size:0.75rem;background:<?= $sc ?>20;color:<?= $sc ?>"><?= $labelMap[$s] ?? ucfirst($s) ?></span></td>
                                    <td style="font-size:0.82rem"><?= htmlspecialchars($po['notes'] ?? '—') ?></td>
                                    <td style="white-space:nowrap">
                                        <a href="?page=purchase_orders&view=<?= $sid ?>" style="padding:3px 8px;font-size:0.78rem;background:var(--neutral-200);color:var(--neutral-700);border-radius:4px;text-decoration:none;font-weight:600;margin-right:4px"><i class="fas fa-eye"></i> View</a>
                                        <?php if ($s === 'pending'): ?>
                                            <?php if (canApprovePurchaseOrder()): ?>
                                            <form method="post" style="display:inline" onsubmit="return confirm('Send this PO to finance for payment approval?')">
                                                <?= csrf_field() ?><input type="hidden" name="_action" value="approve"><input type="hidden" name="po_id" value="<?= $sid ?>">
                                                <button type="submit" style="padding:3px 8px;font-size:0.78rem;background:#2563eb;color:#fff;border:none;border-radius:4px;cursor:pointer">Send to Finance</button>
                                            </form>
                                            <?php endif; ?>
                                            <?php if (canCancelPurchaseOrder()): ?>
                                            <form method="post" style="display:inline" onsubmit="return confirm('Cancel this PO?')">
                                                <?= csrf_field() ?><input type="hidden" name="_action" value="cancel"><input type="hidden" name="po_id" value="<?= $sid ?>">
                                                <button type="submit" style="padding:3px 8px;font-size:0.78rem;background:#dc2626;color:#fff;border:none;border-radius:4px;cursor:pointer">Cancel</button>
                                            </form>
                                            <?php endif; ?>
                                        <?php elseif ($s === 'payment_pending'): ?>
                                            <span style="font-size:0.78rem;color:var(--amber-700);display:block;margin-bottom:4px">Awaiting finance approval</span>
                                            <?php if (canProcessPurchasePayment()): ?>
                                            <a href="?page=purchase_payment_approval" style="display:inline-block;padding:3px 8px;font-size:0.78rem;background:#f59e0b;color:#fff;border:none;border-radius:4px;text-decoration:none;font-weight:600"><i class="fas fa-check-double"></i> Approve Payment</a>
                                            <?php endif; ?>
                                        <?php elseif ($s === 'payment_approved'): ?>
                                            <?php if (canReceivePurchaseOrder()): ?>
                                            <button onclick="showReceiveForm(<?= $sid ?>)" style="padding:3px 8px;font-size:0.78rem;background:#059669;color:#fff;border:none;border-radius:4px;cursor:pointer">Receive</button>
                                            <div id="receive-<?= $sid ?>" style="display:none;margin-top:6px;border:1px solid var(--neutral-300);padding:8px;border-radius:6px;background:var(--neutral-50)">
                                                <form method="post" style="font-size:0.82rem">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="_action" value="receive">
                                                    <input type="hidden" name="po_id" value="<?= $sid ?>">
                                                    <table style="font-size:0.78rem;margin:0;width:auto">
                                                        <thead><tr><th>Product</th><th>Ordered</th><th>Receive</th></tr></thead>
                                                        <tbody>
                                                        <?php
                                                        $itemsStmt = $pdo->prepare('SELECT poi.*, p.name AS pname FROM purchase_order_items poi JOIN products p ON poi.product_id = p.id WHERE poi.purchase_order_id = :poid');
                                                        $itemsStmt->execute(['poid' => $sid]);
                                                        foreach ($itemsStmt->fetchAll() as $it):
                                                        ?>
                                                            <tr>
                                                                <td><?= htmlspecialchars($it['pname']) ?></td>
                                                                <td><?= (int)$it['quantity'] ?></td>
                                                                <td><input type="number" name="received_qty[<?= $it['id'] ?>]" value="<?= (int)$it['quantity'] ?>" min="0" style="width:60px;font-size:0.78rem;padding:2px 4px"></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                    <button type="submit" style="padding:3px 8px;font-size:0.78rem;background:#059669;color:#fff;border:none;border-radius:4px;cursor:pointer;margin-top:4px">Confirm Receive</button>
                                                </form>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (canCancelPurchaseOrder()): ?>
                                            <form method="post" style="display:inline" onsubmit="return confirm('Cancel this PO?')">
                                                <?= csrf_field() ?><input type="hidden" name="_action" value="cancel"><input type="hidden" name="po_id" value="<?= $sid ?>">
                                                <button type="submit" style="padding:3px 8px;font-size:0.78rem;background:#dc2626;color:#fff;border:none;border-radius:4px;cursor:pointer">Cancel</button>
                                            </form>
                                            <?php endif; ?>
                                        <?php elseif ($s === 'payment_rejected'): ?>
                                            <span style="font-size:0.78rem;color:var(--red-600)">Rejected: <?= htmlspecialchars($po['finance_notes'] ?? 'No reason') ?></span>
                                        <?php elseif ($s === 'received'): ?>
                                            <span style="font-size:0.78rem;color:var(--neutral-500)">Received <?= $po['received_at'] ? htmlspecialchars(date('Y-m-d', strtotime($po['received_at']))) : '' ?></span>
                                        <?php else: ?>
                                            <span style="font-size:0.78rem;color:var(--neutral-500)">—</span>
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
    <?php endif; ?>
</section>
<script>
function showReceiveForm(id) {
    var el = document.getElementById('receive-' + id);
    if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
</script>
