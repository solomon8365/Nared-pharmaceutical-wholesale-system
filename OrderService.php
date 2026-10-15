<?php
/**
 * OrderService
 *
 * Centralized customer order creation, editing and sales recording.
 * Order status transitions belong to WorkflowService. Pages never write
 * to customer_orders / customer_order_items / out_of_stock_queue directly.
 */
class OrderService
{
    public static function helpers(): void
    {
        require_once __DIR__ . '/../includes/workflow_helpers.php';
        require_once __DIR__ . '/../includes/credit_helpers.php';
        require_once __DIR__ . '/../includes/auto_migrate.php';
    }

    public static function ensureMigrations(PDO $pdo): void
    {
        static::helpers();
        ensureOrderWorkflowMigration($pdo);
    }

    /**
     * Build price/total map for requested line items and compute order totals.
     * Returns ['lines' => [...], 'subtotal', 'totalDiscount', 'vat', 'grandTotal', 'vatRate'].
     *
     * Pricing follows the fulfillment lane: pickup orders are priced at the
     * product's wholesale price (falling back to the selling price when no
     * wholesale price is set); delivery orders use the selling price.
     */
    public static function buildOrderLines(PDO $pdo, array $rawItems, float $vatRate, float $discountPct = 0, ?string $fulfillmentMethod = null): array
    {
        $lineTotals = [];
        foreach ($rawItems as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);
            $lineDisc = (float) ($item['line_discount'] ?? 0);
            if ($productId <= 0 || $quantity <= 0) continue;
            $lineTotals[] = ['product_id' => $productId, 'quantity' => $quantity, 'line_discount' => $lineDisc];
        }
        if (empty($lineTotals)) {
            throw new ServiceException('Add at least one product with quantity.');
        }

        $qtyByProduct = [];
        foreach ($lineTotals as $line) {
            $qtyByProduct[$line['product_id']] = ($qtyByProduct[$line['product_id']] ?? 0) + $line['quantity'];
        }

        $isPickup = $fulfillmentMethod === StatusCatalog::FULFILLMENT_METHOD_PICKUP;
        $priceByProduct = [];
        foreach ($qtyByProduct as $productId => $_need) {
            $stmt = $pdo->prepare('SELECT price, wholesale_price FROM products WHERE id = :id');
            $stmt->execute(['id' => $productId]);
            $row = $stmt->fetch();
            if ($row === false) throw new ServiceException('Invalid product in order.');
            $unit = (float) $row['price'];
            if ($isPickup && (float) $row['wholesale_price'] > 0) {
                $unit = (float) $row['wholesale_price'];
            }
            $priceByProduct[$productId] = $unit;
        }

        $lines = [];
        $subtotal = 0;
        $totalDiscount = 0;
        foreach ($lineTotals as $line) {
            $unitPrice = $priceByProduct[$line['product_id']];
            $lineDisc = $line['line_discount'];
            $lineSubtotal = ($unitPrice * $line['quantity']) - $lineDisc;
            $subtotal += ($unitPrice * $line['quantity']);
            $totalDiscount += $lineDisc;
            $lines[] = [
                'product_id' => $line['product_id'],
                'quantity' => $line['quantity'],
                'unit_price' => $unitPrice,
                'discount' => $lineDisc,
                'tax_amount' => round($lineSubtotal * $vatRate / 100, 2),
            ];
        }

        if ($discountPct > 0 && $discountPct <= 100) {
            $totalDiscount = round($subtotal * $discountPct / 100, 2);
        }
        $taxable = max($subtotal - $totalDiscount, 0);
        $vatAmount = round($taxable * $vatRate / 100, 2);
        $grandTotal = $taxable + $vatAmount;

