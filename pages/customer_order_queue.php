<?php
require_once __DIR__ . '/../services/init.php';
requirePermission('customer_order_queue', 'view');
$pdo = getPDO();
$uid = (int) currentUser()['id'];
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $orderId = (int) ($_POST['customer_order_id'] ?? 0);

    // Return to the pending list preserving any active filters (Post/Redirect/Get).
    $returnUrl = '?page=customer_order_queue';
    $ql = [];
    foreach (['status' => trim($_GET['status'] ?? ''), 'customer' => trim($_GET['customer'] ?? ''), 'payment_status' => trim($_GET['payment_status'] ?? ''), 'delivery' => trim($_GET['delivery'] ?? '')] as $k => $v) {
        if ($v !== '') { $ql[] = $k . '=' . rawurlencode($v); }
    }
    if (!empty($ql)) { $returnUrl .= '&' . implode('&', $ql); }

    if ($orderId <= 0) {
        $messages[] = ['type' => 'error', 'text' => 'Invalid order.'];
    } elseif ($action === 'reject') {
        requirePermission('customer_order_queue', 'approve');
        try {
            WorkflowService::rejectOrder($pdo, $orderId, $uid, trim($_POST['admin_note'] ?? ''));
            redirect_with_flash($returnUrl, 'Order rejected.');
        } catch (ServiceException $e) {
            $messages[] = ['type' => 'error', 'text' => $e->getMessage()];
        }
    } elseif ($action === 'cancel_refund') {
        requirePermission('customer_order_queue', 'cancel_refund');
        try {
            WorkflowService::cancelOrderForRefund($pdo, $orderId, $uid, trim($_POST['cancel_reason'] ?? ''));
            redirect_with_flash($returnUrl, "Order #{$orderId} cancelled. Finance officer can now process the refund.");
        } catch (ServiceException $e) {
            $messages[] = ['type' => 'error', 'text' => $e->getMessage()];
        }
    } elseif ($action === 'assign_delivery') {
        requirePermission('customer_order_queue', 'assign_delivery');
        try {
            WorkflowService::assignDelivery($pdo, $orderId, (int) ($_POST['delivery_staff_id'] ?? 0), $uid);
            redirect_with_flash($returnUrl, "Delivery staff assigned to Order #{$orderId}.");
        } catch (ServiceException $e) {
            $messages[] = ['type' => 'error', 'text' => $e->getMessage()];
        }
    } elseif ($action === 'mark_out_for_delivery') {
        requirePermission('customer_order_queue', 'assign_delivery');
        try {
            WorkflowService::markOutForDelivery($pdo, $orderId, $uid);
            redirect_with_flash($returnUrl, "Order #{$orderId} marked as out for delivery.");
        } catch (ServiceException $e) {
            $messages[] = ['type' => 'error', 'text' => $e->getMessage()];
        }
    } elseif ($action === 'approve') {
        requirePermission('customer_order_queue', 'approve');
        try {
            $stageCheck = $pdo->prepare('SELECT approval_stage FROM customer_orders WHERE id = :id');
            $stageCheck->execute(['id' => $orderId]);
            $stage = $stageCheck->fetchColumn();
            if ($stage !== null && $stage !== false) {
                if ($stage !== 'admin') {
                    throw new ServiceException('Only the Store Manager can approve the next stage of this order.');
                }
                $adv = WorkflowService::approveSalespersonStage($pdo, $orderId, $uid, 'admin');
                redirect_with_flash($returnUrl, 'Approval stage advanced to ' . strtoupper($adv['next_stage']) . '. The Store Manager must now approve on the Fulfillment page.');
            } else {
                $res = WorkflowService::approveOrder($pdo, $orderId, $uid, !empty($_POST['override_credit']));
                redirect_with_flash($returnUrl, 'Order approved; invoice ' . htmlspecialchars($res['invoice_no']) . ' generated.');
            }
        } catch (ServiceException $e) {
            $messages[] = ['type' => 'error', 'text' => $e->getMessage()];
        }
    }
}

