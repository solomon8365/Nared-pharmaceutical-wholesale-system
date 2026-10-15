<?php
/**
 * ProductService
 *
 * Centralized product CRUD, catalog queries and product-related checks.
 */
class ProductService
{
    public static function find(PDO $pdo, int $productId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id');
        $stmt->execute(['id' => $productId]);
        return $stmt->fetch() ?: null;
    }

    public static function findActive(PDO $pdo, int $productId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id AND status = "active"');
        $stmt->execute(['id' => $productId]);
        return $stmt->fetch() ?: null;
    }

    public static function list(PDO $pdo, string $search = '', string $status = ''): array
    {
        $sql = "SELECT p.*, COALESCE(SUM(COALESCE(pb.quantity, 0)), 0) AS stock_quantity,
                       COALESCE(SUM(COALESCE(pb.quantity, 0) * pb.purchase_price), 0) AS stock_cost,
                       (SELECT b.batch_number FROM product_batches b WHERE b.product_id = p.id AND b.quantity > 0 AND b.expiry_date >= CURDATE() ORDER BY b.expiry_date ASC LIMIT 1) AS nearest_expiry_batch,
                       (SELECT b.expiry_date FROM product_batches b WHERE b.product_id = p.id AND b.quantity > 0 AND b.expiry_date >= CURDATE() ORDER BY b.expiry_date ASC LIMIT 1) AS nearest_expiry_date
                FROM products p
                LEFT JOIN product_batches pb ON pb.product_id = p.id AND pb.quantity > 0 AND pb.expiry_date >= CURDATE()";
        $conds = [];
        $params = [];
        if ($search !== '') {
            $conds[] = '(p.name LIKE :q1 OR p.barcode LIKE :q2 OR p.sku LIKE :q3)';
            $params['q1'] = "%$search%";
            $params['q2'] = "%$search%";
            $params['q3'] = "%$search%";
        }
        if ($status !== '') {
            $conds[] = 'p.product_status = :status';
            $params['status'] = $status;
        }
        if ($conds) {
            $sql .= ' WHERE ' . implode(' AND ', $conds);
        }
        $sql .= ' GROUP BY p.id ORDER BY p.name ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function create(PDO $pdo, array $d, ?int $userId = null): int
    {
        $name = trim($d['name'] ?? '');
        if ($name === '') {
            throw new ServiceException('Product name is required.');
        }
        $stmt = $pdo->prepare('INSERT INTO products (name, barcode, sku, brand, description, unit_of_measure, price, purchase_price, reorder_level, product_status, created_by, created_at, updated_at) VALUES (:name, :barcode, :sku, :brand, :desc, :unit, :price, :cost, :reorder, :status, :created_by, NOW(), NOW())');
        $stmt->execute([
            'name' => $name,
            'barcode' => trim($d['barcode'] ?? '') ?: null,
            'sku' => trim($d['sku'] ?? '') ?: null,
            'brand' => trim($d['brand'] ?? '') ?: null,
            'desc' => trim($d['description'] ?? '') ?: null,
            'unit' => trim($d['unit_of_measure'] ?? '') ?: null,
            'price' => (float) ($d['price'] ?? 0),
            'cost' => (float) ($d['purchase_price'] ?? $d['cost_price'] ?? 0),
            'reorder' => (float) ($d['reorder_level'] ?? 0),
            'status' => $d['product_status'] ?? 'active',
            'created_by' => $userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        AuditService::cms($pdo, $userId, 'product_created', 'products', $id, $name);
        return $id;
    }

    public static function update(PDO $pdo, int $productId, array $d, ?int $userId = null): array
    {
        $name = trim($d['name'] ?? '');
        if ($productId <= 0 || $name === '') {
            return ['success' => false, 'msg' => 'Product name is required.'];
        }
        $stmt = $pdo->prepare('UPDATE products SET name = :name, barcode = :barcode, sku = :sku, brand = :brand, description = :desc, unit_of_measure = :unit, price = :price, purchase_price = :cost, reorder_level = :reorder, product_status = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'name' => $name,
            'barcode' => trim($d['barcode'] ?? '') ?: null,
            'sku' => trim($d['sku'] ?? '') ?: null,
            'brand' => trim($d['brand'] ?? '') ?: null,
            'desc' => trim($d['description'] ?? '') ?: null,
            'unit' => trim($d['unit_of_measure'] ?? '') ?: null,
            'price' => (float) ($d['price'] ?? 0),
            'cost' => (float) ($d['purchase_price'] ?? $d['cost_price'] ?? 0),
            'reorder' => (float) ($d['reorder_level'] ?? 0),
            'status' => $d['product_status'] ?? 'active',
            'id' => $productId,
        ]);
        AuditService::cms($pdo, $userId, 'product_updated', 'products', $productId, $name);
        return ['success' => true, 'msg' => 'Product updated successfully.'];
    }

    public static function delete(PDO $pdo, int $productId, ?int $userId = null): array
    {
        try {
            $pdo->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $productId]);
            AuditService::cms($pdo, $userId, 'product_deleted', 'products', $productId);
            return ['success' => true, 'msg' => 'Product deleted successfully.'];
        } catch (Throwable $e) {
            return ['success' => false, 'msg' => 'Could not delete product: ' . $e->getMessage()];
        }
    }

    public static function hasStock(PDO $pdo, int $productId, float $qty): bool
    {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM product_batches WHERE product_id = :id AND quantity > 0 AND status = 'active'");
        $stmt->execute(['id' => $productId]);
        return (float) $stmt->fetchColumn() >= $qty;
    }
}
