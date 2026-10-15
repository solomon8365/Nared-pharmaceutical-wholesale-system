<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/services/init.php';
requireLogin();
header('Content-Type: application/json');

$saleId = (int) ($_GET['sale_id'] ?? 0);
if ($saleId <= 0) {
    echo json_encode([]);
    exit;
}

$pdo = getPDO();
$stmt = $pdo->prepare("SELECT si.product_id, si.quantity AS quantity_sold, si.unit_price, p.name AS product_name FROM sale_items si JOIN products p ON si.product_id = p.id WHERE si.sale_id = :sale_id");
$stmt->execute(['sale_id' => $saleId]);
echo json_encode($stmt->fetchAll());
