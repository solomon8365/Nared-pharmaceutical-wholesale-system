<?php
/**
 * DeliveryService
 *
 * Centralized delivery workflow operations and queries. Delivery status
 * changes happen ONLY through WorkflowService; this service orchestrates
 * the delivery-specific business rules around them.
 */
class DeliveryService
{
    public static function helpers(): void
    {
        require_once __DIR__ . '/../includes/delivery_helpers.php';
    }

    public static function ensureMigration(PDO $pdo): void
    {
        static::helpers();
        ensureDeliveryErpMigration($pdo);
        require_once __DIR__ . '/../includes/auto_migrate.php';
        ensureFulfillmentMigration($pdo);
    }

    /**
     * Assign one delivery to a driver (delivery_assign page).
     * Order must be approved + payment confirmed, in the delivery lane and
     * routed (Ready for Delivery), and not already assigned.
     */
    public static function assignSingle(PDO $pdo, int $orderId, int $driverId, int $userId): array
    {
        static::helpers();
        if ($orderId <= 0 || $driverId <= 0) {
            return ['success' => false, 'msg' => 'Invalid order or driver.'];
        }
        $check = $pdo->prepare("SELECT id, delivery_staff_id, delivery_status FROM customer_orders WHERE id = ? AND status = 'approved' AND payment_confirmed = 1 AND fulfillment_method = 'delivery' AND fulfillment_status = 'ready_for_delivery'");
        $check->execute([$orderId]);
        $order = $check->fetch();
        if (!$order) {
            return ['success' => false, 'msg' => 'Order not found or not ready.'];
        }
        if ($order['delivery_staff_id'] !== null) {
            return ['success' => false, 'msg' => "Order #{$orderId} is already assigned. Cannot assign twice."];
        }
        if (!WorkflowService::canTransition('delivery', $order['delivery_status'], 'assigned')) {
            return ['success' => false, 'msg' => "Order #{$orderId} cannot be assigned from its current delivery status."];
        }
        $driver = delGetDriverProfile($pdo, $driverId);
        if (!$driver) {
            return ['success' => false, 'msg' => 'Driver profile not found.'];
        }
        $res = WorkflowService::transition($pdo, 'delivery', $orderId, 'assigned', [
            'extra' => ['delivery_staff_id' => $driverId, 'assigned_at' => date('Y-m-d H:i:s'), 'assigned_by' => $userId],
            'changed_by' => $userId,
            'notes' => "Assigned to {$driver['full_name']}",
        ]);
        if (!$res['success']) {
            return ['success' => false, 'msg' => $res['error']];
        }
        delSyncDriverAvailability($pdo, $driverId);
        delLog($pdo, $orderId, 'assigned', null, 'assigned', $userId, "Assigned to {$driver['full_name']}");
        return ['success' => true, 'msg' => "Order #{$orderId} assigned to " . $driver['full_name'] . "."];
    }

    /** Bulk-assign several orders to one driver. */
    public static function bulkAssign(PDO $pdo, array $orderIds, int $driverId, int $userId): array
    {
        static::helpers();
        if (empty($orderIds) || $driverId <= 0) {
            return ['success' => false, 'msg' => 'Select orders and a driver.'];
        }
        $driver = delGetDriverProfile($pdo, $driverId);
        if (!$driver) {
            return ['success' => false, 'msg' => 'Driver profile not found.'];
        }
        $assigned = 0;
        $skipped = 0;
        foreach ($orderIds as $oid) {
            $oid = (int) $oid;
            $check = $pdo->prepare("SELECT id, delivery_staff_id, delivery_status FROM customer_orders WHERE id = ? AND status = 'approved' AND payment_confirmed = 1 AND fulfillment_method = 'delivery' AND fulfillment_status = 'ready_for_delivery'");
            $check->execute([$oid]);
            $o = $check->fetch();
            if (!$o || $o['delivery_staff_id'] !== null) {
                $skipped++;
                continue;
            }
            if (!WorkflowService::canTransition('delivery', $o['delivery_status'], 'assigned')) {
                $skipped++;
                continue;
            }
            $res = WorkflowService::transition($pdo, 'delivery', $oid, 'assigned', [
                'extra' => ['delivery_staff_id' => $driverId, 'assigned_at' => date('Y-m-d H:i:s'), 'assigned_by' => $userId],
                'changed_by' => $userId,
                'notes' => "Bulk assigned to {$driver['full_name']}",
            ]);
            if (!$res['success']) {
                $skipped++;
                continue;
            }
            delLog($pdo, $oid, 'assigned', null, 'assigned', $userId, "Bulk assigned to {$driver['full_name']}");
            $assigned++;
        }
        if ($assigned > 0) {
            delSyncDriverAvailability($pdo, $driverId);
        }
        $msg = "{$assigned} order(s) assigned to " . $driver['full_name'] . ".";
        if ($skipped > 0) $msg .= " {$skipped} skipped (already assigned).";
        return ['success' => $assigned > 0, 'msg' => $msg];
    }

