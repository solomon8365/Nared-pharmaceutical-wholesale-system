<?php
/**
 * Delivery ERP Module - Shared Helpers, CSS, and JS
 * Used by all delivery_* pages for consistent design
 */

function ensureDeliveryErpMigration(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM driver_profiles LIMIT 1');
        $stmt->execute();
        $stmt->closeCursor();
    } catch (Exception $e) {
        $migFile = __DIR__ . '/../migrations/migration_delivery_erp.sql';
        if (!file_exists($migFile)) return;
        $sql = file_get_contents($migFile);
        if (!$sql) return;
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $stmt) {
            if ($stmt === '' || stripos($stmt, 'PREPARE') === 0 || stripos($stmt, 'SET ') === 0 || stripos($stmt, 'EXECUTE') === 0 || stripos($stmt, 'DEALLOCATE') === 0) continue;
            try { $pdo->exec($stmt); } catch (Exception $e) {}
        }
    }
    ensureDriverStatusColumn($pdo);
    ensureDeliveryPodMigration($pdo);
    ensureDriverProfilesMigration($pdo);
    ensureDeliveryRolePermissions($pdo);
}

/**
 * Grants the Delivery Staff role (role_id=3) every delivery operation.
 * Idempotent: INSERT ... SELECT ... WHERE NOT EXISTS, so re-running is a no-op.
 * Bumps the permission cache version only when new rows were actually added.
 */
function ensureDeliveryRolePermissions(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $migFile = __DIR__ . '/../migrations/migration_delivery_role_permissions.sql';
        if (!file_exists($migFile)) return;
        $sql = file_get_contents($migFile);
        if (!$sql) return;
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        $changed = 0;
        foreach ($statements as $stmt) {
            if ($stmt === '' || stripos($stmt, 'PREPARE') === 0 || stripos($stmt, 'SET ') === 0 || stripos($stmt, 'EXECUTE') === 0 || stripos($stmt, 'DEALLOCATE') === 0) continue;
            try {
                $changed += (int) $pdo->exec($stmt);
            } catch (Exception $e) {}
        }
        if ($changed > 0 && function_exists('bumpPermVersion')) {
            bumpPermVersion();
        }
    } catch (Exception $e) {}
}

/**
 * Makes driver_profiles self-contained (no login account required) and remaps
 * customer_orders.delivery_staff_id from users.id to driver_profiles.id.
 * Idempotent: re-running is a no-op once the FK targets driver_profiles.
 */
