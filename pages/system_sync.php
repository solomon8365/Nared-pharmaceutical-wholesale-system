<?php
requirePermission('system_sync', 'view');
require_once __DIR__ . '/../includes/sync_helpers.php';
$pdo = getPDO();

// ── AJAX handlers ────────────────────────────────────────────────────────
// header.php is rendered before this page is included, so its HTML is already
// in the output buffer. For clean JSON responses we discard that buffer first.
function sicBufferCleanStart(): void {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
}

// ── AJAX: Run System Integrity Check ─────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'run') {
    sicBufferCleanStart();
    if (!isset($_GET['csrf']) || !hash_equals(csrf_token(), $_GET['csrf'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }
    try {
        $result = runIntegrityChecks($pdo);
        saveIntegrityCheckResult($pdo, $result);
        echo json_encode(['success' => true, 'result' => $result]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: View module details (read-only diagnostic) ────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'details') {
    sicBufferCleanStart();
    if (!isset($_GET['csrf']) || !hash_equals(csrf_token(), $_GET['csrf'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }
    $module = preg_replace('/[^a-z0-9_]/', '', $_GET['module'] ?? '');
    $payload = ['success' => true, 'module' => $module, 'data' => []];

    switch ($module) {
        case 'orders_invoices':
            $payload['data'] = $pdo->query(
                "SELECT co.id AS order_id, co.status, co.total_amount,
                        COALESCE(i.id, '—') AS invoice_id
                 FROM customer_orders co
                 LEFT JOIN invoices i ON i.customer_order_id = co.id
                 WHERE co.status NOT IN ('cancelled','draft')
                   AND i.id IS NULL
                 ORDER BY co.id DESC LIMIT 20"
            )->fetchAll();
            break;
        case 'products_stock':
            $payload['data'] = $pdo->query(
                "SELECT id, name, sku, barcode, product_status, stock_quantity
                 FROM products
                 WHERE ((sku IS NULL OR sku = '') OR (barcode IS NULL OR barcode = ''))
                   AND product_status = 'active'
                 ORDER BY name ASC LIMIT 20"
            )->fetchAll();
            break;
        case 'inventory_warehouses':
            $payload['data'] = $pdo->query(
                "SELECT p.id AS product_id, p.name, p.stock_quantity,
                        b.batch_qty
                 FROM products p
                 JOIN (SELECT product_id, SUM(quantity) AS batch_qty
                       FROM product_batches WHERE status = 'active'
                       GROUP BY product_id) b ON b.product_id = p.id
                 WHERE ABS(p.stock_quantity - b.batch_qty) > 0.5
                 ORDER BY p.name ASC LIMIT 20"
            )->fetchAll();
            break;
        case 'orders_inventory':
            $payload['data'] = $pdo->query(
                "SELECT id, name, stock_quantity FROM products
                 WHERE stock_quantity < 0 ORDER BY name ASC LIMIT 20"
            )->fetchAll();
            break;
        case 'payments_finance':
            $payload['data'] = $pdo->query(
                "SELECT id AS invoice_id, total_amount, amount_paid, outstanding_balance
                 FROM invoices WHERE amount_paid > total_amount + 0.01
                 ORDER BY id DESC LIMIT 20"
            )->fetchAll();
            break;
        case 'deliveries_orders':
            $payload['data'] = $pdo->query(
                "SELECT id AS order_id, status, delivery_status FROM customer_orders
                 WHERE delivery_status IS NOT NULL
                   AND status IN ('pending','draft','cancelled')
                 ORDER BY id DESC LIMIT 20"
            )->fetchAll();
            break;
        case 'workflows':
            $payload['data'] = $pdo->query(
                "SELECT id AS order_id, status FROM customer_orders co
                 WHERE NOT EXISTS (SELECT 1 FROM status_history sh
                                   WHERE sh.workflow = 'order' AND sh.entity_id = co.id)
                 ORDER BY id DESC LIMIT 20"
            )->fetchAll();
            break;
        case 'credit_profiles':
            $payload['data'] = $pdo->query(
                "SELECT c.id AS customer_id, c.name, c.credit_used
                 FROM customers c WHERE c.credit_limit > 0
                 ORDER BY c.name ASC LIMIT 20"
            )->fetchAll();
            break;
        case 'finance_balances':
            $payload['data'] = $pdo->query(
                "SELECT 'Bank' AS account_type, id AS account_id, account_name AS account, bank_name,
                        opening_balance, balance
                 FROM bank_accounts
                 UNION ALL
                 SELECT 'Cash', id, account_name, '', opening_balance, balance
                 FROM cash_accounts
                 ORDER BY account_type, account LIMIT 20"
            )->fetchAll();
            break;
        case 'customers':
            $payload['data'] = $pdo->query(
                "SELECT id, name, customer_code FROM customers
                 WHERE customer_code IS NULL OR customer_code = ''
                 ORDER BY name ASC LIMIT 20"
            )->fetchAll();
            break;
        case 'suppliers':
            $payload['data'] = $pdo->query(
                "SELECT id, name, supplier_code FROM suppliers
                 WHERE supplier_code IS NULL OR supplier_code = ''
                 ORDER BY name ASC LIMIT 20"
            )->fetchAll();
            break;
        default:
            $payload['data'] = [];
    }
    echo json_encode($payload);
    exit;
}

// ── Normal page render ───────────────────────────────────────────────────
$now = date('Y-m-d H:i:s');
$summary = syncGetSummary($pdo);
$lastCheck = getLastIntegrityCheck($pdo);
$history = getIntegrityHistory($pdo, 12);

// Current status snapshot
$status = 'healthy';
$lastCheckedLabel = 'Never';
$lastSuccessfulLabel = 'Never';
$issuesFound = 0;
if ($lastCheck) {
    $status = $lastCheck['status'];
    $lastCheckedLabel = date('M j, Y g:i A', strtotime($lastCheck['checked_at']));
    $issuesFound = (int) $lastCheck['issues_found'];
    // Find the most recent fully-healthy check for "Last Successful Check"
    $allHistory = getIntegrityHistory($pdo, 50);
    foreach ($allHistory as $h) {
        if ((string) $h['status'] === 'healthy' && (int) $h['issues_found'] === 0) {
            $lastSuccessfulLabel = date('M j, Y g:i A', strtotime($h['checked_at']));
            break;
        }
    }
}

$statusMeta = [
    'healthy' => ['icon' => 'fa-circle-check', 'label' => 'Healthy', 'color' => 'var(--green-600)'],
    'warning' => ['icon' => 'fa-triangle-exclamation', 'label' => 'Warning', 'color' => 'var(--amber-600)'],
    'error'   => ['icon' => 'fa-circle-xmark', 'label' => 'Error', 'color' => 'var(--red-600)'],
];
$sm = $statusMeta[$status] ?? $statusMeta['healthy'];

$modules = [
    ['key' => 'products_stock',      'label' => 'Products',        'icon' => 'fa-capsules',        'cat' => 'Product'],
    ['key' => 'customers',           'label' => 'Customers',       'icon' => 'fa-users',           'cat' => 'Customers'],
    ['key' => 'suppliers',           'label' => 'Suppliers',       'icon' => 'fa-truck',           'cat' => 'Procurement'],
    ['key' => 'orders_inventory',    'label' => 'Orders',          'icon' => 'fa-cart-shopping',   'cat' => 'Orders'],
    ['key' => 'inventory_warehouses','label' => 'Inventory',       'icon' => 'fa-boxes-stacked',   'cat' => 'Inventory'],
    ['key' => 'inventory_warehouses','label' => 'Warehouses',      'icon' => 'fa-warehouse',       'cat' => 'Inventory'],
    ['key' => 'orders_invoices',     'label' => 'Invoices',        'icon' => 'fa-file-invoice',    'cat' => 'Finance'],
    ['key' => 'payments_finance',    'label' => 'Payments',        'icon' => 'fa-money-bill-wave', 'cat' => 'Finance'],
    ['key' => 'deliveries_orders',   'label' => 'Deliveries',      'icon' => 'fa-truck-fast',      'cat' => 'Delivery'],
    ['key' => 'credit_profiles',     'label' => 'Credit Profiles', 'icon' => 'fa-credit-card',     'cat' => 'Credit'],
    ['key' => 'finance_balances',    'label' => 'Finance Balances','icon' => 'fa-scale-balanced', 'cat' => 'Finance'],
    ['key' => 'workflows',           'label' => 'Workflows',       'icon' => 'fa-diagram-project', 'cat' => 'Workflow'],
];

$consistencyPairs = [
    ['key' => 'orders_inventory',    'label' => 'Orders \u2194 Inventory'],
    ['key' => 'inventory_warehouses','label' => 'Inventory \u2194 Warehouses'],
    ['key' => 'orders_invoices',     'label' => 'Orders \u2194 Invoices'],
    ['key' => 'payments_finance',    'label' => 'Payments \u2194 Finance'],
    ['key' => 'deliveries_orders',   'label' => 'Deliveries \u2194 Orders'],
    ['key' => 'products_stock',      'label' => 'Products \u2194 Stock'],
];

// Build a lookup of check statuses from the last run
$checkStatusMap = [];
if ($lastCheck && !empty($lastCheck['result_json']['checks'])) {
    foreach ($lastCheck['result_json']['checks'] as $c) {
        $checkStatusMap[$c['key']] = $c;
    }
}
?>
<section style="max-width:1200px">
    <style>
        .sic-chip{display:inline-flex;align-items:center;gap:6px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:4px 10px;border-radius:999px;background:var(--neutral-100);color:var(--neutral-600);border:1px solid var(--neutral-300)}
        .sic-chip.ok{background:var(--green-50);color:var(--green-700);border-color:var(--green-200)}
        .sic-chip.warn{background:var(--amber-50);color:var(--amber-700);border-color:var(--amber-200)}
        .sic-chip.err{background:var(--red-50);color:var(--red-700);border-color:var(--red-200)}
        .sic-statusbar{display:flex;flex-wrap:wrap;gap:10px;align-items:stretch;margin-bottom:20px}
        .sic-stat{flex:0 1 auto;min-width:150px;background:var(--white);border:1px solid var(--neutral-300);border-radius:var(--radius-lg);padding:16px 18px;box-shadow:var(--shadow-sm)}
        .sic-stat .k{font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--neutral-500);margin-bottom:8px}
        .sic-stat .v{font-size:1.15rem;font-weight:800;color:var(--neutral-900);display:flex;align-items:center;gap:8px}
        .sic-stat .v i{font-size:1.3rem}
        .sic-actions{background:linear-gradient(135deg,var(--primary-800),var(--primary-900) 70%,var(--primary-950));border-radius:var(--radius-xl);padding:24px;margin-bottom:24px;color:#fff;display:flex;flex-wrap:wrap;gap:16px;align-items:center;justify-content:space-between;box-shadow:var(--shadow-md)}
        .sic-actions h2{margin:0 0 6px;color:#fff;font-size:1.2rem;border-left:none}
        .sic-actions p{margin:0;opacity:.85;font-size:.85rem;max-width:560px}
        .sic-actions .note{margin-top:10px;font-size:.75rem;opacity:.7;display:flex;align-items:center;gap:6px}
        .sic-runbtn{display:inline-flex;align-items:center;gap:10px;background:#fff;color:var(--primary-900);font-weight:800;font-size:.9rem;padding:12px 22px;border:none;border-radius:var(--radius-md);cursor:pointer;box-shadow:var(--shadow-md);transition:transform .15s,box-shadow .15s;white-space:nowrap}
        .sic-runbtn:hover{transform:translateY(-1px);box-shadow:var(--shadow-lg)}
        .sic-runbtn:disabled{opacity:.7;cursor:progress}
        .sic-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px;margin-bottom:8px}
        .sic-module{background:var(--white);border:1px solid var(--neutral-300);border-radius:var(--radius-lg);padding:16px;box-shadow:var(--shadow-sm);display:flex;flex-direction:column;gap:10px;transition:transform .15s,box-shadow .15s}
        .sic-module:hover{transform:translateY(-2px);box-shadow:var(--shadow-md)}
        .sic-module .m-head{display:flex;align-items:center;gap:10px}
        .sic-module .m-icon{width:38px;height:38px;border-radius:10px;background:var(--primary-50);color:var(--primary-700);display:flex;align-items:center;justify-content:center;font-size:1rem;flex:0 0 auto}
        .sic-module .m-title{font-weight:700;color:var(--neutral-900);font-size:.92rem;line-height:1.2}
        .sic-module .m-cat{font-size:.7rem;color:var(--neutral-500);font-weight:600;text-transform:uppercase;letter-spacing:.04em}
        .sic-module .m-status{display:flex;align-items:center;gap:6px;margin-top:auto}
        .sic-consistency{background:var(--white);border:1px solid var(--neutral-300);border-radius:var(--radius-lg);padding:18px;box-shadow:var(--shadow-sm);margin-bottom:24px}
        .sic-consistency h3{margin:0 0 14px;font-size:1rem;color:var(--neutral-800);display:flex;align-items:center;gap:8px}
        .sic-pairs{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px}
        .sic-pair{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 14px;border:1px solid var(--neutral-300);border-radius:var(--radius-md);font-size:.85rem;font-weight:600;color:var(--neutral-700)}
        .sic-pair .ok{color:var(--green-600);display:inline-flex;align-items:center;gap:6px}
        .sic-pair .err{color:var(--red-600);display:inline-flex;align-items:center;gap:6px}
        .sic-pair .warn{color:var(--amber-600);display:inline-flex;align-items:center;gap:6px}
        .sic-history{background:var(--white);border:1px solid var(--neutral-300);border-radius:var(--radius-lg);padding:18px;box-shadow:var(--shadow-sm);margin-bottom:24px}
        .sic-history h3{margin:0 0 14px;font-size:1rem;color:var(--neutral-800);display:flex;align-items:center;gap:8px}
        .sic-hrow{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 4px;border-bottom:1px solid var(--neutral-200);font-size:.85rem}
        .sic-hrow:last-child{border-bottom:none}
        .sic-hrow .time{color:var(--neutral-500);font-size:.78rem;white-space:nowrap}
        .sic-loader{display:none;align-items:center;gap:10px;color:#fff;font-weight:600;font-size:.9rem}
        .sic-spinner{width:20px;height:20px;border:3px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:sic-spin .7s linear infinite}
        @keyframes sic-spin{to{transform:rotate(360deg)}}
        .sic-alert{display:none;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--radius-lg);margin-bottom:20px;font-size:.9rem;font-weight:600}
        .sic-alert.ok{background:var(--green-50);color:var(--green-700);border:1px solid var(--green-200);display:flex}
        .sic-alert.warn{background:var(--amber-50);color:var(--amber-700);border:1px solid var(--amber-200);display:flex}
        .sic-alert.err{background:var(--red-50);color:var(--red-700);border:1px solid var(--red-200);display:flex}
        @media (max-width:768px){
            .sic-statusbar{flex-direction:column}
            .sic-stat{min-width:0;width:100%}
            .sic-actions{flex-direction:column;align-items:stretch;text-align:center}
            .sic-actions .note{justify-content:center}
            .sic-runbtn{justify-content:center}
            .sic-pairs{grid-template-columns:1fr}
            .sic-hrow{flex-wrap:wrap}
        }
        @media (max-width:480px){
            .sic-actions{padding:18px}
            .sic-module{padding:14px}
        }
    </style>

    <h1 style="margin-bottom:4px"><i class="fas fa-shield-halved" style="color:var(--brown-600);margin-right:8px"></i> System Data Integrity</h1>
    <p style="color:var(--neutral-600);margin-bottom:20px;font-size:.9rem">
        Data synchronization happens automatically as part of the normal system workflow.
        This page is an administrative tool for <strong>monitoring data consistency</strong> and running an
        integrity check when needed.
    </p>

    <div id="sic-alert" class="sic-alert"></div>

    <div class="sic-statusbar">
        <div class="sic-stat">
            <div class="k">System Status</div>
            <div class="v"><i style="color:<?= $sm['color'] ?>"></i><span id="sic-status-label"><?= htmlspecialchars($sm['label']) ?></span></div>
        </div>
        <div class="sic-stat">
            <div class="k">Last Integrity Check</div>
            <div class="v" style="font-size:.95rem"><i class="fas fa-clock" style="color:var(--neutral-500)"></i><span id="sic-last-checked"><?= htmlspecialchars($lastCheckedLabel) ?></span></div>
        </div>
        <div class="sic-stat">
            <div class="k">Modules Checked</div>
            <div class="v"><i class="fas fa-layer-group" style="color:var(--primary-600)"></i><span id="sic-modules"><?= (int) ($lastCheck['modules_checked'] ?? 12) ?></span></div>
        </div>
        <div class="sic-stat">
            <div class="k">Issues Found</div>
            <div class="v"><i class="fas fa-circle-exclamation" style="color:<?= $issuesFound > 0 ? 'var(--red-600)' : 'var(--green-600)' ?>"></i><span id="sic-issues"><?= (int) $issuesFound ?></span></div>
        </div>
        <div class="sic-stat">
            <div class="k">Last Successful Check</div>
            <div class="v" style="font-size:.95rem"><i class="fas fa-check" style="color:var(--green-600)"></i><span id="sic-last-success"><?= htmlspecialchars($lastSuccessfulLabel) ?></span></div>
        </div>
    </div>

    <div class="sic-actions">
        <div>
            <h2><i class="fas fa-magnifying-glass-chart" style="margin-right:8px"></i>Run System Integrity Check</h2>
            <p>Verify consistency across all system modules. This is a maintenance tool — it does not affect live data and never duplicates or recreates records.</p>
            <div class="note"><i class="fas fa-info-circle"></i> Synchronization is automatic during normal operations.</div>
        </div>
        <button type="button" class="sic-runbtn" id="sic-run-btn" onclick="runIntegrityCheck()">
            <i class="fas fa-play"></i><span id="sic-run-label">Run System Integrity Check</span>
        </button>
        <div class="sic-loader" id="sic-loader"><span class="sic-spinner"></span><span>Checking all modules...</span></div>
    </div>

    <div class="panel" style="box-shadow:var(--shadow-sm);border-radius:var(--radius-lg)">
        <h2 style="margin-bottom:14px">Modules</h2>
        <div class="sic-grid">
            <?php foreach ($modules as $m): ?>
                <?php $c = $checkStatusMap[$m['key']] ?? null; ?>
                <?php $mStatus = $c ? $c['status'] : 'pending'; ?>
                <?php $mIcon = $mStatus === 'synced' ? 'fa-circle-check' : ($mStatus === 'warning' ? 'fa-triangle-exclamation' : ($mStatus === 'error' ? 'fa-circle-xmark' : 'fa-circle-question')); ?>
                <?php $mColor = $mStatus === 'synced' ? 'var(--green-600)' : ($mStatus === 'warning' ? 'var(--amber-600)' : ($mStatus === 'error' ? 'var(--red-600)' : 'var(--neutral-400)')); ?>
                <?php $mLabel = $mStatus === 'synced' ? 'Synced' : ($mStatus === 'warning' ? 'Warning' : ($mStatus === 'error' ? 'Error' : 'Pending')); ?>
                <?php $mTime = $c ? (date('M j, g:i A', strtotime($lastCheck['checked_at']))) : '—'; ?>
                <div class="sic-module" data-module="<?= htmlspecialchars($m['key']) ?>">
                    <div class="m-head">
                        <div class="m-icon"><i class="fas <?= $m['icon'] ?>"></i></div>
                        <div>
                            <div class="m-title"><?= htmlspecialchars($m['label']) ?></div>
                            <div class="m-cat"><?= htmlspecialchars($m['cat']) ?></div>
                        </div>
                    </div>
                    <div class="m-status">
                        <i class="fas <?= $mIcon ?>" style="color:<?= $mColor ?>"></i>
                        <span style="color:<?= $mColor ?>;font-weight:700;font-size:.82rem"><?= htmlspecialchars($mLabel) ?></span>
                        <span style="margin-left:auto;font-size:.72rem;color:var(--neutral-500)"><?= htmlspecialchars($mTime) ?></span>
                    </div>
                    <button type="button" class="sic-chip" style="cursor:pointer;background:transparent;border:1px solid var(--neutral-300)" onclick="viewModule('<?= htmlspecialchars($m['key']) ?>')">
                        <i class="fas fa-eye" style="font-size:.68rem"></i> View Details
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="sic-consistency">
        <h3><i class="fas fa-link" style="color:var(--primary-600)"></i> Data Consistency</h3>
        <div class="sic-pairs">
            <?php foreach ($consistencyPairs as $pair): ?>
                <?php $c = $checkStatusMap[$pair['key']] ?? null; ?>
                <?php $pState = $c ? ($c['status'] === 'synced' ? 'ok' : ($c['status'] === 'warning' ? 'warn' : 'err')) : 'warn'; ?>
                <?php $pLabel = $c ? ($c['status'] === 'synced' ? 'Consistent' : 'Attention') : 'Pending'; ?>
                <?php $pIcon = $c ? ($c['status'] === 'synced' ? 'fa-circle-check' : ($c['status'] === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-xmark')) : 'fa-circle-question'; ?>
                <div class="sic-pair">
                    <span><?= htmlspecialchars($pair['label']) ?></span>
                    <span class="<?= $pState ?>"><i class="fas <?= $pIcon ?>"></i> <?= htmlspecialchars($pLabel) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="sic-history">
        <h3><i class="fas fa-clock-rotate-left" style="color:var(--primary-600)"></i> Recent Integrity Activity</h3>
        <?php if (empty($history)): ?>
            <p style="color:var(--neutral-500);font-size:.85rem;margin:0">No integrity checks have been run yet. Click "Run System Integrity Check" to begin.</p>
        <?php else: ?>
            <?php foreach ($history as $h): ?>
                <?php $hStatus = $h['status']; ?>
                <?php $hIcon = $hStatus === 'healthy' ? 'fa-circle-check' : ($hStatus === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-xmark'); ?>
                <?php $hColor = $hStatus === 'healthy' ? 'var(--green-600)' : ($hStatus === 'warning' ? 'var(--amber-600)' : 'var(--red-600)'); ?>
                <?php $hIssues = (int) $h['issues_found']; ?>
                <div class="sic-hrow">
                    <span style="display:flex;align-items:center;gap:8px"><i class="fas <?= $hIcon ?>" style="color:<?= $hColor ?>"></i><?= $hStatus === 'healthy' ? 'System integrity check completed' : ucfirst($hStatus) . ' — integrity check found ' . $hIssues . ' issue(s)' ?> — <?= (int) $h['modules_checked'] ?> modules</span>
                    <span class="time"><i class="fas fa-clock" style="margin-right:4px"></i><?= date('M j, Y g:i A', strtotime($h['checked_at'])) ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Module details modal -->
    <div id="sic-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:1000;align-items:center;justify-content:center;padding:16px">
        <div style="background:var(--white);border-radius:var(--radius-lg);max-width:720px;width:100%;max-height:80vh;overflow:auto;box-shadow:var(--shadow-xl)">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--neutral-200)">
                <h3 style="margin:0;font-size:1.05rem" id="sic-modal-title">Module Details</h3>
                <button type="button" onclick="closeModal()" style="background:var(--neutral-100);border:none;width:34px;height:34px;border-radius:8px;cursor:pointer;color:var(--neutral-700)"><i class="fas fa-xmark"></i></button>
            </div>
            <div style="padding:20px 22px" id="sic-modal-body">
                <p style="color:var(--neutral-500);font-size:.85rem">Loading details...</p>
            </div>
        </div>
    </div>

    <script>
    var sicCsrf = '<?= csrf_token() ?>';
    var sicModuleLabels = {
        'orders_invoices':'Orders \u2194 Invoices','products_stock':'Products \u2194 Stock',
        'inventory_warehouses':'Inventory \u2194 Warehouses','orders_inventory':'Orders \u2194 Inventory',
        'payments_finance':'Payments \u2194 Finance','deliveries_orders':'Deliveries \u2194 Orders',
        'workflows':'Workflows','credit_profiles':'Credit Profiles','finance_balances':'Finance Balances',
        'customers':'Customers','suppliers':'Suppliers'
    };
    var sicTrans = {
        'order_id':'Order #','invoice_id':'Invoice #','product_id':'Product #','customer_id':'Customer #',
        'name':'Name','status':'Status','total_amount':'Total','amount_paid':'Paid','outstanding_balance':'Outstanding',
        'sku':'SKU','barcode':'Barcode','product_status':'Status','stock_quantity':'Stock','batch_qty':'Batch Qty',
        'delivery_status':'Delivery','credit_used':'Credit Used','customer_code':'Code','supplier_code':'Code',
        'account_type':'Type','account_id':'Account #','opening_balance':'Opening','balance':'Balance',
        'account':'Account','bank_name':'Bank',
        'id':'ID','order':'Order'
    };

    function runIntegrityCheck() {
        var btn = document.getElementById('sic-run-btn');
        var loader = document.getElementById('sic-loader');
        var label = document.getElementById('sic-run-label');
        var alertEl = document.getElementById('sic-alert');
        btn.style.display = 'none';
        loader.style.display = 'flex';
        label.textContent = 'Checking...';
        alertEl.className = 'sic-alert';
        alertEl.innerHTML = '';

        $.getJSON('?page=system_sync&ajax=run&csrf=' + encodeURIComponent(sicCsrf))
            .done(function(res) {
                if (!res.success) {
                    showAlert('error', 'Error', res.message || 'Check failed.');
                    return;
                }
                renderResult(res.result);
                showAlert(res.result.system_status, res.result.system_status === 'healthy'
                    ? 'System check completed successfully'
                    : res.result.system_status === 'warning' ? 'System check completed with warnings' : 'System check found errors',
                    res.result.modules_checked + ' modules checked · ' + res.result.issues_found + ' inconsistencies found');
            })
            .fail(function(xhr) {
                var msg = 'Unable to complete the integrity check.';
                try { msg = JSON.parse(xhr.responseText).message || msg; } catch(e){}
                showAlert('error', 'Check Failed', msg);
            })
            .always(function() {
                btn.style.display = '';
                loader.style.display = 'none';
            });
    }

    function renderResult(result) {
        $('#sic-status-label').text(result.system_status.charAt(0).toUpperCase() + result.system_status.slice(1));
        $('#sic-last-checked').text(formatTime(result.checked_at));
        $('#sic-modules').text(result.modules_checked);
        $('#sic-issues').text(result.issues_found);
        if (result.system_status === 'healthy') {
            $('#sic-last-success').text(formatTime(result.checked_at));
        }
        $('.sic-module').each(function() {
            var key = $(this).data('module');
            var c = null;
            for (var i = 0; i < result.checks.length; i++) {
                if (result.checks[i].key === key) { c = result.checks[i]; break; }
            }
            if (!c) return;
            var s = c.status;
            var icon = s === 'synced' ? 'fa-circle-check' : (s === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-xmark');
            var color = s === 'synced' ? 'var(--green-600)' : (s === 'warning' ? 'var(--amber-600)' : 'var(--red-600)');
            var lbl = s === 'synced' ? 'Synced' : (s === 'warning' ? 'Warning' : 'Error');
            var time = formatTime(result.checked_at);
            $(this).find('.m-status').html('<i class="fas ' + icon + '" style="color:' + color + '"></i><span style="color:' + color + ';font-weight:700;font-size:.82rem">' + lbl + '</span><span style="margin-left:auto;font-size:.72rem;color:var(--neutral-500)">' + time + '</span>');
        });
        $('.sic-pair').each(function() {
            var key = $(this).find('span:first').text();
            // match by label via reverse lookup
            for (var k in sicModuleLabels) {
                if (sicModuleLabels[k] === key) {
                    var c = null;
                    for (var i = 0; i < result.checks.length; i++) {
                        if (result.checks[i].key === k) { c = result.checks[i]; break; }
                    }
                    if (!c) return;
                    var st = c.status;
                    var cl = st === 'synced' ? 'ok' : (st === 'warning' ? 'warn' : 'err');
                    var ic = st === 'synced' ? 'fa-circle-check' : (st === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-xmark');
                    var lb = st === 'synced' ? 'Consistent' : 'Attention';
                    $(this).find('span:last').attr('class', cl);
                    $(this).find('span:last').html('<i class="fas ' + ic + '"></i> ' + lb);
                    return;
                }
            }
        });
    }

    function showAlert(type, title, detail) {
        var icon = type === 'error' ? 'fa-circle-xmark' : (type === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-check');
        $('#sic-alert').attr('class', 'sic-alert ' + type);
        $('#sic-alert').html('<i class="fas ' + icon + '"></i><div><strong>' + title + '</strong><br>' + detail + '</div>');
        setTimeout(function(){ $('#sic-alert').removeClass().addClass('sic-alert'); $('#sic-alert').empty(); }, 8000);
    }

    function viewModule(key) {
        var title = sicModuleLabels[key] || key;
        $('#sic-modal-title').text(title);
        $('#sic-modal-body').html('<p style="color:var(--neutral-500);font-size:.85rem">Loading details...</p>');
        document.getElementById('sic-modal').style.display = 'flex';
        $.getJSON('?page=system_sync&ajax=details&module=' + encodeURIComponent(key) + '&csrf=' + encodeURIComponent(sicCsrf))
            .done(function(res) {
                if (!res.success || !res.data || res.data.length === 0) {
                    $('#sic-modal-body').html('<div style="text-align:center;padding:30px;color:var(--neutral-500)"><i class="fas fa-circle-check" style="font-size:2rem;color:var(--green-600);display:block;margin-bottom:10px"></i>This module is fully consistent. No records need attention.</div>');
                    return;
                }
                var html = '<div style="overflow-x:auto"><table style="min-width:480px">';
                var cols = Object.keys(res.data[0]);
                html += '<thead><tr>';
                cols.forEach(function(c){ html += '<th>' + (sicTrans[c] || c) + '</th>'; });
                html += '</tr></thead><tbody>';
                res.data.forEach(function(row){
                    html += '<tr>';
                    cols.forEach(function(c){
                        var v = row[c] == null ? '—' : row[c];
                        html += '<td>' + v + '</td>';
                    });
                    html += '</tr>';
                });
                html += '</tbody></table></div>';
                html += '<p style="margin-top:12px;font-size:.78rem;color:var(--neutral-500)">Showing up to 20 records. Use the system maintenance tools if corrections are needed.</p>';
                $('#sic-modal-body').html(html);
            })
            .fail(function(){
                $('#sic-modal-body').html('<p style="color:var(--red-600)">Unable to load module details.</p>');
            });
    }

    function closeModal() {
        document.getElementById('sic-modal').style.display = 'none';
    }
    document.getElementById('sic-modal').addEventListener('click', function(e){ if (e.target === this) closeModal(); });

    function formatTime(t) {
        if (!t) return '—';
        var d = new Date(t.replace(' ', 'T'));
        if (isNaN(d)) return t;
        var mon = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][d.getMonth()];
        var h = d.getHours();
        var ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        var m = String(d.getMinutes()).padStart(2, '0');
        return mon + ' ' + d.getDate() + ', ' + h + ':' + m + ' ' + ampm;
    }
    </script>
</section>
