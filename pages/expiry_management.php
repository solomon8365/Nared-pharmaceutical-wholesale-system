<?php
require_once __DIR__ . '/../services/init.php';
require_once __DIR__ . '/../includes/auto_migrate.php';
require_once __DIR__ . '/../includes/stock_helper.php';
requirePermission('expiry_management', 'view');
$pdo = getPDO();
ensureExpiryManagementMigration($pdo);

$messages = [];
$tab = $_GET['tab'] ?? 'overview';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['_action'] ?? '';

    if ($action === 'quarantine') {
        requirePermission('expiry_management', 'edit');
        $batchId = (int)($_POST['batch_id'] ?? 0);
        if ($batchId > 0) {
            $stmt = $pdo->prepare('SELECT pb.*, p.name AS product_name FROM product_batches pb JOIN products p ON pb.product_id = p.id WHERE pb.id = :id AND pb.status = \'expired\'');
            $stmt->execute(['id' => $batchId]);
            $batch = $stmt->fetch();
            if ($batch) {
                $pdo->beginTransaction();
                try {
                    // Stock movement must reflect the REAL change in available
                    // (active) stock, never a blind deduction.
                    $activeSt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM product_batches WHERE product_id = :pid AND status = 'active'");
                    $activeSt->execute(['pid' => $batch['product_id']]);
                    $oldActive = (int)$activeSt->fetchColumn();

                    $pdo->prepare('UPDATE product_batches SET status = \'quarantined\', quarantined_at = NOW() WHERE id = :id')->execute(['id' => $batchId]);

                    // Recompute product stock from active batches so quarantined
                    // quantity is removed from available stock for good.
                    recalc_product_stock($pdo, (int)$batch['product_id']);

                    $activeSt->execute(['pid' => $batch['product_id']]);
                    $newActive = (int)$activeSt->fetchColumn();
                    $delta = $newActive - $oldActive;
                    if ($delta != 0) {
                        log_stock_movement($pdo, (int)$batch['product_id'], $delta, 'quarantine', 'product_batch', $batchId);
                    }

                    $pdo->commit();
                    $messages[] = ['type' => 'success', 'text' => "Batch {$batch['batch_number']} quarantined successfully."];
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $messages[] = ['type' => 'error', 'text' => $e->getMessage()];
                }
            } else {
                $messages[] = ['type' => 'error', 'text' => 'Batch not found or not expired.'];
            }
        }
    } elseif ($action === 'dispose') {
        requirePermission('expiry_management', 'delete');
        $batchId = (int)($_POST['batch_id'] ?? 0);
        $disposalReason = trim($_POST['disposal_reason'] ?? '');
        $disposalDate = trim($_POST['disposal_date'] ?? date('Y-m-d'));
        $quantity = (int)($_POST['quantity'] ?? 0);
        $batchNumber = trim($_POST['batch_number'] ?? '');
        $approvedBy = currentUserId();
        $remarks = trim($_POST['remarks'] ?? '');

        if ($batchId <= 0 || $disposalReason === '' || $quantity <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Disposal reason, batch, and quantity are required.'];
        } else {
            $stmt = $pdo->prepare('SELECT pb.*, p.name AS product_name FROM product_batches pb JOIN products p ON pb.product_id = p.id WHERE pb.id = :id');
            $stmt->execute(['id' => $batchId]);
            $batch = $stmt->fetch();
            if ($batch) {
                $qty = min($quantity, (int)$batch['quantity']);
                $pdo->beginTransaction();
                try {
                    $activeSt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM product_batches WHERE product_id = :pid AND status = 'active'");
                    $activeSt->execute(['pid' => $batch['product_id']]);
                    $oldActive = (int)$activeSt->fetchColumn();

                    // Disposal removes quantity ONLY from this batch. The product
                    // stock is then recomputed from the remaining active batches,
                    // so disposing an old batch can never wipe out newer stock.
                    $pdo->prepare('UPDATE product_batches SET quantity = quantity - :qty, status = IF(quantity - :qty2 <= 0, \'disposed\', status), disposed_at = IF(quantity - :qty3 <= 0, NOW(), disposed_at) WHERE id = :id')
                        ->execute(['qty' => $qty, 'qty2' => $qty, 'qty3' => $qty, 'id' => $batchId]);

                    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
                    $pdo->prepare('INSERT INTO product_disposals (batch_id, product_id, disposal_reason, disposal_date, quantity, batch_number, approved_by, remarks, created_by) VALUES (:bid, :pid, :reason, :ddate, :qty, :bn, :ab, :remarks, :cb)')
                        ->execute(['bid' => $batchId, 'pid' => $batch['product_id'], 'reason' => $disposalReason, 'ddate' => $disposalDate, 'qty' => $qty, 'bn' => $batch['batch_number'], 'ab' => $approvedBy > 0 ? $approvedBy : null, 'remarks' => $remarks, 'cb' => ($userId !== null && $userId > 0) ? $userId : null]);

                    // Recompute available stock from active batches only.
                    recalc_product_stock($pdo, (int)$batch['product_id']);

                    // Log the REAL change so no quantity is counted twice.
                    $activeSt->execute(['pid' => $batch['product_id']]);
                    $newActive = (int)$activeSt->fetchColumn();
                    $delta = $newActive - $oldActive;
                    if ($delta != 0) {
                        log_stock_movement($pdo, (int)$batch['product_id'], $delta, 'disposal: ' . $disposalReason, 'product_disposal', null);
                    }

                    $pdo->commit();
                    $messages[] = ['type' => 'success', 'text' => "Disposed {$qty} units of {$batch['batch_number']}."];
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $messages[] = ['type' => 'error', 'text' => $e->getMessage()];
                }
            } else {
                $messages[] = ['type' => 'error', 'text' => 'Batch not found.'];
            }
        }
    }
}