    /** Reassign an assigned order to a different driver. */
    public static function reassignToDriver(PDO $pdo, int $orderId, int $newDriverId, int $userId): array
    {
        static::helpers();
        if ($orderId <= 0 || $newDriverId <= 0) {
            return ['success' => false, 'msg' => 'Invalid order or driver.'];
        }
        $check = $pdo->prepare("SELECT id, delivery_staff_id, delivery_status FROM customer_orders WHERE id = ?");
        $check->execute([$orderId]);
        $o = $check->fetch();
        if (!$o) {
            return ['success' => false, 'msg' => 'Order not found.'];
        }
        $oldDriverId = (int) $o['delivery_staff_id'];
        $oldStatus = $o['delivery_status'];
        if (!in_array($oldStatus, ['assigned', 'accepted', 'picked_up'], true)) {
            return ['success' => false, 'msg' => "Order #{$orderId} cannot be reassigned from its current status."];
        }
        $newDriver = delGetDriverProfile($pdo, $newDriverId);
        if (!$newDriver) {
            return ['success' => false, 'msg' => 'New driver profile not found.'];
        }
        // Reassign is unassign (-> null) followed by assign (-> assigned); the
        // workflow lane does not allow a direct assigned -> assigned move.
        try {
            WorkflowService::reassignDelivery($pdo, $orderId, $userId, 'Reassign to ' . $newDriver['full_name']);
        } catch (ServiceException $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }
        $res = WorkflowService::transition($pdo, 'delivery', $orderId, 'assigned', [
            'extra' => [
                'delivery_staff_id' => $newDriverId,
                'assigned_at' => date('Y-m-d H:i:s'),
                'assigned_by' => $userId,
                'accepted_at' => null,
                'picked_up_at' => null,
                'dispatch_time' => null,
            ],
            'changed_by' => $userId,
            'notes' => "Reassigned from driver #{$oldDriverId} to {$newDriver['full_name']}",
        ]);
        if (!$res['success']) {
            return ['success' => false, 'msg' => $res['error']];
        }
        delSyncDriverAvailability($pdo, $oldDriverId);
        delSyncDriverAvailability($pdo, $newDriverId);
        delLog($pdo, $orderId, 'reassigned', $oldStatus, 'assigned', $userId, "Reassigned from driver #{$oldDriverId} to {$newDriver['full_name']}");
        return ['success' => true, 'msg' => "Order #{$orderId} reassigned to " . $newDriver['full_name'] . "."];
    }

