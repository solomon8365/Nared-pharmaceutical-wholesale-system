<?php
/**
 * ErpService
 *
 * High-level facade over the existing service layer. It gives pages a single
 * import point for the cross-cutting ERP operations that used to be duplicated
 * (transactions, status updates, notifications, audit logging, and the
 * outstanding-balance calculations). Every method delegates to the canonical
 * implementation so there is still exactly one writer per table.
 *
 *   createTransaction()     -> transactional wrapper with rollback + logging
 *   updateStatus()          -> WorkflowService::transition (guarded)
 *   createNotification()    -> NotificationService (channel dispatch)
 *   createAuditLog()        -> AuditService (channel dispatch)
 *   calculateOutstanding()  -> invoice / purchase-payment outstanding
 *   calculateCreditBalance()-> creditRecalcUsed
 */
class ErpService
{
    /**
     * Run $work inside a transaction. Nested-safe: if the PDO connection is
     * already inside a transaction the work runs in that transaction.
     * Throws ServiceException (wrapping the original) on failure.
     */
    public static function createTransaction(PDO $pdo, callable $work): mixed
    {
        $already = $pdo->inTransaction();
        if (!$already) {
            $pdo->beginTransaction();
        }
        try {
            $result = $work($pdo);
            if (!$already) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if (!$already) {
                $pdo->rollBack();
            }
            static::logError('ErpService::createTransaction failed', $e);
            throw $e instanceof ServiceException
                ? $e
                : new ServiceException('Operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Guarded status transition. Delegates to WorkflowService which validates
     * the transition against WorkflowEngine. Returns the engine result array.
     */
    public static function updateStatus(PDO $pdo, string $workflow, int $entityId, ?string $toStatus, array $options = []): array
    {
        return WorkflowService::transition($pdo, $workflow, $entityId, $toStatus, $options);
    }

    /**
     * Central notification dispatch.
     * Channels: order | delivery | credit | finance.
     * $params keys are channel specific; see NotificationService methods.
     */
    public static function createNotification(PDO $pdo, string $channel, array $params): void
    {
        switch ($channel) {
            case 'order':
                NotificationService::order(
                    $pdo,
                    (int) ($params['order_id'] ?? 0),
                    (int) ($params['customer_id'] ?? 0),
                    (string) ($params['title'] ?? ''),
                    (string) ($params['message'] ?? ''),
                    (string) ($params['type'] ?? 'info')
                );
                break;
            case 'delivery':
                NotificationService::delivery(
                    $pdo,
                    (int) ($params['user_id'] ?? 0),
                    isset($params['order_id']) ? (int) $params['order_id'] : null,
                    (string) ($params['title'] ?? ''),
                    (string) ($params['message'] ?? ''),
                    (string) ($params['type'] ?? 'info')
                );
                break;
            case 'credit':
                NotificationService::credit(
                    $pdo,
                    (int) ($params['customer_id'] ?? 0),
                    (string) ($params['type'] ?? 'info'),
                    (string) ($params['title'] ?? ''),
                    (string) ($params['message'] ?? ''),
                    isset($params['user_id']) ? (int) $params['user_id'] : null
                );
                break;
            case 'finance':
                NotificationService::finance(
                    $pdo,
                    isset($params['user_id']) ? (int) $params['user_id'] : null,
                    isset($params['order_id']) ? (int) $params['order_id'] : null,
                    (string) ($params['title'] ?? ''),
                    (string) ($params['message'] ?? ''),
                    (string) ($params['type'] ?? 'info')
                );
                break;
            default:
                throw new ServiceException('Unknown notification channel: ' . $channel);
        }
    }

    /**
     * Central audit dispatch.
     * Channels: status | payment | delivery | bank | credit | cms.
     * $params keys are channel specific; see AuditService methods.
     */
    public static function createAuditLog(PDO $pdo, string $channel, array $params): void
    {
        switch ($channel) {
            case 'status':
                AuditService::status(
                    $pdo,
                    (string) ($params['workflow'] ?? ''),
                    (int) ($params['entity_id'] ?? 0),
                    isset($params['from']) ? $params['from'] : null,
                    (string) ($params['to'] ?? ''),
                    isset($params['user_id']) ? (int) $params['user_id'] : null,
                    isset($params['notes']) ? (string) $params['notes'] : null
                );
                break;
            case 'payment':
                AuditService::payment(
                    $pdo,
                    (int) ($params['order_id'] ?? 0),
                    (int) ($params['customer_id'] ?? 0),
                    (string) ($params['action'] ?? ''),
                    isset($params['amount']) ? (float) $params['amount'] : null,
                    isset($params['method']) ? (string) $params['method'] : null,
                    isset($params['transaction_ref']) ? (string) $params['transaction_ref'] : null,
                    isset($params['user_id']) ? (int) $params['user_id'] : null,
                    isset($params['notes']) ? (string) $params['notes'] : null
                );
                break;
            case 'delivery':
                AuditService::delivery(
                    $pdo,
                    (int) ($params['order_id'] ?? 0),
                    (string) ($params['action'] ?? ''),
                    isset($params['old_status']) ? $params['old_status'] : null,
                    isset($params['new_status']) ? $params['new_status'] : null,
                    isset($params['user_id']) ? (int) $params['user_id'] : null,
                    (string) ($params['notes'] ?? '')
                );
                break;
            case 'bank':
                AuditService::bank(
                    $pdo,
                    isset($params['user_id']) ? (int) $params['user_id'] : null,
                    (string) ($params['username'] ?? ''),
                    (string) ($params['action'] ?? ''),
                    (string) ($params['entity_type'] ?? ''),
                    isset($params['entity_id']) ? (int) $params['entity_id'] : null,
                    isset($params['old_value']) ? (string) $params['old_value'] : null,
                    isset($params['new_value']) ? (string) $params['new_value'] : null
                );
                break;
            case 'credit':
                AuditService::credit(
                    $pdo,
                    (int) ($params['customer_id'] ?? 0),
                    (string) ($params['action'] ?? ''),
                    isset($params['old_value']) ? (string) $params['old_value'] : null,
                    isset($params['new_value']) ? (string) $params['new_value'] : null,
                    isset($params['user_id']) ? (int) $params['user_id'] : null
                );
                break;
            case 'cms':
                AuditService::cms(
                    $pdo,
                    isset($params['user_id']) ? (int) $params['user_id'] : null,
                    (string) ($params['action'] ?? ''),
                    (string) ($params['entity_type'] ?? ''),
                    isset($params['entity_id']) ? (int) $params['entity_id'] : null,
                    isset($params['summary']) ? (string) $params['summary'] : null
                );
                break;
            default:
                throw new ServiceException('Unknown audit channel: ' . $channel);
        }
    }

    /**
     * Outstanding balance for an entity type.
     * Types: 'invoice' (optionally one invoice id), 'purchase_order' /
     * 'payable' (optionally one PO id, else total supplier payables).
     */
    public static function calculateOutstanding(PDO $pdo, string $type, ?int $id = null): float
    {
        if ($type === 'invoice') {
            require_once __DIR__ . '/../includes/workflow_helpers.php';
            if ($id) {
                $stmt = $pdo->prepare('SELECT total_amount FROM invoices WHERE id = :id');
                $stmt->execute(['id' => $id]);
                $total = (float) $stmt->fetchColumn();
                return max($total - workflowGetInvoicePaidAmount($pdo, $id), 0);
            }
            $stmt = $pdo->query('SELECT COALESCE(SUM(outstanding_balance), 0) FROM invoices WHERE payment_status IN ("unpaid","partial")');
            return (float) $stmt->fetchColumn();
        }

        if ($type === 'purchase_order' || $type === 'payable') {
            if ($id) {
                $stmt = $pdo->prepare('SELECT total_amount FROM purchase_orders WHERE id = :id');
                $stmt->execute(['id' => $id]);
                $total = (float) $stmt->fetchColumn();
                $paid = $pdo->prepare('SELECT COALESCE(SUM(base_amount), 0) FROM supplier_payments WHERE order_id = :id');
                $paid->execute(['id' => $id]);
                return max($total - (float) $paid->fetchColumn(), 0);
            }
            require_once __DIR__ . '/../includes/finance_helpers.php';
            return (float) finGetOutstandingPayables($pdo);
        }

        throw new ServiceException('Unknown outstanding type: ' . $type);
    }

    /** Credit used by a customer (delegates to the canonical creditRecalcUsed). */
    public static function calculateCreditBalance(PDO $pdo, int $customerId): float
    {
        require_once __DIR__ . '/../includes/credit_helpers.php';
        return (float) creditRecalcUsed($pdo, $customerId);
    }

    /** Log a technical error with context (never shown raw to users). */
    public static function logError(string $context, Throwable $e): void
    {
        AuditService::exception($context, $e);
    }

    /** Map a technical error to a safe, user-presentable message. */
    public static function friendlyError(Throwable $e): string
    {
        if ($e instanceof ServiceException) {
            return $e->getMessage();
        }
        $sqlState = $e instanceof PDOException && is_array($e->errorInfo) ? ($e->errorInfo[0] ?? '') : '';
        $messages = [
            '23000' => 'A record with the same key already exists.',
            '42S02' => 'Referenced table or column is missing. Please contact support.',
            'HY000' => 'Database connection lost. Please try again.',
        ];
        return $messages[$sqlState] ?? 'An unexpected error occurred. Please try again.';
    }
}
