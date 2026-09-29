<?php
requirePoCapability('payment');
require_once __DIR__ . '/../includes/workflow_engine.php';
$pdo = getPDO();
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['_action'] ?? '';

    if (in_array($action, ['approve', 'reject'], true)) {
        requirePoCapability('payment');
        $poId = (int) ($_POST['po_id'] ?? 0);

        if ($poId <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Invalid PO.'];
        } else {
            $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = :id AND (status = 'payment_pending' OR (status = 'received' AND (payment_voucher_no IS NULL OR payment_voucher_no = '')))");
            $stmt->execute(['id' => $poId]);
            $po = $stmt->fetch();

            if (!$po) {
                $messages[] = ['type' => 'error', 'text' => 'PO not found or not awaiting payment approval.'];
            } else {
                $notes = trim($_POST['finance_notes'] ?? '');
                $userId = (int) ($_SESSION['user_id'] ?? 0);

                if ($action === 'approve') {
                    $voucherNo = trim($_POST['payment_voucher_no'] ?? '');
                    $paymentDate = trim($_POST['payment_date'] ?? date('Y-m-d'));
                    $paymentMethod = trim($_POST['payment_method'] ?? '');
                    $paymentReference = trim($_POST['payment_reference'] ?? '');
                    $receiptPath = '';

                    if ($voucherNo === '') {
                        $messages[] = ['type' => 'error', 'text' => 'Payment voucher number is required.'];
                    } elseif (empty($paymentMethod)) {
                        $messages[] = ['type' => 'error', 'text' => 'Payment method is required.'];
                    } else {
                        if (!empty($_FILES['payment_receipt']['name'])) {
                            $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
                            if (!in_array($_FILES['payment_receipt']['type'], $allowedTypes)) {
                                $messages[] = ['type' => 'error', 'text' => 'Receipt must be PDF, JPG, or PNG.'];
                            } elseif ($_FILES['payment_receipt']['size'] > 5 * 1024 * 1024) {
                                $messages[] = ['type' => 'error', 'text' => 'Receipt file must be under 5MB.'];
                            } else {
                                $uploadDir = __DIR__ . '/../uploads/';
                                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                                $ext = strtolower(pathinfo($_FILES['payment_receipt']['name'], PATHINFO_EXTENSION));
                                $receiptPath = 'payment_receipt_' . $poId . '_' . time() . '.' . $ext;
                                move_uploaded_file($_FILES['payment_receipt']['tmp_name'], $uploadDir . $receiptPath);
                            }
                        }

                        if (empty($messages)) {
                            $extra = [
                                'finance_notes' => $notes,
                                'payment_approved_by' => $userId,
                                'payment_approved_at' => date('Y-m-d H:i:s'),
                                'payment_voucher_no' => $voucherNo,
                                'payment_date' => $paymentDate,
                                'payment_method' => $paymentMethod,
                                'payment_reference' => $paymentReference,
                                'payment_receipt' => $receiptPath,
                            ];
                            if ($po['status'] === 'received') {
                                // Goods already received — record payment settlement without regressing PO status.
                                $set = [];
                                $params = ['id' => $poId];
                                foreach ($extra as $col => $val) {
                                    $set[] = $col . ' = :e_' . $col;
                                    $params['e_' . $col] = $val;
                                }
                                $pdo->prepare('UPDATE purchase_orders SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
                                WorkflowEngine::logStatusChange($pdo, 'purchase', $poId, 'received', 'received', $userId, 'Payment approved after goods received');
                                $result = ['success' => true, 'from' => 'received', 'to' => 'received'];
                            } else {
                                $result = WorkflowEngine::transition($pdo, 'purchase', $poId, 'payment_approved', [
                                    'extra' => $extra,
                                    'changed_by' => $userId,
                                ]);
                            }
                            if ($result['success']) {
                                redirect_with_flash('?page=purchase_payment_approval', "PO #{$poId} payment approved.");
                            } else {
                                $messages[] = ['type' => 'error', 'text' => $result['error']];
                            }
                        }
                    }
                } else {
                    $result = WorkflowEngine::transition($pdo, 'purchase', $poId, 'payment_rejected', [
                        'extra' => ['finance_notes' => $notes],
                        'changed_by' => $userId,
                    ]);
                    if ($result['success']) {
                        redirect_with_flash('?page=purchase_payment_approval', "PO #{$poId} payment rejected.", 'info');
                    } else {
                        $messages[] = ['type' => 'error', 'text' => $result['error']];
                    }
                }
            }
        }
    }
}

