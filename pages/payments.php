<?php
/**
 * Customer Payments
 *
 * B2B payment centre for a logged-in customer account:
 *   - overview of outstanding / paid / credit balance
 *   - how-to-pay method cards
 *   - invoice list (unpaid / partial / paid / overdue) with due, paid, outstanding
 *   - unified payment history with verification status
 *   - submit a payment (cash / bank / mobile) with evidence upload; it is held
 *     PENDING until a Finance officer verifies it (never auto-settles a ledger)
 *   - printable receipts for recorded & verified transactions only
 */
require_once __DIR__ . '/../services/init.php';
require_once __DIR__ . '/../includes/workflow_helpers.php';
require_once __DIR__ . '/../includes/auto_migrate.php';

/* ─────────────────────────────────────────────────────────────────────────
 * Standalone printable receipt.
 * Routed early by index.php (?page=payments&receipt=…&rtype=…) so it renders
 * without the site chrome. Only VERIFIED transactions are receiptable.
 * ───────────────────────────────────────────────────────────────────────── */
if (isset($_GET['receipt'])) {
    requireLogin();
    $rpdo = getPDO();
    ensureCustomerPaymentSubmissionMigration($rpdo);
    $rcid = customerAccountCustomerId();
    if ($rcid === null) { http_response_code(403); exit('Forbidden'); }
    payment_render_receipt($rpdo, $rcid, (string) $_GET['receipt'], (string) ($_GET['rtype'] ?? 'submission'));
    exit;
}

requireCustomer();
$pdo = getPDO();
$cid = customerAccountCustomerId();
if ($cid === null) {
    echo '<div class="panel"><p>Your account is not linked to a customer profile. Please contact the administrator.</p></div>';
    return;
}

ensureCustomerPaymentSubmissionMigration($pdo);
$uid = (int) ($_SESSION['user']['id'] ?? 0);

/* ── Payment method catalogue ─────────────────────────────────────────── */
$PAY_METHODS = [
    'cash'     => ['label' => 'Cash',          'icon' => 'fa-money-bill-wave',       'desc' => 'Pay in cash at the counter. A Finance officer records it once received.'],
    'bank'     => ['label' => 'Bank Transfer', 'icon' => 'fa-building-columns',      'desc' => 'Transfer to our bank account and attach the deposit slip or transfer confirmation.'],
    'cbe_birr' => ['label' => 'CBE Birr',      'icon' => 'fa-mobile-screen-button',  'desc' => 'Pay through your CBE Birr wallet and attach the confirmation message.'],
    'telebirr' => ['label' => 'Telebirr',      'icon' => 'fa-mobile-screen-button',  'desc' => 'Pay through your Telebirr wallet and attach the confirmation message.'],
    'credit'   => ['label' => 'Credit Account','icon' => 'fa-hand-holding-dollar',   'desc' => 'Buy now, pay later. Available only to approved credit accounts within their limit.'],
];
$submittableMethods = ['cash', 'bank', 'cbe_birr', 'telebirr'];

$creditSalesEnabled = CreditService::isSalesEnabled();

/* ─────────────────────────────────────────────────────────────────────────
 * POST: submit / cancel a payment
 * ───────────────────────────────────────────────────────────────────────── */
$payMsg = '';
$payMsgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['_action'] ?? '';

    if ($action === 'submit_payment') {
        $invId  = (int) ($_POST['invoice_id'] ?? 0);
        $amount = round((float) ($_POST['pay_amount'] ?? 0), 2);
        $method = (string) ($_POST['payment_method'] ?? '');
        $ref    = trim((string) ($_POST['reference'] ?? ''));
        $notes  = trim((string) ($_POST['payment_notes'] ?? ''));
        $errors = [];

        if ($invId <= 0) $errors[] = 'Please select an invoice to pay.';
        if (!in_array($method, $submittableMethods, true)) $errors[] = 'Please choose a valid payment method.';
        if ($amount <= 0) $errors[] = 'Enter a payment amount greater than zero.';
        if ($method !== 'cash' && $ref === '') $errors[] = 'A transaction reference is required for ' . formatPaymentMethod($method) . '.';
        if (mb_strlen($notes) > 500) $notes = mb_substr($notes, 0, 500);

        $inv = null;
        if (!$errors) {
            $st = $pdo->prepare('SELECT id, invoice_no, total_amount, amount_paid, outstanding_balance, payment_status, customer_order_id FROM invoices WHERE id = :id AND customer_id = :cid LIMIT 1');
            $st->execute(['id' => $invId, 'cid' => $cid]);
            $inv = $st->fetch();
            if (!$inv) {
                $errors[] = 'That invoice was not found on your account.';
            } elseif ((float) $inv['outstanding_balance'] <= 0) {
                $errors[] = 'This invoice has no outstanding balance.';
            } elseif ($amount > (float) $inv['outstanding_balance'] + 0.001) {
                $errors[] = 'The amount cannot exceed the outstanding balance of ' . fmt((float) $inv['outstanding_balance']) . '.';
            }
        }

        // Duplicate guard: identical pending claim for the same invoice.
        if (!$errors) {
            $dup = $pdo->prepare("SELECT COUNT(*) FROM customer_payment_submissions WHERE customer_id = :cid AND invoice_id = :iid AND amount = :amt AND COALESCE(reference_no,'') = :ref AND status = 'pending'");
            $dup->execute(['cid' => $cid, 'iid' => $invId, 'amt' => $amount, 'ref' => $ref]);
            if ((int) $dup->fetchColumn() > 0) {
                $errors[] = 'A payment of this amount with the same reference is already awaiting verification.';
            }
        }

        // Secure evidence upload.
        $receiptPath = null;
        if (!$errors && !empty($_FILES['receipt']['name'])) {
            $upErr = null;
            $receiptPath = payment_store_upload($_FILES['receipt'], $upErr);
            if ($upErr) $errors[] = $upErr;
        }
        if (!$errors && $method === 'bank' && !$receiptPath) {
            $errors[] = 'Please attach proof of payment (deposit slip or transfer confirmation) for a bank transfer.';
        }

        if ($errors) {
            $payMsg = implode(' ', $errors);
            $payMsgType = 'error';
        } else {
            $ins = $pdo->prepare("INSERT INTO customer_payment_submissions (customer_id, invoice_id, amount, payment_method, reference_no, receipt_path, notes, status) VALUES (:cid, :iid, :amt, :meth, :ref, :rcpt, :notes, 'pending')");
            $ins->execute([
                'cid' => $cid, 'iid' => $invId, 'amt' => $amount, 'meth' => $method,
                'ref' => $ref !== '' ? $ref : null, 'rcpt' => $receiptPath,
                'notes' => $notes !== '' ? $notes : null,
            ]);
            logPaymentAction($pdo, (int) $inv['customer_order_id'], $cid, 'customer_payment_submitted', $amount, $method, $ref, $uid, 'Awaiting finance verification');
            try {
                NotificationService::finance($pdo, null, null, 'Customer payment awaiting verification',
                    'A ' . formatPaymentMethod($method) . ' payment of ' . fmt($amount) . ' for invoice ' . $inv['invoice_no'] . ' is awaiting verification.', 'info');
            } catch (Throwable $e) { /* notification is best-effort */ }
            $payMsg = 'Payment of ' . fmt($amount) . ' submitted for invoice ' . $inv['invoice_no'] . '. It is now pending Finance verification.';
            $payMsgType = 'success';
        }
    } elseif ($action === 'cancel_submission') {
        $sid = (int) ($_POST['submission_id'] ?? 0);
        $upd = $pdo->prepare("UPDATE customer_payment_submissions SET status = 'cancelled', reviewed_by = :uid, reviewed_at = NOW() WHERE id = :id AND customer_id = :cid AND status = 'pending'");
        $upd->execute(['uid' => $uid, 'id' => $sid, 'cid' => $cid]);
        if ($upd->rowCount() > 0) {
            $payMsg = 'Pending payment cancelled.';
            $payMsgType = 'success';
        } else {
            $payMsg = 'That pending payment could not be found.';
            $payMsgType = 'error';
        }
    }
}

/* ── Credit profile (single source of truth) ──────────────────────────── */
$profile = CreditService::profile($pdo, $cid);
$creditLimit     = (float) ($profile['credit_limit'] ?? 0);
$creditUsed      = (float) ($profile['credit_used'] ?? 0);
$creditAvailable = (float) ($profile['available_credit'] ?? 0);
$creditApproved  = ((int) ($profile['is_credit_approved'] ?? 0) === 1)
    && (($profile['credit_approval_status'] ?? 'pending') === 'approved');

