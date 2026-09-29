<?php
/**
 * WorkflowService
 *
 * THE single gateway for all status transitions and the business rules
 * that surround them. Pages must never:
 *   - call WorkflowEngine::transition directly
 *   - UPDATE order / invoice / payment / delivery status columns directly
 *   - create sales, invoices or stock deductions outside a service
 *
 * Business workflow methods:
 *   approveOrder, rejectOrder, cancelOrderForRefund, assignDelivery,
 *   markOutForDelivery, confirmPayment, confirmDelivery, completeInvoice,
 *   cancelOrder (purchase), returnOrder (sales).
 */
class WorkflowService
{
    private static function engine(): void
    {
        require_once __DIR__ . '/../includes/workflow_engine.php';
    }

    public static function canTransition(string $workflow, ?string $from, ?string $to): bool
    {
        static::engine();
        return WorkflowEngine::canTransition($workflow, $from, $to);
    }

    public static function history(PDO $pdo, string $workflow, int $entityId, int $limit = 50): array
    {
        static::engine();
        return WorkflowEngine::getStatusHistory($pdo, $workflow, $entityId, $limit);
    }

    /** Low-level guarded transition. Prefer the named business methods. */
    public static function transition(PDO $pdo, string $workflow, int $entityId, ?string $toStatus, array $options = []): array
    {
        static::engine();
        return WorkflowEngine::transition($pdo, $workflow, $entityId, $toStatus, $options);
    }

