<?php
requirePermission('sales_performance', 'view');
require_once __DIR__ . '/../includes/auto_migrate.php';
ensureSalesRepMigration(getPDO());
$pdo = getPDO();

$filterMonth = (int) ($_GET['month'] ?? (int) date('n'));
$filterYear = (int) ($_GET['year'] ?? (int) date('Y'));

$performance = $pdo->query("
    SELECT sp.id, sp.name, t.name AS territory_name, t.region,
        sp.commission_rate, sp.new_customer_bonus, sp.monthly_target, sp.monthly_target_bonus,
        (SELECT COUNT(*) FROM sales s WHERE s.salesperson_id = sp.id AND MONTH(s.sale_date) = $filterMonth AND YEAR(s.sale_date) = $filterYear) AS monthly_sales,
        COALESCE((SELECT SUM(s2.total_amount) FROM sales s2 WHERE s2.salesperson_id = sp.id AND MONTH(s2.sale_date) = $filterMonth AND YEAR(s2.sale_date) = $filterYear), 0) AS monthly_revenue,
        (SELECT COUNT(*) FROM visits v WHERE v.salesperson_id = sp.id AND MONTH(v.visit_date) = $filterMonth AND YEAR(v.visit_date) = $filterYear) AS monthly_visits,
        (SELECT COUNT(*) FROM customers c WHERE c.registered_by = sp.id AND MONTH(c.created_at) = $filterMonth AND YEAR(c.created_at) = $filterYear) AS new_customers,
        COALESCE((SELECT SUM(s3.total_amount) FROM sales s3 WHERE s3.salesperson_id = sp.id AND YEAR(s3.sale_date) = $filterYear), 0) AS ytd_revenue,
        (SELECT COUNT(*) FROM sales s4 WHERE s4.salesperson_id = sp.id AND YEAR(s4.sale_date) = $filterYear) AS ytd_sales,
        (SELECT COUNT(*) FROM visits v2 WHERE v2.salesperson_id = sp.id AND YEAR(v2.visit_date) = $filterYear) AS ytd_visits,
        (SELECT COUNT(*) FROM customers c2 WHERE c2.registered_by = sp.id AND YEAR(c2.created_at) = $filterYear) AS ytd_customers
    FROM salespeople sp
    LEFT JOIN territories t ON sp.territory_id = t.id
    WHERE sp.status = 'active'
    ORDER BY monthly_revenue DESC
")->fetchAll();

// Overall summary
$totalRevenue = 0;
$totalSales = 0;
$totalVisits = 0;
$totalNewCustomers = 0;
foreach ($performance as $p) {
    $totalRevenue += (float) $p['monthly_revenue'];
    $totalSales += (int) $p['monthly_sales'];
    $totalVisits += (int) $p['monthly_visits'];
    $totalNewCustomers += (int) $p['new_customers'];
}

// Chart data - monthly revenue by salesperson
$chartLabels = [];
$chartData = [];
foreach ($performance as $p) {
    if ((float) $p['monthly_revenue'] > 0) {
        $chartLabels[] = $p['name'];
        $chartData[] = (float) $p['monthly_revenue'];
    }
}

// Monthly trend (last 6 months)
$monthTrend = $pdo->query("
    SELECT DATE_FORMAT(s.sale_date, '%Y-%m') AS month_key, DATE_FORMAT(s.sale_date, '%b %Y') AS month_label,
        COUNT(*) AS sale_count, SUM(s.total_amount) AS revenue,
        COUNT(DISTINCT s.salesperson_id) AS active_reps
    FROM sales s
    WHERE s.salesperson_id IS NOT NULL
        AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY month_key, month_label
    ORDER BY month_key ASC
")->fetchAll();
?>
<section>
    <h1>Sales Performance</h1>

    <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:16px">
        <input type="hidden" name="page" value="sales_performance">
        <select name="month" style="margin:0">
            <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>"<?= $filterMonth == $m ? ' selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
            <?php endfor; ?>
        </select>
        <select name="year" style="margin:0">
            <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                <option value="<?= $y ?>"<?= $filterYear == $y ? ' selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
        <button type="submit" style="margin:0">View</button>
    </form>

    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px">
        <div class="card"><h2>Monthly Revenue</h2><p><?= fmt($totalRevenue) ?></p></div>
        <div class="card"><h2>Total Sales</h2><p><?= $totalSales ?></p></div>
        <div class="card"><h2>Total Visits</h2><p><?= $totalVisits ?></p></div>
        <div class="card"><h2>New Customers</h2><p><?= $totalNewCustomers ?></p></div>
    </div>

    <?php if (!empty($chartLabels)): ?>
    <div class="panel" style="margin-bottom:16px;max-width:700px">
        <h2>Revenue by Salesperson</h2>
        <canvas id="revenueChart" height="300"></canvas>
    </div>
    <?php endif; ?>

    <?php if (!empty($monthTrend)): ?>
    <div class="panel" style="margin-bottom:16px;max-width:700px">
        <h2>Monthly Trend (Rep-Attributed Sales)</h2>
        <canvas id="trendChart" height="250"></canvas>
    </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Performance by Salesperson</h2>
        <div style="overflow-x:auto">
        <table>
            <thead><tr><th>Salesperson</th><th>Territory</th><th>Sales</th><th>Revenue</th><th>Visits</th><th>New Customers</th><th>Conversion</th><th>Target</th><th>YTD Revenue</th></tr></thead>
            <tbody>
                <?php if (empty($performance)): ?>
                    <tr><td colspan="9">No salespeople found.</td></tr>
                <?php else: ?>
                    <?php foreach ($performance as $p): ?>
                        <?php
                            $conversion = (int) $p['monthly_visits'] > 0 ? round(((int) $p['monthly_sales'] / (int) $p['monthly_visits']) * 100) : 0;
                            $targetMet = (int) $p['monthly_target'] > 0 && (int) $p['monthly_sales'] >= (int) $p['monthly_target'];
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
                            <td style="font-size:0.85rem">
                                <?= htmlspecialchars((string) ($p['territory_name'] ?? '-')) ?>
                                <?php if ($p['region']): ?><br><small style="color:var(--neutral-500)"><?= htmlspecialchars($p['region']) ?></small><?php endif; ?>
                            </td>
                            <td><?= (int) $p['monthly_sales'] ?></td>
                            <td style="font-weight:700"><?= fmt((float) $p['monthly_revenue']) ?></td>
                            <td><?= (int) $p['monthly_visits'] ?></td>
                            <td><?= (int) $p['new_customers'] ?></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:6px">
                                    <div style="background:var(--neutral-200);border-radius:4px;height:8px;width:60px;overflow:hidden">
                                        <div style="background:<?= $conversion >= 50 ? 'var(--brown-600)' : ($conversion >= 25 ? '#f59e0b' : 'var(--red-600)') ?>;height:100%;width:<?= min($conversion, 100) ?>%"></div>
                                    </div>
                                    <small><?= $conversion ?>%</small>
                                </div>
                            </td>
                            <td>
                                <?php if ((int) $p['monthly_target'] > 0): ?>
                                    <?php if ($targetMet): ?>
                                        <span style="color:var(--brown-600);font-weight:700"><?= (int) $p['monthly_sales'] ?>/<?= (int) $p['monthly_target'] ?></span>
                                        <br><small style="color:var(--brown-600)">Met! +<?= fmt((float) $p['monthly_target_bonus']) ?></small>
                                    <?php else: ?>
                                        <span><?= (int) $p['monthly_sales'] ?>/<?= (int) $p['monthly_target'] ?></span>
                                        <br><small style="color:var(--red-600)"><?= (int) $p['monthly_target'] - (int) $p['monthly_sales'] ?> more</small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:var(--neutral-400)">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?= fmt((float) $p['ytd_revenue']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</section>
<?php if (!empty($chartLabels)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('revenueChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets: [{
            label: 'Revenue (<?= currency_sym() ?>)',
            data: <?= json_encode($chartData) ?>,
            backgroundColor: '#6b4226',
            borderRadius: 6
        }]
    },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});
</script>
<?php endif; ?>
<?php if (!empty($monthTrend)): ?>
<script>
new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($monthTrend, 'month_label')) ?>,
        datasets: [{
            label: 'Revenue',
            data: <?= json_encode(array_map('floatval', array_column($monthTrend, 'revenue'))) ?>,
            borderColor: '#6b4226',
            backgroundColor: 'rgba(107,66,38,0.1)',
            fill: true,
            tension: 0.3
        }, {
            label: 'Sales Count',
            data: <?= json_encode(array_map('intval', array_column($monthTrend, 'sale_count'))) ?>,
            borderColor: '#059669',
            borderDash: [5, 5],
            fill: false,
            tension: 0.3,
            yAxisID: 'y1'
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: { beginAtZero: true, position: 'left' },
            y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false } }
        }
    }
});
</script>
<?php endif; ?>
