<?php
/**
 * FulfillmentService
 *
 * Store manager order fulfillment pipeline:
 *   Stock Check -> Ready to Prepare -> Reserved -> Preparing -> Prepared
 *   (Waiting Finance) -> Paid | Credit Approved -> Waiting Customer Pickup |
 *   Ready for Delivery -> Fulfillment Completed (pickup hand-over or delivery).
 * Status changes happen ONLY through WorkflowService; this service
 * orchestrates the store-side business rules around them.
 */
class FulfillmentService
{
    private static function engine(): void
    {
        require_once __DIR__ . '/../includes/workflow_engine.php';
    }

    public static function status(PDO $pdo, int $orderId): ?string
    {
        $stmt = $pdo->prepare('SELECT fulfillment_status FROM customer_orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : $v;
    }

    /** The fulfillment lane for an order: 'pickup' or 'delivery'. */
    public static function method(PDO $pdo, int $orderId): ?string
    {
        $stmt = $pdo->prepare('SELECT fulfillment_method FROM customer_orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
        $v = $stmt->fetchColumn();
        return ($v === false || $v === '') ? null : $v;
    }

    /**
     * Per-item availability for an order. Stock for the order was already
     * committed at approval time (deduct_from_batches), so the units set
     * aside for this order equal its ordered quantity; they are added back
     * to the current free stock to compute what this order may still use.
     */
    public static function checkItems(PDO $pdo, int $orderId): array
    {
        $stmt = $pdo->prepare("
            SELECT coi.product_id, coi.quantity AS ordered_qty, p.name AS product_name,
                   p.stock_quantity, p.reorder_level
            FROM customer_order_items coi
            JOIN products p ON p.id = coi.product_id
            WHERE coi.customer_order_id = :oid
            ORDER BY coi.id ASC
        ");
        $stmt->execute(['oid' => $orderId]);
        $rows = $stmt->fetchAll();
        $out = [];
        $missingTotal = 0;
        $reservedTotal = 0;
        foreach ($rows as $r) {
            $ordered = (int) $r['ordered_qty'];
            $committed = $ordered;
            $available = (int) $r['stock_quantity'] + $committed;
            $missing = max($ordered - $available, 0);
            $missingTotal += $missing;
            $reservedTotal += $ordered;
            $out[] = [
                'product_id' => (int) $r['product_id'],
                'product_name' => $r['product_name'],
                'ordered_qty' => $ordered,
                'stock_quantity' => (int) $r['stock_quantity'],
                'available' => $available,
                'missing' => $missing,
            ];
        }
        return [
            'items' => $out,
            'missing_total' => $missingTotal,
            'reserved_total' => $reservedTotal,
        ];
    }

    /**
     * Build a FEFO (First Expired, First Out) pick plan for a single product.
     *
     * - Considers the product's VALID batches only: status 'active', quantity > 0
     *   and expiry date not in the past (expired batches are excluded from normal
     *   customer fulfillment).
     * - Sorts valid batches by earliest expiry first (then id as a stable tiebreak).
     * - Distributes $needQty across batches: take as much as possible from the
     *   earliest-expiring batch, then continue to the next, until the requested
     *   quantity is fulfilled or the available (valid) stock is exhausted.
     * - Never recommends more than a batch's available quantity and never a
     *   negative pick, so over-picking is impossible.
     * - The recommended warehouse is carried on every row; if the whole order can
     *   be sourced from one warehouse's batches, only that warehouse is used.
     *
     * @return array{rows:array<int,array>, short:int, supply:int}
     */
    public static function fefoPlan(PDO $pdo, int $productId, int $needQty): array
    {
        $needQty = max((int) $needQty, 0);
        $stmt = $pdo->prepare("
            SELECT pb.id AS batch_id, pb.warehouse_id, pb.batch_number, pb.expiry_date,
                   pb.quantity AS batch_qty,
                   COALESCE(w.name, CONCAT('Warehouse #', COALESCE(pb.warehouse_id, 0))) AS warehouse_name
            FROM product_batches pb
            LEFT JOIN warehouses w ON w.id = pb.warehouse_id
            WHERE pb.product_id = :pid
              AND pb.status = 'active'
              AND pb.quantity > 0
              AND (pb.expiry_date IS NULL OR pb.expiry_date >= CURDATE())
            ORDER BY (pb.expiry_date IS NULL) ASC, pb.expiry_date ASC, pb.id ASC
        ");
        $stmt->execute(['pid' => $productId]);
        $batches = $stmt->fetchAll();

        // A product with NO batch rows at all is a legacy non-batch-tracked item.
        // Its sellable stock lives in products.stock_quantity (the path used by
        // deduct_from_batches for non-batch products at approval/sale time). The
        // FEFO plan must fall back to that quantity, otherwise such products can
        // never pass the Pack step even though real stock exists.
        if (empty($batches)) {
            $pStmt = $pdo->prepare('SELECT stock_quantity FROM products WHERE id = :id');
            $pStmt->execute(['id' => $productId]);
            $fallbackStock = (int) $pStmt->fetchColumn();
            $fallbackStock = max($fallbackStock, 0);
            $supply = $fallbackStock;
            $pick = min($fallbackStock, $needQty);
            $rows = $pick > 0 ? [[
                'product_id' => $productId,
                'batch_id' => null,
                'warehouse_id' => null,
                'warehouse_name' => 'General Stock',
                'batch_number' => null,
                'expiry_date' => null,
                'batch_qty' => $fallbackStock,
                'pick' => $pick,
            ]] : [];
            return ['rows' => $rows, 'short' => max($needQty - $pick, 0), 'supply' => $supply];
        }

        // Prefer to fulfil the whole need from a single warehouse if possible. If a
        // single warehouse holds enough valid stock, only its earliest batches are
        // recommended; otherwise the plan falls back to the global FEFO order and the
        // warehouse is reported on each batch row.
        $byWarehouse = [];
        foreach ($batches as $b) {
            $byWarehouse[(int) $b['warehouse_id']][] = $b;
        }
        $chosen = $batches;
        foreach ($byWarehouse as $wid => $wb) {
            $sum = 0;
            foreach ($wb as $b) $sum += (int) $b['batch_qty'];
            if ($sum >= $needQty && $needQty > 0) {
                $chosen = $wb;
                break;
            }
        }

        $rows = [];
        $remaining = $needQty;
        $supply = 0;
        foreach ($chosen as $b) {
            if ($remaining <= 0) break;
            $cap = (int) $b['batch_qty'];
            $supply += $cap;
            $pick = min($cap, $remaining);
            $remaining -= $pick;
            $rows[] = [
                'product_id' => $productId,
                'batch_id' => (int) $b['batch_id'],
                'warehouse_id' => (int) $b['warehouse_id'],
                'warehouse_name' => $b['warehouse_name'],
                'batch_number' => $b['batch_number'],
                'expiry_date' => $b['expiry_date'],
                'batch_qty' => $cap,
                'pick' => max($pick, 0),
            ];
        }
        return ['rows' => $rows, 'short' => max($remaining, 0), 'supply' => $supply];
    }

    /**
     * FEFO plan across every line of an order, keyed by product id.
     * Also carries the product name and ordered quantity for display.
     */
    public static function orderFefoPlans(PDO $pdo, int $orderId): array
    {
        $stmt = $pdo->prepare("
            SELECT coi.product_id, coi.quantity AS ordered_qty, p.name AS product_name
            FROM customer_order_items coi
            JOIN products p ON p.id = coi.product_id
            WHERE coi.customer_order_id = :oid
            ORDER BY coi.id ASC
        ");
        $stmt->execute(['oid' => $orderId]);
        $lines = $stmt->fetchAll();
        $plans = [];
        foreach ($lines as $line) {
            $plan = static::fefoPlan($pdo, (int) $line['product_id'], (int) $line['ordered_qty']);
            $plans[(int) $line['product_id']] = [
                'product_id' => (int) $line['product_id'],
                'product_name' => $line['product_name'],
                'ordered_qty' => (int) $line['ordered_qty'],
                'rows' => $plan['rows'],
                'short' => $plan['short'],
                'supply' => $plan['supply'],
            ];
        }
        return $plans;
    }

    /**
     * Read back the FEFO batch allocation recorded for an order at pack time.
     * Returns the recorded items (with per-batch rows) or an empty array if none
     * has been recorded yet.
     */
    public static function orderFefoAllocation(PDO $pdo, int $orderId): array
    {
        $stmt = $pdo->prepare('SELECT timeline_data FROM customer_orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
        $td = $stmt->fetchColumn();
        if (empty($td)) return [];
        $data = json_decode($td, true);
        if (!is_array($data) || empty($data['fefo_pack']['items'])) {
            return [];
        }
        return $data['fefo_pack'];
    }

    /**
     * Persist the FEFO batch allocation for an order so the chosen batches are
     * recorded against the order lines and an audit trail is written to
     * stock_movements (one row per batch used). Called once at pack time. It is a
     * no-op if the order has already been recorded (idempotent on page refresh).
     */
    private static function recordFefoAllocation(PDO $pdo, int $orderId, int $uid, array $plans): void
    {
        // Guard: never record twice for the same order.
        $guardStmt = $pdo->prepare('SELECT COUNT(*) FROM stock_movements WHERE reference_type = :rt AND reference_id = :rid AND reason LIKE :reason');
        $guardStmt->execute(['rt' => 'customer_order', 'rid' => $orderId, 'reason' => 'FEFO pack %']);
        if ((int) $guardStmt->fetchColumn() > 0) {
            return;
        }

        $lineStmt = $pdo->prepare('SELECT id, product_id FROM customer_order_items WHERE customer_order_id = :oid ORDER BY id ASC');
        $lineStmt->execute(['oid' => $orderId]);
        $lines = $lineStmt->fetchAll();

        $lineIdsByProduct = [];
        foreach ($lines as $l) {
            $lineIdsByProduct[(int) $l['product_id']] = (int) $l['id'];
        }

        $allocation = [];
        $movStmt = $pdo->prepare("
            INSERT INTO stock_movements
                (product_id, quantity_change, reason, reference_type, reference_id, batch_id, warehouse_id, created_at)
            VALUES (:pid, :qty, :reason, :rt, :rid, :bid, :wid, NOW())
        ");
        foreach ($plans as $prodId => $plan) {
            $itemAlloc = [];
            foreach ($plan['rows'] as $b) {
                if ($b['pick'] <= 0) continue;
                $itemAlloc[] = [
                    'product_id' => $prodId,
                    'batch_id' => $b['batch_id'],
                    'batch_number' => $b['batch_number'],
                    'expiry_date' => $b['expiry_date'],
                    'warehouse_id' => $b['warehouse_id'],
                    'warehouse_name' => $b['warehouse_name'],
                    'pick' => $b['pick'],
                ];
                $movStmt->execute([
                    'pid' => $prodId,
                    'qty' => -$b['pick'],
                    'reason' => 'FEFO pack order #' . $orderId . ' (batch ' . $b['batch_number'] . ')',
                    'rt' => 'customer_order',
                    'rid' => $orderId,
                    'bid' => $b['batch_id'],
                    'wid' => $b['warehouse_id'] ?: null,
                ]);
            }
            // Record the primary batch on the order line (single-column fallback;
            // the full multi-batch allocation is stored in the order timeline JSON).
            if (!empty($itemAlloc) && isset($lineIdsByProduct[$prodId])) {
                $first = $itemAlloc[0];
                $upd = $pdo->prepare('UPDATE customer_order_items SET batch_no = :bno, expiry_date = :exp WHERE id = :id');
                $upd->execute(['bno' => $first['batch_number'], 'exp' => $first['expiry_date'], 'id' => $lineIdsByProduct[$prodId]]);
            }
            $allocation[$prodId] = [
                'product_id' => $prodId,
                'product_name' => $plan['product_name'],
                'ordered_qty' => $plan['ordered_qty'],
                'batches' => $itemAlloc,
            ];
        }

        // Append the FEFO allocation to the order's existing timeline JSON.
        $tsStmt = $pdo->prepare('SELECT timeline_data FROM customer_orders WHERE id = :id');
        $tsStmt->execute(['id' => $orderId]);
        $existing = $tsStmt->fetchColumn();
        $data = [];
        if (!empty($existing)) {
            $decoded = json_decode($existing, true);
            if (is_array($decoded)) $data = $decoded;
        }
        $data['fefo_pack'] = [
            'packed_by' => $uid,
            'packed_at' => date('Y-m-d H:i:s'),
            'items' => array_values($allocation),
        ];
        $pdo->prepare('UPDATE customer_orders SET timeline_data = :td WHERE id = :id')
            ->execute(['td' => json_encode($data, JSON_UNESCAPED_UNICODE), 'id' => $orderId]);
    }

    /**
     * Start the stock check for an approved order. Records who/when and
     * sets Ready to Prepare (enough stock) or Out of Stock (missing qty).
     */
    public static function stockCheck(PDO $pdo, int $orderId, int $uid): array
    {
        static::engine();
        if ($orderId <= 0) {
            return ['success' => false, 'msg' => 'Invalid order.'];
        }
        WorkflowEngine::ensureStatusHistoryTable($pdo);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT id, status, fulfillment_status FROM customer_orders WHERE id = :id FOR UPDATE");
            $stmt->execute(['id' => $orderId]);
            $co = $stmt->fetch();
            if (!$co || $co['status'] !== StatusCatalog::ORDER_APPROVED) {
                throw new ServiceException('Only approved orders can be stock checked.');
            }
            $current = $co['fulfillment_status'];
            if (!in_array($current, [StatusCatalog::FULFILLMENT_NOT_STARTED, StatusCatalog::FULFILLMENT_WAITING_STOCK_CHECK, StatusCatalog::FULFILLMENT_OUT_OF_STOCK], true)) {
                throw new ServiceException('Stock check is not allowed from the current fulfillment status.');
            }
            $check = static::checkItems($pdo, $orderId);
            $target = $check['missing_total'] > 0
                ? StatusCatalog::FULFILLMENT_OUT_OF_STOCK
                : StatusCatalog::FULFILLMENT_READY_TO_PREPARE;
            $notes = $check['missing_total'] > 0
                ? 'Stock check: ' . $check['missing_total'] . ' unit(s) short across ' . count($check['items']) . ' item(s)'
                : 'Stock check passed for all ' . count($check['items']) . ' item(s)';
            // A not-started order enters the funnel (Waiting Stock Check) before
            // the outcome of the check is applied to it.
            if ($current === StatusCatalog::FULFILLMENT_NOT_STARTED) {
                $start = WorkflowService::transition($pdo, StatusCatalog::WORKFLOW_FULFILLMENT, $orderId, StatusCatalog::FULFILLMENT_WAITING_STOCK_CHECK, [
                    'skip_transaction' => true,
                    'changed_by' => $uid,
                    'notes' => 'Stock check started',
                ]);
                if (!$start['success']) {
                    throw new ServiceException($start['error']);
                }
            }
            $res = WorkflowService::transition($pdo, StatusCatalog::WORKFLOW_FULFILLMENT, $orderId, $target, [
                'skip_transaction' => true,
                'extra' => ['stock_verified_by' => $uid, 'stock_verified_at' => date('Y-m-d H:i:s')],
                'changed_by' => $uid,
                'notes' => $notes,
            ]);
            if (!$res['success']) {
                throw new ServiceException($res['error']);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            return ['success' => false, 'msg' => $e instanceof ServiceException ? $e->getMessage() : 'Stock check failed: ' . $e->getMessage()];
        }
        return [
            'success' => true,
            'msg' => $check['missing_total'] > 0
                ? 'Stock check complete: ' . $check['missing_total'] . ' unit(s) missing. Order flagged as Out of Stock.'
                : 'Stock check passed. Order is Ready to Prepare.',
        ];
    }

    /**
     * Reserve the order quantities. Physical stock was already committed at
     * approval time, so this records the reservation for tracking and moves
     * the order to Reserved (next step: pick & pack).
     */
    public static function reserveStock(PDO $pdo, int $orderId, int $uid): array
    {
        if ($orderId <= 0) {
            return ['success' => false, 'msg' => 'Invalid order.'];
        }
        if (static::status($pdo, $orderId) !== StatusCatalog::FULFILLMENT_READY_TO_PREPARE) {
            return ['success' => false, 'msg' => 'Order must be Stock Verified (Ready to Prepare) before reserving stock.'];
        }
        $check = static::checkItems($pdo, $orderId);
        if ($check['missing_total'] > 0) {
            return ['success' => false, 'msg' => 'Cannot reserve: ' . $check['missing_total'] . ' unit(s) are short. Resolve stock or cancel fulfillment.'];
        }
        $reserved = $check['reserved_total'];
        $res = WorkflowService::transition($pdo, StatusCatalog::WORKFLOW_FULFILLMENT, $orderId, StatusCatalog::FULFILLMENT_RESERVED, [
            'extra' => ['reserved_quantity' => $reserved, 'reserved_status' => StatusCatalog::RESERVED_RESERVED],
            'changed_by' => $uid,
            'notes' => $reserved . ' unit(s) reserved for this order',
        ]);
        if (!$res['success']) {
            return ['success' => false, 'msg' => $res['error']];
        }
        return ['success' => true, 'msg' => "Order #{$orderId} stock reserved ({$reserved} unit(s)). Ready to pick & pack."];
    }

    /** Start picking & packing: Reserved -> Preparing. */
    public static function startPreparing(PDO $pdo, int $orderId, int $uid): array
    {
        if ($orderId <= 0) {
            return ['success' => false, 'msg' => 'Invalid order.'];
        }
        if (static::status($pdo, $orderId) !== StatusCatalog::FULFILLMENT_RESERVED) {
            return ['success' => false, 'msg' => 'Order must be Reserved before starting pick & pack.'];
        }
        $res = WorkflowService::transition($pdo, StatusCatalog::WORKFLOW_FULFILLMENT, $orderId, StatusCatalog::FULFILLMENT_PREPARING, [
            'changed_by' => $uid,
            'notes' => 'Picking & packing started',
        ]);
        if (!$res['success']) {
            return ['success' => false, 'msg' => $res['error']];
        }
        return ['success' => true, 'msg' => "Order #{$orderId} is being prepared (pick & pack)."];
    }

    /**
     * Convenience: reserve and immediately begin preparing in one call.
     * Kept for callers that advance the order a single step at a time.
     */
    public static function prepareOrder(PDO $pdo, int $orderId, int $uid): array
    {
        $res = static::reserveStock($pdo, $orderId, $uid);
        if (!$res['success']) {
            return $res;
        }
        $res2 = static::startPreparing($pdo, $orderId, $uid);
        if (!$res2['success']) {
            return $res2;
        }
        return ['success' => true, 'msg' => "Order #{$orderId} stock reserved and picking & packing started."];
    }

    /**
     * Mark the order as Prepared (Waiting Finance). Records who/when and
     * notifies the finance queue.
     */
    public static function markPrepared(PDO $pdo, int $orderId, int $uid): array
    {
        if ($orderId <= 0) {
            return ['success' => false, 'msg' => 'Invalid order.'];
        }
        if (static::status($pdo, $orderId) !== StatusCatalog::FULFILLMENT_PREPARING) {
            return ['success' => false, 'msg' => 'Order must be Preparing before it can be marked as prepared.'];
        }
        $check = static::checkItems($pdo, $orderId);
        $res = WorkflowService::transition($pdo, StatusCatalog::WORKFLOW_FULFILLMENT, $orderId, StatusCatalog::FULFILLMENT_PREPARED, [
            'extra' => [
                'prepared_by' => $uid,
                'prepared_at' => date('Y-m-d H:i:s'),
                'prepared_quantity' => $check['reserved_total'],
            ],
            'changed_by' => $uid,
            'notes' => 'Order prepared and handed to finance for payment confirmation',
        ]);
        if (!$res['success']) {
            return ['success' => false, 'msg' => $res['error']];
        }
        return static::finalizePrepared($pdo, $orderId, $uid, 'marked as Prepared');
    }

    /**
     * The Store Manager "Pack" action. Confirms the approved quantities were
     * physically picked/packed and advances the order to Prepared (handoff to
     * Finance / Delivery). Internally this runs the Reserved -> Preparing ->
     * Prepared chain atomically so the Store Manager only ever sees two actions:
     * Stock Check and Pack. Idempotent — an order already packed (Prepared or
     * beyond) is not re-transitioned, so a page refresh cannot duplicate work.
     */
    public static function pack(PDO $pdo, int $orderId, int $uid): array
    {
        if ($orderId <= 0) {
            return ['success' => false, 'msg' => 'Invalid order.'];
        }
        $current = static::status($pdo, $orderId);

        $alreadyDone = [
            StatusCatalog::FULFILLMENT_PREPARED,
            StatusCatalog::FULFILLMENT_PAID,
            StatusCatalog::FULFILLMENT_CREDIT_APPROVED,
            StatusCatalog::FULFILLMENT_WAITING_CUSTOMER_PICKUP,
            StatusCatalog::FULFILLMENT_READY_FOR_DELIVERY,
            StatusCatalog::FULFILLMENT_COMPLETED,
            StatusCatalog::FULFILLMENT_CANCELLED,
        ];
        if (in_array($current, $alreadyDone, true)) {
            // Repair path: if the order was packed but, for some reason, its FEFO
            // batch allocation was never recorded, record it now (idempotent).
            $plans = static::orderFefoPlans($pdo, $orderId);
            static::recordFefoAllocation($pdo, $orderId, $uid, $plans);
            return ['success' => true, 'msg' => "Order #{$orderId} is already packed — no further action needed."];
        }
        if (!in_array($current, [
            StatusCatalog::FULFILLMENT_READY_TO_PREPARE,
            StatusCatalog::FULFILLMENT_RESERVED,
            StatusCatalog::FULFILLMENT_PREPARING,
        ], true)) {
            return ['success' => false, 'msg' => 'Order must pass Stock Check before it can be Packed.'];
        }

        // Pack is only allowed once every ordered item has sufficient stock.
        $check = static::checkItems($pdo, $orderId);
        if ($check['missing_total'] > 0) {
            return ['success' => false, 'msg' => 'Cannot Pack: ' . $check['missing_total'] . ' unit(s) are short. The Stock Check must pass first.'];
        }
        $reserved = $check['reserved_total'];

        // FEFO validation: every line must be fillable from valid (non-expired)
        // active batches. If any line is short, packing must not proceed.
        $fefoPlans = static::orderFefoPlans($pdo, $orderId);
        foreach ($fefoPlans as $plan) {
            if ($plan['short'] > 0) {
                $short = $plan['short'];
                return ['success' => false, 'msg' => "Cannot Pack: {$plan['product_name']} is {$short} unit(s) short across valid (non-expired) batches. Restock or cancel fulfillment."];
            }
        }

        // Build the transition chain from the order's current state. Each step is
        // committed by WorkflowEngine within its own transaction (as in the
        // original pack()); we do not open an outer transaction here.
        $steps = [];
        if ($current === StatusCatalog::FULFILLMENT_READY_TO_PREPARE) {
            $steps[] = StatusCatalog::FULFILLMENT_RESERVED;
            $steps[] = StatusCatalog::FULFILLMENT_PREPARING;
        } elseif ($current === StatusCatalog::FULFILLMENT_RESERVED) {
            $steps[] = StatusCatalog::FULFILLMENT_PREPARING;
        }
        $steps[] = StatusCatalog::FULFILLMENT_PREPARED;

        foreach ($steps as $target) {
            $extra = [];
            if ($target === StatusCatalog::FULFILLMENT_RESERVED) {
                $extra = ['reserved_quantity' => $reserved, 'reserved_status' => StatusCatalog::RESERVED_RESERVED];
            } elseif ($target === StatusCatalog::FULFILLMENT_PREPARED) {
                $extra = [
                    'prepared_by' => $uid,
                    'prepared_at' => date('Y-m-d H:i:s'),
                    'prepared_quantity' => $reserved,
                    'picked_at' => date('Y-m-d H:i:s'),
                    'picker_id' => $uid,
                    'picking_status' => 'picked',
                ];
            }
            $res = WorkflowService::transition($pdo, StatusCatalog::WORKFLOW_FULFILLMENT, $orderId, $target, [
                'extra' => $extra,
                'changed_by' => $uid,
                'notes' => 'Packed by store manager (FEFO batch selection)',
            ]);
            if (!$res['success']) {
                return ['success' => false, 'msg' => $res['error']];
            }
        }

        // Record every batch used (FEFO) on the order lines and in stock_movements.
        // Idempotent — skips if already recorded.
        static::recordFefoAllocation($pdo, $orderId, $uid, $fefoPlans);

        return static::finalizePrepared($pdo, $orderId, $uid, 'packed (FEFO)');
    }

    /**
     * Shared post-"prepared" finalization: notifies the finance queue, and for
     * salesperson orders (payment already confirmed) auto-routes straight to
     * paid / credit_approved so the order never re-enters the finance queue.
     */
    private static function finalizePrepared(PDO $pdo, int $orderId, int $uid, string $verb): array
    {
        $stmt = $pdo->prepare('SELECT payment_confirmed, payment_method FROM customer_orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
        $co = $stmt->fetch();
        if ($co && (int) $co['payment_confirmed'] === 1) {
            $isCredit = ($co['payment_method'] ?? '') === 'credit';
            $auto = static::confirmPayment($pdo, $orderId, $uid, $isCredit);
            if (!$auto['success']) {
                return ['success' => false, 'msg' => $auto['msg']];
            }
            NotificationService::finance($pdo, null, $orderId,
                $isCredit ? 'Salesperson Credit Order Confirmed' : 'Salesperson Order Confirmed',
                $isCredit ? "Order #{$orderId} credit approved and routed." : "Order #{$orderId} paid and routed.", 'success');
            return ['success' => true, 'msg' => "Order #{$orderId} {$verb} and auto-confirmed (" . ($isCredit ? 'credit approved' : 'paid') . ")."];
        }
        NotificationService::finance($pdo, null, $orderId, 'Order Ready for Payment Confirmation', "Order #{$orderId} is prepared and awaiting finance approval.", 'info');
        return ['success' => true, 'msg' => "Order #{$orderId} {$verb}. It now appears in the Finance payment queue."];
    }

    /**
     * Store manager rejects/cancels fulfillment (e.g. permanently out of stock).
     */
    public static function rejectFulfillment(PDO $pdo, int $orderId, int $uid, string $reason = ''): array
    {
        if ($orderId <= 0) {
            return ['success' => false, 'msg' => 'Invalid order.'];
        }
        $current = static::status($pdo, $orderId);
        $allowed = [
            StatusCatalog::FULFILLMENT_NOT_STARTED,
            StatusCatalog::FULFILLMENT_WAITING_STOCK_CHECK,
            StatusCatalog::FULFILLMENT_READY_TO_PREPARE,
            StatusCatalog::FULFILLMENT_RESERVED,
            StatusCatalog::FULFILLMENT_OUT_OF_STOCK,
            StatusCatalog::FULFILLMENT_PREPARING,
        ];
        if (!in_array($current, $allowed, true)) {
            return ['success' => false, 'msg' => 'Fulfillment cannot be cancelled from its current status.'];
        }
        $res = WorkflowService::transition($pdo, StatusCatalog::WORKFLOW_FULFILLMENT, $orderId, StatusCatalog::FULFILLMENT_CANCELLED, [
            'extra' => ['reserved_status' => StatusCatalog::RESERVED_RELEASED],
            'changed_by' => $uid,
            'notes' => $reason !== '' ? 'Fulfillment cancelled: ' . $reason : 'Fulfillment cancelled by store manager',
        ]);
        if (!$res['success']) {
            return ['success' => false, 'msg' => $res['error']];
        }
        return ['success' => true, 'msg' => "Order #{$orderId} fulfillment cancelled."];
    }

    /**
     * Confirm a prepared order after finance approval. Cash orders become
     * 'paid'; credit orders become 'credit_approved'. The order is then
     * routed to Waiting Customer Pickup (pickup lane) or Ready for Delivery
     * (delivery lane). Idempotent once routed or completed.
     */
    public static function confirmPayment(PDO $pdo, int $orderId, int $uid, bool $isCredit = false): array
    {
        if ($orderId <= 0) {
            return ['success' => false, 'msg' => 'Invalid order.'];
        }
        $current = static::status($pdo, $orderId);
        $routed = [
            StatusCatalog::FULFILLMENT_WAITING_CUSTOMER_PICKUP,
            StatusCatalog::FULFILLMENT_READY_FOR_DELIVERY,
            StatusCatalog::FULFILLMENT_COMPLETED,
        ];
        if (in_array($current, $routed, true)) {
            return ['success' => true, 'msg' => 'Order already confirmed and routed.'];
        }
        if ($current !== StatusCatalog::FULFILLMENT_PREPARED) {
            return ['success' => false, 'msg' => 'Only prepared orders can be confirmed.'];
        }
        $target = $isCredit ? StatusCatalog::FULFILLMENT_CREDIT_APPROVED : StatusCatalog::FULFILLMENT_PAID;
        $res = WorkflowService::transition($pdo, StatusCatalog::WORKFLOW_FULFILLMENT, $orderId, $target, [
            'skip_transaction' => true,
            'changed_by' => $uid,
            'notes' => $isCredit ? 'Credit approved — order confirmed' : 'Payment confirmed',
        ]);
        if (!$res['success']) {
            return ['success' => false, 'msg' => $res['error']];
        }
        $method = static::method($pdo, $orderId);
        $isPickup = $method === StatusCatalog::FULFILLMENT_METHOD_PICKUP;
        $branch = $isPickup
            ? StatusCatalog::FULFILLMENT_WAITING_CUSTOMER_PICKUP
            : StatusCatalog::FULFILLMENT_READY_FOR_DELIVERY;
        $bres = WorkflowService::transition($pdo, StatusCatalog::WORKFLOW_FULFILLMENT, $orderId, $branch, [
            'skip_transaction' => true,
            'changed_by' => $uid,
            'notes' => $isPickup ? 'Order routed to customer pickup' : 'Order routed to delivery dispatch',
        ]);
        if (!$bres['success']) {
            return ['success' => false, 'msg' => $bres['error']];
        }
        return [
            'success' => true,
            'msg' => $isPickup
                ? "Order #{$orderId} confirmed and waiting customer pickup."
                : "Order #{$orderId} confirmed and ready for delivery.",
        ];
    }

    /** Backward-compatible alias for cash confirmations. */
    public static function confirmPaid(PDO $pdo, int $orderId, int $uid): array
    {
        return static::confirmPayment($pdo, $orderId, $uid, false);
    }

    /** Route a paid/credit-approved pickup order to Waiting Customer Pickup. */
    public static function markWaitingPickup(PDO $pdo, int $orderId, int $uid): array
    {
        if ($orderId <= 0) {
            return ['success' => false, 'msg' => 'Invalid order.'];
        }
        $current = static::status($pdo, $orderId);
        if (!in_array($current, [StatusCatalog::FULFILLMENT_PAID, StatusCatalog::FULFILLMENT_CREDIT_APPROVED], true)) {
            return ['success' => false, 'msg' => 'Order must be Paid or Credit Approved before routing to pickup.'];
        }
        if (static::method($pdo, $orderId) !== StatusCatalog::FULFILLMENT_METHOD_PICKUP) {
            return ['success' => false, 'msg' => 'This is a delivery order. Route it to delivery dispatch instead.'];
        }
        $res = WorkflowService::transition($pdo, StatusCatalog::WORKFLOW_FULFILLMENT, $orderId, StatusCatalog::FULFILLMENT_WAITING_CUSTOMER_PICKUP, [
            'skip_transaction' => true,
            'changed_by' => $uid,
            'notes' => 'Order routed to customer pickup',
        ]);
        if (!$res['success']) {
            return ['success' => false, 'msg' => $res['error']];
        }
        return ['success' => true, 'msg' => "Order #{$orderId} is waiting customer pickup."];
    }

    /** Route a paid/credit-approved delivery order to Ready for Delivery. */
    public static function readyForDelivery(PDO $pdo, int $orderId, int $uid): array
    {
        if ($orderId <= 0) {
            return ['success' => false, 'msg' => 'Invalid order.'];
        }
        $current = static::status($pdo, $orderId);
        if (!in_array($current, [StatusCatalog::FULFILLMENT_PAID, StatusCatalog::FULFILLMENT_CREDIT_APPROVED], true)) {
            return ['success' => false, 'msg' => 'Order must be Paid or Credit Approved before routing to delivery.'];
        }
        if (static::method($pdo, $orderId) !== StatusCatalog::FULFILLMENT_METHOD_DELIVERY) {
            return ['success' => false, 'msg' => 'This is a pickup order. Use the pickup hand-over process instead.'];
        }
        $res = WorkflowService::transition($pdo, StatusCatalog::WORKFLOW_FULFILLMENT, $orderId, StatusCatalog::FULFILLMENT_READY_FOR_DELIVERY, [
            'skip_transaction' => true,
            'changed_by' => $uid,
            'notes' => 'Order routed to delivery dispatch',
        ]);
        if (!$res['success']) {
            return ['success' => false, 'msg' => $res['error']];
        }
        return ['success' => true, 'msg' => "Order #{$orderId} is ready for delivery."];
    }

    /**
     * Hand the order over to the customer at pickup. Records pickup
     * time/staff/receiver/signature, closes the order invoice and
     * notifies the customer.
     */
    public static function handOverForPickup(PDO $pdo, int $orderId, int $uid, string $receivedBy = '', string $signature = ''): array
    {
        if ($orderId <= 0) {
            return ['success' => false, 'msg' => 'Invalid order.'];
        }
        if (static::status($pdo, $orderId) !== StatusCatalog::FULFILLMENT_WAITING_CUSTOMER_PICKUP) {
            return ['success' => false, 'msg' => 'Order must be waiting customer pickup before hand-over.'];
        }
        $stmt = $pdo->prepare('SELECT customer_id FROM customer_orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
        $customerId = (int) $stmt->fetchColumn();
        $res = WorkflowService::transition($pdo, StatusCatalog::WORKFLOW_FULFILLMENT, $orderId, StatusCatalog::FULFILLMENT_COMPLETED, [
            'skip_transaction' => true,
            'extra' => [
                'pickup_time' => date('Y-m-d H:i:s'),
                'pickup_by' => $uid,
                'received_by' => $receivedBy !== '' ? $receivedBy : null,
                'pickup_signature' => $signature !== '' ? $signature : null,
            ],
            'changed_by' => $uid,
            'notes' => $receivedBy !== '' ? 'Order handed over at pickup to ' . $receivedBy : 'Order handed over at customer pickup',
        ]);
        if (!$res['success']) {
            return ['success' => false, 'msg' => $res['error']];
        }
        static::closeOrderInvoice($pdo, $orderId, $uid);
        if ($customerId > 0) {
            NotificationService::order($pdo, $orderId, $customerId, 'Pickup Completed', "Thank you! Order #{$orderId} was handed over at pickup.", 'success');
        }
        return ['success' => true, 'msg' => "Order #{$orderId} handed over at pickup. Invoice closed."];
    }

    /** Close the order's invoice with a pickup-appropriate audit trail. */
    private static function closeOrderInvoice(PDO $pdo, int $orderId, int $uid): void
    {
        static::engine();
        require_once __DIR__ . '/../includes/delivery_helpers.php';
        $inv = delFindInvoiceForOrder($pdo, $orderId);
        if (!$inv || !WorkflowEngine::canTransition('invoice_fulfillment', $inv['status'], 'completed')) {
            return;
        }
        WorkflowEngine::transition($pdo, 'invoice_fulfillment', (int) $inv['id'], 'completed', [
            'extra' => ['completed_at' => date('Y-m-d H:i:s'), 'completed_by' => $uid],
            'changed_by' => $uid,
            'notes' => 'Pickup hand-over verified — invoice fulfilled',
        ]);
        delLog($pdo, $orderId, 'invoice_completed', $inv['status'], 'completed', $uid, 'Invoice marked completed after pickup hand-over');
    }

    /**
     * Mark fulfillment completed after a successful delivery. Called by the
     * delivery service once the delivery itself is confirmed.
     */
    public static function completeDeliveryFulfillment(PDO $pdo, int $orderId, ?int $uid = null): array
    {
        if ($orderId <= 0) {
            return ['success' => false, 'msg' => 'Invalid order.'];
        }
        $current = static::status($pdo, $orderId);
        if ($current === StatusCatalog::FULFILLMENT_COMPLETED) {
            return ['success' => true, 'msg' => 'Order fulfillment already completed.'];
        }
        if ($current !== StatusCatalog::FULFILLMENT_READY_FOR_DELIVERY) {
            return ['success' => false, 'msg' => 'Order must be Ready for Delivery before fulfillment completes.'];
        }
        $res = WorkflowService::transition($pdo, StatusCatalog::WORKFLOW_FULFILLMENT, $orderId, StatusCatalog::FULFILLMENT_COMPLETED, [
            'skip_transaction' => true,
            'changed_by' => $uid ?? 0,
            'notes' => 'Delivery confirmed — fulfillment completed',
        ]);
        if (!$res['success']) {
            return ['success' => false, 'msg' => $res['error']];
        }
        return ['success' => true, 'msg' => "Order #{$orderId} fulfillment completed after delivery."];
    }

    /** Orders waiting for the store manager: approved and not yet fulfilled. */
    public static function activeOrders(PDO $pdo): array
    {
        return $pdo->query("
            SELECT co.*, c.name AS customer_name, c.phone AS customer_phone,
                   COALESCE(u.full_name, u.username) AS stock_verified_name,
                   COALESCE(p.full_name, p.username) AS prepared_name,
                   COALESCE(pc.full_name, pc.username) AS payment_confirmed_name,
                   dp.phone AS driver_phone
            FROM customer_orders co
            LEFT JOIN customers c ON co.customer_id = c.id
            LEFT JOIN users u ON co.stock_verified_by = u.id
            LEFT JOIN users p ON co.prepared_by = p.id
            LEFT JOIN users pc ON co.payment_confirmed_by = pc.id
            LEFT JOIN driver_profiles dp ON co.delivery_staff_id = dp.id
            WHERE co.status = 'approved'
              AND COALESCE(co.fulfillment_status, 'waiting_stock_check') <> 'fulfillment_completed'
              AND COALESCE(co.fulfillment_status, 'waiting_stock_check') <> 'fulfillment_cancelled'
            ORDER BY co.created_at DESC, co.id DESC
        ")->fetchAll();
    }

    /** Pickup orders routed to the customer-pickup lane (awaiting hand-over). */
    public static function pickupReadyOrders(PDO $pdo): array
    {
        return $pdo->query("
            SELECT co.*, c.name AS customer_name, c.phone AS customer_phone
            FROM customer_orders co
            LEFT JOIN customers c ON co.customer_id = c.id
            WHERE co.fulfillment_status = 'waiting_customer_pickup'
            ORDER BY co.order_date ASC, co.id ASC
        ")->fetchAll();
    }

    /** Delivery orders routed to the dispatch queue (awaiting driver). */
    public static function deliveryReadyOrders(PDO $pdo): array
    {
        return $pdo->query("
            SELECT co.*, c.name AS customer_name, c.phone AS customer_phone
            FROM customer_orders co
            LEFT JOIN customers c ON co.customer_id = c.id
            WHERE co.fulfillment_status = 'ready_for_delivery'
            ORDER BY co.order_date ASC, co.id ASC
        ")->fetchAll();
    }

    /** Completed fulfillment orders (paid & handed to delivery). */
    public static function completedOrders(PDO $pdo, int $limit = 20): array
    {
        $stmt = $pdo->query("
            SELECT co.*, c.name AS customer_name,
                   COALESCE(p.full_name, p.username) AS prepared_name
            FROM customer_orders co
            LEFT JOIN customers c ON co.customer_id = c.id
            LEFT JOIN users p ON co.prepared_by = p.id
            WHERE co.fulfillment_status = 'fulfillment_completed'
            ORDER BY co.prepared_at DESC, co.id DESC
            LIMIT " . (int) $limit
        );
        return $stmt->fetchAll();
    }
}
