<?php
require_once __DIR__ . '/../includes/finance_helpers.php';
require_once __DIR__ . '/../includes/workflow_helpers.php';
require_once __DIR__ . '/../includes/auto_migrate.php';
require_once __DIR__ . '/../services/init.php';
requirePermission('order_fulfillment', 'view');
$pdo = getPDO();
ensureFulfillmentMigration($pdo);

$messages = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf();
    requirePermission('order_fulfillment', 'edit');
    $action = $_POST['_action'] ?? '';
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $uid = (int) ($_SESSION['user_id'] ?? 0);

    if ($action === 'stock_check') {
        $messages[] = FulfillmentService::stockCheck($pdo, $orderId, $uid);
    } elseif ($action === 'reserve_stock') {
        $messages[] = FulfillmentService::reserveStock($pdo, $orderId, $uid);
    } elseif ($action === 'start_preparing') {
        $messages[] = FulfillmentService::startPreparing($pdo, $orderId, $uid);
    } elseif ($action === 'prepare_order') {
        $messages[] = FulfillmentService::prepareOrder($pdo, $orderId, $uid);
    } elseif ($action === 'pack') {
        $messages[] = FulfillmentService::pack($pdo, $orderId, $uid);
    } elseif ($action === 'mark_prepared') {
        $messages[] = FulfillmentService::markPrepared($pdo, $orderId, $uid);
    } elseif ($action === 'mark_waiting_pickup') {
        $messages[] = FulfillmentService::markWaitingPickup($pdo, $orderId, $uid);
    } elseif ($action === 'ready_for_delivery') {
        $messages[] = FulfillmentService::readyForDelivery($pdo, $orderId, $uid);
    } elseif ($action === 'hand_over_pickup') {
        $receivedBy = trim($_POST['received_by'] ?? '');
        $signature = trim($_POST['pickup_signature'] ?? '');
        $messages[] = FulfillmentService::handOverForPickup($pdo, $orderId, $uid, $receivedBy, $signature);
    } elseif ($action === 'reject_fulfillment') {
        $reason = trim($_POST['rejection_reason'] ?? '');
        $messages[] = FulfillmentService::rejectFulfillment($pdo, $orderId, $uid, $reason);
    } elseif ($action === 'approve_salesperson') {
        try {
            $adv = WorkflowService::approveSalespersonStage($pdo, $orderId, $uid, 'store');
            $messages[] = ['success' => true, 'msg' => "Order #{$orderId} approved by the Store Manager. It now awaits Finance finalization."];
        } catch (ServiceException $e) {
            $messages[] = ['success' => false, 'msg' => $e->getMessage()];
        }
    }
}