$filterStatus = trim($_GET['status'] ?? '');
$filterCust = trim($_GET['customer'] ?? '');
$filterPayStatus = trim($_GET['payment_status'] ?? '');
$filterDelivery = trim($_GET['delivery'] ?? '');
$where = [];
$params = [];
if ($filterStatus !== '') {
    $where[] = 'co.status = :status';
    $params['status'] = $filterStatus;
}
if ($filterCust !== '') {
    $where[] = 'c.name LIKE :customer';
    $params['customer'] = "%$filterCust%";
}
if ($filterPayStatus === 'confirmed') {
    $where[] = 'co.payment_confirmed = 1';
} elseif ($filterPayStatus === 'pending') {
    $where[] = 'co.payment_confirmed = 0';
}
if ($filterDelivery === 'pending') {
    $where[] = 'co.delivery_status IS NULL';
} elseif ($filterDelivery !== '') {
    $where[] = 'co.delivery_status = :delivery';
    $params['delivery'] = $filterDelivery;
}
$sql = "SELECT co.*, c.name AS customer_name, u.username AS placed_by
    FROM customer_orders co
    LEFT JOIN customers c ON co.customer_id = c.id
    LEFT JOIN users u ON co.placed_by_user_id = u.id";
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= " ORDER BY co.status = 'pending' DESC, co.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$list = $stmt->fetchAll();

// Fetch delivery staff list
$deliveryStaff = $pdo->query("SELECT id, full_name FROM driver_profiles WHERE status = 'active' ORDER BY full_name ASC")->fetchAll();

// Pre-load all line items grouped by order ID
$itemsByOrder = [];
$iStmt = $pdo->query('SELECT coi.customer_order_id, coi.quantity, coi.unit_price, p.name AS product_name FROM customer_order_items coi JOIN products p ON coi.product_id = p.id ORDER BY coi.id');
foreach ($iStmt->fetchAll() as $it) {
    $itemsByOrder[(int)$it['customer_order_id']][] = $it;
}
?>
<section>
    <h1>Customer order queue</h1>
    <p class="hint">Review requests from customer accounts. Approving creates a sale and reduces inventory (same as Sales). Rejecting leaves stock unchanged.</p>
    <?php foreach ($messages as $message): ?>
        <div class="alert alert-<?= htmlspecialchars($message['type']) ?>"><?= htmlspecialchars($message['text']) ?></div>
    <?php endforeach; ?>

    <div class="panel">
        <h2>All customer orders</h2>
        <form method="get" style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap">
            <input type="hidden" name="page" value="customer_order_queue">
            <select name="status" style="flex:1;min-width:120px;margin:0">
                <option value="">All statuses</option>
                <option value="pending"<?= $filterStatus === 'pending' ? ' selected' : '' ?>>Pending</option>
                <option value="approved"<?= $filterStatus === 'approved' ? ' selected' : '' ?>>Approved</option>
                <option value="rejected"<?= $filterStatus === 'rejected' ? ' selected' : '' ?>>Rejected</option>
            </select>
            <input type="text" name="customer" value="<?= htmlspecialchars($filterCust) ?>" placeholder="Customer name..." style="flex:1;min-width:120px;margin:0">
            <button type="submit" style="margin:0">Filter</button>
                        <select name="payment_status" style="flex:1;min-width:120px;margin:0">
                <option value="">Any payment</option>
                <option value="confirmed"<?= ($_GET['payment_status'] ?? '') === 'confirmed' ? ' selected' : '' ?>>Payment confirmed</option>
                <option value="pending"<?= ($_GET['payment_status'] ?? '') === 'pending' ? ' selected' : '' ?>>Payment pending</option>
            </select>
            <select name="delivery" style="flex:1;min-width:120px;margin:0">
                <option value="">Any delivery</option>
                <option value="pending"<?= $filterDelivery === 'pending' ? ' selected' : '' ?>>Not assigned</option>
                <option value="assigned"<?= $filterDelivery === 'assigned' ? ' selected' : '' ?>>Assigned</option>
                <option value="out_for_delivery"<?= $filterDelivery === 'out_for_delivery' ? ' selected' : '' ?>>Out for delivery</option>
                <option value="delivered"<?= $filterDelivery === 'delivered' ? ' selected' : '' ?>>Delivered</option>
            </select>