    public static function canApproveOrder(PDO $pdo, int $orderId): bool
    {
        $stmt = $pdo->prepare('SELECT status FROM customer_orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
        return static::canTransition('order', $stmt->fetchColumn() ?: null, 'approved');
    }

    /**
     * Approve a customer order in a single step: validates stock, creates the
     * sale + sale_items, deducts inventory, transitions the order to approved,
     * generates the invoice, and re-syncs credit metrics. Orders that follow
     * the staged salesperson approval chain (approval_stage IS NOT NULL) are
     * rejected here and must advance through approveSalespersonStage /
     * finalizeSalespersonOrder instead.
     */
    public static function approveOrder(PDO $pdo, int $orderId, int $uid, bool $overrideCredit = false): array
    {
        $stageCheck = $pdo->prepare('SELECT approval_stage FROM customer_orders WHERE id = :id');
        $stageCheck->execute(['id' => $orderId]);
        $stage = $stageCheck->fetchColumn();
        if ($stage !== null && $stage !== false) {
            throw new ServiceException('This order follows the staged approval chain (Admin -> Store Manager -> Finance) and cannot be approved in a single step.');
        }
        return static::finalizeApprovedOrder($pdo, $orderId, $uid, $overrideCredit);
    }

    /**
     * Finalize a fully approved order: create the sale + sale_items, deduct
     * inventory, mark the order approved, generate the invoice, set the credit
     * due date, and re-sync credit metrics. Runs inside one transaction.
     */
    private static function finalizeApprovedOrder(PDO $pdo, int $orderId, int $uid, bool $overrideCredit = false, bool $autoConfirmPayment = false): array
    {
        require_once __DIR__ . '/../includes/workflow_helpers.php';
        require_once __DIR__ . '/../includes/credit_helpers.php';
        require_once __DIR__ . '/../includes/stock_helper.php';
        static::engine();

        if (!static::canApproveOrder($pdo, $orderId)) {
            throw new ServiceException('Order cannot be approved from its current status.');
        }

        WorkflowEngine::ensureStatusHistoryTable($pdo);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM customer_orders WHERE id = :id AND status = 'pending' FOR UPDATE");
            $stmt->execute(['id' => $orderId]);
            $co = $stmt->fetch();
            if (!$co) {
                throw new ServiceException('Order not found or already processed.');
            }

            $itemsStmt = $pdo->prepare('SELECT product_id, quantity, unit_price FROM customer_order_items WHERE customer_order_id = :id');
            $itemsStmt->execute(['id' => $orderId]);
            $rows = $itemsStmt->fetchAll();
            if (empty($rows)) {
                throw new ServiceException('Order has no line items.');
            }

            $lineTotals = [];
            foreach ($rows as $row) {
                $pid = (int) $row['product_id'];
                $qty = (int) $row['quantity'];
                if ($pid <= 0 || $qty <= 0) continue;
                $lineTotals[] = ['product_id' => $pid, 'quantity' => $qty, 'unit_price' => (float) $row['unit_price']];
            }
            if (empty($lineTotals)) {
                throw new ServiceException('Invalid line items.');
            }

            $qtyByProduct = [];
            foreach ($lineTotals as $line) {
                $qtyByProduct[$line['product_id']] = ($qtyByProduct[$line['product_id']] ?? 0) + $line['quantity'];
            }

            $priceByProduct = [];
            foreach ($qtyByProduct as $productId => $needQty) {
                $pstmt = $pdo->prepare('SELECT price, stock_quantity FROM products WHERE id = :id FOR UPDATE');
                $pstmt->execute(['id' => $productId]);
                $prow = $pstmt->fetch();
                if (!$prow) {
                    throw new ServiceException('Product no longer exists.');
                }
                if ((int) $prow['stock_quantity'] < $needQty) {
                    throw new ServiceException('Insufficient stock to approve this order.');
                }
                $priceByProduct[$productId] = (float) $prow['price'];
            }

            $linesForInsert = [];
            foreach ($lineTotals as $line) {
                $linesForInsert[] = [
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $priceByProduct[$line['product_id']],
                ];
            }

            $total = 0;
            foreach ($linesForInsert as $line) {
                $total += $line['unit_price'] * $line['quantity'];
            }

            $customerId = (int) $co['customer_id'];

            // ERP credit validation
            if ($co['payment_method'] === 'credit') {
                $cv = CreditService::validateOrder($pdo, $customerId, $total, $overrideCredit, $orderId);
                if (!$cv['valid']) {
                    if (!empty($cv['override_rejected'])) {
                        throw new ServiceException($cv['msg']);
                    }
                    $detail = 'Credit Limit: ' . fmt($cv['credit_limit'])
                        . ', Outstanding: ' . fmt($cv['outstanding'])
                        . ', Available: ' . fmt($cv['available'])
                        . ', Requested: ' . fmt($cv['requested'])
                        . ', Exceeded by: ' . fmt($cv['exceeded']) . '.';
                    throw new ServiceException('Order blocked - ' . $detail . ' Only Finance Manager can override.');
                }
                if (!empty($cv['overridden'])) {
                    WorkflowEngine::logStatusChange($pdo, 'order', $orderId, 'pending', 'pending',
                        $uid, 'Credit limit overridden: ' . $cv['msg']);
                }
            }

            $saleDate = $co['order_date'];

            $insSale = $pdo->prepare('INSERT INTO sales (customer_id, sale_date, total_amount, created_at) VALUES (:customer, :sale_date, :total, NOW())');
            $insSale->execute(['customer' => $customerId, 'sale_date' => $saleDate, 'total' => $total]);
            $saleId = (int) $pdo->lastInsertId();

            $insertItem = $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, quantity, unit_price) VALUES (:sale_id, :product_id, :quantity, :unit_price)');
            foreach ($linesForInsert as $line) {
                $insertItem->execute([
                    'sale_id' => $saleId,
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                ]);
                deduct_from_batches($pdo, $line['product_id'], $line['quantity']);
            }

            $pdo->prepare("UPDATE customer_orders SET status = 'approved', approval_stage = NULL, sale_id = :sale_id, processed_at = NOW(), admin_note = NULL WHERE id = :id")
                ->execute(['sale_id' => $saleId, 'id' => $orderId]);
            WorkflowEngine::logStatusChange($pdo, 'order', $orderId, 'pending', 'approved', $uid, 'Order approved with sale #' . $saleId);

            // Generate invoice
            $invNo = InvoiceService::generateInvoiceNo($pdo, $orderId);
            $stmt = $pdo->prepare('SELECT delivery_address FROM customer_orders WHERE id = :id');
            $stmt->execute(['id' => $orderId]);
            $delAddr = $stmt->fetchColumn();

            // Set credit due date for credit sales so overdue tracking works out of the box.
            // The due date follows the payment terms of the agreement version the
            // customer accepted when placing the order; legacy orders without a
            // version fall back to the customer's profile terms.
            $creditDueDate = null;
            $agreementVersionId = null;
            if ($co['payment_method'] === 'credit') {
                $agreementVersionId = isset($co['agreement_version_id']) ? (int) $co['agreement_version_id'] : null;
                $terms = creditAgreementTermsForOrder($pdo, $agreementVersionId, $customerId);
                if ($terms > 0) {
                    $creditDueDate = date('Y-m-d', strtotime($saleDate . ' + ' . $terms . ' days'));
                }
            }

            $insInv = $pdo->prepare('INSERT INTO invoices (invoice_no, customer_order_id, customer_id, total_amount, outstanding_balance, payment_status, agreement_version_id, delivery_address, credit_due_date, generated_at) VALUES (:inv, :oid, :cid, :total, :total2, "unpaid", :agv, :da, :due, NOW())');
            $insInv->execute(['inv' => $invNo, 'oid' => $orderId, 'cid' => $customerId, 'total' => $total, 'total2' => $total, 'agv' => $agreementVersionId, 'da' => $delAddr, 'due' => $creditDueDate]);
            $invoiceId = (int) $pdo->lastInsertId();

            // Salesperson chain: Finance approval is also the payment-confirmation
            // moment, so the order is marked paid here and the invoice settled for
            // non-credit methods. Credit invoices stay open (outstanding_balance
            // is what creditRecalcUsed counts), matching the portal credit flow.
            if ($autoConfirmPayment) {
                $pdo->prepare("UPDATE customer_orders SET payment_confirmed = 1, payment_confirmed_at = NOW(), payment_confirmed_by = :uid, payment_notes = 'Payment received via salesperson order', verified_amount = :total, verified_by = :uid2, verified_at = NOW() WHERE id = :id")
                    ->execute(['uid' => $uid, 'total' => $total, 'uid2' => $uid, 'id' => $orderId]);
                if (($co['payment_method'] ?? '') !== 'credit') {
                    recordInvoicePayment($pdo, $invoiceId, $total, $co['payment_method'], null, null, 'Payment received - salesperson order #' . $orderId, $uid);
                    syncInvoicePayments($pdo, $invoiceId);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e instanceof ServiceException ? $e : new ServiceException('Approval failed: ' . $e->getMessage());
        }

        CreditService::recalcUsed($pdo, $customerId);
        CreditService::applyOverLimitStatus($pdo, $customerId);

        require_once __DIR__ . '/../includes/credit_ledger.php';
        if (function_exists('creditLedgerResetBestEffort')) {
            creditLedgerResetBestEffort($pdo, $customerId);
        }

        return [
            'success' => true,
            'invoice_no' => $invNo,
            'sale_id' => $saleId,
        ];
    }

    public static function rejectOrder(PDO $pdo, int $orderId, int $uid, string $note = ''): void
    {
        $result = static::transition($pdo, 'order', $orderId, 'rejected', [
            'extra' => ['admin_note' => $note !== '' ? $note : null, 'processed_at' => date('Y-m-d H:i:s')],
            'changed_by' => $uid,
        ]);
        if (!$result['success']) {
            throw new ServiceException($result['error']);
        }
    }

    /**
     * Cancel an approved, paid, undelivered order so finance can refund it.
     */
    public static function cancelOrderForRefund(PDO $pdo, int $orderId, int $uid, string $reason): void
    {
        if ($reason === '') {
            throw new ServiceException('Please provide a reason for cancellation.');
        }
        $check = $pdo->prepare('SELECT status, payment_confirmed, delivery_status FROM customer_orders WHERE id = :id');
        $check->execute(['id' => $orderId]);
        $co = $check->fetch();
        if (!$co || $co['status'] !== 'approved' || !$co['payment_confirmed'] || ($co['delivery_status'] === 'delivered')) {
            throw new ServiceException('Order could not be cancelled. Must be approved, payment confirmed, and not yet delivered.');
        }
        $result = static::transition($pdo, 'order', $orderId, 'rejected', [
            'extra' => ['admin_note' => 'Cancelled - ' . $reason, 'processed_at' => date('Y-m-d H:i:s')],
            'changed_by' => $uid,
        ]);
        if (!$result['success']) {
            throw new ServiceException($result['error']);
        }
    }

    /**
     * Assign a delivery staff member to an approved, paid order.
     */
    public static function assignDelivery(PDO $pdo, int $orderId, int $deliveryStaffId, int $uid): void
    {
        if ($deliveryStaffId <= 0) {
            throw new ServiceException('Please choose a delivery staff member.');
        }
        $check = $pdo->prepare('SELECT status, payment_confirmed, delivery_staff_id FROM customer_orders WHERE id = :id');
        $check->execute(['id' => $orderId]);
        $co = $check->fetch();
        if (!$co || $co['status'] !== 'approved' || !$co['payment_confirmed'] || $co['delivery_staff_id'] !== null) {
            throw new ServiceException('Could not assign. Order must be approved, payment confirmed, and not already assigned.');
        }
        $result = static::transition($pdo, 'delivery', $orderId, 'assigned', [
            'extra' => ['delivery_staff_id' => $deliveryStaffId],
            'changed_by' => $uid,
        ]);
        if (!$result['success']) {
            throw new ServiceException($result['error']);
        }
        AuditService::delivery($pdo, $orderId, 'assigned', null, 'assigned', $uid, "Delivery staff #{$deliveryStaffId} assigned");
    }

    public static function markOutForDelivery(PDO $pdo, int $orderId, int $uid): void
    {
        $check = $pdo->prepare('SELECT id, delivery_status, delivery_staff_id FROM customer_orders WHERE id = :id');
        $check->execute(['id' => $orderId]);
        $co = $check->fetch();
        if (!$co || !$co['delivery_staff_id']) {
            throw new ServiceException('Order must have a delivery staff assigned.');
        }
        $result = static::transition($pdo, 'delivery', $orderId, 'out_for_delivery', [
            'changed_by' => $uid,
            'notes' => 'Admin marked order as out for delivery',
        ]);
        if (!$result['success']) {
            throw new ServiceException($result['error']);
        }
    }

    /**
     * Reassign an order to a different driver. Allowed from any pre-delivery
     * state. Clears the assignment so a new one can be made. Routed through
     * the guarded engine transition so status history stays consistent.
     */
    public static function reassignDelivery(PDO $pdo, int $orderId, int $uid, string $reason = ''): void
    {
        $check = $pdo->prepare('SELECT delivery_status FROM customer_orders WHERE id = :id');
        $check->execute(['id' => $orderId]);
        $co = $check->fetch();
        if (!$co) {
            throw new ServiceException('Order not found.');
        }
        $old = $co['delivery_status'];
        if ($old === null) {
            return;
        }
        $allowedFrom = ['assigned', 'accepted', 'picked_up'];
        if (!in_array($old, $allowedFrom, true)) {
            throw new ServiceException('Delivery can only be reassigned before dispatch.');
        }
        $result = static::transition($pdo, 'delivery', $orderId, null, [
            'extra' => ['delivery_staff_id' => null, 'unassign_reason' => ($reason !== '' ? $reason : null)],
            'changed_by' => $uid,
            'notes' => 'Delivery unassigned' . ($reason !== '' ? ' - ' . $reason : ''),
        ]);
        if (!$result['success']) {
            throw new ServiceException($result['error']);
        }
    }

    /**
     * Advance a salesperson order one stage up the approval chain:
     *   'admin' -> 'store'  (performed by an Admin)
     *   'store' -> 'finance' (performed by the Store Manager)
     * Role gating happens at the page via hasPermission(). The final Finance
     * step must use finalizeSalespersonOrder() instead. When $from is given it
     * must equal the order's current stage, so an approval surface cannot
     * advance a stage it is not responsible for.
     */
    public static function approveSalespersonStage(PDO $pdo, int $orderId, int $uid, ?string $from = null): array
    {
        static::engine();
        WorkflowEngine::ensureStatusHistoryTable($pdo);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT approval_stage, status FROM customer_orders WHERE id = :id AND status = 'pending' FOR UPDATE");
            $stmt->execute(['id' => $orderId]);
            $co = $stmt->fetch();
            if (!$co || $co['approval_stage'] === null) {
                throw new ServiceException('Order not found, already processed, or does not follow the staged approval chain.');
            }
            $stage = $co['approval_stage'];
            if ($from !== null && $stage !== $from) {
                throw new ServiceException('This order is not at the approval stage this action applies to.');
            }
            if ($stage === 'admin') {
                $next = 'store';
            } elseif ($stage === 'store') {
                $next = 'finance';
            } else {
                throw new ServiceException('Order is already at the final stage; it must be finalized by Finance.');
            }

            $pdo->prepare('UPDATE customer_orders SET approval_stage = :next WHERE id = :id')
                ->execute(['next' => $next, 'id' => $orderId]);
            WorkflowEngine::logStatusChange($pdo, 'order', $orderId, $stage, 'pending', $uid,
                'Approval stage advanced from ' . strtoupper($stage) . ' to ' . strtoupper($next));
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e instanceof ServiceException ? $e : new ServiceException($e->getMessage());
        }
        return ['next_stage' => $next];
    }

    /**
     * Finance approval of a salesperson order at the 'finance' stage. Runs the
     * same finalization as approveOrder (sale + invoice + stock deduction) and
     * clears approval_stage so the order becomes a regular approved order.
     */
    public static function finalizeSalespersonOrder(PDO $pdo, int $orderId, int $uid, bool $overrideCredit = false): array
    {
        $stageCheck = $pdo->prepare('SELECT approval_stage FROM customer_orders WHERE id = :id');
        $stageCheck->execute(['id' => $orderId]);
        $stage = $stageCheck->fetchColumn();
        if ($stage !== 'finance') {
            throw new ServiceException('Order must be at the Finance approval stage before finalization.');
        }
        return static::finalizeApprovedOrder($pdo, $orderId, $uid, $overrideCredit, true);
    }
}
