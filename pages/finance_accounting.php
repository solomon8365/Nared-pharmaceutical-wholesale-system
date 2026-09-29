<?php
require_once __DIR__ . '/../includes/finance_helpers.php';
ensureFinanceErpMigration(finGetDb());
$pdo = finGetDb();
requireFinanceOfficer();
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_account') {
        $code  = trim($_POST['account_code'] ?? '');
        $name  = trim($_POST['account_name'] ?? '');
        $type  = $_POST['account_type'] ?? '';
        $group = trim($_POST['account_group'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        if ($code === '' || $name === '' || $type === '') {
            $messages[] = ['type' => 'error', 'text' => 'Code, Name, and Type are required.'];
        } else {
            $chk = $pdo->prepare('SELECT id FROM chart_of_accounts WHERE account_code = ?');
            $chk->execute([$code]);
            if ($chk->fetch()) {
                $messages[] = ['type' => 'error', 'text' => 'Account code already exists.'];
            } else {
                $ins = $pdo->prepare('INSERT INTO chart_of_accounts (account_code,account_name,account_type,account_group,notes,created_at) VALUES (?,?,?,?,?,NOW())');
                $ins->execute([$code, $name, $type, $group, $notes]);
                $messages[] = ['type' => 'success', 'text' => 'Account ' . $code . ' created.'];
            }
        }
    }

    if ($action === 'create_journal') {
        $entryDate  = $_POST['entry_date'] ?? date('Y-m-d');
        $desc       = trim($_POST['description'] ?? '');
        $submitType = $_POST['submit_type'] ?? 'draft';
        $lineAcct   = $_POST['line_account'] ?? [];
        $lineDebit  = $_POST['line_debit'] ?? [];
        $lineCredit = $_POST['line_credit'] ?? [];
        $lineDesc   = $_POST['line_desc'] ?? [];
        $totalDr = 0.0;
        $totalCr = 0.0;
        $lines   = [];
        for ($i = 0; $i < count($lineAcct); $i++) {
            $dr = (float) ($lineDebit[$i] ?? 0);
            $cr = (float) ($lineCredit[$i] ?? 0);
            if ($lineAcct[$i] === '') continue;
            if ($dr < 0 || $cr < 0) { $messages[] = ['type' => 'error', 'text' => 'Negative amounts not allowed.']; break; }
            if ($dr > 0 && $cr > 0) { $messages[] = ['type' => 'error', 'text' => 'Cannot have debit and credit on same line.']; break; }
            if ($dr == 0 && $cr == 0) { $messages[] = ['type' => 'error', 'text' => 'Each line needs a debit or credit.']; break; }
            $totalDr += $dr;
            $totalCr += $cr;
            $lines[] = ['account_id' => (int) $lineAcct[$i], 'debit' => $dr, 'credit' => $cr, 'description' => trim($lineDesc[$i] ?? '')];
        }
        if (empty($messages)) {
            if (empty($lines)) {
                $messages[] = ['type' => 'error', 'text' => 'At least one line item required.'];
            } elseif (abs($totalDr - $totalCr) > 0.001) {
                $messages[] = ['type' => 'error', 'text' => 'Total debits must equal total credits.'];
            } else {
                $entryNumber = finGenerateEntryNumber($pdo);
                $st = ($submitType === 'post') ? 'posted' : 'draft';
                $postedBy = ($st === 'posted') ? ($_SESSION['user']['id'] ?? null) : null;
                $postedAt = ($st === 'posted') ? date('Y-m-d H:i:s') : null;
                $insE = $pdo->prepare('INSERT INTO journal_entries (entry_date,entry_number,description,status,total_debit,total_credit,created_by,posted_by,posted_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())');
                $insE->execute([$entryDate, $entryNumber, $desc, $st, $totalDr, $totalCr, $_SESSION['user']['id'] ?? null, $postedBy, $postedAt]);
                $entryId = $pdo->lastInsertId();
                $insL = $pdo->prepare('INSERT INTO journal_entry_lines (journal_entry_id,account_id,debit,credit,description,created_at) VALUES (?,?,?,?,?,NOW())');
                foreach ($lines as $l) {
                    $insL->execute([$entryId, $l['account_id'], $l['debit'], $l['credit'], $l['description']]);
                }
                if ($st === 'posted') {
                    foreach ($lines as $l) {
                        $aRow = $pdo->prepare('SELECT account_type FROM chart_of_accounts WHERE id = ?');
                        $aRow->execute([$l['account_id']]);
                        $aR = $aRow->fetch();
                        if ($aR) {
                            $isDebitInc = in_array($aR['account_type'], ['asset', 'expense']);
                            $balChg = $isDebitInc ? ($l['debit'] - $l['credit']) : ($l['credit'] - $l['debit']);
                            $pdo->prepare('UPDATE chart_of_accounts SET current_balance = current_balance + ? WHERE id = ?')->execute([$balChg, $l['account_id']]);
                        }
                    }
                }
                $messages[] = ['type' => 'success', 'text' => 'Journal entry ' . $entryNumber . ' created' . ($st === 'posted' ? ' and posted.' : '.')];
            }
        }
    }

    if ($action === 'post_journal') {
        $jid = (int) ($_POST['journal_id'] ?? 0);
        $chk = $pdo->prepare('SELECT * FROM journal_entries WHERE id = ? AND status = ?');
        $chk->execute([$jid, 'draft']);
        $je = $chk->fetch();
        if (!$je) {
            $messages[] = ['type' => 'error', 'text' => 'Entry not found or not a draft.'];
        } else {
            $pdo->prepare('UPDATE journal_entries SET status = "posted", posted_by = ?, posted_at = NOW() WHERE id = ?')->execute([$_SESSION['user']['id'] ?? null, $jid]);
            $lns = $pdo->prepare('SELECT * FROM journal_entry_lines WHERE journal_entry_id = ?');
            $lns->execute([$jid]);
            while ($ln = $lns->fetch()) {
                $aChk = $pdo->prepare('SELECT account_type FROM chart_of_accounts WHERE id = ?');
                $aChk->execute([$ln['account_id']]);
                $aR = $aChk->fetch();
                if ($aR) {
                    $isDebitInc = in_array($aR['account_type'], ['asset', 'expense']);
                    $balChg = $isDebitInc ? ($ln['debit'] - $ln['credit']) : ($ln['credit'] - $ln['debit']);
                    $pdo->prepare('UPDATE chart_of_accounts SET current_balance = current_balance + ? WHERE id = ?')->execute([$balChg, $ln['account_id']]);
                }
            }
            $messages[] = ['type' => 'success', 'text' => 'Entry ' . $je['entry_number'] . ' posted.'];
        }
    }

    if ($action === 'reverse_journal') {
        $jid = (int) ($_POST['journal_id'] ?? 0);
        $chk = $pdo->prepare('SELECT * FROM journal_entries WHERE id = ? AND status = ?');
        $chk->execute([$jid, 'posted']);
        $je = $chk->fetch();
        if (!$je) {
            $messages[] = ['type' => 'error', 'text' => 'Entry not found or not posted.'];
        } else {
            $revNum  = finGenerateEntryNumber($pdo);
            $revDesc = 'Reversal of ' . $je['entry_number'] . ' - ' . $je['description'];
            $insE = $pdo->prepare('INSERT INTO journal_entries (entry_date,entry_number,description,status,total_debit,total_credit,created_by,reversal_of,created_at) VALUES (CURDATE(),?,?,?,?,?,?,?,NOW())');
            $insE->execute([$revNum, $revDesc, 'draft', $je['total_debit'], $je['total_credit'], $_SESSION['user']['id'] ?? null, $jid]);
            $revId = $pdo->lastInsertId();
            $origLines = $pdo->prepare('SELECT * FROM journal_entry_lines WHERE journal_entry_id = ?');
            $origLines->execute([$jid]);
            $insL = $pdo->prepare('INSERT INTO journal_entry_lines (journal_entry_id,account_id,debit,credit,description,created_at) VALUES (?,?,?,?,?,NOW())');
            while ($ol = $origLines->fetch()) {
                $insL->execute([$revId, $ol['account_id'], $ol['credit'], $ol['debit'], $ol['description']]);
            }
            $pdo->prepare('UPDATE journal_entries SET status = "reversed", reversed_at = NOW() WHERE id = ?')->execute([$jid]);
            $messages[] = ['type' => 'success', 'text' => 'Entry ' . $je['entry_number'] . ' reversed. New: ' . $revNum . ' (draft).'];
        }
    }

    if ($action === 'delete_journal') {
        $jid = (int) ($_POST['journal_id'] ?? 0);
        $chk = $pdo->prepare('SELECT * FROM journal_entries WHERE id = ? AND status = ?');
        $chk->execute([$jid, 'draft']);
        $je = $chk->fetch();
        if (!$je) {
            $messages[] = ['type' => 'error', 'text' => 'Only draft entries can be deleted.'];
        } else {
            $pdo->prepare('DELETE FROM journal_entry_lines WHERE journal_entry_id = ?')->execute([$jid]);
            $pdo->prepare('DELETE FROM journal_entries WHERE id = ?')->execute([$jid]);
            $messages[] = ['type' => 'success', 'text' => 'Entry ' . $je['entry_number'] . ' deleted.'];
        }
    }

    header('Location: finance_accounting.php?tab=' . ($_POST['current_tab'] ?? 'chart'));
    exit;
}

