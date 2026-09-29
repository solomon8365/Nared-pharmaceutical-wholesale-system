<?php
/**
 * CreditService
 *
 * Centralized credit management: profile, limits, validation, aging,
 * over-limit status and audit. Wraps the legacy credit_helpers.php.
 */
class CreditService
{
    public static function helpers(): void
    {
        require_once __DIR__ . '/../includes/credit_helpers.php';
    }

    public static function profile(PDO $pdo, int $customerId): array
    {
        static::helpers();
        return creditGetProfile($pdo, $customerId);
    }

    public static function recalcUsed(PDO $pdo, int $customerId): float
    {
        static::helpers();
        return creditRecalcUsed($pdo, $customerId);
    }

    public static function aging(PDO $pdo, ?int $customerId = null): array
    {
        static::helpers();
        return creditGetAging($pdo, $customerId);
    }

    public static function validateOrder(PDO $pdo, int $customerId, float $orderAmount, bool $override = false, ?int $excludeOrderId = null): array
    {
        static::helpers();
        return creditValidateOrder($pdo, $customerId, $orderAmount, $override, $excludeOrderId);
    }

    public static function checkOrder(PDO $pdo, int $customerId, float $orderAmount): array
    {
        static::helpers();
        return creditCheckOrder($pdo, $customerId, $orderAmount);
    }

    public static function applyOverLimitStatus(PDO $pdo, int $customerId): string
    {
        static::helpers();
        return creditApplyOverLimitStatus($pdo, $customerId);
    }

    public static function syncOverdue(PDO $pdo): int
    {
        static::helpers();
        return creditSyncOverdue($pdo);
    }

    public static function applyLateCharges(PDO $pdo): int
    {
        static::helpers();
        return creditApplyLateCharges($pdo);
    }

    public static function autoSuspendOverdue(PDO $pdo): int
    {
        static::helpers();
        return creditAutoSuspendOverdue($pdo);
    }

    public static function restoreAutoSuspended(PDO $pdo, ?int $customerId = null): int
    {
        static::helpers();
        return creditRestoreAutoSuspended($pdo, $customerId);
    }

    public static function calculateScore(PDO $pdo, int $customerId): int
    {
        static::helpers();
        return creditCalculateScore($pdo, $customerId);
    }

    public static function getLimit(PDO $pdo, int $customerId): float
    {
        static::helpers();
        return creditGetLimit($pdo, $customerId);
    }

    public static function setLimit(PDO $pdo, int $customerId, float $newLimit): void
    {
        static::helpers();
        creditSetLimit($pdo, $customerId, $newLimit);
    }

    public static function approvalStatus(PDO $pdo, int $customerId): ?string
    {
        static::helpers();
        return creditGetApprovalStatus($pdo, $customerId);
    }

    public static function approve(PDO $pdo, int $customerId, ?int $userId = null): void
    {
        $old = static::approvalStatus($pdo, $customerId);
        $res = WorkflowService::transition($pdo, StatusCatalog::WORKFLOW_CREDIT, $customerId, StatusCatalog::CREDIT_APPROVED, [
            'extra' => ['is_credit_approved' => 1],
            'changed_by' => $userId,
        ]);
        if (!$res['success']) {
            throw new ServiceException($res['error']);
        }
        $pdo->prepare('UPDATE customers SET credit_approved_by = :by, credit_approved_at = NOW() WHERE id = :id')
            ->execute(['by' => $userId, 'id' => $customerId]);
        AuditService::credit($pdo, $customerId, 'credit_approved', $old, StatusCatalog::CREDIT_APPROVED, $userId);
        NotificationService::credit($pdo, $customerId, 'success', 'Credit Approved', 'Your credit account has been approved.');
    }

