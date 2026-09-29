<?php
require_once __DIR__ . '/../includes/finance_helpers.php';
ensureFinanceErpMigration(finGetDb());
$pdo = finGetDb();
requireFinanceOfficer();

$monthRev = finGetMonthRevenue($pdo);
$monthRevPrev = finGetMonthRevenuePrev($pdo);
$monthExp = finGetMonthExpenses($pdo);
$monthExpPrev = finGetMonthExpensesPrev($pdo);
$monthInc = finGetMonthIncome($pdo);
$netProfit = $monthRev + $monthInc - $monthExp;
$outstanding = finGetOutstandingReceivables($pdo);

$revCh = finPctChange($monthRev, $monthRevPrev);
$expCh = finPctChange($monthExp, $monthExpPrev);

$revenueData = finGetMonthlyData($pdo, 'revenue');
$expenseData = finGetMonthlyData($pdo, 'expense');
$paymentMethods = finGetPaymentMethodData($pdo);

$maxRevenue = 0;
foreach ($revenueData as $r) { if ($r['value'] > $maxRevenue) $maxRevenue = $r['value']; }
$maxExpense = 0;
foreach ($expenseData as $e) { if ($e['value'] > $maxExpense) $maxExpense = $e['value']; }

$maxProfit = 0;
$profitData = [];
for ($i = 0; $i < count($revenueData); $i++) {
    $profit = $revenueData[$i]['value'] - $expenseData[$i]['value'];
    $profitData[] = ['label' => $revenueData[$i]['label'], 'profit' => $profit];
    if (abs($profit) > $maxProfit) $maxProfit = abs($profit);
}
$maxRevenueForProfit = $maxRevenue > 0 ? $maxRevenue : 1;
$maxExpenseForProfit = $maxExpense > 0 ? $maxExpense : 1;

$cashFlowData = [];
try {
    $cfStmt = $pdo->query('SELECT transaction_type, MONTH(created_at) AS m, YEAR(created_at) AS y, SUM(amount) AS total FROM cash_transactions GROUP BY transaction_type, YEAR(created_at), MONTH(created_at) ORDER BY YEAR(created_at), MONTH(created_at) LIMIT 24');
    $cfRows = $cfStmt->fetchAll();
    $cfMap = [];
    foreach ($cfRows as $cf) {
        $key = $cf['y'] . '-' . str_pad($cf['m'], 2, '0', STR_PAD_LEFT);
        if (!isset($cfMap[$key])) $cfMap[$key] = ['in' => 0, 'out' => 0, 'label' => date('M', mktime(0, 0, 0, $cf['m']))];
        if ($cf['transaction_type'] === 'in') $cfMap[$key]['in'] = (float) $cf['total'];
        elseif ($cf['transaction_type'] === 'out') $cfMap[$key]['out'] = (float) $cf['total'];
    }
    ksort($cfMap);
    $cashFlowData = array_values($cfMap);
} catch (Exception $e) {}

$maxCashIn = 1;
$maxCashOut = 1;
foreach ($cashFlowData as $cf) {
    if ($cf['in'] > $maxCashIn) $maxCashIn = $cf['in'];
    if ($cf['out'] > $maxCashOut) $maxCashOut = $cf['out'];
}
$maxCash = max($maxCashIn, $maxCashOut);
if ($maxCash <= 0) $maxCash = 1;

$outsData = [];
try {
    $osStmt = $pdo->query('SELECT MONTH(credit_due_date) AS m, YEAR(credit_due_date) AS y, SUM(outstanding_balance) AS total FROM invoices WHERE payment_status IN ("unpaid","partial") AND credit_due_date IS NOT NULL GROUP BY YEAR(credit_due_date), MONTH(credit_due_date) ORDER BY YEAR(credit_due_date), MONTH(credit_due_date) LIMIT 12');
    $osRows = $osStmt->fetchAll();
    foreach ($osRows as $os) {
        $outsData[] = ['label' => date('M', mktime(0, 0, 0, $os['m'])), 'value' => (float) $os['total']];
    }
} catch (Exception $e) {}

$maxOutstanding = 1;
foreach ($outsData as $od) { if ($od['value'] > $maxOutstanding) $maxOutstanding = $od['value']; }

