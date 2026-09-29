<?php
require_once __DIR__ . '/../includes/delivery_helpers.php';
requireDeliveryOperator();
requirePermission('delivery_staff', 'view');
$pdo = delGetDb();
ensureDeliveryErpMigration($pdo);
$messages = [];
$userId = (int)(currentUser()['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_driver'])) {
    require_csrf();
    $driverId = (int)($_POST['driver_id'] ?? 0);
    $fullName = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $vehicleType = trim($_POST['vehicle_type'] ?? '');
    $vehiclePlate = trim($_POST['vehicle_plate'] ?? '');
    $licenseNo = trim($_POST['license_no'] ?? '');

    if ($fullName === '' || $vehicleType === '' || $licenseNo === '') {
        $messages[] = ['type' => 'error', 'text' => 'Full name, vehicle type, and license number are required.'];
    } else {
        try {
            if ($driverId > 0) {
                $stmt = $pdo->prepare("UPDATE driver_profiles SET full_name = ?, phone = ?, vehicle_type = ?, vehicle_plate = ?, license_no = ? WHERE id = ?");
                $stmt->execute([$fullName, $phone !== '' ? $phone : null, $vehicleType, $vehiclePlate, $licenseNo, $driverId]);
                if ($stmt->rowCount() === 0) {
                    $messages[] = ['type' => 'error', 'text' => 'Driver profile not found.'];
                } else {
                    delLog($pdo, 0, 'driver_updated', null, null, $userId, "Updated driver '{$fullName}' (driver #{$driverId})");
                    $messages[] = ['type' => 'success', 'text' => 'Driver profile updated successfully.'];
                }
            } else {
                $stmt = $pdo->prepare("INSERT INTO driver_profiles (full_name, phone, vehicle_type, vehicle_plate, license_no, availability, status) VALUES (?, ?, ?, ?, ?, 'offline', 'active')");
                $stmt->execute([$fullName, $phone !== '' ? $phone : null, $vehicleType, $vehiclePlate, $licenseNo]);
                delLog($pdo, 0, 'driver_registered', null, null, $userId, "Registered driver '{$fullName}' (driver #{$pdo->lastInsertId()})");
                $messages[] = ['type' => 'success', 'text' => "Driver '{$fullName}' registered successfully."];
            }
        } catch (Throwable $e) {
            $messages[] = ['type' => 'error', 'text' => 'Could not save driver: ' . $e->getMessage()];
        }
        safeRedirect('index.php?page=delivery_staff');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_availability'])) {
    require_csrf();
    $driverId = (int)($_POST['driver_id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';
    $validStatuses = ['available', 'busy', 'on_delivery', 'offline', 'on_leave'];

    if ($driverId <= 0 || !in_array($newStatus, $validStatuses)) {
        $messages[] = ['type' => 'error', 'text' => 'Invalid request.'];
    } else {
        $stmt = $pdo->prepare("UPDATE driver_profiles SET availability = ? WHERE id = ?");
        $stmt->execute([$newStatus, $driverId]);
        if ($stmt->rowCount() > 0) {
            delLog($pdo, 0, 'availability_change', null, $newStatus, $userId, "Driver #$driverId set to $newStatus");
            $messages[] = ['type' => 'success', 'text' => "Driver availability updated to " . str_replace('_', ' ', $newStatus) . "."];
        } else {
            $messages[] = ['type' => 'error', 'text' => 'Driver profile not found.'];
        }
        safeRedirect('index.php?page=delivery_staff');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_driver_status'])) {
    require_csrf();
    $driverId = (int)($_POST['driver_id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';
    $validStatuses = ['active', 'suspended'];

    if ($driverId <= 0 || !in_array($newStatus, $validStatuses)) {
        $messages[] = ['type' => 'error', 'text' => 'Invalid request.'];
    } else {
        $stmt = $pdo->prepare("UPDATE driver_profiles SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $driverId]);
        if ($stmt->rowCount() > 0) {
            if ($newStatus === 'suspended') {
                $pdo->prepare("UPDATE driver_profiles SET availability = 'offline' WHERE id = ?")->execute([$driverId]);
            }
            delLog($pdo, 0, 'driver_status_change', null, $newStatus, $userId, "Driver #$driverId " . ($newStatus === 'active' ? 'activated' : 'suspended'));
            $messages[] = ['type' => 'success', 'text' => 'Driver ' . ($newStatus === 'active' ? 'activated' : 'suspended') . ' successfully.'];
        } else {
            $messages[] = ['type' => 'error', 'text' => 'Driver profile not found or already in this status.'];
        }
        safeRedirect('index.php?page=delivery_staff');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_driver'])) {
    require_csrf();
    $driverId = (int)($_POST['driver_id'] ?? 0);

    if ($driverId <= 0) {
        $messages[] = ['type' => 'error', 'text' => 'Invalid driver.'];
    } else {
        $assignedOrders = $pdo->prepare("SELECT id FROM customer_orders WHERE delivery_staff_id = ? AND delivery_status IN ('assigned','accepted')");
        $assignedOrders->execute([$driverId]);
        foreach ($assignedOrders as $ao) {
            try {
                WorkflowService::reassignDelivery($pdo, (int) $ao['id'], $userId, 'Driver profile removed');
            } catch (ServiceException $e) {
                $messages[] = ['type' => 'error', 'text' => 'Could not unassign order #' . (int) $ao['id'] . ': ' . $e->getMessage()];
            }
        }
        $stmt = $pdo->prepare("DELETE FROM driver_profiles WHERE id = ?");
        $stmt->execute([$driverId]);
        if ($stmt->rowCount() > 0) {
            delLog($pdo, 0, 'driver_removed', null, null, $userId, "Driver profile removed for driver #$driverId");
            $messages[] = ['type' => 'success', 'text' => 'Driver profile removed.'];
        } else {
            $messages[] = ['type' => 'error', 'text' => 'Driver profile not found.'];
        }
        safeRedirect('index.php?page=delivery_staff');
    }
}

$totalDrivers = (int)$pdo->query("SELECT COUNT(*) FROM driver_profiles")->fetchColumn();
$availableCount = (int)$pdo->query("SELECT COUNT(*) FROM driver_profiles WHERE availability = 'available'")->fetchColumn();
$busyCount = (int)$pdo->query("SELECT COUNT(*) FROM driver_profiles WHERE availability = 'busy'")->fetchColumn();
$onDeliveryCount = (int)$pdo->query("SELECT COUNT(*) FROM driver_profiles WHERE availability = 'on_delivery'")->fetchColumn();
$offlineCount = (int)$pdo->query("SELECT COUNT(*) FROM driver_profiles WHERE availability = 'offline'")->fetchColumn();
$onLeaveCount = (int)$pdo->query("SELECT COUNT(*) FROM driver_profiles WHERE availability = 'on_leave'")->fetchColumn();

$allDrivers = $pdo->query("SELECT id, full_name, phone, vehicle_type, vehicle_plate, license_no, availability, status, total_deliveries, successful_deliveries, failed_deliveries, avg_delivery_time_min, rating, created_at FROM driver_profiles ORDER BY FIELD(status, 'active', 'suspended'), FIELD(availability, 'available', 'busy', 'on_delivery', 'offline', 'on_leave'), full_name ASC")->fetchAll();

$editDriverId = (int)($_GET['edit'] ?? 0);
$editDriver = null;
if ($editDriverId > 0) {
    foreach ($allDrivers as $d) {
        if ((int)$d['id'] === $editDriverId) {
            $editDriver = $d;
            break;
        }
    }
}

$performanceData = $pdo->query("SELECT id, full_name, total_deliveries, successful_deliveries, failed_deliveries, avg_delivery_time_min, rating, CASE WHEN total_deliveries > 0 THEN ROUND((successful_deliveries / total_deliveries) * 100, 1) ELSE 0 END AS success_rate FROM driver_profiles ORDER BY success_rate DESC, rating DESC")->fetchAll();
?>
<?= delCss() ?>
<div class="del-wrap">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:6px">
        <h1 style="margin-bottom:0"><i class="fas fa-id-card" style="color:var(--del-blue)"></i> Driver Management</h1>
        <button class="del-btn del-btn-blue" onclick="delSwitchTab(document.querySelector('.del-tab:nth-child(3)'), 'tab-add')"><i class="fas fa-plus"></i> Add Driver</button>
    </div>
    <p class="del-subtitle">Manage delivery drivers, their vehicles, and availability</p>

    <?php if (!empty($messages)): ?>
        <?php foreach ($messages as $m): ?>
            <div class="del-notif del-notif-<?= $m['type'] === 'success' ? 'success' : 'critical' ?>" style="margin-bottom:12px">
                <i class="fas <?= $m['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                <?= htmlspecialchars($m['text']) ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="del-stats">
        <div class="del-stat">
            <div class="del-stat-icon" style="background:linear-gradient(135deg,var(--del-blue),var(--del-blue-d))"><i class="fas fa-users"></i></div>
            <div class="del-stat-body">
                <div class="del-stat-val" data-counter="<?= $totalDrivers ?>"><?= $totalDrivers ?></div>
                <div class="del-stat-label">Total Drivers</div>
            </div>
        </div>
        <div class="del-stat">
            <div class="del-stat-icon" style="background:linear-gradient(135deg,var(--del-green),var(--del-green-d))"><i class="fas fa-check-circle"></i></div>
            <div class="del-stat-body">
                <div class="del-stat-val" data-counter="<?= $availableCount ?>"><?= $availableCount ?></div>
                <div class="del-stat-label">Available</div>
            </div>
        </div>
        <div class="del-stat">
            <div class="del-stat-icon" style="background:linear-gradient(135deg,#d97706,#b45309)"><i class="fas fa-busy"></i></div>
            <div class="del-stat-body">
                <div class="del-stat-val" data-counter="<?= $busyCount ?>"><?= $busyCount ?></div>
                <div class="del-stat-label">Busy</div>
            </div>
        </div>
        <div class="del-stat">
            <div class="del-stat-icon" style="background:linear-gradient(135deg,var(--del-purple),var(--del-purple-d))"><i class="fas fa-truck"></i></div>
            <div class="del-stat-body">
                <div class="del-stat-val" data-counter="<?= $onDeliveryCount ?>"><?= $onDeliveryCount ?></div>
                <div class="del-stat-label">On Delivery</div>
            </div>
        </div>
        <div class="del-stat">
            <div class="del-stat-icon" style="background:linear-gradient(135deg,#64748b,#475569)"><i class="fas fa-power-off"></i></div>
            <div class="del-stat-body">
                <div class="del-stat-val" data-counter="<?= $offlineCount ?>"><?= $offlineCount ?></div>
                <div class="del-stat-label">Offline</div>
            </div>
        </div>
        <div class="del-stat">
            <div class="del-stat-icon" style="background:linear-gradient(135deg,var(--del-red),var(--del-red-d))"><i class="fas fa-plane-departure"></i></div>
            <div class="del-stat-body">
                <div class="del-stat-val" data-counter="<?= $onLeaveCount ?>"><?= $onLeaveCount ?></div>
                <div class="del-stat-label">On Leave</div>
            </div>
        </div>
    </div>

    <div class="del-card">
        <div class="del-tabs">
            <button class="del-tab active" onclick="delSwitchTab(this,'tab-all')"><i class="fas fa-users"></i> All Drivers</button>
            <button class="del-tab" onclick="delSwitchTab(this,'tab-perf')"><i class="fas fa-chart-line"></i> Performance</button>
            <button class="del-tab" onclick="delSwitchTab(this,'tab-add')"><i class="fas fa-<?= $editDriver ? 'edit' : 'plus' ?>"></i> <?= $editDriver ? 'Edit' : 'Add' ?> Driver</button>
        </div>

        <div id="tab-all" class="del-panel active">
            <?php if (empty($allDrivers)): ?>
                <div class="del-empty"><i class="fas fa-id-card"></i><p>No drivers registered yet. Add your first driver!</p></div>
            <?php else: ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:14px;padding:4px 0">
                    <?php foreach ($allDrivers as $d): ?>
                        <?php
                        $avail = $d['availability'] ?? 'offline';
                        $driverStatus = $d['status'] ?? 'active';
                        $avatarColors = ['available' => '#059669', 'busy' => '#d97706', 'on_delivery' => '#2563eb', 'offline' => '#64748b', 'on_leave' => '#dc2626'];
                        $avatarBg = $avatarColors[$avail] ?? '#64748b';
                        $initials = '';
                        $parts = explode(' ', trim((string) $d['full_name']));
                        foreach ($parts as $p) { $initials .= mb_strtoupper(mb_substr($p, 0, 1)); if (strlen($initials) >= 2) break; }
                        $successRate = $d['total_deliveries'] > 0 ? round(($d['successful_deliveries'] / $d['total_deliveries']) * 100, 1) : 0;
                        ?>
                        <div class="del-driver-card" style="flex-direction:column;align-items:stretch;gap:12px;padding:18px">
                            <div style="display:flex;align-items:center;gap:12px">
                                <div class="del-avatar" style="background:<?= $avatarBg ?>;width:48px;height:48px;font-size:1.05rem"><?= htmlspecialchars($initials) ?></div>
                                <div style="flex:1;min-width:0">
                                    <div style="font-weight:700;color:#0f172a;font-size:.9rem"><?= htmlspecialchars((string) $d['full_name']) ?></div>
                                    <div style="font-size:.75rem;color:var(--del-gray)"><?= htmlspecialchars($d['vehicle_type'] ?? 'No vehicle') ?> <?= !empty($d['vehicle_plate']) ? '(' . htmlspecialchars($d['vehicle_plate']) . ')' : '' ?></div>
                                    <div style="font-size:.72rem;color:var(--del-gray)">License: <?= htmlspecialchars($d['license_no'] ?? 'N/A') ?><?= !empty($d['phone']) ? ' · ' . htmlspecialchars($d['phone']) : '' ?></div>
                                </div>
                                <div><?= $driverStatus === 'suspended' ? '<span style="display:inline-flex;align-items:center;gap:5px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:99px;padding:3px 10px;font-size:.7rem;font-weight:700"><i class="fas fa-ban"></i> Suspended</span>' : delBadgeHtml($avail) ?></div>
                            </div>
                            <div style="display:flex;gap:16px;font-size:.78rem;color:#64748b;border-top:1px solid var(--del-border);padding-top:10px">
                                <div style="text-align:center;flex:1"><div style="font-weight:800;color:#0f172a;font-size:1rem"><?= (int)$d['total_deliveries'] ?></div><div>Total</div></div>
                                <div style="text-align:center;flex:1"><div style="font-weight:800;color:var(--del-green-d);font-size:1rem"><?= $successRate ?>%</div><div>Success</div></div>
                                <div style="text-align:center;flex:1"><div style="font-weight:800;color:#d97706;font-size:1rem"><?= number_format((float)$d['rating'], 1) ?></div><div>Rating</div></div>
                                <div style="text-align:center;flex:1"><div style="font-weight:800;color:var(--del-blue);font-size:1rem"><?= (int)$d['avg_delivery_time_min'] ?>m</div><div>Avg Time</div></div>
                            </div>
                            <div style="display:flex;gap:6px;border-top:1px solid var(--del-border);padding-top:10px">
                                <a href="?page=delivery_staff&edit=<?= (int)$d['id'] ?>" class="del-btn del-btn-outline del-btn-sm" style="flex:1;justify-content:center;text-decoration:none"><i class="fas fa-edit"></i> Edit</a>
                                <?php if ($driverStatus === 'active'): ?>
                                    <form method="post" style="flex:1;display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="set_driver_status" value="1">
                                        <input type="hidden" name="driver_id" value="<?= (int)$d['id'] ?>">
                                        <input type="hidden" name="new_status" value="suspended">
                                        <button type="submit" class="del-btn del-btn-red del-btn-sm" style="width:100%;justify-content:center" onclick="return confirm('Suspend this driver? They will no longer appear in assignment lists.')"><i class="fas fa-ban"></i> Suspend</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" style="flex:1;display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="set_driver_status" value="1">
                                        <input type="hidden" name="driver_id" value="<?= (int)$d['id'] ?>">
                                        <input type="hidden" name="new_status" value="active">
                                        <button type="submit" class="del-btn del-btn-green del-btn-sm" style="width:100%;justify-content:center"><i class="fas fa-check-circle"></i> Activate</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($driverStatus === 'active'): ?>
                                    <?php if ($avail === 'offline' || $avail === 'on_leave'): ?>
                                        <form method="post" style="flex:1;display:inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="update_availability" value="1">
                                            <input type="hidden" name="driver_id" value="<?= (int)$d['id'] ?>">
                                            <input type="hidden" name="new_status" value="available">
                                            <button type="submit" class="del-btn del-btn-green del-btn-sm" style="width:100%;justify-content:center"><i class="fas fa-toggle-on"></i> Set Available</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" style="flex:1;display:inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="update_availability" value="1">
                                            <input type="hidden" name="driver_id" value="<?= (int)$d['id'] ?>">
                                            <input type="hidden" name="new_status" value="offline">
                                            <button type="submit" class="del-btn del-btn-outline del-btn-sm" style="width:100%;justify-content:center" onclick="return confirm('Set this driver offline?')"><i class="fas fa-toggle-off"></i> Set Offline</button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div id="tab-perf" class="del-panel">
            <?php if (empty($performanceData)): ?>
                <div class="del-empty"><i class="fas fa-chart-bar"></i><p>No performance data available yet.</p></div>
            <?php else: ?>
                <div style="overflow-x:auto">
                    <table class="del-table">
                        <thead>
                            <tr>
                                <th>Driver</th>
                                <th>Total Deliveries</th>
                                <th>Successful</th>
                                <th>Failed</th>
                                <th>Success Rate</th>
                                <th>Avg Delivery Time</th>
                                <th>Rating</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($performanceData as $p): ?>
                                <?php
                                $rate = (float)$p['success_rate'];
                                $rateColor = $rate >= 90 ? 'var(--del-green)' : ($rate >= 70 ? '#d97706' : 'var(--del-red)');
                                ?>
                                <tr>
                                    <td style="font-weight:600"><?= htmlspecialchars((string) $p['full_name']) ?></td>
                                    <td style="text-align:center"><?= (int)$p['total_deliveries'] ?></td>
                                    <td style="text-align:center;color:var(--del-green-d);font-weight:600"><?= (int)$p['successful_deliveries'] ?></td>
                                    <td style="text-align:center;color:var(--del-red);font-weight:600"><?= (int)$p['failed_deliveries'] ?></td>
                                    <td>
                                        <div style="display:flex;align-items:center;gap:8px">
                                            <div class="del-progress-wrap" style="width:80px">
                                                <div class="del-progress-bar" style="width:<?= $rate ?>%;background:<?= $rateColor ?>"></div>
                                            </div>
                                            <span style="font-weight:700;color:<?= $rateColor ?>"><?= $rate ?>%</span>
                                        </div>
                                    </td>
                                    <td style="text-align:center"><?= (int)$p['avg_delivery_time_min'] ?> min</td>
                                    <td style="text-align:center">
                                        <span style="color:#d97706;font-weight:700"><i class="fas fa-star" style="font-size:.7rem"></i> <?= number_format((float)$p['rating'], 1) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div id="tab-add" class="del-panel">
            <div style="max-width:540px;padding:8px 0">
                <h2 style="font-size:1rem;font-weight:700;color:#0f172a;margin-bottom:14px;display:flex;align-items:center;gap:8px">
                    <i class="fas fa-<?= $editDriver ? 'edit' : 'user-plus' ?>" style="color:var(--del-blue)"></i>
                    <?= $editDriver ? 'Edit Driver: ' . htmlspecialchars($editDriver['full_name']) : 'Add New Driver' ?>
                </h2>
                <?php if ($editDriver): ?>
                    <div style="margin-bottom:14px">
                        <a href="?page=delivery_staff" class="del-btn del-btn-outline del-btn-sm"><i class="fas fa-times"></i> Cancel Edit</a>
                    </div>
                <?php endif; ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="save_driver" value="1">
                    <input type="hidden" name="driver_id" value="<?= $editDriver ? (int)$editDriver['id'] : 0 ?>">
                    <div class="del-form-group">
                        <label>Full Name</label>
                        <input type="text" name="full_name" value="<?= htmlspecialchars($editDriver['full_name'] ?? '') ?>" required placeholder="e.g. Abebe Kebede">
                    </div>
                    <div class="del-form-group">
                        <label>Phone</label>
                        <input type="text" name="phone" value="<?= htmlspecialchars($editDriver['phone'] ?? '') ?>" placeholder="e.g. +251 911 123 456">
                    </div>
                    <div class="del-form-group">
                        <label>Vehicle Type</label>
                        <select name="vehicle_type" required>
                            <option value="">— Select —</option>
                            <?php
                            $vTypes = ['Motorcycle', 'Bicycle', 'Car', 'Van', 'Truck', 'Scooter'];
                            $currentVt = $editDriver['vehicle_type'] ?? '';
                            foreach ($vTypes as $vt):
                            ?>
                                <option value="<?= htmlspecialchars($vt) ?>" <?= $currentVt === $vt ? 'selected' : '' ?>><?= htmlspecialchars($vt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="del-form-group">
                        <label>Vehicle Plate Number</label>
                        <input type="text" name="vehicle_plate" value="<?= htmlspecialchars($editDriver['vehicle_plate'] ?? '') ?>" placeholder="e.g. AA-12345">
                    </div>
                    <div class="del-form-group">
                        <label>License Number</label>
                        <input type="text" name="license_no" value="<?= htmlspecialchars($editDriver['license_no'] ?? '') ?>" required placeholder="e.g. DL-98765">
                    </div>
                    <div style="display:flex;gap:10px;margin-top:18px">
                        <button type="submit" class="del-btn del-btn-blue"><i class="fas fa-save"></i> <?= $editDriver ? 'Update Driver' : 'Create Driver Profile' ?></button>
                        <?php if ($editDriver): ?>
                            <form method="post" style="display:inline" onsubmit="return confirm('Remove this driver profile? Their assigned deliveries will be unassigned.')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="delete_driver" value="1">
                                <input type="hidden" name="driver_id" value="<?= (int)$editDriver['id'] ?>">
                                <button type="submit" class="del-btn del-btn-red"><i class="fas fa-trash"></i> Remove Driver</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?= delJs() ?>
