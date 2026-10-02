<?php
require_once __DIR__ . '/../includes/delivery_helpers.php';
require_once __DIR__ . '/../includes/workflow_engine.php';
require_once __DIR__ . '/../includes/auto_migrate.php';
requireDeliveryOperator();
requirePermission('delivery_dashboard', 'view');
$pdo = delGetDb();
ensureDeliveryErpMigration($pdo);
ensureFulfillmentMigration($pdo);

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_csrf();
    $action = $_POST['action'];
    $adminId = (int) $_SESSION['user_id'];

    if ($action === 'unassign') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        if ($orderId > 0) {
            $check = $pdo->prepare("SELECT delivery_staff_id, delivery_status FROM customer_orders WHERE id = ? AND delivery_status IN ('assigned','accepted')");
            $check->execute([$orderId]);
            $order = $check->fetch();
            if ($order) {
                $res = WorkflowEngine::transition($pdo, 'delivery', $orderId, null, [
                    'extra' => ['delivery_staff_id' => null, 'assigned_at' => null, 'assigned_by' => null],
                    'changed_by' => $adminId,
                    'notes' => 'Admin unassigned delivery',
                ]);
                if (!$res['success']) { $error = $res['error']; }
                else {
                    delSyncDriverAvailability($pdo, (int) ($order['delivery_staff_id'] ?? 0));
                    delLog($pdo, $orderId, 'unassigned', $order['delivery_status'], null, $adminId, 'Admin unassigned delivery');
                    $success = "Order #{$orderId} unassigned successfully.";
                }
            } else {
                $error = "Order #{$orderId} cannot be unassigned.";
            }
        }
    }
}

$pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM customer_orders WHERE status = 'approved' AND payment_confirmed = 1 AND delivery_staff_id IS NULL AND delivery_status IS NULL AND fulfillment_method = 'delivery' AND fulfillment_status = 'ready_for_delivery'")->fetchColumn();
$assignedCount = (int) $pdo->query("SELECT COUNT(*) FROM customer_orders WHERE delivery_status IN ('assigned','accepted')")->fetchColumn();
$outCount = (int) $pdo->query("SELECT COUNT(*) FROM customer_orders WHERE delivery_status = 'out_for_delivery'")->fetchColumn();
$completedToday = (int) $pdo->query("SELECT COUNT(*) FROM customer_orders WHERE delivery_status = 'delivered' AND DATE(delivered_at) = CURDATE()")->fetchColumn();
$driversAvailable = (int) $pdo->query("SELECT COUNT(*) FROM driver_profiles WHERE availability = 'available'")->fetchColumn();
$failedToday = (int) $pdo->query("SELECT COUNT(*) FROM customer_orders WHERE delivery_status = 'failed'")->fetchColumn();
$returnsPending = (int) $pdo->query("SELECT COUNT(*) FROM delivery_returns WHERE status = 'pending'")->fetchColumn();
$totalActive = (int) $pdo->query("SELECT COUNT(*) FROM customer_orders WHERE delivery_status IS NOT NULL")->fetchColumn();

