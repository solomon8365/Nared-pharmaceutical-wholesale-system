<?php
require_once __DIR__ . '/../services/init.php';
requirePermission('place_order', 'view');
$pdo = getPDO();
$messages = [];
$creditLimitError = '';
$cid = customerAccountCustomerId();
$creditLimit = 0;
$creditUsed = 0;
$creditProfile = null;
if ($cid !== null) {
    CreditService::syncOverdue($pdo);
    $creditProfile = CreditService::profile($pdo, $cid);
    if ($creditProfile) {
        $creditLimit = $creditProfile['credit_limit'];
        $creditUsed = $creditProfile['credit_used'];
    }
}
$creditSalesEnabled = CreditService::isSalesEnabled();
$creditAvailable = $creditSalesEnabled && $creditLimit > 0;
$activeAgreement = null;
if ($creditSalesEnabled && $cid !== null) {
    $activeAgreement = CreditService::activeAgreement($pdo);
}
$products = $pdo->query("SELECT id, name, brand, generic_name, dosage_form, strength, price, wholesale_price, stock_quantity, barcode FROM products WHERE product_status = 'active' AND (id IN (SELECT product_id FROM product_batches WHERE quantity > 0 AND status = 'active') OR (stock_quantity > 0 AND id NOT IN (SELECT product_id FROM product_batches WHERE quantity > 0))) ORDER BY name")->fetchAll();
$vatRate = CalculationService::vatRate($pdo);

$prefillPid = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
$validPre = false;
if ($prefillPid > 0) {
    foreach ($products as $p) {
        if ((int) $p['id'] === $prefillPid) { $validPre = true; break; }
    }
}
if (!$validPre) $prefillPid = 0;

$editOrderId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editOrder = null;
$editOrderItems = [];
if ($editOrderId > 0 && $cid !== null) {
    $editOrder = OrderService::getEditableOrder($pdo, $editOrderId, $cid);
    if ($editOrder) {
        $editOrderItems = OrderService::getOrderItems($pdo, $editOrderId);
    } else {
        $messages[] = ['type' => 'error', 'text' => 'Order not found or cannot be edited.'];
        $editOrderId = 0;
    }
}

if ($cid === null) {
    $messages[] = ['type' => 'error', 'text' => 'Your login is not linked to a customer record. Ask an administrator to create a customer login for you.'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $cid !== null) {
    require_csrf();
    $orderDate = $_POST['order_date'] ?? date('Y-m-d');
    $items = $_POST['request_items'] ?? [];
    $uid = (int) (currentUser()['id'] ?? 0);
    $paymentMethod = $_POST['payment_method'] ?? 'cash';
    $validMethods = ['cash', 'bank', 'cbe_birr', 'telebirr'];
    if (CreditService::isSalesEnabled()) $validMethods[] = 'credit';
    if (!in_array($paymentMethod, $validMethods, true)) $paymentMethod = 'cash';
    $paymentDetail = trim($_POST['payment_detail'] ?? '');
    $transactionRef = trim($_POST['transaction_ref'] ?? '');
    $bankName = trim($_POST['bank_name'] ?? '');
    $accountName = trim($_POST['account_name'] ?? '');
    $accountNumber = trim($_POST['account_number'] ?? '');
    $merchantName = trim($_POST['merchant_name'] ?? '');
    $merchantId = trim($_POST['merchant_id'] ?? '');
    $deliveryAddress = trim($_POST['delivery_address'] ?? '');
    $fulfillmentMethod = ($_POST['fulfillment_method'] ?? 'delivery') === 'pickup' ? 'pickup' : 'delivery';
    $discountPct = (float) ($_POST['discount_percent'] ?? 0);
    $editingOrderId = isset($_POST['editing_order_id']) ? (int) $_POST['editing_order_id'] : 0;

    $data = [
        'order_date' => $orderDate,
        'items' => $items,
        'payment_method' => $paymentMethod,
        'payment_detail' => $paymentDetail,
        'transaction_ref' => $transactionRef,
        'bank_name' => $bankName,
        'account_name' => $accountName,
        'account_number' => $accountNumber,
        'merchant_name' => $merchantName,
        'merchant_id' => $merchantId,
        'delivery_address' => $deliveryAddress,
        'fulfillment_method' => $fulfillmentMethod,
        'discount_percent' => $discountPct,
        'agreement_version_id' => isset($_POST['agreement_version_id']) ? (int) $_POST['agreement_version_id'] : 0,
        'agreement_accepted' => isset($_POST['agreement_accepted']) ? '1' : '0',
    ];

    try {
        $screenshotPath = null;
        $uploadedNew = false;
        if (isset($_FILES['payment_screenshot']) && $_FILES['payment_screenshot']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['payment_screenshot'];
            $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mime, $allowed, true)) throw new RuntimeException('Payment receipt must be JPEG, PNG, WebP or GIF.');
            if ($file['size'] > MAX_UPLOAD_SIZE) throw new RuntimeException('Receipt too large (max 2 MB).');
            if (!is_dir(PAYMENT_UPLOAD_DIR)) mkdir(PAYMENT_UPLOAD_DIR, 0755, true);
            do { $fname = 'order_' . bin2hex(random_bytes(8)) . '.' . $extMap[$mime]; $dest = PAYMENT_UPLOAD_DIR . DIRECTORY_SEPARATOR . $fname; } while (file_exists($dest));
            if (!move_uploaded_file($file['tmp_name'], $dest)) throw new RuntimeException('Failed to save receipt.');
            $screenshotPath = PAYMENT_UPLOAD_URL . '/' . $fname;
            $uploadedNew = true;
        }

        if ($editingOrderId > 0) {
            OrderService::updateOrder($pdo, $cid, $editingOrderId, $data, $uploadedNew ? $screenshotPath : null);
            set_flash('success', 'Order #' . $editingOrderId . ' updated successfully.');
            header('Location: index.php?page=my_orders');
            exit;
        } else {
            $res = OrderService::submitOrder($pdo, $cid, $uid, $data, $uploadedNew ? $screenshotPath : null);
            $orderId = $res['order_id'];
            set_flash('success', 'Order #' . $orderId . ' submitted for finance verification. Track status in My Orders.');
            header('Location: index.php?page=my_orders');
            exit;
        }
    } catch (ServiceException $e) {
        $errMsg = $e->getMessage();
        if (stripos($errMsg, 'Credit limit exceeded') !== false) {
            $creditLimitError = $errMsg;
        }
        $messages[] = ['type' => 'error', 'text' => $errMsg];
    } catch (Exception $e) {
        $messages[] = ['type' => 'error', 'text' => $e->getMessage()];
    }
}

