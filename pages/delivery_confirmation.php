<?php
require_once __DIR__ . '/../includes/delivery_helpers.php';
require_once __DIR__ . '/../includes/workflow_engine.php';
requireDeliveryOperator();
requirePermission('delivery_confirmation', 'view');
$pdo = delGetDb();
ensureDeliveryErpMigration($pdo);
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delivery'])) {
    require_csrf();
    $orderId = (int) ($_POST['order_id'] ?? 0);
    if ($orderId > 0) {
        $userId = (int) (currentUser()['id'] ?? 0);
        $order = delGetOrderDelivery($pdo, $orderId);
        $oldStatus = $order['delivery_status'] ?? null;
        if (WorkflowEngine::canTransition('delivery', $oldStatus, 'pod_pending')) {
            $now = date('Y-m-d H:i:s');
            $result = WorkflowEngine::transition($pdo, 'delivery', $orderId, 'pod_pending', [
                'extra' => ['delivery_confirmed_at' => $now, 'confirmation_method' => 'admin', 'delivered_at' => $now, 'pod_recorded_by' => $userId, 'pod_recorded_at' => $now],
                'changed_by' => $userId,
                'notes' => 'Delivery confirmed — POD awaiting verification',
            ]);
            if ($result['success']) {
                delLog($pdo, $orderId, 'pod_recorded', $oldStatus, 'pod_pending', $userId, 'Delivery confirmed by staff — awaiting verification');
                delSyncDriverAvailability($pdo, (int) ($order['delivery_staff_id'] ?? 0));
                redirect_with_flash('?page=delivery_confirmation', "Order #$orderId receipt confirmed. It now awaits final verification.");
            } else {
                $messages[] = ['type' => 'error', 'text' => 'Could not confirm: ' . $result['error']];
            }
        } else {
            $messages[] = ['type' => 'error', 'text' => 'Order not found or not out for delivery.'];
        }
    }
}

$outForDelivery = $pdo->query("SELECT co.id, co.order_date, co.total_amount, co.delivery_address, c.name AS customer_name, dp.full_name AS staff_name FROM customer_orders co JOIN customers c ON co.customer_id = c.id LEFT JOIN driver_profiles dp ON co.delivery_staff_id = dp.id WHERE co.delivery_status = 'out_for_delivery' ORDER BY co.order_date ASC")->fetchAll();

$recent = $pdo->query("SELECT co.id, co.order_date, co.delivery_status, co.delivery_confirmed_at, co.confirmation_method, c.name AS customer_name FROM customer_orders co JOIN customers c ON co.customer_id = c.id WHERE co.delivery_status = 'delivered' ORDER BY co.delivery_confirmed_at DESC LIMIT 30")->fetchAll();
?>
<section>
    <h1>Delivery Confirmation</h1>
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>
    <div class="tab-bar">
        <button class="tab-btn active" onclick="switchTab(this,'dc-pending')"><i class="fas fa-hourglass-half"></i> Out for Delivery</button>
        <button class="tab-btn" onclick="switchTab(this,'dc-history')"><i class="fas fa-check-circle"></i> Confirmed</button>
    </div>
    <div class="tab-content">
        <div id="dc-pending" class="tab-panel active">
            <div class="panel">
                <h2>Orders Out for Delivery (Awaiting Confirmation)</h2>
                <?php if (empty($outForDelivery)): ?>
                    <p class="hint">No orders currently out for delivery.</p>
                <?php else: ?>
                    <table>
                        <thead><tr><th>Order #</th><th>Customer</th><th>Date</th><th>Total</th><th>Staff</th><th>Address</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($outForDelivery as $o): ?>
                                <tr>
                                    <td><strong>#<?= (int) $o['id'] ?></strong></td>
                                    <td><?= htmlspecialchars($o['customer_name']) ?></td>
                                    <td><?= htmlspecialchars($o['order_date']) ?></td>
                                    <td><?= fmt((float) $o['total_amount']) ?></td>
                                    <td><?= htmlspecialchars($o['staff_name'] ?? '-') ?></td>
                                    <td style="max-width:200px;font-size:0.8rem"><?= htmlspecialchars((string) ($o['delivery_address'] ?? '')) ?></td>
                                    <td>
                                        <?php if (hasPermission('delivery_confirmation', 'confirm')): ?>
                                        <form method="post" onsubmit="return confirm('Confirm receipt for order #<?= (int)$o['id'] ?>? This moves it to awaiting verification.')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="confirm_delivery" value="1">
                                            <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                                            <button type="submit" style="background:var(--green-600);color:#fff;border:none;border-radius:999px;padding:4px 14px;font-size:0.78rem;cursor:pointer;font-weight:600">Confirm Receipt</button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <div id="dc-history" class="tab-panel">
            <div class="panel">
                <h2>Recently Confirmed Deliveries</h2>
                <table>
                    <thead><tr><th>Order #</th><th>Customer</th><th>Date</th><th>Confirmed At</th><th>Method</th></tr></thead>
                    <tbody>
                        <?php if (empty($recent)): ?>
                            <tr><td colspan="5">No confirmed deliveries.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recent as $r): ?>
                                <tr>
                                    <td><strong>#<?= (int) $r['id'] ?></strong></td>
                                    <td><?= htmlspecialchars($r['customer_name']) ?></td>
                                    <td><?= htmlspecialchars($r['order_date']) ?></td>
                                    <td><?= htmlspecialchars((string) $r['delivery_confirmed_at']) ?></td>
                                    <td><span class="status-badge status-approved"><?= htmlspecialchars($r['confirmation_method'] ?? 'customer') ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
