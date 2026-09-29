<?php
/**
 * AuditService
 *
 * Centralized audit logging. Every important operation must create an audit
 * trail. This service owns ALL writes to status_history, payment_log,
 * delivery_logs, bank_audit_log, credit_audit_log and cms_activity_log.
 *
 * Pages must never insert into these tables directly.
 */
class AuditService
{
    public static function status(PDO $pdo, string $workflow, int $entityId, ?string $from, string $to, ?int $userId = null, ?string $notes = null): void
    {
        WorkflowEngine::logStatusChange($pdo, $workflow, $entityId, $from, $to, $userId, $notes);
    }

    public static function payment(PDO $pdo, int $orderId, int $customerId, string $action, ?float $amount = null, ?string $method = null, ?string $transRef = null, ?int $userId = null, ?string $notes = null): void
    {
        require_once __DIR__ . '/../includes/workflow_helpers.php';
        logPaymentAction($pdo, $orderId, $customerId, $action, $amount, $method, $transRef, $userId, $notes);
    }

    public static function delivery(PDO $pdo, int $orderId, string $action, ?string $oldStatus, ?string $newStatus, ?int $userId, ?string $notes = ''): void
    {
        require_once __DIR__ . '/../includes/delivery_helpers.php';
        delLog($pdo, $orderId, $action, $oldStatus, $newStatus, $userId, $notes);
    }

    public static function bank(PDO $pdo, ?int $userId, string $username, string $action, string $entityType, ?int $entityId, ?string $oldValue = null, ?string $newValue = null): void
    {
        require_once __DIR__ . '/../includes/finance_helpers.php';
        bankAuditLog($pdo, $userId, $username, $action, $entityType, $entityId, $oldValue, $newValue);
    }

    public static function credit(PDO $pdo, int $customerId, string $action, ?string $oldVal = null, ?string $newVal = null, ?int $userId = null): void
    {
        require_once __DIR__ . '/../includes/credit_helpers.php';
        creditLogAudit($pdo, $customerId, $action, $oldVal, $newVal, $userId);
    }

    public static function cms(PDO $pdo, ?int $userId, string $action, string $entityType, ?int $entityId = null, ?string $summary = null): void
    {
        try {
            require_once __DIR__ . '/../includes/auto_migrate.php';
            ensureCmsActivityMigration($pdo);
            if ($entityType === 'page' || $entityType === 'pages') {
                // CMS page actions are stored against page_id/details for the
                // legacy CMS log viewer (joins pages on page_id).
                $stmt = $pdo->prepare('INSERT INTO cms_activity_log (user_id, action, entity_type, entity_id, summary, page_id, details, ip_address, created_at) VALUES (:uid, :act, :et, :eid, :sum, :pid, :det, :ip, NOW())');
                $stmt->execute([
                    'uid' => $userId,
                    'act' => $action,
                    'et' => $entityType,
                    'eid' => $entityId,
                    'sum' => $summary,
                    'pid' => $entityId,
                    'det' => $summary,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO cms_activity_log (user_id, action, entity_type, entity_id, summary, ip_address, created_at) VALUES (:uid, :act, :et, :eid, :sum, :ip, NOW())');
                $stmt->execute([
                    'uid' => $userId,
                    'act' => $action,
                    'et' => $entityType,
                    'eid' => $entityId,
                    'sum' => $summary,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
            }
        } catch (Throwable $e) { /* audit must never break the request */ }
    }

    public static function exception(string $context, Throwable $e): void
    {
        error_log('[ERP] ' . $context . ': ' . $e->getMessage());
    }
}
