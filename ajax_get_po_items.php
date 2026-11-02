<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/services/init.php';
requireLogin();

if (!canViewPurchaseOrder() && !hasPermission('purchase_returns', 'create') && !hasPermission('purchase_returns', 'view') && !isAdmin()) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Access denied']);
    exit;
}

header('Content-Type: application/json');

$poId = (int) ($_GET['po_id'] ?? 0);
if ($poId <= 0) {
    echo json_encode([]);
    exit;
}

$pdo = getPDO();
$stmt = $pdo->prepare("SELECT poi.product_id, poi.quantity AS quantity_ordered, poi.received_qty AS quantity_received, poi.unit_price, p.name AS product_name FROM purchase_order_items poi JOIN products p ON poi.product_id = p.id WHERE poi.purchase_order_id = :po_id");
$stmt->execute(['po_id' => $poId]);
$items = $stmt->fetchAll();

echo json_encode($items);
