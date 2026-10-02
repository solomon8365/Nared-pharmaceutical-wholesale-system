<?php
require_once __DIR__ . '/../services/init.php';
require_once __DIR__ . '/../includes/finance_helpers.php';
require_once __DIR__ . '/../includes/workflow_helpers.php';
require_once __DIR__ . '/../includes/workflow_engine.php';
require_once __DIR__ . '/../includes/credit_helpers.php';
require_once __DIR__ . '/../includes/auto_migrate.php';
ensureFinanceErpMigration(finGetDb());
requirePermission('finance_payments', 'view');
$pdo = finGetDb();
ensureFulfillmentMigration($pdo);
$user = currentUser();
$uid = (int) $user['id'];
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (isset($_POST['confirm_payment'])) {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $notes = trim($_POST['payment_notes'] ?? '');
        $verifiedAmount = (float) ($_POST['verified_amount'] ?? 0);
        $bankAccId = (int) ($_POST['bank_account_id'] ?? 0);
        if ($orderId <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Invalid order.'];
        } else {
            $res = financeConfirmOrderPayment($pdo, $orderId, $verifiedAmount, $notes, $uid, $bankAccId);
            $messages[] = ['type' => $res['success'] ? 'success' : 'error', 'text' => $res['msg']];
        }
    }

    if (isset($_POST['reject_payment'])) {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? '');
        if ($orderId <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Invalid order.'];
        } elseif ($reason === '') {
            $messages[] = ['type' => 'error', 'text' => 'Please provide a reason for rejecting this payment.'];
        } else {
            $stmt = $pdo->prepare("SELECT co.id, co.customer_id, co.status FROM customer_orders co WHERE co.id = :id AND co.salesperson_id IS NULL AND co.payment_confirmed = 0 LIMIT 1");
            $stmt->execute(['id' => $orderId]);
            $order = $stmt->fetch();
            if (!$order) {
                $messages[] = ['type' => 'error', 'text' => 'Order not found or already processed.'];
            } elseif (!WorkflowService::canTransition(StatusCatalog::WORKFLOW_ORDER, $order['status'], StatusCatalog::ORDER_CANCELLED)) {
                $messages[] = ['type' => 'error', 'text' => 'Order cannot be cancelled from its current status.'];
            } else {
                $transition = WorkflowService::transition($pdo, StatusCatalog::WORKFLOW_ORDER, $orderId, StatusCatalog::ORDER_CANCELLED, [
                    'extra' => ['payment_rejection_reason' => $reason, 'verified_by' => $uid, 'verified_at' => date('Y-m-d H:i:s')],
                    'changed_by' => $uid,
                    'notes' => 'Payment rejected: ' . $reason,
                ]);
                if (!$transition['success']) {
                    $messages[] = ['type' => 'error', 'text' => $transition['error']];
                } else {
                    logPaymentAction($pdo, $orderId, $order['customer_id'], 'payment_rejected', null, null, null, $uid, $reason);
                    createOrderNotification($pdo, $orderId, $order['customer_id'], 'Payment Rejected', 'Your payment for order #' . $orderId . ' was rejected. Reason: ' . $reason . '. Please resubmit with correct payment details.', 'error');
                    $messages[] = ['type' => 'success', 'text' => "Payment for Order #{$orderId} rejected. Customer has been notified."];
                }
            }
        }
    }

    if (isset($_POST['verify_payment'])) {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $verifiedAmount = (float) ($_POST['verified_amount'] ?? 0);
        $notes = trim($_POST['verification_notes'] ?? '');
        if ($orderId <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Invalid order.'];
        } elseif ($verifiedAmount <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Verified amount must be greater than zero.'];
        } else {
            $stmt = $pdo->prepare("SELECT co.id, co.customer_id, co.total_amount FROM customer_orders co WHERE co.id = :id AND co.salesperson_id IS NULL LIMIT 1");
            $stmt->execute(['id' => $orderId]);
            $order = $stmt->fetch();
            if (!$order) {
                $messages[] = ['type' => 'error', 'text' => 'Order not found.'];
            } else {
                $pdo->prepare("UPDATE customer_orders SET verified_amount = :amt, verified_by = :uid, verified_at = NOW() WHERE id = :id")
                    ->execute(['amt' => $verifiedAmount, 'uid' => $uid, 'id' => $orderId]);
                logPaymentAction($pdo, $orderId, $order['customer_id'], 'payment_verified', $verifiedAmount, null, null, $uid, $notes !== '' ? $notes : 'Payment verified by finance');
                $messages[] = ['type' => 'success', 'text' => "Payment for Order #{$orderId} verified. Amount: " . finFmt($verifiedAmount)];
            }
        }
    }

    if (isset($_POST['make_payment'])) {
        $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
        $payAmount = (float) ($_POST['pay_amount'] ?? 0);
        $payMethod = trim($_POST['pay_method'] ?? 'cash');
        $payRef = trim($_POST['pay_ref'] ?? '');
        $payNotes = trim($_POST['pay_notes'] ?? '');
        if ($invoiceId <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Please select a valid invoice.'];
        } elseif ($payAmount <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Payment amount must be greater than zero.'];
        } else {
            WorkflowEngine::ensureStatusHistoryTable($pdo);
            $pdo->beginTransaction();
            try {
                $recorded = recordInvoicePayment($pdo, $invoiceId, $payAmount, $payMethod, $payRef !== '' ? $payRef : null, null, $payNotes !== '' ? $payNotes : 'Manual payment by finance', $uid);
                if ($recorded) {
                    $invCheck = $pdo->prepare('SELECT customer_order_id, customer_id FROM invoices WHERE id = :iid LIMIT 1');
                    $invCheck->execute(['iid' => $invoiceId]);
                    $invRow = $invCheck->fetch();
                    if ($invRow) {
                        logPaymentAction($pdo, (int)$invRow['customer_order_id'], null, 'manual_payment_recorded', $payAmount, $payMethod, $payRef, $uid, $payNotes);
                        if ($payMethod === 'credit' && function_exists('creditLogAudit')) {
                            $pdo->prepare('INSERT INTO customer_credit_payments (customer_id, amount, payment_date, payment_method, reference_no, notes, created_by) VALUES (:cid, :amt, CURDATE(), "credit", :ref, :notes, :uid)')
                                ->execute(['cid' => (int)$invRow['customer_id'], 'amt' => $payAmount, 'ref' => $payRef ?: null, 'notes' => $payNotes ?: 'Manual credit payment by finance', 'uid' => $uid]);
                            creditLogAudit($pdo, (int)$invRow['customer_id'], 'manual_credit_payment', null, json_encode(['invoice_id' => $invoiceId, 'amount' => $payAmount, 'ref' => $payRef]), $uid);
                        }
                    }
                    $pdo->commit();
                    $messages[] = ['type' => 'success', 'text' => "Payment of " . finFmt($payAmount) . " recorded against Invoice #{$invoiceId}."];
                } else {
                    $pdo->rollBack();
                    $messages[] = ['type' => 'error', 'text' => 'Payment could not be recorded. Check invoice outstanding balance.'];
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $messages[] = ['type' => 'error', 'text' => ErpService::friendlyError($e)];
            }
        }
    }
    if (isset($_POST['finalize_salesperson'])) {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        if ($orderId <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Invalid order.'];
        } else {
            try {
                $res = WorkflowService::finalizeSalespersonOrder($pdo, $orderId, $uid, !empty($_POST['override_credit']));
                $messages[] = ['type' => 'success', 'text' => 'Order #' . $orderId . ' finalized. Sale #' . (int) $res['sale_id'] . ' and invoice ' . htmlspecialchars($res['invoice_no']) . ' generated; stock deducted.'];
            } catch (ServiceException $e) {
                $messages[] = ['type' => 'error', 'text' => $e->getMessage()];
            }
        }
    }
}

