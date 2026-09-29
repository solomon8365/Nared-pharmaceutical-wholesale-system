<?php
require_once __DIR__ . '/../includes/finance_helpers.php';
ensureFinanceErpMigration(finGetDb());
$pdo = finGetDb();
requireFinanceOfficer();
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['_action'] ?? '';

    if ($action === 'cash_in') {
        $accId = (int) ($_POST['cash_account_id'] ?? 0);
        $amount = (float) ($_POST['amount'] ?? 0);
        $category = trim($_POST['category'] ?? 'Sales');
        $ref = trim($_POST['reference_no'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($accId <= 0 || $amount <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Valid account and positive amount required.'];
        } else {
            $res = cashApply($pdo, $accId, 'in', $amount, $category, $ref, $desc, $_SESSION['user']['id'] ?? null);
            if ($res['success']) {
                $messages[] = ['type' => 'success', 'text' => 'Cash In of ' . finFmt($amount) . ' recorded.'];
            } else {
                $messages[] = ['type' => 'error', 'text' => $res['msg']];
            }
        }
    } elseif ($action === 'cash_out') {
        $accId = (int) ($_POST['cash_account_id'] ?? 0);
        $amount = (float) ($_POST['amount'] ?? 0);
        $category = trim($_POST['category'] ?? 'Expense');
        $ref = trim($_POST['reference_no'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($accId <= 0 || $amount <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Valid account and positive amount required.'];
        } else {
            $res = cashApply($pdo, $accId, 'out', $amount, $category, $ref, $desc, $_SESSION['user']['id'] ?? null);
            if ($res['success']) {
                $messages[] = ['type' => 'success', 'text' => 'Cash Out of ' . finFmt($amount) . ' recorded.'];
            } else {
                $messages[] = ['type' => 'error', 'text' => $res['msg']];
            }
        }
    } elseif ($action === 'adjustment') {
        $accId = (int) ($_POST['cash_account_id'] ?? 0);
        $newBalance = (float) ($_POST['new_balance'] ?? 0);
        $desc = trim($_POST['description'] ?? 'Balance adjustment');
        $res = cashApply($pdo, $accId, 'adjustment', 0, 'Adjustment', '', $desc, $_SESSION['user']['id'] ?? null, $newBalance);
        if ($res['success']) {
            $messages[] = ['type' => 'success', 'text' => 'Balance adjusted to ' . finFmt($newBalance) . '.'];
        } else {
            $messages[] = ['type' => 'error', 'text' => $res['msg']];
        }
    } elseif ($action === 'add_account') {
        $name = trim($_POST['account_name'] ?? '');
        $type = $_POST['account_type'] ?? 'main';
        $opening = (float) ($_POST['opening_balance'] ?? 0);
        if ($name === '') {
            $messages[] = ['type' => 'error', 'text' => 'Account name required.'];
        } elseif ($opening < 0) {
            $messages[] = ['type' => 'error', 'text' => 'Opening balance cannot be negative.'];
        } else {
            try {
                $pdo->prepare('INSERT INTO cash_accounts (account_name, account_type, balance, opening_balance) VALUES (:n, :t, :b, :b2)')->execute(['n' => $name, 't' => $type, 'b' => $opening, 'b2' => $opening]);
                $messages[] = ['type' => 'success', 'text' => 'Cash account "' . htmlspecialchars($name) . '" created.'];
            } catch (PDOException $e) {
                $messages[] = ['type' => 'error', 'text' => 'A cash account with that name already exists.'];
            }
        }
    }
}

$accounts = $pdo->query('SELECT * FROM cash_accounts WHERE status = "active" ORDER BY account_type, account_name')->fetchAll();

$today = date('Y-m-d');
$todayIn = (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE transaction_type = "in" AND DATE(created_at) = CURDATE()')->fetchColumn();
$todayOut = (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE transaction_type = "out" AND DATE(created_at) = CURDATE()')->fetchColumn();
$totalCash = (float) $pdo->query('SELECT COALESCE(SUM(balance), 0) FROM cash_accounts WHERE status = "active"')->fetchColumn();
$netChange = $todayIn - $todayOut;

$txType = $_GET['tx_type'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$txAccId = $_GET['tx_account'] ?? '';

$txQuery = 'SELECT ct.*, ca.account_name FROM cash_transactions ct JOIN cash_accounts ca ON ct.cash_account_id = ca.id WHERE 1=1';
$txParams = [];
if ($txType !== '') { $txQuery .= ' AND ct.transaction_type = :tt'; $txParams['tt'] = $txType; }
if ($txAccId !== '') { $txQuery .= ' AND ct.cash_account_id = :aid'; $txParams['aid'] = (int) $txAccId; }
$txQuery .= ' AND DATE(ct.created_at) BETWEEN :df AND :dt';
$txParams['df'] = $dateFrom;
$txParams['dt'] = $dateTo;
$txQuery .= ' ORDER BY ct.created_at DESC LIMIT 200';
$tstmt = $pdo->prepare($txQuery);
$tstmt->execute($txParams);
$transactions = $tstmt->fetchAll();
?>
<?= finCss() ?>
<section class="fin-wrap">
    <h1><i class="fas fa-money-bill-wave" style="color:var(--fin-purple)"></i> Cash Management</h1>
    <div class="fin-subtitle">Manage cash accounts, record transactions and daily closing</div>

    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= $m['type'] ?>" style="padding:10px 14px;border-radius:8px;margin-bottom:12px;background:<?= $m['type'] === 'success' ? '#dcfce7' : '#fee2e2' ?>;color:<?= $m['type'] === 'success' ? '#166534' : '#991b1b' ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <div class="fin-stats">
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#7c3aed,#6d28d9)"><i class="fas fa-wallet"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $totalCash ?>" data-prefix="<?= htmlspecialchars(currency_sym()) ?> " data-decimals="2"><?= htmlspecialchars(currency_sym()) ?> 0.00</div>
                <div class="fin-stat-label">Total Cash Balance</div>
            </div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#059669,#047857)"><i class="fas fa-arrow-down"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $todayIn ?>" data-prefix="<?= htmlspecialchars(currency_sym()) ?> " data-decimals="2"><?= htmlspecialchars(currency_sym()) ?> 0.00</div>
                <div class="fin-stat-label">Cash In Today</div>
            </div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#dc2626,#b91c1c)"><i class="fas fa-arrow-up"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $todayOut ?>" data-prefix="<?= htmlspecialchars(currency_sym()) ?> " data-decimals="2"><?= htmlspecialchars(currency_sym()) ?> 0.00</div>
                <div class="fin-stat-label">Cash Out Today</div>
            </div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,<?= $netChange >= 0 ? '#059669,#047857' : '#dc2626,#b91c1c' ?>)"><i class="fas fa-balance-scale"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $netChange ?>" data-prefix="<?= htmlspecialchars(currency_sym()) ?> " data-decimals="2"><?= htmlspecialchars(currency_sym()) ?> 0.00</div>
                <div class="fin-stat-label">Net Change Today</div>
            </div>
        </div>
    </div>

    <div class="fin-tabs">
        <button class="fin-tab active" onclick="finSwitchTab(this,'tab-overview')"><i class="fas fa-th-large"></i> Overview</button>
        <button class="fin-tab" onclick="finSwitchTab(this,'tab-cashin')"><i class="fas fa-arrow-down" style="color:var(--fin-green)"></i> Cash In</button>
        <button class="fin-tab" onclick="finSwitchTab(this,'tab-cashout')"><i class="fas fa-arrow-up" style="color:var(--fin-red)"></i> Cash Out</button>
        <button class="fin-tab" onclick="finSwitchTab(this,'tab-history')"><i class="fas fa-history"></i> History</button>
        <button class="fin-tab" onclick="finSwitchTab(this,'tab-closing')"><i class="fas fa-calendar-check"></i> Daily Closing</button>
    </div>

    <div id="tab-overview" class="fin-panel active">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
            <div class="fin-section-title"><i class="fas fa-th-large"></i> Cash Accounts</div>
            <button class="fin-btn fin-btn-purple fin-btn-sm" onclick="finOpenModal('modal-add-account')"><i class="fas fa-plus"></i> Add Account</button>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px">
            <?php foreach ($accounts as $acc): ?>
                <div class="fin-card" style="border-left:4px solid <?= $acc['account_type'] === 'main' ? '#7c3aed' : ($acc['account_type'] === 'petty' ? '#d97706' : '#059669') ?>">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px">
                        <div>
                            <div style="font-size:1.2rem;font-weight:800;color:#0f172a" data-counter="<?= $acc['balance'] ?>" data-prefix="<?= htmlspecialchars(currency_sym()) ?> " data-decimals="2"><?= htmlspecialchars(currency_sym()) ?> 0.00</div>
                            <div style="font-size:.82rem;font-weight:600;color:#334155;margin-top:2px"><?= htmlspecialchars($acc['account_name']) ?></div>
                            <div style="font-size:.72rem;color:var(--fin-gray)"><?= ucfirst($acc['account_type']) ?> Account</div>
                        </div>
                        <span class="fin-badge fin-badge-purple"><?= ucfirst($acc['account_type']) ?></span>
                    </div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                        <button class="fin-btn fin-btn-sm fin-btn-green" onclick="document.getElementById('acc-id-in').value='<?= $acc['id'] ?>';finOpenModal('modal-cash-in')"><i class="fas fa-arrow-down"></i> In</button>
                        <button class="fin-btn fin-btn-sm fin-btn-red" onclick="document.getElementById('acc-id-out').value='<?= $acc['id'] ?>';finOpenModal('modal-cash-out')"><i class="fas fa-arrow-up"></i> Out</button>
                        <button class="fin-btn fin-btn-sm fin-btn-outline" onclick="document.getElementById('adj-acc-id').value='<?= $acc['id'] ?>';document.getElementById('adj-cur-bal').value='<?= $acc['balance'] ?>';finOpenModal('modal-adjust')"><i class="fas fa-sliders-h"></i> Adjust</button>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($accounts)): ?>
                <div class="fin-empty" style="grid-column:1/-1"><i class="fas fa-wallet"></i>No cash accounts. Create one to get started.</div>
            <?php endif; ?>
        </div>
    </div>

    <div id="tab-cashin" class="fin-panel">
        <div class="fin-card" style="max-width:520px">
            <h2><i class="fas fa-arrow-down" style="color:var(--fin-green)"></i> Record Cash Received</h2>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="cash_in">
                <div class="fin-form-group">
                    <label>Account</label>
                    <select name="cash_account_id" required>
                        <?php foreach ($accounts as $acc): ?>
                            <option value="<?= $acc['id'] ?>"><?= htmlspecialchars($acc['account_name']) ?> (<?= finFmt($acc['balance']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fin-form-group">
                    <label>Category</label>
                    <select name="category">
                        <option value="Sales">Sales</option>
                        <option value="Refund">Refund Received</option>
                        <option value="Transfer">Transfer</option>
                        <option value="Deposit">Deposit</option>
                        <option value="Investment">Investment</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="fin-form-group"><label>Amount (ETB)</label><input type="number" step="0.01" name="amount" min="0.01" required></div>
                <div class="fin-form-group"><label>Reference No.</label><input type="text" name="reference_no" placeholder="e.g. Receipt #"></div>
                <div class="fin-form-group"><label>Description</label><textarea name="description" rows="2" placeholder="Details of cash received"></textarea></div>
                <button type="submit" class="fin-btn fin-btn-green"><i class="fas fa-check"></i> Record Cash In</button>
            </form>
        </div>
    </div>

    <div id="tab-cashout" class="fin-panel">
        <div class="fin-card" style="max-width:520px">
            <h2><i class="fas fa-arrow-up" style="color:var(--fin-red)"></i> Record Cash Paid</h2>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="cash_out">
                <div class="fin-form-group">
                    <label>Account</label>
                    <select name="cash_account_id" required>
                        <?php foreach ($accounts as $acc): ?>
                            <option value="<?= $acc['id'] ?>"><?= htmlspecialchars($acc['account_name']) ?> (<?= finFmt($acc['balance']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fin-form-group">
                    <label>Category</label>
                    <select name="category">
                        <option value="Expense">Expense</option>
                        <option value="Transfer">Transfer</option>
                        <option value="Withdrawal">Withdrawal</option>
                        <option value="Change">Change Given</option>
                        <option value="Refund">Refund Paid</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="fin-form-group"><label>Amount (ETB)</label><input type="number" step="0.01" name="amount" min="0.01" required></div>
                <div class="fin-form-group"><label>Reference No.</label><input type="text" name="reference_no" placeholder="e.g. Voucher #"></div>
                <div class="fin-form-group"><label>Description</label><textarea name="description" rows="2" placeholder="Details of cash paid"></textarea></div>
                <button type="submit" class="fin-btn fin-btn-red"><i class="fas fa-check"></i> Record Cash Out</button>
            </form>
        </div>
    </div>

    <div id="tab-history" class="fin-panel">
        <div class="fin-card">
            <h2><i class="fas fa-history" style="color:var(--fin-blue)"></i> Transaction History</h2>
            <form method="get" class="fin-toolbar">
                <input type="hidden" name="page" value="finance_cash">
                <select name="tx_type"><option value="">All Types</option><option value="in" <?= $txType === 'in' ? 'selected' : '' ?>>Cash In</option><option value="out" <?= $txType === 'out' ? 'selected' : '' ?>>Cash Out</option><option value="adjustment" <?= $txType === 'adjustment' ? 'selected' : '' ?>>Adjustment</option></select>
                <select name="tx_account"><option value="">All Accounts</option><?php foreach ($accounts as $a): ?><option value="<?= $a['id'] ?>" <?= $txAccId == $a['id'] ? 'selected' : '' ?>><?= htmlspecialchars($a['account_name']) ?></option><?php endforeach; ?></select>
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
                <button type="submit" class="fin-btn fin-btn-blue fin-btn-sm"><i class="fas fa-filter"></i> Filter</button>
            </form>
            <?php if (empty($transactions)): ?>
                <div class="fin-empty"><i class="fas fa-inbox"></i>No transactions found.</div>
            <?php else: ?>
                <div style="overflow-x:auto">
                <table class="fin-table">
                    <thead><tr><th>Date</th><th>Account</th><th>Type</th><th>Category</th><th>Amount</th><th>Balance After</th><th>Reference</th><th>Description</th></tr></thead>
                    <tbody>
                        <?php foreach ($transactions as $t): ?>
                            <?php
                            $typeColors = ['in' => 'green', 'out' => 'red', 'adjustment' => 'orange', 'transfer_in' => 'teal', 'transfer_out' => 'blue'];
                            $cls = $typeColors[$t['transaction_type']] ?? 'gray';
                            $isInflow = $t['transaction_type'] === 'in' || $t['transaction_type'] === 'transfer_in';
                            $isOutflow = $t['transaction_type'] === 'out' || $t['transaction_type'] === 'transfer_out';
                            $amt = (float) $t['amount'];
                            if ($isInflow) { $sign = '+'; $color = 'var(--fin-green)'; }
                            elseif ($isOutflow) { $sign = '-'; $color = 'var(--fin-red)'; }
                            else { $sign = $amt >= 0 ? '+' : '-'; $color = $amt >= 0 ? 'var(--fin-green)' : 'var(--fin-red)'; }
                            ?>
                            <tr>
                                <td style="white-space:nowrap"><?= date('M d, Y H:i', strtotime($t['created_at'])) ?></td>
                                <td style="font-weight:600"><?= htmlspecialchars($t['account_name']) ?></td>
                                <td>
                                    <span class="fin-badge fin-badge-<?= $cls ?>"><?= ucfirst(str_replace('_', ' ', $t['transaction_type'])) ?></span>
                                </td>
                                <td><?= htmlspecialchars($t['category'] ?? '—') ?></td>
                                <td style="font-weight:700;color:<?= $color ?>"><?= $sign ?><?= finFmt(abs($amt)) ?></td>
                                <td style="font-weight:600"><?= finFmt((float) $t['balance_after']) ?></td>
                                <td style="font-size:.78rem;color:var(--fin-gray)"><?= htmlspecialchars($t['reference_no'] ?? '—') ?></td>
                                <td style="font-size:.78rem;color:var(--fin-gray);max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($t['description'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div id="tab-closing" class="fin-panel">
        <div class="fin-grid-2">
            <div class="fin-card">
                <h2><i class="fas fa-calendar-check" style="color:var(--fin-purple)"></i> Daily Cash Closing</h2>
                <p style="font-size:.82rem;color:var(--fin-gray);margin-bottom:14px">Record the physical cash count at end of day and adjust for discrepancies.</p>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="adjustment">
                    <div class="fin-form-group">
                        <label>Account</label>
                        <select name="cash_account_id" id="closing-acc" required>
                            <?php foreach ($accounts as $acc): ?>
                                <option value="<?= $acc['id'] ?>" data-balance="<?= $acc['balance'] ?>"><?= htmlspecialchars($acc['account_name']) ?> (System: <?= finFmt($acc['balance']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fin-form-group"><label>Physical Cash Count (ETB)</label><input type="number" step="0.01" name="new_balance" required></div>
                    <div class="fin-form-group"><label>Notes</label><textarea name="description" rows="2">Daily closing - <?= date('Y-m-d') ?></textarea></div>
                    <button type="submit" class="fin-btn fin-btn-purple"><i class="fas fa-check"></i> Close Day</button>
                </form>
            </div>
            <div class="fin-card">
                <h2><i class="fas fa-list" style="color:var(--fin-blue)"></i> Recent Adjustments</h2>
                <?php
                $adjStmt = $pdo->prepare('SELECT ct.*, ca.account_name FROM cash_transactions ct JOIN cash_accounts ca ON ct.cash_account_id = ca.id WHERE ct.transaction_type = "adjustment" ORDER BY ct.created_at DESC LIMIT 10');
                $adjStmt->execute();
                $adjustments = $adjStmt->fetchAll();
                ?>
                <?php if (empty($adjustments)): ?>
                    <div class="fin-empty"><i class="fas fa-check-circle" style="color:var(--fin-green)"></i>No adjustments recorded.</div>
                <?php else: ?>
                    <table class="fin-table">
                        <thead><tr><th>Date</th><th>Account</th><th>Amount</th><th>New Balance</th><th>Notes</th></tr></thead>
                        <tbody>
                            <?php foreach ($adjustments as $adj): ?>
                                <tr>
                                    <td><?= date('M d, H:i', strtotime($adj['created_at'])) ?></td>
                                    <td style="font-weight:600"><?= htmlspecialchars($adj['account_name']) ?></td>
                                    <td style="font-weight:700"><?= finFmt((float) $adj['amount']) ?></td>
                                    <td><?= finFmt((float) $adj['balance_after']) ?></td>
                                    <td style="font-size:.78rem;color:var(--fin-gray)"><?= htmlspecialchars($adj['description'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="fin-modal-overlay" id="modal-cash-in">
        <div class="fin-modal">
            <h3><i class="fas fa-arrow-down" style="color:var(--fin-green)"></i> Quick Cash In</h3>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="cash_in">
                <input type="hidden" name="cash_account_id" id="acc-id-in">
                <div class="fin-form-group"><label>Amount (ETB)</label><input type="number" step="0.01" name="amount" min="0.01" required></div>
                <div class="fin-form-group"><label>Category</label><select name="category"><option value="Sales">Sales</option><option value="Refund">Refund</option><option value="Transfer">Transfer</option><option value="Other">Other</option></select></div>
                <div class="fin-form-group"><label>Description</label><input type="text" name="description"></div>
                <div style="display:flex;gap:8px"><button type="submit" class="fin-btn fin-btn-green"><i class="fas fa-check"></i> Record</button><button type="button" class="fin-btn fin-btn-outline" onclick="finCloseModal('modal-cash-in')">Cancel</button></div>
            </form>
        </div>
    </div>

    <div class="fin-modal-overlay" id="modal-cash-out">
        <div class="fin-modal">
            <h3><i class="fas fa-arrow-up" style="color:var(--fin-red)"></i> Quick Cash Out</h3>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="cash_out">
                <input type="hidden" name="cash_account_id" id="acc-id-out">
                <div class="fin-form-group"><label>Amount (ETB)</label><input type="number" step="0.01" name="amount" min="0.01" required></div>
                <div class="fin-form-group"><label>Category</label><select name="category"><option value="Expense">Expense</option><option value="Transfer">Transfer</option><option value="Withdrawal">Withdrawal</option><option value="Change">Change</option><option value="Other">Other</option></select></div>
                <div class="fin-form-group"><label>Description</label><input type="text" name="description"></div>
                <div style="display:flex;gap:8px"><button type="submit" class="fin-btn fin-btn-red"><i class="fas fa-check"></i> Record</button><button type="button" class="fin-btn fin-btn-outline" onclick="finCloseModal('modal-cash-out')">Cancel</button></div>
            </form>
        </div>
    </div>

    <div class="fin-modal-overlay" id="modal-adjust">
        <div class="fin-modal">
            <h3><i class="fas fa-sliders-h" style="color:var(--fin-orange)"></i> Adjust Balance</h3>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="adjustment">
                <input type="hidden" name="cash_account_id" id="adj-acc-id">
                <div class="fin-form-group"><label>Current System Balance</label><input type="text" id="adj-cur-bal" readonly style="background:#f8fafc"></div>
                <div class="fin-form-group"><label>Actual Physical Count (ETB)</label><input type="number" step="0.01" name="new_balance" required></div>
                <div class="fin-form-group"><label>Reason</label><textarea name="description" rows="2">Balance adjustment</textarea></div>
                <div style="display:flex;gap:8px"><button type="submit" class="fin-btn fin-btn-orange"><i class="fas fa-check"></i> Adjust</button><button type="button" class="fin-btn fin-btn-outline" onclick="finCloseModal('modal-adjust')">Cancel</button></div>
            </form>
        </div>
    </div>

    <div class="fin-modal-overlay" id="modal-add-account">
        <div class="fin-modal">
            <h3><i class="fas fa-plus-circle" style="color:var(--fin-purple)"></i> New Cash Account</h3>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="add_account">
                <div class="fin-form-group"><label>Account Name</label><input type="text" name="account_name" required placeholder="e.g. Main Cash Register"></div>
                <div class="fin-form-group"><label>Type</label><select name="account_type"><option value="main">Main</option><option value="petty">Petty Cash</option><option value="float">Float</option></select></div>
                <div class="fin-form-group"><label>Opening Balance (ETB)</label><input type="number" step="0.01" name="opening_balance" value="0"></div>
                <div style="display:flex;gap:8px"><button type="submit" class="fin-btn fin-btn-purple"><i class="fas fa-check"></i> Create</button><button type="button" class="fin-btn fin-btn-outline" onclick="finCloseModal('modal-add-account')">Cancel</button></div>
            </form>
        </div>
    </div>
</section>
<?= finJs() ?>