$summaryExpired = (int)$pdo->query("SELECT COUNT(DISTINCT pb.id) FROM product_batches pb WHERE pb.status = 'expired' AND pb.quantity > 0")->fetchColumn();
$summaryExpiring30 = (int)$pdo->query("SELECT COUNT(DISTINCT pb.id) FROM product_batches pb WHERE pb.status = 'active' AND pb.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND pb.quantity > 0")->fetchColumn();
$summaryExpiring90 = (int)$pdo->query("SELECT COUNT(DISTINCT pb.id) FROM product_batches pb WHERE pb.status = 'active' AND pb.expiry_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 31 DAY) AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) AND pb.quantity > 0")->fetchColumn();
$summaryQuarantined = (int)$pdo->query("SELECT COUNT(DISTINCT pb.id) FROM product_batches pb WHERE pb.status = 'quarantined' AND pb.quantity > 0")->fetchColumn();
$summaryDisposed = (int)$pdo->query("SELECT COUNT(*) FROM product_disposals")->fetchColumn();

$summaryExpiredValue = (float)$pdo->query("SELECT COALESCE(SUM(pb.quantity * pb.purchase_price), 0) FROM product_batches pb WHERE pb.status IN ('expired','quarantined') AND pb.quantity > 0")->fetchColumn();
$summaryDisposedValue = (float)$pdo->query("SELECT COALESCE(SUM(pd.quantity * pb.purchase_price), 0) FROM product_disposals pd JOIN product_batches pb ON pd.batch_id = pb.id")->fetchColumn();

