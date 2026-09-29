<?php
require_once __DIR__ . '/../includes/workflow_helpers.php';
require_once __DIR__ . '/../includes/credit_helpers.php';
require_once __DIR__ . '/../includes/credit_ledger.php';
$pdo = getPDO();
creditLedgerEnsureMigrations($pdo);
creditSyncOverdue($pdo);

$isCustomerView = isCustomer();
$staffCanView = hasPermission('credit_reports', 'view') || hasPermission('credit_management', 'view');

if ($isCustomerView) {
    requireCustomer();
    $cid = customerAccountCustomerId();
    if ($cid === null) { echo '<div class="panel"><p>Your account is not linked to a customer profile.</p></div>'; return; }
} else {
    if (!$staffCanView) {
        echo '<div class="panel" style="padding:40px;text-align:center;color:#b91c1c;font-weight:600">You do not have permission to view customer ledgers.</div>';
        return;
    }
}

$customers = [];
if (!$isCustomerView) {
    $customers = $pdo->query("SELECT id, name, customer_code, credit_limit, credit_used, is_credit_blocked, credit_approval_status FROM customers WHERE credit_limit > 0 ORDER BY name")->fetchAll();
    $cid = isset($_GET['customer_id']) ? (int) $_GET['customer_id'] : 0;
    if ($cid <= 0 && !empty($customers)) {
        $cid = (int) $customers[0]['id'];
    }
}

$ledger = [];
if ($cid > 0) {
    creditLedgerResetBestEffort($pdo, $cid);
    $ledger = creditLedgerGetCustomer($pdo, $cid);
}

