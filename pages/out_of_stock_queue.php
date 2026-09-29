<?php
requirePermission('out_of_stock_queue', 'view');
require_once __DIR__ . '/../includes/auto_migrate.php';
ensureSalesRepMigration(getPDO());
$pdo = getPDO();
$messages = [];

$filterStatus = trim($_GET['status'] ?? '');
$filterProduct = trim($_GET['product'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    // Mark as notified
    if (isset($_POST['mark_notified'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $type = $_POST['notify_type'] ?? '';
        if ($id > 0 && in_array($type, ['admin', 'salesperson'])) {
            $col = $type === 'admin' ? 'notified_admin' : 'notified_salesperson';
            $pdo->prepare("UPDATE out_of_stock_queue SET $col = 1 WHERE id = :id")->execute(['id' => $id]);
            $messages[] = ['type' => 'success', 'text' => ucfirst($type) . ' notification marked.'];
        }
    }

    // Create PO from OOS queue item
    if (isset($_POST['create_po'])) {
        requirePoCapability('create');
        $oosId = (int) ($_POST['oos_id'] ?? 0);
        $supplierId = (int) ($_POST['supplier_id'] ?? 0);
        $qty = (int) ($_POST['po_quantity'] ?? 0);
        if ($oosId > 0 && $supplierId > 0 && $qty > 0) {
            $oosRow = $pdo->prepare("SELECT * FROM out_of_stock_queue WHERE id = :id");
            $oosRow->execute(['id' => $oosId]);
            $oosRow = $oosRow->fetch();
            if ($oosRow) {
                $productRow = $pdo->prepare("SELECT price FROM products WHERE id = :id");
                $productRow->execute(['id' => $oosRow['product_id']]);
                $price = (float) $productRow->fetchColumn();

                // Check if there's already a pending PO for this product+supplier
                $existingPo = $pdo->prepare("SELECT po.id FROM purchase_orders po JOIN purchase_order_items poi ON po.id = poi.purchase_order_id WHERE po.supplier_id = :sid AND po.status IN ('pending','approved','payment_pending') AND poi.product_id = :pid LIMIT 1");
                $existingPo->execute(['sid' => $supplierId, 'pid' => $oosRow['product_id']]);
                $existingPoId = $existingPo->fetchColumn();

                if ($existingPoId) {
                    // Add to existing PO
                    $insItem = $pdo->prepare("INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, unit_price, received_qty) VALUES (:poid, :pid, :qty, :pp, 0)");
                    $insItem->execute(['poid' => $existingPoId, 'pid' => $oosRow['product_id'], 'qty' => $qty, 'pp' => $price]);
                    // Update PO total
                    $newTotal = $pdo->prepare("SELECT COALESCE(SUM(quantity * unit_price),0) FROM purchase_order_items WHERE purchase_order_id = :poid");
                    $newTotal->execute(['poid' => $existingPoId]);
                    $pdo->prepare("UPDATE purchase_orders SET total_amount = :total WHERE id = :id")->execute(['total' => $newTotal->fetchColumn(), 'id' => $existingPoId]);
                    $pdo->prepare("UPDATE out_of_stock_queue SET purchase_order_id = :poid, status = 'purchase_ordered' WHERE id = :id")->execute(['poid' => $existingPoId, 'id' => $oosId]);
                    $messages[] = ['type' => 'success', 'text' => "Added to existing PO #{$existingPoId}."];
                } else {
                    // Create new PO
                    $poDate = date('Y-m-d');
                    $insPo = $pdo->prepare("INSERT INTO purchase_orders (supplier_id, order_date, status, total_amount, created_by, created_at) VALUES (:sid, :od, 'pending', :total, :uid, NOW())");
                    $insPo->execute(['sid' => $supplierId, 'od' => $poDate, 'total' => $qty * $price, 'uid' => currentUser()['id']]);
                    $poId = (int) $pdo->lastInsertId();
                    $insItem = $pdo->prepare("INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, unit_price, received_qty) VALUES (:poid, :pid, :qty, :pp, 0)");
                    $insItem->execute(['poid' => $poId, 'pid' => $oosRow['product_id'], 'qty' => $qty, 'pp' => $price]);
                    $pdo->prepare("UPDATE out_of_stock_queue SET purchase_order_id = :poid, status = 'purchase_ordered' WHERE id = :id")->execute(['poid' => $poId, 'id' => $oosId]);
                    $messages[] = ['type' => 'success', 'text' => "Created PO #{$poId} for this product."];
                }
            }
        }
    }

    // Cancel queue item
    if (isset($_POST['cancel_oos'])) {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE out_of_stock_queue SET status = 'cancelled' WHERE id = :id AND status NOT IN ('fulfilled','cancelled')")->execute(['id' => $id]);
            $messages[] = ['type' => 'success', 'text' => 'Queue item cancelled.'];
        }
    }

    // Mark as fulfilled manually
    if (isset($_POST['mark_fulfilled'])) {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE out_of_stock_queue SET status = 'fulfilled', fulfilled_at = NOW(), quantity_fulfilled = quantity_needed WHERE id = :id AND status != 'cancelled'")->execute(['id' => $id]);
            $messages[] = ['type' => 'success', 'text' => 'Marked as fulfilled.'];
        }
    }

    // Notify customer
    if (isset($_POST['notify_customer'])) {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE out_of_stock_queue SET customer_notified = 1 WHERE id = :id")->execute(['id' => $id]);
            $messages[] = ['type' => 'success', 'text' => 'Customer notification recorded.'];
        }
    }
}