$expiredBatches = $pdo->query("
    SELECT pb.*, p.name AS product_name, p.sku, p.product_code, p.brand, p.description, p.image_path, p.price, p.barcode, p.stock_quantity, c.name AS category_name
    FROM product_batches pb
    JOIN products p ON pb.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE pb.status = 'expired' AND pb.quantity > 0
    ORDER BY pb.expiry_date ASC
")->fetchAll();

$expiringSoon = $pdo->query("
    SELECT pb.*, p.name AS product_name, p.sku, p.product_code, p.brand, p.description, p.image_path, p.price, p.barcode, p.stock_quantity, c.name AS category_name,
        DATEDIFF(pb.expiry_date, CURDATE()) AS days_left
    FROM product_batches pb
    JOIN products p ON pb.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE pb.status = 'active' AND pb.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) AND pb.quantity > 0
    ORDER BY pb.expiry_date ASC
")->fetchAll();

$quarantinedBatches = $pdo->query("
    SELECT pb.*, p.name AS product_name, p.sku, p.product_code, p.brand, p.description, p.image_path, p.price, p.barcode, p.stock_quantity, c.name AS category_name
    FROM product_batches pb
    JOIN products p ON pb.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE pb.status = 'quarantined' AND pb.quantity > 0
    ORDER BY pb.quarantined_at DESC
")->fetchAll();

$disposalHistory = $pdo->query("
    SELECT pd.*, p.name AS product_name, p.sku, p.product_code,
        u1.full_name AS created_by_name, u2.full_name AS approved_by_name
    FROM product_disposals pd
    JOIN products p ON pd.product_id = p.id
    LEFT JOIN users u1 ON pd.created_by = u1.id
    LEFT JOIN users u2 ON pd.approved_by = u2.id
    ORDER BY pd.created_at DESC
    LIMIT 200
")->fetchAll();
?>
<style>
.exp-wrap{max-width:1600px;margin:0 auto}
.exp-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px;margin-bottom:20px}
.exp-card{background:#fff;border-radius:12px;border:1px solid var(--neutral-200);padding:16px;display:flex;align-items:center;gap:12px}
.exp-card-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.exp-card-val{font-size:1.25rem;font-weight:700;color:var(--brown-800)}
.exp-card-label{font-size:0.75rem;color:var(--neutral-500)}
.exp-modal{display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,0.5);align-items:center;justify-content:center}
.exp-modal.open{display:flex}
.exp-modal-box{background:#fff;border-radius:16px;max-width:600px;width:90%;max-height:85vh;overflow-y:auto;padding:24px;position:relative}
.exp-modal-close{position:absolute;top:12px;right:12px;background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--neutral-500)}
.exp-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:600;white-space:nowrap}
.exp-tbl{width:100%;border-collapse:collapse}
.exp-tbl th{padding:8px 12px;font-size:0.72rem;font-weight:700;color:var(--brown-700);text-transform:uppercase;letter-spacing:0.5px;border-bottom:2px solid var(--brown-200);background:var(--brown-50,#faf5f0);text-align:left;white-space:nowrap}
.exp-tbl td{padding:8px 12px;font-size:0.82rem;border-bottom:1px solid var(--neutral-100);vertical-align:middle}
.exp-tbl tr:hover{background:var(--neutral-50)}
@media print{.exp-filters,.exp-btn,.exp-card,.no-print{display:none!important}}
</style>

<section class="exp-wrap">
    <h1 style="font-size:1.4rem;font-weight:700;color:var(--brown-800);margin-bottom:16px"><i class="fas fa-shield-virus" style="margin-right:6px"></i> Expiry Management</h1>

    <?php foreach ($messages as $message): ?>
        <div class="alert alert-<?= htmlspecialchars($message['type']) ?>"><?= htmlspecialchars($message['text']) ?></div>
    <?php endforeach; ?>

    <div class="exp-cards">
        <div class="exp-card"><div class="exp-card-icon" style="background:#fef2f2;color:#dc2626"><i class="fas fa-skull-crossbones"></i></div><div><div class="exp-card-val" style="color:<?= $summaryExpired > 0 ? '#dc2626' : 'var(--brown-800)' ?>"><?= $summaryExpired ?></div><div class="exp-card-label">Expired Batches</div></div></div>
        <div class="exp-card"><div class="exp-card-icon" style="background:#fef3c7;color:#d97706"><i class="fas fa-exclamation-triangle"></i></div><div><div class="exp-card-val" style="color:<?= $summaryExpiring30 > 0 ? '#d97706' : 'var(--brown-800)' ?>"><?= $summaryExpiring30 ?></div><div class="exp-card-label">Expiring 30 Days</div></div></div>
        <div class="exp-card"><div class="exp-card-icon" style="background:#fef9c3;color:#a16207"><i class="fas fa-clock"></i></div><div><div class="exp-card-val"><?= $summaryExpiring90 ?></div><div class="exp-card-label">Expiring 31-90 Days</div></div></div>
        <div class="exp-card"><div class="exp-card-icon" style="background:#f3e8ff;color:#7c3aed"><i class="fas fa-vault"></i></div><div><div class="exp-card-val"><?= $summaryQuarantined ?></div><div class="exp-card-label">Quarantined</div></div></div>
        <div class="exp-card"><div class="exp-card-icon" style="background:#1f2937;color:#fff"><i class="fas fa-trash-can"></i></div><div><div class="exp-card-val"><?= $summaryDisposed ?></div><div class="exp-card-label">Disposed</div></div></div>
        <div class="exp-card"><div class="exp-card-icon" style="background:#fef2f2;color:#dc2626"><i class="fas fa-birr"></i></div><div><div class="exp-card-val" style="font-size:1.1rem"><?= fmt($summaryExpiredValue) ?></div><div class="exp-card-label">Expired Value</div></div></div>
        <div class="exp-card"><div class="exp-card-icon" style="background:#6b7280;color:#fff"><i class="fas fa-file-invoice"></i></div><div><div class="exp-card-val" style="font-size:1.1rem"><?= fmt($summaryDisposedValue) ?></div><div class="exp-card-label">Disposed Value</div></div></div>
    </div>

    <div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:16px" class="no-print">
        <a href="?page=expiry_management&tab=overview" class="tab-btn<?= $tab === 'overview' ? ' active' : '' ?>" style="padding:8px 16px;border-radius:8px;font-size:0.82rem;font-weight:600;text-decoration:none;background:<?= $tab === 'overview' ? 'var(--brown-700)' : 'var(--neutral-100)' ?>;color:<?= $tab === 'overview' ? '#fff' : 'var(--neutral-600)' ?>"><i class="fas fa-chart-pie"></i> Overview</a>
        <a href="?page=expiry_management&tab=expired" class="tab-btn<?= $tab === 'expired' ? ' active' : '' ?>" style="padding:8px 16px;border-radius:8px;font-size:0.82rem;font-weight:600;text-decoration:none;background:<?= $tab === 'expired' ? '#dc2626' : 'var(--neutral-100)' ?>;color:<?= $tab === 'expired' ? '#fff' : 'var(--neutral-600)' ?>"><i class="fas fa-skull-crossbones"></i> Expired (<?= $summaryExpired ?>)</a>
        <a href="?page=expiry_management&tab=expiring" class="tab-btn<?= $tab === 'expiring' ? ' active' : '' ?>" style="padding:8px 16px;border-radius:8px;font-size:0.82rem;font-weight:600;text-decoration:none;background:<?= $tab === 'expiring' ? '#d97706' : 'var(--neutral-100)' ?>;color:<?= $tab === 'expiring' ? '#fff' : 'var(--neutral-600)' ?>"><i class="fas fa-exclamation-triangle"></i> Expiring Soon</a>
        <a href="?page=expiry_management&tab=quarantine" class="tab-btn<?= $tab === 'quarantine' ? ' active' : '' ?>" style="padding:8px 16px;border-radius:8px;font-size:0.82rem;font-weight:600;text-decoration:none;background:<?= $tab === 'quarantine' ? '#7c3aed' : 'var(--neutral-100)' ?>;color:<?= $tab === 'quarantine' ? '#fff' : 'var(--neutral-600)' ?>"><i class="fas fa-vault"></i> Quarantine</a>
        <a href="?page=expiry_management&tab=disposal" class="tab-btn<?= $tab === 'disposal' ? ' active' : '' ?>" style="padding:8px 16px;border-radius:8px;font-size:0.82rem;font-weight:600;text-decoration:none;background:<?= $tab === 'disposal' ? '#374151' : 'var(--neutral-100)' ?>;color:<?= $tab === 'disposal' ? '#fff' : 'var(--neutral-600)' ?>"><i class="fas fa-trash-can"></i> Disposal History</a>
    </div>

<?php if ($tab === 'overview'): ?>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <?php if (!empty($expiredBatches)): ?>
        <div style="background:#fff;border-radius:12px;border:1px solid var(--neutral-200);overflow:hidden">
            <div style="padding:14px 16px;background:#fef2f2;border-bottom:1px solid #fecaca;display:flex;justify-content:space-between;align-items:center">
                <h3 style="font-size:0.95rem;font-weight:700;color:#991b1b;margin:0"><i class="fas fa-skull-crossbones" style="margin-right:6px"></i> Expired Products</h3>
                <a href="?page=expiry_management&tab=expired" style="font-size:0.82rem;color:#991b1b;font-weight:600">View All</a>
            </div>
            <div style="max-height:300px;overflow-y:auto">
                <table class="exp-tbl">
                    <thead><tr><th>Product</th><th>Batch</th><th>Expiry</th><th>Qty</th><th>Value</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($expiredBatches, 0, 10) as $b):
                        $dl = isset($b['days_left']) ? (int)$b['days_left'] : (int)floor((strtotime($b['expiry_date']) - time()) / 86400);
                    ?>
                    <tr>
                        <td><span style="font-weight:600"><?= htmlspecialchars($b['product_name']) ?></span><br><span style="font-size:0.72rem;color:var(--neutral-500)"><?= htmlspecialchars($b['brand'] ?? '') ?></span></td>
                        <td><span style="font-family:monospace;font-size:0.8rem"><?= htmlspecialchars($b['batch_number']) ?></span></td>
                        <td><span style="color:#991b1b;font-weight:600"><?= htmlspecialchars($b['expiry_date']) ?></span></td>
                        <td style="font-weight:700;color:#991b1b"><?= number_format((int)$b['quantity']) ?></td>
                        <td style="font-size:0.82rem"><?= fmt((float)$b['purchase_price'] * (int)$b['quantity']) ?></td>
                        <td style="white-space:nowrap"><button onclick="openProductDetail(<?= htmlspecialchars(json_encode(['product_name'=>$b['product_name'],'brand'=>$b['brand'],'sku'=>$b['sku'],'product_code'=>$b['product_code'],'category_name'=>$b['category_name'],'barcode'=>$b['barcode'],'price'=>$b['price'],'stock_quantity'=>$b['stock_quantity'],'batch_number'=>$b['batch_number'],'expiry_date'=>$b['expiry_date'],'quantity'=>$b['quantity'],'purchase_price'=>$b['purchase_price'],'description'=>$b['description'],'image_path'=>$b['image_path'],'status'=>'expired']),ENT_QUOTES) ?>)" style="padding:4px 8px;background:#1d4ed8;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:0.75rem;font-weight:600"><i class="fas fa-eye"></i></button> <?php if (hasPermission('expiry_management', 'edit')): ?><button onclick="openQuarantineModal(<?= (int)$b['id'] ?>,'<?= htmlspecialchars(addslashes($b['batch_number'])) ?>',<?= (int)$b['quantity'] ?>)" class="exp-btn" style="padding:4px 10px;background:#7c3aed;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:0.75rem;font-weight:600"><i class="fas fa-vault"></i> Quarantine</button><?php endif; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($expiringSoon)): ?>
        <div style="background:#fff;border-radius:12px;border:1px solid var(--neutral-200);overflow:hidden">
            <div style="padding:14px 16px;background:#fef3c7;border-bottom:1px solid #fde68a;display:flex;justify-content:space-between;align-items:center">
                <h3 style="font-size:0.95rem;font-weight:700;color:#92400e;margin:0"><i class="fas fa-exclamation-triangle" style="margin-right:6px"></i> Expiring Soon</h3>
                <a href="?page=expiry_management&tab=expiring" style="font-size:0.82rem;color:#92400e;font-weight:600">View All</a>
            </div>
            <div style="max-height:300px;overflow-y:auto">
                <table class="exp-tbl">
                    <thead><tr><th>Product</th><th>Batch</th><th>Expiry</th><th>Days Left</th><th>Qty</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($expiringSoon, 0, 10) as $b):
                        $dl = isset($b['days_left']) ? (int)$b['days_left'] : (int)floor((strtotime($b['expiry_date']) - time()) / 86400);
                        if ($dl <= 0) { $sl = 'Expired'; $sb = '#dc2626'; $sc = '#fff'; }
                        elseif ($dl <= 30) { $sl = $dl . ' days'; $sb = '#fef2f2'; $sc = '#dc2626'; }
                        else { $sl = $dl . ' days'; $sb = '#fefce8'; $sc = '#a16207'; }
                    ?>
                    <tr>
                        <td><span style="font-weight:600"><?= htmlspecialchars($b['product_name']) ?></span></td>
                        <td><span style="font-family:monospace;font-size:0.8rem"><?= htmlspecialchars($b['batch_number']) ?></span></td>
                        <td><?= htmlspecialchars($b['expiry_date']) ?></td>
                        <td><span class="exp-badge" style="background:<?= $sb ?>;color:<?= $sc ?>"><?= $sl ?></span></td>
                        <td style="font-weight:700"><?= number_format((int)$b['quantity']) ?></td>
                        <td><span class="exp-badge" style="background:#fef3c7;color:#92400e"><i class="fas fa-clock"></i> Expiring</span></td>
                        <td><button onclick="openProductDetail(<?= htmlspecialchars(json_encode(['product_name'=>$b['product_name'],'brand'=>$b['brand'],'sku'=>$b['sku'],'product_code'=>$b['product_code'],'category_name'=>$b['category_name'],'barcode'=>$b['barcode'],'price'=>$b['price'],'stock_quantity'=>$b['stock_quantity'],'batch_number'=>$b['batch_number'],'expiry_date'=>$b['expiry_date'],'quantity'=>$b['quantity'],'purchase_price'=>$b['purchase_price'],'description'=>$b['description'],'image_path'=>$b['image_path'],'status'=>$dl<=0?'expired':'active']),ENT_QUOTES) ?>)" style="padding:4px 8px;background:#1d4ed8;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:0.75rem;font-weight:600"><i class="fas fa-eye"></i></button></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'expired'): ?>
    <div style="background:#fff;border-radius:12px;border:1px solid var(--neutral-200);padding:20px">
        <h2 style="font-size:1.1rem;font-weight:700;color:#991b1b;margin-bottom:16px"><i class="fas fa-skull-crossbones" style="margin-right:6px"></i> Expired Products (<?= count($expiredBatches) ?> batches)</h2>
        <?php if (empty($expiredBatches)): ?>
            <p style="text-align:center;padding:30px;color:var(--neutral-500)"><i class="fas fa-check-circle" style="font-size:2rem;display:block;margin-bottom:8px;color:#16a34a"></i>No expired products found.</p>
        <?php else: ?>
        <div style="overflow-x:auto">
            <table class="exp-tbl">
                <thead><tr><th>Product</th><th>SKU</th><th>Batch</th><th>Category</th><th>Expiry Date</th><th>Days Expired</th><th>Qty</th><th>Value</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($expiredBatches as $b):
                    $dl = abs((int)floor((strtotime($b['expiry_date']) - time()) / 86400));
                ?>
                <tr>
                    <td><span style="font-weight:600;color:var(--brown-800)"><?= htmlspecialchars($b['product_name']) ?></span><br><span style="font-size:0.72rem;color:var(--neutral-500)"><?= htmlspecialchars($b['brand'] ?? '') ?></span></td>
                    <td><span style="font-family:monospace;font-size:0.78rem;color:var(--brown-700)"><?= htmlspecialchars($b['sku'] ?? '--') ?></span></td>
                    <td><span style="font-family:monospace;font-size:0.8rem;font-weight:600"><?= htmlspecialchars($b['batch_number']) ?></span></td>
                    <td style="font-size:0.82rem"><?= htmlspecialchars($b['category_name'] ?? '--') ?></td>
                    <td style="font-weight:700;color:#991b1b"><?= htmlspecialchars($b['expiry_date']) ?></td>
                    <td><span class="exp-badge" style="background:#fef2f2;color:#991b1b"><?= $dl ?> days</span></td>
                    <td style="font-weight:700;color:#991b1b;font-size:0.9rem"><?= number_format((int)$b['quantity']) ?></td>
                    <td style="font-size:0.82rem"><?= fmt((float)$b['purchase_price'] * (int)$b['quantity']) ?></td>
                    <td>
                        <div style="display:flex;gap:4px;flex-wrap:wrap">
                            <button onclick="openProductDetail(<?= htmlspecialchars(json_encode(['product_name'=>$b['product_name'],'brand'=>$b['brand'],'sku'=>$b['sku'],'product_code'=>$b['product_code'],'category_name'=>$b['category_name'],'barcode'=>$b['barcode'],'price'=>$b['price'],'stock_quantity'=>$b['stock_quantity'],'batch_number'=>$b['batch_number'],'expiry_date'=>$b['expiry_date'],'quantity'=>$b['quantity'],'purchase_price'=>$b['purchase_price'],'description'=>$b['description'],'image_path'=>$b['image_path'],'status'=>'expired']),ENT_QUOTES) ?>)" style="padding:4px 8px;background:#1d4ed8;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:0.75rem;font-weight:600"><i class="fas fa-eye"></i></button>
                            <?php if (hasPermission('expiry_management', 'edit')): ?><button onclick="openQuarantineModal(<?= (int)$b['id'] ?>,'<?= htmlspecialchars(addslashes($b['batch_number'])) ?>',<?= (int)$b['quantity'] ?>)" style="padding:4px 10px;background:#7c3aed;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:0.75rem;font-weight:600"><i class="fas fa-vault"></i> Quarantine</button><?php endif; ?>
                            <?php if (hasPermission('expiry_management', 'delete')): ?><button onclick="openDisposeModal(<?= (int)$b['id'] ?>,'<?= htmlspecialchars(addslashes($b['batch_number'])) ?>',<?= (int)$b['quantity'] ?>,'<?= htmlspecialchars(addslashes($b['product_name'])) ?>')" style="padding:4px 10px;background:#dc2626;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:0.75rem;font-weight:600"><i class="fas fa-trash"></i> Dispose</button><?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'expiring'): ?>
    <div style="background:#fff;border-radius:12px;border:1px solid var(--neutral-200);padding:20px">
        <h2 style="font-size:1.1rem;font-weight:700;color:#92400e;margin-bottom:16px"><i class="fas fa-exclamation-triangle" style="margin-right:6px"></i> Expiring Soon (within 90 days)</h2>
        <?php if (empty($expiringSoon)): ?>
            <p style="text-align:center;padding:30px;color:var(--neutral-500)"><i class="fas fa-check-circle" style="font-size:2rem;display:block;margin-bottom:8px;color:#16a34a"></i>No products expiring within 90 days.</p>
        <?php else: ?>
        <div style="overflow-x:auto">
            <table class="exp-tbl">
                <thead><tr><th>Product</th><th>SKU</th><th>Batch</th><th>Category</th><th>Expiry Date</th><th>Days Left</th><th>Qty</th><th>Value</th><th>Urgency</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($expiringSoon as $b):
                    $dl = isset($b['days_left']) ? (int)$b['days_left'] : (int)floor((strtotime($b['expiry_date']) - time()) / 86400);
                    if ($dl <= 0) { $sl = 'Expired'; $sb = '#dc2626'; $sc = '#fff'; $si = 'fa-skull-crossbones'; }
                    elseif ($dl <= 30) { $sl = $dl . ' days'; $sb = '#fef2f2'; $sc = '#dc2626'; $si = 'fa-exclamation-circle'; }
                    else { $sl = $dl . ' days'; $sb = '#fefce8'; $sc = '#a16207'; $si = 'fa-clock'; }
                ?>
                <tr>
                    <td><span style="font-weight:600;color:var(--brown-800)"><?= htmlspecialchars($b['product_name']) ?></span><br><span style="font-size:0.72rem;color:var(--neutral-500)"><?= htmlspecialchars($b['brand'] ?? '') ?></span></td>
                    <td><span style="font-family:monospace;font-size:0.78rem"><?= htmlspecialchars($b['sku'] ?? '--') ?></span></td>
                    <td><span style="font-family:monospace;font-size:0.8rem;font-weight:600"><?= htmlspecialchars($b['batch_number']) ?></span></td>
                    <td style="font-size:0.82rem"><?= htmlspecialchars($b['category_name'] ?? '--') ?></td>
                    <td style="font-weight:600"><?= htmlspecialchars($b['expiry_date']) ?></td>
                    <td><span class="exp-badge" style="background:<?= $sb ?>;color:<?= $sc ?>"><i class="fas <?= $si ?>" style="font-size:0.68rem"></i> <?= $sl ?></span></td>
                    <td style="font-weight:700"><?= number_format((int)$b['quantity']) ?></td>
                    <td style="font-size:0.82rem"><?= fmt((float)$b['purchase_price'] * (int)$b['quantity']) ?></td>
                    <td>
                        <?php if ($dl <= 30): ?>
                            <span class="exp-badge" style="background:#dc2626;color:#fff"><i class="fas fa-fire"></i> Urgent</span>
                        <?php else: ?>
                            <span class="exp-badge" style="background:#fef3c7;color:#92400e"><i class="fas fa-clock"></i> Warning</span>
                        <?php endif; ?>
                    </td>
                    <td><button onclick="openProductDetail(<?= htmlspecialchars(json_encode(['product_name'=>$b['product_name'],'brand'=>$b['brand'],'sku'=>$b['sku'],'product_code'=>$b['product_code'],'category_name'=>$b['category_name'],'barcode'=>$b['barcode'],'price'=>$b['price'],'stock_quantity'=>$b['stock_quantity'],'batch_number'=>$b['batch_number'],'expiry_date'=>$b['expiry_date'],'quantity'=>$b['quantity'],'purchase_price'=>$b['purchase_price'],'description'=>$b['description'],'image_path'=>$b['image_path'],'status'=>$dl<=0?'expired':'active']),ENT_QUOTES) ?>)" style="padding:4px 8px;background:#1d4ed8;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:0.75rem;font-weight:600"><i class="fas fa-eye"></i></button></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'quarantine'): ?>
    <div style="background:#fff;border-radius:12px;border:1px solid var(--neutral-200);padding:20px">
        <h2 style="font-size:1.1rem;font-weight:700;color:#7c3aed;margin-bottom:16px"><i class="fas fa-vault" style="margin-right:6px"></i> Quarantine Inventory (<?= count($quarantinedBatches) ?> batches)</h2>
        <?php if (empty($quarantinedBatches)): ?>
            <p style="text-align:center;padding:30px;color:var(--neutral-500)"><i class="fas fa-vault" style="font-size:2rem;display:block;margin-bottom:8px;color:var(--neutral-300)"></i>No quarantined products.</p>
        <?php else: ?>
        <div style="overflow-x:auto">
            <table class="exp-tbl">
                <thead><tr><th>Product</th><th>SKU</th><th>Batch</th><th>Expiry Date</th><th>Qty</th><th>Value</th><th>Quarantined</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($quarantinedBatches as $b): ?>
                <tr>
                    <td><span style="font-weight:600;color:var(--brown-800)"><?= htmlspecialchars($b['product_name']) ?></span><br><span style="font-size:0.72rem;color:var(--neutral-500)"><?= htmlspecialchars($b['brand'] ?? '') ?></span></td>
                    <td><span style="font-family:monospace;font-size:0.78rem"><?= htmlspecialchars($b['sku'] ?? '--') ?></span></td>
                    <td><span style="font-family:monospace;font-size:0.8rem;font-weight:600"><?= htmlspecialchars($b['batch_number']) ?></span></td>
                    <td style="font-weight:600;color:#991b1b"><?= htmlspecialchars($b['expiry_date']) ?></td>
                    <td style="font-weight:700"><?= number_format((int)$b['quantity']) ?></td>
                    <td style="font-size:0.82rem"><?= fmt((float)$b['purchase_price'] * (int)$b['quantity']) ?></td>
                    <td style="font-size:0.82rem;color:var(--neutral-500)"><?= $b['quarantined_at'] ? htmlspecialchars($b['quarantined_at']) : '--' ?></td>
                    <td><div style="display:flex;gap:4px;flex-wrap:wrap"><button onclick="openProductDetail(<?= htmlspecialchars(json_encode(['product_name'=>$b['product_name'],'brand'=>$b['brand'],'sku'=>$b['sku'],'product_code'=>$b['product_code'],'category_name'=>$b['category_name'],'barcode'=>$b['barcode'],'price'=>$b['price'],'stock_quantity'=>$b['stock_quantity'],'batch_number'=>$b['batch_number'],'expiry_date'=>$b['expiry_date'],'quantity'=>$b['quantity'],'purchase_price'=>$b['purchase_price'],'description'=>$b['description'],'image_path'=>$b['image_path'],'status'=>'quarantined']),ENT_QUOTES) ?>)" style="padding:4px 8px;background:#1d4ed8;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:0.75rem;font-weight:600"><i class="fas fa-eye"></i></button><?php if (hasPermission('expiry_management', 'delete')): ?><button onclick="openDisposeModal(<?= (int)$b['id'] ?>,'<?= htmlspecialchars(addslashes($b['batch_number'])) ?>',<?= (int)$b['quantity'] ?>,'<?= htmlspecialchars(addslashes($b['product_name'])) ?>')" style="padding:4px 10px;background:#dc2626;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:0.75rem;font-weight:600"><i class="fas fa-trash"></i> Dispose</button><?php endif; ?></div></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'disposal'): ?>
    <div style="background:#fff;border-radius:12px;border:1px solid var(--neutral-200);padding:20px">
        <h2 style="font-size:1.1rem;font-weight:700;color:#374151;margin-bottom:16px"><i class="fas fa-trash-can" style="margin-right:6px"></i> Disposal History (<?= count($disposalHistory) ?> records)</h2>
        <?php if (empty($disposalHistory)): ?>
            <p style="text-align:center;padding:30px;color:var(--neutral-500)"><i class="fas fa-history" style="font-size:2rem;display:block;margin-bottom:8px;color:var(--neutral-300)"></i>No disposal records found.</p>
        <?php else: ?>
        <div style="overflow-x:auto">
            <table class="exp-tbl">
                <thead><tr><th>Date</th><th>Product</th><th>Batch</th><th>Qty</th><th>Reason</th><th>Approved By</th><th>Recorded By</th><th>Remarks</th></tr></thead>
                <tbody>
                <?php foreach ($disposalHistory as $d): ?>
                <tr>
                    <td style="white-space:nowrap"><?= htmlspecialchars($d['disposal_date']) ?></td>
                    <td><span style="font-weight:600;color:var(--brown-800)"><?= htmlspecialchars($d['product_name']) ?></span><br><span style="font-size:0.72rem;color:var(--neutral-500)"><?= htmlspecialchars($d['sku'] ?? '') ?></span></td>
                    <td><span style="font-family:monospace;font-size:0.8rem"><?= htmlspecialchars($d['batch_number']) ?></span></td>
                    <td style="font-weight:700;color:#991b1b"><?= number_format((int)$d['quantity']) ?></td>
                    <td style="font-size:0.82rem"><?= htmlspecialchars($d['disposal_reason']) ?></td>
                    <td style="font-size:0.82rem"><?= htmlspecialchars($d['approved_by_name'] ?? '--') ?></td>
                    <td style="font-size:0.82rem"><?= htmlspecialchars($d['created_by_name'] ?? '--') ?></td>
                    <td style="font-size:0.82rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($d['remarks'] ?? '') ?>"><?= htmlspecialchars($d['remarks'] ?? '--') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div id="quarantine-modal" class="exp-modal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="exp-modal-box">
        <button class="exp-modal-close" onclick="document.getElementById('quarantine-modal').classList.remove('open')"><i class="fas fa-times"></i></button>
        <h3 style="font-size:1.1rem;font-weight:700;color:#7c3aed;margin-bottom:16px"><i class="fas fa-vault" style="margin-right:6px"></i> Quarantine Batch</h3>
        <p style="font-size:0.85rem;color:var(--neutral-500);margin-bottom:16px">This will move the expired batch into quarantine inventory, preventing it from being sold.</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="quarantine">
            <input type="hidden" name="batch_id" id="q-batch-id">
            <div style="background:#f5f3ff;padding:12px;border-radius:8px;margin-bottom:16px;font-size:0.85rem">
                <strong>Batch:</strong> <span id="q-batch-num"></span><br>
                <strong>Quantity:</strong> <span id="q-batch-qty"></span>
            </div>
            <button type="submit" style="padding:10px 20px;background:#7c3aed;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600"><i class="fas fa-vault" style="margin-right:4px"></i> Confirm Quarantine</button>
            <button type="button" onclick="document.getElementById('quarantine-modal').classList.remove('open')" style="padding:10px 20px;background:var(--neutral-100);color:var(--neutral-600);border:none;border-radius:8px;cursor:pointer;font-weight:600;margin-left:8px">Cancel</button>
        </form>
    </div>