$tab = $_GET['tab'] ?? 'chart';
$validTabs = ['chart', 'ledger', 'journal', 'trial', 'income', 'balance'];
if (!in_array($tab, $validTabs)) $tab = 'chart';

$totalAssets      = (float) $pdo->query("SELECT COALESCE(SUM(current_balance),0) FROM chart_of_accounts WHERE account_type='asset' AND is_active=1")->fetchColumn();
$totalLiabilities = (float) $pdo->query("SELECT COALESCE(SUM(current_balance),0) FROM chart_of_accounts WHERE account_type='liability' AND is_active=1")->fetchColumn();
$totalEquity      = (float) $pdo->query("SELECT COALESCE(SUM(current_balance),0) FROM chart_of_accounts WHERE account_type='equity' AND is_active=1")->fetchColumn();
$totalRevenue     = (float) $pdo->query("SELECT COALESCE(SUM(CASE WHEN a.account_type='income' THEN jel.credit - jel.debit ELSE 0 END),0) FROM journal_entry_lines jel JOIN chart_of_accounts a ON a.id=jel.account_id JOIN journal_entries je ON je.id=jel.journal_entry_id WHERE je.status='posted' AND a.account_type='income'")->fetchColumn();
$totalExpensesAcct= (float) $pdo->query("SELECT COALESCE(SUM(CASE WHEN a.account_type='expense' THEN jel.debit - jel.credit ELSE 0 END),0) FROM journal_entry_lines jel JOIN chart_of_accounts a ON a.id=jel.account_id JOIN journal_entries je ON je.id=jel.journal_entry_id WHERE je.status='posted' AND a.account_type='expense'")->fetchColumn();
$netIncome = $totalRevenue - $totalExpensesAcct;

$allAccounts = $pdo->query("SELECT id, account_code, account_name, account_type FROM chart_of_accounts WHERE is_active=1 ORDER BY account_code")->fetchAll();
$accountTypeColors = ['asset' => 'blue', 'liability' => 'orange', 'equity' => 'purple', 'income' => 'green', 'expense' => 'red'];

$ledgerAccountId = (int) ($_GET['account_id'] ?? 0);
$ledgerFrom      = $_GET['date_from'] ?? date('Y-01-01');
$ledgerTo        = $_GET['date_to'] ?? date('Y-m-d');
$ledgerLines     = [];
$ledgerRunningBal= 0;
if ($ledgerAccountId > 0) {
    $laStmt = $pdo->prepare('SELECT account_type FROM chart_of_accounts WHERE id = ?');
    $laStmt->execute([$ledgerAccountId]);
    $laInfo = $laStmt->fetch();
    $isDebitAcct = $laInfo ? in_array($laInfo['account_type'], ['asset', 'expense']) : true;
    $ls = $pdo->prepare('SELECT jel.*, je.entry_date, je.entry_number, je.description AS entry_desc FROM journal_entry_lines jel JOIN journal_entries je ON je.id = jel.journal_entry_id WHERE jel.account_id = ? AND je.entry_date BETWEEN ? AND ? AND je.status = ? ORDER BY je.entry_date, je.id');
    $ls->execute([$ledgerAccountId, $ledgerFrom, $ledgerTo, 'posted']);
    foreach ($ls->fetchAll() as $rl) {
        $change = $isDebitAcct ? ($rl['debit'] - $rl['credit']) : ($rl['credit'] - $rl['debit']);
        $ledgerRunningBal += $change;
        $rl['running_balance'] = $ledgerRunningBal;
        $ledgerLines[] = $rl;
    }
}

