<?php
require_once __DIR__ . '/../services/init.php';
requireLogin();
requirePermission('invoice', 'view');
require_once __DIR__ . '/../includes/auto_migrate.php';
require_once __DIR__ . '/../includes/workflow_engine.php';
require_once __DIR__ . '/../includes/delivery_helpers.php';
$pdo = getPDO();
ensureProfessionalInvoiceMigration($pdo);
ensureInvoiceFeatureMigration($pdo);
ensureDeliveryPodMigration($pdo);
$invId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$user = currentUser();
$messages = [];

// Load company info from settings
$companyName    = SettingsService::companyName();
$companyAddress = SettingsService::get('company_address', '');
$companyPhone   = SettingsService::get('company_phone', '');
$companyEmail   = SettingsService::get('company_email', '');
$companyTin     = SettingsService::get('company_tin', '');
$taxRate        = SettingsService::vatRate();
$invoicePrefix  = SettingsService::invoicePrefix();

// ---------------------------------------------------------------
// LIST VIEW
// ---------------------------------------------------------------
if ($invId <= 0) {
    $where = '';
    $params = [];
    if (customerAccountCustomerId() !== null) {
        $cid = customerAccountCustomerId();
        if (!$cid) { echo '<h1>No customer account linked</h1>'; exit; }
        $where = 'WHERE i.customer_id = :cid';
        $params['cid'] = $cid;
    }
    $sql = "SELECT i.id, i.invoice_no, i.reference_no, i.total_amount, i.payment_status, i.amount_paid,
                   i.outstanding_balance, i.sales_type, i.generated_at,
                   c.name AS customer_name, c.customer_code,
                   co.delivery_status, co.payment_confirmed,
                   u.full_name AS salesperson_name
            FROM invoices i
            JOIN customers c ON i.customer_id = c.id
            JOIN customer_orders co ON i.customer_order_id = co.id
            LEFT JOIN users u ON co.salesperson_id = u.id
            $where
            ORDER BY i.generated_at DESC
            LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll();
    require_once __DIR__ . '/../header.php';
    ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<style>
    .inv-list-card{border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.04)}
    .inv-list-card th{background:#1e3a5f;color:#fff;font-size:11px;text-transform:uppercase;letter-spacing:.5px;padding:10px 12px}
    .inv-list-card td{padding:10px 12px;font-size:13px;vertical-align:middle}
    .badge-paid{background:#d1fae5;color:#047857;border:1px solid #059669}
    .badge-partial{background:#fef3c7;color:#92400e;border:1px solid #f59e0b}
    .badge-unpaid{background:#fee2e2;color:#b91c1c;border:1px solid #ef4444}
    .badge-delivered{background:#d1fae5;color:#047857}
    .badge-pending{background:#e5e7eb;color:#374151}
</style>
<section class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0 fw-bold"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i>Sales Invoices</h4>
        <span class="badge bg-secondary"><?= count($invoices) ?> invoices</span>
    </div>
    <div class="inv-list-card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead><tr>
                    <th>Invoice #</th><th>Reference</th><th>Customer</th><th>Sales Type</th>
                    <th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th>
                    <th>Status</th><th>Delivery</th><th>Date</th><th></th>
                </tr></thead>
                <tbody>
                <?php if (empty($invoices)): ?>
                    <tr><td colspan="11" class="text-center py-4 text-muted">No invoices found.</td></tr>
                <?php else: foreach ($invoices as $r):
                    $psClass = $r['payment_status'] === 'paid' ? 'paid' : ($r['payment_status'] === 'partial' ? 'partial' : 'unpaid');
                    $dsLabels = ['assigned'=>'Assigned','out_for_delivery'=>'Out for Delivery','pod_pending'=>'Awaiting Verification','delivered'=>'Delivered'];
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($r['invoice_no']) ?></strong></td>
                    <td class="text-muted small"><?= htmlspecialchars($r['reference_no'] ?? '—') ?></td>
                    <td>
                        <?= htmlspecialchars($r['customer_name']) ?>
                        <?php if ($r['customer_code']): ?><br><small class="text-muted"><?= htmlspecialchars($r['customer_code']) ?></small><?php endif; ?>
                    </td>
                    <td><span class="badge bg-<?= $r['sales_type'] === 'credit' ? 'warning text-dark' : 'info' ?>"><?= ucfirst($r['sales_type']) ?></span></td>
                    <td class="text-end fw-bold"><?= fmt((float) $r['total_amount']) ?></td>
                    <td class="text-end text-success"><?= fmt((float) $r['amount_paid']) ?></td>
                    <td class="text-end fw-bold" style="color:<?= (float)$r['outstanding_balance'] > 0 ? '#b91c1c' : '#047857' ?>"><?= fmt((float) $r['outstanding_balance']) ?></td>
                    <td><span class="badge badge-<?= $psClass ?>"><?= ucfirst($r['payment_status']) ?></span>
                    <?php if ((float)$r['outstanding_balance'] > 0 && !empty($r['credit_due_date']) && strtotime($r['credit_due_date']) < time()): ?>
                        <span class="badge bg-danger" title="Overdue since <?= htmlspecialchars($r['credit_due_date']) ?>">Overdue</span>
                    <?php endif; ?>
                    </td>
                    <td><?php
                        $ds = $r['delivery_status'] ?? '';
                        $dc = $ds === 'delivered' ? 'delivered' : ($ds === 'out_for_delivery' ? 'info' : ($ds === 'pod_pending' ? 'info' : 'pending'));
                        echo '<span class="badge badge-' . $dc . '">' . ($dsLabels[$ds] ?? 'Pending') . '</span>';
                    ?></td>
                    <td class="small text-muted"><?= date('d M Y', strtotime($r['generated_at'])) ?></td>
                    <td><a href="?page=invoice&id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i></a></td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php
    require_once __DIR__ . '/../footer.php';
    exit;
}

// ---------------------------------------------------------------
// POST HANDLERS
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $chk = $pdo->prepare("SELECT i.*, co.customer_id AS order_cid, co.delivery_staff_id, co.salesperson_id
        FROM invoices i JOIN customer_orders co ON i.customer_order_id = co.id WHERE i.id = :id");
    $chk->execute(['id' => $invId]);
    $chkInv = $chk->fetch();
    if (!$chkInv) { header('HTTP/1.0 404 Not Found'); echo 'Not found'; exit; }
    $canEdit = hasPermission('finance_payments', 'view');

    // --- Delivery confirmation ---
    if (isset($_POST['confirm_delivery_invoice'])) {
        if (!isset($_POST['agree_received'])) {
            $messages[] = ['type' => 'error', 'text' => 'Please check the box to confirm receipt.'];
        } elseif (empty($_POST['cust_signature_data'])) {
            $messages[] = ['type' => 'error', 'text' => 'Please sign to confirm delivery.'];
        } else {
            $oid = (int) $chkInv['customer_order_id'];
            $res = DeliveryService::confirmViaInvoice($pdo, $oid, $_POST['cust_signature_data'], (int)($_SESSION['user']['id'] ?? 0));
            if ($res['success']) {
                InvoiceService::saveCustomerSignature($pdo, $invId, $_POST['cust_signature_data']);
            }
            $messages[] = ['type' => $res['success'] ? 'success' : 'error', 'text' => $res['msg']];
        }
    }

    // --- Payment update ---
    if ($canEdit && isset($_POST['update_payment'])) {
        $res = InvoiceService::updatePayment($pdo, $invId, $_POST['payment_status'] ?? 'unpaid', (float)($_POST['amount_paid'] ?? 0), $_POST['sales_type'] ?? 'cash', (int)($_SESSION['user']['id'] ?? 0));
        $messages[] = ['type' => $res['success'] ? 'success' : 'error', 'text' => $res['msg']];
    }

    // --- Credit terms ---
    if ($canEdit && isset($_POST['update_credit'])) {
        InvoiceService::setCreditDueDate($pdo, $invId, $_POST['credit_due_date'] ?? null);
        $messages[] = ['type'=>'success','text'=>'Credit due date saved.'];
    }

    // --- Save signature ---
    if ($canEdit && isset($_POST['save_signature']) && !empty($_POST['signature_data'])) {
        InvoiceService::saveDigitalSignature($pdo, $invId, $_POST['signature_data']);
        $messages[] = ['type'=>'success','text'=>'Signature saved.'];
    }

    // --- Clear signature ---
    if ($canEdit && isset($_POST['clear_signature'])) {
        InvoiceService::clearDigitalSignature($pdo, $invId);
        $messages[] = ['type'=>'success','text'=>'Signature cleared.'];
    }

    // --- Return reference ---
    if ($canEdit && isset($_POST['update_return_ref'])) {
        InvoiceService::setReturnReference($pdo, $invId, $_POST['return_reference_no'] ?? null);
        $messages[] = ['type'=>'success','text'=>'Return reference saved.'];
    }

    // --- Prepared/Approved by (auto-assigned from the authenticated session, never from client input) ---
    if ($canEdit && isset($_POST['update_staff'])) {
        $actingUser = (int) $_SESSION['user']['id'] ?? 0;
        if ($actingUser > 0) {
            InvoiceService::setStaffInfo($pdo, $invId, $actingUser, $actingUser);
            $messages[] = ['type'=>'success','text'=>'Prepared / Approved by set to the current user.'];
        } else {
            $messages[] = ['type'=>'error','text'=>'Could not determine the current user.'];
        }
    }

    // --- Warehouse ---
    if ($canEdit && isset($_POST['update_warehouse'])) {
        InvoiceService::setWarehouse($pdo, $invId, isset($_POST['warehouse_id']) && $_POST['warehouse_id'] !== '' ? (int) $_POST['warehouse_id'] : null);
        $messages[] = ['type'=>'success','text'=>'Warehouse updated.'];
    }

    // --- Email invoice ---
    if ($canEdit && isset($_POST['email_invoice'])) {
        $toEmail = $_POST['email_to'] ?? '';
        if (empty($toEmail)) {
            $messages[] = ['type'=>'error','text'=>'Email address is required.'];
        } else {
            $invNo = $chkInv['invoice_no'];
            $subject = "Invoice $invNo - $companyName";
            $body = "Dear Customer,\n\nPlease find attached invoice $invNo for the amount of " . fmt((float)$chkInv['total_amount']) . ".\n\n";
            $body .= "Invoice Number: $invNo\n";
            $body .= "Amount: " . fmt((float)$chkInv['total_amount']) . "\n";
            $body .= "Amount Paid: " . fmt((float)$chkInv['amount_paid']) . "\n";
            $body .= "Balance: " . fmt((float)$chkInv['outstanding_balance']) . "\n";
            $body .= "Due Date: " . ($chkInv['credit_due_date'] ?? 'N/A') . "\n\n";
            $body .= "View your invoice at: " . (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/pharma%20project/index.php?page=invoice&id=' . $invId . "\n\n";
            $body .= "Thank you for your business.\n\n$companyName";
            $headers = "From: $companyEmail\r\nReply-To: $companyEmail\r\n";
            if (@mail($toEmail, $subject, $body, $headers)) {
                $messages[] = ['type'=>'success','text'=>"Invoice emailed to $toEmail"];
            } else {
                $messages[] = ['type'=>'error','text'=>'Failed to send email. Check mail configuration.'];
            }
        }
    }

    // fall through to re-render the single view with messages
}

// ---------------------------------------------------------------
// SINGLE INVOICE VIEW
// ---------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT i.*, c.name AS customer_name, c.contact_person, c.phone, c.email AS customer_email,
           c.tin_number AS customer_tin, c.customer_code, c.physical_address AS customer_address,
           co.order_date, co.delivery_address, co.total_amount AS order_total,
           co.customer_id AS order_customer_id, co.delivery_status, co.delivery_staff_id,
           co.payment_confirmed, co.payment_method, co.salesperson_id,
           co.delivery_confirmed_at, co.confirmation_method,
           w.name AS warehouse_name
    FROM invoices i
    JOIN customers c ON i.customer_id = c.id
    JOIN customer_orders co ON i.customer_order_id = co.id
    LEFT JOIN warehouses w ON i.warehouse_id = w.id
    WHERE i.id = :id
");
$stmt->execute(['id' => $invId]);
$inv = $stmt->fetch();
if (!$inv) { header('HTTP/1.0 404 Not Found'); echo '<h1>Invoice not found</h1>'; exit; }

$allowed = false;
if (customerAccountCustomerId() !== null) $allowed = ((int)$inv['order_customer_id'] === customerAccountCustomerId());
elseif (hasPermission('invoice', 'view')) $allowed = true;
if (!$allowed) { header('HTTP/1.0 403 Forbidden'); echo '<h1>Access denied</h1>'; exit; }

$canEdit = hasPermission('finance_payments', 'view');

// Delivery status refresh
$stmt2 = $pdo->prepare("SELECT delivery_status, delivery_confirmed_at FROM customer_orders WHERE id = :oid");
$stmt2->execute(['oid' => (int)$inv['customer_order_id']]);
$co = $stmt2->fetch();
$deliveryStatus = $co['delivery_status'] ?? $inv['delivery_status'];
$confirmedAt = $co['delivery_confirmed_at'] ?? $inv['delivery_confirmed_at'] ?? null;

// Line items
$itemsStmt = $pdo->prepare("
    SELECT p.name, p.barcode AS product_code, p.generic_name, p.strength, p.dosage_form, p.manufacturer,
           p.unit_of_measure, coi.quantity, coi.unit_price, coi.batch_no, coi.expiry_date,
           coi.discount, coi.tax_amount
    FROM customer_order_items coi
    JOIN products p ON coi.product_id = p.id
    WHERE coi.customer_order_id = :oid
");
$itemsStmt->execute(['oid' => (int) $inv['customer_order_id']]);
$items = $itemsStmt->fetchAll();

// Fill missing batch/expiry from product_batches
$needBatchFill = false;
foreach ($items as $it) { if (empty($it['batch_no'])) { $needBatchFill = true; break; } }
if ($needBatchFill) {
    $pids = array_unique(array_column($items, 'product_id'));
    if (!empty($pids)) {
        $ph = implode(',', array_fill(0, count($pids), '?'));
        $bStmt = $pdo->prepare("SELECT product_id, batch_number, expiry_date FROM product_batches WHERE product_id IN ($ph) AND quantity > 0 ORDER BY expiry_date ASC");
        $bStmt->execute($pids);
        $batchMap = [];
        while ($b = $bStmt->fetch()) { $batchMap[$b['product_id']] = $b; }
        foreach ($items as &$it) {
            if (empty($it['batch_no']) && isset($batchMap[$it['product_id'] ?? 0])) {
                $it['batch_no'] = $batchMap[$it['product_id']]['batch_number'];
                $it['expiry_date'] = $batchMap[$it['product_id']]['expiry_date'];
            }
        }
        unset($it);
    }
}

// Salesperson name
$salespersonName = '';
if ($inv['salesperson_id']) {
    $salespersonName = userFullName($pdo, (int)$inv['salesperson_id']);
}

// Prepared by / Approved by names
$preparedByName = '';
if (!empty($inv['prepared_by'])) {
    $preparedByName = userFullName($pdo, (int)$inv['prepared_by']);
}
$approvedByName = '';
if (!empty($inv['approved_by'])) {
    $approvedByName = userFullName($pdo, (int)$inv['approved_by']);
}



// Return refs
$retStmt = $pdo->prepare("SELECT id, return_date, refund_amount, status FROM sales_returns WHERE customer_id = :cid AND return_date >= :odate");
$retStmt->execute(['cid' => (int)$inv['order_customer_id'], 'odate' => $inv['order_date']]);
$returnRefs = $retStmt->fetchAll();

// Warehouses list (for admin edit)
$warehouses = [];
if ($canEdit) {
    $warehouses = $pdo->query("SELECT id, name FROM warehouses WHERE warehouse_status='active' ORDER BY name")->fetchAll();
}

// Calculations
$paymentStatus = $inv['payment_status'] ?? 'unpaid';
$amountPaid = (float) ($inv['amount_paid'] ?? 0);
$outstanding = (float) ($inv['outstanding_balance'] ?? 0);
$creditDue = $inv['credit_due_date'] ?? null;
$isOverdue = $outstanding > 0 && $creditDue && strtotime((string) $creditDue) < time();

$totalQty = 0;
$totalItems = count($items);
$totalDiscount = 0;
$totalTax = 0;
$subtotal = 0;
foreach ($items as $it) {
    $qty = (int) $it['quantity'];
    $price = (float) $it['unit_price'];
    $disc = (float) ($it['discount'] ?? 0);
    $tax = (float) ($it['tax_amount'] ?? 0);
    $lineSub = ($price * $qty) - $disc;
    $subtotal += $lineSub;
    $totalQty += $qty;
    $totalDiscount += $disc;
    $totalTax += $tax;
}
// If no per-line tax, calculate VAT from subtotal
if ($totalTax == 0 && $subtotal > 0) {
    $totalTax = round($subtotal * $taxRate / 100, 2);
}
$grandTotal = $subtotal + $totalTax;

// QR code
$qrData = json_encode(['invoice'=>$inv['invoice_no'],'customer'=>$inv['customer_name'],'total'=>$grandTotal,'date'=>$inv['generated_at']]);
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($qrData);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?= htmlspecialchars($inv['invoice_no']) ?> — <?= htmlspecialchars($companyName) ?></title>
    <?= favicon_tags() ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #1e3a5f;
            --primary-light: #2563eb;
            --accent: #059669;
            --danger: #dc2626;
            --warn: #d97706;
        }
        body { font-family: 'Inter', 'Segoe UI', system-ui, sans-serif; font-size: 13px; background: #f1f5f9; color: #1e293b; }
        .invoice-paper { max-width: 900px; margin: 0 auto; background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,.08); overflow: hidden; }

        /* Header */
        .inv-top-bar { background: var(--primary); color: #fff; padding: 20px 30px; display: flex; justify-content: space-between; align-items: center; }
        .inv-top-bar .brand h1 { font-size: 20px; margin: 0; font-weight: 800; letter-spacing: .5px; }
        .inv-top-bar .brand p { font-size: 11px; margin: 2px 0 0; opacity: .85; line-height: 1.5; }
        .inv-top-bar .logo-area { text-align: right; }
        .inv-top-bar .logo-area img { max-height: 50px; border-radius: 6px; background: #fff; padding: 4px 8px; }
        .inv-top-bar .inv-title { font-size: 24px; font-weight: 800; letter-spacing: 1px; }

        .inv-meta-bar { display: flex; gap: 0; border-bottom: 2px solid #e2e8f0; }
        .inv-meta-bar .meta-col { flex: 1; padding: 14px 20px; border-right: 1px solid #e2e8f0; }
        .inv-meta-bar .meta-col:last-child { border-right: none; }
        .inv-meta-bar .meta-label { font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #64748b; font-weight: 700; margin-bottom: 2px; }
        .inv-meta-bar .meta-value { font-size: 12px; font-weight: 600; color: #1e293b; }

        .inv-body-section { padding: 20px 30px; }
        .inv-section-title { font-size: 10px; text-transform: uppercase; letter-spacing: 1.5px; color: var(--primary-light); font-weight: 800; margin-bottom: 8px; padding-bottom: 4px; border-bottom: 2px solid #e2e8f0; }

        .inv-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }
        .inv-info-box { border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; background: #f8fafc; }
        .inv-info-box h5 { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: var(--primary-light); margin: 0 0 8px; font-weight: 700; }
        .inv-info-box p { margin: 0 0 3px; font-size: 12px; line-height: 1.5; }
        .inv-info-box .label { color: #64748b; font-weight: 500; }

        /* Product Table */
        .inv-table { width: 100%; border-collapse: collapse; font-size: 11px; }
        .inv-table th { background: var(--primary); color: #fff; padding: 8px 6px; font-size: 9px; text-transform: uppercase; letter-spacing: .5px; white-space: nowrap; text-align: left; }
        .inv-table td { padding: 7px 6px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        .inv-table tbody tr:hover { background: #f1f5f9; }
        .inv-table .text-right { text-align: right; }
        .inv-table .text-center { text-align: center; }
        .inv-table tfoot td { font-weight: 700; padding: 8px 6px; border-top: 2px solid var(--primary); background: #f8fafc; }
        .inv-table .exp-danger { color: var(--danger); font-weight: 700; }
        .inv-table .exp-warn { color: var(--warn); font-weight: 600; }

        /* Summary */
        .inv-summary-section { display: flex; gap: 20px; margin-top: 20px; padding-top: 16px; border-top: 2px solid #e2e8f0; }
        .inv-summary-left { flex: 1; }
        .inv-summary-right { width: 300px; }
        .inv-totals-table { width: 100%; font-size: 12px; }
        .inv-totals-table td { padding: 5px 10px; }
        .inv-totals-table .grand { font-size: 16px; font-weight: 800; color: var(--primary); border-top: 2px solid var(--primary); padding-top: 8px; }
        .inv-totals-table .balance { color: var(--danger); font-weight: 700; }
        .inv-totals-table .paid { color: var(--accent); font-weight: 600; }

        /* Status badges */
        .inv-badge { display: inline-flex; align-items: center; gap: 4px; padding: 4px 12px; border-radius: 999px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; }
        .inv-badge.paid { background: #d1fae5; color: #047857; border: 1px solid #059669; }
        .inv-badge.partial { background: #fef3c7; color: #92400e; border: 1px solid #f59e0b; }
        .inv-badge.unpaid { background: #fee2e2; color: #b91c1c; border: 1px solid #ef4444; }
        .inv-badge.delivered { background: #d1fae5; color: #047857; }
        .inv-badge.out-for { background: #dbeafe; color: #1a56db; }
        .inv-badge.pending { background: #e5e7eb; color: #374151; }

        /* Signature */
        .sig-box { border: 2px dashed #d1d5db; border-radius: 8px; min-height: 90px; display: flex; align-items: center; justify-content: center; background: #fafafa; padding: 10px; }
        .sig-box img { max-width: 260px; max-height: 70px; }
        .sig-box .empty { color: #9ca3af; font-size: 11px; font-style: italic; }
        .sig-canvas { border: 1px solid #d1d5db; border-radius: 8px; cursor: crosshair; background: #fff; touch-action: none; }

        /* Additional info grid */
        .inv-additional-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 20px; }
        .inv-additional-item { border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 10px; background: #f8fafc; }
        .inv-additional-item .ai-label { font-size: 9px; text-transform: uppercase; letter-spacing: .8px; color: #64748b; font-weight: 700; margin-bottom: 2px; }
        .inv-additional-item .ai-value { font-size: 12px; font-weight: 600; }

        /* Footer */
        .inv-footer-bar { background: #f1f5f9; padding: 16px 30px; border-top: 2px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
        .inv-footer-bar .qr img { width: 80px; height: 80px; border: 1px solid #e2e8f0; border-radius: 6px; }
        .inv-footer-bar .footer-text { font-size: 10px; color: #64748b; text-align: center; line-height: 1.6; }

        /* Print button */
        .btn-float { position: fixed; top: 16px; right: 16px; z-index: 1000; display: flex; gap: 6px; }
        .btn-float .btn { box-shadow: 0 4px 12px rgba(0,0,0,.15); }

        /* Print styles */
        @media print {
            body { background: #fff !important; padding: 0 !important; }
            .invoice-paper { box-shadow: none !important; border-radius: 0 !important; max-width: 100% !important; }
            .btn-float, .no-print, .confirm-section, .inv-email-modal { display: none !important; }
            .inv-meta-bar .meta-col { padding: 8px 12px; }
            .inv-body-section { padding: 12px 20px; }
            @page { margin: 8mm; size: A4; }
        }

        @media (max-width: 768px) {
            .inv-meta-bar { flex-direction: column; }
            .inv-meta-bar .meta-col { border-right: none; border-bottom: 1px solid #e2e8f0; }
            .inv-info-grid { grid-template-columns: 1fr; }
            .inv-summary-section { flex-direction: column; }
            .inv-summary-right { width: 100%; }
            .inv-additional-grid { grid-template-columns: 1fr 1fr; }
            .inv-top-bar { flex-direction: column; gap: 10px; }
            .inv-top-bar .logo-area { text-align: left; }
            .btn-float { top: auto; bottom: 16px; right: 16px; }
        }

        @media(max-width:480px){.inv-top-bar,.inv-body-section,.inv-footer-bar{padding:14px 16px!important}.inv-additional-grid{grid-template-columns:1fr 1fr!important}}
        @media(max-width:380px){.inv-additional-grid{grid-template-columns:1fr!important}.inv-top-bar,.inv-body-section,.inv-footer-bar{padding:12px!important}}
    </style>
</head>
<body>
<div class="btn-float no-print">
    <a href="?page=invoice" class="btn btn-dark btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
    <button onclick="window.print()" class="btn btn-primary btn-sm"><i class="fas fa-print me-1"></i> Print / PDF</button>
    <?php if ($canEdit): ?>
    <button onclick="document.getElementById('emailModal').style.display='flex'" class="btn btn-success btn-sm"><i class="fas fa-envelope me-1"></i> Email</button>
    <?php endif; ?>
</div>

<?php if (!empty($messages)): ?>
<div style="max-width:900px;margin:0 auto 12px">
    <?php foreach ($messages as $m): ?>
    <div class="alert alert-<?= $m['type'] === 'error' ? 'danger' : $m['type'] ?> alert-dismissible fade show py-2 small" role="alert">
        <?= htmlspecialchars($m['text']) ?>
        <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="invoice-paper">
    <!-- ======================== TOP BAR ======================== -->
    <div class="inv-top-bar">
        <div class="brand">
            <h1><?= htmlspecialchars($companyName) ?></h1>
            <p><?= htmlspecialchars($companyAddress) ?></p>
            <p><i class="fas fa-phone me-1"></i> <?= htmlspecialchars($companyPhone) ?> &nbsp; <i class="fas fa-envelope me-1"></i> <?= htmlspecialchars($companyEmail) ?></p>
            <?php if ($companyTin): ?><p><i class="fas fa-id-card me-1"></i> TIN: <strong><?= htmlspecialchars($companyTin) ?></strong></p><?php endif; ?>
        </div>
        <div class="logo-area">
            <img src="<?= htmlspecialchars(brand_logo()) ?>" alt="Logo" style="height:64px;width:auto;max-width:120px;object-fit:contain;margin:0 auto 6px;display:block">
            <div class="inv-title">INVOICE</div>
        </div>
    </div>

    <!-- ======================== META BAR ======================== -->
    <div class="inv-meta-bar">
        <div class="meta-col">
            <div class="meta-label">Invoice Number</div>
            <div class="meta-value"><?= htmlspecialchars($inv['invoice_no']) ?></div>
        </div>
        <div class="meta-col">
            <div class="meta-label">Reference No.</div>
            <div class="meta-value"><?= htmlspecialchars($inv['reference_no'] ?? '—') ?></div>
        </div>
        <div class="meta-col">
            <div class="meta-label">Invoice Date</div>
            <div class="meta-value"><?= date('d M Y', strtotime($inv['generated_at'])) ?></div>
        </div>
        <div class="meta-col">
            <div class="meta-label">Sales Type</div>
            <div class="meta-value"><span class="badge bg-<?= ($inv['sales_type'] ?? 'cash') === 'credit' ? 'warning text-dark' : 'info' ?>"><?= ucfirst($inv['sales_type'] ?? 'cash') ?></span></div>
        </div>
        <div class="meta-col">
            <div class="meta-label">Sales Rep</div>
            <div class="meta-value"><?= htmlspecialchars($salespersonName ?: '—') ?></div>
        </div>
    </div>

    <!-- ======================== CUSTOMER INFO ======================== -->
    <div class="inv-body-section">
        <div class="inv-info-grid">
            <div class="inv-info-box">
                <h5><i class="fas fa-building me-1"></i> Customer Information</h5>
                <p><strong style="font-size:14px"><?= htmlspecialchars($inv['customer_name']) ?></strong></p>
                <p><span class="label">Code:</span> <?= htmlspecialchars($inv['customer_code'] ?? '—') ?></p>
                <p><span class="label">TIN:</span> <?= htmlspecialchars($inv['customer_tin'] ?? '—') ?></p>
                <p><span class="label">Contact Person:</span> <?= htmlspecialchars($inv['contact_person'] ?? '—') ?></p>
                <p><span class="label">Phone:</span> <?= htmlspecialchars($inv['phone'] ?? '—') ?></p>
                <?php if ($inv['customer_email']): ?><p><span class="label">Email:</span> <?= htmlspecialchars($inv['customer_email']) ?></p><?php endif; ?>
            </div>
            <div class="inv-info-box">
                <h5><i class="fas fa-map-marker-alt me-1"></i> Address & Delivery</h5>
                <p><span class="label">Address:</span> <?= htmlspecialchars($inv['customer_address'] ?? '—') ?></p>
                <p><span class="label">Delivery Address:</span></p>
                <p><?= nl2br(htmlspecialchars($inv['delivery_address'] ?? 'Not specified')) ?></p>
                <div class="mt-2">
                    <?php
                        $psClass = $paymentStatus === 'paid' ? 'paid' : ($paymentStatus === 'partial' ? 'partial' : 'unpaid');
                    $dsLabels = ['assigned'=>'Assigned','out_for_delivery'=>'Out for Delivery','pod_pending'=>'Awaiting Verification','delivered'=>'Delivered'];
                    ?>
                    <span class="inv-badge <?= $psClass ?>"><?= ucfirst($paymentStatus) ?></span>
                    <span class="inv-badge <?= $deliveryStatus === 'delivered' ? 'delivered' : ($deliveryStatus === 'out_for_delivery' ? 'out-for' : ($deliveryStatus === 'pod_pending' ? 'pending' : 'pending')) ?>">
                        <i class="fas fa-<?= $deliveryStatus === 'delivered' ? 'check-circle' : ($deliveryStatus === 'out_for_delivery' ? 'truck' : ($deliveryStatus === 'pod_pending' ? 'clipboard-check' : 'clock')) ?>"></i>
                        <?= $dsLabels[$deliveryStatus] ?? 'Pending' ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ======================== PRODUCT TABLE ======================== -->
        <div class="inv-section-title"><i class="fas fa-pills me-1"></i> Product Details</div>
        <div class="table-responsive">
            <table class="inv-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Code</th>
                        <th>Product Name</th>
                        <th>Generic Name</th>
                        <th>Str.</th>
                        <th>Form</th>
                        <th>Mfr.</th>
                        <th>Batch</th>
                        <th>Expiry</th>
                        <th>Unit</th>
                        <th class="text-right">Qty</th>
                        <th class="text-right">Price</th>
                        <th class="text-right">Disc.</th>
                        <th class="text-right">Tax</th>
                        <th class="text-right">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $idx = 0; foreach ($items as $it):
                        $qty = (int) $it['quantity'];
                        $price = (float) $it['unit_price'];
                        $disc = (float) ($it['discount'] ?? 0);
                        $tax = (float) ($it['tax_amount'] ?? 0);
                        $lineTotal = ($price * $qty) - $disc + $tax;
                        $idx++;
                        $expClass = '';
                        if (!empty($it['expiry_date'])) {
                            $expTime = strtotime($it['expiry_date']);
                            if ($expTime < time()) $expClass = 'exp-danger';
                            elseif ($expTime < time() + 7776000) $expClass = 'exp-warn';
                        }
                    ?>
                    <tr>
                        <td class="text-center"><?= $idx ?></td>
                        <td><small class="text-muted"><?= htmlspecialchars(substr($it['product_code'] ?? '', 0, 12)) ?></small></td>
                        <td><strong><?= htmlspecialchars($it['name']) ?></strong></td>
                        <td><small><?= htmlspecialchars($it['generic_name'] ?? '—') ?></small></td>
                        <td><small><?= htmlspecialchars($it['strength'] ?? '—') ?></small></td>
                        <td><small><?= htmlspecialchars($it['dosage_form'] ?? '—') ?></small></td>
                        <td><small><?= htmlspecialchars($it['manufacturer'] ?? '—') ?></small></td>
                        <td><code><?= htmlspecialchars($it['batch_no'] ?? '—') ?></code></td>
                        <td class="<?= $expClass ?>"><small><?= !empty($it['expiry_date']) ? date('M Y', strtotime($it['expiry_date'])) : '—' ?></small></td>
                        <td><small><?= htmlspecialchars($it['unit_of_measure'] ?? 'Pcs') ?></small></td>
                        <td class="text-right"><?= $qty ?></td>
                        <td class="text-right"><?= fmt($price) ?></td>
                        <td class="text-right"><?= $disc > 0 ? fmt($disc) : '—' ?></td>
                        <td class="text-right"><?= $tax > 0 ? fmt($tax) : '—' ?></td>
                        <td class="text-right fw-bold"><?= fmt($lineTotal) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="10" class="text-right fw-bold">Totals</td>
                        <td class="text-right fw-bold"><?= $totalQty ?></td>
                        <td colspan="2" class="text-right"><?= $totalItems ?> items</td>
                        <td class="text-right"><?= fmt($totalDiscount) ?></td>
                        <td class="text-right fw-bold"><?= fmt($grandTotal) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- ======================== SUMMARY ======================== -->
        <div class="inv-summary-section">
            <div class="inv-summary-left">
                <?php if (!empty($returnRefs)): ?>
                <div class="inv-section-title"><i class="fas fa-undo me-1"></i> Return References</div>
                <table class="table table-sm table-bordered mb-3" style="font-size:11px">
                    <thead><tr><th>Return #</th><th>Date</th><th>Refund</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($returnRefs as $rr): ?>
                        <tr>
                            <td><strong>RET-<?= (int) $rr['id'] ?></strong></td>
                            <td><?= htmlspecialchars($rr['return_date']) ?></td>
                            <td><?= fmt((float) $rr['refund_amount']) ?></td>
                            <td><span class="inv-badge <?= $rr['status'] === 'approved' ? 'paid' : 'pending' ?>" style="font-size:9px"><?= ucfirst($rr['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <div class="inv-summary-right">
                <table class="inv-totals-table">
                    <tr><td class="text-muted">Total Items</td><td class="text-end"><?= $totalItems ?></td></tr>
                    <tr><td class="text-muted">Total Quantity</td><td class="text-end"><?= $totalQty ?></td></tr>
                    <tr><td class="text-muted">Subtotal</td><td class="text-end"><?= fmt($subtotal) ?></td></tr>
                    <?php if ($totalDiscount > 0): ?><tr><td class="text-muted">Discount</td><td class="text-end text-danger">- <?= fmt($totalDiscount) ?></td></tr><?php endif; ?>
                    <tr><td class="text-muted">VAT (<?= $taxRate ?>%)</td><td class="text-end"><?= fmt($totalTax) ?></td></tr>
                    <tr><td class="grand">Grand Total</td><td class="text-end grand"><?= fmt($grandTotal) ?></td></tr>
                    <tr><td class="paid">Amount Paid</td><td class="text-end paid"><?= fmt($amountPaid) ?></td></tr>
                    <?php if ($outstanding > 0): ?>
                    <tr><td class="balance">Outstanding Balance</td><td class="text-end balance"><?= fmt($outstanding) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($creditDue): ?>
                    <tr><td class="text-muted">Credit Due Date</td><td class="text-end fw-bold" style="color:var(--warn)"><?= htmlspecialchars($creditDue) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <!-- ======================== ADDITIONAL INFO ======================== -->
        <div class="mt-3">
            <div class="inv-section-title"><i class="fas fa-info-circle me-1"></i> Additional Information</div>
            <div class="inv-additional-grid">
                <div class="inv-additional-item">
                    <div class="ai-label">Payment Status</div>
                    <div class="ai-value"><span class="inv-badge <?= $psClass ?>"><?= ucfirst($paymentStatus) ?></span><?php if ($isOverdue): ?> <span class="inv-badge" style="background:#dc2626;color:#fff">Overdue</span><?php endif; ?></div>
                </div>
                <div class="inv-additional-item">
                    <div class="ai-label">Credit Due Date</div>
                    <div class="ai-value"><?= $creditDue ? htmlspecialchars($creditDue) : '—' ?><?php if ($isOverdue): ?> <small class="text-muted">(<?= max((int) floor((time() - strtotime((string) $creditDue)) / 86400), 1) ?> day<?= max((int) floor((time() - strtotime((string) $creditDue)) / 86400), 1) === 1 ? '' : 's' ?> late)</small><?php endif; ?></div>
                </div>
                <div class="inv-additional-item">
                    <div class="ai-label">Delivery Status</div>
                    <div class="ai-value"><?= $dsLabels[$deliveryStatus] ?? 'Pending' ?></div>
                </div>
                <div class="inv-additional-item">
                    <div class="ai-label">Warehouse</div>
                    <div class="ai-value"><?= htmlspecialchars($inv['warehouse_name'] ?? '—') ?></div>
                </div>
                <div class="inv-additional-item">
                    <div class="ai-label">Prepared By</div>
                    <div class="ai-value"><?= htmlspecialchars($preparedByName ?: '—') ?></div>
                </div>
                <div class="inv-additional-item">
                    <div class="ai-label">Approved By</div>
                    <div class="ai-value"><?= htmlspecialchars($approvedByName ?: '—') ?></div>
                </div>
                <div class="inv-additional-item">
                    <div class="ai-label">Received By</div>
                    <div class="ai-value"><?= ($confirmedAt && in_array($deliveryStatus, ['pod_pending', 'delivered'], true)) ? htmlspecialchars($inv['customer_name']) . '<br><small class="text-muted">' . htmlspecialchars($confirmedAt) . '</small>' : '—' ?></div>
                </div>
                <div class="inv-additional-item">
                    <div class="ai-label">Company Stamp</div>
                    <div class="ai-value" style="font-style:italic;color:#9ca3af;font-size:11px">Stamp area</div>
                </div>
            </div>
        </div>

        <!-- ======================== SIGNATURE ======================== -->
        <div class="inv-info-grid mt-3">
            <div>
                <div class="inv-section-title"><i class="fas fa-pen-fancy me-1"></i> Signature</div>
                <?php if (!empty($inv['digital_signature'])): ?>
                    <div class="sig-box">
                        <img src="<?= htmlspecialchars($inv['digital_signature']) ?>" alt="Signature">
                    </div>
                    <?php if ($canEdit): ?>
                    <form method="post" class="mt-2 no-print text-end">
                        <?= csrf_field() ?>
                        <input type="hidden" name="clear_signature" value="1">
                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-times me-1"></i>Clear</button>
                    </form>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if ($canEdit): ?>
                    <div class="no-print">
                        <canvas id="sig-canvas" class="sig-canvas" width="400" height="100" style="width:100%;max-width:400px"></canvas>
                        <div class="mt-2">
                            <button type="button" onclick="clearSig()" class="btn btn-sm btn-outline-danger me-1"><i class="fas fa-times"></i> Clear</button>
                            <button type="button" onclick="saveSig()" class="btn btn-sm btn-success"><i class="fas fa-save me-1"></i>Save Signature</button>
                        </div>
                        <form id="sig-form" method="post" style="display:none">
                            <?= csrf_field() ?>
                            <input type="hidden" name="save_signature" value="1">
                            <input type="hidden" name="signature_data" id="sig-data">
                        </form>
                    </div>
                    <?php else: ?>
                    <div class="sig-box"><span class="empty">No signature</span></div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div>
                <div class="inv-section-title"><i class="fas fa-qrcode me-1"></i> Invoice QR Code</div>
                <div class="text-center p-3 border rounded bg-light">
                    <img src="<?= $qrUrl ?>" alt="QR Code" style="width:120px;height:120px;border-radius:6px">
                    <div class="mt-2 small text-muted">Scan to verify</div>
                </div>
            </div>
        </div>

        <!-- ======================== DELIVERY CONFIRMATION ======================== -->
        <?php if (customerAccountCustomerId() !== null && $deliveryStatus === 'out_for_delivery'): ?>
        <div class="confirm-section mt-3 p-3 border rounded" style="background:#f0fdf4;border-color:#059669">
            <h6 class="fw-bold" style="color:#047857"><i class="fas fa-check-circle me-1"></i> Confirm Delivery</h6>
            <p class="small text-muted mb-2">Verify the items above. Check the box and sign below to confirm receipt of all items.</p>
            <form method="post" id="deliveryConfirmForm">
                <?= csrf_field() ?>
                <input type="hidden" name="confirm_delivery_invoice" value="1">
                <input type="hidden" name="cust_signature_data" id="cust-sig-data">
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="agree_received" required id="agreeCheck">
                    <label class="form-check-label small" for="agreeCheck">I confirm I have received all items listed in this invoice.</label>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold" style="color:#047857">Customer Signature <span class="text-danger">*</span></label>
                    <canvas id="cust-sig-canvas" class="sig-canvas" width="400" height="100" style="width:100%;max-width:400px;border:2px solid #059669;border-radius:8px;background:#fff"></canvas>
                    <div class="mt-1">
                        <button type="button" onclick="clearCustSig()" class="btn btn-sm btn-outline-danger"><i class="fas fa-times"></i> Clear</button>
                    </div>
                </div>
                <button type="submit" onclick="return captureCustSig()" class="btn btn-success btn-sm"><i class="fas fa-check me-1"></i>Confirm Receipt</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($confirmedAt): ?>
        <div class="alert alert-<?= $deliveryStatus === 'pod_pending' ? 'warning' : 'success' ?> mt-3 py-2 small">
            <i class="fas fa-check-circle me-1"></i> <?= $deliveryStatus === 'pod_pending' ? 'Receipt confirmed — awaiting delivery staff verification' : 'Delivery confirmed' ?> on <?= htmlspecialchars($confirmedAt) ?>
            <?php if ($inv['confirmation_method']): ?> via <?= htmlspecialchars(ucfirst($inv['confirmation_method'])) ?><?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Show customer signature if already confirmed -->
        <?php if (!empty($inv['customer_signature']) && in_array($deliveryStatus, ['pod_pending', 'delivered'], true)): ?>
        <div class="mt-3 p-3 border rounded" style="background:#f0fdf4;border-color:#059669">
            <h6 class="fw-bold" style="color:#047857"><i class="fas fa-signature me-1"></i> Customer Signature</h6>
            <img src="<?= htmlspecialchars($inv['customer_signature']) ?>" alt="Customer Signature" style="max-width:300px;border:1px solid #d1d5db;border-radius:6px;padding:4px;background:#fff">
        </div>
        <?php endif; ?>
    </div>

    <!-- ======================== FOOTER ======================== -->
    <div class="inv-footer-bar">
        <div class="qr">
            <img src="<?= $qrUrl ?>" alt="QR">
        </div>
        <div class="footer-text">
            <strong><?= htmlspecialchars($companyName) ?></strong><br>
            <?= htmlspecialchars($companyAddress) ?> | <?= htmlspecialchars($companyPhone) ?> | <?= htmlspecialchars($companyEmail) ?><br>
            <small>This is a computer-generated invoice. For questions, please contact us.</small>
        </div>
    </div>
</div>

<!-- ======================== EMAIL MODAL ======================== -->
<?php if ($canEdit): ?>
<div id="emailModal" class="inv-email-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:20px">
    <div style="background:#fff;border-radius:12px;max-width:420px;width:100%;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,.3)">
        <h5 class="fw-bold mb-3"><i class="fas fa-envelope me-2 text-success"></i>Email Invoice</h5>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="email_invoice" value="1">
            <div class="mb-3">
                <label class="form-label small fw-bold">Send to Email</label>
                <input type="email" name="email_to" class="form-control" value="<?= htmlspecialchars($inv['customer_email'] ?? '') ?>" required placeholder="customer@email.com">
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-paper-plane me-1"></i>Send</button>
                <button type="button" onclick="document.getElementById('emailModal').style.display='none'" class="btn btn-secondary btn-sm">Cancel</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Digital signature canvas
(function() {
    var canvas = document.getElementById('sig-canvas');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var drawing = false, lastX, lastY;
    function getPos(e) { var r = canvas.getBoundingClientRect(); var t = e.touches ? e.touches[0] : e; return { x: t.clientX - r.left, y: t.clientY - r.top }; }
    canvas.addEventListener('mousedown', function(e) { drawing = true; var p = getPos(e); lastX = p.x; lastY = p.y; });
    canvas.addEventListener('mousemove', function(e) { if (!drawing) return; var p = getPos(e); ctx.beginPath(); ctx.moveTo(lastX, lastY); ctx.lineTo(p.x, p.y); ctx.strokeStyle = '#1a1a1a'; ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.stroke(); lastX = p.x; lastY = p.y; });
    canvas.addEventListener('mouseup', function() { drawing = false; });
    canvas.addEventListener('mouseleave', function() { drawing = false; });
    canvas.addEventListener('touchstart', function(e) { e.preventDefault(); drawing = true; var p = getPos(e); lastX = p.x; lastY = p.y; }, { passive: false });
    canvas.addEventListener('touchmove', function(e) { e.preventDefault(); if (!drawing) return; var p = getPos(e); ctx.beginPath(); ctx.moveTo(lastX, lastY); ctx.lineTo(p.x, p.y); ctx.strokeStyle = '#1a1a1a'; ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.stroke(); lastX = p.x; lastY = p.y; }, { passive: false });
    canvas.addEventListener('touchend', function() { drawing = false; });
    window.clearSig = function() { ctx.clearRect(0, 0, canvas.width, canvas.height); };
    window.saveSig = function() { document.getElementById('sig-data').value = canvas.toDataURL('image/png'); document.getElementById('sig-form').submit(); };
})();

// Customer signature canvas (delivery confirmation)
(function() {
    var canvas = document.getElementById('cust-sig-canvas');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var drawing = false, lastX, lastY;
    function getPos(e) { var r = canvas.getBoundingClientRect(); var t = e.touches ? e.touches[0] : e; return { x: t.clientX - r.left, y: t.clientY - r.top }; }
    canvas.addEventListener('mousedown', function(e) { drawing = true; var p = getPos(e); lastX = p.x; lastY = p.y; });
    canvas.addEventListener('mousemove', function(e) { if (!drawing) return; var p = getPos(e); ctx.beginPath(); ctx.moveTo(lastX, lastY); ctx.lineTo(p.x, p.y); ctx.strokeStyle = '#1a1a1a'; ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.stroke(); lastX = p.x; lastY = p.y; });
    canvas.addEventListener('mouseup', function() { drawing = false; });
    canvas.addEventListener('mouseleave', function() { drawing = false; });
    canvas.addEventListener('touchstart', function(e) { e.preventDefault(); drawing = true; var p = getPos(e); lastX = p.x; lastY = p.y; }, { passive: false });
    canvas.addEventListener('touchmove', function(e) { e.preventDefault(); if (!drawing) return; var p = getPos(e); ctx.beginPath(); ctx.moveTo(lastX, lastY); ctx.lineTo(p.x, p.y); ctx.strokeStyle = '#1a1a1a'; ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.stroke(); lastX = p.x; lastY = p.y; }, { passive: false });
    canvas.addEventListener('touchend', function() { drawing = false; });
    window.clearCustSig = function() { ctx.clearRect(0, 0, canvas.width, canvas.height); };
    window.captureCustSig = function() {
        var data = canvas.toDataURL('image/png');
        var blank = document.createElement('canvas');
        blank.width = canvas.width; blank.height = canvas.height;
        if (canvas.toDataURL() === blank.toDataURL()) { alert('Please sign before confirming.'); return false; }
        document.getElementById('cust-sig-data').value = data;
        return true;
    };
})();

// Close email modal on backdrop click
document.getElementById('emailModal')?.addEventListener('click', function(e) { if (e.target === this) this.style.display = 'none'; });
</script>
</body>
</html>