    public static function suspend(PDO $pdo, int $customerId, ?int $userId = null): void
    {
        $old = static::approvalStatus($pdo, $customerId);
        $res = WorkflowService::transition($pdo, StatusCatalog::WORKFLOW_CREDIT, $customerId, StatusCatalog::CREDIT_SUSPENDED, [
            'extra' => ['is_credit_approved' => 0],
            'changed_by' => $userId,
        ]);
        if (!$res['success']) {
            throw new ServiceException($res['error']);
        }
        AuditService::credit($pdo, $customerId, 'credit_suspended', $old, StatusCatalog::CREDIT_SUSPENDED, $userId);
        NotificationService::credit($pdo, $customerId, 'warning', 'Credit Suspended', 'Your credit account has been suspended.');
    }

    public static function reject(PDO $pdo, int $customerId, ?int $userId = null): void
    {
        $old = static::approvalStatus($pdo, $customerId);
        $res = WorkflowService::transition($pdo, StatusCatalog::WORKFLOW_CREDIT, $customerId, StatusCatalog::CREDIT_REJECTED, [
            'extra' => ['is_credit_approved' => 0],
            'changed_by' => $userId,
        ]);
        if (!$res['success']) {
            throw new ServiceException($res['error']);
        }
        AuditService::credit($pdo, $customerId, 'credit_rejected', $old, StatusCatalog::CREDIT_REJECTED, $userId);
        NotificationService::credit($pdo, $customerId, 'error', 'Credit Rejected', 'Your credit application was rejected.');
    }

    public static function resume(PDO $pdo, int $customerId, ?int $userId = null): void
    {
        $old = static::approvalStatus($pdo, $customerId);
        $res = WorkflowService::transition($pdo, StatusCatalog::WORKFLOW_CREDIT, $customerId, StatusCatalog::CREDIT_APPROVED, [
            'extra' => ['is_credit_approved' => 1],
            'changed_by' => $userId,
        ]);
        if (!$res['success']) {
            throw new ServiceException($res['error']);
        }
        $pdo->prepare('UPDATE customers SET credit_approved_by = :by, credit_approved_at = NOW() WHERE id = :id')
            ->execute(['by' => $userId, 'id' => $customerId]);
        AuditService::credit($pdo, $customerId, 'credit_resumed', $old, StatusCatalog::CREDIT_APPROVED, $userId);
        NotificationService::credit($pdo, $customerId, 'success', 'Credit Resumed', 'Your credit account is active again.');
    }

    public static function recordPayment(PDO $pdo, int $customerId, float $amount, string $payDate, string $method, string $refNo, string $notes, ?int $userId = null, ?string &$error = null): bool
    {
        static::helpers();
        return creditRecordPayment($pdo, $customerId, $amount, $payDate, $method, $refNo, $notes, $userId, $error);
    }

    /** Reduce a credit invoice's outstanding balance when a customer refund is processed. */
    public static function applyRefund(PDO $pdo, int $customerId, int $orderId, float $amount, ?int $userId = null): array
    {
        static::helpers();
        return creditApplyRefund($pdo, $customerId, $orderId, $amount, $userId);
    }

    public static function customerName(PDO $pdo, int $customerId): string
    {
        static::helpers();
        return customerName($pdo, $customerId);
    }

    public static function isSalesEnabled(): bool
    {
        static::helpers();
        return isCreditSalesEnabled();
    }

    public static function setting(PDO $pdo, string $key, string $default = ''): string
    {
        static::helpers();
        return creditGetSetting($pdo, $key, $default);
    }

    public static function activeAgreement(PDO $pdo): array
    {
        static::helpers();
        return creditGetActiveAgreement($pdo);
    }

    public static function publishAgreement(PDO $pdo, ?int $userId = null): array
    {
        static::helpers();
        return creditPublishAgreement($pdo, $userId);
    }

    public static function agreementVersion(PDO $pdo, ?int $id): ?array
    {
        static::helpers();
        return creditGetAgreementVersion($pdo, $id);
    }