$recentOrders = $pdo->query("
    SELECT co.id, co.order_date, co.total_amount, co.delivery_status, co.assigned_at,
           co.driver_name, co.delivered_at,
           c.name AS customer_name,
           dp.full_name AS staff_name
    FROM customer_orders co
    LEFT JOIN customers c ON co.customer_id = c.id
    LEFT JOIN driver_profiles dp ON co.delivery_staff_id = dp.id
    WHERE co.delivery_status IS NOT NULL
    ORDER BY co.assigned_at DESC, co.created_at DESC
    LIMIT 20
")->fetchAll();

$drivers = $pdo->query("
    SELECT dp.id, dp.full_name, dp.phone, dp.vehicle_type, dp.vehicle_plate,
           dp.availability, dp.rating, dp.total_deliveries, dp.successful_deliveries, dp.failed_deliveries,
           (SELECT COUNT(*) FROM customer_orders co WHERE co.delivery_staff_id = dp.id AND co.delivery_status IN ('assigned','accepted','picked_up','out_for_delivery')) AS active_count
    FROM driver_profiles dp
    ORDER BY dp.availability ASC, dp.full_name ASC
")->fetchAll();

$notifications = $pdo->query("SELECT dn.*, u.full_name AS user_name FROM delivery_notifications dn LEFT JOIN users u ON dn.user_id = u.id ORDER BY dn.created_at DESC LIMIT 10")->fetchAll();
?>
<?= delCss() ?>
<style>
.del-section{margin-bottom:20px}
.del-section-title{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--del-gray);margin-bottom:12px;padding-left:2px}
.del-ops-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.del-ops-grid .del-card{margin-bottom:0}
.del-ops-grid .del-card h2{margin-bottom:12px}
.del-dash-list{display:flex;flex-direction:column;gap:6px}
.del-dash-item{display:flex;align-items:center;gap:10px;padding:10px 12px;background:#f8fafc;border-radius:10px;text-decoration:none;color:#334155;font-size:.82rem;font-weight:600;transition:all .15s;border:1px solid transparent}
.del-dash-item:hover{background:#eff6ff;border-color:var(--del-blue);color:var(--del-blue);transform:translateX(2px)}
.del-dash-item i{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.8rem;flex-shrink:0}
@media(max-width:1024px){.del-ops-grid{grid-template-columns:1fr}}
.del-ops-grid>*{min-width:0}
.del-ops-grid .del-card{padding:16px}
.del-ops-grid .del-table{min-width:0}
@media(max-width:420px){.top-header{flex-wrap:wrap;height:auto;min-height:var(--header-h)}.breadcrumbs{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:120px}}
@media(max-width:480px){.del-driver-card{flex-wrap:wrap}.del-driver-card>div:last-child{width:100%;text-align:left;margin-top:8px}}
</style>

<div class="del-wrap">
    <div class="del-header">
        <div>
            <h1><i class="fas fa-truck" style="color:var(--del-blue)"></i> Delivery Dashboard</h1>
            <p class="del-subtitle">Delivery operations center — monitor drivers, assignments, and deliveries at a glance</p>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="del-section">
        <div class="del-section-title">Key Metrics</div>
        <div class="del-stats">
            <div class="del-stat">
                <div class="del-stat-icon" style="background:linear-gradient(135deg,#d97706,#b45309)"><i class="fas fa-clock"></i></div>
                <div class="del-stat-body">
                    <div class="del-stat-val" data-counter="<?= $pendingCount ?>"><?= $pendingCount ?></div>
                    <div class="del-stat-label">Pending Assignment</div>
                </div>
            </div>
            <div class="del-stat">
                <div class="del-stat-icon" style="background:linear-gradient(135deg,#2563eb,#1d4ed8)"><i class="fas fa-user-check"></i></div>
                <div class="del-stat-body">
                    <div class="del-stat-val" data-counter="<?= $assignedCount ?>"><?= $assignedCount ?></div>
                    <div class="del-stat-label">Assigned</div>
                </div>
            </div>
            <div class="del-stat">
                <div class="del-stat-icon" style="background:linear-gradient(135deg,#ea580c,#c2410c)"><i class="fas fa-truck"></i></div>
                <div class="del-stat-body">
                    <div class="del-stat-val" data-counter="<?= $outCount ?>"><?= $outCount ?></div>
                    <div class="del-stat-label">Out for Delivery</div>
                </div>
            </div>
            <div class="del-stat">
                <div class="del-stat-icon" style="background:linear-gradient(135deg,#059669,#047857)"><i class="fas fa-check-circle"></i></div>
                <div class="del-stat-body">
                    <div class="del-stat-val" data-counter="<?= $completedToday ?>"><?= $completedToday ?></div>
                    <div class="del-stat-label">Completed Today</div>
                </div>
            </div>
            <div class="del-stat">
                <div class="del-stat-icon" style="background:linear-gradient(135deg,#0d9488,#0f766e)"><i class="fas fa-users"></i></div>
                <div class="del-stat-body">
                    <div class="del-stat-val" data-counter="<?= $driversAvailable ?>"><?= $driversAvailable ?></div>
                    <div class="del-stat-label">Drivers Available</div>
                </div>
            </div>
            <div class="del-stat">
                <div class="del-stat-icon" style="background:linear-gradient(135deg,#dc2626,#b91c1c)"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="del-stat-body">
                    <div class="del-stat-val" data-counter="<?= $failedToday ?>"><?= $failedToday ?></div>
                    <div class="del-stat-label">Failed</div>
                </div>
            </div>
            <div class="del-stat">
                <div class="del-stat-icon" style="background:linear-gradient(135deg,#4f46e5,#4338ca)"><i class="fas fa-layer-group"></i></div>
                <div class="del-stat-body">
                    <div class="del-stat-val" data-counter="<?= $totalActive ?>"><?= $totalActive ?></div>
                    <div class="del-stat-label">Total Active</div>
                </div>
            </div>
            <div class="del-stat">
                <div class="del-stat-icon" style="background:linear-gradient(135deg,#7c3aed,#6d28d9)"><i class="fas fa-undo"></i></div>
                <div class="del-stat-body">
                    <div class="del-stat-val" data-counter="<?= $returnsPending ?>"><?= $returnsPending ?></div>
                    <div class="del-stat-label">Returns Pending</div>
                </div>
            </div>
        </div>
    </div>

    <div class="del-ops-grid del-section">
        <div class="del-card">
            <h2><i class="fas fa-bolt" style="color:var(--del-blue)"></i> Daily Operations</h2>
            <div class="del-dash-list">
                <a href="?page=delivery_assign" class="del-dash-item"><i class="fas fa-user-plus" style="background:linear-gradient(135deg,#2563eb,#1d4ed8)"></i> Assign Driver</a>
                <a href="?page=delivery_assign&tab=reassign" class="del-dash-item"><i class="fas fa-exchange-alt" style="background:linear-gradient(135deg,#d97706,#b45309)"></i> Reassign Driver</a>
                <a href="?page=delivery_tracking&status=out_for_delivery" class="del-dash-item"><i class="fas fa-truck" style="background:linear-gradient(135deg,#ea580c,#c2410c)"></i> Out for Delivery</a>
                <a href="?page=delivery_tracking&status=failed" class="del-dash-item"><i class="fas fa-exclamation-circle" style="background:linear-gradient(135deg,#dc2626,#b91c1c)"></i> Failed Deliveries</a>
                <a href="?page=delivery_returns" class="del-dash-item"><i class="fas fa-undo" style="background:linear-gradient(135deg,#7c3aed,#6d28d9)"></i> Delivery Returns</a>
            </div>
        </div>
        <div class="del-card">
            <h2><i class="fas fa-chart-line" style="color:var(--del-green)"></i> Monitoring & Admin</h2>
            <div class="del-dash-list">
                <a href="?page=delivery_tracking" class="del-dash-item"><i class="fas fa-route" style="background:linear-gradient(135deg,#059669,#047857)"></i> Delivery Tracking</a>
                <a href="?page=delivery_tracking&status=delivered" class="del-dash-item"><i class="fas fa-check-double" style="background:linear-gradient(135deg,#059669,#047857)"></i> Completed Deliveries</a>
                <a href="?page=delivery_staff" class="del-dash-item"><i class="fas fa-users-cog" style="background:linear-gradient(135deg,#64748b,#475569)"></i> Driver Management</a>
                <a href="?page=delivery_history" class="del-dash-item"><i class="fas fa-chart-bar" style="background:linear-gradient(135deg,#4f46e5,#4338ca)"></i> Reports & Audit Log</a>
                <?php if (hasPermission('settings', 'view')): ?>
                <a href="?page=settings&tab=system" class="del-dash-item"><i class="fas fa-cog" style="background:linear-gradient(135deg,#64748b,#475569)"></i> Delivery Settings</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="del-ops-grid del-section">
        <div class="del-card">
            <h2><i class="fas fa-list-alt"></i> Recent Orders</h2>
            <?php if (empty($recentOrders)): ?>
                <div class="del-empty"><i class="fas fa-truck"></i><p>No recent deliveries.</p></div>
            <?php else: ?>
                <div style="overflow-x:auto">
                    <table class="del-table">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Customer</th>
                                <th>Driver</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentOrders as $o): ?>
                                <tr>
                                    <td><strong>#<?= (int) $o['id'] ?></strong></td>
                                    <td><?= htmlspecialchars($o['customer_name'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($o['staff_name'] ?? $o['driver_name'] ?? '—') ?></td>
                                    <td><?= delBadgeHtml($o['delivery_status'] ?? '') ?></td>
                                    <td style="white-space:nowrap">
                                        <?php if (in_array($o['delivery_status'], ['assigned', 'accepted'])): ?>
                                            <form method="post" style="display:inline" onsubmit="return confirm('Unassign this delivery?')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="unassign">
                                                <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                                                <button type="submit" class="del-btn del-btn-sm del-btn-outline" title="Unassign"><i class="fas fa-times"></i></button>
                                            </form>
                                        <?php else: ?>
                                            <span style="color:var(--del-gray);font-size:.75rem"><?= $o['delivery_status'] === 'picked_up' || $o['delivery_status'] === 'out_for_delivery' ? '<i class="fas fa-lock"></i>' : '' ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="del-card">
            <h2><i class="fas fa-users"></i> Driver Status</h2>
            <?php if (empty($drivers)): ?>
                <div class="del-empty"><i class="fas fa-user-slash"></i><p>No drivers configured. <a href="?page=delivery_staff">Add drivers</a></p></div>
            <?php else: ?>
                <?php foreach ($drivers as $d): ?>
                    <div class="del-driver-card" style="margin-bottom:8px">
                        <?php
                        $avatarColors = ['available' => '#059669', 'busy' => '#d97706', 'on_delivery' => '#2563eb', 'offline' => '#94a3b8', 'on_leave' => '#dc2626'];
                        $avColor = $avatarColors[$d['availability']] ?? '#94a3b8';
                        ?>
                        <div class="del-avatar" style="background:<?= $avColor ?>"><?= strtoupper(substr($d['full_name'], 0, 1)) ?></div>
                        <div style="flex:1;min-width:0">
                            <div style="font-weight:700;font-size:.85rem"><?= htmlspecialchars($d['full_name']) ?></div>
                            <div style="font-size:.75rem;color:var(--del-gray)"><?= htmlspecialchars($d['vehicle_type'] ?? '') ?> <?= htmlspecialchars($d['vehicle_plate'] ?? '') ?></div>
                            <div style="display:flex;gap:8px;margin-top:4px">
                                <?= delBadgeHtml($d['availability']) ?>
                                <?php if ($d['active_count'] > 0): ?>
                                    <span style="font-size:.7rem;color:var(--del-gray)"><i class="fas fa-box"></i> <?= $d['active_count'] ?> active</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="text-align:right;font-size:.75rem;color:var(--del-gray)">
                            <div style="font-weight:600;color:#0f172a"><?= $d['successful_deliveries'] ?> / <?= $d['total_deliveries'] ?></div>
                            <div>success</div>
                            <div style="color:#d97706">★ <?= number_format($d['rating'], 1) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($notifications)): ?>
        <div class="del-card del-section">
            <h2><i class="fas fa-bell"></i> Recent Notifications</h2>
            <?php foreach ($notifications as $n): ?>
                <?php
                $notifClass = 'del-notif-info';
                if ($n['type'] === 'warning') $notifClass = 'del-notif-warning';
                elseif ($n['type'] === 'error') $notifClass = 'del-notif-critical';
                elseif ($n['type'] === 'success') $notifClass = 'del-notif-success';
                ?>
                <div class="del-notif <?= $notifClass ?>" style="<?= !$n['is_read'] ? 'font-weight:600' : '' ?>">
                    <i class="fas fa-<?= $n['type'] === 'warning' ? 'exclamation-triangle' : ($n['type'] === 'error' ? 'times-circle' : ($n['type'] === 'success' ? 'check-circle' : 'info-circle')) ?>"></i>
                    <div>
                        <strong><?= htmlspecialchars($n['title']) ?></strong>
                        <div style="font-size:.8rem;margin-top:2px"><?= htmlspecialchars($n['message']) ?></div>
                        <div style="font-size:.7rem;color:var(--del-gray);margin-top:2px"><?= htmlspecialchars($n['created_at']) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?= delJs() ?>
