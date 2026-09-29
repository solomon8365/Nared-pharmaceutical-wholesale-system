<?php
requirePermission('batch_expiry', 'view');
require_once __DIR__ . '/../includes/stock_helper.php';
require_once __DIR__ . '/../includes/workflow_engine.php';
require_once __DIR__ . '/../includes/auto_migrate.php';
require_once __DIR__ . '/../services/init.php';
$pdo = getPDO();
ensureInventoryWorkflowMigration($pdo);

$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['_action'] ?? '';
    $batchId = (int) ($_POST['batch_id'] ?? 0);
    $uid = isset($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : 0;

    // Return to the inventory list preserving filters (Post/Redirect/Get).
    $returnUrl = '?page=batch_expiry';
    $ql = [];
    foreach (['product_id' => (int) ($_GET['product_id'] ?? 0), 'warehouse_id' => (int) ($_GET['warehouse_id'] ?? 0)] as $k => $v) {
        if ($v > 0) { $ql[] = $k . '=' . $v; }
    }
    if (isset($_GET['expiring'])) { $ql[] = 'expiring=1'; }
    if (isset($_GET['expired'])) { $ql[] = 'expired=1'; }
    if (!empty($ql)) { $returnUrl .= '&' . implode('&', $ql); }

    if ($action === 'verify_batch') {
        requirePermission('batch_expiry', 'edit');
        if ($batchId <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Invalid batch.'];
        } else {
            $res = WorkflowEngine::transition($pdo, StatusCatalog::WORKFLOW_INVENTORY, $batchId, StatusCatalog::INV_AWAITING_EXPIRY_VERIFICATION, [
                'changed_by' => $uid,
                'notes' => 'Batch number and packaging verified',
                'extra' => ['workflow_updated_at' => date('Y-m-d H:i:s'), 'workflow_assigned_to' => $uid],
            ]);
            if ($res['success']) {
                redirect_with_flash($returnUrl, 'Batch verified — awaiting expiry verification.');
            } else {
                $messages[] = ['type' => 'error', 'text' => $res['error']];
            }
        }
    } elseif ($action === 'verify_expiry') {
        requirePermission('batch_expiry', 'edit');
        if ($batchId <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Invalid batch.'];
        } else {
            $res = WorkflowEngine::transition($pdo, StatusCatalog::WORKFLOW_INVENTORY, $batchId, StatusCatalog::INV_AVAILABLE_FOR_SALES, [
                'changed_by' => $uid,
                'notes' => 'Expiry date verified',
                'extra' => ['workflow_updated_at' => date('Y-m-d H:i:s'), 'workflow_assigned_to' => $uid],
            ]);
            if ($res['success']) {
                redirect_with_flash($returnUrl, 'Expiry verified — batch available for sales.');
            } else {
                $messages[] = ['type' => 'error', 'text' => $res['error']];
            }
        }
    }
}

$productId = (int) ($_GET['product_id'] ?? 0);
$warehouseId = (int) ($_GET['warehouse_id'] ?? 0);
$expiring = isset($_GET['expiring']);
$expired = isset($_GET['expired']);

$where = ['1=1'];
$params = [];
if ($productId > 0) {
    $where[] = 'pb.product_id = :pid';
    $params['pid'] = $productId;
}
if ($warehouseId > 0) {
    $where[] = 'pb.warehouse_id = :wid';
    $params['wid'] = $warehouseId;
}
if ($expiring) {
    $where[] = 'pb.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)';
}
if ($expired) {
    $where[] = 'pb.expiry_date < CURDATE()';
}

$sql = 'SELECT pb.id, pb.batch_number, pb.quantity, pb.expiry_date, pb.created_at, pb.workflow_status, p.name AS product_name, p.barcode, w.name AS warehouse_name FROM product_batches pb JOIN products p ON pb.product_id = p.id LEFT JOIN warehouses w ON pb.warehouse_id = w.id WHERE ' . implode(' AND ', $where) . ' ORDER BY pb.expiry_date ASC';
$batches = $pdo->prepare($sql);
$batches->execute($params);
$batches = $batches->fetchAll();