    /** Unassign a delivery before dispatch. */
    public static function unassign(PDO $pdo, int $orderId, int $userId): array
    {
        static::helpers();
        if ($orderId <= 0) {
            return ['success' => false, 'msg' => 'Invalid order.'];
        }
        $check = $pdo->prepare("SELECT id, delivery_staff_id, delivery_status FROM customer_orders WHERE id = ?");
        $check->execute([$orderId]);
        $o = $check->fetch();
        if (!$o || $o['delivery_staff_id'] === null) {
            return ['success' => false, 'msg' => 'Order not found or not assigned.'];
        }
        if (!WorkflowService::canTransition('delivery', $o['delivery_status'], null)) {
            return ['success' => false, 'msg' => 'Cannot unassign order in status: ' . str_replace('_', ' ', $o['delivery_status'] ?? '') . '.'];
        }
        $oldDriverId = (int) $o['delivery_staff_id'];
        $oldStatus = $o['delivery_status'];
        $res = WorkflowService::transition($pdo, 'delivery', $orderId, null, [
            'extra' => [
                'delivery_staff_id' => null,
                'assigned_at' => null,
                'assigned_by' => null,
                'accepted_at' => null,
                'picked_up_at' => null,
            ],
            'changed_by' => $userId,
            'notes' => 'Admin unassigned',
        ]);
        if (!$res['success']) {
            return ['success' => false, 'msg' => $res['error']];
        }
        delSyncDriverAvailability($pdo, $oldDriverId);
        delLog($pdo, $orderId, 'unassigned', $oldStatus, null, $userId, 'Admin unassigned');
        return ['success' => true, 'msg' => "Order #{$orderId} unassigned."];
    }

    public static function getOrderDelivery(PDO $pdo, int $orderId): ?array
    {
        static::helpers();
        return delGetOrderDelivery($pdo, $orderId);
    }

    public static function getDriverProfile(PDO $pdo, int $driverId): ?array
    {
        static::helpers();
        return delGetDriverProfile($pdo, $driverId);
    }

    public static function getAvailableDrivers(PDO $pdo): array
    {
        static::helpers();
        return delGetAvailableDrivers($pdo);
    }

    public static function getAllDrivers(PDO $pdo): array
    {
        static::helpers();
        return delGetAllDrivers($pdo);
    }

    public static function findInvoiceForOrder(PDO $pdo, int $orderId): ?array
    {
        static::helpers();
        return delFindInvoiceForOrder($pdo, $orderId);
    }

    public static function completeInvoiceForOrder(PDO $pdo, int $orderId, ?int $userId = null): void
    {
        static::helpers();
        delCompleteInvoiceForOrder($pdo, $orderId, $userId);
    }

    public static function uploadPodPhoto(): ?string
    {
        static::helpers();
        return delUploadPodPhoto();
    }

    /**
     * Confirm a delivery as completed by delivery staff. Verifies the
     * pod_pending -> delivered transition, completes the invoice and
     * notifies the customer. Runs inside the WorkflowEngine transaction.
     */
    public static function confirmDelivery(PDO $pdo, int $orderId, int $userId): array
    {
        $order = static::getOrderDelivery($pdo, $orderId);
        if (!$order) {
            return ['success' => false, 'msg' => "Order #{$orderId} not found."];
        }
        $oldStatus = $order['delivery_status'] ?? null;
        if (!WorkflowService::canTransition('delivery', $oldStatus, 'delivered')) {
            return ['success' => false, 'msg' => "Order #{$orderId} is not awaiting verification and cannot be confirmed."];
        }
        $result = WorkflowService::transition($pdo, 'delivery', $orderId, 'delivered', [
            'extra' => [
                'pod_verified_by' => $userId,
                'pod_verified_at' => date('Y-m-d H:i:s'),
                'delivery_confirmed_at' => $order['delivery_confirmed_at'] ?: date('Y-m-d H:i:s'),
                'confirmation_method' => $order['confirmation_method'] ?: 'delivery_staff',
            ],
            'changed_by' => $userId,
            'notes' => 'Delivery verified via Delivery Confirmation',
        ]);
        if (!$result['success']) {
            return ['success' => false, 'msg' => $result['error']];
        }
        delSyncDriverAvailability($pdo, (int) ($order['delivery_staff_id'] ?? 0));
        AuditService::delivery($pdo, $orderId, 'status_change', $oldStatus, 'delivered', $userId, 'Delivery verified and confirmed');
        static::completeInvoiceForOrder($pdo, $orderId, $userId);
        FulfillmentService::completeDeliveryFulfillment($pdo, $orderId, $userId);
        if ($order['customer_id']) {
            NotificationService::delivery($pdo, (int) $order['customer_id'], $orderId, 'Delivered', "Order #{$orderId} has been delivered.", 'success');
        }
        return ['success' => true, 'msg' => "Order #{$orderId} verified and marked as delivered. Invoice completed."];
    }

