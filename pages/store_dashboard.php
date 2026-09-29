<?php
require_once __DIR__ . '/../includes/finance_helpers.php';
require_once __DIR__ . '/../includes/workflow_engine.php';
require_once __DIR__ . '/../includes/auto_migrate.php';
require_once __DIR__ . '/../services/init.php';
requirePermission('store_dashboard', 'view');
$pdo = getPDO();
ensureInventoryWorkflowMigration($pdo);
ensureFulfillmentMigration($pdo);

$messages = [];

// Drain any flash messages left over from a previous POST (PRG pattern).
if (!empty($_SESSION['sd_flash'])) {
    foreach ($_SESSION['sd_flash'] as $f) {
        $messages[] = $f;
    }
    unset($_SESSION['sd_flash']);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf();
    $action = $_POST['_action'] ?? '';
    $uid = (int) ($_SESSION['user_id'] ?? 0);

    $flash = null;
    if (in_array($action, ['stock_check', 'reserve_stock', 'start_preparing', 'prepare_order', 'mark_prepared', 'pack'], true)) {
        requirePermission('order_fulfillment', 'edit');
        $oid = (int) ($_POST['order_id'] ?? 0);
        $res = match ($action) {
            'stock_check'     => FulfillmentService::stockCheck($pdo, $oid, $uid),
            'reserve_stock'   => FulfillmentService::reserveStock($pdo, $oid, $uid),
            'start_preparing' => FulfillmentService::startPreparing($pdo, $oid, $uid),
            'prepare_order'   => FulfillmentService::prepareOrder($pdo, $oid, $uid),
            'mark_prepared'   => FulfillmentService::markPrepared($pdo, $oid, $uid),
            'pack'            => FulfillmentService::pack($pdo, $oid, $uid),
            default           => ['success' => false, 'msg' => 'Unknown fulfillment action.'],
        };
        $flash = ['type' => $res['success'] ? 'success' : 'error', 'text' => $res['msg']];
    }
    if ($flash !== null) {
        $_SESSION['sd_flash'][] = $flash;
        safeRedirect('?page=store_dashboard');
    }
}

// ---- Inventory KPIs ------------------------------------------------------
$totalProducts    = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE product_status = 'active'")->fetchColumn();
$inventoryValue   = (float) $pdo->query("SELECT COALESCE(SUM(stock_quantity * COALESCE(NULLIF(purchase_price,0), NULLIF(wholesale_price,0), NULLIF(price,0))),0) FROM products WHERE product_status = 'active'")->fetchColumn();
$lowStockCount    = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE product_status = 'active' AND stock_quantity > 0 AND stock_quantity <= COALESCE(NULLIF(reorder_level,0),10)")->fetchColumn();
$outOfStockCount  = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE product_status = 'active' AND stock_quantity <= 0")->fetchColumn();
$expiringSoon     = (int) $pdo->query("SELECT COUNT(*) FROM product_batches WHERE quantity > 0 AND status = 'active' AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)")->fetchColumn();

// ---- Procurement KPIs ----------------------------------------------------
$pendingPOs       = (int) $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('pending','approved')")->fetchColumn();
$pendingReceipts  = (int) $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('payment_pending','payment_approved')")->fetchColumn();
$approvedPOs      = (int) $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'approved'")->fetchColumn();
$receivedToday    = (int) $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'received' AND DATE(received_at) = CURDATE()")->fetchColumn();