$profile = $ledger['profile'] ?? null;
$unpaid = $ledger['unpaid'] ?? [];
$statement = $ledger['statement'] ?? [];
?>
<style>
.ledger{max-width:1400px;margin:0 auto}
.ledger-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:20px}
.ledger-title{font-size:1.3rem;font-weight:700;color:#1e293b;display:flex;align-items:center;gap:10px;margin:0}
.ledger-title i{color:#2563eb}
.ledger-badge{padding:4px 12px;border-radius:999px;font-size:.72rem;font-weight:700}
.ledger-badge.ok{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}
.ledger-badge.blocked{background:#fee2e2;color:#b91c1c;border:1px solid #fecaca}
.ledger-badge.pending{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.ledger-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:24px}
.ledger-stat{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:16px 18px}
.ledger-stat .ls-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#64748b;margin-bottom:6px;display:flex;align-items:center;gap:6px}
.ledger-stat .ls-val{font-size:1.25rem;font-weight:800;color:#0f172a}
.ledger-stat .ls-sub{font-size:.72rem;color:#94a3b8;margin-top:4px}
.ledger-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;margin-bottom:24px}
.ledger-card-head{padding:15px 20px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.ledger-card-title{font-size:.95rem;font-weight:700;color:#1e293b;display:flex;align-items:center;gap:8px}
.ledger-card-title i{color:#2563eb}
.ledger-table{width:100%;border-collapse:collapse}
.ledger-table th{padding:11px 14px;text-align:left;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#64748b;background:#f8fafc;border-bottom:1px solid #e2e8f0;white-space:nowrap}
.ledger-table td{padding:11px 14px;font-size:.84rem;color:#1e293b;border-bottom:1px solid #f1f5f9}
.ledger-table tr:last-child td{border-bottom:none}
.ledger-table .num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.ledger-tag{display:inline-block;padding:3px 10px;border-radius:999px;font-size:.7rem;font-weight:700}
.ledger-tag.inv{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
.ledger-tag.pay{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}
.ledger-status{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:999px;font-size:.72rem;font-weight:700}
.ledger-status.unpaid{background:#fee2e2;color:#b91c1c}
.ledger-status.partial{background:#fef3c7;color:#92400e}
.ledger-status.paid{background:#dcfce7;color:#166534}
.ledger-empty{text-align:center;padding:36px;color:#94a3b8}
.ledger-empty i{font-size:2.2rem;display:block;margin-bottom:10px;color:#cbd5e1}
.ledger-custsel{margin-bottom:20px}
.ledger-custsel select{padding:10px 14px;border:1px solid #cbd5e1;border-radius:10px;font-size:.88rem;font-family:inherit;min-width:280px}
.ledger-overdue{color:#b91c1c;font-weight:700}
@media(max-width:700px){.ledger-stats{grid-template-columns:repeat(2,1fr)}.ledger-table{font-size:.78rem}}
</style>

<section class="ledger">
    <div class="ledger-head">
        <h2 class="ledger-title"><i class="fas fa-book"></i> Customer Ledger
            <?php if ($profile): ?>
                <span style="font-size:.8rem;color:#64748b;font-weight:600"><?= htmlspecialchars($profile['customer_name']) ?></span>
            <?php endif; ?>
        </h2>
        <?php if ($profile): ?>
            <?php $statusOk = $profile['is_credit_blocked'] ? 'blocked' : 'ok'; ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <span class="ledger-badge <?= $statusOk ?>"><i class="fas fa-<?= $profile['is_credit_blocked'] ? 'ban' : 'check-circle' ?>"></i> <?= $profile['is_credit_blocked'] ? 'Credit Blocked' : 'Credit Active' ?></span>
                <span class="ledger-badge <?= $profile['credit_approval_status'] === 'approved' ? 'ok' : ($profile['credit_approval_status'] === 'pending' ? 'pending' : 'blocked') ?>"><?= ucwords(str_replace('_', ' ', htmlspecialchars((string) $profile['credit_approval_status']))) ?></span>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$isCustomerView && $cid <= 0 && empty($customers)): ?>
        <div class="ledger-card"><div class="ledger-empty"><i class="fas fa-hand-holding-usd"></i>No credit customers yet. Grant credit in Credit Management to see ledgers.</div></div>
        <?php return; ?>
    <?php endif; ?>

    <?php if (!$isCustomerView && !empty($customers)): ?>
    <form method="get" class="ledger-custsel">
        <input type="hidden" name="page" value="ledger">
        <select name="customer_id" onchange="this.form.submit()">
            <?php foreach ($customers as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= $cid === (int) $c['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['name']) ?> (<?= fmt((float) $c['credit_limit']) ?> limit<?= $c['is_credit_blocked'] ? ' · blocked' : '' ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php endif; ?>

    <?php if (!$profile): ?>
        <div class="ledger-card"><div class="ledger-empty"><i class="fas fa-user"></i>No credit profile found for this customer.</div></div>
        <?php return; ?>
    <?php endif; ?>

    <div class="ledger-stats">
        <div class="ledger-stat"><div class="ls-label"><i class="fas fa-credit-card"></i> Credit Limit</div><div class="ls-val"><?= fmt((float) $profile['credit_limit']) ?></div></div>
        <div class="ledger-stat"><div class="ls-label"><i class="fas fa-chart-line"></i> Credit Used</div><div class="ls-val"><?= fmt((float) $profile['credit_used']) ?></div><div class="ls-sub"><?= $profile['utilization'] ?>% utilization</div></div>
        <div class="ledger-stat"><div class="ls-label"><i class="fas fa-piggy-bank"></i> Available</div><div class="ls-val"><?= fmt((float) $profile['available_credit']) ?></div></div>
        <div class="ledger-stat"><div class="ls-label"><i class="fas fa-wallet"></i> Outstanding</div><div class="ls-val"><?= fmt((float) $profile['outstanding_balance']) ?></div></div>
        <div class="ledger-stat"><div class="ls-label"><i class="fas fa-exclamation-triangle"></i> Overdue</div><div class="ls-val <?= (float) $profile['overdue_balance'] > 0 ? 'ledger-overdue' : '' ?>"><?= fmt((float) $profile['overdue_balance']) ?></div></div>
        <div class="ledger-stat"><div class="ls-label"><i class="fas fa-calendar-alt"></i> Payment Terms</div><div class="ls-val" style="font-size:1rem"><?= (int) $profile['credit_period_days'] ?> days</div><div class="ls-sub">Net <?= (int) $profile['credit_period_days'] ?> days from invoice</div></div>
        <div class="ledger-stat"><div class="ls-label"><i class="fas fa-star"></i> Credit Score</div><div class="ls-val"><?= (int) $profile['credit_score'] ?></div></div>
        <div class="ledger-stat"><div class="ls-label"><i class="fas fa-clock"></i> Last Payment</div><div class="ls-val" style="font-size:.95rem"><?= $profile['last_payment_date'] ? date('M j, Y', strtotime((string) $profile['last_payment_date'])) : '—' ?></div><div class="ls-sub"><?= $profile['last_payment_amount'] > 0 ? fmt((float) $profile['last_payment_amount']) : '' ?></div></div>
    </div>

    <div class="ledger-card">
        <div class="ledger-card-head">
            <div class="ledger-card-title"><i class="fas fa-file-invoice-dollar"></i> Unpaid Invoices <span style="color:#64748b;font-weight:600;font-size:.8rem">(FIFO order — oldest first)</span></div>
            <span style="font-size:.8rem;font-weight:700;color:#1e293b">Total Outstanding: <?= fmt((float) $profile['outstanding_balance']) ?></span>
        </div>
        <?php if (empty($unpaid)): ?>
            <div class="ledger-empty"><i class="fas fa-check-circle"></i>No unpaid invoices. All balances are cleared.</div>
        <?php else: ?>
        <table class="ledger-table">
            <thead><tr><th>Invoice #</th><th>Date</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Outstanding</th><th>Due Date</th><th class="num">Days Overdue</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($unpaid as $u): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($u['invoice_no']) ?></strong><?= !empty($u['sales_type']) && $u['sales_type'] === 'credit' ? ' <span class="ledger-tag inv">credit</span>' : '' ?></td>
                    <td><?= date('M j, Y', strtotime((string) $u['generated_at'])) ?></td>
                    <td class="num"><?= fmt((float) $u['total_amount']) ?></td>
                    <td class="num"><?= fmt((float) $u['amount_paid']) ?></td>
                    <td class="num"><strong><?= fmt((float) $u['outstanding_balance']) ?></strong></td>
                    <td><?= $u['credit_due_date'] ? date('M j, Y', strtotime((string) $u['credit_due_date'])) : '—' ?></td>
                    <td class="num <?= (int) $u['days_overdue'] > 0 ? 'ledger-overdue' : '' ?>"><?= $u['days_overdue'] !== null ? (int) $u['days_overdue'] : '—' ?></td>
                    <td><span class="ledger-status <?= $u['payment_status'] ?>"><?= ucfirst($u['payment_status']) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="ledger-card">
        <div class="ledger-card-head">
            <div class="ledger-card-title"><i class="fas fa-stream"></i> Statement History</div>
            <span style="font-size:.8rem;font-weight:700;color:#1e293b">Running Balance: <span style="color:<?= $ledger['running_total'] > 0 ? '#b91c1c' : '#166534' ?>"><?= fmt((float) $ledger['running_total']) ?></span></span>
        </div>
        <?php if (empty($statement)): ?>
            <div class="ledger-empty"><i class="fas fa-stream"></i>No ledger activity yet.</div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="ledger-table">
            <thead><tr><th>Date</th><th>Type</th><th>Reference</th><th class="num">Amount</th><th class="num">Balance</th><th>Recorded By</th></tr></thead>
            <tbody>
                <?php foreach ($statement as $tx): ?>
                <tr>
                    <td style="white-space:nowrap"><?= date('M j, Y H:i', strtotime((string) $tx['created_at'])) ?></td>
                    <td><span class="ledger-tag <?= $tx['type'] === 'INVOICE' ? 'inv' : 'pay' ?>"><?= $tx['type'] === 'INVOICE' ? 'INVOICE' : 'PAYMENT' ?></span></td>
                    <td><?= htmlspecialchars((string) ($tx['reference_number'] ?: '—')) ?></td>
                    <td class="num"><strong style="color:<?= $tx['type'] === 'INVOICE' ? '#b91c1c' : '#166534' ?>"><?= $tx['type'] === 'INVOICE' ? '+' : '−' ?><?= fmt((float) $tx['amount']) ?></strong></td>
                    <td class="num"><span style="color:<?= $tx['running_balance'] > 0 ? '#b91c1c' : '#166534' ?>"><?= fmt((float) $tx['running_balance']) ?></span></td>
                    <td><?= $tx['created_by'] ? userFullName($pdo, (int) $tx['created_by']) : 'System' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($isCustomerView): ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px">
        <a href="?page=credit_portal" class="del-btn del-btn-blue" style="text-decoration:none;display:inline-flex;align-items:center;gap:8px"><i class="fas fa-credit-card"></i> Manage &amp; Pay Credit</a>
        <a href="?page=invoice" class="del-btn del-btn-outline" style="text-decoration:none;display:inline-flex;align-items:center;gap:8px"><i class="fas fa-file-invoice-dollar"></i> My Invoices</a>
    </div>
    <?php endif; ?>
</section>