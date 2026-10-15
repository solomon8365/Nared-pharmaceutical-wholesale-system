<?php
requirePermission('physical_count', 'view');
require_once __DIR__ . '/../includes/stock_helper.php';
$pdo = getPDO();
$messages = [];

$warehouses = $pdo->query('SELECT id, name FROM warehouses WHERE warehouse_status = \'active\' ORDER BY name')->fetchAll();
$products = $pdo->query('SELECT id, name, stock_quantity, purchase_price FROM products ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    // Every physical-count mutation (create, update items, complete, cancel,
    // reverse) adjusts stock, so it requires the create permission. Previously
    // only 'create' was checked and complete/reverse/cancel were unprotected.
    requirePermission('physical_count', 'create');
    $action = $_POST['_action'] ?? '';

    if ($action === 'create') {
        requirePermission('physical_count', 'create');
        $whId = !empty($_POST['warehouse_id']) ? (int)$_POST['warehouse_id'] : null;
        $cDate = $_POST['count_date'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO physical_counts (warehouse_id, count_date, status, notes, created_by) VALUES (:wh, :dt, \'draft\', :notes, :uid)');
            $uid = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
            $stmt->execute(['wh' => $whId, 'dt' => $cDate, 'notes' => $notes, 'uid' => ($uid !== null && $uid > 0) ? $uid : null]);
            $pcId = (int) $pdo->lastInsertId();

            $insItem = $pdo->prepare('INSERT INTO physical_count_items (physical_count_id, product_id, batch_id, expected_qty, unit_cost) VALUES (:pcid, :pid, :bid, :eq, :uc)');

            // Add all products with batches (if warehouse selected, filter by warehouse)
            if ($whId) {
                $batches = $pdo->prepare('SELECT pb.*, p.purchase_price FROM product_batches pb JOIN products p ON pb.product_id = p.id WHERE pb.warehouse_id = :wh AND pb.quantity > 0');
                $batches->execute(['wh' => $whId]);
            } else {
                $batches = $pdo->query('SELECT pb.*, p.purchase_price FROM product_batches pb JOIN products p ON pb.product_id = p.id WHERE pb.quantity > 0');
            }
            foreach ($batches as $b) {
                $insItem->execute(['pcid' => $pcId, 'pid' => $b['product_id'], 'bid' => $b['id'], 'eq' => $b['quantity'], 'uc' => $b['purchase_price']]);
            }

            // Add non-batch products
            $noBatchSql = 'SELECT p.id, p.name, p.stock_quantity, p.purchase_price FROM products p WHERE p.stock_quantity > 0 AND NOT EXISTS (SELECT 1 FROM product_batches pb WHERE pb.product_id = p.id)';
            $nonBatch = $pdo->query($noBatchSql);
            foreach ($nonBatch as $nb) {
                $insItem->execute(['pcid' => $pcId, 'pid' => $nb['id'], 'bid' => null, 'eq' => $nb['stock_quantity'], 'uc' => $nb['purchase_price']]);
            }

            $pdo->commit();
            $messages[] = ['type' => 'success', 'text' => "Count #{$pcId} created with items."];
        } catch (Exception $e) {
            $pdo->rollBack();
            $messages[] = ['type' => 'error', 'text' => 'Failed: ' . $e->getMessage()];
        }
    } elseif ($action === 'update_items') {
        $pcId = (int) ($_POST['count_id'] ?? 0);
        $actualQtys = $_POST['actual_qty'] ?? [];
        $itemNotes = $_POST['item_notes'] ?? [];

        $upd = $pdo->prepare('UPDATE physical_count_items SET actual_qty = :aq, notes = :notes WHERE id = :id AND physical_count_id = :pcid');
        foreach ($actualQtys as $itemId => $qty) {
            $itemId = (int)$itemId;
            $qty = max(0, (int)$qty);
            $n = trim($itemNotes[$itemId] ?? '');
            $upd->execute(['aq' => $qty, 'notes' => $n, 'id' => $itemId, 'pcid' => $pcId]);
        }
        $messages[] = ['type' => 'success', 'text' => 'Count values saved.'];
    } elseif ($action === 'complete') {
        $pcId = (int) ($_POST['count_id'] ?? 0);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM physical_counts WHERE id = :id AND status = 'draft' FOR UPDATE");
            $stmt->execute(['id' => $pcId]);
            if (!$stmt->fetch()) throw new RuntimeException('Count not found or not in draft.');

            $items = $pdo->prepare('SELECT pci.*, p.name AS pname FROM physical_count_items pci JOIN products p ON pci.product_id = p.id WHERE pci.physical_count_id = :pcid');
            $items->execute(['pcid' => $pcId]);
            $hasDiff = false;

            foreach ($items->fetchAll() as $item) {
                $expected = (int)$item['expected_qty'];
                $actual = (int)$item['actual_qty'];
                $diff = $actual - $expected;
                if ($diff === 0) continue;
                $hasDiff = true;

                $pid = (int)$item['product_id'];
                $bid = $item['batch_id'] ? (int)$item['batch_id'] : null;

                if ($bid && $diff < 0) {
                    $deduct = abs($diff);
                    $bst = $pdo->prepare('SELECT quantity FROM product_batches WHERE id = :id FOR UPDATE');
                    $bst->execute(['id' => $bid]);
                    $batch = $bst->fetch();
                    if ($batch && (int)$batch['quantity'] >= $deduct) {
                        $pdo->prepare('UPDATE product_batches SET quantity = quantity - :q WHERE id = :id')
                            ->execute(['q' => $deduct, 'id' => $bid]);
                    } else {
                        $pdo->prepare('UPDATE product_batches SET quantity = 0 WHERE id = :id')
                            ->execute(['id' => $bid]);
                    }
                } elseif ($bid && $diff > 0) {
                    $pdo->prepare('UPDATE product_batches SET quantity = quantity + :q WHERE id = :id')
                        ->execute(['q' => $diff, 'id' => $bid]);
                }

                if ($bid) {
                    recalc_product_stock($pdo, $pid);
                } else {
                    $pdo->prepare('UPDATE products SET stock_quantity = GREATEST(0, stock_quantity + :diff) WHERE id = :id')
                        ->execute(['diff' => $diff, 'id' => $pid]);
                }
                log_stock_movement($pdo, $pid, $diff, 'Physical count adjustment', 'physical_count', $pcId);
            }

            $pdo->prepare("UPDATE physical_counts SET status = 'completed', completed_at = NOW() WHERE id = :id")
                ->execute(['id' => $pcId]);
            $pdo->commit();
            $messages[] = ['type' => 'success', 'text' => "Count #{$pcId} completed. Stock adjusted." . ($hasDiff ? '' : ' (no discrepancies)')];
        } catch (Exception $e) {
            $pdo->rollBack();
            $messages[] = ['type' => 'error', 'text' => 'Failed: ' . $e->getMessage()];
        }
    } elseif ($action === 'cancel') {
        $pcId = (int) ($_POST['count_id'] ?? 0);
        $pdo->prepare("UPDATE physical_counts SET status = 'cancelled' WHERE id = :id AND status = 'draft'")->execute(['id' => $pcId]);
        $messages[] = ['type' => 'success', 'text' => "Count #{$pcId} cancelled."];
    } elseif ($action === 'reverse') {
        $pcId = (int) ($_POST['count_id'] ?? 0);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM physical_counts WHERE id = :id AND status = 'completed' FOR UPDATE");
            $stmt->execute(['id' => $pcId]);
            if (!$stmt->fetch()) throw new RuntimeException('Count not found or not completed.');

            $items = $pdo->prepare('SELECT pci.*, p.name AS pname FROM physical_count_items pci JOIN products p ON pci.product_id = p.id WHERE pci.physical_count_id = :pcid');
            $items->execute(['pcid' => $pcId]);

            foreach ($items->fetchAll() as $item) {
                $expected = (int)$item['expected_qty'];
                $actual = (int)$item['actual_qty'];
                $diff = $expected - $actual;
                if ($diff === 0) continue;

                $pid = (int)$item['product_id'];
                $bid = $item['batch_id'] ? (int)$item['batch_id'] : null;

                if ($bid && $diff > 0) {
                    $pdo->prepare('UPDATE product_batches SET quantity = quantity + :q WHERE id = :id')
                        ->execute(['q' => $diff, 'id' => $bid]);
                } elseif ($bid && $diff < 0) {
                    $deduct = abs($diff);
                    $bst = $pdo->prepare('SELECT quantity FROM product_batches WHERE id = :id FOR UPDATE');
                    $bst->execute(['id' => $bid]);
                    $batch = $bst->fetch();
                    if ($batch && (int)$batch['quantity'] >= $deduct) {
                        $pdo->prepare('UPDATE product_batches SET quantity = quantity - :q WHERE id = :id')
                            ->execute(['q' => $deduct, 'id' => $bid]);
                    } else {
                        $pdo->prepare('UPDATE product_batches SET quantity = 0 WHERE id = :id')
                            ->execute(['id' => $bid]);
                    }
                }

                if ($bid) {
                    recalc_product_stock($pdo, $pid);
                } else {
                    $pdo->prepare('UPDATE products SET stock_quantity = GREATEST(0, stock_quantity + :diff) WHERE id = :id')
                        ->execute(['diff' => $diff, 'id' => $pid]);
                }
                log_stock_movement($pdo, $pid, $diff, 'Physical count reversal', 'physical_count', $pcId);
            }

            $pdo->prepare("UPDATE physical_counts SET status = 'reversed', completed_at = NOW() WHERE id = :id")
                ->execute(['id' => $pcId]);
            $pdo->commit();
            $messages[] = ['type' => 'success', 'text' => "Count #{$pcId} reversed. Stock restored."];
        } catch (Exception $e) {
            $pdo->rollBack();
            $messages[] = ['type' => 'error', 'text' => 'Reverse failed: ' . $e->getMessage()];
        }
    }
}

