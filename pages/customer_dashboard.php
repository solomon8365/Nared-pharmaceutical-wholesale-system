<?php
require_once __DIR__ . '/../includes/workflow_helpers.php';
requireCustomer();
$pdo = getPDO();
$cid = customerAccountCustomerId();
if ($cid === null) { echo '<div class="panel"><p>Your account is not linked to a customer profile.</p></div>'; return; }

$customer = $pdo->prepare('SELECT * FROM customers WHERE id = :cid');
$customer->execute(['cid' => $cid]);
$customer = $customer->fetch();
$customerName = htmlspecialchars($customer['name'] ?? 'Customer');
$hour = (int) date('H');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');

$allOrders = $pdo->prepare('SELECT * FROM customer_orders WHERE customer_id = :cid ORDER BY created_at DESC');
$allOrders->execute(['cid' => $cid]);
$allOrders = $allOrders->fetchAll();

$stats = ['total' => count($allOrders), 'pending' => 0, 'processing' => 0, 'delivered' => 0, 'total_spent' => 0];
foreach ($allOrders as $o) {
    if ($o['status'] === 'pending') $stats['pending']++;
    elseif ($o['status'] === 'approved') { if ($o['delivery_status'] === 'delivered') $stats['delivered']++; else $stats['processing']++; }
    if ($o['payment_confirmed']) $stats['total_spent'] += (float) $o['total_amount'];
}

$recentOrders = array_slice($allOrders, 0, 5);

