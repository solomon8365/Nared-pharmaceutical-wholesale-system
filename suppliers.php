<?php
requirePermission('suppliers', 'view');
require_once __DIR__ . '/../includes/stock_helper.php';
$pdo = getPDO();
$messages = [];
$tab = $_GET['tab'] ?? 'suppliers';

$products = $pdo->query('SELECT id, name, price FROM products ORDER BY name')->fetchAll();
$allSuppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name')->fetchAll();

// --- Supplier CRUD ---
$editSupplier = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int) $_GET['edit']]);
    $editSupplier = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['record_payment']) && !isset($_POST['_action_order'])) {
    require_csrf();
    $action = $_POST['_action'] ?? 'create';

    if ($action === 'delete') {
        requirePermission('suppliers', 'delete');
        $deleteId = (int) ($_POST['delete_id'] ?? 0);
        if ($deleteId > 0) {
            try {
                $pdo->prepare('DELETE FROM suppliers WHERE id = :id')->execute(['id' => $deleteId]);
                $messages[] = ['type' => 'success', 'text' => 'Supplier deleted successfully.'];
            } catch (Throwable $e) {
                $messages[] = ['type' => 'error', 'text' => 'Could not delete supplier: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'update') {
        requirePermission('suppliers', 'edit');
        $supplierId = (int) ($_POST['supplier_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $contact = trim($_POST['contact'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['physical_address'] ?? '');

        if ($supplierId <= 0 || $name === '') {
            $messages[] = ['type' => 'error', 'text' => 'Supplier name is required.'];
        } else {
            $stmt = $pdo->prepare('UPDATE suppliers SET name = :name, contact_person = :contact, phone = :phone, email = :email, physical_address = :addr, supplier_status = :sstatus WHERE id = :id');
            $stmt->execute(['name' => $name, 'contact' => $contact, 'phone' => $phone, 'email' => $email, 'addr' => $address, 'sstatus' => $_POST['supplier_status'] ?? 'active', 'id' => $supplierId]);
            $messages[] = ['type' => 'success', 'text' => 'Supplier updated successfully.'];
        }
    } else {
        requirePermission('suppliers', 'create');
        $name = trim($_POST['name'] ?? '');
        $contact = trim($_POST['contact'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['physical_address'] ?? '');

        if ($name === '') {
            $messages[] = ['type' => 'error', 'text' => 'Supplier name is required.'];
        } else {
            $stmt = $pdo->prepare('INSERT INTO suppliers (name, contact_person, phone, email, physical_address, supplier_status, created_at) VALUES (:name, :contact, :phone, :email, :addr, :sstatus, NOW())');
            $stmt->execute(['name' => $name, 'contact' => $contact, 'phone' => $phone, 'email' => $email, 'addr' => $address, 'sstatus' => $_POST['supplier_status'] ?? 'active']);
            $messages[] = ['type' => 'success', 'text' => 'Supplier added successfully.'];
        }
    }
}

// --- Outstanding balance per supplier (real supplier orders live in purchase_orders) ---
$balStmt = $pdo->query("
    SELECT o.supplier_id,
           SUM(o.total_amount) AS total_ordered,
           COALESCE(SUM(sp.paid), 0) AS total_paid
    FROM purchase_orders o
    LEFT JOIN (SELECT order_id, SUM(base_amount) AS paid FROM supplier_payments GROUP BY order_id) sp ON sp.order_id = o.id
    WHERE o.status <> 'cancelled'
    GROUP BY o.supplier_id");
$supplierBalances = [];
foreach ($balStmt->fetchAll() as $b) {
    $supplierBalances[(int)$b['supplier_id']] = ['ordered' => (float)$b['total_ordered'], 'paid' => (float)$b['total_paid']];
}

// --- Orders per supplier for inline display ---
$ordStmt = $pdo->query("
    SELECT o.id, o.order_date, o.total_amount, o.supplier_id,
           COALESCE(sp.paid, 0) AS paid
    FROM purchase_orders o
    LEFT JOIN (SELECT order_id, SUM(base_amount) AS paid FROM supplier_payments GROUP BY order_id) sp ON sp.order_id = o.id
    WHERE o.status <> 'cancelled'
    ORDER BY o.supplier_id, o.order_date DESC");
$supplierOrders = [];
foreach ($ordStmt->fetchAll() as $o) {
    $supplierOrders[(int)$o['supplier_id']][] = $o;
}

$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE name LIKE :q1 OR contact_person LIKE :q2 OR phone LIKE :q3 ORDER BY created_at DESC");
    $stmt->execute(['q1' => "%$search%", 'q2' => "%$search%", 'q3' => "%$search%"]);
    $suppliers = $stmt->fetchAll();
} else {
    $suppliers = $pdo->query('SELECT * FROM suppliers ORDER BY created_at DESC')->fetchAll();
}

// --- Order logic ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
    require_csrf();
    requirePoCapability('payment');
    $payOrderId = (int) ($_POST['pay_order_id'] ?? 0);
    $amount = (float) ($_POST['pay_amount'] ?? 0);
    $payDate = $_POST['pay_date'] ?? date('Y-m-d');
    $payMethod = trim($_POST['pay_method'] ?? '');
    $payRef = trim($_POST['pay_reference'] ?? '');
    $payNotes = trim($_POST['pay_notes'] ?? '');

    if ($payOrderId <= 0 || $amount <= 0) {
        $messages[] = ['type' => 'error', 'text' => 'Valid order and payment amount are required.'];
    } else {
        $poChk = $pdo->prepare('SELECT id, total_amount FROM purchase_orders WHERE id = :id');
        $poChk->execute(['id' => $payOrderId]);
        $po = $poChk->fetch();
        if (!$po) {
            $messages[] = ['type' => 'error', 'text' => 'Purchase order not found.'];
        } else {
            $pdo->beginTransaction();
            try {
                $baseCur = SettingsService::currencySymbol();
                $pdo->prepare('INSERT INTO supplier_payments (order_id, amount_paid, payment_date, payment_method, reference, notes, currency, exchange_rate, base_amount, created_at) VALUES (:oid, :amt, :dt, :m, :r, :n, :cur, 1.0000, :base_amt, NOW())')
                    ->execute(['oid' => $payOrderId, 'amt' => $amount, 'base_amt' => $amount, 'dt' => $payDate, 'm' => $payMethod, 'r' => $payRef, 'n' => $payNotes, 'cur' => $baseCur]);
                // Keep purchase_orders.payment_status in sync with the total paid.
                $paidStmt = $pdo->prepare('SELECT COALESCE(SUM(base_amount), 0) FROM supplier_payments WHERE order_id = :id');
                $paidStmt->execute(['id' => $payOrderId]);
                $paid = (float) $paidStmt->fetchColumn();
                $poTotal = (float) $po['total_amount'];
                $payStatus = ($poTotal > 0 && $paid >= $poTotal - 0.005) ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
                $pdo->prepare('UPDATE purchase_orders SET payment_status = :ps WHERE id = :id')
                    ->execute(['ps' => $payStatus, 'id' => $payOrderId]);
                $pdo->commit();
                $messages[] = ['type' => 'success', 'text' => 'Payment of ' . fmt($amount) . ' recorded for purchase order #' . $payOrderId . '.'];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $messages[] = ['type' => 'error', 'text' => 'Could not record payment: ' . $e->getMessage()];
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action_order'])) {
    require_csrf();
    requirePoCapability('create');
    $supplierId = (int) ($_POST['supplier_id'] ?? 0);
    $orderDate = $_POST['order_date'] ?? date('Y-m-d');
    $items = $_POST['items'] ?? [];

    if ($supplierId <= 0 || empty($items)) {
        $messages[] = ['type' => 'error', 'text' => 'Supplier and at least one order item are required.'];
    } else {
        $pdo->beginTransaction();
        try {
            $total = 0;
            foreach ($items as $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                $quantity = (int) ($item['quantity'] ?? 0);
                if ($productId <= 0 || $quantity <= 0) continue;
                $stmt = $pdo->prepare('SELECT price FROM products WHERE id = :id');
                $stmt->execute(['id' => $productId]);
                $total += $stmt->fetchColumn() * $quantity;
            }

            $uid = (int) ($_SESSION['user_id'] ?? (currentUser()['id'] ?? 0));
            $stmt = $pdo->prepare("INSERT INTO purchase_orders (supplier_id, order_date, status, total_amount, created_by, received_at, created_at) VALUES (:supplier, :order_date, 'received', :total, :uid, NOW(), NOW())");
            $stmt->execute(['supplier' => $supplierId, 'order_date' => $orderDate, 'total' => $total, 'uid' => $uid]);
            $orderId = (int) $pdo->lastInsertId();

            $insertItem = $pdo->prepare('INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, unit_price, received_qty) VALUES (:order_id, :product_id, :quantity, :unit_price, :received_qty)');
            $insertBatch = $pdo->prepare('INSERT INTO product_batches (product_id, batch_number, expiry_date, quantity, purchase_price) VALUES (:product_id, :batch_number, :expiry_date, :quantity, :purchase_price)');
            foreach ($items as $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                $quantity = (int) ($item['quantity'] ?? 0);
                if ($productId <= 0 || $quantity <= 0) continue;
                $stmt = $pdo->prepare('SELECT price FROM products WHERE id = :id');
                $stmt->execute(['id' => $productId]);
                $price = $stmt->fetchColumn();
                $insertItem->execute(['order_id' => $orderId, 'product_id' => $productId, 'quantity' => $quantity, 'unit_price' => $price, 'received_qty' => $quantity]);

                $wasBatchTracked = has_batches($pdo, $productId);

                $batchNumber = trim($item['batch_number'] ?? '');
                $expiryDate = trim($item['expiry_date'] ?? '');
                $purchasePrice = (float) ($item['purchase_price'] ?? 0);
                // Always create a batch so stock stays batch-tracked; generate a
                // number/expiry when not provided instead of silently skipping.
                if ($batchNumber === '') {
                    $batchNumber = 'AUTO-ORD-' . $orderId . '-' . $productId;
                }
                $insertBatch->execute([
                    'product_id' => $productId,
                    'batch_number' => $batchNumber,
                    'expiry_date' => $expiryDate !== '' ? $expiryDate : null,
                    'quantity' => $quantity,
                    'purchase_price' => $purchasePrice > 0 ? $purchasePrice : $price,
                ]);

                if ($wasBatchTracked) {
                    recalc_product_stock($pdo, $productId);
                } else {
                    $pdo->prepare('UPDATE products SET stock_quantity = stock_quantity + :qty WHERE id = :id')->execute(['qty' => $quantity, 'id' => $productId]);
                }
                log_stock_movement($pdo, $productId, $quantity, 'Supplier order restock', 'purchase_order', (int) $orderId);
            }
            $pdo->commit();
            $messages[] = ['type' => 'success', 'text' => 'Order created successfully.'];
        } catch (Exception $e) {
            $pdo->rollBack();
            $messages[] = ['type' => 'error', 'text' => 'Failed to create order: ' . $e->getMessage()];
        }
    }
}

$filterSupplier = (int) ($_GET['supplier_id'] ?? 0);
$filterDateFrom = trim($_GET['date_from'] ?? '');
$filterDateTo = trim($_GET['date_to'] ?? '');
$where = [];
$params = [];
if ($filterSupplier > 0) { $where[] = 'o.supplier_id = :supplier'; $params['supplier'] = $filterSupplier; }
if ($filterDateFrom !== '') { $where[] = 'o.order_date >= :date_from'; $params['date_from'] = $filterDateFrom; }
if ($filterDateTo !== '') { $where[] = 'o.order_date <= :date_to'; $params['date_to'] = $filterDateTo; }
$sql = 'SELECT o.id, o.order_date, o.total_amount, s.name AS supplier_name, COALESCE(sp.paid, 0) AS paid FROM purchase_orders o LEFT JOIN suppliers s ON o.supplier_id = s.id LEFT JOIN (SELECT order_id, SUM(base_amount) AS paid FROM supplier_payments GROUP BY order_id) sp ON sp.order_id = o.id';
if (!empty($where)) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY o.created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();
?>
<style>@media(max-width:768px){.mob-grid-2{grid-template-columns:1fr!important}}</style>
<section>
    <h1>Suppliers</h1>
    <?php foreach ($messages as $message): ?>
        <div class="alert alert-<?= htmlspecialchars($message['type']) ?>"><?= htmlspecialchars($message['text']) ?></div>
    <?php endforeach; ?>

    <div class="tabs" style="margin-bottom:16px">
        <a href="?page=suppliers&tab=suppliers" class="tab-btn<?= $tab === 'suppliers' ? ' active' : '' ?>">Suppliers</a>
        <a href="?page=suppliers&tab=orders" class="tab-btn<?= $tab === 'orders' ? ' active' : '' ?>">Orders</a>
    </div>

    <?php if ($tab === 'suppliers'): ?>

    <?php if ($editSupplier && hasPermission('suppliers', 'edit')): ?>
        <div class="panel" style="max-width:600px">
            <h2>Edit supplier #<?= htmlspecialchars((string) $editSupplier['id']) ?></h2>
            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="update">
                <input type="hidden" name="supplier_id" value="<?= htmlspecialchars((string) $editSupplier['id']) ?>">
                <div class="mob-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <label>Name<input type="text" name="name" value="<?= htmlspecialchars($editSupplier['name']) ?>" required></label>
                    <label>Supplier Status
                        <select name="supplier_status">
                            <option value="active"<?= ($editSupplier['supplier_status'] ?? 'active') === 'active' ? ' selected' : '' ?>>Active</option>
                            <option value="inactive"<?= ($editSupplier['supplier_status'] ?? '') === 'inactive' ? ' selected' : '' ?>>Inactive</option>
                        </select>
                    </label>
                    <label>Contact Person<input type="text" name="contact" value="<?= htmlspecialchars((string) ($editSupplier['contact_person'] ?? '')) ?>"></label>
                    <label>Phone<input type="text" name="phone" value="<?= htmlspecialchars((string) ($editSupplier['phone'] ?? '')) ?>"></label>
                    <label>Email<input type="email" name="email" value="<?= htmlspecialchars($editSupplier['email'] ?? '') ?>"></label>
                </div>
                <label>Physical Address<textarea name="physical_address"><?= htmlspecialchars($editSupplier['physical_address'] ?? '') ?></textarea></label>
                <div>
                    <button type="submit">Update supplier</button>
                    <a href="?page=suppliers" style="display:inline-block;padding:12px 20px;color:var(--brown-800);font-weight:700">Cancel</a>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div class="panel split">
        <div class="panel-block">
            <?php if (hasPermission('suppliers', 'create')): ?>
            <h2>Add Supplier</h2>
            <form method="post" class="form-grid" style="max-width:500px">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="create">
                <div class="mob-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                    <label>Name<input type="text" name="name" required></label>
                    <label>Status
                        <select name="supplier_status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </label>
                    <label>Contact Person<input type="text" name="contact"></label>
                    <label>Phone<input type="text" name="phone"></label>
                    <label>Email<input type="email" name="email"></label>
                </div>
                <label>Physical Address<textarea name="physical_address"></textarea></label>
                <button type="submit">Create Supplier</button>
            </form>
            <?php endif; ?>
        </div>

        <div class="panel-block">
            <h2>Supplier List</h2>
            <form method="get" style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap">
                <input type="hidden" name="page" value="suppliers">
                <input type="hidden" name="tab" value="suppliers">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search suppliers..." style="flex:1;margin:0">
                <button type="submit" style="margin:0">Search</button>
                <?php if ($search !== ''): ?><a href="?page=suppliers" style="display:inline-flex;align-items:center;padding:0 12px;color:var(--brown-800);font-weight:600">Clear</a><?php endif; ?>
            </form>
            <div style="overflow-x:auto">
            <table>
                <thead><tr><th>ID</th><th>Name</th><th>Contact</th><th>Phone</th><th>Email</th><th>Status</th><th>Balance</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if (empty($suppliers)): ?>
                        <tr><td colspan="6">No suppliers found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($suppliers as $supplier): ?>
                            <?php
                            $sid = (int)$supplier['id'];
                            $bal = $supplierBalances[$sid] ?? ['ordered' => 0, 'paid' => 0];
                            $balance = $bal['ordered'] - $bal['paid'];
                            $oList = $supplierOrders[$sid] ?? [];
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $sid) ?></td>
                                <td><?= htmlspecialchars($supplier['name']) ?></td>
                                <td><?= htmlspecialchars((string) ($supplier['contact_person'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($supplier['phone'] ?? '')) ?></td>
                                <td><?= htmlspecialchars($supplier['email'] ?? '—') ?></td>
                                <td><?php $ss = $supplier['supplier_status'] ?? 'active'; $ssc = $ss === 'active' ? '#059669' : '#f59e0b'; ?><span style="display:inline-block;padding:2px 6px;border-radius:3px;font-size:0.72rem;font-weight:700;background:<?= $ssc ?>20;color:<?= $ssc ?>"><?= ucfirst($ss) ?></span></td>
                                <td class="<?= $balance > 0 ? 'text-danger' : 'text-success' ?>" style="font-weight:700"><?= fmt($balance) ?></td>
                                <td>
                                    <?php if (hasPermission('suppliers', 'edit')): ?>
                                    <a href="?page=suppliers&edit=<?= htmlspecialchars((string) $sid) ?>" style="color:var(--brown-800);font-weight:700;text-decoration:none;margin-right:10px">Edit</a>
                                    <?php endif; ?>
                                    <?php if (hasPermission('suppliers', 'delete')): ?>
                                    <form method="post" style="display:inline" onsubmit="return confirm('Delete this supplier?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="delete">
                                        <input type="hidden" name="delete_id" value="<?= htmlspecialchars((string) $sid) ?>">
                                        <button type="submit" class="btn-danger" style="padding:4px 10px;font-size:0.82rem">Delete</button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if (!empty($oList)): ?>
                                        <button class="btn btn-secondary" style="padding:3px 8px;font-size:0.78rem;margin-left:4px" onclick="toggleOrders(<?= $sid ?>)">Orders</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if (!empty($oList)): ?>
                            <tr class="orders-row" id="orders-row-<?= $sid ?>" style="display:none">
                                <td colspan="8" style="padding:0;background:var(--neutral-50)">
                                    <div style="padding:12px 16px">
                                        <table style="font-size:0.82rem;margin:0">
                                            <thead><tr><th>Order#</th><th>Date</th><th>Total</th><th>Paid</th><th>Balance</th><th></th></tr></thead>
                                            <tbody>
                                                <?php foreach ($oList as $o): ?>
                                                    <?php
                                                    $ot = (float)$o['total_amount'];
                                                    $op = (float)$o['paid'];
                                                    $ob = $ot - $op;
                                                    ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars((string)$o['id']) ?></td>
                                                        <td><?= htmlspecialchars($o['order_date']) ?></td>
                                                        <td><?= fmt($ot) ?></td>
                                                        <td><?= fmt($op) ?></td>
                                                        <td class="<?= $ob > 0 ? 'text-danger' : 'text-success' ?>" style="font-weight:700"><?= fmt($ob) ?></td>
                                                        <td>
                                                            <?php if ($ob > 0): ?>
                                                                <?php if (canProcessPurchasePayment()): ?>
                                                                <button class="btn btn-secondary" style="padding:2px 6px;font-size:0.75rem" onclick="togglePayForm(<?= (int)$o['id'] ?>)">Pay</button>
                                                                <div id="pay-form-<?= (int)$o['id'] ?>" style="display:none;margin-top:4px;border-top:1px solid var(--neutral-200);padding-top:4px;">
                                                                    <form method="post" style="font-size:0.78rem">
                                                                        <?= csrf_field() ?>
                                                                        <input type="hidden" name="record_payment" value="1">
                                                                        <input type="hidden" name="pay_order_id" value="<?= (int)$o['id'] ?>">
                                                                        <label style="display:block;margin:2px 0">Amount <input type="number" step="0.01" name="pay_amount" required style="width:80px;font-size:0.78rem;padding:2px 4px"></label>
                                                                        <label style="display:block;margin:2px 0">Date <input type="date" name="pay_date" value="<?= date('Y-m-d') ?>" style="width:auto;font-size:0.78rem;padding:2px 4px"></label>
                                                                        <label style="display:block;margin:2px 0">Method <input type="text" name="pay_method" placeholder="Bank/Cheque/Cash" style="width:90px;font-size:0.78rem;padding:2px 4px"></label>
                                                                        <label style="display:block;margin:2px 0">Ref# <input type="text" name="pay_reference" placeholder="Ref" style="width:80px;font-size:0.78rem;padding:2px 4px"></label>
                                                                        <label style="display:block;margin:2px 0">Notes <input type="text" name="pay_notes" placeholder="Notes" style="width:80px;font-size:0.78rem;padding:2px 4px"></label>
                                                                        <button type="submit" style="font-size:0.75rem;padding:2px 6px;margin-top:2px">Record</button>
                                                                    </form>
                                                                </div>
                                                                <?php else: ?>
                                                                <span style="color:var(--neutral-500)">Unpaid</span>
                                                                <?php endif; ?>
                                                            <?php else: ?>
                                                                <span style="color:var(--neutral-500)">Paid</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php elseif ($tab === 'orders'): ?>

    <div class="panel split">
        <div class="panel-block">
            <?php if (canCreatePurchaseOrder()): ?>
            <h2>Create Order</h2>
            <form method="post" class="form-grid" id="order-form">
                <?= csrf_field() ?>
                <input type="hidden" name="_action_order" value="1">
                <label>Supplier
                    <select name="supplier_id" required>
                        <option value="">Select supplier</option>
                        <?php foreach ($allSuppliers as $sup): ?>
                            <option value="<?= htmlspecialchars($sup['id']) ?>"><?= htmlspecialchars($sup['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Order date<input type="date" name="order_date" value="<?= date('Y-m-d') ?>"></label>
                <div class="order-items" id="order-items">
                    <div class="order-item-row">
                        <select name="items[0][product_id]" required>
                            <option value="">Select product</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?= htmlspecialchars($product['id']) ?>"><?= htmlspecialchars($product['name']) ?> - <?= fmt((float) $product['price']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" name="items[0][quantity]" value="1" min="1" required>
                        <input type="text" name="items[0][batch_number]" placeholder="Batch #" style="min-width:90px">
                        <input type="date" name="items[0][expiry_date]" style="min-width:130px">
                        <input type="number" step="0.01" name="items[0][purchase_price]" placeholder="Cost" style="min-width:90px">
                        <button type="button" class="remove-item">Remove</button>
                    </div>
                </div>
                <button type="button" id="add-order-item">Add Item</button>
                <button type="submit">Save Order</button>
            </form>
            <?php endif; ?>
        </div>

        <div class="panel-block">
            <h2>Order History</h2>
            <form method="get" style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap">
                <input type="hidden" name="page" value="suppliers">
                <input type="hidden" name="tab" value="orders">
                <select name="supplier_id" style="flex:1;min-width:140px;margin:0">
                    <option value="">All suppliers</option>
                    <?php foreach ($allSuppliers as $sup): ?>
                        <option value="<?= htmlspecialchars($sup['id']) ?>"<?= $filterSupplier === (int) $sup['id'] ? ' selected' : '' ?>><?= htmlspecialchars($sup['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="date" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>" style="margin:0" placeholder="From">
                <input type="date" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>" style="margin:0" placeholder="To">
                <button type="submit" style="margin:0">Filter</button>
                <?php if ($filterSupplier > 0 || $filterDateFrom !== '' || $filterDateTo !== ''): ?><a href="?page=suppliers&tab=orders" style="display:inline-flex;align-items:center;padding:0 12px;color:var(--brown-800);font-weight:600">Clear</a><?php endif; ?>
            </form>
            <table>
                <thead><tr><th>ID</th><th>Date</th><th>Supplier</th><th>Total</th><th>Paid</th><th>Balance</th><th></th></tr></thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr><td colspan="7">No orders recorded yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($orders as $order): ?>
                            <?php
                            $total = (float) $order['total_amount'];
                            $paid = (float) $order['paid'];
                            $balance = $total - $paid;
                            $balClass = $balance <= 0 ? 'text-success' : 'text-danger';
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($order['id']) ?></td>
                                <td><?= htmlspecialchars($order['order_date']) ?></td>
                                <td><?= htmlspecialchars($order['supplier_name'] ?? 'Unknown') ?></td>
                                <td><?= fmt($total) ?></td>
                                <td><?= fmt($paid) ?></td>
                                <td class="<?= $balClass ?>" style="font-weight:700"><?= fmt($balance) ?></td>
                                <td>
                                    <?php if (canProcessPurchasePayment()): ?>
                                    <button class="btn btn-secondary" style="padding:3px 8px;font-size:0.78rem" onclick="togglePayForm(<?= (int) $order['id'] ?>)">Pay</button>
                                    <div id="pay-form-<?= (int) $order['id'] ?>" style="display:none;margin-top:6px;border-top:1px solid var(--neutral-200);padding-top:6px;">
                                        <form method="post" style="font-size:0.82rem">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="record_payment" value="1">
                                            <input type="hidden" name="pay_order_id" value="<?= (int) $order['id'] ?>">
                                            <label style="display:block;margin:2px 0">Amount<input type="number" step="0.01" name="pay_amount" required style="width:90px;font-size:0.82rem;padding:3px 6px"></label>
                                            <label style="display:block;margin:2px 0">Date<input type="date" name="pay_date" value="<?= date('Y-m-d') ?>" style="width:auto;font-size:0.82rem;padding:3px 6px"></label>
                                            <label style="display:block;margin:2px 0">Method<input type="text" name="pay_method" placeholder="Bank/Cheque/Cash" style="width:100px;font-size:0.82rem;padding:3px 6px"></label>
                                            <label style="display:block;margin:2px 0">Ref#<input type="text" name="pay_reference" placeholder="Optional" style="width:100px;font-size:0.82rem;padding:3px 6px"></label>
                                            <label style="display:block;margin:2px 0">Notes<input type="text" name="pay_notes" placeholder="Optional" style="width:100px;font-size:0.82rem;padding:3px 6px"></label>
                                            <button type="submit" style="font-size:0.78rem;padding:3px 8px;margin-top:4px">Record</button>
                                        </form>
                                    </div>
                                    <?php else: ?>
                                    <span style="color:var(--neutral-500)">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>
</section>
<script>
function togglePayForm(id) {
    var el = document.getElementById('pay-form-' + id);
    if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
function toggleOrders(id) {
    var el = document.getElementById('orders-row-' + id);
    if (el) el.style.display = el.style.display === 'none' ? 'table-row' : 'none';
}
</script>