// ---- Stock movement KPIs -------------------------------------------------
$todayStockIn     = (int) $pdo->query("SELECT COALESCE(SUM(CASE WHEN quantity_change > 0 THEN quantity_change ELSE 0 END),0) FROM stock_movements WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$todayStockOut    = (int) $pdo->query("SELECT COALESCE(SUM(CASE WHEN quantity_change < 0 THEN -quantity_change ELSE 0 END),0) FROM stock_movements WHERE DATE(created_at) = CURDATE()")->fetchColumn();

// ---- Order processing pipeline (store manager scope only) ----------------
$fulfillmentCounts = $pdo->query("SELECT COALESCE(fulfillment_status, 'waiting_stock_check') AS st, COUNT(*) AS c FROM customer_orders WHERE status = 'approved' GROUP BY st")->fetchAll();
$fulf = ['waiting' => 0, 'ready' => 0, 'picking' => 0, 'packing' => 0, 'dispatch' => 0, 'oos' => 0];
foreach ($fulfillmentCounts as $fr) {
    $fkey = $fr['st'];
    if ($fkey === StatusCatalog::FULFILLMENT_WAITING_STOCK_CHECK) $fulf['waiting'] = (int) $fr['c'];
    elseif ($fkey === StatusCatalog::FULFILLMENT_READY_TO_PREPARE) $fulf['ready'] = (int) $fr['c'];
    elseif ($fkey === StatusCatalog::FULFILLMENT_RESERVED) $fulf['picking'] = (int) $fr['c'];
    elseif ($fkey === StatusCatalog::FULFILLMENT_PREPARING) $fulf['packing'] = (int) $fr['c'];
    elseif ($fkey === StatusCatalog::FULFILLMENT_PREPARED) $fulf['dispatch'] = (int) $fr['c'];
    elseif ($fkey === StatusCatalog::FULFILLMENT_OUT_OF_STOCK) $fulf['oos'] = (int) $fr['c'];
}

$allActiveFulfillment = FulfillmentService::activeOrders($pdo);

// Total ordered quantity + distinct product count per active order.
$orderIds = array_map(fn ($o) => (int) $o['id'], $allActiveFulfillment);
$lineInfo = [];
if (!empty($orderIds)) {
    $in = implode(',', array_fill(0, count($orderIds), '?'));
    $lineStmt = $pdo->prepare("SELECT customer_order_id AS oid, COUNT(*) AS product_count, COALESCE(SUM(quantity),0) AS total_qty FROM customer_order_items WHERE customer_order_id IN ($in) GROUP BY customer_order_id");
    $lineStmt->execute($orderIds);
    foreach ($lineStmt->fetchAll() as $r) {
        $lineInfo[(int) $r['oid']] = $r;
    }
}

// Orders still requiring a Store Manager action: Stock Check or Pack.
$storeActionScope = [
    StatusCatalog::FULFILLMENT_WAITING_STOCK_CHECK,
    StatusCatalog::FULFILLMENT_OUT_OF_STOCK,
    StatusCatalog::FULFILLMENT_READY_TO_PREPARE,
    StatusCatalog::FULFILLMENT_RESERVED,
    StatusCatalog::FULFILLMENT_PREPARING,
];
$fulfillmentAction = [];
$fulfillmentPacked = [];
foreach ($allActiveFulfillment as $o) {
    $o['_lines'] = $lineInfo[(int) $o['id']] ?? ['product_count' => 0, 'total_qty' => 0];
    $fst = $o['fulfillment_status'] ?: StatusCatalog::FULFILLMENT_WAITING_STOCK_CHECK;
    if (in_array($fst, $storeActionScope, true)) {
        $fulfillmentAction[] = $o;
    } else {
        $fulfillmentPacked[] = $o;
    }
}
$fulfillmentAction = array_slice($fulfillmentAction, 0, 15);
$fulfillmentPacked = array_slice($fulfillmentPacked, 0, 12);

// Lightweight status flags for the two-action store workflow.
$sdStockCheckLabel = [
    StatusCatalog::FULFILLMENT_WAITING_STOCK_CHECK => ['Pending Check', 'fin-badge-orange'],
    StatusCatalog::FULFILLMENT_OUT_OF_STOCK        => ['Short — re-check', 'fin-badge-red'],
    StatusCatalog::FULFILLMENT_READY_TO_PREPARE    => ['Verified', 'fin-badge-green'],
    StatusCatalog::FULFILLMENT_RESERVED            => ['Verified', 'fin-badge-green'],
    StatusCatalog::FULFILLMENT_PREPARING           => ['Verified', 'fin-badge-green'],
    StatusCatalog::FULFILLMENT_PREPARED            => ['Verified', 'fin-badge-green'],
];
$sdPackLabel = [
    StatusCatalog::FULFILLMENT_WAITING_STOCK_CHECK => ['Locked', 'fin-badge-gray'],
    StatusCatalog::FULFILLMENT_OUT_OF_STOCK        => ['Locked', 'fin-badge-gray'],
    StatusCatalog::FULFILLMENT_READY_TO_PREPARE    => ['Ready', 'fin-badge-teal'],
    StatusCatalog::FULFILLMENT_RESERVED            => ['In Progress', 'fin-badge-purple'],
    StatusCatalog::FULFILLMENT_PREPARING           => ['In Progress', 'fin-badge-purple'],
    StatusCatalog::FULFILLMENT_PREPARED            => ['Packed', 'fin-badge-green'],
];

// ---- Purchase order panel: open POs --------------------------------------
$openPOs = $pdo->prepare("
    SELECT po.id, po.order_date, po.status, po.total_amount, po.received_at,
           s.name AS supplier_name, u.full_name AS assigned_name
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN users u ON po.created_by = u.id
    WHERE po.status IN (:s1, :s2, :s3, :s4)
    ORDER BY po.order_date ASC, po.id ASC
    LIMIT 10
");
$openPOs->execute([
    's1' => StatusCatalog::PO_PENDING,
    's2' => StatusCatalog::PO_APPROVED,
    's3' => StatusCatalog::PO_PAYMENT_PENDING,
    's4' => StatusCatalog::PO_PAYMENT_APPROVED,
]);
$openPOs = $openPOs->fetchAll();

// ---- Low stock panel -------------------------------------------------------
$lowStock = $pdo->query("
    SELECT p.id, p.name, p.brand, p.stock_quantity,
           COALESCE(NULLIF(p.reorder_level,0),10) AS eff_reorder,
           s.name AS supplier_name
    FROM products p
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    WHERE p.product_status = 'active' AND p.stock_quantity > 0
      AND p.stock_quantity <= COALESCE(NULLIF(p.reorder_level,0),10)
    ORDER BY p.stock_quantity ASC
    LIMIT 10
")->fetchAll();

// ---- Expiry panel -----------------------------------------------------------
$expiredCount = (int) $pdo->query("SELECT COUNT(*) FROM product_batches WHERE quantity > 0 AND expiry_date < CURDATE()")->fetchColumn();
$exp30Count   = (int) $pdo->query("SELECT COUNT(*) FROM product_batches WHERE quantity > 0 AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
$exp60Count   = (int) $pdo->query("SELECT COUNT(*) FROM product_batches WHERE quantity > 0 AND expiry_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 31 DAY) AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)")->fetchColumn();
$expiryRows   = $pdo->query("
    SELECT pb.id, pb.batch_number, pb.expiry_date, pb.quantity, pb.status,
           p.name AS product_name
    FROM product_batches pb
    JOIN products p ON pb.product_id = p.id
    WHERE pb.quantity > 0 AND pb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
    ORDER BY pb.expiry_date ASC
    LIMIT 15
")->fetchAll();

// ---- Recent activities --------------------------------------------------------
$recentReceived = $pdo->query("
    SELECT sm.id, sm.quantity_change, sm.reference_id, sm.reason, sm.created_at,
           p.name AS product_name
    FROM stock_movements sm
    JOIN products p ON sm.product_id = p.id
    WHERE sm.reference_type = 'purchase_order' AND sm.quantity_change > 0
    ORDER BY sm.created_at DESC, sm.id DESC
    LIMIT 6
")->fetchAll();

$recentAdjustments = $pdo->query("
    SELECT sm.id, sm.quantity_change, sm.reason, sm.created_at, p.name AS product_name
    FROM stock_movements sm
    JOIN products p ON sm.product_id = p.id
    WHERE sm.reference_type = 'adjustment'
    ORDER BY sm.created_at DESC, sm.id DESC
    LIMIT 6
")->fetchAll();

$recentApprovedPOs = $pdo->query("
    SELECT sh.entity_id, sh.to_status, sh.notes, sh.created_at, u.full_name AS changed_name
    FROM status_history sh
    LEFT JOIN users u ON sh.changed_by = u.id
    WHERE sh.workflow IN ('purchase', 'purchase_order') AND sh.to_status = 'approved'
    ORDER BY sh.created_at DESC, sh.id DESC
    LIMIT 6
")->fetchAll();

$recentPrepared = $pdo->query("
    SELECT co.id, co.total_amount, co.prepared_at, c.name AS customer_name
    FROM customer_orders co
    LEFT JOIN customers c ON co.customer_id = c.id
    WHERE co.prepared_at IS NOT NULL
    ORDER BY co.prepared_at DESC, co.id DESC
    LIMIT 6
")->fetchAll();

// ---- Warehouse overview -----------------------------------------------------
$totalWarehouses   = (int) $pdo->query("SELECT COUNT(*) FROM warehouses WHERE warehouse_status = 'active'")->fetchColumn();
$totalInventoryQty = (int) $pdo->query("SELECT COALESCE(SUM(stock_quantity),0) FROM products WHERE product_status = 'active'")->fetchColumn();
$newOrdersCount        = (int) $pdo->query("SELECT COUNT(*) FROM customer_orders WHERE status = 'pending'")->fetchColumn();
$readyForDeliveryCount = (int) $pdo->query("SELECT COUNT(*) FROM customer_orders WHERE fulfillment_status = 'ready_for_delivery'")->fetchColumn();
$completedOrdersCount  = (int) $pdo->query("SELECT COUNT(*) FROM customer_orders WHERE delivery_status = 'delivered'")->fetchColumn();
$pendingTransfersCount = (int) $pdo->query("SELECT COUNT(*) FROM stock_transfers WHERE status IS NULL OR (status <> 'completed' AND status <> 'cancelled' AND status <> 'rejected')")->fetchColumn();

// ---- Warehouse stock distribution (per-warehouse batch totals) -----------------
$warehouseDist = $pdo->query("
    SELECT w.id, w.name, w.location,
           COUNT(DISTINCT ps.product_id) AS product_count,
           COALESCE(SUM(ps.qty), 0) AS total_qty,
           COALESCE(SUM(ps.qty * COALESCE(NULLIF(ps.purchase_price, 0),
               COALESCE(NULLIF(p.purchase_price, 0), COALESCE(NULLIF(p.wholesale_price, 0), p.price)))), 0) AS inv_value,
           COALESCE(SUM(ps.qty > 0 AND ps.qty <= COALESCE(NULLIF(p.reorder_level, 0), 10)), 0) AS low_count,
           COALESCE(SUM(ps.qty <= 0), 0) AS oos_count
    FROM warehouses w
    LEFT JOIN (
        SELECT pb.warehouse_id, pb.product_id,
               SUM(GREATEST(pb.quantity, 0)) AS qty,
               MAX(pb.purchase_price) AS purchase_price
        FROM product_batches pb
        WHERE pb.status = 'active'
        GROUP BY pb.warehouse_id, pb.product_id
    ) ps ON ps.warehouse_id = w.id
    LEFT JOIN products p ON p.id = ps.product_id
    WHERE w.warehouse_status = 'active'
    GROUP BY w.id, w.name, w.location
    ORDER BY w.name ASC
")->fetchAll();

// ---- Warehouse detail (click-through from distribution) ------------------------
$whDetailId = (int) ($_GET['warehouse'] ?? 0);
$whDetail = null;
$whProducts = [];
if ($whDetailId > 0) {
    $wStmt = $pdo->prepare("SELECT id, name, location FROM warehouses WHERE id = :id");
    $wStmt->execute(['id' => $whDetailId]);
    $whDetail = $wStmt->fetch();
    if ($whDetail) {
        $wpStmt = $pdo->prepare("
            SELECT p.id, p.name, p.brand, p.unit_of_measure, p.reorder_level,
                   SUM(GREATEST(pb.quantity, 0)) AS wh_qty,
                   COUNT(pb.id) AS batch_count,
                   MIN(CASE WHEN pb.quantity > 0 THEN pb.expiry_date END) AS next_expiry
            FROM product_batches pb
            JOIN products p ON p.id = pb.product_id
            WHERE pb.warehouse_id = :wid AND pb.status = 'active'
            GROUP BY p.id, p.name, p.brand, p.unit_of_measure, p.reorder_level
            ORDER BY wh_qty ASC, p.name ASC
        ");
        $wpStmt->execute(['wid' => $whDetailId]);
        $whProducts = $wpStmt->fetchAll();
    }
}

// ---- Recent stock movements (in / out / transfer / adjustment) ------------------
$recentMovements = $pdo->query("
    SELECT sm.id, sm.quantity_change, sm.reason, sm.reference_type, sm.created_at,
           p.name AS product_name,
           wf.name AS from_wh, wt.name AS to_wh, u.full_name AS user_name
    FROM stock_movements sm
    JOIN products p ON sm.product_id = p.id
    LEFT JOIN stock_transfers st ON sm.reference_type = 'stock_transfer' AND st.id = sm.reference_id
    LEFT JOIN warehouses wf ON wf.id = st.from_warehouse_id
    LEFT JOIN warehouses wt ON wt.id = st.to_warehouse_id
    LEFT JOIN users u ON u.id = st.created_by
    WHERE sm.reference_type IN ('purchase_order', 'sale', 'stock_transfer', 'adjustment')
    ORDER BY sm.created_at DESC, sm.id DESC
    LIMIT 12
")->fetchAll();

if (!function_exists('sdMoveCategory')) {
    function sdMoveCategory(string $refType, int $change): array
    {
        if ($refType === 'stock_transfer' || $refType === 'transfer') return ['Transfer', 'background:#eff6ff;color:#1d4ed8', 'fa-right-left'];
        if ($refType === 'adjustment') return ['Adjustment', 'background:#f5f3ff;color:#6d28d9', 'fa-sliders'];
        if ($change >= 0) return ['Stock In', 'background:#ecfdf5;color:#059669', 'fa-arrow-down'];
        return ['Stock Out', 'background:#fef2f2;color:#b91c1c', 'fa-arrow-up'];
    }
}

// ---- Stage presentation (store manager vocabulary, finance-free) -------------
$sdStageLabel = [
    StatusCatalog::FULFILLMENT_WAITING_STOCK_CHECK => 'Waiting Stock Verification',
    StatusCatalog::FULFILLMENT_READY_TO_PREPARE    => 'Ready to Pick',
    StatusCatalog::FULFILLMENT_RESERVED            => 'Picking In Progress',
    StatusCatalog::FULFILLMENT_PREPARING           => 'Packing',
    StatusCatalog::FULFILLMENT_PREPARED            => 'Ready for Dispatch',
    StatusCatalog::FULFILLMENT_OUT_OF_STOCK        => 'Out of Stock',
];
$sdStageTone = [
    StatusCatalog::FULFILLMENT_WAITING_STOCK_CHECK => 'fin-badge-orange',
    StatusCatalog::FULFILLMENT_READY_TO_PREPARE    => 'fin-badge-blue',
    StatusCatalog::FULFILLMENT_RESERVED            => 'fin-badge-purple',
    StatusCatalog::FULFILLMENT_PREPARING           => 'fin-badge-teal',
    StatusCatalog::FULFILLMENT_PREPARED            => 'fin-badge-green',
    StatusCatalog::FULFILLMENT_OUT_OF_STOCK        => 'fin-badge-red',
];
$sdToneClass = ['success' => 'fin-badge-green', 'warning' => 'fin-badge-orange', 'danger' => 'fin-badge-red', 'info' => 'fin-badge-blue', 'neutral' => 'fin-badge-gray'];
if (!function_exists('sdBadge')) {
    function sdBadge(string $workflow, ?string $status, array $toneClass): string
    {
        $tone = StatusCatalog::statusTone($workflow, $status);
        $cls = $toneClass[$tone] ?? 'fin-badge-gray';
        return '<span class="fin-badge ' . $cls . '">' . htmlspecialchars(StatusCatalog::statusLabel($workflow, $status)) . '</span>';
    }
}
?>
<?= finCss() ?>
<style>
.sd-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:600;white-space:nowrap}
.sd-move{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:10px;font-size:.72rem;font-weight:600}
.sd-move i{font-size:.68rem}
.sd-ok{background:#f1f5f9;color:#64748b}
.sd-sec{display:flex;align-items:center;gap:8px;margin:22px 0 10px}
.sd-sec::after{content:"";flex:1;height:1px;background:var(--fin-border)}
.sd-sec h2{font-size:.85rem;font-weight:800;margin:0;text-transform:uppercase;letter-spacing:.04em;color:#475569}
.sd-sec i{color:var(--fin-teal)}
.sd-wf-card{background:var(--fin-card);border:1px solid var(--fin-border);border-radius:12px;padding:16px;box-shadow:var(--fin-shadow);margin-bottom:16px}
.sd-wf-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;gap:8px}
.sd-wf-head h2{font-size:.95rem;margin:0}
.sd-wf-count{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:22px;padding:0 6px;border-radius:11px;font-size:.72rem;font-weight:800;background:#eef2ff;color:#4338ca}
.sd-btn{display:inline-flex;align-items:center;gap:4px;padding:8px 12px;min-height:40px;border-radius:8px;border:1px solid var(--fin-border);background:#fff;color:#1e3a8a;font-size:.72rem;font-weight:600;text-decoration:none;cursor:pointer;white-space:nowrap}
.sd-btn:hover{background:#eff6ff}
.sd-btn-green{border-color:#bbf7d0;background:#f0fdf4;color:#166534}
.sd-btn-green:hover{background:#dcfce7}
.sd-btn-red{border-color:#fecaca;background:#fef2f2;color:#991b1b}
.sd-btn-red:hover{background:#fee2e2}
.sd-hint{font-size:.72rem;color:var(--fin-gray);margin-top:8px}
.sd-panel-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;gap:8px}
.sd-panel-head h3{font-size:.95rem;margin:0}
.sd-mini-stat{flex:1;min-width:110px;background:#f8fafc;border:1px solid var(--fin-border);border-radius:10px;padding:10px 12px}
.sd-mini-stat-val{font-size:1.05rem;font-weight:800}
.sd-mini-stat-label{font-size:.7rem;color:var(--fin-gray);margin-top:2px}
.sd-flow{display:flex;align-items:stretch;gap:6px;flex-wrap:wrap}
.sd-flow-step{display:flex;align-items:center;gap:6px;padding:7px 11px;border-radius:9px;font-size:.74rem;font-weight:700;line-height:1.2}
.sd-flow-step i{font-size:.7rem}
.sd-flow-off{background:#f1f5f9;color:#64748b}
.sd-flow-off-blue{background:#eff6ff;color:#1d4ed8}
.sd-flow-off-purple{background:#f5f3ff;color:#6d28d9}
.sd-flow-off-orange{background:#fff7ed;color:#c2410c}
.sd-flow-on{background:var(--fin-teal);color:#fff}
.sd-flow-end{outline:2px solid var(--fin-orange);outline-offset:1px}
.sd-flow-arrow{display:flex;align-items:center;color:#cbd5e1;font-size:.7rem}
.sd-flow-gate{align-self:center;font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#b45309;background:#fffbeb;border:1px dashed #fcd34d;padding:4px 8px;border-radius:6px;white-space:nowrap}
.sd-act-item{display:flex;align-items:center;gap:10px;padding:8px 2px;border-bottom:1px dashed #eef2f7}
.sd-act-item:last-child{border-bottom:none}
.sd-act-ico{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.8rem;flex:none}
.sd-act-body{flex:1;min-width:0}
.sd-act-title{font-size:.8rem;font-weight:600;color:#1e293b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sd-act-sub{font-size:.72rem;color:#94a3b8}
.sd-act-time{font-size:.7rem;color:#94a3b8;white-space:nowrap}
.sd-exp-bucket{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:16px;font-size:.68rem;font-weight:700;white-space:nowrap}
.sd-alert-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px}
.sd-alert-tile{display:flex;align-items:center;gap:10px;padding:12px 14px;border:1px solid var(--fin-border);border-radius:10px;background:#fff;text-decoration:none;transition:background .15s,border-color .15s}
.sd-alert-tile:hover{background:#f8fafc;border-color:#cbd5e1}
.sd-alert-tile.is-hot{border-color:#fecaca;background:#fff7f7}
.sd-alert-tile.is-hot:hover{background:#fef2f2}
.sd-alert-ico{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex:none}
.sd-alert-body{min-width:0;flex:1}
.sd-alert-label{font-size:.76rem;font-weight:600;color:#334155;line-height:1.3}
.sd-alert-count{font-size:1.15rem;font-weight:800;color:#0f172a;line-height:1.1}
.sd-alert-go{color:#94a3b8;font-size:.75rem;flex:none}
@media(max-width:1200px){.fin-stats{grid-template-columns:repeat(3,1fr)}}
@media(max-width:768px){.fin-stats{grid-template-columns:repeat(2,1fr)}.fin-grid-2{grid-template-columns:1fr}}
@media(max-width:480px){.fin-stats{grid-template-columns:1fr}}

/* ================= Store Manager Dashboard — Enhanced Design ================= */
.sd-wrap{--sd-grad:linear-gradient(135deg,#0f766e 0%,#0d9488 55%,#14b8a6 100%);font-family:'Inter',system-ui,Segoe UI,Roboto,sans-serif}
/* Hero banner */
.sd-hero{position:relative;overflow:hidden;border-radius:20px;background:linear-gradient(120deg,#0f172a 0%,#134e4a 55%,#0f766e 100%);color:#fff;padding:26px 28px;margin-bottom:22px;box-shadow:0 12px 30px -12px rgba(15,118,110,.55);display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}
.sd-hero::before{content:"";position:absolute;top:-60px;right:-40px;width:220px;height:220px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.18),transparent 65%)}
.sd-hero::after{content:"";position:absolute;bottom:-80px;left:-30px;width:200px;height:200px;border-radius:50%;background:radial-gradient(circle,rgba(45,212,191,.28),transparent 65%)}
.sd-hero-icon{width:56px;height:56px;border-radius:16px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;font-size:1.5rem;flex-shrink:0;box-shadow:0 6px 18px rgba(0,0,0,.2)}
.sd-hero-text{flex:1;min-width:0;position:relative;z-index:1}
.sd-hero h1{font-size:1.55rem;font-weight:800;margin:0 0 4px;color:#fff;display:flex;align-items:center;gap:10px;letter-spacing:-.01em}
.sd-hero-sub{font-size:.84rem;color:#a7f3d0;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.sd-hero-meta{position:relative;z-index:1;text-align:right}
.sd-hero-date{display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.22);padding:9px 16px;border-radius:12px;font-size:.8rem;font-weight:600;color:#fff;backdrop-filter:blur(4px)}
.sd-role-chip{display:inline-flex;align-items:center;gap:6px;background:rgba(20,184,166,.25);border:1px solid rgba(45,212,191,.4);color:#ccfbf1;padding:3px 12px;border-radius:20px;font-size:.7rem;font-weight:700;letter-spacing:.03em}

/* Stat tiles */
.fin-stat{background:#fff;border-radius:16px;border:1px solid #e6edf5;padding:18px 16px;display:flex;align-items:flex-start;gap:13px;box-shadow:0 1px 2px rgba(15,23,42,.05);cursor:pointer;transition:all .22s cubic-bezier(.4,0,.2,1);position:relative;overflow:hidden}
.fin-stat::after{content:"";position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,rgba(255,255,255,0),rgba(37,99,235,.35),rgba(255,255,255,0))}
.fin-stat:hover{transform:translateY(-4px);box-shadow:0 14px 30px -12px rgba(15,23,42,.22);border-color:#d6e4f5}
.fin-stat-icon{width:50px;height:50px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.15rem;flex-shrink:0;color:#fff;position:relative;box-shadow:0 6px 14px -6px rgba(15,23,42,.4)}
.fin-stat-val{font-size:1.45rem;font-weight:800;color:#0f172a;line-height:1.1;letter-spacing:-.01em}
.fin-stat-label{font-size:.72rem;color:#64748b;margin-top:4px;font-weight:600;line-height:1.35}
.fin-stat .fin-stat-val[data-counter]{}
/* ===== Unified KPI grid refinements ===== */
.kpi-grid{grid-template-columns:repeat(auto-fit,minmax(205px,1fr));gap:14px}
.fin-stat.crit{border-left:4px solid var(--fin-orange)}
.fin-stat.crit-oos{border-left:4px solid var(--fin-red);background:linear-gradient(180deg,#fff,#fff7f7)}
.fin-stat.crit-oos .fin-stat-val{color:#dc2626}
.fin-stat.crit-low .fin-stat-val{color:#b45309}
.fin-stat.crit-exp{border-left:4px solid #ea580c}
.fin-stat .fin-stat-cat{font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;margin-bottom:2px}
.fin-stat .fin-stat-foot{font-size:.68rem;color:#94a3b8;margin-top:3px;font-weight:500}
/* Section headers */
.sd-sec{margin:30px 0 14px}
.sd-sec h2{font-size:.8rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#334155;display:flex;align-items:center;gap:10px;margin:0}
.sd-sec>h2>i:first-child,.sd-sec h2 .sd-sec-ic{width:30px;height:30px;border-radius:9px;background:var(--sd-grad);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;box-shadow:0 4px 10px -4px rgba(15,118,110,.5);flex-shrink:0}
/* Cards */
.sd-wf-card{background:#fff;border-radius:16px;border:1px solid #e6edf5;padding:18px;box-shadow:0 6px 22px -12px rgba(15,23,42,.14);margin-bottom:16px;transition:box-shadow .2s}
.sd-wf-card:hover{box-shadow:0 10px 28px -14px rgba(15,23,42,.2)}
.sd-wf-head h2{font-size:.98rem;font-weight:800;color:#0f172a;display:flex;align-items:center;gap:9px}
.sd-wf-head h2>i:first-child{width:30px;height:30px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;font-size:.82rem;flex-shrink:0;background:linear-gradient(135deg,#f8fafc,#eff6ff);box-shadow:inset 0 0 0 1px #e2e8f0}
.sd-panel-head h3{font-size:.95rem;font-weight:800;color:#0f172a;display:flex;align-items:center;gap:9px}
.sd-panel-head h3>i:first-child{width:28px;height:28px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;font-size:.8rem;flex-shrink:0;background:linear-gradient(135deg,#f8fafc,#f0fdf4);box-shadow:inset 0 0 0 1px #e2e8f0}
.sd-wf-count{background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;box-shadow:0 3px 8px -3px rgba(37,99,235,.5)}
/* Workflow flow */
.sd-flow{display:flex;align-items:stretch;gap:8px;flex-wrap:wrap;background:linear-gradient(180deg,#f8fafc,#f1f5f9);border-radius:14px;padding:14px;border:1px solid #e6edf5}
.sd-flow-step{padding:9px 13px;border-radius:11px;font-size:.76rem;font-weight:700;line-height:1.25;display:flex;align-items:center;gap:7px;box-shadow:0 2px 6px -3px rgba(15,23,42,.18);position:relative;animation:sdPulse 2.4s ease-in-out infinite}
.sd-flow-on{background:linear-gradient(135deg,#0d9488,#0f766e);color:#fff}
.sd-flow-off{background:#e9eef5;color:#475569;animation:none}
.sd-flow-off-blue{background:linear-gradient(135deg,#dbeafe,#bfdbfe);color:#1e40af;animation:none}
.sd-flow-off-purple{background:linear-gradient(135deg,#ede9fe,#ddd6fe);color:#5b21b6;animation:none}
.sd-flow-off-orange{background:linear-gradient(135deg,#ffedd5,#fed7aa);color:#9a3412;animation:none}
.sd-flow-step.sd-flow-end{outline:3px solid rgba(15,118,110,.35);outline-offset:1px}
.sd-flow-arrow{display:flex;align-items:center;color:#94a3b8;font-size:.72rem}
.sd-flow-gate{align-self:center;font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#0f766e;background:#ccfbf1;border:1px solid #5eead4;padding:4px 9px;border-radius:8px;white-space:nowrap}
@keyframes sdPulse{0%,100%{transform:scale(1)}50%{transform:scale(1.015)}}
/* ===== Fulfillment workflow timeline ===== */
.sd-timeline{display:flex;align-items:flex-start;gap:0;padding:8px 2px 4px;overflow-x:auto}
.sd-tl-step{flex:1 1 0;min-width:112px;display:flex;flex-direction:column;align-items:center;text-align:center;position:relative;padding:0 8px}
.sd-tl-step::before{content:"";position:absolute;top:21px;left:calc(-50% + 21px);width:calc(100% - 42px);height:3px;background:#e2e8f0;z-index:0}
.sd-tl-step:first-child::before{display:none}
.sd-tl-step.sd-tl-active::before{background:linear-gradient(90deg,#99f6e4,#0d9488)}
.sd-tl-node{width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.95rem;background:#f1f5f9;color:#94a3b8;border:2px solid #e2e8f0;position:relative;z-index:1;flex-shrink:0;box-shadow:0 2px 6px -3px rgba(15,23,42,.18);transition:all .2s}
.sd-tl-past .sd-tl-node{background:#e9f5ef;color:#0f766e;border-color:#a7f3d0}
.sd-tl-active .sd-tl-node{background:linear-gradient(135deg,#0d9488,#0f766e);color:#fff;border-color:#0f766e;box-shadow:0 6px 16px -6px rgba(15,118,110,.6)}
.sd-tl-later .sd-tl-node{background:#f1f5f9;color:#94a3b8}
.sd-tl-node i{transition:transform .2s}
.sd-tl-active:hover .sd-tl-node,.sd-tl-current:hover .sd-tl-node{transform:scale(1.08)}
.sd-tl-current .sd-tl-node{background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border-color:#d97706;box-shadow:0 6px 16px -6px rgba(217,119,6,.6);animation:sdPulse 2s ease-in-out infinite}
.sd-tl-label{font-size:.7rem;font-weight:600;color:#64748b;margin-top:9px;line-height:1.25;max-width:104px}
.sd-tl-active .sd-tl-label,.sd-tl-current .sd-tl-label{color:#0f172a;font-weight:700}
.sd-tl-step.sd-tl-handoff{outline:2px dashed rgba(15,118,110,.45);outline-offset:2px;border-radius:10px}
.sd-tl-badge{font-size:.56rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;background:#ccfbf1;color:#0f766e;border:1px solid #5eead4;padding:2px 6px;border-radius:6px;margin-top:5px;white-space:nowrap}
@media(max-width:820px){
  .sd-timeline{flex-direction:column;align-items:stretch;overflow-x:visible;gap:0}
  .sd-tl-step{flex:none;min-width:0;flex-direction:row;align-items:center;text-align:left;padding:8px 2px 8px 4px}
  .sd-tl-step::before{top:calc(-50% + 21px);left:21px;width:3px;height:calc(100% - 42px)}
  .sd-tl-step:first-child::before{top:auto;display:none}
  .sd-tl-step.sd-tl-active::before{background:linear-gradient(180deg,#99f6e4,#0d9488)}
  .sd-tl-node{width:34px;height:34px;font-size:.82rem}
  .sd-tl-label{margin-top:0;margin-left:13px;max-width:none;flex:1;font-size:.78rem}
  .sd-tl-badge{margin-top:0}
}
/* ===== Warehouse summary cards ===== */
.wh-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;margin-top:4px}
.wh-card{position:relative;overflow:hidden;background:#fff;border:1px solid #e6edf5;border-radius:16px;padding:16px 16px 14px;box-shadow:0 6px 22px -14px rgba(15,23,42,.16);transition:all .2s;cursor:pointer;text-decoration:none;display:block}
.wh-card:hover{transform:translateY(-3px);border-color:#a7f3d0;box-shadow:0 14px 30px -16px rgba(15,118,110,.35)}
.wh-card::before{content:"";position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,#0f766e,#14b8a6)}
.wh-card.is-empty::before{background:linear-gradient(90deg,#94a3b8,#cbd5e1)}
.wh-card.is-alert::before{background:linear-gradient(90deg,#d97706,#f59e0b)}
.wh-head{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.wh-ico{width:38px;height:38px;border-radius:11px;background:linear-gradient(135deg,#f0fdfa,#ccfbf1);color:#0f766e;display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0}
.wh-card.is-empty .wh-ico{background:#f1f5f9;color:#94a3b8}
.wh-name{font-size:.92rem;font-weight:800;color:#0f172a;line-height:1.2}
.wh-loc{font-size:.7rem;color:#94a3b8}
.wh-stats{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px}
.wh-stat{background:linear-gradient(180deg,#f8fafc,#f1f5f9);border:1px solid #eef2f7;border-radius:10px;padding:7px 10px}
.wh-stat-val{font-size:.95rem;font-weight:800;color:#0f172a;line-height:1.1}
.wh-stat-label{font-size:.62rem;color:#94a3b8;font-weight:600;margin-top:2px;text-transform:uppercase;letter-spacing:.03em}
.wh-empty-flag{display:inline-flex;align-items:center;gap:5px;margin-top:10px;font-size:.68rem;font-weight:700;color:#64748b;background:#f1f5f9;padding:4px 9px;border-radius:6px}
/* Tables */
.fin-table{border-collapse:separate;border-spacing:0;font-size:.84rem;width:100%}
.fin-table thead th{background:linear-gradient(180deg,#f8fafc,#f1f5f9);font-weight:800;color:#334155;border-bottom:2px solid #e2e8f0;padding:12px 14px}
.fin-table tbody tr{transition:all .12s}
.fin-table tbody tr:hover{background:#f0f7ff}
.fin-table tbody tr:last-child td{border-bottom:none}
/* Quick actions */
.fin-quick-actions{display:flex;gap:11px;flex-wrap:wrap}
.fin-qa{padding:12px 18px;border-radius:13px;font-size:.82rem;font-weight:700;color:#334155;cursor:pointer;text-decoration:none;transition:all .2s;border:1px solid #e6edf5;background:#fff;display:flex;align-items:center;gap:9px;box-shadow:0 3px 8px -6px rgba(15,23,42,.12)}
.fin-qa:hover{border-color:#7dd3c8;color:#0f766e;transform:translateY(-2px);box-shadow:0 10px 22px -12px rgba(15,118,110,.35)}
.fin-qa i{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.9rem;box-shadow:0 4px 10px -4px rgba(15,23,42,.35)}
/* Alert tiles */
.sd-alert-tile{border:1px solid #e6edf5;border-radius:13px;padding:14px 15px;background:#fff;box-shadow:0 2px 8px -6px rgba(15,23,42,.1);transition:all .2s;border-left:4px solid transparent}
.sd-alert-tile:hover{transform:translateY(-2px);box-shadow:0 10px 22px -12px rgba(15,23,42,.2)}
.sd-alert-tile.is-hot{border-color:#fecaca;border-left-color:#dc2626;background:linear-gradient(180deg,#fff, #fff7f7)}
.sd-alert-ico{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0;box-shadow:0 4px 10px -5px rgba(15,23,42,.25)}
.sd-alert-count{font-size:1.3rem;font-weight:800;color:#0f172a;line-height:1.05}
/* Mini stats */
.sd-mini-stat{background:linear-gradient(180deg,#f8fafc,#f1f5f9);border:1px solid #e6edf5;border-radius:11px;padding:11px 13px}
.sd-mini-stat-val{font-size:1.2rem;font-weight:800;line-height:1.1}
.sd-mini-stat-label{font-size:.7rem;color:var(--fin-gray);margin-top:3px;font-weight:600}
/* Buttons on dashboard */
.sd-btn{padding:8px 14px;min-height:40px;border-radius:10px;border:1px solid #e6edf5;background:#fff;color:#1e3a8a;font-size:.74rem;font-weight:700;display:inline-flex;align-items:center;gap:6px;cursor:pointer;text-decoration:none;transition:all .15s;box-shadow:0 2px 6px -5px rgba(15,23,42,.15)}
.sd-btn:hover{background:#f0f7ff;border-color:#bfdbfe;transform:translateY(-1px)}
.sd-btn-green{background:linear-gradient(135deg,#f0fdf4,#dcfce7);border-color:#bbf7d0;color:#166534}
.sd-btn-green:hover{background:#dcfce7;border-color:#86efac}
.sd-btn-red{background:linear-gradient(135deg,#fef2f2,#fee2e2);border-color:#fecaca;color:#991b1b}
.sd-btn-red:hover{background:#fee2e2;border-color:#fca5a5}
/* Activity rows */
.sd-act-item{border-bottom:1px dashed #eef2f7;padding:9px 2px}
.sd-act-ico{box-shadow:0 4px 10px -5px rgba(15,23,42,.2)}
/* Movement / expiry pills */
.sd-move,.sd-exp-bucket{box-shadow:0 1px 2px rgba(15,23,42,.08)}
/* Responsive tweaks */
@media(max-width:768px){.sd-hero{flex-direction:column;align-items:flex-start}.sd-hero-meta{text-align:left}}
</style>

<section class="fin-wrap sd-wrap">
    <div class="sd-hero">
        <div style="display:flex;align-items:center;gap:16px;flex:1;min-width:0;position:relative;z-index:1">
            <div class="sd-hero-icon"><i class="fas fa-store"></i></div>
            <div class="sd-hero-text">
                <h1>Store Manager Dashboard</h1>
                <div class="sd-hero-sub">
                    <span class="sd-role-chip"><i class="fas fa-user-gear"></i> Store Operations</span>
                    <span><i class="fas fa-boxes-stacked"></i> Inventory · Operations · Receiving · Order Preparation</span>
                </div>
            </div>
        </div>
        <div class="sd-hero-meta">
            <span class="sd-hero-date"><i class="fas fa-calendar-day"></i> <?= date('l, M d, Y') ?></span>
        </div>
    </div>

    <?php foreach ($messages as $m): ?>
        <div class="notice notice-<?= $m['type'] === 'success' ? 'success' : 'error' ?>" style="margin-bottom:12px"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <!-- ============ Key Metrics (unified grid) ============ -->
    <div class="sd-sec"><h2><span class="sd-sec-ic"><i class="fas fa-gauge-high"></i></span> Key Metrics</h2></div>
    <div class="fin-stats kpi-grid">
        <!-- Inventory -->
        <div class="fin-stat" onclick="window.location='?page=warehouse_inventory'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#0f766e,#115e59)"><i class="fas fa-warehouse"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-cat">Inventory</div>
                <div class="fin-stat-val" data-counter="<?= $totalWarehouses ?>">0</div>
                <div class="fin-stat-label">Warehouses</div>
            </div>
        </div>
        <div class="fin-stat" onclick="window.location='?page=products'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#2563eb,#1d4ed8)"><i class="fas fa-capsules"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-cat">Inventory</div>
                <div class="fin-stat-val" data-counter="<?= $totalProducts ?>">0</div>
                <div class="fin-stat-label">Products / SKUs</div>
            </div>
        </div>
        <div class="fin-stat" onclick="window.location='?page=stock'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#6366f1,#4f46e5)"><i class="fas fa-layer-group"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-cat">Inventory</div>
                <div class="fin-stat-val" data-counter="<?= $totalInventoryQty ?>">0</div>
                <div class="fin-stat-label">Inventory Quantity</div>
            </div>
        </div>
        <div class="fin-stat" onclick="window.location='?page=stock'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#059669,#047857)"><i class="fas fa-boxes-stacked"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-cat">Inventory</div>
                <div class="fin-stat-val" data-counter="<?= $inventoryValue ?>" data-prefix="<?= currency_sym() ?> " data-decimals="2"><?= currency_sym() ?> 0.00</div>
                <div class="fin-stat-label">Inventory Value</div>
            </div>
        </div>
        <div class="fin-stat crit crit-low" onclick="window.location='?page=stock'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#d97706,#b45309)"><i class="fas fa-battery-quarter"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-cat">Critical</div>
                <div class="fin-stat-val" data-counter="<?= $lowStockCount ?>">0</div>
                <div class="fin-stat-label">Low Stock Items</div>
            </div>
        </div>
        <div class="fin-stat crit-oos" onclick="window.location='?page=out_of_stock_queue'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#dc2626,#b91c1c)"><i class="fas fa-box-open"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-cat">Critical</div>
                <div class="fin-stat-val" data-counter="<?= $outOfStockCount ?>">0</div>
                <div class="fin-stat-label">Out of Stock Products</div>
            </div>
        </div>
        <div class="fin-stat crit crit-exp" onclick="window.location='?page=expiry_management&tab=expiring'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#ea580c,#c2410c)"><i class="fas fa-hourglass-half"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-cat">Critical</div>
                <div class="fin-stat-val" data-counter="<?= $expiringSoon ?>">0</div>
                <div class="fin-stat-label">Expiring in 90 Days</div>
            </div>
        </div>

        <!-- Procurement -->
        <div class="fin-stat" onclick="window.location='?page=purchase_orders'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#7c3aed,#6d28d9)"><i class="fas fa-file-invoice"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-cat">Procurement</div>
                <div class="fin-stat-val" data-counter="<?= $pendingPOs ?>">0</div>
                <div class="fin-stat-label">Pending Purchase Orders</div>
            </div>
        </div>
        <div class="fin-stat" onclick="window.location='?page=goods_receiving'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#0d9488,#0f766e)"><i class="fas fa-truck-loading"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-cat">Procurement</div>
                <div class="fin-stat-val" data-counter="<?= $pendingReceipts ?>">0</div>
                <div class="fin-stat-label">Pending Goods Receipts</div>
            </div>
        </div>

        <!-- Stock movement -->
        <div class="fin-stat" onclick="window.location='?page=stock_movements'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#16a34a,#15803d)"><i class="fas fa-arrow-down-to-line"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-cat">Stock Movement</div>
                <div class="fin-stat-val" data-counter="<?= $todayStockIn ?>">0</div>
                <div class="fin-stat-label">Today's Stock In</div>
            </div>
        </div>
        <div class="fin-stat" onclick="window.location='?page=stock_movements'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#db2777,#be185d)"><i class="fas fa-arrow-up-from-line"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-cat">Stock Movement</div>
                <div class="fin-stat-val" data-counter="<?= $todayStockOut ?>">0</div>
                <div class="fin-stat-label">Today's Stock Out</div>
            </div>
        </div>

        <!-- Order workflow -->
        <div class="fin-stat" onclick="window.location='?page=customer_order_queue'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#475569,#334155)"><i class="fas fa-cart-plus"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-cat">Order Workflow</div>
                <div class="fin-stat-val" data-counter="<?= $newOrdersCount ?>">0</div>
                <div class="fin-stat-label">New Orders</div>
            </div>
        </div>
        <div class="fin-stat" onclick="window.location='?page=order_fulfillment'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706)"><i class="fas fa-clipboard-question"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-cat">Order Workflow</div>
                <div class="fin-stat-val" data-counter="<?= $fulf['waiting'] ?>">0</div>
                <div class="fin-stat-label">Stock Check Pending</div>
            </div>
        </div>
        <div class="fin-stat" onclick="window.location='?page=order_fulfillment'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#7c3aed,#6d28d9)"><i class="fas fa-warehouse"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-cat">Order Workflow</div>
                <div class="fin-stat-val" data-counter="<?= $fulf['picking'] ?>">0</div>
                <div class="fin-stat-label">Picking In Progress</div>
            </div>
        </div>
        <div class="fin-stat" onclick="window.location='?page=order_fulfillment'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#2563eb,#1d4ed8)"><i class="fas fa-boxes-packing"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-cat">Order Workflow</div>
                <div class="fin-stat-val" data-counter="<?= $fulf['ready'] ?>">0</div>
                <div class="fin-stat-label">Ready for Packing</div>
            </div>
        </div>
        <div class="fin-stat" onclick="window.location='?page=order_fulfillment'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#0d9488,#0f766e)"><i class="fas fa-sack-dollar"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-cat">Order Workflow</div>
                <div class="fin-stat-val" data-counter="<?= $fulf['dispatch'] ?>">0</div>
                <div class="fin-stat-label">Prepared / Waiting Payment</div>
            </div>
        </div>
        <div class="fin-stat" onclick="window.location='?page=delivery_assign'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#0ea5e9,#0369a1)"><i class="fas fa-truck"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-cat">Order Workflow</div>
                <div class="fin-stat-val" data-counter="<?= $readyForDeliveryCount ?>">0</div>
                <div class="fin-stat-label">Ready for Delivery</div>
            </div>
        </div>
        <div class="fin-stat" onclick="window.location='?page=delivery_history'">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#22c55e,#15803d)"><i class="fas fa-circle-check"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-cat">Order Workflow</div>
                <div class="fin-stat-val" data-counter="<?= $completedOrdersCount ?>">0</div>
                <div class="fin-stat-label">Completed</div>
            </div>
        </div>
    </div>

    <!-- ============ Workflow ============ -->
    <div class="sd-wf-card" style="margin-top:16px">
        <div class="sd-wf-head">
            <h2><i class="fas fa-diagram-project" style="color:var(--fin-blue)"></i> Fulfillment Workflow</h2>
        </div>
        <div class="sd-timeline">
            <div class="sd-tl-step sd-tl-active">
                <div class="sd-tl-node"><i class="fas fa-cart-shopping"></i></div>
                <div class="sd-tl-label">Customer Places Order</div>
            </div>
            <div class="sd-tl-step sd-tl-active">
                <div class="sd-tl-node"><i class="fas fa-user-shield"></i></div>
                <div class="sd-tl-label">Administrator Approves</div>
            </div>
            <div class="sd-tl-step sd-tl-active">
                <div class="sd-tl-node"><i class="fas fa-clipboard-check"></i></div>
                <div class="sd-tl-label">Verify Stock</div>
            </div>
            <div class="sd-tl-step sd-tl-active">
                <div class="sd-tl-node"><i class="fas fa-dolly"></i></div>
                <div class="sd-tl-label">Ready to Pick</div>
            </div>
            <div class="sd-tl-step sd-tl-active">
                <div class="sd-tl-node"><i class="fas fa-warehouse"></i></div>
                <div class="sd-tl-label">Picking</div>
            </div>
            <div class="sd-tl-step sd-tl-active">
                <div class="sd-tl-node"><i class="fas fa-box-open"></i></div>
                <div class="sd-tl-label">Packing</div>
            </div>
            <div class="sd-tl-step sd-tl-current sd-tl-handoff">
                <div class="sd-tl-node"><i class="fas fa-truck-ramp-box"></i></div>
                <div class="sd-tl-label">Ready for Dispatch</div>
                <div class="sd-tl-badge">Hand-off</div>
            </div>
            <div class="sd-tl-step sd-tl-later">
                <div class="sd-tl-node"><i class="fas fa-truck"></i></div>
                <div class="sd-tl-label">Delivery Takes Order</div>
            </div>
            <div class="sd-tl-step sd-tl-later">
                <div class="sd-tl-node"><i class="fas fa-check-double"></i></div>
                <div class="sd-tl-label">Delivered</div>
            </div>
            <div class="sd-tl-step sd-tl-later">
                <div class="sd-tl-node"><i class="fas fa-file-invoice-dollar"></i></div>
                <div class="sd-tl-label">Finance Closes Invoice</div>
            </div>
        </div>
        <div class="sd-hint"><i class="fas fa-info-circle"></i> Store Manager responsibility ends at <strong>Ready for Dispatch</strong> (orange). Finance and Delivery teams take over from there.</div>
    </div>

    <!-- ============ Warehouse Summary Cards ============ -->
    <div class="sd-sec" style="margin-top:22px"><h2><span class="sd-sec-ic"><i class="fas fa-warehouse"></i></span> Warehouse Summary</h2></div>
    <?php if (empty($warehouseDist)): ?>
        <div class="sd-wf-card"><div class="fin-empty" style="padding:16px"><i class="fas fa-warehouse"></i>No active warehouses configured.</div></div>
    <?php else: ?>
    <div class="wh-cards">
        <?php foreach ($warehouseDist as $wd):
            $wq = (float) $wd['total_qty'];
            $isEmpty = (int) $wd['product_count'] <= 0 || $wq <= 0;
            $hasAlert = (int) $wd['oos_count'] > 0 || (int) $wd['low_count'] > 0;
            $cardClass = $isEmpty ? ' is-empty' : ($hasAlert ? ' is-alert' : '');
        ?>
        <a class="wh-card<?= $cardClass ?>" href="?page=store_dashboard&warehouse=<?= (int) $wd['id'] ?>">
            <div class="wh-head">
                <div class="wh-ico"><i class="fas fa-warehouse"></i></div>
                <div>
                    <div class="wh-name"><?= htmlspecialchars($wd['name']) ?></div>
                    <div class="wh-loc"><i class="fas fa-location-dot"></i> <?= htmlspecialchars($wd['location'] ?: 'No location set') ?></div>
                </div>
            </div>
            <?php if ($isEmpty): ?>
                <div class="wh-empty-flag"><i class="fas fa-box-open"></i> No stock recorded yet</div>
            <?php else: ?>
            <div class="wh-stats">
                <div class="wh-stat">
                    <div class="wh-stat-val"><?= number_format($wq) ?></div>
                    <div class="wh-stat-label">Total Qty</div>
                </div>
                <div class="wh-stat">
                    <div class="wh-stat-val" style="color:<?= (int) $wd['product_count'] ? '#0f766e' : '#94a3b8' ?>"><?= (int) $wd['product_count'] ?></div>
                    <div class="wh-stat-label">Products</div>
                </div>
                <div class="wh-stat">
                    <div class="wh-stat-val" style="color:<?= (int) $wd['low_count'] > 0 ? '#b45309' : '#94a3b8' ?>"><?= (int) $wd['low_count'] ?></div>
                    <div class="wh-stat-label">Low Stock</div>
                </div>
                <div class="wh-stat">
                    <div class="wh-stat-val" style="color:<?= (int) $wd['oos_count'] > 0 ? '#b91c1c' : '#94a3b8' ?>"><?= (int) $wd['oos_count'] ?></div>
                    <div class="wh-stat-label">Out of Stock</div>
                </div>
            </div>
            <div class="wh-empty-flag" style="margin-top:10px"><i class="fas fa-coins"></i> Value: <?= currency_sym() ?> <?= number_format((float) $wd['inv_value'], 2) ?></div>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ============ Warehouse Stock Distribution ============ -->
    <?php if ($whDetail): ?>
    <div class="sd-wf-card" style="margin-top:16px">
        <div class="sd-wf-head">
            <h2><i class="fas fa-warehouse" style="color:var(--fin-teal)"></i> <?= htmlspecialchars($whDetail['name']) ?> — Products &amp; Quantities</h2>
            <a class="sd-btn" href="?page=store_dashboard"><i class="fas fa-arrow-left"></i> Back to Overview</a>
        </div>
        <div class="sd-hint" style="margin:0 0 10px"><i class="fas fa-location-dot"></i> <?= htmlspecialchars($whDetail['location'] ?: '—') ?> · <?= count($whProducts) ?> products with batches in this warehouse</div>
        <?php if (empty($whProducts)): ?>
            <div class="fin-empty" style="padding:16px"><i class="fas fa-boxes-stacked"></i>No stock recorded in this warehouse yet.</div>
        <?php else: ?>
        <div style="overflow-x:auto">
            <table class="fin-table">
                <thead><tr><th>Product</th><th>Quantity</th><th>Batches</th><th>Reorder Level</th><th>Next Expiry</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($whProducts as $wp):
                        $wq = (int) $wp['wh_qty'];
                        $effMin = (int) (empty($wp['reorder_level']) ? 10 : $wp['reorder_level']);
                        if ($wq <= 0) { $wsBadge = 'background:#fef2f2;color:#b91c1c'; $wsText = 'Out of Stock'; }
                        elseif ($wq <= $effMin) { $wsBadge = 'background:#fffbeb;color:#b45309'; $wsText = 'Low Stock'; }
                        else { $wsBadge = 'background:#ecfdf5;color:#047857'; $wsText = 'Available'; }
                    ?>
                    <tr>
                        <td style="font-size:.82rem;color:#475569;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><strong><?= htmlspecialchars($wp['name']) ?></strong><?= $wp['brand'] ? ' <span style="color:#94a3b8">' . htmlspecialchars($wp['brand']) . '</span>' : '' ?></td>
                        <td style="font-weight:700;<?= $wq <= 0 ? 'color:#b91c1c' : ($wq <= $effMin ? 'color:#92400e' : 'color:#166534') ?>"><?= $wq ?><?= $wp['unit_of_measure'] ? ' ' . htmlspecialchars($wp['unit_of_measure']) : '' ?></td>
                        <td style="font-size:.8rem;color:#475569"><?= (int) $wp['batch_count'] ?></td>
                        <td style="font-size:.8rem;color:#475569"><?= $effMin ?></td>
                        <td style="font-size:.78rem;color:var(--fin-gray)"><?= $wp['next_expiry'] ? htmlspecialchars(date('M d, Y', strtotime($wp['next_expiry']))) : '—' ?></td>
                        <td><span class="sd-exp-bucket" style="<?= $wsBadge ?>"><?= $wsText ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="sd-wf-card" style="margin-top:16px">
        <div class="sd-panel-head">
            <h3><i class="fas fa-chart-pie" style="color:var(--fin-indigo)"></i> Warehouse Stock Distribution</h3>
            <a class="sd-btn" href="?page=warehouse_inventory"><i class="fas fa-table-columns"></i> Full Inventory</a>
        </div>
        <?php if (empty($warehouseDist)): ?>
            <div class="fin-empty" style="padding:16px"><i class="fas fa-warehouse"></i>No active warehouses configured.</div>
        <?php else: ?>
        <div style="overflow-x:auto">
            <table class="fin-table">
                <thead><tr><th>Warehouse</th><th>Products</th><th>Total Quantity</th><th>Low Stock Items</th><th>Out of Stock Items</th><th>Inventory Value</th></tr></thead>
                <tbody>
                    <?php foreach ($warehouseDist as $wd): ?>
                    <tr style="cursor:pointer" onclick="window.location='?page=store_dashboard&warehouse=<?= (int) $wd['id'] ?>'">
                        <td>
                            <div style="font-weight:700;color:var(--fin-blue);text-decoration:none;font-size:.86rem"><i class="fas fa-warehouse" style="color:#94a3b8;margin-right:6px"></i><?= htmlspecialchars($wd['name']) ?></div>
                            <div style="font-size:.72rem;color:#94a3b8"><?= htmlspecialchars($wd['location'] ?: '—') ?></div>
                        </td>
                        <td style="font-weight:600"><?= (int) $wd['product_count'] ?></td>
                        <td style="font-weight:700"><?= number_format((float) $wd['total_qty']) ?></td>
                        <td style="font-weight:700;color:<?= (int) $wd['low_count'] > 0 ? '#b45309' : '#94a3b8' ?>"><?= (int) $wd['low_count'] ?></td>
                        <td style="font-weight:700;color:<?= (int) $wd['oos_count'] > 0 ? '#b91c1c' : '#94a3b8' ?>"><?= (int) $wd['oos_count'] ?></td>
                        <td style="font-weight:700;color:#334155"><?= currency_sym() ?> <?= number_format((float) $wd['inv_value'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="sd-hint"><i class="fas fa-hand-pointer"></i> Click a warehouse to view its products and quantities.</div>
        <?php endif; ?>
    </div>

    <!-- ============ Attention Required ============ -->
    <?php
    $attentionItems = [
        ['label' => 'Products below reorder level', 'count' => $lowStockCount, 'icon' => 'fa-battery-quarter', 'bg' => '#fffbeb', 'fg' => '#b45309', 'href' => '?page=stock'],
        ['label' => 'Out-of-stock products', 'count' => $outOfStockCount, 'icon' => 'fa-box-open', 'bg' => '#fef2f2', 'fg' => '#b91c1c', 'href' => '?page=out_of_stock_queue'],
        ['label' => 'Products expiring soon', 'count' => $expiringSoon, 'icon' => 'fa-hourglass-half', 'bg' => '#fff7ed', 'fg' => '#c2410c', 'href' => '?page=expiry_management&tab=expiring'],
        ['label' => 'Orders waiting for stock check', 'count' => $fulf['waiting'], 'icon' => 'fa-clipboard-question', 'bg' => '#eff6ff', 'fg' => '#1d4ed8', 'href' => '?page=order_fulfillment'],
        ['label' => 'Orders waiting for picking', 'count' => $fulf['ready'], 'icon' => 'fa-dolly', 'bg' => '#f0fdf4', 'fg' => '#15803d', 'href' => '?page=order_fulfillment'],
        ['label' => 'Orders waiting for preparation', 'count' => $fulf['picking'] + $fulf['packing'], 'icon' => 'fa-boxes-packing', 'bg' => '#f5f3ff', 'fg' => '#6d28d9', 'href' => '?page=order_fulfillment'],
        ['label' => 'Pending stock transfers', 'count' => $pendingTransfersCount, 'icon' => 'fa-right-left', 'bg' => '#ecfeff', 'fg' => '#0e7490', 'href' => '?page=stock_transfers'],
    ];
    ?>
    <div class="sd-wf-card" style="margin-top:16px">
        <div class="sd-wf-head">
            <h2><i class="fas fa-triangle-exclamation" style="color:var(--fin-orange)"></i> Attention Required</h2>
            <span class="sd-wf-count"><?= array_sum(array_column($attentionItems, 'count')) ?></span>
        </div>
        <div class="sd-alert-grid">
            <?php foreach ($attentionItems as $ai): ?>
            <a class="sd-alert-tile<?= $ai['count'] > 0 ? ' is-hot' : '' ?>" href="<?= $ai['href'] ?>">
                <span class="sd-alert-ico" style="background:<?= $ai['bg'] ?>;color:<?= $ai['fg'] ?>"><i class="fas <?= $ai['icon'] ?>"></i></span>
                <span class="sd-alert-body">
                    <span class="sd-alert-count"><?= (int) $ai['count'] ?></span>
                    <span class="sd-alert-label"><?= htmlspecialchars($ai['label']) ?></span>
                </span>
                <span class="sd-alert-go"><i class="fas fa-chevron-right"></i></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ============ Quick Actions ============ -->
    <div class="sd-sec"><h2><span class="sd-sec-ic"><i class="fas fa-bolt"></i></span> Quick Actions</h2></div>
    <div class="fin-quick-actions">
        <a href="?page=stock" class="fin-qa"><i style="background:var(--fin-blue)"><i class="fas fa-magnifying-glass"></i></i> Check Stock</a>
        <a href="?page=warehouse_inventory" class="fin-qa"><i style="background:var(--fin-teal)"><i class="fas fa-warehouse"></i></i> View Warehouse Stock</a>
        <a href="?page=stock_transfers" class="fin-qa"><i style="background:var(--fin-indigo)"><i class="fas fa-right-left"></i></i> Stock Transfer</a>
        <a href="?page=stock_adjustments" class="fin-qa"><i style="background:var(--fin-purple)"><i class="fas fa-sliders"></i></i> Stock Adjustment</a>
        <a href="?page=order_fulfillment" class="fin-qa"><i style="background:var(--fin-green)"><i class="fas fa-boxes-stacked"></i></i> New Order Preparation</a>
        <a href="?page=batch_expiry" class="fin-qa"><i style="background:var(--fin-orange)"><i class="fas fa-calendar-days"></i></i> View Batch &amp; Expiry</a>
        <a href="?page=goods_receiving" class="fin-qa"><i style="background:#0d9488"><i class="fas fa-truck-loading"></i></i> Receive Goods</a>
        <a href="?page=physical_count" class="fin-qa"><i style="background:#64748b"><i class="fas fa-clipboard-check"></i></i> Physical Count</a>
    </div>

    <!-- ============ Purchase Order Panel ============ -->
    <div class="sd-wf-card" style="margin-top:16px">
        <div class="sd-panel-head">
            <h3><i class="fas fa-file-invoice" style="color:var(--fin-purple)"></i> Purchase Order Panel</h3>
            <a class="sd-btn" href="?page=purchase_orders"><i class="fas fa-plus"></i> Create Purchase Order</a>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
            <div class="sd-mini-stat"><div class="sd-mini-stat-val" style="color:#7c3aed"><?= $pendingPOs ?></div><div class="sd-mini-stat-label">Pending Purchase Orders</div></div>
            <div class="sd-mini-stat"><div class="sd-mini-stat-val" style="color:#2563eb"><?= $approvedPOs ?></div><div class="sd-mini-stat-label">Approved Purchase Orders</div></div>
            <div class="sd-mini-stat"><div class="sd-mini-stat-val" style="color:#d97706"><?= $pendingReceipts ?></div><div class="sd-mini-stat-label">Goods Waiting to Receive</div></div>
            <div class="sd-mini-stat"><div class="sd-mini-stat-val" style="color:#16a34a"><?= $receivedToday ?></div><div class="sd-mini-stat-label">Received Today</div></div>
        </div>
        <?php if (empty($openPOs)): ?>
            <div class="fin-empty" style="padding:16px"><i class="fas fa-check-circle" style="color:var(--fin-green)"></i>No open purchase orders.</div>
        <?php else: ?>
        <div style="overflow-x:auto">
            <table class="fin-table">
                <thead><tr><th>Reference</th><th>Supplier</th><th>Status</th><th>Assigned User</th><th>Date</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($openPOs as $r): ?>
                    <tr>
                        <td><a href="?page=purchase_orders&view=<?= (int) $r['id'] ?>" style="font-weight:700;color:var(--fin-blue);text-decoration:none">PO-<?= str_pad((string) $r['id'], 4, '0', STR_PAD_LEFT) ?></a></td>
                        <td style="font-size:.82rem;color:#475569;max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($r['supplier_name'] ?? '—') ?></td>
                        <td><?= sdBadge(StatusCatalog::WORKFLOW_PURCHASE, $r['status'], $sdToneClass) ?></td>
                        <td style="font-size:.8rem;color:#475569"><?= htmlspecialchars($r['assigned_name'] ?? '—') ?></td>
                        <td style="color:var(--fin-gray);font-size:.78rem"><?= htmlspecialchars($r['order_date']) ?></td>
                        <td>
                            <?php if (in_array($r['status'], [StatusCatalog::PO_PAYMENT_APPROVED], true)): ?>
                                <a class="sd-btn sd-btn-green" href="?page=goods_receiving"><i class="fas fa-truck-loading"></i> Receive</a>
                            <?php else: ?>
                                <a class="sd-btn" href="?page=purchase_orders&view=<?= (int) $r['id'] ?>"><i class="fas fa-eye"></i> View</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ============ Orders Requiring Action (Stock Check / Pack) ============ -->
    <div class="sd-sec" style="margin-top:22px"><h2><span class="sd-sec-ic"><i class="fas fa-boxes-stacked"></i></span> Orders Requiring Action</h2></div>
    <div class="sd-wf-card" style="margin-top:4px">
        <div class="sd-wf-head">
            <h2><i class="fas fa-clipboard-list" style="color:var(--fin-teal)"></i> Store Work Queue</h2>
            <span class="sd-wf-count"><?= count($fulfillmentAction) ?></span>
        </div>
        <?php if (empty($fulfillmentAction)): ?>
            <div class="fin-empty" style="padding:16px"><i class="fas fa-check-circle" style="color:var(--fin-green)"></i>No approved orders are waiting on the Store Manager — everything is either checked, packed, or handed off.</div>
        <?php else: ?>
        <div style="overflow-x:auto">
            <table class="fin-table">
                <thead><tr><th>Order #</th><th>Customer</th><th>Order Date</th><th>Products</th><th>Total Qty</th><th>Stock Check</th><th>Pack</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($fulfillmentAction as $fo): ?>
                        <?php
                            $fst = $fo['fulfillment_status'] ?: StatusCatalog::FULFILLMENT_WAITING_STOCK_CHECK;
                            [$scLabel, $scCls] = $sdStockCheckLabel[$fst] ?? ['—', 'fin-badge-gray'];
                            [$pkLabel, $pkCls] = $sdPackLabel[$fst] ?? ['—', 'fin-badge-gray'];
                            $needsCheck = $fst === StatusCatalog::FULFILLMENT_WAITING_STOCK_CHECK || $fst === StatusCatalog::FULFILLMENT_OUT_OF_STOCK;
                            $needsPack  = in_array($fst, [StatusCatalog::FULFILLMENT_READY_TO_PREPARE, StatusCatalog::FULFILLMENT_RESERVED, StatusCatalog::FULFILLMENT_PREPARING], true);
                        ?>
                        <tr>
                            <td><a href="?page=order_fulfillment" style="font-weight:700;color:var(--fin-blue);text-decoration:none">#<?= (int) $fo['id'] ?></a></td>
                            <td style="font-size:.82rem;color:#475569;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><strong><?= htmlspecialchars($fo['customer_name'] ?? '—') ?></strong></td>
                            <td style="font-size:.78rem;color:var(--fin-gray);white-space:nowrap"><?= htmlspecialchars($fo['order_date'] ?? '—') ?></td>
                            <td style="font-size:.8rem;color:#475569"><?= (int) $fo['_lines']['product_count'] ?></td>
                            <td style="font-weight:700;color:#334155"><?= (int) $fo['_lines']['total_qty'] ?></td>
                            <td><span class="fin-badge <?= $scCls ?>"><?= htmlspecialchars($scLabel) ?></span></td>
                            <td><span class="fin-badge <?= $pkCls ?>"><?= htmlspecialchars($pkLabel) ?></span></td>
                            <td>
                                <?php if ($needsCheck): ?>
                                    <form method="post" style="display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="stock_check">
                                        <input type="hidden" name="order_id" value="<?= (int) $fo['id'] ?>">
                                        <button type="submit" class="sd-btn sd-btn-green" onclick="return confirm('Run stock check for order #<?= (int) $fo['id'] ?>?');"><i class="fas fa-clipboard-check"></i> Stock Check</button>
                                    </form>
                                <?php elseif ($needsPack): ?>
                                    <form method="post" style="display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="pack">
                                        <input type="hidden" name="order_id" value="<?= (int) $fo['id'] ?>">
                                        <button type="submit" class="sd-btn sd-btn-green" onclick="return confirm('Pack order #<?= (int) $fo['id'] ?> and hand it off?');"><i class="fas fa-box-open"></i> Pack</button>
                                    </form>
                                <?php else: ?>
                                    <span class="fin-badge fin-badge-gray">No action</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="sd-hint"><i class="fas fa-info-circle"></i> Just two steps per order: <strong>Stock Check</strong> then <strong>Pack</strong>. Packing confirms the quantities were picked/packed and hands the order to Finance / Delivery. Once packed the order leaves this queue.</div>
        <?php endif; ?>
    </div>

    <!-- ============ Handed Off (Packed) ============ -->
    <div class="sd-wf-card" style="margin-top:4px">
        <div class="sd-wf-head">
            <h2><i class="fas fa-truck-ramp-box" style="color:var(--fin-green)"></i> Handed Off — Packed &amp; Routed</h2>
            <span class="sd-wf-count"><?= count($fulfillmentPacked) ?></span>
        </div>
        <?php if (empty($fulfillmentPacked)): ?>
            <div class="fin-empty" style="padding:16px"><i class="fas fa-inbox"></i>No packed orders to show.</div>
        <?php else: ?>
        <div style="overflow-x:auto">
            <table class="fin-table">
                <thead><tr><th>Order #</th><th>Customer</th><th>Products</th><th>Total Qty</th><th>Packed At</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($fulfillmentPacked as $fo):
                        $pfst = $fo['fulfillment_status'] ?: StatusCatalog::FULFILLMENT_WAITING_STOCK_CHECK;
                    ?>
                        <tr>
                            <td><a href="?page=order_fulfillment" style="font-weight:700;color:var(--fin-blue);text-decoration:none">#<?= (int) $fo['id'] ?></a></td>
                            <td style="font-size:.82rem;color:#475569;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><strong><?= htmlspecialchars($fo['customer_name'] ?? '—') ?></strong></td>
                            <td style="font-size:.8rem;color:#475569"><?= (int) $fo['_lines']['product_count'] ?></td>
                            <td style="font-weight:700;color:#334155"><?= (int) $fo['_lines']['total_qty'] ?></td>
                            <td style="font-size:.78rem;color:var(--fin-gray);white-space:nowrap"><?= $fo['prepared_at'] ? date('M d, Y H:i', strtotime($fo['prepared_at'])) : '—' ?></td>
                            <td><span class="fin-badge <?= $sdStageTone[$pfst] ?? 'fin-badge-gray' ?>"><?= htmlspecialchars($sdStageLabel[$pfst] ?? ($fo['fulfillment_status'] ?: '—')) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="sd-hint"><i class="fas fa-info-circle"></i> Store Manager responsibility ends once an order is packed. Finance and Delivery take over from here.</div>
        <?php endif; ?>
    </div>

    <!-- ============ Low Stock + Expiry ============ -->
    <div class="fin-grid-2">
        <div class="sd-wf-card" style="margin-bottom:0">
            <div class="sd-wf-head">
                <h2><i class="fas fa-battery-quarter" style="color:var(--fin-orange)"></i> Low Stock Reorder</h2>
                <span class="sd-wf-count"><?= count($lowStock) ?></span>
            </div>
            <?php if (empty($lowStock)): ?>
                <div class="fin-empty" style="padding:16px"><i class="fas fa-check-circle" style="color:var(--fin-green)"></i>No products below reorder level.</div>
            <?php else: ?>
            <div style="overflow-x:auto">
                <table class="fin-table">
                    <thead><tr><th>Product</th><th>Current Qty</th><th>Min Qty</th><th>Supplier</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($lowStock as $r): ?>
                        <tr>
                            <td style="font-size:.82rem;color:#475569;max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><strong><?= htmlspecialchars($r['name']) ?></strong><?= $r['brand'] ? ' <span style="color:#94a3b8">'.htmlspecialchars($r['brand']).'</span>' : '' ?></td>
                            <td style="font-weight:700;color:#92400e"><?= (int) $r['stock_quantity'] ?></td>
                            <td style="font-size:.8rem;color:#475569"><?= (int) $r['eff_reorder'] ?></td>
                            <td style="font-size:.8rem;color:#475569;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($r['supplier_name'] ?? '—') ?></td>
                            <td><a class="sd-btn sd-btn-green" href="?page=purchase_orders"><i class="fas fa-cart-plus"></i> Create Purchase Order</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="sd-hint"><i class="fas fa-info-circle"></i> Reorder level defaults to 10 when not configured on the product.</div>
            <?php endif; ?>
        </div>

        <div class="sd-wf-card" style="margin-bottom:0">
            <div class="sd-wf-head">
                <h2><i class="fas fa-calendar-times" style="color:var(--fin-red)"></i> Expiry Monitor</h2>
                <span class="sd-wf-count"><?= $expiredCount + $exp30Count + $exp60Count ?></span>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
                <div class="sd-mini-stat"><div class="sd-mini-stat-val" style="color:#dc2626"><?= $expiredCount ?></div><div class="sd-mini-stat-label">Expired Products</div></div>
                <div class="sd-mini-stat"><div class="sd-mini-stat-val" style="color:#d97706"><?= $exp30Count ?></div><div class="sd-mini-stat-label">Expiring in 30 Days</div></div>
                <div class="sd-mini-stat"><div class="sd-mini-stat-val" style="color:#ca8a04"><?= $exp60Count ?></div><div class="sd-mini-stat-label">Expiring in 60 Days</div></div>
            </div>
            <?php if (empty($expiryRows)): ?>
                <div class="fin-empty" style="padding:16px"><i class="fas fa-check-circle" style="color:var(--fin-green)"></i>No expired or soon-to-expire batches.</div>
            <?php else: ?>
            <div style="overflow-x:auto">
                <table class="fin-table">
                    <thead><tr><th>Product</th><th>Batch #</th><th>Expiry</th><th>Qty</th><th>Bucket</th></tr></thead>
                    <tbody>
                        <?php foreach ($expiryRows as $r): ?>
                            <?php
                            $ed = $r['expiry_date'];
                            $today = date('Y-m-d');
                            if ($ed < $today) { $bucket = 'Expired'; $bCls = 'background:#fef2f2;color:#dc2626'; }
                            elseif ($ed <= date('Y-m-d', strtotime('+30 days'))) { $bucket = '30 Days'; $bCls = 'background:#fff7ed;color:#d97706'; }
                            else { $bucket = '60 Days'; $bCls = 'background:#fefce8;color:#ca8a04'; }
                            ?>
                            <tr>
                                <td style="font-size:.82rem;color:#475569;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><strong><?= htmlspecialchars($r['product_name']) ?></strong></td>
                                <td style="font-family:monospace;font-size:.78rem"><?= htmlspecialchars($r['batch_number']) ?></td>
                                <td style="color:var(--fin-gray);font-size:.78rem"><?= htmlspecialchars($ed) ?></td>
                                <td style="font-size:.8rem;color:#475569"><?= (int) $r['quantity'] ?></td>
                                <td><span class="sd-exp-bucket" style="<?= $bCls ?>"><?= $bucket ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============ Recent Stock Movements ============ -->
    <div class="sd-sec" style="margin-top:22px"><h2><span class="sd-sec-ic"><i class="fas fa-right-left"></i></span> Recent Stock Movements</h2></div>
    <div class="sd-wf-card" style="margin-bottom:16px">
        <div class="sd-panel-head">
            <h3><i class="fas fa-clock-rotate-left" style="color:var(--fin-blue)"></i> Latest In / Out / Transfer / Adjustment</h3>
            <a class="sd-btn" href="?page=stock_movements"><i class="fas fa-list"></i> View All Movements</a>
        </div>
        <?php if (empty($recentMovements)): ?>
            <div class="fin-empty" style="padding:16px"><i class="fas fa-inbox"></i>No stock movements recorded yet.</div>
        <?php else: ?>
        <div style="overflow-x:auto">
            <table class="fin-table">
                <thead><tr><th>Type</th><th>Product</th><th>Quantity</th><th>Warehouse</th><th>Date / Time</th><th>User</th></tr></thead>
                <tbody>
                    <?php foreach ($recentMovements as $mv):
                        [$mCat, $mStyle, $mIcon] = sdMoveCategory($mv['reference_type'], (int) $mv['quantity_change']);
                        $whLabel = $mv['reference_type'] === 'transfer'
                            ? trim(($mv['from_wh'] ?: '—') . ' → ' . ($mv['to_wh'] ?: '—'))
                            : '—';
                    ?>
                    <tr>
                        <td><span class="sd-move" style="<?= $mStyle ?>"><i class="fas <?= $mIcon ?>"></i> <?= $mCat ?></span></td>
                        <td style="font-size:.82rem;color:#475569;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><strong><?= htmlspecialchars($mv['product_name']) ?></strong></td>
                        <td style="font-weight:700;color:<?= (int) $mv['quantity_change'] >= 0 ? '#166534' : '#b91c1c' ?>"><?= (int) $mv['quantity_change'] > 0 ? '+' : '' ?><?= (int) $mv['quantity_change'] ?></td>
                        <td style="font-size:.8rem;color:#475569;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($whLabel) ?></td>
                        <td style="font-size:.78rem;color:var(--fin-gray);white-space:nowrap"><?= date('M d, Y H:i', strtotime($mv['created_at'])) ?></td>
                        <td style="font-size:.8rem;color:#475569"><?= htmlspecialchars($mv['user_name'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="sd-hint"><i class="fas fa-info-circle"></i> Warehouse and user are recorded for transfers; other movements apply to the pooled inventory.</div>
        <?php endif; ?>
    </div>

    <!-- ============ Recent Activities ============ -->
    <div class="sd-sec" style="margin-top:22px"><h2><span class="sd-sec-ic"><i class="fas fa-clock-rotate-left"></i></span> Recent Activities</h2></div>
    <div class="fin-grid-2">
        <div class="sd-wf-card" style="margin-bottom:0">
            <div class="sd-wf-head">
                <h2><i class="fas fa-truck-loading" style="color:var(--fin-green)"></i> Recently Received Products</h2>
                <span class="sd-wf-count"><?= count($recentReceived) ?></span>
            </div>
            <?php if (empty($recentReceived)): ?>
                <div class="fin-empty" style="padding:16px"><i class="fas fa-inbox"></i>No goods received yet.</div>
            <?php else: ?>
                <?php foreach ($recentReceived as $a): ?>
                <div class="sd-act-item">
                    <span class="sd-act-ico" style="background:#ecfdf5;color:#059669"><i class="fas fa-boxes-stacked"></i></span>
                    <div class="sd-act-body">
                        <div class="sd-act-title"><?= htmlspecialchars($a['product_name']) ?></div>
                        <div class="sd-act-sub">+<?= (int) $a['quantity_change'] ?> units · PO #<?= (int) $a['reference_id'] ?></div>
                    </div>
                    <span class="sd-act-time"><?= date('M d', strtotime($a['created_at'])) ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="sd-wf-card" style="margin-bottom:0">
            <div class="sd-wf-head">
                <h2><i class="fas fa-sliders" style="color:var(--fin-purple)"></i> Recent Stock Adjustments</h2>
                <span class="sd-wf-count"><?= count($recentAdjustments) ?></span>
            </div>
            <?php if (empty($recentAdjustments)): ?>
                <div class="fin-empty" style="padding:16px"><i class="fas fa-inbox"></i>No stock adjustments recorded yet.</div>
            <?php else: ?>
                <?php foreach ($recentAdjustments as $a): ?>
                <div class="sd-act-item">
                    <span class="sd-act-ico" style="background:#f5f3ff;color:#7c3aed"><i class="fas fa-sliders"></i></span>
                    <div class="sd-act-body">
                        <div class="sd-act-title"><?= htmlspecialchars($a['product_name']) ?></div>
                        <div class="sd-act-sub"><?= $a['quantity_change'] > 0 ? '+' : '' ?><?= (int) $a['quantity_change'] ?> units · <?= htmlspecialchars($a['reason'] ?? 'adjustment') ?></div>
                    </div>
                    <span class="sd-act-time"><?= date('M d', strtotime($a['created_at'])) ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="fin-grid-2" style="margin-top:16px">
        <div class="sd-wf-card" style="margin-bottom:0">
            <div class="sd-wf-head">
                <h2><i class="fas fa-file-invoice" style="color:var(--fin-blue)"></i> Recently Approved Purchase Orders</h2>
                <span class="sd-wf-count"><?= count($recentApprovedPOs) ?></span>
            </div>
            <?php if (empty($recentApprovedPOs)): ?>
                <div class="fin-empty" style="padding:16px"><i class="fas fa-inbox"></i>No purchase order approvals recorded yet.</div>
            <?php else: ?>
                <?php foreach ($recentApprovedPOs as $a): ?>
                <div class="sd-act-item">
                    <span class="sd-act-ico" style="background:#eff6ff;color:#2563eb"><i class="fas fa-check-circle"></i></span>
                    <div class="sd-act-body">
                        <div class="sd-act-title">PO-<?= str_pad((string) $a['entity_id'], 4, '0', STR_PAD_LEFT) ?> approved</div>
                        <div class="sd-act-sub"><?= htmlspecialchars($a['changed_name'] ?? 'Administrator') ?></div>
                    </div>
                    <span class="sd-act-time"><?= date('M d', strtotime($a['created_at'])) ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="sd-wf-card" style="margin-bottom:0">
            <div class="sd-wf-head">
                <h2><i class="fas fa-boxes-stacked" style="color:var(--fin-teal)"></i> Recently Prepared Orders</h2>
                <span class="sd-wf-count"><?= count($recentPrepared) ?></span>
            </div>
            <?php if (empty($recentPrepared)): ?>
                <div class="fin-empty" style="padding:16px"><i class="fas fa-inbox"></i>No orders prepared yet.</div>
            <?php else: ?>
                <?php foreach ($recentPrepared as $a): ?>
                <div class="sd-act-item">
                    <span class="sd-act-ico" style="background:#f0fdf4;color:#16a34a"><i class="fas fa-box-open"></i></span>
                    <div class="sd-act-body">
                        <div class="sd-act-title">Order #<?= (int) $a['id'] ?> packed</div>
                        <div class="sd-act-sub"><?= htmlspecialchars($a['customer_name'] ?? '—') ?></div>
                    </div>
                    <span class="sd-act-time"><?= date('M d', strtotime($a['prepared_at'])) ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>
<?= finJs() ?>
