<?php
require_once __DIR__ . '/../services/init.php';
require_once __DIR__ . '/../includes/finance_helpers.php';
ensureFinanceErpMigration(finGetDb());
$pdo = finGetDb();
requirePermission('finance_refunds', 'view');
require_once __DIR__ . '/../includes/credit_helpers.php';
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['_action'] ?? '';

    // Server-side permission enforcement (the UI hides buttons, but the
    // handler must also reject unauthorized POSTs directly).
    $refundPerm = [
        'process_refund' => 'confirm',
        'process_supplier_refund' => 'confirm',
        'approve_refund' => 'confirm',
        'reject_refund' => 'unconfirm',
    ];
    if (isset($refundPerm[$action]) && !hasPermission('finance_refunds', $refundPerm[$action])) {
        $messages[] = ['type' => 'error', 'text' => 'You do not have permission to perform this action.'];
    } else {

    if ($action === 'process_refund') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $amount = (float) ($_POST['refund_amount'] ?? 0);
        $reason = trim($_POST['refund_reason'] ?? '');
        if ($orderId <= 0 || $amount <= 0 || $reason === '') {
            $messages[] = ['type' => 'error', 'text' => 'All fields required.'];
        } else {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('SELECT total_amount, customer_id FROM customer_orders WHERE id = :id');
                $stmt->execute(['id' => $orderId]);
                $co = $stmt->fetch();
                if (!$co) throw new RuntimeException('Order not found.');
                if ($amount > (float) $co['total_amount']) throw new RuntimeException('Amount exceeds order total.');
                $uid = $_SESSION['user']['id'] ?? null;
                $receiptPath = '';
                if (!empty($_FILES['receipt']['tmp_name'])) {
                    $ext = pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION);
                    $receiptPath = 'uploads/refunds/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    @mkdir(__DIR__ . '/../uploads/refunds', 0777, true);
                    move_uploaded_file($_FILES['receipt']['tmp_name'], __DIR__ . '/../' . $receiptPath);
                }
                $pdo->prepare('INSERT INTO refunds (customer_order_id, amount, reason, refunded_at, refunded_by, status, refund_type, receipt_path) VALUES (:oid, :amt, :reason, NOW(), :uid, "completed", "customer", :rp)')->execute(['oid' => $orderId, 'amt' => $amount, 'reason' => $reason, 'uid' => $uid, 'rp' => $receiptPath]);
                // Auto-record bank transaction if bank account selected
                $bankAccId = (int) ($_POST['bank_account_id'] ?? 0);
                if ($bankAccId > 0) {
                    bankRecordTransaction($pdo, $bankAccId, 'refund', $amount, '', 'Customer refund - Order #' . $orderId . ': ' . $reason, (int)$co['customer_id'], null, $uid);
                }
                // Credit orders: reduce the linked invoice's outstanding balance (audited, transactional)
                $creditAdj = creditApplyRefund($pdo, (int)$co['customer_id'], $orderId, $amount, $uid !== null ? (int)$uid : null);
                $pdo->commit();
                $refundMsg = 'Refund of ' . finFmt($amount) . ' processed for Order #' . $orderId . '.';
                if (!empty($creditAdj['msg'])) $refundMsg .= ' ' . $creditAdj['msg'];
                redirect_with_flash('?page=finance_refunds', $refundMsg);
            } catch (Exception $e) { $pdo->rollBack(); $messages[] = ['type' => 'error', 'text' => ErpService::friendlyError($e)]; }
        }
    } elseif ($action === 'process_supplier_refund') {
        $sid = (int) ($_POST['supplier_id'] ?? 0);
        $amount = (float) ($_POST['refund_amount'] ?? 0);
        $reason = trim($_POST['refund_reason'] ?? '');
        if ($sid <= 0 || $amount <= 0 || $reason === '') {
            $messages[] = ['type' => 'error', 'text' => 'All fields required.'];
        } else {
            $uid = $_SESSION['user']['id'] ?? null;
            $pdo->prepare('INSERT INTO refunds (amount, reason, refunded_at, refunded_by, status, refund_type, supplier_id) VALUES (:amt, :reason, NOW(), :uid, "completed", "supplier", :sid)')->execute(['amt' => $amount, 'reason' => $reason, 'uid' => $uid, 'sid' => $sid]);
            // Auto-record bank transaction if bank account selected
            $bankAccId = (int) ($_POST['bank_account_id'] ?? 0);
            if ($bankAccId > 0) {
                bankRecordTransaction($pdo, $bankAccId, 'refund', $amount, '', 'Supplier refund - #' . $sid . ': ' . $reason, null, $sid, $uid);
            }
            redirect_with_flash('?page=finance_refunds', 'Supplier refund of ' . finFmt($amount) . ' recorded.');
        }
    } elseif ($action === 'approve_refund') {
        $rid = (int) ($_POST['refund_id'] ?? 0);
        $uid = $_SESSION['user']['id'] ?? null;
        $pdo->prepare('UPDATE refunds SET status = "approved" WHERE id = :id')->execute(['id' => $rid]);
        redirect_with_flash('?page=finance_refunds', 'Refund approved.');
    } elseif ($action === 'reject_refund') {
        $rid = (int) ($_POST['refund_id'] ?? 0);
        $pdo->prepare('UPDATE refunds SET status = "rejected" WHERE id = :id')->execute(['id' => $rid]);
        redirect_with_flash('?page=finance_refunds', 'Refund rejected.');
    }
    }
}