function ensureDriverProfilesMigration(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $hasCol = function (string $table, string $col) use ($pdo): bool {
            try {
                $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
                $st->execute(['t' => $table, 'c' => $col]);
                return (int) $st->fetchColumn() > 0;
            } catch (Exception $e) { return true; }
        };
        $fkTargets = function (string $table, string $col) use ($pdo): array {
            $st = $pdo->prepare("SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c AND REFERENCED_TABLE_NAME IS NOT NULL");
            $st->execute(['t' => $table, 'c' => $col]);
            return $st->fetchAll();
        };

        // 1. Driver profiles carry their own identity fields
        if (!$hasCol('driver_profiles', 'full_name')) {
            $pdo->exec("ALTER TABLE driver_profiles ADD COLUMN full_name VARCHAR(150) NULL AFTER user_id");
        }
        if (!$hasCol('driver_profiles', 'phone')) {
            $pdo->exec("ALTER TABLE driver_profiles ADD COLUMN phone VARCHAR(50) NULL AFTER full_name");
        }
        if (!$hasCol('driver_profiles', 'status')) {
            $pdo->exec("ALTER TABLE driver_profiles ADD COLUMN status ENUM('active','suspended') NOT NULL DEFAULT 'active' AFTER availability");
        }

        // 2. Decouple driver_profiles from the users table (nullable, no FK)
        foreach ($fkTargets('driver_profiles', 'user_id') as $fk) {
            try { $pdo->exec("ALTER TABLE driver_profiles DROP FOREIGN KEY `{$fk['CONSTRAINT_NAME']}`"); } catch (Exception $e) {}
        }
        try { $pdo->exec("ALTER TABLE driver_profiles DROP INDEX user_id"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE driver_profiles MODIFY COLUMN user_id INT UNSIGNED NULL"); } catch (Exception $e) {}

        // 3. One-time backfill of identity from legacy login link
        try {
            $hasUsersPhone = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'phone'")->fetchColumn() > 0;
        } catch (Exception $e) { $hasUsersPhone = false; }
        $phoneSet = $hasUsersPhone ? ", dp.phone = COALESCE(NULLIF(dp.phone, ''), u.phone)" : '';
        $pdo->exec("UPDATE driver_profiles dp JOIN users u ON dp.user_id = u.id SET dp.full_name = COALESCE(NULLIF(dp.full_name, ''), u.full_name){$phoneSet}");

        // 4. Remap delivery_staff_id only while it still references users
        $legacy = false;
        foreach ($fkTargets('customer_orders', 'delivery_staff_id') as $fk) {
            if ($fk['REFERENCED_TABLE_NAME'] === 'users') $legacy = true;
            try { $pdo->exec("ALTER TABLE customer_orders DROP FOREIGN KEY `{$fk['CONSTRAINT_NAME']}`"); } catch (Exception $e) {}
        }
        if ($legacy) {
            $pdo->exec("UPDATE customer_orders co LEFT JOIN users u ON co.delivery_staff_id = u.id LEFT JOIN driver_profiles dp ON dp.user_id = u.id SET co.delivery_staff_id = dp.id WHERE co.delivery_staff_id IS NOT NULL");
            $pdo->exec("UPDATE customer_orders co JOIN driver_profiles dp ON co.delivery_staff_id = dp.id SET co.driver_name = dp.full_name WHERE co.driver_name IS NULL OR co.driver_name = ''");
        }
        $hasDriverFk = false;
        foreach ($fkTargets('customer_orders', 'delivery_staff_id') as $fk) {
            if ($fk['REFERENCED_TABLE_NAME'] === 'driver_profiles') $hasDriverFk = true;
        }
        if (!$hasDriverFk) {
            try {
                $pdo->exec("ALTER TABLE customer_orders ADD CONSTRAINT fk_customer_orders_delivery_staff FOREIGN KEY (delivery_staff_id) REFERENCES driver_profiles(id) ON DELETE SET NULL");
            } catch (Exception $e) {}
        }
    } catch (Exception $e) {}
}

function ensureDeliveryPodMigration(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $hasCol = function (string $table, string $col) use ($pdo): bool {
            try {
                $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
                $st->execute(['t' => $table, 'c' => $col]);
                return (int) $st->fetchColumn() > 0;
            } catch (Exception $e) { return true; }
        };
        if (!$hasCol('customer_orders', 'pod_recorded_by')) {
            $pdo->exec("ALTER TABLE customer_orders MODIFY COLUMN delivery_status ENUM('assigned','accepted','picked_up','out_for_delivery','pod_pending','delivered','failed','returned') NULL");
            $pdo->exec("ALTER TABLE customer_orders ADD COLUMN pod_recorded_by INT UNSIGNED NULL AFTER confirmation_method");
            $pdo->exec("ALTER TABLE customer_orders ADD COLUMN pod_recorded_at DATETIME NULL AFTER pod_recorded_by");
            $pdo->exec("ALTER TABLE customer_orders ADD COLUMN pod_verified_by INT UNSIGNED NULL AFTER pod_recorded_at");
            $pdo->exec("ALTER TABLE customer_orders ADD COLUMN pod_verified_at DATETIME NULL AFTER pod_verified_by");
        }
        if (!$hasCol('invoices', 'status')) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN status ENUM('pending','completed') NOT NULL DEFAULT 'pending' AFTER delivery_address");
            $pdo->exec("ALTER TABLE invoices ADD COLUMN completed_at DATETIME NULL AFTER status");
            $pdo->exec("ALTER TABLE invoices ADD COLUMN completed_by INT UNSIGNED NULL AFTER completed_at");
        }
        if (!is_dir(__DIR__ . '/../uploads/pod')) {
            @mkdir(__DIR__ . '/../uploads/pod', 0777, true);
        }
    } catch (Exception $e) {}
}

