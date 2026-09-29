<?php

class WorkflowEngine {
    private static $workflows = [
        'order' => [
            'table' => 'customer_orders',
            'id_column' => 'id',
            'status_column' => 'status',
            'label' => 'Order',
            'transitions' => [
                'pending' => ['approved', 'rejected', 'cancelled'],
                'approved' => ['rejected'],
                'rejected' => [],
                'cancelled' => [],
            ],
        ],
        'purchase' => [
            'table' => 'purchase_orders',
            'id_column' => 'id',
            'status_column' => 'status',
            'label' => 'Purchase Order',
            'transitions' => [
                'pending' => ['payment_pending', 'cancelled'],
                'payment_pending' => ['payment_approved', 'payment_rejected', 'cancelled'],
                'payment_approved' => ['received', 'cancelled'],
                'payment_rejected' => [],
                'received' => [],
                'cancelled' => [],
            ],
        ],
        'purchase_payment' => [
            'table' => 'purchase_orders',
            'id_column' => 'id',
            'status_column' => 'payment_status',
            'label' => 'Purchase Payment',
            'transitions' => [
                null => ['partial', 'paid'],
                'unpaid' => ['partial', 'paid'],
                'partial' => ['unpaid', 'paid'],
                'paid' => ['partial'],
            ],
        ],
        'delivery' => [
            'table' => 'customer_orders',
            'id_column' => 'id',
            'status_column' => 'delivery_status',
            'label' => 'Delivery',
            'transitions' => [
                null => ['assigned'],
                'assigned' => ['accepted', 'picked_up', null, 'out_for_delivery'],
                'accepted' => ['picked_up', null, 'assigned', 'out_for_delivery'],
                'picked_up' => ['out_for_delivery', null, 'assigned'],
                'out_for_delivery' => ['pod_pending', 'failed'],
                'pod_pending' => ['delivered', 'failed'],
                'delivered' => ['returned'],
                'failed' => [],
                'returned' => [],
            ],
        ],
        'picking' => [
            'table' => 'customer_orders',
            'id_column' => 'id',
            'status_column' => 'picking_status',
            'label' => 'Warehouse Picking',
            'transitions' => [
                null => ['picked'],
                'picked' => ['ready_for_delivery'],
                'ready_for_delivery' => [],
            ],
        ],
        'fulfillment' => [
            'table' => 'customer_orders',
            'id_column' => 'id',
            'status_column' => 'fulfillment_status',
            'label' => 'Order Fulfillment',
            'transitions' => [
                null => ['waiting_stock_check', 'fulfillment_cancelled'],
                'waiting_stock_check' => ['ready_to_prepare', 'out_of_stock', 'fulfillment_cancelled'],
                'ready_to_prepare' => ['reserved', 'out_of_stock', 'fulfillment_cancelled'],
                'reserved' => ['preparing', 'fulfillment_cancelled'],
                'out_of_stock' => ['ready_to_prepare', 'fulfillment_cancelled'],
                'preparing' => ['prepared', 'fulfillment_cancelled'],
                'prepared' => ['paid', 'credit_approved', 'fulfillment_cancelled'],
                'paid' => ['waiting_customer_pickup', 'ready_for_delivery'],
                'credit_approved' => ['waiting_customer_pickup', 'ready_for_delivery'],
                'waiting_customer_pickup' => ['fulfillment_completed'],
                'ready_for_delivery' => ['fulfillment_completed'],
                'fulfillment_completed' => [],
                'fulfillment_cancelled' => [],
            ],
        ],
        'invoice' => [
            'table' => 'invoices',
            'id_column' => 'id',
            'status_column' => 'payment_status',
            'label' => 'Invoice',
            'transitions' => [
                'unpaid' => ['paid', 'partial'],
                'partial' => ['paid'],
                'paid' => [],
            ],
        ],
        'invoice_fulfillment' => [
            'table' => 'invoices',
            'id_column' => 'id',
            'status_column' => 'status',
            'label' => 'Invoice Fulfillment',
            'transitions' => [
                'pending' => ['completed'],
                'completed' => [],
            ],
        ],
        'credit' => [
            'table' => 'customers',
            'id_column' => 'id',
            'status_column' => 'credit_approval_status',
            'label' => 'Credit',
            'transitions' => [
                'pending' => ['approved', 'suspended', 'rejected', 'over_limit'],
                'approved' => ['approved', 'suspended', 'rejected', 'over_limit'],
                'suspended' => ['approved', 'suspended', 'rejected'],
                'rejected' => ['approved', 'rejected'],
                'over_limit' => ['approved', 'suspended', 'rejected'],
                '' => ['pending', 'approved', 'suspended', 'rejected', 'over_limit'],
            ],
        ],
        'inventory' => [
            'table' => 'product_batches',
            'id_column' => 'id',
            'status_column' => 'workflow_status',
            'label' => 'Inventory Batch',
            'transitions' => [
                // Purchase Order -> Goods Receiving -> Batch Verification ->
                // Expiry Verification -> Available for Sales
                null => ['awaiting_batch_verification', 'reorder_needed', 'available_for_sales', 'expired', 'quarantined', 'disposed'],
                'awaiting_batch_verification' => ['awaiting_expiry_verification', 'available_for_sales'],
                'awaiting_expiry_verification' => ['available_for_sales'],
                'available_for_sales' => ['reorder_needed', 'expired', 'quarantined', 'disposed'],
                'reorder_needed' => ['awaiting_batch_verification', 'available_for_sales'],
                'expired' => ['quarantined', 'disposed'],
                'quarantined' => ['disposed'],
                'disposed' => [],
            ],
        ],
    ];

