<?php
requirePermission('commissions', 'view');
require_once __DIR__ . '/../includes/auto_migrate.php';
ensureSalesRepMigration(getPDO());
$pdo = getPDO();
$messages = [];

$filterSp = (int) ($_GET['sp_id'] ?? 0);
$filterType = trim($_GET['type'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterMonth = (int) ($_GET['month'] ?? 0);
$filterYear = (int) ($_GET['year'] ?? date('Y'));
$activeTab = $_GET['tab'] ?? 'list';

$salespeople = $pdo->query("SELECT id, name FROM salespeople WHERE status = 'active' ORDER BY name")->fetchAll();

// Return to the list preserving filters (Post/Redirect/Get).
$returnUrl = '?page=commissions';
$ql = [];
foreach (['sp_id' => (int) ($_GET['sp_id'] ?? 0), 'type' => trim($_GET['type'] ?? ''), 'status' => trim($_GET['status'] ?? ''), 'month' => (int) ($_GET['month'] ?? 0), 'year' => (int) ($_GET['year'] ?? 0), 'tab' => trim($_GET['tab'] ?? '')] as $k => $v) {
    if ($v !== '' && $v !== 0) { $ql[] = $k . '=' . rawurlencode((string) $v); }
}
if (!empty($ql)) { $returnUrl .= '&' . implode('&', $ql); }

// Approve commission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_commission'])) {
    require_csrf();
    $commId = (int) ($_POST['commission_id'] ?? 0);
    if ($commId > 0) {
        if (CommissionService::approve($pdo, $commId, currentUser()['id'])) {
            redirect_with_flash($returnUrl, 'Commission approved.');
        } else {
            $messages[] = ['type' => 'error', 'text' => 'Commission not found or already processed.'];
        }
    }
}

// Reject commission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_commission'])) {
    require_csrf();
    $commId = (int) ($_POST['commission_id'] ?? 0);
    $reason = trim($_POST['rejection_reason'] ?? '');
    if ($commId > 0) {
        if (CommissionService::reject($pdo, $commId, currentUser()['id'], $reason ?: null)) {
            redirect_with_flash($returnUrl, 'Commission rejected.');
        }
    }
}

// Mark commission as paid
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_commission'])) {
    require_csrf();
    $commId = (int) ($_POST['commission_id'] ?? 0);
    if ($commId > 0) {
        if (CommissionService::pay($pdo, $commId, currentUser()['id'])) {
            redirect_with_flash($returnUrl, 'Commission marked as paid.');
        } else {
            $messages[] = ['type' => 'error', 'text' => 'Commission not found or not approved yet.'];
        }
    }
}

// Batch approve/pay
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_action'])) {
    require_csrf();
    $action = $_POST['batch_action'];
    $ids = array_map('intval', $_POST['comm_ids'] ?? []);
    if (!empty($ids)) {
        if ($action === 'approve') {
            $count = CommissionService::approveMany($pdo, $ids, currentUser()['id']);
            redirect_with_flash($returnUrl, $count . ' commission(s) approved.');
        } elseif ($action === 'pay') {
            $count = CommissionService::payMany($pdo, $ids, currentUser()['id']);
            redirect_with_flash($returnUrl, $count . ' commission(s) marked as paid.');
        }
    }
}

// Build query
$where = ['1=1'];
$params = [];
if ($filterSp > 0) { $where[] = 'c.salesperson_id = :spid'; $params['spid'] = $filterSp; }
if ($filterType !== '') { $where[] = 'c.commission_type = :type'; $params['type'] = $filterType; }
if ($filterStatus === 'pending') { $where[] = 'c.status = \'pending\''; }
elseif ($filterStatus === 'approved') { $where[] = 'c.status = \'approved\''; }
elseif ($filterStatus === 'paid') { $where[] = 'c.status = \'paid\''; }
elseif ($filterStatus === 'rejected') { $where[] = 'c.status = \'rejected\''; }
if ($filterMonth > 0) { $where[] = 'c.commission_month = :month'; $params['month'] = $filterMonth; }
if ($filterYear > 0) { $where[] = 'c.commission_year = :year'; $params['year'] = $filterYear; }

