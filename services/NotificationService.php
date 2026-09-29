<?php
/**
 * NotificationService
 *
 * Centralized notification sending. All notifications (order, delivery,
 * credit, finance) must be dispatched through this service so that pages
 * never write to notification tables directly.
 */
class NotificationService
{
    public static function order(PDO $pdo, int $orderId, int $customerId, string $title, string $message, string $type = 'info'): void
    {
        require_once __DIR__ . '/../includes/workflow_helpers.php';
        createOrderNotification($pdo, $orderId, $customerId, $title, $message, $type);
    }

    public static function delivery(PDO $pdo, int $userId, ?int $orderId, string $title, string $message, string $type = 'info'): void
    {
        require_once __DIR__ . '/../includes/delivery_helpers.php';
        delNotify($pdo, $userId, $orderId, $title, $message, $type);
    }

    public static function credit(PDO $pdo, int $customerId, string $type, string $title, string $message, ?int $userId = null): void
    {
        require_once __DIR__ . '/../includes/credit_helpers.php';
        creditNotify($pdo, $customerId, $type, $title, $message, $userId);
    }

    public static function finance(PDO $pdo, ?int $userId, ?int $orderId, string $title, string $message, string $type = 'info'): void
    {
        try {
            $severity = in_array($type, ['warning', 'critical'], true) ? $type : 'info';
            $stmt = $pdo->prepare('INSERT INTO finance_notifications (notification_type, title, message, reference_type, reference_id, severity, created_for) VALUES (:ntype, :title, :msg, :rtype, :rid, :sev, :uid)');
            $stmt->execute([
                'ntype' => 'system',
                'title' => $title,
                'msg' => $message,
                'rtype' => $orderId !== null ? 'order' : null,
                'rid' => $orderId,
                'sev' => $severity,
                'uid' => $userId,
            ]);
        } catch (Throwable $e) { /* notification must never break the request */ }
    }

    public static function unreadForCustomer(PDO $pdo, int $customerId): int
    {
        require_once __DIR__ . '/../includes/workflow_helpers.php';
        return getUnreadNotificationCount($pdo, $customerId);
    }

    public static function markCustomerRead(PDO $pdo, int $customerId, ?int $orderId = null): void
    {
        require_once __DIR__ . '/../includes/workflow_helpers.php';
        markNotificationsRead($pdo, $customerId, $orderId);
    }
}