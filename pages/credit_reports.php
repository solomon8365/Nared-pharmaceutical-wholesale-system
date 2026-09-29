<?php
require_once __DIR__ . '/../includes/workflow_helpers.php';
require_once __DIR__ . '/../includes/credit_helpers.php';
requirePermission('credit_reports', 'view');
$pdo = getPDO();
creditSyncOverdue($pdo);

$tab = $_GET['tab'] ?? 'outstanding';
$reportMsg = '';

if (isset($_GET['export']) && $_GET['export'] === 'print') {
    echo '<script>window.print();</script>';
}
?>
<style>
.crr{max-width:1400px;margin:0 auto}
.crr-head{font-size:1.3rem;font-weight:700;color:#1e293b;margin:0 0 20px;display:flex;align-items:center;gap:10px}
.crr-head i{color:#2563eb}
.crr-tabs{display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:24px;overflow-x:auto}
.crr-tab{padding:10px 18px;font-size:0.84rem;font-weight:600;color:#64748b;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;text-decoration:none;display:flex;align-items:center;gap:6px;transition:all 0.15s;white-space:nowrap}
.crr-tab:hover{color:#1e293b}
.crr-tab.active{color:#2563eb;border-bottom-color:#2563eb}
.crr-panel{background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:24px}
.crr-panel-head{padding:16px 22px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between}
.crr-panel-title{font-size:0.95rem;font-weight:700;color:#1e293b;display:flex;align-items:center;gap:8px}
.crr-panel-title i{color:#2563eb}
.crr-table{width:100%;border-collapse:collapse}
.crr-table th{padding:10px 14px;text-align:left;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color:#64748b;background:#f8fafc;border-bottom:1px solid #e2e8f0}
.crr-table td{padding:10px 14px;font-size:0.84rem;color:#1e293b;border-bottom:1px solid #f1f5f9}
.crr-table tr:last-child td{border-bottom:none}
.crr-table tr:hover td{background:#f8fafc}
.crr-table tfoot td{font-weight:700;background:#f1f5f9;border-top:2px solid #e2e8f0}
.crr-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:0.7rem;font-weight:600}
.crr-badge.paid{background:#f0fdf4;color:#15803d}
.crr-badge.unpaid{background:#fef2f2;color:#dc2626}
.crr-badge.partial{background:#fffbeb;color:#b45309}
.crr-badge.overdue{background:#fef2f2;color:#dc2626;animation:crflash 1.5s ease infinite}
@keyframes crflash{0%,100%{opacity:1}50%{opacity:0.6}}
.crr-aging-bar{display:flex;gap:4px;height:32px;border-radius:8px;overflow:hidden;margin-bottom:16px}
.crr-aging-seg{display:flex;align-items:center;justify-content:center;color:#fff;font-size:0.7rem;font-weight:700;transition:width 0.3s}
.crr-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px}
.crr-sum-card{background:#f8fafc;border-radius:10px;padding:14px;border:1px solid #e2e8f0;text-align:center}
.crr-sum-val{font-size:1.1rem;font-weight:800;color:#1e293b}
.crr-sum-lbl{font-size:0.7rem;font-weight:600;color:#64748b;text-transform:uppercase;margin-top:2px}
.crr-filter{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.crr-filter select,.crr-filter input{padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:0.84rem}
.crr-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;font-weight:600;font-size:0.8rem;cursor:pointer;border:none;font-family:inherit;text-decoration:none;transition:all 0.15s}
.crr-btn-outline{background:#fff;color:#1e293b;border:1.5px solid #e2e8f0}.crr-btn-outline:hover{border-color:#2563eb;color:#2563eb}
.crr-btn-primary{background:#2563eb;color:#fff}.crr-btn-primary:hover{background:#1d4ed8}
.crr-empty{text-align:center;padding:40px;color:#94a3b8}
.crr-empty i{font-size:2rem;color:#cbd5e1;margin-bottom:8px;display:block}
.crr-stmt-header{display:flex;justify-content:space-between;align-items:center;padding:20px 22px;background:linear-gradient(135deg,#1e3a5f 0%,#2563eb 100%);color:#fff;border-radius:14px 14px 0 0}
.crr-stmt-company{font-size:1.1rem;font-weight:800}
.crr-stmt-info{font-size:0.82rem;opacity:0.8}
.crr-stmt-cust{padding:16px 22px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;font-size:0.84rem}
.crr-stmt-cust dt{color:#64748b;font-size:0.72rem;font-weight:600;text-transform:uppercase}
.crr-stmt-cust dd{font-weight:600;color:#1e293b;margin:0 0 8px}
@media print{.crr-tabs,.crr-filter,.no-print{display:none!important}.crr-panel{box-shadow:none;border:none}}
@media(max-width:768px){.crr-tabs{gap:0}.crr-tab{padding:8px 12px;font-size:0.78rem}.crr-stmt-cust{grid-template-columns:1fr 1fr}}
</style>

<section class="crr">
<div class="crr-head"><i class="fas fa-chart-bar"></i> Credit Reports</div>

<div class="crr-tabs">
    <a href="?page=credit_reports&tab=outstanding" class="crr-tab <?= $tab==='outstanding'?'active':'' ?>"><i class="fas fa-file-invoice-dollar"></i> Outstanding</a>
    <a href="?page=credit_reports&tab=overdue" class="crr-tab <?= $tab==='overdue'?'active':'' ?>"><i class="fas fa-exclamation-circle"></i> Overdue</a>
    <a href="?page=credit_reports&tab=aging" class="crr-tab <?= $tab==='aging'?'active':'' ?>"><i class="fas fa-layer-group"></i> Aging</a>
    <a href="?page=credit_reports&tab=collections" class="crr-tab <?= $tab==='collections'?'active':'' ?>"><i class="fas fa-hand-holding-usd"></i> Collections</a>
    <a href="?page=credit_reports&tab=customers" class="crr-tab <?= $tab==='customers'?'active':'' ?>"><i class="fas fa-users"></i> Credit Customers</a>
    <a href="?page=credit_reports&tab=statement" class="crr-tab <?= $tab==='statement'?'active':'' ?>"><i class="fas fa-file-alt"></i> Statement</a>
</div>

<?php if ($tab === 'outstanding'): ?>
<div class="crr-panel">
    <div class="crr-panel-head">
        <div class="crr-panel-title"><i class="fas fa-file-invoice-dollar"></i> Outstanding Invoices</div>
        <a href="?page=credit_reports&tab=outstanding&export=print" class="crr-btn crr-btn-outline no-print"><i class="fas fa-print"></i> Print</a>
    </div>
    <?php
    $osInvoices = $pdo->query("SELECT i.*, c.name AS customer_name FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE i.payment_status IN ('unpaid','partial') AND i.outstanding_balance > 0 ORDER BY i.credit_due_date ASC, i.generated_at DESC")->fetchAll();
    $totalOS = array_sum(array_column($osInvoices, 'outstanding_balance'));
    ?>
    <div class="crr-summary">
        <div class="crr-sum-card"><div class="crr-sum-val" style="color:#dc2626"><?= fmt($totalOS) ?></div><div class="crr-sum-lbl">Total Outstanding</div></div>
        <div class="crr-sum-card"><div class="crr-sum-val"><?= count($osInvoices) ?></div><div class="crr-sum-lbl">Unpaid Invoices</div></div>
    </div>
    <?php if (empty($osInvoices)): ?>
    <div class="crr-empty"><i class="fas fa-check-circle" style="color:#16a34a"></i><p>No outstanding invoices.</p></div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="crr-table">
        <thead><tr><th>Invoice</th><th>Customer</th><th>Order</th><th>Total</th><th>Paid</th><th>Outstanding</th><th>Due Date</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($osInvoices as $inv):
            $daysOverdue = $inv['credit_due_date'] ? max(0, (int)((time() - strtotime($inv['credit_due_date'])) / 86400)) : 0;
        ?>
        <tr>
            <td><strong><?= htmlspecialchars($inv['invoice_no']) ?></strong></td>
            <td><?= htmlspecialchars($inv['customer_name']) ?></td>
            <td>#<?= $inv['customer_order_id'] ?></td>
            <td><?= fmt((float)$inv['total_amount']) ?></td>
            <td style="color:#16a34a"><?= fmt((float)$inv['amount_paid']) ?></td>
            <td><strong style="color:#dc2626"><?= fmt((float)$inv['outstanding_balance']) ?></strong></td>
            <td><?= $inv['credit_due_date'] ? date('M d, Y', strtotime($inv['credit_due_date'])) : 'N/A' ?></td>
            <td><span class="crr-badge <?= $inv['payment_status'] ?>"><?= ucfirst($inv['payment_status']) ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="5">Total Outstanding</td><td colspan="3" style="color:#dc2626;font-size:1rem"><?= fmt($totalOS) ?></td></tr></tfoot>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'overdue'): ?>
<div class="crr-panel">
    <div class="crr-panel-head">
        <div class="crr-panel-title"><i class="fas fa-exclamation-circle"></i> Overdue Invoices</div>
    </div>
    <?php
    $overdueInvoices = $pdo->query("SELECT i.*, c.name AS customer_name, DATEDIFF(CURDATE(), i.credit_due_date) AS days_over FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE i.payment_status IN ('unpaid','partial') AND i.credit_due_date IS NOT NULL AND i.credit_due_date < CURDATE() ORDER BY i.credit_due_date ASC")->fetchAll();
    $totalOverdue = array_sum(array_column($overdueInvoices, 'outstanding_balance'));
    $avgDays = !empty($overdueInvoices) ? round(array_sum(array_column($overdueInvoices, 'days_over')) / count($overdueInvoices)) : 0;
    ?>
    <div class="crr-summary">
        <div class="crr-sum-card"><div class="crr-sum-val" style="color:#dc2626"><?= fmt($totalOverdue) ?></div><div class="crr-sum-lbl">Total Overdue</div></div>
        <div class="crr-sum-card"><div class="crr-sum-val" style="color:#b45309"><?= count($overdueInvoices) ?></div><div class="crr-sum-lbl">Overdue Invoices</div></div>
        <div class="crr-sum-card"><div class="crr-sum-val"><?= $avgDays ?></div><div class="crr-sum-lbl">Avg Days Overdue</div></div>
    </div>
    <?php if (empty($overdueInvoices)): ?>
    <div class="crr-empty"><i class="fas fa-check-circle" style="color:#16a34a"></i><p>No overdue invoices.</p></div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="crr-table">
        <thead><tr><th>Invoice</th><th>Customer</th><th>Total</th><th>Outstanding</th><th>Due Date</th><th>Days Overdue</th></tr></thead>
        <tbody>
        <?php foreach ($overdueInvoices as $inv): ?>
        <tr style="background:rgba(254,226,226,0.3)">
            <td><strong><?= htmlspecialchars($inv['invoice_no']) ?></strong></td>
            <td><?= htmlspecialchars($inv['customer_name']) ?></td>
            <td><?= fmt((float)$inv['total_amount']) ?></td>
            <td><strong style="color:#dc2626"><?= fmt((float)$inv['outstanding_balance']) ?></strong></td>
            <td><?= date('M d, Y', strtotime($inv['credit_due_date'])) ?></td>
            <td><span class="crr-badge overdue"><i class="fas fa-exclamation-triangle"></i> <?= $inv['days_over'] ?> days</span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="3">Total Overdue</td><td colspan="3" style="color:#dc2626;font-size:1rem"><?= fmt($totalOverdue) ?></td></tr></tfoot>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'aging'): ?>
<?php $aging = creditGetAging($pdo); ?>
<div class="crr-panel">
    <div class="crr-panel-head">
        <div class="crr-panel-title"><i class="fas fa-layer-group"></i> Credit Aging Report</div>
    </div>
    <?php
    $totalAging = $aging['total'];
    $colors = ['#16a34a','#eab308','#f97316','#dc2626','#991b1b'];
    $labels = ['Current','1-30 Days','31-60 Days','61-90 Days','90+ Days'];
    $keys = ['current','1_30','31_60','61_90','90_plus'];
    ?>
    <div style="padding:20px 22px">
    <?php if ($totalAging > 0): ?>
    <div class="crr-aging-bar">
        <?php foreach ($keys as $i => $k):
            $pct = $totalAging > 0 ? ($aging['buckets'][$k] / $totalAging * 100) : 0;
            if ($pct > 0): ?>
            <div class="crr-aging-seg" style="width:<?= max($pct, 5) ?>%;background:<?= $colors[$i] ?>" title="<?= $labels[$i] ?>: <?= fmt($aging['buckets'][$k]) ?>"><?= $labels[$i] ?></div>
        <?php endif; endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="crr-summary">
        <?php foreach ($keys as $i => $k): ?>
        <div class="crr-sum-card">
            <div class="crr-sum-val" style="color:<?= $colors[$i] ?>"><?= fmt($aging['buckets'][$k]) ?></div>
            <div class="crr-sum-lbl"><?= $labels[$i] ?></div>
        </div>
        <?php endforeach; ?>
        <div class="crr-sum-card"><div class="crr-sum-val"><?= fmt($totalAging) ?></div><div class="crr-sum-lbl">Total</div></div>
    </div>
    </div>
</div>

<?php elseif ($tab === 'collections'): ?>
<div class="crr-panel">
    <div class="crr-panel-head"><div class="crr-panel-title"><i class="fas fa-hand-holding-usd"></i> Payment Collections Report</div></div>
    <?php
    $byMethod = $pdo->query("SELECT payment_method, COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS total, COALESCE(AVG(amount),0) AS avg_amt FROM customer_credit_payments GROUP BY payment_method ORDER BY total DESC")->fetchAll();
    $byCustomer = $pdo->query("SELECT c.name, COALESCE(SUM(cp.amount),0) AS total_paid, MAX(cp.payment_date) AS last_pay FROM customer_credit_payments cp JOIN customers c ON cp.customer_id = c.id GROUP BY c.id, c.name ORDER BY total_paid DESC LIMIT 50")->fetchAll();
    $grandTotal = array_sum(array_column($byMethod, 'total'));
    $grandCount = array_sum(array_column($byMethod, 'cnt'));
    ?>
    <div style="padding:20px 22px">
    <h3 style="font-size:0.9rem;color:#1e293b;margin-bottom:12px">By Payment Method</h3>
    <div style="overflow-x:auto">
    <table class="crr-table">
        <thead><tr><th>Method</th><th>Count</th><th>Total Collected</th><th>Average</th></tr></thead>
        <tbody>
        <?php foreach ($byMethod as $m): ?>
        <tr>
            <td><strong style="text-transform:capitalize"><?= htmlspecialchars(str_replace('_', ' ', $m['payment_method'] ?? 'N/A')) ?></strong></td>
            <td><?= $m['cnt'] ?></td>
            <td style="color:#16a34a;font-weight:600"><?= fmt((float)$m['total']) ?></td>
            <td><?= fmt((float)$m['avg_amt']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td><strong>Grand Total</strong></td><td><strong><?= $grandCount ?></strong></td><td colspan="2" style="color:#16a34a;font-size:1rem"><strong><?= fmt($grandTotal) ?></strong></td></tr></tfoot>
    </table>
    </div>

    <h3 style="font-size:0.9rem;color:#1e293b;margin:20px 0 12px">By Customer</h3>
    <div style="overflow-x:auto">
    <table class="crr-table">
        <thead><tr><th>Customer</th><th>Total Paid</th><th>Last Payment</th></tr></thead>
        <tbody>
        <?php foreach ($byCustomer as $c): ?>
        <tr>
            <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
            <td style="color:#16a34a;font-weight:600"><?= fmt((float)$c['total_paid']) ?></td>
            <td><?= $c['last_pay'] ? date('M d, Y', strtotime($c['last_pay'])) : 'N/A' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>
</div>

<?php elseif ($tab === 'customers'): ?>
<div class="crr-panel">
    <div class="crr-panel-head"><div class="crr-panel-title"><i class="fas fa-users"></i> Credit Customers</div></div>
    <?php
    $custs = $pdo->query("SELECT c.*, COALESCE(SUM(i.outstanding_balance),0) AS outstanding FROM customers c LEFT JOIN invoices i ON i.customer_id = c.id AND i.payment_status IN ('unpaid','partial') WHERE c.credit_limit > 0 GROUP BY c.id ORDER BY c.name")->fetchAll();
    ?>
    <div style="overflow-x:auto">
    <table class="crr-table">
        <thead><tr><th>Customer</th><th>Type</th><th>Limit</th><th>Used</th><th>Available</th><th>Outstanding</th><th>Score</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($custs as $c):
            $lim = (float)$c['credit_limit'];
            $used = (float)$c['credit_used'];
            $avail = max($lim - $used, 0);
            $os = (float)$c['outstanding'];
            $score = (int)$c['credit_score'];
            $scoreColor = $score >= 650 ? '#16a34a' : ($score >= 500 ? '#eab308' : ($score >= 300 ? '#f97316' : '#dc2626'));
            $status = $c['credit_approval_status'] ?? 'pending';
            $statusColors = ['approved'=>'#16a34a','pending'=>'#f59e0b','suspended'=>'#dc2626','rejected'=>'#991b1b'];
        ?>
        <tr>
            <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
            <td><?= htmlspecialchars(ucfirst(str_replace('_',' ',$c['customer_type'] ?? ''))) ?></td>
            <td><?= fmt($lim) ?></td>
            <td><?= fmt($used) ?></td>
            <td style="color:<?= $avail > 0 ? '#16a34a' : '#dc2626' ?>;font-weight:600"><?= fmt($avail) ?></td>
            <td style="color:<?= $os > 0 ? '#dc2626' : '#16a34a' ?>;font-weight:600"><?= fmt($os) ?></td>
            <td><span style="color:<?= $scoreColor ?>;font-weight:700"><?= $score ?></span></td>
            <td><span class="crr-badge" style="background:<?= $statusColors[$status] ?? '#6b7280' ?>20;color:<?= $statusColors[$status] ?? '#6b7280' ?>"><?= ucfirst($status) ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php elseif ($tab === 'statement'): ?>
<?php
$custId = isset($_GET['cust_id']) ? (int)$_GET['cust_id'] : 0;
$customers = $pdo->query("SELECT id, name FROM customers WHERE credit_limit > 0 ORDER BY name")->fetchAll();
if ($custId > 0) {
    $profile = creditGetProfile($pdo, $custId);
    $stmtInvoices = $pdo->prepare("SELECT * FROM invoices WHERE customer_id = :cid ORDER BY generated_at DESC");
    $stmtInvoices->execute(['cid' => $custId]);
    $stmtInvoices = $stmtInvoices->fetchAll();
    $stmtPayments = $pdo->prepare("SELECT * FROM customer_credit_payments WHERE customer_id = :cid ORDER BY payment_date DESC");
    $stmtPayments->execute(['cid' => $custId]);
    $stmtPayments = $stmtPayments->fetchAll();
} else {
    $profile = [];
    $stmtInvoices = [];
    $stmtPayments = [];
}
?>
<div class="crr-panel">
    <div class="crr-panel-head">
        <div class="crr-panel-title"><i class="fas fa-file-alt"></i> Customer Statement</div>
        <div class="crr-filter no-print">
            <form method="get" style="display:flex;gap:8px;align-items:center">
                <input type="hidden" name="page" value="credit_reports">
                <input type="hidden" name="tab" value="statement">
                <select name="cust_id" onchange="this.form.submit()">
                    <option value="">Select Customer</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $custId === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php if ($custId > 0): ?>
            <a href="?page=credit_reports&tab=statement&cust_id=<?= $custId ?>&export=print" class="crr-btn crr-btn-outline"><i class="fas fa-print"></i> Print</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($custId > 0 && !empty($profile)): ?>
    <div class="crr-stmt-header">
        <div>
            <div class="crr-stmt-company"><?= htmlspecialchars(company_name()) ?></div>
            <div class="crr-stmt-info">Customer Statement</div>
        </div>
        <div style="text-align:right">
            <div class="crr-stmt-info">Date: <?= date('M d, Y') ?></div>
        </div>
    </div>
    <div class="crr-stmt-cust">
        <div><dt>Customer</dt><dd><?= htmlspecialchars($profile['name']) ?></dd><dt>Credit Limit</dt><dd><?= fmt($profile['credit_limit']) ?></dd></div>
        <div><dt>Available</dt><dd style="color:#16a34a"><?= fmt($profile['available_credit']) ?></dd><dt>Outstanding</dt><dd style="color:#dc2626"><?= fmt($profile['outstanding_balance']) ?></dd></div>
        <div><dt>Overdue</dt><dd style="color:#b45309"><?= fmt($profile['overdue_balance']) ?></dd><dt>Score</dt><dd><?= $profile['credit_score'] ?></dd></div>
    </div>

    <div style="padding:20px 22px">
    <h3 style="font-size:0.9rem;color:#1e293b;margin-bottom:10px">Invoices</h3>
    <div style="overflow-x:auto">
    <table class="crr-table">
        <thead><tr><th>#</th><th>Date</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th>Due Date</th></tr></thead>
        <tbody>
        <?php $runBal = 0; foreach ($stmtInvoices as $inv):
            $runBal += (float)$inv['outstanding_balance'];
        ?>
        <tr>
            <td><strong><?= htmlspecialchars($inv['invoice_no']) ?></strong></td>
            <td><?= date('M d, Y', strtotime($inv['generated_at'])) ?></td>
            <td><?= fmt((float)$inv['total_amount']) ?></td>
            <td style="color:#16a34a"><?= fmt((float)$inv['amount_paid']) ?></td>
            <td style="font-weight:600;color:<?= $inv['outstanding_balance'] > 0 ? '#dc2626' : '#16a34a' ?>"><?= fmt((float)$inv['outstanding_balance']) ?></td>
            <td><span class="crr-badge <?= $inv['payment_status'] ?>"><?= ucfirst($inv['payment_status']) ?></span></td>
            <td><?= $inv['credit_due_date'] ? date('M d, Y', strtotime($inv['credit_due_date'])) : 'N/A' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <h3 style="font-size:0.9rem;color:#1e293b;margin:20px 0 10px">Payments</h3>
    <?php if (empty($stmtPayments)): ?>
    <p style="color:#94a3b8;font-size:0.84rem">No payments recorded.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="crr-table">
        <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Notes</th></tr></thead>
        <tbody>
        <?php foreach ($stmtPayments as $p): ?>
        <tr>
            <td><?= date('M d, Y', strtotime($p['payment_date'])) ?></td>
            <td style="color:#16a34a;font-weight:600"><?= fmt((float)$p['amount']) ?></td>
            <td style="text-transform:capitalize"><?= htmlspecialchars(str_replace('_',' ',$p['payment_method'] ?? '')) ?></td>
            <td><?= htmlspecialchars($p['reference_no'] ?? '—') ?></td>
            <td><?= htmlspecialchars($p['notes'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    </div>
    <?php elseif ($custId > 0): ?>
    <div class="crr-empty"><i class="fas fa-user-slash"></i><p>Customer not found.</p></div>
    <?php else: ?>
    <div class="crr-empty"><i class="fas fa-file-alt"></i><p>Select a customer to view their statement.</p></div>
    <?php endif; ?>
</div>
<?php endif; ?>

</section>