$sql = "SELECT c.*, sp.name AS salesperson_name,
    CASE c.reference_type
        WHEN 'sale' THEN (SELECT CONCAT('Sale #', s.id, ' - ', cu.name) FROM sales s LEFT JOIN customers cu ON s.customer_id = cu.id WHERE s.id = c.reference_id)
        WHEN 'customer_order' THEN (SELECT CONCAT('Order #', co.id, ' - ', cu2.name) FROM customer_orders co LEFT JOIN customers cu2 ON co.customer_id = cu2.id WHERE co.id = c.reference_id)
        WHEN 'customer' THEN (SELECT name FROM customers WHERE id = c.reference_id)
        ELSE NULL
    END AS reference_desc
    FROM commissions c
    LEFT JOIN salespeople sp ON c.salesperson_id = sp.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY c.created_at DESC LIMIT 200";

$comms = $pdo->prepare($sql);
$comms->execute($params);
$commissions = $comms->fetchAll();

// Summary aggregations
$aggSql = "SELECT COUNT(*) AS total_count,
    COALESCE(SUM(commission_amount),0) AS total_amt,
    COALESCE(SUM(CASE WHEN status='pending' THEN commission_amount ELSE 0 END),0) AS pending_amt,
    COALESCE(SUM(CASE WHEN status='approved' THEN commission_amount ELSE 0 END),0) AS approved_amt,
    COALESCE(SUM(CASE WHEN status='paid' THEN commission_amount ELSE 0 END),0) AS paid_amt,
    COALESCE(SUM(CASE WHEN status='rejected' THEN commission_amount ELSE 0 END),0) AS rejected_amt,
    SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending_count,
    SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS approved_count,
    SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) AS paid_count
    FROM commissions c WHERE " . implode(' AND ', $where);
$agg = $pdo->prepare($aggSql);
$agg->execute($params);
$agg = $agg->fetch();

