<?php
/**
 * Order & Payment Workflow Helpers
 * Provides notification, payment log, and timeline utilities.
 */

require_once __DIR__ . '/../services/SettingsService.php';

// VAT rate from settings
function workflowGetVatRate($pdo = null) {
    static $rate = null;
    if ($rate === null) {
        $rate = SettingsService::vatRate();
    }
    return $rate;
}

// Log payment action
function logPaymentAction($pdo, $orderId, $customerId, $action, $amount = null, $method = null, $transRef = null, $userId = null, $notes = null) {
    // Invoice-based payments have no customer order — store NULL, never 0.
    $orderId = ($orderId > 0) ? (int) $orderId : null;
    $stmt = $pdo->prepare("INSERT INTO payment_log (customer_order_id, customer_id, action, amount, payment_method, transaction_ref, performed_by, notes) VALUES (:oid, :cid, :action, :amt, :method, :ref, :uid, :notes)");
    $stmt->execute([
        'oid' => $orderId, 'cid' => $customerId, 'action' => $action,
        'amt' => $amount, 'method' => $method, 'ref' => $transRef,
        'uid' => $userId, 'notes' => $notes
    ]);
}

// Create notification
function createOrderNotification($pdo, $orderId, $customerId, $title, $message, $type = 'info') {
    $stmt = $pdo->prepare("INSERT INTO order_notifications (customer_order_id, customer_id, title, message, type) VALUES (:oid, :cid, :title, :msg, :type)");
    $stmt->execute(['oid' => $orderId, 'cid' => $customerId, 'title' => $title, 'msg' => $message, 'type' => $type]);
}

// Get unread notification count for customer
function getUnreadNotificationCount($pdo, $customerId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM order_notifications WHERE customer_id = :cid AND is_read = 0");
    $stmt->execute(['cid' => $customerId]);
    return (int) $stmt->fetchColumn();
}

// Mark notifications as read
function markNotificationsRead($pdo, $customerId, $orderId = null) {
    if ($orderId) {
        $pdo->prepare("UPDATE order_notifications SET is_read = 1 WHERE customer_id = :cid AND customer_order_id = :oid")
            ->execute(['cid' => $customerId, 'oid' => $orderId]);
    } else {
        $pdo->prepare("UPDATE order_notifications SET is_read = 1 WHERE customer_id = :cid")
            ->execute(['cid' => $customerId]);
    }
}

// Get order timeline steps
function getOrderTimeline($pdo, $orderId) {
    $stmt = $pdo->prepare("SELECT * FROM customer_orders WHERE id = :id");
    $stmt->execute(['id' => $orderId]);
    $order = $stmt->fetch();
    if (!$order) return [];

    $steps = [
        ['key' => 'cart',         'label' => 'Shopping Cart',        'icon' => 'fa-shopping-cart',  'done' => true],
        ['key' => 'checkout',     'label' => 'Checkout',             'icon' => 'fa-credit-card',    'done' => true],
        ['key' => 'payment_sub',  'label' => 'Payment Submitted',    'icon' => 'fa-money-check',    'done' => !empty($order['payment_confirmed']) || !empty($order['transaction_ref'])],
        ['key' => 'finance_ver',  'label' => 'Finance Verification', 'icon' => 'fa-user-check',     'done' => !empty($order['verified_at'])],
        ['key' => 'pay_approved', 'label' => 'Payment Approved',     'icon' => 'fa-check-circle',   'done' => !empty($order['payment_confirmed'])],
        ['key' => 'admin_appr',   'label' => 'Admin Approval',       'icon' => 'fa-gavel',          'done' => $order['status'] === 'approved'],
        ['key' => 'warehouse',    'label' => 'Warehouse Processing', 'icon' => 'fa-boxes',          'done' => $order['picking_status'] === 'picked'],
        ['key' => 'out_delivery', 'label' => 'Out for Delivery',     'icon' => 'fa-truck',          'done' => $order['delivery_status'] === 'out_for_delivery' || $order['delivery_status'] === 'delivered'],
        ['key' => 'delivered',    'label' => 'Delivered',            'icon' => 'fa-check-double',   'done' => $order['delivery_status'] === 'delivered'],
    ];

    if ($order['status'] === 'rejected') {
        $steps[] = ['key' => 'rejected', 'label' => 'Order Rejected', 'icon' => 'fa-times-circle', 'done' => true, 'error' => true];
    }

    return $steps;
}

