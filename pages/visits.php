<?php
requirePermission('visits', 'view');
require_once __DIR__ . '/../includes/auto_migrate.php';
ensureSalesRepMigration(getPDO());
ensureSalespersonOrderApprovalMigration(getPDO());
$pdo = getPDO();
$messages = [];
$errors = [];

$activeTab = $_GET['tab'] ?? 'visits';

$salespeople = $pdo->query("SELECT id, name FROM salespeople WHERE status = 'active' ORDER BY name")->fetchAll();
$customers = $pdo->query("SELECT id, name, phone FROM customers ORDER BY name")->fetchAll();
$products = $pdo->query('SELECT id, name, price, wholesale_price, stock_quantity FROM products ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    // Record Visit
    if (isset($_POST['save_visit'])) {
        $salespersonId = (int) ($_POST['salesperson_id'] ?? 0);
        $customerId = (int) ($_POST['customer_id'] ?? 0);
        $visitDate = $_POST['visit_date'] ?? date('Y-m-d');
        $visitType = $_POST['visit_type'] ?? 'initial';
        $notes = trim($_POST['notes'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $followUpDate = $_POST['follow_up_date'] ?? null;
        $status = $_POST['status'] ?? 'completed';

        if ($salespersonId <= 0) {
            $errors[] = 'Please select a salesperson.';
        } else {
            $customerName = null;
            if ($customerId > 0) {
                $cst = $pdo->prepare('SELECT name FROM customers WHERE id = :id');
                $cst->execute(['id' => $customerId]);
                $customerName = $cst->fetchColumn();
            } else {
                $customerName = trim($_POST['customer_name_manual'] ?? '');
            }
            $stmt = $pdo->prepare('INSERT INTO visits (salesperson_id, customer_id, customer_name, visit_date, visit_type, notes, location, follow_up_date, status) VALUES (:spid, :cid, :cname, :vd, :vt, :notes, :loc, :fud, :st)');
            $stmt->execute([
                'spid' => $salespersonId,
                'cid' => $customerId > 0 ? $customerId : null,
                'cname' => $customerName ?: null,
                'vd' => $visitDate,
                'vt' => $visitType,
                'notes' => $notes ?: null,
                'loc' => $location ?: null,
                'fud' => $followUpDate ?: null,
                'st' => $status
            ]);
            $messages[] = ['type' => 'success', 'text' => 'Visit recorded.'];
            $activeTab = 'visits';
        }
    }

    // Register Customer
    if (isset($_POST['save_customer'])) {
        $salespersonId = (int) ($_POST['salesperson_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $customerType = $_POST['customer_type'] ?? null;
        $licenseNumber = trim($_POST['license_number'] ?? '');
        $tinNumber = trim($_POST['tin_number'] ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['physical_address'] ?? '');
        $creditLimit = (float) ($_POST['credit_limit'] ?? 0);
        $paymentTerms = trim($_POST['payment_terms'] ?? '');

        if ($name === '') {
            $errors[] = 'Customer name is required.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO customers (name, customer_type, license_number, tin_number, contact_person, phone, email, physical_address, credit_limit, payment_terms, customer_status, registered_by, created_at) VALUES (:name, :ctype, :lic, :tin, :contact, :phone, :email, :addr, :cl, :pt, :cstatus, :rb, NOW())');
            $stmt->execute([
                'name' => $name,
                'ctype' => $customerType ?: null,
                'lic' => $licenseNumber ?: null,
                'tin' => $tinNumber ?: null,
                'contact' => $contactPerson ?: null,
                'phone' => $phone ?: null,
                'email' => $email ?: null,
                'addr' => $address ?: null,
                'cl' => $creditLimit,
                'pt' => $paymentTerms ?: null,
                'cstatus' => 'active',
                'rb' => $salespersonId > 0 ? $salespersonId : null
            ]);
            $newCustomerId = (int) $pdo->lastInsertId();
            $messages[] = ['type' => 'success', 'text' => 'Customer "' . htmlspecialchars($name) . '" registered.'];

            // Auto-create new_customer commission
            if ($salespersonId > 0) {
                $spStmt = $pdo->prepare('SELECT new_customer_bonus FROM salespeople WHERE id = :id');
                $spStmt->execute(['id' => $salespersonId]);
                $bonus = (float) $spStmt->fetchColumn();
                if ($bonus > 0) {
                    $insComm = $pdo->prepare('INSERT INTO commissions (salesperson_id, commission_type, reference_type, reference_id, commission_amount, status, notes, created_at) VALUES (:spid, \'new_customer\', \'customer\', :refid, :amt, \'pending\', :notes, NOW())');
                    $insComm->execute([
                        'spid' => $salespersonId,
                        'refid' => $newCustomerId,
                        'amt' => $bonus,
                        'notes' => 'Auto: New customer registered - ' . $name
                    ]);
                }
            }
            $activeTab = 'register';
        }
    }

    // Create Order
    if (isset($_POST['save_order'])) {
        $salespersonId = (int) ($_POST['salesperson_id'] ?? 0);
        $customerId = (int) ($_POST['customer_id'] ?? 0);
        $orderDate = $_POST['order_date'] ?? date('Y-m-d');
        $items = $_POST['order_items'] ?? [];
        $deliveryAddress = trim($_POST['delivery_address'] ?? '');
        $notes = trim($_POST['order_notes'] ?? '');
        $fulfillmentMethod = ($_POST['fulfillment_method'] ?? 'delivery') === 'pickup' ? 'pickup' : 'delivery';

        if ($customerId <= 0) {
            $errors[] = 'Please select a customer.';
        } elseif ($fulfillmentMethod === 'delivery' && $deliveryAddress === '') {
            $errors[] = 'Delivery orders require a delivery address. Choose Pickup or enter a delivery address.';
        } else {
            $lineTotals = [];
            foreach ($items as $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                $quantity = (int) ($item['quantity'] ?? 0);
                if ($productId > 0 && $quantity > 0) {
                    $lineTotals[] = ['product_id' => $productId, 'quantity' => $quantity];
                }
            }

            if (empty($lineTotals)) {
                $errors[] = 'Add at least one product with quantity.';
            } else {
                $pdo->beginTransaction();
                try {
                    $priceByProduct = [];
                    foreach ($lineTotals as $line) {
                        $stmt = $pdo->prepare('SELECT price, wholesale_price FROM products WHERE id = :id');
                        $stmt->execute(['id' => $line['product_id']]);
                        $row = $stmt->fetch();
                        if ($row === false) throw new RuntimeException('Invalid product.');
                        $unit = (float) $row['price'];
                        if ($fulfillmentMethod === 'pickup' && (float) $row['wholesale_price'] > 0) {
                            $unit = (float) $row['wholesale_price'];
                        }
                        $priceByProduct[$line['product_id']] = $unit;
                    }

                    $total = 0;
                    foreach ($lineTotals as $line) {
                        $total += $priceByProduct[$line['product_id']] * $line['quantity'];
                    }

                    // Use first admin user as placed_by
                    $adminUser = $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1")->fetchColumn();

                    $stmt = $pdo->prepare("INSERT INTO customer_orders (customer_id, placed_by_user_id, salesperson_id, order_date, status, total_amount, payment_method, payment_detail, delivery_address, fulfillment_method, approval_stage, created_at) VALUES (:cid, :uid, :spid, :od, 'pending', :total, 'bank', 'Registered by sales rep', :da, :fm, 'admin', NOW())");
                    $stmt->execute([
                        'cid' => $customerId,
                        'uid' => $adminUser,
                        'spid' => $salespersonId > 0 ? $salespersonId : null,
                        'od' => $orderDate,
                        'total' => $total,
                        'da' => $deliveryAddress ?: null,
                        'fm' => $fulfillmentMethod,
                    ]);
                    $orderId = (int) $pdo->lastInsertId();

                    $ins = $pdo->prepare('INSERT INTO customer_order_items (customer_order_id, product_id, quantity, unit_price) VALUES (:oid, :pid, :qty, :price)');
                    foreach ($lineTotals as $line) {
                        $ins->execute([
                            'oid' => $orderId,
                            'pid' => $line['product_id'],
                            'qty' => $line['quantity'],
                            'price' => $priceByProduct[$line['product_id']]
                        ]);
                    }
                    $pdo->commit();
                    $messages[] = ['type' => 'success', 'text' => 'Order #' . $orderId . ' created and submitted for admin approval.'];
                    $activeTab = 'orders';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errors[] = $e->getMessage();
                }
            }
        }
    }

    // Delete Visit
    if (isset($_POST['delete_visit'])) {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM visits WHERE id = :id')->execute(['id' => $id]);
            $messages[] = ['type' => 'success', 'text' => 'Visit deleted.'];
        }
    }
}

// Fetch visits
$filterSp = (int) ($_GET['sp_id'] ?? 0);
$filterDateFrom = trim($_GET['date_from'] ?? '');
$filterDateTo = trim($_GET['date_to'] ?? '');

$vWhere = ['1=1'];
$vParams = [];
if ($filterSp > 0) { $vWhere[] = 'v.salesperson_id = :spid'; $vParams['spid'] = $filterSp; }
if ($filterDateFrom !== '') { $vWhere[] = 'v.visit_date >= :df'; $vParams['df'] = $filterDateFrom; }
if ($filterDateTo !== '') { $vWhere[] = 'v.visit_date <= :dt'; $vParams['dt'] = $filterDateTo; }

$visits = $pdo->prepare("SELECT v.*, sp.name AS salesperson_name FROM visits v LEFT JOIN salespeople sp ON v.salesperson_id = sp.id WHERE " . implode(' AND ', $vWhere) . " ORDER BY v.visit_date DESC, v.id DESC LIMIT 100");
$visits->execute($vParams);
$visits = $visits->fetchAll();

// Recent orders placed by salespeople
$recentOrders = $pdo->query("SELECT co.*, sp.name AS salesperson_name, c.name AS customer_name FROM customer_orders co LEFT JOIN salespeople sp ON co.salesperson_id = sp.id LEFT JOIN customers c ON co.customer_id = c.id WHERE co.salesperson_id IS NOT NULL ORDER BY co.created_at DESC LIMIT 20")->fetchAll();
?>
<section>
    <h1>Visits &amp; Customer Registration</h1>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
    <?php endif; ?>
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <div class="tab-bar">
        <button class="tab-btn<?= $activeTab === 'visits' ? ' active' : '' ?>" onclick="switchTab(this,'v-visits')"><i class="fas fa-walking"></i> Visit Log</button>
        <button class="tab-btn<?= $activeTab === 'register' ? ' active' : '' ?>" onclick="switchTab(this,'v-register')"><i class="fas fa-user-plus"></i> Register Customer</button>
        <button class="tab-btn<?= $activeTab === 'orders' ? ' active' : '' ?>" onclick="switchTab(this,'v-orders')"><i class="fas fa-shopping-cart"></i> Create Order</button>
    </div>
    <div class="tab-content">

        <!-- Tab 1: Visit Log -->
        <div id="v-visits" class="tab-panel<?= $activeTab === 'visits' ? ' active' : '' ?>">
            <div class="panel">
                <h2>Record Visit</h2>
                <form method="post" class="form-grid" style="max-width:700px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="save_visit" value="1">
                    <label>Salesperson
                        <select name="salesperson_id" required>
                            <option value="0">-- Select --</option>
                            <?php foreach ($salespeople as $sp): ?>
                                <option value="<?= (int) $sp['id'] ?>"><?= htmlspecialchars($sp['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Customer (existing)
                        <select name="customer_id">
                            <option value="0">-- Walk-in / New prospect --</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Customer Name (if walk-in)<input type="text" name="customer_name_manual" placeholder="Enter name manually"></label>
                    <label>Visit Date<input type="date" name="visit_date" value="<?= date('Y-m-d') ?>"></label>
                    <label>Visit Type
                        <select name="visit_type">
                            <option value="initial">Initial Visit</option>
                            <option value="follow_up">Follow-up</option>
                            <option value="closing">Closing</option>
                            <option value="delivery">Delivery</option>
                        </select>
                    </label>
                    <label>Status
                        <select name="status">
                            <option value="completed">Completed</option>
                            <option value="pending_follow_up">Pending Follow-up</option>
                        </select>
                    </label>
                    <label>Location / Address<input type="text" name="location" placeholder="Where the visit took place"></label>
                    <label>Follow-up Date<input type="date" name="follow_up_date"></label>
                    <label>Notes<textarea name="notes" rows="2" placeholder="Visit notes..."></textarea></label>
                    <button type="submit">Record Visit</button>
                </form>
            </div>

            <div class="panel" style="margin-top:16px">
                <h2>Visit History</h2>
                <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
                    <input type="hidden" name="page" value="visits">
                    <input type="hidden" name="tab" value="visits">
                    <select name="sp_id" style="margin:0;min-width:150px">
                        <option value="0">All Salespeople</option>
                        <?php foreach ($salespeople as $sp): ?>
                            <option value="<?= (int) $sp['id'] ?>"<?= $filterSp === (int) $sp['id'] ? ' selected' : '' ?>><?= htmlspecialchars($sp['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>" style="margin:0">
                    <input type="date" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>" style="margin:0">
                    <button type="submit" style="margin:0">Filter</button>
                </form>
                <table>
                    <thead><tr><th>Date</th><th>Salesperson</th><th>Customer</th><th>Type</th><th>Location</th><th>Follow-up</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        <?php if (empty($visits)): ?>
                            <tr><td colspan="8">No visits recorded.</td></tr>
                        <?php else: ?>
                            <?php foreach ($visits as $v): ?>
                                <tr>
                                    <td><?= htmlspecialchars($v['visit_date']) ?></td>
                                    <td><strong><?= htmlspecialchars($v['salesperson_name'] ?? '-') ?></strong></td>
                                    <td><?= htmlspecialchars($v['customer_name'] ?? $v['notes'] ?? '-') ?></td>
                                    <td><span style="font-size:0.8rem;background:var(--neutral-100);padding:2px 8px;border-radius:4px"><?= ucfirst(str_replace('_', ' ', $v['visit_type'])) ?></span></td>
                                    <td style="font-size:0.85rem"><?= htmlspecialchars((string) ($v['location'] ?? '-')) ?></td>
                                    <td><?= $v['follow_up_date'] ? htmlspecialchars($v['follow_up_date']) : '-' ?></td>
                                    <td><span class="status-badge status-<?= $v['status'] === 'completed' ? 'approved' : 'pending' ?>"><?= ucfirst(str_replace('_', ' ', $v['status'])) ?></span></td>
                                    <td>
                                        <form method="post" style="display:inline" onsubmit="return confirm('Delete this visit?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="delete_visit" value="1">
                                            <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                                            <button type="submit" style="color:var(--red-600);background:none;border:none;cursor:pointer;font-size:0.82rem"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tab 2: Register Customer -->
        <div id="v-register" class="tab-panel<?= $activeTab === 'register' ? ' active' : '' ?>">
            <div class="panel" style="max-width:700px">
                <h2>Register New Business Customer</h2>
                <form method="post" class="form-grid" id="reg-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="save_customer" value="1">
                    <label>Registered By (Salesperson)
                        <select name="salesperson_id">
                            <option value="0">-- Select salesperson --</option>
                            <?php foreach ($salespeople as $sp): ?>
                                <option value="<?= (int) $sp['id'] ?>"><?= htmlspecialchars($sp['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Customer Name<input type="text" name="name" required placeholder="Business name"></label>
                    <label>Customer Type
                        <select name="customer_type">
                            <option value="">-- Select --</option>
                            <option value="pharmacy">Pharmacy</option>
                            <option value="drug_store">Drug Store</option>
                            <option value="hospital">Hospital</option>
                            <option value="clinic">Clinic</option>
                            <option value="wholesaler">Wholesaler</option>
                            <option value="other_licensed">Other Licensed</option>
                        </select>
                    </label>
                    <label>Contact Person<input type="text" name="contact_person" placeholder="Primary contact"></label>
                    <label>Phone<input type="text" name="phone" placeholder="Phone number"></label>
                    <label>Email<input type="email" name="email" placeholder="Email address"></label>
                    <label>License Number<input type="text" name="license_number" placeholder="License #"></label>
                    <label>TIN Number<input type="text" name="tin_number" placeholder="TIN #"></label>
                    <label>Physical Address<textarea name="physical_address" rows="2" placeholder="Address"></textarea></label>
                    <label>Credit Limit (<?= currency_sym() ?>)<input type="number" name="credit_limit" step="0.01" min="0" value="0"></label>
                    <label>Payment Terms<input type="text" name="payment_terms" placeholder="e.g. Net 30 days"></label>
                    <button type="submit">Register Customer</button>
                </form>
            </div>
        </div>

        <!-- Tab 3: Create Order -->
        <div id="v-orders" class="tab-panel<?= $activeTab === 'orders' ? ' active' : '' ?>">
            <div class="panel" style="max-width:800px">
                <h2>Create Customer Order</h2>
                <form method="post" class="form-grid" id="order-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="save_order" value="1">
                    <label>Salesperson
                        <select name="salesperson_id" required>
                            <option value="0">-- Select --</option>
                            <?php foreach ($salespeople as $sp): ?>
                                <option value="<?= (int) $sp['id'] ?>"><?= htmlspecialchars($sp['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Customer
                        <select name="customer_id" required>
                            <option value="0">-- Select customer --</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Order Date<input type="date" name="order_date" value="<?= date('Y-m-d') ?>"></label>
                    <label>Fulfillment Method
                        <select name="fulfillment_method" id="visits-fm" onchange="visitsToggleDelivery(this)">
                            <option value="delivery">Delivery</option>
                            <option value="pickup">Customer Pickup</option>
                        </select>
                    </label>
                    <label id="visits-da-label">Delivery Address<textarea name="delivery_address" rows="2" placeholder="Delivery address (required for delivery)"></textarea></label>
                    <label>Notes<input type="text" name="order_notes" placeholder="Optional notes"></label>
                </form>
                <div id="order-lines">
                    <table style="width:100%">
                        <thead><tr><th>Product</th><th style="width:100px">Qty</th><th style="width:120px">Unit Price</th><th style="width:120px">Subtotal</th><th style="width:40px"></th></tr></thead>
                        <tbody id="order-items-body">
                            <tr>
                                <td><select name="order_items[0][product_id]" class="order-product" style="width:100%;margin:0" onchange="updatePrice(0)">
                                    <option value="0">-- Select product --</option>
                                    <?php foreach ($products as $p): ?>
                                        <option value="<?= (int) $p['id'] ?>" data-price="<?= (float) $p['price'] ?>" data-wprice="<?= (float) $p['wholesale_price'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= fmt((float) $p['price']) ?>)</option>
                                    <?php endforeach; ?>
                                </select></td>
                                <td><input type="number" name="order_items[0][quantity]" class="order-qty" min="1" value="1" style="margin:0" oninput="updateSubtotal(0)"></td>
                                <td><input type="text" class="order-price" readonly style="margin:0;background:var(--neutral-100)"></td>
                                <td><input type="text" class="order-subtotal" readonly style="margin:0;background:var(--neutral-100)"></td>
                                <td><button type="button" onclick="addOrderLine()" style="background:none;border:none;color:var(--brown-600);cursor:pointer"><i class="fas fa-plus"></i></button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div style="display:flex;justify-content:flex-end;margin-top:12px">
                    <strong style="font-size:1.1rem">Total: <span id="order-total"><?= fmt(0) ?></span></strong>
                </div>
                <div style="margin-top:16px">
                    <button type="button" onclick="submitOrder()" style="background:var(--brown-700);color:#fff;padding:10px 24px;border:none;border-radius:8px;cursor:pointer;font-size:0.95rem;font-weight:600">Submit Order</button>
                </div>
            </div>

            <?php if (!empty($recentOrders)): ?>
            <div class="panel" style="margin-top:16px">
                <h2>Recent Orders by Salespeople</h2>
                <table>
                    <thead><tr><th>Order #</th><th>Date</th><th>Salesperson</th><th>Customer</th><th>Total</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($recentOrders as $ro): ?>
                            <tr>
                                <td>#<?= (int) $ro['id'] ?></td>
                                <td><?= htmlspecialchars($ro['order_date']) ?></td>
                                <td><?= htmlspecialchars($ro['salesperson_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($ro['customer_name'] ?? '-') ?></td>
                                <td style="font-weight:700"><?= fmt((float) $ro['total_amount']) ?></td>
                                <td><span class="status-badge status-<?= $ro['status'] === 'approved' ? 'approved' : ($ro['status'] === 'rejected' ? 'rejected' : 'pending') ?>"><?= ucfirst($ro['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>
<script>
var orderLineCount = 1;
function visitsToggleDelivery(sel) {
    var isDelivery = sel.value === 'delivery';
    var lbl = document.getElementById('visits-da-label');
    if (lbl) {
        var ta = lbl.querySelector('textarea');
        if (isDelivery) {
            lbl.style.display = '';
            if (ta) ta.required = true;
        } else {
            lbl.style.display = 'none';
            if (ta) ta.required = false;
        }
    }
    var selects = document.querySelectorAll('.order-product');
    for (var i = 0; i < selects.length; i++) updatePrice(i);
}
function visitsUnitPrice(opt) {
    var fm = document.getElementById('visits-fm');
    var retail = parseFloat(opt.getAttribute('data-price')) || 0;
    if (fm && fm.value === 'pickup') {
        var wholesale = parseFloat(opt.getAttribute('data-wprice')) || 0;
        if (wholesale > 0) return wholesale;
    }
    return retail;
}
function addOrderLine() {
    var tbody = document.getElementById('order-items-body');
    var row = tbody.querySelector('tr').cloneNode(true);
    var idx = orderLineCount++;
    row.querySelectorAll('select, input').forEach(function(el) {
        if (el.name) el.name = el.name.replace(/\[\d+\]/, '[' + idx + ']');
        if (el.tagName === 'SELECT') el.value = '0';
        if (el.tagName === 'INPUT' && el.type === 'number') el.value = '1';
        if (el.className.indexOf('order-price') >= 0 || el.className.indexOf('order-subtotal') >= 0) el.value = '';
    });
    tbody.appendChild(row);
    updateTotal();
}
function updatePrice(idx) {
    var selects = document.querySelectorAll('.order-product');
    if (!selects[idx]) return;
    var opt = selects[idx].options[selects[idx].selectedIndex];
    var price = visitsUnitPrice(opt);
    var rows = document.querySelectorAll('#order-items-body tr');
    var priceInput = rows[idx].querySelector('.order-price');
    var qtyInput = rows[idx].querySelector('.order-qty');
    priceInput.value = price > 0 ? price.toFixed(2) : '';
    var sub = price * (parseInt(qtyInput.value) || 0);
    rows[idx].querySelector('.order-subtotal').value = sub > 0 ? sub.toFixed(2) : '';
    updateTotal();
}
function updateSubtotal(idx) {
    var rows = document.querySelectorAll('#order-items-body tr');
    var selects = document.querySelectorAll('.order-product');
    if (!selects[idx]) return;
    var opt = selects[idx].options[selects[idx].selectedIndex];
    var price = visitsUnitPrice(opt);
    var qty = parseInt(rows[idx].querySelector('.order-qty').value) || 0;
    var sub = price * qty;
    rows[idx].querySelector('.order-subtotal').value = sub > 0 ? sub.toFixed(2) : '';
    updateTotal();
}
function updateTotal() {
    var total = 0;
    document.querySelectorAll('.order-subtotal').forEach(function(el) {
        total += parseFloat(el.value) || 0;
    });
    document.getElementById('order-total').textContent = total.toFixed(2);
}
function submitOrder() {
    var form = document.getElementById('order-form');
    var h = document.createElement('input');
    h.type = 'hidden';
    h.name = 'save_order';
    h.value = '1';
    form.appendChild(h);
    form.submit();
}
</script>