$totalPaymentAmount = 0;
foreach ($paymentMethods as $pm) $totalPaymentAmount += $pm['value'];
if ($totalPaymentAmount <= 0) $totalPaymentAmount = 1;

$pieColors = ['#2563eb', '#059669', '#d97706', '#dc2626', '#7c3aed', '#0d9488', '#4f46e5', '#db2777', '#ea580c'];
$pieLegend = [];
$pieGradientParts = [];
$pieCumulative = 0;
foreach ($paymentMethods as $idx => $pm) {
    $pct = ($pm['value'] / $totalPaymentAmount) * 100;
    $color = $pieColors[$idx % count($pieColors)];
    $start = $pieCumulative;
    $pieCumulative += $pct;
    $pieGradientParts[] = "$color {$start}% {$pieCumulative}%";
    $pieLegend[] = ['label' => $pm['label'], 'value' => $pm['value'], 'pct' => $pct, 'color' => $color];
}
$pieGradient = implode(', ', $pieGradientParts);
if (empty($pieGradient)) $pieGradient = '#e2e8f0 0% 100%';

$activeTab = $_GET['tab'] ?? 'revenue';
$validTabs = ['revenue', 'expense', 'profit', 'payment_methods', 'cash_flow', 'outstanding'];
if (!in_array($activeTab, $validTabs)) $activeTab = 'revenue';

function finChartBar($value, $max, $color) {
    $pct = $max > 0 ? round(($value / $max) * 100) : 0;
    if ($pct < 1 && $value > 0) $pct = 1;
    return 'width:100%;background:' . $color . ';height:' . $pct . '%;border-radius:4px 4px 0 0;min-height:2px;transition:height .3s';
}
?>