$jFilter = $_GET['jfilter'] ?? 'all';
$jq = 'SELECT je.* FROM journal_entries je';
$jParams = [];
if (in_array($jFilter, ['draft', 'posted', 'reversed'])) {
    $jq .= ' WHERE je.status = ?';
    $jParams[] = $jFilter;
}
$jq .= ' ORDER BY je.entry_date DESC, je.id DESC';
$jStmt = $pdo->prepare($jq);
$jStmt->execute($jParams);
$journalEntries = $jStmt->fetchAll();

$tbAccounts = $pdo->query("SELECT * FROM chart_of_accounts WHERE is_active=1 AND current_balance != 0 ORDER BY account_code")->fetchAll();
$tbTotalDr = 0;
$tbTotalCr = 0;
$tbData = [];
foreach ($tbAccounts as $tb) {
    $bal = (float) $tb['current_balance'];
    if ($bal == 0) continue;
    if (in_array($tb['account_type'], ['asset', 'expense'])) {
        $tbData[] = ['code' => $tb['account_code'], 'name' => $tb['account_name'], 'type' => $tb['account_type'], 'group' => $tb['account_group'], 'debit' => max($bal, 0), 'credit' => max(-$bal, 0)];
        if ($bal > 0) $tbTotalDr += $bal; else $tbTotalCr += abs($bal);
    } else {
        $tbData[] = ['code' => $tb['account_code'], 'name' => $tb['account_name'], 'type' => $tb['account_type'], 'group' => $tb['account_group'], 'debit' => max(-$bal, 0), 'credit' => max($bal, 0)];
        if ($bal > 0) $tbTotalCr += $bal; else $tbTotalDr += abs($bal);
    }
}

$isIncomeRows  = $pdo->query("SELECT account_code, account_name, account_group, current_balance FROM chart_of_accounts WHERE account_type='income' AND is_active=1 ORDER BY account_code")->fetchAll();
$isExpenseRows = $pdo->query("SELECT account_code, account_name, account_group, current_balance FROM chart_of_accounts WHERE account_type='expense' AND is_active=1 ORDER BY account_code")->fetchAll();
$isTotalRevenue = 0;
$isTotalExpenses = 0;
foreach ($isIncomeRows as $r) $isTotalRevenue += (float) $r['current_balance'];
foreach ($isExpenseRows as $r) $isTotalExpenses += (float) $r['current_balance'];

$bsAssets       = $pdo->query("SELECT account_code, account_name, account_group, current_balance FROM chart_of_accounts WHERE account_type='asset' AND is_active=1 AND current_balance != 0 ORDER BY account_code")->fetchAll();
$bsLiabilities  = $pdo->query("SELECT account_code, account_name, account_group, current_balance FROM chart_of_accounts WHERE account_type='liability' AND is_active=1 AND current_balance != 0 ORDER BY account_code")->fetchAll();
$bsEquity       = $pdo->query("SELECT account_code, account_name, account_group, current_balance FROM chart_of_accounts WHERE account_type='equity' AND is_active=1 AND current_balance != 0 ORDER BY account_code")->fetchAll();
$bsTotalAssets  = 0;
$bsTotalLiab    = 0;
$bsTotalEq      = 0;
foreach ($bsAssets as $r) $bsTotalAssets += (float) $r['current_balance'];
foreach ($bsLiabilities as $r) $bsTotalLiab += (float) $r['current_balance'];
foreach ($bsEquity as $r) $bsTotalEq += (float) $r['current_balance'];
$bsTotalEq += $netIncome;