</div>

<div id="dispose-modal" class="exp-modal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="exp-modal-box">
        <button class="exp-modal-close" onclick="document.getElementById('dispose-modal').classList.remove('open')"><i class="fas fa-times"></i></button>
        <h3 style="font-size:1.1rem;font-weight:700;color:#dc2626;margin-bottom:16px"><i class="fas fa-trash-can" style="margin-right:6px"></i> Dispose Product</h3>
        <p style="font-size:0.85rem;color:var(--neutral-500);margin-bottom:16px">Record the disposal of expired/quarantined stock. This action is permanent.</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="dispose">
            <input type="hidden" name="batch_id" id="d-batch-id">
            <div style="background:#fef2f2;padding:12px;border-radius:8px;margin-bottom:16px;font-size:0.85rem">
                <strong>Product:</strong> <span id="d-product-name"></span><br>
                <strong>Batch:</strong> <span id="d-batch-num"></span>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
                <label style="display:block;font-size:0.82rem;font-weight:600;color:var(--neutral-600);margin-bottom:4px">Disposal Reason <span style="color:#dc2626">*</span>
                    <select name="disposal_reason" required style="width:100%;padding:8px;border:1px solid var(--neutral-300);border-radius:8px;font-size:0.85rem">
                        <option value="">Select reason...</option>
                        <option value="Expired - Regulatory">Expired - Regulatory</option>
                        <option value="Contaminated">Contaminated</option>
                        <option value="Damaged Packaging">Damaged Packaging</option>
                        <option value="Temperature Breach">Temperature Breach</option>
                        <option value="Recalled">Recalled</option>
                        <option value="Other">Other</option>
                    </select>
                </label>
                <label style="display:block;font-size:0.82rem;font-weight:600;color:var(--neutral-600);margin-bottom:4px">Disposal Date <span style="color:#dc2626">*</span>
                    <input type="date" name="disposal_date" value="<?= date('Y-m-d') ?>" required style="width:100%;padding:8px;border:1px solid var(--neutral-300);border-radius:8px;font-size:0.85rem">
                </label>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
                <label style="display:block;font-size:0.82rem;font-weight:600;color:var(--neutral-600);margin-bottom:4px">Quantity <span style="color:#dc2626">*</span>
                    <input type="number" name="quantity" id="d-qty" min="1" required style="width:100%;padding:8px;border:1px solid var(--neutral-300);border-radius:8px;font-size:0.85rem">
                </label>
                <label style="display:block;font-size:0.82rem;font-weight:600;color:var(--neutral-600);margin-bottom:4px">Batch Number
                    <input type="text" name="batch_number" id="d-batch-input" readonly style="width:100%;padding:8px;border:1px solid var(--neutral-300);border-radius:8px;font-size:0.85rem;background:var(--neutral-50)">
                </label>
            </div>
            <div style="margin-bottom:12px">
                <label style="display:block;font-size:0.82rem;font-weight:600;color:var(--neutral-600);margin-bottom:4px">Approved By <span style="color:var(--neutral-400);font-weight:500">(auto-assigned — your account)</span></label>
                <div style="padding:8px 10px;border:1px solid var(--neutral-300);border-radius:8px;font-size:0.85rem;background:var(--neutral-50);color:var(--neutral-700)"><?= htmlspecialchars((string)(currentUser()['full_name'] ?? '')) ?: '—' ?></div>
            </div>
            <div style="margin-bottom:16px">
                <label style="display:block;font-size:0.82rem;font-weight:600;color:var(--neutral-600);margin-bottom:4px">Remarks</label>
                <textarea name="remarks" rows="3" style="width:100%;padding:8px;border:1px solid var(--neutral-300);border-radius:8px;font-size:0.85rem;resize:vertical" placeholder="Additional notes about this disposal..."></textarea>
            </div>
            <button type="submit" onclick="return confirm('Are you sure you want to permanently dispose of this stock?')" style="padding:10px 20px;background:#dc2626;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600"><i class="fas fa-trash" style="margin-right:4px"></i> Confirm Disposal</button>
            <button type="button" onclick="document.getElementById('dispose-modal').classList.remove('open')" style="padding:10px 20px;background:var(--neutral-100);color:var(--neutral-600);border:none;border-radius:8px;cursor:pointer;font-weight:600;margin-left:8px">Cancel</button>
        </form>
    </div>
