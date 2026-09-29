<?php
require_once __DIR__ . '/../includes/finance_helpers.php';
ensureFinanceErpMigration(finGetDb());
$pdo = finGetDb();
requirePermission('finance_reports', 'view');
$isAdminUser = isAdmin();

$activeTab = $_GET['tab'] ?? 'daily_sales';
$validTabs = [
    'daily_sales','monthly_sales','profit','expense','outstanding_customers',
    'outstanding_suppliers','bank','cash','tax','vat','credit','payment','refund'
];
$activeTab = in_array($activeTab, $validTabs) ? $activeTab : 'daily_sales';
if (!$isAdminUser && $activeTab === 'profit') $activeTab = 'daily_sales';

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo   = $_GET['date_to'] ?? date('Y-m-d');

$monthRev   = finGetMonthRevenue($pdo);
$monthExp   = finGetMonthExpenses($pdo);
$monthInc   = finGetMonthIncome($pdo);
$outRec     = finGetOutstandingReceivables($pdo);
$outPay     = finGetOutstandingPayables($pdo);
$bankBal    = finGetBankBalance($pdo);
$cashBal    = finGetCashBalance($pdo);
$netProfit  = $monthRev + $monthInc - $monthExp;
$vatRate    = SettingsService::vatRate();

$monthRevPrev  = finGetMonthRevenuePrev($pdo);
$monthExpPrev  = finGetMonthExpensesPrev($pdo);
$chRev  = finPctChange($monthRev, $monthRevPrev);
$chExp  = finPctChange($monthExp, $monthExpPrev);

$tabReports = [
    'daily_sales'          => ['icon' => 'calendar-day', 'label' => 'Daily Sales'],
    'monthly_sales'        => ['icon' => 'calendar-alt', 'label' => 'Monthly Sales'],
    'profit'               => ['icon' => 'chart-line', 'label' => 'Profit Report'],
    'expense'              => ['icon' => 'receipt', 'label' => 'Expense Report'],
    'outstanding_customers'=> ['icon' => 'user-clock', 'label' => 'Outstanding Customers'],
    'outstanding_suppliers'=> ['icon' => 'building', 'label' => 'Outstanding Suppliers'],
    'bank'                 => ['icon' => 'university', 'label' => 'Bank Report'],
    'cash'                 => ['icon' => 'money-bill-wave', 'label' => 'Cash Report'],
    'tax'                  => ['icon' => 'percentage', 'label' => 'Tax Report'],
    'vat'                  => ['icon' => 'file-invoice', 'label' => 'VAT Report'],
    'credit'               => ['icon' => 'credit-card', 'label' => 'Credit Report'],
    'payment'              => ['icon' => 'hand-holding-usd', 'label' => 'Payment Report'],
    'refund'               => ['icon' => 'undo-alt', 'label' => 'Refund Report'],
];
if (!$isAdminUser) unset($tabReports['profit']);

