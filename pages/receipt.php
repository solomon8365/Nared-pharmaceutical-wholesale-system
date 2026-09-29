<?php
requirePermission('sales', 'view');
require_once __DIR__ . '/../config.php';
$pdo = getPDO();
$saleId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($saleId <= 0) {
    header('Location: index.php?page=sales');
    exit;
}

$stmt = $pdo->prepare('SELECT s.*, c.name AS customer_name FROM sales s LEFT JOIN customers c ON s.customer_id = c.id WHERE s.id = :id');
$stmt->execute(['id' => $saleId]);
$sale = $stmt->fetch();

if (!$sale) {
    header('Location: index.php?page=sales');
    exit;
}

if (isSalesperson()) {
    $me = salespersonForUser($pdo, (int) ($_SESSION['user']['id'] ?? 0));
    if (!$me || (int) ($sale['salesperson_id'] ?? 0) !== (int) $me['id']) {
        header('Location: index.php?page=sales');
        exit;
    }
}

$itemsStmt = $pdo->prepare('SELECT si.quantity, si.unit_price, p.name AS product_name, p.barcode FROM sale_items si JOIN products p ON si.product_id = p.id WHERE si.sale_id = :id');
$itemsStmt->execute(['id' => $saleId]);
$items = $itemsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt #<?= htmlspecialchars((string) $saleId) ?> - <?= htmlspecialchars(APP_NAME) ?></title>
    <?= favicon_tags() ?>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <style>
        body { background: var(--white); margin: 0; padding: 20px; font-size: 14px; }
        .receipt { max-width: 700px; margin: 0 auto; padding: 30px; border: 2px solid var(--brown-200); border-radius: var(--radius-lg); }
        .receipt-header { text-align: center; border-bottom: 3px double var(--brown-800); padding-bottom: 16px; margin-bottom: 20px; }
        .receipt-header h1 { margin: 0 0 4px; color: var(--brown-900); font-size: 1.4rem; border: none; display: block; }
        .receipt-header .receipt-no { font-size: 0.95rem; color: var(--neutral-700); margin-top: 4px; }
        .receipt-meta { display: flex; justify-content: space-between; margin-bottom: 18px; font-size: 0.92rem; }
        .receipt-meta div { line-height: 1.6; }
        .receipt table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .receipt th { background: var(--brown-800); color: var(--white); padding: 10px 8px; text-align: left; font-size: 0.82rem; }
        .receipt td { padding: 10px 8px; border-bottom: 1px solid var(--neutral-200); }
        .receipt tbody tr:last-child td { border-bottom: 2px solid var(--brown-800); }
        .receipt .amount { text-align: right; }
        .receipt .total-row td { font-weight: 800; font-size: 1.1rem; color: var(--brown-900); padding-top: 12px; }
        .receipt-footer { text-align: center; margin-top: 24px; color: var(--neutral-500); font-size: 0.88rem; border-top: 1px solid var(--brown-200); padding-top: 16px; }
        .no-print { text-align: center; margin-bottom: 16px; }
        @media(max-width:480px){.receipt-meta{flex-direction:column;gap:8px}}
        @media print {
            .no-print { display: none; }
            body { padding: 0; }
            .receipt { border: none; border-radius: 0; padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()" style="background:var(--brown-700);color:var(--white);border:none;padding:10px 24px;border-radius:6px;cursor:pointer;font-weight:700;font-size:1rem">Print / Save PDF</button>
        <a href="index.php?page=sales" style="display:inline-block;margin-left:12px;color:var(--brown-800);font-weight:600">← Back to sales</a>
    </div>
    <div class="receipt">
        <div class="receipt-header">
            <img src="<?= htmlspecialchars(brand_logo()) ?>" alt="Logo" style="height:56px;width:auto;max-width:110px;object-fit:contain;margin:0 auto 8px;display:block">
            <h1><?= htmlspecialchars(APP_NAME) ?></h1>
            <div class="receipt-no">Receipt #<?= htmlspecialchars((string) $saleId) ?></div>
        </div>
        <div class="receipt-meta">
            <div>
                <strong>Customer:</strong> <?= htmlspecialchars($sale['customer_name'] ?? 'N/A') ?><br>
                <strong>Fulfillment:</strong> <?= (($sale['fulfillment_method'] ?? '') === 'pickup') ? 'Customer Pickup' : 'Delivery' ?><br>
            </div>
            <div style="text-align:right">
                <strong>Date:</strong> <?= htmlspecialchars($sale['sale_date']) ?><br>
                <strong>Payment:</strong> <?= ((int) ($sale['payment_confirmed'] ?? 0) === 1) ? 'Paid' : 'Unpaid' ?><?php if (!empty($sale['payment_method'])): ?> — <?= htmlspecialchars(ucfirst(str_replace('_', ' ', (string) $sale['payment_method']))) ?><?php endif; ?><?php if (!empty($sale['payment_detail'])): ?> (<?= htmlspecialchars($sale['payment_detail']) ?>)<?php endif; ?><br>
            </div>
        </div>
        <table>
            <thead>
                <tr><th>Product</th><th class="amount">Qty</th><th class="amount">Unit price</th><th class="amount">Total</th></tr>
            </thead>
            <tbody>
                <?php
                $subtotal = 0;
                $hasBarcode = false;
                foreach ($items as $item):
                    $lineTotal = (float) $item['unit_price'] * (int) $item['quantity'];
                    $subtotal += $lineTotal;
                    if (!empty($item['barcode'])) $hasBarcode = true;
                ?>
                <tr>
                    <td>
                        <?= htmlspecialchars($item['product_name']) ?>
                        <?php if (!empty($item['barcode'])): ?>
                            <br><svg class="barcode-line" data-barcode="<?= htmlspecialchars($item['barcode']) ?>" style="max-width:140px;margin-top:4px"></svg>
                        <?php endif; ?>
                    </td>
                    <td class="amount"><?= htmlspecialchars((string) $item['quantity']) ?></td>
                    <td class="amount"><?= fmt((float) $item['unit_price']) ?></td>
                    <td class="amount"><?= fmt($lineTotal) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td colspan="3" style="text-align:right">Total:</td>
                    <td class="amount"><?= fmt($subtotal) ?></td>
                </tr>
            </tfoot>
        </table>
        <div class="receipt-footer">
            Thank you for your business!<br>
            <?= htmlspecialchars(APP_NAME) ?>
        </div>
    </div>
<script>
document.querySelectorAll('.barcode-line').forEach(function(el) {
    try { JsBarcode(el, el.getAttribute('data-barcode'), { format: 'CODE128', width: 1, height: 24, displayValue: false, margin: 2, background: 'transparent' }); } catch(e) {}
});
</script>
</body>
</html>