<?php if ($filterStatus !== '' || $filterCust !== '' || !empty($_GET['payment_status']) || $filterDelivery !== ''): ?><a href="?page=customer_order_queue" style="display:inline-flex;align-items:center;padding:0 12px;color:var(--brown-800);font-weight:600">Clear</a><?php endif; ?>
        </form>
        <div style="overflow-x:auto">
        <table>
            <thead><tr><th>ID</th><th>Date</th><th>Customer</th><th>Total</th><th>Pay</th><th>Status</th><th>Payment</th><th>Fulfillment</th><th>Delivery</th><th></th></tr></thead>
            <tbody>
                <?php if (empty($list)): ?>
                    <tr><td colspan="10">No customer orders yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($list as $row): ?>
                        <?php $oid = (int)$row['id']; $items = $itemsByOrder[$oid] ?? []; ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $oid) ?></td>
                            <td><?= htmlspecialchars($row['order_date']) ?></td>
                            <td><?= htmlspecialchars($row['customer_name'] ?? '') ?></td>
                            <td><?= fmt((float) $row['total_amount']) ?></td>
                            <td><?= htmlspecialchars($row['payment_method'] ?? '') ?></td>
                            <td><span class="status-badge status-<?= htmlspecialchars($row['status']) ?>"><?= htmlspecialchars($row['status']) ?></span>
                                <?php if (($row['approval_stage'] ?? null) !== null): ?>
                                    <span class="status-badge status-<?= $row['approval_stage'] === 'admin' ? 'danger' : ($row['approval_stage'] === 'store' ? 'pending' : 'approved') ?>" style="font-size:0.7rem"><?= htmlspecialchars(ucfirst((string) $row['approval_stage'])) ?> stage</span>
                                <?php endif; ?>
                            </td>
                            <td><?php if ($row['payment_confirmed']): ?><span class="status-badge status-approved" style="font-size:0.7rem">Confirmed</span><?php else: ?><span class="status-badge status-pending" style="font-size:0.7rem">Pending</span><?php endif; ?></td>
                            <td style="font-size:0.78rem">
                                <?php if ($row['fulfillment_method'] === StatusCatalog::FULFILLMENT_METHOD_PICKUP): ?>
                                    <span class="status-badge status-pending" style="font-size:0.7rem">Pickup</span>
                                <?php elseif ($row['fulfillment_method'] === StatusCatalog::FULFILLMENT_METHOD_DELIVERY): ?>
                                    <span class="status-badge status-approved" style="font-size:0.7rem">Delivery</span>
                                <?php else: ?>
                                    <span class="cell-sub">—</span>
                                <?php endif; ?>
                                <?php if (!empty($row['fulfillment_status'])): ?>
                                    <div class="cell-sub" style="margin-top:2px"><?= htmlspecialchars(str_replace('_', ' ', ucfirst($row['fulfillment_status']))) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.78rem">
                                <?php if ($row['delivery_status']): ?>
                                    <span class="status-badge <?= $row['delivery_status'] === 'delivered' ? 'status-approved' : '' ?>" style="font-size:0.7rem;<?= $row['delivery_status'] === 'out_for_delivery' ? 'background:#f59e0b;color:#fff' : '' ?>"><?= htmlspecialchars(str_replace('_', ' ', ucfirst($row['delivery_status']))) ?></span>
                                <?php else: ?>
                                    <span class="cell-sub">—</span>
                                <?php endif; ?>
                            </td>
                            <td><button class="btn btn-secondary" style="padding:8px 16px;font-size:0.82rem;min-height:44px" onclick="toggleOrderDetail(<?= $oid ?>)">View</button></td>
                        </tr>
                        <tr id="order-detail-<?= $oid ?>" style="display:none">
                            <td colspan="9" style="padding:0;background:var(--neutral-50)">
                                <div style="padding:14px 18px">
                                    <table style="font-size:0.82rem;margin-bottom:10px;width:auto">
                                        <tbody>
                                            <tr><th style="text-align:left;padding-right:12px">Placed by</th><td><?= htmlspecialchars($row['placed_by'] ?? '') ?></td></tr>
                                            <tr><th style="text-align:left;padding-right:12px">Payment details</th><td><?= htmlspecialchars($row['payment_detail'] ?? '') ?>
                                                <?php $ss = (string)($row['payment_screenshot'] ?? ''); if ($ss !== ''): ?>
                                                    <a href="<?= htmlspecialchars($ss) ?>" target="_blank" style="display:inline-flex;align-items:center;gap:4px;margin-left:8px;color:var(--brown-700);font-weight:600;font-size:0.82rem"><i class="fas fa-image"></i> Receipt</a>
                                                <?php endif; ?>
                                            </td></tr>
                                            <tr><th style="text-align:left;padding-right:12px">Payment status</th><td><?php if ($row['payment_confirmed']): ?><span class="status-badge status-approved" style="font-size:0.72rem">Confirmed</span><?php else: ?><span class="status-badge status-pending" style="font-size:0.72rem">Pending</span><?php endif; ?></td></tr>
                                            <?php if (!empty($row['fulfillment_method'])): ?>
                                                <tr><th style="text-align:left;padding-right:12px">Fulfillment</th><td><?= $row['fulfillment_method'] === StatusCatalog::FULFILLMENT_METHOD_PICKUP ? 'Customer Pickup' : 'Delivery' ?><?php if (!empty($row['fulfillment_status'])): ?> <span class="status-badge status-pending" style="font-size:0.72rem"><?= htmlspecialchars(str_replace('_', ' ', ucfirst($row['fulfillment_status']))) ?></span><?php endif; ?></td></tr>
                                            <?php endif; ?>
                                            <?php if (!empty($row['sale_id'])): ?><tr><th style="text-align:left;padding-right:12px">Sale ID</th><td><?= htmlspecialchars((string)$row['sale_id']) ?></td></tr><?php endif; ?>
                                            <?php
                                            $invStmt = $pdo->prepare('SELECT id, invoice_no FROM invoices WHERE customer_order_id = :oid');
                                            $invStmt->execute(['oid' => $oid]);
                                            $invRow = $invStmt->fetch();
                                            if ($invRow): ?>
                                                <tr><th style="text-align:left;padding-right:12px">Invoice</th><td><a href="index.php?page=invoice&id=<?= (int)$invRow['id'] ?>" target="_blank" style="color:var(--brown-700);font-weight:600"><i class="fas fa-file-invoice"></i> <?= htmlspecialchars($invRow['invoice_no']) ?></a></td></tr>
                                            <?php endif; ?>
                                            <?php if (!empty($row['delivery_status'])): ?>
                                                <tr><th style="text-align:left;padding-right:12px">Delivery</th><td><span class="status-badge <?= $row['delivery_status'] === 'delivered' ? 'status-approved' : '' ?>" style="font-size:0.72rem"><?= htmlspecialchars(str_replace('_', ' ', ucfirst($row['delivery_status']))) ?></span><?php if (!empty($row['delivery_confirmed_at'])): ?> <span style="font-size:0.72rem;color:var(--neutral-500)">(confirmed <?= htmlspecialchars($row['delivery_confirmed_at']) ?>)</span><?php endif; ?></td></tr>
                                            <?php endif; ?>
                                            <?php if ($row['payment_confirmed'] && !empty($row['delivery_staff_id'])): ?>
                                                <?php $dsName = $pdo->prepare("SELECT full_name FROM driver_profiles WHERE id = :id"); $dsName->execute(['id' => (int)$row['delivery_staff_id']]); $dsRow = $dsName->fetch(); if ($dsRow): ?>
                                                <tr><th style="text-align:left;padding-right:12px">Delivery staff</th><td><?= htmlspecialchars($dsRow['full_name']) ?></td></tr>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if ($row['admin_note']): ?><tr><th style="text-align:left;padding-right:12px">Admin note</th><td><?= htmlspecialchars($row['admin_note']) ?></td></tr><?php endif; ?>
                                        </tbody>
                                    </table>
                                    <div style="overflow-x:auto">
                                    <table style="font-size:0.82rem;border-collapse:collapse;margin-bottom:10px">
                                        <thead><tr><th>Product</th><th>Qty</th><th>Unit price</th><th>Line total</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($items as $it): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($it['product_name']) ?></td>
                                                    <td><?= htmlspecialchars((string)$it['quantity']) ?></td>
                                                    <td><?= fmt((float)$it['unit_price']) ?></td>
                                                    <td><?= fmt((float)$it['unit_price'] * (int)$it['quantity']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    </div>
                                    <?php if ($row['status'] === 'pending'): ?>
                                        <div style="display:flex;gap:12px;flex-wrap:wrap;border-top:1px solid var(--neutral-300);padding-top:10px;align-items:center">
                                            <?php $rowStage = $row['approval_stage'] ?? null; ?>
                                            <?php if ($rowStage === 'admin' && hasPermission('customer_order_queue', 'approve')): ?>
                                                <form method="post" style="margin:0" onsubmit="return confirm('Advance this salesperson order to Store Manager approval?')">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="approve">
                                                    <input type="hidden" name="customer_order_id" value="<?= $oid ?>">
                                                    <button type="submit" style="padding:8px 16px;font-size:0.82rem;background:var(--brown-600);color:#fff;border:none;border-radius:999px;cursor:pointer;font-weight:700;min-height:44px">Approve (Store Manager)</button>
                                                </form>
                                            <?php elseif ($rowStage === 'store'): ?>
                                                <span style="font-size:0.78rem;color:var(--neutral-500)">Awaiting Store Manager approval on the Fulfillment page.</span>
                                            <?php elseif ($rowStage === 'finance'): ?>
                                                <span style="font-size:0.78rem;color:var(--neutral-500)">Awaiting Finance finalization.</span>
                                            <?php elseif (hasPermission('customer_order_queue', 'approve')): ?>
                                                <form method="post" style="margin:0">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="approve">
                                                    <input type="hidden" name="customer_order_id" value="<?= $oid ?>">
                                                    <?php if ($row['payment_method'] === 'credit' && creditCanOverride()): ?>
                                                    <label style="display:flex;align-items:center;gap:4px;font-size:0.78rem;margin-bottom:4px;cursor:pointer">
                                                        <input type="checkbox" name="override_credit" value="1">
                                                        <span style="color:var(--fin-orange)"><i class="fas fa-exclamation-triangle"></i> Override Credit Limit</span>
                                                    </label>
                                                    <?php endif; ?>
                                                    <button type="submit" style="padding:8px 16px;font-size:0.82rem;background:var(--brown-600);color:#fff;border:none;border-radius:999px;cursor:pointer;font-weight:700;min-height:44px">Approve</button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="post" style="margin:0;display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="reject">
                                                <input type="hidden" name="customer_order_id" value="<?= $oid ?>">
                                                <input type="text" name="admin_note" placeholder="Reason (optional)" style="font-size:0.82rem;padding:8px 10px;margin:0;width:200px;min-width:140px;min-height:44px">
                                                <button type="submit" style="padding:8px 16px;font-size:0.82rem;background:var(--red-600);color:#fff;border:none;border-radius:999px;cursor:pointer;font-weight:700;min-height:44px">Reject</button>
                                            </form>
                                        </div>
                                    <?php elseif ($row['status'] === 'approved' && $row['payment_confirmed'] && $row['delivery_status'] !== 'delivered' && in_array($row['fulfillment_status'], [StatusCatalog::FULFILLMENT_READY_FOR_DELIVERY, StatusCatalog::FULFILLMENT_COMPLETED], true)): ?>
                                        <div style="display:flex;gap:12px;flex-wrap:wrap;border-top:1px solid var(--neutral-300);padding-top:10px;align-items:center">
                                            <?php if (hasPermission('customer_order_queue', 'assign_delivery')): ?>
                                            <a href="index.php?page=delivery_assign" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;font-size:0.82rem;background:var(--green-600);color:#fff;border-radius:999px;text-decoration:none;font-weight:700;min-height:44px"><i class="fas fa-truck"></i> Open Delivery Dispatch</a>
                                            <?php endif; ?>
                                            <span style="font-size:0.78rem;color:var(--neutral-500)">Order is Ready for Delivery — assign a driver from the delivery module.</span>
                                        </div>
                                    <?php elseif ($row['status'] === 'approved' && $row['payment_confirmed'] && $row['fulfillment_status'] === StatusCatalog::FULFILLMENT_WAITING_CUSTOMER_PICKUP): ?>
                                        <div style="display:flex;gap:12px;flex-wrap:wrap;border-top:1px solid var(--neutral-300);padding-top:10px;align-items:center">
                                            <span style="font-size:0.82rem;color:var(--brown-700);font-weight:600"><i class="fas fa-store"></i> Order is waiting customer pickup — hand over at the store.</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</section>
<script>
function toggleOrderDetail(id) {
    var el = document.getElementById('order-detail-' + id);
    if (el) el.style.display = el.style.display === 'none' ? 'table-row' : 'none';
}
</script>
