<?php
// Super Admin module — root account only. Server-side hard gate.
requireSuperAdmin();

$pdo = getPDO();
ensureAdminAuditLog($pdo);

$user = currentUser();
$curUid = (int) ($user['id'] ?? 0);
$currency = get_setting('currency', 'ETB');

// ── Core statistics ───────────────────────────────────────────────────────
$stats = [];
$stats['total_users']       = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role <> 'customer'")->fetchColumn();
$stats['active_users']      = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role <> 'customer' AND account_status = 'approved'")->fetchColumn();
$stats['customer_accounts'] = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetchColumn();
$stats['total_products']    = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE product_status = 'active'")->fetchColumn();
$stats['total_customers']   = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE customer_status = 'active'")->fetchColumn();
$stats['total_suppliers']   = (int) $pdo->query("SELECT COUNT(*) FROM suppliers WHERE supplier_status = 'active'")->fetchColumn();
$stats['total_orders']      = (int) $pdo->query("SELECT COUNT(*) FROM customer_orders")->fetchColumn();
$stats['pending_orders']    = (int) $pdo->query("SELECT COUNT(*) FROM customer_orders WHERE status = 'pending'")->fetchColumn();
$stats['low_stock']         = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE product_status = 'active' AND stock_quantity > 0 AND stock_quantity <= COALESCE(NULLIF(reorder_level,0),10)")->fetchColumn();
$stats['out_of_stock']      = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE product_status = 'active' AND stock_quantity <= 0")->fetchColumn();
$stats['expiring_batches']  = (int) $pdo->query("SELECT COUNT(*) FROM product_batches WHERE quantity > 0 AND status = 'active' AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)")->fetchColumn();
$stats['expired_batches']   = (int) $pdo->query("SELECT COUNT(*) FROM product_batches WHERE quantity > 0 AND status = 'active' AND expiry_date < CURDATE()")->fetchColumn();
$stats['pending_transfers'] = (int) $pdo->query("SELECT COUNT(*) FROM bank_transfers WHERE status = 'submitted'")->fetchColumn();
$stats['credit_outstanding']= (float) $pdo->query("SELECT COALESCE(SUM(outstanding_balance),0) FROM invoices WHERE payment_status IN ('unpaid','partial')")->fetchColumn();
$stats['credit_overdue']    = (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE payment_status IN ('unpaid','partial') AND credit_due_date IS NOT NULL AND credit_due_date < CURDATE()")->fetchColumn();
$stats['failed_logins_24h'] = (int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
$stats['roles_count']       = (int) $pdo->query("SELECT COUNT(*) FROM roles WHERE status = 'active'")->fetchColumn();

// ── System alerts / important notifications ────────────────────────────────
$alerts = [];
if ($stats['out_of_stock'] > 0)      $alerts[] = ['error',   'fa-box-open',                $stats['out_of_stock'] . ' active products are out of stock'];
if ($stats['expired_batches'] > 0)   $alerts[] = ['error',   'fa-skull-crossbones',        $stats['expired_batches'] . ' stock batches have expired'];
if ($stats['credit_overdue'] > 0)    $alerts[] = ['error',   'fa-file-invoice-dollar',     $stats['credit_overdue'] . ' credit invoices are overdue'];
if ($stats['low_stock'] > 0)         $alerts[] = ['warning', 'fa-triangle-exclamation',    $stats['low_stock'] . ' products are at or below reorder level'];
if ($stats['expiring_batches'] > 0)  $alerts[] = ['warning', 'fa-clock',                   $stats['expiring_batches'] . ' batches expire within 90 days'];
if ($stats['pending_orders'] > 0)    $alerts[] = ['info',    'fa-hourglass-half',          $stats['pending_orders'] . ' customer orders awaiting approval'];
if ($stats['pending_transfers'] > 0) $alerts[] = ['info',    'fa-right-left',              $stats['pending_transfers'] . ' bank transfers awaiting approval'];
if ($stats['failed_logins_24h'] > 0) $alerts[] = ['warning', 'fa-user-shield',             $stats['failed_logins_24h'] . ' failed login attempts in the last 24 hours'];
$stats['alert_count'] = count($alerts);

// ── Recent administrative activity (audit trail) ───────────────────────────
$recentAdmin = $pdo->query(
    "SELECT a.action, a.entity, a.entity_id, a.details, a.username, a.ip_address, a.created_at
       FROM admin_audit_log a
      ORDER BY a.id DESC
      LIMIT 10"
)->fetchAll();

// ── Recent users / products / orders ───────────────────────────────────────
$recent_users = $pdo->query(
    "SELECT u.id, u.username, u.full_name, u.role, u.account_status, u.created_at, r.name AS role_name
       FROM users u LEFT JOIN roles r ON u.role_id = r.id
      WHERE u.role <> 'customer'
      ORDER BY u.id DESC LIMIT 5"
)->fetchAll();

$recent_products = $pdo->query(
    "SELECT p.id, p.name, p.stock_quantity, p.reorder_level, p.price, p.product_status, c.name AS category_name
       FROM products p LEFT JOIN categories c ON p.category_id = c.id
      ORDER BY p.id DESC LIMIT 5"
)->fetchAll();

$recent_orders = $pdo->query(
    "SELECT co.id, co.order_date, co.total_amount, co.status, co.payment_confirmed, c.name AS customer_name
       FROM customer_orders co LEFT JOIN customers c ON co.customer_id = c.id
      ORDER BY co.id DESC LIMIT 5"
)->fetchAll();

// ── System health ──────────────────────────────────────────────────────────
$diskFree = @disk_free_space('.');
$diskTotal = @disk_total_space('.');
$diskPct = ($diskFree !== false && $diskTotal) ? round((1 - $diskFree / $diskTotal) * 100, 1) : null;
$health = [
    'php_version'        => PHP_VERSION,
    'db_driver'          => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
    'server'             => $_SERVER['SERVER_SOFTWARE'] ?? php_sapi_name(),
    'memory_limit'       => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time') . 's',
    'upload_max'         => ini_get('upload_max_filesize'),
    'disk_usage_pct'     => $diskPct,
];

$fmtMoney = static fn($v) => number_format((float) $v, 2);
$badgeClass = static function (string $status): string {
    return match (strtolower($status)) {
        'active', 'approved', 'delivered', 'completed', 'paid' => 'is-ok',
        'pending', 'inactive', 'partial', 'submitted' => 'is-warn',
        'rejected', 'cancelled', 'failed', 'overdue', 'unpaid' => 'is-danger',
        default => 'is-neutral',
    };
};
?><style>
        :root{--sad-radius:14px}
        .sad-wrap{padding:4px 0 40px}
        .sad-head{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px}
        .sad-title{display:flex;align-items:center;gap:12px}
        .sad-title .sad-shield{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:#fff;background:linear-gradient(135deg,#7c3aed,#4f46e5);box-shadow:0 8px 20px rgba(79,70,229,.35)}
        .sad-title h1{margin:0;font-size:1.35rem;font-weight:800;color:var(--neutral-900)}
        .sad-title p{margin:2px 0 0;font-size:.82rem;color:var(--neutral-500)}
        .sad-chip{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;font-size:.72rem;font-weight:800;letter-spacing:.3px;background:#f3e8ff;color:#6d28d9;border:1px solid #e9d5ff}
        .sad-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px}
        .sad-card{background:var(--white);border:1px solid var(--neutral-300);border-radius:var(--sad-radius);box-shadow:0 1px 3px rgba(15,23,42,.06);padding:16px 18px}
        .sad-stat{display:flex;align-items:center;gap:14px}
        .sad-stat .sad-ic{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.05rem;flex-shrink:0}
        .sad-stat .sad-val{font-size:1.6rem;font-weight:800;line-height:1.05;color:var(--neutral-900)}
        .sad-stat .sad-lab{font-size:.74rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--neutral-500);margin-top:3px}
        .sad-sub{font-size:.72rem;color:var(--neutral-500);margin-top:4px}
        .sad-cols{display:grid;grid-template-columns:1.35fr 1fr;gap:16px;margin-bottom:18px}
        .sad-h{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px}
        .sad-h h2{margin:0;font-size:.98rem;font-weight:800;color:var(--neutral-900);display:flex;align-items:center;gap:8px}
        .sad-h h2 i{color:var(--primary-600)}
        .sad-h a{font-size:.75rem;font-weight:700;color:var(--primary-600);text-decoration:none}
        .sad-alert{display:flex;align-items:center;gap:12px;padding:11px 14px;border-radius:12px;border:1px solid;font-size:.83rem;font-weight:600;margin-bottom:8px}
        .sad-alert i{font-size:.95rem}
        .sad-alert.error{background:#fef2f2;border-color:#fecaca;color:#b91c1c}
        .sad-alert.warning{background:#fffbeb;border-color:#fde68a;color:#b45309}
        .sad-alert.info{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8}
        .sad-alert.none{background:#f0fdf4;border-color:#bbf7d0;color:#15803d}
        .sad-actions{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
        .sad-action{display:flex;align-items:center;gap:11px;padding:12px 13px;border:1px solid var(--neutral-300);border-radius:12px;background:var(--white);text-decoration:none;transition:.14s}
        .sad-action:hover{border-color:var(--primary-300);background:var(--primary-50);transform:translateY(-1px)}
        .sad-action .sad-aic{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;flex-shrink:0;font-size:.9rem}
        .sad-action b{display:block;font-size:.82rem;color:var(--neutral-900)}
        .sad-action span{display:block;font-size:.7rem;color:var(--neutral-500);margin-top:1px}
        .sad-table{width:100%;border-collapse:collapse;font-size:.82rem}
        .sad-table th{text-align:left;font-size:.68rem;text-transform:uppercase;letter-spacing:.4px;color:var(--neutral-500);padding:8px 10px;border-bottom:1px solid var(--neutral-300)}
        .sad-table td{padding:9px 10px;border-bottom:1px solid var(--neutral-100);color:var(--neutral-800)}
        .sad-table tr:last-child td{border-bottom:none}
        .sad-badge{display:inline-block;padding:2px 9px;border-radius:999px;font-size:.68rem;font-weight:700;text-transform:capitalize}
        .sad-badge.is-ok{background:#dcfce7;color:#15803d}
        .sad-badge.is-warn{background:#fef3c7;color:#b45309}
        .sad-badge.is-danger{background:#fee2e2;color:#b91c1c}
        .sad-badge.is-neutral{background:var(--neutral-200);color:var(--neutral-600)}
        .sad-empty{text-align:center;padding:26px 10px;color:var(--neutral-500);font-size:.82rem}
        .sad-empty i{font-size:1.6rem;display:block;margin-bottom:8px;color:var(--neutral-300)}
        .sad-health{display:grid;grid-template-columns:1fr 1fr;gap:8px}
        .sad-health div{display:flex;justify-content:space-between;gap:8px;padding:8px 10px;background:var(--neutral-100);border-radius:9px;font-size:.78rem}
        .sad-health span:first-child{color:var(--neutral-500);font-weight:600}
        .sad-health span:last-child{color:var(--neutral-900);font-weight:700;text-align:right;word-break:break-word}
        .sad-mini{font-size:.74rem;color:var(--neutral-500)}
        .sad-money{font-variant-numeric:tabular-nums;font-weight:700}
        @media(max-width:1100px){.sad-grid{grid-template-columns:repeat(2,1fr)}.sad-cols{grid-template-columns:1fr}}
        @media(max-width:640px){.sad-grid{grid-template-columns:1fr}.sad-actions{grid-template-columns:1fr}.sad-health{grid-template-columns:1fr}}
    </style>
<div class="sad-wrap" style="max-width:1400px;margin:0 auto">

    <div class="sad-head">
        <div class="sad-title">
            <div class="sad-shield"><i class="fas fa-shield-halved"></i></div>
            <div>
                <h1>Super Admin Dashboard</h1>
                <p>Root control center — full system oversight, users, roles, settings and audit</p>
            </div>
        </div>
        <span class="sad-chip"><i class="fas fa-crown"></i> Root Account</span>
    </div>

    <!-- Stats -->
    <div class="sad-grid">
        <div class="sad-card"><div class="sad-stat">
            <div class="sad-ic" style="background:#eef2ff;color:#4f46e5"><i class="fas fa-users"></i></div>
            <div><div class="sad-val"><?= number_format($stats['total_users']) ?></div><div class="sad-lab">Staff Users</div></div>
        </div><div class="sad-sub"><?= number_format($stats['active_users']) ?> active · <?= number_format($stats['roles_count']) ?> roles</div></div>

        <div class="sad-card"><div class="sad-stat">
            <div class="sad-ic" style="background:#ecfdf5;color:#059669"><i class="fas fa-capsules"></i></div>
            <div><div class="sad-val"><?= number_format($stats['total_products']) ?></div><div class="sad-lab">Active Products</div></div>
        </div><div class="sad-sub"><?= number_format($stats['out_of_stock']) ?> out of stock · <?= number_format($stats['low_stock']) ?> low</div></div>

        <div class="sad-card"><div class="sad-stat">
            <div class="sad-ic" style="background:#eff6ff;color:#2563eb"><i class="fas fa-store"></i></div>
            <div><div class="sad-val"><?= number_format($stats['total_customers']) ?></div><div class="sad-lab">Active Customers</div></div>
        </div><div class="sad-sub"><?= number_format($stats['customer_accounts']) ?> portal accounts</div></div>

        <div class="sad-card"><div class="sad-stat">
            <div class="sad-ic" style="background:#fff7ed;color:#ea580c"><i class="fas fa-truck-field"></i></div>
            <div><div class="sad-val"><?= number_format($stats['total_suppliers']) ?></div><div class="sad-lab">Suppliers</div></div>
        </div><div class="sad-sub"><?= number_format($stats['total_orders']) ?> orders on record</div></div>

        <div class="sad-card"><div class="sad-stat">
            <div class="sad-ic" style="background:#f5f3ff;color:#7c3aed"><i class="fas fa-receipt"></i></div>
            <div><div class="sad-val"><?= number_format($stats['total_orders']) ?></div><div class="sad-lab">Total Orders</div></div>
        </div><div class="sad-sub"><?= number_format($stats['pending_orders']) ?> pending approval</div></div>

        <div class="sad-card"><div class="sad-stat">
            <div class="sad-ic" style="background:#fef2f2;color:#dc2626"><i class="fas fa-bell"></i></div>
            <div><div class="sad-val"><?= number_format($stats['alert_count']) ?></div><div class="sad-lab">System Alerts</div></div>
        </div><div class="sad-sub"><?= number_format($stats['failed_logins_24h']) ?> failed logins (24h)</div></div>

        <div class="sad-card"><div class="sad-stat">
            <div class="sad-ic" style="background:#ecfeff;color:#0891b2"><i class="fas fa-coins"></i></div>
            <div><div class="sad-val sad-money"><?= $fmtMoney($stats['credit_outstanding']) ?></div><div class="sad-lab">Credit Outstanding (<?= htmlspecialchars($currency) ?>)</div></div>
        </div><div class="sad-sub"><?= number_format($stats['credit_overdue']) ?> overdue invoices</div></div>

        <div class="sad-card"><div class="sad-stat">
            <div class="sad-ic" style="background:#fefce8;color:#ca8a04"><i class="fas fa-hourglass-half"></i></div>
            <div><div class="sad-val"><?= number_format($stats['pending_transfers']) ?></div><div class="sad-lab">Pending Transfers</div></div>
        </div><div class="sad-sub"><?= number_format($stats['expiring_batches']) ?> batches expiring ≤90d</div></div>
    </div>

    <!-- Alerts + Quick actions -->
    <div class="sad-cols">
        <div class="sad-card">
            <div class="sad-h"><h2><i class="fas fa-bell"></i> System Alerts &amp; Notifications</h2>
                <a href="?page=audit_logs"><i class="fas fa-arrow-right"></i> Audit logs</a></div>
            <?php if (empty($alerts)): ?>
                <div class="sad-alert none"><i class="fas fa-circle-check"></i> All clear — no system alerts at this time.</div>
            <?php else: ?>
                <?php foreach ($alerts as $a): ?>
                    <div class="sad-alert <?= htmlspecialchars($a[0]) ?>"><i class="fas <?= htmlspecialchars($a[1]) ?>"></i> <?= htmlspecialchars($a[2]) ?></div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="sad-card">
            <div class="sad-h"><h2><i class="fas fa-bolt"></i> Quick Actions</h2></div>
            <div class="sad-actions">
                <a class="sad-action" href="?page=users"><span class="sad-aic" style="background:#4f46e5"><i class="fas fa-user-plus"></i></span><span><b>Users</b><span>Create &amp; manage accounts</span></span></a>
                <a class="sad-action" href="?page=roles"><span class="sad-aic" style="background:#dc2626"><i class="fas fa-users-gear"></i></span><span><b>Roles</b><span>Permissions matrix</span></span></a>
                <a class="sad-action" href="?page=settings"><span class="sad-aic" style="background:#64748b"><i class="fas fa-sliders"></i></span><span><b>Settings</b><span>Company &amp; security</span></span></a>
                <a class="sad-action" href="?page=backup"><span class="sad-aic" style="background:#0891b2"><i class="fas fa-database"></i></span><span><b>Backups</b><span>Backup &amp; restore</span></span></a>
                <a class="sad-action" href="?page=audit_logs"><span class="sad-aic" style="background:#f97316"><i class="fas fa-scroll"></i></span><span><b>Audit Logs</b><span>System activity trail</span></span></a>
                <a class="sad-action" href="?page=login_history"><span class="sad-aic" style="background:#059669"><i class="fas fa-right-to-bracket"></i></span><span><b>Login History</b><span>Sign-in activity</span></span></a>
            </div>
        </div>
    </div>

    <!-- Recent admin activity + system health -->
    <div class="sad-cols">
        <div class="sad-card">
            <div class="sad-h"><h2><i class="fas fa-clock-rotate-left"></i> Recent Administrative Activity</h2></div>
            <?php if (empty($recentAdmin)): ?>
                <div class="sad-empty"><i class="fas fa-clipboard-list"></i>No administrative actions recorded yet.</div>
            <?php else: ?>
                <table class="sad-table">
                    <thead><tr><th>Action</th><th>Target</th><th>User</th><th>When</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentAdmin as $a): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($a['action']) ?></strong><?php if (!empty($a['details'])): ?><div class="sad-mini"><?= htmlspecialchars(mb_strimwidth((string) $a['details'], 0, 70, '…')) ?></div><?php endif; ?></td>
                            <td><?= htmlspecialchars(trim(($a['entity'] ?? '') . ($a['entity_id'] !== null && $a['entity_id'] !== '' ? ' #' . $a['entity_id'] : ''))) ?: '—' ?></td>
                            <td><?= htmlspecialchars($a['username'] ?? '—') ?></td>
                            <td class="sad-mini"><?= htmlspecialchars($a['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="sad-card">
            <div class="sad-h"><h2><i class="fas fa-heart-pulse"></i> System Health</h2></div>
            <div class="sad-health">
                <div><span>PHP Version</span><span><?= htmlspecialchars($health['php_version']) ?></span></div>
                <div><span>Database</span><span><?= htmlspecialchars(strtoupper($health['db_driver'])) ?> · OK</span></div>
                <div><span>Server</span><span><?= htmlspecialchars($health['server']) ?></span></div>
                <div><span>Memory Limit</span><span><?= htmlspecialchars($health['memory_limit']) ?></span></div>
                <div><span>Max Exec Time</span><span><?= htmlspecialchars($health['max_execution_time']) ?></span></div>
                <div><span>Upload Max</span><span><?= htmlspecialchars($health['upload_max']) ?></span></div>
                <div><span>Disk Usage</span><span><?= $health['disk_usage_pct'] !== null ? htmlspecialchars($health['disk_usage_pct']) . '%' : 'n/a' ?></span></div>
                <div><span>Total Tables</span><span><?= number_format((int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn()) ?></span></div>
            </div>
        </div>
    </div>

    <!-- Recent users + products -->
    <div class="sad-cols">
        <div class="sad-card">
            <div class="sad-h"><h2><i class="fas fa-user-tie"></i> Recent Users</h2><a href="?page=users">Manage <i class="fas fa-arrow-right"></i></a></div>
            <?php if (empty($recent_users)): ?>
                <div class="sad-empty"><i class="fas fa-users"></i>No staff users yet.</div>
            <?php else: ?>
                <table class="sad-table">
                    <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent_users as $u): ?>
                        <tr>
                            <td><?= htmlspecialchars($u['full_name'] ?: $u['username']) ?></td>
                            <td><?= htmlspecialchars($u['username']) ?></td>
                            <td><?= htmlspecialchars($u['role_name'] ?? $u['role']) ?></td>
                            <td><span class="sad-badge <?= $badgeClass((string) $u['account_status']) ?>"><?= htmlspecialchars($u['account_status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="sad-card">
            <div class="sad-h"><h2><i class="fas fa-pills"></i> Recent Products</h2><a href="?page=products">View all <i class="fas fa-arrow-right"></i></a></div>
            <?php if (empty($recent_products)): ?>
                <div class="sad-empty"><i class="fas fa-box"></i>No products yet.</div>
            <?php else: ?>
                <table class="sad-table">
                    <thead><tr><th>Product</th><th>Stock</th><th>Price</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent_products as $p): ?>
                        <tr>
                            <td><?= htmlspecialchars($p['name']) ?><div class="sad-mini"><?= htmlspecialchars($p['category_name'] ?? '—') ?></div></td>
                            <td><?= number_format((int) $p['stock_quantity']) ?></td>
                            <td class="sad-money"><?= $fmtMoney($p['price']) ?></td>
                            <td><span class="sad-badge <?= $badgeClass((string) $p['product_status']) ?>"><?= htmlspecialchars($p['product_status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent orders -->
    <div class="sad-card">
        <div class="sad-h"><h2><i class="fas fa-receipt"></i> Recent Orders</h2><a href="?page=customer_order_queue">Order queue <i class="fas fa-arrow-right"></i></a></div>
        <?php if (empty($recent_orders)): ?>
            <div class="sad-empty"><i class="fas fa-receipt"></i>No orders yet.</div>
        <?php else: ?>
            <table class="sad-table">
                <thead><tr><th>#</th><th>Customer</th><th>Date</th><th>Amount</th><th>Payment</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($recent_orders as $o): ?>
                    <tr>
                        <td>#<?= (int) $o['id'] ?></td>
                        <td><?= htmlspecialchars($o['customer_name'] ?? '—') ?></td>
                        <td class="sad-mini"><?= htmlspecialchars((string) $o['order_date']) ?></td>
                        <td class="sad-money"><?= $fmtMoney($o['total_amount']) ?></td>
                        <td><span class="sad-badge <?= $o['payment_confirmed'] ? 'is-ok' : 'is-warn' ?>"><?= $o['payment_confirmed'] ? 'Confirmed' : 'Unconfirmed' ?></span></td>
                        <td><span class="sad-badge <?= $badgeClass((string) $o['status']) ?>"><?= htmlspecialchars((string) $o['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div>