$pendingVerification = $pdo->prepare('SELECT pb.id, pb.batch_number, pb.quantity, pb.expiry_date, pb.workflow_status, pb.workflow_updated_at, p.name AS product_name, u.full_name AS assigned_name FROM product_batches pb JOIN products p ON pb.product_id = p.id LEFT JOIN users u ON pb.workflow_assigned_to = u.id WHERE pb.workflow_status IN (:s1, :s2) AND pb.quantity > 0 ORDER BY pb.workflow_updated_at ASC, pb.id ASC');
$pendingVerification->execute(['s1' => StatusCatalog::INV_AWAITING_BATCH_VERIFICATION, 's2' => StatusCatalog::INV_AWAITING_EXPIRY_VERIFICATION]);
$pendingVerification = $pendingVerification->fetchAll();

$products = $pdo->query('SELECT id, name FROM products ORDER BY name')->fetchAll();
$warehouses = $pdo->query('SELECT id, name FROM warehouses ORDER BY name')->fetchAll();
$summary = batchExpiryCounts($pdo, 60, false);
?>
<section>
    <h1>Batch & Expiry Management</h1>
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px">
        <div class="card"><h2>Total Batches</h2><p><?= (int) ($summary['total'] ?? 0) ?></p></div>
        <div class="card"><h2>Total Qty</h2><p><?= (int) ($summary['total_qty'] ?? 0) ?></p></div>
        <div class="card"><h2>Expired</h2><p style="color:var(--red-700)"><?= (int) ($summary['expired_count'] ?? 0) ?></p></div>
        <div class="card"><h2>Expiring Soon</h2><p style="color:var(--amber-700)"><?= (int) ($summary['expiring_count'] ?? 0) ?></p></div>
    </div>
    <?php foreach ($messages as $m): ?>
        <div class="notice notice-<?= $m['type'] === 'success' ? 'success' : 'error' ?>" style="margin-bottom:12px"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>
    <div class="panel" style="margin-bottom:16px">
        <h2 style="margin-bottom:12px">Batch &amp; Expiry Verification Queue</h2>
        <?php if (empty($pendingVerification)): ?>
            <p>No batches awaiting verification.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Batch #</th><th>Product</th><th>Quantity</th><th>Expiry</th><th>Status</th><th>Assigned To</th><th>Updated</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($pendingVerification as $pv): ?>
                        <tr>
                            <td><?= htmlspecialchars($pv['batch_number']) ?></td>
                            <td><strong><?= htmlspecialchars($pv['product_name']) ?></strong></td>
                            <td><?= (int) $pv['quantity'] ?></td>
                            <td><?= htmlspecialchars((string) $pv['expiry_date']) ?></td>
                            <td><span class="status-badge <?= StatusCatalog::statusTone('inventory', $pv['workflow_status']) === 'warning' ? 'status-pending' : (StatusCatalog::statusTone('inventory', $pv['workflow_status']) === 'info' ? 'status-info' : 'status-approved') ?>"><?= htmlspecialchars(StatusCatalog::statusLabel('inventory', $pv['workflow_status'])) ?></span></td>
                            <td><?= htmlspecialchars($pv['assigned_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($pv['workflow_updated_at'] ?? '-') ?></td>
                            <td>
                                <?php if ($pv['workflow_status'] === StatusCatalog::INV_AWAITING_BATCH_VERIFICATION): ?>
                                    <?php if (hasPermission('batch_expiry', 'edit')): ?>
                                    <form method="post" style="display:inline">
                                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($_SESSION['_csrf_token'] ?? '') ?>">
                                        <input type="hidden" name="_action" value="verify_batch">
                                        <input type="hidden" name="batch_id" value="<?= (int) $pv['id'] ?>">
                                        <button type="submit" class="btn btn-sm" onclick="return confirm('Mark batch <?= htmlspecialchars($pv['batch_number']) ?> as verified?');">Verify Batch</button>
                                    </form>
                                    <?php endif; ?>
                                <?php elseif ($pv['workflow_status'] === StatusCatalog::INV_AWAITING_EXPIRY_VERIFICATION): ?>
                                    <?php if (hasPermission('batch_expiry', 'edit')): ?>
                                    <form method="post" style="display:inline">
                                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($_SESSION['_csrf_token'] ?? '') ?>">
                                        <input type="hidden" name="_action" value="verify_expiry">
                                        <input type="hidden" name="batch_id" value="<?= (int) $pv['id'] ?>">
                                        <button type="submit" class="btn btn-sm" onclick="return confirm('Confirm expiry for batch <?= htmlspecialchars($pv['batch_number']) ?>?');">Verify Expiry</button>
                                    </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <div class="panel">
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
            <input type="hidden" name="page" value="batch_expiry">
            <select name="product_id" style="flex:1;min-width:140px;margin:0">
                <option value="">All products</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"<?= $productId === (int) $p['id'] ? ' selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="warehouse_id" style="margin:0">
                <option value="">All warehouses</option>
                <?php foreach ($warehouses as $w): ?>
                    <option value="<?= (int) $w['id'] ?>"<?= $warehouseId === (int) $w['id'] ? ' selected' : '' ?>><?= htmlspecialchars($w['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label style="display:flex;align-items:center;gap:4px;margin:0;white-space:nowrap"><input type="checkbox" name="expiring" value="1"<?= $expiring ? ' checked' : '' ?>> Expiring soon</label>
            <label style="display:flex;align-items:center;gap:4px;margin:0;white-space:nowrap"><input type="checkbox" name="expired" value="1"<?= $expired ? ' checked' : '' ?>> Expired</label>
            <button type="submit" style="margin:0">Filter</button>
            <?php if ($productId > 0 || $warehouseId > 0 || $expiring || $expired): ?><a href="?page=batch_expiry" style="display:inline-flex;align-items:center;padding:0 12px;color:var(--brown-800);font-weight:600">Clear</a><?php endif; ?>
        </form>
        <table>
            <thead><tr><th>Product</th><th>Batch #</th><th>Quantity</th><th>Expiry</th><th>Warehouse</th><th>Status</th><th>Workflow</th><th>Created</th></tr></thead>
            <tbody>
                <?php if (empty($batches)): ?>
                    <tr><td colspan="8">No batches found.</td></tr>
                <?php else: ?>
                    <?php foreach ($batches as $b): ?>
                        <?php
                        $expDate = $b['expiry_date'];
                        $isExpired = $expDate && $expDate < date('Y-m-d');
                        $isExpiring = $expDate && !$isExpired && $expDate <= date('Y-m-d', strtotime('+60 days'));
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($b['product_name']) ?></strong><?= $b['barcode'] ? '<br><span class="cell-sub">' . htmlspecialchars($b['barcode']) . '</span>' : '' ?></td>
                            <td><?= htmlspecialchars($b['batch_number']) ?></td>
                            <td><?= (int) $b['quantity'] ?></td>
                            <td><?= htmlspecialchars((string) $b['expiry_date']) ?></td>
                            <td><?= htmlspecialchars($b['warehouse_name'] ?? '-') ?></td>
                            <td><?php if ($isExpired): ?><span class="status-badge status-rejected">Expired</span><?php elseif ($isExpiring): ?><span class="status-badge status-pending">Expiring</span><?php else: ?><span class="status-badge status-approved">Good</span><?php endif; ?></td>
                            <td><span class="status-badge <?= in_array($b['workflow_status'], [StatusCatalog::INV_AWAITING_BATCH_VERIFICATION, StatusCatalog::INV_AWAITING_EXPIRY_VERIFICATION], true) ? 'status-pending' : 'status-approved' ?>"><?= htmlspecialchars(StatusCatalog::statusLabel('inventory', $b['workflow_status'])) ?></span></td>
                            <td><?= htmlspecialchars($b['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
