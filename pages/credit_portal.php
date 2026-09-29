<?php
require_once __DIR__ . '/../includes/workflow_helpers.php';
require_once __DIR__ . '/../includes/credit_helpers.php';
requireCustomer();
$pdo = getPDO();
$cid = customerAccountCustomerId();
if ($cid === null) { echo '<div class="panel"><p>Your account is not linked to a customer profile.</p></div>'; return; }

$profile = creditGetProfile($pdo, $cid);
if (empty($profile)) { echo '<div class="panel"><p>Customer profile not found.</p></div>'; return; }

$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'pay_now') {
    require_csrf();
    $amount = (float)($_POST['amount'] ?? 0);
    $method = $_POST['payment_method'] ?? 'cash';
    $ref = trim($_POST['reference_no'] ?? '');
    $payDate = $_POST['payment_date'] ?? date('Y-m-d');

    if ($amount <= 0) {
        $messages[] = ['type' => 'error', 'text' => 'Please enter a valid amount.'];
    } elseif ($ref === '') {
        $messages[] = ['type' => 'error', 'text' => 'A payment reference number is required.'];
    } elseif ($amount > $profile['outstanding_balance']) {
        $messages[] = ['type' => 'error', 'text' => 'Amount exceeds outstanding balance of ' . fmt($profile['outstanding_balance']) . '.'];
    } else {
        $payErr = '';
        $ok = creditRecordPayment($pdo, $cid, $amount, $payDate, $method, $ref, 'Customer self-payment', (int)($_SESSION['user']['id'] ?? 0), $payErr);
        $messages[] = $ok ? ['type' => 'success', 'text' => 'Payment of ' . fmt($amount) . ' recorded successfully!'] : ['type' => 'error', 'text' => $payErr !== '' ? $payErr : 'Failed to record payment.'];
        $profile = creditGetProfile($pdo, $cid);
    }
}

$myInvoices = $pdo->prepare("SELECT * FROM invoices WHERE customer_id = :cid ORDER BY generated_at DESC");
$myInvoices->execute(['cid' => $cid]);
$myInvoices = $myInvoices->fetchAll();

$myPayments = $pdo->prepare("SELECT * FROM customer_credit_payments WHERE customer_id = :cid ORDER BY payment_date DESC LIMIT 20");
$myPayments->execute(['cid' => $cid]);
$myPayments = $myPayments->fetchAll();

