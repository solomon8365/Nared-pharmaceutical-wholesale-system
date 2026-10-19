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

// Registered business/customer record, used to prefill Step 2 without letting
// the customer silently rewrite verified account information.
$customer = null;
if ($cid !== null) {
    $cstmt = $pdo->prepare('SELECT id, customer_code, name, customer_type, contact_person, phone, email, physical_address, tin_number, license_number, customer_status FROM customers WHERE id = :id LIMIT 1');
    $cstmt->execute(['id' => $cid]);
    $customer = $cstmt->fetch() ?: null;
}
$customerTypeLabel = '';
if ($customer) {
    $typeLabels = [
        'pharmacy' => 'Pharmacy', 'drug_store' => 'Drug Store', 'hospital' => 'Hospital',
        'clinic' => 'Clinic', 'wholesaler' => 'Wholesaler', 'other_licensed' => 'Other Licensed',
    ];
    $customerTypeLabel = $typeLabels[$customer['customer_type'] ?? ''] ?? '';
}

// Step 1 no longer renders the whole catalog into every <select>; it searches
// products live through ajax_search_products.php. Here we only need to know
// whether any sellable product exists, and to seed the rows already referenced
// by a prefill / edit (loaded further below).
$hasProducts = (int) $pdo->query("SELECT COUNT(*) FROM products p WHERE p.product_status = 'active' AND (EXISTS (SELECT 1 FROM product_batches b WHERE b.product_id = p.id AND b.quantity > 0 AND b.status = 'active') OR p.stock_quantity > 0)")->fetchColumn() > 0;
$vatRate = CalculationService::vatRate($pdo);

$prefillPid = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
if ($prefillPid > 0) {
    $ps = $pdo->prepare("SELECT COUNT(*) FROM products WHERE id = :id AND product_status = 'active'");
    $ps->execute(['id' => $prefillPid]);
    if (!(int) $ps->fetchColumn()) $prefillPid = 0;
}

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

// One-time form token: blocks duplicate submissions (double-click, or a
// browser back + resubmit after a successful order). The token is rotated on
// the next GET after a successful submit.
if (empty($_SESSION['order_form_token'])) {
    $_SESSION['order_form_token'] = bin2hex(random_bytes(16));
}
$formToken = $_SESSION['order_form_token'];

