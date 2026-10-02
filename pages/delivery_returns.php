<?php
require_once __DIR__ . '/../includes/delivery_helpers.php';
require_once __DIR__ . '/../includes/workflow_engine.php';
requireDeliveryOperator();
$pdo = delGetDb();
ensureDeliveryErpMigration($pdo);

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_csrf();
    $action = $_POST['action'];
    $adminId = (int) $_SESSION['user_id'];

    if ($action === 'approve_return') {
        $returnId = (int) ($_POST['return_id'] ?? 0);
        if ($returnId > 0) {
            $stmt = $pdo->prepare("UPDATE delivery_returns SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ? AND status = 'pending'");
            $stmt->execute([$adminId, $returnId]);
            if ($stmt->rowCount() > 0) {
                $ret = $pdo->prepare("SELECT dr.* FROM delivery_returns dr WHERE dr.id = ?");
                $ret->execute([$returnId]);
                $retData = $ret->fetch();
                if ($retData) {
                    delLog($pdo, (int) $retData['order_id'], 'return_approved', 'returned', 'returned', $adminId, 'Return approved: ' . ($retData['reason'] ?? ''));
                }
                $success = 'Return request approved.';
                redirect_with_flash('?page=delivery_returns', 'Return request approved.');
            } else {
                $error = 'Could not approve return (already processed).';
            }
        }
    } elseif ($action === 'reject_return') {
        $returnId = (int) ($_POST['return_id'] ?? 0);
        if ($returnId > 0) {
            $stmt = $pdo->prepare("UPDATE delivery_returns SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ? AND status = 'pending'");
            $stmt->execute([$adminId, $returnId]);
            if ($stmt->rowCount() > 0) {
                $ret = $pdo->prepare("SELECT dr.* FROM delivery_returns dr WHERE dr.id = ?");
                $ret->execute([$returnId]);
                $retData = $ret->fetch();
                if ($retData) {
                    delLog($pdo, (int) $retData['order_id'], 'return_rejected', 'returned', 'delivered', $adminId, 'Return rejected');
                }
                $success = 'Return request rejected.';
                redirect_with_flash('?page=delivery_returns', 'Return request rejected.');
            } else {
                $error = 'Could not reject return.';
            }
        }
    } elseif ($action === 'create_return') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $returnType = $_POST['return_type'] ?? 'full';
        $reason = trim($_POST['reason'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($orderId <= 0 || $reason === '') {
            $error = 'Please select an order and provide a reason.';
        } else {
            $check = $pdo->prepare("SELECT id, delivery_status, delivery_staff_id FROM customer_orders WHERE id = ?");
            $check->execute([$orderId]);
            $order = $check->fetch();
            if (!$order) {
                $error = 'Order not found.';
            } elseif (!WorkflowEngine::canTransition('delivery', $order['delivery_status'], 'returned')) {
                $error = 'Order is not delivered or cannot be returned from its current status.';
            } else {
                $ins = $pdo->prepare("INSERT INTO delivery_returns (order_id, return_type, reason, notes, requested_by, status) VALUES (?, ?, ?, ?, ?, 'pending')");
                $ins->execute([$orderId, $returnType, $reason, $notes, $adminId]);
                $res = WorkflowEngine::transition($pdo, 'delivery', $orderId, 'returned', [
                    'extra' => [
                        'return_reason' => $reason,
                        'return_notes' => $notes !== '' ? $notes : null,
                        'return_requested_at' => date('Y-m-d H:i:s'),
                        'return_approved_by' => $adminId,
                    ],
                    'changed_by' => $adminId,
                    'notes' => 'Return reason: ' . $reason,
                ]);
                if (!$res['success']) {
                    $error = $res['error'];
                } else {
                    delSyncDriverAvailability($pdo, (int) ($order['delivery_staff_id'] ?? 0));
                    delLog($pdo, $orderId, 'return_requested', 'delivered', 'returned', $adminId, 'Return reason: ' . $reason);
                    $success = "Return request created for Order #{$orderId}.";
                }
            }
        }
    }
}