    public static function requireAgreementAcceptance(PDO $pdo): bool
    {
        static::helpers();
        return creditRequireAgreementAcceptance($pdo);
    }

    public static function agreementTermsForOrder(PDO $pdo, ?int $agreementVersionId, ?int $customerId = null): int
    {
        static::helpers();
        return creditAgreementTermsForOrder($pdo, $agreementVersionId, $customerId);
    }

    public static function canOverride(): bool
    {
        static::helpers();
        return creditCanOverride();
    }

    /** Write off an outstanding invoice balance (bad debt). */
    public static function writeOff(PDO $pdo, int $customerId, int $invoiceId, float $amount, string $reason, ?int $userId): array
    {
        static::helpers();
        $stmt = $pdo->prepare('SELECT outstanding_balance, payment_status FROM invoices WHERE id = :id AND customer_id = :cid');
        $stmt->execute(['id' => $invoiceId, 'cid' => $customerId]);
        $inv = $stmt->fetch();
        if (!$inv) {
            return ['success' => false, 'msg' => 'Invoice not found for this customer.'];
        }
        $oldBal = (float) $inv['outstanding_balance'];
        $amount = max(0, min($amount, $oldBal));
        if ($amount <= 0) {
            return ['success' => false, 'msg' => 'Invoice has no outstanding balance.'];
        }
        $newBal = round($oldBal - $amount, 2);
        $newStatus = $newBal <= 0 ? 'paid' : 'partial';
        // Keep amount_paid consistent so total - amount_paid still equals outstanding_balance
        $totStmt = $pdo->prepare('SELECT total_amount, amount_paid FROM invoices WHERE id = :id');
        $totStmt->execute(['id' => $invoiceId]);
        $invTot = $totStmt->fetch();
        $amountPaid = $invTot ? max((float) $invTot['amount_paid'], (float) $invTot['total_amount'] - $newBal) : 0;
        $pdo->prepare('UPDATE invoices SET outstanding_balance = :bal, amount_paid = :ap, payment_status = :ps WHERE id = :id')->execute(['bal' => $newBal, 'ap' => $amountPaid, 'ps' => $newStatus, 'id' => $invoiceId]);
        AuditService::credit($pdo, $customerId, 'write_off', (string) $oldBal, json_encode(['invoice_id' => $invoiceId, 'amount' => $amount, 'reason' => $reason]), $userId);
        if ($newStatus !== $inv['payment_status']) {
            require_once __DIR__ . '/../includes/workflow_engine.php';
            WorkflowEngine::logStatusChange($pdo, 'invoice', $invoiceId, $inv['payment_status'], $newStatus, $userId, 'Write-off: ' . $reason);
        }
        static::recalcUsed($pdo, $customerId);
        return ['success' => true, 'msg' => 'Write-off of ' . fmt($amount) . ' applied to Invoice #' . $invoiceId . '.', 'written_off' => $amount];
    }

    /** Update the credit due date of an invoice. */
    public static function updateDueDate(PDO $pdo, int $customerId, int $invoiceId, string $newDate, ?int $userId): array
    {
        if ($newDate === '') {
            return ['success' => false, 'msg' => 'Please provide a due date.'];
        }
        $stmt = $pdo->prepare('SELECT credit_due_date FROM invoices WHERE id = :id AND customer_id = :cid');
        $stmt->execute(['id' => $invoiceId, 'cid' => $customerId]);
        $inv = $stmt->fetch();
        if (!$inv) {
            return ['success' => false, 'msg' => 'Invoice not found for this customer.'];
        }
        $old = $inv['credit_due_date'];
        $pdo->prepare('UPDATE invoices SET credit_due_date = :d WHERE id = :id')->execute(['d' => $newDate, 'id' => $invoiceId]);
        AuditService::credit($pdo, $customerId, 'due_date_updated', $old, $newDate, $userId);
        return ['success' => true, 'msg' => 'Due date updated for Invoice #' . $invoiceId . '.'];
    }
}