$pendingCount = $pdo->query("SELECT COUNT(*) FROM customer_orders WHERE salesperson_id IS NULL AND payment_confirmed = 0 AND status IN ('pending','approved') AND fulfillment_status = 'prepared'")->fetchColumn();
$pendingAmount = (float) $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM customer_orders WHERE salesperson_id IS NULL AND payment_confirmed = 0 AND status IN ('pending','approved') AND fulfillment_status = 'prepared'")->fetchColumn();
$todayConfirmedCount = $pdo->query("SELECT COUNT(*) FROM customer_orders WHERE salesperson_id IS NULL AND payment_confirmed = 1 AND DATE(payment_confirmed_at) = CURDATE()")->fetchColumn();
$todayConfirmedAmount = (float) $pdo->query("SELECT COALESCE(SUM(COALESCE(verified_amount, total_amount)), 0) FROM customer_orders WHERE salesperson_id IS NULL AND payment_confirmed = 1 AND DATE(payment_confirmed_at) = CURDATE()")->fetchColumn();
$totalConfirmedAmount = (float) $pdo->query("SELECT COALESCE(SUM(COALESCE(verified_amount, total_amount)), 0) FROM customer_orders WHERE salesperson_id IS NULL AND payment_confirmed = 1")->fetchColumn();

