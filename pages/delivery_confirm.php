<?php
require_once __DIR__ . '/../services/init.php';
require_once __DIR__ . '/../includes/delivery_helpers.php';
requireDeliveryOperator();
requirePermission('delivery_confirmation', 'view');
$pdo = delGetDb();
DeliveryService::ensureMigration($pdo);

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_csrf();
    $action = $_POST['action'];

    if ($action === 'confirm_delivery') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        if ($orderId > 0) {
            $userId = (int) (currentUser()['id'] ?? 0);
            $res = DeliveryService::confirmDelivery($pdo, $orderId, $userId);
            if ($res['success']) {
                $success = $res['msg'];
            } else {
                $error = "Order #{$orderId} could not be verified: " . $res['msg'];
            }
        }
    }
}

$pending = $pdo->query("
    SELECT co.id, co.order_date, co.total_amount, co.delivery_status, co.delivered_at, co.delivery_confirmed_at,
           co.receiver_name, co.receiver_phone, co.delivery_notes, co.delivery_photo, co.receiver_signature,
           co.confirmation_method, co.driver_name, co.pod_recorded_at, co.pod_verified_at,
           c.name AS customer_name, c.phone AS customer_phone,
           dp.full_name AS staff_name
    FROM customer_orders co
    LEFT JOIN customers c ON co.customer_id = c.id
    LEFT JOIN driver_profiles dp ON co.delivery_staff_id = dp.id
    WHERE co.delivery_status = 'pod_pending'
    ORDER BY co.pod_recorded_at ASC
")->fetchAll();

$outForDelivery = $pdo->query("
    SELECT co.id, co.order_date, co.total_amount, co.delivery_status, co.dispatch_time,
           c.name AS customer_name,
           dp.full_name AS staff_name
    FROM customer_orders co
    LEFT JOIN customers c ON co.customer_id = c.id
    LEFT JOIN driver_profiles dp ON co.delivery_staff_id = dp.id
    WHERE co.delivery_status = 'out_for_delivery'
    ORDER BY co.dispatch_time ASC
")->fetchAll();

$confirmedToday = $pdo->query("
    SELECT co.id, co.order_date, co.total_amount, co.delivery_status, co.delivery_confirmed_at, co.confirmation_method,
           c.name AS customer_name,
           dp.full_name AS staff_name
    FROM customer_orders co
    LEFT JOIN customers c ON co.customer_id = c.id
    LEFT JOIN driver_profiles dp ON co.delivery_staff_id = dp.id
    WHERE co.delivery_confirmed_at IS NOT NULL AND DATE(co.delivery_confirmed_at) = CURDATE()
    ORDER BY co.delivery_confirmed_at DESC
")->fetchAll();

$pendingCount = count($pending);
$transitCount = count($outForDelivery);
$confirmedTodayCount = count($confirmedToday);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Confirmation — NARED PHARMACON</title>
    <?= delCss() ?>
</head>
<body>
<div class="del-wrap" style="padding:20px">
    <h1><i class="fas fa-clipboard-check" style="color:var(--del-green)"></i> Delivery Confirmation</h1>
    <p class="del-subtitle">Verify and confirm completed deliveries</p>

    <?php if ($success): ?>
        <div class="del-notif del-notif-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="del-notif del-notif-critical"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="del-stats">
        <div class="del-stat">
            <div class="del-stat-icon" style="background:linear-gradient(135deg,#d97706,#b45309)"><i class="fas fa-clock"></i></div>
            <div class="del-stat-body">
                <div class="del-stat-val" data-counter="<?= $pendingCount ?>"><?= $pendingCount ?></div>
                <div class="del-stat-label">Awaiting Verification</div>
            </div>
        </div>
        <div class="del-stat">
            <div class="del-stat-icon" style="background:linear-gradient(135deg,#2563eb,#1d4ed8)"><i class="fas fa-truck"></i></div>
            <div class="del-stat-body">
                <div class="del-stat-val" data-counter="<?= $transitCount ?>"><?= $transitCount ?></div>
                <div class="del-stat-label">Out for Delivery</div>
            </div>
        </div>
        <div class="del-stat">
            <div class="del-stat-icon" style="background:linear-gradient(135deg,#059669,#047857)"><i class="fas fa-check-circle"></i></div>
            <div class="del-stat-body">
                <div class="del-stat-val" data-counter="<?= $confirmedTodayCount ?>"><?= $confirmedTodayCount ?></div>
                <div class="del-stat-label">Confirmed Today</div>
            </div>
        </div>
    </div>

    <div class="del-tabs" id="confirm-tabs">
        <button class="del-tab active" onclick="delSwitchTab(this,'panel-pending')"><i class="fas fa-clipboard-check"></i> Awaiting Verification (<?= $pendingCount ?>)</button>
        <button class="del-tab" onclick="delSwitchTab(this,'panel-transit')"><i class="fas fa-truck"></i> Out for Delivery (<?= $transitCount ?>)</button>
        <button class="del-tab" onclick="delSwitchTab(this,'panel-confirmed')"><i class="fas fa-check-double"></i> Confirmed Today (<?= $confirmedTodayCount ?>)</button>
    </div>

    <div class="del-panel active" id="panel-pending">
        <div class="del-card">
            <h2><i class="fas fa-clipboard-check"></i> Awaiting Verification (POD recorded)</h2>
            <?php if (empty($pending)): ?>
                <div class="del-empty"><i class="fas fa-check-circle"></i><p>No deliveries pending confirmation.</p></div>
            <?php else: ?>
                <table class="del-table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Customer</th>
                            <th>Driver</th>
                            <th>Receiver</th>
                            <th>Delivered At</th>
                            <th>Proof</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending as $o): ?>
                            <tr>
                                <td><strong>#<?= (int) $o['id'] ?></strong></td>
                                <td><?= htmlspecialchars($o['customer_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($o['staff_name'] ?? $o['driver_name'] ?? '—') ?></td>
                                <td>
                                    <?= htmlspecialchars($o['receiver_name'] ?? '—') ?>
                                    <?php if (!empty($o['receiver_phone'])): ?>
                                        <br><small style="color:var(--del-gray)"><?= htmlspecialchars($o['receiver_phone']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($o['delivered_at'] ?? '—') ?></td>
                                <td>
                                    <?php if (!empty($o['delivery_photo'])): ?>
                                        <a href="<?= htmlspecialchars($o['delivery_photo']) ?>" target="_blank" class="del-btn del-btn-sm del-btn-outline"><i class="fas fa-image"></i> Photo</a>
                                    <?php else: ?>
                                        <span style="color:var(--del-gray)">—</span>
                                    <?php endif; ?>
                                    <?php if (!empty($o['receiver_signature'])): ?>
                                        <a href="#" onclick="viewSignature('<?= htmlspecialchars($o['receiver_signature']) ?>'); return false;" class="del-btn del-btn-sm del-btn-outline"><i class="fas fa-signature"></i> Signature</a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" style="display:inline" onsubmit="return confirm('Verify and complete this delivery?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="confirm_delivery">
                                        <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                                        <button type="submit" class="del-btn del-btn-sm del-btn-green"><i class="fas fa-check-circle"></i> Verify & Complete</button>
                                    </form>
                                </td>
                            </tr>
                            <?php if (!empty($o['delivery_notes'])): ?>
                                <tr style="background:#f8fafc">
                                    <td colspan="7" style="font-size:.8rem;color:var(--del-gray)"><i class="fas fa-sticky-note"></i> <?= htmlspecialchars($o['delivery_notes']) ?></td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="del-panel" id="panel-transit">
        <div class="del-card">
            <h2><i class="fas fa-truck"></i> Currently Out for Delivery</h2>
            <?php if (empty($outForDelivery)): ?>
                <div class="del-empty"><i class="fas fa-truck"></i><p>No orders currently out for delivery.</p></div>
            <?php else: ?>
                <table class="del-table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Customer</th>
                            <th>Driver</th>
                            <th>Dispatched At</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($outForDelivery as $o): ?>
                            <tr>
                                <td><strong>#<?= (int) $o['id'] ?></strong></td>
                                <td><?= htmlspecialchars($o['customer_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($o['staff_name'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($o['dispatch_time'] ?? '—') ?></td>
                                <td><?= delBadgeHtml('out_for_delivery') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="del-panel" id="panel-confirmed">
        <div class="del-card">
            <h2><i class="fas fa-check-double"></i> Confirmed Today</h2>
            <?php if (empty($confirmedToday)): ?>
                <div class="del-empty"><i class="fas fa-calendar-check"></i><p>No deliveries confirmed today yet.</p></div>
            <?php else: ?>
                <table class="del-table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Customer</th>
                            <th>Driver</th>
                            <th>Confirmed At</th>
                            <th>Method</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($confirmedToday as $o): ?>
                            <tr>
                                <td><strong>#<?= (int) $o['id'] ?></strong></td>
                                <td><?= htmlspecialchars($o['customer_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($o['staff_name'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($o['delivery_confirmed_at'] ?? '—') ?></td>
                                <td><?= delBadgeHtml($o['confirmation_method'] === 'admin' ? 'available' : 'assigned') ?>
                                    <?= ucfirst(htmlspecialchars($o['confirmation_method'] ?? '—')) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
<?= delJs() ?>
<script>
function viewSignature(data) {
    var w = window.open('', '_blank', 'width=520,height=280');
    if (!w) { alert('Please allow popups to view the signature.'); return; }
    w.document.write('<html><body style="font-family:sans-serif;text-align:center;background:#fff"><h4 style="margin:10px 0">Customer Signature</h4><img src="' + data + '" style="max-width:480px;max-height:220px;border:1px solid #d1d5db;border-radius:6px;padding:4px;background:#fff"><p style="color:#64748b">POD customer signature</p></body></html>');
}
</script>
</body>
</html>