    /**
     * Customer confirms receipt via the invoice page. Records the POD
     * signature and moves the delivery to pod_pending for staff verification.
     */
    public static function confirmViaInvoice(PDO $pdo, int $orderId, string $signature, int $userId): array
    {
        $order = static::getOrderDelivery($pdo, $orderId);
        if (!$order) {
            return ['success' => false, 'msg' => 'Order not found.'];
        }
        $orderStatus = $order['delivery_status'] ?? null;
        if (!WorkflowService::canTransition('delivery', $orderStatus, 'pod_pending')) {
            return ['success' => false, 'msg' => 'Order is not out for delivery and cannot be confirmed.'];
        }
        $now = date('Y-m-d H:i:s');
        $result = WorkflowService::transition($pdo, 'delivery', $orderId, 'pod_pending', [
            'extra' => [
                'receiver_signature' => $signature,
                'delivered_at' => $now,
                'delivery_confirmed_at' => $now,
                'confirmation_method' => 'invoice',
                'pod_recorded_at' => $now,
                'pod_recorded_by' => $userId,
            ],
            'changed_by' => $userId,
            'notes' => 'Customer confirmed receipt via invoice signature',
        ]);
        if (!$result['success']) {
            return ['success' => false, 'msg' => 'Could not confirm. ' . $result['error']];
        }
        delSyncDriverAvailability($pdo, (int) ($order['delivery_staff_id'] ?? 0));
        return ['success' => true, 'msg' => 'Receipt confirmed! Our delivery staff will verify and complete the delivery.'];
    }

    /** Mark a delivery as failed (from out_for_delivery). */
    public static function markFailed(PDO $pdo, int $orderId, int $userId, string $reason, string $notes = ''): array
    {
        $order = static::getOrderDelivery($pdo, $orderId);
        $oldStatus = $order['delivery_status'] ?? null;
        if (!WorkflowService::canTransition('delivery', $oldStatus, 'failed')) {
            return ['success' => false, 'msg' => 'This delivery cannot be marked as failed from its current status.'];
        }
        $result = WorkflowService::transition($pdo, 'delivery', $orderId, 'failed', [
            'extra' => ['failed_reason' => $reason, 'failed_notes' => $notes !== '' ? $notes : null],
            'changed_by' => $userId,
            'notes' => 'Delivery failed: ' . $reason,
        ]);
        if (!$result['success']) {
            return ['success' => false, 'msg' => $result['error']];
        }
        delSyncDriverAvailability($pdo, (int) ($order['delivery_staff_id'] ?? 0));
        AuditService::delivery($pdo, $orderId, 'failed', $oldStatus, 'failed', $userId, $reason);
        return ['success' => true, 'msg' => "Order #{$orderId} marked as failed."];
    }

    /** Record a delivery return (from delivered state). */
    public static function returnOrder(PDO $pdo, int $orderId, int $userId, string $reason, string $notes = ''): array
    {
        $order = static::getOrderDelivery($pdo, $orderId);
        $oldStatus = $order['delivery_status'] ?? null;
        if (!WorkflowService::canTransition('delivery', $oldStatus, 'returned')) {
            return ['success' => false, 'msg' => 'Only delivered orders can be returned.'];
        }
        $result = WorkflowService::transition($pdo, 'delivery', $orderId, 'returned', [
            'extra' => ['return_reason' => $reason, 'return_notes' => $notes !== '' ? $notes : null],
            'changed_by' => $userId,
            'notes' => 'Delivery returned: ' . $reason,
        ]);
        if (!$result['success']) {
            return ['success' => false, 'msg' => $result['error']];
        }
        delSyncDriverAvailability($pdo, (int) ($order['delivery_staff_id'] ?? 0));
        AuditService::delivery($pdo, $orderId, 'returned', $oldStatus, 'returned', $userId, $reason);
        return ['success' => true, 'msg' => "Order #{$orderId} marked as returned."];
    }
}
