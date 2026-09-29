<?php
require_once __DIR__ . '/../services/init.php';
requireCustomer();
$pdo = getPDO();
$cid = customerAccountCustomerId();
if ($cid === null) { echo '<div class="panel"><p>Your account is not linked to a customer profile.</p></div>'; return; }

$tab = $_GET['tab'] ?? 'pending';
$paymentMsg = '';

/* ── Handle Payment Submission ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'make_payment') {
    require_csrf();
    $invId = (int)($_POST['invoice_id'] ?? 0);
    $method = $_POST['payment_method'] ?? 'cash';
    $ref = trim($_POST['reference'] ?? '');
    $amount = (float)($_POST['pay_amount'] ?? 0);
    $payNotes = trim($_POST['payment_notes'] ?? '');

    $receiptPath = null;
    if (!empty($_FILES['receipt']['tmp_name']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg','image/png','image/gif','image/webp','application/pdf'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['receipt']['tmp_name']);
        if (in_array($mime, $allowed, true)) {
            $ext = pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION) ?: 'bin';
            $dir = __DIR__ . '/../uploads/receipts';
            if (!is_dir($dir)) { mkdir($dir, 0755, true); }
            $receiptPath = 'uploads/receipts/' . date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            move_uploaded_file($_FILES['receipt']['tmp_name'], __DIR__ . '/../' . $receiptPath);
        }
    }

    $res = PaymentService::recordPayment($pdo, $invId, $amount, $method, $ref, $receiptPath, $payNotes, (int)($_SESSION['user']['id'] ?? 0), $cid);
    $paymentMsg = ['type' => $res['success'] ? 'success' : 'error', 'text' => $res['msg']];
    if ($res['success']) {
        $tab = 'history';
    }
}

/* ── Data ── */
$allInvoices = $pdo->prepare('SELECT i.*, co.total_amount AS order_total, co.status AS order_status, co.delivery_status FROM invoices i LEFT JOIN customer_orders co ON i.customer_order_id = co.id WHERE i.customer_id = :cid ORDER BY i.generated_at DESC');
$allInvoices->execute(['cid' => $cid]);
$allInvoices = $allInvoices->fetchAll();

$pending = []; $paid = []; $partial = [];
$totalOutstanding = 0; $totalPaid = 0;
foreach ($allInvoices as $inv) {
    if ($inv['payment_status'] === 'paid') { $paid[] = $inv; }
    elseif ($inv['payment_status'] === 'partial') { $partial[] = $inv; $totalOutstanding += (float)$inv['outstanding_balance']; }
    else { $pending[] = $inv; $totalOutstanding += (float)$inv['outstanding_balance']; }
    $totalPaid += (float)$inv['amount_paid'];
}

$creditLimit = (float) ($customer['credit_limit'] ?? 0);
$creditUsed = (float) ($customer['credit_used'] ?? 0);
$creditRemaining = max($creditLimit - $creditUsed, 0);

$showInvoices = $tab === 'paid' ? $paid : ($tab === 'partial' ? $partial : $pending);

/* ── Load payment history for all invoices ── */
$paymentsByInvoice = [];
if (!empty($allInvoices)) {
    $invIds = array_column($allInvoices, 'id');
    $placeholders = implode(',', array_fill(0, count($invIds), '?'));
    $phStmt = $pdo->prepare("SELECT ip.*, i.invoice_no, i.total_amount AS inv_total FROM invoice_payments ip JOIN invoices i ON ip.invoice_id = i.id WHERE ip.invoice_id IN ($placeholders) ORDER BY ip.created_at ASC");
    $phStmt->execute($invIds);
    foreach ($phStmt->fetchAll() as $ph) {
        $paymentsByInvoice[(int)$ph['invoice_id']][] = $ph;
    }
}

