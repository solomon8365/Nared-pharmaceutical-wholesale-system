<?php

function log_stock_movement(PDO $pdo, int $productId, int $quantityChange, string $reason, ?string $refType = null, ?int $refId = null): void
{
    $stmt = $pdo->prepare('INSERT INTO stock_movements (product_id, quantity_change, reason, reference_type, reference_id, created_at) VALUES (:pid, :qty, :reason, :ref_type, :ref_id, NOW())');
    $stmt->execute([
        'pid' => $productId,
        'qty' => $quantityChange,
        'reason' => $reason,
        'ref_type' => $refType,
        'ref_id' => $refId,
    ]);
}

function has_batches(PDO $pdo, int $productId): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM product_batches WHERE product_id = :pid');
    $stmt->execute(['pid' => $productId]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Recompute products.stock_quantity from AVAILABLE (active) batches only.
 *
 * Expired / quarantined / disposed quantities are NOT sellable, so they are
 * excluded. Products with no batch rows keep their manual stock_quantity.
 * Call this after any operation that changes batch quantities/status
 * (receiving, quarantine, disposal, deduction, returns).
 */
function recalc_product_stock(PDO $pdo, int $productId): void
{
    $count = $pdo->prepare('SELECT COUNT(*) FROM product_batches WHERE product_id = :pid');
    $count->execute(['pid' => $productId]);
    if ((int) $count->fetchColumn() === 0) {
        return; // no-batch product: keep manual stock_quantity
    }

    $st = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM product_batches WHERE product_id = :pid AND status = 'active'");
    $st->execute(['pid' => $productId]);
    $total = (int) $st->fetchColumn();

    $pdo->prepare('UPDATE products SET stock_quantity = :qty WHERE id = :id')
        ->execute(['qty' => max($total, 0), 'id' => $productId]);
}

function stockTotals(PDO $pdo, ?int $lowThreshold = null, string $valueColumn = 'price', ?string $status = null): array
{
    if (!in_array($valueColumn, ['price', 'purchase_price'], true)) {
        $valueColumn = 'price';
    }
    $where = $status !== null ? ' WHERE product_status = ' . $pdo->quote($status) : '';
    $lowCase = $lowThreshold !== null ? ', COUNT(CASE WHEN stock_quantity <= ' . (int) $lowThreshold . ' THEN 1 END) AS low_count' : '';
    $row = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(stock_quantity),0) AS total_qty, COALESCE(SUM(stock_quantity * {$valueColumn}),0) AS total_value{$lowCase}, COUNT(CASE WHEN stock_quantity = 0 THEN 1 END) AS out_count FROM products{$where}")->fetch();
    return $row ?: [];
}

function batchExpiryCounts(PDO $pdo, int $days = 60, bool $inStockOnly = false): array
{
    $where = $inStockOnly ? ' WHERE quantity > 0' : '';
    $row = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(quantity),0) AS total_qty, COUNT(CASE WHEN expiry_date < CURDATE() THEN 1 END) AS expired_count, COUNT(CASE WHEN expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL " . (int) $days . " DAY) THEN 1 END) AS expiring_count FROM product_batches{$where}")->fetch();
    return $row ?: [];
}

function expiredProductCount(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(DISTINCT product_id) FROM product_batches WHERE expiry_date < CURDATE() AND quantity > 0")->fetchColumn();
}

function deduct_from_batches(PDO $pdo, int $productId, int $needQty): void
{
    // Only AVAILABLE (active) batches can be sold; quarantined/expired/disposed
    // stock must never be deducted for sales.
    $stmt = $pdo->prepare("SELECT id, quantity FROM product_batches WHERE product_id = :pid AND quantity > 0 AND status = 'active' ORDER BY expiry_date ASC, id ASC FOR UPDATE");
    $stmt->execute(['pid' => $productId]);
    $batches = $stmt->fetchAll();

    if (!empty($batches)) {
        $remaining = $needQty;
        foreach ($batches as $batch) {
            if ($remaining <= 0) break;
            $take = min((int) $batch['quantity'], $remaining);
            $remaining -= $take;
            $pdo->prepare('UPDATE product_batches SET quantity = quantity - :qty WHERE id = :id')
                ->execute(['qty' => $take, 'id' => $batch['id']]);
        }
        if ($remaining > 0) {
            throw new RuntimeException('Insufficient stock (batch tracked).');
        }
        // Recompute product stock from active batches instead of blindly
        // subtracting, so it stays exactly in sync with batch quantities.
        recalc_product_stock($pdo, $productId);
    } else {
        $st = $pdo->prepare('SELECT stock_quantity FROM products WHERE id = :id FOR UPDATE');
        $st->execute(['id' => $productId]);
        $avail = (int) $st->fetchColumn();
        if ($avail < $needQty) {
            throw new RuntimeException('Insufficient stock.');
        }
        $pdo->prepare('UPDATE products SET stock_quantity = stock_quantity - :qty WHERE id = :id')
            ->execute(['qty' => $needQty, 'id' => $productId]);
    }
}