</div>

<div id="product-detail-modal" class="exp-modal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="exp-modal-box" style="max-width:520px">
        <button class="exp-modal-close" onclick="document.getElementById('product-detail-modal').classList.remove('open')"><i class="fas fa-times"></i></button>
        <h3 style="font-size:1.05rem;font-weight:700;color:var(--brown-800);margin-bottom:14px"><i class="fas fa-pills" style="margin-right:6px;color:#7c3aed"></i> Product Details</h3>
        <div style="display:flex;gap:16px;margin-bottom:16px">
            <div id="pd-img-wrap" style="width:90px;height:90px;border-radius:10px;background:var(--neutral-100);display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden">
                <img id="pd-img" src="" alt="" style="display:none;width:100%;height:100%;object-fit:cover">
                <i id="pd-img-icon" class="fas fa-pills" style="font-size:1.6rem;color:var(--neutral-300)"></i>
            </div>
            <div style="flex:1;min-width:0">
                <div id="pd-name" style="font-size:1rem;font-weight:700;color:var(--brown-800);margin-bottom:2px"></div>
                <div id="pd-brand" style="font-size:0.8rem;color:var(--neutral-500);margin-bottom:4px"></div>
                <div id="pd-status" style="margin-bottom:4px"></div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <span id="pd-sku" class="exp-badge" style="background:var(--neutral-100);color:var(--neutral-600);font-family:monospace"></span>
                    <span id="pd-code" class="exp-badge" style="background:#ede9fe;color:#7c3aed;font-family:monospace"></span>
                </div>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
            <div style="background:var(--neutral-50);padding:10px 12px;border-radius:8px"><div style="font-size:0.7rem;color:var(--neutral-500);margin-bottom:2px">Category</div><div id="pd-cat" style="font-size:0.85rem;font-weight:600"></div></div>
            <div style="background:var(--neutral-50);padding:10px 12px;border-radius:8px"><div style="font-size:0.7rem;color:var(--neutral-500);margin-bottom:2px">Barcode</div><div id="pd-barcode" style="font-size:0.85rem;font-weight:600;font-family:monospace"></div></div>
            <div style="background:var(--neutral-50);padding:10px 12px;border-radius:8px"><div style="font-size:0.7rem;color:var(--neutral-500);margin-bottom:2px">Retail Price</div><div id="pd-price" style="font-size:0.85rem;font-weight:700;color:#16a34a"></div></div>
            <div style="background:var(--neutral-50);padding:10px 12px;border-radius:8px"><div style="font-size:0.7rem;color:var(--neutral-500);margin-bottom:2px">Total Stock</div><div id="pd-stock" style="font-size:0.85rem;font-weight:700"></div></div>
        </div>
        <div style="margin-bottom:14px"><div style="font-size:0.72rem;font-weight:700;color:var(--neutral-500);text-transform:uppercase;margin-bottom:4px">Batch Info</div>
            <div style="background:var(--neutral-50);padding:10px 12px;border-radius:8px;display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:0.82rem">
                <div><span style="color:var(--neutral-500)">Batch:</span> <strong id="pd-batch" style="font-family:monospace"></strong></div>
                <div><span style="color:var(--neutral-500)">Expiry:</span> <strong id="pd-expiry"></strong></div>
                <div><span style="color:var(--neutral-500)">Batch Qty:</span> <strong id="pd-qty"></strong></div>
                <div><span style="color:var(--neutral-500)">Batch Price:</span> <strong id="pd-batch-price"></strong></div>
                <div><span style="color:var(--neutral-500)">Batch Value:</span> <strong id="pd-value" style="color:#16a34a"></strong></div>
                <div><span style="color:var(--neutral-500)">Days:</span> <strong id="pd-days"></strong></div>
            </div>
        </div>
        <div id="pd-desc-wrap" style="margin-bottom:0"><div style="font-size:0.72rem;font-weight:700;color:var(--neutral-500);text-transform:uppercase;margin-bottom:4px">Description</div><div id="pd-desc" style="font-size:0.82rem;color:var(--neutral-600);background:var(--neutral-50);padding:10px 12px;border-radius:8px;max-height:80px;overflow-y:auto"></div></div>
    </div>
