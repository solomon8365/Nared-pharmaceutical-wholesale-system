<?php
requirePermission('warehouse_picking', 'view');
$pdo = getPDO();
$user = currentUser();
$uid = (int) $user['id'];
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_picked') {
        requirePermission('warehouse_picking', 'edit');
        $orderId = (int) ($_POST['order_id'] ?? 0);
        if ($orderId > 0) {
            $check = $pdo->prepare("SELECT id FROM customer_orders WHERE id = :oid AND status = 'approved' AND payment_confirmed = 1 AND (picking_status IS NULL OR picking_status NOT IN ('picked','ready_for_delivery')) AND (delivery_status IS NULL OR delivery_status != 'delivered')");
            $check->execute(['oid' => $orderId]);
            if ($check->fetch()) {
                $transition = WorkflowService::transition($pdo, StatusCatalog::WORKFLOW_PICKING, $orderId, StatusCatalog::PICKING_PICKED, [
                    'extra' => ['picked_at' => date('Y-m-d H:i:s'), 'picker_id' => $uid],
                    'changed_by' => $uid,
                    'notes' => 'Warehouse marked order as picked',
                ]);
                if ($transition['success']) {
                    redirect_with_flash('?page=warehouse_picking', "Order #{$orderId} marked as picked.");
                } else {
                    $messages[] = ['type' => 'error', 'text' => "Order #{$orderId} could not be picked. Check status."];
                }
            } else {
                $messages[] = ['type' => 'error', 'text' => "Order #{$orderId} could not be picked. Check status."];
            }
        }
    }

    if ($action === 'mark_ready') {
        requirePermission('warehouse_picking', 'edit');
        $orderId = (int) ($_POST['order_id'] ?? 0);
        if ($orderId > 0) {
            $transition = WorkflowService::transition($pdo, StatusCatalog::WORKFLOW_PICKING, $orderId, StatusCatalog::PICKING_READY_FOR_DELIVERY, [
                'changed_by' => $uid,
                'notes' => 'Store prepared order — ready for delivery handoff',
            ]);
            if ($transition['success']) {
                redirect_with_flash('?page=warehouse_picking', "Order #{$orderId} marked as ready for delivery.");
            } else {
                $messages[] = ['type' => 'error', 'text' => "Order #{$orderId} could not be marked ready. Check status."];
            }
        }
    }
}