    public static function ensureStatusHistoryTable(PDO $pdo): void {
        static $done = false;
        if ($done) return;
        // Probe first: once the table exists no DDL is ever issued. Issuing DDL
        // inside an active transaction would implicitly COMMIT it in MySQL, so
        // we must avoid CREATE TABLE whenever possible.
        try {
            $pdo->query('SELECT 1 FROM status_history LIMIT 1');
            // to_status must be nullable (delivery unassign records a null status).
            $col = $pdo->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'status_history' AND COLUMN_NAME = 'to_status'")->fetchColumn();
            if ($col === 'NO') {
                $pdo->exec("ALTER TABLE status_history MODIFY COLUMN to_status VARCHAR(50) DEFAULT NULL");
            }
            $done = true;
            return;
        } catch (Exception $e) {
            // Table missing -> fall through and create it.
        }
        $sql = "CREATE TABLE IF NOT EXISTS status_history (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            workflow VARCHAR(50) NOT NULL,
            entity_id INT UNSIGNED NOT NULL,
            from_status VARCHAR(50) DEFAULT NULL,
            to_status VARCHAR(50) DEFAULT NULL,
            changed_by INT UNSIGNED DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_wh_entity (workflow, entity_id),
            INDEX idx_wh_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        try { $pdo->exec($sql); } catch (Exception $e) {}
        $done = true;
    }

    public static function workflowExists(string $workflow): bool {
        return isset(self::$workflows[$workflow]);
    }

    public static function getValidTransitions(string $workflow, ?string $fromStatus): array {
        if (!self::workflowExists($workflow)) return [];
        $wf = self::$workflows[$workflow];
        $key = $fromStatus;
        if (array_key_exists($key, $wf['transitions'])) {
            return $wf['transitions'][$key];
        }
        return [];
    }

    public static function canTransition(string $workflow, ?string $fromStatus, ?string $toStatus): bool {
        $valid = self::getValidTransitions($workflow, $fromStatus);
        return in_array($toStatus, $valid, true);
    }