// Calculate order totals
function calculateOrderTotals($pdo, $orderId) {
    $stmt = $pdo->prepare("
        SELECT coi.quantity, coi.unit_price, coi.discount, coi.tax_amount
        FROM customer_order_items coi WHERE coi.customer_order_id = :oid
    ");
    $stmt->execute(['oid' => $orderId]);
    $items = $stmt->fetchAll();

    $vatRate = workflowGetVatRate($pdo);
    $subtotal = 0;
    $totalDiscount = 0;
    $totalTax = 0;
    $totalQty = 0;

    foreach ($items as $it) {
        $qty = (int) $it['quantity'];
        $price = (float) $it['unit_price'];
        $disc = (float) ($it['discount'] ?? 0);
        $tax = (float) ($it['tax_amount'] ?? 0);
        $subtotal += ($price * $qty);
        $totalDiscount += $disc;
        $totalTax += $tax;
        $totalQty += $qty;
    }

    if ($totalTax == 0 && $subtotal > 0) {
        $totalTax = round(($subtotal - $totalDiscount) * $vatRate / 100, 2);
    }
    $grandTotal = ($subtotal - $totalDiscount) + $totalTax;

    return [
        'subtotal' => $subtotal,
        'discount' => $totalDiscount,
        'vat' => $totalTax,
        'vat_rate' => $vatRate,
        'grand_total' => $grandTotal,
        'total_qty' => $totalQty,
        'item_count' => count($items),
    ];
}

// Format payment method label
function formatPaymentMethod($method) {
    $labels = [
        'cash' => 'Cash',
        'bank' => 'Bank Transfer',
        'cbe_birr' => 'CBE Birr',
        'telebirr' => 'Telebirr',
        'credit' => 'Credit Account',
    ];
    return $labels[$method] ?? ucfirst($method);
}

/**
 * Record a payment against an invoice and recalculate invoice totals.
 * Each call creates a separate payment record — never overwrites.
 */
function recordInvoicePayment(PDO $pdo, int $invoiceId, float $amount, string $method, ?string $ref = null, ?string $receipt = null, ?string $notes = null, ?int $userId = null): bool {
    if ($amount <= 0) return false;
    $inv = $pdo->prepare('SELECT id, customer_id, total_amount, outstanding_balance FROM invoices WHERE id = :id FOR UPDATE');
    $inv->execute(['id' => $invoiceId]);
    $inv = $inv->fetch();
    if (!$inv) return false;

    $payAmount = min($amount, (float) $inv['outstanding_balance']);
    if ($payAmount <= 0) return false;

    $pdo->prepare('INSERT INTO invoice_payments (invoice_id, customer_id, amount, payment_method, reference_no, receipt_path, notes, recorded_by) VALUES (:iid, :cid, :amt, :meth, :ref, :rcpt, :notes, :uid)')
        ->execute(['iid' => $invoiceId, 'cid' => $inv['customer_id'], 'amt' => $payAmount, 'meth' => $method, 'ref' => $ref, 'rcpt' => $receipt, 'notes' => $notes, 'uid' => $userId]);

    syncInvoicePayments($pdo, $invoiceId);

    // Keep credit metrics in sync (idempotent)
    if (function_exists('creditRecalcUsed')) {
        creditRecalcUsed($pdo, (int) $inv['customer_id']);
    }
    return true;
}

/**
 * Total amount paid against an invoice (from the invoice_payments ledger).
 * Single source of truth for the "paid amount" formula.
 */
function workflowGetInvoicePaidAmount(PDO $pdo, int $invoiceId): float {
    $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE invoice_id = :iid');
    $sumStmt->execute(['iid' => $invoiceId]);
    return (float) $sumStmt->fetchColumn();
}

/**
 * Derive invoice payment_status from total vs paid amounts.
 * Single source of truth for the status formula.
 */
function workflowInvoicePaymentStatus(float $total, float $paid): string {
    if ($total > 0 && $paid >= $total) return 'paid';
    if ($paid > 0) return 'partial';
    return 'unpaid';
}

/**
 * Recalculate invoice amount_paid, outstanding_balance, payment_status
 * from the invoice_payments ledger. Single source of truth.
 */
function syncInvoicePayments(PDO $pdo, int $invoiceId): void {
    require_once __DIR__ . '/workflow_engine.php';

    $totalPaid = workflowGetInvoicePaidAmount($pdo, $invoiceId);

    $inv = $pdo->prepare('SELECT total_amount, payment_status FROM invoices WHERE id = :id');
    $inv->execute(['id' => $invoiceId]);
    $invRow = $inv->fetch();
    $grandTotal = (float) $invRow['total_amount'];
    $oldStatus = $invRow['payment_status'];

    $outstanding = max($grandTotal - $totalPaid, 0);
    $status = workflowInvoicePaymentStatus($grandTotal, $totalPaid);

    $pdo->prepare('UPDATE invoices SET amount_paid = :paid, outstanding_balance = :out, payment_status = :status WHERE id = :id')
        ->execute(['paid' => $totalPaid, 'out' => $outstanding, 'status' => $status, 'id' => $invoiceId]);

    if ($status !== $oldStatus) {
        WorkflowEngine::logStatusChange($pdo, 'invoice', $invoiceId, $oldStatus, $status, null, 'Payment synced from invoice_payments ledger');
    }
}

/**
 * Get all payments for an invoice.
 */
function getInvoicePayments(PDO $pdo, int $invoiceId): array {
    $stmt = $pdo->prepare('SELECT ip.*, u.username AS recorded_by_name FROM invoice_payments ip LEFT JOIN users u ON ip.recorded_by = u.id WHERE ip.invoice_id = :iid ORDER BY ip.created_at ASC');
    $stmt->execute(['iid' => $invoiceId]);
    return $stmt->fetchAll();
}