$activeOrders = FulfillmentService::activeOrders($pdo);
$completedOrders = FulfillmentService::completedOrders($pdo);
$pickupReady = FulfillmentService::pickupReadyOrders($pdo);
$deliveryReady = FulfillmentService::deliveryReadyOrders($pdo);
// Store Managers run a compressed two-action workflow (Stock Check + Pack);
// other roles keep the full multi-step fulfillment chain.
$smOnly = isStoreManager();
$spApprovals = $pdo->query("
    SELECT co.*, c.name AS customer_name, sp.name AS salesperson_name
    FROM customer_orders co
    LEFT JOIN customers c ON co.customer_id = c.id
    LEFT JOIN salespeople sp ON co.salesperson_id = sp.id
    WHERE co.status = 'pending' AND co.approval_stage IS NOT NULL
    ORDER BY co.created_at ASC
")->fetchAll();

$counts = [
    'waiting' => 0, 'ready' => 0, 'reserved' => 0, 'oos' => 0,
    'preparing' => 0, 'prepared' => 0, 'waiting_pickup' => 0, 'ready_delivery' => 0,
];
foreach ($activeOrders as $o) {
    $st = $o['fulfillment_status'] ?: StatusCatalog::FULFILLMENT_WAITING_STOCK_CHECK;
    if ($st === StatusCatalog::FULFILLMENT_WAITING_STOCK_CHECK) $counts['waiting']++;
    elseif ($st === StatusCatalog::FULFILLMENT_READY_TO_PREPARE) $counts['ready']++;
    elseif ($st === StatusCatalog::FULFILLMENT_RESERVED) $counts['reserved']++;
    elseif ($st === StatusCatalog::FULFILLMENT_OUT_OF_STOCK) $counts['oos']++;
    elseif ($st === StatusCatalog::FULFILLMENT_PREPARING) $counts['preparing']++;
    elseif ($st === StatusCatalog::FULFILLMENT_PREPARED) $counts['prepared']++;
    elseif ($st === StatusCatalog::FULFILLMENT_WAITING_CUSTOMER_PICKUP) $counts['waiting_pickup']++;
    elseif ($st === StatusCatalog::FULFILLMENT_READY_FOR_DELIVERY) $counts['ready_delivery']++;
}

$checkCache = [];
foreach ($activeOrders as $o) {
    $checkCache[(int) $o['id']] = FulfillmentService::checkItems($pdo, (int) $o['id']);
}

$toneClass = ['success' => 'fin-badge-green', 'warning' => 'fin-badge-orange', 'danger' => 'fin-badge-red', 'info' => 'fin-badge-blue', 'neutral' => 'fin-badge-gray'];
$toneColor = ['success' => '#16a34a', 'warning' => '#d97706', 'danger' => '#dc2626', 'info' => '#2563eb', 'neutral' => '#94a3b8'];

function ofBadge(array $toneClass, string $workflow, ?string $status): string
{
    $tone = StatusCatalog::statusTone($workflow, $status);
    $cls = $toneClass[$tone] ?? 'fin-badge-gray';
    return '<span class="fin-badge ' . $cls . '">' . htmlspecialchars(StatusCatalog::statusLabel($workflow, $status)) . '</span>';
}

function ofFulfStatus(array $o): string
{
    return ($o['fulfillment_status'] ?? null) ?: StatusCatalog::FULFILLMENT_WAITING_STOCK_CHECK;
}

function ofIsPickup(array $o): bool
{
    return ($o['fulfillment_method'] ?? '') === StatusCatalog::FULFILLMENT_METHOD_PICKUP;
}

function ofIsNewOrder(array $o): bool
{
    return ($o['fulfillment_status'] ?? null) === null;
}

function ofDeliveryLabel(?string $s): string
{
    $map = [
        'assigned' => 'Assigned',
        'accepted' => 'Accepted',
        'picked_up' => 'Picked Up',
        'out_for_delivery' => 'Out for Delivery',
        'pod_pending' => 'POD Pending',
        'delivered' => 'Delivered',
        'failed' => 'Failed',
        'returned' => 'Returned',
    ];
    return ($s === null || $s === '') ? '—' : ($map[$s] ?? ucwords(str_replace('_', ' ', $s)));
}

/**
 * Progress steps derived ONLY from real customer_orders columns/statuses.
 * No invented statuses; a step is "done" only when the underlying data says so.
 */
function ofSteps(array $o): array
{
    $st = ofFulfStatus($o);
    $raw = $o['fulfillment_status'] ?? null;

    $steps = [
        ['key' => 'new_order', 'label' => 'New Order'],
        ['key' => 'stock_check', 'label' => 'Stock Check'],
        ['key' => 'pick_items', 'label' => 'Pick Items'],
        ['key' => 'verify_qty', 'label' => 'Verify Quantity'],
        ['key' => 'pack_prepare', 'label' => 'Pack & Prepare'],
        ['key' => 'mark_prepared', 'label' => 'Mark Prepared'],
    ];

    $pastStockCheck  = ['ready_to_prepare','out_of_stock','reserved','preparing','prepared','paid','credit_approved','waiting_customer_pickup','ready_for_delivery','fulfillment_completed'];
    $pastPickItems   = ['reserved','preparing','prepared','paid','credit_approved','waiting_customer_pickup','ready_for_delivery','fulfillment_completed'];
    $pastVerifyQty   = ['preparing','prepared','paid','credit_approved','waiting_customer_pickup','ready_for_delivery','fulfillment_completed'];
    $pastMarkPrepared = ['prepared','paid','credit_approved','waiting_customer_pickup','ready_for_delivery','fulfillment_completed'];

    $done = [
        'new_order'    => true,
        'stock_check'  => !empty($o['stock_verified_at']) || in_array($st, $pastStockCheck, true),
        'pick_items'   => ((int) ($o['reserved_quantity'] ?? 0) > 0) || in_array($st, $pastPickItems, true),
        'verify_qty'   => in_array($st, $pastVerifyQty, true),
        'pack_prepare' => in_array($st, $pastMarkPrepared, true),
        'mark_prepared'=> in_array($st, $pastMarkPrepared, true),
    ];

    $current = 0;
    foreach ($steps as $i => $s) {
        $steps[$i]['done'] = !empty($done[$s['key']]);
        $steps[$i]['current'] = false;
        if (empty($done[$s['key']]) && $current === 0) {
            $steps[$i]['current'] = true;
            $current = 1;
        }
        $steps[$i]['idx'] = $i + 1;
    }
    return $steps;
}

/** Current-state message generated from the real DB state of the order. */
function ofState(array $o, array $check): array
{
    $st = ofFulfStatus($o);
    $paid = (int) ($o['payment_confirmed'] ?? 0) === 1;
    $dSt = $o['delivery_status'] ?? null;
    $missing = (int) ($check['missing_total'] ?? 0);
    $isPickup = ofIsPickup($o);

    switch ($st) {
        case StatusCatalog::FULFILLMENT_WAITING_STOCK_CHECK:
            return ['info', 'New order — ready for stock check'];
        case StatusCatalog::FULFILLMENT_OUT_OF_STOCK:
            return ['danger', "Stock check found {$missing} unit(s) missing — re-check stock or cancel fulfillment"];
        case StatusCatalog::FULFILLMENT_READY_TO_PREPARE:
            return ['success', 'Stock verified — order ready for preparation'];
        case StatusCatalog::FULFILLMENT_RESERVED:
            return ['info', 'Stock reserved — ready for pick & pack'];
        case StatusCatalog::FULFILLMENT_PREPARING:
            return ['info', 'Picking & packing in progress'];
        case StatusCatalog::FULFILLMENT_PREPARED:
            return $paid ? ['success', 'Payment confirmed — order ready to route'] : ['warning', 'Awaiting Finance payment confirmation'];
        case StatusCatalog::FULFILLMENT_PAID:
        case StatusCatalog::FULFILLMENT_CREDIT_APPROVED:
            return ['success', 'Payment confirmed — order ready to route'];
        case StatusCatalog::FULFILLMENT_WAITING_CUSTOMER_PICKUP:
            return ['warning', 'Order ready for customer pickup — awaiting hand-over'];
        case StatusCatalog::FULFILLMENT_READY_FOR_DELIVERY:
            if (in_array($dSt, ['assigned', 'accepted'], true)) return ['warning', 'Driver assigned — waiting for pickup'];
            if ($dSt === 'picked_up') return ['info', 'Order picked up by driver'];
            if ($dSt === 'out_for_delivery') return ['info', 'Out for delivery'];
            if ($dSt === 'pod_pending') return ['info', 'Delivery made — awaiting confirmation'];
            if ($dSt === 'delivered') return ['success', 'Order delivered'];
            return ['warning', 'Order prepared — ready for delivery assignment'];
        case StatusCatalog::FULFILLMENT_COMPLETED:
            return ['success', $isPickup ? 'Order handed over — complete' : 'Order delivered — complete'];
        case StatusCatalog::FULFILLMENT_CANCELLED:
            return ['danger', 'Fulfillment cancelled'];
        default:
            return ['neutral', 'Fulfillment pending'];
    }
}

/** Per-item availability indicator for the products table. */
function ofItemStatus(array $it): array
{
    $ord = (int) $it['ordered_qty'];
    $avail = (int) $it['available'];
    $mis = (int) $it['missing'];
    if ($mis >= $ord && $avail <= 0) return ['Out of Stock', 'of-badge-red'];
    if ($mis > 0) return ['Partial', 'of-badge-orange'];
    return ['Available', 'of-badge-green'];
}

/**
 * Active, pickable batches for a product, FEFO ordered (earliest expiry
 * first). Only reuses existing tables (product_batches, warehouses) —
 * no new tables are created.
 */
function ofPickBatches(PDO $pdo, int $productId): array
{
    $stmt = $pdo->prepare("
        SELECT pb.id, pb.batch_number, pb.expiry_date, pb.quantity AS batch_qty,
               pb.warehouse_id,
               CASE WHEN pb.warehouse_id IS NULL THEN 'Unassigned Warehouse'
                    ELSE COALESCE(w.name, CONCAT('Warehouse #', pb.warehouse_id)) END AS warehouse_name
        FROM product_batches pb
        LEFT JOIN warehouses w ON w.id = pb.warehouse_id
        WHERE pb.product_id = :pid
          AND pb.status = 'active'
          AND pb.quantity > 0
          AND (pb.expiry_date IS NULL OR pb.expiry_date >= CURDATE())
        ORDER BY (pb.expiry_date IS NULL) ASC, pb.expiry_date ASC, pb.id ASC
    ");
    $stmt->execute(['pid' => $productId]);
    $rows = $stmt->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'batch_id' => (int) $r['id'],
            'batch_number' => $r['batch_number'],
            'expiry_date' => $r['expiry_date'],
            'batch_qty' => (int) $r['batch_qty'],
            'warehouse_id' => (int) $r['warehouse_id'],
            'warehouse_name' => $r['warehouse_name'],
        ];
    }
    return $out;
}