$exportUrl = '?page=finance_reports&tab=' . urlencode($activeTab) . '&date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo);
?>
<?= finCss() ?>
<style>
@media print{
    .fin-tabs,.fin-toolbar,.fin-no-print,.export-btn-wrap,.fin-btn{display:none!important}
    .fin-panel{display:block!important;page-break-inside:avoid}
    .fin-wrap{padding:0!important}
    .fin-card{box-shadow:none;border:1px solid #ccc}
    body{font-size:11px}
    .fin-table th,.fin-table td{padding:4px 6px;font-size:.72rem}
    .fin-print-head{display:flex!important}
}
.fin-print-head{display:none;align-items:center;gap:14px;border-bottom:2px solid var(--fin-blue);padding-bottom:10px;margin-bottom:14px}
</style>
<div class="fin-print-head">
    <img src="<?= htmlspecialchars(brand_logo()) ?>" alt="Logo" style="height:52px;width:auto;max-width:100px;object-fit:contain">
    <div>
        <div style="font-size:1.05rem;font-weight:800;color:var(--fin-blue)"><?= htmlspecialchars(company_name()) ?></div>
        <div style="font-size:.8rem;color:#475569">Finance Report · Printed <?= htmlspecialchars(date('M j, Y H:i')) ?></div>
    </div>
</div>

<section class="fin-wrap">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:6px">
        <div>
            <h1><i class="fas fa-file-invoice-dollar" style="color:var(--fin-blue)"></i> Finance Reports</h1>
            <div class="fin-subtitle">Comprehensive financial reports and analytics</div>
        </div>
        <div class="export-btn-wrap fin-no-print" style="display:flex;gap:8px">
            <?php if (hasPermission('finance_reports', 'export')): ?>
            <button onclick="window.print()" class="fin-btn fin-btn-outline"><i class="fas fa-print"></i> Print</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="fin-stats">
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#059669,#047857)"><i class="fas fa-chart-area"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $monthRev ?>" data-prefix="<?= htmlspecialchars(currency_sym()) ?> " data-decimals="2"><?= htmlspecialchars(currency_sym()) ?> 0.00</div>
                <div class="fin-stat-label">Total Revenue (Month)</div>
                <div class="fin-stat-change <?= $chRev['dir'] ?>"><i class="fas fa-arrow-<?= $chRev['dir']==='up'?'up':($chRev['dir']==='down'?'down':'right') ?>"></i> <?= $chRev['pct'] ?>%</div>
            </div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#dc2626,#b91c1c)"><i class="fas fa-receipt"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $monthExp ?>" data-prefix="<?= htmlspecialchars(currency_sym()) ?> " data-decimals="2"><?= htmlspecialchars(currency_sym()) ?> 0.00</div>
                <div class="fin-stat-label">Total Expenses (Month)</div>
                <div class="fin-stat-change <?= $chExp['dir'] ?>"><i class="fas fa-arrow-<?= $chExp['dir']==='up'?'up':($chExp['dir']==='down'?'down':'right') ?>"></i> <?= $chExp['pct'] ?>%</div>
            </div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#059669,#16a34a)"><i class="fas fa-hand-holding-usd"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $netProfit ?>" data-prefix="<?= htmlspecialchars(currency_sym()) ?> " data-decimals="2"><?= htmlspecialchars(currency_sym()) ?> 0.00</div>
                <div class="fin-stat-label">Net Profit (Month)</div>
            </div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#d97706,#b45309)"><i class="fas fa-file-invoice-dollar"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $outRec ?>" data-prefix="<?= htmlspecialchars(currency_sym()) ?> " data-decimals="2"><?= htmlspecialchars(currency_sym()) ?> 0.00</div>
                <div class="fin-stat-label">Outstanding Balance</div>
            </div>
        </div>
    </div>

    <div class="fin-toolbar">
        <?php foreach ($tabReports as $key => $info): ?>
            <a href="?page=finance_reports&tab=<?= $key ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>"
               class="fin-tab <?= $activeTab === $key ? 'active' : '' ?>"><i class="fas fa-<?= $info['icon'] ?>"></i> <?= $info['label'] ?></a>
        <?php endforeach; ?>
    </div>

    <form method="get" class="fin-toolbar fin-no-print" style="margin-bottom:14px">
        <input type="hidden" name="page" value="finance_reports">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
        <label style="font-size:.8rem;font-weight:600;color:var(--fin-gray)">From</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
        <label style="font-size:.8rem;font-weight:600;color:var(--fin-gray)">To</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
        <button type="submit" class="fin-btn fin-btn-blue"><i class="fas fa-filter"></i> Filter</button>
    </form>

<?php
if ($activeTab === 'daily_sales'):
    $dailySales = $pdo->prepare("
        SELECT co.id, co.created_at, co.total_amount, co.payment_method, co.payment_confirmed, c.name AS customer_name
        FROM customer_orders co
        LEFT JOIN customers c ON co.customer_id = c.id
        WHERE DATE(co.created_at) = CURDATE() AND co.status != 'cancelled'
        ORDER BY co.created_at DESC
    ");
    $dailySales->execute();
    $dsRows = $dailySales->fetchAll();

    $dsTotal = 0; $dsConfirmed = 0; $dsPending = 0; $dsCount = count($dsRows);
    $dsMethods = [];
    $hourlyTrend = array_fill(0, 24, 0);
    foreach ($dsRows as $r) {
        $amt = (float) $r['total_amount'];
        $dsTotal += $amt;
        if ($r['payment_confirmed']) $dsConfirmed += $amt; else $dsPending += $amt;
        $pm = $r['payment_method'] ?? 'other';
        $dsMethods[$pm] = ($dsMethods[$pm] ?? 0) + $amt;
        $hour = (int) date('H', strtotime($r['created_at']));
        $hourlyTrend[$hour] += $amt;
    }
?>
    <div class="fin-card">
        <h2><i class="fas fa-calendar-day" style="color:var(--fin-blue)"></i> Daily Sales Report — <?= date('l, F j, Y') ?></h2>
        <div class="fin-kpi-row">
            <div class="fin-kpi"><div class="fin-kpi-val"><?= finFmt($dsTotal) ?></div><div class="fin-kpi-label">Today's Sales</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val"><?= $dsCount ?></div><div class="fin-kpi-label">Transactions</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val" style="color:var(--fin-green)"><?= finFmt($dsConfirmed) ?></div><div class="fin-kpi-label">Confirmed</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val" style="color:var(--fin-orange)"><?= finFmt($dsPending) ?></div><div class="fin-kpi-label">Pending</div></div>
        </div>
    </div>

    <?php if (!empty($dsMethods)): ?>
    <div class="fin-card">
        <h2><i class="fas fa-credit-card" style="color:var(--fin-purple)"></i> Payment Method Breakdown</h2>
        <div style="display:flex;gap:16px;flex-wrap:wrap">
            <?php foreach ($dsMethods as $method => $amt): ?>
                <div style="padding:14px 20px;background:#f8fafc;border:1px solid var(--fin-border);border-radius:10px;text-align:center;min-width:120px">
                    <div style="font-size:.72rem;color:var(--fin-gray);font-weight:600;text-transform:uppercase"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $method))) ?></div>
                    <div style="font-size:1.1rem;font-weight:800;color:#0f172a;margin-top:4px"><?= finFmt($amt) ?></div>
                    <div style="font-size:.68rem;color:var(--fin-gray)"><?= $dsTotal > 0 ? round(($amt / $dsTotal) * 100, 1) : 0 ?>%</div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="fin-card">
        <h2><i class="fas fa-clock" style="color:var(--fin-orange)"></i> Hourly Trend</h2>
        <div style="display:flex;align-items:flex-end;gap:3px;height:120px;padding-top:10px">
            <?php
            $maxHour = max($hourlyTrend) ?: 1;
            foreach ($hourlyTrend as $h => $hAmt):
                $pct = ($hAmt / $maxHour) * 100;
            ?>
                <div title="<?= $h ?>:00 — <?= finFmt($hAmt) ?>" style="flex:1;background:<?= $hAmt > 0 ? 'var(--fin-blue)' : '#e2e8f0' ?>;height:<?= max($pct, 2) ?>%;border-radius:3px 3px 0 0;transition:height .3s;min-width:0"></div>
            <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:3px;margin-top:4px">
            <?php foreach (range(0, 23) as $h): ?>
                <div style="flex:1;text-align:center;font-size:.55rem;color:var(--fin-gray)"><?= $h % 3 === 0 ? $h . 'h' : '' ?></div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="fin-card">
        <h2><i class="fas fa-list" style="color:var(--fin-teal)"></i> Today's Transactions</h2>
        <?php if (empty($dsRows)): ?>
            <div class="fin-empty"><i class="fas fa-inbox"></i>No sales recorded today.</div>
        <?php else: ?>
            <div style="overflow-x:auto">
            <table class="fin-table">
                <thead><tr><th>Time</th><th>Order #</th><th>Customer</th><th>Amount</th><th>Method</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($dsRows as $r): ?>
                        <tr>
                            <td style="color:var(--fin-gray);font-size:.78rem"><?= date('H:i', strtotime($r['created_at'])) ?></td>
                            <td style="font-weight:700">#<?= $r['id'] ?></td>
                            <td style="font-weight:600"><?= htmlspecialchars($r['customer_name'] ?? 'Walk-in') ?></td>
                            <td style="font-weight:700"><?= finFmt((float) $r['total_amount']) ?></td>
                            <td><span class="fin-badge fin-badge-blue"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $r['payment_method'] ?? 'N/A'))) ?></span></td>
                            <td>
                                <?php if ($r['payment_confirmed']): ?>
                                    <span class="fin-badge fin-badge-green"><i class="fas fa-check"></i> Confirmed</span>
                                <?php else: ?>
                                    <span class="fin-badge fin-badge-orange"><i class="fas fa-clock"></i> Pending</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($activeTab === 'monthly_sales'):
    $msRows = $pdo->prepare("
        SELECT DATE(co.created_at) AS sale_date, co.total_amount, co.payment_method, co.payment_confirmed
        FROM customer_orders co
        WHERE co.created_at BETWEEN :df AND :dt AND co.status != 'cancelled'
        ORDER BY sale_date ASC
    ");
    $msRows->execute(['df' => $dateFrom . ' 00:00:00', 'dt' => $dateTo . ' 23:59:59']);
    $msData = $msRows->fetchAll();

    $msTotal = 0; $msConfirmed = 0; $msPending = 0; $msCount = count($msData);
    $msMethods = []; $msDaily = [];
    foreach ($msData as $r) {
        $amt = (float) $r['total_amount'];
        $msTotal += $amt;
        if ($r['payment_confirmed']) $msConfirmed += $amt; else $msPending += $amt;
        $pm = $r['payment_method'] ?? 'other';
        $msMethods[$pm] = ($msMethods[$pm] ?? 0) + $amt;
        $day = $r['sale_date'];
        $msDaily[$day] = ($msDaily[$day] ?? 0) + $amt;
    }
?>
    <div class="fin-card">
        <h2><i class="fas fa-calendar-alt" style="color:var(--fin-blue)"></i> Monthly Sales Report — <?= date('F Y') ?></h2>
        <div class="fin-kpi-row">
            <div class="fin-kpi"><div class="fin-kpi-val"><?= finFmt($msTotal) ?></div><div class="fin-kpi-label">Total Sales</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val"><?= $msCount ?></div><div class="fin-kpi-label">Transactions</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val" style="color:var(--fin-green)"><?= finFmt($msConfirmed) ?></div><div class="fin-kpi-label">Confirmed</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val" style="color:var(--fin-orange)"><?= finFmt($msPending) ?></div><div class="fin-kpi-label">Pending</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val"><?= $msTotal > 0 ? finFmt($msTotal / max(1, count($msDaily))) : finFmt(0) ?></div><div class="fin-kpi-label">Avg Daily</div></div>
        </div>
    </div>

    <?php if (!empty($msMethods)): ?>
    <div class="fin-card">
        <h2><i class="fas fa-credit-card" style="color:var(--fin-purple)"></i> Payment Method Breakdown</h2>
        <div style="overflow-x:auto">
        <table class="fin-table">
            <thead><tr><th>Method</th><th>Amount</th><th>% of Total</th><th style="width:120px">Share</th></tr></thead>
            <tbody>
                <?php foreach ($msMethods as $method => $amt):
                    $pct = $msTotal > 0 ? ($amt / $msTotal) * 100 : 0;
                ?>
                    <tr>
                        <td style="font-weight:600"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $method))) ?></td>
                        <td style="font-weight:700"><?= finFmt($amt) ?></td>
                        <td><?= round($pct, 1) ?>%</td>
                        <td><div class="fin-progress-wrap"><div class="fin-progress-bar" style="width:<?= $pct ?>%;background:var(--fin-blue)"></div></div></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="fin-card">
        <h2><i class="fas fa-chart-bar" style="color:var(--fin-teal)"></i> Daily Sales Trend</h2>
        <?php if (empty($msDaily)): ?>
            <div class="fin-empty"><i class="fas fa-inbox"></i>No sales data for this period.</div>
        <?php else: ?>
            <div style="overflow-x:auto">
            <table class="fin-table">
                <thead><tr><th>Date</th><th>Day</th><th>Sales</th><th style="width:200px">Bar</th></tr></thead>
                <tbody>
                    <?php
                    $maxDay = max($msDaily) ?: 1;
                    foreach ($msDaily as $day => $dayAmt):
                        $pctBar = ($dayAmt / $maxDay) * 100;
                    ?>
                        <tr>
                            <td style="font-weight:600"><?= date('M d', strtotime($day)) ?></td>
                            <td style="color:var(--fin-gray);font-size:.78rem"><?= date('l', strtotime($day)) ?></td>
                            <td style="font-weight:700"><?= finFmt($dayAmt) ?></td>
                            <td><div class="fin-progress-wrap"><div class="fin-progress-bar" style="width:<?= $pctBar ?>%;background:linear-gradient(90deg,#2563eb,#3b82f6)"></div></div></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($activeTab === 'profit'):
    $profRev = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM customer_orders WHERE created_at BETWEEN :df AND :dt AND status != 'cancelled'");
    $profRev->execute(['df' => $dateFrom . ' 00:00:00', 'dt' => $dateTo . ' 23:59:59']);
    $totalRevenue = (float) $profRev->fetchColumn();

    $profExp = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date BETWEEN :df AND :dt");
    $profExp->execute(['df' => $dateFrom, 'dt' => $dateTo]);
    $totalExpenses = (float) $profExp->fetchColumn();

    $profInc = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM income WHERE income_date BETWEEN :df AND :dt");
    $profInc->execute(['df' => $dateFrom, 'dt' => $dateTo]);
    $totalIncome = (float) $profInc->fetchColumn();

    $profRef = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE refunded_at BETWEEN :df AND :dt");
    $profRef->execute(['df' => $dateFrom . ' 00:00:00', 'dt' => $dateTo . ' 23:59:59']);
    $totalRefunds = (float) $profRef->fetchColumn();

    $netRevenue = $totalRevenue - $totalRefunds;
    $grossProfit = $netRevenue - $totalExpenses;
    $netProfitP = $netRevenue + $totalIncome - $totalExpenses;
    $profitMargin = $netRevenue > 0 ? ($grossProfit / $netRevenue) * 100 : 0;

    $profExpByCat = $pdo->prepare("SELECT expense_category, COALESCE(SUM(amount), 0) AS total FROM expenses WHERE expense_date BETWEEN :df AND :dt GROUP BY expense_category ORDER BY total DESC");
    $profExpByCat->execute(['df' => $dateFrom, 'dt' => $dateTo]);
    $expByCat = $profExpByCat->fetchAll();