$ready = $pdo->query("
    SELECT co.*, c.name AS customer_name
    FROM customer_orders co
    JOIN customers c ON co.customer_id = c.id
    WHERE co.status = 'approved'
      AND co.payment_confirmed = 1
      AND (co.picking_status IS NULL OR co.picking_status NOT IN ('picked','ready_for_delivery'))
      AND (co.delivery_status IS NULL)
    ORDER BY co.created_at ASC
")->fetchAll();

$picked = $pdo->query("
    SELECT co.*, c.name AS customer_name, u.full_name AS picker_name
    FROM customer_orders co
    JOIN customers c ON co.customer_id = c.id
    LEFT JOIN users u ON co.picker_id = u.id
    WHERE co.picking_status = 'picked'
      AND (co.delivery_status IS NULL OR co.delivery_status != 'delivered')
    ORDER BY co.picked_at DESC
    LIMIT 50
")->fetchAll();

$readyForDelivery = $pdo->query("
    SELECT co.*, c.name AS customer_name, u.full_name AS picker_name
    FROM customer_orders co
    JOIN customers c ON co.customer_id = c.id
    LEFT JOIN users u ON co.picker_id = u.id
    WHERE co.picking_status = 'ready_for_delivery'
      AND (co.delivery_status IS NULL OR co.delivery_status != 'delivered')
    ORDER BY co.picked_at DESC
    LIMIT 50
")->fetchAll();

$selectedOrder = null;
$selectedItems = [];
if (isset($_GET['view'])) {
    $viewId = (int) $_GET['view'];
    $st = $pdo->prepare("SELECT co.*, c.name AS customer_name, u.full_name AS picker_name FROM customer_orders co JOIN customers c ON co.customer_id = c.id LEFT JOIN users u ON co.picker_id = u.id WHERE co.id = :id");
    $st->execute(['id' => $viewId]);
    $selectedOrder = $st->fetch();
    if ($selectedOrder && $selectedOrder['sale_id']) {
        $its = $pdo->prepare("
            SELECT si.*, p.name AS product_name, p.barcode
            FROM sale_items si
            JOIN products p ON si.product_id = p.id
            WHERE si.sale_id = :sid
            ORDER BY p.name
        ");
        $its->execute(['sid' => $selectedOrder['sale_id']]);
        $selectedItems = $its->fetchAll();
    }
}
?>
<section>
    <h1>Warehouse Picking</h1>
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <div class="tab-bar">
        <button class="tab-btn<?= !isset($_GET['view']) ? ' active' : '' ?>" onclick="switchTab(this,'pick-ready')"><i class="fas fa-clipboard-list"></i> Ready to Pick (<?= count($ready) ?>)</button>
        <button class="tab-btn" onclick="switchTab(this,'pick-done')"><i class="fas fa-box"></i> Picked (<?= count($picked) ?>)</button>
        <button class="tab-btn" onclick="switchTab(this,'pick-ready-delivery')"><i class="fas fa-truck-fast"></i> Ready for Delivery (<?= count($readyForDelivery) ?>)</button>
    </div>

    <div class="tab-content">
        <div id="pick-ready" class="tab-panel<?= !isset($_GET['view']) ? ' active' : '' ?>">
            <?php if (empty($ready) && !isset($_GET['view'])): ?>
                <div class="panel"><p class="hint">No orders ready for picking. Orders appear here after payment is confirmed.</p></div>
            <?php endif; ?>
            <?php foreach ($ready as $co): $oid = (int) $co['id']; ?>
                <div class="panel" style="margin-bottom:10px">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                        <div>
                            <strong style="font-size:1.05rem">Order #<?= $oid ?></strong>
                            <span style="color:var(--neutral-500);font-size:0.85rem;margin-left:10px"><?= htmlspecialchars($co['customer_name']) ?></span>
                            <span style="color:var(--neutral-500);font-size:0.82rem;margin-left:10px"><?= htmlspecialchars($co['order_date']) ?></span>
                        </div>
                        <div style="display:flex;gap:8px">
                            <a href="?page=warehouse_picking&view=<?= $oid ?>" style="padding:6px 16px;background:#2563eb;color:#fff;border-radius:999px;text-decoration:none;font-size:0.82rem;font-weight:600"><i class="fas fa-eye"></i> View Items</a>
                            <form method="post" style="margin:0" onsubmit="return confirm('Mark Order #<?= $oid ?> as picked?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="mark_picked">
                                <input type="hidden" name="order_id" value="<?= $oid ?>">
                                <button type="submit" style="padding:6px 16px;background:#059669;color:#fff;border:none;border-radius:999px;cursor:pointer;font-size:0.82rem;font-weight:600"><i class="fas fa-check"></i> Mark Picked</button>
                            </form>
                        </div>
                    </div>
                    <div style="margin-top:8px;font-size:0.85rem;color:var(--neutral-600)">
                        Total: <strong><?= fmt((float) $co['total_amount']) ?></strong>
                        &middot; Payment: <?= htmlspecialchars($co['payment_method']) ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if ($selectedOrder): ?>
                <div class="panel" style="margin-top:16px">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                        <h2 style="margin:0">Order #<?= (int)$selectedOrder['id'] ?> — <?= htmlspecialchars($selectedOrder['customer_name']) ?></h2>
                        <div style="display:flex;gap:8px">
                            <a href="?page=warehouse_picking" style="padding:6px 16px;background:var(--neutral-500);color:#fff;border-radius:999px;text-decoration:none;font-size:0.82rem;font-weight:600">← Back</a>
                            <?php if (!$selectedOrder['picking_status'] || !in_array($selectedOrder['picking_status'], ['picked', 'ready_for_delivery'], true)): ?>
                                <form method="post" style="margin:0" onsubmit="return confirm('Mark all items as picked?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="mark_picked">
                                    <input type="hidden" name="order_id" value="<?= (int)$selectedOrder['id'] ?>">
                                    <button type="submit" style="padding:6px 16px;background:#059669;color:#fff;border:none;border-radius:999px;cursor:pointer;font-size:0.82rem;font-weight:600"><i class="fas fa-check"></i> Mark All Picked</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <table style="margin-top:12px">
                        <thead><tr><th>Product</th><th>Barcode</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr></thead>
                        <tbody>
                            <?php foreach ($selectedItems as $it): ?>
                                <tr>
                                    <td><?= htmlspecialchars($it['product_name']) ?></td>
                                    <td style="font-size:0.82rem;color:var(--neutral-500)"><?= htmlspecialchars($it['barcode'] ?? '—') ?></td>
                                    <td><?= (int) $it['quantity'] ?></td>
                                    <td><?= fmt((float) $it['unit_price']) ?></td>
                                    <td><?= fmt((float) $it['quantity'] * (float) $it['unit_price']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div style="margin-top:8px;text-align:right;font-weight:700;font-size:1rem">
                        Order Total: <?= fmt((float) $selectedOrder['total_amount']) ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div id="pick-done" class="tab-panel">
            <?php if (empty($picked)): ?>
                <div class="panel"><p class="hint">No picked orders yet.</p></div>
            <?php endif; ?>
            <?php foreach ($picked as $co): $oid = (int) $co['id']; ?>
                <div class="panel" style="margin-bottom:10px">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                        <div>
                            <strong style="font-size:1.05rem">Order #<?= $oid ?></strong>
                            <span style="color:var(--neutral-500);font-size:0.85rem;margin-left:10px"><?= htmlspecialchars($co['customer_name']) ?></span>
                            <span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:0.72rem;font-weight:700;background:#05966920;color:#059669;margin-left:10px">Picked</span>
                        </div>
                        <div>
                            <span style="font-size:0.82rem;color:var(--neutral-500)">by <?= htmlspecialchars($co['picker_name'] ?? '—') ?> at <?= htmlspecialchars($co['picked_at'] ?? '') ?></span>
                            <a href="?page=warehouse_picking&view=<?= $oid ?>" style="padding:6px 16px;background:var(--neutral-500);color:#fff;border-radius:999px;text-decoration:none;font-size:0.82rem;font-weight:600;margin-left:8px"><i class="fas fa-eye"></i> View</a>
                            <form method="post" style="display:inline;margin-left:8px" onsubmit="return confirm('Mark Order #<?= $oid ?> as ready for delivery?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="mark_ready">
                                <input type="hidden" name="order_id" value="<?= $oid ?>">
                                <button type="submit" style="padding:6px 16px;background:#059669;color:#fff;border:none;border-radius:999px;cursor:pointer;font-size:0.82rem;font-weight:600"><i class="fas fa-truck-fast"></i> Ready for Delivery</button>
                            </form>
                        </div>
                    </div>
                    <div style="margin-top:6px;font-size:0.85rem;color:var(--neutral-600)">
                        Total: <strong><?= fmt((float) $co['total_amount']) ?></strong>
                        &middot; Order: <?= htmlspecialchars($co['order_date']) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div id="pick-ready-delivery" class="tab-panel">
            <?php if (empty($readyForDelivery)): ?>
                <div class="panel"><p class="hint">No orders ready for delivery. Mark picked orders as ready when the store has packed the goods.</p></div>
            <?php endif; ?>
            <?php foreach ($readyForDelivery as $co): $oid = (int) $co['id']; ?>
                <div class="panel" style="margin-bottom:10px">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                        <div>
                            <strong style="font-size:1.05rem">Order #<?= $oid ?></strong>
                            <span style="color:var(--neutral-500);font-size:0.85rem;margin-left:10px"><?= htmlspecialchars($co['customer_name']) ?></span>
                            <span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:0.72rem;font-weight:700;background:#05966920;color:#059669;margin-left:10px">Ready for Delivery</span>
                        </div>
                        <div>
                            <span style="font-size:0.82rem;color:var(--neutral-500)">picked by <?= htmlspecialchars($co['picker_name'] ?? '—') ?> at <?= htmlspecialchars($co['picked_at'] ?? '') ?></span>
                            <a href="?page=warehouse_picking&view=<?= $oid ?>" style="padding:6px 16px;background:var(--neutral-500);color:#fff;border-radius:999px;text-decoration:none;font-size:0.82rem;font-weight:600;margin-left:8px"><i class="fas fa-eye"></i> View</a>
                        </div>
                    </div>
                    <div style="margin-top:6px;font-size:0.85rem;color:var(--neutral-600)">
                        Total: <strong><?= fmt((float) $co['total_amount']) ?></strong>
                        &middot; Handoff: Delivery Staff / Driver assignment begins from this stage.
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