$pendingReturns = $pdo->query("
    SELECT dr.*, co.id AS order_id, c.name AS customer_name, u.full_name AS requested_by_name
    FROM delivery_returns dr
    JOIN customer_orders co ON dr.order_id = co.id
    LEFT JOIN customers c ON co.customer_id = c.id
    LEFT JOIN users u ON dr.requested_by = u.id
    WHERE dr.status = 'pending'
    ORDER BY dr.created_at DESC
")->fetchAll();

$returnHistory = $pdo->query("
    SELECT dr.*, co.id AS order_id, c.name AS customer_name,
           u1.full_name AS requested_by_name, u2.full_name AS approved_by_name
    FROM delivery_returns dr
    JOIN customer_orders co ON dr.order_id = co.id
    LEFT JOIN customers c ON co.customer_id = c.id
    LEFT JOIN users u1 ON dr.requested_by = u1.id
    LEFT JOIN users u2 ON dr.approved_by = u2.id
    WHERE dr.status IN ('approved','rejected','completed')
    ORDER BY dr.created_at DESC
")->fetchAll();

$deliveredOrders = $pdo->query("
    SELECT co.id, co.order_date, c.name AS customer_name
    FROM customer_orders co
    LEFT JOIN customers c ON co.customer_id = c.id
    WHERE co.delivery_status = 'delivered'
    ORDER BY co.id DESC
    LIMIT 200
")->fetchAll();

$pendingCount = count($pendingReturns);
$historyCount = count($returnHistory);
$approvedCount = $pdo->query("SELECT COUNT(*) FROM delivery_returns WHERE status = 'approved'")->fetchColumn();
$completedCount = $pdo->query("SELECT COUNT(*) FROM delivery_returns WHERE status = 'completed'")->fetchColumn();
$rejectedCount = $pdo->query("SELECT COUNT(*) FROM delivery_returns WHERE status = 'rejected'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Returns — NARED PHARMACON</title>
    <?= delCss() ?>
</head>
<body>
<div class="del-wrap" style="padding:20px">
    <h1><i class="fas fa-undo" style="color:var(--del-purple)"></i> Delivery Returns</h1>
    <p class="del-subtitle">Manage product returns from customers</p>

    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="del-stats">
        <div class="del-stat">
            <div class="del-stat-icon" style="background:linear-gradient(135deg,#d97706,#b45309)"><i class="fas fa-clock"></i></div>
            <div class="del-stat-body">
                <div class="del-stat-val" data-counter="<?= $pendingCount ?>"><?= $pendingCount ?></div>
                <div class="del-stat-label">Pending Returns</div>
            </div>
        </div>
        <div class="del-stat">
            <div class="del-stat-icon" style="background:linear-gradient(135deg,#059669,#047857)"><i class="fas fa-check"></i></div>
            <div class="del-stat-body">
                <div class="del-stat-val" data-counter="<?= $approvedCount ?>"><?= $approvedCount ?></div>
                <div class="del-stat-label">Approved</div>
            </div>
        </div>
        <div class="del-stat">
            <div class="del-stat-icon" style="background:linear-gradient(135deg,#2563eb,#1d4ed8)"><i class="fas fa-box-open"></i></div>
            <div class="del-stat-body">
                <div class="del-stat-val" data-counter="<?= $completedCount ?>"><?= $completedCount ?></div>
                <div class="del-stat-label">Completed</div>
            </div>
        </div>
        <div class="del-stat">
            <div class="del-stat-icon" style="background:linear-gradient(135deg,#dc2626,#b91c1c)"><i class="fas fa-times"></i></div>
            <div class="del-stat-body">
                <div class="del-stat-val" data-counter="<?= $rejectedCount ?>"><?= $rejectedCount ?></div>
                <div class="del-stat-label">Rejected</div>
            </div>
        </div>
    </div>

    <div class="del-tabs" id="return-tabs">
        <button class="del-tab active" onclick="delSwitchTab(this,'panel-pending')"><i class="fas fa-clock"></i> Pending Returns (<?= $pendingCount ?>)</button>
        <button class="del-tab" onclick="delSwitchTab(this,'panel-history')"><i class="fas fa-history"></i> Return History (<?= $historyCount ?>)</button>
        <button class="del-tab" onclick="delSwitchTab(this,'panel-new')"><i class="fas fa-plus-circle"></i> New Return Request</button>
    </div>

    <div class="del-panel active" id="panel-pending">
        <div class="del-card">
            <h2><i class="fas fa-clock"></i> Pending Return Requests</h2>
            <?php if (empty($pendingReturns)): ?>
                <div class="del-empty"><i class="fas fa-check-circle"></i><p>No pending return requests.</p></div>
            <?php else: ?>
                <table class="del-table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Customer</th>
                            <th>Type</th>
                            <th>Reason</th>
                            <th>Requested By</th>
                            <th>Requested At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingReturns as $r): ?>
                            <tr>
                                <td><strong>#<?= (int) $r['order_id'] ?></strong></td>
                                <td><?= htmlspecialchars($r['customer_name'] ?? '') ?></td>
                                <td><?= delBadgeHtml($r['return_type'] === 'full' ? 'assigned' : 'picked_up') ?> <?= ucfirst(htmlspecialchars($r['return_type'])) ?></td>
                                <td><?= htmlspecialchars($r['reason']) ?></td>
                                <td><?= htmlspecialchars($r['requested_by_name'] ?? '—') ?></td>
                                <td style="font-size:.78rem"><?= htmlspecialchars($r['created_at']) ?></td>
                                <td style="white-space:nowrap">
                                    <form method="post" style="display:inline" onsubmit="return confirm('Approve this return?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="approve_return">
                                        <input type="hidden" name="return_id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="del-btn del-btn-sm del-btn-green"><i class="fas fa-check"></i> Approve</button>
                                    </form>
                                    <form method="post" style="display:inline" onsubmit="return confirm('Reject this return?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reject_return">
                                        <input type="hidden" name="return_id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="del-btn del-btn-sm del-btn-red"><i class="fas fa-times"></i> Reject</button>
                                    </form>
                                </td>
                            </tr>
                            <?php if (!empty($r['notes'])): ?>
                                <tr style="background:#f8fafc"><td colspan="7" style="font-size:.8rem;color:var(--del-gray)"><i class="fas fa-sticky-note"></i> <?= htmlspecialchars($r['notes']) ?></td></tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="del-panel" id="panel-history">
        <div class="del-card">
            <h2><i class="fas fa-history"></i> Return History</h2>
            <?php if (empty($returnHistory)): ?>
                <div class="del-empty"><i class="fas fa-history"></i><p>No return history yet.</p></div>
            <?php else: ?>
                <table class="del-table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Customer</th>
                            <th>Type</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Requested By</th>
                            <th>Approved By</th>
                            <th>Approved At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($returnHistory as $r): ?>
                            <tr>
                                <td><strong>#<?= (int) $r['order_id'] ?></strong></td>
                                <td><?= htmlspecialchars($r['customer_name'] ?? '') ?></td>
                                <td><?= ucfirst(htmlspecialchars($r['return_type'])) ?></td>
                                <td><?= htmlspecialchars($r['reason']) ?></td>
                                <td><?= delBadgeHtml($r['status'] === 'approved' ? 'available' : ($r['status'] === 'rejected' ? 'offline' : ($r['status'] === 'completed' ? 'delivered' : 'pending'))) ?>
                                    <?= ucfirst(htmlspecialchars($r['status'])) ?>
                                </td>
                                <td><?= htmlspecialchars($r['requested_by_name'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($r['approved_by_name'] ?? '—') ?></td>
                                <td style="font-size:.78rem"><?= htmlspecialchars($r['approved_at'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="del-panel" id="panel-new">
        <div class="del-card">
            <h2><i class="fas fa-plus-circle"></i> Create Return Request</h2>
            <form method="post" style="max-width:600px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_return">
                <div class="del-form-group">
                    <label>Select Delivered Order *</label>
                    <select name="order_id" required style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem">
                        <option value="">— Select Order —</option>
                        <?php foreach ($deliveredOrders as $do): ?>
                            <option value="<?= (int) $do['id'] ?>">#<?= (int) $do['id'] ?> — <?= htmlspecialchars($do['customer_name'] ?? '') ?> (<?= htmlspecialchars($do['order_date']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="del-form-group">
                    <label>Return Type *</label>
                    <select name="return_type" required style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem">
                        <option value="full">Full Return</option>
                        <option value="partial">Partial Return</option>
                    </select>
                </div>
                <div class="del-form-group">
                    <label>Reason *</label>
                    <input type="text" name="reason" required placeholder="e.g., Damaged product, Wrong item delivered..." style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem">
                </div>
                <div class="del-form-group">
                    <label>Notes</label>
                    <textarea name="notes" rows="3" placeholder="Additional details..." style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;resize:vertical"></textarea>
                </div>
                <button type="submit" class="del-btn del-btn-purple"><i class="fas fa-paper-plane"></i> Submit Return Request</button>
            </form>
        </div>
    </div>
</div>
<?= delJs() ?>
</body>
</html>
