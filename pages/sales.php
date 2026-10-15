<?php
require_once __DIR__ . '/../services/init.php';
require_once __DIR__ . '/../includes/finance_helpers.php';
require_once __DIR__ . '/../includes/workflow_helpers.php';
require_once __DIR__ . '/../includes/workflow_engine.php';
require_once __DIR__ . '/../includes/credit_helpers.php';
require_once __DIR__ . '/../includes/delivery_helpers.php';
require_once __DIR__ . '/../includes/auto_migrate.php';
requirePermission('sales', 'view');
$pdo = getPDO();
ensureFulfillmentMigration($pdo);
$user = currentUser();
$uid = (int) ($user['id'] ?? 0);
$messages = [];
$products = $pdo->query("SELECT id, name, price, wholesale_price, stock_quantity, barcode FROM products WHERE product_status = 'active' AND (id IN (SELECT product_id FROM product_batches WHERE quantity > 0 AND status = 'active') OR (stock_quantity > 0 AND id NOT IN (SELECT product_id FROM product_batches WHERE quantity > 0))) ORDER BY name")->fetchAll();
$customers = $pdo->query('SELECT id, name FROM customers ORDER BY name')->fetchAll();
$salespeople = $pdo->query("SELECT id, name FROM salespeople WHERE status = 'active' ORDER BY name")->fetchAll();

$isSalespersonUser = isSalesperson();
$mySalesperson = null;
$activeAgreement = [];
if ($isSalespersonUser) {
    ensureSalespersonRoleMigration($pdo);
    $mySalesperson = salespersonForUser($pdo, $uid);
    if (!$mySalesperson) {
        $messages[] = ['type' => 'error', 'text' => 'No salesperson profile is linked to your account. Ask an administrator to link your salesperson account.'];
    }
}
try {
    $activeAgreement = creditGetActiveAgreement($pdo);
} catch (Throwable $e) {
    $activeAgreement = [];
}

$productMap = [];
foreach ($products as $p) {
    if (!empty($p['barcode'])) $productMap[$p['barcode']] = $p;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    // Confirm sale payment inline
    if (isset($_POST['confirm_sale_payment_id'])) {
        requirePermission('sales', 'pay');
        $sid = (int) $_POST['confirm_sale_payment_id'];
        if ($sid > 0) {
            try {
                $res = OrderService::confirmSalePayment($pdo, $sid, trim($_POST['payment_method'] ?? ''), trim($_POST['payment_detail'] ?? ''), (int) ($_SESSION['user']['id'] ?? 0));
                $messages[] = ['type' => 'success', 'text' => $res['msg']];
            } catch (ServiceException $e) {
                $messages[] = ['type' => 'error', 'text' => $e->getMessage()];
            }
        }
        // Don't process the rest of the form
    } elseif (isset($_POST['pay_commission_sale_id'])) {
        requirePermission('sales', 'pay');
        $sid = (int) $_POST['pay_commission_sale_id'];
        if ($sid > 0) {
            $res = PaymentService::payCommission($pdo, $sid);
            $messages[] = ['type' => $res['success'] ? 'success' : 'error', 'text' => $res['msg']];
        }
        // Don't process the rest of the form
    } elseif (isset($_POST['confirm_salesperson_order_id'])) {
        requirePermission('sales', 'pay');
        $orderId = (int) $_POST['confirm_salesperson_order_id'];
        if ($orderId <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Invalid order.'];
        } else {
            $res = financeConfirmOrderPayment($pdo, $orderId, (float) ($_POST['verified_amount'] ?? 0), trim($_POST['payment_notes'] ?? ''), $uid, (int) ($_POST['bank_account_id'] ?? 0));
            $messages[] = ['type' => $res['success'] ? 'success' : 'error', 'text' => $res['msg']];
        }
        // Don't process the rest of the form
    } else {
        requirePermission('sales', 'create');
        $data = [
            'customer_id' => (int) ($_POST['customer_id'] ?? 0),
            'sale_date' => $_POST['sale_date'] ?? date('Y-m-d'),
            'sale_items' => $_POST['sale_items'] ?? [],
            'salesperson_id' => (int) ($_POST['salesperson_id'] ?? 0),
            'commission_percent' => (float) ($_POST['commission_percent'] ?? 0),
            'fulfillment_method' => ($_POST['fulfillment_method'] ?? 'delivery') === 'pickup' ? 'pickup' : 'delivery',
            'delivery_address' => trim($_POST['delivery_address'] ?? ''),
            'payment_method' => trim($_POST['payment_method'] ?? ''),
            'payment_detail' => trim($_POST['payment_detail'] ?? ''),
            'payment_confirmed' => hasPermission('sales', 'pay') && !empty($_POST['payment_confirmed']),
            'agreement_version_id' => (int) ($_POST['agreement_version_id'] ?? 0),
            'agreement_accepted' => $_POST['agreement_accepted'] ?? '',
        ];
        if ($isSalespersonUser) {
            $data['salesperson_id'] = $mySalesperson ? (int) $mySalesperson['id'] : 0;
            $data['commission_percent'] = $mySalesperson ? (float) $mySalesperson['commission_rate'] : 0;
        }
        try {
            if ($isSalespersonUser) {
                $data['placed_by_user_id'] = $uid;
                $photoPath = '';
                if (isset($_FILES['payment_photo']) && $_FILES['payment_photo']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['payment_photo'];
                    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = finfo_file($finfo, $file['tmp_name']);
                    finfo_close($finfo);
                    if (!in_array($mime, $allowed, true)) throw new RuntimeException('Payment photo must be JPEG, PNG, WebP or GIF.');
                    if ($file['size'] > MAX_UPLOAD_SIZE) throw new RuntimeException('Payment photo too large (max 2 MB).');
                    do { $fname = 'sp_' . bin2hex(random_bytes(8)) . '.' . $extMap[$mime]; } while (is_file(PAYMENT_UPLOAD_DIR . DIRECTORY_SEPARATOR . $fname));
                    $photoPath = storage_put($file['tmp_name'], 'payments', $fname, $mime);
                    if ($photoPath === null) throw new RuntimeException('Failed to save payment photo.');
                }
                $data['payment_photo_path'] = $photoPath;
                $res = OrderService::createSalespersonOrder($pdo, $data);
                $messages[] = ['type' => 'success', 'text' => 'Salesperson order #' . (int) $res['order_id'] . ' submitted for the approval chain (Admin &rarr; Store Manager &rarr; Finance).'];
            } else {
                OrderService::recordSale($pdo, $data);
                $messages[] = ['type' => 'success', 'text' => 'Sale recorded successfully.'];
            }
            $products = $pdo->query("SELECT id, name, price, stock_quantity FROM products WHERE product_status = 'active' AND (id IN (SELECT product_id FROM product_batches WHERE quantity > 0 AND status = 'active') OR (stock_quantity > 0 AND id NOT IN (SELECT product_id FROM product_batches WHERE quantity > 0))) ORDER BY name")->fetchAll();
        } catch (ServiceException $e) {
            $messages[] = ['type' => 'error', 'text' => $e->getMessage()];
        } catch (Exception $e) {
            $messages[] = ['type' => 'error', 'text' => $e->getMessage()];
        }
    }
}