$refundable = $pdo->query("SELECT co.id, co.total_amount, co.created_at, c.name AS customer_name FROM customer_orders co LEFT JOIN customers c ON co.customer_id = c.id WHERE co.status IN ('approved','pending') AND co.payment_confirmed = 1 AND co.id NOT IN (SELECT customer_order_id FROM refunds WHERE customer_order_id IS NOT NULL) ORDER BY co.created_at DESC LIMIT 50")->fetchAll();
$allRefunds = $pdo->query('SELECT r.*, c.name AS customer_name, co.id AS order_id, u.full_name AS refunded_by_name FROM refunds r LEFT JOIN customer_orders co ON r.customer_order_id = co.id LEFT JOIN customers c ON co.customer_id = c.id LEFT JOIN users u ON r.refunded_by = u.id ORDER BY r.created_at DESC LIMIT 100')->fetchAll();
$suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name')->fetchAll();
$pendingRefunds = array_filter($allRefunds, fn($r) => ($r['status'] ?? 'completed') === 'pending');
$monthRefunds = (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())')->fetchColumn();
$yearRefunds = (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE YEAR(created_at) = YEAR(CURDATE())')->fetchColumn();
$refundCount = (int) $pdo->query('SELECT COUNT(*) FROM refunds WHERE YEAR(created_at) = YEAR(CURDATE())')->fetchColumn();
$avgRefund = $refundCount > 0 ? $yearRefunds / $refundCount : 0;
$pendingCount = count($pendingRefunds);
$customerRefunds = count(array_filter($allRefunds, fn($r) => ($r['refund_type'] ?? 'customer') === 'customer'));
$supplierRefunds = count(array_filter($allRefunds, fn($r) => ($r['refund_type'] ?? '') === 'supplier'));

$tab = $_GET['tab'] ?? 'customer';
?>
<?= finCss() ?>
<section class="fin-wrap">
    <h1><i class="fas fa-undo-alt" style="color:var(--fin-red)"></i> Refunds Management</h1>
    <div class="fin-subtitle">Process and manage customer and supplier refunds</div>

    <?php foreach ($messages as $m): ?>
        <div style="padding:10px 14px;border-radius:8px;margin-bottom:12px;background:<?= $m['type'] === 'success' ? '#dcfce7' : '#fee2e2' ?>;color:<?= $m['type'] === 'success' ? '#166534' : '#991b1b' ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <div class="fin-stats">
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#dc2626,#b91c1c)"><i class="fas fa-undo-alt"></i></div>
            <div class="fin-stat-body"><div class="fin-stat-val" data-counter="<?= $monthRefunds ?>" data-prefix="<?= htmlspecialchars(currency_sym()) ?> " data-decimals="2"><?= htmlspecialchars(currency_sym()) ?> 0.00</div><div class="fin-stat-label">Refunds This Month</div></div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#d97706,#b45309)"><i class="fas fa-clock"></i></div>
            <div class="fin-stat-body"><div class="fin-stat-val" data-counter="<?= $pendingCount ?>">0</div><div class="fin-stat-label">Pending Approvals</div></div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#2563eb,#1d4ed8)"><i class="fas fa-user"></i></div>
            <div class="fin-stat-body"><div class="fin-stat-val" data-counter="<?= $customerRefunds ?>">0</div><div class="fin-stat-label">Customer Refunds</div></div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#7c3aed,#6d28d9)"><i class="fas fa-building"></i></div>
            <div class="fin-stat-body"><div class="fin-stat-val" data-counter="<?= $supplierRefunds ?>">0</div><div class="fin-stat-label">Supplier Refunds</div></div>
        </div>
    </div>

    <div class="fin-tabs">
        <button class="fin-tab <?= $tab === 'customer' ? 'active' : '' ?>" onclick="finSwitchTab(this,'tab-customer')"><i class="fas fa-user"></i> Customer Refunds</button>
        <button class="fin-tab <?= $tab === 'supplier' ? 'active' : '' ?>" onclick="finSwitchTab(this,'tab-supplier')"><i class="fas fa-building"></i> Supplier Refunds</button>
        <button class="fin-tab <?= $tab === 'pending' ? 'active' : '' ?>" onclick="finSwitchTab(this,'tab-pending')"><i class="fas fa-clock"></i> Pending Approvals</button>
        <button class="fin-tab <?= $tab === 'history' ? 'active' : '' ?>" onclick="finSwitchTab(this,'tab-history')"><i class="fas fa-history"></i> Refund History</button>
        <button class="fin-tab <?= $tab === 'reports' ? 'active' : '' ?>" onclick="finSwitchTab(this,'tab-reports')"><i class="fas fa-chart-bar"></i> Reports</button>
    </div>

    <div id="tab-customer" class="fin-panel <?= $tab === 'customer' ? 'active' : '' ?>">
        <div class="fin-card">
            <h2><i class="fas fa-plus-circle" style="color:var(--fin-green)"></i> Process Customer Refund</h2>
            <?php if (hasPermission('finance_refunds', 'confirm')): ?>
            <form method="post" enctype="multipart/form-data" style="max-width:700px">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="process_refund">
                <div class="fin-form-group"><label>Order</label><select name="order_id" required><option value="">Select order...</option><?php foreach ($refundable as $r): ?><option value="<?= $r['id'] ?>">#<?= $r['id'] ?> - <?= htmlspecialchars($r['customer_name'] ?? 'N/A') ?> (<?= finFmt((float) $r['total_amount']) ?>)</option><?php endforeach; ?></select></div>
                <div class="fin-form-group"><label>Refund Amount (ETB)</label><input type="number" step="0.01" name="refund_amount" min="0.01" required></div>
                <div class="fin-form-group"><label>Reason</label><input type="text" name="refund_reason" required placeholder="e.g. Damaged product, wrong item..."></div>
                <div class="fin-form-group">
                    <label>Bank Account (auto-debit)</label>
                    <?= bankRenderAccountDropdown($pdo, 'bank_account_id', (string)bankGetDefaultAccountId($pdo)) ?>
                </div>
                <div class="fin-form-group"><label>Receipt / Supporting Document (optional)</label><input type="file" name="receipt" accept="image/*,.pdf,.doc,.docx"></div>
                <button type="submit" class="fin-btn fin-btn-red"><i class="fas fa-undo"></i> Process Refund</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <div id="tab-supplier" class="fin-panel <?= $tab === 'supplier' ? 'active' : '' ?>">
        <div class="fin-grid-2">
            <div class="fin-card">
                <h2><i class="fas fa-building" style="color:var(--fin-purple)"></i> Process Supplier Refund</h2>
                <?php if (hasPermission('finance_refunds', 'confirm')): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="process_supplier_refund">
                    <div class="fin-form-group"><label>Supplier</label><select name="supplier_id" required><option value="">Select supplier...</option><?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="fin-form-group"><label>Amount (ETB)</label><input type="number" step="0.01" name="refund_amount" min="0.01" required></div>
                    <div class="fin-form-group"><label>Reason</label><input type="text" name="refund_reason" required></div>
                    <div class="fin-form-group">
                        <label>Bank Account (auto-debit)</label>
                        <?= bankRenderAccountDropdown($pdo, 'bank_account_id', (string)bankGetDefaultAccountId($pdo)) ?>
                    </div>
                    <button type="submit" class="fin-btn fin-btn-purple"><i class="fas fa-check"></i> Record Refund</button>
                </form>
                <?php endif; ?>
            </div>
            <div class="fin-card">
                <h2><i class="fas fa-history" style="color:var(--fin-blue)"></i> Supplier Refund History</h2>
                <?php $sRefunds = array_filter($allRefunds, fn($r) => ($r['refund_type'] ?? '') === 'supplier'); ?>
                <?php if (empty($sRefunds)): ?>
                    <div class="fin-empty"><i class="fas fa-inbox"></i>No supplier refunds yet.</div>
                <?php else: ?>
                    <div style="overflow-x:auto">
                    <table class="fin-table"><thead><tr><th>Date</th><th>Amount</th><th>Reason</th><th>Status</th></tr></thead>
                    <tbody><?php foreach (array_slice($sRefunds, 0, 20) as $sr): ?><tr><td><?= date('M d, Y', strtotime($sr['created_at'])) ?></td><td style="font-weight:700;color:var(--fin-red)"><?= finFmt((float) $sr['amount']) ?></td><td style="font-size:.78rem;color:var(--fin-gray)"><?= htmlspecialchars($sr['reason']) ?></td><td><span class="fin-badge fin-badge-<?= $sr['status'] === 'completed' ? 'green' : ($sr['status'] === 'approved' ? 'blue' : ($sr['status'] === 'rejected' ? 'red' : 'orange')) ?>"><?= ucfirst($sr['status'] ?? 'completed') ?></span></td></tr><?php endforeach; ?></tbody></table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="tab-pending" class="fin-panel <?= $tab === 'pending' ? 'active' : '' ?>">
        <div class="fin-card">
            <h2><i class="fas fa-clock" style="color:var(--fin-orange)"></i> Pending Refund Approvals</h2>
            <?php if (empty($pendingRefunds)): ?>
                <div class="fin-empty"><i class="fas fa-check-circle" style="color:var(--fin-green)"></i>No pending refunds.</div>
            <?php else: ?>
                <div style="overflow-x:auto"><table class="fin-table">
                    <thead><tr><th>Date</th><th>Order/Supplier</th><th>Customer</th><th>Amount</th><th>Reason</th><th>Type</th><th>Actions</th></tr></thead>
                    <tbody><?php foreach ($pendingRefunds as $pr): ?>
                        <tr>
                            <td><?= date('M d, Y', strtotime($pr['created_at'])) ?></td>
                            <td style="font-weight:600"><?= $pr['order_id'] ? '#' . $pr['order_id'] : 'Supplier #' . ($pr['supplier_id'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($pr['customer_name'] ?? 'N/A') ?></td>
                            <td style="font-weight:700;color:var(--fin-red)"><?= finFmt((float) $pr['amount']) ?></td>
                            <td style="font-size:.78rem;color:var(--fin-gray)"><?= htmlspecialchars($pr['reason']) ?></td>
                            <td><span class="fin-badge fin-badge-<?= ($pr['refund_type'] ?? '') === 'supplier' ? 'purple' : 'blue' ?>"><?= ucfirst($pr['refund_type'] ?? 'customer') ?></span></td>
                            <td style="white-space:nowrap">
                                <?php if (hasPermission('finance_refunds', 'confirm')): ?>
                                <form method="post" style="display:inline"><input type="hidden" name="_action" value="approve_refund"><input type="hidden" name="refund_id" value="<?= $pr['id'] ?>"><?= csrf_field() ?><button type="submit" class="fin-btn fin-btn-sm fin-btn-green"><i class="fas fa-check"></i></button></form>
                                <?php endif; ?>
                                <?php if (hasPermission('finance_refunds', 'unconfirm')): ?>
                                <form method="post" style="display:inline" onsubmit="return confirm('Reject?')"><input type="hidden" name="_action" value="reject_refund"><input type="hidden" name="refund_id" value="<?= $pr['id'] ?>"><?= csrf_field() ?><button type="submit" class="fin-btn fin-btn-sm fin-btn-red"><i class="fas fa-times"></i></button></form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </div>
    </div>

    <div id="tab-history" class="fin-panel <?= $tab === 'history' ? 'active' : '' ?>">
        <div class="fin-card">
            <h2><i class="fas fa-history" style="color:var(--fin-blue)"></i> All Refund History</h2>
            <?php if (empty($allRefunds)): ?>
                <div class="fin-empty"><i class="fas fa-inbox"></i>No refunds recorded.</div>
            <?php else: ?>
                <div style="overflow-x:auto"><table class="fin-table">
                    <thead><tr><th>Date</th><th>Type</th><th>Order</th><th>Customer</th><th>Amount</th><th>Reason</th><th>Receipt</th><th>Status</th><th>By</th></tr></thead>
                    <tbody><?php foreach ($allRefunds as $rf): ?>
                        <tr>
                            <td style="white-space:nowrap"><?= date('M d, Y', strtotime($rf['created_at'])) ?></td>
                            <td><span class="fin-badge fin-badge-<?= ($rf['refund_type'] ?? '') === 'supplier' ? 'purple' : 'blue' ?>"><?= ucfirst($rf['refund_type'] ?? 'customer') ?></span></td>
                            <td style="font-weight:600"><?= $rf['order_id'] ? '#' . $rf['order_id'] : '—' ?></td>
                            <td><?= htmlspecialchars($rf['customer_name'] ?? 'N/A') ?></td>
                            <td style="font-weight:700;color:var(--fin-red)"><?= finFmt((float) $rf['amount']) ?></td>
                            <td style="font-size:.78rem;color:var(--fin-gray);max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($rf['reason']) ?></td>
                            <td><?php if (!empty($rf['receipt_path'])): ?><a href="<?= htmlspecialchars($rf['receipt_path']) ?>" target="_blank" class="fin-btn fin-btn-sm fin-btn-blue"><i class="fas fa-file"></i> View</a><?php else: ?>—<?php endif; ?></td>
                            <td><span class="fin-badge fin-badge-<?= ($rf['status'] ?? 'completed') === 'completed' ? 'green' : (($rf['status'] ?? '') === 'approved' ? 'blue' : (($rf['status'] ?? '') === 'rejected' ? 'red' : 'orange')) ?>"><?= ucfirst($rf['status'] ?? 'completed') ?></span></td>
                            <td style="font-size:.78rem;color:var(--fin-gray)"><?= htmlspecialchars($rf['refunded_by_name'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </div>
    </div>

    <div id="tab-reports" class="fin-panel <?= $tab === 'reports' ? 'active' : '' ?>">
        <div class="fin-kpi-row">
            <div class="fin-kpi"><div class="fin-kpi-val" style="color:var(--fin-red)" data-counter="<?= $monthRefunds ?>" data-prefix="<?= htmlspecialchars(currency_sym()) ?> " data-decimals="2"><?= htmlspecialchars(currency_sym()) ?> 0.00</div><div class="fin-kpi-label">This Month</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val" style="color:var(--fin-orange)" data-counter="<?= $yearRefunds ?>" data-prefix="<?= htmlspecialchars(currency_sym()) ?> " data-decimals="2"><?= htmlspecialchars(currency_sym()) ?> 0.00</div><div class="fin-kpi-label">This Year</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val" data-counter="<?= $refundCount ?>">0</div><div class="fin-kpi-label">Total Refunds</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val" style="color:var(--fin-purple)" data-counter="<?= $avgRefund ?>" data-prefix="<?= htmlspecialchars(currency_sym()) ?> " data-decimals="2"><?= htmlspecialchars(currency_sym()) ?> 0.00</div><div class="fin-kpi-label">Average Refund</div></div>
        </div>
    </div>
</section>
<?= finJs() ?>
