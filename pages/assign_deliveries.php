<?php
require_once __DIR__ . '/../includes/auto_migrate.php';
requireDeliveryOperator();
requirePermission('assign_deliveries', 'view');
$pdo = getPDO();
ensureFulfillmentMigration($pdo);
$uid = (int) ($_SESSION['user']['id'] ?? 0);
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign'])) {
    require_csrf();
    requirePermission('assign_deliveries', 'assign_delivery');
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $staffId = (int) ($_POST['delivery_staff_id'] ?? 0);
    if ($orderId > 0 && $staffId > 0) {
        try {
            WorkflowService::assignDelivery($pdo, $orderId, $staffId, $uid);
            redirect_with_flash('?page=assign_deliveries', 'Delivery assigned.');
        } catch (ServiceException $e) {
            $messages[] = ['type' => 'error', 'text' => $e->getMessage()];
        }
    }
}

$ready = $pdo->query("SELECT co.id, co.order_date, co.total_amount, co.delivery_address, c.name AS customer_name, u.full_name AS customer_user FROM customer_orders co JOIN customers c ON co.customer_id = c.id LEFT JOIN users u ON co.placed_by_user_id = u.id WHERE co.status = 'approved' AND co.payment_confirmed = 1 AND co.delivery_staff_id IS NULL AND co.fulfillment_method = 'delivery' AND co.fulfillment_status = 'ready_for_delivery' ORDER BY co.order_date ASC")->fetchAll();

$assigned = $pdo->query("SELECT co.id, co.order_date, co.total_amount, co.delivery_status, co.delivery_address, c.name AS customer_name, dp.full_name AS staff_name FROM customer_orders co JOIN customers c ON co.customer_id = c.id LEFT JOIN driver_profiles dp ON co.delivery_staff_id = dp.id WHERE co.delivery_staff_id IS NOT NULL ORDER BY co.created_at DESC LIMIT 50")->fetchAll();

$deliveryStaff = $pdo->query("SELECT id, full_name FROM driver_profiles WHERE status = 'active' ORDER BY full_name ASC")->fetchAll();
?>
<section>
    <h1>Assign Deliveries</h1>
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>
    <div class="tab-bar">
        <button class="tab-btn active" onclick="switchTab(this,'ad-ready')"><i class="fas fa-clock"></i> Ready to Assign</button>
        <button class="tab-btn" onclick="switchTab(this,'ad-assigned')"><i class="fas fa-truck"></i> Assigned</button>
    </div>
    <div class="tab-content">
        <div id="ad-ready" class="tab-panel active">
            <div class="panel">
                <h2>Orders Ready for Delivery Assignment</h2>
                <?php if (empty($ready)): ?>
                    <p class="hint">No orders ready for delivery assignment.</p>
                <?php else: ?>
                    <table>
                        <thead><tr><th>Order #</th><th>Customer</th><th>Date</th><th>Total</th><th>Address</th><th>Assign</th></tr></thead>
                        <tbody>
                            <?php foreach ($ready as $o): ?>
                                <tr>
                                    <td><strong>#<?= (int) $o['id'] ?></strong></td>
                                    <td><?= htmlspecialchars($o['customer_name']) ?></td>
                                    <td><?= htmlspecialchars($o['order_date']) ?></td>
                                    <td><?= fmt((float) $o['total_amount']) ?></td>
                                    <td style="max-width:200px;font-size:0.8rem"><?= htmlspecialchars((string) ($o['delivery_address'] ?? '')) ?></td>
                                    <td>
                                        <?php if (hasPermission('assign_deliveries', 'assign_delivery')): ?>
                                        <form method="post" style="display:flex;gap:4px;flex-wrap:nowrap">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="assign" value="1">
                                            <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                                            <select name="delivery_staff_id" required style="margin:0;font-size:0.78rem;padding:4px 6px;max-width:130px">
                                                <option value="">— Driver —</option>
                                                <?php foreach ($deliveryStaff as $ds): ?>
                                                    <option value="<?= (int) $ds['id'] ?>"><?= htmlspecialchars($ds['full_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" style="margin:0;padding:4px 10px;font-size:0.78rem">Assign</button>
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
        <div id="ad-assigned" class="tab-panel">
            <div class="panel">
                <h2>Assigned Deliveries</h2>
                <table>
                    <thead><tr><th>Order #</th><th>Customer</th><th>Date</th><th>Total</th><th>Staff</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php if (empty($assigned)): ?>
                            <tr><td colspan="6">No assigned deliveries.</td></tr>
                        <?php else: ?>
                            <?php foreach ($assigned as $o): ?>
                                <tr>
                                    <td><strong>#<?= (int) $o['id'] ?></strong></td>
                                    <td><?= htmlspecialchars($o['customer_name']) ?></td>
                                    <td><?= htmlspecialchars($o['order_date']) ?></td>
                                    <td><?= fmt((float) $o['total_amount']) ?></td>
                                    <td><?= htmlspecialchars($o['staff_name'] ?? 'Unknown') ?></td>
                                    <td><span class="status-badge status-<?= $o['delivery_status'] === 'delivered' ? 'approved' : ($o['delivery_status'] === 'out_for_delivery' ? 'pending' : '') ?>"><?= htmlspecialchars((string) ($o['delivery_status'] ?? 'assigned')) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