$filterCustomer = (int) ($_GET['customer_id'] ?? 0);
$filterDateFrom = trim($_GET['date_from'] ?? '');
$filterDateTo = trim($_GET['date_to'] ?? '');
$where = [];
$params = [];
if ($filterCustomer > 0) {
    $where[] = 's.customer_id = :customer';
    $params['customer'] = $filterCustomer;
}
if ($filterDateFrom !== '') {
    $where[] = 's.sale_date >= :date_from';
    $params['date_from'] = $filterDateFrom;
}
if ($filterDateTo !== '') {
    $where[] = 's.sale_date <= :date_to';
    $params['date_to'] = $filterDateTo;
}
if ($isSalespersonUser && $mySalesperson) {
    $where[] = 's.salesperson_id = :mysp';
    $params['mysp'] = (int) $mySalesperson['id'];
}
$sql = 'SELECT s.id, s.sale_date, s.fulfillment_method, s.total_amount, s.payment_method, s.payment_detail, s.payment_confirmed, s.agent_name, s.commission_percent, s.commission_amount, s.paid_at, c.name AS customer_name FROM sales s LEFT JOIN customers c ON s.customer_id = c.id';
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY s.created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

$spWhere = 'co.salesperson_id IS NOT NULL';
$spParams = [];
if ($isSalespersonUser && $mySalesperson) {
    $spWhere .= ' AND co.salesperson_id = :spid';
    $spParams['spid'] = (int) $mySalesperson['id'];
}
$spOrdersStmt = $pdo->prepare("SELECT co.*, c.name AS customer_name, sp.name AS salesperson_name
    FROM customer_orders co
    LEFT JOIN customers c ON co.customer_id = c.id
    LEFT JOIN salespeople sp ON co.salesperson_id = sp.id
    WHERE {$spWhere}
    ORDER BY co.created_at DESC LIMIT 200");
$spOrdersStmt->execute($spParams);
$spOrders = $spOrdersStmt->fetchAll();
$spItemsByOrder = [];
$spItemStmt = $pdo->query('SELECT coi.customer_order_id, coi.quantity, coi.unit_price, p.name AS product_name FROM customer_order_items coi JOIN products p ON coi.product_id = p.id');
foreach ($spItemStmt->fetchAll() as $spIt) {
    $spItemsByOrder[(int)$spIt['customer_order_id']][] = $spIt;
}

$myCommissions = [];
if ($isSalespersonUser && $mySalesperson) {
    $mcStmt = $pdo->prepare("SELECT c.*, COALESCE((SELECT cu.name FROM sales s JOIN customers cu ON s.customer_id = cu.id WHERE s.id = c.reference_id), '—') AS customer_name FROM commissions c WHERE c.salesperson_id = :spid ORDER BY c.created_at DESC LIMIT 50");
    $mcStmt->execute(['spid' => (int) $mySalesperson['id']]);
    $myCommissions = $mcStmt->fetchAll();
}
?>
<section>
    <h1>Sales</h1>
    <?php foreach ($messages as $message): ?>
        <div class="alert alert-<?= htmlspecialchars($message['type']) ?>"><?= htmlspecialchars($message['text']) ?></div>
    <?php endforeach; ?>

    <div class="tab-bar">
        <?php if (hasPermission('sales', 'create')): ?>
        <button class="tab-btn active" onclick="switchTab(this,'sale-new')"><i class="fas fa-plus"></i> New Sale</button>
        <?php endif; ?>
        <button class="tab-btn <?= hasPermission('sales', 'create') ? '' : 'active' ?>" onclick="switchTab(this,'sale-history')"><i class="fas fa-history"></i> Sale History</button>
        <button class="tab-btn" onclick="switchTab(this,'sale-orders')"><i class="fas fa-clipboard-list"></i> Salesperson Orders</button>
        <?php if ($isSalespersonUser): ?>
        <button class="tab-btn" onclick="switchTab(this,'sale-commissions')"><i class="fas fa-coins"></i> My Commission</button>
        <?php endif; ?>
    </div>
    <div class="tab-content">
        <?php if (hasPermission('sales', 'create')): ?>
        <div id="sale-new" class="tab-panel active">
            <div class="panel">
                <h2>New Sale</h2>
                <?php if (empty($customers)): ?>
                    <p class="hint">Add at least one customer before recording sales.</p>
                <?php elseif (empty($products)): ?>
                    <p class="hint">Add products before recording sales.</p>
                <?php elseif ($isSalespersonUser && !$mySalesperson): ?>
                    <p class="hint">You cannot record sales until an administrator links your salesperson profile to your account.</p>
                <?php else: ?>
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;padding:8px 14px;border-radius:999px;background:var(--neutral-100);border:2px solid var(--neutral-300);transition:all 0.2s" id="sale-scanner-zone">
                    <i class="fas fa-barcode" style="color:var(--neutral-500)"></i>
                    <input type="text" id="sale-barcode-input" placeholder="Scan barcode to add product…" style="border:none;background:none;margin:0;padding:4px 0;outline:none;flex:1;font-size:0.88rem" autofocus>
                    <span id="sale-scanner-feedback" style="font-size:0.78rem;color:var(--neutral-500)"></span>
                    <button type="button" onclick="openCameraScanner()" title="Scan with camera" style="background:none;border:none;cursor:pointer;padding:4px;color:var(--neutral-500);font-size:1rem"><i class="fas fa-camera"></i></button>
                </div>
                <script>
                var productMap = <?= json_encode($productMap) ?>;
                var saleRowIdx = <?= count($products) > 0 ? 'Math.max(1, $("select[name^=\'sale_items\']").length)' : '1' ?>;
                document.getElementById('sale-barcode-input').addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        var code = this.value.trim();
                        if (!code) return;
                        var found = productMap[code];
                        var fb = document.getElementById('sale-scanner-feedback');
                        if (found) {
                            fb.textContent = 'Found: ' + found.name;
                            fb.style.color = 'var(--brown-700)';
                            document.getElementById('sale-scanner-zone').style.borderColor = 'var(--brown-600)';
                            var sel = document.querySelector('select[name="sale_items[0][product_id]"]');
                            if (sel) {
                                sel.value = found.id;
                                sel.dispatchEvent(new Event('change'));
                            }
                            var qty = document.querySelector('input[name="sale_items[0][quantity]"]');
                            if (qty) qty.focus();
                            this.value = '';
                        } else {
                            fb.textContent = 'Not found';
                            fb.style.color = 'var(--red-700)';
                            document.getElementById('sale-scanner-zone').style.borderColor = 'var(--red-600)';
                        }
                        setTimeout(function() { fb.textContent = ''; document.getElementById('sale-scanner-zone').style.borderColor = 'var(--neutral-300)'; }, 2500);
                    }
                });
                document.addEventListener('barcode-scanned', function(e) {
                    var input = document.getElementById('sale-barcode-input');
                    if (input && !document.activeElement || document.activeElement === input) {
                        input.value = e.detail.code;
                        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
                    }
                });
                </script>
                <form method="post" class="form-grid" id="sale-form" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <label>Customer (who is buying)
                        <select name="customer_id" required>
                            <option value="">— Select buyer —</option>
                            <?php foreach ($customers as $customer): ?>
                                <option value="<?= htmlspecialchars($customer['id']) ?>"><?= htmlspecialchars($customer['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Sale date<input type="date" name="sale_date" value="<?= date('Y-m-d') ?>"></label>
                    <label>Fulfillment Method
                        <select name="fulfillment_method" id="sale-fm" onchange="saleToggleDelivery(this)">
                            <option value="delivery">Delivery</option>
                            <option value="pickup">Customer Pickup</option>
                        </select>
                    </label>
                    <label id="sale-da-label" style="grid-column:1/-1">Delivery Address
                        <textarea name="delivery_address" rows="2" placeholder="Delivery address (required for delivery)"></textarea>
                    </label>
                    <fieldset style="border:1px solid var(--brown-300);padding:12px;border-radius:6px;grid-column:1/-1">
                        <legend style="font-weight:600;color:var(--brown-800)">Products being sold</legend>
                        <div class="order-items" id="sale-items">
                            <div class="order-item-row">
                                <select name="sale_items[0][product_id]" required>
                                    <option value="">— Select product —</option>
                                    <?php foreach ($products as $product): ?>
                                        <option value="<?= htmlspecialchars($product['id']) ?>" data-barcode="<?= htmlspecialchars((string) ($product['barcode'] ?? '')) ?>" data-wprice="<?= (float) $product['wholesale_price'] ?>"><?= htmlspecialchars($product['name']) ?> — <?= fmt((float) $product['price']) ?> (stock <?= (int) $product['stock_quantity'] ?>)<?= $product['barcode'] ? ' [' . htmlspecialchars($product['barcode']) . ']' : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" name="sale_items[0][quantity]" value="1" min="1" required style="width:80px">
                                <button type="button" class="remove-item">Remove</button>
                            </div>
                        </div>
                        <button type="button" id="add-sale-item" style="margin-top:8px">+ Add another product</button>
                    </fieldset>
                    <fieldset style="border:1px solid var(--brown-300);padding:12px;border-radius:6px;grid-column:1/-1">
                        <legend style="font-weight:600;color:var(--brown-800)">Payment</legend>
                        <div style="display:flex;gap:12px;flex-wrap:wrap">
                            <label style="flex:1">Payment method
                                <select name="payment_method" id="sale-payment-method" onchange="saleToggleAgreement(this)">
                                    <option value="">— Select —</option>
                                    <option value="cash" <?= (($_POST['payment_method'] ?? '') === 'cash') ? 'selected' : '' ?>>Cash</option>
                                    <option value="bank_transfer" <?= (($_POST['payment_method'] ?? '') === 'bank_transfer') ? 'selected' : '' ?>>Bank Transfer</option>
                                    <option value="mobile_money" <?= (($_POST['payment_method'] ?? '') === 'mobile_money') ? 'selected' : '' ?>>Mobile Money</option>
                                </select>
                            </label>
                            <label style="flex:1">Payment reference / detail
                                <input type="text" name="payment_detail" value="<?= htmlspecialchars($_POST['payment_detail'] ?? '') ?>" placeholder="e.g. transaction ref">
                            </label>
                            <?php if ($isSalespersonUser): ?>
                            <label style="flex:1">Payment photo (required unless credit)
                                <input type="file" name="payment_photo" id="sale-payment-photo" accept="image/jpeg,image/png,image/webp,image/gif" style="padding:6px">
                                <span class="hint" id="sale-payment-photo-hint" style="display:none">Not required for credit orders.</span>
                            </label>
                            <?php endif; ?>
                            <?php if (hasPermission('sales', 'pay')): ?>
                            <label style="flex:1;display:flex;align-items:flex-end">
                                <span style="display:flex;align-items:center;gap:6px;padding:6px 0"><input type="checkbox" name="payment_confirmed" value="1" <?= !empty($_POST['payment_confirmed']) ? 'checked' : '' ?> style="width:auto"> Payment received</span>
                            </label>
                            <?php else: ?>
                            <label style="flex:1;display:flex;align-items:flex-end">
                                <span class="hint" style="padding:6px 0">Payment is confirmed by Finance from Sale History.</span>
                            </label>
                            <?php endif; ?>
                        </div>
                    </fieldset>
                    <fieldset id="sale-credit-agreement" style="display:none;border:1px solid var(--brown-300);padding:12px;border-radius:6px;grid-column:1/-1">
                        <legend style="font-weight:600;color:var(--brown-800)">Credit Agreement</legend>
                        <input type="hidden" name="agreement_version_id" value="<?= (int) ($activeAgreement['id'] ?? 0) ?>">
                        <p class="hint" style="white-space:pre-line;margin:0 0 8px"><?= htmlspecialchars((string) ($activeAgreement['content'] ?? 'No credit agreement is currently active.')) ?></p>
                        <label style="display:flex;align-items:center;gap:6px">
                            <input type="checkbox" name="agreement_accepted" value="1" style="width:auto">
                            I accept the Credit Agreement and will pay within <?= (int) ($activeAgreement['payment_terms_days'] ?? 30) ?> days of the invoice date.
                        </label>
                    </fieldset>
                    <?php if ($isSalespersonUser): ?>
                    <fieldset style="border:1px solid var(--brown-300);padding:12px;border-radius:6px;grid-column:1/-1">
                        <legend style="font-weight:600;color:var(--brown-800)">Commission</legend>
                        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
                            <input type="hidden" name="salesperson_id" value="<?= (int) ($mySalesperson['id'] ?? 0) ?>">
                            <input type="hidden" name="commission_percent" value="<?= (float) ($mySalesperson['commission_rate'] ?? 0) ?>">
                            <span style="font-size:0.88rem">You: <strong><?= htmlspecialchars($mySalesperson['name'] ?? '—') ?></strong></span>
                            <span class="status-badge status-approved" style="font-size:0.75rem">Commission rate <?= (float) ($mySalesperson['commission_rate'] ?? 0) ?>%</span>
                        </div>
                    </fieldset>
                    <?php else: ?>
                    <fieldset style="border:1px solid var(--brown-300);padding:12px;border-radius:6px;grid-column:1/-1">
                        <legend style="font-weight:600;color:var(--brown-800)">Commission (optional)</legend>
                        <div style="display:flex;gap:12px;flex-wrap:wrap">
                            <label style="flex:1">Salesperson
                                <select name="salesperson_id">
                                    <option value="">— None —</option>
                                    <?php foreach ($salespeople as $sp): ?>
                                        <option value="<?= (int) $sp['id'] ?>"><?= htmlspecialchars($sp['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label style="flex:1">Commission %<input type="number" name="commission_percent" min="0" max="100" step="0.1" value="0"></label>
                        </div>
                    </fieldset>
                    <?php endif; ?>
                    <button type="submit" style="grid-column:1/-1">Save Sale</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <div id="sale-history" class="tab-panel">
            <div class="panel">
                <h2>Sale History</h2>
                <form method="get" style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap">
                    <input type="hidden" name="page" value="sales">
                    <select name="customer_id" style="flex:1;min-width:140px;margin:0">
                        <option value="">All customers</option>
                        <?php foreach ($customers as $cust): ?>
                            <option value="<?= htmlspecialchars($cust['id']) ?>"<?= $filterCustomer === (int) $cust['id'] ? ' selected' : '' ?>><?= htmlspecialchars($cust['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>" style="margin:0" placeholder="From">
                    <input type="date" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>" style="margin:0" placeholder="To">
                    <button type="submit" style="margin:0">Filter</button>
                    <?php if ($filterCustomer > 0 || $filterDateFrom !== '' || $filterDateTo !== ''): ?><a href="?page=sales" style="display:inline-flex;align-items:center;padding:0 12px;color:var(--brown-800);font-weight:600">Clear</a><?php endif; ?>
                </form>
                <div style="overflow-x:auto">
                <table>
                    <thead><tr><th>ID</th><th>Date</th><th>Customer</th><th>Fulfillment</th><th>Payment</th><th>Total</th><th>Commission</th><th></th></tr></thead>
                    <tbody>
                        <?php if (empty($sales)): ?>
                            <tr><td colspan="8">No sales recorded yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($sales as $sale): ?>
                                <?php
                                $hasComm = !empty($sale['agent_name']) && (float)$sale['commission_amount'] > 0;
                                $isPaid = $sale['paid_at'] !== null;
                                $isPickup = ($sale['fulfillment_method'] ?? '') === 'pickup';
                                $payConfirmed = (int) ($sale['payment_confirmed'] ?? 0) === 1;
                                $payMethodLabel = match ($sale['payment_method'] ?? '') { 'cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'mobile_money' => 'Mobile Money', 'credit' => 'Credit', default => ($sale['payment_method'] ?? '') !== '' ? ucfirst((string) $sale['payment_method']) : '' };
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) $sale['id']) ?></td>
                                    <td><?= htmlspecialchars($sale['sale_date']) ?></td>
                                    <td><?= htmlspecialchars($sale['customer_name'] ?? 'Unknown') ?></td>
                                    <td><span class="status-badge status-<?= $isPickup ? 'pending' : 'approved' ?>" style="font-size:0.7rem"><?= $isPickup ? 'Pickup' : 'Delivery' ?></span></td>
                                    <td style="font-size:0.82rem">
                                        <span class="status-badge status-<?= $payConfirmed ? 'approved' : 'pending' ?>" style="font-size:0.7rem"><?= $payConfirmed ? 'Paid' : 'Unpaid' ?></span>
                                        <?php if ($payMethodLabel !== ''): ?><span class="cell-sub"><?= htmlspecialchars($payMethodLabel) ?></span><?php endif; ?>
                                        <?php if (!empty($sale['payment_detail'])): ?><span class="cell-sub"><?= htmlspecialchars($sale['payment_detail']) ?></span><?php endif; ?>
                                    </td>
                                    <td><?= fmt((float) $sale['total_amount']) ?></td>
                                    <td style="font-size:0.82rem">
                                        <?php if ($hasComm): ?>
                                            <strong><?= htmlspecialchars($sale['agent_name']) ?></strong>
                                            <span class="cell-sub"><?= htmlspecialchars((string) $sale['commission_percent']) ?>%</span>
                                            <span><?= fmt((float) $sale['commission_amount']) ?></span>
                                            <span class="status-badge status-<?= $isPaid ? 'approved' : 'pending' ?>" style="font-size:0.7rem;padding:2px 6px;margin-left:4px"><?= $isPaid ? 'Paid' : 'Due' ?></span>
                                        <?php else: ?>
                                            <span class="cell-sub">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="index.php?page=receipt&amp;id=<?= htmlspecialchars((string) $sale['id']) ?>" style="font-size:0.82rem;color:var(--brown-700);margin-right:8px">Receipt</a>
                                        <?php if (!$payConfirmed): ?>
                                            <?php if (hasPermission('sales', 'pay')): ?>
                                            <form method="post" style="display:inline" onsubmit="return confirm('Confirm payment received for sale <?= (int)$sale['id'] ?>?')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="confirm_sale_payment_id" value="<?= (int)$sale['id'] ?>">
                        <select name="payment_method" required style="padding:8px 10px;font-size:0.82rem;margin:0;min-height:44px">
                            <option value="">Pay method</option>
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="mobile_money">Mobile Money</option>
                        </select>
                        <button type="submit" style="padding:8px 16px;font-size:0.82rem;background:var(--green-600);color:#fff;border:none;border-radius:999px;cursor:pointer;font-weight:600;min-height:44px">Confirm Payment</button>
                                            </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if ($hasComm && !$isPaid): ?>
                                            <?php if (hasPermission('sales', 'pay')): ?>
                                            <form method="post" style="display:inline" onsubmit="return confirm('Pay <?= fmt((float)$sale['commission_amount']) ?> commission to <?= htmlspecialchars($sale['agent_name']) ?>?')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="pay_commission_sale_id" value="<?= (int)$sale['id'] ?>">
                                                <button type="submit" style="padding:8px 16px;font-size:0.82rem;background:var(--brown-600);color:#fff;border:none;border-radius:999px;cursor:pointer;font-weight:600;min-height:44px">Pay Comm.</button>
                                            </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
        <div id="sale-orders" class="tab-panel">
            <div class="panel">
                <h2>Salesperson Orders</h2>
                <p class="hint">Orders registered by salespeople follow an approval chain (Admin &rarr; Store Manager &rarr; Finance). Orders that skip the chain have their payment confirmed here; customer-portal orders are confirmed under Finance &rarr; Payment Confirmation.</p>
                <?php if (empty($spOrders)): ?>
                    <p class="hint">No salesperson-registered orders yet.</p>
                <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:14px;margin-top:10px">
                    <?php foreach ($spOrders as $spOrder): ?>
                        <?php
                        $spId = (int)$spOrder['id'];
                        $spConfirmed = (int)($spOrder['payment_confirmed'] ?? 0) === 1;
                        $spReady = in_array($spOrder['fulfillment_status'] ?? '', ['prepared', 'ready_to_prepare', 'paid', 'waiting_customer_pickup', 'ready_for_delivery', 'fulfillment_completed', 'credit_approved']);
                        $spItems = $spItemsByOrder[$spId] ?? [];
                        $spStage = $spOrder['approval_stage'] ?? null;
                        $spStageLabel = match ($spStage) {
                            'admin' => 'Pending Admin Approval',
                            'store' => 'Pending Store Manager Approval',
                            'finance' => 'Pending Finance Approval',
                            null => '',
                            default => '',
                        };
                        $spStageTone = match ($spStage) {
                            'admin' => 'danger',
                            'store' => 'pending',
                            'finance' => 'approved',
                            default => 'pending',
                        };
                        $spDelStatus = $spOrder['delivery_status'] ?? null;
                        $spFulfillment = $spOrder['fulfillment_status'] ?? null;
                        $spDelBadge = '';
                        if ($spDelStatus !== null && $spDelStatus !== '') {
                            $spDelBadge = delBadgeHtml($spDelStatus);
                        } elseif ($spFulfillment === 'ready_for_delivery') {
                            $spDelBadge = '<span style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:.72rem;font-weight:700;background:#e0f2fe;color:#075985">Ready for Delivery</span>';
                        } elseif ($spFulfillment === 'waiting_customer_pickup') {
                            $spDelBadge = '<span style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:.72rem;font-weight:700;background:#f0fdf4;color:#15803d">Waiting Customer Pickup</span>';
                        } elseif ($spFulfillment === 'paid' || $spFulfillment === 'credit_approved') {
                            $spDelBadge = '<span style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:.72rem;font-weight:700;background:#f1f5f9;color:#475569">Awaiting Routing</span>';
                        }
                        ?>  
                        <div style="border:1px solid var(--neutral-300);border-left:4px solid <?= $spConfirmed ? 'var(--green-600)' : 'var(--orange-600)' ?>;border-radius:8px;padding:14px">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px">
                                <div>
                                    <strong>Order #<?= $spId ?></strong>
                                    <span class="cell-sub"><?= htmlspecialchars($spOrder['customer_name'] ?? 'Unknown Customer') ?></span>
                                    <?php if (!empty($spOrder['salesperson_name'])): ?><span class="status-badge status-approved" style="font-size:0.7rem;margin-left:6px"><?= htmlspecialchars($spOrder['salesperson_name']) ?></span><?php endif; ?>
                                </div>
                                <div style="text-align:right;font-size:0.78rem;color:var(--neutral-500)">
                                    <div><?= htmlspecialchars($spOrder['order_date']) ?></div>
                                    <div><?= date('M d, Y H:i', strtotime($spOrder['created_at'])) ?></div>
                                </div>
                            </div>
                            <?php if (!empty($spItems)): ?>
                                <div style="overflow-x:auto">
                                <table style="width:100%;margin:10px 0;font-size:0.8rem;border-collapse:collapse">
                                    <thead><tr><th style="text-align:left;padding:6px 8px;border-bottom:1px solid var(--neutral-300)">Product</th><th style="padding:6px 8px;border-bottom:1px solid var(--neutral-300)">Qty</th><th style="text-align:right;padding:6px 8px;border-bottom:1px solid var(--neutral-300)">Price</th><th style="text-align:right;padding:6px 8px;border-bottom:1px solid var(--neutral-300)">Line Total</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($spItems as $spIt): ?>
                                            <tr>
                                                <td style="padding:5px 8px"><?= htmlspecialchars($spIt['product_name']) ?></td>
                                                <td style="padding:5px 8px;text-align:center"><?= (int)$spIt['quantity'] ?></td>
                                                <td style="padding:5px 8px;text-align:right"><?= fmt((float)$spIt['unit_price']) ?></td>
                                                <td style="padding:5px 8px;text-align:right"><?= fmt((float)$spIt['unit_price'] * (int)$spIt['quantity']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                </div>
                            <?php endif; ?>
                            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                                <div style="font-size:0.85rem">
                                    <strong><?= fmt((float)$spOrder['total_amount']) ?></strong>
                                    <span class="cell-sub"><?= ucfirst((string)($spOrder['payment_method'] ?? 'cash')) ?></span>
                                    <span class="status-badge status-<?= $spConfirmed ? 'approved' : 'pending' ?>" style="font-size:0.7rem;margin-left:6px"><?= $spConfirmed ? 'Paid' : 'Unpaid' ?></span>
                                    <?php if ($spStage !== null): ?>
                                        <span class="status-badge status-<?= $spStageTone ?>" style="font-size:0.7rem;margin-left:6px"><?= htmlspecialchars($spStageLabel) ?></span>
                                    <?php endif; ?>
                                    <?php if ($spDelBadge !== ''): ?>
                                        <span style="margin-left:6px"><?= $spDelBadge ?></span>
                                        <?php if (($spDelStatus ?? '') === 'delivered' && !empty($spOrder['delivery_confirmed_at'])): ?>
                                            <span class="cell-sub" style="margin-left:4px">confirmed <?= htmlspecialchars($spOrder['delivery_confirmed_at']) ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($spStage === null && !$spConfirmed && hasPermission('sales', 'pay')): ?>
                                <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap" onsubmit="return confirm('Confirm payment for Order #<?= $spId ?>?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="confirm_salesperson_order_id" value="<?= $spId ?>">
                                    <label style="font-size:0.7rem">Amount paid<input type="number" name="verified_amount" value="<?= (float)$spOrder['total_amount'] ?>" step="0.01" min="0" style="padding:8px 10px;font-size:0.82rem;margin:0;width:130px;min-width:100px"></label>
                                    <label style="font-size:0.7rem">Finance notes<input type="text" name="payment_notes" placeholder="Optional" style="padding:8px 10px;font-size:0.82rem;margin:0;width:180px;min-width:120px"></label>
                                    <?php if (($spOrder['payment_method'] ?? 'cash') !== 'credit'): ?>
                                    <label style="font-size:0.7rem">Bank account<?= bankRenderAccountDropdown($pdo, 'bank_account_id', (string)bankGetDefaultAccountId($pdo)) ?></label>
                                    <?php endif; ?>
                                    <button type="submit" style="padding:8px 16px;font-size:0.82rem;background:var(--green-600);color:#fff;border:none;border-radius:999px;cursor:pointer;font-weight:600;min-height:44px">Confirm Payment</button>
                                </form>
                                <?php elseif (!$spConfirmed): ?>
                                    <span class="hint" style="font-size:0.78rem"><?= $spStage !== null ? 'Payment is confirmed when Finance approves the final stage.' : 'Payment is confirmed by Finance.' ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($isSalespersonUser): ?>
        <div id="sale-commissions" class="tab-panel">
            <div class="panel">
                <h2>My Commission</h2>
                <div style="overflow-x:auto">
                <table>
                    <thead><tr><th>Date</th><th>Customer</th><th>Type</th><th>Rate</th><th>Amount</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php if (empty($myCommissions)): ?>
                            <tr><td colspan="6">No commissions yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($myCommissions as $mc): ?>
                            <tr>
                                <td><?= htmlspecialchars($mc['created_at']) ?></td>
                                <td><?= htmlspecialchars($mc['customer_name'] ?? '—') ?></td>
                                <td style="text-transform:capitalize"><?= htmlspecialchars(str_replace('_', ' ', $mc['commission_type'] ?? 'sale')) ?></td>
                                <td><?= $mc['commission_rate'] !== null ? (float) $mc['commission_rate'] . '%' : '-' ?></td>
                                <td style="font-weight:700;color:var(--brown-600)"><?= fmt((float) $mc['commission_amount']) ?></td>
                                <td><span class="status-badge status-<?= ($mc['status'] ?? 'pending') === 'paid' ? 'approved' : (($mc['status'] ?? 'pending') === 'rejected' ? 'danger' : 'pending') ?>" style="font-size:0.7rem"><?= htmlspecialchars(ucfirst($mc['status'] ?? 'pending')) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
                <p class="hint">Commission is approved and paid by management.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>
<script>
function saleToggleAgreement(sel) {
    var box = document.getElementById('sale-credit-agreement');
    if (box) box.style.display = sel && sel.value === 'credit' ? '' : 'none';
    var photo = document.getElementById('sale-payment-photo');
    var hint = document.getElementById('sale-payment-photo-hint');
    if (photo) {
        var need = !sel || sel.value !== 'credit';
        photo.required = need;
        if (hint) hint.style.display = need ? 'none' : '';
    }
}
(function () {
    var pm = document.getElementById('sale-payment-method');
    if (pm) saleToggleAgreement(pm);
})();
function saleToggleDelivery(sel) {
    var pickup = sel.value === 'pickup';
    var da = document.getElementById('sale-da-label');
    if (da) da.style.display = pickup ? 'none' : '';
    document.querySelectorAll('#sale-items select[name^="sale_items"]').forEach(function (s) {
        var option = s.selectedOptions && s.selectedOptions[0];
        var w = option ? parseFloat(option.dataset.wprice || '0') : 0;
        if (pickup && w > 0) {
            s.dataset.prevPrice = option.text;
            option.text = option.text.replace(/ — [\d,.]+ \(/, ' — ' + w.toFixed(2) + ' (');
        } else if (s.dataset.prevPrice) {
            option.text = s.dataset.prevPrice;
            delete s.dataset.prevPrice;
        }
    });
}
</script>