</div>

<script>
var CURRENCY = '<?= addslashes(SettingsService::currencySymbol()) ?>';
function openQuarantineModal(batchId, batchNum, qty) {
    document.getElementById('q-batch-id').value = batchId;
    document.getElementById('q-batch-num').textContent = batchNum;
    document.getElementById('q-batch-qty').textContent = qty + ' units';
    document.getElementById('quarantine-modal').classList.add('open');
}
function openDisposeModal(batchId, batchNum, qty, productName) {
    document.getElementById('d-batch-id').value = batchId;
    document.getElementById('d-product-name').textContent = productName;
    document.getElementById('d-batch-num').textContent = batchNum;
    document.getElementById('d-batch-input').value = batchNum;
    document.getElementById('d-qty').value = qty;
    document.getElementById('d-qty').max = qty;
    document.getElementById('dispose-modal').classList.add('open');
}
function openProductDetail(b) {
    var m = document.getElementById('product-detail-modal');
    document.getElementById('pd-name').textContent = b.product_name || '';
    document.getElementById('pd-brand').textContent = b.brand || '';
    document.getElementById('pd-sku').textContent = 'SKU: ' + (b.sku || '--');
    document.getElementById('pd-code').textContent = b.product_code || '';
    document.getElementById('pd-cat').textContent = b.category_name || '--';
    document.getElementById('pd-barcode').textContent = b.barcode || '--';
    document.getElementById('pd-price').textContent = CURRENCY + ' ' + parseFloat(b.price || 0).toFixed(2);
    document.getElementById('pd-stock').textContent = parseInt(b.stock_quantity || 0).toLocaleString() + ' units';
    document.getElementById('pd-batch').textContent = b.batch_number || '';
    document.getElementById('pd-expiry').textContent = b.expiry_date || '';
    document.getElementById('pd-qty').textContent = parseInt(b.quantity || 0).toLocaleString();
    document.getElementById('pd-batch-price').textContent = CURRENCY + ' ' + parseFloat(b.purchase_price || 0).toFixed(2);
    document.getElementById('pd-value').textContent = CURRENCY + ' ' + (parseFloat(b.purchase_price || 0) * parseInt(b.quantity || 0)).toFixed(2);
    var now = new Date(), exp = new Date(b.expiry_date), diff = Math.ceil((exp - now) / 86400);
    var daysEl = document.getElementById('pd-days');
    if (b.status === 'expired' || diff <= 0) { daysEl.textContent = Math.abs(diff) + ' days expired'; daysEl.style.color = '#dc2626'; }
    else { daysEl.textContent = diff + ' days left'; daysEl.style.color = diff <= 30 ? '#dc2626' : '#a16207'; }
    var statusColors = { expired: ['#dc2626','Expired'], quarantined: ['#7c3aed','Quarantined'], disposed: ['#374151','Disposed'], active: ['#16a34a','Active'] };
    var sc = statusColors[b.status] || ['var(--neutral-500)', b.status];
    document.getElementById('pd-status').innerHTML = '<span class="exp-badge" style="background:' + sc[0] + ';color:#fff">' + sc[1] + '</span>';
    var img = document.getElementById('pd-img'), icon = document.getElementById('pd-img-icon');
    if (b.image_path) { var ip = b.image_path; img.src = /^https?:\/\//i.test(ip) ? ip : (ip.indexOf('uploads/') === 0 ? ip : 'uploads/products/' + ip); img.style.display = 'block'; icon.style.display = 'none'; }
    else { img.style.display = 'none'; icon.style.display = ''; }
    var desc = b.description || '';
    var dw = document.getElementById('pd-desc-wrap');
    if (desc) { document.getElementById('pd-desc').textContent = desc; dw.style.display = ''; } else { dw.style.display = 'none'; }
    m.classList.add('open');
}
</script>
</section>