if ($editOrderId > 0 && $cid !== null) {
    $editOrder = OrderService::getEditableOrder($pdo, $editOrderId, $cid);
    if ($editOrder) {
        $editOrderItems = OrderService::getOrderItems($pdo, $editOrderId);
    }
}
$pendingCount = 0;
if ($cid !== null) {
    $pcStmt = $pdo->prepare("SELECT COUNT(*) FROM customer_orders WHERE customer_id = :cid AND status = 'pending'");
    $pcStmt->execute(['cid' => $cid]);
    $pendingCount = (int) $pcStmt->fetchColumn();
}
$unreadNotifs = $cid !== null ? getUnreadNotificationCount($pdo, $cid) : 0;
?>
<style>
.ord-wrap{max-width:1400px;margin:0 auto}
.ord-grid{display:grid;grid-template-columns:1fr 380px;gap:24px;align-items:start}
@media(max-width:900px){.ord-grid{grid-template-columns:1fr}}
.ord-panel{background:#fff;border-radius:12px;border:1px solid var(--neutral-200,#e5e7eb);padding:24px;margin-bottom:16px}
.ord-panel h2{font-size:1rem;font-weight:700;color:var(--brown-800,#3d2c1e);margin-bottom:14px;display:flex;align-items:center;gap:8px}
.ord-panel h2 i{color:var(--brown-600,#6b4226);font-size:0.95rem}
.ord-sel{width:100%;padding:10px 12px;border:1px solid var(--neutral-300,#d1d5db);border-radius:8px;font-size:0.88rem;background:#fff}
.ord-input{width:100%;padding:10px 12px;border:1px solid var(--neutral-300,#d1d5db);border-radius:8px;font-size:0.88rem}
.ord-input:focus,.ord-sel:focus{outline:none;border-color:var(--brown-500,#8b6914);box-shadow:0 0 0 2px rgba(139,105,20,0.15)}
.ord-label{display:block;font-size:0.82rem;font-weight:600;color:var(--neutral-600,#4b5563);margin-bottom:5px}
.ord-field{margin-bottom:12px}
.ord-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.ord-row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}

@media(max-width:480px){
    .ord-row,.ord-row3{grid-template-columns:1fr}
}
.ord-item{background:var(--neutral-50,#f9fafb);border:1px solid var(--neutral-200,#e5e7eb);border-radius:10px;padding:14px;margin-bottom:10px;position:relative}
.ord-item select{width:100%}
.ord-item-bottom{display:grid;grid-template-columns:1fr 120px 120px 100px 36px;gap:8px;margin-top:8px;align-items:end}
@media(max-width:700px){.ord-item-bottom{grid-template-columns:1fr 1fr;gap:6px}}
.ord-item-remove{background:var(--red-100,#fee2e2);color:var(--red-700,#b91c1c);border:none;border-radius:6px;cursor:pointer;font-size:0.8rem;padding:6px 10px;display:flex;align-items:center;gap:4px}
.ord-add-btn{background:var(--brown-50,#f5f0e8);color:var(--brown-700,#5c3d1a);border:1px dashed var(--brown-300,#c4a882);border-radius:8px;padding:10px;width:100%;cursor:pointer;font-weight:600;font-size:0.88rem;display:flex;align-items:center;justify-content:center;gap:6px;margin-bottom:14px}
.ord-add-btn:hover{background:var(--brown-100,#ebe0cc);border-color:var(--brown-500)}
.ord-submit{background:var(--brown-700,#5c3d1a);color:#fff;border:none;border-radius:10px;padding:14px 28px;font-size:1rem;font-weight:700;cursor:pointer;width:100%;display:flex;align-items:center;justify-content:center;gap:8px;transition:background 0.2s}
.ord-submit:hover{background:var(--brown-800,#3d2c1e)}
.ord-cancel{display:inline-flex;align-items:center;justify-content:center;padding:12px 20px;color:var(--neutral-600,#4b5563);font-weight:600;font-size:0.88rem;border-radius:8px;text-decoration:none;margin-top:8px;width:100%;text-align:center;border:1px solid var(--neutral-300)}
.ord-cancel:hover{background:var(--neutral-100)}
.payment-fields{display:none;margin-top:10px;padding:12px;background:var(--neutral-50,#f9fafb);border:1px solid var(--neutral-200);border-radius:8px}
.payment-fields.active{display:block}
.summary-row{display:flex;justify-content:space-between;padding:6px 0;font-size:0.88rem;color:var(--neutral-600,#4b5563)}
.summary-row.total{border-top:2px solid var(--brown-300,#c4a882);padding-top:10px;margin-top:6px;font-weight:700;font-size:1.05rem;color:var(--brown-800,#3d2c1e)}
.credit-bar{background:var(--neutral-100);border-radius:6px;height:8px;overflow:hidden;margin-top:6px}
.credit-bar-fill{height:100%;background:var(--green-600,#059669);border-radius:6px;transition:width 0.3s}
.notif-badge{background:var(--red-600);color:#fff;border-radius:50%;font-size:0.68rem;padding:2px 6px;font-weight:700;margin-left:4px}
.empty-cart{text-align:center;padding:30px;color:var(--neutral-500);font-size:0.9rem}
.stock-low{color:var(--orange-600,#ea580c);font-size:0.75rem;font-weight:600}
.stock-ok{color:var(--green-700,#15803d);font-size:0.75rem}
.line-total{font-weight:700;color:var(--brown-700);font-size:0.92rem}
</style>

<section class="ord-wrap">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
        <h1 style="font-size:1.3rem;font-weight:700;color:var(--brown-800);margin:0"><?= $editOrder ? 'Edit Order #' . (int) $editOrder['id'] : 'Place Order' ?></h1>
        <div style="display:flex;gap:10px;align-items:center">
            <?php if ($unreadNotifs > 0): ?><span class="notif-badge"><?= $unreadNotifs ?> new</span><?php endif; ?>
            <?php if ($pendingCount > 0): ?><span style="background:var(--orange-100);color:var(--orange-700);padding:4px 10px;border-radius:20px;font-size:0.78rem;font-weight:600"><i class="fas fa-clock"></i> <?= $pendingCount ?> pending order<?= $pendingCount > 1 ? 's' : '' ?></span><?php endif; ?>
        </div>
    </div>

    <?php foreach ($messages as $message): ?>
        <div class="alert alert-<?= htmlspecialchars($message['type']) ?>"><?= htmlspecialchars($message['text']) ?></div>
    <?php endforeach; ?>

    <?php if ($creditLimitError !== ''): ?>
    <div class="cl-modal" id="creditLimitModal">
        <div class="cl-modal-card">
            <div class="cl-modal-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <h2 class="cl-modal-title">Credit limit exceeded!</h2>
            <p class="cl-modal-detail"><?= htmlspecialchars($creditLimitError) ?></p>
            <p class="cl-modal-hint">Your order was <strong>not</strong> submitted. Please reduce the order total, pay down your outstanding balance, or contact our finance team for a credit limit review.</p>
            <div class="cl-modal-actions">
                <button type="button" class="cl-btn cl-btn-primary" onclick="closeCreditLimitModal()">Adjust My Order</button>
                <a href="?page=credit_portal" class="cl-btn cl-btn-link"><i class="fas fa-credit-card"></i> My Credit</a>
            </div>
        </div>
    </div>
    <style>
    .cl-modal{position:fixed;inset:0;z-index:11000;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:20px}
    .cl-modal-card{background:#fff;border-radius:18px;max-width:480px;width:100%;padding:30px 28px;text-align:center;box-shadow:0 25px 70px rgba(0,0,0,.3)}
    .cl-modal-icon{width:62px;height:62px;border-radius:50%;background:#fef2f2;color:#dc2626;display:flex;align-items:center;justify-content:center;font-size:1.7rem;margin:0 auto 16px}
    .cl-modal-title{font-size:1.35rem;font-weight:800;color:#1e293b;margin:0 0 10px}
    .cl-modal-detail{font-size:.88rem;color:#475569;line-height:1.55;margin:0 0 10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px}
    .cl-modal-hint{font-size:.8rem;color:#64748b;line-height:1.5;margin:0 0 20px}
    .cl-modal-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
    .cl-btn{border:none;font-family:inherit;cursor:pointer;font-weight:700;font-size:.88rem;padding:11px 22px;border-radius:12px;text-decoration:none;display:inline-flex;align-items:center;gap:8px}
    .cl-btn-primary{background:#2563eb;color:#fff}
    .cl-btn-primary:hover{background:#1d4ed8}
    .cl-btn-link{background:#f1f5f9;color:#334155}
    .cl-btn-link:hover{background:#e2e8f0}
    </style>
    <script>
    function closeCreditLimitModal() {
        var m = document.getElementById('creditLimitModal');
        if (m) m.style.display = 'none';
        var first = document.querySelector('#orderForm input:invalid, #orderForm select:invalid, #orderForm textarea:invalid');
        if (first) { first.scrollIntoView({ behavior: 'smooth', block: 'center' }); first.focus(); }
    }
    document.addEventListener('DOMContentLoaded', function() {
        var m = document.getElementById('creditLimitModal');
        if (m) m.addEventListener('click', function(e) { if (e.target === m) document.getElementById('creditLimitModal').style.display = 'none'; });
    });
    </script>
    <?php endif; ?>

    <?php if ($cid === null): ?>
        <div class="ord-panel"><p>Your account is not linked to a customer profile. Contact an administrator.</p></div>
    <?php elseif (empty($products)): ?>
        <div class="ord-panel"><p>No products are available yet.</p></div>
    <?php else: ?>
    <form method="post" enctype="multipart/form-data" id="orderForm" data-swal-exclude="true" data-ajax-off="true" novalidate>
        <?= csrf_field() ?>
        <?php if ($editOrder): ?>
            <input type="hidden" name="editing_order_id" value="<?= (int) $editOrder['id'] ?>">
        <?php endif; ?>

        <div class="ord-grid">
            <div>
                <div class="ord-panel">
                    <h2><i class="fas fa-box"></i> Order Items</h2>
                    <div style="margin-bottom:12px;position:relative">
                        <input type="text" id="productSearch" class="ord-input" placeholder="Search products by name, brand, or generic..." oninput="filterProductSelects(this.value)" style="padding-left:34px">
                        <i class="fas fa-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--neutral-400);font-size:0.85rem;pointer-events:none"></i>
                    </div>
                    <div id="orderItems">
                        <?php if ($editOrder && !empty($editOrderItems)): ?>
                            <?php foreach ($editOrderItems as $i => $item): ?>
                                <div class="ord-item" data-index="<?= $i ?>">
                                    <div style="margin-bottom:6px">
                                        <select name="request_items[<?= $i ?>][product_id]" class="ord-sel product-select" required onchange="updatePrice(this)">
                                            <option value="">Select product</option>
                                            <?php foreach ($products as $product): ?>
                                                <option value="<?= htmlspecialchars((string) $product['id']) ?>" data-price="<?= (float) $product['price'] ?>" data-wprice="<?= (float) $product['wholesale_price'] ?>" data-stock="<?= (int) $product['stock_quantity'] ?>" data-name="<?= htmlspecialchars($product['name']) ?>" data-brand="<?= htmlspecialchars($product['brand'] ?? '') ?>" data-strength="<?= htmlspecialchars($product['strength'] ?? '') ?>" data-generic="<?= htmlspecialchars($product['generic_name'] ?? '') ?>" data-form="<?= htmlspecialchars($product['dosage_form'] ?? '') ?>" data-search="<?= strtolower(htmlspecialchars($product['name'] . ' ' . ($product['brand'] ?? '') . ' ' . ($product['generic_name'] ?? '') . ' ' . ($product['strength'] ?? ''))) ?>"<?= (int) $product['id'] === (int) $item['product_id'] ? ' selected' : '' ?>><?= htmlspecialchars($product['name']) ?><?= !empty($product['brand']) ? ' (' . htmlspecialchars($product['brand']) . ')' : '' ?><?= !empty($product['strength']) ? ' - ' . htmlspecialchars($product['strength']) : '' ?> — <?= fmt((float) $product['price']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="ord-item-bottom">
                                        <div>
                                            <span class="ord-label">Quantity</span>
                                            <input type="number" name="request_items[<?= $i ?>][quantity]" value="<?= (int) $item['quantity'] ?>" min="1" class="ord-input qty-input" onchange="updatePrice(this.closest('.ord-item'))" onkeyup="updatePrice(this.closest('.ord-item'))" required>
                                            <div class="stock-hint stock-ok" style="margin-top:4px"></div>
                                        </div>
                                        <div>
                                            <span class="ord-label">Unit Price</span>
                                            <div class="ord-input" style="background:var(--neutral-100);border-style:dashed;display:flex;align-items:center;height:42px" data-price-display>0</div>
                                        </div>
                                        <div>
                                            <span class="ord-label">Discount (ETB)</span>
                                            <input type="number" name="request_items[<?= $i ?>][line_discount]" value="<?= (float) ($item['discount'] ?? 0) ?>" min="0" step="0.01" class="ord-input disc-input" onchange="updatePrice(this.closest('.ord-item'))" onkeyup="updatePrice(this.closest('.ord-item'))">
                                        </div>
                                        <div>
                                            <span class="ord-label">Line Total</span>
                                            <div class="line-total" data-line-total>0</div>
                                        </div>
                                        <button type="button" class="ord-item-remove" onclick="removeItem(this)"><i class="fas fa-times"></i></button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="ord-item" data-index="0">
                                <div style="margin-bottom:6px">
                                    <select name="request_items[0][product_id]" class="ord-sel product-select" required onchange="updatePrice(this)">
                                        <option value="">Select product</option>
                                        <?php foreach ($products as $product): ?>
                                            <option value="<?= htmlspecialchars((string) $product['id']) ?>" data-price="<?= (float) $product['price'] ?>" data-wprice="<?= (float) $product['wholesale_price'] ?>" data-stock="<?= (int) $product['stock_quantity'] ?>" data-name="<?= htmlspecialchars($product['name']) ?>" data-brand="<?= htmlspecialchars($product['brand'] ?? '') ?>" data-strength="<?= htmlspecialchars($product['strength'] ?? '') ?>" data-generic="<?= htmlspecialchars($product['generic_name'] ?? '') ?>" data-form="<?= htmlspecialchars($product['dosage_form'] ?? '') ?>" data-search="<?= strtolower(htmlspecialchars($product['name'] . ' ' . ($product['brand'] ?? '') . ' ' . ($product['generic_name'] ?? '') . ' ' . ($product['strength'] ?? ''))) ?>"<?= (int) $product['id'] === $prefillPid ? ' selected' : '' ?>><?= htmlspecialchars($product['name']) ?><?= !empty($product['brand']) ? ' (' . htmlspecialchars($product['brand']) . ')' : '' ?><?= !empty($product['strength']) ? ' - ' . htmlspecialchars($product['strength']) : '' ?> — <?= fmt((float) $product['price']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="ord-item-bottom">
                                    <div>
                                        <span class="ord-label">Quantity</span>
                                        <input type="number" name="request_items[0][quantity]" value="1" min="1" class="ord-input qty-input" onchange="updatePrice(this.closest('.ord-item'))" onkeyup="updatePrice(this.closest('.ord-item'))" required>
                                        <div class="stock-hint stock-ok" style="margin-top:4px"></div>
                                    </div>
                                    <div>
                                        <span class="ord-label">Unit Price</span>
                                        <div class="ord-input" style="background:var(--neutral-100);border-style:dashed;display:flex;align-items:center;height:42px" data-price-display>0</div>
                                    </div>
                                    <div>
                                        <span class="ord-label">Discount (ETB)</span>
                                        <input type="number" name="request_items[0][line_discount]" value="0" min="0" step="0.01" class="ord-input disc-input" onchange="updatePrice(this.closest('.ord-item'))" onkeyup="updatePrice(this.closest('.ord-item'))">
                                    </div>
                                    <div>
                                        <span class="ord-label">Line Total</span>
                                        <div class="line-total" data-line-total>0</div>
                                    </div>
                                    <button type="button" class="ord-item-remove" onclick="removeItem(this)"><i class="fas fa-times"></i></button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="ord-add-btn" onclick="addItem()"><i class="fas fa-plus"></i> Add Another Product</button>
                </div>

                <div class="ord-panel">
                    <h2><i class="fas fa-truck-loading"></i> Fulfillment Method</h2>
                    <div class="ord-field">
                        <span class="ord-label">How would you like to receive this order? *</span>
                        <select name="fulfillment_method" class="ord-sel" id="fulfillmentMethod" onchange="switchFulfillmentMethod()" required>
                            <option value="delivery" <?= (($editOrder ? ($editOrder['fulfillment_method'] ?? '') : ($_POST['fulfillment_method'] ?? 'delivery')) === 'delivery') ? 'selected' : '' ?>>Delivery to Address</option>
                            <option value="pickup" <?= (($editOrder ? ($editOrder['fulfillment_method'] ?? '') : ($_POST['fulfillment_method'] ?? '')) === 'pickup') ? 'selected' : '' ?>>Pickup from Store</option>
                        </select>
                    </div>
                    <div id="deliveryAddressBlock">
                        <div class="ord-field">
                            <span class="ord-label">Delivery Address *</span>
                            <textarea name="delivery_address" rows="3" class="ord-input" id="deliveryAddressInput" required placeholder="Full delivery address including city, area, landmark"><?= htmlspecialchars($editOrder ? ($editOrder['delivery_address'] ?? '') : ($_POST['delivery_address'] ?? '')) ?></textarea>
                        </div>
                    </div>
                    <div id="pickupInfoBlock" style="display:none;padding:12px;background:var(--brown-50,#f5f0e8);border:1px dashed var(--brown-300,#c4a882);border-radius:8px;font-size:0.85rem;color:var(--brown-800,#3d2c1e)">
                        <i class="fas fa-store"></i> You will be notified when your order is packed and ready for pickup at our store.
                    </div>
                    <div class="ord-row">
                        <div class="ord-field">
                            <span class="ord-label">Order Date</span>
                            <input type="date" name="order_date" value="<?= htmlspecialchars($editOrder ? $editOrder['order_date'] : ($_POST['order_date'] ?? date('Y-m-d'))) ?>" class="ord-input">
                        </div>
                        <div class="ord-field">
                            <span class="ord-label">Order Discount (%)</span>
                            <input type="number" name="discount_percent" value="0" min="0" max="100" step="0.1" class="ord-input" id="discountPercent" onchange="recalcSummary()" onkeyup="recalcSummary()">
                        </div>
                    </div>
                </div>

                <div class="ord-panel">
                    <h2><i class="fas fa-credit-card"></i> Payment Method</h2>
                    <div class="ord-field">
                        <span class="ord-label">Select Payment Method *</span>
                        <select name="payment_method" class="ord-sel" id="paymentMethod" onchange="switchPaymentMethod()" required>
                            <option value="cash" <?= (($editOrder ? $editOrder['payment_method'] : ($_POST['payment_method'] ?? 'cash')) === 'cash') ? 'selected' : '' ?>>Cash</option>
                            <option value="bank" <?= (($editOrder ? $editOrder['payment_method'] : ($_POST['payment_method'] ?? '')) === 'bank') ? 'selected' : '' ?>>Bank Transfer</option>
                            <option value="cbe_birr" <?= (($editOrder ? $editOrder['payment_method'] : ($_POST['payment_method'] ?? '')) === 'cbe_birr') ? 'selected' : '' ?>>CBE Birr</option>
                            <option value="telebirr" <?= (($editOrder ? $editOrder['payment_method'] : ($_POST['payment_method'] ?? '')) === 'telebirr') ? 'selected' : '' ?>>Telebirr</option>
                            <?php if ($creditSalesEnabled): ?>
                            <option value="credit" <?= (($editOrder ? $editOrder['payment_method'] : ($_POST['payment_method'] ?? '')) === 'credit') ? 'selected' : '' ?>>Credit Account</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div id="pf-bank" class="payment-fields">
                        <div class="ord-row">
                            <div class="ord-field">
                                <span class="ord-label">Bank Name</span>
                                <input type="text" name="bank_name" class="ord-input" placeholder="e.g. Commercial Bank of Ethiopia" value="<?= htmlspecialchars($editOrder ? ($editOrder['bank_name'] ?? '') : ($_POST['bank_name'] ?? '')) ?>">
                            </div>
                            <div class="ord-field">
                                <span class="ord-label">Account Name</span>
                                <input type="text" name="account_name" class="ord-input" placeholder="Account holder name" value="<?= htmlspecialchars($editOrder ? ($editOrder['account_name'] ?? '') : ($_POST['account_name'] ?? '')) ?>">
                            </div>
                        </div>
                        <div class="ord-row">
                            <div class="ord-field">
                                <span class="ord-label">Account Number</span>
                                <input type="text" name="account_number" class="ord-input" placeholder="Account number" value="<?= htmlspecialchars($editOrder ? ($editOrder['account_number'] ?? '') : ($_POST['account_number'] ?? '')) ?>">
                            </div>
                            <div class="ord-field">
                                <span class="ord-label">Transaction Reference</span>
                                <input type="text" name="transaction_ref" class="ord-input" placeholder="Transfer ref or receipt number" value="<?= htmlspecialchars($editOrder ? ($editOrder['transaction_ref'] ?? '') : ($_POST['transaction_ref'] ?? '')) ?>">
                            </div>
                        </div>
                    </div>

                    <div id="pf-cbe_birr" class="payment-fields">
                        <div class="ord-row">
                            <div class="ord-field">
                                <span class="ord-label">Merchant Name</span>
                                <input type="text" name="merchant_name" class="ord-input" placeholder="Merchant name" value="<?= htmlspecialchars($editOrder ? ($editOrder['merchant_name'] ?? '') : ($_POST['merchant_name'] ?? '')) ?>">
                            </div>
                            <div class="ord-field">
                                <span class="ord-label">Merchant ID</span>
                                <input type="text" name="merchant_id" class="ord-input" placeholder="Merchant ID" value="<?= htmlspecialchars($editOrder ? ($editOrder['merchant_id'] ?? '') : ($_POST['merchant_id'] ?? '')) ?>">
                            </div>
                        </div>
                        <div class="ord-field">
                            <span class="ord-label">Transaction Reference *</span>
                            <input type="text" name="transaction_ref" class="ord-input" placeholder="CBE Birr transaction reference" value="<?= htmlspecialchars($editOrder ? ($editOrder['transaction_ref'] ?? '') : ($_POST['transaction_ref'] ?? '')) ?>">
                        </div>
                        <div style="text-align:center;margin-top:10px;padding:16px;background:var(--neutral-100);border-radius:8px">
                            <p style="font-size:0.82rem;color:var(--neutral-600);margin-bottom:8px">Scan to pay via CBE Birr</p>
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?= urlencode('cbebirr://pay?merchant=' . ($editOrder ? ($editOrder['merchant_id'] ?? 'NARED') : 'NARED') . '&amount=ORDER_TOTAL') ?>" alt="CBE Birr QR" style="border-radius:8px;border:1px solid var(--neutral-300)">
                        </div>
                    </div>

                    <div id="pf-telebirr" class="payment-fields">
                        <div class="ord-field">
                            <span class="ord-label">Phone Number *</span>
                            <input type="text" name="payment_detail" class="ord-input" placeholder="09XXXXXXXX or +2519XXXXXXXX" value="<?= htmlspecialchars($editOrder ? ($editOrder['payment_detail'] ?? '') : ($_POST['payment_detail'] ?? '')) ?>">
                        </div>
                        <div class="ord-field">
                            <span class="ord-label">Transaction Reference</span>
                            <input type="text" name="transaction_ref" class="ord-input" placeholder="Telebirr transaction ID" value="<?= htmlspecialchars($editOrder ? ($editOrder['transaction_ref'] ?? '') : ($_POST['transaction_ref'] ?? '')) ?>">
                        </div>
                        <div style="text-align:center;margin-top:10px;padding:16px;background:var(--neutral-100);border-radius:8px">
                            <p style="font-size:0.82rem;color:var(--neutral-600);margin-bottom:8px">Scan to pay via Telebirr</p>
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?= urlencode('telebirr://pay?phone=NARED&amount=ORDER_TOTAL') ?>" alt="Telebirr QR" style="border-radius:8px;border:1px solid var(--neutral-300)">
                        </div>
                    </div>

                    <div id="pf-cash" class="payment-fields">
                        <p style="font-size:0.85rem;color:var(--neutral-600);padding:8px 0">Pay cash at pickup or upon delivery. No advance payment details required.</p>
                    </div>

                    <div id="pf-credit" class="payment-fields">
                        <?php if ($creditAvailable): ?>
                            <div style="margin-bottom:10px">
                                <div style="display:flex;justify-content:space-between;font-size:0.82rem;margin-bottom:4px">
                                    <span>Credit Used</span>
                                    <span style="font-weight:600"><?= fmt($creditUsed) ?> / <?= fmt($creditLimit) ?></span>
                                </div>
                                <div class="credit-bar">
                                    <div class="credit-bar-fill" style="width:<?= min(($creditUsed / $creditLimit) * 100, 100) ?>%"></div>
                                </div>
                                <p style="font-size:0.78rem;color:var(--neutral-500);margin-top:4px">Available: <?= fmt(max($creditLimit - $creditUsed, 0)) ?></p>
                            </div>
                            <?php if ($activeAgreement): ?>
                                <div style="border:1px solid var(--neutral-200);border-radius:10px;padding:14px;background:var(--neutral-50,#f9fafb)">
                                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;gap:8px;flex-wrap:wrap">
                                        <strong style="font-size:0.88rem;color:var(--brown-800,#3d2c1e)"><i class="fas fa-file-contract"></i> <?= htmlspecialchars($activeAgreement['title'] ?: 'Credit Agreement') ?></strong>
                                        <span style="background:var(--brown-100,#ebe0cc);color:var(--brown-700,#5c3d1a);font-size:0.72rem;font-weight:700;padding:2px 8px;border-radius:12px">Version <?= (int) $activeAgreement['version'] ?></span>
                                    </div>
                                    <div style="font-size:0.8rem;color:var(--neutral-600);margin-bottom:8px"><i class="fas fa-calendar-check"></i> Payment due within <strong><?= (int) $activeAgreement['payment_terms_days'] ?></strong> days of the invoice date.</div>
                                    <div style="font-size:0.78rem;color:var(--neutral-700);line-height:1.5;max-height:180px;overflow-y:auto;white-space:pre-wrap;background:#fff;border:1px solid var(--neutral-200);border-radius:8px;padding:10px;margin-bottom:10px"><?= htmlspecialchars($activeAgreement['content']) ?></div>
                                    <?php if ($activeAgreement['require_acceptance']): ?>
                                        <label style="display:flex;gap:8px;align-items:flex-start;font-size:0.8rem;color:var(--neutral-700);cursor:pointer">
                                            <input type="checkbox" name="agreement_accepted" value="1" required style="margin-top:2px">
                                            <span>I have read and accept the current Credit Agreement (Version <?= (int) $activeAgreement['version'] ?>). My order is subject to these terms.</span>
                                        </label>
                                    <?php endif; ?>
                                    <input type="hidden" name="agreement_version_id" value="<?= (int) $activeAgreement['id'] ?>">
                                </div>
                            <?php endif; ?>
                        <?php elseif ($creditSalesEnabled): ?>
                            <p style="font-size:0.85rem;color:var(--red-600);padding:8px 0">Credit account not available for your account. Contact admin.</p>
                        <?php endif; ?>
                    </div>

                    <div class="ord-field" style="margin-top:10px">
                        <span class="ord-label">Payment Notes</span>
                        <textarea name="payment_detail" rows="2" class="ord-input" placeholder="Any additional payment details..."><?= htmlspecialchars($editOrder ? ($editOrder['payment_detail'] ?? '') : ($_POST['payment_detail'] ?? '')) ?></textarea>
                    </div>

                    <div class="ord-field">
                        <span class="ord-label">Upload Payment Receipt</span>
                        <input type="file" name="payment_screenshot" accept="image/jpeg,image/png,image/webp,image/gif" class="ord-input" style="padding:8px">
                        <span style="font-size:0.75rem;color:var(--neutral-500)">JPEG, PNG, WebP or GIF up to 2 MB.</span>
                    </div>
                </div>

                <button type="submit" class="ord-submit" data-swal-exclude="true"><i class="fas fa-paper-plane"></i> <?= $editOrder ? 'Update Order' : 'Submit Order' ?></button>
                <?php if ($editOrder): ?>
                    <a href="index.php?page=my_orders" class="ord-cancel"><i class="fas fa-times"></i> Cancel Edit</a>
                <?php endif; ?>
            </div>

            <div>
                <div class="ord-panel" style="position:sticky;top:20px">
                    <h2><i class="fas fa-receipt"></i> Order Summary</h2>
                    <div id="summaryContent">
                        <div class="empty-cart"><i class="fas fa-shopping-cart" style="font-size:2rem;color:var(--neutral-300);margin-bottom:8px;display:block"></i>Add products to see summary</div>
                    </div>
                </div>
            </div>
        </div>
    </form>
    <?php endif; ?>
</section>

<script>
var VAT_RATE = <?= number_format($vatRate, 2, '.', '') ?>;
var CURRENCY = '<?= addslashes(SettingsService::currencySymbol()) ?>';
var itemIndex = <?= $editOrder && !empty($editOrderItems) ? count($editOrderItems) : 1 ?>;

function switchPaymentMethod() {
    var m = document.getElementById('paymentMethod').value;
    document.querySelectorAll('.payment-fields').forEach(function(el) { el.classList.remove('active'); });
    var target = document.getElementById('pf-' + m);
    if (target) target.classList.add('active');
    var agree = document.querySelector('input[name="agreement_accepted"]');
    if (agree) agree.required = (m === 'credit');
}

function switchFulfillmentMethod() {
    var m = document.getElementById('fulfillmentMethod').value;
    var addrBlock = document.getElementById('deliveryAddressBlock');
    var pickupInfo = document.getElementById('pickupInfoBlock');
    var addrInput = document.getElementById('deliveryAddressInput');
    if (m === 'pickup') {
        if (addrBlock) addrBlock.style.display = 'none';
        if (pickupInfo) pickupInfo.style.display = 'block';
        if (addrInput) addrInput.required = false;
    } else {
        if (addrBlock) addrBlock.style.display = '';
        if (pickupInfo) pickupInfo.style.display = 'none';
        if (addrInput) addrInput.required = true;
    }
    document.querySelectorAll('.ord-item').forEach(function(row) { updatePrice(row); });
    recalcSummary();
}

function isPickupLane() {
    return (document.getElementById('fulfillmentMethod')?.value || 'delivery') === 'pickup';
}

function unitPrice(opt) {
    var retail = parseFloat(opt.getAttribute('data-price') || 0);
    if (isPickupLane()) {
        var wholesale = parseFloat(opt.getAttribute('data-wprice') || 0);
        if (wholesale > 0) return wholesale;
    }
    return retail;
}

function filterProductSelects(query) {
    var q = query.toLowerCase().trim();
    document.querySelectorAll('.product-select').forEach(function(sel) {
        Array.from(sel.options).forEach(function(opt) {
            if (!opt.value) return;
            var searchText = (opt.getAttribute('data-search') || opt.textContent).toLowerCase();
            opt.hidden = q !== '' && searchText.indexOf(q) === -1;
            opt.disabled = opt.hidden;
        });
        if (q !== '' && sel.value && sel.options[sel.selectedIndex].hidden) {
            sel.value = '';
            updatePrice(sel.closest('.ord-item'));
        }
    });
}

function updatePrice(row) {
    if (!row) return;
    if (!row.classList || !row.classList.contains('ord-item')) row = row.closest('.ord-item');
    if (!row) return;
    var sel = row.querySelector('.product-select');
    var opt = sel.options[sel.selectedIndex];
    var price = unitPrice(opt);
    var stock = parseInt(opt.getAttribute('data-stock') || 0);
    var qtyInput = row.querySelector('.qty-input');
    var qty = parseInt(qtyInput.value || 0);
    var disc = parseFloat(row.querySelector('.disc-input').value || 0);
    if (stock > 0) {
        qtyInput.max = stock;
    } else {
        qtyInput.removeAttribute('max');
    }
    if (stock > 0 && qty > stock) {
        qtyInput.value = stock;
        qty = stock;
    }
    var lineTotal = (price * qty) - disc;
    row.querySelector('[data-price-display]').textContent = fmtNum(price);
    row.querySelector('[data-line-total]').textContent = fmtNum(lineTotal);
    var hint = row.querySelector('.stock-hint');
    if (stock <= 0) {
        row.querySelector('[data-price-display]').innerHTML = fmtNum(price) + '<br><span class="stock-low">Out of Stock</span>';
        if (hint) hint.textContent = 'Insufficient stock available.';
    } else if (stock <= 10) {
        row.querySelector('[data-price-display]').innerHTML = fmtNum(price) + '<br><span class="stock-low">Only ' + stock + ' left</span>';
        if (hint) hint.textContent = 'Max order qty: ' + stock;
    } else {
        if (hint) hint.textContent = 'Max order qty: ' + stock;
    }
    recalcSummary();
}

function recalcSummary() {
    var items = document.querySelectorAll('.ord-item');
    var subtotal = 0, totalDisc = 0, totalQty = 0, itemCount = 0;
    items.forEach(function(row) {
        var sel = row.querySelector('.product-select');
        if (!sel || !sel.value) return;
        var opt = sel.options[sel.selectedIndex];
        var price = unitPrice(opt);
        var qty = parseInt(row.querySelector('.qty-input').value || 0);
        var disc = parseFloat(row.querySelector('.disc-input').value || 0);
        subtotal += price * qty;
        totalDisc += disc;
        totalQty += qty;
        itemCount++;
    });
    var discPct = parseFloat(document.getElementById('discountPercent')?.value || 0);
    if (discPct > 0 && discPct <= 100) {
        totalDisc = Math.round(subtotal * discPct / 100 * 100) / 100;
    }
    var taxable = Math.max(subtotal - totalDisc, 0);
    var vat = Math.round(taxable * VAT_RATE / 100 * 100) / 100;
    var grand = taxable + vat;
    var html = '';
    if (itemCount === 0) {
        html = '<div class="empty-cart"><i class="fas fa-shopping-cart" style="font-size:2rem;color:var(--neutral-300);margin-bottom:8px;display:block"></i>Add products to see summary</div>';
    } else {
        html += '<div class="summary-row"><span>Items</span><span>' + itemCount + ' products (' + totalQty + ' units)</span></div>';
        html += '<div class="summary-row"><span>Subtotal</span><span>' + fmtETB(subtotal) + '</span></div>';
        if (totalDisc > 0) html += '<div class="summary-row" style="color:var(--red-600)"><span>Discount</span><span>- ' + fmtETB(totalDisc) + '</span></div>';
        html += '<div class="summary-row"><span>VAT (' + VAT_RATE + '%)</span><span>' + fmtETB(vat) + '</span></div>';
        html += '<div class="summary-row total"><span>Grand Total</span><span>' + fmtETB(grand) + '</span></div>';
        var pm = document.getElementById('paymentMethod')?.value || 'cash';
        html += '<div style="margin-top:12px;padding:10px;background:var(--neutral-50);border-radius:8px;font-size:0.82rem">';
        html += '<div style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="color:var(--neutral-500)">Payment</span><span style="font-weight:600">' + pm.replace('_', ' ').replace(/\b\w/g, function(l){ return l.toUpperCase(); }) + '</span></div>';
        html += '<div style="display:flex;justify-content:space-between"><span style="color:var(--neutral-500)">Status</span><span style="color:var(--orange-600);font-weight:600">Pending Verification</span></div>';
        html += '</div>';
    }
    document.getElementById('summaryContent').innerHTML = html;
}

function addItem() {
    var container = document.getElementById('orderItems');
    var idx = itemIndex++;
    var options = '<option value="">Select product</option>';
    var firstSel = document.querySelector('.product-select');
    if (firstSel) {
        Array.from(firstSel.options).forEach(function(opt) {
            if (!opt.value) return;
            var txt = opt.textContent.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            var searchText = (opt.getAttribute('data-search') || '').replace(/"/g,'&quot;');
            options += '<option value="' + opt.value + '" data-price="' + opt.getAttribute('data-price') + '" data-wprice="' + opt.getAttribute('data-wprice') + '" data-stock="' + opt.getAttribute('data-stock') + '" data-search="' + searchText + '">' + txt + '</option>';
        });
    }
    var html = '<div class="ord-item" data-index="' + idx + '">';
    html += '<div style="margin-bottom:6px"><select name="request_items[' + idx + '][product_id]" class="ord-sel product-select" required onchange="updatePrice(this.closest(\'.ord-item\'))">' + options + '</select></div>';
    html += '<div class="ord-item-bottom">';
    html += '<div><span class="ord-label">Quantity</span><input type="number" name="request_items[' + idx + '][quantity]" value="1" min="1" class="ord-input qty-input" onchange="updatePrice(this.closest(\'.ord-item\'))" onkeyup="updatePrice(this.closest(\'.ord-item\'))" required><div class="stock-hint stock-ok" style="margin-top:4px"></div></div>';
    html += '<div><span class="ord-label">Unit Price</span><div class="ord-input" style="background:var(--neutral-100);border-style:dashed;display:flex;align-items:center;height:42px" data-price-display>0</div></div>';
    html += '<div><span class="ord-label">Discount (ETB)</span><input type="number" name="request_items[' + idx + '][line_discount]" value="0" min="0" step="0.01" class="ord-input disc-input" onchange="updatePrice(this.closest(\'.ord-item\'))" onkeyup="updatePrice(this.closest(\'.ord-item\'))"></div>';
    html += '<div><span class="ord-label">Line Total</span><div class="line-total" data-line-total>0</div></div>';
    html += '<button type="button" class="ord-item-remove" onclick="removeItem(this)"><i class="fas fa-times"></i></button>';
    html += '</div></div>';
    container.insertAdjacentHTML('beforeend', html);
    var searchVal = document.getElementById('productSearch')?.value || '';
    if (searchVal) filterProductSelects(searchVal);
    recalcSummary();
}

function removeItem(btn) {
    var items = document.querySelectorAll('.ord-item');
    if (items.length <= 1) { alert('You need at least one product.'); return; }
    btn.closest('.ord-item').remove();
    recalcSummary();
}

function fmtNum(n) { return CURRENCY + ' ' + n.toFixed(2); }
function fmtETB(n) { return CURRENCY + ' ' + n.toFixed(2); }

document.addEventListener('DOMContentLoaded', function() {
    switchPaymentMethod();
    switchFulfillmentMethod();
    document.querySelectorAll('.ord-item').forEach(function(row) { updatePrice(row); });
    recalcSummary();

    // Ensure the Submit/Update Order button gives clear feedback instead of failing
    // silently when a required field is empty (browser validation bubble can be easy
    // to miss, especially for fields below the fold or inside hidden panels).
    var orderForm = document.getElementById('orderForm');
    if (orderForm) {
        orderForm.addEventListener('submit', function(e) {
            if (!orderForm.checkValidity()) {
                e.preventDefault();
                var firstInvalid = orderForm.querySelector('.ord-item select:invalid, .ord-item input:invalid, textarea:invalid, select:invalid, input:required:invalid');
                if (firstInvalid) {
                    firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    firstInvalid.focus();
                    firstInvalid.style.outline = '2px solid #dc2626';
                    setTimeout(function() { firstInvalid.style.outline = ''; }, 1600);
                }
                var missing = function(el) {
                    var friendly = { agreement_accepted: 'ticking the Credit Agreement acceptance box' };
                    if (friendly[el.name]) return 'Please complete: ' + friendly[el.name];
                    var field = el.closest('.ord-field');
                    var lbl = field ? field.querySelector('.ord-label') : el.closest('label');
                    var txt = lbl ? lbl.textContent.trim().split('\n')[0].trim() : '';
                    if (txt.length > 1) return 'Please complete: ' + txt;
                    return 'Please complete: ' + el.name.replace(/request_items\[\d+\]\[/g, '').replace(/[\[\]]/g, '').replace(/_/g, ' ');
                };
                var msg = firstInvalid ? missing(firstInvalid) : 'Please complete all required fields before submitting.';
                if (typeof showToast === 'function') {
                    try { showToast(msg, 'error'); }
                    catch (err) { alert('Please complete all required fields before submitting.'); }
                } else {
                    alert('Please complete all required fields before submitting.');
                }
            }
        });
    }
});
</script>
