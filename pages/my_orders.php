<?php
require_once __DIR__ . '/../includes/workflow_helpers.php';
require_once __DIR__ . '/../includes/credit_helpers.php';
requirePermission('my_orders', 'view');
$pdo = getPDO();
$cid = customerAccountCustomerId();
$messages = [];


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_rejected'])) {
    require_csrf();
    $orderId = (int) ($_POST['order_id'] ?? 0);
    if ($orderId > 0) {
        $stmt = $pdo->prepare("DELETE FROM customer_orders WHERE id = :id AND customer_id = :cid AND status = 'rejected'");
        $stmt->execute(['id' => $orderId, 'cid' => $cid]);
        $messages[] = ['type' => $stmt->rowCount() > 0 ? 'success' : 'error', 'text' => $stmt->rowCount() > 0 ? 'Order deleted.' : 'Could not delete.'];
    }
}

if ($cid === null) {
    $rows = []; $invoices = []; $stats = ['total' => 0, 'pending' => 0, 'processing' => 0, 'delivered' => 0, 'total_spent' => 0];
} else {
    $rows = $pdo->prepare('SELECT co.* FROM customer_orders co WHERE co.customer_id = :cid ORDER BY co.created_at DESC');
    $rows->execute(['cid' => $cid]);
    $rows = $rows->fetchAll();

    $invStmt = $pdo->prepare('SELECT id, invoice_no, customer_order_id FROM invoices WHERE customer_id = :cid');
    $invStmt->execute(['cid' => $cid]);
    $invoices = [];
    foreach ($invStmt->fetchAll() as $inv) $invoices[(int)$inv['customer_order_id']] = $inv;

    $stats = ['total' => count($rows), 'pending' => 0, 'processing' => 0, 'delivered' => 0, 'total_spent' => 0];
    foreach ($rows as $r) {
        if ($r['status'] === 'pending') $stats['pending']++;
        elseif ($r['status'] === 'approved') {
            if ($r['delivery_status'] === 'delivered' || $r['fulfillment_status'] === StatusCatalog::FULFILLMENT_COMPLETED) $stats['delivered']++;
            else $stats['processing']++;
        }
        if ($r['payment_confirmed']) $stats['total_spent'] += (float) $r['total_amount'];
    }
}

$unreadNotifs = $cid !== null ? getUnreadNotificationCount($pdo, $cid) : 0;

