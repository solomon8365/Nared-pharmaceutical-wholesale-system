<?php
/**
 * PurchaseService
 *
 * Centralized purchase order lifecycle. All purchase order transitions go
 * through WorkflowService (workflow: purchase).
 */
class PurchaseService
{
    public static function find(PDO $pdo, int $poId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = :id');
        $stmt->execute(['id' => $poId]);
        return $stmt->fetch() ?: null;
    }

    public static function list(PDO $pdo, string $status = '', string $search = ''): array
    {
        $sql = 'SELECT po.*, s.name AS supplier_name FROM purchase_orders po LEFT JOIN suppliers s ON s.id = po.supplier_id';
        $conds = [];
        $params = [];
        if ($status !== '') {
            $conds[] = 'po.status = :status';
            $params['status'] = $status;
        }
        if ($search !== '') {
            $conds[] = '(po.po_number LIKE :q1 OR s.name LIKE :q2)';
            $params['q1'] = "%$search%";
            $params['q2'] = "%$search%";
        }
        if ($conds) {
            $sql .= ' WHERE ' . implode(' AND ', $conds);
        }
        $sql .= ' ORDER BY po.created_at DESC LIMIT 300';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function create(PDO $pdo, int $supplierId, float $total, ?int $createdBy = null): int
    {
        // PO numbers are derived from the row id (PO-<id>); no po_number column exists.
        $stmt = $pdo->prepare('INSERT INTO purchase_orders (supplier_id, total_amount, status, created_by, created_at) VALUES (:sid, :total, "pending", :cb, NOW())');
        $stmt->execute(['sid' => $supplierId, 'total' => $total, 'cb' => $createdBy]);
        return (int) $pdo->lastInsertId();
    }

    public static function items(PDO $pdo, int $poId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM purchase_order_items WHERE purchase_order_id = :id');
        $stmt->execute(['id' => $poId]);
        return $stmt->fetchAll();
    }

    public static function transition(PDO $pdo, int $poId, string $toStatus, ?int $userId = null, ?string $notes = null): array
    {
        return WorkflowService::transition($pdo, StatusCatalog::WORKFLOW_PURCHASE, $poId, $toStatus, [
            'changed_by' => $userId,
            'notes' => $notes,
        ]);
    }

    public static function canTransition(PDO $pdo, int $poId, string $toStatus): bool
    {
        $po = static::find($pdo, $poId);
        if (!$po) return false;
        return WorkflowService::canTransition(StatusCatalog::WORKFLOW_PURCHASE, (string) $po['status'], $toStatus);
    }
}