        return [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'totalDiscount' => $totalDiscount,
            'vat' => $vatAmount,
            'grandTotal' => $grandTotal,
            'vatRate' => $vatRate,
        ];
    }

    /**
     * Reject any customer-placed order line whose requested quantity exceeds
     * the currently available stock. Applies to the customer portal only
     * (submitOrder / updateOrder); salesperson orders keep the existing
     * out-of-stock enqueue behavior through the approval chain.
     */
    private static function validateCustomerStock(PDO $pdo, array $rawItems): void
    {
        $qtyByProduct = [];
        foreach ($rawItems as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);
            if ($productId <= 0 || $quantity <= 0) continue;
            $qtyByProduct[$productId] = ($qtyByProduct[$productId] ?? 0) + $quantity;
        }
        foreach ($qtyByProduct as $productId => $needQty) {
            $stmt = $pdo->prepare('SELECT stock_quantity FROM products WHERE id = :id');
            $stmt->execute(['id' => $productId]);
            $available = (int) $stmt->fetchColumn();
            if ($needQty > $available) {
                throw new ServiceException('Insufficient stock available.');
            }
        }
    }

    /**
     * Submit a new customer order (customer portal). Runs in a transaction,
     * validates credit for credit orders, handles payment screenshot upload
     * path (resolved by caller), and enqueues out-of-stock lines.
     */
    public static function submitOrder(PDO $pdo, int $customerId, int $uid, array $data, ?string $paymentScreenshotPath = null): array
    {
        static::helpers();
        static::ensureMigrations($pdo);
        $vatRate = CalculationService::vatRate($pdo);
        $fulfillmentMethod = static::resolveFulfillmentMethod($pdo, $data);
        static::validateCustomerStock($pdo, $data['items'] ?? []);
        $built = static::buildOrderLines($pdo, $data['items'] ?? [], $vatRate, (float) ($data['discount_percent'] ?? 0), $fulfillmentMethod);
        $grandTotal = $built['grandTotal'];
        $paymentMethod = $data['payment_method'] ?? 'cash';

        if ($paymentMethod !== 'cash' && $paymentMethod !== 'credit' && ($data['transaction_ref'] ?? '') === '' && ($data['payment_detail'] ?? '') === '') {
            throw new ServiceException('Please enter payment details or transaction reference for the selected payment method.');
        }

        if ($paymentMethod === 'credit' && $grandTotal > 0) {
            CreditService::syncOverdue($pdo);
            $creditCheck = CreditService::validateOrder($pdo, $customerId, $grandTotal);
            if (!$creditCheck['valid']) {
                $msg = $creditCheck['msg'];
                if ($creditCheck['is_over_limit']) {
                    $msg = 'Credit limit exceeded. ' . fmt($creditCheck['credit_limit'])
                        . ' limit, ' . fmt($creditCheck['outstanding']) . ' outstanding, '
                        . fmt($creditCheck['available']) . ' available. Order of '
                        . fmt($creditCheck['requested']) . ' exceeds by '
                        . fmt($creditCheck['exceeded']) . '.';
                }
                throw new ServiceException($msg);
            }
        }

        // Credit orders must reference the current active agreement version and
        // (if enabled) the customer's explicit acceptance.
        $agreementVersionId = null;
        $agreementAcceptedAt = null;
        if ($paymentMethod === 'credit' && $grandTotal > 0) {
            $agreementVersionId = static::validateCreditAgreement($pdo, $data);
            $agreementAcceptedAt = date('Y-m-d H:i:s');
        }

        $orderDate = $data['order_date'] ?? date('Y-m-d');
        $orderStatus = get_setting('default_order_status', 'pending');

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO customer_orders (customer_id, placed_by_user_id, order_date, status, total_amount, discount_amount, vat_amount, payment_method, agreement_version_id, agreement_accepted_at, payment_detail, transaction_ref, bank_name, account_name, account_number, merchant_name, merchant_id, payment_screenshot, delivery_address, fulfillment_method, created_at) VALUES (:cid, :uid, :od, :st, :total, :disc, :vat, :pm, :agv, :aga, :pd, :tr, :bn, :an, :acn, :mn, :mid, :ss, :da, :fm, NOW())");
            $stmt->execute([
                'st' => $orderStatus, 'cid' => $customerId, 'uid' => $uid, 'od' => $orderDate,
                'total' => $grandTotal, 'disc' => $built['totalDiscount'], 'vat' => $built['vat'],
                'pm' => $paymentMethod, 'agv' => $agreementVersionId, 'aga' => $agreementAcceptedAt,
                'pd' => $data['payment_detail'] ?? '',
                'tr' => !empty($data['transaction_ref']) ? $data['transaction_ref'] : null,
                'bn' => !empty($data['bank_name']) ? $data['bank_name'] : null,
                'an' => !empty($data['account_name']) ? $data['account_name'] : null,
                'acn' => !empty($data['account_number']) ? $data['account_number'] : null,
                'mn' => !empty($data['merchant_name']) ? $data['merchant_name'] : null,
                'mid' => !empty($data['merchant_id']) ? $data['merchant_id'] : null,
                'ss' => $paymentScreenshotPath,
                'da' => !empty($data['delivery_address']) ? $data['delivery_address'] : null,
                'fm' => $fulfillmentMethod,
            ]);
            $orderId = (int) $pdo->lastInsertId();
            static::insertOrderItems($pdo, $orderId, $built['lines']);
            static::enqueueOutOfStock($pdo, $orderId, $built['lines']);

            AuditService::payment($pdo, $orderId, $customerId, 'order_submitted', $grandTotal, $paymentMethod, !empty($data['transaction_ref']) ? $data['transaction_ref'] : null, $uid, 'Order submitted by customer');
            NotificationService::order($pdo, $orderId, $customerId, 'Order Submitted', 'Your order #' . $orderId . ' for ' . fmt($grandTotal) . ' has been submitted and is awaiting finance verification.', 'info');
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e instanceof ServiceException ? $e : new ServiceException($e->getMessage());
        }
        return ['order_id' => $orderId, 'grand_total' => $grandTotal];
    }

    /**
     * Validate and resolve the wholesale fulfillment lane (pickup / delivery).
     * Defaults to delivery so legacy order entry keeps the existing behavior,
     * but requires a delivery address whenever delivery is selected.
     */
    private static function resolveFulfillmentMethod(PDO $pdo, array $data): string
    {
        $method = $data['fulfillment_method'] ?? StatusCatalog::FULFILLMENT_METHOD_DELIVERY;
        $method = (string) $method;
        if (!in_array($method, [StatusCatalog::FULFILLMENT_METHOD_PICKUP, StatusCatalog::FULFILLMENT_METHOD_DELIVERY], true)) {
            throw new ServiceException('Please choose a fulfillment method: pickup or delivery.');
        }
        if ($method === StatusCatalog::FULFILLMENT_METHOD_DELIVERY && trim((string) ($data['delivery_address'] ?? '')) === '') {
            throw new ServiceException('Delivery orders require a delivery address. Choose Pickup or enter a delivery address.');
        }
        return $method;
    }

    /**
     * Validate that a credit order carries the current active agreement version
     * and (when configured) explicit customer acceptance. Returns the accepted
     * version id, which is snapshotted onto the order.
     */
    private static function validateCreditAgreement(PDO $pdo, array $data): int
    {
        static::helpers();
        $active = creditGetActiveAgreement($pdo);
        $postedId = (int) ($data['agreement_version_id'] ?? 0);
        if ($postedId <= 0 || $postedId !== (int) $active['id']) {
            throw new ServiceException('The credit agreement has been updated. Please review and accept the current Credit Agreement before continuing.');
        }
        if (!empty($active['require_acceptance']) && (($data['agreement_accepted'] ?? '') !== '1')) {
            throw new ServiceException('You must accept the current Credit Agreement before placing a credit order.');
        }
        return $postedId;
    }

    /**
     * Update a pending customer order (customer portal). Deletes + re-inserts
     * line items inside a transaction.
     */
    public static function updateOrder(PDO $pdo, int $customerId, int $editingOrderId, array $data, ?string $paymentScreenshotPath = null): void
    {
        static::helpers();
        static::ensureMigrations($pdo);
        $vatRate = CalculationService::vatRate($pdo);
        $fulfillmentMethod = static::resolveFulfillmentMethod($pdo, $data);
        static::validateCustomerStock($pdo, $data['items'] ?? []);
        $built = static::buildOrderLines($pdo, $data['items'] ?? [], $vatRate, (float) ($data['discount_percent'] ?? 0), $fulfillmentMethod);
        $grandTotal = $built['grandTotal'];
        $paymentMethod = $data['payment_method'] ?? 'cash';

        if ($paymentMethod !== 'cash' && $paymentMethod !== 'credit' && ($data['transaction_ref'] ?? '') === '' && ($data['payment_detail'] ?? '') === '') {
            throw new ServiceException('Please enter payment details or transaction reference for the selected payment method.');
        }

        if ($paymentMethod === 'credit' && $grandTotal > 0) {
            CreditService::syncOverdue($pdo);
            $creditCheck = CreditService::validateOrder($pdo, $customerId, $grandTotal, false, $editingOrderId);
            if (!$creditCheck['valid']) {
                $msg = $creditCheck['msg'];
                if ($creditCheck['is_over_limit']) {
                    $msg = 'Credit limit exceeded. ' . fmt($creditCheck['credit_limit'])
                        . ' limit, ' . fmt($creditCheck['outstanding']) . ' outstanding, '
                        . fmt($creditCheck['available']) . ' available. Order of '
                        . fmt($creditCheck['requested']) . ' exceeds by '
                        . fmt($creditCheck['exceeded']) . '.';
                }
                throw new ServiceException($msg);
            }
        }

        $agreementVersionId = null;
        $agreementAcceptedAt = null;
        if ($paymentMethod === 'credit' && $grandTotal > 0) {
            $agreementVersionId = static::validateCreditAgreement($pdo, $data);
            $agreementAcceptedAt = date('Y-m-d H:i:s');
        }

        $pdo->beginTransaction();
        try {
            $check = $pdo->prepare("SELECT id, payment_screenshot FROM customer_orders WHERE id = :id AND customer_id = :cid AND status = 'pending' LIMIT 1");
            $check->execute(['id' => $editingOrderId, 'cid' => $customerId]);
            $existing = $check->fetch();
            if (!$existing) throw new ServiceException('Order not found or no longer editable.');
            $screenshotPath = $paymentScreenshotPath !== null ? $paymentScreenshotPath : $existing['payment_screenshot'];

            $orderDate = $data['order_date'] ?? date('Y-m-d');
            $stmt = $pdo->prepare("UPDATE customer_orders SET order_date = :od, total_amount = :total, discount_amount = :disc, vat_amount = :vat, payment_method = :pm, agreement_version_id = :agv, agreement_accepted_at = :aga, payment_detail = :pd, transaction_ref = :tr, bank_name = :bn, account_name = :an, account_number = :acn, merchant_name = :mn, merchant_id = :mid, payment_screenshot = :ss, delivery_address = :da, fulfillment_method = :fm WHERE id = :id");
            $stmt->execute([
                'od' => $orderDate, 'total' => $grandTotal, 'disc' => $built['totalDiscount'], 'vat' => $built['vat'],
                'pm' => $paymentMethod, 'agv' => $agreementVersionId, 'aga' => $agreementAcceptedAt,
                'pd' => $data['payment_detail'] ?? '',
                'tr' => !empty($data['transaction_ref']) ? $data['transaction_ref'] : null,
                'bn' => !empty($data['bank_name']) ? $data['bank_name'] : null,
                'an' => !empty($data['account_name']) ? $data['account_name'] : null,
                'acn' => !empty($data['account_number']) ? $data['account_number'] : null,
                'mn' => !empty($data['merchant_name']) ? $data['merchant_name'] : null,
                'mid' => !empty($data['merchant_id']) ? $data['merchant_id'] : null,
                'ss' => $screenshotPath, 'da' => !empty($data['delivery_address']) ? $data['delivery_address'] : null,
                'fm' => $fulfillmentMethod,
                'id' => $editingOrderId,
            ]);
            $pdo->prepare('DELETE FROM customer_order_items WHERE customer_order_id = :oid')->execute(['oid' => $editingOrderId]);
            static::insertOrderItems($pdo, $editingOrderId, $built['lines']);
            $pdo->prepare("DELETE FROM out_of_stock_queue WHERE customer_order_id = :oid AND status = 'pending'")->execute(['oid' => $editingOrderId]);
            static::enqueueOutOfStock($pdo, $editingOrderId, $built['lines']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e instanceof ServiceException ? $e : new ServiceException($e->getMessage());
        }
    }

    private static function insertOrderItems(PDO $pdo, int $orderId, array $lines): void
    {
        $ins = $pdo->prepare('INSERT INTO customer_order_items (customer_order_id, product_id, quantity, unit_price, discount, tax_amount) VALUES (:oid, :pid, :qty, :price, :disc, :tax)');
        foreach ($lines as $line) {
            $ins->execute(['oid' => $orderId, 'pid' => $line['product_id'], 'qty' => $line['quantity'], 'price' => $line['unit_price'], 'disc' => $line['discount'], 'tax' => $line['tax_amount']]);
        }
    }

    private static function enqueueOutOfStock(PDO $pdo, int $orderId, array $lines): void
    {
        foreach ($lines as $line) {
            $stockStmt = $pdo->prepare('SELECT stock_quantity FROM products WHERE id = :id');
            $stockStmt->execute(['id' => $line['product_id']]);
            $stock = (int) $stockStmt->fetchColumn();
            if ($stock < $line['quantity']) {
                $shortage = $line['quantity'] - max($stock, 0);
                $pdo->prepare('INSERT INTO out_of_stock_queue (customer_order_id, product_id, quantity_needed, status, notes, created_at) VALUES (:oid, :pid, :qty, \'pending\', :notes, NOW())')->execute([
                    'oid' => $orderId, 'pid' => $line['product_id'], 'qty' => $shortage, 'notes' => "Auto: Order #{$orderId}",
                ]);
            }
        }
    }

    /**
     * Record a direct over-the-counter sale (admin sales page).
     * Transactional: validates stock, creates sale + sale_items + commission
     * record, deducts batches and logs stock movement.
     */
    public static function recordSale(PDO $pdo, array $data): array
    {
        require_once __DIR__ . '/../includes/stock_helper.php';
        require_once __DIR__ . '/../includes/auto_migrate.php';
        ensureSalesFulfillmentMigration($pdo);
        $customerId = (int) ($data['customer_id'] ?? 0);
        $items = $data['sale_items'] ?? [];
        $saleDate = $data['sale_date'] ?? date('Y-m-d');
        $salespersonId = (int) ($data['salesperson_id'] ?? 0);
        $commissionPercent = (float) ($data['commission_percent'] ?? 0);
        $paymentMethod = trim((string) ($data['payment_method'] ?? ''));
        $paymentDetail = trim((string) ($data['payment_detail'] ?? ''));
        $paymentConfirmed = !empty($data['payment_confirmed']);
        $fulfillmentMethod = $data['fulfillment_method'] ?? StatusCatalog::FULFILLMENT_METHOD_DELIVERY;
        if (!in_array($fulfillmentMethod, [StatusCatalog::FULFILLMENT_METHOD_PICKUP, StatusCatalog::FULFILLMENT_METHOD_DELIVERY], true)) {
            throw new ServiceException('Please choose a fulfillment method: pickup or delivery.');
        }

        if ($customerId <= 0 || empty($items)) {
            throw new ServiceException('Customer and at least one line item are required.');
        }

        $lineTotals = [];
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);
            if ($productId <= 0 || $quantity <= 0) continue;
            $lineTotals[] = ['product_id' => $productId, 'quantity' => $quantity];
        }
        if (empty($lineTotals)) {
            throw new ServiceException('Add at least one product with quantity.');
        }

        $qtyByProduct = [];
        foreach ($lineTotals as $line) {
            $qtyByProduct[$line['product_id']] = ($qtyByProduct[$line['product_id']] ?? 0) + $line['quantity'];
        }

        $priceByProduct = [];
        $isPickup = $fulfillmentMethod === StatusCatalog::FULFILLMENT_METHOD_PICKUP;
        foreach ($qtyByProduct as $productId => $needQty) {
            $stmt = $pdo->prepare('SELECT price, wholesale_price, stock_quantity FROM products WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $productId]);
            $row = $stmt->fetch();
            if (!$row) throw new ServiceException('Product not found.');
            if ((int) $row['stock_quantity'] < $needQty) {
                throw new ServiceException('Insufficient stock for one or more products.');
            }
            $unit = (float) $row['price'];
            if ($isPickup && (float) $row['wholesale_price'] > 0) {
                $unit = (float) $row['wholesale_price'];
            }
            $priceByProduct[$productId] = $unit;
        }

        $linesForInsert = [];
        foreach ($lineTotals as $line) {
            $linesForInsert[] = ['product_id' => $line['product_id'], 'quantity' => $line['quantity'], 'unit_price' => $priceByProduct[$line['product_id']]];
        }

        $total = 0;
        foreach ($linesForInsert as $line) {
            $total += $line['unit_price'] * $line['quantity'];
        }

        // Credit sales must pass the shared credit validation and carry an
        // accepted copy of the current active credit agreement. Mirrors the
        // enforcement in submitOrder() so the sales page cannot bypass the
        // credit policy by recording directly into the sales table.
        if ($paymentMethod === 'credit' && $total > 0) {
            require_once __DIR__ . '/../includes/credit_helpers.php';
            CreditService::syncOverdue($pdo);
            $check = creditValidateOrder($pdo, $customerId, $total, function_exists('creditCanOverride') ? creditCanOverride() : false);
            if (!$check['valid']) {
                $msg = $check['msg'];
                if (!empty($check['is_over_limit'])) {
                    $msg = 'Credit limit exceeded. ' . fmt($check['credit_limit'])
                        . ' limit, ' . fmt($check['outstanding']) . ' outstanding, '
                        . fmt($check['available']) . ' available. Order of '
                        . fmt($check['requested']) . ' exceeds by ' . fmt($check['exceeded']) . '.';
                }
                throw new ServiceException($msg);
            }
            $active = creditGetActiveAgreement($pdo);
            $postedId = (int) ($data['agreement_version_id'] ?? 0);
            if ($postedId <= 0 || $postedId !== (int) ($active['id'] ?? 0)) {
                throw new ServiceException('The credit agreement has been updated. Please review and accept the current Credit Agreement before continuing.');
            }
            if (creditRequireAgreementAcceptance($pdo) && (($data['agreement_accepted'] ?? '') !== '1')) {
                throw new ServiceException('You must accept the current Credit Agreement before placing a credit order.');
            }
        }

        $agentName = '';
        if ($salespersonId > 0) {
            $spStmt = $pdo->prepare('SELECT name FROM salespeople WHERE id = :id AND status = \'active\'');
            $spStmt->execute(['id' => $salespersonId]);
            $spRow = $spStmt->fetch();
            $agentName = $spRow ? $spRow['name'] : '';
        }
        $commissionAmount = $commissionPercent > 0 ? round($total * $commissionPercent / 100, 0) : 0;

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO sales (customer_id, salesperson_id, sale_date, fulfillment_method, total_amount, payment_method, payment_detail, payment_confirmed, agent_name, commission_percent, commission_amount, created_at) VALUES (:customer, :sp, :sale_date, :fm, :total, :pm, :pd, :pc, :agent, :cp, :ca, NOW())');
            $stmt->execute([
                'customer' => $customerId, 'sp' => $salespersonId > 0 ? $salespersonId : null,
                'sale_date' => $saleDate, 'fm' => $fulfillmentMethod, 'total' => $total,
                'pm' => $paymentMethod !== '' ? $paymentMethod : null, 'pd' => $paymentDetail !== '' ? $paymentDetail : null,
                'pc' => $paymentConfirmed ? 1 : 0, 'agent' => $agentName ?: null,
                'cp' => $commissionPercent > 0 ? $commissionPercent : null,
                'ca' => $commissionAmount > 0 ? $commissionAmount : null,
            ]);
            $saleId = (int) $pdo->lastInsertId();

            if ($salespersonId > 0 && $commissionAmount > 0) {
                $insComm = $pdo->prepare('INSERT INTO commissions (salesperson_id, commission_type, reference_type, reference_id, sale_amount, commission_rate, commission_amount, status, notes, commission_month, commission_year, created_at) VALUES (:spid, \'sale\', \'sale\', :refid, :sa, :cr, :ca, \'pending\', :notes, :cm, :cy, NOW())');
                $insComm->execute([
                    'spid' => $salespersonId, 'refid' => $saleId, 'sa' => $total, 'cr' => $commissionPercent,
                    'ca' => $commissionAmount, 'notes' => 'Auto: Sale #' . $saleId . ' by ' . $agentName,
                    'cm' => (int) date('m', strtotime($saleDate)), 'cy' => (int) date('Y', strtotime($saleDate)),
                ]);
            }

            $insertItem = $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, quantity, unit_price) VALUES (:sale_id, :product_id, :quantity, :unit_price)');
            foreach ($linesForInsert as $line) {
                $insertItem->execute(['sale_id' => $saleId, 'product_id' => $line['product_id'], 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price']]);
                deduct_from_batches($pdo, $line['product_id'], $line['quantity']);
                log_stock_movement($pdo, $line['product_id'], -$line['quantity'], 'Sale', 'sale', (int) $saleId);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e instanceof ServiceException ? $e : new ServiceException($e->getMessage());
        }
        return ['success' => true, 'sale_id' => $saleId, 'total' => $total];
    }

    /**
     * Record a salesperson-created order (Sales page "New Sale" or salespeople
     * registration). Unlike recordSale — an immediate over-the-counter sale —
     * this creates a PENDING customer order that must pass the staged approval
     * chain: Admin -> Store Manager -> Finance. Stock is NOT deducted here; it
     * is committed when Finance finalizes the order.
     */
    public static function createSalespersonOrder(PDO $pdo, array $data): array
    {
        static::helpers();
        require_once __DIR__ . '/../includes/workflow_engine.php';
        require_once __DIR__ . '/../includes/stock_helper.php';
        require_once __DIR__ . '/../includes/auto_migrate.php';
        ensureSalespersonOrderApprovalMigration($pdo);

        $customerId = (int) ($data['customer_id'] ?? 0);
        $salespersonId = (int) ($data['salesperson_id'] ?? 0);
        $placedByUid = (int) ($data['placed_by_user_id'] ?? 0);
        $orderDate = $data['sale_date'] ?? date('Y-m-d');
        $paymentMethod = trim((string) ($data['payment_method'] ?? ''));
        $paymentDetail = trim((string) ($data['payment_detail'] ?? ''));
        $deliveryAddress = trim((string) ($data['delivery_address'] ?? ''));
        $fulfillmentMethod = $data['fulfillment_method'] ?? 'delivery';
        if (!in_array($fulfillmentMethod, [StatusCatalog::FULFILLMENT_METHOD_PICKUP, StatusCatalog::FULFILLMENT_METHOD_DELIVERY], true)) {
            $fulfillmentMethod = StatusCatalog::FULFILLMENT_METHOD_DELIVERY;
        }
        if ($fulfillmentMethod === StatusCatalog::FULFILLMENT_METHOD_DELIVERY && $deliveryAddress === '') {
            throw new ServiceException('Delivery orders require a delivery address. Choose Customer Pickup or enter a delivery address.');
        }

        if ($customerId <= 0 || $salespersonId <= 0 || empty($data['sale_items'])) {
            throw new ServiceException('Customer and at least one line item are required.');
        }

        $built = static::buildOrderLines($pdo, $data['sale_items'], 0, 0, $fulfillmentMethod);
        $total = $built['grandTotal'];

        // Credit orders follow the same shared policy as the customer portal:
        // credit limit check (non-overrideable by a salesperson) plus explicit
        // acceptance of the current active credit agreement.
        $agreementVersionId = null;
        $agreementAcceptedAt = null;
        if ($paymentMethod === 'credit' && $total > 0) {
            CreditService::syncOverdue($pdo);
            $check = creditValidateOrder($pdo, $customerId, $total, function_exists('creditCanOverride') ? creditCanOverride() : false);
            if (!$check['valid']) {
                $msg = $check['msg'];
                if (!empty($check['is_over_limit'])) {
                    $msg = 'Credit limit exceeded. ' . fmt($check['credit_limit'])
                        . ' limit, ' . fmt($check['outstanding']) . ' outstanding, '
                        . fmt($check['available']) . ' available. Order of '
                        . fmt($check['requested']) . ' exceeds by ' . fmt($check['exceeded']) . '.';
                }
                throw new ServiceException($msg);
            }
            $active = creditGetActiveAgreement($pdo);
            $postedId = (int) ($data['agreement_version_id'] ?? 0);
            if ($postedId <= 0 || $postedId !== (int) ($active['id'] ?? 0)) {
                throw new ServiceException('The credit agreement has been updated. Please review and accept the current Credit Agreement before continuing.');
            }
            if (creditRequireAgreementAcceptance($pdo) && (($data['agreement_accepted'] ?? '') !== '1')) {
                throw new ServiceException('You must accept the current Credit Agreement before placing a credit order.');
            }
            $agreementVersionId = $postedId;
            $agreementAcceptedAt = date('Y-m-d H:i:s');
        }

        if ($paymentMethod !== '' && $paymentMethod !== 'cash' && $paymentMethod !== 'credit' && $paymentDetail === '') {
            throw new ServiceException('Please provide payment details / transaction reference for the selected payment method.');
        }

        // Non-credit salesperson orders normally carry an uploaded payment photo
        // (bank slip, mobile-money screenshot, cash receipt) resolved by the caller.
        // Back-office registrations on a customer's behalf may opt out with
        // require_payment_photo = false (no receipt collected yet).
        $requirePaymentPhoto = (bool) ($data['require_payment_photo'] ?? true);
        if ($requirePaymentPhoto && $paymentMethod !== 'credit' && trim((string) ($data['payment_photo_path'] ?? '')) === '') {
            throw new ServiceException('A payment photo is required for non-credit orders. Please upload the payment receipt image.');
        }

        $paymentEnum = match ($paymentMethod) {
            'bank_transfer' => 'bank',
            'mobile_money' => 'mobile_money',
            '' => 'cash',
            default => $paymentMethod,
        };
        $salesType = $paymentMethod === 'credit' ? 'credit' : 'cash';

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare("INSERT INTO customer_orders (customer_id, placed_by_user_id, salesperson_id, order_date, status, total_amount, payment_method, payment_detail, payment_screenshot, delivery_address, sales_type, agreement_version_id, agreement_accepted_at, fulfillment_method, approval_stage, created_at) VALUES (:cid, :uid, :spid, :od, 'pending', :total, :pm, :pd, :ps, :da, :st, :agv, :aga, :fm, 'admin', NOW())");
            $ins->execute([
                'cid' => $customerId, 'uid' => $placedByUid > 0 ? $placedByUid : null,
                'spid' => $salespersonId, 'od' => $orderDate, 'total' => $total,
                'pm' => $paymentEnum, 'pd' => $paymentDetail !== '' ? $paymentDetail : null,
                'ps' => trim((string) ($data['payment_photo_path'] ?? '')) !== '' ? trim((string) $data['payment_photo_path']) : null,
                'da' => $deliveryAddress !== '' ? $deliveryAddress : null,
                'st' => $salesType, 'agv' => $agreementVersionId, 'aga' => $agreementAcceptedAt,
                'fm' => $fulfillmentMethod,
            ]);
            $orderId = (int) $pdo->lastInsertId();

            $insItem = $pdo->prepare('INSERT INTO customer_order_items (customer_order_id, product_id, quantity, unit_price) VALUES (:oid, :pid, :qty, :price)');
            foreach ($built['lines'] as $line) {
                $insItem->execute(['oid' => $orderId, 'pid' => $line['product_id'], 'qty' => $line['quantity'], 'price' => $line['unit_price']]);
            }

            WorkflowEngine::logStatusChange($pdo, 'order', $orderId, null, 'pending', $placedByUid > 0 ? $placedByUid : null, 'Salesperson order submitted - awaiting Admin approval');
            NotificationService::finance($pdo, null, $orderId, 'Salesperson Order Awaiting Approval', 'Salesperson order #' . $orderId . ' for ' . fmt($total) . ' needs Admin approval.', 'info');
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e instanceof ServiceException ? $e : new ServiceException($e->getMessage());
        }
        return ['order_id' => $orderId, 'total' => $total];
    }

    /**
     * Confirm payment on a recorded direct sale (admin sales page).
     * Only unconfirmed sales can be confirmed; idempotent on repeated calls.
     */
    public static function confirmSalePayment(PDO $pdo, int $saleId, string $paymentMethod, string $paymentDetail = '', ?int $userId = null): array
    {
        $paymentMethod = trim($paymentMethod);
        if ($paymentMethod === '') {
            throw new ServiceException('Choose a payment method.');
        }
        $stmt = $pdo->prepare("SELECT id FROM sales WHERE id = :id AND payment_confirmed = 0");
        $stmt->execute(['id' => $saleId]);
        if (!$stmt->fetch()) {
            return ['success' => true, 'msg' => 'Sale payment is already confirmed.'];
        }
        $pdo->prepare('UPDATE sales SET payment_method = :pm, payment_detail = :pd, payment_confirmed = 1 WHERE id = :id')
            ->execute(['pm' => $paymentMethod, 'pd' => $paymentDetail !== '' ? $paymentDetail : null, 'id' => $saleId]);
        return ['success' => true, 'msg' => 'Sale payment confirmed.'];
    }

    /** Whether a customer's order is editable (must be pending). */
    public static function getEditableOrder(PDO $pdo, int $orderId, int $customerId): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM customer_orders WHERE id = :id AND customer_id = :cid AND status = 'pending' LIMIT 1");
        $stmt->execute(['id' => $orderId, 'cid' => $customerId]);
        return $stmt->fetch() ?: null;
    }

    public static function getOrderItems(PDO $pdo, int $orderId): array
    {
        $stmt = $pdo->prepare('SELECT coi.*, p.name AS product_name, p.brand, p.strength FROM customer_order_items coi JOIN products p ON coi.product_id = p.id WHERE coi.customer_order_id = :oid');
        $stmt->execute(['oid' => $orderId]);
        return $stmt->fetchAll();
    }
}