$filterStatus = $_GET['status'] ?? '';
$where = []; $params = [];
if ($filterStatus !== '') { $where[] = 'pc.status = :s'; $params['s'] = $filterStatus; }
$sql = 'SELECT pc.*, w.name AS warehouse_name FROM physical_counts pc LEFT JOIN warehouses w ON pc.warehouse_id = w.id';
if (!empty($where)) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY pc.created_at DESC';
$counts = $pdo->prepare($sql);
$counts->execute($params);
$counts = $counts->fetchAll();

$editCount = null;
$editItems = [];
if (isset($_GET['count'])) {
    $stmt = $pdo->prepare('SELECT * FROM physical_counts WHERE id = :id');
    $stmt->execute(['id' => (int)$_GET['count']]);
    $editCount = $stmt->fetch();
    if ($editCount) {
        $stmt = $pdo->prepare('SELECT pci.*, p.name AS pname FROM physical_count_items pci JOIN products p ON pci.product_id = p.id WHERE pci.physical_count_id = :pcid ORDER BY p.name');
        $stmt->execute(['pcid' => $editCount['id']]);
        $editItems = $stmt->fetchAll();
    }
}
?>
<section>
    <h1>Physical Count</h1>
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <div class="tab-bar">
        <?php if (hasPermission('physical_count', 'create')): ?>
        <button class="tab-btn<?= !$editCount ? ' active' : '' ?>" onclick="switchTab(this,'pc-create')"><i class="fas fa-plus"></i> New Count</button>
        <?php endif; ?>
        <button class="tab-btn" onclick="switchTab(this,'pc-list')"><i class="fas fa-list"></i> Count History</button>
        <?php if ($editCount): ?>
            <button class="tab-btn active" onclick="switchTab(this,'pc-count')"><i class="fas fa-clipboard-check"></i> Count #<?= $editCount['id'] ?></button>
        <?php endif; ?>
    </div>

    <div class="tab-content">
        <?php if (hasPermission('physical_count', 'create')): ?>
        <div id="pc-create" class="tab-panel<?= !$editCount ? ' active' : '' ?>">
            <div class="panel" style="max-width:500px">
                <h2>Start New Count</h2>
                <form method="post" class="form-grid">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="create">
                    <label>Warehouse
                        <select name="warehouse_id">
                            <option value="">— All warehouses —</option>
                            <?php foreach ($warehouses as $w): ?>
                                <option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Count Date<input type="date" name="count_date" value="<?= date('Y-m-d') ?>" required></label>
                    <label>Notes<textarea name="notes" rows="2"></textarea></label>
                    <button type="submit">Generate Count Sheet</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div id="pc-list" class="tab-panel">
            <div class="panel">
                <h2>Count History</h2>
                <form method="get" style="margin-bottom:12px;display:flex;gap:8px">
                    <input type="hidden" name="page" value="physical_count">
                    <select name="status" style="min-width:120px;margin:0">
                        <option value="">All Statuses</option>
                        <?php foreach (['draft' => 'Draft','completed' => 'Completed','cancelled' => 'Cancelled','reversed' => 'Reversed'] as $v => $l): ?>
                            <option value="<?= $v ?>"<?= $filterStatus === $v ? ' selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" style="margin:0">Filter</button>
                    <?php if ($filterStatus !== ''): ?><a href="?page=physical_count" style="display:inline-flex;align-items:center;padding:0 12px;color:var(--brown-800);font-weight:600">Clear</a><?php endif; ?>
                </form>
                <div style="overflow-x:auto">
                <table>
                    <thead><tr><th>#</th><th>Date</th><th>Warehouse</th><th>Status</th><th>Notes</th><th></th></tr></thead>
                    <tbody>
                        <?php if (empty($counts)): ?>
                            <tr><td colspan="6">No counts yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($counts as $c):
                                $s = $c['status'];
                                $sc = $s === 'completed' ? '#059669' : ($s === 'cancelled' ? '#dc2626' : ($s === 'reversed' ? '#7c3aed' : '#f59e0b'));
                            ?>
                                <tr>
                                    <td><?= (int)$c['id'] ?></td>
                                    <td><?= htmlspecialchars($c['count_date']) ?></td>
                                    <td><?= htmlspecialchars($c['warehouse_name'] ?? 'All') ?></td>
                                    <td><span style="display:inline-block;padding:2px 8px;border-radius:4px;font-weight:700;font-size:0.75rem;background:<?= $sc ?>20;color:<?= $sc ?>"><?= ucfirst($s) ?></span></td>
                                    <td style="font-size:0.82rem"><?= htmlspecialchars($c['notes'] ?? '—') ?></td>
                                    <td>
                                        <?php if ($s === 'draft'): ?>
                                            <?php if (hasPermission('physical_count', 'create')): ?>
                                            <a href="?page=physical_count&count=<?= (int)$c['id'] ?>" style="padding:3px 8px;font-size:0.78rem;background:#2563eb;color:#fff;border-radius:4px;text-decoration:none">Enter Count</a>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <a href="?page=physical_count&count=<?= (int)$c['id'] ?>" style="padding:3px 8px;font-size:0.78rem;background:var(--neutral-400);color:#fff;border-radius:4px;text-decoration:none">View</a>
                                            <?php if ($s === 'completed' && hasPermission('physical_count', 'create')): ?>
                                            <form method="post" style="display:inline" onsubmit="return confirm('Reverse this count? Stock will be restored to pre-count levels.')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="_action" value="reverse">
                                                <input type="hidden" name="count_id" value="<?= (int)$c['id'] ?>">
                                                <button type="submit" style="padding:3px 8px;font-size:0.78rem;background:#7c3aed;color:#fff;border:none;border-radius:4px;cursor:pointer">Reverse</button>
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

        <?php if ($editCount): ?>
        <?php if ($editCount['status'] === 'draft' && !hasPermission('physical_count', 'create')): ?>
            <div class="panel"><p class="hint">You need the Physical Count "Create/Enter" permission to work on draft counts.</p></div>
        <?php else: ?>
        <div id="pc-count" class="tab-panel active">
            <div class="panel">
                <h2>Count #<?= $editCount['id'] ?> — <?= htmlspecialchars($editCount['warehouse_name'] ?? 'All Warehouses') ?> — <?= htmlspecialchars($editCount['count_date']) ?></h2>
                <p style="margin-bottom:8px">Status: <strong><?= ucfirst($editCount['status']) ?></strong>
                    <?php if ($editCount['status'] === 'draft'): ?>
                        <span style="color:var(--neutral-500);font-size:0.85rem"> — Enter actual quantities below, then click "Complete Count"</span>
                    <?php endif; ?>
                </p>
                <?php if (empty($editItems)): ?>
                    <p class="hint">No items in this count.</p>
                <?php else: ?>
                <form method="post" style="overflow-x:auto">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="update_items">
                    <input type="hidden" name="count_id" value="<?= $editCount['id'] ?>">
                    <table>
                        <thead><tr><th>Product</th><th>Expected Qty</th><th>Actual Qty</th><th>Diff</th><th>Notes</th></tr></thead>
                        <tbody>
                            <?php foreach ($editItems as $item):
                                $diff = (int)$item['actual_qty'] - (int)$item['expected_qty'];
                                $readonly = $editCount['status'] !== 'draft' ? ' readonly' : '';
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['pname']) ?>
                                        <?php if ($item['batch_id']): ?>
                                            <br><span style="font-size:0.75rem;color:var(--neutral-500)">Batch #<?= (int)$item['batch_id'] ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center"><?= (int)$item['expected_qty'] ?></td>
                                    <td><input type="number" name="actual_qty[<?= $item['id'] ?>]" value="<?= (int)$item['actual_qty'] ?>" min="0" style="width:70px;font-size:0.85rem;text-align:center"<?= $readonly ?>></td>
                                    <td style="text-align:center;font-weight:700;color:<?= $diff < 0 ? '#dc2626' : ($diff > 0 ? '#059669' : 'inherit') ?>"><?= $diff !== 0 ? ($diff > 0 ? '+' : '') . $diff : '—' ?></td>
                                    <td><input type="text" name="item_notes[<?= $item['id'] ?>]" value="<?= htmlspecialchars($item['notes'] ?? '') ?>" style="width:100%;font-size:0.82rem"<?= $readonly ?>></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($editCount['status'] === 'draft'): ?>
                        <div style="display:flex;gap:8px;margin-top:8px">
                            <button type="submit">Save Count Values</button>
                            <button type="submit" formaction="?page=physical_count" formmethod="post" name="_action" value="complete" style="background:#059669;color:#fff" onclick="return confirm('Complete this count? Stock will be adjusted for differences.')">Complete Count</button>
                            <button type="submit" formaction="?page=physical_count" formmethod="post" name="_action" value="cancel" style="background:#dc2626;color:#fff" onclick="return confirm('Cancel this count?')">Cancel Count</button>
                        </div>
                    <?php elseif ($editCount['status'] === 'completed' && hasPermission('physical_count', 'create')): ?>
                        <div style="margin-top:8px">
                            <form method="post" style="display:inline" onsubmit="return confirm('Reverse this count? Stock will be restored to pre-count levels.')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="reverse">
                                <input type="hidden" name="count_id" value="<?= (int)$editCount['id'] ?>">
                                <button type="submit" style="padding:6px 14px;background:#7c3aed;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600"><i class="fas fa-undo"></i> Reverse This Count</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
