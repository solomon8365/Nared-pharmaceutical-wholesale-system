<?php
/**
 * InventoryService
 *
 * Centralized stock operations: batch intake/use, stock movements, expiry
 * reporting. All stock adjustments funnel through stock_helper functions.
 */
class InventoryService
{
    public static function stockOnHand(PDO $pdo, int $productId): float
    {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM product_batches WHERE product_id = :id AND quantity > 0');
        $stmt->execute(['id' => $productId]);
        return (float) $stmt->fetchColumn();
    }

    public static function addStock(PDO $pdo, int $productId, float $quantity, float $costPrice, ?string $expiryDate = null, ?string $batchNumber = null): void
    {
        $bno = $batchNumber ?: 'B-' . $productId . '-' . date('YmdHis');
        $stmt = $pdo->prepare('INSERT INTO product_batches (product_id, batch_number, quantity, purchase_price, expiry_date, status, created_at) VALUES (:pid, :bno, :qty, :cost, :exp, "active", NOW())');
        $stmt->execute(['pid' => $productId, 'bno' => $bno, 'qty' => $quantity, 'cost' => $costPrice, 'exp' => $expiryDate ?: '2099-12-31']);
    }

    public static function recordMovement(PDO $pdo, int $productId, float $quantityChange, string $reason, ?string $refType = null, ?int $refId = null): void
    {
        require_once __DIR__ . '/../includes/stock_helper.php';
        log_stock_movement($pdo, $productId, (int) $quantityChange, $reason, $refType, $refId);
    }

    public static function deductFromBatches(PDO $pdo, int $productId, float $quantity): void
    {
        require_once __DIR__ . '/../includes/stock_helper.php';
        deduct_from_batches($pdo, $productId, (int) $quantity);
    }

    public static function totals(PDO $pdo, ?int $lowThreshold = null, string $valueColumn = 'price', ?string $status = null): array
    {
        require_once __DIR__ . '/../includes/stock_helper.php';
        return stockTotals($pdo, $lowThreshold, $valueColumn, $status);
    }

    public static function batchExpiryCounts(PDO $pdo): array
    {
        require_once __DIR__ . '/../includes/stock_helper.php';
        return batchExpiryCounts($pdo);
    }

    public static function expiredProductCount(PDO $pdo): int
    {
        require_once __DIR__ . '/../includes/stock_helper.php';
        return expiredProductCount($pdo);
    }

    public static function batches(PDO $pdo, int $productId = 0): array
    {
        $sql = 'SELECT b.*, p.name AS product_name FROM product_batches b JOIN products p ON p.id = b.product_id';
        if ($productId > 0) {
            $stmt = $pdo->prepare('SELECT b.*, p.name AS product_name FROM product_batches b JOIN products p ON p.id = b.product_id WHERE b.product_id = :pid ORDER BY b.expiry_date ASC');
            $stmt->execute(['pid' => $productId]);
            return $stmt->fetchAll();
        }
        return $pdo->query($sql . ' ORDER BY b.expiry_date ASC')->fetchAll();
    }
}
