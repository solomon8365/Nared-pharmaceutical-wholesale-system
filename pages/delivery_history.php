<?php
require_once __DIR__ . '/../includes/delivery_helpers.php';
requireDeliveryStaff();
$pdo = delGetDb();
ensureDeliveryErpMigration($pdo);

$page_num = max(1, (int) ($_GET['p'] ?? 1));
$perPage = 50;
$offset = ($page_num - 1) * $perPage;

$actionFilter = $_GET['action'] ?? '';
$driverFilter = (int) ($_GET['driver'] ?? 0);
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$search = trim($_GET['search'] ?? '');

$where = ['1=1'];
$params = [];

if ($actionFilter !== '') {
    $where[] = 'dl.action = :action';
    $params['action'] = $actionFilter;
}
if ($driverFilter > 0) {
    $where[] = 'dl.performed_by = :driver';
    $params['driver'] = $driverFilter;
}
if ($dateFrom !== '') {
    $where[] = 'dl.created_at >= :date_from';
    $params['date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'dl.created_at <= :date_to';
    $params['date_to'] = $dateTo . ' 23:59:59';
}
if ($search !== '') {
    $where[] = '(dl.order_id LIKE :search OR c.name LIKE :search2)';
    $params['search'] = '%' . $search . '%';
    $params['search2'] = '%' . $search . '%';
}

$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM delivery_logs dl LEFT JOIN customer_orders co ON dl.order_id = co.id LEFT JOIN customers c ON co.customer_id = c.id WHERE {$whereSql}");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

$stmt = $pdo->prepare("
    SELECT dl.*, u.full_name AS performer_name, c.name AS customer_name
    FROM delivery_logs dl
    LEFT JOIN users u ON dl.performed_by = u.id
    LEFT JOIN customer_orders co ON dl.order_id = co.id
    LEFT JOIN customers c ON co.customer_id = c.id
    WHERE {$whereSql}
    ORDER BY dl.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$drivers = $pdo->query("SELECT id, full_name, username FROM users WHERE role IN ('delivery','admin') ORDER BY full_name ASC")->fetchAll();

$actionTypes = $pdo->query("SELECT DISTINCT action FROM delivery_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);

$todayStats = $pdo->query("
    SELECT COUNT(*) AS total_actions,
           COUNT(DISTINCT order_id) AS unique_orders,
           (SELECT u.full_name FROM delivery_logs dl2 JOIN users u ON dl2.performed_by = u.id WHERE DATE(dl2.created_at) = CURDATE() GROUP BY dl2.performed_by ORDER BY COUNT(*) DESC LIMIT 1) AS most_active_driver
    FROM delivery_logs WHERE DATE(created_at) = CURDATE()
")->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Reports — NARED PHARMACON</title>
    <?= delCss() ?>
</head>
<body>
<div class="del-wrap" style="padding:20px">
    <h1><i class="fas fa-chart-bar" style="color:var(--del-indigo)"></i> Delivery Reports</h1>
    <p class="del-subtitle">Audit log and performance analytics for all delivery actions</p>

    <div class="del-stats" style="grid-template-columns:repeat(3,1fr)">
        <div class="del-stat">
            <div class="del-stat-icon" style="background:linear-gradient(135deg,#2563eb,#1d4ed8)"><i class="fas fa-list"></i></div>
            <div class="del-stat-body">
                <div class="del-stat-val" data-counter="<?= (int)($todayStats['total_actions'] ?? 0) ?>"><?= (int)($todayStats['total_actions'] ?? 0) ?></div>
                <div class="del-stat-label">Actions Today</div>
            </div>
        </div>
        <div class="del-stat">
            <div class="del-stat-icon" style="background:linear-gradient(135deg,#059669,#047857)"><i class="fas fa-box"></i></div>
            <div class="del-stat-body">
                <div class="del-stat-val" data-counter="<?= (int)($todayStats['unique_orders'] ?? 0) ?>"><?= (int)($todayStats['unique_orders'] ?? 0) ?></div>
                <div class="del-stat-label">Unique Orders Today</div>
            </div>
        </div>
        <div class="del-stat">
            <div class="del-stat-icon" style="background:linear-gradient(135deg,#7c3aed,#6d28d9)"><i class="fas fa-user"></i></div>
            <div class="del-stat-body">
                <div class="del-stat-val" style="font-size:1rem"><?= htmlspecialchars($todayStats['most_active_driver'] ?? '—') ?></div>
                <div class="del-stat-label">Most Active Driver Today</div>
            </div>
        </div>
    </div>

    <div class="del-card">
        <div class="del-toolbar">
            <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;width:100%">
                <input type="hidden" name="page" value="delivery_history">
                <input type="text" name="search" placeholder="Search Order# or Customer..." value="<?= htmlspecialchars($search) ?>" style="padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;min-width:180px">
                <select name="action" style="padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem">
                    <option value="">All Actions</option>
                    <?php foreach ($actionTypes as $at): ?>
                        <option value="<?= htmlspecialchars($at) ?>"<?= $actionFilter === $at ? ' selected' : '' ?>><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $at))) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="driver" style="padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem">
                    <option value="">All Staff</option>
                    <?php foreach ($drivers as $d): ?>
                        <option value="<?= (int) $d['id'] ?>"<?= $driverFilter === (int) $d['id'] ? ' selected' : '' ?>><?= htmlspecialchars($d['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" style="padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem">
                <span style="color:var(--del-gray)">to</span>
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" style="padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem">
                <button type="submit" class="del-btn del-btn-blue del-btn-sm"><i class="fas fa-search"></i> Filter</button>
                <a href="?page=delivery_history" class="del-btn del-btn-outline del-btn-sm">Clear</a>
            </form>
        </div>

        <?php if (empty($logs)): ?>
            <div class="del-empty"><i class="fas fa-history"></i><p>No delivery logs found for the selected filters.</p></div>
        <?php else: ?>
            <table class="del-table">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Order #</th>
                        <th>Customer</th>
                        <th>Action</th>
                        <th>Old Status</th>
                        <th>New Status</th>
                        <th>Performed By</th>
                        <th>Notes</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td style="white-space:nowrap;font-size:.78rem"><?= htmlspecialchars($log['created_at']) ?></td>
                            <td><strong>#<?= (int) $log['order_id'] ?></strong></td>
                            <td><?= htmlspecialchars($log['customer_name'] ?? '—') ?></td>
                            <td>
                                <?php
                                $actionColors = [
                                    'assigned' => 'var(--del-blue)',
                                    'accepted' => 'var(--del-purple)',
                                    'picked_up' => 'var(--del-orange)',
                                    'start_delivery' => 'var(--del-orange)',
                                    'pod_recorded' => '#ca8a04',
                                    'delivered' => 'var(--del-green)',
                                    'invoice_completed' => 'var(--del-green)',
                                    'admin_confirmed' => 'var(--del-green)',
                                    'failed' => 'var(--del-red)',
                                    'unassigned' => '#94a3b8',
                                ];
                                $ac = $actionColors[$log['action']] ?? 'var(--del-gray)';
                                ?>
                                <span style="color:<?= $ac ?>;font-weight:700;font-size:.8rem"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $log['action']))) ?></span>
                            </td>
                            <td><?php if ($log['old_status']): ?><?= delBadgeHtml($log['old_status']) ?><?php else: ?>—<?php endif; ?></td>
                            <td><?php if ($log['new_status']): ?><?= delBadgeHtml($log['new_status']) ?><?php else: ?>—<?php endif; ?></td>
                            <td><?= htmlspecialchars($log['performer_name'] ?? 'System') ?></td>
                            <td style="max-width:200px;font-size:.8rem;color:var(--del-gray)"><?= htmlspecialchars($log['notes'] ?? '') ?></td>
                            <td style="font-size:.75rem;color:var(--del-gray)"><?= htmlspecialchars($log['ip_address'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1): ?>
                <div style="display:flex;justify-content:center;gap:6px;margin-top:16px">
                    <?php if ($page_num > 1): ?>
                        <a href="?page=delivery_history&p=<?= $page_num - 1 ?>&action=<?= urlencode($actionFilter) ?>&driver=<?= $driverFilter ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&search=<?= urlencode($search) ?>" class="del-btn del-btn-outline del-btn-sm"><i class="fas fa-chevron-left"></i></a>
                    <?php endif; ?>
                    <span style="padding:6px 14px;font-size:.82rem;font-weight:600;color:var(--del-gray)">Page <?= $page_num ?> of <?= $totalPages ?> (<?= $totalRows ?> records)</span>
                    <?php if ($page_num < $totalPages): ?>
                        <a href="?page=delivery_history&p=<?= $page_num + 1 ?>&action=<?= urlencode($actionFilter) ?>&driver=<?= $driverFilter ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&search=<?= urlencode($search) ?>" class="del-btn del-btn-outline del-btn-sm"><i class="fas fa-chevron-right"></i></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?= delJs() ?>
</body>
</html>