$chartAccounts = $pdo->query("SELECT * FROM chart_of_accounts ORDER BY account_code")->fetchAll();
?>
<?= finCss() ?>
<section class="fin-wrap">
    <h1><i class="fas fa-book" style="color:var(--fin-blue)"></i> Accounting &amp; General Ledger</h1>
    <div class="fin-subtitle">Chart of accounts, journal entries, trial balance, and financial statements</div>

    <?php foreach ($messages as $m): ?>
        <div style="padding:10px 14px;border-radius:8px;margin-bottom:12px;background:<?= $m['type'] === 'success' ? '#dcfce7' : '#fee2e2' ?>;color:<?= $m['type'] === 'success' ? '#166534' : '#991b1b' ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <div class="fin-stats">
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#2563eb,#1d4ed8)"><i class="fas fa-landmark"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $totalAssets ?>" data-prefix="<?= currency_sym() ?> " data-decimals="2"><?= currency_sym() ?> 0.00</div>
                <div class="fin-stat-label">Total Assets</div>
            </div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#d97706,#b45309)"><i class="fas fa-file-invoice-dollar"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $totalLiabilities ?>" data-prefix="<?= currency_sym() ?> " data-decimals="2"><?= currency_sym() ?> 0.00</div>
                <div class="fin-stat-label">Total Liabilities</div>
            </div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#7c3aed,#6d28d9)"><i class="fas fa-balance-scale"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $totalEquity ?>" data-prefix="<?= currency_sym() ?> " data-decimals="2"><?= currency_sym() ?> 0.00</div>
                <div class="fin-stat-label">Total Equity</div>
            </div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#059669,#047857)"><i class="fas fa-chart-line"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $totalRevenue ?>" data-prefix="<?= currency_sym() ?> " data-decimals="2"><?= currency_sym() ?> 0.00</div>
                <div class="fin-stat-label">Total Revenue</div>
            </div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#dc2626,#b91c1c)"><i class="fas fa-receipt"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $totalExpensesAcct ?>" data-prefix="<?= currency_sym() ?> " data-decimals="2"><?= currency_sym() ?> 0.00</div>
                <div class="fin-stat-label">Total Expenses</div>
            </div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,<?= $netIncome >= 0 ? '#059669,#047857' : '#dc2626,#b91c1c' ?>)"><i class="fas fa-dollar-sign"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val" data-counter="<?= $netIncome ?>" data-prefix="<?= currency_sym() ?> " data-decimals="2"><?= currency_sym() ?> 0.00</div>
                <div class="fin-stat-label">Net Income</div>
            </div>
        </div>
    </div>

    <div class="fin-tabs">
        <button class="fin-tab <?= $tab === 'chart' ? 'active' : '' ?>" onclick="finSwitchTab(this,'panel-chart')"><i class="fas fa-sitemap"></i> Chart of Accounts</button>
        <button class="fin-tab <?= $tab === 'ledger' ? 'active' : '' ?>" onclick="finSwitchTab(this,'panel-ledger')"><i class="fas fa-scroll"></i> General Ledger</button>
        <button class="fin-tab <?= $tab === 'journal' ? 'active' : '' ?>" onclick="finSwitchTab(this,'panel-journal')"><i class="fas fa-book-open"></i> Journal Entries</button>
        <button class="fin-tab <?= $tab === 'trial' ? 'active' : '' ?>" onclick="finSwitchTab(this,'panel-trial')"><i class="fas fa-balance-scale-right"></i> Trial Balance</button>
        <button class="fin-tab <?= $tab === 'income' ? 'active' : '' ?>" onclick="finSwitchTab(this,'panel-income')"><i class="fas fa-chart-bar"></i> Income Statement</button>
        <button class="fin-tab <?= $tab === 'balance' ? 'active' : '' ?>" onclick="finSwitchTab(this,'panel-balance')"><i class="fas fa-scale-balanced"></i> Balance Sheet</button>
    </div>

    <!-- ====== CHART OF ACCOUNTS ====== -->
    <div id="panel-chart" class="fin-panel <?= $tab === 'chart' ? 'active' : '' ?>">
        <div class="fin-card" style="max-width:700px">
            <h2><i class="fas fa-plus-circle" style="color:var(--fin-green)"></i> Add Account</h2>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_account">
                <input type="hidden" name="current_tab" value="chart">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="fin-form-group"><label>Account Code</label><input type="text" name="account_code" required maxlength="20" placeholder="e.g. 1050"></div>
                    <div class="fin-form-group"><label>Account Name</label><input type="text" name="account_name" required maxlength="150" placeholder="e.g. Petty Cash"></div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="fin-form-group">
                        <label>Account Type</label>
                        <select name="account_type" required>
                            <option value="asset">Asset</option>
                            <option value="liability">Liability</option>
                            <option value="equity">Equity</option>
                            <option value="income">Income</option>
                            <option value="expense">Expense</option>
                        </select>
                    </div>
                    <div class="fin-form-group"><label>Account Group</label><input type="text" name="account_group" maxlength="100" placeholder="e.g. Cash"></div>
                </div>
                <div class="fin-form-group"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>
                <button type="submit" class="fin-btn fin-btn-green"><i class="fas fa-save"></i> Save Account</button>
            </form>
        </div>
        <div class="fin-card">
            <h2><i class="fas fa-sitemap" style="color:var(--fin-blue)"></i> All Accounts</h2>
            <?php
            $grouped = [];
            foreach ($chartAccounts as $ca) {
                $grouped[$ca['account_type']][] = $ca;
            }
            $typeLabels = ['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity', 'income' => 'Income', 'expense' => 'Expenses'];
            $typeOrder  = ['asset', 'liability', 'equity', 'income', 'expense'];
            ?>
            <?php if (empty($chartAccounts)): ?>
                <div class="fin-empty"><i class="fas fa-inbox"></i>No accounts found. Add your first account above.</div>
            <?php else: ?>
                <div style="overflow-x:auto">
                <table class="fin-table">
                    <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Group</th><th>Balance</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($typeOrder as $tp): ?>
                        <?php if (!empty($grouped[$tp])): ?>
                            <tr><td colspan="6" style="background:#f8fafc;font-weight:700;color:#475569;font-size:.78rem;text-transform:uppercase;letter-spacing:.03em;padding:8px 14px"><?= $typeLabels[$tp] ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ($grouped[$tp] ?? [] as $ca): ?>
                            <tr>
                                <td style="font-weight:700;font-family:monospace"><?= htmlspecialchars($ca['account_code']) ?></td>
                                <td style="font-weight:600"><?= htmlspecialchars($ca['account_name']) ?></td>
                                <td><span class="fin-badge fin-badge-<?= $accountTypeColors[$ca['account_type']] ?? 'gray' ?>"><?= ucfirst($ca['account_type']) ?></span></td>
                                <td style="color:var(--fin-gray)"><?= htmlspecialchars($ca['account_group'] ?? '—') ?></td>
                                <td style="font-weight:700;color:<?= (float) $ca['current_balance'] >= 0 ? 'var(--fin-green)' : 'var(--fin-red)' ?>"><?= finFmt((float) $ca['current_balance']) ?></td>
                                <td><?= $ca['is_active'] ? '<span class="fin-badge fin-badge-green">Active</span>' : '<span class="fin-badge fin-badge-red">Inactive</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ====== GENERAL LEDGER ====== -->
    <div id="panel-ledger" class="fin-panel <?= $tab === 'ledger' ? 'active' : '' ?>">
        <div class="fin-card">
            <h2><i class="fas fa-scroll" style="color:var(--fin-teal)"></i> General Ledger</h2>
            <form method="get" style="margin-bottom:16px">
                <input type="hidden" name="page" value="finance_accounting">
                <input type="hidden" name="tab" value="ledger">
                <div class="fin-toolbar">
                    <select name="account_id" required>
                        <option value="">Select Account...</option>
                        <?php foreach ($allAccounts as $aa): ?>
                            <option value="<?= $aa['id'] ?>" <?= $ledgerAccountId == $aa['id'] ? 'selected' : '' ?>><?= $aa['account_code'] ?> - <?= htmlspecialchars($aa['account_name']) ?> (<?= ucfirst($aa['account_type']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($ledgerFrom) ?>">
                    <input type="date" name="date_to" value="<?= htmlspecialchars($ledgerTo) ?>">
                    <button type="submit" class="fin-btn fin-btn-blue fin-btn-sm"><i class="fas fa-filter"></i> Filter</button>
                </div>
            </form>
            <?php if ($ledgerAccountId == 0): ?>
                <div class="fin-empty"><i class="fas fa-search"></i>Select an account and date range to view ledger entries.</div>
            <?php elseif (empty($ledgerLines)): ?>
                <div class="fin-empty"><i class="fas fa-inbox"></i>No posted entries found for this account in the selected period.</div>
            <?php else: ?>
                <div class="fin-kpi-row" style="margin-bottom:16px">
                    <div class="fin-kpi">
                        <div class="fin-kpi-val" style="color:var(--fin-blue)"><?= finFmt($ledgerRunningBal) ?></div>
                        <div class="fin-kpi-label">Running Balance</div>
                    </div>
                    <div class="fin-kpi">
                        <div class="fin-kpi-val" style="color:var(--fin-green)"><?= finFmt(array_reduce($ledgerLines, function($s,$l){ return $s + (float)$l['debit']; }, 0)) ?></div>
                        <div class="fin-kpi-label">Total Debits</div>
                    </div>
                    <div class="fin-kpi">
                        <div class="fin-kpi-val" style="color:var(--fin-red)"><?= finFmt(array_reduce($ledgerLines, function($s,$l){ return $s + (float)$l['credit']; }, 0)) ?></div>
                        <div class="fin-kpi-label">Total Credits</div>
                    </div>
                </div>
                <div style="overflow-x:auto">
                <table class="fin-table">
                    <thead><tr><th>Date</th><th>Entry #</th><th>Description</th><th>Debit</th><th>Credit</th><th>Running Balance</th></tr></thead>
                    <tbody>
                    <?php foreach ($ledgerLines as $ll): ?>
                        <tr>
                            <td style="white-space:nowrap"><?= date('M d, Y', strtotime($ll['entry_date'])) ?></td>
                            <td style="font-family:monospace;font-weight:600"><?= htmlspecialchars($ll['entry_number']) ?></td>
                            <td style="color:var(--fin-gray)"><?= htmlspecialchars($ll['description'] ?? $ll['entry_desc'] ?? '—') ?></td>
                            <td style="font-weight:700;color:var(--fin-green)"><?= (float) $ll['debit'] > 0 ? finFmt((float) $ll['debit']) : '—' ?></td>
                            <td style="font-weight:700;color:var(--fin-red)"><?= (float) $ll['credit'] > 0 ? finFmt((float) $ll['credit']) : '—' ?></td>
                            <td style="font-weight:700;color:<?= $ll['running_balance'] >= 0 ? 'var(--fin-blue)' : 'var(--fin-red)' ?>"><?= finFmt($ll['running_balance']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ====== JOURNAL ENTRIES ====== -->
    <div id="panel-journal" class="fin-panel <?= $tab === 'journal' ? 'active' : '' ?>">
        <div class="fin-card">
            <h2><i class="fas fa-book-open" style="color:var(--fin-purple)"></i> Journal Entries</h2>
            <div class="fin-toolbar">
                <div class="fin-filter-pills">
                    <?php
                    $jCounts = [];
                    $jCounts['all']     = $pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
                    $jCounts['draft']   = $pdo->query("SELECT COUNT(*) FROM journal_entries WHERE status='draft'")->fetchColumn();
                    $jCounts['posted']  = $pdo->query("SELECT COUNT(*) FROM journal_entries WHERE status='posted'")->fetchColumn();
                    $jCounts['reversed']= $pdo->query("SELECT COUNT(*) FROM journal_entries WHERE status='reversed'")->fetchColumn();
                    $jFilters = ['all' => 'All', 'draft' => 'Draft', 'posted' => 'Posted', 'reversed' => 'Reversed'];
                    $jPillColors = ['all' => '', 'draft' => 'fin-badge-orange', 'posted' => 'fin-badge-green', 'reversed' => 'fin-badge-red'];
                    ?>
                    <?php foreach ($jFilters as $fk => $fl): ?>
                        <a href="?page=finance_accounting&tab=journal&jfilter=<?= $fk ?>" class="fin-pill <?= $jFilter === $fk ? 'active' : '' ?>"><?= $fl ?> (<?= $jCounts[$fk] ?>)</a>
                    <?php endforeach; ?>
                </div>
                <button class="fin-btn fin-btn-green" onclick="document.getElementById('journal-form-card').style.display=document.getElementById('journal-form-card').style.display==='none'?'block':'none'"><i class="fas fa-plus"></i> New Entry</button>
            </div>
        </div>

        <div id="journal-form-card" class="fin-card" style="display:none">
            <h2><i class="fas fa-pen-to-square" style="color:var(--fin-green)"></i> Create Journal Entry</h2>
            <form method="post" id="journalEntryForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_journal">
                <input type="hidden" name="current_tab" value="journal">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="fin-form-group"><label>Date</label><input type="date" name="entry_date" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="fin-form-group"><label>Description</label><input type="text" name="description" placeholder="Entry description" required></div>
                </div>
                <div class="fin-section-title" style="margin-top:12px">Line Items</div>
                <div id="je-lines">
                    <div class="je-line" style="display:grid;grid-template-columns:2fr 1fr 1fr 2fr 40px;gap:8px;margin-bottom:8px;align-items:end">
                        <div class="fin-form-group"><label>Account</label>
                            <select name="line_account[]" required>
                                <option value="">Select...</option>
                                <?php foreach ($allAccounts as $aa): ?>
                                    <option value="<?= $aa['id'] ?>"><?= $aa['account_code'] ?> - <?= htmlspecialchars($aa['account_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="fin-form-group"><label>Debit</label><input type="number" step="0.01" min="0" name="line_debit[]" value="0" class="je-debit" oninput="calcJournalTotals()"></div>
                        <div class="fin-form-group"><label>Credit</label><input type="number" step="0.01" min="0" name="line_credit[]" value="0" class="je-credit" oninput="calcJournalTotals()"></div>
                        <div class="fin-form-group"><label>Description</label><input type="text" name="line_desc[]" placeholder="Line detail"></div>
                        <div class="fin-form-group"><label>&nbsp;</label><button type="button" class="fin-btn fin-btn-sm fin-btn-red" onclick="this.closest('.je-line').remove();calcJournalTotals()"><i class="fas fa-times"></i></button></div>
                    </div>
                </div>
                <button type="button" class="fin-btn fin-btn-sm fin-btn-outline" onclick="addJournalLine()" style="margin-bottom:14px"><i class="fas fa-plus"></i> Add Row</button>
                <div style="display:flex;gap:20px;align-items:center;padding:12px 16px;background:#f8fafc;border-radius:10px;margin-bottom:14px">
                    <div><strong>Total Debit:</strong> <span id="je-total-debit" style="font-weight:700;color:var(--fin-green)"><?= currency_sym() ?> 0.00</span></div>
                    <div><strong>Total Credit:</strong> <span id="je-total-credit" style="font-weight:700;color:var(--fin-red)"><?= currency_sym() ?> 0.00</span></div>
                    <div><strong>Difference:</strong> <span id="je-difference" style="font-weight:700">0.00</span></div>
                </div>
                <div style="display:flex;gap:8px">
                    <button type="submit" name="submit_type" value="draft" class="fin-btn fin-btn-orange"><i class="fas fa-save"></i> Save as Draft</button>
                    <button type="submit" name="submit_type" value="post" class="fin-btn fin-btn-green"><i class="fas fa-check-circle"></i> Post</button>
                </div>
            </form>
        </div>

        <div class="fin-card">
            <?php if (empty($journalEntries)): ?>
                <div class="fin-empty"><i class="fas fa-inbox"></i>No journal entries found.</div>
            <?php else: ?>
                <div style="overflow-x:auto">
                <table class="fin-table">
                    <thead><tr><th>Date</th><th>Entry #</th><th>Description</th><th>Debit</th><th>Credit</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($journalEntries as $je): ?>
                        <?php
                        $stBadge = ['draft' => 'orange', 'posted' => 'green', 'reversed' => 'red'];
                        ?>
                        <tr>
                            <td style="white-space:nowrap"><?= date('M d, Y', strtotime($je['entry_date'])) ?></td>
                            <td style="font-family:monospace;font-weight:700"><?= htmlspecialchars($je['entry_number']) ?></td>
                            <td style="color:var(--fin-gray)"><?= htmlspecialchars($je['description']) ?></td>
                            <td style="font-weight:700;color:var(--fin-green)"><?= finFmt((float) $je['total_debit']) ?></td>
                            <td style="font-weight:700;color:var(--fin-red)"><?= finFmt((float) $je['total_credit']) ?></td>
                            <td><span class="fin-badge fin-badge-<?= $stBadge[$je['status']] ?? 'gray' ?>"><?= ucfirst($je['status']) ?></span></td>
                            <td style="white-space:nowrap">
                                <?php if ($je['status'] === 'draft'): ?>
                                    <form method="post" style="display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="post_journal">
                                        <input type="hidden" name="journal_id" value="<?= $je['id'] ?>">
                                        <input type="hidden" name="current_tab" value="journal">
                                        <button type="submit" class="fin-btn fin-btn-sm fin-btn-green" title="Post"><i class="fas fa-check"></i></button>
                                    </form>
                                    <form method="post" style="display:inline" onsubmit="return confirm('Delete this draft entry?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_journal">
                                        <input type="hidden" name="journal_id" value="<?= $je['id'] ?>">
                                        <input type="hidden" name="current_tab" value="journal">
                                        <button type="submit" class="fin-btn fin-btn-sm fin-btn-red" title="Delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                <?php elseif ($je['status'] === 'posted'): ?>
                                    <form method="post" style="display:inline" onsubmit="return confirm('Reverse this posted entry? A new reversal entry will be created.')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reverse_journal">
                                        <input type="hidden" name="journal_id" value="<?= $je['id'] ?>">
                                        <input type="hidden" name="current_tab" value="journal">
                                        <button type="submit" class="fin-btn fin-btn-sm fin-btn-orange" title="Reverse"><i class="fas fa-undo"></i></button>
                                    </form>
                                <?php endif; ?>
                                <button type="button" class="fin-btn fin-btn-sm fin-btn-outline" title="View Lines" onclick="toggleJELines(<?= $je['id'] ?>)"><i class="fas fa-eye"></i></button>
                            </td>
                        </tr>
                        <tr id="je-lines-<?= $je['id'] ?>" style="display:none">
                            <td colspan="7" style="padding:0 14px 12px;background:#f8fafc">
                                <?php
                                $detailLines = $pdo->prepare('SELECT jel.*, a.account_code, a.account_name FROM journal_entry_lines jel JOIN chart_of_accounts a ON a.id = jel.account_id WHERE jel.journal_entry_id = ?');
                                $detailLines->execute([$je['id']]);
                                $dLines = $detailLines->fetchAll();
                                ?>
                                <table class="fin-table" style="margin-top:8px">
                                    <thead><tr><th>Account</th><th>Debit</th><th>Credit</th><th>Description</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($dLines as $dl): ?>
                                        <tr>
                                            <td style="font-weight:600"><?= htmlspecialchars($dl['account_code']) ?> - <?= htmlspecialchars($dl['account_name']) ?></td>
                                            <td style="font-weight:700;color:var(--fin-green)"><?= (float) $dl['debit'] > 0 ? finFmt((float) $dl['debit']) : '—' ?></td>
                                            <td style="font-weight:700;color:var(--fin-red)"><?= (float) $dl['credit'] > 0 ? finFmt((float) $dl['credit']) : '—' ?></td>
                                            <td style="color:var(--fin-gray)"><?= htmlspecialchars($dl['description'] ?? '—') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ====== TRIAL BALANCE ====== -->
    <div id="panel-trial" class="fin-panel <?= $tab === 'trial' ? 'active' : '' ?>">
        <div class="fin-card">
            <h2><i class="fas fa-balance-scale-right" style="color:var(--fin-indigo)"></i> Trial Balance as of <?= date('F d, Y') ?></h2>
            <?php if (empty($tbData)): ?>
                <div class="fin-empty"><i class="fas fa-inbox"></i>No accounts with balances found. Post journal entries to populate balances.</div>
            <?php else: ?>
                <div style="overflow-x:auto">
                <table class="fin-table">
                    <thead><tr><th>Code</th><th>Account Name</th><th>Type</th><th>Group</th><th style="text-align:right">Debit</th><th style="text-align:right">Credit</th></tr></thead>
                    <tbody>
                    <?php foreach ($tbData as $td): ?>
                        <tr>
                            <td style="font-weight:700;font-family:monospace"><?= htmlspecialchars($td['code']) ?></td>
                            <td style="font-weight:600"><?= htmlspecialchars($td['name']) ?></td>
                            <td><span class="fin-badge fin-badge-<?= $accountTypeColors[$td['type']] ?? 'gray' ?>"><?= ucfirst($td['type']) ?></span></td>
                            <td style="color:var(--fin-gray)"><?= htmlspecialchars($td['group'] ?? '—') ?></td>
                            <td style="text-align:right;font-weight:700;color:var(--fin-green)"><?= $td['debit'] > 0 ? finFmt($td['debit']) : '—' ?></td>
                            <td style="text-align:right;font-weight:700;color:var(--fin-red)"><?= $td['credit'] > 0 ? finFmt($td['credit']) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:#f1f5f9;font-weight:800">
                            <td colspan="4" style="text-align:right;font-size:.85rem">TOTAL</td>
                            <td style="text-align:right;color:var(--fin-green)"><?= finFmt($tbTotalDr) ?></td>
                            <td style="text-align:right;color:var(--fin-red)"><?= finFmt($tbTotalCr) ?></td>
                        </tr>
                        <tr style="background:<?= abs($tbTotalDr - $tbTotalCr) < 0.01 ? '#dcfce7' : '#fee2e2' ?>">
                            <td colspan="4" style="text-align:right;font-weight:800;font-size:.85rem">Difference</td>
                            <td colspan="2" style="text-align:center;font-weight:800;color:<?= abs($tbTotalDr - $tbTotalCr) < 0.01 ? '#166534' : '#991b1b' ?>"><?= finFmt(abs($tbTotalDr - $tbTotalCr)) ?></td>
                        </tr>
                    </tfoot>
                </table>
                </div>
                <div style="margin-top:14px;padding:12px;border-radius:10px;background:<?= abs($tbTotalDr - $tbTotalCr) < 0.01 ? '#dcfce7' : '#fee2e2' ?>;color:<?= abs($tbTotalDr - $tbTotalCr) < 0.01 ? '#166534' : '#991b1b' ?>;font-weight:700;font-size:.85rem;text-align:center">
                    <i class="fas fa-<?= abs($tbTotalDr - $tbTotalCr) < 0.01 ? 'check-circle' : 'exclamation-triangle' ?>"></i>
                    <?= abs($tbTotalDr - $tbTotalCr) < 0.01 ? 'Trial balance is in balance.' : 'Trial balance is OUT OF BALANCE by ' . finFmt(abs($tbTotalDr - $tbTotalCr)) . '. Please review entries.' ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ====== INCOME STATEMENT ====== -->
    <div id="panel-income" class="fin-panel <?= $tab === 'income' ? 'active' : '' ?>">
        <div class="fin-card">
            <h2><i class="fas fa-chart-bar" style="color:var(--fin-green)"></i> Income Statement (Profit &amp; Loss)</h2>
            <div class="fin-section-title"><i class="fas fa-arrow-trend-up" style="color:var(--fin-green)"></i> Revenue</div>
            <?php if (empty($isIncomeRows)): ?>
                <div style="padding:12px;color:var(--fin-gray);font-size:.85rem;margin-bottom:16px">No income accounts with balances.</div>
            <?php else: ?>
                <div style="overflow-x:auto;margin-bottom:16px">
                <table class="fin-table">
                    <thead><tr><th>Code</th><th>Account Name</th><th>Group</th><th style="text-align:right">Amount</th></tr></thead>
                    <tbody>
                    <?php foreach ($isIncomeRows as $ir): ?>
                        <tr>
                            <td style="font-family:monospace;font-weight:700"><?= htmlspecialchars($ir['account_code']) ?></td>
                            <td style="font-weight:600"><?= htmlspecialchars($ir['account_name']) ?></td>
                            <td style="color:var(--fin-gray)"><?= htmlspecialchars($ir['account_group'] ?? '—') ?></td>
                            <td style="text-align:right;font-weight:700;color:var(--fin-green)"><?= finFmt((float) $ir['current_balance']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:#dcfce7;font-weight:800">
                            <td colspan="3" style="text-align:right">Total Revenue</td>
                            <td style="text-align:right;color:var(--fin-green)"><?= finFmt($isTotalRevenue) ?></td>
                        </tr>
                    </tfoot>
                </table>
                </div>
            <?php endif; ?>

            <div class="fin-section-title"><i class="fas fa-arrow-trend-down" style="color:var(--fin-red)"></i> Expenses</div>
            <?php if (empty($isExpenseRows)): ?>
                <div style="padding:12px;color:var(--fin-gray);font-size:.85rem;margin-bottom:16px">No expense accounts with balances.</div>
            <?php else: ?>
                <div style="overflow-x:auto;margin-bottom:16px">
                <table class="fin-table">
                    <thead><tr><th>Code</th><th>Account Name</th><th>Group</th><th style="text-align:right">Amount</th></tr></thead>
                    <tbody>
                    <?php foreach ($isExpenseRows as $er): ?>
                        <tr>
                            <td style="font-family:monospace;font-weight:700"><?= htmlspecialchars($er['account_code']) ?></td>
                            <td style="font-weight:600"><?= htmlspecialchars($er['account_name']) ?></td>
                            <td style="color:var(--fin-gray)"><?= htmlspecialchars($er['account_group'] ?? '—') ?></td>
                            <td style="text-align:right;font-weight:700;color:var(--fin-red)"><?= finFmt((float) $er['current_balance']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:#fee2e2;font-weight:800">
                            <td colspan="3" style="text-align:right">Total Expenses</td>
                            <td style="text-align:right;color:var(--fin-red)"><?= finFmt($isTotalExpenses) ?></td>
                        </tr>
                    </tfoot>
                </table>
                </div>
            <?php endif; ?>

            <div style="padding:18px 20px;border-radius:12px;background:<?= $netIncome >= 0 ? '#dcfce7' : '#fee2e2' ?>;display:flex;justify-content:space-between;align-items:center">
                <div style="font-weight:800;font-size:1rem;color:<?= $netIncome >= 0 ? '#166534' : '#991b1b' ?>">
                    <i class="fas fa-<?= $netIncome >= 0 ? 'trophy' : 'exclamation-triangle' ?>"></i> Net Income
                </div>
                <div style="font-weight:800;font-size:1.3rem;color:<?= $netIncome >= 0 ? '#166534' : '#991b1b' ?>"><?= finFmt($netIncome) ?></div>
            </div>
        </div>
    </div>

    <!-- ====== BALANCE SHEET ====== -->
    <div id="panel-balance" class="fin-panel <?= $tab === 'balance' ? 'active' : '' ?>">
        <div class="fin-card">
            <h2><i class="fas fa-scale-balanced" style="color:var(--fin-blue)"></i> Balance Sheet as of <?= date('F d, Y') ?></h2>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                <div>
                    <div class="fin-section-title"><i class="fas fa-landmark" style="color:var(--fin-blue)"></i> Assets</div>
                    <?php if (empty($bsAssets)): ?>
                        <div style="padding:12px;color:var(--fin-gray);font-size:.85rem">No asset accounts with balances.</div>
                    <?php else: ?>
                        <div style="overflow-x:auto">
                        <table class="fin-table">
                            <thead><tr><th>Code</th><th>Name</th><th style="text-align:right">Balance</th></tr></thead>
                            <tbody>
                            <?php foreach ($bsAssets as $ba): ?>
                                <tr>
                                    <td style="font-family:monospace;font-weight:700"><?= htmlspecialchars($ba['account_code']) ?></td>
                                    <td style="font-weight:600"><?= htmlspecialchars($ba['account_name']) ?></td>
                                    <td style="text-align:right;font-weight:700;color:var(--fin-blue)"><?= finFmt((float) $ba['current_balance']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background:#dbeafe;font-weight:800">
                                    <td colspan="2" style="text-align:right">Total Assets</td>
                                    <td style="text-align:right;color:var(--fin-blue)"><?= finFmt($bsTotalAssets) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                        </div>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="fin-section-title"><i class="fas fa-file-invoice-dollar" style="color:var(--fin-orange)"></i> Liabilities</div>
                    <?php if (empty($bsLiabilities)): ?>
                        <div style="padding:12px;color:var(--fin-gray);font-size:.85rem">No liability accounts with balances.</div>
                    <?php else: ?>
                        <div style="overflow-x:auto;margin-bottom:20px">
                        <table class="fin-table">
                            <thead><tr><th>Code</th><th>Name</th><th style="text-align:right">Balance</th></tr></thead>
                            <tbody>
                            <?php foreach ($bsLiabilities as $bl): ?>
                                <tr>
                                    <td style="font-family:monospace;font-weight:700"><?= htmlspecialchars($bl['account_code']) ?></td>
                                    <td style="font-weight:600"><?= htmlspecialchars($bl['account_name']) ?></td>
                                    <td style="text-align:right;font-weight:700;color:var(--fin-orange)"><?= finFmt((float) $bl['current_balance']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background:#ffedd5;font-weight:800">
                                    <td colspan="2" style="text-align:right">Total Liabilities</td>
                                    <td style="text-align:right;color:var(--fin-orange)"><?= finFmt($bsTotalLiab) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                        </div>
                    <?php endif; ?>

                    <div class="fin-section-title"><i class="fas fa-balance-scale" style="color:var(--fin-purple)"></i> Equity</div>
                    <?php if (empty($bsEquity) && $netIncome == 0): ?>
                        <div style="padding:12px;color:var(--fin-gray);font-size:.85rem">No equity accounts with balances.</div>
                    <?php else: ?>
                        <div style="overflow-x:auto">
                        <table class="fin-table">
                            <thead><tr><th>Code</th><th>Name</th><th style="text-align:right">Balance</th></tr></thead>
                            <tbody>
                            <?php foreach ($bsEquity as $be): ?>
                                <tr>
                                    <td style="font-family:monospace;font-weight:700"><?= htmlspecialchars($be['account_code']) ?></td>
                                    <td style="font-weight:600"><?= htmlspecialchars($be['account_name']) ?></td>
                                    <td style="text-align:right;font-weight:700;color:var(--fin-purple)"><?= finFmt((float) $be['current_balance']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($netIncome != 0): ?>
                                <tr style="background:#ede9fe">
                                    <td colspan="2" style="font-weight:700;color:var(--fin-purple)"><i class="fas fa-calculator"></i> Net Income (Retained)</td>
                                    <td style="text-align:right;font-weight:700;color:<?= $netIncome >= 0 ? 'var(--fin-green)' : 'var(--fin-red)' ?>"><?= finFmt($netIncome) ?></td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background:#ede9fe;font-weight:800">
                                    <td colspan="2" style="text-align:right">Total Equity + Net Income</td>
                                    <td style="text-align:right;color:var(--fin-purple)"><?= finFmt($bsTotalEq) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div style="margin-top:18px;padding:16px 20px;border-radius:12px;background:<?= abs($bsTotalAssets - ($bsTotalLiab + $bsTotalEq)) < 0.01 ? '#dcfce7' : '#fee2e2' ?>;display:flex;justify-content:space-between;align-items:center">
                <div>
                    <strong style="font-size:.9rem;color:<?= $bsTotalAssets - ($bsTotalLiab + $bsTotalEq) >= 0 ? '#166534' : '#991b1b' ?>">
                        <i class="fas fa-scale-balanced"></i> Balance Check: Assets = Liabilities + Equity
                    </strong>
                    <div style="font-size:.78rem;color:<?= abs($bsTotalAssets - ($bsTotalLiab + $bsTotalEq)) < 0.01 ? '#166534' : '#991b1b' ?>">
                        <?= finFmt($bsTotalAssets) ?> = <?= finFmt($bsTotalLiab) ?> + <?= finFmt($bsTotalEq) ?>
                        (Difference: <?= finFmt(abs($bsTotalAssets - ($bsTotalLiab + $bsTotalEq))) ?>)
                    </div>
                </div>
                <div style="font-weight:800;font-size:1rem;color:<?= abs($bsTotalAssets - ($bsTotalLiab + $bsTotalEq)) < 0.01 ? '#166534' : '#991b1b' ?>">
                    <?= abs($bsTotalAssets - ($bsTotalLiab + $bsTotalEq)) < 0.01 ? 'BALANCED' : 'OUT OF BALANCE' ?>
                </div>
            </div>
        </div>
    </div>

</section>

<script>
function addJournalLine() {
    var wrap = document.getElementById('je-lines');
    var accounts = document.querySelector('#je-lines select[name="line_account[]"]');
    if (!accounts) return;
    var html = '<div class="je-line" style="display:grid;grid-template-columns:2fr 1fr 1fr 2fr 40px;gap:8px;margin-bottom:8px;align-items:end">';
    html += '<div class="fin-form-group"><label>Account</label><select name="line_account[]" required>' + accounts.innerHTML + '</select></div>';
    html += '<div class="fin-form-group"><label>Debit</label><input type="number" step="0.01" min="0" name="line_debit[]" value="0" class="je-debit" oninput="calcJournalTotals()"></div>';
    html += '<div class="fin-form-group"><label>Credit</label><input type="number" step="0.01" min="0" name="line_credit[]" value="0" class="je-credit" oninput="calcJournalTotals()"></div>';
    html += '<div class="fin-form-group"><label>Description</label><input type="text" name="line_desc[]" placeholder="Line detail"></div>';
    html += '<div class="fin-form-group"><label>&nbsp;</label><button type="button" class="fin-btn fin-btn-sm fin-btn-red" onclick="this.closest(\'.je-line\').remove();calcJournalTotals()"><i class="fas fa-times"></i></button></div>';
    html += '</div>';
    wrap.insertAdjacentHTML('beforeend', html);
}
function calcJournalTotals() {
    var drs = document.querySelectorAll('.je-debit');
    var crs = document.querySelectorAll('.je-credit');
    var totalDr = 0, totalCr = 0;
    drs.forEach(function(el) { totalDr += parseFloat(el.value) || 0; });
    crs.forEach(function(el) { totalCr += parseFloat(el.value) || 0; });
    var cur = '<?= addslashes(currency_sym()) ?>';
    document.getElementById('je-total-debit').textContent = cur + ' ' + totalDr.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    document.getElementById('je-total-credit').textContent = cur + ' ' + totalCr.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    var diff = Math.abs(totalDr - totalCr);
    var diffEl = document.getElementById('je-difference');
    diffEl.textContent = cur + ' ' + diff.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    diffEl.style.color = diff < 0.01 ? '#059669' : '#dc2626';
}
function toggleJELines(id) {
    var el = document.getElementById('je-lines-' + id);
    if (el) el.style.display = el.style.display === 'none' ? '' : 'none';
}
document.addEventListener('DOMContentLoaded', function() {
    calcJournalTotals();
    var activePanel = document.querySelector('.fin-panel.active');
    if (activePanel) {
        var wrap = activePanel.closest('.fin-wrap') || document;
        var tabBtns = activePanel.closest('.fin-wrap') ? activePanel.closest('.fin-wrap').querySelectorAll('.fin-tab') : [];
    }
});
</script>
<?= finJs() ?>