/* ── Invoices + ledger ────────────────────────────────────────────────── */
$invStmt = $pdo->prepare(
    "SELECT i.*, co.id AS order_id, co.status AS order_status, co.payment_method AS order_payment_method,
            co.payment_confirmed, co.verified_amount, co.verified_at, co.transaction_ref AS order_ref,
            co.payment_rejection_reason,
            COALESCE(vu.full_name, vu.username) AS verified_by_name
     FROM invoices i
     LEFT JOIN customer_orders co ON i.customer_order_id = co.id
     LEFT JOIN users vu ON co.verified_by = vu.id
     WHERE i.customer_id = :cid
     ORDER BY i.generated_at DESC"
);
$invStmt->execute(['cid' => $cid]);
$invoices = $invStmt->fetchAll();

$today = strtotime(date('Y-m-d'));
$unpaidCount = 0; $partialCount = 0; $paidCount = 0; $overdueCount = 0;
$outstandingTotal = 0.0; $paidTotal = 0.0;
foreach ($invoices as $inv) {
    $st = $inv['payment_status'];
    $due = $inv['credit_due_date'];
    $isOverdue = $st !== 'paid' && $due && strtotime($due) < $today;
    if ($st === 'paid') { $paidCount++; }
    elseif ($isOverdue) { $overdueCount++; }
    elseif ($st === 'partial') { $partialCount++; }
    else { $unpaidCount++; }
    if ($st !== 'paid') $outstandingTotal += (float) $inv['outstanding_balance'];
    $paidTotal += (float) $inv['amount_paid'];
}

$invIds = array_column($invoices, 'id');
$paymentsByInvoice = [];
$subsByInvoice = [];
if (!empty($invIds)) {
    $ph = implode(',', array_fill(0, count($invIds), '?'));
    $pp = $pdo->prepare("SELECT ip.*, COALESCE(u.full_name, u.username) AS recorded_by_name FROM invoice_payments ip LEFT JOIN users u ON ip.recorded_by = u.id WHERE ip.invoice_id IN ($ph) ORDER BY ip.created_at ASC");
    $pp->execute($invIds);
    foreach ($pp->fetchAll() as $row) { $paymentsByInvoice[(int) $row['invoice_id']][] = $row; }

    $sp = $pdo->prepare("SELECT s.*, COALESCE(u.full_name, u.username) AS reviewed_by_name, i.invoice_no FROM customer_payment_submissions s LEFT JOIN users u ON s.reviewed_by = u.id LEFT JOIN invoices i ON s.invoice_id = i.id WHERE s.invoice_id IN ($ph) ORDER BY s.created_at ASC");
    $sp->execute($invIds);
    foreach ($sp->fetchAll() as $row) {
        // Verified submissions are already represented in the invoice_payments ledger,
        // and cancelled ones were withdrawn by the customer — include only
        // pending/rejected ones here to avoid double counting.
        if ($row['status'] === 'verified' || $row['status'] === 'cancelled') continue;
        $subsByInvoice[(int) $row['invoice_id']][] = $row;
    }
}

/* ── All submissions for this customer ────────────────────────────────── */
$allSubs = $pdo->prepare("SELECT s.*, i.invoice_no, i.customer_order_id FROM customer_payment_submissions s LEFT JOIN invoices i ON s.invoice_id = i.id WHERE s.customer_id = :cid AND s.status <> 'cancelled' ORDER BY s.created_at DESC");
$allSubs->execute(['cid' => $cid]);
$allSubs = $allSubs->fetchAll();

/* ── Order-level payments (submitted at Place Order) ──────────────────── */
$ordStmt = $pdo->prepare(
    "SELECT co.id, co.status, co.total_amount, co.payment_method, co.transaction_ref, co.payment_confirmed,
            co.verified_amount, co.payment_confirmed_at, co.verified_at, co.payment_rejection_reason, co.created_at,
            i.invoice_no, COALESCE(vu.full_name, vu.username) AS verified_by_name
     FROM customer_orders co
     LEFT JOIN invoices i ON i.customer_order_id = co.id
     LEFT JOIN users vu ON co.verified_by = vu.id
     WHERE co.customer_id = :cid AND co.salesperson_id IS NULL
     ORDER BY co.created_at DESC"
);
$ordStmt->execute(['cid' => $cid]);
$orders = $ordStmt->fetchAll();

$pendingOrderCount = 0; $rejectedOrderCount = 0;
foreach ($orders as $o) {
    if ((int) $o['payment_confirmed'] === 1) continue;
    if (!empty($o['payment_rejection_reason']) || $o['status'] === 'rejected' || $o['status'] === 'cancelled') { $rejectedOrderCount++; }
    else { $pendingOrderCount++; }
}
$pendingSubCount = 0; $rejectedSubCount = 0;
foreach ($allSubs as $s) {
    if ($s['status'] === 'pending') $pendingSubCount++;
    elseif ($s['status'] === 'rejected') $rejectedSubCount++;
}
$pendingTotal = $pendingOrderCount + $pendingSubCount;

/* ── Unified history ──────────────────────────────────────────────────── */
$history = [];
foreach ($orders as $o) {
    $od = (int) $o['payment_confirmed'] === 1 ? ($o['payment_confirmed_at'] ?: $o['created_at']) : $o['created_at'];
    if ((int) $o['payment_confirmed'] === 1) {
        $status = 'verified';
        $amt = $o['verified_amount'] !== null ? (float) $o['verified_amount'] : (float) $o['total_amount'];
    } elseif (!empty($o['payment_rejection_reason']) || $o['status'] === 'rejected' || $o['status'] === 'cancelled') {
        $status = 'rejected';
        $amt = (float) $o['total_amount'];
    } else {
        $status = 'pending';
        $amt = (float) $o['total_amount'];
    }
    $history[] = [
        'sort' => strtotime($od) ?: 0,
        'date' => $od,
        'title' => 'Order #' . (int) $o['id'],
        'subtitle' => $o['invoice_no'] ? 'Invoice ' . $o['invoice_no'] : 'Order payment',
        'amount' => $amt,
        'method' => $o['payment_method'],
        'reference' => $o['transaction_ref'],
        'status' => $status,
        'reason' => $o['payment_rejection_reason'],
        'receipt_type' => 'order',
        'receipt_id' => (int) $o['id'],
        'order_no' => (int) $o['id'],
        'invoice_no' => $o['invoice_no'],
        'receiptable' => $status === 'verified',
    ];
}
foreach ($allSubs as $s) {
    $history[] = [
        'sort' => strtotime($s['created_at']) ?: 0,
        'date' => $s['created_at'],
        'title' => $s['invoice_no'] ? 'Invoice ' . $s['invoice_no'] : 'Payment',
        'subtitle' => 'Order #' . (int) ($s['customer_order_id'] ?? 0) ? 'Top-up payment' : 'Top-up payment',
        'amount' => (float) $s['amount'],
        'method' => $s['payment_method'],
        'reference' => $s['reference_no'],
        'status' => $s['status'],
        'reason' => $s['rejection_reason'],
        'receipt_type' => 'submission',
        'receipt_id' => (int) $s['id'],
        'order_no' => (int) ($s['customer_order_id'] ?? 0),
        'invoice_no' => $s['invoice_no'],
        'receiptable' => $s['status'] === 'verified',
    ];
}
usort($history, function ($a, $b) { return $b['sort'] <=> $a['sort']; });