?>
    <div class="fin-card">
        <h2><i class="fas fa-chart-line" style="color:var(--fin-green)"></i> Profit Report — <?= date('M d', strtotime($dateFrom)) ?> to <?= date('M d', strtotime($dateTo)) ?></h2>
        <div class="fin-kpi-row">
            <div class="fin-kpi" style="border-left:3px solid var(--fin-blue)"><div class="fin-kpi-val" style="color:var(--fin-blue)"><?= finFmt($totalRevenue) ?></div><div class="fin-kpi-label">Gross Revenue</div></div>
            <div class="fin-kpi" style="border-left:3px solid var(--fin-red)"><div class="fin-kpi-val" style="color:var(--fin-red)">- <?= finFmt($totalRefunds) ?></div><div class="fin-kpi-label">Refunds</div></div>
            <div class="fin-kpi" style="border-left:3px solid var(--fin-orange)"><div class="fin-kpi-val" style="color:var(--fin-orange)">- <?= finFmt($totalExpenses) ?></div><div class="fin-kpi-label">Total Expenses</div></div>
            <div class="fin-kpi" style="border-left:3px solid var(--fin-green)"><div class="fin-kpi-val" style="color:var(--fin-green)"><?= finFmt($grossProfit) ?></div><div class="fin-kpi-label">Gross Profit</div></div>
            <div class="fin-kpi" style="border-left:3px solid var(--fin-purple)"><div class="fin-kpi-val" style="color:var(--fin-purple)">+ <?= finFmt($totalIncome) ?></div><div class="fin-kpi-label">Other Income</div></div>
            <div class="fin-kpi" style="border-left:3px solid <?= $netProfitP >= 0 ? 'var(--fin-green)' : 'var(--fin-red)' ?>;background:<?= $netProfitP >= 0 ? '#f0fdf4' : '#fef2f2' ?>"><div class="fin-kpi-val" style="color:<?= $netProfitP >= 0 ? 'var(--fin-green)' : 'var(--fin-red)' ?>"><?= finFmt($netProfitP) ?></div><div class="fin-kpi-label">Net Profit</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val"><?= round($profitMargin, 1) ?>%</div><div class="fin-kpi-label">Profit Margin</div></div>
        </div>
    </div>

    <div class="fin-card">
        <h2><i class="fas fa-calculator" style="color:var(--fin-teal)"></i> Profit Formula</h2>
        <div style="background:#f8fafc;border-radius:10px;padding:20px;font-size:.9rem;line-height:2">
            <div><span style="color:var(--fin-gray)">Gross Revenue:</span> <strong><?= finFmt($totalRevenue) ?></strong></div>
            <div><span style="color:var(--fin-gray)">Less Refunds:</span> <strong style="color:var(--fin-red)">- <?= finFmt($totalRefunds) ?></strong></div>
            <div style="border-bottom:2px solid var(--fin-border);padding-bottom:6px"><span style="color:var(--fin-gray)">Net Revenue:</span> <strong><?= finFmt($netRevenue) ?></strong></div>
            <div><span style="color:var(--fin-gray)">Less Expenses (COGS + OpEx):</span> <strong style="color:var(--fin-red)">- <?= finFmt($totalExpenses) ?></strong></div>
            <div style="border-bottom:2px solid var(--fin-border);padding-bottom:6px"><span style="color:var(--fin-gray)">Gross Profit:</span> <strong style="color:<?= $grossProfit >= 0 ? 'var(--fin-green)' : 'var(--fin-red)' ?>"><?= finFmt($grossProfit) ?></strong></div>
            <div><span style="color:var(--fin-gray)">Add Other Income:</span> <strong style="color:var(--fin-green)">+ <?= finFmt($totalIncome) ?></strong></div>
            <div style="font-size:1.1rem"><span style="font-weight:800">Net Profit:</span> <strong style="color:<?= $netProfitP >= 0 ? 'var(--fin-green)' : 'var(--fin-red)' ?>"><?= finFmt($netProfitP) ?></strong></div>
        </div>
    </div>

    <?php if (!empty($expByCat)): ?>
    <div class="fin-card">
        <h2><i class="fas fa-layer-group" style="color:var(--fin-orange)"></i> Expenses by Category</h2>
        <div style="overflow-x:auto">
        <table class="fin-table">
            <thead><tr><th>Category</th><th>Amount</th><th>% of Expenses</th><th style="width:150px">Share</th></tr></thead>
            <tbody>
                <?php foreach ($expByCat as $ec): ?>
                    <tr>
                        <td style="font-weight:600"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $ec['expense_category']))) ?></td>
                        <td style="font-weight:700"><?= finFmt((float) $ec['total']) ?></td>
                        <td><?= $totalExpenses > 0 ? round(((float) $ec['total'] / $totalExpenses) * 100, 1) : 0 ?>%</td>
                        <td><div class="fin-progress-wrap"><div class="fin-progress-bar" style="width:<?= $totalExpenses > 0 ? round(((float) $ec['total'] / $totalExpenses) * 100) : 0 ?>%;background:var(--fin-orange)"></div></div></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td><strong>Total</strong></td><td><strong><?= finFmt($totalExpenses) ?></strong></td><td><strong>100%</strong></td><td></td></tr></tfoot>
        </table>
        </div>
    </div>
    <?php endif; ?>

