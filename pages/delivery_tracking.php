<?php
require_once __DIR__ . '/../includes/delivery_helpers.php';
require_once __DIR__ . '/../includes/workflow_engine.php';
requireDeliveryOperator();
requirePermission('delivery_tracking', 'view');
$pdo = delGetDb();
ensureDeliveryErpMigration($pdo);
$userId = (int)(currentUser()['id'] ?? 0);
$messages = [];

// Return to the tracking list preserving filters (Post/Redirect/Get).
$returnUrl = '?page=delivery_tracking';
$ql = [];
foreach (['status' => trim($_GET['status'] ?? ''), 'driver' => (int) ($_GET['driver'] ?? 0), 'q' => trim($_GET['q'] ?? '')] as $k => $v) {
    if ($v !== '' && $v !== 0) { $ql[] = $k . '=' . rawurlencode((string) $v); }
}
if (!empty($ql)) { $returnUrl .= '&' . implode('&', $ql); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_csrf();
    $action = $_POST['action'];
    $orderId = (int) ($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        $messages[] = ['type' => 'error', 'text' => 'Invalid order.'];
    } else {
        $order = delGetOrderDelivery($pdo, $orderId);
        $oldStatus = $order['delivery_status'] ?? null;
        switch ($action) {
            case 'mark_picked_up':
                if (WorkflowEngine::canTransition('delivery', $oldStatus, 'picked_up')) {
                    $result = WorkflowEngine::transition($pdo, 'delivery', $orderId, 'picked_up', ['extra' => ['picked_up_at' => date('Y-m-d H:i:s')], 'changed_by' => $userId, 'notes' => 'Delivery staff marked order as picked up']);
                    if ($result['success']) { delLog($pdo, $orderId, 'status_change', $oldStatus, 'picked_up', $userId, 'Marked picked up by delivery staff'); redirect_with_flash($returnUrl, "Order #{$orderId} marked as picked up."); }
                    else { $messages[] = ['type' => 'error', 'text' => $result['error']]; }
                } else { $messages[] = ['type' => 'error', 'text' => 'Order cannot be marked picked up from its current status.']; }
                break;

            case 'mark_out_for_delivery':
                if (WorkflowEngine::canTransition('delivery', $oldStatus, 'out_for_delivery')) {
                    $result = WorkflowEngine::transition($pdo, 'delivery', $orderId, 'out_for_delivery', ['extra' => ['dispatch_time' => date('Y-m-d H:i:s')], 'changed_by' => $userId, 'notes' => 'Delivery staff dispatched order']);
                    if ($result['success']) { delLog($pdo, $orderId, 'status_change', $oldStatus, 'out_for_delivery', $userId, 'Dispatched by delivery staff'); redirect_with_flash($returnUrl, "Order #{$orderId} marked as out for delivery."); }
                    else { $messages[] = ['type' => 'error', 'text' => $result['error']]; }
                } else { $messages[] = ['type' => 'error', 'text' => 'Order cannot be dispatched from its current status.']; }
                break;

            case 'record_pod':
                $receiverName = trim($_POST['receiver_name'] ?? '');
                $receiverPhone = trim($_POST['receiver_phone'] ?? '');
                $deliveryNotes = trim($_POST['delivery_notes'] ?? '');
                $signature = trim($_POST['receiver_signature'] ?? '');
                $deliveryDate = trim($_POST['delivery_date'] ?? '');
                $deliveryTime = trim($_POST['delivery_time'] ?? '');
                if ($receiverName === '') {
                    $messages[] = ['type' => 'error', 'text' => 'Receiver name is required.'];
                } elseif ($receiverPhone === '') {
                    $messages[] = ['type' => 'error', 'text' => 'Receiver phone is required.'];
                } elseif ($signature === '') {
                    $messages[] = ['type' => 'error', 'text' => 'Customer signature is required to record proof of delivery.'];
                } elseif (!WorkflowEngine::canTransition('delivery', $oldStatus, 'pod_pending')) {
                    $messages[] = ['type' => 'error', 'text' => 'Order cannot have POD recorded from its current status.'];
                } else {
                    $photoPath = delUploadPodPhoto();
                    if ($photoPath === false) {
                        $messages[] = ['type' => 'error', 'text' => 'Invalid POD photo upload (allowed: jpg, png, webp, gif, max 2MB).'];
                        break;
                    }
                    $deliveredAt = ($deliveryDate !== '' && $deliveryTime !== '') ? $deliveryDate . ' ' . $deliveryTime : date('Y-m-d H:i:s');
                    $now = date('Y-m-d H:i:s');
                    $extra = [
                        'delivered_at' => $deliveredAt,
                        'receiver_name' => $receiverName,
                        'receiver_phone' => $receiverPhone,
                        'receiver_signature' => $signature,
                        'delivery_notes' => $deliveryNotes !== '' ? $deliveryNotes : null,
                        'delivery_photo' => $photoPath,
                        'delivery_confirmed_at' => $now,
                        'confirmation_method' => 'delivery_staff',
                        'pod_recorded_by' => $userId,
                        'pod_recorded_at' => $now,
                    ];
                    $result = WorkflowEngine::transition($pdo, 'delivery', $orderId, 'pod_pending', ['extra' => $extra, 'changed_by' => $userId, 'notes' => 'POD recorded — awaiting verification']);
                    if ($result['success']) {
                        delLog($pdo, $orderId, 'pod_recorded', $oldStatus, 'pod_pending', $userId, 'POD recorded: ' . $receiverName . ' (' . $receiverPhone . ')');
                        delSyncDriverAvailability($pdo, (int) ($order['delivery_staff_id'] ?? 0));
                        if ($order && $order['customer_id']) {
                            delNotify($pdo, (int) $order['customer_id'], $orderId, 'Receipt Confirmed', "Order #{$orderId} receipt confirmed. Delivery staff will verify shortly.", 'info');
                        }
                        redirect_with_flash($returnUrl, "Order #{$orderId} POD recorded. Delivery now awaiting verification.");
                    } else { $messages[] = ['type' => 'error', 'text' => $result['error']]; }
                }
                break;

            case 'verify_delivery':
                if (WorkflowEngine::canTransition('delivery', $oldStatus, 'delivered')) {
                    $result = WorkflowEngine::transition($pdo, 'delivery', $orderId, 'delivered', ['extra' => ['pod_verified_by' => $userId, 'pod_verified_at' => date('Y-m-d H:i:s')], 'changed_by' => $userId, 'notes' => 'Delivery verified after POD confirmation']);
                    if ($result['success']) {
                        delLog($pdo, $orderId, 'status_change', $oldStatus, 'delivered', $userId, 'Delivery verified by delivery staff');
                        delSyncDriverAvailability($pdo, (int) ($order['delivery_staff_id'] ?? 0));
                        delCompleteInvoiceForOrder($pdo, $orderId, $userId);
                        if ($order && $order['customer_id']) {
                            delNotify($pdo, (int) $order['customer_id'], $orderId, 'Delivered', "Order #{$orderId} has been delivered.", 'success');
                        }
                        redirect_with_flash($returnUrl, "Order #{$orderId} verified and marked as delivered. Invoice completed.");
                    } else { $messages[] = ['type' => 'error', 'text' => $result['error']]; }
                } else { $messages[] = ['type' => 'error', 'text' => 'Order cannot be verified from its current status.']; }
                break;

            case 'mark_failed':
                $failedReason = trim($_POST['failed_reason'] ?? '');
                $failedNotes = trim($_POST['failed_notes'] ?? '');
                if ($failedReason === '') {
                    $messages[] = ['type' => 'error', 'text' => 'Please provide a reason for the failed delivery.'];
                } elseif (WorkflowEngine::canTransition('delivery', $oldStatus, 'failed')) {
                    $result = WorkflowEngine::transition($pdo, 'delivery', $orderId, 'failed', ['extra' => ['failed_reason' => $failedReason, 'failed_notes' => $failedNotes !== '' ? $failedNotes : null], 'changed_by' => $userId, 'notes' => "Failed: {$failedReason} - {$failedNotes}"]);
                    if ($result['success']) { delLog($pdo, $orderId, 'status_change', $oldStatus, 'failed', $userId, "Failed: {$failedReason} - {$failedNotes}"); delSyncDriverAvailability($pdo, (int) ($order['delivery_staff_id'] ?? 0)); redirect_with_flash($returnUrl, "Order #{$orderId} marked as failed."); }
                    else { $messages[] = ['type' => 'error', 'text' => $result['error']]; }
                } else { $messages[] = ['type' => 'error', 'text' => 'Order cannot be marked failed from its current status.']; }
                break;
        }
    }
}

