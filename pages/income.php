<?php
requirePermission('income', 'view');
$pdo = getPDO();
$messages = [];

$categories = ['investment_income','interest','rental_income','grant','donation','other_income'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['_action'] ?? '';

    if ($action === 'create') {
        $category = $_POST['category'] ?? '';
        $amount = (float) ($_POST['amount'] ?? 0);
        $desc = trim($_POST['description'] ?? '');
        $incDate = $_POST['income_date'] ?? date('Y-m-d');
        $payMethod = $_POST['payment_method'] ?? '';

        if ($category === '' || $amount <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Category and positive amount required.'];
        } else {
            $pdo->prepare("INSERT INTO income (income_category, amount, description, income_date, payment_method, created_by) VALUES (:cat, :amt, :desc, :dt, :pm, :uid)")
                ->execute(['cat' => $category, 'amt' => $amount, 'desc' => $desc, 'dt' => $incDate, 'pm' => $payMethod, 'uid' => (int)($_SESSION['user_id'] ?? 0)]);
            $messages[] = ['type' => 'success', 'text' => 'Income recorded.'];
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM income WHERE id = :id')->execute(['id' => $id]);
        $messages[] = ['type' => 'success', 'text' => 'Income deleted.'];
    }
}

$from = $_GET['date_from'] ?? date('Y-m-01');
$to = $_GET['date_to'] ?? date('Y-m-d');
$stmt = $pdo->prepare('SELECT i.*, u.username FROM income i LEFT JOIN users u ON i.created_by = u.id WHERE i.income_date BETWEEN :from AND :to ORDER BY i.income_date DESC, i.created_at DESC');
$stmt->execute(['from' => $from, 'to' => $to]);
$incomes = $stmt->fetchAll();

$totStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) AS total FROM income WHERE income_date BETWEEN :from AND :to');
$totStmt->execute(['from' => $from, 'to' => $to]);
$totalIncome = (float) $totStmt->fetchColumn();
?>
<section>
    <h1>Non-Sale Income</h1>
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <div class="tab-bar">
        <button class="tab-btn active" onclick="switchTab(this,'inc-create')"><i class="fas fa-plus"></i> Record Income</button>
        <button class="tab-btn" onclick="switchTab(this,'inc-list')"><i class="fas fa-list"></i> Income List</button>
    </div>

    <div class="tab-content">
        <div id="inc-create" class="tab-panel active">
            <div class="panel">
                <h2>Record Non-Sale Income</h2>
                <form method="post" class="form-grid" style="max-width:450px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="create">
                    <label>Category
                        <select name="category" required>
                            <option value="">Select</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= $c ?>"><?= ucfirst(str_replace('_', ' ', $c)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Amount (<?= currency_sym() ?>)
                        <input type="number" step="0.01" name="amount" min="0.01" required>
                    </label>
                    <label>Date
                        <input type="date" name="income_date" value="<?= date('Y-m-d') ?>" required>
                    </label>
                    <label>Payment Method
                        <select name="payment_method">
                            <option value="">—</option>
                            <option>Cash</option>
                            <option>Bank Transfer</option>
                            <option>Mobile Money</option>
                            <option>Cheque</option>
                            <option>Other</option>
                        </select>
                    </label>
                    <label>Description<textarea name="description" rows="3"></textarea></label>
                    <button type="submit">Save Income</button>
                </form>
            </div>
        </div>

        <div id="inc-list" class="tab-panel">
            <div class="panel">
                <h2>Income History</h2>
                <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
                    <input type="hidden" name="page" value="income">
                    <label style="font-size:0.82rem;display:flex;align-items:center;gap:4px">From <input type="date" name="date_from" value="<?= htmlspecialchars($from) ?>" style="margin:0;width:auto"></label>
                    <label style="font-size:0.82rem;display:flex;align-items:center;gap:4px">To <input type="date" name="date_to" value="<?= htmlspecialchars($to) ?>" style="margin:0;width:auto"></label>
                    <button type="submit" style="margin:0">Filter</button>
                </form>
                <div style="overflow-x:auto">
                <table>
                    <thead><tr><th>Date</th><th>Category</th><th>Amount</th><th>Payment Method</th><th>Description</th><th>Recorded By</th><th></th></tr></thead>
                    <tbody>
                        <?php if (empty($incomes)): ?>
                            <tr><td colspan="7">No income records found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($incomes as $inc): ?>
                                <tr>
                                    <td><?= htmlspecialchars($inc['income_date']) ?></td>
                                    <td><?= ucfirst(str_replace('_',' ', htmlspecialchars($inc['income_category']))) ?></td>
                                    <td style="font-weight:600;color:#059669">+<?= fmt((float)$inc['amount']) ?></td>
                                    <td><?= htmlspecialchars($inc['payment_method'] ?? '—') ?></td>
                                    <td style="font-size:0.82rem"><?= htmlspecialchars($inc['description'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($inc['username'] ?? '?') ?></td>
                                    <td>
                                        <form method="post" onsubmit="return confirm('Delete this income record?')" style="display:inline">
                                            <?= csrf_field() ?><input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="<?= $inc['id'] ?>">
                                            <button type="submit" style="padding:8px 12px;min-height:36px;font-size:0.78rem;background:#dc2626;color:#fff;border:none;border-radius:4px;cursor:pointer">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight:700"><td colspan="2">Total</td><td style="color:#059669">+<?= fmt($totalIncome) ?></td><td colspan="4"></td></tr>
                    </tfoot>
                </table>
                </div>
            </div>
        </div>
    </div>
</section>