    public static function logStatusChange(PDO $pdo, string $workflow, int $entityId, ?string $fromStatus, ?string $toStatus, ?int $changedBy = null, ?string $notes = null): void {
        self::ensureStatusHistoryTable($pdo);
        $changedBy = $changedBy ?? (isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null);
        $stmt = $pdo->prepare('INSERT INTO status_history (workflow, entity_id, from_status, to_status, changed_by, notes) VALUES (:wf, :eid, :from, :to, :uid, :notes)');
        $stmt->execute(['wf' => $workflow, 'eid' => $entityId, 'from' => $fromStatus, 'to' => $toStatus, 'uid' => $changedBy, 'notes' => $notes]);
    }

    public static function transition(PDO $pdo, string $workflow, int $entityId, ?string $toStatus, array $options = []): array {
        if (!self::workflowExists($workflow)) {
            return ['success' => false, 'error' => "Unknown workflow: {$workflow}"];
        }
        $wf = self::$workflows[$workflow];
        self::ensureStatusHistoryTable($pdo);

        $skipTransaction = !empty($options['skip_transaction']);
        if (!$skipTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $idCol = $wf['id_column'];
            $stCol = $wf['status_column'];

            if (!$skipTransaction) {
                $stmt = $pdo->prepare("SELECT {$idCol} AS eid, {$stCol} AS estatus FROM {$wf['table']} WHERE {$idCol} = :id FOR UPDATE");
            } else {
                $stmt = $pdo->prepare("SELECT {$idCol} AS eid, {$stCol} AS estatus FROM {$wf['table']} WHERE {$idCol} = :id");
            }
            $stmt->execute(['id' => $entityId]);
            $entity = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$entity) {
                if (!$skipTransaction) $pdo->rollBack();
                return ['success' => false, 'error' => "{$wf['label']} #{$entityId} not found."];
            }

            $fromStatus = $entity['estatus'];

            if (!self::canTransition($workflow, $fromStatus, $toStatus)) {
                if (!$skipTransaction) $pdo->rollBack();
                $fromDisplay = $fromStatus ?? 'none';
                $toDisplay = $toStatus ?? 'none';
                return ['success' => false, 'error' => "Invalid transition: '{$wf['label']}' cannot move from '{$fromDisplay}' to '{$toDisplay}'."];
            }

            $extra = $options['extra'] ?? [];
            $setClauses = ["{$stCol} = :to_status"];
            $params = ['to_status' => $toStatus, 'id' => $entityId];

            foreach ($extra as $col => $val) {
                $setClauses[] = "{$col} = :ext_{$col}";
                $params["ext_{$col}"] = $val;
            }

            $sql = "UPDATE {$wf['table']} SET " . implode(', ', $setClauses) . " WHERE {$idCol} = :id";
            $pdo->prepare($sql)->execute($params);

            $changedBy = $options['changed_by'] ?? (isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null);
            $notes = $options['notes'] ?? null;

            self::logStatusChange($pdo, $workflow, $entityId, $fromStatus, $toStatus, $changedBy, $notes);

            if (!$skipTransaction) $pdo->commit();
            return [
                'success' => true,
                'from' => $fromStatus,
                'to' => $toStatus,
                'entity_id' => $entityId,
                'workflow' => $workflow,
            ];
        } catch (Exception $e) {
            if (!$skipTransaction) $pdo->rollBack();
            return ['success' => false, 'error' => 'Transition failed: ' . $e->getMessage()];
        }
    }

    public static function getStatusHistory(PDO $pdo, string $workflow, int $entityId, int $limit = 50): array {
        self::ensureStatusHistoryTable($pdo);
        $stmt = $pdo->prepare('SELECT sh.*, COALESCE(u.full_name, u.username) AS changed_by_name FROM status_history sh LEFT JOIN users u ON sh.changed_by = u.id WHERE sh.workflow = :wf AND sh.entity_id = :eid ORDER BY sh.created_at DESC LIMIT ' . (int)$limit);
        $stmt->execute(['wf' => $workflow, 'eid' => $entityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
