<?php
require_once __DIR__ . '/../includes/finance_helpers.php';
ensureFinanceErpMigration(finGetDb());
$pdo = finGetDb();
requirePermission('expenses', 'view');
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['_action'] ?? '';

    if ($action === 'create_expense') {
        $cat = trim($_POST['expense_category'] ?? '');
        $amount = (float) ($_POST['amount'] ?? 0);
        $date = $_POST['expense_date'] ?? date('Y-m-d');
        $method = $_POST['payment_method'] ?? 'cash';
        $desc = trim($_POST['description'] ?? '');
        if ($cat === '' || $amount <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Category and positive amount required.'];
        } else {
            $receiptPath = '';
            if (!empty($_FILES['receipt']['tmp_name'])) {
                $ext = pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION);
                $receiptPath = 'uploads/receipts/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                move_uploaded_file($_FILES['receipt']['tmp_name'], __DIR__ . '/../' . $receiptPath);
            }
            $uid = $_SESSION['user']['id'] ?? null;
            $pdo->prepare('INSERT INTO expenses (expense_category, amount, description, expense_date, payment_method, receipt_path, created_by, approval_status) VALUES (:cat, :amt, :desc, :dt, :pm, :rp, :uid, "approved")')->execute(['cat' => $cat, 'amt' => $amount, 'desc' => $desc, 'dt' => $date, 'pm' => $method, 'rp' => $receiptPath, 'uid' => $uid]);
            // Auto-record bank transaction if bank account selected
            $bankAccId = (int) ($_POST['bank_account_id'] ?? 0);
            if ($bankAccId > 0) {
                bankRecordTransaction($pdo, $bankAccId, 'expense', $amount, '', $desc, null, null, $uid);
            }
            $messages[] = ['type' => 'success', 'text' => 'Expense of ' . finFmt($amount) . ' recorded.'];
        }
    } elseif ($action === 'create_income') {
        $cat = trim($_POST['income_category'] ?? '');
        $amount = (float) ($_POST['amount'] ?? 0);
        $date = $_POST['income_date'] ?? date('Y-m-d');
        $method = $_POST['payment_method'] ?? 'cash';
        $desc = trim($_POST['description'] ?? '');
        if ($cat === '' || $amount <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Category and positive amount required.'];
        } else {
            $uid = $_SESSION['user']['id'] ?? null;
            $pdo->prepare('INSERT INTO income (income_category, amount, description, income_date, payment_method, created_by) VALUES (:cat, :amt, :desc, :dt, :pm, :uid)')->execute(['cat' => $cat, 'amt' => $amount, 'desc' => $desc, 'dt' => $date, 'pm' => $method, 'uid' => $uid]);
            // Auto-record bank transaction if bank account selected
            $bankAccId = (int) ($_POST['bank_account_id'] ?? 0);
            if ($bankAccId > 0) {
                bankRecordTransaction($pdo, $bankAccId, 'deposit', $amount, '', $desc, null, null, $uid);
            }
            $messages[] = ['type' => 'success', 'text' => 'Income of ' . finFmt($amount) . ' recorded.'];
        }
    } elseif ($action === 'delete_expense') {
        $eid = (int) ($_POST['expense_id'] ?? 0);
        if ($eid > 0) {
            $pdo->prepare('DELETE FROM expenses WHERE id = :id')->execute(['id' => $eid]);
            $messages[] = ['type' => 'success', 'text' => 'Expense deleted.'];
        }
    } elseif ($action === 'delete_income') {
        $iid = (int) ($_POST['income_id'] ?? 0);
        if ($iid > 0) {
            $pdo->prepare('DELETE FROM income WHERE id = :id')->execute(['id' => $iid]);
            $messages[] = ['type' => 'success', 'text' => 'Income record deleted.'];
        }
    }
}