$postedStep = 1;
$duplicateSubmit = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $cid !== null) {
    require_csrf();
    $postedStep = max(1, min(4, (int) ($_POST['current_step'] ?? 4)));
    $submittedToken = (string) ($_POST['form_token'] ?? '');
    if ($submittedToken === '' || !hash_equals($formToken, $submittedToken)) {
        $duplicateSubmit = true;
        $messages[] = ['type' => 'error', 'text' => 'This order was already submitted. Open My Orders to review it - this avoids creating a duplicate order.'];
    }
    if (!$duplicateSubmit) {
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
        $orderNotes = trim($_POST['order_notes'] ?? '');
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
            'order_notes' => $orderNotes,
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
                do { $fname = 'order_' . bin2hex(random_bytes(8)) . '.' . $extMap[$mime]; } while (is_file(PAYMENT_UPLOAD_DIR . DIRECTORY_SEPARATOR . $fname));
                $screenshotPath = storage_put($file['tmp_name'], 'payments', $fname, $mime);
                if ($screenshotPath === null) throw new RuntimeException('Failed to save receipt.');
                $uploadedNew = true;
            }

            if ($editingOrderId > 0) {
                OrderService::updateOrder($pdo, $cid, $editingOrderId, $data, $uploadedNew ? $screenshotPath : null);
                unset($_SESSION['order_form_token']);
                set_flash('success', 'Order #' . $editingOrderId . ' updated successfully.');
                header('Location: index.php?page=my_orders');
                exit;
            } else {
                $res = OrderService::submitOrder($pdo, $cid, $uid, $data, $uploadedNew ? $screenshotPath : null);
                $orderId = $res['order_id'];
                unset($_SESSION['order_form_token']);
                set_flash('success', 'Order #' . $orderId . ' submitted successfully and is awaiting finance verification. Track status in My Orders.');
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

// Which wizard step to re-open after a failed submit.
$activeStep = 1;
if ($duplicateSubmit) {
    $activeStep = 4;
} elseif (!empty($messages)) {
    $activeStep = $postedStep;
    foreach ($messages as $m) {
        $t = $m['text'];
        if (stripos($t, 'credit') !== false && stripos($t, 'limit') !== false) { $activeStep = 3; break; }
        if (stripos($t, 'agreement') !== false || stripos($t, 'transaction') !== false) { $activeStep = 3; break; }
        if (stripos($t, 'stock') !== false || stripos($t, 'product') !== false || stripos($t, 'quantity') !== false) { $activeStep = 1; break; }
        if (stripos($t, 'delivery address') !== false || stripos($t, 'fulfillment') !== false) { $activeStep = 2; break; }
        if (stripos($t, 'payment') !== false) { $activeStep = 3; break; }
    }
}

$selMethod = 'cash';
if ($editOrder && in_array($editOrder['payment_method'], ['cash', 'bank', 'cbe_birr', 'telebirr', 'credit'], true)) {
    $selMethod = $editOrder['payment_method'];
} elseif (isset($_POST['payment_method']) && in_array($_POST['payment_method'], ['cash', 'bank', 'cbe_birr', 'telebirr', 'credit'], true)) {
    $selMethod = $_POST['payment_method'];
}
if ($selMethod === 'credit' && !$creditSalesEnabled) $selMethod = 'cash';

$selFulfillment = 'delivery';
if ($editOrder) {
    $selFulfillment = ($editOrder['fulfillment_method'] ?? 'delivery') === 'pickup' ? 'pickup' : 'delivery';
} elseif (isset($_POST['fulfillment_method'])) {
    $selFulfillment = $_POST['fulfillment_method'] === 'pickup' ? 'pickup' : 'delivery';
}

// A small, bounded initial list keeps the picker usable before the customer
// types anything. The old page rendered the ENTIRE catalog into every row;
// this caps it at 25 and live search refines it.
$initialProducts = $pdo->query("SELECT p.id, p.name, p.brand, p.generic_name, p.dosage_form, p.strength, p.price, p.wholesale_price, p.stock_quantity, p.barcode,
                                       CASE WHEN EXISTS (SELECT 1 FROM product_batches b WHERE b.product_id = p.id)
                                            THEN COALESCE((SELECT SUM(b2.quantity) FROM product_batches b2 WHERE b2.product_id = p.id AND b2.quantity > 0 AND b2.status = 'active'), 0)
                                            ELSE p.stock_quantity END AS available_stock
                                FROM products p
                                WHERE p.product_status = 'active'
                                HAVING available_stock > 0
                                ORDER BY p.name
                                LIMIT 25")->fetchAll();

// Seed rows for a prefill / edit with just their own product. Everything else
// is fetched live as the customer types in the Step 1 search box.
$seedIds = [];
if ($prefillPid > 0 && !$editOrder) { $seedIds[$prefillPid] = true; }
foreach ($editOrderItems as $it) {
    $pid = (int) ($it['product_id'] ?? 0);
    if ($pid > 0) { $seedIds[$pid] = true; }
}
$seedProducts = [];
if (!empty($seedIds)) {
    $seedIn = implode(',', array_map('intval', array_keys($seedIds)));
    $seedStmt = $pdo->query("SELECT p.id, p.name, p.brand, p.generic_name, p.dosage_form, p.strength, p.price, p.wholesale_price, p.stock_quantity, p.barcode,
                                    CASE WHEN EXISTS (SELECT 1 FROM product_batches b WHERE b.product_id = p.id)
                                         THEN COALESCE((SELECT SUM(b2.quantity) FROM product_batches b2 WHERE b2.product_id = p.id AND b2.quantity > 0 AND b2.status = 'active'), 0)
                                         ELSE p.stock_quantity END AS available_stock
                             FROM products p WHERE p.id IN ($seedIn)");
    foreach ($seedStmt->fetchAll() as $row) { $seedProducts[(int) $row['id']] = $row; }
}

// Renders one <option> for a product using the SAME data attributes the live
// search endpoint feeds into the page, so totals/stock behave identically.
$productOptionHtml = function (array $p, bool $selected = false): string {
    $name = (string) $p['name'];
    $brand = (string) ($p['brand'] ?? '');
    $strength = (string) ($p['strength'] ?? '');
    $label = $name
        . ($brand !== '' ? ' (' . $brand . ')' : '')
        . ($strength !== '' ? ' - ' . $strength : '')
        . ' — ' . fmt((float) $p['price']);
    $search = strtolower($name . ' ' . $brand . ' ' . (string) ($p['generic_name'] ?? '') . ' ' . $strength . ' ' . (string) ($p['barcode'] ?? ''));
    return '<option value="' . (int) $p['id'] . '"' . ($selected ? ' selected' : '')
        . ' data-price="' . (float) $p['price'] . '"'
        . ' data-wprice="' . (float) ($p['wholesale_price'] ?? 0) . '"'
        . ' data-stock="' . (int) ($p['available_stock'] ?? ($p['stock_quantity'] ?? 0)) . '"'
        . ' data-name="' . htmlspecialchars($name, ENT_QUOTES) . '"'
        . ' data-brand="' . htmlspecialchars($brand, ENT_QUOTES) . '"'
        . ' data-strength="' . htmlspecialchars($strength, ENT_QUOTES) . '"'
        . ' data-generic="' . htmlspecialchars((string) ($p['generic_name'] ?? ''), ENT_QUOTES) . '"'
        . ' data-form="' . htmlspecialchars((string) ($p['dosage_form'] ?? ''), ENT_QUOTES) . '"'
        . ' data-search="' . htmlspecialchars($search, ENT_QUOTES) . '"'
        . '>' . htmlspecialchars($label) . '</option>';
};

// Row renderer shared by the edit list and the blank first row.
$renderRow = function (int $idx, ?array $selectedProduct = null, int $qty = 1, float $disc = 0) use ($initialProducts, $productOptionHtml) {
    $selectedId = $selectedProduct ? (int) $selectedProduct['id'] : 0;
    $initialIds = array_map('intval', array_column($initialProducts, 'id'));
    $rowOptions = '';
    if ($selectedProduct && !in_array($selectedId, $initialIds, true)) {
        $rowOptions .= $productOptionHtml($selectedProduct, true);
    }
    foreach ($initialProducts as $ip) {
        $rowOptions .= $productOptionHtml($ip, (int) $ip['id'] === $selectedId);
    }
    ob_start(); ?>
    <div class="ord-item" data-index="<?= $idx ?>">
        <div class="ord-item-head">
            <select name="request_items[<?= $idx ?>][product_id]" class="ord-sel product-select" required onchange="updatePrice(this)">
                <option value="">Select product</option>
                <?= $rowOptions ?>
            </select>
            <button type="button" class="ord-item-remove" onclick="removeItem(this)" title="Remove product"><i class="fas fa-trash-alt"></i></button>
        </div>
        <div class="ord-item-grid">
            <div class="ord-field">
                <span class="ord-label">Quantity</span>
                <input type="number" name="request_items[<?= $idx ?>][quantity]" value="<?= $qty ?>" min="1" class="ord-input qty-input" onchange="updatePrice(this.closest('.ord-item'))" onkeyup="updatePrice(this.closest('.ord-item'))" required>
                <div class="stock-hint"></div>
            </div>
            <div class="ord-field">
                <span class="ord-label">Unit Price</span>
                <div class="ord-static" data-price-display>—</div>
            </div>
            <div class="ord-field">
                <span class="ord-label">Line Discount (ETB)</span>
                <input type="number" name="request_items[<?= $idx ?>][line_discount]" value="<?= (float) $disc ?>" min="0" step="0.01" class="ord-input disc-input" onchange="updatePrice(this.closest('.ord-item'))" onkeyup="updatePrice(this.closest('.ord-item'))">
            </div>
            <div class="ord-field">
                <span class="ord-label">Line Total</span>
                <div class="ord-static ord-line-total" data-line-total>—</div>
            </div>
        </div>
    </div>
    <?php return ob_get_clean();
};
?>
<style>
.ord-wrap{max-width:1280px;margin:0 auto}
.ord-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px}
.ord-title{font-size:1.35rem;font-weight:800;color:var(--primary-900,#1e3a5f);margin:0;display:flex;align-items:center;gap:10px}
.ord-title i{color:var(--primary-600,#2563eb)}
.ord-grid{display:grid;grid-template-columns:1fr 340px;gap:22px;align-items:start}
@media(max-width:980px){.ord-grid{grid-template-columns:1fr}}
.ord-main{min-width:0}

/* Step indicator */
.ord-steps{display:flex;list-style:none;margin:0 0 18px;padding:0;gap:6px;overflow-x:auto;scrollbar-width:thin}
.ord-step-item{display:flex;align-items:center;gap:9px;padding:8px 12px;border-radius:10px;flex:1;min-width:150px;background:#fff;border:1px solid var(--neutral-200,#e2e8f0)}
.ord-step-item .num{width:26px;height:26px;border-radius:50%;background:var(--neutral-100,#f1f5f9);color:var(--neutral-500,#64748b);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.78rem;flex-shrink:0}
.ord-step-item .lbl{font-size:.82rem;font-weight:700;color:var(--neutral-500,#64748b);white-space:nowrap}
.ord-step-item .lbl small{display:block;font-weight:500;font-size:.68rem;color:var(--neutral-400,#94a3b8)}
.ord-step-item.is-active{border-color:var(--primary-600,#2563eb);background:var(--primary-50,#eff6ff)}
.ord-step-item.is-active .num{background:var(--primary-600,#2563eb);color:#fff}
.ord-step-item.is-active .lbl{color:var(--primary-800,#1e40af)}
.ord-step-item.is-done .num{background:var(--green-600,#16a34a);color:#fff}
.ord-step-item.is-done .lbl{color:var(--primary-900,#1e3a5f)}

.ord-panel{background:#fff;border-radius:14px;border:1px solid var(--neutral-200,#e2e8f0);padding:22px;margin-bottom:14px;box-shadow:0 1px 3px rgba(15,23,42,.04)}
.ord-panel h2{font-size:1rem;font-weight:800;color:var(--primary-900,#1e3a5f);margin:0 0 16px;display:flex;align-items:center;gap:9px}
.ord-panel h2 i{color:var(--primary-600,#2563eb);font-size:.95rem}
.ord-pane{display:none}
.ord-pane.is-active{display:block;animation:ordFade .18s ease}
@keyframes ordFade{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}

.ord-label{display:block;font-size:.8rem;font-weight:700;color:var(--neutral-600,#475569);margin-bottom:5px}
.ord-field{margin-bottom:14px}
.ord-input,.ord-sel{width:100%;padding:10px 12px;border:1px solid var(--neutral-300,#cbd5e1);border-radius:9px;font-size:.88rem;font-family:inherit;background:#fff;color:#0f172a;box-sizing:border-box}
.ord-input:focus,.ord-sel:focus{outline:none;border-color:var(--primary-600,#2563eb);box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.ord-input.ord-invalid,.ord-sel.ord-invalid{border-color:#dc2626;box-shadow:0 0 0 3px rgba(220,38,38,.12)}
.ord-static{padding:10px 12px;border:1px dashed var(--neutral-300,#cbd5e1);border-radius:9px;background:var(--neutral-50,#f8fafc);font-size:.88rem;color:#0f172a;min-height:42px;display:flex;align-items:center}
.ord-line-total{font-weight:800;color:var(--primary-800,#1e40af)}
.ord-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.ord-row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
@media(max-width:560px){.ord-row,.ord-row3{grid-template-columns:1fr}}

.ord-item{border:1px solid var(--neutral-200,#e2e8f0);border-radius:11px;padding:14px;margin-bottom:12px;background:#fff}
.ord-item-head{display:flex;gap:8px;align-items:center;margin-bottom:10px}
.ord-item-head .product-select{flex:1;min-width:0}
.ord-item-grid{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px}
@media(max-width:720px){.ord-item-grid{grid-template-columns:1fr 1fr}}
.ord-item-remove{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;border-radius:9px;cursor:pointer;padding:10px 12px;flex-shrink:0;font-size:.85rem}
.ord-item-remove:hover{background:#fee2e2}
.ord-add-btn{background:var(--primary-50,#eff6ff);color:var(--primary-800,#1e40af);border:1.5px dashed var(--primary-200,#bfdbfe);border-radius:10px;padding:11px;width:100%;cursor:pointer;font-weight:700;font-size:.86rem;display:flex;align-items:center;justify-content:center;gap:7px}
.ord-add-btn:hover{background:var(--primary-100,#dbeafe)}
.stock-hint{margin-top:5px;font-size:.74rem;font-weight:700}
.ord-in{color:#15803d}.ord-out{color:#dc2626}
/* Product suggestion panel under the Step 1 search box */
.ord-suggest{position:absolute;left:0;right:0;top:46px;z-index:60;background:#fff;border:1px solid var(--neutral-200,#e2e8f0);border-radius:12px;box-shadow:0 16px 40px rgba(15,23,42,.16);max-height:340px;overflow:auto;display:none}
.ord-suggest.is-open{display:block}
.ord-suggest-head{padding:8px 12px;font-size:.68rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#64748b;background:#f8fafc;border-bottom:1px solid #eef2f6;position:sticky;top:0;z-index:1}
.ord-suggest-item{display:flex;align-items:center;gap:12px;width:100%;padding:10px 12px;border:0;border-bottom:1px solid #f1f5f9;background:#fff;text-align:left;cursor:pointer;font:inherit}
.ord-suggest-item:last-child{border-bottom:0}
.ord-suggest-item:hover,.ord-suggest-item:focus{background:#eff6ff;outline:none}
.ord-suggest-main{flex:1;min-width:0}
.ord-suggest-name{display:block;font-weight:700;font-size:.86rem;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ord-suggest-meta{display:block;font-size:.72rem;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ord-suggest-price{display:flex;flex-direction:column;align-items:flex-end;gap:2px;font-weight:800;font-size:.82rem;color:#1e40af;white-space:nowrap}
.ord-suggest-stock{font-size:.66rem;font-weight:700}
.ord-suggest-empty{padding:16px 14px;font-size:.82rem;line-height:1.45;color:#64748b;text-align:center}
.ord-suggest-empty strong{color:#b45309}

/* Business info card */
.ord-info-card{background:var(--primary-50,#eff6ff);border:1px solid var(--primary-200,#bfdbfe);border-radius:12px;padding:16px;margin-bottom:16px}
.ord-info-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px 18px;margin-top:8px}
@media(max-width:560px){.ord-info-grid{grid-template-columns:1fr}}
.ord-info-grid dt{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:var(--primary-700,#1d4ed8)}
.ord-info-grid dd{margin:2px 0 0;font-size:.86rem;font-weight:600;color:#0f172a}

/* Payment cards */
.ord-pay-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:16px}
.ord-pay-card{position:relative;display:flex;flex-direction:column;gap:4px;padding:14px;border:1.5px solid var(--neutral-200,#e2e8f0);border-radius:12px;cursor:pointer;background:#fff;transition:all .15s}
.ord-pay-card:hover{border-color:var(--primary-300,#93c5fd)}
.ord-pay-card.is-selected{border-color:var(--primary-600,#2563eb);background:var(--primary-50,#eff6ff);box-shadow:0 0 0 3px rgba(37,99,235,.1)}
.ord-pay-card input{position:absolute;opacity:0;pointer-events:none}
.ord-pay-card .pc-icon{font-size:1.1rem;color:var(--primary-600,#2563eb)}
.ord-pay-card .pc-name{font-size:.86rem;font-weight:800;color:var(--primary-900,#1e3a5f)}
.ord-pay-card .pc-sub{font-size:.72rem;color:var(--neutral-500,#64748b)}
.ord-pay-card.is-disabled{opacity:.55;cursor:not-allowed}
.ord-paypanel{display:none;margin-top:6px;padding:14px;background:var(--neutral-50,#f8fafc);border:1px solid var(--neutral-200,#e2e8f0);border-radius:11px}
.ord-paypanel.is-active{display:block}
.ord-note-box{font-size:.82rem;color:var(--neutral-600,#475569);line-height:1.5}

/* Summary */
.ord-side{position:sticky;top:16px}
.summary-row{display:flex;justify-content:space-between;padding:6px 0;font-size:.87rem;color:var(--neutral-600,#475569)}
.summary-row span:last-child{font-weight:600;color:#0f172a}
.summary-row.total{border-top:2px solid var(--primary-200,#bfdbfe);padding-top:11px;margin-top:7px;font-weight:800;font-size:1.05rem}
.summary-row.total span{color:var(--primary-900,#1e3a5f)}
.ord-empty{text-align:center;padding:26px 12px;color:var(--neutral-400,#94a3b8);font-size:.86rem}
.ord-empty i{font-size:1.9rem;display:block;margin-bottom:8px;color:var(--neutral-300,#cbd5e1)}

/* Nav buttons */
.ord-nav{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:6px}
.ord-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:none;border-radius:10px;padding:12px 22px;font-size:.9rem;font-weight:700;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .15s}
.ord-btn-primary{background:var(--primary-600,#2563eb);color:#fff}
.ord-btn-primary:hover{background:var(--primary-700,#1d4ed8)}
.ord-btn-ghost{background:#fff;color:var(--neutral-600,#475569);border:1px solid var(--neutral-300,#cbd5e1)}
.ord-btn-ghost:hover{background:var(--neutral-50,#f8fafc)}
.ord-btn-submit{background:var(--primary-800,#1e40af);color:#fff}
.ord-btn-submit:hover{background:var(--primary-900,#1e3a5f)}
.ord-btn[disabled]{opacity:.6;cursor:wait}
.ord-cancel{text-align:center;display:block;margin-top:10px;font-size:.84rem;color:var(--neutral-500,#64748b);text-decoration:none}
.ord-cancel:hover{color:var(--primary-600,#2563eb)}
.credit-bar{background:var(--neutral-100,#f1f5f9);border-radius:6px;height:8px;overflow:hidden;margin-top:6px}
.credit-bar-fill{height:100%;background:var(--primary-600,#2563eb);border-radius:6px}
.notif-badge{background:#dc2626;color:#fff;border-radius:20px;font-size:.68rem;padding:3px 9px;font-weight:700}
.ord-review-tbl{width:100%;border-collapse:collapse;font-size:.8rem;margin-bottom:12px}
.ord-review-tbl th{text-align:left;padding:7px 9px;background:var(--neutral-50,#f8fafc);font-size:.7rem;text-transform:uppercase;letter-spacing:.03em;color:var(--neutral-500,#64748b);border-bottom:1px solid var(--neutral-200,#e2e8f0)}
.ord-review-tbl td{padding:7px 9px;border-bottom:1px solid var(--neutral-100,#f1f5f9)}
.confirm-check{display:flex;gap:10px;align-items:flex-start;padding:14px;background:var(--primary-50,#eff6ff);border:1px solid var(--primary-200,#bfdbfe);border-radius:11px;font-size:.85rem;color:#0f172a;cursor:pointer}
.confirm-check input{margin-top:2px;width:auto}
.badge-soft{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;background:var(--primary-100,#dbeafe);color:var(--primary-800,#1e40af)}
</style>

<section class="ord-wrap" id="ordMain">
    <div class="ord-head">
        <h1 class="ord-title"><i class="fas fa-file-medical"></i> <?= $editOrder ? 'Edit Order #' . (int) $editOrder['id'] : 'Place Order' ?></h1>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <?php if ($unreadNotifs > 0): ?><span class="notif-badge"><?= $unreadNotifs ?> new</span><?php endif; ?>
            <?php if ($pendingCount > 0): ?><span class="badge-soft"><i class="fas fa-clock"></i> <?= $pendingCount ?> pending order<?= $pendingCount > 1 ? 's' : '' ?></span><?php endif; ?>
            <a href="index.php?page=my_orders" class="ord-btn ord-btn-ghost" style="padding:8px 14px;font-size:.82rem"><i class="fas fa-list"></i> My Orders</a>
        </div>
    </div>

    <?php foreach ($messages as $message): ?>
        <div class="alert alert-<?= htmlspecialchars($message['type']) ?>"><?= htmlspecialchars($message['text']) ?></div>
    <?php endforeach; ?>

    <?php if ($creditLimitError !== ''): ?>
    <div class="cl-modal" id="creditLimitModal">
        <div class="cl-modal-card">
            <div class="cl-modal-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <h2 class="cl-modal-title">Credit limit exceeded</h2>
            <p class="cl-modal-detail"><?= htmlspecialchars($creditLimitError) ?></p>
            <p class="cl-modal-hint">Your order was <strong>not</strong> submitted. Reduce the order total, pay down your outstanding balance, or contact finance for a credit limit review.</p>
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
        ordShowStep(3);
    }
    document.addEventListener('DOMContentLoaded', function() {
        var m = document.getElementById('creditLimitModal');
        if (m) m.addEventListener('click', function(e) { if (e.target === m) m.style.display = 'none'; });
    });
    </script>
    <?php endif; ?>

    <?php if ($cid === null): ?>
        <div class="ord-panel"><p>Your account is not linked to a customer profile. Contact an administrator.</p></div>
    <?php elseif (!$hasProducts): ?>
        <div class="ord-panel"><p>No products are available yet. Please check back later.</p></div>
    <?php else: ?>
    <form method="post" enctype="multipart/form-data" id="orderForm" data-swal-exclude="true" data-ajax-off="true" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="form_token" value="<?= htmlspecialchars($formToken) ?>">
        <input type="hidden" name="current_step" id="current_step" value="<?= (int) $activeStep ?>">
        <?php if ($editOrder): ?>
            <input type="hidden" name="editing_order_id" value="<?= (int) $editOrder['id'] ?>">
        <?php endif; ?>

        <div class="ord-grid">
            <div class="ord-main">
                <ol class="ord-steps" id="ordSteps">
                    <li class="ord-step-item" data-step="1"><span class="num">1</span><span class="lbl">Review Order<small>Products &amp; quantities</small></span></li>
                    <li class="ord-step-item" data-step="2"><span class="num">2</span><span class="lbl">Customer &amp; Delivery<small>Who &amp; where</small></span></li>
                    <li class="ord-step-item" data-step="3"><span class="num">3</span><span class="lbl">Payment Method<small>How you pay</small></span></li>
                    <li class="ord-step-item" data-step="4"><span class="num">4</span><span class="lbl">Confirmation<small>Review &amp; submit</small></span></li>
                </ol>

                <!-- STEP 1 -->
                <div class="ord-panel ord-pane" data-step="1">
                    <h2><i class="fas fa-box-open"></i> Step 1 · Review Order</h2>
                    <div class="ord-field" style="position:relative">
                        <input type="text" id="productSearch" class="ord-input" placeholder="Search products by name, brand, generic, strength or barcode..." oninput="onProductSearch(this.value)" autocomplete="off" style="padding-left:34px">
                        <i class="fas fa-search" style="position:absolute;left:12px;top:38px;color:var(--neutral-400,#94a3b8);font-size:.85rem;pointer-events:none"></i>
                        <span id="productSearchHint" style="display:block;margin-top:6px;font-size:.74rem;color:var(--neutral-500,#64748b)"><i class="fas fa-lightbulb"></i> Pick from the list, or type 2+ characters to narrow it by name, brand, generic, strength or barcode.</span>
                        <div id="productSuggestions" class="ord-suggest" role="listbox" aria-label="Product suggestions"></div>
                    </div>
                    <div id="orderItems">
                        <?php if ($editOrder && !empty($editOrderItems)): ?>
                            <?php foreach ($editOrderItems as $i => $item): ?>
                                <?= $renderRow((int) $i, $seedProducts[(int) $item['product_id']] ?? null, (int) $item['quantity'], (float) ($item['discount'] ?? 0)) ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?= $renderRow(0, $prefillPid > 0 ? ($seedProducts[$prefillPid] ?? null) : null, 1, 0) ?>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="ord-add-btn" onclick="addItem()"><i class="fas fa-plus"></i> Add Another Product</button>
                    <div class="ord-row" style="margin-top:16px">
                        <div class="ord-field">
                            <span class="ord-label">Order Date</span>
                            <input type="date" name="order_date" value="<?= htmlspecialchars($editOrder ? $editOrder['order_date'] : ($_POST['order_date'] ?? date('Y-m-d'))) ?>" class="ord-input">
                        </div>
                        <div class="ord-field">
                            <span class="ord-label">Order Discount (%)</span>
                            <input type="number" name="discount_percent" id="discountPercent" value="<?= htmlspecialchars((string) ($editOrder ? ($editOrder['discount_amount'] > 0 && $editOrder['total_amount'] > 0 ? '' : 0) : 0)) ?>" min="0" max="100" step="0.1" class="ord-input" onchange="recalcSummary()" onkeyup="recalcSummary()">
                        </div>
                    </div>
                </div>

                <!-- STEP 2 -->
                <div class="ord-panel ord-pane" data-step="2">
                    <h2><i class="fas fa-building"></i> Step 2 · Customer &amp; Delivery Details</h2>
                    <?php if ($customer): ?>
                    <div class="ord-info-card">
                        <div style="font-size:.8rem;font-weight:800;color:var(--primary-800,#1e40af)"><i class="fas fa-check-circle"></i> Ordering as your registered account</div>
                        <dl class="ord-info-grid">
                            <div><dt>Business Name</dt><dd><?= htmlspecialchars($customer['name']) ?></dd></div>
                            <div><dt>Account Type</dt><dd><?= htmlspecialchars($customerTypeLabel ?: 'Registered Customer') ?></dd></div>
                            <div><dt>Contact Person</dt><dd><?= htmlspecialchars($customer['contact_person'] ?: '—') ?></dd></div>
                            <div><dt>Phone</dt><dd><?= htmlspecialchars($customer['phone'] ?: '—') ?></dd></div>
                            <div><dt>TIN</dt><dd><?= htmlspecialchars($customer['tin_number'] ?: '—') ?></dd></div>
                            <div><dt>License No.</dt><dd><?= htmlspecialchars($customer['license_number'] ?: '—') ?></dd></div>
                            <div style="grid-column:1/-1"><dt>Registered Address</dt><dd><?= htmlspecialchars($customer['physical_address'] ?: '—') ?></dd></div>
                        </dl>
                        <p style="font-size:.74rem;color:var(--primary-700,#1d4ed8);margin:10px 0 0"><i class="fas fa-lock"></i> Verified account details above cannot be changed here. Contact support to update them.</p>
                    </div>
                    <?php endif; ?>
                    <div class="ord-field">
                        <span class="ord-label">How would you like to receive this order? *</span>
                        <select name="fulfillment_method" class="ord-sel" id="fulfillmentMethod" onchange="switchFulfillmentMethod()" required>
                            <option value="delivery" <?= $selFulfillment === 'delivery' ? 'selected' : '' ?>>Delivery to Address</option>
                            <option value="pickup" <?= $selFulfillment === 'pickup' ? 'selected' : '' ?>>Pickup from Store (wholesale pickup pricing)</option>
                        </select>
                    </div>
                    <div id="deliveryAddressBlock">
                        <div class="ord-field">
                            <span class="ord-label">Delivery Address *</span>
                            <textarea name="delivery_address" rows="3" class="ord-input" id="deliveryAddressInput" required placeholder="Full delivery address including city, sub-city, area and landmark"><?= htmlspecialchars($editOrder ? ($editOrder['delivery_address'] ?? '') : ($_POST['delivery_address'] ?? ($customer['physical_address'] ?? ''))) ?></textarea>
                        </div>
                    </div>
                    <div id="pickupInfoBlock" style="display:none;padding:12px 14px;background:var(--primary-50,#eff6ff);border:1px dashed var(--primary-200,#bfdbfe);border-radius:10px;font-size:.84rem;color:var(--primary-800,#1e40af)">
                        <i class="fas fa-store"></i> You will be notified when your order is packed and ready for pickup at our store. Pickup orders use wholesale pricing where available.
                    </div>
                    <div class="ord-field">
                        <span class="ord-label">Order Notes (optional)</span>
                        <textarea name="order_notes" rows="2" class="ord-input" placeholder="Any instructions for this order (e.g. preferred delivery time, special handling)"><?= htmlspecialchars($editOrder ? ($editOrder['order_notes'] ?? '') : ($_POST['order_notes'] ?? '')) ?></textarea>
                    </div>
                </div>

                <!-- STEP 3 -->
                <div class="ord-panel ord-pane" data-step="3">
                    <h2><i class="fas fa-credit-card"></i> Step 3 · Payment Method</h2>
                    <div class="ord-pay-cards" id="payCards">
                        <label class="ord-pay-card" data-method="cash">
                            <input type="radio" name="payment_method" value="cash" <?= $selMethod === 'cash' ? 'checked' : '' ?>>
                            <span class="pc-icon"><i class="fas fa-money-bill-wave"></i></span>
                            <span class="pc-name">Cash</span>
                            <span class="pc-sub">Pay on pickup / delivery</span>
                        </label>
                        <label class="ord-pay-card" data-method="bank">
                            <input type="radio" name="payment_method" value="bank" <?= $selMethod === 'bank' ? 'checked' : '' ?>>
                            <span class="pc-icon"><i class="fas fa-university"></i></span>
                            <span class="pc-name">Bank Transfer</span>
                            <span class="pc-sub">Upload transfer reference</span>
                        </label>
                        <?php if ($creditSalesEnabled): ?>
                        <label class="ord-pay-card <?= $creditAvailable ? '' : 'is-disabled' ?>" data-method="credit">
                            <input type="radio" name="payment_method" value="credit" <?= $selMethod === 'credit' ? 'checked' : '' ?> <?= $creditAvailable ? '' : 'disabled' ?>>
                            <span class="pc-icon"><i class="fas fa-hand-holding-usd"></i></span>
                            <span class="pc-name">Credit / Pay Later</span>
                            <span class="pc-sub"><?= $creditAvailable ? 'Approved credit account' : 'Not available on your account' ?></span>
                        </label>
                        <?php endif; ?>
                        <label class="ord-pay-card" data-method="cbe_birr">
                            <input type="radio" name="payment_method" value="cbe_birr" <?= $selMethod === 'cbe_birr' ? 'checked' : '' ?>>
                            <span class="pc-icon"><i class="fas fa-mobile-alt"></i></span>
                            <span class="pc-name">CBE Birr</span>
                            <span class="pc-sub">Mobile transfer</span>
                        </label>
                        <label class="ord-pay-card" data-method="telebirr">
                            <input type="radio" name="payment_method" value="telebirr" <?= $selMethod === 'telebirr' ? 'checked' : '' ?>>
                            <span class="pc-icon"><i class="fas fa-wallet"></i></span>
                            <span class="pc-name">Telebirr</span>
                            <span class="pc-sub">Mobile wallet</span>
                        </label>
                    </div>

                    <div class="ord-paypanel" data-method="cash">
                        <p class="ord-note-box"><i class="fas fa-info-circle" style="color:var(--primary-600,#2563eb)"></i> No advance payment is required. Your payment status stays <strong>Pending</strong> until finance receives and verifies the cash. Cash is never marked received automatically.</p>
                        <div class="ord-field" style="margin-top:10px;margin-bottom:0">
                            <span class="ord-label">Payment Notes (optional)</span>
                            <input type="text" name="payment_detail" class="ord-input" placeholder="e.g. Will pay on delivery" value="<?= htmlspecialchars($editOrder ? ($editOrder['payment_detail'] ?? '') : ($_POST['payment_detail'] ?? '')) ?>">
                        </div>
                    </div>

                    <div class="ord-paypanel" data-method="bank">
                        <div class="ord-row">
                            <div class="ord-field"><span class="ord-label">Bank Name</span><input type="text" name="bank_name" class="ord-input" placeholder="e.g. Commercial Bank of Ethiopia" value="<?= htmlspecialchars($editOrder ? ($editOrder['bank_name'] ?? '') : ($_POST['bank_name'] ?? '')) ?>"></div>
                            <div class="ord-field"><span class="ord-label">Account Name</span><input type="text" name="account_name" class="ord-input" placeholder="Account holder name" value="<?= htmlspecialchars($editOrder ? ($editOrder['account_name'] ?? '') : ($_POST['account_name'] ?? '')) ?>"></div>
                        </div>
                        <div class="ord-row">
                            <div class="ord-field"><span class="ord-label">Account Number</span><input type="text" name="account_number" class="ord-input" placeholder="Account number" value="<?= htmlspecialchars($editOrder ? ($editOrder['account_number'] ?? '') : ($_POST['account_number'] ?? '')) ?>"></div>
                            <div class="ord-field"><span class="ord-label">Transfer Reference *</span><input type="text" name="transaction_ref" class="ord-input" placeholder="Transfer ref / receipt number" value="<?= htmlspecialchars($editOrder ? ($editOrder['transaction_ref'] ?? '') : ($_POST['transaction_ref'] ?? '')) ?>"></div>
                        </div>
                        <p class="ord-note-box"><i class="fas fa-clock" style="color:#d97706"></i> Bank transfers are verified by our finance team before your order is confirmed.</p>
                    </div>

                    <div class="ord-paypanel" data-method="cbe_birr">
                        <div class="ord-row">
                            <div class="ord-field"><span class="ord-label">Merchant Name</span><input type="text" name="merchant_name" class="ord-input" placeholder="Merchant name" value="<?= htmlspecialchars($editOrder ? ($editOrder['merchant_name'] ?? '') : ($_POST['merchant_name'] ?? '')) ?>"></div>
                            <div class="ord-field"><span class="ord-label">Merchant ID</span><input type="text" name="merchant_id" class="ord-input" placeholder="Merchant ID" value="<?= htmlspecialchars($editOrder ? ($editOrder['merchant_id'] ?? '') : ($_POST['merchant_id'] ?? '')) ?>"></div>
                        </div>
                        <div class="ord-field"><span class="ord-label">Transaction Reference *</span><input type="text" name="transaction_ref" class="ord-input" placeholder="CBE Birr transaction reference" value="<?= htmlspecialchars($editOrder ? ($editOrder['transaction_ref'] ?? '') : ($_POST['transaction_ref'] ?? '')) ?>"></div>
                    </div>

                    <div class="ord-paypanel" data-method="telebirr">
                        <div class="ord-row">
                            <div class="ord-field"><span class="ord-label">Phone Number *</span><input type="text" name="payment_detail" class="ord-input" placeholder="09XXXXXXXX or +2519XXXXXXXX" value="<?= htmlspecialchars($editOrder ? ($editOrder['payment_detail'] ?? '') : ($_POST['payment_detail'] ?? '')) ?>"></div>
                            <div class="ord-field"><span class="ord-label">Transaction Reference</span><input type="text" name="transaction_ref" class="ord-input" placeholder="Telebirr transaction ID" value="<?= htmlspecialchars($editOrder ? ($editOrder['transaction_ref'] ?? '') : ($_POST['transaction_ref'] ?? '')) ?>"></div>
                        </div>
                    </div>

                    <?php if ($creditSalesEnabled): ?>
                    <div class="ord-paypanel" data-method="credit">
                        <?php if ($creditAvailable): ?>
                            <div style="margin-bottom:12px">
                                <div style="display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:4px">
                                    <span>Credit Used</span>
                                    <span style="font-weight:700"><?= fmt($creditUsed) ?> / <?= fmt($creditLimit) ?></span>
                                </div>
                                <div class="credit-bar"><div class="credit-bar-fill" style="width:<?= $creditLimit > 0 ? min(($creditUsed / $creditLimit) * 100, 100) : 0 ?>%"></div></div>
                                <p style="font-size:.78rem;color:var(--neutral-500,#64748b);margin-top:5px">Available credit: <strong><?= fmt(max($creditLimit - $creditUsed, 0)) ?></strong></p>
                            </div>
                            <?php if ($activeAgreement): ?>
                                <div style="border:1px solid var(--neutral-200,#e2e8f0);border-radius:10px;padding:14px;background:#fff">
                                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;gap:8px;flex-wrap:wrap">
                                        <strong style="font-size:.88rem;color:var(--primary-900,#1e3a5f)"><i class="fas fa-file-contract"></i> <?= htmlspecialchars($activeAgreement['title'] ?: 'Credit Agreement') ?></strong>
                                        <span class="badge-soft">Version <?= (int) $activeAgreement['version'] ?></span>
                                    </div>
                                    <div style="font-size:.8rem;color:var(--neutral-600,#475569);margin-bottom:8px"><i class="fas fa-calendar-check"></i> Payment due within <strong><?= (int) $activeAgreement['payment_terms_days'] ?></strong> days of the invoice date.</div>
                                    <div style="font-size:.78rem;color:#334155;line-height:1.5;max-height:180px;overflow-y:auto;white-space:pre-wrap;background:var(--neutral-50,#f8fafc);border:1px solid var(--neutral-200,#e2e8f0);border-radius:8px;padding:10px;margin-bottom:10px"><?= htmlspecialchars($activeAgreement['content']) ?></div>
                                    <?php if ($activeAgreement['require_acceptance']): ?>
                                        <label style="display:flex;gap:8px;align-items:flex-start;font-size:.8rem;color:#334155;cursor:pointer">
                                            <input type="checkbox" name="agreement_accepted" value="1" required style="margin-top:2px;width:auto">
                                            <span>I have read and accept the current Credit Agreement (Version <?= (int) $activeAgreement['version'] ?>). My order is subject to these terms.</span>
                                        </label>
                                    <?php endif; ?>
                                    <input type="hidden" name="agreement_version_id" value="<?= (int) $activeAgreement['id'] ?>">
                                </div>
                            <?php endif; ?>
                            <p class="ord-note-box" style="margin-top:10px"><i class="fas fa-info-circle" style="color:var(--primary-600,#2563eb)"></i> Credit orders are checked against your approved limit and outstanding balance. Any configured credit markup is applied once and shown in the summary.</p>
                        <?php else: ?>
                            <p class="ord-note-box" style="color:#b91c1c"><i class="fas fa-ban"></i> Your account is not currently approved for credit purchases. Contact finance to apply.</p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="ord-paypanel" data-method="__upload" style="display:none"></div>
                    <div class="ord-field" style="margin-top:16px">
                        <span class="ord-label">Upload Payment Evidence (optional)</span>
                        <input type="file" name="payment_screenshot" accept="image/jpeg,image/png,image/webp,image/gif" class="ord-input" style="padding:9px">
                        <span style="font-size:.74rem;color:var(--neutral-500,#64748b)">JPEG, PNG, WebP or GIF up to 2 MB. Required for bank / mobile transfers where the system asks for it.</span>
                    </div>
                </div>

                <!-- STEP 4 -->
                <div class="ord-panel ord-pane" data-step="4">
                    <h2><i class="fas fa-clipboard-check"></i> Step 4 · Confirm &amp; Submit</h2>
                    <div id="confirmReview"></div>
                    <label class="confirm-check" style="margin-top:14px">
                        <input type="checkbox" name="confirm_order" id="confirmOrder" value="1" required>
                        <span>I confirm the products, quantities, delivery details and payment method above are correct and complete. I understand my order will be submitted for approval and finance verification, and that no payment is considered received until it is verified by finance.</span>
                    </label>
                    <div id="dupNote" style="display:none;margin-top:12px;padding:10px 12px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;color:#991b1b;font-size:.82rem"><i class="fas fa-exclamation-triangle"></i> This order was already submitted. Check My Orders.</div>
                </div>

                <div class="ord-nav">
                    <button type="button" class="ord-btn ord-btn-ghost" id="ordBack" onclick="ordGoBack()"><i class="fas fa-arrow-left"></i> Back</button>
                    <div style="display:flex;gap:10px;align-items:center">
                        <button type="button" class="ord-btn ord-btn-primary" id="ordNext" onclick="ordGoNext()">Continue <i class="fas fa-arrow-right"></i></button>
                        <button type="submit" class="ord-btn ord-btn-submit" id="ordSubmit" data-swal-exclude="true" style="display:none"><i class="fas fa-paper-plane"></i> <?= $editOrder ? 'Update Order' : 'Place Order' ?></button>
                    </div>
                </div>
                <?php if ($editOrder): ?>
                    <a href="index.php?page=my_orders" class="ord-cancel"><i class="fas fa-times"></i> Cancel edit</a>
                <?php endif; ?>
            </div>

            <aside class="ord-side">
                <div class="ord-panel" style="margin-bottom:0">
                    <h2><i class="fas fa-receipt"></i> Order Summary</h2>
                    <div id="summaryContent"></div>
                </div>
            </aside>
        </div>
    </form>
    <?php endif; ?>
</section>

<script>
var VAT_RATE = <?= number_format((float) $vatRate, 2, '.', '') ?>;
var CURRENCY = '<?= addslashes(SettingsService::currencySymbol()) ?>';
var itemIndex = <?= $editOrder && !empty($editOrderItems) ? count($editOrderItems) : 1 ?>;
var CURRENT_STEP = <?= (int) $activeStep ?>;
var SUBMITTED = false;

function money(n){ n = isFinite(n) ? n : 0; return CURRENCY + ' ' + (Math.round(n * 100) / 100).toFixed(2); }
function fmtNum(n){ return money(n); }
function fmtETB(n){ return money(n); }

function isPickupLane(){ var el = document.getElementById('fulfillmentMethod'); return (el ? el.value : 'delivery') === 'pickup'; }
function currentPayMethod(){ var r = document.querySelector('input[name="payment_method"]:checked'); return r ? r.value : 'cash'; }

function unitPrice(opt){
    if (!opt) return 0;
    var retail = parseFloat(opt.getAttribute('data-price') || 0);
    if (isPickupLane()) {
        var w = parseFloat(opt.getAttribute('data-wprice') || 0);
        if (w > 0) return w;
    }
    return retail;
}

function switchFulfillmentMethod(){
    var el = document.getElementById('fulfillmentMethod');
    var m = el ? el.value : 'delivery';
    var addr = document.getElementById('deliveryAddressBlock');
    var pick = document.getElementById('pickupInfoBlock');
    var inp = document.getElementById('deliveryAddressInput');
    if (addr) addr.style.display = (m === 'pickup') ? 'none' : '';
    if (pick) pick.style.display = (m === 'pickup') ? '' : 'none';
    if (inp) inp.required = (m !== 'pickup');
    document.querySelectorAll('.ord-item').forEach(function(row){ updatePrice(row); });
    recalcSummary();
}

function switchPaymentMethod(){
    var m = currentPayMethod();
    document.querySelectorAll('.ord-paypanel[data-method]').forEach(function(p){
        var active = p.getAttribute('data-method') === m;
        if (p.getAttribute('data-method') === '__upload') return;
        p.classList.toggle('is-active', active);
        p.querySelectorAll('input,select,textarea').forEach(function(el){
            if (el.type === 'radio') return;
            el.disabled = !active;
        });
    });
    document.querySelectorAll('.ord-pay-card').forEach(function(c){
        var mm = c.getAttribute('data-method');
        if (mm === '__upload') return;
        c.classList.toggle('is-selected', mm === m);
    });
    // Required-by-method reference fields.
    var reqMap = { bank: ['transaction_ref'], cbe_birr: ['transaction_ref'], telebirr: ['payment_detail'] };
    var reqs = reqMap[m] || [];
    var panel = document.querySelector('.ord-paypanel[data-method="' + m + '"]');
    if (panel) {
        panel.querySelectorAll('input').forEach(function(el){
            if (!el.name) return;
            el.required = reqs.indexOf(el.name) !== -1;
        });
    }
    var agree = document.querySelector('input[name="agreement_accepted"]');
    if (agree) agree.required = (m === 'credit');
    recalcSummary();
}

var PRODUCT_SEARCH_TIMER = null;
var PRODUCT_LAST_QUERY = '';
var PRODUCT_LAST_RESULTS = [];
var PRODUCT_SEARCH_SEQ = 0;

function escAttr(s){ return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function productOptionHtml(p){
    var name = p.name || '';
    var brand = p.brand || '';
    var strength = p.strength || '';
    var label = name + (brand ? ' (' + brand + ')' : '') + (strength ? ' - ' + strength : '') + ' — ' + money(parseFloat(p.price || 0));
    var search = (name + ' ' + brand + ' ' + (p.generic || '') + ' ' + strength + ' ' + (p.barcode || '')).toLowerCase();
    return '<option value="' + parseInt(p.id, 10) + '"'
        + ' data-price="' + parseFloat(p.price || 0) + '"'
        + ' data-wprice="' + parseFloat(p.wholesale_price || 0) + '"'
        + ' data-stock="' + parseInt(p.stock || 0, 10) + '"'
        + ' data-name="' + escAttr(name) + '"'
        + ' data-brand="' + escAttr(brand) + '"'
        + ' data-strength="' + escAttr(strength) + '"'
        + ' data-generic="' + escAttr(p.generic || '') + '"'
        + ' data-form="' + escAttr(p.form || '') + '"'
        + ' data-search="' + escAttr(search) + '"'
        + '>' + escAttr(label) + '</option>';
}

function stockBadge(stock){
    var n = parseInt(stock || 0, 10);
    if (n <= 0) return '<span class="ord-suggest-stock ord-out"><i class="fas fa-circle-xmark"></i> Out of stock</span>';
    if (n <= 10) return '<span class="ord-suggest-stock" style="color:#b45309"><i class="fas fa-triangle-exclamation"></i> Low stock · ' + n + '</span>';
    return '<span class="ord-suggest-stock ord-in"><i class="fas fa-circle-check"></i> In stock · ' + n + '</span>';
}

function suggestionItemHtml(p){
    var name = p.name || '';
    var bits = [];
    if (p.brand) bits.push(p.brand);
    if (p.generic && p.generic !== name) bits.push(p.generic);
    if (p.strength) bits.push(p.strength);
    if (p.form) bits.push(p.form);
    return '<button type="button" class="ord-suggest-item" role="option" data-pid="' + parseInt(p.id, 10) + '">'
        + '<span class="ord-suggest-main"><span class="ord-suggest-name">' + escAttr(name) + '</span>'
        + '<span class="ord-suggest-meta">' + escAttr(bits.join(' · ')) + '</span></span>'
        + '<span class="ord-suggest-price">' + money(parseFloat(p.price || 0)) + stockBadge(p.stock) + '</span>'
        + '</button>';
}

function showSuggestionsBox(html){
    var box = document.getElementById('productSuggestions');
    if (!box) return;
    box.innerHTML = html;
    box.classList.add('is-open');
}

function closeSuggestions(){
    var box = document.getElementById('productSuggestions');
    if (box) box.classList.remove('is-open');
}

function renderSuggestions(results, q, matchedBy){
    if (!results.length) {
        showSuggestionsBox('<div class="ord-suggest-empty"><strong>No products match “' + escAttr(q) + '”.</strong><br>Check the spelling, try a generic name, or clear the box to browse all products.</div>');
        return;
    }
    var head;
    if (q) {
        head = (matchedBy === 'similar' ? 'Close matches' : results.length + ' match' + (results.length === 1 ? '' : 'es')) + ' for “' + escAttr(q) + '”';
    } else {
        head = results.length + ' product' + (results.length === 1 ? '' : 's') + ' available — click one to add it';
    }
    showSuggestionsBox('<div class="ord-suggest-head">' + head + '</div>' + results.map(suggestionItemHtml).join(''));
}

function onProductSearch(value){
    if (PRODUCT_SEARCH_TIMER) clearTimeout(PRODUCT_SEARCH_TIMER);
    var q = (value || '').trim();
    var hint = document.getElementById('productSearchHint');
    if (q.length < 2) {
        if (hint) hint.style.display = '';
        if (q === '') {
            PRODUCT_SEARCH_TIMER = setTimeout(function(){ searchProducts(''); }, 150);
        } else if (PRODUCT_LAST_RESULTS.length) {
            renderSuggestions(PRODUCT_LAST_RESULTS, '', 'exact');
        }
        return;
    }
    if (hint) hint.style.display = 'none';
    PRODUCT_SEARCH_TIMER = setTimeout(function(){ searchProducts(q); }, 220);
}

function searchProducts(q){
    PRODUCT_LAST_QUERY = q;
    var seq = ++PRODUCT_SEARCH_SEQ;
    showSuggestionsBox('<div class="ord-suggest-empty"><i class="fas fa-spinner fa-spin"></i> Searching…</div>');
    fetch('ajax_search_products.php?q=' + encodeURIComponent(q), {
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        credentials: 'same-origin'
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if (seq !== PRODUCT_SEARCH_SEQ) return; // a newer search already started
        var results = (data && data.results) || [];
        PRODUCT_LAST_RESULTS = results;
        applyProductOptions(results);
        renderSuggestions(results, q, data && data.matched_by);
    })
    .catch(function(){
        if (seq !== PRODUCT_SEARCH_SEQ) return;
        showSuggestionsBox('<div class="ord-suggest-empty"><strong>Search is unavailable right now.</strong><br>You can still pick a product from the dropdown.</div>');
    });
}

function applyProductOptions(results){
    var optionsHtml = results.map(productOptionHtml).join('');
    document.querySelectorAll('.product-select').forEach(function(sel){
        var current = sel.value;
        var savedHtml = '';
        if (current && sel.selectedIndex >= 0 && sel.options[sel.selectedIndex]) {
            savedHtml = sel.options[sel.selectedIndex].outerHTML;
        }
        sel.innerHTML = '<option value="">Select product</option>' + optionsHtml;
        if (current && !sel.querySelector('option[value="' + current + '"]')) {
            sel.insertAdjacentHTML('beforeend', savedHtml);
        }
        sel.value = current || '';
    });
}

function chooseSuggestion(pid){
    var product = null;
    for (var i = 0; i < PRODUCT_LAST_RESULTS.length; i++) {
        if (String(PRODUCT_LAST_RESULTS[i].id) === String(pid)) { product = PRODUCT_LAST_RESULTS[i]; break; }
    }
    if (!product) return;
    // Type-to-add fills the first row with no product yet (or creates a row).
    var sel = null;
    var selects = document.querySelectorAll('.product-select');
    for (var j = 0; j < selects.length; j++) { if (!selects[j].value) { sel = selects[j]; break; } }
    if (!sel) { addItem(); var all = document.querySelectorAll('.product-select'); sel = all[all.length - 1]; }
    if (!sel) return;
    var value = String(parseInt(product.id, 10));
    if (!sel.querySelector('option[value="' + value + '"]')) {
        sel.insertAdjacentHTML('beforeend', productOptionHtml(product));
    }
    sel.value = value;
    sel.dispatchEvent(new Event('change', { bubbles: true }));
    var row = sel.closest('.ord-item');
    if (row) updatePrice(row);
    closeSuggestions();
    var input = document.getElementById('productSearch');
    if (input) input.value = '';
    var hint = document.getElementById('productSearchHint');
    if (hint) hint.style.display = '';
    var qty = row ? row.querySelector('.qty-input') : null;
    if (qty) { try { qty.focus(); qty.select(); } catch (e) {} }
}

// Clicking a suggestion fills the first empty row; clicking elsewhere closes it.
document.addEventListener('click', function(e){
    var item = e.target && e.target.closest ? e.target.closest('.ord-suggest-item') : null;
    if (item) {
        e.preventDefault();
        chooseSuggestion(item.getAttribute('data-pid'));
        return;
    }
    var box = document.getElementById('productSuggestions');
    var input = document.getElementById('productSearch');
    if (box && box.classList.contains('is-open') && !box.contains(e.target) && e.target !== input) {
        closeSuggestions();
    }
});

// Backwards-compatible alias for any older inline handler.
function filterProductSelects(query){ onProductSearch(query); }

function updatePrice(row){
    if (!row) return;
    if (!row.classList || !row.classList.contains('ord-item')) row = row.closest('.ord-item');
    if (!row) return;
    var sel = row.querySelector('.product-select');
    if (!sel) return;
    var opt = sel.options[sel.selectedIndex];
    var price = unitPrice(opt);
    var stock = parseInt(opt ? (opt.getAttribute('data-stock') || 0) : 0, 10);
    var qtyInput = row.querySelector('.qty-input');
    var qty = parseInt(qtyInput.value || 0, 10);
    var disc = parseFloat(row.querySelector('.disc-input').value || 0);
    if (stock > 0) {
        qtyInput.max = stock;
        if (qty > stock) { qtyInput.value = stock; qty = stock; }
    } else {
        qtyInput.removeAttribute('max');
    }
    var priceEl = row.querySelector('[data-price-display]');
    var totalEl = row.querySelector('[data-line-total]');
    var hint = row.querySelector('.stock-hint');
    if (!sel.value) {
        if (priceEl) priceEl.textContent = '—';
        if (totalEl) totalEl.textContent = '—';
        if (hint) hint.textContent = '';
    } else {
        if (priceEl) priceEl.textContent = money(price);
        if (totalEl) totalEl.textContent = money((price * qty) - disc);
        if (hint) hint.innerHTML = (stock > 0) ? '<span class="ord-in"><i class="fas fa-check"></i> In stock</span>' : '<span class="ord-out"><i class="fas fa-times"></i> Out of stock</span>';
    }
    recalcSummary();
}

function recalcSummary(){
    var rows = document.querySelectorAll('.ord-item');
    var subtotal = 0, totalLineDisc = 0, totalQty = 0, itemCount = 0;
    rows.forEach(function(row){
        var sel = row.querySelector('.product-select');
        if (!sel || !sel.value) return;
        var opt = sel.options[sel.selectedIndex];
        var qty = parseInt(row.querySelector('.qty-input').value || 0, 10);
        if (qty <= 0) return;
        subtotal += unitPrice(opt) * qty;
        totalLineDisc += parseFloat(row.querySelector('.disc-input').value || 0);
        totalQty += qty;
        itemCount++;
    });
    var discPct = parseFloat(document.getElementById('discountPercent').value || 0);
    var totalDisc = totalLineDisc;
    if (discPct > 0 && discPct <= 100) totalDisc = Math.round(subtotal * discPct) / 100;
    var taxable = Math.max(subtotal - totalDisc, 0);
    var vat = Math.round(taxable * VAT_RATE) / 100;
    var grand = taxable + vat;
    var el = document.getElementById('summaryContent');
    if (!el) return;
    if (itemCount === 0) {
        el.innerHTML = '<div class="ord-empty"><i class="fas fa-shopping-basket"></i>Add products to see your summary</div>';
        return;
    }
    var pmLabels = { cash: 'Cash', bank: 'Bank Transfer', cbe_birr: 'CBE Birr', telebirr: 'Telebirr', credit: 'Credit / Pay Later' };
    var pm = currentPayMethod();
    var html = '';
    html += '<div class="summary-row"><span>Items</span><span>' + itemCount + ' product' + (itemCount > 1 ? 's' : '') + ' · ' + totalQty + ' unit' + (totalQty > 1 ? 's' : '') + '</span></div>';
    html += '<div class="summary-row"><span>Subtotal</span><span>' + money(subtotal) + '</span></div>';
    if (totalDisc > 0) html += '<div class="summary-row" style="color:#dc2626"><span>Discount</span><span style="color:#dc2626">- ' + money(totalDisc) + '</span></div>';
    html += '<div class="summary-row"><span>VAT (' + VAT_RATE + '%)</span><span>' + money(vat) + '</span></div>';
    html += '<div class="summary-row total"><span>Total (ETB)</span><span>' + money(grand) + '</span></div>';
    html += '<div style="margin-top:12px;padding:11px;background:var(--neutral-50,#f8fafc);border-radius:10px;font-size:.8rem">';
    html += '<div style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="color:var(--neutral-500,#64748b)">Fulfillment</span><span style="font-weight:700">' + (isPickupLane() ? 'Pickup' : 'Delivery') + '</span></div>';
    html += '<div style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="color:var(--neutral-500,#64748b)">Payment</span><span style="font-weight:700">' + (pmLabels[pm] || pm) + '</span></div>';
    html += '<div style="display:flex;justify-content:space-between"><span style="color:var(--neutral-500,#64748b)">Status</span><span style="color:#b45309;font-weight:700">Pending verification</span></div>';
    html += '</div>';
    el.innerHTML = html;
}

function addItem(){
    var container = document.getElementById('orderItems');
    var firstSel = document.querySelector('.product-select');
    if (!container || !firstSel) return;
    var idx = itemIndex++;
    var optionsHtml = firstSel.innerHTML.replace(/ selected(="[^"]*")?/g, '');
    var html = '<div class="ord-item" data-index="' + idx + '">';
    html += '<div class="ord-item-head"><select name="request_items[' + idx + '][product_id]" class="ord-sel product-select" required onchange="updatePrice(this)">' + optionsHtml + '</select>';
    html += '<button type="button" class="ord-item-remove" onclick="removeItem(this)" title="Remove product"><i class="fas fa-trash-alt"></i></button></div>';
    html += '<div class="ord-item-grid">';
    html += '<div class="ord-field"><span class="ord-label">Quantity</span><input type="number" name="request_items[' + idx + '][quantity]" value="1" min="1" class="ord-input qty-input" onchange="updatePrice(this.closest(\'.ord-item\'))" onkeyup="updatePrice(this.closest(\'.ord-item\'))" required><div class="stock-hint"></div></div>';
    html += '<div class="ord-field"><span class="ord-label">Unit Price</span><div class="ord-static" data-price-display>—</div></div>';
    html += '<div class="ord-field"><span class="ord-label">Line Discount (ETB)</span><input type="number" name="request_items[' + idx + '][line_discount]" value="0" min="0" step="0.01" class="ord-input disc-input" onchange="updatePrice(this.closest(\'.ord-item\'))" onkeyup="updatePrice(this.closest(\'.ord-item\'))"></div>';
    html += '<div class="ord-field"><span class="ord-label">Line Total</span><div class="ord-static ord-line-total" data-line-total>—</div></div>';
    html += '</div></div>';
    container.insertAdjacentHTML('beforeend', html);
    recalcSummary();
}

function removeItem(btn){
    var items = document.querySelectorAll('.ord-item');
    if (items.length <= 1) { showMsg('You need at least one product in the order.', 'error'); return; }
    btn.closest('.ord-item').remove();
    recalcSummary();
}

function showMsg(msg, type){
    if (typeof showToast === 'function') { try { showToast(msg, type || 'info'); return; } catch (e) {} }
    alert(msg);
}

function ordShowStep(n){
    CURRENT_STEP = n;
    document.querySelectorAll('.ord-pane[data-step]').forEach(function(p){
        p.classList.toggle('is-active', parseInt(p.getAttribute('data-step'), 10) === n);
    });
    document.querySelectorAll('.ord-step-item').forEach(function(s){
        var sn = parseInt(s.getAttribute('data-step'), 10);
        s.classList.toggle('is-active', sn === n);
        s.classList.toggle('is-done', sn < n);
    });
    var back = document.getElementById('ordBack');
    var next = document.getElementById('ordNext');
    var submit = document.getElementById('ordSubmit');
    if (back) back.style.visibility = n > 1 ? 'visible' : 'hidden';
    if (next) next.style.display = n < 4 ? '' : 'none';
    if (submit) submit.style.display = n === 4 ? '' : 'none';
    var cs = document.getElementById('current_step');
    if (cs) cs.value = n;
    if (n === 4) buildConfirmation();
    var main = document.getElementById('ordMain');
    if (main) { try { window.scrollTo({ top: Math.max(main.offsetTop - 70, 0), behavior: 'smooth' }); } catch (e) { window.scrollTo(0, 0); } }
}

function ordValidateStep(step){
    var pane = document.querySelector('.ord-pane[data-step="' + step + '"]');
    if (!pane) return true;
    var ok = true, first = null;
    pane.querySelectorAll('input,select,textarea').forEach(function(el){
        if (el.disabled || el.type === 'radio') return;
        if (!el.checkValidity()) {
            ok = false;
            el.classList.add('ord-invalid');
            if (!first) first = el;
        } else {
            el.classList.remove('ord-invalid');
        }
    });
    if (step === 3 && !document.querySelector('input[name="payment_method"]:checked')) {
        ok = false;
        showMsg('Please choose a payment method.', 'error');
    }
    if (!ok) {
        if (first) { first.scrollIntoView({ behavior: 'smooth', block: 'center' }); try { first.focus(); } catch (e) {} }
        if (first || step !== 3) showMsg('Please complete the highlighted fields before continuing.', 'error');
    }
    return ok;
}

function ordGoNext(){
    if (!ordValidateStep(CURRENT_STEP)) return;
    ordShowStep(Math.min(4, CURRENT_STEP + 1));
}
function ordGoBack(){ ordShowStep(Math.max(1, CURRENT_STEP - 1)); }

function buildConfirmation(){
    var rows = document.querySelectorAll('.ord-item');
    var html = '';
    var lines = '';
    var subtotal = 0, totalLineDisc = 0, totalQty = 0, itemCount = 0;
    rows.forEach(function(row){
        var sel = row.querySelector('.product-select');
        if (!sel || !sel.value) return;
        var opt = sel.options[sel.selectedIndex];
        var qty = parseInt(row.querySelector('.qty-input').value || 0, 10);
        if (qty <= 0) return;
        var price = unitPrice(opt);
        var disc = parseFloat(row.querySelector('.disc-input').value || 0);
        subtotal += price * qty; totalLineDisc += disc; totalQty += qty; itemCount++;
        var meta = [opt.getAttribute('data-generic'), opt.getAttribute('data-strength'), opt.getAttribute('data-form')].filter(Boolean).join(' · ');
        lines += '<tr><td><strong>' + esc(opt.getAttribute('data-name') || opt.textContent) + '</strong>' + (meta ? '<div style="font-size:.72rem;color:#64748b">' + esc(meta) + '</div>' : '') + '</td>';
        lines += '<td>' + qty + '</td><td>' + money(price) + '</td><td style="font-weight:700">' + money(price * qty - disc) + '</td></tr>';
    });
    if (itemCount === 0) { html = '<div class="ord-empty">No products selected.</div>'; }
    else {
        var discPct = parseFloat(document.getElementById('discountPercent').value || 0);
        var totalDisc = totalLineDisc;
        if (discPct > 0 && discPct <= 100) totalDisc = Math.round(subtotal * discPct) / 100;
        var taxable = Math.max(subtotal - totalDisc, 0);
        var vat = Math.round(taxable * VAT_RATE) / 100;
        var grand = taxable + vat;
        var pmLabels = { cash: 'Cash', bank: 'Bank Transfer', cbe_birr: 'CBE Birr', telebirr: 'Telebirr', credit: 'Credit / Pay Later' };
        var pm = currentPayMethod();
        html += '<div class="ord-review-tbl-wrap" style="overflow-x:auto"><table class="ord-review-tbl"><thead><tr><th>Product</th><th>Qty</th><th>Unit Price</th><th>Line Total</th></tr></thead><tbody>' + lines + '</tbody></table></div>';
        html += '<div class="summary-row"><span>Subtotal</span><span>' + money(subtotal) + '</span></div>';
        if (totalDisc > 0) html += '<div class="summary-row" style="color:#dc2626"><span>Discount</span><span style="color:#dc2626">- ' + money(totalDisc) + '</span></div>';
        html += '<div class="summary-row"><span>VAT (' + VAT_RATE + '%)</span><span>' + money(vat) + '</span></div>';
        html += '<div class="summary-row total"><span>Total (ETB)</span><span>' + money(grand) + '</span></div>';
        // Delivery
        var ful = isPickupLane() ? 'Pickup from store' : 'Delivery';
        var addr = (document.getElementById('deliveryAddressInput') || {}).value || '';
        var notes = (document.querySelector('textarea[name="order_notes"]') || {}).value || '';
        html += '<div style="margin-top:14px;padding:12px;border:1px solid var(--neutral-200,#e2e8f0);border-radius:10px;font-size:.82rem">';
        html += '<div style="font-weight:800;color:var(--primary-900,#1e3a5f);margin-bottom:6px"><i class="fas fa-truck"></i> Delivery Details</div>';
        html += '<div style="display:flex;justify-content:space-between;padding:2px 0"><span style="color:#64748b">Method</span><span style="font-weight:700">' + esc(ful) + '</span></div>';
        if (!isPickupLane()) html += '<div style="display:flex;justify-content:space-between;padding:2px 0;gap:12px"><span style="color:#64748b">Address</span><span style="font-weight:600;text-align:right">' + esc(addr || '—') + '</span></div>';
        if (notes.trim() !== '') html += '<div style="display:flex;justify-content:space-between;padding:2px 0;gap:12px"><span style="color:#64748b">Notes</span><span style="font-weight:600;text-align:right">' + esc(notes) + '</span></div>';
        html += '</div>';
        // Payment
        var ref = (document.querySelector('.ord-paypanel[data-method="' + pm + '"] input[name="transaction_ref"]') || {}).value || '';
        html += '<div style="margin-top:10px;padding:12px;border:1px solid var(--neutral-200,#e2e8f0);border-radius:10px;font-size:.82rem">';
        html += '<div style="font-weight:800;color:var(--primary-900,#1e3a5f);margin-bottom:6px"><i class="fas fa-credit-card"></i> Payment</div>';
        html += '<div style="display:flex;justify-content:space-between;padding:2px 0"><span style="color:#64748b">Method</span><span style="font-weight:700">' + esc(pmLabels[pm] || pm) + '</span></div>';
        if (ref) html += '<div style="display:flex;justify-content:space-between;padding:2px 0"><span style="color:#64748b">Reference</span><span style="font-weight:600">' + esc(ref) + '</span></div>';
        html += '<div style="display:flex;justify-content:space-between;padding:2px 0"><span style="color:#64748b">Status</span><span style="color:#b45309;font-weight:700">Pending finance verification</span></div>';
        html += '</div>';
    }
    document.getElementById('confirmReview').innerHTML = html;
}
function esc(s){ return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

document.addEventListener('DOMContentLoaded', function(){
    // Payment cards click -> switch panel.
    document.querySelectorAll('.ord-pay-card').forEach(function(card){
        card.addEventListener('click', function(){
            var radio = card.querySelector('input[type="radio"]');
            if (radio && !radio.disabled) { radio.checked = true; switchPaymentMethod(); }
        });
    });
    document.querySelectorAll('input[name="payment_method"]').forEach(function(r){
        r.addEventListener('change', switchPaymentMethod);
    });

    switchPaymentMethod();
    switchFulfillmentMethod();
    document.querySelectorAll('.ord-item').forEach(function(row){ updatePrice(row); });
    recalcSummary();
    ordShowStep(CURRENT_STEP);

    // Reopen the suggestion panel when returning to the search box.
    var searchInput = document.getElementById('productSearch');
    if (searchInput) {
        searchInput.addEventListener('focus', function(){
            var box = document.getElementById('productSuggestions');
            if (box && box.innerHTML.trim() !== '' && this.value.trim() !== '') box.classList.add('is-open');
        });
        searchInput.addEventListener('keydown', function(e){
            if (e.key === 'Escape') closeSuggestions();
        });
    }

    <?php if ($duplicateSubmit): ?>
    var dn = document.getElementById('dupNote'); if (dn) dn.style.display = 'block';
    <?php endif; ?>

    // Final submit: guard against duplicate submissions + clear messaging.
    var orderForm = document.getElementById('orderForm');
    if (orderForm) {
        orderForm.addEventListener('submit', function(e){
            if (SUBMITTED) { e.preventDefault(); return; }
            // Validate every visible required field across all steps.
            var invalid = null;
            ['1','2','3'].forEach(function(s){
                var pane = document.querySelector('.ord-pane[data-step="' + s + '"]');
                if (!pane) return;
                pane.querySelectorAll('input,select,textarea').forEach(function(el){
                    if (el.disabled || el.type === 'radio') return;
                    if (!el.checkValidity()) { el.classList.add('ord-invalid'); if (!invalid) { invalid = el; invalid.setAttribute('data-bad-step', s); } }
                    else el.classList.remove('ord-invalid');
                });
            });
            var confirmEl = document.getElementById('confirmOrder');
            if (confirmEl && !confirmEl.checked) { invalid = confirmEl; }
            if (invalid) {
                e.preventDefault();
                var step = parseInt(invalid.getAttribute('data-bad-step') || '4', 10);
                if (CURRENT_STEP !== step) ordShowStep(step);
                showMsg('Please complete the highlighted fields before placing your order.', 'error');
                return;
            }
            SUBMITTED = true;
            var btn = document.getElementById('ordSubmit');
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...'; }
        });
    }
});
</script>