$aging = creditGetAging($pdo, $cid);
$tab = $_GET['tab'] ?? 'overview';
?>
<style>
.cp{max-width:1200px;margin:0 auto}
.cp-head{font-size:1.3rem;font-weight:700;color:#1e293b;margin:0 0 20px;display:flex;align-items:center;gap:10px}
.cp-head i{color:#2563eb}
.cp-cards{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.cp-card{background:#fff;border-radius:12px;padding:20px;border:1px solid #e2e8f0;display:flex;align-items:center;gap:14px;transition:all 0.2s}
.cp-card:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(0,0,0,0.06)}
.cp-card-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.cp-card-val{font-size:1.2rem;font-weight:800;color:#1e293b}
.cp-card-lbl{font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;margin-top:2px}
.cp-progress{margin-top:8px;height:8px;background:#f1f5f9;border-radius:8px;overflow:hidden}
.cp-progress-fill{height:100%;border-radius:8px;transition:width 0.5s}
.cp-tabs{display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:24px;overflow-x:auto}
.cp-tab{padding:10px 22px;font-size:0.88rem;font-weight:600;color:#64748b;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;text-decoration:none;display:flex;align-items:center;gap:6px;transition:all 0.15s;white-space:nowrap}
.cp-tab:hover{color:#1e293b}
.cp-tab.active{color:#2563eb;border-bottom-color:#2563eb}
.cp-panel{background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:24px}
.cp-panel-head{padding:18px 22px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between}
.cp-panel-title{font-size:0.95rem;font-weight:700;color:#1e293b;display:flex;align-items:center;gap:8px}
.cp-panel-title i{color:#2563eb}
.cp-panel-body{padding:20px 22px}
.cp-table{width:100%;border-collapse:collapse}
.cp-table th{padding:10px 14px;text-align:left;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color:#64748b;background:#f8fafc;border-bottom:1px solid #e2e8f0}
.cp-table td{padding:10px 14px;font-size:0.84rem;color:#1e293b;border-bottom:1px solid #f1f5f9}
.cp-table tr:last-child td{border-bottom:none}
.cp-table tr:hover td{background:#f8fafc}
.cp-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:0.7rem;font-weight:600}
.cp-badge.paid{background:#f0fdf4;color:#15803d}
.cp-badge.unpaid{background:#fef2f2;color:#dc2626}
.cp-badge.partial{background:#fffbeb;color:#b45309}
.cp-aging-row{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid #f1f5f9}
.cp-aging-row:last-child{border-bottom:none}
.cp-aging-label{width:100px;font-size:0.82rem;font-weight:600;color:#475569}
.cp-aging-bar{flex:1;height:24px;background:#f1f5f9;border-radius:8px;overflow:hidden}
.cp-aging-fill{height:100%;border-radius:8px;display:flex;align-items:center;padding:0 10px;font-size:0.7rem;font-weight:700;color:#fff;min-width:fit-content}
.cp-aging-val{width:120px;text-align:right;font-size:0.88rem;font-weight:700;color:#1e293b}
.cp-form{max-width:500px}
.cp-fg{margin-bottom:14px}
.cp-fg label{display:block;font-size:0.8rem;font-weight:600;color:#475569;margin-bottom:4px}
.cp-fg select,.cp-fg input,.cp-fg textarea{width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:8px;font:inherit;font-size:0.88rem;color:#1e293b;background:#fff;box-sizing:border-box}
.cp-fg select:focus,.cp-fg input:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,0.1)}
.cp-fg-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.cp-btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border-radius:8px;font-weight:600;font-size:0.85rem;cursor:pointer;border:none;font-family:inherit;transition:all 0.15s;text-decoration:none}
.cp-btn-primary{background:#2563eb;color:#fff}.cp-btn-primary:hover{background:#1d4ed8}
.cp-btn-outline{background:#fff;color:#1e293b;border:1.5px solid #e2e8f0}.cp-btn-outline:hover{border-color:#2563eb;color:#2563eb}
.cp-msg{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 20px;margin-bottom:16px;color:#166534;font-weight:600;font-size:0.88rem;display:flex;align-items:center;gap:10px}
.cp-msg.error{background:#fef2f2;border-color:#fecaca;color:#991b1b}
.cp-empty{text-align:center;padding:40px;color:#94a3b8}
.cp-empty i{font-size:2rem;color:#cbd5e1;margin-bottom:8px;display:block}
.cp-score{display:inline-flex;align-items:center;justify-content:center;width:64px;height:64px;border-radius:50%;font-size:1.2rem;font-weight:800;color:#fff}
@media(max-width:900px){.cp-cards{grid-template-columns:1fr 1fr}.cp-fg-row{grid-template-columns:1fr}}
@media(max-width:600px){.cp-cards{grid-template-columns:1fr}}
</style>
<style>@media(max-width:768px){.cp-score-grid{grid-template-columns:1fr!important}.cp-summary-grid{grid-template-columns:1fr!important}}</style>

<section class="cp">
<div class="cp-head"><i class="fas fa-credit-card"></i> My Credit</div>

<?php foreach ($messages as $m): ?>
<div class="cp-msg <?= $m['type'] ?>"><i class="fas fa-<?= $m['type']==='success'?'check-circle':'exclamation-circle' ?>"></i> <?= htmlspecialchars($m['text']) ?></div>
<?php endforeach; ?>

<!-- CREDIT SUMMARY CARDS -->
<div class="cp-cards">
    <div class="cp-card">
        <div class="cp-card-icon" style="background:#eff6ff;color:#2563eb"><i class="fas fa-money-check-alt"></i></div>
        <div>
            <div class="cp-card-val"><?= fmt($profile['credit_limit']) ?></div>
            <div class="cp-card-lbl">Credit Limit</div>
            <?php if ($profile['credit_limit'] > 0): ?>
            <div class="cp-progress"><div class="cp-progress-fill" style="width:<?= min($profile['utilization'], 100) ?>%;background:<?= $profile['utilization'] > 80 ? '#dc2626' : ($profile['utilization'] > 50 ? '#f59e0b' : '#16a34a') ?>"></div></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="cp-card">
        <div class="cp-card-icon" style="background:#f0fdf4;color:#16a34a"><i class="fas fa-wallet"></i></div>
        <div>
            <div class="cp-card-val" style="color:#16a34a"><?= fmt($profile['available_credit']) ?></div>
            <div class="cp-card-lbl">Available Credit</div>
        </div>
    </div>
    <div class="cp-card">
        <div class="cp-card-icon" style="background:#fef2f2;color:#dc2626"><i class="fas fa-exclamation-circle"></i></div>
        <div>
            <div class="cp-card-val" style="color:#dc2626"><?= fmt($profile['outstanding_balance']) ?></div>
            <div class="cp-card-lbl">Outstanding</div>
        </div>
    </div>
    <div class="cp-card">
        <div class="cp-card-icon" style="background:#fffbeb;color:#d97706"><i class="fas fa-clock"></i></div>
        <div>
            <div class="cp-card-val" style="color:<?= $profile['overdue_balance'] > 0 ? '#dc2626' : '#16a34a' ?>"><?= fmt($profile['overdue_balance']) ?></div>
            <div class="cp-card-lbl">Overdue</div>
        </div>
    </div>
</div>

<!-- CREDIT SCORE + INFO -->
<div class="cp-score-grid" style="display:grid;grid-template-columns:1fr 2fr;gap:20px;margin-bottom:24px">
    <div class="cp-panel">
        <div class="cp-panel-head"><div class="cp-panel-title"><i class="fas fa-star"></i> Credit Score</div></div>
        <div class="cp-panel-body" style="text-align:center">
            <?php
            $score = $profile['credit_score'];
            $scoreColor = $score >= 650 ? '#16a34a' : ($score >= 500 ? '#eab308' : ($score >= 300 ? '#f97316' : '#dc2626'));
            $scoreLabel = $score >= 650 ? 'Excellent' : ($score >= 500 ? 'Good' : ($score >= 300 ? 'Fair' : 'Poor'));
            ?>
            <div class="cp-score" style="background:<?= $scoreColor ?>;margin:0 auto 10px"><?= $score ?></div>
            <div style="font-weight:700;color:<?= $scoreColor ?>;font-size:0.9rem"><?= $scoreLabel ?></div>
            <div style="font-size:0.78rem;color:#64748b;margin-top:4px">Utilization: <?= $profile['utilization'] ?>%</div>
            <div style="font-size:0.78rem;color:#64748b">Status: <span style="font-weight:600;color:<?= $profile['credit_approval_status']==='approved' ? '#16a34a' : '#f59e0b' ?>"><?= ucfirst($profile['credit_approval_status']) ?></span></div>
        </div>
    </div>
    <div class="cp-panel">
        <div class="cp-panel-head"><div class="cp-panel-title"><i class="fas fa-info-circle"></i> Credit Summary</div></div>
        <div class="cp-panel-body">
            <div class="cp-summary-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:0.85rem">
                <div><span style="color:#64748b">Total Purchases:</span> <strong><?= fmt($profile['total_credit_sales']) ?></strong></div>
                <div><span style="color:#64748b">Total Payments:</span> <strong style="color:#16a34a"><?= fmt($profile['total_payments']) ?></strong></div>
                <div><span style="color:#64748b">Credit Terms:</span> <strong><?= $profile['credit_terms_days'] ?> days</strong></div>
                <div><span style="color:#64748b">Avg Payment Time:</span> <strong><?= $profile['avg_pay_days'] ? round($profile['avg_pay_days']) . ' days' : 'N/A' ?></strong></div>
                <div><span style="color:#64748b">Last Payment:</span> <strong><?= $profile['last_payment_date'] ? date('M d, Y', strtotime($profile['last_payment_date'])) : 'None' ?></strong></div>
                <div><span style="color:#64748b">Last Payment Amt:</span> <strong><?= $profile['last_payment_amount'] > 0 ? fmt($profile['last_payment_amount']) : 'N/A' ?></strong></div>
            </div>
        </div>
    </div>
</div>

<!-- TABS -->
<div class="cp-tabs">
    <a href="?page=credit_portal&tab=overview" class="cp-tab <?= $tab==='overview'?'active':'' ?>"><i class="fas fa-file-invoice"></i> Invoices</a>
    <a href="?page=credit_portal&tab=payments" class="cp-tab <?= $tab==='payments'?'active':'' ?>"><i class="fas fa-history"></i> Payment History</a>
    <a href="?page=credit_portal&tab=aging" class="cp-tab <?= $tab==='aging'?'active':'' ?>"><i class="fas fa-layer-group"></i> Aging</a>
    <a href="?page=credit_portal&tab=pay" class="cp-tab <?= $tab==='pay'?'active':'' ?>"><i class="fas fa-credit-card"></i> Pay Now</a>
</div>

<?php if ($tab === 'overview'): ?>
<div class="cp-panel">
    <div class="cp-panel-head"><div class="cp-panel-title"><i class="fas fa-file-invoice"></i> My Invoices</div></div>
    <?php if (empty($myInvoices)): ?>
    <div class="cp-empty"><i class="fas fa-file-invoice"></i><p>No invoices yet.</p></div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="cp-table">
        <thead><tr><th>Invoice</th><th>Date</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th>Due Date</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($myInvoices as $inv): ?>
        <tr>
            <td><strong><?= htmlspecialchars($inv['invoice_no']) ?></strong></td>
            <td><?= date('M d, Y', strtotime($inv['generated_at'])) ?></td>
            <td><?= fmt((float)$inv['total_amount']) ?></td>
            <td style="color:#16a34a"><?= fmt((float)$inv['amount_paid']) ?></td>
            <td style="font-weight:700;color:<?= $inv['outstanding_balance'] > 0 ? '#dc2626' : '#16a34a' ?>"><?= fmt((float)$inv['outstanding_balance']) ?>
                <?php if ((float)($inv['penalty_amount'] ?? 0) > 0): ?><br><span style="font-size:.72rem;color:#b45309">incl. <?= fmt((float)$inv['penalty_amount']) ?> late fees</span><?php endif; ?>
            </td>
            <td><span class="cp-badge <?= $inv['payment_status'] ?>"><?= ucfirst($inv['payment_status']) ?></span></td>
            <td><?= $inv['credit_due_date'] ? date('M d, Y', strtotime($inv['credit_due_date'])) : 'N/A' ?></td>
            <td>
                <?php if ($inv['outstanding_balance'] > 0): ?>
                <a href="index.php?page=invoice&id=<?= (int)$inv['id'] ?>" target="_blank" class="cp-btn cp-btn-primary" style="padding:4px 12px;font-size:0.78rem"><i class="fas fa-eye"></i> View</a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'payments'): ?>
<div class="cp-panel">
    <div class="cp-panel-head"><div class="cp-panel-title"><i class="fas fa-history"></i> Payment History</div></div>
    <?php if (empty($myPayments)): ?>
    <div class="cp-empty"><i class="fas fa-history"></i><p>No payments recorded yet.</p></div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="cp-table">
        <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Notes</th></tr></thead>
        <tbody>
        <?php foreach ($myPayments as $p): ?>
        <tr>
            <td><?= date('M d, Y', strtotime($p['payment_date'])) ?></td>
            <td style="color:#16a34a;font-weight:700"><?= fmt((float)$p['amount']) ?></td>
            <td style="text-transform:capitalize"><?= htmlspecialchars(str_replace('_', ' ', $p['payment_method'] ?? '')) ?></td>
            <td><?= htmlspecialchars($p['reference_no'] ?? '—') ?></td>
            <td><?= htmlspecialchars($p['notes'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'aging'): ?>
<div class="cp-panel">
    <div class="cp-panel-head"><div class="cp-panel-title"><i class="fas fa-layer-group"></i> Credit Aging</div></div>
    <div class="cp-panel-body">
    <?php
    $totalAging = $aging['total'];
    $colors = ['#16a34a','#eab308','#f97316','#dc2626','#991b1b'];
    $labels = ['Current','1-30 Days','31-60 Days','61-90 Days','90+ Days'];
    $keys = ['current','1_30','31_60','61_90','90_plus'];
    $maxVal = max(array_values($aging['buckets']), 1);
    ?>
    <?php foreach ($keys as $i => $k): ?>
    <div class="cp-aging-row">
        <div class="cp-aging-label"><?= $labels[$i] ?></div>
        <div class="cp-aging-bar">
            <div class="cp-aging-fill" style="width:<?= max(($aging['buckets'][$k] / $maxVal) * 100, $aging['buckets'][$k] > 0 ? 15 : 0) ?>%;background:<?= $colors[$i] ?>"><?= $aging['buckets'][$k] > 0 ? fmt($aging['buckets'][$k]) : '' ?></div>
        </div>
        <div class="cp-aging-val" style="color:<?= $colors[$i] ?>"><?= fmt($aging['buckets'][$k]) ?></div>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<?php elseif ($tab === 'pay'): ?>
<div class="cp-panel">
    <div class="cp-panel-head"><div class="cp-panel-title"><i class="fas fa-credit-card"></i> Make a Payment</div></div>
    <div class="cp-panel-body">
    <?php if ($profile['outstanding_balance'] <= 0): ?>
    <div class="cp-empty"><i class="fas fa-check-circle" style="color:#16a34a"></i><p><strong>No outstanding balance!</strong></p><p>You're all paid up.</p></div>
    <?php else: ?>
        <form method="post" class="cp-form">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="pay_now">
            <div class="cp-fg"><label>Outstanding Balance</label><input type="text" value="<?= fmt($profile['outstanding_balance']) ?>" readonly style="background:#f8fafc;font-weight:700;color:#dc2626"></div>
            <div class="cp-fg-row">
                <div class="cp-fg"><label>Payment Amount (ETB) *</label><input type="number" name="amount" min="0.01" max="<?= (float)$profile['outstanding_balance'] ?>" step="0.01" required placeholder="Enter amount"></div>
                <div class="cp-fg"><label>Payment Date *</label><input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required></div>
            </div>
            <div class="cp-fg-row">
                <div class="cp-fg"><label>Payment Method *</label><select name="payment_method" required>
                    <option value="cash">Cash</option>
                    <option value="bank">Bank Transfer</option>
                    <option value="telebirr">Telebirr</option>
                    <option value="cbe_birr">CBE Birr</option>
                </select></div>
                <div class="cp-fg"><label>Reference Number</label><input type="text" name="reference_no" placeholder="Transaction reference" required></div>
            </div>
            <button type="submit" class="cp-btn cp-btn-primary"><i class="fas fa-check-circle"></i> Submit Payment</button>
        </form>
    <?php endif; ?>
    </div>
</div>
<?php endif; ?>

</section>