$unreadNotifs = 0;
$notifs = [];
try {
    $nStmt = $pdo->prepare('SELECT * FROM order_notifications WHERE customer_id = :cid ORDER BY created_at DESC LIMIT 10');
    $nStmt->execute(['cid' => $cid]);
    $notifs = $nStmt->fetchAll();
    $unreadNotifs = getUnreadNotificationCount($pdo, $cid);
} catch (Exception $e) {}
?>
<style>
.dsh{max-width:1200px;margin:0 auto}
.dsh-welcome{background:linear-gradient(135deg,#1e3a5f 0%,#2563eb 60%,#1e40af 100%);border-radius:16px;padding:32px;color:#fff;position:relative;overflow:hidden;margin-bottom:24px}
.dsh-welcome::after{content:'';position:absolute;top:-50%;right:-10%;width:350px;height:350px;background:radial-gradient(circle,rgba(255,255,255,0.06) 0%,transparent 70%);border-radius:50%;pointer-events:none}
.dsh-welcome h1{margin:0 0 4px;font-size:1.5rem;font-weight:800;position:relative;z-index:1}
.dsh-welcome p{margin:0;font-size:0.9rem;opacity:0.8;position:relative;z-index:1}
.dsh-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:24px}
.dsh-stat{background:#fff;border-radius:12px;padding:20px;border:1px solid #e2e8f0;display:flex;align-items:center;gap:14px;transition:all 0.2s}
.dsh-stat:hover{transform:translateY(-2px);box-shadow:0 8px 25px rgba(0,0,0,0.06)}
.dsh-stat-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0}
.dsh-stat-val{font-size:1.5rem;font-weight:800;color:#1e293b}
.dsh-stat-label{font-size:0.75rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.03em}
.dsh-grid{display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:24px}
.dsh-panel{background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden}
.dsh-panel-head{padding:18px 22px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between}
.dsh-panel-title{font-size:0.95rem;font-weight:700;color:#1e293b;display:flex;align-items:center;gap:8px}
.dsh-panel-title i{color:#2563eb}
.dsh-panel-link{font-size:0.82rem;font-weight:600;color:#2563eb;text-decoration:none;display:flex;align-items:center;gap:4px}
.dsh-panel-body{padding:20px 22px}
.dsh-actions{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px}
.dsh-action{display:flex;flex-direction:column;align-items:center;gap:10px;padding:24px 16px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;text-decoration:none;color:#1e293b;transition:all 0.2s}
.dsh-action:hover{transform:translateY(-3px);box-shadow:0 8px 25px rgba(0,0,0,0.08);border-color:#2563eb}
.dsh-action-icon{width:52px;height:52px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem}
.dsh-action-label{font-size:0.85rem;font-weight:700}
.dsh-table{width:100%;border-collapse:collapse}
.dsh-table th{padding:10px 14px;text-align:left;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color:#64748b;background:#f8fafc;border-bottom:1px solid #e2e8f0}
.dsh-table td{padding:12px 14px;font-size:0.85rem;color:#1e293b;border-bottom:1px solid #f1f5f9}
.dsh-table tr:last-child td{border-bottom:none}
.dsh-table tr:hover td{background:#f8fafc}
.dsh-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:0.72rem;font-weight:600}
.dsh-badge.pending{background:#fff7ed;color:#c2410c}
.dsh-badge.approved{background:#f0fdf4;color:#15803d}
.dsh-badge.delivered{background:#eff6ff;color:#1d4ed8}
.dsh-badge.rejected{background:#fef2f2;color:#dc2626}
.dsh-notif{display:flex;align-items:flex-start;gap:12px;padding:10px 0;border-bottom:1px solid #f1f5f9}
.dsh-notif:last-child{border-bottom:none}
.dsh-notif-icon{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.75rem;flex-shrink:0}
.dsh-notif-icon.info{background:#eff6ff;color:#2563eb}
.dsh-notif-icon.success{background:#f0fdf4;color:#16a34a}
.dsh-notif-icon.warning{background:#fffbeb;color:#d97706}
.dsh-notif-icon.error{background:#fef2f2;color:#dc2626}
.dsh-notif-title{font-size:0.85rem;font-weight:600;color:#1e293b;margin:0 0 2px}
.dsh-notif-text{font-size:0.8rem;color:#64748b;margin:0}
.dsh-notif-time{font-size:0.7rem;color:#94a3b8;white-space:nowrap}
.dsh-empty{text-align:center;padding:30px;color:#94a3b8}
.dsh-empty i{font-size:2rem;color:#cbd5e1;margin-bottom:8px;display:block}
.dsh-empty p{font-size:0.85rem;margin:4px 0}
.dsh-notif-count{background:#dc2626;color:#fff;border-radius:50%;font-size:0.65rem;padding:2px 7px;font-weight:700}
@media(max-width:900px){.dsh-stats{grid-template-columns:repeat(2,1fr)}.dsh-grid{grid-template-columns:1fr}.dsh-actions{grid-template-columns:repeat(2,1fr)}}
@media(max-width:600px){.dsh-stats{grid-template-columns:1fr 1fr;gap:10px}.dsh-actions{grid-template-columns:1fr 1fr;gap:8px}.dsh-welcome{padding:24px 20px}.dsh-welcome h1{font-size:1.2rem}}
@media(max-width:480px){.dsh-stats{grid-template-columns:1fr!important}.dsh-actions{grid-template-columns:1fr!important}}
</style>

<section class="dsh">

<!-- WELCOME -->
<div class="dsh-welcome">
    <h1><?= $greeting ?>, <?= $customerName ?> 👋</h1>
    <p>Welcome to <?= htmlspecialchars(company_name()) ?> Wholesale Portal</p>
</div>

<!-- QUICK ACTIONS -->
<div class="dsh-actions">
    <a href="index.php?page=catalog" class="dsh-action">
        <div class="dsh-action-icon" style="background:#eff6ff;color:#2563eb"><i class="fas fa-search"></i></div>
        <div class="dsh-action-label">Browse Products</div>
    </a>
    <a href="index.php?page=place_order" class="dsh-action">
        <div class="dsh-action-icon" style="background:#f0fdf4;color:#16a34a"><i class="fas fa-cart-plus"></i></div>
        <div class="dsh-action-label">New Order</div>
    </a>
    <a href="index.php?page=my_orders" class="dsh-action">
        <div class="dsh-action-icon" style="background:#fffbeb;color:#d97706"><i class="fas fa-clipboard-list"></i></div>
        <div class="dsh-action-label">My Orders</div>
    </a>
</div>

<!-- STATS -->
<div class="dsh-stats">
    <div class="dsh-stat">
        <div class="dsh-stat-icon" style="background:#eff6ff;color:#2563eb"><i class="fas fa-shopping-cart"></i></div>
        <div><div class="dsh-stat-val"><?= $stats['total'] ?></div><div class="dsh-stat-label">Total Orders</div></div>
    </div>
    <div class="dsh-stat">
        <div class="dsh-stat-icon" style="background:#fffbeb;color:#d97706"><i class="fas fa-hourglass-half"></i></div>
        <div><div class="dsh-stat-val"><?= $stats['pending'] ?></div><div class="dsh-stat-label">Pending</div></div>
    </div>
    <div class="dsh-stat">
        <div class="dsh-stat-icon" style="background:#f5f3ff;color:#7c3aed"><i class="fas fa-cog"></i></div>
        <div><div class="dsh-stat-val"><?= $stats['processing'] ?></div><div class="dsh-stat-label">Processing</div></div>
    </div>
    <div class="dsh-stat">
        <div class="dsh-stat-icon" style="background:#f0fdf4;color:#16a34a"><i class="fas fa-check-double"></i></div>
        <div><div class="dsh-stat-val"><?= $stats['delivered'] ?></div><div class="dsh-stat-label">Delivered</div></div>
    </div>
    <div class="dsh-stat">
        <div class="dsh-stat-icon" style="background:#f0fdfa;color:#0d9488"><i class="fas fa-money-bill-wave"></i></div>
        <div><div class="dsh-stat-val"><?= fmt($stats['total_spent']) ?></div><div class="dsh-stat-label">Total Paid</div></div>
    </div>
</div>

<!-- 2-COL: RECENT ORDERS + NOTIFICATIONS -->
<div class="dsh-grid">
    <!-- Recent Orders -->
    <div class="dsh-panel">
        <div class="dsh-panel-head">
            <div class="dsh-panel-title"><i class="fas fa-clock"></i> Recent Orders</div>
            <a href="index.php?page=my_orders" class="dsh-panel-link">View All <i class="fas fa-arrow-right"></i></a>
        </div>
        <?php if (empty($recentOrders)): ?>
        <div class="dsh-empty"><i class="fas fa-shopping-cart"></i><p>No orders yet.</p><p><a href="index.php?page=catalog" style="color:#2563eb;font-weight:600">Browse products</a> to get started.</p></div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="dsh-table">
            <thead><tr><th>Order</th><th>Date</th><th>Amount</th><th>Status</th><th>View</th></tr></thead>
            <tbody>
            <?php foreach ($recentOrders as $o):
                $bc = 'pending'; $bt = ucfirst($o['status']);
                if ($o['status'] === 'approved') { if ($o['delivery_status'] === 'delivered') { $bc = 'delivered'; $bt = 'Delivered'; } else { $bc = 'approved'; $bt = 'Processing'; } }
                elseif ($o['status'] === 'rejected') { $bc = 'rejected'; }
            ?>
            <tr>
                <td><strong>#<?= $o['id'] ?></strong></td>
                <td><?= date('M d, Y', strtotime($o['order_date'])) ?></td>
                <td><strong><?= fmt((float)$o['total_amount']) ?></strong></td>
                <td><span class="dsh-badge <?= $bc ?>"><?= $bt ?></span></td>
                <td><a href="index.php?page=my_orders" style="color:#2563eb;font-weight:600;font-size:0.82rem;text-decoration:none">View</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Notifications -->
    <div class="dsh-panel">
        <div class="dsh-panel-head">
            <div class="dsh-panel-title"><i class="fas fa-bell"></i> Notifications</div>
            <?php if ($unreadNotifs > 0): ?><span class="dsh-notif-count"><?= $unreadNotifs ?></span><?php endif; ?>
        </div>
        <div class="dsh-panel-body" style="max-height:400px;overflow-y:auto;padding:10px 22px">
            <?php if (empty($notifs)): ?>
            <div class="dsh-empty"><i class="fas fa-bell-slash"></i><p>No notifications</p></div>
            <?php else: ?>
                <?php foreach ($notifs as $n): ?>
                <div class="dsh-notif" style="<?= !$n['is_read'] ? 'background:#eff6ff;border-radius:8px;padding:10px 12px;margin-bottom:4px' : '' ?>">
                    <div class="dsh-notif-icon <?= htmlspecialchars($n['type']) ?>"><i class="fas fa-<?= $n['type']==='success'?'check-circle':($n['type']==='error'?'exclamation-circle':($n['type']==='warning'?'exclamation-triangle':'info-circle')) ?>"></i></div>
                    <div style="flex:1;min-width:0">
                        <div class="dsh-notif-title"><?= htmlspecialchars($n['title']) ?></div>
                        <div class="dsh-notif-text"><?= htmlspecialchars($n['message']) ?></div>
                    </div>
                    <div class="dsh-notif-time"><?= date('M d', strtotime($n['created_at'])) ?></div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

</section>
