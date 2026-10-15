<?php
require_once __DIR__ . '/../includes/finance_helpers.php';
require_once __DIR__ . '/../includes/auto_migrate.php';
requirePermission('finance_dashboard', 'view');
ensureFinanceErpMigration(finGetDb());
$pdo = finGetDb();
ensureCustomerPaymentSubmissionMigration($pdo);
$user = currentUser();

$todaySales = finGetTodaySales($pdo);
$todaySalesPrev = finGetTodaySalesPrev($pdo);
$todayCollections = finGetTodayCollections($pdo);
$todayCollectionsPrev = finGetTodayCollectionsPrev($pdo);
$outRec = finGetOutstandingReceivables($pdo);
$outPay = finGetOutstandingPayables($pdo);
$bankBal = finGetBankBalance($pdo);
$cashBal = finGetCashBalance($pdo);
$monthRev = finGetMonthRevenue($pdo);
$monthRevPrev = finGetMonthRevenuePrev($pdo);
$monthCOGS = finGetMonthCOGS($pdo);
$monthExp = finGetMonthExpenses($pdo);
$monthExpPrev = finGetMonthExpensesPrev($pdo);
$monthInc = finGetMonthIncome($pdo);
$refunds = finGetTotalRefunds($pdo);
$grossProfit = $monthRev - $monthCOGS;
$netProfit = $monthRev - $monthCOGS - $monthExp - $refunds;
$pendingApprovals = finGetPendingPaymentApprovals($pdo);