$catFilter = $_GET['cat'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

$catIcons = ['rent' => ['building', '#2563eb'], 'utilities' => ['plug', '#d97706'], 'salaries' => ['users', '#059669'], 'office_supplies' => ['pencil-ruler', '#7c3aed'], 'transportation' => ['truck', '#ea580c'], 'maintenance' => ['tools', '#0d9488'], 'marketing' => ['megaphone', '#db2777'], 'insurance' => ['shield-alt', '#4f46e5'], 'tax' => ['percentage', '#dc2626'], 'other' => ['ellipsis-h', '#64748b']];

$expQuery = 'SELECT * FROM expenses WHERE 1=1';
$expParams = [];
if ($catFilter !== '') { $expQuery .= ' AND expense_category = :cat'; $expParams['cat'] = $catFilter; }
$expQuery .= ' AND expense_date BETWEEN :df AND :dt';
$expParams['df'] = $dateFrom;
$expParams['dt'] = $dateTo;
$expQuery .= ' ORDER BY expense_date DESC, id DESC';
$estmt = $pdo->prepare($expQuery);
$estmt->execute($expParams);
$expenses = $estmt->fetchAll();

$incQuery = 'SELECT * FROM income WHERE income_date BETWEEN :df AND :dt ORDER BY income_date DESC, id DESC';
$istmt = $pdo->prepare($incQuery);
$istmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
$incomes = $istmt->fetchAll();

$thisMonthExp = (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE MONTH(expense_date) = MONTH(CURDATE()) AND YEAR(expense_date) = YEAR(CURDATE())')->fetchColumn();
$thisYearExp = (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE YEAR(expense_date) = YEAR(CURDATE())')->fetchColumn();
$thisMonthInc = (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM income WHERE MONTH(income_date) = MONTH(CURDATE()) AND YEAR(income_date) = YEAR(CURDATE())')->fetchColumn();
$avgMonthly = $thisYearExp / max((int) date('n'), 1);

$catSummary = $pdo->query("SELECT expense_category, COALESCE(SUM(amount), 0) AS total FROM expenses WHERE YEAR(expense_date) = YEAR(CURDATE()) GROUP BY expense_category ORDER BY total DESC")->fetchAll();
$maxCat = 0;
foreach ($catSummary as $cs) { if ((float) $cs['total'] > $maxCat) $maxCat = (float) $cs['total']; }

$monthlyBreakdown = $pdo->query("SELECT MONTH(expense_date) AS m, YEAR(expense_date) AS y, COALESCE(SUM(amount), 0) AS total FROM expenses WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY YEAR(expense_date), MONTH(expense_date) ORDER BY y DESC, m DESC")->fetchAll();
?>
<?= finCss() ?>
<section class="fin-wrap">
    <h1><i class="fas fa-receipt" style="color:var(--fin-red)"></i> Expense Management</h1>
    <div class="fin-subtitle">Track business expenses, income and financial categories</div>

    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= ($m['type'] ?? '') === 'success' ? 'success' : 'error' ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <div class="fin-stats">
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#dc2626,#b91c1c)"><i class="fas fa-receipt"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $thisMonthExp ?>" data-prefix="<?= htmlspecialchars(currency_sym()) ?> " data-decimals="2"><?= htmlspecialchars(currency_sym()) ?> 0.00</div>
                <div class="fin-stat-label">Expenses This Month</div>
            </div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#ea580c,#c2410c)"><i class="fas fa-calendar-year"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $thisYearExp ?>" data-prefix="<?= htmlspecialchars(currency_sym()) ?> " data-decimals="2"><?= htmlspecialchars(currency_sym()) ?> 0.00</div>
                <div class="fin-stat-label">Expenses This Year</div>
            </div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#059669,#047857)"><i class="fas fa-chart-line"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $thisMonthInc ?>" data-prefix="<?= htmlspecialchars(currency_sym()) ?> " data-decimals="2"><?= htmlspecialchars(currency_sym()) ?> 0.00</div>
                <div class="fin-stat-label">Income This Month</div>
            </div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#7c3aed,#6d28d9)"><i class="fas fa-calculator"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $avgMonthly ?>" data-prefix="<?= htmlspecialchars(currency_sym()) ?> " data-decimals="2"><?= htmlspecialchars(currency_sym()) ?> 0.00</div>
                <div class="fin-stat-label">Avg Monthly Expense</div>
            </div>
        </div>
    </div>

    <div class="fin-tabs">
        <button class="fin-tab active" onclick="finSwitchTab(this,'tab-expenses')"><i class="fas fa-list"></i> Expenses</button>
        <button class="fin-tab" onclick="finSwitchTab(this,'tab-add')"><i class="fas fa-plus-circle" style="color:var(--fin-green)"></i> Add Expense</button>
        <button class="fin-tab" onclick="finSwitchTab(this,'tab-income')"><i class="fas fa-chart-line" style="color:var(--fin-green)"></i> Income</button>
        <button class="fin-tab" onclick="finSwitchTab(this,'tab-categories')"><i class="fas fa-tags" style="color:var(--fin-purple)"></i> Categories</button>
        <button class="fin-tab" onclick="finSwitchTab(this,'tab-reports')"><i class="fas fa-chart-bar" style="color:var(--fin-blue)"></i> Reports</button>
    </div>

    <div id="tab-expenses" class="fin-panel active">
        <div class="fin-card">
            <div class="fin-toolbar">
                <form method="get" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                    <input type="hidden" name="page" value="finance_expenses">
                    <select name="cat"><option value="">All Categories</option><?php foreach ($catIcons as $k => $v): ?><option value="<?= $k ?>" <?= $catFilter === $k ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $k)) ?></option><?php endforeach; ?></select>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                    <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
                    <button type="submit" class="fin-btn fin-btn-blue fin-btn-sm"><i class="fas fa-filter"></i> Filter</button>
                </form>
            </div>
            <?php if (empty($expenses)): ?>
                <div class="fin-empty"><i class="fas fa-inbox"></i>No expenses found for this period.</div>
            <?php else: ?>
                <div style="overflow-x:auto">
                <table class="fin-table">
                    <thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th><th>Method</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($expenses as $e): ?>
                            <?php $ci = $catIcons[$e['expense_category']] ?? $catIcons['other']; ?>
                            <tr>
                                <td style="white-space:nowrap"><?= date('M d, Y', strtotime($e['expense_date'])) ?></td>
                                <td>
                                    <span style="display:inline-flex;align-items:center;gap:6px">
                                        <span style="width:28px;height:28px;border-radius:6px;background:<?= $ci[1] ?>20;color:<?= $ci[1] ?>;display:flex;align-items:center;justify-content:center;font-size:.7rem"><i class="fas fa-<?= $ci[0] ?>"></i></span>
                                        <?= ucwords(str_replace('_', ' ', $e['expense_category'])) ?>
                                    </span>
                                </td>
                                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--fin-gray)"><?= htmlspecialchars($e['description'] ?? '—') ?></td>
                                <td style="font-weight:700;color:var(--fin-red)"><?= finFmt((float) $e['amount']) ?></td>
                                <td><span class="fin-badge fin-badge-gray"><?= ucwords(str_replace('_', ' ', $e['payment_method'] ?? 'N/A')) ?></span></td>
                                <td>
                                    <form method="post" style="display:inline" onsubmit="return confirm('Delete this expense?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="delete_expense">
                                        <input type="hidden" name="expense_id" value="<?= $e['id'] ?>">
                                        <button type="submit" class="fin-btn fin-btn-sm fin-btn-red" title="Delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div id="tab-add" class="fin-panel">
        <div class="fin-card" style="max-width:560px">
            <h2><i class="fas fa-plus-circle" style="color:var(--fin-green)"></i> Add Expense</h2>
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="create_expense">
                <div class="fin-form-group">
                    <label>Category</label>
                    <select name="expense_category" required>
                        <?php foreach ($catIcons as $k => $v): ?>
                            <option value="<?= $k ?>"><?= ucwords(str_replace('_', ' ', $k)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fin-form-group"><label>Amount (ETB)</label><input type="number" step="0.01" name="amount" min="0.01" required></div>
                <div class="fin-form-group"><label>Date</label><input type="date" name="expense_date" value="<?= date('Y-m-d') ?>" required></div>
                <div class="fin-form-group">
                    <label>Payment Method</label>
                    <select name="payment_method">
                        <option value="cash">Cash</option>
                        <option value="bank">Bank Transfer</option>
                        <option value="cbe_birr">CBE Birr</option>
                        <option value="telebirr">Telebirr</option>
                    </select>
                </div>
                <div class="fin-form-group">
                    <label>Bank Account (auto-update)</label>
                    <?= bankRenderAccountDropdown($pdo, 'bank_account_id', (string)bankGetDefaultAccountId($pdo)) ?>
                </div>
                <div class="fin-form-group"><label>Description</label><textarea name="description" rows="3" placeholder="What was this expense for?"></textarea></div>
                <div class="fin-form-group"><label>Receipt (optional)</label><input type="file" name="receipt" accept="image/*,.pdf"></div>
                <button type="submit" class="fin-btn fin-btn-green"><i class="fas fa-save"></i> Record Expense</button>
            </form>
        </div>
    </div>

    <div id="tab-income" class="fin-panel">
        <div class="fin-grid-2">
            <div class="fin-card">
                <h2><i class="fas fa-plus-circle" style="color:var(--fin-green)"></i> Add Income</h2>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="create_income">
                    <div class="fin-form-group">
                        <label>Category</label>
                        <select name="income_category" required>
                            <option value="investment_income">Investment Income</option>
                            <option value="interest">Interest</option>
                            <option value="rental_income">Rental Income</option>
                            <option value="grant">Grant</option>
                            <option value="donation">Donation</option>
                            <option value="other_income">Other Income</option>
                        </select>
                    </div>
                    <div class="fin-form-group"><label>Amount (ETB)</label><input type="number" step="0.01" name="amount" min="0.01" required></div>
                    <div class="fin-form-group"><label>Date</label><input type="date" name="income_date" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="fin-form-group"><label>Payment Method</label><select name="payment_method"><option value="cash">Cash</option><option value="bank">Bank Transfer</option><option value="cbe_birr">CBE Birr</option><option value="telebirr">Telebirr</option></select></div>
                    <div class="fin-form-group">
                        <label>Bank Account (auto-update)</label>
                        <?= bankRenderAccountDropdown($pdo, 'bank_account_id', (string)bankGetDefaultAccountId($pdo)) ?>
                    </div>
                    <div class="fin-form-group"><label>Description</label><textarea name="description" rows="2"></textarea></div>
                    <button type="submit" class="fin-btn fin-btn-green"><i class="fas fa-save"></i> Record Income</button>
                </form>
            </div>
            <div class="fin-card">
                <h2><i class="fas fa-history" style="color:var(--fin-blue)"></i> Income Records</h2>
                <?php if (empty($incomes)): ?>
                    <div class="fin-empty"><i class="fas fa-inbox"></i>No income records found.</div>
                <?php else: ?>
                    <div style="overflow-x:auto">
                    <table class="fin-table">
                        <thead><tr><th>Date</th><th>Category</th><th>Amount</th><th>Method</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($incomes as $inc): ?>
                                <tr>
                                    <td><?= date('M d', strtotime($inc['income_date'])) ?></td>
                                    <td><?= ucwords(str_replace('_', ' ', $inc['income_category'])) ?></td>
                                    <td style="font-weight:700;color:var(--fin-green)"><?= finFmt((float) $inc['amount']) ?></td>
                                    <td><span class="fin-badge fin-badge-gray"><?= ucfirst($inc['payment_method'] ?? 'N/A') ?></span></td>
                                    <td>
                                        <form method="post" style="display:inline" onsubmit="return confirm('Delete?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="_action" value="delete_income">
                                            <input type="hidden" name="income_id" value="<?= $inc['id'] ?>">
                                            <button type="submit" class="fin-btn fin-btn-sm fin-btn-red"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="tab-categories" class="fin-panel">
        <div class="fin-card">
            <h2><i class="fas fa-tags" style="color:var(--fin-purple)"></i> Expenses by Category (<?= date('Y') ?>)</h2>
            <?php if (empty($catSummary)): ?>
                <div class="fin-empty"><i class="fas fa-chart-bar"></i>No expense data for this year.</div>
            <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:12px">
                    <?php foreach ($catSummary as $cs):
                        $ci = $catIcons[$cs['expense_category']] ?? $catIcons['other'];
                        $pct = $maxCat > 0 ? round(((float) $cs['total'] / $maxCat) * 100) : 0;
                        $ofTotal = $thisYearExp > 0 ? round(((float) $cs['total'] / $thisYearExp) * 100, 1) : 0;
                    ?>
                        <div style="display:flex;align-items:center;gap:12px">
                            <div style="width:140px;font-size:.82rem;font-weight:600;display:flex;align-items:center;gap:6px">
                                <span style="width:24px;height:24px;border-radius:6px;background:<?= $ci[1] ?>20;color:<?= $ci[1] ?>;display:flex;align-items:center;justify-content:center;font-size:.65rem"><i class="fas fa-<?= $ci[0] ?>"></i></span>
                                <?= ucwords(str_replace('_', ' ', $cs['expense_category'])) ?>
                            </div>
                            <div style="flex:1">
                                <div class="fin-progress-wrap" style="height:10px">
                                    <div class="fin-progress-bar" style="width:<?= $pct ?>%;background:<?= $ci[1] ?>"></div>
                                </div>
                            </div>
                            <div style="width:120px;text-align:right;font-size:.82rem;font-weight:700;color:var(--fin-red)"><?= finFmt((float) $cs['total']) ?></div>
                            <div style="width:50px;text-align:right;font-size:.72rem;color:var(--fin-gray)"><?= $ofTotal ?>%</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div id="tab-reports" class="fin-panel">
        <div class="fin-kpi-row">
            <div class="fin-kpi"><div class="fin-kpi-val" style="color:var(--fin-red)" data-counter="<?= $thisMonthExp ?>" data-prefix="<?= htmlspecialchars(currency_sym()) ?> " data-decimals="2"><?= htmlspecialchars(currency_sym()) ?> 0.00</div><div class="fin-kpi-label">This Month</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val" style="color:var(--fin-orange)" data-counter="<?= $thisYearExp ?>" data-prefix="<?= htmlspecialchars(currency_sym()) ?> " data-decimals="2"><?= htmlspecialchars(currency_sym()) ?> 0.00</div><div class="fin-kpi-label">This Year</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val" style="color:var(--fin-green)" data-counter="<?= $thisMonthInc ?>" data-prefix="<?= htmlspecialchars(currency_sym()) ?> " data-decimals="2"><?= htmlspecialchars(currency_sym()) ?> 0.00</div><div class="fin-kpi-label">Income (Month)</div></div>
            <div class="fin-kpi"><div class="fin-kpi-val" style="color:var(--fin-purple)" data-counter="<?= $avgMonthly ?>" data-prefix="<?= htmlspecialchars(currency_sym()) ?> " data-decimals="2"><?= htmlspecialchars(currency_sym()) ?> 0.00</div><div class="fin-kpi-label">Avg Monthly</div></div>
        </div>
        <div class="fin-card">
            <h2><i class="fas fa-calendar" style="color:var(--fin-blue)"></i> Monthly Breakdown (Last 12 Months)</h2>
            <?php if (empty($monthlyBreakdown)): ?>
                <div class="fin-empty"><i class="fas fa-chart-area"></i>No data available.</div>
            <?php else: ?>
                <div style="overflow-x:auto">
                <table class="fin-table">
                    <thead><tr><th>Month</th><th>Year</th><th>Total Expenses</th></tr></thead>
                    <tbody>
                        <?php foreach ($monthlyBreakdown as $mb): ?>
                            <tr>
                                <td style="font-weight:600"><?= date('F', mktime(0, 0, 0, $mb['m'], 1)) ?></td>
                                <td><?= $mb['y'] ?></td>
                                <td style="font-weight:700;color:var(--fin-red)"><?= finFmt((float) $mb['total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
<?= finJs() ?>