$pendingPos = $pdo->query("
    SELECT po.*, s.name AS supplier_name, u.full_name AS created_by_name
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN users u ON po.created_by = u.id
    WHERE po.status = 'payment_pending' OR (po.status = 'received' AND (po.payment_voucher_no IS NULL OR po.payment_voucher_no = ''))
    ORDER BY po.created_at ASC
")->fetchAll();

$recentActions = $pdo->query("
    SELECT po.*, s.name AS supplier_name, u.full_name AS approved_by_name
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN users u ON po.payment_approved_by = u.id
    WHERE po.status IN ('payment_approved', 'payment_rejected')
    ORDER BY po.payment_approved_at DESC
    LIMIT 20
")->fetchAll();
?>
<section>
    <h1>Purchase Payment Approval</h1>
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <div class="tab-bar">
        <button class="tab-btn active" onclick="switchTab(this,'ppa-pending')"><i class="fas fa-clock"></i> Awaiting Approval (<?= count($pendingPos) ?>)</button>
        <button class="tab-btn" onclick="switchTab(this,'ppa-history')"><i class="fas fa-history"></i> History</button>
    </div>

    <div class="tab-content">
        <div id="ppa-pending" class="tab-panel active">
            <?php if (empty($pendingPos)): ?>
                <div class="panel"><p class="hint">No purchase orders awaiting payment approval.</p></div>
            <?php endif; ?>
            <?php foreach ($pendingPos as $po): $poid = (int) $po['id']; ?>
                <div class="panel" style="margin-bottom:12px">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                        <div>
                            <strong style="font-size:1.05rem">PO #<?= $poid ?></strong>
                            <span style="color:var(--neutral-500);font-size:0.85rem;margin-left:10px"><?= htmlspecialchars($po['supplier_name']) ?></span>
                            <span style="color:var(--neutral-500);font-size:0.82rem;margin-left:10px"><?= htmlspecialchars($po['order_date']) ?></span>
                        </div>
                        <span style="font-size:0.85rem;color:var(--neutral-600)">Total: <strong><?= fmt((float) $po['total_amount']) ?></strong></span>
                    </div>
                    <?php
                    $itemsSt = $pdo->prepare('SELECT poi.*, p.name AS pname FROM purchase_order_items poi JOIN products p ON poi.product_id = p.id WHERE poi.purchase_order_id = :poid');
                    $itemsSt->execute(['poid' => $poid]);
                    $poItems = $itemsSt->fetchAll();
                    ?>
                    <div style="overflow-x:auto">
                    <table style="font-size:0.82rem;margin-top:8px">
                        <thead><tr><th>Product</th><th>Qty</th><th>Unit Price</th><th>Subtotal</th></tr></thead>
                        <tbody>
                            <?php foreach ($poItems as $it): ?>
                                <tr>
                                    <td><?= htmlspecialchars($it['pname']) ?></td>
                                    <td><?= (int)$it['quantity'] ?></td>
                                    <td><?= fmt((float)$it['unit_price']) ?></td>
                                    <td><?= fmt((float)$it['unit_price'] * (int)$it['quantity']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php if ($po['notes']): ?>
                        <p style="font-size:0.82rem;color:var(--neutral-600);margin-top:6px"><strong>Admin Notes:</strong> <?= htmlspecialchars($po['notes']) ?></p>
                    <?php endif; ?>
                    <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
                        <button onclick="showApproveForm(<?= $poid ?>)" style="padding:8px 18px;background:#059669;color:#fff;border:none;border-radius:999px;cursor:pointer;font-weight:600;min-height:44px"><i class="fas fa-check"></i> Approve Payment</button>
                        <button onclick="showRejectForm(<?= $poid ?>)" style="padding:8px 18px;background:#dc2626;color:#fff;border:none;border-radius:999px;cursor:pointer;font-weight:600;min-height:44px"><i class="fas fa-times"></i> Reject</button>
                    </div>

                    <div id="approve-<?= $poid ?>" style="display:none;margin-top:10px;border:1px solid var(--neutral-300);padding:12px;border-radius:8px;background:var(--neutral-50)">
                        <form method="post" enctype="multipart/form-data" style="font-size:0.85rem">
                            <?= csrf_field() ?>
                            <input type="hidden" name="_action" value="approve">
                            <input type="hidden" name="po_id" value="<?= $poid ?>">
                            <div class="form-grid" style="grid-template-columns:1fr 1fr">
                                <label>Voucher No. *<input type="text" name="payment_voucher_no" required></label>
                                <label>Payment Date<input type="date" name="payment_date" value="<?= date('Y-m-d') ?>"></label>
                                <label>Payment Method *<select name="payment_method" required>
                                    <option value="">Select</option>
                                    <option value="cash">Cash</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                    <option value="cheque">Cheque</option>
                                    <option value="mobile_money">Mobile Money</option>
                                </select></label>
                                <label>Reference No.<input type="text" name="payment_reference" placeholder="Cheque/Transfer ref"></label>
                                <label style="grid-column:1/-1">Payment Receipt (PDF/JPG/PNG, max 5MB)<input type="file" name="payment_receipt" accept=".pdf,.jpg,.jpeg,.png"></label>
                                <label style="grid-column:1/-1">Finance Notes<textarea name="finance_notes" rows="2" placeholder="Optional notes about this payment"></textarea></label>
                            </div>
                            <button type="submit" style="padding:8px 20px;background:#059669;color:#fff;border:none;border-radius:999px;cursor:pointer;font-weight:600;margin-top:6px;min-height:44px"><i class="fas fa-check"></i> Confirm Approval</button>
                            <button type="button" onclick="document.getElementById('approve-<?= $poid ?>').style.display='none'" style="padding:8px 16px;background:var(--neutral-300);color:var(--neutral-700);border:none;border-radius:999px;cursor:pointer;margin-left:6px;min-height:44px">Cancel</button>
                        </form>
                    </div>

                    <div id="reject-<?= $poid ?>" style="display:none;margin-top:10px;border:1px solid var(--neutral-300);padding:12px;border-radius:8px;background:var(--neutral-50)">
                        <form method="post" style="font-size:0.85rem">
                            <?= csrf_field() ?>
                            <input type="hidden" name="_action" value="reject">
                            <input type="hidden" name="po_id" value="<?= $poid ?>">
                            <label>Reason for Rejection *<textarea name="finance_notes" rows="2" required placeholder="Explain why this payment is rejected"></textarea></label>
                            <button type="submit" style="padding:8px 20px;background:#dc2626;color:#fff;border:none;border-radius:999px;cursor:pointer;font-weight:600;margin-top:6px;min-height:44px"><i class="fas fa-times"></i> Confirm Rejection</button>
                            <button type="button" onclick="document.getElementById('reject-<?= $poid ?>').style.display='none'" style="padding:8px 16px;background:var(--neutral-300);color:var(--neutral-700);border:none;border-radius:999px;cursor:pointer;margin-left:6px;min-height:44px">Cancel</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div id="ppa-history" class="tab-panel">
            <?php if (empty($recentActions)): ?>
                <div class="panel"><p class="hint">No payment approval history yet.</p></div>
            <?php endif; ?>
            <div class="panel">
                <div style="overflow-x:auto">
                <table>
                    <thead><tr><th>PO #</th><th>Supplier</th><th>Total</th><th>Status</th><th>Voucher</th><th>Method</th><th>Approved By</th><th>Date</th><th>Notes</th></tr></thead>
                    <tbody>
                        <?php foreach ($recentActions as $po): ?>
                            <tr>
                                <td><strong>#<?= (int)$po['id'] ?></strong></td>
                                <td><?= htmlspecialchars($po['supplier_name']) ?></td>
                                <td><?= fmt((float)$po['total_amount']) ?></td>
                                <td><span style="display:inline-block;padding:2px 6px;border-radius:4px;font-weight:700;font-size:0.72rem;background:<?= $po['status'] === 'payment_approved' ? '#05966920' : '#dc262620' ?>;color:<?= $po['status'] === 'payment_approved' ? '#059669' : '#dc2626' ?>"><?= $po['status'] === 'payment_approved' ? 'Approved' : 'Rejected' ?></span></td>
                                <td style="font-size:0.8rem"><?= htmlspecialchars($po['payment_voucher_no'] ?? '—') ?></td>
                                <td style="font-size:0.8rem"><?= htmlspecialchars($po['payment_method'] ?? '—') ?></td>
                                <td style="font-size:0.8rem"><?= htmlspecialchars($po['approved_by_name'] ?? '—') ?></td>
                                <td style="font-size:0.8rem"><?= $po['payment_approved_at'] ? htmlspecialchars(date('Y-m-d H:i', strtotime($po['payment_approved_at']))) : '—' ?></td>
                                <td style="font-size:0.78rem;max-width:200px;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($po['finance_notes'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</section>
<script>
function showApproveForm(id) {
    document.getElementById('reject-' + id).style.display = 'none';
    var f = document.getElementById('approve-' + id);
    f.style.display = f.style.display === 'none' ? 'block' : 'none';
}
function showRejectForm(id) {
    document.getElementById('approve-' + id).style.display = 'none';
    var f = document.getElementById('reject-' + id);
    f.style.display = f.style.display === 'none' ? 'block' : 'none';
}
</script>