$statusFilter = $_GET['status'] ?? '';
$driverFilter = (int) ($_GET['driver'] ?? 0);
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$search = trim($_GET['search'] ?? '');

$where = ['co.delivery_status IS NOT NULL'];
$params = [];

if ($statusFilter !== '') {
    $where[] = 'co.delivery_status = :status';
    $params['status'] = $statusFilter;
}
if ($driverFilter > 0) {
    $where[] = 'co.delivery_staff_id = :driver';
    $params['driver'] = $driverFilter;
}
if ($dateFrom !== '') {
    $where[] = 'co.created_at >= :df';
    $params['df'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'co.created_at <= :dt';
    $params['dt'] = $dateTo . ' 23:59:59';
}
if ($search !== '') {
    $where[] = '(co.id LIKE :search OR c.name LIKE :search2)';
    $params['search'] = '%' . $search . '%';
    $params['search2'] = '%' . $search . '%';
}

$whereSql = implode(' AND ', $where);
$orders = $pdo->prepare("
    SELECT co.id, co.order_date, co.total_amount, co.delivery_status, co.delivery_address,
           co.delivery_confirmed_at, co.assigned_at, co.accepted_at, co.picked_up_at,
           co.dispatch_time, co.delivered_at, co.pod_recorded_at, co.pod_verified_at,
           co.driver_name, co.receiver_name,
           c.name AS customer_name, c.phone AS customer_phone,
           dp.full_name AS staff_name
    FROM customer_orders co
    LEFT JOIN customers c ON co.customer_id = c.id
    LEFT JOIN driver_profiles dp ON co.delivery_staff_id = dp.id
    WHERE {$whereSql}
    ORDER BY co.created_at DESC
    LIMIT 200
");
$orders->execute($params);
$orders = $orders->fetchAll();

$drivers = $pdo->query("SELECT id, full_name FROM driver_profiles WHERE status = 'active' ORDER BY full_name ASC")->fetchAll();

$summary = $pdo->query("
    SELECT COUNT(*) AS total,
           COUNT(CASE WHEN delivery_status = 'assigned' THEN 1 END) AS assigned,
           COUNT(CASE WHEN delivery_status = 'accepted' THEN 1 END) AS accepted,
           COUNT(CASE WHEN delivery_status = 'picked_up' THEN 1 END) AS picked_up,
           COUNT(CASE WHEN delivery_status = 'out_for_delivery' THEN 1 END) AS out_for_delivery,
           COUNT(CASE WHEN delivery_status = 'pod_pending' THEN 1 END) AS pod_pending,
           COUNT(CASE WHEN delivery_status = 'delivered' THEN 1 END) AS delivered,
           COUNT(CASE WHEN delivery_status = 'failed' THEN 1 END) AS failed,
           COUNT(CASE WHEN delivery_status = 'delivered' AND DATE(delivered_at) = CURDATE() THEN 1 END) AS delivered_today
    FROM customer_orders WHERE delivery_status IS NOT NULL
")->fetch();

$selectedOrder = null;
if (!empty($_GET['view'])) {
    $viewId = (int) $_GET['view'];
    $vStmt = $pdo->prepare("SELECT co.*, c.name AS customer_name, c.phone AS customer_phone, dp.full_name AS staff_name FROM customer_orders co LEFT JOIN customers c ON co.customer_id = c.id LEFT JOIN driver_profiles dp ON co.delivery_staff_id = dp.id WHERE co.id = ?");
    $vStmt->execute([$viewId]);
    $selectedOrder = $vStmt->fetch();
}
?>
<?= delCss() ?>
<style>
.status-nav{display:flex;gap:4px;flex-wrap:wrap;margin-bottom:12px}
.status-nav a{padding:6px 14px;border-radius:20px;font-size:.75rem;font-weight:600;text-decoration:none;transition:all .15s;border:1px solid #e2e8f0;background:#fff;color:#475569}
.status-nav a:hover{border-color:#94a3b8;background:#f8fafc}
.status-nav a.active{background:#2563eb;color:#fff;border-color:#2563eb}

.del-stats{grid-template-columns:repeat(7,minmax(0,1fr));gap:12px;margin-bottom:18px}
.del-stat{min-height:88px;padding:16px 14px}
@media(max-width:1200px){.del-stats{grid-template-columns:repeat(4,minmax(0,1fr))}}
@media(max-width:900px){.del-stats{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:520px){.del-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.del-stat{padding:12px 10px}.del-stat-icon{width:38px;height:38px;font-size:.95rem}}

.del-filter-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px;margin-bottom:14px}
.del-filter-grid{display:grid;grid-template-columns:minmax(200px,1.6fr) minmax(150px,.9fr) minmax(150px,.9fr) minmax(150px,.8fr) minmax(150px,.8fr);gap:10px;align-items:end}
.del-filter-field{display:flex;flex-direction:column;gap:5px;min-width:0}
.del-filter-field label{font-size:.7rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em}
.del-filter-field input,.del-filter-field select{width:100%;min-width:0;height:38px;padding:0 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;background:#fff;font-family:inherit;box-sizing:border-box;color:#334155;outline:none}
.del-filter-field input:focus,.del-filter-field select:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.del-filter-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:12px}
.del-filter-actions .del-btn{min-height:38px}
@media(max-width:1180px){.del-filter-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.del-filter-search{grid-column:span 2}}
@media(max-width:820px){.del-filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.del-filter-search{grid-column:auto}}
@media(max-width:520px){.del-filter-grid{grid-template-columns:1fr}.del-filter-actions{justify-content:stretch}.del-filter-actions .del-btn{flex:1;justify-content:center}}

.del-empty{padding:32px 16px;border:1px dashed #cbd5e1;border-radius:12px;background:#fcfcfd}
.del-empty p{margin:6px 0 0;font-size:.88rem}
.del-table td{vertical-align:middle}
@media(max-width:1100px){.del-table{min-width:860px}}
@media(max-width:420px){.top-header{flex-wrap:wrap;height:auto;min-height:var(--header-h)}.breadcrumbs{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:120px}}
</style>

<div class="del-wrap">
    <h1><i class="fas fa-route" style="color:var(--del-blue)"></i> Delivery Tracking</h1>
    <p class="del-subtitle">Track all deliveries in real-time</p>

    <?php if (!empty($messages)): ?>
        <?php foreach ($messages as $m): ?>
            <div class="del-notif del-notif-<?= $m['type'] === 'success' ? 'success' : 'critical' ?>" style="margin-bottom:12px">
                <i class="fas <?= $m['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                <?= htmlspecialchars($m['text']) ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="del-stats">
        <div class="del-stat">
            <div class="del-stat-icon" style="background:linear-gradient(135deg,#64748b,#475569)"><i class="fas fa-list"></i></div>
            <div class="del-stat-body">
                <div class="del-stat-val" data-counter="<?= (int)$summary['total'] ?>"><?= (int)$summary['total'] ?></div>
                <div class="del-stat-label">Total Active</div>
            </div>
        </div>
        <div class="del-stat">
            <div class="del-stat-icon" style="background:linear-gradient(135deg,#2563eb,#1d4ed8)"><i class="fas fa-user-check"></i></div>
            <div class="del-stat-body">
                <div class="del-stat-val" data-counter="<?= (int)$summary['assigned'] ?>"><?= (int)$summary['assigned'] ?></div>
                <div class="del-stat-label">Assigned</div>
            </div>
        </div>
        <div class="del-stat">
            <div class="del-stat-icon" style="background:linear-gradient(135deg,#7c3aed,#6d28d9)"><i class="fas fa-handshake"></i></div>
            <div class="del-stat-body">
                <div class="del-stat-val" data-counter="<?= (int)$summary['accepted'] ?>"><?= (int)$summary['accepted'] ?></div>
                <div class="del-stat-label">Accepted</div>
            </div>
        </div>
        <div class="del-stat">
            <div class="del-stat-icon" style="background:linear-gradient(135deg,#d97706,#b45309)"><i class="fas fa-box"></i></div>
            <div class="del-stat-body">
                <div class="del-stat-val" data-counter="<?= (int)$summary['picked_up'] ?>"><?= (int)$summary['picked_up'] ?></div>
                <div class="del-stat-label">Picked Up</div>
            </div>
        </div>
        <div class="del-stat">
            <div class="del-stat-icon" style="background:linear-gradient(135deg,#ea580c,#c2410c)"><i class="fas fa-truck"></i></div>
            <div class="del-stat-body">
                <div class="del-stat-val" data-counter="<?= (int)$summary['out_for_delivery'] ?>"><?= (int)$summary['out_for_delivery'] ?></div>
                <div class="del-stat-label">Out for Delivery</div>
            </div>
        </div>
        <div class="del-stat">
            <div class="del-stat-icon" style="background:linear-gradient(135deg,#ca8a04,#a16207)"><i class="fas fa-clipboard-check"></i></div>
            <div class="del-stat-body">
                <div class="del-stat-val" data-counter="<?= (int)$summary['pod_pending'] ?>"><?= (int)$summary['pod_pending'] ?></div>
                <div class="del-stat-label">Awaiting Verification</div>
            </div>
        </div>
        <div class="del-stat">
            <div class="del-stat-icon" style="background:linear-gradient(135deg,#059669,#047857)"><i class="fas fa-check-circle"></i></div>
            <div class="del-stat-body">
                <div class="del-stat-val" data-counter="<?= (int)$summary['delivered_today'] ?>"><?= (int)$summary['delivered_today'] ?></div>
                <div class="del-stat-label">Delivered Today</div>
            </div>
        </div>
    </div>

    <?php if ($selectedOrder): ?>
        <div class="del-card" style="border-left:4px solid var(--del-blue)">
            <h2><i class="fas fa-map-marker-alt"></i> Order #<?= (int) $selectedOrder['id'] ?> — Delivery Timeline</h2>
            <?= delRenderTimeline($selectedOrder) ?>
            <div class="del-grid-2" style="margin-top:14px">
                <div>
                    <strong style="font-size:.8rem;color:var(--del-gray)">Customer</strong><br>
                    <?= htmlspecialchars($selectedOrder['customer_name'] ?? '') ?>
                    <?php if (!empty($selectedOrder['customer_phone'])): ?>
                        <br><small style="color:var(--del-gray)"><i class="fas fa-phone"></i> <?= htmlspecialchars($selectedOrder['customer_phone']) ?></small>
                    <?php endif; ?>
                </div>
                <div>
                    <strong style="font-size:.8rem;color:var(--del-gray)">Driver</strong><br>
                    <?= htmlspecialchars($selectedOrder['staff_name'] ?? $selectedOrder['driver_name'] ?? 'Unassigned') ?>
                </div>
                <div>
                    <strong style="font-size:.8rem;color:var(--del-gray)">Delivery Address</strong><br>
                    <?= nl2br(htmlspecialchars($selectedOrder['delivery_address'] ?? 'Not specified')) ?>
                </div>
                <?php if (!empty($selectedOrder['receiver_name'])): ?>
                    <div>
                        <strong style="font-size:.8rem;color:var(--del-gray)">Received By</strong><br>
                        <?= htmlspecialchars($selectedOrder['receiver_name']) ?>
                        <?php if (!empty($selectedOrder['receiver_phone'])): ?>
                            <br><small style="color:var(--del-gray)"><?= htmlspecialchars($selectedOrder['receiver_phone']) ?></small>
                        <?php endif; ?>
                        <?php if (!empty($selectedOrder['delivered_at'])): ?>
                            <br><small style="color:var(--del-gray)">Delivered on <?= htmlspecialchars($selectedOrder['delivered_at']) ?></small>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($selectedOrder['receiver_signature'])): ?>
                    <div>
                        <strong style="font-size:.8rem;color:var(--del-gray)">Customer Signature</strong><br>
                        <img src="<?= htmlspecialchars($selectedOrder['receiver_signature']) ?>" alt="Customer Signature" style="max-width:240px;max-height:80px;border:1px solid #d1d5db;border-radius:6px;padding:4px;background:#fff">
                    </div>
                <?php endif; ?>
                <?php if (!empty($selectedOrder['pod_recorded_at'])): ?>
                    <div>
                        <strong style="font-size:.8rem;color:var(--del-gray)">POD Recorded</strong><br>
                        <?= htmlspecialchars($selectedOrder['pod_recorded_at']) ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($selectedOrder['pod_verified_at'])): ?>
                    <div>
                        <strong style="font-size:.8rem;color:var(--del-green)">Verified</strong><br>
                        <?= htmlspecialchars($selectedOrder['pod_verified_at']) ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($selectedOrder['delivery_notes'])): ?>
                    <div>
                        <strong style="font-size:.8rem;color:var(--del-gray)">Delivery Notes</strong><br>
                        <?= htmlspecialchars($selectedOrder['delivery_notes']) ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($selectedOrder['delivery_photo'])): ?>
                    <div>
                        <strong style="font-size:.8rem;color:var(--del-gray)">Proof Photo</strong><br>
                        <a href="<?= htmlspecialchars($selectedOrder['delivery_photo']) ?>" target="_blank" class="del-btn del-btn-sm del-btn-outline"><i class="fas fa-image"></i> View Photo</a>
                    </div>
                <?php endif; ?>
            </div>
            <div style="margin-top:14px">
                <a href="?page=delivery_tracking" class="del-btn del-btn-outline del-btn-sm"><i class="fas fa-arrow-left"></i> Back to Tracking</a>
            </div>
        </div>
    <?php endif; ?>

    <div class="status-nav">
        <a href="?page=delivery_tracking" class="<?= $statusFilter === '' ? 'active' : '' ?>">All</a>
        <a href="?page=delivery_tracking&status=assigned" class="<?= $statusFilter === 'assigned' ? 'active' : '' ?>">Assigned</a>
        <a href="?page=delivery_tracking&status=accepted" class="<?= $statusFilter === 'accepted' ? 'active' : '' ?>">Accepted</a>
        <a href="?page=delivery_tracking&status=picked_up" class="<?= $statusFilter === 'picked_up' ? 'active' : '' ?>">Picked Up</a>
        <a href="?page=delivery_tracking&status=out_for_delivery" class="<?= $statusFilter === 'out_for_delivery' ? 'active' : '' ?>">Out for Delivery</a>
        <a href="?page=delivery_tracking&status=pod_pending" class="<?= $statusFilter === 'pod_pending' ? 'active' : '' ?>">Awaiting Verification</a>
        <a href="?page=delivery_tracking&status=delivered" class="<?= $statusFilter === 'delivered' ? 'active' : '' ?>">Completed</a>
        <a href="?page=delivery_tracking&status=failed" class="<?= $statusFilter === 'failed' ? 'active' : '' ?>">Failed</a>
        <a href="?page=delivery_tracking&status=returned" class="<?= $statusFilter === 'returned' ? 'active' : '' ?>">Returned</a>
    </div>

    <div class="del-card">
        <div class="del-filter-box">
            <form method="get" autocomplete="off">
                <input type="hidden" name="page" value="delivery_tracking">
                <div class="del-filter-grid">
                    <div class="del-filter-field del-filter-search">
                        <label>Order # / Customer</label>
                        <input type="text" name="search" placeholder="Search by order # or customer name" value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="del-filter-field">
                        <label>Status</label>
                        <select name="status">
                            <option value="">All Statuses</option>
                            <option value="assigned"<?= $statusFilter === 'assigned' ? ' selected' : '' ?>>Assigned</option>
                            <option value="accepted"<?= $statusFilter === 'accepted' ? ' selected' : '' ?>>Accepted</option>
                            <option value="picked_up"<?= $statusFilter === 'picked_up' ? ' selected' : '' ?>>Picked Up</option>
                            <option value="out_for_delivery"<?= $statusFilter === 'out_for_delivery' ? ' selected' : '' ?>>Out for Delivery</option>
                            <option value="pod_pending"<?= $statusFilter === 'pod_pending' ? ' selected' : '' ?>>Awaiting Verification</option>
                            <option value="delivered"<?= $statusFilter === 'delivered' ? ' selected' : '' ?>>Delivered</option>
                            <option value="failed"<?= $statusFilter === 'failed' ? ' selected' : '' ?>>Failed</option>
                            <option value="returned"<?= $statusFilter === 'returned' ? ' selected' : '' ?>>Returned</option>
                        </select>
                    </div>
                    <div class="del-filter-field">
                        <label>Driver</label>
                        <select name="driver">
                            <option value="">All Drivers</option>
                            <?php foreach ($drivers as $d): ?>
                                <option value="<?= (int) $d['id'] ?>"<?= $driverFilter === (int) $d['id'] ? ' selected' : '' ?>><?= htmlspecialchars($d['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="del-filter-field">
                        <label>From Date</label>
                        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                    </div>
                    <div class="del-filter-field">
                        <label>To Date</label>
                        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
                    </div>
                </div>
                <div class="del-filter-actions">
                    <button type="submit" class="del-btn del-btn-blue"><i class="fas fa-search"></i> Filter</button>
                    <a href="?page=delivery_tracking" class="del-btn del-btn-outline"><i class="fas fa-times"></i> Clear</a>
                </div>
            </form>
        </div>

        <?php if (empty($orders)): ?>
            <div class="del-empty"><i class="fas fa-route"></i><p>No deliveries found matching your filters.</p></div>
        <?php else: ?>
            <div style="overflow-x:auto">
                <table class="del-table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Customer</th>
                            <th>Driver</th>
                            <th>Status</th>
                            <th>Assigned</th>
                            <th>Last Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $o): ?>
                            <tr>
                                <td><strong>#<?= (int) $o['id'] ?></strong></td>
                                <td>
                                    <?= htmlspecialchars($o['customer_name'] ?? '') ?>
                                    <?php if (!empty($o['customer_phone'])): ?>
                                        <br><small style="color:var(--del-gray)"><?= htmlspecialchars($o['customer_phone']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($o['staff_name'] ?? $o['driver_name'] ?? '—') ?></td>
                                <td><?= delBadgeHtml($o['delivery_status'] ?? '') ?></td>
                                <td style="font-size:.78rem;white-space:nowrap"><?= htmlspecialchars($o['assigned_at'] ?? '—') ?></td>
                                <td style="font-size:.78rem;white-space:nowrap">
                                    <?php
                                    $lastUpdate = $o['pod_verified_at'] ?? $o['delivered_at'] ?? $o['pod_recorded_at'] ?? $o['dispatch_time'] ?? $o['picked_up_at'] ?? $o['accepted_at'] ?? $o['assigned_at'] ?? '—';
                                    echo htmlspecialchars($lastUpdate);
                                    ?>
                                </td>
                                <td>
                                    <a href="?page=delivery_tracking&view=<?= (int) $o['id'] ?>" class="del-btn del-btn-sm del-btn-outline" title="View Timeline">
                                        <i class="fas fa-eye"></i> Timeline
                                    </a>
                                    <?php $st = $o['delivery_status'] ?? ''; ?>
                                    <?php if (hasPermission('delivery_tracking', 'edit')): ?>
                                    <?php if ($st === 'assigned' || $st === 'accepted' || $st === 'picked_up'): ?>
                                        <form method="post" style="display:inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="mark_out_for_delivery">
                                            <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                                            <button type="submit" class="del-btn del-btn-sm del-btn-blue" title="Dispatch this order"><i class="fas fa-truck"></i> Dispatch</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($st === 'assigned' || $st === 'accepted'): ?>
                                        <button class="del-btn del-btn-sm del-btn-blue" title="Mark picked up" onclick="showStatusModal(<?= (int) $o['id'] ?>,'picked_up')"><i class="fas fa-box"></i> Picked Up</button>
                                    <?php endif; ?>
                                    <?php if ($st === 'out_for_delivery'): ?>
                                        <button class="del-btn del-btn-sm del-btn-green" title="Record proof of delivery" onclick="showPodModal(<?= (int) $o['id'] ?>)"><i class="fas fa-clipboard-check"></i> Record POD</button>
                                        <button class="del-btn del-btn-sm del-btn-red" title="Mark failed" onclick="showFailedModal(<?= (int) $o['id'] ?>)"><i class="fas fa-times-circle"></i> Failed</button>
                                    <?php endif; ?>
                                    <?php if ($st === 'pod_pending'): ?>
                                        <button class="del-btn del-btn-sm del-btn-green" title="Verify POD and mark delivered" onclick="showVerifyModal(<?= (int) $o['id'] ?>)"><i class="fas fa-check-circle"></i> Verify</button>
                                        <button class="del-btn del-btn-sm del-btn-red" title="Mark failed" onclick="showFailedModal(<?= (int) $o['id'] ?>)"><i class="fas fa-times-circle"></i> Failed</button>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="del-modal-overlay" id="statusModal">
    <div class="del-modal">
        <h3 id="statusModalTitle">Update Status</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="order_id" id="statusModalOrder">
            <input type="hidden" name="action" id="statusModalAction">
            <p style="color:var(--del-gray);font-size:.85rem;margin:0 0 14px" id="statusModalText">Are you sure?</p>
            <div style="display:flex;gap:10px;justify-content:flex-end">
                <button type="button" class="del-btn del-btn-outline" onclick="document.getElementById('statusModal').classList.remove('show')">Cancel</button>
                <button type="submit" class="del-btn del-btn-blue">Confirm</button>
            </div>
        </form>
    </div>
</div>

<div class="del-modal-overlay" id="podModal">
    <div class="del-modal">
        <h3><i class="fas fa-clipboard-check" style="color:var(--del-green)"></i> Record Proof of Delivery</h3>
        <form method="post" enctype="multipart/form-data" onsubmit="return capturePodSig()">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="record_pod">
            <input type="hidden" name="order_id" id="podModalOrder">
            <input type="hidden" name="receiver_signature" id="pod-sig-data">
            <div class="del-form-group">
                <label>Receiver Name <span style="color:var(--del-red)">*</span></label>
                <input type="text" name="receiver_name" required placeholder="Who received the order" style="box-sizing:border-box">
            </div>
            <div class="del-form-group">
                <label>Receiver Phone <span style="color:var(--del-red)">*</span></label>
                <input type="text" name="receiver_phone" required placeholder="Receiver contact number" style="box-sizing:border-box">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <div class="del-form-group">
                    <label>Delivery Date</label>
                    <input type="date" name="delivery_date" value="<?= date('Y-m-d') ?>" style="box-sizing:border-box">
                </div>
                <div class="del-form-group">
                    <label>Delivery Time</label>
                    <input type="time" name="delivery_time" value="<?= date('H:i') ?>" style="box-sizing:border-box">
                </div>
            </div>
            <div class="del-form-group">
                <label>Customer Signature <span style="color:var(--del-red)">*</span></label>
                <canvas id="pod-sig-canvas" width="500" height="140" style="width:100%;max-width:500px;border:2px solid var(--del-green);border-radius:8px;background:#fff"></canvas>
                <div style="margin-top:6px">
                    <button type="button" class="del-btn del-btn-sm del-btn-outline" onclick="clearPodSig()"><i class="fas fa-times"></i> Clear Signature</button>
                </div>
            </div>
            <div class="del-form-group">
                <label>Delivery Notes</label>
                <textarea name="delivery_notes" rows="2" placeholder="Optional notes" style="box-sizing:border-box"></textarea>
            </div>
            <div class="del-form-group">
                <label>POD Photo (optional)</label>
                <input type="file" name="delivery_photo" accept="image/jpeg,image/png,image/webp,image/gif" style="box-sizing:border-box">
                <small style="color:var(--del-gray)">JPG, PNG, WebP or GIF — max 2MB</small>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end">
                <button type="button" class="del-btn del-btn-outline" onclick="document.getElementById('podModal').classList.remove('show')">Cancel</button>
                <button type="submit" class="del-btn del-btn-green">Record POD</button>
            </div>
        </form>
    </div>
</div>

<div class="del-modal-overlay" id="verifyModal">
    <div class="del-modal">
        <h3><i class="fas fa-check-circle" style="color:var(--del-green)"></i> Verify Delivery</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="verify_delivery">
            <input type="hidden" name="order_id" id="verifyModalOrder">
            <p style="color:var(--del-gray);font-size:.85rem;margin:0 0 14px">The customer has confirmed receipt and the POD has been recorded. Verify the delivery to mark it as <strong>Delivered</strong> and complete the invoice.</p>
            <div style="display:flex;gap:10px;justify-content:flex-end">
                <button type="button" class="del-btn del-btn-outline" onclick="document.getElementById('verifyModal').classList.remove('show')">Cancel</button>
                <button type="submit" class="del-btn del-btn-green">Verify & Complete</button>
            </div>
        </form>
    </div>
</div>

<div class="del-modal-overlay" id="failedModal">
    <div class="del-modal">
        <h3>Mark as Failed</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="mark_failed">
            <input type="hidden" name="order_id" id="failedModalOrder">
            <div style="margin-bottom:10px">
                <label style="font-size:.78rem;font-weight:600;color:var(--del-gray)">Reason</label>
                <select name="failed_reason" required style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;box-sizing:border-box">
                    <option value="">Select reason...</option>
                    <option value="customer_not_available">Customer not available</option>
                    <option value="wrong_address">Wrong address</option>
                    <option value="no_contact">No contact</option>
                    <option value="damaged_goods">Damaged goods</option>
                    <option value="vehicle_issue">Vehicle issue</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div style="margin-bottom:14px">
                <label style="font-size:.78rem;font-weight:600;color:var(--del-gray)">Notes</label>
                <textarea name="failed_notes" rows="2" placeholder="Optional details" style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;box-sizing:border-box"></textarea>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end">
                <button type="button" class="del-btn del-btn-outline" onclick="document.getElementById('failedModal').classList.remove('show')">Cancel</button>
                <button type="submit" class="del-btn del-btn-red">Mark Failed</button>
            </div>
        </form>
    </div>
</div>

<script>
function showStatusModal(orderId, action) {
    delCloseAllModals();
    document.getElementById('statusModalOrder').value = orderId;
    document.getElementById('statusModalAction').value = 'mark_' + action;
    document.getElementById('statusModalTitle').textContent = 'Mark as ' + action.replace(/_/g, ' ');
    document.getElementById('statusModalText').textContent = 'Update order #' + orderId + ' to "' + action.replace(/_/g, ' ') + '"?';
    document.getElementById('statusModal').classList.add('show');
}
function showPodModal(orderId) {
    delCloseAllModals();
    document.getElementById('podModalOrder').value = orderId;
    clearPodSig();
    document.getElementById('podModal').classList.add('show');
}
function showVerifyModal(orderId) {
    delCloseAllModals();
    document.getElementById('verifyModalOrder').value = orderId;
    document.getElementById('verifyModal').classList.add('show');
}
function showFailedModal(orderId) {
    delCloseAllModals();
    document.getElementById('failedModalOrder').value = orderId;
    document.getElementById('failedModal').classList.add('show');
}

// POD signature pad
(function() {
    var canvas = document.getElementById('pod-sig-canvas');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var drawing = false, lastX, lastY;
    function getPos(e) { var r = canvas.getBoundingClientRect(); var t = e.touches ? e.touches[0] : e; return { x: (t.clientX - r.left) * (canvas.width / r.width), y: (t.clientY - r.top) * (canvas.height / r.height) }; }
    canvas.addEventListener('mousedown', function(e) { drawing = true; var p = getPos(e); lastX = p.x; lastY = p.y; });
    canvas.addEventListener('mousemove', function(e) { if (!drawing) return; var p = getPos(e); ctx.beginPath(); ctx.moveTo(lastX, lastY); ctx.lineTo(p.x, p.y); ctx.strokeStyle = '#1a1a1a'; ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.stroke(); lastX = p.x; lastY = p.y; });
    canvas.addEventListener('mouseup', function() { drawing = false; });
    canvas.addEventListener('mouseleave', function() { drawing = false; });
    canvas.addEventListener('touchstart', function(e) { e.preventDefault(); drawing = true; var p = getPos(e); lastX = p.x; lastY = p.y; }, { passive: false });
    canvas.addEventListener('touchmove', function(e) { e.preventDefault(); if (!drawing) return; var p = getPos(e); ctx.beginPath(); ctx.moveTo(lastX, lastY); ctx.lineTo(p.x, p.y); ctx.strokeStyle = '#1a1a1a'; ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.stroke(); lastX = p.x; lastY = p.y; }, { passive: false });
    canvas.addEventListener('touchend', function() { drawing = false; });
    window.clearPodSig = function() { ctx.clearRect(0, 0, canvas.width, canvas.height); };
    window.capturePodSig = function() {
        var data = canvas.toDataURL('image/png');
        var blank = document.createElement('canvas');
        blank.width = canvas.width; blank.height = canvas.height;
        if (canvas.toDataURL() === blank.toDataURL()) { alert('Please capture the customer signature before recording POD.'); return false; }
        document.getElementById('pod-sig-data').value = data;
        return true;
    };
})();
</script>
<?= delJs() ?>