<?php elseif ($activeTab === 'expense'):
    $expRows = $pdo->prepare("
        SELECT e.*, u.full_name AS created_by_name
        FROM expenses e
        LEFT JOIN users u ON e.created_by = u.id
        WHERE e.expense_date BETWEEN :df AND :dt
        ORDER BY e.expense_date DESC, e.id DESC
    ");
    $expRows->execute(['df' => $dateFrom, 'dt' => $dateTo]);
    $expenses = $expRows->fetchAll();

    $expTotal = 0; $expByCat = []; $expMethods = [];
    foreach ($expenses as $e) {
        $amt = (float) $e['amount'];
        $expTotal += $amt;
        $cat = $e['expense_category'] ?? 'other';
        $expByCat[$cat] = ($expByCat[$cat] ?? 0) + $amt;
        $pm = $e['payment_method'] ?? 'other';
        $expMethods[$pm] = ($expMethods[$pm] ?? 0) + $amt;
    }
    arsort($expByCat);
?>
    <div class="fin-card">
        <h2><i class="fas fa-receipt" style="color:var(--fin-red)"></i> Expense Report — <?= date('M d', strtotime($dateFrom)) ?> to <?= date('M d', strtotime($dateTo)) ?></h2>
        <div class="fin-kpi-row">
            <div class="fin-kpi"><div class="fin-kpi-val" style="color:var(--fin-red)"><?= finFmt($expTotal) ?></div><div class="fin-kpi-label">Total Expenses</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val"><?= count($expenses) ?></div><div class="fin-kpi-label">Transactions</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val"><?= count($expByCat) ?></div><div class="fin-kpi-label">Categories</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val"><?= $expTotal > 0 ? finFmt($expTotal / max(1, count($expenses))) : finFmt(0) ?></div><div class="fin-kpi-label">Avg per Expense</div></div>
        </div>
    </div>

    <?php if (!empty($expByCat)): ?>
    <div class="fin-card">
        <h2><i class="fas fa-chart-pie" style="color:var(--fin-purple)"></i> By Category</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px">
            <?php foreach ($expByCat as $cat => $catAmt):
                $pctCat = $expTotal > 0 ? ($catAmt / $expTotal) * 100 : 0;
            ?>
                <div style="background:#f8fafc;border-radius:10px;padding:14px;text-align:center;border:1px solid var(--fin-border)">
                    <div style="font-size:.7rem;color:var(--fin-gray);font-weight:600;text-transform:uppercase"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $cat))) ?></div>
                    <div style="font-size:1rem;font-weight:800;margin-top:4px"><?= finFmt($catAmt) ?></div>
                    <div style="font-size:.68rem;color:var(--fin-gray)"><?= round($pctCat, 1) ?>%</div>
                    <div class="fin-progress-wrap" style="margin-top:6px"><div class="fin-progress-bar" style="width:<?= $pctCat ?>%;background:var(--fin-red)"></div></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="fin-card">
        <h2><i class="fas fa-list" style="color:var(--fin-blue)"></i> Expense Details</h2>
        <?php if (empty($expenses)): ?>
            <div class="fin-empty"><i class="fas fa-inbox"></i>No expenses recorded for this period.</div>
        <?php else: ?>
            <div style="overflow-x:auto">
            <table class="fin-table">
                <thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Method</th><th>Amount</th><th>Recorded By</th></tr></thead>
                <tbody>
                    <?php foreach ($expenses as $e): ?>
                        <tr>
                            <td style="color:var(--fin-gray);font-size:.78rem"><?= date('M d, Y', strtotime($e['expense_date'])) ?></td>
                            <td><span class="fin-badge fin-badge-red"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $e['expense_category']))) ?></span></td>
                            <td style="max-width:250px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($e['description'] ?? '—') ?></td>
                            <td><span class="fin-badge fin-badge-blue"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $e['payment_method'] ?? 'other'))) ?></span></td>
                            <td style="font-weight:700;color:var(--fin-red)"><?= finFmt((float) $e['amount']) ?></td>
                            <td style="font-size:.78rem"><?= htmlspecialchars($e['created_by_name'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="4" style="text-align:right;font-weight:700">Total</td><td style="font-weight:800;color:var(--fin-red)"><?= finFmt($expTotal) ?></td><td></td></tr></tfoot>
            </table>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($activeTab === 'outstanding_customers'):
    $ocRows = $pdo->prepare("
        SELECT i.id, i.invoice_no, i.total_amount, i.outstanding_balance, i.credit_due_date, i.payment_status,
               i.generated_at AS created_at, c.name AS customer_name, c.phone AS customer_phone
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        WHERE i.payment_status IN ('unpaid', 'partial')
        ORDER BY i.credit_due_date ASC, i.outstanding_balance DESC
    ");
    $ocRows->execute();
    $ocData = $ocRows->fetchAll();

    $ocTotal = 0; $ocOverdue = 0; $ocCurrent = 0; $oc30 = 0; $oc60 = 0; $oc90 = 0;
    $today = new DateTime();
    foreach ($ocData as $r) {
        $bal = (float) $r['outstanding_balance'];
        $ocTotal += $bal;
        if (!empty($r['credit_due_date'])) {
            $due = new DateTime($r['credit_due_date']);
            $diff = (int) $today->diff($due)->days;
            if ($due < $today) { $ocOverdue += $bal; $oc90 += ($diff > 90 ? $bal : 0); $oc60 += ($diff > 60 && $diff <= 90 ? $bal : 0); $oc30 += ($diff > 30 && $diff <= 60 ? $bal : 0); }
            else { $ocCurrent += $bal; }
        }
    }
?>
    <div class="fin-card">
        <h2><i class="fas fa-user-clock" style="color:var(--fin-orange)"></i> Outstanding Customers</h2>
        <div class="fin-kpi-row">
            <div class="fin-kpi" style="border-left:3px solid var(--fin-orange)"><div class="fin-kpi-val"><?= finFmt($ocTotal) ?></div><div class="fin-kpi-label">Total Outstanding</div></div>
            <div class="fin-kpi" style="border-left:3px solid var(--fin-red)"><div class="fin-kpi-val" style="color:var(--fin-red)"><?= finFmt($ocOverdue) ?></div><div class="fin-kpi-label">Overdue</div></div>
            <div class="fin-kpi" style="border-left:3px solid var(--fin-green)"><div class="fin-kpi-val" style="color:var(--fin-green)"><?= finFmt($ocCurrent) ?></div><div class="fin-kpi-label">Current</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val"><?= count($ocData) ?></div><div class="fin-kpi-label">Unpaid Invoices</div></div>
        </div>
        <div class="fin-kpi-row">
            <div class="fin-kpi" style="background:#fef2f2"><div class="fin-kpi-val" style="font-size:.9rem;color:#991b1b"><?= finFmt($oc30) ?></div><div class="fin-kpi-label">31-60 Days</div></div>
            <div class="fin-kpi" style="background:#fef2f2"><div class="fin-kpi-val" style="font-size:.9rem;color:#991b1b"><?= finFmt($oc60) ?></div><div class="fin-kpi-label">61-90 Days</div></div>
            <div class="fin-kpi" style="background:#fef2f2"><div class="fin-kpi-val" style="font-size:.9rem;color:#991b1b"><?= finFmt($oc90) ?></div><div class="fin-kpi-label">90+ Days</div></div>
        </div>
    </div>

    <div class="fin-card">
        <h2><i class="fas fa-users" style="color:var(--fin-blue)"></i> Outstanding Invoices</h2>
        <?php if (empty($ocData)): ?>
            <div class="fin-empty"><i class="fas fa-check-circle" style="color:var(--fin-green)"></i>No outstanding invoices!</div>
        <?php else: ?>
            <div style="overflow-x:auto">
            <table class="fin-table">
                <thead><tr><th>Invoice</th><th>Customer</th><th>Total</th><th>Outstanding</th><th>Due Date</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($ocData as $r):
                        $isOverdue = !empty($r['credit_due_date']) && strtotime($r['credit_due_date']) < time();
                    ?>
                        <tr>
                            <td style="font-weight:700"><?= htmlspecialchars($r['invoice_no'] ?? '#' . $r['id']) ?></td>
                            <td style="font-weight:600"><?= htmlspecialchars($r['customer_name'] ?? 'N/A') ?></td>
                            <td><?= finFmt((float) $r['total_amount']) ?></td>
                            <td style="font-weight:800;color:var(--fin-red)"><?= finFmt((float) $r['outstanding_balance']) ?></td>
                            <td style="font-size:.78rem;color:<?= $isOverdue ? 'var(--fin-red)' : 'var(--fin-gray)' ?>"><?= $r['credit_due_date'] ? date('M d, Y', strtotime($r['credit_due_date'])) : '—' ?></td>
                            <td>
                                <?php if ($isOverdue): ?>
                                    <span class="fin-badge fin-badge-red"><i class="fas fa-exclamation-circle"></i> Overdue</span>
                                <?php elseif ($r['payment_status'] === 'partial'): ?>
                                    <span class="fin-badge fin-badge-orange">Partial</span>
                                <?php else: ?>
                                    <span class="fin-badge fin-badge-blue">Unpaid</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="3" style="text-align:right;font-weight:700">Total</td><td style="font-weight:800;color:var(--fin-red)"><?= finFmt($ocTotal) ?></td><td colspan="2"></td></tr></tfoot>
            </table>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($activeTab === 'outstanding_suppliers'):
    $osRows = $pdo->prepare("
        SELECT po.id AS po_id, CONCAT('PO-', po.id) AS po_number, po.total_amount, po.status, po.created_at,
               s.name AS supplier_name, s.email AS supplier_email,
               COALESCE(sp.total_paid, 0) AS total_paid,
               (po.total_amount - COALESCE(sp.total_paid, 0)) AS outstanding
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.id
        LEFT JOIN (SELECT order_id, SUM(amount_paid) AS total_paid FROM supplier_payments GROUP BY order_id) sp ON po.id = sp.order_id
        WHERE po.status IN ('received', 'payment_approved', 'payment_pending')
        AND po.total_amount > COALESCE(sp.total_paid, 0)
        ORDER BY po.created_at ASC
    ");
    $osRows->execute();
    $osData = $osRows->fetchAll();

    $osTotal = 0; $osCount = count($osData);
    foreach ($osData as $r) { $osTotal += (float) $r['outstanding']; }
?>
    <div class="fin-card">
        <h2><i class="fas fa-building" style="color:var(--fin-red)"></i> Outstanding Suppliers</h2>
        <div class="fin-kpi-row">
            <div class="fin-kpi" style="border-left:3px solid var(--fin-red)"><div class="fin-kpi-val" style="color:var(--fin-red)"><?= finFmt($osTotal) ?></div><div class="fin-kpi-label">Total Payable</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val"><?= $osCount ?></div><div class="fin-kpi-label">Unpaid POs</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val"><?= $osCount > 0 ? finFmt($osTotal / $osCount) : finFmt(0) ?></div><div class="fin-kpi-label">Avg per PO</div></div>
        </div>
    </div>

    <div class="fin-card">
        <h2><i class="fas fa-file-contract" style="color:var(--fin-blue)"></i> Unpaid Purchase Orders</h2>
        <?php if (empty($osData)): ?>
            <div class="fin-empty"><i class="fas fa-check-circle" style="color:var(--fin-green)"></i>All purchase orders are paid!</div>
        <?php else: ?>
            <div style="overflow-x:auto">
            <table class="fin-table">
                <thead><tr><th>PO #</th><th>Supplier</th><th>Total</th><th>Paid</th><th>Outstanding</th><th>Status</th><th>Date</th></tr></thead>
                <tbody>
                    <?php foreach ($osData as $r): ?>
                        <tr>
                            <td style="font-weight:700"><?= htmlspecialchars($r['po_number'] ?? '#' . $r['po_id']) ?></td>
                            <td style="font-weight:600"><?= htmlspecialchars($r['supplier_name'] ?? 'N/A') ?></td>
                            <td><?= finFmt((float) $r['total_amount']) ?></td>
                            <td style="color:var(--fin-green)"><?= finFmt((float) $r['total_paid']) ?></td>
                            <td style="font-weight:800;color:var(--fin-red)"><?= finFmt((float) $r['outstanding']) ?></td>
                            <td><span class="fin-badge fin-badge-orange"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $r['status']))) ?></span></td>
                            <td style="font-size:.78rem;color:var(--fin-gray)"><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="3" style="text-align:right;font-weight:700">Total</td><td></td><td style="font-weight:800;color:var(--fin-red)"><?= finFmt($osTotal) ?></td><td colspan="2"></td></tr></tfoot>
            </table>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($activeTab === 'bank'):
    try {
        $baRows = $pdo->query("SELECT * FROM bank_accounts WHERE status = 'active' ORDER BY account_name")->fetchAll();
    } catch (Exception $e) { $baRows = []; }
    $baTotalBal = 0;
    foreach ($baRows as $ba) { $baTotalBal += (float) $ba['balance']; }

    $btRows = $pdo->prepare("
        SELECT bt.*, ba.account_name
        FROM bank_transactions bt
        LEFT JOIN bank_accounts ba ON bt.bank_account_id = ba.id
        ORDER BY bt.created_at DESC LIMIT 50
    ");
    $btRows->execute();
    $btData = $btRows->fetchAll();

    $btDeposits = 0; $btWithdrawals = 0;
    foreach ($btData as $bt) {
        if ($bt['transaction_type'] === 'deposit' || $bt['transaction_type'] === 'in') $btDeposits += (float) $bt['amount'];
        else $btWithdrawals += (float) $bt['amount'];
    }
?>
    <div class="fin-card">
        <h2><i class="fas fa-university" style="color:var(--fin-teal)"></i> Bank Report</h2>
        <div class="fin-kpi-row">
            <div class="fin-kpi" style="border-left:3px solid var(--fin-teal)"><div class="fin-kpi-val" style="color:var(--fin-teal)"><?= finFmt($baTotalBal) ?></div><div class="fin-kpi-label">Total Bank Balance</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val"><?= count($baRows) ?></div><div class="fin-kpi-label">Active Accounts</div></div>
            <div class="fin-kpi" style="border-left:3px solid var(--fin-green)"><div class="fin-kpi-val" style="color:var(--fin-green)"><?= finFmt($btDeposits) ?></div><div class="fin-kpi-label">Recent Deposits</div></div>
            <div class="fin-kpi" style="border-left:3px solid var(--fin-red)"><div class="fin-kpi-val" style="color:var(--fin-red)"><?= finFmt($btWithdrawals) ?></div><div class="fin-kpi-label">Recent Withdrawals</div></div>
        </div>
    </div>

    <?php if (!empty($baRows)): ?>
    <div class="fin-card">
        <h2><i class="fas fa-credit-card" style="color:var(--fin-blue)"></i> Bank Accounts</h2>
        <div style="overflow-x:auto">
        <table class="fin-table">
            <thead><tr><th>Account Name</th><th>Bank</th><th>Account #</th><th>Type</th><th>Branch</th><th>Balance</th></tr></thead>
            <tbody>
                <?php foreach ($baRows as $ba): ?>
                    <tr>
                        <td style="font-weight:700"><?= htmlspecialchars($ba['account_name']) ?></td>
                        <td><?= htmlspecialchars($ba['bank_name']) ?></td>
                        <td style="font-family:monospace;font-size:.78rem"><?= htmlspecialchars($ba['account_number']) ?></td>
                        <td><span class="fin-badge fin-badge-teal"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $ba['account_type'] ?? 'N/A'))) ?></span></td>
                        <td style="font-size:.78rem"><?= htmlspecialchars($ba['branch'] ?? '—') ?></td>
                        <td style="font-weight:800;color:<?= (float)$ba['balance'] >= 0 ? 'var(--fin-green)' : 'var(--fin-red)' ?>"><?= finFmt((float) $ba['balance']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="5" style="text-align:right;font-weight:700">Total</td><td style="font-weight:800;color:var(--fin-teal)"><?= finFmt($baTotalBal) ?></td></tr></tfoot>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($btRows)): ?>
    <div class="fin-card">
        <h2><i class="fas fa-exchange-alt" style="color:var(--fin-purple)"></i> Recent Transactions (Last 50)</h2>
        <div style="overflow-x:auto">
        <table class="fin-table">
            <thead><tr><th>Date</th><th>Account</th><th>Type</th><th>Amount</th><th>Balance After</th><th>Reference</th><th>Description</th></tr></thead>
            <tbody>
                <?php foreach ($btData as $bt): ?>
                    <tr>
                        <td style="font-size:.78rem;color:var(--fin-gray)"><?= date('M d, H:i', strtotime($bt['created_at'])) ?></td>
                        <td style="font-weight:600"><?= htmlspecialchars($bt['account_name'] ?? '—') ?></td>
                        <td>
                            <?php if (in_array($bt['transaction_type'], ['deposit', 'in'])): ?>
                                <span class="fin-badge fin-badge-green"><i class="fas fa-arrow-down"></i> <?= htmlspecialchars(ucwords($bt['transaction_type'])) ?></span>
                            <?php else: ?>
                                <span class="fin-badge fin-badge-red"><i class="fas fa-arrow-up"></i> <?= htmlspecialchars(ucwords($bt['transaction_type'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="font-weight:700;color:<?= in_array($bt['transaction_type'], ['deposit','in']) ? 'var(--fin-green)' : 'var(--fin-red)' ?>"><?= finFmt((float) $bt['amount']) ?></td>
                        <td><?= finFmt((float) $bt['balance_after']) ?></td>
                        <td style="font-size:.78rem;font-family:monospace"><?= htmlspecialchars($bt['reference_no'] ?? '—') ?></td>
                        <td style="font-size:.78rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($bt['description'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

<?php elseif ($activeTab === 'cash'):
    try {
        $caRows = $pdo->query("SELECT * FROM cash_accounts WHERE status = 'active' ORDER BY account_name")->fetchAll();
    } catch (Exception $e) { $caRows = []; }
    $caTotalBal = 0;
    foreach ($caRows as $ca) { $caTotalBal += (float) $ca['balance']; }

    $ctRows = $pdo->prepare("
        SELECT ct.*, ca.account_name
        FROM cash_transactions ct
        LEFT JOIN cash_accounts ca ON ct.cash_account_id = ca.id
        ORDER BY ct.created_at DESC LIMIT 50
    ");
    $ctRows->execute();
    $ctData = $ctRows->fetchAll();

    $ctIns = 0; $ctOuts = 0;
    $todayCashIn = 0; $todayCashOut = 0;
    foreach ($ctData as $ct) {
        $amt = (float) $ct['amount'];
        if (in_array($ct['transaction_type'], ['in', 'adjustment'])) $ctIns += $amt; else $ctOuts += $amt;
        if (date('Y-m-d', strtotime($ct['created_at'])) === date('Y-m-d')) {
            if (in_array($ct['transaction_type'], ['in', 'adjustment'])) $todayCashIn += $amt; else $todayCashOut += $amt;
        }
    }
?>
    <div class="fin-card">
        <h2><i class="fas fa-money-bill-wave" style="color:var(--fin-purple)"></i> Cash Report</h2>
        <div class="fin-kpi-row">
            <div class="fin-kpi" style="border-left:3px solid var(--fin-purple)"><div class="fin-kpi-val" style="color:var(--fin-purple)"><?= finFmt($caTotalBal) ?></div><div class="fin-kpi-label">Total Cash Balance</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val"><?= count($caRows) ?></div><div class="fin-kpi-label">Active Accounts</div></div>
            <div class="fin-kpi" style="border-left:3px solid var(--fin-green)"><div class="fin-kpi-val" style="color:var(--fin-green)"><?= finFmt($todayCashIn) ?></div><div class="fin-kpi-label">Today Inflow</div></div>
            <div class="fin-kpi" style="border-left:3px solid var(--fin-red)"><div class="fin-kpi-val" style="color:var(--fin-red)"><?= finFmt($todayCashOut) ?></div><div class="fin-kpi-label">Today Outflow</div></div>
        </div>
    </div>

    <?php if (!empty($caRows)): ?>
    <div class="fin-card">
        <h2><i class="fas fa-wallet" style="color:var(--fin-blue)"></i> Cash Accounts</h2>
        <div style="overflow-x:auto">
        <table class="fin-table">
            <thead><tr><th>Account Name</th><th>Location</th><th>Responsible</th><th>Balance</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($caRows as $ca): ?>
                    <tr>
                        <td style="font-weight:700"><?= htmlspecialchars($ca['account_name']) ?></td>
                        <td style="font-size:.78rem"><?= htmlspecialchars($ca['location'] ?? '—') ?></td>
                        <td style="font-size:.78rem"><?= htmlspecialchars($ca['responsible_person'] ?? '—') ?></td>
                        <td style="font-weight:800;color:var(--fin-purple)"><?= finFmt((float) $ca['balance']) ?></td>
                        <td><span class="fin-badge fin-badge-green"><?= htmlspecialchars(ucwords($ca['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="3" style="text-align:right;font-weight:700">Total</td><td style="font-weight:800;color:var(--fin-purple)"><?= finFmt($caTotalBal) ?></td><td></td></tr></tfoot>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($ctData)): ?>
    <div class="fin-card">
        <h2><i class="fas fa-exchange-alt" style="color:var(--fin-orange)"></i> Recent Cash Transactions (Last 50)</h2>
        <div style="overflow-x:auto">
        <table class="fin-table">
            <thead><tr><th>Date</th><th>Account</th><th>Type</th><th>Category</th><th>Amount</th><th>Balance After</th><th>Description</th></tr></thead>
            <tbody>
                <?php foreach ($ctData as $ct): ?>
                    <tr>
                        <td style="font-size:.78rem;color:var(--fin-gray)"><?= date('M d, H:i', strtotime($ct['created_at'])) ?></td>
                        <td style="font-weight:600"><?= htmlspecialchars($ct['account_name'] ?? '—') ?></td>
                        <td>
                            <?php if ($ct['transaction_type'] === 'in' || $ct['transaction_type'] === 'adjustment'): ?>
                                <span class="fin-badge fin-badge-green"><?= htmlspecialchars(ucwords($ct['transaction_type'])) ?></span>
                            <?php else: ?>
                                <span class="fin-badge fin-badge-red"><?= htmlspecialchars(ucwords($ct['transaction_type'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.78rem"><?= htmlspecialchars($ct['category'] ?? '—') ?></td>
                        <td style="font-weight:700;color:<?= $ct['transaction_type'] === 'out' ? 'var(--fin-red)' : 'var(--fin-green)' ?>"><?= finFmt((float) $ct['amount']) ?></td>
                        <td><?= finFmt((float) $ct['balance_after']) ?></td>
                        <td style="font-size:.78rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($ct['description'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

<?php elseif ($activeTab === 'tax'):
    $taxRev = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM customer_orders WHERE created_at BETWEEN :df AND :dt AND status != 'cancelled'");
    $taxRev->execute(['df' => $dateFrom . ' 00:00:00', 'dt' => $dateTo . ' 23:59:59']);
    $taxRevenue = (float) $taxRev->fetchColumn();
    $taxAmount = $taxRevenue * ($vatRate / 100);

    $taxDaily = $pdo->prepare("
        SELECT DATE(created_at) AS d, COALESCE(SUM(total_amount), 0) AS day_total
        FROM customer_orders
        WHERE created_at BETWEEN :df AND :dt AND status != 'cancelled'
        GROUP BY d ORDER BY d ASC
    ");
    $taxDaily->execute(['df' => $dateFrom . ' 00:00:00', 'dt' => $dateTo . ' 23:59:59']);
    $taxDailyRows = $taxDaily->fetchAll();
?>
    <div class="fin-card">
        <h2><i class="fas fa-percentage" style="color:var(--fin-red)"></i> Tax Report — <?= $vatRate ?>% VAT</h2>
        <div class="fin-kpi-row">
            <div class="fin-kpi" style="border-left:3px solid var(--fin-blue)"><div class="fin-kpi-val" style="color:var(--fin-blue)"><?= finFmt($taxRevenue) ?></div><div class="fin-kpi-label">Taxable Revenue</div></div>
            <div class="fin-kpi" style="border-left:3px solid var(--fin-red)"><div class="fin-kpi-val" style="color:var(--fin-red)"><?= finFmt($taxAmount) ?></div><div class="fin-kpi-label">VAT Collected (<?= $vatRate ?>%)</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val"><?= $vatRate ?>%</div><div class="fin-kpi-label">VAT Rate</div></div>
        </div>
    </div>

    <?php if (!empty($taxDailyRows)): ?>
    <div class="fin-card">
        <h2><i class="fas fa-calendar" style="color:var(--fin-blue)"></i> Daily Tax Breakdown</h2>
        <div style="overflow-x:auto">
        <table class="fin-table">
            <thead><tr><th>Date</th><th>Revenue</th><th>VAT (<?= $vatRate ?>%)</th><th>Cumulative VAT</th></tr></thead>
            <tbody>
                <?php $cumTax = 0; foreach ($taxDailyRows as $td):
                    $dayVat = (float) $td['day_total'] * ($vatRate / 100);
                    $cumTax += $dayVat;
                ?>
                    <tr>
                        <td style="font-weight:600"><?= date('M d, Y', strtotime($td['d'])) ?></td>
                        <td><?= finFmt((float) $td['day_total']) ?></td>
                        <td style="font-weight:700;color:var(--fin-red)"><?= finFmt($dayVat) ?></td>
                        <td style="color:var(--fin-gray)"><?= finFmt($cumTax) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td style="text-align:right;font-weight:700">Total</td><td style="font-weight:800"><?= finFmt($taxRevenue) ?></td><td style="font-weight:800;color:var(--fin-red)"><?= finFmt($taxAmount) ?></td><td></td></tr></tfoot>
        </table>
        </div>
    </div>
    <?php endif; ?>

<?php elseif ($activeTab === 'vat'):
    $vatOutRev = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM customer_orders WHERE created_at BETWEEN :df AND :dt AND status != 'cancelled'");
    $vatOutRev->execute(['df' => $dateFrom . ' 00:00:00', 'dt' => $dateTo . ' 23:59:59']);
    $outputRevenue = (float) $vatOutRev->fetchColumn();
    $outputVAT = $outputRevenue * ($vatRate / 100);

    $vatInExp = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date BETWEEN :df AND :dt");
    $vatInExp->execute(['df' => $dateFrom, 'dt' => $dateTo]);
    $inputExpenses = (float) $vatInExp->fetchColumn();
    $inputVAT = $inputExpenses * ($vatRate / 100);

    $netVATPayable = $outputVAT - $inputVAT;

    $vatExpByCat = $pdo->prepare("
        SELECT expense_category, COALESCE(SUM(amount), 0) AS total
        FROM expenses WHERE expense_date BETWEEN :df AND :dt
        GROUP BY expense_category ORDER BY total DESC
    ");
    $vatExpByCat->execute(['df' => $dateFrom, 'dt' => $dateTo]);
    $vatExpCats = $vatExpByCat->fetchAll();
?>
    <div class="fin-card">
        <h2><i class="fas fa-file-invoice" style="color:var(--fin-purple)"></i> VAT Report — <?= date('M d', strtotime($dateFrom)) ?> to <?= date('M d', strtotime($dateTo)) ?></h2>
        <div class="fin-kpi-row">
            <div class="fin-kpi" style="border-left:3px solid var(--fin-blue);background:#eff6ff"><div class="fin-kpi-val" style="font-size:1rem;color:var(--fin-blue)"><?= finFmt($outputVAT) ?></div><div class="fin-kpi-label">Output VAT (Sales)</div></div>
            <div class="fin-kpi" style="border-left:3px solid var(--fin-green);background:#f0fdf4"><div class="fin-kpi-val" style="font-size:1rem;color:var(--fin-green)"><?= finFmt($inputVAT) ?></div><div class="fin-kpi-label">Input VAT (Expenses)</div></div>
            <div class="fin-kpi" style="border-left:3px solid <?= $netVATPayable >= 0 ? 'var(--fin-red)' : 'var(--fin-green)' ?>;background:<?= $netVATPayable >= 0 ? '#fef2f2' : '#f0fdf4' ?>"><div class="fin-kpi-val" style="font-size:1rem;color:<?= $netVATPayable >= 0 ? 'var(--fin-red)' : 'var(--fin-green)' ?>"><?= finFmt($netVATPayable) ?></div><div class="fin-kpi-label"><?= $netVATPayable >= 0 ? 'Net VAT Payable' : 'VAT Refundable' ?></div></div>
            <div class="fin-kpi"><div class="fin-kpi-val"><?= $vatRate ?>%</div><div class="fin-kpi-label">VAT Rate</div></div>
        </div>
    </div>

    <div class="fin-card">
        <h2><i class="fas fa-calculator" style="color:var(--fin-teal)"></i> VAT Calculation</h2>
        <div style="background:#f8fafc;border-radius:10px;padding:20px;font-size:.9rem;line-height:2">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
                <div>
                    <div class="fin-section-title"><i class="fas fa-arrow-up" style="color:var(--fin-blue)"></i> Output VAT (Collected on Sales)</div>
                    <div><span style="color:var(--fin-gray)">Taxable Sales:</span> <strong><?= finFmt($outputRevenue) ?></strong></div>
                    <div><span style="color:var(--fin-gray)">VAT Rate:</span> <strong><?= $vatRate ?>%</strong></div>
                    <div style="font-size:1.1rem;border-top:2px solid var(--fin-border);padding-top:4px"><span style="font-weight:800">Output VAT:</span> <strong style="color:var(--fin-blue)"><?= finFmt($outputVAT) ?></strong></div>
                </div>
                <div>
                    <div class="fin-section-title"><i class="fas fa-arrow-down" style="color:var(--fin-green)"></i> Input VAT (Paid on Expenses)</div>
                    <div><span style="color:var(--fin-gray)">Total Expenses:</span> <strong><?= finFmt($inputExpenses) ?></strong></div>
                    <div><span style="color:var(--fin-gray)">VAT Rate:</span> <strong><?= $vatRate ?>%</strong></div>
                    <div style="font-size:1.1rem;border-top:2px solid var(--fin-border);padding-top:4px"><span style="font-weight:800">Input VAT:</span> <strong style="color:var(--fin-green)"><?= finFmt($inputVAT) ?></strong></div>
                </div>
            </div>
            <div style="border-top:2px solid var(--fin-border);margin-top:16px;padding-top:12px;text-align:center">
                <div style="font-size:1.15rem;font-weight:800">Net VAT <?= $netVATPayable >= 0 ? 'Payable' : 'Refundable' ?>: <span style="color:<?= $netVATPayable >= 0 ? 'var(--fin-red)' : 'var(--fin-green)' ?>"><?= finFmt($netVATPayable) ?></span></div>
            </div>
        </div>
    </div>

    <?php if (!empty($vatExpCats)): ?>
    <div class="fin-card">
        <h2><i class="fas fa-layer-group" style="color:var(--fin-orange)"></i> Input VAT by Expense Category</h2>
        <div style="overflow-x:auto">
        <table class="fin-table">
            <thead><tr><th>Category</th><th>Amount</th><th>Input VAT</th><th style="width:120px">Share</th></tr></thead>
            <tbody>
                <?php foreach ($vatExpCats as $vc):
                    $catVat = (float) $vc['total'] * ($vatRate / 100);
                    $catPct = $inputExpenses > 0 ? ((float) $vc['total'] / $inputExpenses) * 100 : 0;
                ?>
                    <tr>
                        <td style="font-weight:600"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $vc['expense_category']))) ?></td>
                        <td><?= finFmt((float) $vc['total']) ?></td>
                        <td style="font-weight:700;color:var(--fin-green)"><?= finFmt($catVat) ?></td>
                        <td><div class="fin-progress-wrap"><div class="fin-progress-bar" style="width:<?= $catPct ?>%;background:var(--fin-green)"></div></div></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td style="text-align:right;font-weight:700">Total</td><td style="font-weight:800"><?= finFmt($inputExpenses) ?></td><td style="font-weight:800;color:var(--fin-green)"><?= finFmt($inputVAT) ?></td><td></td></tr></tfoot>
        </table>
        </div>
    </div>
    <?php endif; ?>

<?php elseif ($activeTab === 'credit'):
    $crRows = $pdo->prepare("
        SELECT i.id, i.invoice_no, i.total_amount, i.outstanding_balance, i.credit_due_date,
               i.generated_at AS created_at, c.name AS customer_name,
               DATEDIFF(CURDATE(), COALESCE(i.credit_due_date, i.generated_at)) AS days_overdue
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        WHERE i.payment_status IN ('unpaid', 'partial')
        ORDER BY i.credit_due_date ASC
    ");
    $crRows->execute();
    $crData = $crRows->fetchAll();

    $crTotal = 0; $crOverdue = 0; $crCurrent = 0; $crCreditSales = 0; $crCustomers = [];
    foreach ($crData as $r) {
        $bal = (float) $r['outstanding_balance'];
        $crTotal += $bal;
        $cust = $r['customer_name'] ?? 'Unknown';
        $crCustomers[$cust] = ($crCustomers[$cust] ?? 0) + $bal;
        if ((int) $r['days_overdue'] > 0 && !empty($r['credit_due_date']) && strtotime($r['credit_due_date']) < time()) {
            $crOverdue += $bal;
        } else {
            $crCurrent += $bal;
        }
    }
    arsort($crCustomers);
    $crCustomers = array_slice($crCustomers, 0, 10, true);

    $crTotalSales = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM customer_orders WHERE created_at BETWEEN :df AND :dt AND status != 'cancelled' AND payment_method = 'credit'");
    $crTotalSales->execute(['df' => $dateFrom . ' 00:00:00', 'dt' => $dateTo . ' 23:59:59']);
    $crCreditSalesTotal = (float) $crTotalSales->fetchColumn();

    $crAllSales = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM customer_orders WHERE created_at BETWEEN :df AND :dt AND status != 'cancelled'");
    $crAllSales->execute(['df' => $dateFrom . ' 00:00:00', 'dt' => $dateTo . ' 23:59:59']);
    $crAllSalesTotal = (float) $crAllSales->fetchColumn();
?>
    <div class="fin-card">
        <h2><i class="fas fa-credit-card" style="color:var(--fin-indigo)"></i> Credit Report</h2>
        <div class="fin-kpi-row">
            <div class="fin-kpi" style="border-left:3px solid var(--fin-indigo)"><div class="fin-kpi-val" style="color:var(--fin-indigo)"><?= finFmt($crTotal) ?></div><div class="fin-kpi-label">Total Outstanding</div></div>
            <div class="fin-kpi" style="border-left:3px solid var(--fin-red)"><div class="fin-kpi-val" style="color:var(--fin-red)"><?= finFmt($crOverdue) ?></div><div class="fin-kpi-label">Overdue Amount</div></div>
            <div class="fin-kpi" style="border-left:3px solid var(--fin-green)"><div class="fin-kpi-val" style="color:var(--fin-green)"><?= finFmt($crCurrent) ?></div><div class="fin-kpi-label">Current (Not Due)</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val"><?= finFmt($crCreditSalesTotal) ?></div><div class="fin-kpi-label">Credit Sales (Period)</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val"><?= $crAllSalesTotal > 0 ? round(($crCreditSalesTotal / $crAllSalesTotal) * 100, 1) : 0 ?>%</div><div class="fin-kpi-label">Credit % of Sales</div></div>
        </div>
    </div>

    <?php if (!empty($crCustomers)): ?>
    <div class="fin-card">
        <h2><i class="fas fa-users" style="color:var(--fin-orange)"></i> Top Outstanding Customers</h2>
        <div style="overflow-x:auto">
        <table class="fin-table">
            <thead><tr><th>#</th><th>Customer</th><th>Outstanding Balance</th><th style="width:150px">Share</th></tr></thead>
            <tbody>
                <?php $rank = 1; foreach ($crCustomers as $custName => $custBal):
                    $custPct = $crTotal > 0 ? ($custBal / $crTotal) * 100 : 0;
                ?>
                    <tr>
                        <td style="font-weight:700;color:var(--fin-gray)"><?= $rank++ ?></td>
                        <td style="font-weight:600"><?= htmlspecialchars($custName) ?></td>
                        <td style="font-weight:700;color:var(--fin-red)"><?= finFmt($custBal) ?></td>
                        <td><div class="fin-progress-wrap"><div class="fin-progress-bar" style="width:<?= $custPct ?>%;background:var(--fin-indigo)"></div></div></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="fin-card">
        <h2><i class="fas fa-file-invoice" style="color:var(--fin-blue)"></i> Credit Invoices</h2>
        <?php if (empty($crData)): ?>
            <div class="fin-empty"><i class="fas fa-check-circle" style="color:var(--fin-green)"></i>No outstanding credit invoices!</div>
        <?php else: ?>
            <div style="overflow-x:auto">
            <table class="fin-table">
                <thead><tr><th>Invoice</th><th>Customer</th><th>Total</th><th>Outstanding</th><th>Due Date</th><th>Days</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($crData as $r):
                        $daysOver = (int) $r['days_overdue'];
                        $isOD = $daysOver > 0 && !empty($r['credit_due_date']) && strtotime($r['credit_due_date']) < time();
                    ?>
                        <tr>
                            <td style="font-weight:700"><?= htmlspecialchars($r['invoice_no'] ?? '#' . $r['id']) ?></td>
                            <td style="font-weight:600"><?= htmlspecialchars($r['customer_name'] ?? 'N/A') ?></td>
                            <td><?= finFmt((float) $r['total_amount']) ?></td>
                            <td style="font-weight:800;color:var(--fin-red)"><?= finFmt((float) $r['outstanding_balance']) ?></td>
                            <td style="font-size:.78rem;color:<?= $isOD ? 'var(--fin-red)' : 'var(--fin-gray)' ?>"><?= $r['credit_due_date'] ? date('M d, Y', strtotime($r['credit_due_date'])) : '—' ?></td>
                            <td style="font-size:.78rem;color:<?= $isOD ? 'var(--fin-red)' : 'var(--fin-gray)' ?>"><?= $r['credit_due_date'] ? ($isOD ? $daysOver . ' days overdue' : 'Due in ' . abs($daysOver) . ' days') : '—' ?></td>
                            <td>
                                <?php if ($isOD): ?>
                                    <span class="fin-badge fin-badge-red"><i class="fas fa-exclamation-circle"></i> Overdue</span>
                                <?php else: ?>
                                    <span class="fin-badge fin-badge-blue">Outstanding</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="3" style="text-align:right;font-weight:700">Total</td><td style="font-weight:800;color:var(--fin-red)"><?= finFmt($crTotal) ?></td><td colspan="3"></td></tr></tfoot>
            </table>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($activeTab === 'payment'):
    $pmtRows = $pdo->prepare("
        SELECT ip.*, c.name AS customer_name, u.full_name AS received_by_name
        FROM invoice_payments ip
        LEFT JOIN customers c ON ip.customer_id = c.id
        LEFT JOIN users u ON ip.recorded_by = u.id
        WHERE ip.created_at BETWEEN :df AND :dt
        ORDER BY ip.created_at DESC
    ");
    $pmtRows->execute(['df' => $dateFrom . ' 00:00:00', 'dt' => $dateTo . ' 23:59:59']);
    $pmtData = $pmtRows->fetchAll();

    $pmtTotal = 0; $pmtCount = count($pmtData); $pmtMethods = [];
    foreach ($pmtData as $p) {
        $amt = (float) $p['amount'];
        $pmtTotal += $amt;
        $pm = $p['payment_method'] ?? 'other';
        $pmtMethods[$pm] = ($pmtMethods[$pm] ?? 0) + $amt;
    }
?>
    <div class="fin-card">
        <h2><i class="fas fa-hand-holding-usd" style="color:var(--fin-green)"></i> Payment Report — <?= date('M d', strtotime($dateFrom)) ?> to <?= date('M d', strtotime($dateTo)) ?></h2>
        <div class="fin-kpi-row">
            <div class="fin-kpi" style="border-left:3px solid var(--fin-green)"><div class="fin-kpi-val" style="color:var(--fin-green)"><?= finFmt($pmtTotal) ?></div><div class="fin-kpi-label">Total Collected</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val"><?= $pmtCount ?></div><div class="fin-kpi-label">Payments Received</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val"><?= count($pmtMethods) ?></div><div class="fin-kpi-label">Payment Methods</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val"><?= $pmtCount > 0 ? finFmt($pmtTotal / $pmtCount) : finFmt(0) ?></div><div class="fin-kpi-label">Avg Payment</div></div>
        </div>
    </div>

    <?php if (!empty($pmtMethods)): ?>
    <div class="fin-card">
        <h2><i class="fas fa-credit-card" style="color:var(--fin-purple)"></i> By Payment Method</h2>
        <div style="display:flex;gap:16px;flex-wrap:wrap">
            <?php foreach ($pmtMethods as $method => $amt): ?>
                <div style="padding:14px 20px;background:#f8fafc;border:1px solid var(--fin-border);border-radius:10px;text-align:center;min-width:120px">
                    <div style="font-size:.72rem;color:var(--fin-gray);font-weight:600;text-transform:uppercase"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $method))) ?></div>
                    <div style="font-size:1.1rem;font-weight:800;color:var(--fin-green);margin-top:4px"><?= finFmt($amt) ?></div>
                    <div style="font-size:.68rem;color:var(--fin-gray)"><?= $pmtTotal > 0 ? round(($amt / $pmtTotal) * 100, 1) : 0 ?>%</div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="fin-card">
        <h2><i class="fas fa-list" style="color:var(--fin-blue)"></i> Payment Details</h2>
        <?php if (empty($pmtData)): ?>
            <div class="fin-empty"><i class="fas fa-inbox"></i>No payments recorded for this period.</div>
        <?php else: ?>
            <div style="overflow-x:auto">
            <table class="fin-table">
                <thead><tr><th>Date</th><th>Customer</th><th>Amount</th><th>Method</th><th>Reference</th><th>Received By</th></tr></thead>
                <tbody>
                    <?php foreach ($pmtData as $p): ?>
                        <tr>
                            <td style="font-size:.78rem;color:var(--fin-gray)"><?= date('M d, Y H:i', strtotime($p['created_at'])) ?></td>
                            <td style="font-weight:600"><?= htmlspecialchars($p['customer_name'] ?? 'N/A') ?></td>
                            <td style="font-weight:700;color:var(--fin-green)"><?= finFmt((float) $p['amount']) ?></td>
                            <td><span class="fin-badge fin-badge-blue"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $p['payment_method'] ?? 'other'))) ?></span></td>
                            <td style="font-size:.78rem;font-family:monospace"><?= htmlspecialchars($p['reference_no'] ?? '—') ?></td>
                            <td style="font-size:.78rem"><?= htmlspecialchars($p['received_by_name'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="2" style="text-align:right;font-weight:700">Total</td><td style="font-weight:800;color:var(--fin-green)"><?= finFmt($pmtTotal) ?></td><td colspan="3"></td></tr></tfoot>
            </table>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($activeTab === 'refund'):
    $refRows = $pdo->prepare("
        SELECT r.*, co.id AS order_id, co.total_amount AS order_total, c.name AS customer_name,
               u.full_name AS refunded_by_name
        FROM refunds r
        LEFT JOIN customer_orders co ON r.customer_order_id = co.id
        LEFT JOIN customers c ON co.customer_id = c.id
        LEFT JOIN users u ON r.refunded_by = u.id
        WHERE r.refunded_at BETWEEN :df AND :dt
        ORDER BY r.refunded_at DESC
    ");
    $refRows->execute(['df' => $dateFrom . ' 00:00:00', 'dt' => $dateTo . ' 23:59:59']);
    $refData = $refRows->fetchAll();

    $refTotal = 0; $refCount = count($refData);
    foreach ($refData as $r) { $refTotal += (float) $r['amount']; }

    $refSales = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM customer_orders WHERE created_at BETWEEN :df AND :dt AND status != 'cancelled'");
    $refSales->execute(['df' => $dateFrom . ' 00:00:00', 'dt' => $dateTo . ' 23:59:59']);
    $periodSales = (float) $refSales->fetchColumn();
    $refundRate = $periodSales > 0 ? ($refTotal / $periodSales) * 100 : 0;
?>
    <div class="fin-card">
        <h2><i class="fas fa-undo-alt" style="color:var(--fin-pink)"></i> Refund Report — <?= date('M d', strtotime($dateFrom)) ?> to <?= date('M d', strtotime($dateTo)) ?></h2>
        <div class="fin-kpi-row">
            <div class="fin-kpi" style="border-left:3px solid var(--fin-red)"><div class="fin-kpi-val" style="color:var(--fin-red)"><?= finFmt($refTotal) ?></div><div class="fin-kpi-label">Total Refunded</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val"><?= $refCount ?></div><div class="fin-kpi-label">Refund Transactions</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val"><?= round($refundRate, 2) ?>%</div><div class="fin-kpi-label">Refund Rate</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val"><?= $refCount > 0 ? finFmt($refTotal / $refCount) : finFmt(0) ?></div><div class="fin-kpi-label">Avg Refund</div></div>
        </div>
    </div>

    <div class="fin-card">
        <h2><i class="fas fa-list" style="color:var(--fin-blue)"></i> Refund Details</h2>
        <?php if (empty($refData)): ?>
            <div class="fin-empty"><i class="fas fa-check-circle" style="color:var(--fin-green)"></i>No refunds recorded for this period.</div>
        <?php else: ?>
            <div style="overflow-x:auto">
            <table class="fin-table">
                <thead><tr><th>Date</th><th>Order #</th><th>Customer</th><th>Order Total</th><th>Refund Amount</th><th>Reason</th><th>Processed By</th></tr></thead>
                <tbody>
                    <?php foreach ($refData as $r): ?>
                        <tr>
                            <td style="font-size:.78rem;color:var(--fin-gray)"><?= date('M d, Y H:i', strtotime($r['refunded_at'])) ?></td>
                            <td style="font-weight:700">#<?= $r['order_id'] ?></td>
                            <td style="font-weight:600"><?= htmlspecialchars($r['customer_name'] ?? 'N/A') ?></td>
                            <td><?= finFmt((float) $r['order_total']) ?></td>
                            <td style="font-weight:800;color:var(--fin-red)"><?= finFmt((float) $r['amount']) ?></td>
                            <td style="font-size:.78rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($r['reason'] ?? '') ?>"><?= htmlspecialchars($r['reason'] ?? '—') ?></td>
                            <td style="font-size:.78rem"><?= htmlspecialchars($r['refunded_by_name'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="4" style="text-align:right;font-weight:700">Total</td><td style="font-weight:800;color:var(--fin-red)"><?= finFmt($refTotal) ?></td><td colspan="2"></td></tr></tfoot>
            </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

</section>
<?= finJs() ?>