/** FEFO pick default: distribute ordered qty across batches, earliest first. */
function ofFEfoPlan(array $batches, int $ordered): array
{
    $plan = [];
    $remaining = $ordered;
    foreach ($batches as $b) {
        $pick = min($b['batch_qty'], $remaining);
        $plan[] = [
            'batch_id' => $b['batch_id'],
            'batch_number' => $b['batch_number'],
            'expiry_date' => $b['expiry_date'],
            'warehouse_id' => $b['warehouse_id'],
            'warehouse_name' => $b['warehouse_name'],
            'batch_qty' => $b['batch_qty'],
            'pick' => $pick,
        ];
        $remaining -= $pick;
        if ($remaining <= 0) break;
    }
    return ['rows' => $plan, 'short' => max($remaining, 0)];
}
?>
<?= finCss() ?>
<style>
.of-card{background:var(--fin-card);border:1px solid var(--fin-border);border-radius:12px;padding:16px;box-shadow:var(--fin-shadow);margin-bottom:16px}
.of-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;gap:8px}
.of-head h2{font-size:.95rem;margin:0}
.of-tabs{display:flex;gap:8px;overflow-x:auto;padding-bottom:10px;margin-bottom:14px;border-bottom:1px solid var(--fin-border);scrollbar-width:thin}
.of-tab{flex:0 0 auto;display:flex;flex-direction:column;align-items:flex-start;gap:3px;min-width:150px;max-width:220px;padding:10px 12px;border-radius:10px;border:1px solid var(--fin-border);background:#fff;cursor:pointer;text-align:left;font-family:inherit;transition:all .15s}
.of-tab:hover{border-color:#94a3b8}
.of-tab.active{border-color:var(--fin-blue);background:#eff6ff;box-shadow:inset 0 0 0 1px var(--fin-blue)}
.of-tab-id{font-weight:800;font-size:.85rem;color:#0f172a}
.of-tab-cust{font-size:.72rem;color:var(--fin-gray);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
.of-tab-st{margin-top:2px}
.of-detail{display:none}
.of-detail.active{display:block}
.of-hero{display:flex;flex-direction:column;gap:12px;margin-bottom:14px}
.of-hero-title{font-weight:800;font-size:1.05rem;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.of-hero-cust{font-size:.85rem;color:var(--fin-gray)}
.of-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;font-size:.78rem}
.of-grid .lbl{color:var(--fin-gray);font-size:.68rem;display:block;font-weight:600;text-transform:uppercase;letter-spacing:.03em}
.of-grid .val{font-weight:700;color:#0f172a}
.of-progress{overflow-x:auto;padding:8px 0 4px;margin-bottom:16px;scrollbar-width:thin}
.of-progress-track{display:flex;min-width:max-content}
.of-step{display:flex;flex-direction:column;align-items:center;position:relative;min-width:96px}
.of-step:not(:last-child)::after{content:'';position:absolute;top:15px;left:calc(50% + 18px);width:calc(100% - 36px);height:2px;background:#e2e8f0}
.of-step.done:not(:last-child)::after{background:#bbf7d0}
.of-step-dot{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:800;background:#f1f5f9;color:#64748b;border:2px solid #e2e8f0;z-index:1}
.of-step.done .of-step-dot{background:#16a34a;border-color:#16a34a;color:#fff}
.of-step.current .of-step-dot{background:#eff6ff;border-color:var(--fin-blue);color:var(--fin-blue)}
.of-step-label{font-size:.68rem;color:var(--fin-gray);text-align:center;margin-top:6px;font-weight:600;line-height:1.3}
.of-step.done .of-step-label{color:#166534}
.of-step.current .of-step-label{color:var(--fin-blue);font-weight:700}
.of-sec{border:1px solid var(--fin-border);border-radius:10px;padding:14px;margin-bottom:12px;background:#fff}
.of-sec h3{font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#475569;margin:0 0 12px;display:flex;align-items:center;gap:6px}
.of-sec h3 i{color:var(--fin-blue)}
.of-items{width:100%;border-collapse:collapse;font-size:.78rem}
.of-items th{text-align:left;padding:6px 10px;background:#f8fafc;font-weight:700;color:#475569;border-bottom:2px solid var(--fin-border);font-size:.68rem;text-transform:uppercase;white-space:nowrap}
.of-items td{padding:6px 10px;border-bottom:1px solid #f1f5f9}
.of-missing{color:#dc2626;font-weight:700}
.of-ok{color:#16a34a;font-weight:700}
.of-item-badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:20px;font-size:.66rem;font-weight:700;white-space:nowrap}
.of-item-badge.of-badge-green{background:#dcfce7;color:#166534}
.of-item-badge.of-badge-orange{background:#ffedd5;color:#9a3412}
.of-item-badge.of-badge-red{background:#fee2e2;color:#991b1b}
.of-btn{display:inline-flex;align-items:center;gap:4px;padding:8px 16px;min-height:44px;border-radius:8px;border:1px solid var(--fin-border);background:#fff;color:#1e3a8a;font-size:.74rem;font-weight:600;text-decoration:none;cursor:pointer;white-space:nowrap}
.of-btn:hover{background:#eff6ff}
.of-btn-green{border-color:#bbf7d0;background:#f0fdf4;color:#166534}
.of-btn-green:hover{background:#dcfce7}
.of-btn-red{border-color:#fecaca;background:#fef2f2;color:#991b1b}
.of-btn-red:hover{background:#fee2e2}
.of-note{display:inline-flex;align-items:center;gap:5px;font-size:.76rem;font-weight:600}
.of-state{display:flex;align-items:center;gap:8px;padding:11px 14px;border-radius:10px;font-size:.8rem;font-weight:600;margin-top:4px}
.of-state.ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}
.of-state.warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e}
.of-state.danger{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.of-state.info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}
.of-state.neutral{background:#f8fafc;border:1px solid var(--fin-border);color:#475569}
.of-badge-new{display:inline-flex;align-items:center;gap:5px;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;padding:3px 10px;border-radius:20px;font-size:.68rem;font-weight:800;letter-spacing:.03em}
.of-new-pulse{animation:ofPulse 2s ease-in-out infinite}
@keyframes ofPulse{0%,100%{box-shadow:0 0 0 0 rgba(34,197,94,.35)}50%{box-shadow:0 0 0 6px rgba(34,197,94,0)}}
.of-summary{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding-bottom:12px;margin-bottom:12px;border-bottom:1px solid var(--fin-border)}
.of-summary-main{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.of-summary-id{font-weight:800;font-size:1rem;color:#0f172a}
.of-summary-cust{font-size:.8rem;color:var(--fin-gray)}
.of-summary-total{font-size:.85rem;color:#0f172a}
.of-summary-total b{color:var(--fin-blue)}
.of-acc{border:1px solid var(--fin-border);border-radius:10px;margin-bottom:12px;background:#fff;overflow:hidden}
.of-acc>summary{list-style:none;cursor:pointer;display:flex;align-items:center;gap:8px;padding:12px 14px;font-size:.8rem;font-weight:800;color:#334155;background:#f8fafc;user-select:none}
.of-acc>summary::-webkit-details-marker{display:none}
.of-acc>summary .chev{margin-left:auto;transition:transform .18s;color:var(--fin-gray);font-size:.7rem}
.of-acc[open]>summary .chev{transform:rotate(180deg)}
.of-acc>summary:hover{background:#f1f5f9}
.of-acc-body{padding:14px;border-top:1px solid var(--fin-border)}
.of-sticky-bar{position:sticky;top:70px;z-index:20;display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;padding:10px 14px;margin:-4px 0 14px;background:rgba(255,255,255,.92);backdrop-filter:blur(4px);border:1px solid var(--fin-border);border-radius:12px;box-shadow:var(--fin-shadow)}
.of-sticky-bar .of-btn{min-height:42px;font-size:.8rem}
.of-sticky-bar .of-btn-primary{background:linear-gradient(135deg,#16a34a,#15803d);border-color:#15803d;color:#fff;font-weight:700;padding:8px 20px}
.of-sticky-bar .of-btn-primary:hover{background:linear-gradient(135deg,#15803d,#166534)}
@media(max-width:768px){.fin-stats{grid-template-columns:repeat(2,1fr)}}
</style>

<section class="fin-wrap">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:6px">
        <div>
            <h1><i class="fas fa-boxes-stacked" style="color:var(--fin-teal)"></i> Order Fulfillment</h1>
            <div class="fin-subtitle">Review, verify, prepare and track customer orders.</div>
        </div>
        <div style="display:flex;gap:8px">
            <span style="padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:.78rem;color:#64748b;background:#fff"><i class="fas fa-calendar-day" style="color:var(--fin-blue)"></i> <?= date('M d, Y') ?></span>
        </div>
    </div>

    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= $m['success'] ? 'success' : 'error' ?>">
            <i class="fas fa-<?= $m['success'] ? 'check-circle' : 'exclamation-triangle' ?>"></i> <?= htmlspecialchars($m['msg'] ?? $m['text'] ?? '') ?>
        </div>
    <?php endforeach; ?>

    <div class="of-card">
        <div class="of-head">
            <h2><i class="fas fa-user-check" style="color:var(--fin-blue)"></i> Salesperson Order Approvals (<?= count($spApprovals) ?>)</h2>
            <span class="sd-wf-count" style="display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:22px;padding:0 6px;border-radius:11px;font-size:.72rem;font-weight:800;background:#eef2ff;color:#4338ca"><?= count($spApprovals) ?></span>
        </div>
        <?php if (empty($spApprovals)): ?>
            <div class="fin-empty"><i class="fas fa-user-check"></i><div>No salesperson orders awaiting approval.</div></div>
        <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:10px">
                <?php foreach ($spApprovals as $sa): ?>
                    <?php $saStage = (string) $sa['approval_stage']; ?>
                    <?php $saBadge = match ($saStage) {
                        'admin' => ['Pending Admin Approval', 'fin-badge-red'],
                        'store' => ['Pending Store Manager Approval', 'fin-badge-orange'],
                        'finance' => ['Pending Finance Finalization', 'fin-badge-blue'],
                        default => ['Awaiting Approval', 'fin-badge-gray'],
                    }; ?>
                    <div style="border:1px solid var(--fin-border);border-radius:10px;padding:12px 14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;background:#fff">
                        <div>
                            <div style="font-weight:800;font-size:.86rem">Order #<?= (int) $sa['id'] ?> <span class="fin-badge <?= $saBadge[1] ?>"><?= htmlspecialchars($saBadge[0]) ?></span></div>
                            <div style="font-size:.76rem;color:var(--fin-gray);margin-top:2px">
                                <i class="fas fa-user"></i> <?= htmlspecialchars($sa['customer_name'] ?? 'Unknown Customer') ?>
                                <?php if (!empty($sa['salesperson_name'])): ?> &middot; <i class="fas fa-user-tie"></i> <?= htmlspecialchars($sa['salesperson_name']) ?><?php endif; ?>
                            </div>
                        </div>
                        <div style="text-align:right;font-size:.8rem">
                            <strong><?= fmt((float) $sa['total_amount']) ?></strong>
                            <div class="fin-badge fin-badge-gray" style="margin-top:4px"><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($sa['payment_method'] ?? 'cash')))) ?></div>
                        </div>
                        <?php if ($saStage === 'store' && hasPermission('order_fulfillment', 'edit')): ?>
                            <form method="post" style="display:inline;margin:0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="approve_salesperson">
                                <input type="hidden" name="order_id" value="<?= (int) $sa['id'] ?>">
                                <button type="submit" class="of-btn of-btn-green" onclick="return confirm('Approve order #<?= (int) $sa['id'] ?> and send it to Finance finalization?')"><i class="fas fa-check-double"></i> Approve (Finance)</button>
                            </form>
                        <?php elseif ($saStage === 'store'): ?>
                            <span class="of-note" style="color:var(--fin-gray)">Awaiting Store Manager approval</span>
                        <?php elseif ($saStage === 'admin'): ?>
                            <span class="of-note" style="color:var(--fin-gray)">Awaiting Admin approval</span>
                        <?php elseif ($saStage === 'finance'): ?>
                            <span class="of-note" style="color:var(--fin-gray)">Awaiting Finance finalization</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="fin-stats" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#d97706,#b45309)"><i class="fas fa-hourglass-half"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val"><?= $counts['waiting'] ?></div>
                <div class="fin-stat-label">New Orders</div>
            </div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#2563eb,#1d4ed8)"><i class="fas fa-clipboard-check"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val"><?= $counts['ready'] ?></div>
                <div class="fin-stat-label">Ready to Prepare</div>
            </div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#7c3aed,#6d28d9)"><i class="fas fa-lock"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val"><?= $counts['reserved'] ?></div>
                <div class="fin-stat-label">Reserved</div>
            </div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#ea580c,#c2410c)"><i class="fas fa-boxes-stacked"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val"><?= $counts['preparing'] ?></div>
                <div class="fin-stat-label">Preparing</div>
            </div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#059669,#047857)"><i class="fas fa-file-invoice-dollar"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val"><?= $counts['prepared'] ?></div>
                <div class="fin-stat-label">Waiting Finance</div>
            </div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#f59e0b,#b45309)"><i class="fas fa-store"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val"><?= $counts['waiting_pickup'] ?></div>
                <div class="fin-stat-label">Pickup Ready</div>
            </div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#0d9488,#0f766e)"><i class="fas fa-truck"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val"><?= $counts['ready_delivery'] ?></div>
                <div class="fin-stat-label">Delivery Ready</div>
            </div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#dc2626,#b91c1c)"><i class="fas fa-box-open"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val"><?= $counts['oos'] ?></div>
                <div class="fin-stat-label">Out of Stock</div>
            </div>
        </div>
        <div class="fin-stat">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#16a34a,#15803d)"><i class="fas fa-check-double"></i></div>
            <div class="fin-stat-body">
                <div class="fin-stat-val"><?= count($completedOrders) ?></div>
                <div class="fin-stat-label">Completed</div>
            </div>
        </div>
    </div>

    <div class="of-card">
        <div class="of-head">
            <h2><i class="fas fa-list-ul" style="color:var(--fin-blue)"></i> Active Orders (<?= count($activeOrders) ?>)</h2>
            <span class="sd-wf-count" style="display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:22px;padding:0 6px;border-radius:11px;font-size:.72rem;font-weight:800;background:#eef2ff;color:#4338ca"><?= count($activeOrders) ?></span>
        </div>
        <?php if (empty($activeOrders)): ?>
            <div class="fin-empty"><i class="fas fa-check-circle" style="color:var(--fin-green)"></i><div>No orders available.</div><div style="font-size:.8rem;margin-top:4px">Newly approved orders will appear here for stock checking.</div></div>
        <?php else: ?>
            <div class="of-tabs">
                <?php foreach ($activeOrders as $i => $o): ?>
                    <?php $oid = (int) $o['id']; $st = ofFulfStatus($o); ?>
                    <button type="button" class="of-tab<?= $i === 0 ? ' active' : '' ?>" data-target="of-detail-<?= $oid ?>" onclick="ofSelect(this)">
                        <span class="of-tab-id">#<?= $oid ?></span>
                        <span class="of-tab-cust"><?= htmlspecialchars($o['customer_name'] ?? 'Unknown Customer') ?></span>
                        <span class="of-tab-st"><?php if (ofIsNewOrder($o)): ?><span class="of-badge-new of-new-pulse"><i class="fas fa-bolt" style="font-size:.6rem"></i> NEW ORDER</span><?php else: ?><?= ofBadge($toneClass, StatusCatalog::WORKFLOW_FULFILLMENT, $st) ?><?php endif; ?></span>
                    </button>
                <?php endforeach; ?>
            </div>

            <?php foreach ($activeOrders as $i => $o): ?>
                <?php
                    $oid = (int) $o['id'];
                    $st = ofFulfStatus($o);
                    $check = $checkCache[$oid];
                    $isPickup = ofIsPickup($o);
                    $paid = (int) ($o['payment_confirmed'] ?? 0) === 1;
                    $dSt = $o['delivery_status'] ?? null;
                    $steps = ofSteps($o);
                    $state = ofState($o, $check);
                    $stVerifying = in_array($st, [StatusCatalog::FULFILLMENT_WAITING_STOCK_CHECK, StatusCatalog::FULFILLMENT_OUT_OF_STOCK], true);
                    $cancellable = in_array($st, [StatusCatalog::FULFILLMENT_WAITING_STOCK_CHECK, StatusCatalog::FULFILLMENT_READY_TO_PREPARE, StatusCatalog::FULFILLMENT_RESERVED, StatusCatalog::FULFILLMENT_OUT_OF_STOCK, StatusCatalog::FULFILLMENT_PREPARING], true);
                ?>
                <div class="of-detail<?= $i === 0 ? ' active' : '' ?>" id="of-detail-<?= $oid ?>">

                    <div class="of-summary">
                        <div class="of-summary-main">
                            <span class="of-summary-id">#<?= $oid ?></span>
                            <span class="of-summary-cust"><i class="fas fa-user"></i> <?= htmlspecialchars($o['customer_name'] ?? 'Unknown Customer') ?></span>
                            <span class="of-summary-total">Total <b><?= fmt((float) ($o['total_amount'] ?? 0)) ?></b></span>
                        </div>
                        <?php if (ofIsNewOrder($o)): ?><span class="of-badge-new of-new-pulse"><i class="fas fa-bolt" style="font-size:.6rem"></i> NEW ORDER</span><?php else: ?><?= ofBadge($toneClass, StatusCatalog::WORKFLOW_FULFILLMENT, $st) ?><?php endif; ?>
                    </div>

                    <div class="of-progress">
                        <div class="of-progress-track">
                            <?php foreach ($steps as $s): ?>
                                <div class="of-step<?= $s['done'] ? ' done' : ($s['current'] ? ' current' : '') ?>">
                                    <div class="of-step-dot"><?= $s['done'] ? '<i class="fas fa-check"></i>' : $s['idx'] ?></div>
                                    <div class="of-step-label"><?= htmlspecialchars($s['label']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="of-sec">
                        <h3><i class="fas fa-boxes-stacked"></i> Products</h3>
                        <div style="overflow-x:auto">
                            <table class="of-items">
                                <thead><tr><th>Product</th><th>Ordered</th><th>Stock Available</th><th>Status</th></tr></thead>
                                <tbody>
                                    <?php $totOrdered = 0; $totAvail = 0; ?>
                                    <?php foreach ($check['items'] as $it): ?>
                                        <?php
                                            $totOrdered += (int) $it['ordered_qty'];
                                            $totAvail += (int) $it['available'];
                                            $ist = ofItemStatus($it);
                                        ?>
                                        <tr>
                                            <td style="font-weight:600"><?= htmlspecialchars($it['product_name']) ?></td>
                                            <td><?= (int) $it['ordered_qty'] ?></td>
                                            <td><?= (int) $it['available'] ?></td>
                                            <td><span class="of-item-badge <?= $ist[1] ?>"><?= $ist[0] ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div style="margin-top:16px">
                            <h3 style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#475569;margin:0 0 8px;display:flex;align-items:center;gap:6px"><i class="fas fa-warehouse" style="color:var(--fin-teal)"></i> Picking Plan (FEFO — earliest expiry first)</h3>
                            <div style="font-size:.72rem;color:var(--fin-gray);margin:0 0 8px"><i class="fas fa-arrows-turn-to-dots"></i> Recommended batch selection is calculated automatically: valid (non-expired) active batches, earliest expiry first, continuing to the next batch until the ordered quantity is fulfilled. Warehouse is reported per batch.</div>
                            <div id="pickPlan-<?= $oid ?>">
                                <?php foreach ($check['items'] as $it): ?>
                                    <?php
                                        $fplan = FulfillmentService::fefoPlan($pdo, (int) $it['product_id'], (int) $it['ordered_qty']);
                                        $ordQty = (int) $it['ordered_qty'];
                                        $pickTotal = array_sum(array_column($fplan['rows'], 'pick'));
                                        $planShort = $fplan['short'];
                                    ?>
                                    <div class="of-pick-item" data-order="<?= $oid ?>" data-product="<?= (int) $it['product_id'] ?>" data-ord="<?= $ordQty ?>">
                                        <div style="font-weight:700;font-size:.8rem;margin:6px 0 6px"><?= htmlspecialchars($it['product_name']) ?> <span style="color:var(--fin-gray);font-weight:600">— Ordered <?= $ordQty ?></span></div>
                                        <?php if (empty($fplan['rows'])): ?>
                                            <div class="of-missing" style="padding:6px 0">No valid (non-expired) active batches available for this product.</div>
                                        <?php else: ?>
                                        <table class="of-items">
                                            <thead><tr>
                                                <th>Qty to Pick</th><th>Warehouse</th><th>Batch #</th><th>Expiry</th><th>Available</th><th>FEFO</th>
                                            </tr></thead>
                                            <tbody>
                                                <?php $rowPos = 0; ?>
                                                <?php foreach ($fplan['rows'] as $brow): ?>
                                                    <tr class="of-pick-row" data-capa="<?= $brow['batch_qty'] ?>">
                                                        <td>
                                                            <input type="number" class="of-pick-qty" data-order="<?= $oid ?>" value="<?= $brow['pick'] ?>" min="0" max="<?= $brow['batch_qty'] ?>" data-capa="<?= $brow['batch_qty'] ?>" style="width:70px;padding:5px 8px;border:1px solid #cbd5e1;border-radius:6px;font-size:.78rem">
                                                        </td>
                                                        <td><?= htmlspecialchars($brow['warehouse_name'] ?? '—') ?></td>
                                                        <td><?= htmlspecialchars($brow['batch_number'] ?? '—') ?></td>
                                                        <td style="white-space:nowrap"><?= htmlspecialchars($brow['expiry_date'] ?? '—') ?></td>
                                                        <td><?= $brow['batch_qty'] ?></td>
                                                        <td><?= $rowPos === 0 ? '<span class="of-item-badge of-badge-green"><i class="fas fa-fire"></i> FEFO First</span>' : '' ?></td>
                                                    </tr>
                                                    <?php $rowPos++; ?>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        <div class="of-pick-total" style="margin-top:4px;font-size:.75rem;font-weight:600">
                                            <span>Pick sum: <span class="of-pick-sum" data-order="<?= $oid ?>"><?= $pickTotal ?></span> / <?= $ordQty ?></span>
                                            <?php if ($planShort > 0): ?>&nbsp;<span class="of-missing">— <?= $planShort ?> short across valid (non-expired) batches</span><?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <?php if (in_array($st, [StatusCatalog::FULFILLMENT_PREPARED, StatusCatalog::FULFILLMENT_PAID, StatusCatalog::FULFILLMENT_CREDIT_APPROVED, StatusCatalog::FULFILLMENT_WAITING_CUSTOMER_PICKUP, StatusCatalog::FULFILLMENT_READY_FOR_DELIVERY, StatusCatalog::FULFILLMENT_COMPLETED], true)): ?>
                        <?php $fefoRecorded = FulfillmentService::orderFefoAllocation($pdo, $oid); ?>
                        <?php if (!empty($fefoRecorded['items'])): ?>
                        <div style="margin-top:16px">
                            <h3 style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#475569;margin:0 0 8px;display:flex;align-items:center;gap:6px"><i class="fas fa-clipboard-check" style="color:var(--fin-green)"></i> Batch Allocation (recorded at Pack)</h3>
                            <div style="font-size:.72rem;color:var(--fin-gray);margin:0 0 8px">Every batch used to fulfil this order is captured below and written to the stock movement ledger (<em>reference: customer_order #<?= $oid ?></em>).</div>
                            <?php foreach ($fefoRecorded['items'] as $allItem): ?>
                                <div style="font-weight:700;font-size:.78rem;margin:10px 0 4px"><?= htmlspecialchars($allItem['product_name']) ?> <span style="color:var(--fin-gray);font-weight:600">— Ordered <?= (int) $allItem['ordered_qty'] ?></span></div>
                                <?php if (empty($allItem['batches'])): ?>
                                    <div class="of-missing" style="padding:4px 0">No batches recorded (no valid stock at pack time).</div>
                                <?php else: ?>
                                <table class="of-items">
                                    <thead><tr><th>Product</th><th>Batch #</th><th>Expiry</th><th>Qty to Pick</th><th>Warehouse</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($allItem['batches'] as $ab): ?>
                                            <tr>
                                                <td style="font-weight:600"><?= htmlspecialchars($allItem['product_name']) ?></td>
                                                <td><?= htmlspecialchars($ab['batch_number'] ?? '—') ?></td>
                                                <td style="white-space:nowrap"><?= htmlspecialchars($ab['expiry_date'] ?? '—') ?></td>
                                                <td style="font-weight:700"><?= (int) $ab['pick'] ?></td>
                                                <td><?= htmlspecialchars($ab['warehouse_name'] ?? '—') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <details class="of-acc">
                        <summary><i class="fas fa-clipboard-check" style="color:var(--fin-blue)"></i> Stock Verification <span class="chev"><i class="fas fa-chevron-down"></i></span></summary>
                        <div class="of-acc-body">
                        <div class="of-grid">
                            <div style="grid-column:1/-1"><span class="lbl">Verified By</span><span class="val"><?php $svName = trim((string)($o['stock_verified_name'] ?? '')); echo $svName !== '' ? 'Verified by: ' . htmlspecialchars($svName) . ' on ' . ($o['stock_verified_at'] ? date('M d, H:i', strtotime($o['stock_verified_at'])) : '—') : '—'; ?></span></div>
                            <div><span class="lbl">Total Ordered</span><span class="val"><?= (int) $totOrdered ?></span></div>
                            <div><span class="lbl">Total Available</span><span class="val"><?= (int) $totAvail ?></span></div>
                            <div><span class="lbl">Missing Items</span><span class="val"><?= (int) $check['missing_total'] > 0 ? '<span class="of-missing">' . (int) $check['missing_total'] . '</span>' : '<span class="of-ok">0</span>' ?></span></div>
                            <div><span class="lbl">Reserved Quantity</span><span class="val"><?= (int) ($o['reserved_quantity'] ?? 0) > 0 ? (int) $o['reserved_quantity'] : '—' ?></span></div>
                        </div>
                        <?php if ($stVerifying && hasPermission('order_fulfillment', 'edit')): ?>
                            <div style="margin-top:12px">
                                <form method="post" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="stock_check">
                                    <input type="hidden" name="order_id" value="<?= $oid ?>">
                                    <button type="submit" class="of-btn<?= $st === StatusCatalog::FULFILLMENT_OUT_OF_STOCK ? '' : ' of-btn-green' ?>" onclick="return confirm('Run stock check for order #<?= $oid ?>?')"><i class="fas fa-<?= $st === StatusCatalog::FULFILLMENT_OUT_OF_STOCK ? 'sync' : 'clipboard-check' ?>"></i> <?= $st === StatusCatalog::FULFILLMENT_OUT_OF_STOCK ? 'Re-check Stock' : 'Verify Stock' ?></button>
                                </form>
                            </div>
                        <?php endif; ?>
                        </div>
                    </details>

                    <details class="of-acc">
                        <summary><i class="fas fa-box-open" style="color:var(--fin-blue)"></i> Order Preparation <span class="chev"><i class="fas fa-chevron-down"></i></span></summary>
                        <div class="of-acc-body">
                        <div class="of-grid">
                            <div style="grid-column:1/-1"><span class="lbl">Prepared By</span><span class="val"><?php $pName = trim((string)($o['prepared_name'] ?? '')); echo $pName !== '' ? 'Prepared by: ' . htmlspecialchars($pName) . ' on ' . ($o['prepared_at'] ? date('M d, H:i', strtotime($o['prepared_at'])) : '—') : '—'; ?></span></div>
                            <div><span class="lbl">Preparation Status</span><span class="val"><?= ofBadge($toneClass, StatusCatalog::WORKFLOW_FULFILLMENT, $st) ?></span></div>
                        </div>
                        </div>
                    </details>

                    <details class="of-acc">
                        <summary><i class="fas fa-credit-card" style="color:var(--fin-blue)"></i> Payment Details <span class="chev"><i class="fas fa-chevron-down"></i></span></summary>
                        <div class="of-acc-body">
                        <?php if ($st === StatusCatalog::FULFILLMENT_PREPARED && !$paid): ?>
                            <div class="of-state warn"><i class="fas fa-hourglass-half"></i> Awaiting Finance payment confirmation</div>
                        <?php elseif ($paid): ?>
                            <div class="of-state ok"><i class="fas fa-check-circle"></i> Payment confirmed</div>
                        <?php endif; ?>
                        <div class="of-grid">
                            <div><span class="lbl">Payment Method</span><span class="val"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $o['payment_method'] ?? 'cash'))) ?></span></div>
                            <div><span class="lbl">Payment Status</span><span class="val"><?= $paid ? 'Confirmed' : 'Pending' ?></span></div>
                            <?php if (!empty($o['transaction_ref'])): ?><div><span class="lbl">Reference</span><span class="val"><?= htmlspecialchars($o['transaction_ref']) ?></span></div><?php endif; ?>
                            <?php if ($paid && !empty($o['payment_confirmed_name'])): ?><div style="grid-column:1/-1"><span class="lbl">Confirmed By</span><span class="val">Confirmed by: <?= htmlspecialchars($o['payment_confirmed_name']) ?> on <?= !empty($o['payment_confirmed_at']) ? date('M d, H:i', strtotime($o['payment_confirmed_at'])) : '—' ?></span></div><?php endif; ?>
                        </div>
                        </div>
                    </details>

                    <details class="of-acc">
                        <summary><i class="fas fa-truck" style="color:var(--fin-blue)"></i> Fulfillment &amp; Delivery <span class="chev"><i class="fas fa-chevron-down"></i></span></summary>
                        <div class="of-acc-body">
                        <div class="of-grid">
                            <div><span class="lbl">Fulfillment Lane</span><span class="val"><?= $isPickup ? 'Pickup' : 'Delivery' ?></span></div>
                            <?php if ($dSt): ?><div><span class="lbl">Delivery Status</span><span class="val"><?= ofDeliveryLabel($dSt) ?></span></div><?php endif; ?>
                            <?php if (!empty($o['driver_name'])): ?><div><span class="lbl">Assigned Driver</span><span class="val"><?= htmlspecialchars($o['driver_name']) ?></span></div><?php endif; ?>
                            <?php if (!empty($o['driver_phone'])): ?><div><span class="lbl">Driver Phone</span><span class="val"><?= htmlspecialchars($o['driver_phone']) ?></span></div><?php endif; ?>
                            <?php if (!empty($o['assigned_at'])): ?><div><span class="lbl">Assigned At</span><span class="val"><?= date('M d, H:i', strtotime($o['assigned_at'])) ?></span></div><?php endif; ?>
                            <?php if (!empty($o['picked_up_at'])): ?><div><span class="lbl">Picked Up At</span><span class="val"><?= date('M d, H:i', strtotime($o['picked_up_at'])) ?></span></div><?php endif; ?>
                            <?php if (!empty($o['dispatch_time'])): ?><div><span class="lbl">Out for Delivery At</span><span class="val"><?= date('M d, H:i', strtotime($o['dispatch_time'])) ?></span></div><?php endif; ?>
                            <?php if (!empty($o['delivered_at'])): ?><div><span class="lbl">Delivered At</span><span class="val"><?= date('M d, H:i', strtotime($o['delivered_at'])) ?></span></div><?php endif; ?>
                        </div>
                        <?php if ($st === StatusCatalog::FULFILLMENT_READY_FOR_DELIVERY && !$dSt): ?>
                            <div class="of-state info" style="margin-top:12px"><i class="fas fa-truck"></i> Awaiting delivery dispatch — assign a driver on the Delivery Dispatch page</div>
                        <?php endif; ?>
                        </div>
                    </details>

                    <div class="of-sticky-bar">
                        <span class="of-note" style="align-self:center"><i class="fas fa-bolt" style="color:var(--fin-blue)"></i> Actions</span>

                            <?php if ($smOnly): ?>
                                <?php if (in_array($st, [StatusCatalog::FULFILLMENT_READY_TO_PREPARE, StatusCatalog::FULFILLMENT_RESERVED, StatusCatalog::FULFILLMENT_PREPARING], true) && hasPermission('order_fulfillment', 'edit')): ?>
                                <form method="post" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="pack">
                                    <input type="hidden" name="order_id" value="<?= $oid ?>">
                                    <button type="submit" class="of-btn of-btn-primary" onclick="return confirm('Pack order #<?= $oid ?> and hand it off to Finance / Delivery?')"><i class="fas fa-check-double"></i> Mark Packed</button>
                                </form>
                                <?php endif; ?>
                            <?php else: ?>

                            <?php if ($st === StatusCatalog::FULFILLMENT_READY_TO_PREPARE): ?>
                                <?php if (hasPermission('order_fulfillment', 'edit')): ?>
                                <form method="post" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="reserve_stock">
                                    <input type="hidden" name="order_id" value="<?= $oid ?>">
                                    <button type="submit" class="of-btn of-btn-primary" onclick="return confirm('Reserve <?= (int) $check['reserved_total'] ?> unit(s) for order #<?= $oid ?>?')"><i class="fas fa-lock"></i> Reserve Stock</button>
                                </form>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($st === StatusCatalog::FULFILLMENT_RESERVED): ?>
                                <span class="of-note" style="color:#6d28d9"><i class="fas fa-lock"></i> <?= (int) $check['reserved_total'] ?> unit(s) reserved</span>
                                <?php if (hasPermission('order_fulfillment', 'edit')): ?>
                                <form method="post" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="start_preparing">
                                    <input type="hidden" name="order_id" value="<?= $oid ?>">
                                    <button type="submit" class="of-btn of-btn-primary" onclick="return confirm('Start pick &amp; pack for order #<?= $oid ?>?')"><i class="fas fa-boxes-stacked"></i> Pick &amp; Pack</button>
                                </form>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($st === StatusCatalog::FULFILLMENT_PREPARING): ?>
                                <?php if (hasPermission('order_fulfillment', 'edit')): ?>
                                <form method="post" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="mark_prepared">
                                    <input type="hidden" name="order_id" value="<?= $oid ?>">
                                    <button type="submit" class="of-btn of-btn-primary" onclick="return confirm('Mark order #<?= $oid ?> as prepared and send to finance?')"><i class="fas fa-check-double"></i> Mark Prepared</button>
                                </form>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if (in_array($st, [StatusCatalog::FULFILLMENT_PAID, StatusCatalog::FULFILLMENT_CREDIT_APPROVED], true)): ?>
                                <?php if ($isPickup): ?>
                                    <?php if (hasPermission('order_fulfillment', 'edit')): ?>
                                    <form method="post" style="display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="mark_waiting_pickup">
                                        <input type="hidden" name="order_id" value="<?= $oid ?>">
                                        <button type="submit" class="of-btn of-btn-primary"><i class="fas fa-store"></i> Route to Customer Pickup</button>
                                    </form>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if (hasPermission('order_fulfillment', 'edit')): ?>
                                    <form method="post" style="display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="ready_for_delivery">
                                        <input type="hidden" name="order_id" value="<?= $oid ?>">
                                        <button type="submit" class="of-btn of-btn-primary"><i class="fas fa-truck"></i> Mark Ready for Delivery</button>
                                    </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($st === StatusCatalog::FULFILLMENT_WAITING_CUSTOMER_PICKUP): ?>
                                <span class="of-note" style="color:#92400e"><i class="fas fa-store"></i> Waiting customer pickup</span>
                                <?php if (hasPermission('order_fulfillment', 'edit')): ?>
                                <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="hand_over_pickup">
                                    <input type="hidden" name="order_id" value="<?= $oid ?>">
                                    <div>
                                        <label style="font-size:.68rem;color:var(--fin-gray);font-weight:600;display:block">Received By</label>
                                        <input type="text" name="received_by" placeholder="Customer / collector name" style="padding:7px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:.78rem">
                                    </div>
                                    <div>
                                        <label style="font-size:.68rem;color:var(--fin-gray);font-weight:600;display:block">Signature / ID</label>
                                        <input type="text" name="pickup_signature" placeholder="Signature or ID no." style="padding:7px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:.78rem">
                                    </div>
                                    <button type="submit" class="of-btn of-btn-green" onclick="return confirm('Hand order #<?= $oid ?> over at pickup? This closes the invoice.')"><i class="fas fa-hand-holding-heart"></i> Hand Over at Pickup</button>
                                </form>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($st === StatusCatalog::FULFILLMENT_PREPARED && $paid): ?>
                                <span class="of-note" style="color:#166534"><i class="fas fa-hourglass-half"></i> Payment confirmed — routing in progress</span>
                            <?php endif; ?>

                            <?php if ($st === StatusCatalog::FULFILLMENT_READY_FOR_DELIVERY && $dSt === null): ?>
                                <span class="of-note" style="color:#0f766e"><i class="fas fa-truck"></i> Awaiting delivery dispatch</span>
                            <?php endif; ?>

                            <?php if ($cancellable): ?>
                                <?php if (hasPermission('order_fulfillment', 'edit')): ?>
                                <form method="post" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="reject_fulfillment">
                                    <input type="hidden" name="order_id" value="<?= $oid ?>">
                                    <button type="submit" class="of-btn of-btn-red" onclick="return confirm('Cancel fulfillment for order #<?= $oid ?>?')"><i class="fas fa-times"></i> Cancel</button>
                                </form>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php endif; // !$smOnly — full multi-step chain for non-Store-Manager roles ?>

                    </div>

                    <div class="of-state <?= $state[0] ?>"><i class="fas fa-<?= $state[0] === 'danger' ? 'exclamation-triangle' : ($state[0] === 'success' ? 'check-circle' : 'info-circle') ?>"></i> <?= htmlspecialchars($state[1]) ?></div>

                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="of-card">
        <div class="of-head">
            <h2><i class="fas fa-check-double" style="color:var(--fin-green)"></i> Completed Orders (<?= count($completedOrders) ?>)</h2>
        </div>
        <?php if (empty($completedOrders)): ?>
            <div class="fin-empty"><i class="fas fa-inbox"></i><div>No completed orders yet.</div></div>
        <?php else: ?>
            <div style="overflow-x:auto">
                <table class="fin-table">
                    <thead><tr><th>Order #</th><th>Customer</th><th>Total</th><th>Lane</th><th>Prepared By</th><th>Prepared At</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($completedOrders as $o): ?>
                            <tr>
                                <td style="font-weight:700">#<?= (int) $o['id'] ?></td>
                                <td style="font-weight:600"><?= htmlspecialchars($o['customer_name'] ?? '—') ?></td>
                                <td><?= fmt((float) $o['total_amount']) ?></td>
                                <td><?= ($o['fulfillment_method'] ?? '') === StatusCatalog::FULFILLMENT_METHOD_PICKUP ? 'Pickup' : 'Delivery' ?></td>
                                <td style="font-size:.8rem"><?= htmlspecialchars($o['prepared_name'] ?? '—') ?></td>
                                <td style="font-size:.78rem;color:var(--fin-gray)"><?= $o['prepared_at'] ? date('M d, Y H:i', strtotime($o['prepared_at'])) : '—' ?></td>
                                <td><?= ofBadge($toneClass, StatusCatalog::WORKFLOW_FULFILLMENT, StatusCatalog::FULFILLMENT_COMPLETED) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
<script>
function ofSelect(btn){
    var target = btn.getAttribute('data-target');
    document.querySelectorAll('.of-tab').forEach(function(t){ t.classList.remove('active'); });
    document.querySelectorAll('.of-detail').forEach(function(p){ p.classList.remove('active'); });
    btn.classList.add('active');
    var el = document.getElementById(target);
    if (el) el.classList.add('active');
}

var OF_PICK = {
    clampEach: function(input, order){
        var row = input.closest('.of-pick-row');
        var capa = parseInt(row.getAttribute('data-capa') || 0, 10);
        var val = parseInt(input.value || 0, 10);
        if (isNaN(val) || val < 0) { input.value = 0; val = 0; }
        if (val > capa) { input.value = capa; val = capa; }
        var item = input.closest('.of-pick-item');
        var ordered = parseInt(item.getAttribute('data-ord') || 0, 10);
        var sum = 0;
        item.querySelectorAll('.of-pick-qty').forEach(function(o){ sum += parseInt(o.value || 0, 10); });
        if (sum > ordered) {
            input.value = Math.max(capa - (sum - val), 0);
        }
        OF_PICK.recalc(item);
    },
    recalc: function(item){
        var ordered = parseInt(item.getAttribute('data-ord') || 0, 10);
        var sum = 0;
        item.querySelectorAll('.of-pick-qty').forEach(function(o){ sum += parseInt(o.value || 0, 10); });
        var sumEl = item.querySelector('.of-pick-sum');
        if (sumEl) {
            sumEl.textContent = sum;
            sumEl.style.color = sum === ordered ? '#16a34a' : (sum > ordered ? '#dc2626' : '#d97706');
        }
    }
};
document.addEventListener('input', function(e){
    if (e.target && e.target.classList && e.target.classList.contains('of-pick-qty')) {
        OF_PICK.clampEach(e.target);
    }
});
document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('.of-pick-item').forEach(function(item){ OF_PICK.recalc(item); });
});
</script>
<?= finJs() ?>
