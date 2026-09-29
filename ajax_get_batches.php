<?php
require_once __DIR__ . '/auth.php';
requireLogin();
$pdo = getPDO();
$productId = (int) ($_GET['product_id'] ?? 0);
$warehouseId = (int) ($_GET['warehouse_id'] ?? 0);
header('Content-Type: application/json');
if ($productId <= 0) { echo '[]'; exit; }

// Only batches actually allocated to the chosen source warehouse, with stock.
if ($warehouseId > 0) {
    $stmt = $pdo->prepare("SELECT id, batch_number, expiry_date, quantity FROM product_batches WHERE product_id = :pid AND warehouse_id = :wid AND quantity > 0 ORDER BY expiry_date ASC");
    $stmt->execute(['pid' => $productId, 'wid' => $warehouseId]);
} else {
    $stmt = $pdo->prepare('SELECT id, batch_number, expiry_date, quantity FROM product_batches WHERE product_id = :pid AND quantity > 0 ORDER BY expiry_date ASC');
    $stmt->execute(['pid' => $productId]);
}
echo json_encode($stmt->fetchAll());
