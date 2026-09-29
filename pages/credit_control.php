<?php
require_once __DIR__ . '/../includes/workflow_helpers.php';
require_once __DIR__ . '/../includes/credit_helpers.php';
requirePermission('credit_control', 'view');
$pdo = getPDO();
$user = currentUser();
$uid = (int) $user['id'];
$messages = [];

creditSyncOverdue($pdo);

$activeTab = $_GET['tab'] ?? 'settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['_action'] ?? '';
    $returnUrl = '?page=credit_control' . ($activeTab !== '' ? '&tab=' . urlencode($activeTab) : '');

    // Action-level permission enforcement (server-side, not just UI hiding)
    $creditPerm = [
        'add_credit_customer' => 'edit', 'update_limit' => 'edit', 'increase_limit' => 'edit', 'reduce_limit' => 'edit',
        'approve_credit' => 'approve', 'suspend_credit' => 'approve', 'reject_credit' => 'approve',
        'resume_credit' => 'approve', 'record_payment' => 'pay', 'write_off' => 'delete', 'edit_due_date' => 'edit',
    ];
    $creditBlocked = isset($creditPerm[$action]) && !hasPermission('credit_control', $creditPerm[$action]);
    if ($action === '' && !hasPermission('credit_control', 'edit')) {
        $creditBlocked = true;
    }
    if ($creditBlocked) {
        $messages[] = ['type' => 'error', 'text' => 'You do not have permission to perform this action.'];
    } elseif ($action === 'add_credit_customer') {
        $res = creditCreateProfile($pdo, (int) ($_POST['customer_id'] ?? 0), (float) ($_POST['credit_limit'] ?? 0), (int) ($_POST['credit_terms_days'] ?? 0), $uid);
        $messages[] = $res['success']
            ? ['type' => 'success', 'text' => $res['msg']]
            : ['type' => 'error', 'text' => $res['msg']];
    } elseif ($action === '') {
        // Settings save
        $settings = [
            'allow_credit_sales', 'require_credit_approval', 'max_credit_limit',
            'default_credit_days', 'grace_period_days', 'penalty_percentage',
            'interest_percentage', 'auto_suspend_on_overdue', 'block_new_orders_on_overdue', 'allow_override',
            'agreement_title', 'agreement_content', 'require_agreement_acceptance'
        ];
        $changed = [];
        foreach ($settings as $key) {
            if (isset($_POST[$key])) {
                $val = $_POST[$key];
                $old = creditGetSetting($pdo, $key, '');
                if ($val !== $old) {
                    $pdo->prepare('INSERT INTO credit_control_settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = :v2')
                        ->execute(['k' => $key, 'v' => $val, 'v2' => $val]);
                    $changed[] = "$key: $old -> $val";
                }
            }
        }
        foreach ($settings as $key) {
            if (!isset($_POST[$key]) && in_array($key, ['allow_credit_sales','require_credit_approval','auto_suspend_on_overdue','block_new_orders_on_overdue','allow_override','require_agreement_acceptance'])) {
                $old = creditGetSetting($pdo, $key, '0');
                if ($old !== '0') {
                    $pdo->prepare('INSERT INTO credit_control_settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = :v2')
                        ->execute(['k' => $key, 'v' => '0', 'v2' => '0']);
                    $changed[] = "$key: $old -> 0";
                }
            }
        }
        // Publish a new Credit Agreement version whenever agreement-relevant
        // settings change so new orders use the new terms while already-placed
        // orders keep the exact version they accepted.
        $agreementKeys = ['agreement_title', 'agreement_content', 'default_credit_days', 'require_agreement_acceptance', 'require_credit_approval'];
        $agreementChanged = false;
        foreach ($changed as $c) {
            foreach ($agreementKeys as $k) {
                if (strpos($c, $k . ':') === 0) { $agreementChanged = true; break; }
            }
        }
        if ($agreementChanged) {
            $pub = creditPublishAgreement($pdo, $uid);
            $messages[] = ['type' => 'success', 'text' => 'A new Credit Agreement (Version ' . $pub['version'] . ') has been published and is now active for new credit orders.'];
        }
        if (!empty($changed)) {
            $messages[] = ['type' => 'success', 'text' => 'Settings updated. Changes: ' . implode('; ', $changed)];
        } else {
            $messages[] = ['type' => 'info', 'text' => 'No changes detected.'];
        }
    } elseif ($action === 'update_limit') {
        $cid = (int) ($_POST['customer_id'] ?? 0);
        $newLimit = (float) ($_POST['new_limit'] ?? 0);
        if ($cid <= 0) { $messages[] = ['type' => 'error', 'text' => 'Invalid customer.']; }
        else {
            $stmt = $pdo->prepare('SELECT credit_limit FROM customers WHERE id = :id');
            $stmt->execute(['id' => $cid]);
            $oldLimit = (float) $stmt->fetchColumn();
            $pdo->prepare('UPDATE customers SET credit_limit = :cl WHERE id = :id')->execute(['cl' => $newLimit, 'id' => $cid]);
            creditLogAudit($pdo, $cid, 'limit_updated', (string) $oldLimit, (string) $newLimit, $uid);
            $messages[] = ['type' => 'success', 'text' => 'Credit limit updated for customer #' . $cid . '.'];
        }
    } elseif ($action === 'increase_limit') {
        $cid = (int) ($_POST['customer_id'] ?? 0);
        $addAmount = (float) ($_POST['add_amount'] ?? 0);
        if ($cid <= 0 || $addAmount <= 0) { $messages[] = ['type' => 'error', 'text' => 'Invalid customer or amount.']; }
        else {
            $stmt = $pdo->prepare('SELECT credit_limit FROM customers WHERE id = :id');
            $stmt->execute(['id' => $cid]);
            $oldLimit = (float) $stmt->fetchColumn();
            $newLimit = $oldLimit + $addAmount;
            $pdo->prepare('UPDATE customers SET credit_limit = :cl WHERE id = :id')->execute(['cl' => $newLimit, 'id' => $cid]);
            creditLogAudit($pdo, $cid, 'limit_increased', (string) $oldLimit, (string) $newLimit, $uid);
            $messages[] = ['type' => 'success', 'text' => 'Credit limit increased by ' . fmt($addAmount) . ' for customer #' . $cid . '.'];
        }
    } elseif ($action === 'reduce_limit') {
        $cid = (int) ($_POST['customer_id'] ?? 0);
        $subAmount = (float) ($_POST['sub_amount'] ?? 0);
        if ($cid <= 0 || $subAmount <= 0) { $messages[] = ['type' => 'error', 'text' => 'Invalid customer or amount.']; }
        else {
            $stmt = $pdo->prepare('SELECT credit_limit FROM customers WHERE id = :id');
            $stmt->execute(['id' => $cid]);
            $oldLimit = (float) $stmt->fetchColumn();
            $newLimit = max($oldLimit - $subAmount, 0);
            $pdo->prepare('UPDATE customers SET credit_limit = :cl WHERE id = :id')->execute(['cl' => $newLimit, 'id' => $cid]);
            creditLogAudit($pdo, $cid, 'limit_reduced', (string) $oldLimit, (string) $newLimit, $uid);
            $messages[] = ['type' => 'success', 'text' => 'Credit limit reduced by ' . fmt($subAmount) . ' for customer #' . $cid . '.'];
        }
    } elseif ($action === 'approve_credit') {
        $cid = (int) ($_POST['customer_id'] ?? 0);
        if ($cid <= 0) { $messages[] = ['type' => 'error', 'text' => 'Invalid customer.']; }
        else {
            try {
                CreditService::approve($pdo, $cid, $uid);
                redirect_with_flash($returnUrl, 'Credit approved for customer #' . $cid . '.');
            } catch (ServiceException $e) {
                $messages[] = ['type' => 'error', 'text' => $e->getMessage()];
            }
        }
    } elseif ($action === 'suspend_credit') {
        $cid = (int) ($_POST['customer_id'] ?? 0);
        if ($cid <= 0) { $messages[] = ['type' => 'error', 'text' => 'Invalid customer.']; }
        else {
            try {
                CreditService::suspend($pdo, $cid, $uid);
                redirect_with_flash($returnUrl, 'Credit suspended for customer #' . $cid . '.');
            } catch (ServiceException $e) {
                $messages[] = ['type' => 'error', 'text' => $e->getMessage()];
            }
        }
    } elseif ($action === 'reject_credit') {
        $cid = (int) ($_POST['customer_id'] ?? 0);
        if ($cid <= 0) { $messages[] = ['type' => 'error', 'text' => 'Invalid customer.']; }
        else {
            try {
                CreditService::reject($pdo, $cid, $uid);
                redirect_with_flash($returnUrl, 'Credit rejected for customer #' . $cid . '.');
            } catch (ServiceException $e) {
                $messages[] = ['type' => 'error', 'text' => $e->getMessage()];
            }
        }
    } elseif ($action === 'resume_credit') {
        $cid = (int) ($_POST['customer_id'] ?? 0);
        if ($cid <= 0) { $messages[] = ['type' => 'error', 'text' => 'Invalid customer.']; }
        else {
            try {
                CreditService::resume($pdo, $cid, $uid);
                redirect_with_flash($returnUrl, 'Credit resumed for customer #' . $cid . '.');
            } catch (ServiceException $e) {
                $messages[] = ['type' => 'error', 'text' => $e->getMessage()];
            }
        }
    } elseif ($action === 'record_payment') {
        $cid = (int) ($_POST['customer_id'] ?? 0);
        $amount = (float) ($_POST['amount'] ?? 0);
        $payDate = $_POST['payment_date'] ?? date('Y-m-d');
        $method = $_POST['payment_method'] ?? '';
        $refNo = trim($_POST['reference_no'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        if ($cid <= 0 || $amount <= 0) { $messages[] = ['type' => 'error', 'text' => 'Customer and positive amount required.']; }
        else {
            $payErr = '';
            $ok = creditRecordPayment($pdo, $cid, $amount, $payDate, $method, $refNo, $notes, $uid, $payErr);
            if ($ok) {
                redirect_with_flash($returnUrl, 'Payment of ' . fmt($amount) . ' recorded for customer #' . $cid . '.');
            } else {
                $messages[] = ['type' => 'error', 'text' => $payErr !== '' ? $payErr : 'Failed to record payment.'];
            }
        }
    } elseif ($action === 'write_off') {
        $cid = (int) ($_POST['customer_id'] ?? 0);
        $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
        $writeOffAmt = (float) ($_POST['write_off_amount'] ?? 0);
        $reason = trim($_POST['write_off_reason'] ?? '');
        if ($cid <= 0 || $invoiceId <= 0) { $messages[] = ['type' => 'error', 'text' => 'Invalid customer or invoice.']; }
        else {
            if ($writeOffAmt <= 0) {
                $balStmt = $pdo->prepare('SELECT outstanding_balance FROM invoices WHERE id = :id AND customer_id = :cid');
                $balStmt->execute(['id' => $invoiceId, 'cid' => $cid]);
                $writeOffAmt = (float) $balStmt->fetchColumn();
            }
            $res = CreditService::writeOff($pdo, $cid, $invoiceId, $writeOffAmt, $reason, $uid);
            $messages[] = $res['success']
                ? ['type' => 'success', 'text' => $res['msg']]
                : ['type' => 'error', 'text' => $res['msg']];
        }
    } elseif ($action === 'edit_due_date') {
        $cid = (int) ($_POST['customer_id'] ?? 0);
        $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
        $newDate = $_POST['new_due_date'] ?? '';
        if ($cid <= 0 || $invoiceId <= 0 || $newDate === '') { $messages[] = ['type' => 'error', 'text' => 'Missing required fields.']; }
        else {
            $stmt = $pdo->prepare('SELECT credit_due_date FROM invoices WHERE id = :id AND customer_id = :cid');
            $stmt->execute(['id' => $invoiceId, 'cid' => $cid]);
            $row = $stmt->fetch();
            if (!$row) { $messages[] = ['type' => 'error', 'text' => 'Invoice not found.']; }
            else {
                $oldDate = $row['credit_due_date'] ?? 'NULL';
                $pdo->prepare('UPDATE invoices SET credit_due_date = :d WHERE id = :id')->execute(['d' => $newDate, 'id' => $invoiceId]);
                creditSyncOverdue($pdo);
                creditLogAudit($pdo, $cid, 'due_date_changed', $oldDate, $newDate, $uid);
                $messages[] = ['type' => 'success', 'text' => 'Due date updated for Invoice #' . $invoiceId . '.'];
            }
        }
    } elseif ($action === 'send_reminder') {
        $cid = (int) ($_POST['customer_id'] ?? 0);
        if ($cid <= 0) { $messages[] = ['type' => 'error', 'text' => 'Invalid customer.']; }
        else {
            $stmt = $pdo->prepare('SELECT name FROM customers WHERE id = :id');
            $stmt->execute(['id' => $cid]);
            $cname = $stmt->fetchColumn();
            creditNotify($pdo, $cid, 'warning', 'Payment Reminder', 'Please settle your outstanding credit balance at your earliest convenience.');
            creditLogAudit($pdo, $cid, 'reminder_sent', null, 'Payment reminder sent', $uid);
            $messages[] = ['type' => 'success', 'text' => 'Payment reminder sent to ' . htmlspecialchars($cname) . '.'];
        }
    } elseif ($action === 'print_statement') {
        $cid = (int) ($_POST['customer_id'] ?? 0);
        if ($cid <= 0) { $messages[] = ['type' => 'error', 'text' => 'Invalid customer.']; }
        else {
            $stmt = $pdo->prepare('SELECT name FROM customers WHERE id = :id');
            $stmt->execute(['id' => $cid]);
            $cname = $stmt->fetchColumn();
            creditLogAudit($pdo, $cid, 'statement_printed', null, 'Account statement printed', $uid);
            $messages[] = ['type' => 'success', 'text' => 'Statement print initiated for ' . htmlspecialchars($cname) . '.'];
        }
    }
}

// Settings stats
$stats = [
    'credit_customers' => $pdo->query("SELECT COUNT(*) FROM customers WHERE credit_limit > 0")->fetchColumn(),
    'total_outstanding' => $pdo->query("SELECT COALESCE(SUM(outstanding_balance),0) FROM invoices WHERE payment_status IN ('unpaid','partial')")->fetchColumn(),
    'total_overdue' => $pdo->query("SELECT COALESCE(SUM(outstanding_balance),0) FROM invoices WHERE payment_status IN ('unpaid','partial') AND credit_due_date < CURDATE()")->fetchColumn(),
    'total_limits' => $pdo->query("SELECT COALESCE(SUM(credit_limit),0) FROM customers WHERE credit_limit > 0")->fetchColumn(),
    'active_credit' => $pdo->query("SELECT COUNT(*) FROM customers WHERE credit_limit > 0 AND credit_approval_status = 'approved'")->fetchColumn(),
    'over_limit' => $pdo->query("SELECT COUNT(*) FROM customers WHERE credit_limit > 0 AND credit_used > credit_limit")->fetchColumn(),
];

// Credit Management data
$totalCreditSales = (float) $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE sales_type = 'credit' OR payment_status != 'unpaid'")->fetchColumn();
$totalOutstanding = (float) $pdo->query("SELECT COALESCE(SUM(outstanding_balance), 0) FROM invoices WHERE payment_status IN ('unpaid','partial')")->fetchColumn();
$totalOverdue = (float) $pdo->query("SELECT COALESCE(SUM(outstanding_balance), 0) FROM invoices WHERE payment_status IN ('unpaid','partial') AND credit_due_date IS NOT NULL AND credit_due_date < CURDATE()")->fetchColumn();
$activeCreditCust = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE credit_limit > 0 AND credit_approval_status = 'approved'")->fetchColumn();
$overLimitCount = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE credit_limit > 0 AND credit_used > credit_limit")->fetchColumn();
$thisMonthCollected = (float) $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM customer_credit_payments WHERE DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')")->fetchColumn();
$avgDaysVal = $pdo->query('SELECT AVG(DATEDIFF(cp.payment_date, i.generated_at)) FROM customer_credit_payments cp JOIN invoices i ON cp.customer_id = i.customer_id WHERE cp.payment_date >= i.generated_at')->fetchColumn();
$avgCollectionDays = $avgDaysVal ? round((float) $avgDaysVal) : 0;
$custOverdueCount = (int) $pdo->query("SELECT COUNT(DISTINCT customer_id) FROM invoices WHERE payment_status IN ('unpaid','partial') AND credit_due_date IS NOT NULL AND credit_due_date < CURDATE()")->fetchColumn();
$badDebtRisk = (float) $pdo->query("SELECT COALESCE(SUM(outstanding_balance), 0) FROM invoices WHERE payment_status IN ('unpaid','partial') AND credit_due_date IS NOT NULL AND credit_due_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)")->fetchColumn();
$totalUsed = (float) $pdo->query("SELECT COALESCE(SUM(credit_used), 0) FROM customers WHERE credit_limit > 0")->fetchColumn();
$totalLimits = (float) $pdo->query("SELECT COALESCE(SUM(credit_limit), 0) FROM customers WHERE credit_limit > 0")->fetchColumn();
$creditUtilization = $totalLimits > 0 ? round(($totalUsed / $totalLimits) * 100, 1) : 0;
$collectionRate = $totalCreditSales > 0 ? round((($totalCreditSales - $totalOutstanding) / $totalCreditSales) * 100, 1) : 0;
$pendingApprovalCount = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE credit_approval_status = 'pending' AND credit_limit > 0")->fetchColumn();

$cmTab = $_GET['cm_tab'] ?? 'profiles';
$filterStatus = $_GET['status'] ?? 'all';
$searchName = trim($_GET['search'] ?? '');

$allCustomers = $pdo->query("
    SELECT c.*,
        COALESCE((SELECT SUM(outstanding_balance) FROM invoices WHERE customer_id = c.id AND payment_status IN ('unpaid','partial')), 0) AS outstanding,
        COALESCE((SELECT SUM(outstanding_balance) FROM invoices WHERE customer_id = c.id AND payment_status IN ('unpaid','partial') AND credit_due_date IS NOT NULL AND credit_due_date < CURDATE()), 0) AS overdue_amt,
        COALESCE((SELECT SUM(amount) FROM customer_credit_payments WHERE customer_id = c.id), 0) AS total_payments,
        COALESCE((SELECT MIN(credit_due_date) FROM invoices WHERE customer_id = c.id AND payment_status IN ('unpaid','partial') AND credit_due_date IS NOT NULL), '') AS next_due_date,
        COALESCE((SELECT full_name FROM users WHERE id = c.credit_approved_by), NULL) AS approved_by_name
    FROM customers c
    WHERE c.credit_limit > 0
    ORDER BY c.name
")->fetchAll();

$nonCreditCustomers = $pdo->query("
    SELECT c.id, c.name, c.customer_type, c.customer_status
    FROM customers c
    WHERE c.credit_limit <= 0
    ORDER BY c.name
")->fetchAll();

$filteredCustomers = [];
foreach ($allCustomers as $c) {
    $limit = (float) $c['credit_limit'];
    $used = (float) $c['credit_used'];
    $os = (float) $c['outstanding'];
    $ov = (float) $c['overdue_amt'];
    $status = $c['credit_approval_status'] ?? 'pending';
    $maxed = $limit > 0 && $used >= $limit;
    $isOverdue = $ov > 0;
    if ($searchName !== '' && stripos($c['name'], $searchName) === false) continue;
    if ($filterStatus === 'active' && ($status !== 'approved' || $isOverdue || $maxed)) continue;
    if ($filterStatus === 'overdue' && !$isOverdue) continue;
    if ($filterStatus === 'suspended' && $status !== 'suspended') continue;
    if ($filterStatus === 'maxed' && !$maxed) continue;
    if ($filterStatus === 'over_limit' && $status !== 'over_limit') continue;
    $c['_effective_status'] = $status;
    $c['_is_overdue'] = $isOverdue;
    $c['_is_maxed'] = $maxed;
    $filteredCustomers[] = $c;
}

$paymentsList = $pdo->query('SELECT cp.*, c.name AS customer_name, u.username AS recorded_by FROM customer_credit_payments cp JOIN customers c ON cp.customer_id = c.id LEFT JOIN users u ON cp.created_by = u.id ORDER BY cp.created_at DESC LIMIT 200')->fetchAll();

$pendingApprovals = $pdo->query("SELECT id, name, customer_type, credit_limit, credit_used, credit_score, credit_terms_days, created_at FROM customers WHERE credit_approval_status = 'pending' AND credit_limit > 0 ORDER BY name")->fetchAll();

$outstandingInvoices = $pdo->query("SELECT i.*, c.name AS customer_name FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE i.payment_status IN ('unpaid','partial') AND i.outstanding_balance > 0 ORDER BY i.credit_due_date ASC")->fetchAll();

$auditLog = [];
try {
    $auditLog = $pdo->query('SELECT cal.*, c.name AS customer_name, u.username AS user_name FROM credit_audit_log cal LEFT JOIN customers c ON cal.customer_id = c.id LEFT JOIN users u ON cal.user_id = u.id ORDER BY cal.created_at DESC LIMIT 200')->fetchAll();
} catch (Exception $e) { $auditLog = []; }

$overdueCustomers = [];
$overdueInvStmt = $pdo->query("SELECT i.*, c.name AS customer_name, c.credit_limit, c.credit_used, c.credit_score, c.credit_approval_status FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE i.payment_status IN ('unpaid','partial') AND i.outstanding_balance > 0 AND i.credit_due_date IS NOT NULL AND i.credit_due_date < CURDATE() ORDER BY c.name, i.credit_due_date ASC");
foreach ($overdueInvStmt->fetchAll() as $inv) {
    $cid = (int) $inv['customer_id'];
    if (!isset($overdueCustomers[$cid])) {
        $overdueCustomers[$cid] = [
            'id' => $cid, 'name' => $inv['customer_name'],
            'credit_limit' => (float) $inv['credit_limit'], 'credit_used' => (float) $inv['credit_used'],
            'credit_score' => (int) $inv['credit_score'], 'credit_approval_status' => $inv['credit_approval_status'],
            'total_overdue' => 0, 'invoices' => [],
        ];
    }
    $overdueCustomers[$cid]['total_overdue'] += (float) $inv['outstanding_balance'];
    $overdueCustomers[$cid]['invoices'][] = $inv;
}
$overdueCustomers = array_values($overdueCustomers);

$upcomingCollections = $pdo->query("SELECT i.id AS invoice_id, i.total_amount, i.outstanding_balance, i.credit_due_date, i.payment_status, c.id AS customer_id, c.name AS customer_name FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE i.payment_status IN ('unpaid','partial') AND i.outstanding_balance > 0 AND i.credit_due_date IS NOT NULL ORDER BY i.credit_due_date ASC LIMIT 100")->fetchAll();

$collectionSchedule = ['this_week' => [], 'next_week' => [], 'this_month' => [], 'next_month' => [], 'future' => []];
$now = new DateTime();
foreach ($upcomingCollections as $uc) {
    $due = new DateTime($uc['credit_due_date']);
    $diff = (int) $now->diff($due)->days;
    if ($due < $now) continue;
    if ($diff <= 7) $collectionSchedule['this_week'][] = $uc;
    elseif ($diff <= 14) $collectionSchedule['next_week'][] = $uc;
    elseif ($diff <= 30) $collectionSchedule['this_month'][] = $uc;
    elseif ($diff <= 60) $collectionSchedule['next_month'][] = $uc;
    else $collectionSchedule['future'][] = $uc;
}

$s = [];
foreach ($pdo->query("SELECT setting_key, setting_value FROM credit_control_settings")->fetchAll() as $row) $s[$row['setting_key']] = $row['setting_value'];

$activeAgreement = creditGetActiveAgreement($pdo);
?>
<style>
.cc{max-width:1440px;margin:0 auto}
.cc-head{font-size:1.3rem;font-weight:700;color:#1e293b;margin:0 0 20px;display:flex;align-items:center;gap:10px}
.cc-head i{color:#2563eb}
.cc-tabs{display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:0;overflow-x:auto}
.cc-tab{padding:11px 20px;font-size:.85rem;font-weight:600;color:#64748b;cursor:pointer;border:none;background:none;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .15s;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;font-family:inherit}
.cc-tab:hover{color:#334155}
.cc-tab.active{color:#2563eb;border-bottom-color:#2563eb}
.cc-panel{display:none;padding:20px 0}
.cc-panel.active{display:block}
.cc-cards{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.cc-card{background:#fff;border-radius:12px;padding:18px;border:1px solid #e2e8f0;text-align:center}
.cc-card-val{font-size:1.3rem;font-weight:800;color:#1e293b}
.cc-card-lbl{font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;margin-top:4px}
.cc-section{background:#fff;border-radius:14px;border:1px solid #e2e8f0;margin-bottom:20px;overflow:hidden}
.cc-sec-head{padding:16px 22px;border-bottom:1px solid #f1f5f9;font-size:0.95rem;font-weight:700;color:#1e293b;display:flex;align-items:center;gap:8px}
.cc-sec-head i{color:#2563eb}
.cc-sec-body{padding:20px 22px}
.cc-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
.cc-fg{margin-bottom:14px}
.cc-fg label{display:block;font-size:0.8rem;font-weight:600;color:#475569;margin-bottom:4px}
.cc-fg input[type="number"]{width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:8px;font:inherit;font-size:0.88rem;color:#1e293b;box-sizing:border-box}
.cc-fg input:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,0.1)}
.cc-toggle{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid #f1f5f9}
.cc-toggle:last-child{border-bottom:none}
.cc-toggle-info{flex:1}
.cc-toggle-title{font-size:0.88rem;font-weight:600;color:#1e293b}
.cc-toggle-desc{font-size:0.78rem;color:#64748b;margin-top:2px}
.cc-switch{position:relative;width:48px;height:26px;flex-shrink:0}
.cc-switch input{opacity:0;width:0;height:0}
.cc-switch .slider{position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:#cbd5e1;border-radius:26px;transition:0.2s}
.cc-switch .slider::before{content:'';position:absolute;height:20px;width:20px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:0.2s}
.cc-switch input:checked + .slider{background:#2563eb}
.cc-switch input:checked + .slider::before{transform:translateX(22px)}
.cc-btn{display:inline-flex;align-items:center;gap:6px;padding:10px 24px;border-radius:8px;font-weight:600;font-size:0.88rem;cursor:pointer;border:none;font-family:inherit;transition:all 0.15s}
.cc-btn-primary{background:#2563eb;color:#fff}.cc-btn-primary:hover{background:#1d4ed8}
.cc-save{display:flex;justify-content:flex-end;padding:16px 22px;border-top:1px solid #f1f5f9;background:#f8fafc;border-radius:0 0 14px 14px}
.cc-msg{padding:12px 18px;border-radius:10px;margin-bottom:16px;font-weight:600;font-size:0.88rem;display:flex;align-items:center;gap:10px}
.cc-msg.success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}
.cc-msg.info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}
.cc-msg.error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.cr-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px}
.cr-stat{background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:18px 16px;display:flex;align-items:center;gap:12px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.cr-stat-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.15rem;flex-shrink:0}
.cr-stat-val{font-size:1.25rem;font-weight:700;color:#0f172a;line-height:1.2}
.cr-stat-label{font-size:.76rem;color:#64748b;margin-top:2px}
.cr-stat-sub{font-size:.68rem;color:#94a3b8;margin-top:1px}
.cr-tabs{display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:0;overflow-x:auto;flex-wrap:nowrap}
.cr-tab{padding:10px 18px;font-size:.82rem;font-weight:600;color:#64748b;cursor:pointer;border:none;background:none;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .15s;display:inline-flex;align-items:center;gap:5px;white-space:nowrap}
.cr-tab:hover{color:#334155}
.cr-tab.active{color:#2563eb;border-bottom-color:#2563eb}
.cr-panel{display:none;padding:20px 0}
.cr-panel.active{display:block}
.cr-card{background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:20px;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.cr-card-header{font-size:.95rem;font-weight:700;color:#0f172a;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.cr-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px}
.cr-toolbar select,.cr-toolbar input[type="text"]{padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem}
.cr-toolbar input[type="text"]{min-width:220px}
.cr-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:600}
.cr-badge-green{background:#dcfce7;color:#166534}
.cr-badge-orange{background:#ffedd5;color:#9a3412}
.cr-badge-red{background:#fee2e2;color:#991b1b}
.cr-badge-darkred{background:#991b1b;color:#fff}
.cr-badge-blue{background:#dbeafe;color:#1e40af}
.cr-badge-gray{background:#f1f5f9;color:#475569}
.cr-badge-purple{background:#f3e8ff;color:#6b21a8}
.cr-table{width:100%;border-collapse:collapse;font-size:.84rem}
.cr-table th{text-align:left;padding:10px 12px;background:#f8fafc;font-weight:600;color:#475569;border-bottom:2px solid #e2e8f0;font-size:.75rem;text-transform:uppercase;letter-spacing:.03em}
.cr-table td{padding:10px 12px;border-bottom:1px solid #f1f5f9}
.cr-table tbody tr{transition:background .1s}
.cr-table tbody tr:hover{background:#f8fafc}
.cr-progress-wrap{width:100%;background:#e2e8f0;border-radius:6px;height:8px;overflow:hidden}
.cr-progress-bar{height:100%;border-radius:6px;transition:width .3s}
.cr-detail-panel{display:none;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin:8px 0 16px}
.cr-detail-panel.open{display:block}
.cr-detail-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:16px}
.cr-detail-item .cr-label{font-size:.73rem;color:#64748b;text-transform:uppercase;font-weight:600;margin-bottom:3px}
.cr-detail-item .cr-val{font-size:.95rem;font-weight:600;color:#0f172a}
.cr-gauge{width:100%;max-width:260px;margin:10px auto;text-align:center}
.cr-gauge-circle{width:100px;height:100px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 8px;font-size:1.4rem;font-weight:800;color:#fff}
.cr-gauge-label{font-size:.78rem;color:#64748b}
.cr-form-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-top:10px}
.cr-form-row label{font-size:.78rem;color:#475569;font-weight:600;display:flex;flex-direction:column;gap:3px}
.cr-form-row input,.cr-form-row select{padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:.83rem}
.cr-invoice-list{margin:10px 0}
.cr-invoice-list table{width:100%;font-size:.8rem}
.cr-invoice-list th{background:#f1f5f9;padding:6px 10px;text-align:left;font-size:.72rem;text-transform:uppercase;color:#475569}
.cr-invoice-list td{padding:6px 10px;border-bottom:1px solid #f1f5f9}
.cr-empty{text-align:center;padding:30px;color:#94a3b8}
.cr-empty i{font-size:2rem;display:block;margin-bottom:8px;color:#cbd5e1}
.cr-filter-pills{display:flex;gap:6px;flex-wrap:wrap}
.cr-pill{padding:6px 14px;border-radius:20px;font-size:.78rem;font-weight:600;border:1px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer;transition:all .15s}
.cr-pill:hover{border-color:#94a3b8;color:#334155}
.cr-pill.active{background:#2563eb;color:#fff;border-color:#2563eb}
.cr-edit-inline{display:none;margin:10px 0;padding:12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px}
.cr-edit-inline.open{display:block}
.cr-section{margin-bottom:24px}
.cr-section-title{font-size:.88rem;font-weight:700;color:#0f172a;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.cr-schedule-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}
.cr-schedule-card{border-radius:10px;border:1px solid #e2e8f0;padding:16px}
.cr-schedule-card h4{font-size:.85rem;font-weight:700;color:#0f172a;margin:0 0 8px;display:flex;align-items:center;gap:6px}
.cr-schedule-item{padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:.8rem;display:flex;justify-content:space-between;align-items:center}
.cr-schedule-item:last-child{border-bottom:none}
.cr-search-box{display:flex;gap:8px;align-items:center;margin-left:auto}
.cr-modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:1050;align-items:flex-start;justify-content:center;padding:60px 16px;overflow-y:auto}
.cr-modal-overlay.open{display:flex}
.cr-modal{background:#fff;border-radius:14px;border:1px solid #e2e8f0;box-shadow:0 20px 50px rgba(0,0,0,.2);max-width:560px;width:100%;padding:24px}
.cr-modal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
.cr-modal-head h3{font-size:1.05rem;font-weight:700;color:#0f172a;margin:0}
.cr-modal-sub{font-size:.8rem;color:#64748b;margin-bottom:16px}
.cr-modal-close{background:none;border:none;font-size:1.2rem;color:#94a3b8;cursor:pointer;padding:4px 8px;border-radius:6px}
.cr-modal-close:hover{background:#f1f5f9;color:#334155}
.cr-modal-form{display:flex;flex-direction:column;gap:14px}
.cr-modal-form label{font-size:.8rem;font-weight:600;color:#475569;display:flex;flex-direction:column;gap:4px}
.cr-modal-form input,.cr-modal-form select{padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;width:100%;box-sizing:border-box}
.cr-modal-form input:focus,.cr-modal-form select:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1)}
.cr-modal-preview{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;font-size:.8rem}
.cr-modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:6px}
@media(max-width:1200px){.cr-stats{grid-template-columns:repeat(2,1fr)}.cc-cards{grid-template-columns:repeat(2,1fr)}}
@media(max-width:768px){.cr-stats{grid-template-columns:repeat(2,1fr)}.cr-table{font-size:.78rem}.cr-table th,.cr-table td{padding:8px 6px}.cr-form-row{flex-direction:column}.cr-form-row label{width:100%}.cc-cards{grid-template-columns:1fr 1fr}.cc-row{grid-template-columns:1fr}}
@media(max-width:480px){.cr-stats{grid-template-columns:1fr}}
</style>
<style>@media(max-width:768px){.cr-detail-grid{grid-template-columns:1fr!important}.cr-pay-grid{grid-template-columns:1fr!important}.cr-pay-inner{grid-template-columns:1fr!important}}</style>

<section class="cc">
<div class="cc-head"><i class="fas fa-sliders-h"></i> Credit Control &amp; Management</div>

<?php foreach ($messages as $m): ?>
<div class="cc-msg <?= $m['type'] ?>"><i class="fas fa-<?= $m['type']==='success'?'check-circle':($m['type']==='error'?'exclamation-circle':'info-circle') ?>"></i> <?= htmlspecialchars($m['text']) ?></div>
<?php endforeach; ?>

<div class="cc-tabs">
    <button class="cc-tab <?= $activeTab === 'settings' ? 'active' : '' ?>" onclick="ccGoTab(event,'settings')"><i class="fas fa-sliders-h"></i> Settings</button>
    <button class="cc-tab <?= $activeTab === 'management' ? 'active' : '' ?>" onclick="ccGoTab(event,'management')"><i class="fas fa-credit-card"></i> Credit Management</button>
</div>

<!-- ════════════ SETTINGS TAB ════════════ -->
<div id="cc-panel-settings" class="cc-panel <?= $activeTab === 'settings' ? 'active' : '' ?>">
<div class="cc-cards">
    <div class="cc-card"><div class="cc-card-val"><?= $stats['credit_customers'] ?></div><div class="cc-card-lbl">Credit Customers</div></div>
    <div class="cc-card"><div class="cc-card-val" style="color:#dc2626"><?= fmt((float)$stats['total_outstanding']) ?></div><div class="cc-card-lbl">Total Outstanding</div></div>
    <div class="cc-card"><div class="cc-card-val" style="color:#b45309"><?= fmt((float)$stats['total_overdue']) ?></div><div class="cc-card-lbl">Total Overdue</div></div>
    <div class="cc-card"><div class="cc-card-val" style="color:#059669"><?= fmt((float)$stats['total_limits']) ?></div><div class="cc-card-lbl">Total Credit Limits</div></div>
</div>

<form method="post">
<?= csrf_field() ?>

<div class="cc-section">
    <div class="cc-sec-head"><i class="fas fa-shield-alt"></i> Credit Policy</div>
    <div class="cc-sec-body">
        <div class="cc-toggle">
            <div class="cc-toggle-info"><div class="cc-toggle-title">Allow Credit Sales</div><div class="cc-toggle-desc">Enable credit-based purchasing for customers</div></div>
            <label class="cc-switch"><input type="checkbox" name="allow_credit_sales" value="1" <?= ($s['allow_credit_sales'] ?? '1') === '1' ? 'checked' : '' ?>><span class="slider"></span></label>
        </div>
        <div class="cc-toggle">
            <div class="cc-toggle-info"><div class="cc-toggle-title">Require Credit Approval</div><div class="cc-toggle-desc">Customers must be approved before using credit</div></div>
            <label class="cc-switch"><input type="checkbox" name="require_credit_approval" value="1" <?= ($s['require_credit_approval'] ?? '1') === '1' ? 'checked' : '' ?>><span class="slider"></span></label>
        </div>
        <div class="cc-toggle">
            <div class="cc-toggle-info"><div class="cc-toggle-title">Allow Override (Super Admin)</div><div class="cc-toggle-desc">Allow overriding credit limit restrictions</div></div>
            <label class="cc-switch"><input type="checkbox" name="allow_override" value="1" <?= ($s['allow_override'] ?? '1') === '1' ? 'checked' : '' ?>><span class="slider"></span></label>
        </div>
    </div>
</div>

<div class="cc-section">
    <div class="cc-sec-head"><i class="fas fa-file-contract"></i> Credit Agreement</div>
    <div class="cc-sec-body">
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 12px;font-size:0.8rem;color:#1e40af;margin-bottom:14px">
            <i class="fas fa-history"></i> Customers selecting <strong>CREDIT</strong> must review and accept the current agreement. Saving a change here publishes a <strong>new version</strong> — existing orders keep the exact version they accepted.
            <div style="margin-top:6px">Currently active: <strong>Version <?= (int) $activeAgreement['version'] ?></strong> · Payment terms <strong><?= (int) $activeAgreement['payment_terms_days'] ?></strong> days.</div>
        </div>
        <div class="cc-row">
            <div class="cc-fg"><label>Agreement Title</label><input type="text" name="agreement_title" value="<?= htmlspecialchars($s['agreement_title'] ?? 'Credit Account Terms &amp; Conditions') ?>" maxlength="255"></div>
            <div class="cc-fg"><label>Payment Terms (Days)</label><input type="number" name="default_credit_days" value="<?= htmlspecialchars($s['default_credit_days'] ?? (string) SettingsService::defaultCreditDays()) ?>" min="1" max="365"><span style="font-size:0.74rem;color:#94a3b8">Used as the due days in the agreement and for new credit invoices.</span></div>
        </div>
        <div class="cc-fg" style="margin-bottom:14px"><label>Agreement Terms &amp; Conditions</label><textarea name="agreement_content" rows="9" style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:0.85rem;font-family:inherit;box-sizing:border-box"><?= htmlspecialchars($s['agreement_content'] ?? '') ?></textarea></div>
        <div class="cc-toggle">
            <div class="cc-toggle-info"><div class="cc-toggle-title">Require Agreement Acceptance</div><div class="cc-toggle-desc">Customers must check the acceptance box when placing credit orders</div></div>
            <label class="cc-switch"><input type="checkbox" name="require_agreement_acceptance" value="1" <?= ($s['require_agreement_acceptance'] ?? '1') === '1' ? 'checked' : '' ?>><span class="slider"></span></label>
        </div>
    </div>
</div>

<div class="cc-section">
    <div class="cc-sec-head"><i class="fas fa-money-check-alt"></i> Credit Limits</div>
    <div class="cc-sec-body">
        <div class="cc-row">
            <div class="cc-fg"><label>Maximum Credit Limit (<?= SettingsService::currencySymbol() ?>)</label><input type="number" name="max_credit_limit" value="<?= htmlspecialchars($s['max_credit_limit'] ?? (string) SettingsService::maxCreditLimit()) ?>" min="0" step="100"></div>
            <div></div>
        </div>
    </div>
</div>

<div class="cc-section">
    <div class="cc-sec-head"><i class="fas fa-exclamation-triangle"></i> Overdue Management</div>
    <div class="cc-sec-body">
        <div class="cc-row">
            <div class="cc-fg"><label>Grace Period (Days)</label><input type="number" name="grace_period_days" value="<?= htmlspecialchars($s['grace_period_days'] ?? '7') ?>" min="0" max="90"></div>
            <div class="cc-fg"><label>Penalty Percentage (%)</label><input type="number" name="penalty_percentage" value="<?= htmlspecialchars($s['penalty_percentage'] ?? '2') ?>" min="0" max="50" step="0.01"></div>
        </div>
        <div class="cc-row">
            <div class="cc-fg"><label>Interest Percentage (%/month)</label><input type="number" name="interest_percentage" value="<?= htmlspecialchars($s['interest_percentage'] ?? '1.5') ?>" min="0" max="50" step="0.01"></div>
            <div></div>
        </div>
        <div class="cc-toggle">
            <div class="cc-toggle-info"><div class="cc-toggle-title">Auto Suspend on Overdue</div><div class="cc-toggle-desc">Automatically suspend credit when payment becomes overdue</div></div>
            <label class="cc-switch"><input type="checkbox" name="auto_suspend_on_overdue" value="1" <?= ($s['auto_suspend_on_overdue'] ?? '0') === '1' ? 'checked' : '' ?>><span class="slider"></span></label>
        </div>
        <div class="cc-toggle">
            <div class="cc-toggle-info"><div class="cc-toggle-title">Block New Orders on Overdue</div><div class="cc-toggle-desc">Prevent customers with overdue balance from placing new orders</div></div>
            <label class="cc-switch"><input type="checkbox" name="block_new_orders_on_overdue" value="1" <?= ($s['block_new_orders_on_overdue'] ?? '0') === '1' ? 'checked' : '' ?>><span class="slider"></span></label>
        </div>
    </div>
</div>

<div class="cc-save">
    <button type="submit" class="cc-btn cc-btn-primary"><i class="fas fa-save"></i> Save Settings</button>
</div>
</form>
</div>

<!-- ════════════ CREDIT MANAGEMENT TAB ════════════ -->
<div id="cc-panel-management" class="cc-panel <?= $activeTab === 'management' ? 'active' : '' ?>">

<div class="cr-stats">
    <div class="cr-stat"><div class="cr-stat-icon" style="background:#dbeafe;color:#2563eb"><i class="fas fa-file-invoice-dollar"></i></div><div><div class="cr-stat-val"><?= fmt($totalCreditSales) ?></div><div class="cr-stat-label">Total Credit Sales</div><div class="cr-stat-sub">Lifetime credit revenue</div></div></div>
    <div class="cr-stat"><div class="cr-stat-icon" style="background:#fef3c7;color:#d97706"><i class="fas fa-hourglass-half"></i></div><div><div class="cr-stat-val"><?= fmt($totalOutstanding) ?></div><div class="cr-stat-label">Total Outstanding</div><div class="cr-stat-sub"><?= $activeCreditCust ?> active customers</div></div></div>
    <div class="cr-stat"><div class="cr-stat-icon" style="background:#fee2e2;color:#dc2626"><i class="fas fa-exclamation-triangle"></i></div><div><div class="cr-stat-val"><?= fmt($totalOverdue) ?></div><div class="cr-stat-label">Total Overdue</div><div class="cr-stat-sub"><?= $custOverdueCount ?> overdue accounts</div></div></div>
    <div class="cr-stat"><div class="cr-stat-icon" style="background:#dcfce7;color:#059669"><i class="fas fa-users"></i></div><div><div class="cr-stat-val"><?= $activeCreditCust ?></div><div class="cr-stat-label">Active Credit Customers</div><div class="cr-stat-sub"><?= $overLimitCount ?> over limit</div></div></div>
    <div class="cr-stat"><div class="cr-stat-icon" style="background:#fce7f3;color:#db2777"><i class="fas fa-exclamation-circle"></i></div><div><div class="cr-stat-val"><?= $overLimitCount ?></div><div class="cr-stat-label">Customers Over Limit</div><div class="cr-stat-sub"><?= $creditUtilization ?>% utilization</div></div></div>
    <div class="cr-stat"><div class="cr-stat-icon" style="background:#ede9fe;color:#7c3aed"><i class="fas fa-hand-holding-usd"></i></div><div><div class="cr-stat-val"><?= fmt($thisMonthCollected) ?></div><div class="cr-stat-label">Collections This Month</div><div class="cr-stat-sub"><?= $collectionRate ?>% collection rate</div></div></div>
    <div class="cr-stat"><div class="cr-stat-icon" style="background:#e0e7ff;color:#4338ca"><i class="fas fa-clock"></i></div><div><div class="cr-stat-val"><?= $avgCollectionDays ?> days</div><div class="cr-stat-label">Avg Collection Days</div><div class="cr-stat-sub">Payment turnaround</div></div></div>
    <div class="cr-stat"><div class="cr-stat-icon" style="background:#fef2f2;color:#991b1b"><i class="fas fa-skull"></i></div><div><div class="cr-stat-val"><?= fmt($badDebtRisk) ?></div><div class="cr-stat-label">Bad Debt Risk</div><div class="cr-stat-sub">90+ days overdue</div></div></div>
</div>

<div class="cr-tabs">
    <button class="cr-tab <?= $cmTab === 'profiles' ? 'active' : '' ?>" onclick="ccGoCmTab(event,'profiles')"><i class="fas fa-users"></i> Customer Credit Profiles</button>
    <button class="cr-tab <?= $cmTab === 'outstanding' ? 'active' : '' ?>" onclick="ccGoCmTab(event,'outstanding')"><i class="fas fa-file-invoice"></i> Outstanding Invoices</button>
    <button class="cr-tab <?= $cmTab === 'payments' ? 'active' : '' ?>" onclick="ccGoCmTab(event,'payments')"><i class="fas fa-money-check-alt"></i> Record Payment</button>
    <button class="cr-tab <?= $cmTab === 'history' ? 'active' : '' ?>" onclick="ccGoCmTab(event,'history')"><i class="fas fa-history"></i> Payment History</button>
    <button class="cr-tab <?= $cmTab === 'schedule' ? 'active' : '' ?>" onclick="ccGoCmTab(event,'schedule')"><i class="fas fa-calendar-alt"></i> Collection Schedule</button>
    <button class="cr-tab <?= $cmTab === 'overdue' ? 'active' : '' ?>" onclick="ccGoCmTab(event,'overdue')"><i class="fas fa-exclamation-triangle"></i> Overdue Customers</button>
    <button class="cr-tab <?= $cmTab === 'approvals' ? 'active' : '' ?>" onclick="ccGoCmTab(event,'approvals')"><i class="fas fa-check-double"></i> Credit Approvals <?= $pendingApprovalCount > 0 ? '<span class="cr-badge cr-badge-orange" style="font-size:.7rem;padding:1px 6px">'.$pendingApprovalCount.'</span>' : '' ?></button>
    <button class="cr-tab <?= $cmTab === 'audit' ? 'active' : '' ?>" onclick="ccGoCmTab(event,'audit')"><i class="fas fa-clipboard-list"></i> Credit Audit Log</button>
</div>

<!-- Customer Credit Profiles -->
<div id="cm-tab-profiles" class="cr-panel <?= $cmTab === 'profiles' ? 'active' : '' ?>">
    <div class="cr-card" style="border-top-left-radius:0;border-top-right-radius:0">
        <div class="cr-toolbar">
            <?php if (hasPermission('credit_control', 'edit')): ?>
            <button class="cc-btn cc-btn-primary" onclick="ccOpenAddModal()"><i class="fas fa-user-plus"></i> Add Credit Customer</button>
            <?php endif; ?>
            <div class="cr-filter-pills">
                <button class="cr-pill <?= $filterStatus === 'all' ? 'active' : '' ?>" onclick="ccPillFilter(event,'all')">All</button>
                <button class="cr-pill <?= $filterStatus === 'active' ? 'active' : '' ?>" onclick="ccPillFilter(event,'active')">Active</button>
                <button class="cr-pill <?= $filterStatus === 'overdue' ? 'active' : '' ?>" onclick="ccPillFilter(event,'overdue')">Overdue</button>
                <button class="cr-pill <?= $filterStatus === 'suspended' ? 'active' : '' ?>" onclick="ccPillFilter(event,'suspended')">Suspended</button>
                <button class="cr-pill <?= $filterStatus === 'maxed' ? 'active' : '' ?>" onclick="ccPillFilter(event,'maxed')">Maxed Out</button>
                <button class="cr-pill <?= $filterStatus === 'over_limit' ? 'active' : '' ?>" onclick="ccPillFilter(event,'over_limit')" style="border-color:#991b1b;color:#991b1b">Over Limit</button>
            </div>
            <form method="get" class="cr-search-box">
                <input type="hidden" name="page" value="credit_control">
                <input type="hidden" name="tab" value="management">
                <input type="hidden" name="cm_tab" value="profiles">
                <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
                <input type="text" name="search" value="<?= htmlspecialchars($searchName) ?>" placeholder="Search customer...">
                <button type="submit" class="cc-btn cc-btn-primary" style="padding:7px 14px;font-size:.8rem"><i class="fas fa-search"></i></button>
            </form>
        </div>
        <?php if (empty($filteredCustomers)): ?>
            <div class="cr-empty"><i class="fas fa-inbox"></i>No customers with credit profiles found.</div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="cr-table">
            <thead><tr><th>Customer Name</th><th>Type</th><th>Terms</th><th>Credit Limit</th><th>Used</th><th>Available</th><th>Utilization</th><th>Outstanding</th><th>Overdue</th><th>Score</th><th>Risk</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($filteredCustomers as $c):
                    $limit = (float) $c['credit_limit']; $used = (float) $c['credit_used']; $available = max($limit - $used, 0);
                    $os = (float) $c['outstanding']; $ov = (float) $c['overdue_amt']; $score = (int) $c['credit_score'];
                    $status = $c['_effective_status']; $isOverdue = $c['_is_overdue']; $isMaxed = $c['_is_maxed'];
                    $utilPct = $limit > 0 ? round(($used / $limit) * 100) : 0;
                    $riskLevel = $score >= 650 ? 'low' : ($score >= 500 ? 'moderate' : ($score >= 300 ? 'high' : 'critical'));
                    $riskColors = ['low'=>'#059669','moderate'=>'#d97706','high'=>'#dc2626','critical'=>'#991b1b'];
                    $rowColor = $isOverdue ? '#fef2f2' : ($isMaxed ? '#fffbeb' : ($utilPct > 75 ? '#fffbeb' : ''));
                    $utilColor = $utilPct >= 100 ? '#dc2626' : ($utilPct > 80 ? '#d97706' : '#059669');
                ?>
                    <tr style="background:<?= $rowColor ?>">
                        <td style="font-weight:600;color:#0f172a"><a href="javascript:ccToggleDetail(<?= $c['id'] ?>)" style="color:#2563eb;text-decoration:none"><?= htmlspecialchars($c['name']) ?></a></td>
                        <td><?= htmlspecialchars($c['customer_type'] ?? '—') ?></td>
                        <td style="font-size:.8rem;color:#64748b"><?= (int)($c['credit_terms_days'] ?? 30) ?> days</td>
                        <td style="font-weight:600"><?= fmt($limit) ?></td>
                        <td style="font-weight:600;color:#d97706"><?= fmt($used) ?></td>
                        <td style="font-weight:600;color:<?= $available > 0 ? '#059669' : '#dc2626' ?>"><?= fmt($available) ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:6px">
                                <div class="cr-progress-wrap" style="width:50px;height:6px;flex-shrink:0"><div class="cr-progress-bar" style="width:<?= min($utilPct,100) ?>%;background:<?= $utilColor ?>"></div></div>
                                <span style="font-weight:700;font-size:.75rem;color:<?= $utilColor ?>"><?= $utilPct ?>%</span>
                            </div>
                        </td>
                        <td style="font-weight:700;color:<?= $os > 0 ? '#dc2626' : '#059669' ?>"><?= fmt($os) ?></td>
                        <td style="font-weight:700;color:<?= $ov > 0 ? '#dc2626' : '#64748b' ?>"><?= $ov > 0 ? fmt($ov) : '—' ?></td>
                        <td><span style="font-weight:700;color:<?= $riskColors[$riskLevel] ?>"><?= $score ?></span></td>
                        <td><span class="cr-badge <?= $riskLevel === 'low' ? 'cr-badge-green' : ($riskLevel === 'moderate' ? 'cr-badge-orange' : ($riskLevel === 'high' ? 'cr-badge-red' : 'cr-badge-darkred')) ?>"><?= ucfirst($riskLevel) ?></span></td>
                        <td><?php
                            if ($status === 'over_limit'): ?><span class="cr-badge" style="background:#991b1b;color:#fff"><i class="fas fa-exclamation-triangle"></i> Over Limit</span><?php
                            elseif ($status === 'approved'): ?><span class="cr-badge cr-badge-green"><i class="fas fa-check-circle"></i> Approved</span><?php
                            elseif ($status === 'suspended'): ?><span class="cr-badge cr-badge-red"><i class="fas fa-ban"></i> Suspended</span><?php
                            elseif ($status === 'rejected'): ?><span class="cr-badge cr-badge-darkred"><i class="fas fa-times-circle"></i> Rejected</span><?php
                            else: ?><span class="cr-badge cr-badge-orange"><i class="fas fa-clock"></i> Pending</span><?php
                            endif; ?></td>
                        <td onclick="event.stopPropagation()" style="white-space:nowrap"><button class="cr-btn cc-btn-primary" style="padding:4px 10px;font-size:.75rem;border-radius:6px" onclick="ccToggleDetail(<?= $c['id'] ?>)"><i class="fas fa-eye"></i> View</button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php foreach ($filteredCustomers as $c):
            $limit = (float) $c['credit_limit']; $used = (float) $c['credit_used']; $available = max($limit - $used, 0);
            $os = (float) $c['outstanding']; $ov = (float) $c['overdue_amt']; $score = (int) $c['credit_score'];
            $utilPct = $limit > 0 ? round(($used / $limit) * 100) : 0;
            $riskLevel = $score >= 650 ? 'low' : ($score >= 500 ? 'moderate' : ($score >= 300 ? 'high' : 'critical'));
            $riskColors = ['low'=>'#059669','moderate'=>'#d97706','high'=>'#dc2626','critical'=>'#991b1b'];
            $scoreColor = $riskColors[$riskLevel];
            $invStmt = $pdo->prepare('SELECT * FROM invoices WHERE customer_id = :cid AND payment_status IN ("unpaid","partial") AND outstanding_balance > 0 ORDER BY credit_due_date ASC');
            $invStmt->execute(['cid' => $c['id']]); $openInvoices = $invStmt->fetchAll();
            $payStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM customer_credit_payments WHERE customer_id = :cid');
            $payStmt->execute(['cid' => $c['id']]); $totalPaid = (float) $payStmt->fetchColumn();
            $totalSalesStmt = $pdo->prepare('SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE customer_id = :cid');
            $totalSalesStmt->execute(['cid' => $c['id']]); $totalCreditSalesCust = (float) $totalSalesStmt->fetchColumn();
            $payProgress = $totalCreditSalesCust > 0 ? round(($totalPaid / $totalCreditSalesCust) * 100) : 0;
        ?>
            <div class="cr-detail-panel" id="cc-detail-<?= $c['id'] ?>">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:16px">
                    <div><h3 style="font-size:1.05rem;font-weight:700;color:#0f172a;margin:0"><?= htmlspecialchars($c['name']) ?></h3><div style="font-size:.8rem;color:#64748b;margin-top:3px"><?= htmlspecialchars($c['customer_type'] ?? 'Business') ?> &middot; Terms: <?= $c['credit_terms_days'] ?? 30 ?> days</div></div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                        <button class="cc-btn cc-btn-primary" style="padding:4px 10px;font-size:.75rem;border-radius:6px" onclick="ccToggleEditLimit(<?= $c['id'] ?>)"><i class="fas fa-edit"></i> Edit Limit</button>
                        <?php if ($c['_effective_status'] !== 'approved'): ?>
                            <form method="post" style="display:inline" onsubmit="return confirm('Approve credit for this customer?')"><?= csrf_field() ?><input type="hidden" name="_action" value="approve_credit"><input type="hidden" name="customer_id" value="<?= $c['id'] ?>"><button type="submit" class="cc-btn cc-btn-primary" style="padding:4px 10px;font-size:.75rem;border-radius:6px;background:#059669"><i class="fas fa-check"></i> Approve</button></form>
                        <?php endif; ?>
                        <?php if ($c['_effective_status'] !== 'suspended'): ?>
                            <form method="post" style="display:inline" onsubmit="return confirm('Suspend credit for this customer?')"><?= csrf_field() ?><input type="hidden" name="_action" value="suspend_credit"><input type="hidden" name="customer_id" value="<?= $c['id'] ?>"><button type="submit" class="cc-btn cc-btn-primary" style="padding:4px 10px;font-size:.75rem;border-radius:6px;background:#dc2626"><i class="fas fa-ban"></i> Suspend</button></form>
                        <?php else: ?>
                            <form method="post" style="display:inline" onsubmit="return confirm('Resume credit for this customer?')"><?= csrf_field() ?><input type="hidden" name="_action" value="resume_credit"><input type="hidden" name="customer_id" value="<?= $c['id'] ?>"><button type="submit" class="cc-btn cc-btn-primary" style="padding:4px 10px;font-size:.75rem;border-radius:6px;background:#059669"><i class="fas fa-play"></i> Resume</button></form>
                        <?php endif; ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('Send payment reminder?')"><?= csrf_field() ?><input type="hidden" name="_action" value="send_reminder"><input type="hidden" name="customer_id" value="<?= $c['id'] ?>"><button type="submit" class="cc-btn cc-btn-primary" style="padding:4px 10px;font-size:.75rem;border-radius:6px;background:#d97706"><i class="fas fa-bell"></i> Send Reminder</button></form>
                        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="_action" value="print_statement"><input type="hidden" name="customer_id" value="<?= $c['id'] ?>"><button type="submit" class="cc-btn" style="padding:4px 10px;font-size:.75rem;border-radius:6px;background:transparent;border:1px solid #cbd5e1;color:#475569"><i class="fas fa-print"></i> Print Statement</button></form>
                    </div>
                </div>
                <div class="cr-edit-inline" id="cc-edit-<?= $c['id'] ?>">
                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
                        <form method="post" style="display:flex;gap:8px;align-items:flex-end"><?= csrf_field() ?><input type="hidden" name="_action" value="increase_limit"><input type="hidden" name="customer_id" value="<?= $c['id'] ?>"><label style="font-size:.78rem;font-weight:600;color:#475569;display:flex;flex-direction:column;gap:3px">Increase by (<?= currency_sym() ?>)<input type="number" step="0.01" name="add_amount" min="0.01" required style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:.83rem;width:120px"></label><button type="submit" class="cc-btn cc-btn-primary" style="padding:5px 10px;font-size:.75rem;border-radius:6px;background:#059669"><i class="fas fa-plus"></i> Increase</button></form>
                        <form method="post" style="display:flex;gap:8px;align-items:flex-end"><?= csrf_field() ?><input type="hidden" name="_action" value="reduce_limit"><input type="hidden" name="customer_id" value="<?= $c['id'] ?>"><label style="font-size:.78rem;font-weight:600;color:#475569;display:flex;flex-direction:column;gap:3px">Reduce by (<?= currency_sym() ?>)<input type="number" step="0.01" name="sub_amount" min="0.01" required style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:.83rem;width:120px"></label><button type="submit" class="cc-btn cc-btn-primary" style="padding:5px 10px;font-size:.75rem;border-radius:6px;background:#dc2626"><i class="fas fa-minus"></i> Reduce</button></form>
                        <form method="post" style="display:flex;gap:8px;align-items:flex-end"><?= csrf_field() ?><input type="hidden" name="_action" value="update_limit"><input type="hidden" name="customer_id" value="<?= $c['id'] ?>"><label style="font-size:.78rem;font-weight:600;color:#475569;display:flex;flex-direction:column;gap:3px">Set Absolute Limit (<?= currency_sym() ?>)<input type="number" step="0.01" name="new_limit" value="<?= $limit ?>" min="0" required style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:.83rem;width:120px"></label><button type="submit" class="cc-btn cc-btn-primary" style="padding:5px 10px;font-size:.75rem;border-radius:6px"><i class="fas fa-save"></i> Set</button></form>
                    </div>
                </div>
                <div class="cr-detail-grid" style="display:grid;grid-template-columns:1fr 240px;gap:16px">
                    <div>
                        <div class="cr-detail-grid">
                            <div class="cr-detail-item"><div class="cr-label">Credit Limit</div><div class="cr-val"><?= fmt($limit) ?></div></div>
                            <div class="cr-detail-item"><div class="cr-label">Payment Terms</div><div class="cr-val"><?= (int)($c['credit_terms_days'] ?? 30) ?> days</div></div>
                            <div class="cr-detail-item"><div class="cr-label">Credit Used</div><div class="cr-val" style="color:#d97706"><?= fmt($used) ?></div></div>
                            <div class="cr-detail-item"><div class="cr-label">Available Credit</div><div class="cr-val" style="color:<?= $available>0?'#059669':'#dc2626' ?>"><?= fmt($available) ?></div></div>
                            <div class="cr-detail-item"><div class="cr-label">Outstanding Balance</div><div class="cr-val" style="color:<?= $os>0?'#dc2626':'#059669' ?>"><?= fmt($os) ?></div></div>
                            <div class="cr-detail-item"><div class="cr-label">Overdue Amount</div><div class="cr-val" style="color:<?= $ov>0?'#dc2626':'#059669' ?>"><?= $ov>0?fmt($ov):'None' ?></div></div>
                            <div class="cr-detail-item"><div class="cr-label">Last Payment Date</div><div class="cr-val"><?= $c['last_payment_date'] ? htmlspecialchars($c['last_payment_date']) : '—' ?></div></div>
                            <div class="cr-detail-item"><div class="cr-label">Next Due Date</div><div class="cr-val" style="color:<?= $c['next_due_date'] && new DateTime($c['next_due_date']) < new DateTime() ? '#dc2626' : '#475569' ?>"><?= $c['next_due_date'] ? htmlspecialchars($c['next_due_date']) : '—' ?></div></div>
                            <div class="cr-detail-item"><div class="cr-label">Credit Utilization</div><div class="cr-val"><?= $limit > 0 ? $utilPct . '%' : '—' ?></div></div>
                            <div class="cr-detail-item"><div class="cr-label">Remaining Credit</div><div class="cr-val" style="color:<?= $available > 0 ? '#059669' : '#dc2626' ?>"><?= fmt($available) ?></div></div>
                            <div class="cr-detail-item"><div class="cr-label">Over Limit Amount</div><div class="cr-val" style="color:#dc2626"><?= $used > $limit ? fmt($used - $limit) : '—' ?></div></div>
                            <div class="cr-detail-item"><div class="cr-label">Credit Score</div><div class="cr-val" style="color:<?= $scoreColor ?>"><?= $score ?> / 800</div></div>
                                    <div class="cr-detail-item"><div class="cr-label">Risk Level</div><div class="cr-val"><span class="cr-badge <?= $riskLevel==='low'?'cr-badge-green':($riskLevel==='moderate'?'cr-badge-orange':($riskLevel==='high'?'cr-badge-red':'cr-badge-darkred')) ?>"><?= ucfirst($riskLevel) ?></span></div></div>
                                    <div class="cr-detail-item"><div class="cr-label">Approved By</div><div class="cr-val"><?= $c['approved_by_name'] ? htmlspecialchars($c['approved_by_name']) : '—' ?></div></div>
                                    <div class="cr-detail-item"><div class="cr-label">Approval Date</div><div class="cr-val"><?= $c['credit_approved_at'] ? date('M j, Y', strtotime($c['credit_approved_at'])) : '—' ?></div></div>
                            <div class="cr-detail-item"><div class="cr-label">Status</div><div class="cr-val"><?php
                                $effSt = $c['_effective_status'];
                                if ($effSt === 'approved'): ?><span class="cr-badge cr-badge-green">Approved</span><?php
                                elseif ($effSt === 'over_limit'): ?><span class="cr-badge cr-badge-red" style="background:#991b1b;color:#fff"><i class="fas fa-exclamation-triangle"></i> Over Limit</span><?php
                                elseif ($effSt === 'suspended'): ?><span class="cr-badge cr-badge-red">Suspended</span><?php
                                elseif ($effSt === 'rejected'): ?><span class="cr-badge cr-badge-darkred">Rejected</span><?php
                                else: ?><span class="cr-badge cr-badge-orange">Pending</span><?php
                                endif; ?></div></div>
                        </div>
                        <div style="margin-bottom:14px">
                            <div style="font-size:.76rem;font-weight:600;color:#475569;margin-bottom:5px">Credit Utilization</div>
                            <div class="cr-progress-wrap" style="height:10px"><div class="cr-progress-bar" style="width:<?= min($utilPct,100) ?>%;background:<?= $utilPct >= 100 ? '#dc2626' : ($utilPct > 80 ? '#d97706' : '#059669') ?>"></div></div>
                            <div style="display:flex;justify-content:space-between;font-size:.7rem;color:#94a3b8;margin-top:3px"><span><?= fmt($used) ?> used</span><span><?= $utilPct ?>%</span><span><?= fmt($limit) ?> limit</span></div>
                        </div>
                        <div style="margin-bottom:14px">
                            <div style="font-size:.76rem;font-weight:600;color:#475569;margin-bottom:5px">Payment Progress</div>
                            <div class="cr-progress-wrap" style="height:10px"><div class="cr-progress-bar" style="width:<?= min($payProgress,100) ?>%;background:<?= $payProgress >= 100 ? '#059669' : ($payProgress >= 50 ? '#d97706' : '#dc2626') ?>"></div></div>
                            <div style="display:flex;justify-content:space-between;font-size:.7rem;color:#94a3b8;margin-top:3px"><span><?= fmt($totalPaid) ?> paid</span><span><?= $payProgress ?>%</span><span><?= fmt($totalCreditSalesCust) ?> total</span></div>
                        </div>
                        <?php if (!empty($openInvoices)): ?>
                        <div class="cr-invoice-list">
                            <div style="font-size:.8rem;font-weight:700;color:#334155;margin-bottom:6px"><i class="fas fa-file-invoice"></i> Open Invoices (<?= count($openInvoices) ?>)</div>
                            <div style="overflow-x:auto">
                            <table><thead><tr><th>Invoice #</th><th>Total</th><th>Outstanding</th><th>Due Date</th><th>Actions</th></tr></thead>
                                <tbody><?php foreach ($openInvoices as $inv):
                                    $invDue = $inv['credit_due_date'] ? new DateTime($inv['credit_due_date']) : null;
                                    $invOverdue = $invDue && $invDue < new DateTime();
                                ?><tr>
                                    <td style="font-weight:600">#<?= $inv['id'] ?></td>
                                    <td><?= fmt((float)$inv['total_amount']) ?></td>
                                    <td style="font-weight:700;color:#dc2626"><?= fmt((float)$inv['outstanding_balance']) ?></td>
                                    <td><span style="color:<?= $invOverdue?'#dc2626':'#475569' ?>;font-weight:<?= $invOverdue?'700':'400' ?>"><?= htmlspecialchars($inv['credit_due_date'] ?? '—') ?></span></td>
                                    <td onclick="event.stopPropagation()" style="white-space:nowrap">
                                        <form method="post" style="display:inline-flex;gap:4px;align-items:center"><?= csrf_field() ?><input type="hidden" name="_action" value="edit_due_date"><input type="hidden" name="customer_id" value="<?= $c['id'] ?>"><input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>"><input type="date" name="new_due_date" value="<?= htmlspecialchars($inv['credit_due_date']??date('Y-m-d')) ?>" style="padding:3px 6px;border:1px solid #cbd5e1;border-radius:4px;font-size:.75rem;width:110px"><button type="submit" class="cc-btn cc-btn-primary" style="padding:3px 8px;font-size:.7rem;border-radius:4px"><i class="fas fa-save"></i></button></form>
                                        <form method="post" style="display:inline;margin-left:4px" onsubmit="return confirm('Write off <?= fmt((float)$inv['outstanding_balance']) ?>?')"><?= csrf_field() ?><input type="hidden" name="_action" value="write_off"><input type="hidden" name="customer_id" value="<?= $c['id'] ?>"><input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>"><input type="hidden" name="write_off_amount" value="<?= (float)$inv['outstanding_balance'] ?>"><input type="hidden" name="write_off_reason" value="Credit write-off"><button type="submit" class="cc-btn cc-btn-primary" style="padding:3px 8px;font-size:.7rem;border-radius:4px;background:#dc2626"><i class="fas fa-times-circle"></i></button></form>
                                    </td>
                                </tr><?php endforeach; ?></tbody>
                            </table>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div style="text-align:center">
                        <div style="font-size:.76rem;font-weight:600;color:#475569;margin-bottom:8px">Credit Score</div>
                        <div class="cr-gauge"><div class="cr-gauge-circle" style="background:<?= $scoreColor ?>"><?= $score ?></div><div class="cr-gauge-label">out of 800</div></div>
                        <div style="margin-top:12px;padding:10px;background:#f8fafc;border-radius:8px;text-align:left;font-size:.78rem">
                            <div style="margin-bottom:6px"><strong>Total Paid:</strong> <?= fmt($totalPaid) ?></div>
                            <div style="margin-bottom:6px"><strong>Total Sales:</strong> <?= fmt($totalCreditSalesCust) ?></div>
                            <div><strong>Terms:</strong> <?= (int)($c['credit_terms_days'] ?? 30) ?> days</div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Outstanding Invoices -->
<div id="cm-tab-outstanding" class="cr-panel <?= $cmTab === 'outstanding' ? 'active' : '' ?>">
    <div class="cr-card" style="border-top-left-radius:0;border-top-right-radius:0">
        <div class="cr-card-header"><i class="fas fa-file-invoice" style="color:#dc2626"></i> Outstanding Invoices <span class="cr-badge cr-badge-red" style="margin-left:8px"><?= count($outstandingInvoices) ?></span></div>
        <?php if (empty($outstandingInvoices)): ?>
            <div class="cr-empty"><i class="fas fa-check-circle" style="color:#059669"></i>No outstanding invoices</div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="cr-table">
            <thead><tr><th>Invoice #</th><th>Customer</th><th>Total</th><th>Paid</th><th>Outstanding</th><th>Due Date</th><th>Days Overdue</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($outstandingInvoices as $inv):
                    $invOs = (float) $inv['outstanding_balance'];
                    $invTotal = (float) $inv['total_amount'];
                    $invPaid = (float) $inv['amount_paid'];
                    $invDueDate = $inv['credit_due_date'] ? new DateTime($inv['credit_due_date']) : null;
                    $invNow = new DateTime();
                    $invDaysOver = $invDueDate && $invDueDate < $invNow ? (int) $invNow->diff($invDueDate)->days : 0;
                    $invIsOverdue = $invDaysOver > 0;
                ?>
                    <tr style="background:<?= $invIsOverdue ? '#fef2f2' : '' ?>">
                        <td style="font-weight:600">#<?= $inv['id'] ?></td>
                        <td style="font-weight:600;color:#0f172a"><?= htmlspecialchars($inv['customer_name']) ?></td>
                        <td><?= fmt($invTotal) ?></td>
                        <td style="color:#059669"><?= fmt($invPaid) ?></td>
                        <td style="font-weight:700;color:#dc2626"><?= fmt($invOs) ?></td>
                        <td><span style="color:<?= $invIsOverdue?'#dc2626':'#475569' ?>"><?= htmlspecialchars($inv['credit_due_date'] ?? '—') ?></span></td>
                        <td><?php if ($invIsOverdue): ?><span class="cr-badge cr-badge-red"><?= $invDaysOver ?> days</span><?php else: ?><span style="color:#94a3b8">—</span><?php endif; ?></td>
                        <td onclick="event.stopPropagation()" style="white-space:nowrap">
                            <form method="post" style="display:inline-flex;gap:4px;align-items:center"><?= csrf_field() ?><input type="hidden" name="_action" value="edit_due_date"><input type="hidden" name="customer_id" value="<?= $inv['customer_id'] ?>"><input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>"><input type="date" name="new_due_date" value="<?= htmlspecialchars($inv['credit_due_date']??date('Y-m-d')) ?>" style="padding:3px 6px;border:1px solid #cbd5e1;border-radius:4px;font-size:.75rem;width:110px"><button type="submit" class="cc-btn cc-btn-primary" style="padding:3px 8px;font-size:.7rem;border-radius:4px"><i class="fas fa-save"></i></button></form>
                            <form method="post" style="display:inline;margin-left:4px" onsubmit="return confirm('Write off <?= fmt($invOs) ?>?')"><?= csrf_field() ?><input type="hidden" name="_action" value="write_off"><input type="hidden" name="customer_id" value="<?= $inv['customer_id'] ?>"><input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>"><input type="hidden" name="write_off_amount" value="<?= $invOs ?>"><input type="hidden" name="write_off_reason" value="Credit write-off"><button type="submit" class="cc-btn cc-btn-primary" style="padding:3px 8px;font-size:.7rem;border-radius:4px;background:#dc2626"><i class="fas fa-times-circle"></i></button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Record Payment -->
<div id="cm-tab-payments" class="cr-panel <?= $cmTab === 'payments' ? 'active' : '' ?>">
    <div class="cr-card" style="border-top-left-radius:0;border-top-right-radius:0">
        <div class="cr-card-header"><i class="fas fa-money-check-alt" style="color:#059669"></i> Record Credit Payment</div>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="_action" value="record_payment">
            <div class="cr-pay-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                <div style="display:flex;flex-direction:column;gap:12px">
                    <label style="font-size:.82rem;font-weight:600;color:#475569">Customer
                        <select name="customer_id" required id="cc-pay-customer" style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;margin-top:4px">
                            <option value="">Select customer...</option>
                            <?php foreach ($allCustomers as $c): ?>
                                <option value="<?= $c['id'] ?>" data-limit="<?= (float)$c['credit_limit'] ?>" data-used="<?= (float)$c['credit_used'] ?>" data-outstanding="<?= (float)$c['outstanding'] ?>"><?= htmlspecialchars($c['name']) ?> (Outstanding: <?= fmt((float)$c['outstanding']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div id="cc-pay-summary" style="display:none;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;font-size:.82rem">
                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px">
                            <div><span style="color:#64748b;font-size:.72rem;display:block">Limit</span><strong id="cc-pay-limit">—</strong></div>
                            <div><span style="color:#64748b;font-size:.72rem;display:block">Used</span><strong id="cc-pay-used" style="color:#d97706">—</strong></div>
                            <div><span style="color:#64748b;font-size:.72rem;display:block">Outstanding</span><strong id="cc-pay-os" style="color:#dc2626">—</strong></div>
                        </div>
                    </div>
                </div>
                <div style="display:flex;flex-direction:column;gap:12px">
                    <label style="font-size:.82rem;font-weight:600;color:#475569">Amount (<?= currency_sym() ?>)<input type="number" step="0.01" name="amount" min="0.01" required style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;margin-top:4px"></label>
                    <div class="cr-pay-inner" style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                        <label style="font-size:.82rem;font-weight:600;color:#475569">Date<input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;margin-top:4px"></label>
                        <label style="font-size:.82rem;font-weight:600;color:#475569">Method<select name="payment_method" required style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;margin-top:4px"><option value="">Select...</option><option value="cash">Cash</option><option value="bank">Bank Transfer</option><option value="cbe_birr">CBE Birr</option><option value="telebirr">TeleBirr</option><option value="cheque">Cheque</option></select></label>
                    </div>
                    <label style="font-size:.82rem;font-weight:600;color:#475569">Reference No.<input type="text" name="reference_no" placeholder="Receipt or reference number" style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;margin-top:4px"></label>
                    <label style="font-size:.82rem;font-weight:600;color:#475569">Notes<textarea name="notes" rows="2" style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;margin-top:4px;resize:vertical"></textarea></label>
                    <button type="submit" class="cc-btn cc-btn-primary" style="padding:10px 24px;font-size:.9rem;align-self:flex-start;background:#059669"><i class="fas fa-check-circle"></i> Record Payment</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Payment History -->
<div id="cm-tab-history" class="cr-panel <?= $cmTab === 'history' ? 'active' : '' ?>">
    <div class="cr-card" style="border-top-left-radius:0;border-top-right-radius:0">
        <div class="cr-card-header"><i class="fas fa-history" style="color:#7c3aed"></i> Credit Payment History</div>
        <?php if (empty($paymentsList)): ?>
            <div class="cr-empty"><i class="fas fa-inbox"></i>No credit payments recorded</div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="cr-table">
            <thead><tr><th>Date</th><th>Customer</th><th>Amount</th><th>Method</th><th>Reference</th><th>Recorded By</th><th>Notes</th></tr></thead>
            <tbody>
                <?php foreach ($paymentsList as $p): ?>
                    <tr>
                        <td style="white-space:nowrap"><?= htmlspecialchars($p['payment_date']) ?></td>
                        <td style="font-weight:600"><?= htmlspecialchars($p['customer_name']) ?></td>
                        <td style="font-weight:700;color:#059669">+<?= fmt((float)$p['amount']) ?></td>
                        <td><span class="cr-badge cr-badge-blue"><?= htmlspecialchars(ucfirst(str_replace('_',' ',$p['payment_method']??'—'))) ?></span></td>
                        <td style="font-size:.8rem;color:#64748b"><?= htmlspecialchars($p['reference_no'] ?? '—') ?></td>
                        <td style="font-size:.8rem;color:#64748b"><?= htmlspecialchars($p['recorded_by'] ?? '—') ?></td>
                        <td style="font-size:.78rem;color:#94a3b8;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($p['notes'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div style="margin-top:12px;text-align:right;font-size:.78rem;color:#94a3b8">Showing <?= count($paymentsList) ?> records</div>
        <?php endif; ?>
    </div>
</div>

<!-- Collection Schedule -->
<div id="cm-tab-schedule" class="cr-panel <?= $cmTab === 'schedule' ? 'active' : '' ?>">
    <div class="cr-card" style="border-top-left-radius:0;border-top-right-radius:0">
        <div class="cr-card-header"><i class="fas fa-calendar-alt" style="color:#2563eb"></i> Collection Schedule — Upcoming Payments</div>
        <?php if (empty($upcomingCollections)): ?>
            <div class="cr-empty"><i class="fas fa-check-circle" style="color:#059669"></i>No upcoming collections scheduled</div>
        <?php else: ?>
        <div class="cr-schedule-grid">
            <?php $scheduleLabels = ['this_week'=>'This Week','next_week'=>'Next Week','this_month'=>'This Month','next_month'=>'Next Month','future'=>'Future']; ?>
            <?php $scheduleColors = ['this_week'=>'#059669','next_week'=>'#2563eb','this_month'=>'#d97706','next_month'=>'#7c3aed','future'=>'#64748b']; ?>
            <?php foreach (['this_week','next_week','this_month','next_month','future'] as $period): ?>
                <div class="cr-schedule-card" style="border-color:<?= $scheduleColors[$period] ?>40">
                    <h4><span style="color:<?= $scheduleColors[$period] ?>"><i class="fas fa-circle"></i></span> <?= $scheduleLabels[$period] ?> <span class="cr-badge cr-badge-gray" style="margin-left:auto"><?= count($collectionSchedule[$period]) ?></span></h4>
                    <?php if (empty($collectionSchedule[$period])): ?>
                        <div style="font-size:.78rem;color:#94a3b8;padding:6px 0">No invoices due</div>
                    <?php else: ?>
                        <?php foreach ($collectionSchedule[$period] as $sc): ?>
                            <div class="cr-schedule-item">
                                <div><strong style="color:#0f172a">#<?= $sc['invoice_id'] ?></strong> — <?= htmlspecialchars($sc['customer_name']) ?></div>
                                <div style="text-align:right">
                                    <div style="font-weight:700;color:#dc2626"><?= fmt((float)$sc['outstanding_balance']) ?></div>
                                    <div style="font-size:.7rem;color:#64748b">Due: <?= htmlspecialchars($sc['credit_due_date']) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Overdue Customers -->
<div id="cm-tab-overdue" class="cr-panel <?= $cmTab === 'overdue' ? 'active' : '' ?>">
    <div class="cr-card" style="border-top-left-radius:0;border-top-right-radius:0">
        <div class="cr-card-header"><i class="fas fa-exclamation-triangle" style="color:#dc2626"></i> Overdue Customers <span class="cr-badge cr-badge-red" style="margin-left:8px"><?= count($overdueCustomers) ?></span></div>
        <?php if (empty($overdueCustomers)): ?>
            <div class="cr-empty"><i class="fas fa-check-circle" style="color:#059669"></i>No overdue customers</div>
        <?php else: ?>
            <?php foreach ($overdueCustomers as $oc): ?>
                <div style="background:#fff;border:1px solid #fecaca;border-radius:10px;padding:16px;margin-bottom:12px">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;margin-bottom:10px">
                        <div>
                            <strong style="font-size:.95rem;color:#0f172a"><?= htmlspecialchars($oc['name']) ?></strong>
                            <span class="cr-badge cr-badge-red" style="margin-left:8px"><i class="fas fa-exclamation"></i> <?= fmt($oc['total_overdue']) ?> overdue</span>
                            <span class="cr-badge cr-badge-<?= $oc['credit_approval_status']==='approved'?'green':($oc['credit_approval_status']==='suspended'?'red':'orange') ?>" style="margin-left:4px"><?= ucfirst($oc['credit_approval_status']) ?></span>
                        </div>
                        <div style="display:flex;gap:6px"><span style="font-size:.78rem;color:#64748b">Limit: <?= fmt($oc['credit_limit']) ?></span><span style="font-size:.78rem;color:#64748b">Score: <?= $oc['credit_score'] ?></span></div>
                    </div>
                    <div style="overflow-x:auto">
                    <table class="cr-table" style="font-size:.8rem">
                        <thead><tr><th>Invoice #</th><th>Total</th><th>Outstanding</th><th>Due Date</th><th>Days Overdue</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($oc['invoices'] as $inv):
                                $invDue = new DateTime($inv['credit_due_date']);
                                $invDays = (int) (new DateTime())->diff($invDue)->days;
                            ?>
                                <tr>
                                    <td style="font-weight:600">#<?= $inv['id'] ?></td>
                                    <td><?= fmt((float)$inv['total_amount']) ?></td>
                                    <td style="font-weight:700;color:#dc2626"><?= fmt((float)$inv['outstanding_balance']) ?></td>
                                    <td style="color:#dc2626"><?= htmlspecialchars($inv['credit_due_date']) ?></td>
                                    <td><span class="cr-badge cr-badge-red"><?= $invDays ?> days</span></td>
                                    <td onclick="event.stopPropagation()" style="white-space:nowrap">
                                        <form method="post" style="display:inline-flex;gap:4px;align-items:center"><?= csrf_field() ?><input type="hidden" name="_action" value="edit_due_date"><input type="hidden" name="customer_id" value="<?= $oc['id'] ?>"><input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>"><input type="date" name="new_due_date" value="<?= htmlspecialchars($inv['credit_due_date']) ?>" style="padding:3px 6px;border:1px solid #cbd5e1;border-radius:4px;font-size:.75rem;width:110px"><button type="submit" class="cc-btn cc-btn-primary" style="padding:3px 8px;font-size:.7rem;border-radius:4px"><i class="fas fa-save"></i></button></form>
                                        <form method="post" style="display:inline" onsubmit="return confirm('Send reminder?')"><?= csrf_field() ?><input type="hidden" name="_action" value="send_reminder"><input type="hidden" name="customer_id" value="<?= $oc['id'] ?>"><button type="submit" class="cc-btn cc-btn-primary" style="padding:3px 8px;font-size:.7rem;border-radius:4px;background:#d97706"><i class="fas fa-bell"></i></button></form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Credit Approvals -->
<div id="cm-tab-approvals" class="cr-panel <?= $cmTab === 'approvals' ? 'active' : '' ?>">
    <div class="cr-card" style="border-top-left-radius:0;border-top-right-radius:0">
        <div class="cr-card-header"><i class="fas fa-check-double" style="color:#7c3aed"></i> Credit Approval Requests <?= $pendingApprovalCount > 0 ? '<span class="cr-badge cr-badge-orange">'.$pendingApprovalCount.' pending</span>' : '' ?></div>
        <?php if (empty($pendingApprovals)): ?>
            <div class="cr-empty"><i class="fas fa-check-circle" style="color:#059669"></i>No pending approval requests</div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="cr-table">
            <thead><tr><th>Customer</th><th>Type</th><th>Requested Limit</th><th>Current Used</th><th>Score</th><th>Terms</th><th>Registered</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($pendingApprovals as $pa):
                    $paLimit = (float) $pa['credit_limit']; $paUsed = (float) $pa['credit_used']; $paScore = (int) $pa['credit_score'];
                ?>
                    <tr>
                        <td style="font-weight:600;color:#0f172a"><?= htmlspecialchars($pa['name']) ?></td>
                        <td><?= htmlspecialchars($pa['customer_type'] ?? '—') ?></td>
                        <td style="font-weight:600"><?= fmt($paLimit) ?></td>
                        <td style="color:#d97706"><?= fmt($paUsed) ?></td>
                        <td><span style="font-weight:700;color:<?= $paScore>=650?'#059669':($paScore>=500?'#d97706':'#ea580c') ?>"><?= $paScore ?></span></td>
                        <td><?= (int) $pa['credit_terms_days'] ?> days</td>
                        <td style="font-size:.8rem;color:#64748b"><?= htmlspecialchars($pa['created_at'] ?? '—') ?></td>
                        <td onclick="event.stopPropagation()" style="white-space:nowrap">
                            <form method="post" style="display:inline" onsubmit="return confirm('Approve credit for <?= htmlspecialchars($pa['name']) ?>?')"><?= csrf_field() ?><input type="hidden" name="_action" value="approve_credit"><input type="hidden" name="customer_id" value="<?= $pa['id'] ?>"><button type="submit" class="cc-btn cc-btn-primary" style="padding:4px 10px;font-size:.75rem;border-radius:6px;background:#059669"><i class="fas fa-check"></i> Approve</button></form>
                            <form method="post" style="display:inline;margin-left:4px" onsubmit="return confirm('Reject credit for <?= htmlspecialchars($pa['name']) ?>?')"><?= csrf_field() ?><input type="hidden" name="_action" value="reject_credit"><input type="hidden" name="customer_id" value="<?= $pa['id'] ?>"><button type="submit" class="cc-btn cc-btn-primary" style="padding:4px 10px;font-size:.75rem;border-radius:6px;background:#dc2626"><i class="fas fa-times"></i> Reject</button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Credit Audit Log -->
<div id="cm-tab-audit" class="cr-panel <?= $cmTab === 'audit' ? 'active' : '' ?>">
    <div class="cr-card" style="border-top-left-radius:0;border-top-right-radius:0">
        <div class="cr-card-header"><i class="fas fa-clipboard-list" style="color:#d97706"></i> Credit Audit Trail</div>
        <?php if (empty($auditLog)): ?>
            <div class="cr-empty"><i class="fas fa-clipboard"></i>No audit records found</div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="cr-table">
            <thead><tr><th>Date</th><th>Customer</th><th>User</th><th>Action</th><th>Old Value</th><th>New Value</th><th>IP</th></tr></thead>
            <tbody>
                <?php foreach ($auditLog as $log): ?>
                    <tr>
                        <td style="white-space:nowrap;font-size:.8rem"><?= htmlspecialchars($log['created_at']) ?></td>
                        <td style="font-weight:600"><?= htmlspecialchars($log['customer_name'] ?? '—') ?></td>
                        <td style="font-size:.8rem"><?= htmlspecialchars($log['user_name'] ?? '—') ?></td>
                        <td><span class="cr-badge cr-badge-blue"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $log['action']))) ?></span></td>
                        <td style="font-size:.8rem;color:#64748b;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($log['old_value'] ?? '—') ?></td>
                        <td style="font-size:.8rem;color:#64748b;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($log['new_value'] ?? '—') ?></td>
                        <td style="font-size:.78rem;color:#94a3b8"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

</div>

<div class="cr-modal-overlay" id="cc-add-modal">
    <div class="cr-modal">
        <div class="cr-modal-head">
            <h3>Add Credit Customer</h3>
            <button type="button" class="cr-modal-close" onclick="ccCloseAddModal()">&times;</button>
        </div>
        <div class="cr-modal-sub">Select an existing customer to create a credit profile. New profiles start as Pending and must be approved in the Credit Approvals tab.</div>
        <form method="post" class="cr-modal-form" onsubmit="return ccValidateAddForm()">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="add_credit_customer">
            <label>Customer
                <select id="cc-add-customer" name="customer_id" required>
                    <option value="">— Select customer —</option>
                    <?php foreach ($nonCreditCustomers as $nc): ?>
                    <option value="<?= $nc['id'] ?>"><?= htmlspecialchars($nc['name']) ?> (<?= htmlspecialchars($nc['customer_type'] ?? '') ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Credit Limit (<?= currency_sym() ?>)
                <input type="number" id="cc-add-limit" name="credit_limit" step="0.01" min="0.01" placeholder="e.g. 10000" required>
            </label>
            <label>Payment Terms (days)
                <input type="number" id="cc-add-terms" name="credit_terms_days" min="1" max="365" value="<?= (int) SettingsService::defaultCreditDays() ?>" required>
            </label>
            <div class="cr-modal-preview" id="cc-add-preview" style="display:none">
                <div style="display:flex;justify-content:space-between;font-weight:600;color:#0f172a;margin-bottom:6px"><span id="cc-pv-name">—</span><span class="cr-badge cr-badge-orange">Pending</span></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 16px">
                    <div>Credit Limit: <strong id="cc-pv-limit">0.00</strong></div>
                    <div>Available Credit: <strong id="cc-pv-available">0.00</strong></div>
                    <div>Current Outstanding: <strong id="cc-pv-outstanding">0.00</strong></div>
                    <div>Overdue: <strong id="cc-pv-overdue">0.00</strong></div>
                </div>
            </div>
            <div class="cr-modal-actions">
                <button type="button" class="cc-btn" onclick="ccCloseAddModal()" style="background:transparent;border:1px solid #cbd5e1;color:#475569">Cancel</button>
                <button type="submit" class="cc-btn cc-btn-primary"><i class="fas fa-user-plus"></i> Create Credit Profile</button>
            </div>
        </form>
    </div>
</div>
</section>

<script>
function ccGoTab(e,tab) {
    e.preventDefault();
    var url = new URL(window.location.href);
    url.searchParams.set('page','credit_control');
    url.searchParams.set('tab',tab);
    window.location.href = url.toString();
}
function ccGoCmTab(e,tab) {
    e.preventDefault();
    var url = new URL(window.location.href);
    url.searchParams.set('page','credit_control');
    url.searchParams.set('tab','management');
    url.searchParams.set('cm_tab',tab);
    window.location.href = url.toString();
}
function ccPillFilter(e,status) {
    e.preventDefault();
    var url = new URL(window.location.href);
    url.searchParams.set('page','credit_control');
    url.searchParams.set('tab','management');
    url.searchParams.set('cm_tab','profiles');
    url.searchParams.set('status',status);
    window.location.href = url.toString();
}
function ccToggleDetail(id) {
    var el = document.getElementById('cc-detail-' + id);
    if (el) {
        var isOpen = el.classList.contains('open');
        document.querySelectorAll('.cr-detail-panel').forEach(function(p) { p.classList.remove('open'); });
        if (!isOpen) el.classList.add('open');
    }
}
function ccToggleEditLimit(id) {
    var el = document.getElementById('cc-edit-' + id);
    if (el) el.classList.toggle('open');
}
function ccOpenAddModal() {
    document.getElementById('cc-add-modal').classList.add('open');
}
function ccCloseAddModal() {
    document.getElementById('cc-add-modal').classList.remove('open');
}
function ccValidateAddForm() {
    var cid = document.getElementById('cc-add-customer');
    if (!cid || !cid.value) { alert('Please select an existing customer.'); return false; }
    var lim = parseFloat(document.getElementById('cc-add-limit').value);
    var terms = parseInt(document.getElementById('cc-add-terms').value, 10);
    if (!(lim > 0)) { alert('Please enter a positive credit limit.'); return false; }
    if (!(terms >= 1 && terms <= 365)) { alert('Payment terms must be between 1 and 365 days.'); return false; }
    return true;
}
document.addEventListener('DOMContentLoaded', function() {
    var sel = document.getElementById('cc-pay-customer');
    if (sel) {
        sel.addEventListener('change', function() {
            var opt = sel.options[sel.selectedIndex];
            var sum = document.getElementById('cc-pay-summary');
            if (sel.value) {
                document.getElementById('cc-pay-limit').textContent = opt.getAttribute('data-limit');
                document.getElementById('cc-pay-used').textContent = opt.getAttribute('data-used');
                document.getElementById('cc-pay-os').textContent = opt.getAttribute('data-outstanding');
                sum.style.display = 'block';
            } else {
                sum.style.display = 'none';
            }
        });
    }
    var addCust = document.getElementById('cc-add-customer');
    if (addCust) {
        addCust.addEventListener('change', function() {
            var opt = addCust.options[addCust.selectedIndex];
            var preview = document.getElementById('cc-add-preview');
            if (addCust.value) {
                document.getElementById('cc-pv-name').textContent = opt.textContent.replace(/\s*\(.*\)$/, '');
                preview.style.display = 'block';
            } else {
                preview.style.display = 'none';
            }
        });
        var addLimit = document.getElementById('cc-add-limit');
        if (addLimit) addLimit.addEventListener('input', function() {
            var lim = parseFloat(addLimit.value);
            if (isNaN(lim) || lim <= 0) return;
            document.getElementById('cc-pv-limit').textContent = lim.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('cc-pv-available').textContent = document.getElementById('cc-pv-limit').textContent;
            document.getElementById('cc-pv-outstanding').textContent = '0.00';
            document.getElementById('cc-pv-overdue').textContent = '0.00';
        });
    }
});
</script>