// Monthly summary by salesperson (for monthly report tab)
$monthlyRows = $pdo->query("
    SELECT sp.id AS spid, sp.name, sp.commission_rate, sp.new_customer_bonus, sp.monthly_target, sp.monthly_target_bonus,
        COALESCE(SUM(CASE WHEN c.commission_type='sale' AND c.status='paid' THEN c.commission_amount ELSE 0 END),0) AS sale_commissions,
        COALESCE(SUM(CASE WHEN c.commission_type='new_customer' AND c.status='paid' THEN c.commission_amount ELSE 0 END),0) AS nc_commissions,
        (SELECT COUNT(*) FROM commissions cc WHERE cc.salesperson_id = sp.id AND cc.commission_type='new_customer' AND cc.status='paid' AND cc.commission_year = $filterYear) AS new_customers,
        (SELECT COUNT(*) FROM sales ss WHERE ss.salesperson_id = sp.id AND MONTH(ss.sale_date) = $filterMonth AND YEAR(ss.sale_date) = $filterYear) AS monthly_sales_count,
        COALESCE((SELECT SUM(ss2.total_amount) FROM sales ss2 WHERE ss2.salesperson_id = sp.id AND MONTH(ss2.sale_date) = $filterMonth AND YEAR(ss2.sale_date) = $filterYear),0) AS monthly_revenue
    FROM salespeople sp
    LEFT JOIN commissions c ON c.salesperson_id = sp.id AND c.commission_year = $filterYear AND c.commission_month = $filterMonth
    WHERE sp.status = 'active'
    GROUP BY sp.id, sp.name, sp.commission_rate, sp.new_customer_bonus, sp.monthly_target, sp.monthly_target_bonus
    ORDER BY sp.name
")->fetchAll();

$paymentHistory = $pdo->query("SELECT c.*, sp.name AS salesperson_name FROM commissions c LEFT JOIN salespeople sp ON c.salesperson_id = sp.id WHERE c.status = 'paid' ORDER BY c.paid_at DESC LIMIT 30")->fetchAll();
?>
<section>
    <h1>Commissions</h1>
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px">
        <div class="card"><h2>Total Commissions</h2><p><?= fmt((float) $agg['total_amt']) ?></p></div>
        <div class="card" style="border-top-color:#f59e0b"><h2>Pending</h2><p style="color:#f59e0b"><?= fmt((float) $agg['pending_amt']) ?></p><small><?= (int) ($agg['pending_count'] ?? 0) ?> records</small></div>
        <div class="card" style="border-top-color:#3b82f6"><h2>Approved (Unpaid)</h2><p style="color:#3b82f6"><?= fmt((float) $agg['approved_amt']) ?></p><small><?= (int) ($agg['approved_count'] ?? 0) ?> records</small></div>
        <div class="card" style="border-top-color:var(--brown-600)"><h2>Paid</h2><p style="color:var(--brown-600)"><?= fmt((float) $agg['paid_amt']) ?></p><small><?= (int) ($agg['paid_count'] ?? 0) ?> records</small></div>
    </div>

    <div class="tab-bar">
        <button class="tab-btn<?= $activeTab === 'list' ? ' active' : '' ?>" onclick="switchTab(this,'comm-list')"><i class="fas fa-list"></i> All Commissions</button>
        <button class="tab-btn<?= $activeTab === 'monthly' ? ' active' : '' ?>" onclick="switchTab(this,'comm-monthly')"><i class="fas fa-calendar-alt"></i> Monthly Report</button>
        <button class="tab-btn<?= $activeTab === 'history' ? ' active' : '' ?>" onclick="switchTab(this,'comm-history')"><i class="fas fa-credit-card"></i> Payment History</button>
    </div>
    <div class="tab-content">

        <!-- All Commissions -->
        <div id="comm-list" class="tab-panel<?= $activeTab === 'list' ? ' active' : '' ?>">
            <div class="panel">
                <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
                    <input type="hidden" name="page" value="commissions">
                    <input type="hidden" name="tab" value="list">
                    <select name="sp_id" style="margin:0;min-width:140px">
                        <option value="0">All Salespeople</option>
                        <?php foreach ($salespeople as $sp): ?>
                            <option value="<?= (int) $sp['id'] ?>"<?= $filterSp === (int) $sp['id'] ? ' selected' : '' ?>><?= htmlspecialchars($sp['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="type" style="margin:0">
                        <option value="">All Types</option>
                        <option value="sale"<?= $filterType === 'sale' ? ' selected' : '' ?>>Sale Commission</option>
                        <option value="new_customer"<?= $filterType === 'new_customer' ? ' selected' : '' ?>>New Customer</option>
                        <option value="monthly_target"<?= $filterType === 'monthly_target' ? ' selected' : '' ?>>Monthly Target</option>
                    </select>
                    <select name="status" style="margin:0">
                        <option value="">All Status</option>
                        <option value="pending"<?= $filterStatus === 'pending' ? ' selected' : '' ?>>Pending</option>
                        <option value="approved"<?= $filterStatus === 'approved' ? ' selected' : '' ?>>Approved</option>
                        <option value="paid"<?= $filterStatus === 'paid' ? ' selected' : '' ?>>Paid</option>
                        <option value="rejected"<?= $filterStatus === 'rejected' ? ' selected' : '' ?>>Rejected</option>
                    </select>
                    <button type="submit" style="margin:0">Filter</button>
                </form>

                <form method="post">
                    <?= csrf_field() ?>
                    <div style="display:flex;gap:8px;margin-bottom:8px">
                        <button type="submit" name="batch_action" value="approve" onclick="return confirm('Approve selected?')">Approve Selected</button>
                        <button type="submit" name="batch_action" value="pay" onclick="return confirm('Mark selected as paid?')">Pay Selected</button>
                    </div>
                    <table>
                        <thead><tr><th><input type="checkbox" id="checkAll" onclick="toggleAll(this)"></th><th>Salesperson</th><th>Type</th><th>Reference</th><th>Sale Amount</th><th>Rate</th><th>Commission</th><th>Status</th><th>Date</th><th></th></tr></thead>
                        <tbody>
                            <?php if (empty($commissions)): ?>
                                <tr><td colspan="10">No commissions found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($commissions as $c): ?>
                                    <tr>
                                        <td><input type="checkbox" name="comm_ids[]" value="<?= (int) $c['id'] ?>" class="comm-check"></td>
                                        <td><strong><?= htmlspecialchars($c['salesperson_name'] ?? '-') ?></strong></td>
                                        <td><span style="font-size:0.78rem;padding:2px 8px;border-radius:4px;background:<?= $c['commission_type'] === 'sale' ? '#dbeafe' : ($c['commission_type'] === 'new_customer' ? '#d1fae5' : '#fef3c7') ?>;color:<?= $c['commission_type'] === 'sale' ? '#1d4ed8' : ($c['commission_type'] === 'new_customer' ? '#047857' : '#b45309') ?>"><?= $c['commission_type'] === 'sale' ? 'Sale' : ($c['commission_type'] === 'new_customer' ? 'New Customer' : 'Monthly Target') ?></span></td>
                                        <td style="font-size:0.85rem"><?= htmlspecialchars((string) ($c['reference_desc'] ?? '-')) ?></td>
                                        <td><?= $c['sale_amount'] !== null ? fmt((float) $c['sale_amount']) : '-' ?></td>
                                        <td><?= $c['commission_rate'] !== null ? (float) $c['commission_rate'] . '%' : '-' ?></td>
                                        <td style="font-weight:700;color:var(--brown-600)"><?= fmt((float) $c['commission_amount']) ?></td>
                                        <td>
                                            <?php if ($c['status'] === 'pending'): ?>
                                                <span style="color:#f59e0b;font-weight:600">Pending</span>
                                            <?php elseif ($c['status'] === 'approved'): ?>
                                                <span style="color:#3b82f6;font-weight:600">Approved</span>
                                            <?php elseif ($c['status'] === 'paid'): ?>
                                                <span style="color:var(--brown-600);font-weight:700">Paid <?= $c['paid_at'] ? htmlspecialchars(date('M j', strtotime($c['paid_at']))) : '' ?></span>
                                            <?php elseif ($c['status'] === 'rejected'): ?>
                                                <span style="color:var(--red-600);font-weight:600">Rejected</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size:0.82rem"><?= htmlspecialchars($c['created_at']) ?></td>
                                        <td style="white-space:nowrap">
                                            <?php if ($c['status'] === 'pending'): ?>
                                                <form method="post" style="display:inline" onsubmit="return confirm('Approve this commission?')">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="commission_id" value="<?= (int) $c['id'] ?>">
                                                    <button type="submit" name="approve_commission" style="font-size:0.78rem;padding:2px 8px;background:var(--brown-600);color:#fff;border:none;border-radius:4px;cursor:pointer">Approve</button>
                                                </form>
                                                <form method="post" style="display:inline" onsubmit="return confirm('Reject this commission?')">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="commission_id" value="<?= (int) $c['id'] ?>">
                                                    <input type="text" name="rejection_reason" placeholder="Reason" style="width:100px;margin:0;font-size:0.78rem">
                                                    <button type="submit" name="reject_commission" style="font-size:0.78rem;padding:2px 8px;background:var(--red-600);color:#fff;border:none;border-radius:4px;cursor:pointer">Reject</button>
                                                </form>
                                            <?php elseif ($c['status'] === 'approved'): ?>
                                                <form method="post" style="display:inline" onsubmit="return confirm('Mark as paid?')">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="commission_id" value="<?= (int) $c['id'] ?>">
                                                    <button type="submit" name="pay_commission" style="font-size:0.78rem;padding:2px 8px;background:var(--brown-600);color:#fff;border:none;border-radius:4px;cursor:pointer">Pay</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </form>
            </div>
        </div>

        <!-- Monthly Report -->
        <div id="comm-monthly" class="tab-panel<?= $activeTab === 'monthly' ? ' active' : '' ?>">
            <div class="panel">
                <form method="get" style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
                    <input type="hidden" name="page" value="commissions">
                    <input type="hidden" name="tab" value="monthly">
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
                    <button type="submit" style="margin:0">View Report</button>
                </form>
                <table>
                    <thead><tr><th>Salesperson</th><th>Commission Rate</th><th>Sales Count</th><th>Revenue</th><th>Sale Commissions</th><th>New Customers</th><th>New Customer Bonus</th><th>Total Earned</th><th>Paid</th></tr></thead>
                    <tbody>
                        <?php if (empty($monthlyRows)): ?>
                            <tr><td colspan="9">No data for this period.</td></tr>
                        <?php else: ?>
                            <?php foreach ($monthlyRows as $mr): ?>
                                <?php
                                    $totalEarned = (float) $mr['sale_commissions'] + (float) $mr['nc_commissions'];
                                    // Check if monthly target bonus is earned
                                    $targetBonus = 0;
                                    if ((int) $mr['monthly_target'] > 0 && (int) $mr['monthly_sales_count'] >= (int) $mr['monthly_target']) {
                                        $targetBonus = (float) $mr['monthly_target_bonus'];
                                        $totalEarned += $targetBonus;
                                    }
                                    $totalPaid = $totalEarned; // simplified for display
                                ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($mr['name']) ?></strong></td>
                                    <td><?= (float) $mr['commission_rate'] ?>%</td>
                                    <td><?= (int) $mr['monthly_sales_count'] ?></td>
                                    <td><?= fmt((float) $mr['monthly_revenue']) ?></td>
                                    <td><?= fmt((float) $mr['sale_commissions']) ?></td>
                                    <td><?= (int) $mr['new_customers'] ?></td>
                                    <td><?= fmt((float) $mr['nc_commissions']) ?></td>
                                    <td style="font-weight:700;color:var(--brown-600)"><?= fmt($totalEarned) ?></td>
                                    <td>
                                        <?php if ((int) $mr['monthly_target'] > 0): ?>
                                            <small>Target: <?= (int) $mr['monthly_target'] ?> orders</small>
                                            <?php if ((int) $mr['monthly_sales_count'] >= (int) $mr['monthly_target']): ?>
                                                <br><span style="color:var(--brown-600);font-weight:600">Target met! +<?= fmt($targetBonus) ?></span>
                                            <?php else: ?>
                                                <br><span style="color:var(--neutral-500)"> <?= (int) $mr['monthly_target'] - (int) $mr['monthly_sales_count'] ?> more needed</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Payment History -->
        <div id="comm-history" class="tab-panel<?= $activeTab === 'history' ? ' active' : '' ?>">
            <div class="panel" style="border-top-color:var(--brown-600)">
                <h2>Payment History</h2>
                <?php if (empty($paymentHistory)): ?>
                    <p class="hint">No commission payments recorded yet.</p>
                <?php else: ?>
                <table>
                    <thead><tr><th>Date</th><th>Salesperson</th><th>Type</th><th>Amount</th><th>Reference</th></tr></thead>
                    <tbody>
                        <?php foreach ($paymentHistory as $ph): ?>
                            <tr>
                                <td><?= htmlspecialchars($ph['paid_at'] ? date('M j, Y H:i', strtotime($ph['paid_at'])) : '-') ?></td>
                                <td><strong><?= htmlspecialchars($ph['salesperson_name'] ?? '-') ?></strong></td>
                                <td><?= ucfirst(str_replace('_', ' ', $ph['commission_type'])) ?></td>
                                <td style="font-weight:700;color:var(--brown-600)"><?= fmt((float) $ph['commission_amount']) ?></td>
                                <td style="font-size:0.85rem"><?= htmlspecialchars((string) ($ph['reference_desc'] ?? $ph['notes'] ?? '-')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
<script>
function toggleAll(cb) {
    document.querySelectorAll('.comm-check').forEach(function(c) { c.checked = cb.checked; });
}
</script>