/* ── Tab / filter state ───────────────────────────────────────────────── */
$tab = $_GET['tab'] ?? 'overview';
if (!in_array($tab, ['overview', 'invoices', 'history', 'make'], true)) $tab = 'overview';
$statusFilter = $_GET['status'] ?? 'all';
if (!in_array($statusFilter, ['all', 'unpaid', 'partial', 'paid', 'overdue'], true)) $statusFilter = 'all';
if ($payMsgType === 'success' && $tab === 'make') $tab = 'history';
?>
<style>
.pay{max-width:1180px;margin:0 auto}
.pay-head{margin-bottom:22px}
.pay-title{font-size:1.45rem;font-weight:800;color:#0f172a;margin:0;display:flex;align-items:center;gap:11px}
.pay-title i{color:#2563eb}
.pay-sub{font-size:.86rem;color:#64748b;margin-top:3px}
.pay-msg{border-radius:11px;padding:13px 18px;margin-bottom:18px;font-weight:600;font-size:.88rem;display:flex;align-items:flex-start;gap:10px;line-height:1.45}
.pay-msg.success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}
.pay-msg.error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.pay-msg i{margin-top:2px}

.pay-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px}
.pay-stat{background:#fff;border-radius:14px;padding:17px 19px;border:1px solid #e2e8f0;display:flex;align-items:center;gap:14px;box-shadow:0 1px 3px rgba(15,23,42,.04)}
.pay-stat-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.pay-stat-val{font-size:1.22rem;font-weight:800;color:#0f172a;line-height:1.15}
.pay-stat-label{font-size:.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.03em;margin-top:2px}

.pay-tabs{display:flex;gap:4px;border-bottom:2px solid #e2e8f0;margin-bottom:22px;overflow-x:auto}
.pay-tab{padding:11px 20px;font-size:.88rem;font-weight:700;color:#64748b;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;text-decoration:none;display:flex;align-items:center;gap:7px;white-space:nowrap;transition:color .15s,border-color .15s}
.pay-tab:hover{color:#1e293b}
.pay-tab.active{color:#2563eb;border-bottom-color:#2563eb}
.pay-tab-count{background:#f1f5f9;padding:2px 8px;border-radius:11px;font-size:.71rem;font-weight:800}
.pay-tab.active .pay-tab-count{background:#eff6ff;color:#2563eb}
.pay-tab-count.warn{background:#fef3c7;color:#b45309}

.pay-card{background:#fff;border-radius:14px;border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(15,23,42,.04);margin-bottom:18px;overflow:hidden}
.pay-card-head{padding:16px 22px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.pay-card-title{font-size:.96rem;font-weight:800;color:#0f172a;display:flex;align-items:center;gap:9px}
.pay-card-title i{color:#2563eb}
.pay-card-body{padding:20px 22px}

.pay-methods{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px}
.pay-method{border:1.5px solid #e2e8f0;border-radius:13px;padding:16px;background:#fbfcfe;transition:border-color .15s,transform .15s}
.pay-method:hover{border-color:#bfdbfe;transform:translateY(-2px)}
.pay-method-icon{width:42px;height:42px;border-radius:11px;background:#eff6ff;color:#2563eb;display:flex;align-items:center;justify-content:center;font-size:1rem;margin-bottom:10px}
.pay-method-name{font-weight:800;font-size:.92rem;color:#0f172a}
.pay-method-desc{font-size:.79rem;color:#64748b;margin-top:5px;line-height:1.5}
.pay-method.unavailable{opacity:.62}
.pay-method.unavailable .pay-method-icon{background:#f1f5f9;color:#94a3b8}
.pay-method-flag{display:inline-flex;align-items:center;gap:4px;font-size:.68rem;font-weight:800;padding:3px 9px;border-radius:20px;margin-top:9px}
.pay-method-flag.ok{background:#f0fdf4;color:#15803d}
.pay-method-flag.no{background:#fef2f2;color:#b91c1c}

.pay-alert{border-radius:12px;padding:14px 18px;margin-bottom:14px;font-size:.85rem;display:flex;gap:11px;align-items:flex-start;line-height:1.5}
.pay-alert.warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e}
.pay-alert.danger{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.pay-alert.info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}
.pay-alert strong{font-weight:800}

.pay-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 11px;border-radius:20px;font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.02em}
.pay-badge.verified,.pay-badge.paid{background:#f0fdf4;color:#15803d}
.pay-badge.pending,.pay-badge.partial{background:#fffbeb;color:#b45309}
.pay-badge.rejected,.pay-badge.unpaid,.pay-badge.overdue{background:#fef2f2;color:#dc2626}
.pay-badge.overdue{animation:paypulse 1.6s ease infinite}
@keyframes paypulse{0%,100%{opacity:1}50%{opacity:.55}}

.pay-chips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}
.pay-chip{padding:7px 15px;border-radius:20px;border:1.5px solid #e2e8f0;background:#fff;color:#475569;font-size:.8rem;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:all .15s}
.pay-chip:hover{border-color:#93c5fd;color:#1e293b}
.pay-chip.active{background:#2563eb;border-color:#2563eb;color:#fff}
.pay-chip .n{background:rgba(0,0,0,.08);border-radius:10px;padding:1px 7px;font-size:.7rem}
.pay-chip.active .n{background:rgba(255,255,255,.22)}

.inv-card{background:#fff;border-radius:14px;border:1px solid #e2e8f0;margin-bottom:16px;overflow:hidden;box-shadow:0 1px 3px rgba(15,23,42,.04)}
.inv-head{padding:15px 20px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
.inv-no{font-size:1rem;font-weight:800;color:#0f172a}
.inv-order{font-size:.76rem;color:#64748b;margin-top:2px}
.inv-body{padding:18px 20px}
.inv-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:8px}
.inv-cell{background:#f8fafc;border-radius:10px;padding:11px 12px;text-align:center}
.inv-cell-v{font-size:1.05rem;font-weight:800;color:#0f172a}
.inv-cell-v.green{color:#16a34a}.inv-cell-v.red{color:#dc2626}.inv-cell-v.blue{color:#2563eb}
.inv-cell-l{font-size:.68rem;font-weight:700;color:#64748b;text-transform:uppercase;margin-top:3px;letter-spacing:.03em}
.inv-acts{display:flex;gap:9px;flex-wrap:wrap;margin-top:14px}
.inv-ledger{margin-top:14px;border-top:1px solid #f1f5f9;padding-top:13px}
.inv-ledger h4{font-size:.79rem;font-weight:800;color:#475569;margin:0 0 9px;display:flex;align-items:center;gap:7px}
.inv-ledger h4 i{color:#2563eb}
.led-row{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:9px 12px;background:#f8fafc;border-radius:8px;margin-bottom:6px;font-size:.82rem;flex-wrap:wrap}
.led-row .l{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.led-row .d{color:#64748b;min-width:118px}
.led-row .m{color:#475569;font-weight:700}
.led-row .r{color:#94a3b8}
.led-row .a{color:#16a34a;font-weight:800;margin-left:auto}

.pay-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:10px 17px;border-radius:9px;font-weight:700;font-size:.83rem;cursor:pointer;transition:all .15s;border:none;font-family:inherit;text-decoration:none;min-height:42px}
.pay-btn-primary{background:#2563eb;color:#fff}.pay-btn-primary:hover{background:#1d4ed8}
.pay-btn-success{background:#16a34a;color:#fff}.pay-btn-success:hover{background:#15803d}
.pay-btn-outline{background:#fff;color:#1e293b;border:1.5px solid #e2e8f0}.pay-btn-outline:hover{border-color:#2563eb;color:#2563eb}
.pay-btn-danger{background:#fff;color:#dc2626;border:1.5px solid #fecaca}.pay-btn-danger:hover{background:#fef2f2}
.pay-btn-sm{padding:8px 13px;font-size:.79rem;min-height:38px}
.pay-btn:disabled{opacity:.55;cursor:not-allowed}

.pay-table{width:100%;border-collapse:collapse}
.pay-table th{padding:11px 14px;text-align:left;font-size:.71rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#64748b;background:#f8fafc;border-bottom:1px solid #e2e8f0;white-space:nowrap}
.pay-table td{padding:12px 14px;font-size:.84rem;color:#1e293b;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.pay-table tr:last-child td{border-bottom:none}
.pay-table tr:hover td{background:#f8fafc}

.pay-empty{text-align:center;padding:44px 20px;color:#94a3b8}
.pay-empty i{font-size:2.1rem;color:#cbd5e1;margin-bottom:10px;display:block}
.pay-empty p{font-size:.87rem;margin:3px 0}

.pay-fg{margin-bottom:16px}
.pay-fg label{display:block;font-size:.8rem;font-weight:700;color:#334155;margin-bottom:6px}
.pay-fg select,.pay-fg input[type=text],.pay-fg input[type=number],.pay-fg textarea{width:100%;padding:11px 14px;border:1.5px solid #e2e8f0;border-radius:9px;font:inherit;font-size:.9rem;color:#0f172a;background:#fff;box-sizing:border-box}
.pay-fg select:focus,.pay-fg input:focus,.pay-fg textarea:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.pay-fg .hint{font-size:.75rem;color:#64748b;margin-top:5px;display:block}
.pay-fg input[type=file]{width:100%;padding:10px;border:1.5px dashed #cbd5e1;border-radius:9px;background:#fbfcfe;font-size:.82rem;box-sizing:border-box}
.pay-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.pay-readout{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px}
.pay-readout .box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 15px}
.pay-readout .box .k{font-size:.7rem;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.03em}
.pay-readout .box .v{font-size:1.08rem;font-weight:800;color:#0f172a;margin-top:3px}
.pay-readout .box .v.red{color:#dc2626}

/* method radio cards */
.pay-mgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
.pay-mcard{position:relative;border:1.5px solid #e2e8f0;border-radius:12px;padding:14px;cursor:pointer;background:#fff;transition:all .15s;display:block}
.pay-mcard:hover{border-color:#93c5fd}
.pay-mcard input{position:absolute;opacity:0;pointer-events:none}
.pay-mcard .mi{width:38px;height:38px;border-radius:10px;background:#eff6ff;color:#2563eb;display:flex;align-items:center;justify-content:center;margin-bottom:9px}
.pay-mcard .mn{font-weight:800;font-size:.88rem;color:#0f172a}
.pay-mcard .md{font-size:.75rem;color:#64748b;margin-top:4px;line-height:1.45}
.pay-mcard.sel{border-color:#2563eb;background:#f5f9ff;box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.pay-mcard.sel .mi{background:#2563eb;color:#fff}

@media(max-width:900px){
  .pay-stats{grid-template-columns:1fr 1fr}
  .inv-grid{grid-template-columns:1fr 1fr}
}
@media(max-width:640px){
  .pay-stats{grid-template-columns:1fr}
  .inv-grid{grid-template-columns:1fr 1fr}
  .pay-row,.pay-readout{grid-template-columns:1fr}
  .pay-tab{padding:9px 13px;font-size:.82rem}
}
</style>

<section class="pay">
    <div class="pay-head">
        <h1 class="pay-title"><i class="fas fa-credit-card"></i> Payments</h1>
        <div class="pay-sub">Review invoices, settle balances and track every payment's verification status.</div>
    </div>

    <?php if ($payMsg): ?>
        <div class="pay-msg <?= $payMsgType === 'success' ? 'success' : 'error' ?>">
            <i class="fas fa-<?= $payMsgType === 'success' ? 'circle-check' : 'circle-exclamation' ?>"></i>
            <span><?= htmlspecialchars($payMsg) ?></span>
        </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="pay-stats">
        <div class="pay-stat">
            <div class="pay-stat-icon" style="background:#fef2f2;color:#dc2626"><i class="fas fa-file-invoice-dollar"></i></div>
            <div><div class="pay-stat-val"><?= fmt($outstandingTotal) ?></div><div class="pay-stat-label">Outstanding</div></div>
        </div>
        <div class="pay-stat">
            <div class="pay-stat-icon" style="background:#f0fdf4;color:#16a34a"><i class="fas fa-circle-check"></i></div>
            <div><div class="pay-stat-val"><?= fmt($paidTotal) ?></div><div class="pay-stat-label">Total Paid</div></div>
        </div>
        <div class="pay-stat">
            <div class="pay-stat-icon" style="background:#eff6ff;color:#2563eb"><i class="fas fa-hand-holding-dollar"></i></div>
            <div><div class="pay-stat-val"><?= fmt($creditAvailable) ?></div><div class="pay-stat-label">Credit Available</div></div>
        </div>
        <div class="pay-stat">
            <div class="pay-stat-icon" style="background:#fffbeb;color:#d97706"><i class="fas fa-hourglass-half"></i></div>
            <div><div class="pay-stat-val"><?= (int) $pendingTotal ?></div><div class="pay-stat-label">Awaiting Verification</div></div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="pay-tabs">
        <a href="?page=payments&tab=overview" class="pay-tab <?= $tab === 'overview' ? 'active' : '' ?>"><i class="fas fa-gauge-high"></i> Overview</a>
        <a href="?page=payments&tab=invoices" class="pay-tab <?= $tab === 'invoices' ? 'active' : '' ?>"><i class="fas fa-file-invoice"></i> Invoices <span class="pay-tab-count"><?= count($invoices) ?></span></a>
        <a href="?page=payments&tab=history" class="pay-tab <?= $tab === 'history' ? 'active' : '' ?>"><i class="fas fa-clock-rotate-left"></i> Payment History</a>
        <a href="?page=payments&tab=make" class="pay-tab <?= $tab === 'make' ? 'active' : '' ?>"><i class="fas fa-plus-circle"></i> Make a Payment<?php if ($pendingTotal > 0): ?> <span class="pay-tab-count warn"><?= (int) $pendingTotal ?></span><?php endif; ?></a>
    </div>

<?php if ($tab === 'overview'): ?>
    <!-- Attention items -->
    <?php if ($rejectedSubCount > 0 || $rejectedOrderCount > 0): ?>
        <div class="pay-alert danger">
            <i class="fas fa-triangle-exclamation"></i>
            <div>
                <strong>Some payments need your attention.</strong>
                <?= $rejectedSubCount + $rejectedOrderCount ?> payment(s) were rejected or require corrections.
                Open <a href="?page=payments&tab=history" style="color:inherit;font-weight:800;text-decoration:underline">Payment History</a> to see the reason and resubmit.
            </div>
        </div>
    <?php endif; ?>

    <?php if ($pendingTotal > 0): ?>
        <div class="pay-alert info">
            <i class="fas fa-hourglass-half"></i>
            <div>
                <strong><?= (int) $pendingTotal ?> payment(s) awaiting Finance verification.</strong>
                Once Finance verifies the amount and reference, your invoice balance is updated automatically. No action is needed from you.
            </div>
        </div>
    <?php endif; ?>

    <!-- How to pay -->
    <div class="pay-card">
        <div class="pay-card-head"><div class="pay-card-title"><i class="fas fa-wallet"></i> How to Pay</div></div>
        <div class="pay-card-body">
            <div class="pay-methods">
                <?php foreach ($PAY_METHODS as $key => $m):
                    $isCredit = $key === 'credit';
                    $available = !$isCredit || ($creditSalesEnabled && $creditApproved);
                ?>
                <div class="pay-method<?= $available ? '' : ' unavailable' ?>">
                    <div class="pay-method-icon"><i class="fas <?= $m['icon'] ?>"></i></div>
                    <div class="pay-method-name"><?= htmlspecialchars($m['label']) ?></div>
                    <div class="pay-method-desc"><?= htmlspecialchars($m['desc']) ?></div>
                    <?php if ($isCredit): ?>
                        <span class="pay-method-flag <?= $available ? 'ok' : 'no' ?>">
                            <i class="fas fa-<?= $available ? 'circle-check' : 'lock' ?>"></i>
                            <?= $available ? 'Available on your account' : 'Not available' ?>
                        </span>
                    <?php else: ?>
                        <span class="pay-method-flag ok"><i class="fas fa-circle-check"></i> Accepted</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (!$creditSalesEnabled || !$creditApproved): ?>
                <div class="pay-alert warn" style="margin:16px 0 0">
                    <i class="fas fa-circle-info"></i>
                    <div>Credit (pay later) is <?= !$creditSalesEnabled ? 'currently disabled by the company' : 'not yet approved for your account' ?>.
                        Credit is only available to approved accounts within their limit and terms.</div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Pending verification detail -->
    <?php if ($pendingTotal > 0):
        $pendings = array_filter($history, fn($h) => $h['status'] === 'pending');
    ?>
    <div class="pay-card">
        <div class="pay-card-head"><div class="pay-card-title"><i class="fas fa-hourglass-half"></i> Awaiting Finance Verification</div></div>
        <div class="pay-card-body" style="padding-top:12px">
            <?php foreach ($pendings as $h): ?>
                <div class="led-row">
                    <span class="l">
                        <span class="d"><?= date('M d, Y H:i', strtotime($h['date'])) ?></span>
                        <span class="m"><?= htmlspecialchars(formatPaymentMethod($h['method'])) ?></span>
                        <span class="r"><?= $h['reference'] ? htmlspecialchars($h['reference']) : 'No reference' ?></span>
                    </span>
                    <span class="pay-badge pending"><i class="fas fa-hourglass-half"></i> Pending</span>
                    <span class="a"><?= fmt($h['amount']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Outstanding preview -->
    <div class="pay-card">
        <div class="pay-card-head">
            <div class="pay-card-title"><i class="fas fa-file-invoice-dollar"></i> Outstanding Invoices</div>
            <a href="?page=payments&tab=make" class="pay-btn pay-btn-primary pay-btn-sm"><i class="fas fa-plus"></i> Make a Payment</a>
        </div>
        <div class="pay-card-body" style="padding-top:12px">
            <?php
            $outstandingInvoices = array_values(array_filter($invoices, fn($i) => (float) $i['outstanding_balance'] > 0));
            if (empty($outstandingInvoices)): ?>
                <div class="pay-empty"><i class="fas fa-circle-check" style="color:#16a34a"></i><p><strong>All settled.</strong></p><p>You have no outstanding invoices.</p></div>
            <?php else: foreach (array_slice($outstandingInvoices, 0, 4) as $inv):
                $due = $inv['credit_due_date'];
                $isOverdue = $due && strtotime($due) < $today;
            ?>
                <div class="led-row">
                    <span class="l">
                        <span style="font-weight:800;color:#0f172a"><?= htmlspecialchars($inv['invoice_no']) ?></span>
                        <?php if ($inv['order_id']): ?><span class="r">Order #<?= (int) $inv['order_id'] ?></span><?php endif; ?>
                        <?php if ($due): ?><span class="d"><i class="fas fa-calendar-day"></i> Due <?= date('M d, Y', strtotime($due)) ?></span><?php endif; ?>
                    </span>
                    <?php if ($isOverdue): ?><span class="pay-badge overdue">Overdue</span><?php elseif ($inv['payment_status'] === 'partial'): ?><span class="pay-badge partial">Partial</span><?php else: ?><span class="pay-badge unpaid">Unpaid</span><?php endif; ?>
                    <span class="a" style="color:#dc2626"><?= fmt((float) $inv['outstanding_balance']) ?></span>
                </div>
            <?php endforeach; ?>
            <?php if (count($outstandingInvoices) > 4): ?>
                <div style="text-align:center;margin-top:10px"><a href="?page=payments&tab=invoices" class="pay-btn pay-btn-outline pay-btn-sm">View all <?= count($outstandingInvoices) ?> outstanding</a></div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($tab === 'invoices'): ?>
    <?php
    $filtered = [];
    foreach ($invoices as $inv) {
        $st = $inv['payment_status'];
        $due = $inv['credit_due_date'];
        $isOverdue = $st !== 'paid' && $due && strtotime($due) < $today;
        $bucket = $st === 'paid' ? 'paid' : ($isOverdue ? 'overdue' : ($st === 'partial' ? 'partial' : 'unpaid'));
        if ($statusFilter === 'all' || $statusFilter === $bucket) $filtered[] = [$inv, $isOverdue, $bucket];
    }
    ?>
    <div class="pay-chips">
        <a href="?page=payments&tab=invoices&status=all" class="pay-chip <?= $statusFilter === 'all' ? 'active' : '' ?>">All <span class="n"><?= count($invoices) ?></span></a>
        <a href="?page=payments&tab=invoices&status=unpaid" class="pay-chip <?= $statusFilter === 'unpaid' ? 'active' : '' ?>">Unpaid <span class="n"><?= $unpaidCount ?></span></a>
        <a href="?page=payments&tab=invoices&status=partial" class="pay-chip <?= $statusFilter === 'partial' ? 'active' : '' ?>">Partially Paid <span class="n"><?= $partialCount ?></span></a>
        <a href="?page=payments&tab=invoices&status=paid" class="pay-chip <?= $statusFilter === 'paid' ? 'active' : '' ?>">Paid <span class="n"><?= $paidCount ?></span></a>
        <a href="?page=payments&tab=invoices&status=overdue" class="pay-chip <?= $statusFilter === 'overdue' ? 'active' : '' ?>">Overdue <span class="n"><?= $overdueCount ?></span></a>
    </div>

    <?php if (empty($filtered)): ?>
        <div class="pay-card"><div class="pay-card-body"><div class="pay-empty"><i class="fas fa-file-invoice"></i><p>No <?= $statusFilter === 'all' ? '' : htmlspecialchars($statusFilter) ?> invoices found.</p></div></div></div>
    <?php else: foreach ($filtered as [$inv, $isOverdue, $bucket]):
        $iid = (int) $inv['id'];
        $ipays = $paymentsByInvoice[$iid] ?? [];
        $isubs = $subsByInvoice[$iid] ?? [];
        $outstanding = (float) $inv['outstanding_balance'];
    ?>
        <div class="inv-card">
            <div class="inv-head">
                <div>
                    <div class="inv-no"><?= htmlspecialchars($inv['invoice_no']) ?></div>
                    <div class="inv-order">
                        <?= $inv['order_id'] ? 'Order #' . (int) $inv['order_id'] : 'Invoice' ?>
                        &middot; <?= htmlspecialchars(formatPaymentMethod($inv['order_payment_method'] ?? $inv['sales_type'])) ?>
                        <?php if ($inv['credit_due_date']): ?> &middot; Due <?= date('M d, Y', strtotime($inv['credit_due_date'])) ?><?php endif; ?>
                    </div>
                </div>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <?php if ($isOverdue): ?><span class="pay-badge overdue"><i class="fas fa-triangle-exclamation"></i> Overdue</span>
                    <?php elseif ($bucket === 'paid'): ?><span class="pay-badge paid"><i class="fas fa-circle-check"></i> Paid</span>
                    <?php elseif ($bucket === 'partial'): ?><span class="pay-badge partial"><i class="fas fa-adjust"></i> Partially Paid</span>
                    <?php else: ?><span class="pay-badge unpaid"><i class="fas fa-circle-exclamation"></i> Unpaid</span><?php endif; ?>
                    <?php if ($isOverdue): ?><span style="font-size:.75rem;color:#dc2626;font-weight:700"><?= abs((int) floor((strtotime($inv['credit_due_date']) - $today) / 86400)) ?> days overdue</span><?php endif; ?>
                </div>
            </div>
            <div class="inv-body">
                <div class="inv-grid">
                    <div class="inv-cell"><div class="inv-cell-v blue"><?= fmt((float) $inv['total_amount']) ?></div><div class="inv-cell-l">Invoice Total</div></div>
                    <div class="inv-cell"><div class="inv-cell-v green"><?= fmt((float) $inv['amount_paid']) ?></div><div class="inv-cell-l">Amount Paid</div></div>
                    <div class="inv-cell"><div class="inv-cell-v red"><?= fmt($outstanding) ?></div><div class="inv-cell-l">Outstanding</div></div>
                    <div class="inv-cell"><div class="inv-cell-v"><?= count($ipays) + count($isubs) ?></div><div class="inv-cell-l">Payments</div></div>
                </div>

                <?php if (!empty($ipays) || !empty($isubs)): ?>
                <div class="inv-ledger">
                    <h4><i class="fas fa-clock-rotate-left"></i> Payment History</h4>
                    <?php foreach ($ipays as $ip): ?>
                        <div class="led-row">
                            <span class="l">
                                <span class="d"><?= date('M d, Y', strtotime($ip['created_at'])) ?></span>
                                <span class="m"><?= htmlspecialchars(formatPaymentMethod($ip['payment_method'] ?? 'cash')) ?></span>
                                <span class="r"><?= $ip['reference_no'] ? htmlspecialchars($ip['reference_no']) : '' ?></span>
                            </span>
                            <span class="pay-badge verified"><i class="fas fa-circle-check"></i> Verified</span>
                            <span class="a">+ <?= fmt((float) $ip['amount']) ?></span>
                            <a href="index.php?page=payments&receipt=<?= (int) $ip['id'] ?>&rtype=payment" target="_blank" class="pay-btn pay-btn-outline pay-btn-sm" title="Print receipt"><i class="fas fa-print"></i></a>
                        </div>
                    <?php endforeach; ?>
                    <?php foreach ($isubs as $s): ?>
                        <div class="led-row">
                            <span class="l">
                                <span class="d"><?= date('M d, Y', strtotime($s['created_at'])) ?></span>
                                <span class="m"><?= htmlspecialchars(formatPaymentMethod($s['payment_method'])) ?></span>
                                <span class="r"><?= $s['reference_no'] ? htmlspecialchars($s['reference_no']) : '' ?></span>
                            </span>
                            <?php if ($s['status'] === 'verified'): ?>
                                <span class="pay-badge verified"><i class="fas fa-circle-check"></i> Verified</span>
                                <span class="a">+ <?= fmt((float) $s['amount']) ?></span>
                                <a href="index.php?page=payments&receipt=<?= (int) $s['id'] ?>&rtype=submission" target="_blank" class="pay-btn pay-btn-outline pay-btn-sm" title="Print receipt"><i class="fas fa-print"></i></a>
                            <?php elseif ($s['status'] === 'pending'): ?>
                                <span class="pay-badge pending"><i class="fas fa-hourglass-half"></i> Pending</span>
                                <span class="a" style="color:#b45309"><?= fmt((float) $s['amount']) ?></span>
                                <form method="post" style="display:inline" onsubmit="return confirm('Cancel this pending payment?')">
                                    <?= csrf_field() ?><input type="hidden" name="_action" value="cancel_submission"><input type="hidden" name="submission_id" value="<?= (int) $s['id'] ?>">
                                    <button type="submit" class="pay-btn pay-btn-danger pay-btn-sm">Cancel</button>
                                </form>
                            <?php else: ?>
                                <span class="pay-badge rejected"><i class="fas fa-circle-xmark"></i> Rejected</span>
                                <span class="a" style="color:#dc2626"><?= fmt((float) $s['amount']) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($s['status'] === 'rejected' && !empty($s['rejection_reason'])): ?>
                            <div class="pay-alert danger" style="margin:4px 0 8px"><i class="fas fa-circle-xmark"></i><div><strong>Reason:</strong> <?= htmlspecialchars($s['rejection_reason']) ?></div></div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="inv-acts">
                    <?php if ($outstanding > 0): ?>
                        <a href="?page=payments&tab=make&invoice_id=<?= $iid ?>" class="pay-btn pay-btn-success pay-btn-sm"><i class="fas fa-credit-card"></i> Pay Now</a>
                    <?php endif; ?>
                    <a href="index.php?page=invoice&id=<?= $iid ?>" class="pay-btn pay-btn-outline pay-btn-sm"><i class="fas fa-file-invoice"></i> View Invoice</a>
                </div>
            </div>
        </div>
    <?php endforeach; endif; ?>

<?php elseif ($tab === 'history'): ?>
    <div class="pay-card">
        <div class="pay-card-head"><div class="pay-card-title"><i class="fas fa-clock-rotate-left"></i> All Payment History</div></div>
        <?php if (empty($history)): ?>
            <div class="pay-empty"><i class="fas fa-clock-rotate-left"></i><p>No payments recorded yet.</p></div>
        <?php else: ?>
        <div style="overflow-x:auto">
            <table class="pay-table">
                <thead><tr><th>Date</th><th>Reference</th><th>Order / Invoice</th><th>Amount</th><th>Method</th><th>Transaction Ref</th><th>Verification</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($history as $h): ?>
                    <tr>
                        <td style="white-space:nowrap;color:#64748b"><?= date('M d, Y H:i', strtotime($h['date'])) ?></td>
                        <td style="font-weight:700"><?= htmlspecialchars($h['title']) ?></td>
                        <td><?= $h['invoice_no'] ? htmlspecialchars($h['invoice_no']) : '—' ?></td>
                        <td style="font-weight:800;color:<?= $h['status'] === 'verified' ? '#16a34a' : ($h['status'] === 'rejected' ? '#dc2626' : '#b45309') ?>"><?= fmt($h['amount']) ?></td>
                        <td><?= htmlspecialchars(formatPaymentMethod($h['method'])) ?></td>
                        <td style="color:#64748b"><?= $h['reference'] ? htmlspecialchars($h['reference']) : '—' ?></td>
                        <td>
                            <?php if ($h['status'] === 'verified'): ?><span class="pay-badge verified"><i class="fas fa-circle-check"></i> Verified</span>
                            <?php elseif ($h['status'] === 'rejected'): ?><span class="pay-badge rejected"><i class="fas fa-circle-xmark"></i> Rejected</span>
                            <?php else: ?><span class="pay-badge pending"><i class="fas fa-hourglass-half"></i> Pending</span><?php endif; ?>
                            <?php if ($h['status'] === 'rejected' && !empty($h['reason'])): ?>
                                <div style="font-size:.74rem;color:#991b1b;margin-top:4px;max-width:230px"><?= htmlspecialchars($h['reason']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap">
                            <?php if ($h['receiptable']): ?>
                                <a href="index.php?page=payments&receipt=<?= (int) $h['receipt_id'] ?>&rtype=<?= $h['receipt_type'] ?>" target="_blank" class="pay-btn pay-btn-outline pay-btn-sm"><i class="fas fa-print"></i> Receipt</a>
                            <?php elseif ($h['status'] === 'pending' && $h['receipt_type'] === 'submission'): ?>
                                <form method="post" onsubmit="return confirm('Cancel this pending payment?')">
                                    <?= csrf_field() ?><input type="hidden" name="_action" value="cancel_submission"><input type="hidden" name="submission_id" value="<?= (int) $h['receipt_id'] ?>">
                                    <button type="submit" class="pay-btn pay-btn-danger pay-btn-sm">Cancel</button>
                                </form>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'make'): ?>
    <?php
    $payable = array_values(array_filter($invoices, fn($i) => (float) $i['outstanding_balance'] > 0));
    $preselect = (int) ($_GET['invoice_id'] ?? 0);
    ?>
    <div class="pay-card">
        <div class="pay-card-head"><div class="pay-card-title"><i class="fas fa-credit-card"></i> Make a Payment</div></div>
        <div class="pay-card-body">
        <?php if (empty($payable)): ?>
            <div class="pay-empty"><i class="fas fa-circle-check" style="color:#16a34a"></i><p><strong>Nothing to pay.</strong></p><p>You have no invoices with an outstanding balance.</p>
                <div style="margin-top:14px"><a href="?page=payments&tab=invoices" class="pay-btn pay-btn-outline pay-btn-sm">View Invoices</a></div>
            </div>
        <?php else: ?>
            <div class="pay-alert info" style="margin-bottom:18px">
                <i class="fas fa-circle-info"></i>
                <div>Your payment is recorded as <strong>pending verification</strong>. Once a Finance officer confirms the amount and reference, the invoice balance is updated and the transaction becomes a verified, receiptable payment.</div>
            </div>
            <form method="post" enctype="multipart/form-data" id="payForm">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="submit_payment">
                <input type="hidden" name="payment_method" id="pmField" value="cash">

                <div class="pay-fg">
                    <label for="payInvoice">Invoice to pay *</label>
                    <select name="invoice_id" id="payInvoice" required onchange="payUpdateInvoice()">
                        <option value="">— Select an invoice —</option>
                        <?php foreach ($payable as $inv): ?>
                            <option value="<?= (int) $inv['id'] ?>"
                                    data-bal="<?= (float) $inv['outstanding_balance'] ?>"
                                    data-total="<?= (float) $inv['total_amount'] ?>"
                                    data-no="<?= htmlspecialchars($inv['invoice_no']) ?>"
                                    <?= $preselect === (int) $inv['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($inv['invoice_no']) ?> — <?= fmt((float) $inv['outstanding_balance']) ?> outstanding
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="pay-readout">
                    <div class="box"><div class="k">Invoice Total</div><div class="v" id="roTotal">—</div></div>
                    <div class="box"><div class="k">Outstanding Balance</div><div class="v red" id="roBal">—</div></div>
                </div>

                <div class="pay-fg">
                    <label for="payAmount">Amount to pay *</label>
                    <input type="number" name="pay_amount" id="payAmount" min="0.01" step="0.01" placeholder="0.00" required oninput="payValidate()">
                    <span class="hint" id="payHint">Select an invoice first.</span>
                </div>

                <div class="pay-fg">
                    <label>Payment method *</label>
                    <div class="pay-mgrid" id="payMethods">
                        <?php foreach ($submittableMethods as $key): $m = $PAY_METHODS[$key]; ?>
                        <label class="pay-mcard<?= $key === 'cash' ? ' sel' : '' ?>" data-method="<?= $key ?>">
                            <input type="radio" name="pm_radio" value="<?= $key ?>" <?= $key === 'cash' ? 'checked' : '' ?> onchange="paySelectMethod('<?= $key ?>')">
                            <div class="mi"><i class="fas <?= $m['icon'] ?>"></i></div>
                            <div class="mn"><?= htmlspecialchars($m['label']) ?></div>
                            <div class="md"><?= htmlspecialchars($m['desc']) ?></div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="pay-row">
                    <div class="pay-fg">
                        <label for="payRef">Transaction reference <span id="refReq" style="color:#dc2626">*</span></label>
                        <input type="text" name="reference" id="payRef" maxlength="100" placeholder="e.g. bank FT number / SMS code" oninput="payValidate()">
                    </div>
                    <div class="pay-fg">
                        <label for="payReceipt">Proof of payment <span id="rcptReq" style="color:#dc2626;display:none">*</span></label>
                        <input type="file" name="receipt" id="payReceipt" accept="image/jpeg,image/png,image/gif,image/webp,application/pdf">
                        <span class="hint">JPG, PNG, GIF, WEBP or PDF · max 2 MB.</span>
                    </div>
                </div>

                <div class="pay-fg">
                    <label for="payNotes">Notes (optional)</label>
                    <textarea name="payment_notes" id="payNotes" rows="2" maxlength="500" placeholder="Anything Finance should know about this payment…"></textarea>
                </div>

                <div id="payFormErr" class="pay-msg error" style="display:none"></div>

                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:6px">
                    <button type="submit" class="pay-btn pay-btn-success" id="paySubmit"><i class="fas fa-paper-plane"></i> Submit Payment</button>
                    <a href="?page=payments&tab=invoices" class="pay-btn pay-btn-outline">Cancel</a>
                </div>
            </form>
        <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
</section>

<?php if ($tab === 'make'):
    $payableJson = [];
    foreach (($payable ?? []) as $inv) {
        $payableJson[(int) $inv['id']] = ['bal' => (float) $inv['outstanding_balance'], 'total' => (float) $inv['total_amount'], 'no' => $inv['invoice_no']];
    }
?>
<script>
var CURRENCY = <?= json_encode(currency_sym()) ?>;
var PAYABLE = <?= json_encode($payableJson) ?>;
function payMoney(n){ return CURRENCY + ' ' + Number(n||0).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function payUpdateInvoice(){
    var sel=document.getElementById('payInvoice'); if(!sel) return;
    var id=sel.value; var d=PAYABLE[id];
    var total=document.getElementById('roTotal'), bal=document.getElementById('roBal'), amt=document.getElementById('payAmount'), hint=document.getElementById('payHint');
    if(!d){ total.textContent='—'; bal.textContent='—'; amt.removeAttribute('max'); amt.value=''; hint.textContent='Select an invoice first.'; return; }
    total.textContent=payMoney(d.total); bal.textContent=payMoney(d.bal);
    amt.max=d.bal; amt.value=d.bal.toFixed(2);
    hint.textContent='You may pay part or all of the '+payMoney(d.bal)+' outstanding.';
    payValidate();
}
function paySelectMethod(m){
    document.getElementById('pmField').value=m;
    document.querySelectorAll('#payMethods .pay-mcard').forEach(function(c){ c.classList.toggle('sel', c.dataset.method===m); });
    var needRef = m!=='cash';
    document.getElementById('refReq').style.display = needRef?'inline':'none';
    document.getElementById('rcptReq').style.display = m==='bank'?'inline':'none';
    payValidate();
}
function payValidate(){
    var amt=document.getElementById('payAmount'), err=document.getElementById('payFormErr');
    if(!err) return true;
    var msgs=[];
    var id=document.getElementById('payInvoice').value;
    var d=PAYABLE[id];
    var v=parseFloat(amt.value||'0');
    if(!d) msgs.push('Select an invoice to pay.');
    if(d && v>d.bal+0.001) msgs.push('Amount cannot exceed the outstanding balance of '+payMoney(d.bal)+'.');
    if(v<=0) msgs.push('Enter an amount greater than zero.');
    var method=document.getElementById('pmField').value;
    if(method!=='cash' && !document.getElementById('payRef').value.trim()) msgs.push('A transaction reference is required for this method.');
    if(msgs.length){ err.style.display='flex'; err.innerHTML='<i class="fas fa-circle-exclamation"></i><span>'+msgs.join(' ')+'</span>'; return false; }
    err.style.display='none'; return true;
}
(function(){
    var f=document.getElementById('payForm'); if(!f) return;
    f.addEventListener('submit', function(e){
        if(!payValidate()){ e.preventDefault(); return; }
        var b=document.getElementById('paySubmit'); if(b){ b.disabled=true; b.innerHTML='<i class="fas fa-spinner fa-spin"></i> Submitting…'; }
    });
    paySelectMethod(document.getElementById('pmField').value);
    payUpdateInvoice();
})();
</script>
<?php endif; ?>

<?php
/* ═══════════════════════════════════════════════════════════════════════
 * Helpers
 * ═══════════════════════════════════════════════════════════════════════ */

/**
 * Securely validate + store an uploaded evidence file.
 * Returns the public URL path (uploads/payments/…), or null when no file /
 * on error ($error is populated with a user-facing message).
 */
function payment_store_upload(array $file, ?string &$error): ?string
{
    $error = null;
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE || empty($file['name'])) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'The file could not be uploaded. Please try again.';
        return null;
    }
    if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > MAX_UPLOAD_SIZE) {
        $error = 'The file must be smaller than ' . round(MAX_UPLOAD_SIZE / 1048576) . ' MB.';
        return null;
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    $mime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($file['tmp_name']);
    }
    if (!isset($allowed[$mime])) {
        $error = 'Only JPG, PNG, GIF, WEBP or PDF files are accepted.';
        return null;
    }
    $name = 'pay_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
    $stored = storage_put($file['tmp_name'], 'payments', $name, $mime);
    if ($stored === null) {
        $error = 'Could not save the uploaded file. Please contact the administrator.';
        return null;
    }
    return $stored;
}

/**
 * Render a standalone, printable receipt for a verified transaction.
 * Only the customer's own recorded/verified transactions are receiptable.
 */
function payment_render_receipt(PDO $pdo, int $cid, string $ref, string $rtype): void
{
    $amount = 0.0; $method = ''; $reference = ''; $notes = '';
    $date = ''; $invoiceNo = ''; $orderNo = 0; $verifiedBy = ''; $verifiedAt = ''; $statusLabel = 'Verified';
    $ok = false;

    if ($rtype === 'submission') {
        $st = $pdo->prepare(
            "SELECT s.*, i.invoice_no, i.customer_order_id, co.id AS order_id,
                    COALESCE(u.full_name, u.username) AS reviewer
             FROM customer_payment_submissions s
             LEFT JOIN invoices i ON s.invoice_id = i.id
             LEFT JOIN customer_orders co ON i.customer_order_id = co.id
             LEFT JOIN users u ON s.reviewed_by = u.id
             WHERE s.id = :id AND s.customer_id = :cid AND s.status = 'verified' LIMIT 1"
        );
        $st->execute(['id' => (int) $ref, 'cid' => $cid]);
        $r = $st->fetch();
        if ($r) {
            $ok = true;
            $amount = (float) $r['amount']; $method = (string) $r['payment_method'];
            $reference = (string) ($r['reference_no'] ?? ''); $notes = (string) ($r['notes'] ?? '');
            $date = (string) $r['created_at']; $invoiceNo = (string) ($r['invoice_no'] ?? '');
            $orderNo = (int) ($r['customer_order_id'] ?? 0); $verifiedBy = (string) ($r['reviewer'] ?? 'Finance');
            $verifiedAt = (string) ($r['reviewed_at'] ?? '');
        }
    } elseif ($rtype === 'payment') {
        $st = $pdo->prepare(
            "SELECT ip.*, i.invoice_no, i.customer_order_id,
                    COALESCE(u.full_name, u.username) AS recorder
             FROM invoice_payments ip
             LEFT JOIN invoices i ON ip.invoice_id = i.id
             LEFT JOIN users u ON ip.recorded_by = u.id
             WHERE ip.id = :id AND ip.customer_id = :cid LIMIT 1"
        );
        $st->execute(['id' => (int) $ref, 'cid' => $cid]);
        $r = $st->fetch();
        if ($r) {
            $ok = true;
            $amount = (float) $r['amount']; $method = (string) ($r['payment_method'] ?? 'cash');
            $reference = (string) ($r['reference_no'] ?? ''); $notes = (string) ($r['notes'] ?? '');
            $date = (string) $r['created_at']; $invoiceNo = (string) ($r['invoice_no'] ?? '');
            $orderNo = (int) ($r['customer_order_id'] ?? 0); $verifiedBy = (string) ($r['recorder'] ?? 'Finance');
            $verifiedAt = (string) $r['created_at'];
        }
    } elseif ($rtype === 'order') {
        $st = $pdo->prepare(
            "SELECT co.*, i.invoice_no, COALESCE(u.full_name, u.username) AS verifier
             FROM customer_orders co
             LEFT JOIN invoices i ON i.customer_order_id = co.id
             LEFT JOIN users u ON co.verified_by = u.id
             WHERE co.id = :id AND co.customer_id = :cid AND co.payment_confirmed = 1 LIMIT 1"
        );
        $st->execute(['id' => (int) $ref, 'cid' => $cid]);
        $r = $st->fetch();
        if ($r) {
            $ok = true;
            $amount = $r['verified_amount'] !== null ? (float) $r['verified_amount'] : (float) $r['total_amount'];
            $method = (string) $r['payment_method']; $reference = (string) ($r['transaction_ref'] ?? '');
            $notes = (string) ($r['payment_notes'] ?? '');
            $date = (string) ($r['payment_confirmed_at'] ?: $r['created_at']); $invoiceNo = (string) ($r['invoice_no'] ?? '');
            $orderNo = (int) $r['id']; $verifiedBy = (string) ($r['verifier'] ?? 'Finance');
            $verifiedAt = (string) ($r['verified_at'] ?? '');
        }
    }

    $cname = '';
    $cst = $pdo->prepare('SELECT name, tin_number, phone FROM customers WHERE id = :id');
    $cst->execute(['id' => $cid]);
    $cust = $cst->fetch() ?: [];
    $cname = (string) ($cust['name'] ?? '');

    $company = SettingsService::companyName();
    $logo = brand_logo();
    $sym = currency_sym();
    $receiptNo = 'RCPT-' . strtoupper(substr($rtype, 0, 3)) . '-' . str_pad((string) (int) $ref, 6, '0', STR_PAD_LEFT);

    header('Content-Type: text/html; charset=utf-8');
    if (!$ok) {
        http_response_code(404);
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $ok ? 'Payment Receipt ' . htmlspecialchars($receiptNo) : 'Receipt not found' ?></title>
<style>
    *{box-sizing:border-box}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;background:#eef2f7;margin:0;padding:32px 16px;color:#0f172a}
    .rc{max-width:640px;margin:0 auto;background:#fff;border-radius:16px;box-shadow:0 10px 30px rgba(15,23,42,.10);overflow:hidden}
    .rc-top{background:linear-gradient(135deg,#1e3a5f,#1e40af);color:#fff;padding:24px 28px;display:flex;justify-content:space-between;align-items:flex-start;gap:16px}
    .rc-brand{display:flex;align-items:center;gap:12px}
    .rc-brand img{width:46px;height:46px;border-radius:10px;object-fit:cover;background:#fff;padding:2px}
    .rc-brand h1{font-size:1.05rem;margin:0;font-weight:800;letter-spacing:.02em}
    .rc-brand div{font-size:.75rem;opacity:.85;margin-top:2px}
    .rc-top .no{text-align:right;font-size:.76rem;opacity:.9}
    .rc-top .no strong{display:block;font-size:.95rem;letter-spacing:.03em;margin-top:2px}
    .rc-body{padding:26px 28px}
    .rc-paid{display:inline-flex;align-items:center;gap:7px;background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;border-radius:20px;padding:5px 14px;font-weight:800;font-size:.78rem;text-transform:uppercase;letter-spacing:.03em}
    .rc-amt{font-size:2rem;font-weight:800;color:#0f172a;margin:16px 0 4px}
    .rc-amt-sub{color:#64748b;font-size:.84rem}
    .rc-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:22px;border-top:1px solid #e2e8f0;padding-top:20px}
    .rc-item .k{font-size:.7rem;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.04em}
    .rc-item .v{font-size:.9rem;font-weight:600;margin-top:3px;word-break:break-word}
    .rc-note{margin-top:20px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:13px 16px;font-size:.82rem;color:#475569}
    .rc-foot{border-top:1px solid #e2e8f0;margin-top:22px;padding-top:16px;font-size:.74rem;color:#94a3b8;text-align:center;line-height:1.6}
    .rc-toolbar{max-width:640px;margin:0 auto 18px;display:flex;gap:10px;justify-content:flex-end}
    .rc-btn{border:none;border-radius:9px;padding:11px 18px;font-weight:700;font-size:.85rem;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:7px}
    .rc-btn.primary{background:#2563eb;color:#fff}
    .rc-btn.outline{background:#fff;color:#1e293b;border:1.5px solid #cbd5e1}
    .rc-notfound{max-width:520px;margin:60px auto;background:#fff;border-radius:16px;padding:40px;text-align:center;box-shadow:0 10px 30px rgba(15,23,42,.08)}
    .rc-notfound i{font-size:2.4rem;color:#f59e0b;margin-bottom:12px}
    @media print{
        body{background:#fff;padding:0}
        .rc{box-shadow:none;border-radius:0;max-width:100%}
        .rc-toolbar{display:none!important}
    }
    @media(max-width:520px){.rc-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php if (!$ok): ?>
    <div class="rc-notfound">
        <i class="fas fa-triangle-exclamation" style="font-family:'Font Awesome 6 Free'"></i>
        <h2 style="margin:0 0 8px">Receipt unavailable</h2>
        <p style="color:#64748b;font-size:.9rem">This receipt does not exist, is not yours, or belongs to a payment that has not been verified yet. Only recorded and verified transactions can be receipted.</p>
        <a href="?page=payments" style="color:#2563eb;font-weight:700">← Back to Payments</a>
    </div>
<?php else: ?>
    <div class="rc-toolbar">
        <a href="?page=payments" class="rc-btn outline">← Back</a>
        <button class="rc-btn primary" onclick="window.print()">🖨 Print / Save as PDF</button>
    </div>
    <div class="rc">
        <div class="rc-top">
            <div class="rc-brand">
                <img src="<?= htmlspecialchars($logo) ?>" alt="">
                <div><h1><?= htmlspecialchars($company) ?></h1><div>Official Payment Receipt</div></div>
            </div>
            <div class="no">Receipt No<strong><?= htmlspecialchars($receiptNo) ?></strong></div>
        </div>
        <div class="rc-body">
            <span class="rc-paid">✓ Verified Payment</span>
            <div class="rc-amt"><?= htmlspecialchars($sym) ?> <?= number_format($amount, 2) ?></div>
            <div class="rc-amt-sub">Received from <strong><?= htmlspecialchars($cname) ?></strong></div>

            <div class="rc-grid">
                <div class="rc-item"><div class="k">Payment Date</div><div class="v"><?= $date ? date('M d, Y H:i', strtotime($date)) : '—' ?></div></div>
                <div class="rc-item"><div class="k">Payment Method</div><div class="v"><?= htmlspecialchars(formatPaymentMethod($method)) ?></div></div>
                <div class="rc-item"><div class="k">Invoice</div><div class="v"><?= $invoiceNo ? htmlspecialchars($invoiceNo) : '—' ?></div></div>
                <div class="rc-item"><div class="k">Order</div><div class="v"><?= $orderNo ? '#' . $orderNo : '—' ?></div></div>
                <div class="rc-item"><div class="k">Transaction Reference</div><div class="v"><?= $reference ? htmlspecialchars($reference) : '—' ?></div></div>
                <div class="rc-item"><div class="k">Verified By</div><div class="v"><?= htmlspecialchars($verifiedBy ?: 'Finance') ?><?= $verifiedAt ? '<br><span style="font-weight:500;color:#64748b;font-size:.8rem">' . date('M d, Y H:i', strtotime($verifiedAt)) . '</span>' : '' ?></div></div>
                <?php if ($cust && !empty($cust['tin_number'])): ?>
                <div class="rc-item"><div class="k">Customer TIN</div><div class="v"><?= htmlspecialchars($cust['tin_number']) ?></div></div>
                <?php endif; ?>
                <?php if (!empty($notes)): ?>
                <div class="rc-item"><div class="k">Notes</div><div class="v"><?= htmlspecialchars($notes) ?></div></div>
                <?php endif; ?>
            </div>

            <div class="rc-note">
                This receipt confirms a payment that has been recorded and verified in the NARED finance system.
                It is valid without a signature. Retain it for your records.
            </div>

            <div class="rc-foot">
                <?= htmlspecialchars($company) ?> · Generated <?= date('M d, Y H:i') ?><br>
                Computer-generated receipt — no signature required.
            </div>
        </div>
    </div>
<?php endif; ?>
</body>
</html>
    <?php
    exit;
}