$export = $_GET['export'] ?? '';
if (in_array($export, ['csv', 'xls', 'pdf'])) {
    $filename = 'my_orders_' . date('Ymd');
    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'Date', 'Total', 'Status', 'Payment', 'Confirmed', 'Delivery']);
        foreach ($rows as $r) fputcsv($out, [$r['id'], $r['order_date'], $r['total_amount'], $r['status'], $r['payment_method'], $r['payment_confirmed'] ? 'Yes' : 'No', $r['delivery_status'] ?? '—']);
        fclose($out); exit;
    }
    if ($export === 'xls') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        echo '<html><head><meta charset="utf-8"><style>td,th{border:1px solid #ccc;padding:4px 8px;font-size:12px}th{background:#1e3a5f;color:#fff}</style></head><body><h2>My Orders</h2><table><tr><th>ID</th><th>Date</th><th>Total</th><th>Payment</th><th>Status</th><th>Confirmed</th><th>Delivery</th></tr>';
        foreach ($rows as $r) echo '<tr><td>'.$r['id'].'</td><td>'.$r['order_date'].'</td><td>'.$r['total_amount'].'</td><td>'.htmlspecialchars($r['payment_method']).'</td><td>'.$r['status'].'</td><td>'.($r['payment_confirmed']?'Yes':'No').'</td><td>'.($r['delivery_status']??'—').'</td></tr>';
        echo '</table></body></html>'; exit;
    }
    if ($export === 'pdf') {
        // Print-friendly standalone view (browser Print / Save-as-PDF).
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>My Orders — ' . $filename . '</title>';
        echo '<style>body{font-family:Arial,Helvetica,sans-serif;margin:24px}h1{font-size:18px;color:#1e3a5f}table{width:100%;border-collapse:collapse;margin-top:12px}th,td{border:1px solid #ccc;padding:6px 8px;font-size:12px;text-align:left}th{background:#1e3a5f;color:#fff}@media print{.no-print{display:none}}</style></head><body>';
        echo '<div class="no-print" style="margin-bottom:12px"><button onclick="window.print()" style="padding:8px 14px">Print / Save as PDF</button></div>';
        echo '<h1>My Orders</h1><table><tr><th>ID</th><th>Date</th><th>Total</th><th>Payment</th><th>Status</th><th>Confirmed</th><th>Delivery</th></tr>';
        foreach ($rows as $r) echo '<tr><td>'.$r['id'].'</td><td>'.$r['order_date'].'</td><td>'.$r['total_amount'].'</td><td>'.htmlspecialchars($r['payment_method']).'</td><td>'.$r['status'].'</td><td>'.($r['payment_confirmed']?'Yes':'No').'</td><td>'.($r['delivery_status']??'—').'</td></tr>';
        echo '</table></body></html>'; exit;
    }
}
?>
<style>
.mo-wrap{max-width:900px;margin:0 auto;padding:0 16px}
.mo-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px}
@media(max-width:600px){.mo-summary{grid-template-columns:repeat(2,1fr)}}
.mo-sum{text-align:center;padding:16px 10px;background:#fff;border-radius:10px;border:1px solid var(--neutral-200)}
.mo-sum-num{font-size:1.4rem;font-weight:700;color:var(--brown-800)}
.mo-sum-lbl{font-size:0.72rem;color:var(--neutral-500);margin-top:2px}
.mo-row{background:#fff;border:1px solid var(--neutral-200);border-radius:10px;margin-bottom:10px;overflow:hidden;transition:border-color 0.2s}
.mo-row:hover{border-color:var(--brown-300)}
.mo-top{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;cursor:pointer;gap:12px;flex-wrap:wrap}
.mo-top:hover{background:var(--neutral-50)}
.mo-left{min-width:0}
.mo-id{font-weight:700;font-size:0.95rem;color:var(--brown-800);display:flex;align-items:center;gap:8px}
.mo-date{font-size:0.78rem;color:var(--neutral-500);margin-top:2px}
.mo-right{text-align:right;flex-shrink:0}
.mo-amt{font-weight:700;font-size:1rem;color:var(--brown-800)}
.mo-method{font-size:0.72rem;color:var(--neutral-500);margin-top:2px}
.mo-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:0.7rem;font-weight:600;white-space:nowrap}
.mo-expand{display:none;padding:0 16px 16px}
.mo-expand.open{display:block}
.mo-info{display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:0.82rem;margin-bottom:12px}
.mo-info dt{color:var(--neutral-500);font-size:0.72rem}
.mo-info dd{font-weight:600;color:var(--brown-800)}
.mo-items{width:100%;border-collapse:collapse;font-size:0.8rem;margin-bottom:12px}
.mo-items th{text-align:left;padding:6px 8px;background:var(--neutral-50);font-size:0.72rem;font-weight:600;color:var(--neutral-600);border-bottom:1px solid var(--neutral-200)}
.mo-items td{padding:5px 8px;border-bottom:1px solid var(--neutral-100)}
.mo-actions{display:flex;gap:8px;flex-wrap:wrap}
.mo-btn{padding:8px 14px;border:none;border-radius:8px;font-size:0.78rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:4px;text-decoration:none;min-height:44px}
.mo-btn:hover{opacity:0.85}
.mo-btn-primary{background:var(--brown-600);color:#fff}
.mo-btn-success{background:var(--green-600);color:#fff}
.mo-btn-outline{background:transparent;border:1px solid var(--neutral-300);color:var(--neutral-600)}
.mo-btn-danger{background:var(--red-100);color:var(--red-700)}
.mo-empty{text-align:center;padding:40px 20px;color:var(--neutral-500)}
.mo-progress{display:flex;align-items:center;gap:0;margin:10px 0 14px;padding:0 4px}
.mo-pbar{flex:1;height:4px;border-radius:4px;background:var(--neutral-200);position:relative}
.mo-pbar-fill{height:100%;border-radius:4px;transition:width 0.3s}
.mo-pbar-label{font-size:0.68rem;color:var(--neutral-500);white-space:nowrap;margin-left:8px;min-width:60px;text-align:right}
</style>

<section class="mo-wrap">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <h1 style="font-size:1.2rem;font-weight:700;color:var(--brown-800);margin:0"><i class="fas fa-clipboard-list"></i> My Orders</h1>
        <div style="display:flex;gap:8px;align-items:center">
            <?php if ($unreadNotifs > 0): ?><span style="background:var(--red-600);color:#fff;border-radius:12px;font-size:0.68rem;padding:2px 8px;font-weight:700"><?= $unreadNotifs ?> new</span><?php endif; ?>
            <div class="export-dropdown">
                <button type="button" class="export-btn" onclick="toggleExport(this)" style="padding:5px 12px;border:1px solid var(--neutral-300);border-radius:8px;background:#fff;font-size:0.78rem;cursor:pointer"><i class="fas fa-download"></i> Export</button>
                <div class="export-dropdown-menu">
                    <a href="?page=my_orders&export=pdf"><i class="fas fa-file-pdf"></i> PDF</a>
                    <a href="?page=my_orders&export=xls"><i class="fas fa-file-excel"></i> Excel</a>
                    <a href="?page=my_orders&export=csv"><i class="fas fa-file-csv"></i> CSV</a>
                </div>
            </div>
        </div>
    </div>

    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>" style="margin-bottom:12px"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <?php if ($cid === null): ?>
        <div class="mo-empty">Your account is not linked to a customer profile. Contact admin.</div>
    <?php elseif (empty($rows)): ?>
        <div class="mo-empty">
            <i class="fas fa-shopping-cart" style="font-size:2rem;color:var(--neutral-300);display:block;margin-bottom:8px"></i>
            No orders yet. <a href="index.php?page=catalog" style="color:var(--brown-600);font-weight:600">Browse Catalog</a> to start.
        </div>
    <?php else: ?>
        <div class="mo-summary">
            <div class="mo-sum"><div class="mo-sum-num"><?= $stats['total'] ?></div><div class="mo-sum-lbl">Total</div></div>
            <div class="mo-sum"><div class="mo-sum-num" style="color:var(--orange-600)"><?= $stats['pending'] ?></div><div class="mo-sum-lbl">Pending</div></div>
            <div class="mo-sum"><div class="mo-sum-num" style="color:var(--blue-700,#1d4ed8)"><?= $stats['processing'] ?></div><div class="mo-sum-lbl">Processing</div></div>
            <div class="mo-sum"><div class="mo-sum-num" style="color:var(--green-600)"><?= $stats['delivered'] ?></div><div class="mo-sum-lbl">Delivered</div></div>
        </div>

        <?php foreach ($rows as $r):
            $oid = (int) $r['id'];
            $st = $r['status'];
            $payOk = $r['payment_confirmed'];
            $del = $r['delivery_status'];
            $fulfSt = $r['fulfillment_status'] ?? null;
            $isPickup = ($r['fulfillment_method'] ?? '') === StatusCatalog::FULFILLMENT_METHOD_PICKUP;

            if ($st === 'rejected') { $badge = '<span class="mo-badge" style="background:var(--red-100);color:var(--red-700)">Rejected</span>'; $pct = 0; }
            elseif ($del === 'delivered' || $fulfSt === StatusCatalog::FULFILLMENT_COMPLETED) { $badge = '<span class="mo-badge" style="background:var(--green-100);color:var(--green-700)"><i class="fas fa-check"></i> Delivered</span>'; $pct = 100; }
            elseif ($isPickup && $fulfSt === StatusCatalog::FULFILLMENT_WAITING_CUSTOMER_PICKUP) { $badge = '<span class="mo-badge" style="background:var(--green-100);color:var(--green-700)"><i class="fas fa-store"></i> Ready for Pickup</span>'; $pct = 85; }
            elseif ($isPickup && $payOk && $st === 'approved') { $badge = '<span class="mo-badge" style="background:var(--orange-100);color:var(--orange-700)"><i class="fas fa-store"></i> Paid · Awaiting Pickup</span>'; $pct = 60; }
            elseif ($del === 'out_for_delivery') { $badge = '<span class="mo-badge" style="background:var(--blue-100,#dbeafe);color:var(--blue-700,#1d4ed8)"><i class="fas fa-truck"></i> Out for Delivery</span>'; $pct = 75; }
            elseif ($del === 'assigned') { $badge = '<span class="mo-badge" style="background:var(--brown-100);color:var(--brown-700)"><i class="fas fa-truck"></i> Assigned</span>'; $pct = 60; }
            elseif ($fulfSt === StatusCatalog::FULFILLMENT_READY_FOR_DELIVERY) { $badge = '<span class="mo-badge" style="background:var(--blue-100,#dbeafe);color:var(--blue-700,#1d4ed8)"><i class="fas fa-truck"></i> Ready for Delivery</span>'; $pct = 70; }
            elseif ($payOk && $st === 'approved') { $badge = '<span class="mo-badge" style="background:var(--green-100);color:var(--green-700)"><i class="fas fa-check"></i> Paid</span>'; $pct = 50; }
            elseif ($st === 'approved') { $badge = '<span class="mo-badge" style="background:var(--orange-100);color:var(--orange-700)"><i class="fas fa-clock"></i> Awaiting Payment</span>'; $pct = 35; }
            else { $badge = '<span class="mo-badge" style="background:var(--orange-100);color:var(--orange-700)"><i class="fas fa-clock"></i> Pending</span>'; $pct = 15; }

            $barColor = $pct >= 100 ? 'var(--green-500)' : ($pct >= 50 ? 'var(--brown-500)' : 'var(--neutral-400)');
        ?>
            <div class="mo-row" style="border-left:3px solid <?= $pct >= 100 ? 'var(--green-500)' : ($st === 'rejected' ? 'var(--red-500)' : $barColor) ?>">
                <div class="mo-top" onclick="toggleOrder(<?= $oid ?>)">
                    <div class="mo-left">
                        <div class="mo-id">
                            #<?= $oid ?> <?= $badge ?>
                            <?php if ($payOk && $st !== 'rejected'): ?><span class="mo-badge" style="background:var(--green-100);color:var(--green-700)"><i class="fas fa-check-double"></i> Paid</span><?php endif; ?>
                        </div>
                        <div class="mo-date"><?= date('M j, Y', strtotime($r['order_date'])) ?></div>
                    </div>
                    <div class="mo-right">
                        <div class="mo-amt"><?= fmt((float) $r['total_amount']) ?></div>
                        <div class="mo-method"><?= formatPaymentMethod($r['payment_method'] ?? '') ?></div>
                    </div>
                </div>
                <div class="mo-progress" style="padding:0 16px">
                    <div class="mo-pbar"><div class="mo-pbar-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>"></div></div>
                    <div class="mo-pbar-label"><?= $pct ?>%</div>
                </div>

                <div class="mo-expand" id="order-<?= $oid ?>">
                    <dl class="mo-info">
                        <dt>Order Date</dt><dd><?= htmlspecialchars($r['order_date']) ?></dd>
                        <dt>Total</dt><dd><?= fmt((float) $r['total_amount']) ?></dd>
                        <dt>VAT</dt><dd><?= fmt((float) ($r['vat_amount'] ?? 0)) ?></dd>
                        <dt>Discount</dt><dd><?= (float)($r['discount_amount'] ?? 0) > 0 ? fmt((float) $r['discount_amount']) : '—' ?></dd>
                        <dt>Payment</dt><dd><?= formatPaymentMethod($r['payment_method'] ?? '') ?></dd>
                        <dt>Payment Status</dt><dd style="color:<?= $payOk ? 'var(--green-700)' : 'var(--orange-600)' ?>"><?= $payOk ? 'Confirmed' : 'Unpaid' ?></dd>
                        <?php if (($r['payment_method'] ?? '') === 'credit' && !empty($r['agreement_version_id'])):
                            $agRow = creditGetAgreementVersion($pdo, (int) $r['agreement_version_id']);
                            if ($agRow): ?>
                            <dt>Credit Agreement</dt><dd><i class="fas fa-file-contract"></i> <?= htmlspecialchars($agRow['title'] ?: 'Credit Agreement') ?> — <strong>Version <?= (int) $agRow['version'] ?></strong> (<?= (int) $agRow['payment_terms_days'] ?> day terms)<?php if (!empty($r['agreement_accepted_at'])): ?> <span style="color:var(--green-700)">· accepted <?= date('M j, Y', strtotime($r['agreement_accepted_at'])) ?></span><?php endif; ?></dd>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($r['transaction_ref']): ?><dt>Ref</dt><dd><?= htmlspecialchars($r['transaction_ref']) ?></dd><?php endif; ?>
                        <?php if ($r['delivery_address']): ?><dt>Address</dt><dd><?= htmlspecialchars($r['delivery_address']) ?></dd><?php endif; ?>
                        <?php if ($r['delivery_status']): ?><dt>Delivery</dt><dd><?= htmlspecialchars(ucwords(str_replace('_', ' ', $r['delivery_status']))) ?></dd><?php endif; ?>
                        <?php if ($r['fulfillment_method'] === StatusCatalog::FULFILLMENT_METHOD_PICKUP): ?><dt>Fulfillment</dt><dd><i class="fas fa-store"></i> Customer Pickup<?php if ($fulfSt === StatusCatalog::FULFILLMENT_WAITING_CUSTOMER_PICKUP): ?> <span style="color:var(--green-700);font-weight:700">— Ready! Visit the store to collect your order.</span><?php endif; ?></dd><?php endif; ?>
                        <?php if ($r['driver_name']): ?><dt>Driver</dt><dd><?= htmlspecialchars($r['driver_name']) ?></dd><?php endif; ?>
                        <?php if ($r['admin_note']): ?><dt>Note</dt><dd style="color:var(--brown-600)"><?= htmlspecialchars($r['admin_note']) ?></dd><?php endif; ?>
                        <?php if ($r['payment_rejection_reason']): ?><dt style="color:var(--red-600)">Rejection Reason</dt><dd style="color:var(--red-700)"><?= htmlspecialchars($r['payment_rejection_reason']) ?></dd><?php endif; ?>
                    </dl>

                    <?php
                    $iStmt = $pdo->prepare('SELECT coi.*, p.name AS product_name FROM customer_order_items coi JOIN products p ON coi.product_id = p.id WHERE coi.customer_order_id = :oid');
                    $iStmt->execute(['oid' => $oid]);
                    $orderItems = $iStmt->fetchAll();
                    if (!empty($orderItems)):
                    ?>
                        <div style="overflow-x:auto">
                        <table class="mo-items">
                            <thead><tr><th>Product</th><th>Batch</th><th>Expiry</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead>
                            <tbody>
                                <?php foreach ($orderItems as $it): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($it['product_name']) ?></td>
                                        <td><?= htmlspecialchars($it['batch_no'] ?? '—') ?></td>
                                        <td style="<?= !empty($it['expiry_date']) && strtotime($it['expiry_date']) < strtotime('+3 months') ? 'color:var(--red-600);font-weight:600' : '' ?>"><?= !empty($it['expiry_date']) ? date('M Y', strtotime($it['expiry_date'])) : '—' ?></td>
                                        <td><?= (int)$it['quantity'] ?></td>
                                        <td><?= fmt((float)$it['unit_price']) ?></td>
                                        <td style="font-weight:600"><?= fmt((float)$it['unit_price'] * (int)$it['quantity'] - (float)($it['discount'] ?? 0)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    <?php endif; ?>

                    <div class="mo-actions">
                        <?php if ($st === 'pending'): ?>
                            <a href="index.php?page=place_order&edit=<?= $oid ?>" class="mo-btn mo-btn-primary"><i class="fas fa-edit"></i> Edit</a>
                        <?php endif; ?>
                        <?php if ($st === 'rejected'): ?>
                            <form method="post" style="display:inline" onsubmit="return confirm('Delete this order?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="delete_rejected" value="1">
                                <input type="hidden" name="order_id" value="<?= $oid ?>">
                                <button type="submit" class="mo-btn mo-btn-danger"><i class="fas fa-trash"></i> Delete</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($del === 'out_for_delivery' && isset($invoices[$oid])): ?>
                            <a href="index.php?page=invoice&id=<?= (int)$invoices[$oid]['id'] ?>" class="mo-btn mo-btn-success"><i class="fas fa-file-invoice"></i> Confirm Delivery on Invoice</a>
                        <?php endif; ?>
                        <?php if (isset($invoices[$oid])): ?>
                            <a href="index.php?page=invoice&id=<?= (int)$invoices[$oid]['id'] ?>" target="_blank" class="mo-btn mo-btn-primary"><i class="fas fa-file-invoice"></i> <?= htmlspecialchars($invoices[$oid]['invoice_no']) ?></a>
                        <?php endif; ?>
                        <?php if ($r['payment_screenshot']): ?>
                            <a href="<?= htmlspecialchars($r['payment_screenshot']) ?>" target="_blank" class="mo-btn mo-btn-outline"><i class="fas fa-image"></i> Receipt</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<script>
function toggleOrder(id) {
    var el = document.getElementById('order-' + id);
    if (!el) return;
    var wasOpen = el.classList.contains('open');
    document.querySelectorAll('.mo-expand.open').forEach(function(d) { d.classList.remove('open'); });
    if (!wasOpen) el.classList.add('open');
}
</script>
