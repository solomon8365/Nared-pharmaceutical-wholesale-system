<?php
// Finance officers reach this from their sidebar and hold credit_management
// (not credit_reports). Accept either grant, matching the Customer Ledger
// page (pages/ledger.php) which already treats the two as equivalent.
if (!hasPermission('credit_reports', 'view') && !hasPermission('credit_management', 'view')) {
    requirePermission('credit_reports', 'view');
}
require_once __DIR__ . '/../includes/finance_helpers.php';
$pdo = finGetDb();

$customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$dateFrom   = $_GET['date_from'] ?? date('Y-m-01');
$dateTo     = $_GET['date_to'] ?? date('Y-m-d');

$customers = $pdo->query("SELECT id, name, customer_code, phone FROM customers WHERE customer_status='active' ORDER BY name")->fetchAll();

$customer = null;
$transactions = [];
$openingBalance = 0;
if ($customerId) {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = :id");
    $stmt->execute(['id' => $customerId]);
    $customer = $stmt->fetch();

    if ($customer) {
        $debitLines = [];
        $creditLines = [];

        $ordStmt = $pdo->prepare("SELECT id, order_date, total_amount, payment_method, status FROM customer_orders WHERE customer_id = :cid AND order_date BETWEEN :d1 AND :d2 AND status != 'rejected' ORDER BY order_date ASC, id ASC");
        $ordStmt->execute(['cid' => $customerId, 'd1' => $dateFrom, 'd2' => $dateTo]);
        foreach ($ordStmt->fetchAll() as $o) {
            $debitLines[] = [
                'date'    => $o['order_date'],
                'type'    => 'Sale',
                'desc'    => 'Order #' . $o['id'] . ' (' . ucfirst($o['payment_method']) . ')',
                'debit'   => (float)$o['total_amount'],
                'credit'  => 0,
                'ref'     => 'order',
                'ref_id'  => $o['id'],
            ];
        }

        $invStmt = $pdo->prepare("SELECT id, invoice_no, total_amount, generated_at, payment_status FROM invoices WHERE customer_id = :cid AND generated_at BETWEEN :d1 AND :d2 AND sales_type = 'credit' ORDER BY generated_at ASC, id ASC");
        $invStmt->execute(['cid' => $customerId, 'd1' => $dateFrom . ' 00:00:00', 'd2' => $dateTo . ' 23:59:59']);
        $seenOrders = [];
        foreach ($invStmt->fetchAll() as $inv) {
            $seenOrders[$inv['id']] = true;
            $debitLines[] = [
                'date'    => substr($inv['generated_at'], 0, 10),
                'type'    => 'Credit Invoice',
                'desc'    => $inv['invoice_no'],
                'debit'   => (float)$inv['total_amount'],
                'credit'  => 0,
                'ref'     => 'invoice',
                'ref_id'  => $inv['id'],
            ];
        }

        $payStmt = $pdo->prepare("SELECT id, amount, payment_date, payment_method, reference_no, notes FROM customer_credit_payments WHERE customer_id = :cid AND payment_date BETWEEN :d1 AND :d2 ORDER BY payment_date ASC, id ASC");
        $payStmt->execute(['cid' => $customerId, 'd1' => $dateFrom, 'd2' => $dateTo]);
        foreach ($payStmt->fetchAll() as $p) {
            $creditLines[] = [
                'date'    => $p['payment_date'],
                'type'    => 'Payment',
                'desc'    => ($p['reference_no'] ? $p['reference_no'] . ' — ' : '') . ucfirst($p['payment_method'] ?? 'cash'),
                'debit'   => 0,
                'credit'  => (float)$p['amount'],
                'ref'     => 'payment',
                'ref_id'  => $p['id'],
            ];
        }

        $refStmt = $pdo->prepare("SELECT r.id, r.amount, r.reason, r.refunded_at, co.id AS order_id FROM refunds r JOIN customer_orders co ON co.id = r.customer_order_id WHERE r.customer_order_id IN (SELECT id FROM customer_orders WHERE customer_id = :cid) AND DATE(r.refunded_at) BETWEEN :d1 AND :d2 AND r.status NOT IN ('rejected') ORDER BY r.refunded_at ASC, r.id ASC");
        $refStmt->execute(['cid' => $customerId, 'd1' => $dateFrom, 'd2' => $dateTo]);
        foreach ($refStmt->fetchAll() as $r) {
            $creditLines[] = [
                'date'    => substr($r['refunded_at'], 0, 10),
                'type'    => 'Refund',
                'desc'    => 'Refund — Order #' . $r['order_id'],
                'debit'   => 0,
                'credit'  => (float)$r['amount'],
                'ref'     => 'refund',
                'ref_id'  => $r['id'],
            ];
        }

        $srStmt = $pdo->prepare("SELECT sr.id, sr.total_amount, sr.refund_amount, sr.return_date, sr.reason FROM sales_returns sr WHERE sr.customer_id = :cid AND sr.return_date BETWEEN :d1 AND :d2 AND sr.status = 'returned' ORDER BY sr.return_date ASC, sr.id ASC");
        $srStmt->execute(['cid' => $customerId, 'd1' => $dateFrom, 'd2' => $dateTo]);
        foreach ($srStmt->fetchAll() as $sr) {
            $creditLines[] = [
                'date'    => $sr['return_date'],
                'type'    => 'Return',
                'desc'    => 'Sales return #' . $sr['id'],
                'debit'   => 0,
                'credit'  => (float)$sr['refund_amount'],
                'ref'     => 'return',
                'ref_id'  => $sr['id'],
            ];
        }

        $transactions = array_merge($debitLines, $creditLines);
        usort($transactions, function ($a, $b) {
            return $a['date'] <=> $b['date'] ?: ($a['debit'] > 0 ? 0 : 1) <=> ($b['debit'] > 0 ? 0 : 1);
        });

        $openStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) AS total_orders FROM customer_orders WHERE customer_id = :cid AND order_date < :d1 AND status != 'rejected'");
        $openStmt->execute(['cid' => $customerId, 'd1' => $dateFrom]);
        $openOrders = (float)$openStmt->fetch()['total_orders'];

        $openPayStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS total_payments FROM customer_credit_payments WHERE customer_id = :cid AND payment_date < :d1");
        $openPayStmt->execute(['cid' => $customerId, 'd1' => $dateFrom]);
        $openPayments = (float)$openPayStmt->fetch()['total_payments'];

        $openRefStmt = $pdo->prepare("SELECT COALESCE(SUM(r.amount), 0) AS total_refunds FROM refunds r JOIN customer_orders co ON co.id = r.customer_order_id WHERE co.customer_id = :cid AND DATE(r.refunded_at) < :d1 AND r.status NOT IN ('rejected')");
        $openRefStmt->execute(['cid' => $customerId, 'd1' => $dateFrom]);
        $openRefunds = (float)$openRefStmt->fetch()['total_refunds'];

        $openSrStmt = $pdo->prepare("SELECT COALESCE(SUM(sr.refund_amount), 0) AS total_returns FROM sales_returns sr WHERE sr.customer_id = :cid AND sr.return_date < :d1 AND sr.status = 'returned'");
        $openSrStmt->execute(['cid' => $customerId, 'd1' => $dateFrom]);
        $openReturns = (float)$openSrStmt->fetch()['total_returns'];

        $openingBalance = $openOrders - $openPayments - $openRefunds - $openReturns;
    }
}