function fpFetchOrders(PDO $pdo, string $where, array $params = [], string $orderBy = ''): array {
    $sql = "SELECT co.*, c.name AS customer_name, c.phone AS customer_phone, c.tin_number AS customer_tin,
                   u.full_name AS confirmed_by_name,
                   vu.full_name AS verified_by_name,
                   inv.invoice_no, inv.id AS inv_id
            FROM customer_orders co
            LEFT JOIN customers c ON co.customer_id = c.id
            LEFT JOIN users u ON co.payment_confirmed_by = u.id
            LEFT JOIN users vu ON co.verified_by = vu.id
            LEFT JOIN invoices inv ON inv.customer_order_id = co.id
            WHERE $where " . ($orderBy ?: 'ORDER BY co.created_at DESC');
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

$pendingOrders = fpFetchOrders($pdo, "co.salesperson_id IS NULL AND co.payment_confirmed = 0 AND co.status IN ('pending','approved') AND co.fulfillment_status = 'prepared'");
$salespersonFinalize = fpFetchOrders($pdo, "co.salesperson_id IS NOT NULL AND co.status = 'pending' AND co.approval_stage = 'finance'");
$approvedOrders = fpFetchOrders($pdo, "co.salesperson_id IS NULL AND co.payment_confirmed = 1");
$rejectedOrders = fpFetchOrders($pdo, "co.salesperson_id IS NULL AND co.status = 'cancelled' AND co.payment_rejection_reason IS NOT NULL");

$paymentHistory = fpFetchOrders($pdo, "co.salesperson_id IS NULL AND co.payment_confirmed = 1", [], "ORDER BY co.payment_confirmed_at DESC");
try {
    $phLogStmt = $pdo->query("SELECT pl.*, c.name AS customer_name, u.full_name AS performed_by_name FROM payment_log pl LEFT JOIN customers c ON pl.customer_id = c.id LEFT JOIN users u ON pl.performed_by = u.id ORDER BY pl.created_at DESC LIMIT 50");
    $paymentLogs = $phLogStmt->fetchAll();
} catch (Exception $e) {
    $paymentLogs = [];
}

$unpaidInvoices = [];
try {
    $uiStmt = $pdo->prepare("SELECT inv.*, c.name AS customer_name FROM invoices inv LEFT JOIN customers c ON inv.customer_id = c.id WHERE inv.payment_status IN ('unpaid','partial') ORDER BY inv.created_at DESC");
    $uiStmt->execute();
    $unpaidInvoices = $uiStmt->fetchAll();
} catch (Exception $e) {
    $unpaidInvoices = [];
}

$itemsByOrder = [];
try {
    $iStmt = $pdo->query('SELECT coi.customer_order_id, coi.quantity, coi.unit_price, coi.discount, coi.tax_amount, p.name AS product_name FROM customer_order_items coi JOIN products p ON coi.product_id = p.id');
    foreach ($iStmt->fetchAll() as $it) {
        $itemsByOrder[(int)$it['customer_order_id']][] = $it;
    }
} catch (Exception $e) {}

$logByOrder = [];
foreach ($paymentLogs as $log) {
    $logByOrder[(int)$log['customer_order_id']][] = $log;
}

$paymentMethodLabels = [
    'cash' => 'Cash',
    'bank' => 'Bank Transfer',
    'cbe_birr' => 'CBE Birr',
    'telebirr' => 'Telebirr',
    'credit' => 'Credit Account',
];

function fpMethodBadge(string $method): string {
    $colors = [
        'cash' => 'fin-badge-green',
        'bank' => 'fin-badge-blue',
        'cbe_birr' => 'fin-badge-purple',
        'telebirr' => 'fin-badge-teal',
        'credit' => 'fin-badge-orange',
    ];
    $labels = [
        'cash' => 'Cash',
        'bank' => 'Bank Transfer',
        'cbe_birr' => 'CBE Birr',
        'telebirr' => 'Telebirr',
        'credit' => 'Credit Account',
    ];
    $cls = $colors[$method] ?? 'fin-badge-gray';
    $lbl = $labels[$method] ?? ucfirst(str_replace('_', ' ', $method));
    return '<span class="fin-badge ' . $cls . '">' . htmlspecialchars($lbl) . '</span>';
}
?>
<?= finCss() ?>
<style>
.fp-order-card{background:var(--fin-card);border-radius:var(--fin-radius);border:1px solid var(--fin-border);padding:20px;margin-bottom:14px;box-shadow:var(--fin-shadow);transition:all .2s;border-left:4px solid var(--fin-border)}
.fp-order-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08)}
.fp-order-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;flex-wrap:wrap;gap:8px}
.fp-order-id{font-weight:800;font-size:1.05rem;color:#0f172a}
.fp-order-customer{font-size:.82rem;color:var(--fin-gray);margin-top:2px}
.fp-order-date{text-align:right;font-size:.78rem;color:var(--fin-gray)}
.fp-detail-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin-bottom:14px}
.fp-detail-item{font-size:.82rem}
.fp-detail-item .lbl{color:var(--fin-gray);font-size:.72rem;display:block;margin-bottom:2px;font-weight:500}
.fp-detail-item .val{font-weight:700;color:#0f172a}
.fp-detail-item .val.big{font-size:1.05rem;color:var(--fin-blue)}
.fp-items-tbl{width:100%;border-collapse:collapse;font-size:.78rem;margin:10px 0}
.fp-items-tbl th{text-align:left;padding:7px 10px;background:#f8fafc;font-weight:700;color:#475569;border-bottom:2px solid var(--fin-border);font-size:.7rem;text-transform:uppercase}
.fp-items-tbl td{padding:7px 10px;border-bottom:1px solid #f1f5f9}
.fp-receipt-wrap{margin:8px 0}
.fp-receipt-link{color:var(--fin-blue);font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:4px;font-size:.82rem;cursor:pointer}
.fp-receipt-link:hover{text-decoration:underline}
.fp-receipt-img{max-height:200px;border-radius:10px;border:1px solid var(--fin-border);margin-top:8px;display:none}
.fp-rejection-box{background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px;margin:8px 0;font-size:.82rem}
.fp-notes-box{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px;margin:8px 0;font-size:.82rem}
.fp-log-section{margin-top:10px;border-top:1px solid var(--fin-border);padding-top:10px;font-size:.78rem}
.fp-log-item{display:flex;gap:8px;padding:4px 0;align-items:flex-start}
.fp-log-dot{width:8px;height:8px;border-radius:50%;margin-top:4px;flex-shrink:0}
.fp-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding-top:14px;border-top:1px solid var(--fin-border);margin-top:12px}
.fp-reject-form{display:none;margin-top:10px;padding:14px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px}
.fp-reject-form.active{display:block}
.fp-verify-form{display:none;margin-top:10px;padding:14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px}
.fp-verify-form.active{display:block}
.fp-form-row{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end}
.fp-form-row>div{flex:1;min-width:120px}
.fp-form-row label{display:block;font-size:.72rem;color:var(--fin-gray);font-weight:600;margin-bottom:3px}
.fp-form-row input,.fp-form-row select,.fp-form-row textarea{width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:.82rem;font-family:inherit}
.fp-bank-info{background:#f8fafc;border:1px solid var(--fin-border);border-radius:10px;padding:12px;margin:8px 0;font-size:.82rem}
.fp-bank-info strong{color:#0f172a}
.fp-payment-method-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:.72rem;font-weight:700}
</style>

<section class="fin-wrap">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:6px">
        <div>
            <h1><i class="fas fa-check-double" style="color:var(--fin-green)"></i> Payment Confirmation</h1>
            <div class="fin-subtitle">Verify, confirm, and manage customer payment transactions</div>
        </div>
    </div>

    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= ($m['type'] ?? '') === 'success' ? 'success' : 'error' ?>" style="display:flex;align-items:center;gap:10px">
            <i class="fas fa-<?= ($m['type'] ?? '') === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
            <?= htmlspecialchars($m['text']) ?>
        </div>
    <?php endforeach; ?>

    <div class="fin-stats" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr))">
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#d97706,#b45309)"><i class="fas fa-hourglass-half"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $pendingCount ?>" data-prefix="" data-suffix="">0</div>
                <div class="fin-stat-label">Pending Orders</div>
            </div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#dc2626,#b91c1c)"><i class="fas fa-coins"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $pendingAmount ?>" data-prefix="<?= htmlspecialchars(currency_sym()) ?> " data-decimals="2"><?= htmlspecialchars(currency_sym()) ?> 0.00</div>
                <div class="fin-stat-label">Total Pending Amount</div>
            </div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#059669,#047857)"><i class="fas fa-calendar-check"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $todayConfirmedCount ?>" data-prefix="" data-suffix="">0</div>
                <div class="fin-stat-label">Today's Confirmed</div>
            </div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#2563eb,#1d4ed8)"><i class="fas fa-hand-holding-usd"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $todayConfirmedAmount ?>" data-prefix="<?= htmlspecialchars(currency_sym()) ?> " data-decimals="2"><?= htmlspecialchars(currency_sym()) ?> 0.00</div>
                <div class="fin-stat-label">Total Confirmed Amount</div>
            </div>
        </div>
    </div>

    <div class="fin-card">
        <div class="fin-tabs" id="fp-tabs">
            <button class="fin-tab active" onclick="finSwitchTab(this,'fp-panel-pending')"><i class="fas fa-clock"></i> Pending <span class="fin-badge fin-badge-orange" style="margin-left:4px"><?= $pendingCount ?></span></button>
            <button class="fin-tab" onclick="finSwitchTab(this,'fp-panel-finalize')"><i class="fas fa-clipboard-check"></i> Finalize Salesperson <span class="fin-badge fin-badge-blue" style="margin-left:4px"><?= count($salespersonFinalize) ?></span></button>
            <button class="fin-tab" onclick="finSwitchTab(this,'fp-panel-approved')"><i class="fas fa-check-circle"></i> Approved <span class="fin-badge fin-badge-green" style="margin-left:4px"><?= count($approvedOrders) ?></span></button>
            <button class="fin-tab" onclick="finSwitchTab(this,'fp-panel-rejected')"><i class="fas fa-times-circle"></i> Rejected <span class="fin-badge fin-badge-red" style="margin-left:4px"><?= count($rejectedOrders) ?></span></button>
<button class="fin-tab" onclick="finSwitchTab(this,'fp-panel-history')"><i class="fas fa-history"></i> Payment History</button>
<?php if (hasPermission('finance_payments', 'process')): ?>
<button class="fin-tab" onclick="finSwitchTab(this,'fp-panel-make')"><i class="fas fa-plus-circle"></i> Make Payment</button>
<?php endif; ?>
        </div>

        <div class="fin-panel active" id="fp-panel-pending">
            <?php if (empty($pendingOrders)): ?>
                <div class="fin-empty"><i class="fas fa-check-circle" style="color:var(--fin-green)"></i><div>All caught up! No pending payments to review.</div></div>
            <?php endif; ?>
            <?php foreach ($pendingOrders as $row): ?>
                <?php $oid = (int)$row['id']; ?>
                <div class="fp-order-card" style="border-left-color:var(--fin-orange)">
                    <div class="fp-order-head">
                        <div>
                            <div class="fp-order-id">Order #<?= $oid ?> <span class="fin-badge fin-badge-orange">Pending</span></div>
                            <div class="fp-order-customer"><?= htmlspecialchars($row['customer_name'] ?? 'Unknown Customer') ?></div>
                        </div>
                        <div class="fp-order-date">
                            <div>Order: <?= htmlspecialchars($row['order_date'] ?? date('M d, Y', strtotime($row['created_at']))) ?></div>
                            <div>Created: <?= date('M d, Y H:i', strtotime($row['created_at'])) ?></div>
                        </div>
                    </div>

                    <div class="fp-detail-grid">
                        <div class="fp-detail-item"><span class="lbl">Total Amount</span><span class="val big"><?= finFmt((float)$row['total_amount']) ?></span></div>
                        <div class="fp-detail-item"><span class="lbl">Payment Method</span><span class="val"><?= fpMethodBadge($row['payment_method'] ?? '') ?></span></div>
                        <div class="fp-detail-item"><span class="lbl">Fulfillment</span><span class="val"><?= ($row['fulfillment_method'] ?? '') === StatusCatalog::FULFILLMENT_METHOD_PICKUP ? '<span class="fp-payment-method-badge" style="background:#fef3c7;color:#92400e"><i class="fas fa-store"></i> Pickup</span>' : '<span class="fp-payment-method-badge" style="background:#dbeafe;color:#1e40af"><i class="fas fa-truck"></i> Delivery</span>' ?></span></div>
                        <div class="fp-detail-item"><span class="lbl">Payment Date</span><span class="val"><?= htmlspecialchars($row['transaction_ref'] ?? date('M d, Y', strtotime($row['created_at']))) ?></span></div>
                        <div class="fp-detail-item"><span class="lbl">Transaction Ref</span><span class="val"><?= htmlspecialchars($row['transaction_ref'] ?? '—') ?></span></div>
                        <div class="fp-detail-item"><span class="lbl">Phone</span><span class="val"><?= htmlspecialchars($row['customer_phone'] ?? '—') ?></span></div>
                        <div class="fp-detail-item"><span class="lbl">TIN</span><span class="val"><?= htmlspecialchars($row['customer_tin'] ?? '—') ?></span></div>
                        <div class="fp-detail-item"><span class="lbl">Payment Detail</span><span class="val"><?= htmlspecialchars($row['payment_detail'] ?? '—') ?></span></div>
                    </div>

                    <?php if (in_array($row['payment_method'] ?? '', ['bank', 'cbe_birr', 'telebirr'])): ?>
                        <div class="fp-bank-info">
                            <strong><i class="fas fa-university"></i> <?= $row['payment_method'] === 'bank' ? 'Bank Details' : ($row['payment_method'] === 'cbe_birr' ? 'CBE Birr Details' : 'TeleBirr Details') ?></strong>
                            <div class="fp-detail-grid" style="margin-top:8px">
                                <?php if ($row['bank_name']): ?>
                                    <div class="fp-detail-item"><span class="lbl">Bank Name</span><span class="val"><?= htmlspecialchars($row['bank_name']) ?></span></div>
                                <?php endif; ?>
                                <?php if ($row['account_name']): ?>
                                    <div class="fp-detail-item"><span class="lbl">Account Name</span><span class="val"><?= htmlspecialchars($row['account_name']) ?></span></div>
                                <?php endif; ?>
                                <?php if ($row['account_number']): ?>
                                    <div class="fp-detail-item"><span class="lbl">Account Number</span><span class="val"><?= htmlspecialchars($row['account_number']) ?></span></div>
                                <?php endif; ?>
                                <?php if ($row['merchant_name']): ?>
                                    <div class="fp-detail-item"><span class="lbl">Merchant Name</span><span class="val"><?= htmlspecialchars($row['merchant_name']) ?></span></div>
                                <?php endif; ?>
                                <?php if ($row['merchant_id']): ?>
                                    <div class="fp-detail-item"><span class="lbl">Merchant ID</span><span class="val"><?= htmlspecialchars($row['merchant_id']) ?></span></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php $items = $itemsByOrder[$oid] ?? []; if (!empty($items)): ?>
                        <div style="overflow-x:auto">
                        <table class="fp-items-tbl">
                            <thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Discount</th><th>Tax</th><th>Line Total</th></tr></thead>
                            <tbody>
                                <?php foreach ($items as $it): ?>
                                    <tr>
                                        <td style="font-weight:600"><?= htmlspecialchars($it['product_name']) ?></td>
                                        <td><?= (int)$it['quantity'] ?></td>
                                        <td><?= finFmt((float)$it['unit_price']) ?></td>
                                        <td><?= ($it['discount'] ?? 0) > 0 ? finFmt((float)$it['discount']) : '—' ?></td>
                                        <td><?= ($it['tax_amount'] ?? 0) > 0 ? finFmt((float)$it['tax_amount']) : '—' ?></td>
                                        <td style="font-weight:700"><?= finFmt((float)$it['unit_price'] * (int)$it['quantity'] - (float)($it['discount'] ?? 0)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    <?php endif; ?>

                    <?php if ($row['payment_screenshot']): ?>
                        <div class="fp-receipt-wrap">
                            <span class="fp-receipt-link" onclick="fpToggleReceipt(this)"><i class="fas fa-image"></i> View Payment Screenshot</span>
                            <img src="<?= htmlspecialchars($row['payment_screenshot']) ?>" class="fp-receipt-img" alt="Payment screenshot">
                        </div>
                    <?php endif; ?>

                    <?php if ($row['delivery_address']): ?>
                        <div style="font-size:.82rem;margin-bottom:8px"><span style="color:var(--fin-gray)"><i class="fas fa-map-marker-alt"></i> Delivery:</span> <?= nl2br(htmlspecialchars($row['delivery_address'])) ?></div>
                    <?php endif; ?>

                    <?php $logs = $logByOrder[$oid] ?? []; if (!empty($logs)): ?>
                        <div class="fp-log-section">
                            <strong style="color:#475569"><i class="fas fa-history"></i> Activity Log</strong>
                            <?php foreach (array_slice($logs, 0, 3) as $log): ?>
                                <div class="fp-log-item">
                                    <div class="fp-log-dot" style="background:<?= $log['action'] === 'payment_confirmed' ? 'var(--fin-green)' : ($log['action'] === 'payment_rejected' ? 'var(--fin-red)' : 'var(--fin-orange)') ?>"></div>
                                    <div>
                                        <span style="font-weight:600"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $log['action']))) ?></span>
                                        <?php if ($log['amount']): ?> — <?= finFmt((float)$log['amount']) ?><?php endif; ?>
                                        <span style="color:var(--fin-gray)">by <?= htmlspecialchars($log['performed_by_name'] ?? 'System') ?></span>
                                        <span style="color:#94a3b8"><?= date('M d, H:i', strtotime($log['created_at'])) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="fp-actions">
                        <?php if (hasPermission('finance_payments', 'confirm')): ?>
                        <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap" onsubmit="return confirm('Confirm payment for Order #<?= $oid ?>?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="confirm_payment" value="1">
                            <input type="hidden" name="order_id" value="<?= $oid ?>">
                            <div>
                                <label style="font-size:.72rem;color:var(--fin-gray);font-weight:600;display:block;margin-bottom:3px">Amount Paid</label>
                                <input type="number" name="verified_amount" value="<?= (float)$row['total_amount'] ?>" step="0.01" min="0" style="width:150px;min-width:120px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:.82rem">
                            </div>
                            <div>
                                <label style="font-size:.72rem;color:var(--fin-gray);font-weight:600;display:block;margin-bottom:3px">Finance Notes</label>
                                <input type="text" name="payment_notes" placeholder="Optional notes" style="width:200px;min-width:140px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:.82rem">
                            </div>
                            <?php if ($row['payment_method'] !== 'credit'): ?>
                            <div>
                                <label style="font-size:.72rem;color:var(--fin-gray);font-weight:600;display:block;margin-bottom:3px">Bank Account</label>
                                <?= bankRenderAccountDropdown($pdo, 'bank_account_id', (string)bankGetDefaultAccountId($pdo)) ?>
                            </div>
                            <?php endif; ?>
                            <button type="submit" class="fin-btn fin-btn-green"><i class="fas fa-check"></i> <?= ($row['payment_method'] ?? '') === 'credit' ? 'Approve Credit' : 'Confirm' ?></button>
                        </form>
                        <?php endif; ?>
                        <?php if (hasPermission('finance_payments', 'confirm')): ?>
                        <button type="button" class="fin-btn fin-btn-blue" onclick="fpToggleVerify(<?= $oid ?>)"><i class="fas fa-search-dollar"></i> Verify</button>
                        <?php endif; ?>
                        <?php if (hasPermission('finance_payments', 'unconfirm')): ?>
                        <button type="button" class="fin-btn fin-btn-red fin-btn-sm" onclick="fpToggleReject(<?= $oid ?>)"><i class="fas fa-times"></i> Reject</button>
                        <?php endif; ?>
                        <?php if ($row['inv_id']): ?>
                            <a href="?page=invoice&id=<?= (int)$row['inv_id'] ?>" target="_blank" class="fin-btn fin-btn-outline fin-btn-sm"><i class="fas fa-file-invoice"></i> Invoice</a>
                        <?php endif; ?>
                    </div>

                    <?php if (hasPermission('finance_payments', 'confirm')): ?>
                    <div class="fp-verify-form" id="verify-<?= $oid ?>">
                        <form method="post" onsubmit="return confirm('Verify payment amount for Order #<?= $oid ?>?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="verify_payment" value="1">
                            <input type="hidden" name="order_id" value="<?= $oid ?>">
                            <div class="fp-form-row" style="margin-bottom:10px">
                                <div>
                                    <label>Verified Amount</label>
                                    <input type="number" name="verified_amount" value="<?= (float)$row['total_amount'] ?>" step="0.01" min="0" required>
                                </div>
                                <div style="flex:2">
                                    <label>Verification Notes</label>
                                    <input type="text" name="verification_notes" placeholder="e.g. Amount matches bank statement">
                                </div>
                            </div>
                            <div style="display:flex;gap:8px">
                                <button type="submit" class="fin-btn fin-btn-blue"><i class="fas fa-check"></i> Confirm Verification</button>
                                <button type="button" class="fin-btn fin-btn-outline" onclick="fpToggleVerify(<?= $oid ?>)">Cancel</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>

                    <?php if (hasPermission('finance_payments', 'unconfirm')): ?>
                    <div class="fp-reject-form" id="reject-<?= $oid ?>">
                        <form method="post" onsubmit="return confirm('Reject this payment? The order will be cancelled and customer notified.')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="reject_payment" value="1">
                            <input type="hidden" name="order_id" value="<?= $oid ?>">
                            <div style="margin-bottom:10px">
                                <label style="font-size:.82rem;font-weight:700;color:#991b1b;display:block;margin-bottom:4px"><i class="fas fa-exclamation-triangle"></i> Rejection Reason *</label>
                                <textarea name="rejection_reason" rows="3" required placeholder="Explain why this payment is being rejected..." style="width:100%;padding:8px 10px;border:1px solid #fecaca;border-radius:8px;font-size:.82rem;font-family:inherit"></textarea>
                            </div>
                            <div style="display:flex;gap:8px">
                                <button type="submit" class="fin-btn fin-btn-red"><i class="fas fa-times"></i> Confirm Rejection</button>
                                <button type="button" class="fin-btn fin-btn-outline" onclick="fpToggleReject(<?= $oid ?>)">Cancel</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="fin-panel" id="fp-panel-finalize">
            <?php if (empty($salespersonFinalize)): ?>
                <div class="fin-empty"><i class="fas fa-check-circle" style="color:var(--fin-green)"></i><div>No salesperson orders awaiting Finance finalization.</div></div>
            <?php endif; ?>
            <?php foreach ($salespersonFinalize as $row): ?>
                <?php
                $oid = (int) $row['id'];
                $spNameStmt = $pdo->prepare('SELECT name FROM salespeople WHERE id = :id');
                $spNameStmt->execute(['id' => (int) $row['salesperson_id']]);
                $spName = $spNameStmt->fetchColumn();
                ?>
                <div class="fp-order-card" style="border-left-color:var(--fin-blue)">
                    <div class="fp-order-head">
                        <div>
                            <div class="fp-order-id">Order #<?= $oid ?> <span class="fin-badge fin-badge-blue">Finance Approval</span></div>
                            <div class="fp-order-customer"><?= htmlspecialchars($row['customer_name'] ?? 'Unknown Customer') ?></div>
                            <div class="fp-order-customer" style="margin-top:2px"><i class="fas fa-user-tie"></i> Salesperson: <?= htmlspecialchars((string) ($spName ?: $row['salesperson_id'])) ?></div>
                        </div>
                        <div class="fp-order-date">
                            <div>Order: <?= htmlspecialchars($row['order_date'] ?? '—') ?></div>
                            <div>Created: <?= date('M d, Y H:i', strtotime($row['created_at'])) ?></div>
                        </div>
                    </div>

                    <div class="fp-detail-grid">
                        <div class="fp-detail-item"><span class="lbl">Total Amount</span><span class="val big"><?= finFmt((float) $row['total_amount']) ?></span></div>
                        <div class="fp-detail-item"><span class="lbl">Payment Method</span><span class="val"><?= fpMethodBadge($row['payment_method'] ?? '') ?></span></div>
                        <div class="fp-detail-item"><span class="lbl">Fulfillment</span><span class="val"><?= ($row['fulfillment_method'] ?? '') === StatusCatalog::FULFILLMENT_METHOD_PICKUP ? '<span class="fp-payment-method-badge" style="background:#fef3c7;color:#92400e"><i class="fas fa-store"></i> Pickup</span>' : '<span class="fp-payment-method-badge" style="background:#dbeafe;color:#1e40af"><i class="fas fa-truck"></i> Delivery</span>' ?></span></div>
                        <div class="fp-detail-item"><span class="lbl">Payment Detail</span><span class="val"><?= htmlspecialchars($row['payment_detail'] ?? '—') ?></span></div>
                    </div>

                    <?php $items = $itemsByOrder[$oid] ?? []; if (!empty($items)): ?>
                        <div style="overflow-x:auto">
                        <table class="fp-items-tbl">
                            <thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Line Total</th></tr></thead>
                            <tbody>
                                <?php foreach ($items as $it): ?>
                                    <tr>
                                        <td style="font-weight:600"><?= htmlspecialchars($it['product_name']) ?></td>
                                        <td><?= (int) $it['quantity'] ?></td>
                                        <td><?= finFmt((float) $it['unit_price']) ?></td>
                                        <td style="font-weight:700"><?= finFmt((float) $it['unit_price'] * (int) $it['quantity']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    <?php endif; ?>

                    <div class="fp-actions">
                        <span class="fin-badge fin-badge-blue"><i class="fas fa-info-circle"></i> Finalizing creates the sale, invoice and stock deduction.</span>
                        <?php if (hasPermission('finance_payments', 'confirm')): ?>
                        <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap" onsubmit="return confirm('Finalize Order #<?= $oid ?>? This creates the sale, invoice and deducts stock.')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="finalize_salesperson" value="1">
                            <input type="hidden" name="order_id" value="<?= $oid ?>">
                            <?php if (($row['payment_method'] ?? '') === 'credit' && creditCanOverride()): ?>
                                <label style="display:flex;align-items:center;gap:4px;font-size:.78rem;cursor:pointer;margin-bottom:8px">
                                    <input type="checkbox" name="override_credit" value="1" style="width:auto">
                                    <span style="color:var(--fin-orange)"><i class="fas fa-exclamation-triangle"></i> Override Credit Limit</span>
                                </label>
                            <?php endif; ?>
                            <button type="submit" class="fin-btn fin-btn-blue"><i class="fas fa-check-double"></i> Finalize Order</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="fin-panel" id="fp-panel-approved">
            <?php if (empty($approvedOrders)): ?>
                <div class="fin-empty"><i class="fas fa-inbox"></i><div>No confirmed payments yet.</div></div>
            <?php else: ?>
                <div style="overflow-x:auto">
                    <table class="fin-table">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Customer</th>
                                <th>Total Amount</th>
                                <th>Verified Amount</th>
                                <th>Payment Method</th>
                                <th>Confirmed By</th>
                                <th>Confirmed At</th>
                                <th>Invoice</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($approvedOrders as $row): ?>
                                <tr>
                                    <td style="font-weight:700">#<?= (int)$row['id'] ?></td>
                                    <td style="font-weight:600"><?= htmlspecialchars($row['customer_name'] ?? '—') ?></td>
                                    <td><?= finFmt((float)$row['total_amount']) ?></td>
                                    <td style="font-weight:700;color:var(--fin-green)"><?= $row['verified_amount'] ? finFmt((float)$row['verified_amount']) : '—' ?></td>
                                    <td><?= fpMethodBadge($row['payment_method'] ?? '') ?></td>
                                    <td style="font-size:.78rem"><?= htmlspecialchars($row['confirmed_by_name'] ?? '—') ?></td>
                                    <td style="font-size:.78rem;color:var(--fin-gray)"><?= $row['payment_confirmed_at'] ? date('M d, Y H:i', strtotime($row['payment_confirmed_at'])) : '—' ?></td>
                                    <td>
                                        <?php if ($row['inv_id']): ?>
                                            <a href="?page=invoice&id=<?= (int)$row['inv_id'] ?>" target="_blank" class="fin-btn fin-btn-sm fin-btn-outline"><i class="fas fa-file-invoice"></i></a>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="fin-panel" id="fp-panel-rejected">
            <?php if (empty($rejectedOrders)): ?>
                <div class="fin-empty"><i class="fas fa-check-circle" style="color:var(--fin-green)"></i><div>No rejected payments.</div></div>
            <?php else: ?>
                <div style="overflow-x:auto">
                    <table class="fin-table">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Customer</th>
                                <th>Total Amount</th>
                                <th>Payment Method</th>
                                <th>Rejection Reason</th>
                                <th>Verified By</th>
                                <th>Rejected At</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rejectedOrders as $row): ?>
                                <tr>
                                    <td style="font-weight:700">#<?= (int)$row['id'] ?></td>
                                    <td style="font-weight:600"><?= htmlspecialchars($row['customer_name'] ?? '—') ?></td>
                                    <td><?= finFmt((float)$row['total_amount']) ?></td>
                                    <td><?= fpMethodBadge($row['payment_method'] ?? '') ?></td>
                                    <td style="color:#991b1b;font-size:.78rem;max-width:250px"><?= htmlspecialchars($row['payment_rejection_reason'] ?? '—') ?></td>
                                    <td style="font-size:.78rem"><?= htmlspecialchars($row['verified_by_name'] ?? '—') ?></td>
                                    <td style="font-size:.78rem;color:var(--fin-gray)"><?= $row['verified_at'] ? date('M d, Y H:i', strtotime($row['verified_at'])) : '—' ?></td>
                                    <td>
                                        <button class="fin-btn fin-btn-sm fin-btn-outline" onclick="fpShowRejectDetail(<?= $oid = (int)$row['id'] ?>)"><i class="fas fa-eye"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php foreach ($rejectedOrders as $row): ?>
                    <?php $oid = (int)$row['id']; $logs = $logByOrder[$oid] ?? []; if (!empty($logs)): ?>
                        <div class="fp-order-card" style="margin-top:14px;border-left-color:var(--fin-red)" id="reject-detail-<?= $oid ?>" hidden>
                            <div class="fp-order-id">Order #<?= $oid ?> <span class="fin-badge fin-badge-red">Rejected</span></div>
                            <div class="fp-rejection-box">
                                <strong style="color:#991b1b"><i class="fas fa-exclamation-triangle"></i> Rejection Reason:</strong>
                                <?= htmlspecialchars($row['payment_rejection_reason'] ?? '—') ?>
                            </div>
                            <div class="fp-log-section">
                                <strong style="color:#475569"><i class="fas fa-history"></i> Payment Log</strong>
                                <?php foreach ($logs as $log): ?>
                                    <div class="fp-log-item">
                                        <div class="fp-log-dot" style="background:<?= $log['action'] === 'payment_rejected' ? 'var(--fin-red)' : 'var(--fin-orange)' ?>"></div>
                                        <div>
                                            <span style="font-weight:600"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $log['action']))) ?></span>
                                            <span style="color:var(--fin-gray)">by <?= htmlspecialchars($log['performed_by_name'] ?? 'System') ?></span>
                                            <span style="color:#94a3b8"><?= date('M d, Y H:i', strtotime($log['created_at'])) ?></span>
                                            <?php if ($log['notes']): ?><div style="color:var(--fin-gray);font-style:italic;margin-top:2px"><?= htmlspecialchars($log['notes']) ?></div><?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="fin-panel" id="fp-panel-history">
            <div class="fin-card" style="margin-bottom:16px;padding:16px">
                <h2 style="margin-bottom:12px"><i class="fas fa-list" style="color:var(--fin-blue)"></i> Recent Payment Activity</h2>
                <?php if (empty($paymentLogs)): ?>
                    <div class="fin-empty"><i class="fas fa-inbox"></i><div>No payment activity recorded yet.</div></div>
                <?php else: ?>
                    <div style="overflow-x:auto">
                        <table class="fin-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Order #</th>
                                    <th>Customer</th>
                                    <th>Action</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>By</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($paymentLogs as $log): ?>
                                    <tr>
                                        <td style="font-size:.78rem;color:var(--fin-gray);white-space:nowrap"><?= date('M d, Y H:i', strtotime($log['created_at'])) ?></td>
                                        <td style="font-weight:700">#<?= (int)$log['customer_order_id'] ?></td>
                                        <td style="font-weight:600"><?= htmlspecialchars($log['customer_name'] ?? '—') ?></td>
                                        <td>
                                            <?php
                                            $actionColors = [
                                                'payment_confirmed' => 'fin-badge-green',
                                                'payment_rejected' => 'fin-badge-red',
                                                'payment_unconfirmed' => 'fin-badge-orange',
                                                'payment_verified' => 'fin-badge-blue',
                                                'manual_payment_recorded' => 'fin-badge-purple',
                                            ];
                                            $aColor = $actionColors[$log['action']] ?? 'fin-badge-gray';
                                            ?>
                                            <span class="fin-badge <?= $aColor ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $log['action']))) ?></span>
                                        </td>
                                        <td style="font-weight:700;color:var(--fin-green)"><?= $log['amount'] ? finFmt((float)$log['amount']) : '—' ?></td>
                                        <td style="font-size:.78rem"><?= $log['payment_method'] ? htmlspecialchars(ucwords(str_replace('_', ' ', $log['payment_method']))) : '—' ?></td>
                                        <td style="font-size:.78rem"><?= htmlspecialchars($log['performed_by_name'] ?? 'System') ?></td>
                                        <td style="font-size:.78rem;color:var(--fin-gray);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($log['notes'] ?? '') ?>"><?= htmlspecialchars($log['notes'] ?? '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (hasPermission('finance_payments', 'process')): ?>
        <div class="fin-panel" id="fp-panel-make">
            <div class="fin-card" style="padding:24px">
                <h2><i class="fas fa-plus-circle" style="color:var(--fin-green)"></i> Record a Payment Against Invoice</h2>
                <p style="font-size:.82rem;color:var(--fin-gray);margin-bottom:20px">Manually record a payment received for an outstanding invoice. This will update the invoice ledger automatically.</p>

                <?php if (empty($unpaidInvoices)): ?>
                    <div class="fin-empty"><i class="fas fa-check-circle" style="color:var(--fin-green)"></i><div>All invoices are paid! No outstanding balances.</div></div>
                <?php else: ?>
                    <form method="post" onsubmit="return confirm('Record this payment?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="make_payment" value="1">

                        <div style="overflow-x:auto;margin-bottom:20px">
                            <table class="fin-table">
                                <thead>
                                    <tr>
                                        <th>Select</th>
                                        <th>Invoice #</th>
                                        <th>Customer</th>
                                        <th>Invoice Total</th>
                                        <th>Amount Paid</th>
                                        <th>Outstanding</th>
                                        <th>Status</th>
                                        <th>Due Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($unpaidInvoices as $inv): ?>
                                        <tr>
                                            <td>
                                                <input type="radio" name="invoice_id" value="<?= (int)$inv['id'] ?>" required style="width:18px;height:18px;cursor:pointer" onchange="fpSelectInvoice(<?= (int)$inv['id'] ?>, <?= (float)$inv['outstanding_balance'] ?>)">
                                            </td>
                                            <td style="font-weight:700"><?= htmlspecialchars($inv['invoice_no'] ?? '#' . $inv['id']) ?></td>
                                            <td style="font-weight:600"><?= htmlspecialchars($inv['customer_name'] ?? '—') ?></td>
                                            <td><?= finFmt((float)$inv['total_amount']) ?></td>
                                            <td style="color:var(--fin-green)"><?= finFmt((float)$inv['amount_paid']) ?></td>
                                            <td style="color:var(--fin-red);font-weight:700" id="outstanding-<?= (int)$inv['id'] ?>"><?= finFmt((float)$inv['outstanding_balance']) ?></td>
                                            <td>
                                                <span class="fin-badge <?= $inv['payment_status'] === 'paid' ? 'fin-badge-green' : 'fin-badge-orange' ?>">
                                                    <?= ucfirst($inv['payment_status']) ?>
                                                </span>
                                            </td>
                                            <td style="font-size:.78rem;color:var(--fin-gray)"><?= $inv['credit_due_date'] ? date('M d, Y', strtotime($inv['credit_due_date'])) : '—' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="fp-detail-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));background:#f8fafc;border-radius:10px;padding:16px;border:1px solid var(--fin-border)">
                            <div class="fp-form-row" style="flex:none;width:100%;gap:14px">
                                <div style="flex:1;min-width:160px">
                                    <label style="font-size:.8rem;font-weight:700;color:#475569;margin-bottom:6px;display:block">Payment Amount *</label>
                                    <input type="number" name="pay_amount" id="fp-pay-amount" step="0.01" min="0.01" required placeholder="0.00" style="width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.95rem;font-weight:700;font-family:inherit">
                                </div>
                                <div style="flex:1;min-width:160px">
                                    <label style="font-size:.8rem;font-weight:700;color:#475569;margin-bottom:6px;display:block">Payment Method *</label>
                                    <select name="pay_method" required style="width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.88rem;font-family:inherit">
                                        <option value="cash">Cash</option>
                                        <option value="bank">Bank Transfer</option>
                                        <option value="cbe_birr">CBE Birr</option>
                                        <option value="telebirr">TeleBirr</option>
                                    </select>
                                </div>
                                <div style="flex:1;min-width:160px">
                                    <label style="font-size:.8rem;font-weight:700;color:#475569;margin-bottom:6px;display:block">Transaction Reference</label>
                                    <input type="text" name="pay_ref" placeholder="e.g. Receipt #, Ref code" style="width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.88rem;font-family:inherit">
                                </div>
                            </div>
                        </div>
                        <div style="margin-top:12px">
                            <label style="font-size:.8rem;font-weight:700;color:#475569;margin-bottom:6px;display:block">Notes</label>
                            <textarea name="pay_notes" rows="2" placeholder="Optional payment notes..." style="width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.85rem;font-family:inherit;resize:vertical"></textarea>
                        </div>
                        <div style="margin-top:16px;display:flex;justify-content:flex-end;gap:10px">
                            <button type="reset" class="fin-btn fin-btn-outline"><i class="fas fa-undo"></i> Reset</button>
                            <button type="submit" class="fin-btn fin-btn-green"><i class="fas fa-save"></i> Record Payment</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>

<script>
function fpToggleReceipt(el) {
    var img = el.nextElementSibling;
    if (img) {
        if (img.style.display === 'none' || !img.style.display) {
            img.style.display = 'block';
            el.innerHTML = '<i class="fas fa-eye-slash"></i> Hide Screenshot';
        } else {
            img.style.display = 'none';
            el.innerHTML = '<i class="fas fa-image"></i> View Payment Screenshot';
        }
    }
}
function fpToggleReject(id) {
    var el = document.getElementById('reject-' + id);
    if (el) el.classList.toggle('active');
}
function fpToggleVerify(id) {
    var el = document.getElementById('verify-' + id);
    if (el) el.classList.toggle('active');
}
function fpShowRejectDetail(id) {
    var el = document.getElementById('reject-detail-' + id);
    if (el) {
        if (el.hidden) {
            el.hidden = false;
            el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } else {
            el.hidden = true;
        }
    }
}
function fpSelectInvoice(id, outstanding) {
    var amtField = document.getElementById('fp-pay-amount');
    if (amtField) {
        amtField.value = outstanding.toFixed(2);
        amtField.max = outstanding;
    }
}
</script>
<?= finJs() ?>
