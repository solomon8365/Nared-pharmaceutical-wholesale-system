<?php
require_once __DIR__ . '/../services/init.php';
require_once __DIR__ . '/../includes/delivery_helpers.php';
require_once __DIR__ . '/../includes/auto_migrate.php';
requireDeliveryOperator();
requirePermission('assign_deliveries', 'view');
$pdo = delGetDb();
DeliveryService::ensureMigration($pdo);
ensureFulfillmentMigration($pdo);
$messages = [];
$userId = (int)(currentUser()['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_csrf();
    $act = $_POST['action'];

    if ($act === 'assign_single') {
        $res = DeliveryService::assignSingle($pdo, (int)($_POST['order_id'] ?? 0), (int)($_POST['driver_id'] ?? 0), $userId);
        $messages[] = ['type' => $res['success'] ? 'success' : 'error', 'text' => $res['msg']];
    } elseif ($act === 'bulk_assign') {
        $orderIds = array_filter(array_map('intval', $_POST['order_ids'] ?? []));
        $res = DeliveryService::bulkAssign($pdo, $orderIds, (int)($_POST['driver_id'] ?? 0), $userId);
        $messages[] = ['type' => $res['success'] ? 'success' : 'error', 'text' => $res['msg']];
    } elseif ($act === 'reassign') {
        $res = DeliveryService::reassignToDriver($pdo, (int)($_POST['order_id'] ?? 0), (int)($_POST['new_driver_id'] ?? 0), $userId);
        $messages[] = ['type' => $res['success'] ? 'success' : 'error', 'text' => $res['msg']];
    } elseif ($act === 'unassign') {
        $res = DeliveryService::unassign($pdo, (int)($_POST['order_id'] ?? 0), $userId);
        $messages[] = ['type' => $res['success'] ? 'success' : 'error', 'text' => $res['msg']];
    }
    safeRedirect('index.php?page=delivery_assign');
}

$readyCount = (int)$pdo->query("SELECT COUNT(*) FROM customer_orders WHERE status = 'approved' AND payment_confirmed = 1 AND delivery_staff_id IS NULL AND (delivery_status IS NULL OR delivery_status = '') AND fulfillment_method = 'delivery' AND fulfillment_status = 'ready_for_delivery'")->fetchColumn();
$assignedCount = (int)$pdo->query("SELECT COUNT(*) FROM customer_orders WHERE delivery_staff_id IS NOT NULL AND delivery_status IN ('assigned','accepted','picked_up','out_for_delivery')")->fetchColumn();
$availableDrivers = delGetAvailableDrivers($pdo);
$availableCount = count($availableDrivers);

$readyOrders = $pdo->query("
    SELECT co.id, co.order_date, co.total_amount, co.delivery_address,
           c.name AS customer_name, c.phone AS customer_phone,
           (SELECT COUNT(*) FROM customer_order_items WHERE customer_order_id = co.id) AS item_count
    FROM customer_orders co
    LEFT JOIN customers c ON co.customer_id = c.id
    WHERE co.status = 'approved' AND co.payment_confirmed = 1
      AND co.delivery_staff_id IS NULL AND (co.delivery_status IS NULL OR co.delivery_status = '')
      AND co.fulfillment_method = 'delivery' AND co.fulfillment_status = 'ready_for_delivery'
    ORDER BY co.order_date ASC
")->fetchAll();

$assignedOrders = $pdo->query("
    SELECT co.id, co.order_date, co.total_amount, co.delivery_status, co.assigned_at,
           c.name AS customer_name, dp.full_name AS driver_name,
           admin.full_name AS assigned_by_name
    FROM customer_orders co
    LEFT JOIN customers c ON co.customer_id = c.id
    LEFT JOIN driver_profiles dp ON co.delivery_staff_id = dp.id
    LEFT JOIN users admin ON co.assigned_by = admin.id
    WHERE co.delivery_staff_id IS NOT NULL
      AND co.delivery_status IN ('assigned','accepted','picked_up','out_for_delivery')
    ORDER BY FIELD(co.delivery_status, 'assigned','accepted','picked_up','out_for_delivery'), co.assigned_at DESC
")->fetchAll();

$allDrivers = delGetAllDrivers($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Driver — NARED PHARMACON</title>
    <?= delCss() ?>
    <style>
    .bulk-bar{display:none;position:sticky;top:0;z-index:50;background:linear-gradient(135deg,#1e40af,#2563eb);color:#fff;padding:12px 20px;border-radius:12px;margin-bottom:14px;align-items:center;gap:14px;box-shadow:0 4px 20px rgba(37,99,235,.3)}
    .bulk-bar.show{display:flex}
    .bulk-bar .bulk-count{font-weight:800;font-size:1.1rem}
    .bulk-bar select{padding:8px 12px;border-radius:8px;border:none;font-size:.85rem;min-width:180px}
    .bulk-bar button{padding:8px 20px;border-radius:8px;border:none;font-weight:700;cursor:pointer;font-size:.85rem}
    .row-check{width:18px;height:18px;cursor:pointer;accent-color:var(--del-blue)}
    .assigned-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:20px;font-size:.75rem;font-weight:600;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
    .reassign-btn{padding:4px 10px;border:1px solid #fde68a;background:#fffbeb;color:#92400e;border-radius:6px;font-size:.72rem;font-weight:600;cursor:pointer}
    .reassign-btn:hover{background:#fef3c7}
    </style>
</head>
<body>
<?php $activeTab = $_GET['tab'] ?? 'assign'; ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var tab = '<?= $activeTab ?>';
    if (tab === 'reassign') {
        var btns = document.querySelectorAll('.del-tab');
        if (btns.length >= 2) delSwitchTab(btns[1], 'tab-assigned');
    }
});
</script>
<div class="del-wrap" style="padding:20px">
    <h1><i class="fas fa-user-check" style="color:var(--del-blue)"></i> Assign Driver</h1>
    <p class="del-subtitle">Assign or reassign drivers to approved orders — select multiple orders for bulk assignment</p>

    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= $m['type'] === 'success' ? 'success' : 'error' ?>" style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
            <i class="fas <?= $m['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
            <?= htmlspecialchars($m['text']) ?>
        </div>
    <?php endforeach; ?>

    <div class="del-stats">
        <div class="del-stat">
            <div class="del-stat-icon" style="background:linear-gradient(135deg,var(--del-orange),var(--del-orange-d))"><i class="fas fa-clock"></i></div>
            <div class="del-stat-body">
                <div class="del-stat-val" data-counter="<?= $readyCount ?>"><?= $readyCount ?></div>
                <div class="del-stat-label">Ready to Assign</div>
            </div>
        </div>
        <div class="del-stat">
            <div class="del-stat-icon" style="background:linear-gradient(135deg,var(--del-blue),var(--del-blue-d))"><i class="fas fa-truck"></i></div>
            <div class="del-stat-body">
                <div class="del-stat-val" data-counter="<?= $assignedCount ?>"><?= $assignedCount ?></div>
                <div class="del-stat-label">Currently Assigned</div>
            </div>
        </div>
        <div class="del-stat">
            <div class="del-stat-icon" style="background:linear-gradient(135deg,var(--del-green),var(--del-green-d))"><i class="fas fa-motorcycle"></i></div>
            <div class="del-stat-body">
                <div class="del-stat-val" data-counter="<?= $availableCount ?>"><?= $availableCount ?></div>
                <div class="del-stat-label">Available Drivers</div>
            </div>
        </div>
    </div>

    <div class="del-card">
        <div class="del-tabs">
            <button class="del-tab active" onclick="delSwitchTab(this,'tab-ready')"><i class="fas fa-clock"></i> Ready to Assign (<?= $readyCount ?>)</button>
            <button class="del-tab" onclick="delSwitchTab(this,'tab-assigned')"><i class="fas fa-truck"></i> Assigned Orders (<?= $assignedCount ?>)</button>
        </div>

        <div id="tab-ready" class="del-panel active">
            <?php if (hasPermission('assign_deliveries', 'assign_delivery')): ?>
            <div class="bulk-bar" id="bulk-bar">
                <span><span class="bulk-count" id="bulk-count">0</span> orders selected</span>
                <div style="display:flex;align-items:center;gap:8px">
                    <label style="font-weight:600;font-size:.82rem">Assign to:</label>
                    <select id="bulk-driver-select">
                        <option value="">— Select Driver —</option>
                        <?php foreach ($allDrivers as $d): ?>
                            <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['full_name']) ?> (<?= $d['availability'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button onclick="submitBulkAssign()" style="background:#fff;color:#1e40af"><i class="fas fa-user-plus"></i> Assign Selected</button>
                <button onclick="clearSelection()" style="background:rgba(255,255,255,.2);color:#fff">Clear</button>
            </div>
            <?php endif; ?>

            <?php if (empty($readyOrders)): ?>
                <div class="del-empty"><i class="fas fa-check-double"></i><p>All caught up! No orders waiting for assignment.</p></div>
            <?php else: ?>
                <div style="overflow-x:auto">
                    <table class="del-table" id="ready-table">
                        <thead>
                            <tr>
                                <th style="width:40px"><input type="checkbox" class="row-check" id="select-all" onchange="toggleSelectAll(this)"></th>
                                <th>Order #</th>
                                <th>Customer</th>
                                <th>Date</th>
                                <th>Total</th>
                                <th>Address</th>
                                <th>Items</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($readyOrders as $o): ?>
                                <tr data-order-id="<?= (int)$o['id'] ?>">
                                    <td><input type="checkbox" class="row-check order-cb" value="<?= (int)$o['id'] ?>" onchange="updateBulkBar()"></td>
                                    <td><strong>#<?= (int)$o['id'] ?></strong></td>
                                    <td>
                                        <div style="font-weight:600;color:#0f172a"><?= htmlspecialchars($o['customer_name'] ?? 'Unknown') ?></div>
                                        <?php if (!empty($o['customer_phone'])): ?>
                                            <div style="font-size:.72rem;color:var(--del-gray)"><i class="fas fa-phone" style="width:12px"></i> <?= htmlspecialchars($o['customer_phone']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="white-space:nowrap"><?= htmlspecialchars($o['order_date']) ?></td>
                                    <td style="font-weight:700;color:var(--del-green-d)"><?= fmt((float)$o['total_amount']) ?></td>
                                    <td style="max-width:180px;font-size:.78rem;color:#64748b"><?= htmlspecialchars($o['delivery_address'] ?? '-') ?></td>
                                    <td style="text-align:center"><span style="background:var(--del-bg);padding:3px 8px;border-radius:6px;font-size:.75rem;font-weight:700;color:var(--del-blue)"><?= (int)$o['item_count'] ?></span></td>
                                    <td>
                                        <?php if (hasPermission('assign_deliveries', 'assign_delivery')): ?>
                                        <button class="del-btn del-btn-blue del-btn-sm" onclick="openSingleAssign(<?= (int)$o['id'] ?>, this)"><i class="fas fa-user-plus"></i> Assign</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div id="tab-assigned" class="del-panel">
            <?php if (empty($assignedOrders)): ?>
                <div class="del-empty"><i class="fas fa-inbox"></i><p>No assigned orders.</p></div>
            <?php else: ?>
                <div style="overflow-x:auto">
                    <table class="del-table">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Customer</th>
                                <th>Driver</th>
                                <th>Status</th>
                                <th>Assigned By</th>
                                <th>Assigned At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignedOrders as $o): ?>
                                <?php $locked = !in_array($o['delivery_status'], ['assigned', 'accepted', 'picked_up']); ?>
                                <tr>
                                    <td><strong>#<?= (int)$o['id'] ?></strong></td>
                                    <td><?= htmlspecialchars($o['customer_name'] ?? 'Unknown') ?></td>
                                    <td style="font-weight:600"><?= htmlspecialchars($o['driver_name'] ?? 'Unknown') ?></td>
                                    <td><?= delBadgeHtml($o['delivery_status']) ?></td>
                                    <td style="font-size:.78rem;color:var(--del-gray)"><?= htmlspecialchars($o['assigned_by_name'] ?? '—') ?></td>
                                    <td style="white-space:nowrap;font-size:.78rem;color:var(--del-gray)"><?= $o['assigned_at'] ? date('M d, H:i', strtotime($o['assigned_at'])) : '—' ?></td>
                                    <td style="white-space:nowrap">
                                        <?php if ($locked): ?>
                                            <span style="font-size:.75rem;color:var(--del-red);font-weight:600"><i class="fas fa-lock"></i> Already Assigned to <?= htmlspecialchars($o['driver_name'] ?? 'Driver') ?></span>
                                        <?php else: ?>
                                            <?php if (hasPermission('assign_deliveries', 'assign_delivery')): ?>
                                            <form method="post" style="display:inline" onsubmit="return confirm('Unassign order #<?= (int)$o['id'] ?>?')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="unassign">
                                                <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                                                <button type="submit" class="del-btn del-btn-red del-btn-sm"><i class="fas fa-user-times"></i> Unassign</button>
                                            </form>
                                            <button class="reassign-btn" onclick="openReassign(<?= (int)$o['id'] ?>, '<?= htmlspecialchars($o['driver_name'] ?? '', ENT_QUOTES) ?>')"><i class="fas fa-exchange-alt"></i> Reassign</button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="del-modal-overlay" id="modal-single-assign">
    <div class="del-modal">
        <h3><i class="fas fa-user-check" style="color:var(--del-blue)"></i> Assign Delivery</h3>
        <div style="background:var(--del-bg);border-radius:10px;padding:14px 16px;margin-bottom:16px;font-size:.82rem">
            <div style="display:flex;flex-direction:column;gap:8px">
                <div style="display:flex;align-items:center;gap:8px">
                    <span style="color:var(--del-gray);min-width:90px">Order:</span> <strong id="s-order-id" style="color:var(--del-blue)"></strong>
                </div>
                <div style="display:flex;align-items:center;gap:8px">
                    <span style="color:var(--del-gray);min-width:90px">Customer:</span> <strong id="s-order-customer"></strong>
                </div>
                <div style="display:flex;align-items:center;gap:8px">
                    <span style="color:var(--del-gray);min-width:90px">Phone:</span> <strong id="s-order-phone"></strong>
                </div>
                <div style="display:flex;align-items:center;gap:8px">
                    <span style="color:var(--del-gray);min-width:90px">Order Date:</span> <strong id="s-order-date"></strong>
                </div>
                <div style="display:flex;align-items:center;gap:8px">
                    <span style="color:var(--del-gray);min-width:90px">Total:</span> <strong id="s-order-total" style="color:var(--del-green-d)"></strong>
                </div>
                <div style="display:flex;align-items:flex-start;gap:8px">
                    <span style="color:var(--del-gray);min-width:90px">Address:</span> <strong id="s-order-address"></strong>
                </div>
            </div>
        </div>
        <form method="post" id="single-assign-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="assign_single">
            <input type="hidden" name="order_id" id="s-order-input">
            <div class="del-form-group">
                <label>Select Driver</label>
                <select name="driver_id" id="s-driver-select" required onchange="showVehicle(this, 's-vehicle-info', 's-vehicle-type', 's-vehicle-plate')">
                    <option value="">— Choose Driver —</option>
                    <?php foreach ($allDrivers as $d): ?>
                        <?php if ($d['availability'] === 'available'): ?>
                            <option value="<?= (int)$d['id'] ?>" data-type="<?= htmlspecialchars($d['vehicle_type'] ?? '') ?>" data-plate="<?= htmlspecialchars($d['vehicle_plate'] ?? '') ?>"><?= htmlspecialchars($d['full_name']) ?> (<?= htmlspecialchars($d['vehicle_type'] ?? 'N/A') ?>)</option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="s-vehicle-info" style="display:none;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:.82rem">
                <i class="fas fa-motorcycle" style="color:var(--del-blue)"></i>
                <strong id="s-vehicle-type"></strong> — <span id="s-vehicle-plate"></span>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end">
                <button type="button" class="del-btn del-btn-outline" onclick="delCloseModal('modal-single-assign')">Cancel</button>
                <?php if (hasPermission('assign_deliveries', 'assign_delivery')): ?>
                <button type="submit" class="del-btn del-btn-blue"><i class="fas fa-check"></i> Confirm Assignment</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="del-modal-overlay" id="modal-reassign">
    <div class="del-modal">
        <h3><i class="fas fa-exchange-alt" style="color:var(--del-orange)"></i> Reassign Delivery</h3>
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:.82rem;color:#92400e">
            <i class="fas fa-info-circle"></i> Currently assigned to: <strong id="r-current-driver"></strong>
        </div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reassign">
            <input type="hidden" name="order_id" id="r-order-input">
            <div class="del-form-group">
                <label>Select New Driver</label>
                <select name="new_driver_id" id="r-driver-select" required onchange="showVehicle(this, 'r-vehicle-info', 'r-vehicle-type', 'r-vehicle-plate')">
                    <option value="">— Choose Driver —</option>
                    <?php foreach ($allDrivers as $d): ?>
                        <?php if ($d['availability'] === 'available'): ?>
                            <option value="<?= (int)$d['id'] ?>" data-type="<?= htmlspecialchars($d['vehicle_type'] ?? '') ?>" data-plate="<?= htmlspecialchars($d['vehicle_plate'] ?? '') ?>"><?= htmlspecialchars($d['full_name']) ?> (<?= htmlspecialchars($d['vehicle_type'] ?? 'N/A') ?>)</option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="r-vehicle-info" style="display:none;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:.82rem">
                <i class="fas fa-motorcycle" style="color:var(--del-blue)"></i>
                <strong id="r-vehicle-type"></strong> — <span id="r-vehicle-plate"></span>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end">
                <button type="button" class="del-btn del-btn-outline" onclick="delCloseModal('modal-reassign')">Cancel</button>
                <?php if (hasPermission('assign_deliveries', 'assign_delivery')): ?>
                <button type="submit" class="del-btn del-btn-orange"><i class="fas fa-exchange-alt"></i> Reassign</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<script>
function openSingleAssign(orderId, btn) {
    document.getElementById('s-order-input').value = orderId;
    var tr = btn.closest('tr');
    var cells = tr.querySelectorAll('td');
    // cells[0]=checkbox, cells[1]=Order#, cells[2]=Customer, cells[3]=Date, cells[4]=Total, cells[5]=Address
    var custCell = cells[2];
    var custDivs = custCell.querySelectorAll('div');
    var custName = custDivs.length > 0 ? custDivs[0].textContent.trim() : custCell.textContent.trim();
    var custPhone = custDivs.length > 1 ? custDivs[1].textContent.trim() : '';
    document.getElementById('s-order-id').textContent = '#' + orderId;
    document.getElementById('s-order-customer').textContent = custName;
    document.getElementById('s-order-phone').textContent = custPhone || '—';
    document.getElementById('s-order-date').textContent = cells[3].textContent.trim();
    document.getElementById('s-order-total').textContent = cells[4].textContent.trim();
    document.getElementById('s-order-address').textContent = cells[5].textContent.trim();
    document.getElementById('s-driver-select').value = '';
    document.getElementById('s-vehicle-info').style.display = 'none';
    delOpenModal('modal-single-assign');
}
function openReassign(orderId, driverName) {
    document.getElementById('r-order-input').value = orderId;
    document.getElementById('r-current-driver').textContent = driverName;
    document.getElementById('r-driver-select').value = '';
    document.getElementById('r-vehicle-info').style.display = 'none';
    delOpenModal('modal-reassign');
}
function showVehicle(sel, infoId, typeId, plateId) {
    var opt = sel.options[sel.selectedIndex];
    var info = document.getElementById(infoId);
    if (sel.value && opt.getAttribute('data-type')) {
        document.getElementById(typeId).textContent = opt.getAttribute('data-type') || 'N/A';
        document.getElementById(plateId).textContent = opt.getAttribute('data-plate') || 'N/A';
        info.style.display = 'block';
    } else {
        info.style.display = 'none';
    }
}
function toggleSelectAll(cb) {
    document.querySelectorAll('.order-cb').forEach(function(c) { c.checked = cb.checked; });
    updateBulkBar();
}
function updateBulkBar() {
    var checked = document.querySelectorAll('.order-cb:checked');
    var count = checked.length;
    document.getElementById('bulk-count').textContent = count;
    var bar = document.getElementById('bulk-bar');
    if (count > 0) {
        bar.classList.add('show');
    } else {
        bar.classList.remove('show');
    }
}
function clearSelection() {
    document.querySelectorAll('.order-cb').forEach(function(c) { c.checked = false; });
    document.getElementById('select-all').checked = false;
    updateBulkBar();
}
function submitBulkAssign() {
    var driverId = document.getElementById('bulk-driver-select').value;
    if (!driverId) { alert('Please select a driver.'); return; }
    var checked = document.querySelectorAll('.order-cb:checked');
    if (checked.length === 0) { alert('Please select at least one order.'); return; }
    var ids = [];
    checked.forEach(function(c) { ids.push(c.value); });
    var form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<?= csrf_field() ?>' +
        '<input type="hidden" name="action" value="bulk_assign">' +
        '<input type="hidden" name="driver_id" value="' + driverId + '">';
    ids.forEach(function(id) {
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'order_ids[]';
        inp.value = id;
        form.appendChild(inp);
    });
    document.body.appendChild(form);
    form.submit();
}
</script>
<?= delJs() ?>
</body>
</html>