// Customer-submitted payments awaiting Finance verification (customer_payment_submissions)
$pendingCustSubmissions = 0;
$pendingCustSubmissionAmount = 0.0;
$customerPendingSubs = [];
try {
    $pendingCustSubmissions = (int) $pdo->query("SELECT COUNT(*) FROM customer_payment_submissions WHERE status = 'pending'")->fetchColumn();
    $pendingCustSubmissionAmount = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM customer_payment_submissions WHERE status = 'pending'")->fetchColumn();
    $cpsStmt = $pdo->query("SELECT s.id, s.amount, s.payment_method, s.reference_no, s.created_at, s.receipt_path, c.name AS customer_name, i.invoice_no
                            FROM customer_payment_submissions s
                            LEFT JOIN customers c ON s.customer_id = c.id
                            LEFT JOIN invoices i ON s.invoice_id = i.id
                            WHERE s.status = 'pending'
                            ORDER BY s.created_at ASC LIMIT 5");
    $customerPendingSubs = $cpsStmt->fetchAll();
} catch (Exception $e) {
    $pendingCustSubmissions = 0;
    $pendingCustSubmissionAmount = 0.0;
    $customerPendingSubs = [];
}

$creditOutstanding = (float) $pdo->query("SELECT COALESCE(SUM(outstanding_balance),0) FROM invoices WHERE payment_status IN ('unpaid','partial')")->fetchColumn();
$creditOverdue = (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE payment_status IN ('unpaid','partial') AND credit_due_date IS NOT NULL AND credit_due_date < CURDATE()")->fetchColumn();
$creditPending = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE credit_approval_status = 'pending'")->fetchColumn();
$canCredit = hasPermission('credit_management', 'view') || hasPermission('credit_control', 'view') || isAdmin();

$cashInflow = $todayCollections + $monthInc;
$cashOutflow = $monthExp;
$netCashFlow = $cashInflow - $cashOutflow;

$monthlyRevenue = finGetMonthlyData($pdo, 'revenue');
$monthlyExpenses = finGetMonthlyData($pdo, 'expense');

$monthlyCollections = [];
for ($i = 11; $i >= 0; $i--) {
    $m = date('n', strtotime("-{$i} months"));
    $y = date('Y', strtotime("-{$i} months"));
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE MONTH(created_at) = ? AND YEAR(created_at) = ?');
    $stmt->execute([$m, $y]);
    $monthlyCollections[] = ['label' => date('M Y', strtotime("-{$i} months")), 'value' => (float) $stmt->fetchColumn()];
}

$recentTransactions = $pdo->query("
    (SELECT 'payment' AS type, ip.amount, ip.created_at, c.name COLLATE utf8mb4_general_ci AS entity, ip.payment_method COLLATE utf8mb4_general_ci AS method FROM invoice_payments ip LEFT JOIN customers c ON ip.customer_id = c.id)
    UNION ALL
    (SELECT 'expense' AS type, e.amount, e.created_at, e.expense_category COLLATE utf8mb4_general_ci AS entity, NULL AS method FROM expenses e)
    UNION ALL
    (SELECT 'refund' AS type, r.amount, r.created_at, r.reason COLLATE utf8mb4_general_ci AS entity, NULL AS method FROM refunds r)
    ORDER BY created_at DESC LIMIT 12
")->fetchAll();

$notifications = [];
try {
    $nstmt = $pdo->query('SELECT * FROM finance_notifications WHERE is_read = 0 ORDER BY created_at DESC LIMIT 10');
    $notifications = $nstmt->fetchAll();
} catch (Exception $e) {}

if (empty($notifications)) {
    $lowBankCount = $pdo->query("SELECT COUNT(*) FROM bank_accounts WHERE balance < 10000 AND status = 'active'")->fetchColumn();
    if ($lowBankCount > 0) $notifications[] = ['notification_type' => 'low_balance', 'title' => 'Low Bank Balance', 'message' => "$lowBankCount bank account(s) below 10,000.", 'severity' => 'warning'];
    $payableStatuses = implode('","', finPoPayableStatuses());
    $pendingSupplier = (int) $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN (\"{$payableStatuses}\") AND payment_status != 'paid'")->fetchColumn();
    if ($pendingSupplier > 0) $notifications[] = ['notification_type' => 'pending_payments', 'title' => 'Pending Supplier Payments', 'message' => "$pendingSupplier supplier payment(s) awaiting processing.", 'severity' => 'info'];
    $unreconciledCount = $pdo->query("SELECT COUNT(*) FROM bank_transactions WHERE status = 'pending'")->fetchColumn();
    if ($unreconciledCount > 0) $notifications[] = ['notification_type' => 'unreconciled', 'title' => 'Pending Bank Transactions', 'message' => "$unreconciledCount bank transaction(s) pending.", 'severity' => 'warning'];
    if ($canCredit && $creditOverdue > 0) $notifications[] = ['notification_type' => 'overdue_credit', 'title' => 'Overdue Credit', 'message' => "$creditOverdue credit invoice(s) past due, total " . currency_sym() . " " . number_format($creditOutstanding, 2) . ".", 'severity' => 'warning'];
}

if ($pendingCustSubmissions > 0) {
    array_unshift($notifications, [
        'notification_type' => 'customer_payment_pending',
        'title' => 'Customer Payments Awaiting Verification',
        'message' => $pendingCustSubmissions . ' customer payment' . ($pendingCustSubmissions === 1 ? '' : 's') . ' totalling ' . currency_sym() . ' ' . number_format($pendingCustSubmissionAmount, 2) . ' awaiting Finance approval.',
        'severity' => 'warning',
    ]);
}

$ch = finPctChange($todaySales, $todaySalesPrev);
$chCol = finPctChange($todayCollections, $todayCollectionsPrev);
$chRev = finPctChange($monthRev, $monthRevPrev);
$chExp = finPctChange($monthExp, $monthExpPrev);

$maxRevVal = max(array_column($monthlyRevenue, 'value')) ?: 1;
$maxExpVal = max(array_column($monthlyExpenses, 'value')) ?: 1;
$maxColVal = max(array_column($monthlyCollections, 'value')) ?: 1;
$maxChartVal = max($maxRevVal, $maxExpVal);
?>
<?= finCss() ?>
<style>
.fd-chart-wrap{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
.fd-chart{background:var(--fin-card);border-radius:var(--fin-radius);border:1px solid var(--fin-border);padding:20px;box-shadow:var(--fin-shadow)}
.fd-chart h3{font-size:.9rem;font-weight:700;color:#0f172a;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.fd-bar-chart{display:flex;align-items:flex-end;gap:4px;height:160px;padding:10px 0;position:relative}
.fd-bar-group{flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;height:100%;justify-content:flex-end}
.fd-bar{width:60%;border-radius:4px 4px 0 0;min-height:2px;transition:height .3s}
.fd-bar.rev{background:linear-gradient(180deg,#2563eb,#1d4ed8)}
.fd-bar.exp{background:linear-gradient(180deg,#dc2626,#b91c1c)}
.fd-bar.col{background:linear-gradient(180deg,#059669,#047857)}
.fd-bar-label{font-size:.6rem;color:#64748b;text-align:center;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
.fd-bar-legend{display:flex;gap:16px;justify-content:center;margin-top:10px;font-size:.75rem;color:#475569}
.fd-bar-legend span{display:flex;align-items:center;gap:5px}
.fd-bar-legend i{width:10px;height:10px;border-radius:2px;display:inline-block}
.fd-cash-flow{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;text-align:center;padding:10px 0}
.fd-cash-flow .fd-cf-val{font-size:1.15rem;font-weight:800}
.fd-cash-flow .fd-cf-label{font-size:.72rem;color:#64748b;margin-top:2px}
.fd-trans-type{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:10px;font-size:.68rem;font-weight:600}
.fd-trans-type.payment{background:#dcfce7;color:#166534}
.fd-trans-type.expense{background:#fee2e2;color:#991b1b}
.fd-trans-type.refund{background:#ffedd5;color:#9a3412}
@media(max-width:1200px){.fd-chart-wrap{grid-template-columns:1fr}}
@media(max-width:480px){.fd-cash-flow{grid-template-columns:1fr!important}.fd-cash-flow .fd-cf-val{font-size:1rem!important}}
.fd-cpa{display:flex;align-items:center;gap:14px;flex-wrap:wrap;background:linear-gradient(135deg,#eef2ff,#f5f3ff);border:1px solid #c7d2fe;border-left:5px solid var(--fin-purple,#7c3aed);border-radius:var(--fin-radius,12px);padding:14px 18px;margin-bottom:16px;box-shadow:var(--fin-shadow,0 1px 3px rgba(0,0,0,.08))}
.fd-cpa-icon{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex:0 0 auto}
.fd-cpa-body{flex:1;min-width:200px}
.fd-cpa-body strong{display:block;font-size:.95rem;font-weight:800;color:#0f172a}
.fd-cpa-body span{font-size:.8rem;color:#475569}
.fd-cpa-btn{display:inline-flex;align-items:center;gap:7px;background:var(--fin-purple,#7c3aed);color:#fff;text-decoration:none;font-weight:700;font-size:.84rem;padding:10px 18px;border-radius:9px;white-space:nowrap;transition:transform .15s,filter .15s}
.fd-cpa-btn:hover{filter:brightness(1.07);transform:translateY(-1px)}
.fd-cpa-list{flex:1 1 100%;display:flex;flex-direction:column;gap:6px;margin-top:4px;border-top:1px dashed #c7d2fe;padding-top:10px}
.fd-cpa-item{display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:.82rem;padding:6px 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px}
.fd-cpa-amt{font-weight:800;color:#166534;min-width:100px}
.fd-cpa-meta{flex:1;color:#475569;min-width:160px}
.fd-cpa-date{color:#94a3b8;font-size:.75rem}
@media(max-width:600px){.fd-cpa{flex-direction:column;align-items:stretch}.fd-cpa-btn{justify-content:center}}
</style>

<section class="fin-wrap">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:6px">
        <div>
            <h1><i class="fas fa-chart-line" style="color:var(--fin-blue)"></i> Finance Dashboard</h1>
            <div class="fin-subtitle">Company-wide financial performance overview</div>
        </div>
        <div style="display:flex;gap:8px">
            <select onchange="if(this.value)window.location=this.value" style="padding:8px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.82rem">
                <option value="">Current Period</option>
                <option value="?page=finance_dashboard&range=today">Today</option>
                <option value="?page=finance_dashboard&range=week">This Week</option>
                <option value="?page=finance_dashboard&range=month">This Month</option>
            </select>
        </div>
    </div>

    <?php if ($pendingCustSubmissions > 0): ?>
    <div class="fd-cpa">
        <div class="fd-cpa-icon"><i class="fas fa-user-check"></i></div>
        <div class="fd-cpa-body">
            <strong><?= (int) $pendingCustSubmissions ?> customer payment<?= $pendingCustSubmissions === 1 ? '' : 's' ?> awaiting verification</strong>
            <span>Total <?= htmlspecialchars(currency_sym()) ?> <?= number_format($pendingCustSubmissionAmount, 2) ?> submitted by customers and pending Finance approval.</span>
        </div>
        <a href="?page=finance_payments&amp;tab=customer" class="fd-cpa-btn"><i class="fas fa-check-circle"></i> Review &amp; Verify</a>
        <?php if (!empty($customerPendingSubs)): ?>
        <div class="fd-cpa-list">
            <?php foreach ($customerPendingSubs as $cs): ?>
            <div class="fd-cpa-item">
                <span class="fd-cpa-amt"><?= htmlspecialchars(currency_sym()) ?> <?= number_format((float) $cs['amount'], 2) ?></span>
                <span class="fd-cpa-meta"><?= htmlspecialchars($cs['customer_name'] ?? 'Unknown customer') ?><?= !empty($cs['invoice_no']) ? ' · ' . htmlspecialchars($cs['invoice_no']) : '' ?> · <?= htmlspecialchars(ucfirst((string) $cs['payment_method'])) ?></span>
                <span class="fd-cpa-date"><?= date('M d, H:i', strtotime((string) $cs['created_at'])) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="fin-stats">
        <div class="fin-stat" onclick="window.location='?page=finance_reports&tab=sales'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#2563eb,#1d4ed8)"><i class="fas fa-shopping-cart"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $todaySales ?>" data-prefix="<?= currency_sym() ?> " data-decimals="2"><?= currency_sym() ?> 0.00</div>
                <div class="fin-stat-label">Today's Sales</div>
                <div class="fin-stat-change <?= $ch['dir'] ?>"><i class="fas fa-arrow-<?= $ch['dir'] === 'up' ? 'up' : ($ch['dir'] === 'down' ? 'down' : 'right') ?>"></i> <?= $ch['pct'] ?>%</div>
            </div>
        </div>
        <div class="fin-stat" onclick="window.location='?page=finance_payments'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#059669,#047857)"><i class="fas fa-hand-holding-usd"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $todayCollections ?>" data-prefix="<?= currency_sym() ?> " data-decimals="2"><?= currency_sym() ?> 0.00</div>
                <div class="fin-stat-label">Today's Collections</div>
                <div class="fin-stat-change <?= $chCol['dir'] ?>"><i class="fas fa-arrow-<?= $chCol['dir'] === 'up' ? 'up' : ($chCol['dir'] === 'down' ? 'down' : 'right') ?>"></i> <?= $chCol['pct'] ?>%</div>
            </div>
        </div>
        <div class="fin-stat" onclick="window.location='?page=finance_reports'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#d97706,#b45309)"><i class="fas fa-file-invoice-dollar"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $outRec ?>" data-prefix="<?= currency_sym() ?> " data-decimals="2"><?= currency_sym() ?> 0.00</div>
                <div class="fin-stat-label">Outstanding Receivables</div>
            </div>
        </div>
        <div class="fin-stat" onclick="window.location='?page=supplier_payments'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#dc2626,#b91c1c)"><i class="fas fa-file-contract"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $outPay ?>" data-prefix="<?= currency_sym() ?> " data-decimals="2"><?= currency_sym() ?> 0.00</div>
                <div class="fin-stat-label">Outstanding Payables</div>
            </div>
        </div>
        <div class="fin-stat" onclick="window.location='?page=finance_bank'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#0d9488,#0f766e)"><i class="fas fa-university"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $bankBal ?>" data-prefix="<?= currency_sym() ?> " data-decimals="2"><?= currency_sym() ?> 0.00</div>
                <div class="fin-stat-label">Bank Balance</div>
            </div>
        </div>
        <div class="fin-stat" onclick="window.location='?page=finance_cash'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#7c3aed,#6d28d9)"><i class="fas fa-money-bill-wave"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $cashBal ?>" data-prefix="<?= currency_sym() ?> " data-decimals="2"><?= currency_sym() ?> 0.00</div>
                <div class="fin-stat-label">Cash Balance</div>
            </div>
        </div>
        <div class="fin-stat" onclick="window.location='?page=finance_reports'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#4f46e5,#4338ca)"><i class="fas fa-chart-area"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $monthRev ?>" data-prefix="<?= currency_sym() ?> " data-decimals="2"><?= currency_sym() ?> 0.00</div>
                <div class="fin-stat-label">Monthly Revenue</div>
                <div class="fin-stat-change <?= $chRev['dir'] ?>"><i class="fas fa-arrow-<?= $chRev['dir'] === 'up' ? 'up' : ($chRev['dir'] === 'down' ? 'down' : 'right') ?>"></i> <?= $chRev['pct'] ?>%</div>
            </div>
        </div>
        <div class="fin-stat" onclick="window.location='?page=finance_reports&tab=profit'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#dc2626,#b91c1c)"><i class="fas fa-truck"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $monthCOGS ?>" data-prefix="<?= currency_sym() ?> " data-decimals="2"><?= currency_sym() ?> 0.00</div>
                <div class="fin-stat-label">COGS (Cost of Goods Sold)</div>
            </div>
        </div>
        <div class="fin-stat" onclick="window.location='?page=finance_reports&tab=profit'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#d97706,#b45309)"><i class="fas fa-coins"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $grossProfit ?>" data-prefix="<?= currency_sym() ?> " data-decimals="2"><?= currency_sym() ?> 0.00</div>
                <div class="fin-stat-label">Gross Profit</div>
            </div>
        </div>
        <div class="fin-stat" onclick="window.location='?page=finance_expenses'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#ea580c,#c2410c)"><i class="fas fa-receipt"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $monthExp ?>" data-prefix="<?= currency_sym() ?> " data-decimals="2"><?= currency_sym() ?> 0.00</div>
                <div class="fin-stat-label">Expenses</div>
                <div class="fin-stat-change <?= $chExp['dir'] ?>"><i class="fas fa-arrow-<?= $chExp['dir'] === 'up' ? 'up' : ($chExp['dir'] === 'down' ? 'down' : 'right') ?>"></i> <?= $chExp['pct'] ?>%</div>
            </div>
        </div>
        <div class="fin-stat" onclick="window.location='?page=finance_refunds'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#db2777,#be185d)"><i class="fas fa-undo-alt"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $refunds ?>" data-prefix="<?= currency_sym() ?> " data-decimals="2"><?= currency_sym() ?> 0.00</div>
                <div class="fin-stat-label">Refunds</div>
            </div>
        </div>
        <div class="fin-stat" onclick="window.location='?page=finance_reports&tab=profit'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#059669,#16a34a)"><i class="fas fa-chart-pie"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $netProfit ?>" data-prefix="<?= currency_sym() ?> " data-decimals="2"><?= currency_sym() ?> 0.00</div>
                <div class="fin-stat-label">Net Profit</div>
            </div>
        </div>
        <div class="fin-stat" onclick="window.location='?page=finance_payments<?= $pendingCustSubmissions > 0 ? '&tab=customer' : '' ?>'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706)"><i class="fas fa-clock"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $pendingApprovals + $pendingCustSubmissions ?>" data-prefix="" data-suffix="">0</div>
                <div class="fin-stat-label">Pending Payment Approvals</div>
                <?php if ($pendingCustSubmissions > 0): ?><div class="fin-stat-change up" style="color:#7c3aed"><i class="fas fa-user-check"></i> <?= (int) $pendingCustSubmissions ?> customer<?= $pendingCustSubmissions === 1 ? '' : 's' ?></div><?php endif; ?>
            </div>
        </div>
        <?php if ($canCredit): ?>
        <div class="fin-stat" onclick="window.location='?page=credit_management'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#7c3aed,#6d28d9)"><i class="fas fa-file-invoice-dollar"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $creditOutstanding ?>" data-prefix="<?= currency_sym() ?> " data-decimals="2"><?= currency_sym() ?> 0.00</div>
                <div class="fin-stat-label">Credit Outstanding</div>
                <div class="fin-stat-change <?= $creditOverdue > 0 ? 'down' : 'up' ?>"><i class="fas fa-arrow-<?= $creditOverdue > 0 ? 'down' : 'up' ?>"></i> <?= $creditOverdue > 0 ? $creditOverdue . ' overdue' : 'No overdue' ?><?= $creditPending > 0 ? ' · ' . $creditPending . ' pending' : '' ?></div>
            </div>
        </div>
        <?php endif; ?>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#0891b2,#0e7490)"><i class="fas fa-exchange-alt"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $netCashFlow ?>" data-prefix="<?= currency_sym() ?> " data-decimals="2"><?= currency_sym() ?> 0.00</div>
                <div class="fin-stat-label">Net Cash Flow</div>
                <div class="fin-stat-change <?= $netCashFlow >= 0 ? 'up' : 'down' ?>"><i class="fas fa-arrow-<?= $netCashFlow >= 0 ? 'up' : 'down' ?>"></i> <?= round(abs($netCashFlow) / max($cashOutflow, 1) * 100, 1) ?>%</div>
            </div>
        </div>
    </div>

    <div class="fin-quick-actions">
        <a href="?page=finance_payments" class="fin-qa"><i style="background:var(--fin-green)"><i class="fas fa-check-circle"></i></i> Receive Payment</a>
        <a href="?page=supplier_payments" class="fin-qa"><i style="background:var(--fin-blue)"><i class="fas fa-building"></i></i> Supplier Payment</a>
        <a href="?page=finance_bank" class="fin-qa"><i style="background:var(--fin-teal)"><i class="fas fa-exchange-alt"></i></i> Bank Transfer</a>
        <a href="?page=finance_bank" class="fin-qa"><i style="background:var(--fin-purple)"><i class="fas fa-download"></i></i> Deposit</a>
        <a href="?page=finance_cash" class="fin-qa"><i style="background:var(--fin-orange)"><i class="fas fa-upload"></i></i> Withdraw</a>
        <a href="?page=finance_expenses" class="fin-qa"><i style="background:var(--fin-red)"><i class="fas fa-plus-circle"></i></i> Add Expense</a>
        <a href="?page=finance_reports" class="fin-qa"><i style="background:var(--fin-indigo)"><i class="fas fa-file-alt"></i></i> Generate Report</a>
    </div>

    <div class="fin-grid-2" style="margin-bottom:16px">
        <div class="fin-card">
            <h2><i class="fas fa-bell" style="color:var(--fin-orange)"></i> Financial Notifications</h2>
            <?php if (empty($notifications)): ?>
                <div class="fin-empty" style="padding:20px"><i class="fas fa-check-circle" style="color:var(--fin-green)"></i>All clear — no financial notifications.</div>
            <?php else: ?>
                <?php foreach ($notifications as $n): ?>
                    <div class="fin-notif fin-notif-<?= $n['severity'] ?? 'info' ?>">
                        <i class="fas fa-<?= $n['severity'] === 'critical' ? 'exclamation-triangle' : ($n['severity'] === 'warning' ? 'exclamation-circle' : 'info-circle') ?>"></i>
                        <div><strong><?= htmlspecialchars($n['title']) ?></strong><br><span style="opacity:.85"><?= htmlspecialchars($n['message']) ?></span></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="fin-card">
            <h2><i class="fas fa-history" style="color:var(--fin-blue)"></i> Recent Financial Transactions</h2>
            <?php if (empty($recentTransactions)): ?>
                <div class="fin-empty" style="padding:20px"><i class="fas fa-inbox"></i>No transactions recorded.</div>
            <?php else: ?>
                <div style="overflow-x:auto">
                <table class="fin-table">
                    <thead><tr><th>Type</th><th>Entity</th><th>Amount</th><th>Date</th></tr></thead>
                    <tbody>
                        <?php foreach ($recentTransactions as $t): ?>
                            <tr>
                                <td><span class="fd-trans-type <?= $t['type'] ?>"><?= ucfirst($t['type']) ?></span></td>
                                <td style="font-size:.82rem;color:#475569;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($t['entity'] ?? '—') ?></td>
                                <td style="font-weight:700;color:<?= $t['type'] === 'payment' ? 'var(--fin-green)' : 'var(--fin-red)' ?>"><?= finFmt((float)$t['amount']) ?></td>
                                <td style="color:var(--fin-gray);font-size:.78rem"><?= date('M d, H:i', strtotime($t['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="fd-chart-wrap">
        <div class="fd-chart">
            <h3><i class="fas fa-chart-bar" style="color:var(--fin-blue)"></i> Revenue vs Expenses (12 months)</h3>
            <div class="fd-bar-chart">
                <?php for ($i = 0; $i < 12; $i++): ?>
                    <?php $rv = $monthlyRevenue[$i]['value'] ?? 0; $ev = $monthlyExpenses[$i]['value'] ?? 0; ?>
                    <?php $rh = max(round(($rv / $maxChartVal) * 140), 2); $eh = max(round(($ev / $maxChartVal) * 140), 2); ?>
                    <div class="fd-bar-group">
                        <div style="display:flex;flex-direction:column;align-items:center;gap:1px;flex:1;justify-content:flex-end">
                            <div class="fd-bar rev" style="height:<?= $rh ?>px" title="Revenue: <?= finFmt($rv) ?>"></div>
                            <div class="fd-bar exp" style="height:<?= $eh ?>px" title="Expenses: <?= finFmt($ev) ?>"></div>
                        </div>
                        <div class="fd-bar-label"><?= date('M', strtotime($monthlyRevenue[$i]['label'])) ?></div>
                    </div>
                <?php endfor; ?>
            </div>
            <div class="fd-bar-legend">
                <span><i style="background:#2563eb"></i> Revenue</span>
                <span><i style="background:#dc2626"></i> Expenses</span>
            </div>
            <div class="fd-cash-flow">
                <div><div class="fd-cf-val" style="color:var(--fin-green)"><?= finFmt($cashInflow) ?></div><div class="fd-cf-label">Total Inflow</div></div>
                <div><div class="fd-cf-val" style="color:var(--fin-red)"><?= finFmt($cashOutflow) ?></div><div class="fd-cf-label">Total Outflow</div></div>
                <div><div class="fd-cf-val" style="color:<?= $netCashFlow >= 0 ? 'var(--fin-green)' : 'var(--fin-red)' ?>"><?= finFmt($netCashFlow) ?></div><div class="fd-cf-label">Net Cash Flow</div></div>
            </div>
        </div>

        <div class="fd-chart">
            <h3><i class="fas fa-hand-holding-usd" style="color:var(--fin-green)"></i> Monthly Collections (12 months)</h3>
            <div class="fd-bar-chart" style="height:140px">
                <?php foreach ($monthlyCollections as $mc): ?>
                    <?php $ch = max(round(($mc['value'] / max($maxColVal, 1)) * 130), 2); ?>
                    <div class="fd-bar-group">
                        <div class="fd-bar col" style="height:<?= $ch ?>px" title="Collected: <?= finFmt($mc['value']) ?>"></div>
                        <div class="fd-bar-label"><?= date('M', strtotime($mc['label'])) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="fd-bar-legend">
                <span><i style="background:#059669"></i> Collections</span>
                <span style="color:#64748b">Total: <?= finFmt(array_sum(array_column($monthlyCollections, 'value'))) ?></span>
            </div>
        </div>
    </div>
</section>
<?= finJs() ?>