<?= finCss() ?>
<style>
.fin-chart-tab-content{min-height:320px}
.fin-chart-legend{display:flex;flex-wrap:wrap;gap:12px;margin-top:14px}
.fin-chart-legend-item{display:flex;align-items:center;gap:6px;font-size:.78rem;color:#475569}
.fin-chart-legend-dot{width:12px;height:12px;border-radius:3px;flex-shrink:0}
.fin-pie-wrap{display:flex;align-items:center;gap:30px;flex-wrap:wrap}
.fin-pie-legend{display:flex;flex-direction:column;gap:8px}
.fin-pie-legend-row{display:flex;align-items:center;gap:8px;font-size:.82rem}
.fin-pie-legend-dot{width:14px;height:14px;border-radius:4px;flex-shrink:0}
.fin-pie-legend-val{font-weight:700;margin-left:auto;min-width:70px;text-align:right}
.fin-pie-legend-pct{color:var(--fin-gray);font-size:.72rem;min-width:40px;text-align:right}
.fin-cf-dual{display:flex;gap:3px;align-items:flex-end;flex:1}
.fin-cf-dual .fin-cf-bar{flex:1}
</style>
<section class="fin-wrap">
    <div style="margin-bottom:6px">
        <h1><i class="fas fa-chart-bar" style="color:var(--fin-blue)"></i> Finance Charts</h1>
        <div class="fin-subtitle">Visual breakdown of revenue, expenses, cash flow, and more</div>
    </div>

    <div class="fin-kpi-row" style="margin-bottom:20px">
        <div class="fin-kpi">
            <div class="fin-kpi-val" style="color:var(--fin-blue)" data-counter="<?= $monthRev ?>" data-prefix="<?= htmlspecialchars(currency_sym()) ?> " data-decimals="2"><?= htmlspecialchars(currency_sym()) ?> 0.00</div>
            <div class="fin-kpi-label">Monthly Revenue</div>
        </div>
        <div class="fin-kpi">
            <div class="fin-kpi-val" style="color:var(--fin-red)" data-counter="<?= $monthExp ?>" data-prefix="<?= htmlspecialchars(currency_sym()) ?> " data-decimals="2"><?= htmlspecialchars(currency_sym()) ?> 0.00</div>
            <div class="fin-kpi-label">Monthly Expenses</div>
        </div>
        <div class="fin-kpi">
            <div class="fin-kpi-val" style="color:<?= $netProfit >= 0 ? 'var(--fin-green)' : 'var(--fin-red)' ?>" data-counter="<?= $netProfit ?>" data-prefix="<?= htmlspecialchars(currency_sym()) ?> " data-decimals="2"><?= htmlspecialchars(currency_sym()) ?> 0.00</div>
            <div class="fin-kpi-label">Net Profit</div>
        </div>
        <div class="fin-kpi">
            <div class="fin-kpi-val" style="color:var(--fin-orange)" data-counter="<?= $outstanding ?>" data-prefix="<?= htmlspecialchars(currency_sym()) ?> " data-decimals="2"><?= htmlspecialchars(currency_sym()) ?> 0.00</div>
            <div class="fin-kpi-label">Outstanding Balance</div>
        </div>
    </div>

    <div class="fin-card" style="padding-bottom:0">
        <div class="fin-tabs">
            <button class="fin-tab<?= $activeTab === 'revenue' ? ' active' : '' ?>" onclick="finSwitchTab(this,'panel-revenue')"><i class="fas fa-chart-line"></i> Monthly Revenue</button>
            <button class="fin-tab<?= $activeTab === 'expense' ? ' active' : '' ?>" onclick="finSwitchTab(this,'panel-expense')"><i class="fas fa-chart-bar"></i> Monthly Expenses</button>
            <button class="fin-tab<?= $activeTab === 'profit' ? ' active' : '' ?>" onclick="finSwitchTab(this,'panel-profit')"><i class="fas fa-balance-scale"></i> Profit Chart</button>
            <button class="fin-tab<?= $activeTab === 'payment_methods' ? ' active' : '' ?>" onclick="finSwitchTab(this,'panel-payment_methods')"><i class="fas fa-credit-card"></i> Payment Methods</button>
            <button class="fin-tab<?= $activeTab === 'cash_flow' ? ' active' : '' ?>" onclick="finSwitchTab(this,'panel-cash_flow')"><i class="fas fa-exchange-alt"></i> Cash Flow</button>
            <button class="fin-tab<?= $activeTab === 'outstanding' ? ' active' : '' ?>" onclick="finSwitchTab(this,'panel-outstanding')"><i class="fas fa-file-invoice-dollar"></i> Outstanding Trend</button>
        </div>

        <div id="panel-revenue" class="fin-panel fin-chart-tab-content<?= $activeTab === 'revenue' ? ' active' : '' ?>">
            <div style="padding:20px 0">
                <div style="display:flex;align-items:flex-end;gap:6px;height:220px;padding-top:20px">
                    <?php foreach ($revenueData as $rd): ?>
                        <?php $pct = $maxRevenue > 0 ? round(($rd['value'] / $maxRevenue) * 100) : 0; if ($pct < 1 && $rd['value'] > 0) $pct = 1; ?>
                        <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%">
                            <div style="font-size:.65rem;color:var(--fin-blue);font-weight:700;margin-bottom:4px;white-space:nowrap"><?= finFmtShort($rd['value']) ?></div>
                            <div style="<?= finChartBar($rd['value'], $maxRevenue, '#2563eb') ?>" title="<?= finFmt($rd['value']) ?>"></div>
                            <div style="font-size:.65rem;margin-top:4px;color:#64748b;text-align:center"><?= date('M', strtotime($rd['label'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (empty($revenueData)): ?>
                    <div class="fin-empty"><i class="fas fa-chart-bar"></i>No revenue data available.</div>
                <?php endif; ?>
            </div>
            <div class="fin-chart-legend">
                <div class="fin-chart-legend-item"><div class="fin-chart-legend-dot" style="background:#2563eb"></div>Revenue (Last 12 Months)</div>
            </div>
        </div>

        <div id="panel-expense" class="fin-panel fin-chart-tab-content<?= $activeTab === 'expense' ? ' active' : '' ?>">
            <div style="padding:20px 0">
                <div style="display:flex;align-items:flex-end;gap:6px;height:220px;padding-top:20px">
                    <?php foreach ($expenseData as $ed): ?>
                        <?php $pct = $maxExpense > 0 ? round(($ed['value'] / $maxExpense) * 100) : 0; if ($pct < 1 && $ed['value'] > 0) $pct = 1; ?>
                        <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%">
                            <div style="font-size:.65rem;color:var(--fin-red);font-weight:700;margin-bottom:4px;white-space:nowrap"><?= finFmtShort($ed['value']) ?></div>
                            <div style="<?= finChartBar($ed['value'], $maxExpense, '#dc2626') ?>" title="<?= finFmt($ed['value']) ?>"></div>
                            <div style="font-size:.65rem;margin-top:4px;color:#64748b;text-align:center"><?= date('M', strtotime($ed['label'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (empty($expenseData)): ?>
                    <div class="fin-empty"><i class="fas fa-chart-bar"></i>No expense data available.</div>
                <?php endif; ?>
            </div>
            <div class="fin-chart-legend">
                <div class="fin-chart-legend-item"><div class="fin-chart-legend-dot" style="background:#dc2626"></div>Expenses (Last 12 Months)</div>
            </div>
        </div>

        <div id="panel-profit" class="fin-panel fin-chart-tab-content<?= $activeTab === 'profit' ? ' active' : '' ?>">
            <div style="padding:20px 0">
                <?php
                $maxSide = max($maxRevenueForProfit, $maxExpenseForProfit);
                if ($maxSide <= 0) $maxSide = 1;
                ?>
                <div style="display:flex;align-items:flex-end;gap:6px;height:240px;padding-top:20px">
                    <?php for ($i = 0; $i < count($revenueData); $i++): ?>
                        <?php
                        $rev = $revenueData[$i]['value'];
                        $exp = $expenseData[$i]['value'];
                        $revPct = round(($rev / $maxSide) * 100);
                        $expPct = round(($exp / $maxSide) * 100);
                        if ($revPct < 1 && $rev > 0) $revPct = 1;
                        if ($expPct < 1 && $exp > 0) $expPct = 1;
                        $netP = $rev - $exp;
                        ?>
                        <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%">
                            <div style="font-size:.6rem;font-weight:700;color:<?= $netP >= 0 ? 'var(--fin-green)' : 'var(--fin-red)' ?>;margin-bottom:4px;white-space:nowrap"><?= $netP >= 0 ? '+' : '' ?><?= finFmtShort($netP) ?></div>
                            <div style="display:flex;gap:3px;align-items:flex-end;width:100%;height:100%">
                                <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%">
                                    <div style="width:100%;background:#059669;height:<?= $revPct ?>%;border-radius:4px 4px 0 0;min-height:2px" title="Revenue: <?= finFmt($rev) ?>"></div>
                                </div>
                                <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%">
                                    <div style="width:100%;background:#dc2626;height:<?= $expPct ?>%;border-radius:4px 4px 0 0;min-height:2px" title="Expense: <?= finFmt($exp) ?>"></div>
                                </div>
                            </div>
                            <div style="font-size:.65rem;margin-top:4px;color:#64748b;text-align:center"><?= date('M', strtotime($revenueData[$i]['label'])) ?></div>
                        </div>
                    <?php endfor; ?>
                </div>
                <?php if (empty($revenueData)): ?>
                    <div class="fin-empty"><i class="fas fa-chart-bar"></i>No data available for profit chart.</div>
                <?php endif; ?>
            </div>
            <div class="fin-chart-legend">
                <div class="fin-chart-legend-item"><div class="fin-chart-legend-dot" style="background:#059669"></div>Revenue</div>
                <div class="fin-chart-legend-item"><div class="fin-chart-legend-dot" style="background:#dc2626"></div>Expense</div>
                <div class="fin-chart-legend-item"><div class="fin-chart-legend-dot" style="background:#64748b"></div>Net Profit (above bars)</div>
            </div>
        </div>

        <div id="panel-payment_methods" class="fin-panel fin-chart-tab-content<?= $activeTab === 'payment_methods' ? ' active' : '' ?>">
            <div style="padding:20px 0">
                <?php if (!empty($paymentMethods)): ?>
                    <div class="fin-pie-wrap">
                        <div>
                            <div style="width:200px;height:200px;border-radius:50%;background:conic-gradient(<?= $pieGradient ?>);box-shadow:0 2px 12px rgba(0,0,0,.1);position:relative">
                                <div style="position:absolute;inset:30%;background:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-direction:column">
                                    <div style="font-size:.72rem;color:var(--fin-gray);font-weight:600">Total</div>
                                    <div style="font-size:.85rem;font-weight:800;color:#0f172a"><?= finFmtShort($totalPaymentAmount) ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="fin-pie-legend">
                            <?php foreach ($pieLegend as $pl): ?>
                                <div class="fin-pie-legend-row">
                                    <div class="fin-pie-legend-dot" style="background:<?= $pl['color'] ?>"></div>
                                    <span style="font-weight:600"><?= htmlspecialchars($pl['label']) ?></span>
                                    <span class="fin-pie-legend-pct"><?= number_format($pl['pct'], 1) ?>%</span>
                                    <span class="fin-pie-legend-val" style="color:#0f172a"><?= finFmtShort($pl['value']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="fin-empty"><i class="fas fa-credit-card"></i>No payment method data available.</div>
                <?php endif; ?>
            </div>
        </div>

        <div id="panel-cash_flow" class="fin-panel fin-chart-tab-content<?= $activeTab === 'cash_flow' ? ' active' : '' ?>">
            <div style="padding:20px 0">
                <?php if (!empty($cashFlowData)): ?>
                    <div style="display:flex;align-items:flex-end;gap:6px;height:220px;padding-top:20px">
                        <?php foreach ($cashFlowData as $cf): ?>
                            <?php
                            $inPct = round(($cf['in'] / $maxCash) * 100);
                            $outPct = round(($cf['out'] / $maxCash) * 100);
                            if ($inPct < 1 && $cf['in'] > 0) $inPct = 1;
                            if ($outPct < 1 && $cf['out'] > 0) $outPct = 1;
                            ?>
                            <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%">
                                <div style="font-size:.6rem;color:var(--fin-green);font-weight:600;margin-bottom:2px;white-space:nowrap"><?= finFmtShort($cf['in']) ?></div>
                                <div style="font-size:.6rem;color:var(--fin-red);font-weight:600;margin-bottom:2px;white-space:nowrap"><?= finFmtShort($cf['out']) ?></div>
                                <div style="display:flex;gap:3px;align-items:flex-end;width:100%;height:100%">
                                    <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%">
                                        <div style="width:100%;background:#059669;height:<?= $inPct ?>%;border-radius:4px 4px 0 0;min-height:2px" title="Cash In: <?= finFmt($cf['in']) ?>"></div>
                                    </div>
                                    <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%">
                                        <div style="width:100%;background:#dc2626;height:<?= $outPct ?>%;border-radius:4px 4px 0 0;min-height:2px" title="Cash Out: <?= finFmt($cf['out']) ?>"></div>
                                    </div>
                                </div>
                                <div style="font-size:.65rem;margin-top:4px;color:#64748b;text-align:center"><?= htmlspecialchars($cf['label']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="fin-empty"><i class="fas fa-exchange-alt"></i>No cash flow data available.</div>
                <?php endif; ?>
            </div>
            <div class="fin-chart-legend">
                <div class="fin-chart-legend-item"><div class="fin-chart-legend-dot" style="background:#059669"></div>Cash In</div>
                <div class="fin-chart-legend-item"><div class="fin-chart-legend-dot" style="background:#dc2626"></div>Cash Out</div>
            </div>
        </div>

        <div id="panel-outstanding" class="fin-panel fin-chart-tab-content<?= $activeTab === 'outstanding' ? ' active' : '' ?>">
            <div style="padding:20px 0">
                <?php if (!empty($outsData)): ?>
                    <div style="display:flex;align-items:flex-end;gap:6px;height:220px;padding-top:20px">
                        <?php foreach ($outsData as $od): ?>
                            <?php $pct = round(($od['value'] / $maxOutstanding) * 100); if ($pct < 1 && $od['value'] > 0) $pct = 1; ?>
                            <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%">
                                <div style="font-size:.65rem;color:var(--fin-orange);font-weight:700;margin-bottom:4px;white-space:nowrap"><?= finFmtShort($od['value']) ?></div>
                                <div style="width:100%;background:linear-gradient(to top,#d97706,#f59e0b);height:<?= $pct ?>%;border-radius:4px 4px 0 0;min-height:2px;transition:height .3s" title="<?= finFmt($od['value']) ?>"></div>
                                <div style="font-size:.65rem;margin-top:4px;color:#64748b;text-align:center"><?= htmlspecialchars($od['label']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="fin-empty"><i class="fas fa-file-invoice-dollar"></i>No outstanding receivables data available.</div>
                <?php endif; ?>
            </div>
            <div class="fin-chart-legend">
                <div class="fin-chart-legend-item"><div class="fin-chart-legend-dot" style="background:#d97706"></div>Outstanding Receivables</div>
            </div>
        </div>

    </div>
</section>
<?= finJs() ?>