function ensureDriverStatusColumn(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("ALTER TABLE driver_profiles ADD COLUMN status ENUM('active','suspended') NOT NULL DEFAULT 'active' AFTER availability");
    } catch (Exception $e) {}
}

function delGetDb(): PDO {
    if (function_exists('getPDO')) {
        return getPDO();
    }
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true]);
    }
    return $pdo;
}

function delLog(PDO $pdo, int $orderId, string $action, ?string $oldStatus, ?string $newStatus, ?int $userId, ?string $notes = ''): void {
    try {
        $stmt = $pdo->prepare('INSERT INTO delivery_logs (order_id, action, old_status, new_status, performed_by, notes, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$orderId, $action, $oldStatus, $newStatus, $userId, $notes, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Exception $e) {}
}

function delNotify(PDO $pdo, int $userId, ?int $orderId, string $title, string $message, string $type = 'info'): void {
    try {
        $stmt = $pdo->prepare('INSERT INTO delivery_notifications (user_id, order_id, title, message, type) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $orderId, $title, $message, $type]);
    } catch (Exception $e) {}
}

function delGetDriverProfile(PDO $pdo, int $driverId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM driver_profiles WHERE id = ?');
    $stmt->execute([$driverId]);
    return $stmt->fetch() ?: null;
}

function delSyncDriverAvailability(PDO $pdo, int $driverId): void {
    if ($driverId <= 0) return;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM customer_orders WHERE delivery_staff_id = ? AND delivery_status IN ('assigned','accepted','picked_up','out_for_delivery')");
    $stmt->execute([$driverId]);
    $active = (int) $stmt->fetchColumn();
    if ($active > 0) {
        $pdo->prepare("UPDATE driver_profiles SET availability = 'on_delivery' WHERE id = ?")->execute([$driverId]);
    } else {
        $pdo->prepare("UPDATE driver_profiles SET availability = 'available' WHERE id = ? AND availability = 'on_delivery'")->execute([$driverId]);
    }
}

function delGetAvailableDrivers(PDO $pdo): array {
    $stmt = $pdo->query("SELECT id, full_name, phone, vehicle_type, vehicle_plate, availability, rating, successful_deliveries, status FROM driver_profiles WHERE status = 'active' AND availability = 'available' ORDER BY rating DESC, successful_deliveries DESC");
    return $stmt->fetchAll();
}

function delGetAllDrivers(PDO $pdo): array {
    $stmt = $pdo->query("SELECT id, full_name, phone, vehicle_type, vehicle_plate, license_no, availability, rating, total_deliveries, successful_deliveries, failed_deliveries, avg_delivery_time_min, status FROM driver_profiles ORDER BY FIELD(status,'active','suspended') ASC, availability ASC, full_name ASC");
    return $stmt->fetchAll();
}

function delGetOrderDelivery(PDO $pdo, int $orderId): ?array {
    $stmt = $pdo->prepare('SELECT co.*, c.name AS customer_name, c.phone AS customer_phone, c.contact_person AS customer_contact, dp.full_name AS staff_name FROM customer_orders co LEFT JOIN customers c ON co.customer_id = c.id LEFT JOIN driver_profiles dp ON co.delivery_staff_id = dp.id WHERE co.id = ?');
    $stmt->execute([$orderId]);
    return $stmt->fetch() ?: null;
}

function delFindInvoiceForOrder(PDO $pdo, int $orderId): ?array {
    $stmt = $pdo->prepare('SELECT id, status FROM invoices WHERE customer_order_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$orderId]);
    return $stmt->fetch() ?: null;
}

function delCompleteInvoiceForOrder(PDO $pdo, int $orderId, ?int $userId = null): void {
    $inv = delFindInvoiceForOrder($pdo, $orderId);
    if (!$inv) return;
    require_once __DIR__ . '/workflow_engine.php';
    if (WorkflowEngine::canTransition('invoice_fulfillment', $inv['status'], 'completed')) {
        $result = WorkflowEngine::transition($pdo, 'invoice_fulfillment', (int) $inv['id'], 'completed', [
            'extra' => ['completed_at' => date('Y-m-d H:i:s'), 'completed_by' => $userId],
            'changed_by' => $userId,
            'notes' => 'Delivery verified — invoice fulfilled',
        ]);
        if ($result['success']) {
            delLog($pdo, $orderId, 'invoice_completed', $inv['status'], 'completed', $userId, 'Invoice marked completed after delivery verification');
        }
    }
}

function delUploadPodPhoto(): ?string {
    if (empty($_FILES['delivery_photo']) || ($_FILES['delivery_photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES['delivery_photo'];
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] <= 0 || $file['size'] > MAX_UPLOAD_SIZE) {
        return false;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($ext, $allowed, true)) {
        return false;
    }
    $dir = __DIR__ . '/../uploads/pod';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $path = 'uploads/pod/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/../' . $path)) {
        return false;
    }
    return $path;
}

function delBadgeHtml(string $status): string {
    $map = [
        'assigned' => ['bg' => '#dbeafe', 'fg' => '#1e40af', 'label' => 'Assigned'],
        'accepted' => ['bg' => '#e0e7ff', 'fg' => '#3730a3', 'label' => 'Accepted'],
        'picked_up' => ['bg' => '#fef3c7', 'fg' => '#92400e', 'label' => 'Picked Up'],
        'out_for_delivery' => ['bg' => '#ffedd5', 'fg' => '#9a3412', 'label' => 'Out for Delivery'],
        'pod_pending' => ['bg' => '#fef3c7', 'fg' => '#b45309', 'label' => 'Awaiting Verification'],
        'delivered' => ['bg' => '#dcfce7', 'fg' => '#166534', 'label' => 'Delivered'],
        'failed' => ['bg' => '#fee2e2', 'fg' => '#991b1b', 'label' => 'Failed'],
        'returned' => ['bg' => '#f3e8ff', 'fg' => '#6b21a8', 'label' => 'Returned'],
        'pending' => ['bg' => '#f1f5f9', 'fg' => '#475569', 'label' => 'Pending'],
        'available' => ['bg' => '#dcfce7', 'fg' => '#166534', 'label' => 'Available'],
        'busy' => ['bg' => '#fef3c7', 'fg' => '#92400e', 'label' => 'Busy'],
        'on_delivery' => ['bg' => '#dbeafe', 'fg' => '#1e40af', 'label' => 'On Delivery'],
        'offline' => ['bg' => '#f1f5f9', 'fg' => '#475569', 'label' => 'Offline'],
        'on_leave' => ['bg' => '#fee2e2', 'fg' => '#991b1b', 'label' => 'On Leave'],
    ];
    $info = $map[$status] ?? ['bg' => '#f1f5f9', 'fg' => '#475569', 'label' => ucfirst(str_replace('_', ' ', $status))];
    return '<span style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:.72rem;font-weight:700;background:' . $info['bg'] . ';color:' . $info['fg'] . '">' . htmlspecialchars($info['label']) . '</span>';
}

function delStatusIcon(string $status): string {
    $icons = [
        'assigned' => 'fa-user-check',
        'accepted' => 'fa-handshake',
        'picked_up' => 'fa-box',
        'out_for_delivery' => 'fa-truck',
        'pod_pending' => 'fa-clipboard-check',
        'delivered' => 'fa-check-circle',
        'failed' => 'fa-times-circle',
        'returned' => 'fa-undo',
    ];
    return $icons[$status] ?? 'fa-circle';
}

function delWorkflowSteps(): array {
    return [
        ['key' => 'assigned', 'label' => 'Assigned', 'icon' => 'fa-user-check', 'color' => '#2563eb'],
        ['key' => 'accepted', 'label' => 'Accepted', 'icon' => 'fa-handshake', 'color' => '#7c3aed'],
        ['key' => 'picked_up', 'label' => 'Picked Up', 'icon' => 'fa-box', 'color' => '#d97706'],
        ['key' => 'out_for_delivery', 'label' => 'Out for Delivery', 'icon' => 'fa-truck', 'color' => '#ea580c'],
        ['key' => 'pod_pending', 'label' => 'POD Verified', 'icon' => 'fa-clipboard-check', 'color' => '#ca8a04'],
        ['key' => 'delivered', 'label' => 'Delivered', 'icon' => 'fa-check-circle', 'color' => '#059669'],
    ];
}

function delRenderTimeline(array $order): string {
    $steps = delWorkflowSteps();
    $current = $order['delivery_status'] ?? '';
    $timeMap = [
        'assigned' => $order['assigned_at'] ?? null,
        'accepted' => $order['accepted_at'] ?? null,
        'picked_up' => $order['picked_up_at'] ?? null,
        'out_for_delivery' => $order['dispatch_time'] ?? null,
        'pod_pending' => $order['pod_recorded_at'] ?? null,
        'delivered' => $order['delivered_at'] ?? null,
    ];
    $foundCurrent = false;
    $html = '<div style="display:flex;align-items:center;gap:0;margin:12px 0;overflow-x:auto;padding-bottom:4px">';
    foreach ($steps as $i => $step) {
        if ($step['key'] === $current) $foundCurrent = true;
        $done = !$foundCurrent || $step['key'] === $current;
        $bg = $done ? $step['color'] : '#e2e8f0';
        $fg = $done ? '#fff' : '#94a3b8';
        $lineBg = $done && $i < count($steps) - 1 ? $step['color'] : '#e2e8f0';
        $html .= '<div style="display:flex;flex-direction:column;align-items:center;min-width:80px">';
        $html .= '<div style="width:36px;height:36px;border-radius:50%;background:' . $bg . ';color:' . $fg . ';display:flex;align-items:center;justify-content:center;font-size:.85rem"><i class="fas ' . $step['icon'] . '"></i></div>';
        $html .= '<div style="font-size:.68rem;font-weight:600;color:' . ($done ? '#334155' : '#94a3b8') . ';margin-top:4px;text-align:center">' . $step['label'] . '</div>';
        if (!empty($timeMap[$step['key']])) {
            $html .= '<div style="font-size:.6rem;color:#94a3b8">' . date('M d, H:i', strtotime($timeMap[$step['key']])) . '</div>';
        }
        $html .= '</div>';
        if ($i < count($steps) - 1) {
            $html .= '<div style="flex:1;height:3px;min-width:20px;background:' . $lineBg . ';margin:0 -2px;margin-bottom:20px;border-radius:2px"></div>';
        }
    }
    $html .= '</div>';
    return $html;
}

function delCss(): string {
    return <<<'CSS'
<style>
:root{--del-blue:#2563eb;--del-blue-d:#1d4ed8;--del-green:#059669;--del-green-d:#047857;--del-red:#dc2626;--del-red-d:#b91c1c;--del-orange:#d97706;--del-orange-d:#b45309;--del-purple:#7c3aed;--del-purple-d:#6d28d9;--del-teal:#0d9488;--del-indigo:#4f46e5;--del-pink:#db2777;--del-gray:#64748b;--del-bg:#f1f5f9;--del-card:#fff;--del-border:#e2e8f0;--del-radius:14px;--del-shadow:0 1px 3px rgba(0,0,0,.06)}
.del-wrap{max-width:1440px;margin:0 auto;padding:0 0 40px}
.del-wrap h1{font-size:1.4rem;font-weight:800;color:#0f172a;margin-bottom:6px;display:flex;align-items:center;gap:10px}
.del-subtitle{font-size:.82rem;color:var(--del-gray);margin-bottom:20px}
.del-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:22px}
.del-stat{background:var(--del-card);border-radius:var(--del-radius);border:1px solid var(--del-border);padding:18px 16px;display:flex;align-items:flex-start;gap:12px;box-shadow:var(--del-shadow);transition:all .2s;position:relative;overflow:hidden}
.del-stat:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,.1)}
.del-stat-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;color:#fff}
.del-stat-body{flex:1;min-width:0}
.del-stat-val{font-size:1.2rem;font-weight:800;color:#0f172a;line-height:1.2}
.del-stat-label{font-size:.72rem;color:var(--del-gray);margin-top:2px;font-weight:500}
.del-card{background:var(--del-card);border-radius:var(--del-radius);border:1px solid var(--del-border);padding:20px;margin-bottom:16px;box-shadow:var(--del-shadow)}
.del-card h2{font-size:1rem;font-weight:700;color:#0f172a;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.del-card h2 i{font-size:.9rem}
.del-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.del-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px}
.del-table{width:100%;border-collapse:separate;border-spacing:0;font-size:.84rem}
.del-table th{text-align:left;padding:11px 14px;background:#f8fafc;font-weight:700;color:#475569;border-bottom:2px solid var(--del-border);font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.del-table td{padding:11px 14px;border-bottom:1px solid #f1f5f9}
.del-table tbody tr{transition:background .1s}
.del-table tbody tr:hover{background:#f8fafc}
.del-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:.72rem;font-weight:700;white-space:nowrap}
.del-btn{padding:8px 16px;border:none;border-radius:10px;font-size:.82rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;font-family:inherit}
.del-btn:hover{opacity:.85;transform:translateY(-1px)}
.del-btn-blue{background:var(--del-blue);color:#fff}
.del-btn-green{background:var(--del-green);color:#fff}
.del-btn-red{background:var(--del-red);color:#fff}
.del-btn-orange{background:var(--del-orange);color:#fff}
.del-btn-purple{background:var(--del-purple);color:#fff}
.del-btn-outline{background:transparent;border:1px solid #cbd5e1;color:#475569}
.del-btn-outline:hover{background:#f8fafc;border-color:#94a3b8}
.del-btn-sm{padding:8px 12px;min-height:36px;font-size:.75rem;border-radius:8px}
.del-tabs{display:flex;gap:0;border-bottom:2px solid var(--del-border);margin-bottom:0;overflow-x:auto}
.del-tab{padding:11px 20px;font-size:.84rem;font-weight:600;color:var(--del-gray);cursor:pointer;border:none;background:none;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .15s;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;font-family:inherit}
.del-tab:hover{color:#334155}
.del-tab.active{color:var(--del-blue);border-bottom-color:var(--del-blue)}
.del-panel{display:none;padding:20px 0}
.del-panel.active{display:block}
.del-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px}
.del-toolbar select,.del-toolbar input{padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;background:#fff;font-family:inherit}
.del-empty{text-align:center;padding:35px;color:var(--del-gray)}
.del-empty i{font-size:2.2rem;display:block;margin-bottom:10px;color:#cbd5e1}
.del-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10000;align-items:center;justify-content:center}
.del-modal-overlay.show{display:flex}
.del-modal{background:#fff;border-radius:16px;padding:24px;max-width:640px;width:95%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.del-modal h3{font-size:1.1rem;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.del-form-group{margin-bottom:14px}
.del-form-group label{display:block;font-size:.8rem;font-weight:600;color:#475569;margin-bottom:4px}
.del-form-group input,.del-form-group select,.del-form-group textarea{width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;font-family:inherit}
.del-form-group textarea{resize:vertical;min-height:60px}
.del-driver-card{background:var(--del-card);border-radius:12px;border:1px solid var(--del-border);padding:16px;display:flex;align-items:center;gap:14px;transition:all .15s}
.del-driver-card:hover{box-shadow:0 2px 8px rgba(0,0,0,.08)}
.del-avatar{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;font-weight:700;color:#fff;flex-shrink:0}
.del-progress-wrap{width:100%;background:#e2e8f0;border-radius:6px;height:8px;overflow:hidden}
.del-progress-bar{height:100%;border-radius:6px;transition:width .3s}
.del-notif{display:flex;align-items:flex-start;gap:10px;padding:10px 14px;border-radius:10px;margin-bottom:8px;font-size:.82rem}
.del-notif i{margin-top:2px;font-size:.85rem;flex-shrink:0}
.del-notif-warning{background:#fffbeb;border:1px solid #fde68a;color:#92400e}
.del-notif-critical{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.del-notif-info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}
.del-notif-success{background:#dcfce7;border:1px solid #bbf7d0;color:#166534}
.del-quick-actions{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px}
.del-qa{display:flex;align-items:center;gap:8px;padding:10px 16px;background:var(--del-card);border:1px solid var(--del-border);border-radius:12px;font-size:.82rem;font-weight:600;color:#334155;cursor:pointer;text-decoration:none;transition:all .15s}
.del-qa:hover{border-color:var(--del-blue);color:var(--del-blue);transform:translateY(-1px);box-shadow:0 2px 8px rgba(37,99,235,.15)}
.del-qa i{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.85rem}
@media(max-width:1200px){.del-grid-2,.del-grid-3{grid-template-columns:1fr}}
@media(max-width:768px){.del-stats{grid-template-columns:repeat(2,1fr)}.del-table{font-size:.78rem}.del-table th,.del-table td{padding:8px 6px}}
@media(max-width:480px){.del-stats{grid-template-columns:1fr}.del-wrap{padding:0 0 24px}.del-card{padding:14px}.del-toolbar{flex-direction:column;align-items:stretch}.del-toolbar select,.del-toolbar input{width:100%}.del-btn-sm{min-height:36px;padding:8px 12px;font-size:.78rem}.del-quick-actions{gap:6px}.del-qa{padding:8px 12px;font-size:.78rem}}
@media(max-width:380px){.del-stats{gap:8px}.del-stat{padding:12px 10px;gap:8px}.del-stat-icon{width:36px;height:36px;font-size:.9rem}.del-stat-val{font-size:1rem}.del-card{padding:12px}.del-driver-card{padding:12px;gap:10px}.del-avatar{width:38px;height:38px;font-size:.85rem}}
</style>
CSS;
}

function delJs(): string {
    return <<<'JS'
<script>
function delSwitchTab(btn,id){
    var p=btn.closest('.del-tabs');
    if(p){p.querySelectorAll('.del-tab').forEach(function(t){t.classList.remove('active')})}
    var wrap=btn.closest('.del-wrap')||document;
    wrap.querySelectorAll('.del-panel').forEach(function(x){x.classList.remove('active')});
    btn.classList.add('active');
    var el=document.getElementById(id);
    if(el)el.classList.add('active');
}
function delAnimatedCounters(){
    document.querySelectorAll('[data-counter]').forEach(function(el){
        var target=parseFloat(el.getAttribute('data-counter'));
        var prefix=el.getAttribute('data-prefix')||'';
        var suffix=el.getAttribute('data-suffix')||'';
        var decimals=el.getAttribute('data-decimals')||'0';
        var start=0;
        var duration=800;
        var startTime=null;
        function step(ts){
            if(!startTime)startTime=ts;
            var progress=Math.min((ts-startTime)/duration,1);
            var eased=1-Math.pow(1-progress,3);
            var current=start+eased*(target-start);
            el.textContent=prefix+current.toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g,',')+suffix;
            if(progress<1)requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    });
}
function delOpenModal(id){var m=document.getElementById(id);if(m)m.classList.add('show')}
function delCloseModal(id){var m=document.getElementById(id);if(m)m.classList.remove('show')}
function delCloseAllModals(){document.querySelectorAll('.del-modal-overlay').forEach(function(m){m.classList.remove('show')})}
document.addEventListener('DOMContentLoaded',function(){
    delAnimatedCounters();
    document.querySelectorAll('.del-modal-overlay').forEach(function(m){
        m.addEventListener('click',function(e){if(e.target===m)delCloseAllModals()});
    });
});
</script>
JS;
}
