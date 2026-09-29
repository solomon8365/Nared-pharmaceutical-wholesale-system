<?php
/**
 * InvoiceService
 *
 * Centralized invoice operations. Invoice payment status changes go through
 * WorkflowService/ledger sync; this service owns invoice metadata updates and
 * queries. Pages must never UPDATE invoices.status directly.
 */
class InvoiceService
{
    public static function find(PDO $pdo, int $invoiceId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = :id');
        $stmt->execute(['id' => $invoiceId]);
        return $stmt->fetch() ?: null;
    }

    public static function findForOrder(PDO $pdo, int $orderId): ?array
    {
        $stmt = $pdo->prepare('SELECT id, invoice_no, status, payment_status, total_amount, outstanding_balance FROM invoices WHERE customer_order_id = :oid ORDER BY id DESC LIMIT 1');
        $stmt->execute(['oid' => $orderId]);
        return $stmt->fetch() ?: null;
    }

    public static function generateInvoiceNo(PDO $pdo, int $orderId): string
    {
        return SettingsService::invoicePrefix() . date('Ymd') . '-' . str_pad((string) $orderId, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Create an invoice for an approved order. Transactional by design; the
     * caller may invoke it inside an existing transaction.
     */
    public static function createForOrder(PDO $pdo, int $orderId, int $customerId, float $total, ?string $deliveryAddress = null): int
    {
        $invNo = static::generateInvoiceNo($pdo, $orderId);
        $stmt = $pdo->prepare('INSERT INTO invoices (invoice_no, customer_order_id, customer_id, total_amount, outstanding_balance, payment_status, delivery_address, generated_at) VALUES (:inv, :oid, :cid, :total, :total2, "unpaid", :da, NOW())');
        $stmt->execute(['inv' => $invNo, 'oid' => $orderId, 'cid' => $customerId, 'total' => $total, 'total2' => $total, 'da' => $deliveryAddress]);
        return (int) $pdo->lastInsertId();
    }

    /** Manually set invoice payment state (admin/finance). */
    public static function updatePayment(PDO $pdo, int $invoiceId, string $paymentStatus, float $amountPaid, string $salesType = 'cash', ?int $userId = null): array
    {
        require_once __DIR__ . '/../includes/workflow_engine.php';
        $inv = static::find($pdo, $invoiceId);
        if (!$inv) {
            return ['success' => false, 'msg' => 'Invoice not found.'];
        }
        $tot = (float) $inv['total_amount'];
        if (!in_array($paymentStatus, ['paid', 'partial', 'unpaid'], true)) $paymentStatus = 'unpaid';
        $ap = max(0, min($amountPaid, $tot));
        if ($paymentStatus === 'paid') { $ap = $tot; }
        $oldPs = $inv['payment_status'];
        $pdo->prepare('UPDATE invoices SET payment_status=:ps, amount_paid=:ap, outstanding_balance=:bal, sales_type=:st WHERE id=:id')
            ->execute(['ps' => $paymentStatus, 'ap' => $ap, 'bal' => $tot - $ap, 'st' => $salesType, 'id' => $invoiceId]);
        if ($paymentStatus !== $oldPs) {
            WorkflowEngine::logStatusChange($pdo, 'invoice', $invoiceId, $oldPs, $paymentStatus, $userId, 'Manual payment update');
        }
        CreditService::recalcUsed($pdo, (int) $inv['customer_id']);
        require_once __DIR__ . '/../includes/credit_ledger.php';
        if (function_exists('creditLedgerResetBestEffort')) {
            creditLedgerResetBestEffort($pdo, (int) $inv['customer_id']);
        }
        return ['success' => true, 'msg' => 'Payment updated.'];
    }

    /** Update the digital signature (company signature). */
    public static function saveDigitalSignature(PDO $pdo, int $invoiceId, string $signature): void
    {
        $pdo->prepare('UPDATE invoices SET digital_signature=:s WHERE id=:id')->execute(['s' => $signature, 'id' => $invoiceId]);
    }

    public static function clearDigitalSignature(PDO $pdo, int $invoiceId): void
    {
        $pdo->prepare('UPDATE invoices SET digital_signature=NULL WHERE id=:id')->execute(['id' => $invoiceId]);
    }

    public static function saveCustomerSignature(PDO $pdo, int $invoiceId, string $signature): void
    {
        $pdo->prepare('UPDATE invoices SET customer_signature=:sig WHERE id=:id')->execute(['sig' => $signature, 'id' => $invoiceId]);
    }

    public static function setReturnReference(PDO $pdo, int $invoiceId, ?string $ref): void
    {
        $pdo->prepare('UPDATE invoices SET return_reference_no=:r WHERE id=:id')->execute(['r' => $ref ?: null, 'id' => $invoiceId]);
    }

    public static function setStaffInfo(PDO $pdo, int $invoiceId, ?int $preparedBy, ?int $approvedBy): void
    {
        $pdo->prepare('UPDATE invoices SET prepared_by=:pb, approved_by=:ab WHERE id=:id')
            ->execute(['pb' => $preparedBy ?: null, 'ab' => $approvedBy ?: null, 'id' => $invoiceId]);
    }

    public static function setWarehouse(PDO $pdo, int $invoiceId, ?int $warehouseId): void
    {
        $pdo->prepare('UPDATE invoices SET warehouse_id=:w WHERE id=:id')->execute(['w' => $warehouseId ?: null, 'id' => $invoiceId]);
    }

    public static function setCreditDueDate(PDO $pdo, int $invoiceId, ?string $date): void
    {
        $pdo->prepare('UPDATE invoices SET credit_due_date=:d WHERE id=:id')->execute(['d' => $date ?: null, 'id' => $invoiceId]);
    }

    public static function listForRole(PDO $pdo, ?int $userId = null): array
    {
        $where = '';
        $params = [];
        if (PermissionService::isCustomer()) {
            $cid = AuthenticationService::customerId();
            if (!$cid) return [];
            $where = 'WHERE i.customer_id = :cid';
            $params['cid'] = $cid;
        }
        $sql = "SELECT i.id, i.invoice_no, i.reference_no, i.total_amount, i.payment_status, i.amount_paid,
                       i.outstanding_balance, i.sales_type, i.generated_at,
                       c.name AS customer_name, c.customer_code,
                       co.delivery_status, co.payment_confirmed,
                       u.full_name AS salesperson_name
                FROM invoices i
                JOIN customers c ON i.customer_id = c.id
                JOIN customer_orders co ON i.customer_order_id = co.id
                LEFT JOIN users u ON co.salesperson_id = u.id
                $where
                ORDER BY i.generated_at DESC
                LIMIT 200";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function getPayments(PDO $pdo, int $invoiceId): array
    {
        require_once __DIR__ . '/../includes/workflow_helpers.php';
        return getInvoicePayments($pdo, $invoiceId);
    }
}