// Build filter
$where = ['1=1'];
$params = [];
if ($filterStatus !== '') { $where[] = 'o.status = :status'; $params['status'] = $filterStatus; }
if ($filterProduct !== '') { $where[] = 'p.name LIKE :product'; $params['product'] = '%' . $filterProduct . '%'; }

$queue = $pdo->prepare("
    SELECT o.*, p.name AS product_name, p.stock_quantity AS current_stock, p.price,
        co.id AS order_num, co.status AS order_status,
        c.name AS customer_name, c.phone AS customer_phone,
        sp.name AS salesperson_name,
        po.id AS po_num, po.status AS po_status,
        s.name AS supplier_name
    FROM out_of_stock_queue o
    JOIN products p ON o.product_id = p.id
    LEFT JOIN customer_orders co ON o.customer_order_id = co.id
    LEFT JOIN customers c ON co.customer_id = c.id
    LEFT JOIN salespeople sp ON o.salesperson_id = sp.id
    LEFT JOIN purchase_orders po ON o.purchase_order_id = po.id
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY o.created_at DESC LIMIT 200
");
$queue->execute($params);
$queue = $queue->fetchAll();

$summary = $pdo->query("SELECT COUNT(*) AS total, SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending, SUM(CASE WHEN status='purchase_ordered' THEN 1 ELSE 0 END) AS ordered, SUM(CASE WHEN status='fulfilled' THEN 1 ELSE 0 END) AS fulfilled, SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) AS cancelled FROM out_of_stock_queue")->fetch();

$suppliers = $pdo->query("SELECT id, name FROM suppliers WHERE supplier_status = 'active' ORDER BY name")->fetchAll();
?>
<section>
    <h1>Out of Stock Queue</h1>
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px">
        <div class="card" style="border-top-color:var(--red-600)"><h2>Pending</h2><p style="color:var(--red-600)"><?= (int) ($summary['pending'] ?? 0) ?></p></div>
        <div class="card" style="border-top-color:#f59e0b"><h2>PO Created</h2><p style="color:#f59e0b"><?= (int) ($summary['ordered'] ?? 0) ?></p></div>
        <div class="card" style="border-top-color:#059669"><h2>Fulfilled</h2><p style="color:#059669"><?= (int) ($summary['fulfilled'] ?? 0) ?></p></div>
        <div class="card"><h2>Total</h2><p><?= (int) ($summary['total'] ?? 0) ?></p></div>
    </div>

    <div class="panel">
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
            <input type="hidden" name="page" value="out_of_stock_queue">
            <input type="text" name="product" placeholder="Search product..." value="<?= htmlspecialchars($filterProduct) ?>" style="flex:1;min-width:160px;margin:0">
            <select name="status" style="margin:0">
                <option value="">All Status</option>
                <option value="pending"<?= $filterStatus === 'pending' ? ' selected' : '' ?>>Pending</option>
                <option value="purchase_ordered"<?= $filterStatus === 'purchase_ordered' ? ' selected' : '' ?>>PO Created</option>
                <option value="receiving"<?= $filterStatus === 'receiving' ? ' selected' : '' ?>>Receiving</option>
                <option value="fulfilled"<?= $filterStatus === 'fulfilled' ? ' selected' : '' ?>>Fulfilled</option>
                <option value="cancelled"<?= $filterStatus === 'cancelled' ? ' selected' : '' ?>>Cancelled</option>
            </select>
            <button type="submit" style="margin:0">Filter</button>
        </form>

        <?php if (empty($queue)): ?>
            <p class="hint">No out-of-stock items found.</p>
        <?php else: ?>
            <?php foreach ($queue as $q): ?>
                <div style="border:1px solid <?= $q['status'] === 'pending' ? 'var(--red-400)' : 'var(--neutral-200)' ?>;border-radius:10px;padding:14px;margin-bottom:12px;background:<?= $q['status'] === 'pending' ? '#fef2f2' : '#fff' ?>">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px">
                        <div>
                            <strong style="font-size:1rem"><?= htmlspecialchars($q['product_name']) ?></strong>
                            <span style="font-size:0.82rem;margin-left:8px;padding:2px 8px;border-radius:4px;background:<?= $q['status'] === 'pending' ? '#fee2e2' : ($q['status'] === 'fulfilled' ? '#d1fae5' : '#fef3c7') ?>;color:<?= $q['status'] === 'pending' ? '#dc2626' : ($q['status'] === 'fulfilled' ? '#047857' : '#b45309') ?>"><?= ucfirst(str_replace('_', ' ', $q['status'])) ?></span>
                        </div>
                        <div style="font-size:0.85rem;color:var(--neutral-600)">
                            Current stock: <strong style="color:<?= (int) $q['current_stock'] > 0 ? 'var(--brown-600)' : 'var(--red-600)' ?>"><?= (int) $q['current_stock'] ?></strong>
                            &middot; Need: <?= (int) $q['quantity_needed'] ?>
                            &middot; <?= htmlspecialchars($q['created_at']) ?>
                        </div>
                    </div>
                    <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:0.85rem;margin-top:6px">
                        <?php if ($q['order_num']): ?>
                            <span>Order #<strong><?= (int) $q['order_num'] ?></strong> (<?= htmlspecialchars($q['order_status']) ?>)</span>
                        <?php endif; ?>
                        <?php if ($q['customer_name']): ?>
                            <span>Customer: <strong><?= htmlspecialchars($q['customer_name']) ?></strong> <?= $q['customer_phone'] ? '(' . htmlspecialchars($q['customer_phone']) . ')' : '' ?></span>
                        <?php endif; ?>
                        <?php if ($q['salesperson_name']): ?>
                            <span>Rep: <?= htmlspecialchars($q['salesperson_name']) ?></span>
                        <?php endif; ?>
                        <?php if ($q['po_num']): ?>
                            <span>PO #<strong><?= (int) $q['po_num'] ?></strong> (<?= htmlspecialchars($q['po_status']) ?>)</span>
                        <?php endif; ?>
                        <?php if ($q['supplier_name']): ?>
                            <span>Supplier: <?= htmlspecialchars($q['supplier_name']) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($q['status'] === 'pending' && canCreatePurchaseOrder()): ?>
                        <div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                            <form method="post" style="display:flex;gap:4px;align-items:center">
                                <?= csrf_field() ?>
                                <input type="hidden" name="oos_id" value="<?= (int) $q['id'] ?>">
                                <select name="supplier_id" required style="margin:0;font-size:0.82rem;padding:3px 6px">
                                    <option value="">-- Supplier --</option>
                                    <?php foreach ($suppliers as $s): ?>
                                        <option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" name="po_quantity" value="<?= (int) $q['quantity_needed'] ?>" min="1" required style="width:70px;margin:0;font-size:0.82rem;padding:3px 4px">
                                <button type="submit" name="create_po" value="1" style="font-size:0.78rem;padding:3px 10px;background:var(--brown-600);color:#fff;border:none;border-radius:4px;cursor:pointer">Create PO</button>
                            </form>
                            <form method="post" style="display:inline" onsubmit="return confirm('Cancel this item?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $q['id'] ?>">
                                <button type="submit" name="cancel_oos" value="1" style="font-size:0.78rem;padding:3px 10px;background:var(--neutral-200);color:var(--neutral-700);border:none;border-radius:4px;cursor:pointer">Cancel</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <?php if ($q['status'] === 'fulfilled' && !$q['customer_notified']): ?>
                        <div style="margin-top:8px">
                            <form method="post" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $q['id'] ?>">
                                <button type="submit" name="notify_customer" value="1" style="font-size:0.78rem;padding:3px 10px;background:#059669;color:#fff;border:none;border-radius:4px;cursor:pointer"><i class="fas fa-bell"></i> Notify Customer</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>
