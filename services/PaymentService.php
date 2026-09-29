<?php
/**
 * PaymentService
 *
 * Centralized payment recording and confirmation. Pages must never insert
 * into invoice_payments / payment_log / sales.paid_at directly; they call
 * this service instead. All multi-step payment operations are transactional.
 */
class PaymentService
{
    public static function helpers(): void
    {
        require_once __DIR__ . '/../includes/workflow_helpers.php';
        require_once __DIR__ . '/../includes/workflow_engine.php';
        require_once __DIR__ . '/../includes/auto_migrate.php';
    }

    /**
     * Record a customer payment against an invoice (with optional receipt
     * upload handled by the caller). Transactional + audit logged.
     */
    public static function recordPayment(PDO $pdo, int $invoiceId, float $amount, string $method, ?string $ref, ?string $receiptPath, ?string $notes, ?int $userId, int $customerId): array
    {
        if ($amount <= 0) {
            return ['success' => false, 'msg' => 'Please enter a valid payment amount.'];
        }
        static::helpers();
        ensureInvoicePaymentsMigration($pdo);
        WorkflowEngine::ensureStatusHistoryTable($pdo);
        $pdo->beginTransaction();
        try {
            $ok = recordInvoicePayment($pdo, $invoiceId, $amount, $method, $ref, $receiptPath, $notes, $userId);
            if (!$ok) {
                throw new RuntimeException('Payment failed. Check invoice or amount.');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            return ['success' => false, 'msg' => $e->getMessage()];
        }
        AuditService::payment($pdo, 0, $customerId, 'payment_recorded', $amount, $method, $ref, $userId, $notes);
        CreditService::recalcUsed($pdo, $customerId);
        return ['success' => true, 'msg' => 'Payment of ' . fmt($amount) . ' recorded successfully!'];
    }

    /**
     * Mark a sale's commission as paid (sales.php). No-op when already paid.
     */
    public static function payCommission(PDO $pdo, int $saleId): array
    {
        $stmt = $pdo->prepare("UPDATE sales SET paid_at = NOW() WHERE id = :id AND paid_at IS NULL AND commission_amount IS NOT NULL AND commission_amount > 0");
        $stmt->execute(['id' => $saleId]);
        if ($stmt->rowCount() > 0) {
            return ['success' => true, 'msg' => 'Commission marked as paid.'];
        }
        return ['success' => false, 'msg' => 'Commission already paid or not found.'];
    }

    public static function getInvoicePayments(PDO $pdo, int $invoiceId): array
    {
        static::helpers();
        return getInvoicePayments($pdo, $invoiceId);
    }
}