if (isset($_GET['export']) && $_GET['export'] === 'print') {
    echo '<script>window.print();</script>';
}
?>
<style>
.stmt{font-family:inherit;color:#1f2937}
.stmt *{box-sizing:border-box}
.stmt-hdr{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:14px;margin-bottom:20px}
.stmt-hdr h1{margin:0;font-size:1.35rem;font-weight:800;color:#1e3a5f;display:flex;align-items:center;gap:10px}
.stmt-hdr h1 i{color:#2563eb}
.stmt-filters{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:18px;padding:14px;background:#fff;border:1px solid #e5e7eb;border-radius:10px}
.stmt-filters label{display:flex;flex-direction:column;gap:4px;font-size:.78rem;font-weight:600;color:#374151}
.stmt-filters select,.stmt-filters input{padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.82rem}
.stmt-info{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:18px}
.stmt-info-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px;border-top:3px solid #2563eb}
.stmt-info-card .lbl{font-size:.72rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.03em}
.stmt-info-card .val{font-size:1.1rem;font-weight:800;color:#111827;margin-top:4px}
.stmt-info-card .sub{font-size:.72rem;color:#9ca3af;margin-top:2px}
.stmt-tbl{width:100%;border-collapse:collapse;font-size:.8rem}
.stmt-tbl th{text-align:left;font-size:.7rem;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;font-weight:700;padding:10px 12px;border-bottom:2px solid #e5e7eb;white-space:nowrap}
.stmt-tbl td{padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.stmt-tbl tbody tr:last-child td{border-bottom:none}
.stmt-tbl tbody tr:hover{background:#f8fafc}
.stmt-tbl .num{text-align:right;font-weight:700;font-variant-numeric:tabular-nums}
.stmt-tbl .dr{color:#dc2626}
.stmt-tbl .cr{color:#059669}
.stmt-tbl .bal{font-weight:800;color:#111827}
.stmt-tbl .bal.neg{color:#dc2626}
.stmt-tbl .bal.pos{color:#059669}
.stmt-totals{display:flex;justify-content:flex-end;gap:24px;margin-top:12px;padding:12px 16px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;font-size:.82rem}
.stmt-totals .st-item{display:flex;flex-direction:column;align-items:center;gap:2px}
.stmt-totals .st-val{font-size:1.1rem;font-weight:800}
.stmt-empty{padding:40px;text-align:center;color:#9ca3af;font-size:.85rem}
.stmt-empty i{display:block;font-size:1.5rem;margin-bottom:8px;color:#cbd5e1}
@media(max-width:480px){.stmt-totals{flex-wrap:wrap;gap:12px;justify-content:flex-start}}
@media print{.stmt-filters,.fin-no-print,.export-btn-wrap,.stmt-actions{display:none!important}body{font-size:11px}.stmt-tbl th,.stmt-tbl td{padding:6px 8px;font-size:.75rem}}
</style>

<section class="stmt">
    <div class="stmt-hdr">
        <div>
            <h1><i class="fas fa-file-invoice"></i> Customer Statement</h1>
            <div style="font-size:.8rem;color:#6b7280;margin-top:3px">Transaction history with running balance</div>
        </div>
        <?php if ($customer): ?>
        <div class="export-btn-wrap stmt-actions fin-no-print" style="display:flex;gap:8px">
            <a href="?page=customer_statement&customer_id=<?= $customerId ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&export=print" class="fin-btn fin-btn-outline" target="_blank"><i class="fas fa-print"></i> Print</a>
        </div>
        <?php endif; ?>
    </div>

    <form method="get" class="stmt-filters fin-no-print">
        <input type="hidden" name="page" value="customer_statement">
        <label>Customer
            <select name="customer_id" required>
                <option value="">— Select customer —</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?= $c['id'] ?>"<?= $customerId === (int)$c['id'] ? ' selected' : '' ?>><?= htmlspecialchars($c['name']) ?><?= $c['customer_code'] ? ' (' . htmlspecialchars($c['customer_code']) . ')' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>From<input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"></label>
        <label>To<input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"></label>
        <button type="submit" class="fin-btn fin-btn-blue" style="align-self:flex-end"><i class="fas fa-filter"></i> Show</button>
    </form>

<?php if ($customer): ?>
    <?php
        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($transactions as $t) {
            $totalDebit += $t['debit'];
            $totalCredit += $t['credit'];
        }
        $closingBalance = $openingBalance + $totalDebit - $totalCredit;
    ?>
    <div class="stmt-info">
        <div class="stmt-info-card">
            <div class="lbl">Customer</div>
            <div class="val"><?= htmlspecialchars($customer['name']) ?></div>
            <div class="sub"><?= htmlspecialchars($customer['customer_code'] ?? '') ?></div>
        </div>
        <div class="stmt-info-card">
            <div class="lbl">Phone</div>
            <div class="val"><?= htmlspecialchars($customer['phone'] ?? '—') ?></div>
        </div>
        <div class="stmt-info-card">
            <div class="lbl">Period</div>
            <div class="val" style="font-size:.85rem"><?= htmlspecialchars($dateFrom) ?> → <?= htmlspecialchars($dateTo) ?></div>
        </div>
        <div class="stmt-info-card">
            <div class="lbl">Opening Balance</div>
            <div class="val <?= $openingBalance < 0 ? 'dr' : 'cr' ?>" style="color:<?= $openingBalance > 0 ? '#059669' : ($openingBalance < 0 ? '#dc2626' : '#111827') ?>"><?= currency_sym() ?> <?= number_format($openingBalance, 2) ?></div>
        </div>
        <div class="stmt-info-card">
            <div class="lbl">Closing Balance</div>
            <div class="val" style="color:<?= $closingBalance > 0 ? '#059669' : ($closingBalance < 0 ? '#dc2626' : '#111827') ?>"><?= currency_sym() ?> <?= number_format($closingBalance, 2) ?></div>
        </div>
    </div>

    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden">
        <?php if (empty($transactions)): ?>
            <div class="stmt-empty"><i class="fas fa-receipt"></i>No transactions found for this period.</div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="stmt-tbl">
            <thead><tr><th>Date</th><th>Type</th><th>Description</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead>
            <tbody>
                <?php if ($openingBalance != 0): ?>
                <tr>
                    <td><?= htmlspecialchars($dateFrom) ?></td>
                    <td>Opening</td>
                    <td>Balance brought forward</td>
                    <td class="num">—</td>
                    <td class="num">—</td>
                    <td class="num bal <?= $openingBalance < 0 ? 'neg' : 'pos' ?>"><?= currency_sym() ?> <?= number_format($openingBalance, 2) ?></td>
                </tr>
                <?php endif; ?>
                <?php $running = $openingBalance; foreach ($transactions as $t):
                    $running += $t['debit'] - $t['credit'];
                ?>
                <tr>
                    <td><?= htmlspecialchars($t['date']) ?></td>
                    <td><?= htmlspecialchars($t['type']) ?></td>
                    <td><?= htmlspecialchars($t['desc']) ?></td>
                    <td class="num dr"><?= $t['debit'] > 0 ? currency_sym() . ' ' . number_format($t['debit'], 2) : '—' ?></td>
                    <td class="num cr"><?= $t['credit'] > 0 ? currency_sym() . ' ' . number_format($t['credit'], 2) : '—' ?></td>
                    <td class="num bal <?= $running < 0 ? 'neg' : 'pos' ?>"><?= currency_sym() ?> <?= number_format($running, 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div class="stmt-totals">
            <div class="st-item"><span class="lbl" style="font-size:.72rem;color:#6b7280;font-weight:600">Total Debit</span><span class="st-val dr" style="color:#dc2626"><?= currency_sym() ?> <?= number_format($totalDebit, 2) ?></span></div>
            <div class="st-item"><span class="lbl" style="font-size:.72rem;color:#6b7280;font-weight:600">Total Credit</span><span class="st-val" style="color:#059669"><?= currency_sym() ?> <?= number_format($totalCredit, 2) ?></span></div>
            <div class="st-item"><span class="lbl" style="font-size:.72rem;color:#6b7280;font-weight:600">Closing Balance</span><span class="st-val" style="color:<?= $closingBalance > 0 ? '#059669' : ($closingBalance < 0 ? '#dc2626' : '#111827') ?>"><?= currency_sym() ?> <?= number_format($closingBalance, 2) ?></span></div>
        </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php if ($customerId && !$customer): ?>
    <div class="stmt-empty"><i class="fas fa-user-slash"></i>Customer not found.</div>
<?php endif; ?>
<?php if (!$customerId): ?>
    <div class="stmt-empty"><i class="fas fa-search"></i>Select a customer and click Show to view their statement.</div>
<?php endif; ?>
</section>