$msg = $_GET['msg'] ?? '';
?>
<style>
.pay{max-width:1200px;margin:0 auto}
.pay-title{font-size:1.3rem;font-weight:700;color:#1e293b;margin:0 0 20px;display:flex;align-items:center;gap:10px}
.pay-title i{color:#2563eb}
.pay-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.pay-stat{background:#fff;border-radius:12px;padding:18px 20px;border:1px solid #e2e8f0;display:flex;align-items:center;gap:14px;transition:all 0.2s}
.pay-stat:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(0,0,0,0.06)}
.pay-stat-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.pay-stat-val{font-size:1.3rem;font-weight:800;color:#1e293b}
.pay-stat-label{font-size:0.75rem;font-weight:600;color:#64748b;text-transform:uppercase}
.pay-tabs{display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:20px}
.pay-tab{padding:10px 22px;font-size:0.88rem;font-weight:600;color:#64748b;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;text-decoration:none;display:flex;align-items:center;gap:6px;transition:all 0.15s}
.pay-tab:hover{color:#1e293b}
.pay-tab.active{color:#2563eb;border-bottom-color:#2563eb}
.pay-tab-count{background:#f1f5f9;padding:2px 8px;border-radius:12px;font-size:0.72rem;font-weight:700}
.pay-tab.active .pay-tab-count{background:#eff6ff;color:#2563eb}
.pay-panel{background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:24px}
.pay-panel-head{padding:18px 22px;border-bottom:1px solid #f1f5f9}
.pay-panel-title{font-size:0.95rem;font-weight:700;color:#1e293b;display:flex;align-items:center;gap:8px}
.pay-panel-title i{color:#2563eb}
.pay-panel-body{padding:20px 22px}
.pay-table{width:100%;border-collapse:collapse}
.pay-table th{padding:10px 14px;text-align:left;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color:#64748b;background:#f8fafc;border-bottom:1px solid #e2e8f0}
.pay-table td{padding:12px 14px;font-size:0.85rem;color:#1e293b;border-bottom:1px solid #f1f5f9}
.pay-table tr:last-child td{border-bottom:none}
.pay-table tr:hover td{background:#f8fafc}
.pay-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:0.72rem;font-weight:600}
.pay-badge.paid{background:#f0fdf4;color:#15803d}
.pay-badge.unpaid{background:#fef2f2;color:#dc2626}
.pay-badge.partial{background:#fffbeb;color:#b45309}
.pay-badge.overdue{background:#fef2f2;color:#dc2626;animation:payflash 1.5s ease infinite}
@keyframes payflash{0%,100%{opacity:1}50%{opacity:0.6}}
.pay-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-weight:600;font-size:0.82rem;cursor:pointer;transition:all 0.15s;border:none;font-family:inherit;text-decoration:none}
.pay-btn-primary{background:#2563eb;color:#fff}.pay-btn-primary:hover{background:#1d4ed8}
.pay-btn-success{background:#16a34a;color:#fff}.pay-btn-success:hover{background:#15803d}
.pay-btn-outline{background:#fff;color:#1e293b;border:1.5px solid #e2e8f0}.pay-btn-outline:hover{border-color:#2563eb;color:#2563eb}
.pay-btn-sm{padding:8px 16px;font-size:0.82rem;min-height:44px}
.pay-form{max-width:600px}
.pay-fg{margin-bottom:14px}
.pay-fg label{display:block;font-size:0.8rem;font-weight:600;color:#475569;margin-bottom:4px}
.pay-fg select,.pay-fg input,.pay-fg textarea{width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:8px;font:inherit;font-size:0.88rem;color:#1e293b;background:#fff;box-sizing:border-box}
.pay-fg select:focus,.pay-fg input:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,0.1)}
.pay-fg-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.pay-msg{border-radius:10px;padding:12px 20px;margin-bottom:16px;font-weight:600;font-size:0.88rem;display:flex;align-items:center;gap:10px}
.pay-msg.success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}
.pay-msg.error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.pay-empty{text-align:center;padding:40px;color:#94a3b8}
.pay-empty i{font-size:2rem;color:#cbd5e1;margin-bottom:8px;display:block}
.pay-empty p{font-size:0.88rem;margin:4px 0}
.inv-card{background:#fff;border-radius:14px;border:1px solid #e2e8f0;margin-bottom:16px;overflow:hidden}
.inv-card-head{padding:16px 22px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
.inv-card-body{padding:18px 22px}
.inv-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:14px}
.inv-sum-item{text-align:center;padding:10px;background:#f8fafc;border-radius:8px}
.inv-sum-val{font-size:1.1rem;font-weight:800;color:#1e293b}
.inv-sum-label{font-size:0.7rem;font-weight:600;color:#64748b;text-transform:uppercase;margin-top:2px}
.inv-sum-val.green{color:#16a34a}
.inv-sum-val.red{color:#dc2626}
.inv-sum-val.blue{color:#2563eb}
.inv-payments{margin-top:14px;border-top:1px solid #f1f5f9;padding-top:14px}
.inv-payments h4{font-size:0.82rem;font-weight:700;color:#475569;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.inv-payments h4 i{color:#2563eb}
.inv-pay-row{display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:#f8fafc;border-radius:6px;margin-bottom:6px;font-size:0.82rem}
.inv-pay-row .date{color:#64748b;min-width:120px}
.inv-pay-row .method{color:#475569;font-weight:600;min-width:100px}
.inv-pay-row .ref{color:#94a3b8;flex:1;text-align:right}
.inv-pay-row .amt{color:#16a34a;font-weight:700;min-width:100px;text-align:right}
@media(max-width:900px){.pay-stats{grid-template-columns:1fr 1fr}.inv-summary{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.pay-stats{grid-template-columns:1fr}.pay-tabs{overflow-x:auto}.pay-tab{padding:8px 14px;font-size:0.82rem;white-space:nowrap}.inv-summary{grid-template-columns:1fr}.inv-pay-row{flex-wrap:wrap;gap:4px}.inv-pay-row .date,.inv-pay-row .method{min-width:auto}.inv-pay-row .ref{text-align:left}}
</style>

<section class="pay">
<div class="pay-title"><i class="fas fa-credit-card"></i> Payments</div>

<?php if ($paymentMsg): ?><div class="pay-msg <?= $paymentMsg['type'] ?>"><i class="fas fa-<?= $paymentMsg['type'] === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i> <?= htmlspecialchars($paymentMsg['text']) ?></div><?php endif; ?>
<?php if ($msg === 'paid'): ?><div class="pay-msg success"><i class="fas fa-check-circle"></i> Payment submitted successfully!</div><?php endif; ?>

<!-- Stats -->
<div class="pay-stats">
    <div class="pay-stat">
        <div class="pay-stat-icon" style="background:#fef2f2;color:#dc2626"><i class="fas fa-exclamation-circle"></i></div>
        <div><div class="pay-stat-val"><?= fmt($totalOutstanding) ?></div><div class="pay-stat-label">Outstanding</div></div>
    </div>
    <div class="pay-stat">
        <div class="pay-stat-icon" style="background:#f0fdf4;color:#16a34a"><i class="fas fa-check-circle"></i></div>
        <div><div class="pay-stat-val"><?= fmt($totalPaid) ?></div><div class="pay-stat-label">Total Paid</div></div>
    </div>
    <div class="pay-stat">
        <div class="pay-stat-icon" style="background:#eff6ff;color:#2563eb"><i class="fas fa-credit-card"></i></div>
        <div><div class="pay-stat-val"><?= fmt($creditRemaining) ?></div><div class="pay-stat-label">Credit Available</div></div>
    </div>
    <div class="pay-stat">
        <div class="pay-stat-icon" style="background:#fffbeb;color:#d97706"><i class="fas fa-file-invoice"></i></div>
        <div><div class="pay-stat-val"><?= count($allInvoices) ?></div><div class="pay-stat-label">Total Invoices</div></div>
    </div>
</div>

<!-- Tabs -->
<div class="pay-tabs">
    <a href="?page=payments&tab=pending" class="pay-tab <?= $tab === 'pending' ? 'active' : '' ?>"><i class="fas fa-clock"></i> Unpaid <span class="pay-tab-count"><?= count($pending) ?></span></a>
    <a href="?page=payments&tab=partial" class="pay-tab <?= $tab === 'partial' ? 'active' : '' ?>"><i class="fas fa-adjust"></i> Partial <span class="pay-tab-count"><?= count($partial) ?></span></a>
    <a href="?page=payments&tab=paid" class="pay-tab <?= $tab === 'paid' ? 'active' : '' ?>"><i class="fas fa-check-circle"></i> Paid <span class="pay-tab-count"><?= count($paid) ?></span></a>
    <a href="?page=payments&tab=history" class="pay-tab <?= $tab === 'history' ? 'active' : '' ?>"><i class="fas fa-history"></i> Payment History</a>
    <a href="?page=payments&tab=make" class="pay-tab <?= $tab === 'make' ? 'active' : '' ?>"><i class="fas fa-credit-card"></i> Make Payment</a>
</div>

<?php if ($tab === 'make'): ?>
<!-- MAKE PAYMENT -->
<div class="pay-panel">
    <div class="pay-panel-head"><div class="pay-panel-title"><i class="fas fa-credit-card"></i> Make a Payment</div></div>
    <div class="pay-panel-body">
    <?php if (empty($pending) && empty($partial)): ?>
        <div class="pay-empty"><i class="fas fa-check-circle" style="color:#16a34a"></i><p><strong>All invoices paid!</strong></p><p>No outstanding invoices to pay.</p></div>
    <?php else: ?>
        <form method="post" class="pay-form" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="make_payment">
            <div class="pay-fg"><label>Select Invoice</label><select name="invoice_id" required id="payInvoice" onchange="payUpdateAmt()"><option value="">-- Choose Invoice --</option><?php foreach (array_merge($pending, $partial) as $inv): ?><option value="<?= (int)$inv['id'] ?>" data-bal="<?= (float)$inv['outstanding_balance'] ?>" data-total="<?= (float)$inv['total_amount'] ?>"><?= htmlspecialchars($inv['invoice_no']) ?> — <?= fmt((float)$inv['outstanding_balance']) ?> outstanding of <?= fmt((float)$inv['total_amount']) ?></option><?php endforeach; ?></select></div>
            <div class="pay-fg-row">
                <div class="pay-fg"><label>Invoice Total</label><input type="text" id="payInvTotal" readonly style="background:#f8fafc;font-weight:700;color:#1e293b"></div>
                <div class="pay-fg"><label>Outstanding Amount</label><input type="text" id="payAmt" readonly style="background:#f8fafc;font-weight:700;color:#dc2626"></div>
            </div>
            <div class="pay-fg">
                <label>Payment Amount *</label>
                <input type="number" name="pay_amount" id="payAmount" min="0.01" step="0.01" required placeholder="Enter amount to pay">
                <span style="font-size:0.75rem;color:#64748b;margin-top:4px;display:block" id="payHint"></span>
            </div>
            <div class="pay-fg-row">
                <div class="pay-fg"><label>Payment Method</label><select name="payment_method"><option value="cash">Cash</option><option value="bank">Bank Transfer</option><option value="telebirr">Telebirr</option><option value="cbe_birr">CBE Birr</option></select></div>
                <div class="pay-fg"><label>Reference Number</label><input type="text" name="reference" placeholder="Transaction reference"></div>
            </div>
            <div class="pay-fg"><label>Payment Notes</label><textarea name="payment_notes" rows="2" placeholder="Optional notes..."></textarea></div>
            <div class="pay-fg"><label>Upload Receipt (optional)</label><input type="file" name="receipt" accept="image/*,.pdf" style="padding:8px"></div>
            <button type="submit" class="pay-btn pay-btn-success"><i class="fas fa-check-circle"></i> Submit Payment</button>
        </form>
    <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'history'): ?>
<!-- PAYMENT HISTORY -->
<div class="pay-panel">
    <div class="pay-panel-head"><div class="pay-panel-title"><i class="fas fa-history"></i> All Payment History</div></div>
    <?php if (empty($paymentsByInvoice)): ?>
    <div class="pay-empty"><i class="fas fa-history"></i><p>No payment history yet.</p></div>
    <?php else: ?>
    <div style="overflow-x:auto"><table class="pay-table">
        <thead><tr><th>Date</th><th>Invoice</th><th>Amount</th><th>Method</th><th>Reference</th><th>Recorded By</th></tr></thead>
        <tbody>
        <?php foreach ($paymentsByInvoice as $iid => $pays): foreach ($pays as $ph): ?>
        <tr>
            <td><?= date('M d, Y H:i', strtotime($ph['created_at'])) ?></td>
            <td><strong><?= htmlspecialchars($ph['invoice_no']) ?></strong></td>
            <td><strong style="color:#16a34a"><?= fmt((float)$ph['amount']) ?></strong></td>
            <td><span style="text-transform:capitalize"><?= htmlspecialchars(str_replace('_', ' ', $ph['payment_method'] ?? 'N/A')) ?></span></td>
            <td><?= htmlspecialchars($ph['reference_no'] ?? '—') ?></td>
            <td><?= htmlspecialchars($ph['recorded_by_name'] ?? 'System') ?></td>
        </tr>
        <?php endforeach; endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- INVOICE LIST (pending/partial/paid) with per-invoice payment history -->
<?php if (empty($showInvoices)): ?>
<div class="pay-panel">
    <div class="pay-panel-body">
        <div class="pay-empty"><i class="fas fa-file-invoice"></i><p>No <?= $tab ?> invoices.</p></div>
    </div>
</div>
<?php else: ?>
<?php foreach ($showInvoices as $inv):
    $invPayments = $paymentsByInvoice[(int)$inv['id']] ?? [];
    $grandTotal = (float)$inv['total_amount'];
    $totalPaidInv = (float)$inv['amount_paid'];
    $outstanding = (float)$inv['outstanding_balance'];
    $status = $inv['payment_status'];
    $daysLeft = $inv['credit_due_date'] ? (int)((strtotime($inv['credit_due_date']) - time()) / 86400) : null;
    $isOverdue = $daysLeft !== null && $daysLeft < 0 && $status !== 'paid';
?>
<div class="inv-card">
    <div class="inv-card-head">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <strong style="font-size:1rem;color:#1e293b"><?= htmlspecialchars($inv['invoice_no']) ?></strong>
            <span class="pay-badge <?= $isOverdue ? 'overdue' : $status ?>"><?= $isOverdue ? 'OVERDUE' : strtoupper($status) ?></span>
            <?php if ($inv['credit_due_date']): ?>
                <span style="font-size:0.78rem;color:#64748b">Due: <?= date('M d, Y', strtotime($inv['credit_due_date'])) ?></span>
                <?php if ($isOverdue): ?><span style="font-size:0.72rem;color:#dc2626;font-weight:600">(<?= abs($daysLeft) ?> days overdue)</span><?php endif; ?>
            <?php endif; ?>
        </div>
        <div style="display:flex;gap:8px">
            <?php if ($status !== 'paid'): ?>
                <a href="?page=payments&tab=make" class="pay-btn pay-btn-success pay-btn-sm"><i class="fas fa-credit-card"></i> Pay Now</a>
            <?php endif; ?>
            <a href="index.php?page=invoice&id=<?= (int)$inv['id'] ?>" class="pay-btn pay-btn-outline pay-btn-sm">View Invoice</a>
        </div>
    </div>
    <div class="inv-card-body">
        <div class="inv-summary">
            <div class="inv-sum-item">
                <div class="inv-sum-val blue"><?= fmt($grandTotal) ?></div>
                <div class="inv-sum-label">Grand Total</div>
            </div>
            <div class="inv-sum-item">
                <div class="inv-sum-val green"><?= fmt($totalPaidInv) ?></div>
                <div class="inv-sum-label">Total Paid</div>
            </div>
            <div class="inv-sum-item">
                <div class="inv-sum-val red"><?= fmt($outstanding) ?></div>
                <div class="inv-sum-label">Outstanding</div>
            </div>
            <div class="inv-sum-item">
                <div class="inv-sum-val"><?= count($invPayments) ?></div>
                <div class="inv-sum-label">Payments Made</div>
            </div>
        </div>

        <?php if (!empty($invPayments)): ?>
        <div class="inv-payments">
            <h4><i class="fas fa-history"></i> Payment History</h4>
            <?php foreach ($invPayments as $ip): ?>
            <div class="inv-pay-row">
                <span class="date"><?= date('M d, Y H:i', strtotime($ip['created_at'])) ?></span>
                <span class="method"><?= htmlspecialchars(str_replace('_', ' ', ucfirst($ip['payment_method'] ?? 'N/A'))) ?></span>
                <span class="ref"><?= htmlspecialchars($ip['reference_no'] ?? '') ?></span>
                <span class="amt">+ <?= fmt((float)$ip['amount']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p style="font-size:0.82rem;color:#94a3b8;margin-top:10px"><i class="fas fa-info-circle"></i> No payments recorded yet.</p>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>

</section>

<script>
var CURRENCY = '<?= addslashes(SettingsService::currencySymbol()) ?>';
function payUpdateAmt(){
    var sel=document.getElementById('payInvoice'),amt=document.getElementById('payAmt'),total=document.getElementById('payInvTotal'),inp=document.getElementById('payAmount'),hint=document.getElementById('payHint');
    if(!sel)return;
    var opt=sel.options[sel.selectedIndex];
    var bal=opt&&opt.dataset.bal?parseFloat(opt.dataset.bal):0;
    var tot=opt&&opt.dataset.total?parseFloat(opt.dataset.total):0;
    amt.value=bal>0?CURRENCY+' '+bal.toLocaleString('en',{minimumFractionDigits:2}):'';
    total.value=tot>0?CURRENCY+' '+tot.toLocaleString('en',{minimumFractionDigits:2}):'';
    if(inp){if(bal>0){inp.max=bal;inp.placeholder='Max: '+CURRENCY+' '+bal.toLocaleString('en',{minimumFractionDigits:2});hint.textContent='Enter amount up to '+fmtETB(bal)+'.';}else{inp.removeAttribute('max');hint.textContent='';}}
}
function fmtETB(n){return CURRENCY+' '+n.toFixed(2);}
</script>
