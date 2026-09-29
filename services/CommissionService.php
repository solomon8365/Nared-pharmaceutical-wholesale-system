<?php
/**
 * CommissionService
 *
 * Centralized commission lifecycle: approve, reject, pay (single and batch)
 * and new-customer bonus creation. Statuses come from StatusCatalog.
 */
class CommissionService
{
    /** Approve a single pending commission. Returns true when changed. */
    public static function approve(PDO $pdo, int $commissionId, int $userId): bool
    {
        $stmt = $pdo->prepare(
            "UPDATE commissions SET status = :status, approved_by = :uid, approved_at = NOW()
             WHERE id = :id AND status = :from"
        );
        $stmt->execute([
            'status' => StatusCatalog::COMMISSION_APPROVED,
            'from' => StatusCatalog::COMMISSION_PENDING,
            'uid' => $userId,
            'id' => $commissionId,
        ]);
        return (bool) $stmt->rowCount();
    }

    /** Reject a single pending commission. Returns true when changed. */
    public static function reject(PDO $pdo, int $commissionId, int $userId, ?string $reason = null): bool
    {
        $stmt = $pdo->prepare(
            "UPDATE commissions SET status = :status, rejection_reason = :reason,
                approved_by = :uid, approved_at = NOW()
             WHERE id = :id AND status = :from"
        );
        $stmt->execute([
            'status' => StatusCatalog::COMMISSION_REJECTED,
            'from' => StatusCatalog::COMMISSION_PENDING,
            'reason' => $reason ?: null,
            'uid' => $userId,
            'id' => $commissionId,
        ]);
        return (bool) $stmt->rowCount();
    }

    /** Mark an approved commission as paid. Returns true when changed. */
    public static function pay(PDO $pdo, int $commissionId, int $userId): bool
    {
        $stmt = $pdo->prepare(
            "UPDATE commissions SET status = :status, paid_by = :uid, paid_at = NOW()
             WHERE id = :id AND status = :from"
        );
        $stmt->execute([
            'status' => StatusCatalog::COMMISSION_PAID,
            'from' => StatusCatalog::COMMISSION_APPROVED,
            'uid' => $userId,
            'id' => $commissionId,
        ]);
        return (bool) $stmt->rowCount();
    }

    /** Batch approve pending commissions. Returns the number changed. */
    public static function approveMany(PDO $pdo, array $ids, int $userId): int
    {
        return static::batchUpdate($pdo, $ids, StatusCatalog::COMMISSION_APPROVED, $userId);
    }

    /** Batch mark approved commissions as paid. Returns the number changed. */
    public static function payMany(PDO $pdo, array $ids, int $userId): int
    {
        return static::batchUpdate($pdo, $ids, StatusCatalog::COMMISSION_PAID, $userId);
    }

    private static function batchUpdate(PDO $pdo, array $ids, string $to, int $userId): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn($id) => $id > 0));
        if (empty($ids)) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        if ($to === StatusCatalog::COMMISSION_PAID) {
            $stmt = $pdo->prepare("UPDATE commissions SET status = :status, paid_by = :uid, paid_at = NOW() WHERE id IN ($placeholders) AND status = :from");
            $params = array_merge([':status' => $to, ':from' => StatusCatalog::COMMISSION_APPROVED, ':uid' => $userId], $ids);
        } else {
            $stmt = $pdo->prepare("UPDATE commissions SET status = :status, approved_by = :uid, approved_at = NOW() WHERE id IN ($placeholders) AND status = :from");
            $params = array_merge([':status' => $to, ':from' => StatusCatalog::COMMISSION_PENDING, ':uid' => $userId], $ids);
        }
        $stmt->execute($params);
        return (int) $stmt->rowCount();
    }

    /**
     * Create a commission record (e.g. auto new-customer bonus).
     * Returns the new commission id.
     */
    public static function create(PDO $pdo, int $salespersonId, string $type, string $refType, int $refId, float $amount, ?string $notes = null): int
    {
        $stmt = $pdo->prepare(
            "INSERT INTO commissions (salesperson_id, commission_type, reference_type, reference_id,
                commission_amount, status, notes, created_at)
             VALUES (:spid, :type, :ref_type, :ref_id, :amt, :status, :notes, NOW())"
        );
        $stmt->execute([
            'spid' => $salespersonId,
            'type' => $type,
            'ref_type' => $refType,
            'ref_id' => $refId,
            'amt' => $amount,
            'status' => StatusCatalog::COMMISSION_PENDING,
            'notes' => $notes ?: null,
        ]);
        return (int) $pdo->lastInsertId();
    }
}
