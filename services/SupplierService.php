<?php
/**
 * SupplierService
 *
 * Centralized supplier CRUD and queries.
 */
class SupplierService
{
    public static function find(PDO $pdo, int $supplierId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = :id');
        $stmt->execute(['id' => $supplierId]);
        return $stmt->fetch() ?: null;
    }

    public static function list(PDO $pdo, string $search = ''): array
    {
        $base = 'SELECT s.*, COALESCE(po.total_pending, 0) AS pending_orders FROM suppliers s LEFT JOIN (SELECT supplier_id, COUNT(*) AS total_pending FROM purchase_orders WHERE status IN ("pending","payment_pending","payment_approved") GROUP BY supplier_id) po ON po.supplier_id = s.id';
        if ($search !== '') {
            $stmt = $pdo->prepare("$base WHERE s.name LIKE :q1 OR s.contact_person LIKE :q2 OR s.phone LIKE :q3 OR s.email LIKE :q4 ORDER BY s.name");
            $stmt->execute(['q1' => "%$search%", 'q2' => "%$search%", 'q3' => "%$search%", 'q4' => "%$search%"]);
        } else {
            $stmt = $pdo->query("$base ORDER BY s.name");
        }
        return $stmt->fetchAll();
    }

    public static function create(PDO $pdo, array $d): int
    {
        $name = trim($d['name'] ?? '');
        if ($name === '') {
            throw new ServiceException('Supplier name is required.');
        }
        $stmt = $pdo->prepare('INSERT INTO suppliers (name, contact_person, phone, email, physical_address, supplier_status) VALUES (:name, :cp, :phone, :email, :addr, :status)');
        $stmt->execute([
            'name' => $name,
            'cp' => trim($d['contact_person'] ?? '') ?: null,
            'phone' => trim($d['phone'] ?? '') ?: null,
            'email' => trim($d['email'] ?? '') ?: null,
            'addr' => trim($d['physical_address'] ?? '') ?: null,
            'status' => $d['supplier_status'] ?? 'active',
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(PDO $pdo, int $supplierId, array $d): array
    {
        $name = trim($d['name'] ?? '');
        if ($supplierId <= 0 || $name === '') {
            return ['success' => false, 'msg' => 'Supplier name is required.'];
        }
        $stmt = $pdo->prepare('UPDATE suppliers SET name = :name, contact_person = :cp, phone = :phone, email = :email, physical_address = :addr, supplier_status = :status WHERE id = :id');
        $stmt->execute([
            'name' => $name,
            'cp' => trim($d['contact_person'] ?? '') ?: null,
            'phone' => trim($d['phone'] ?? '') ?: null,
            'email' => trim($d['email'] ?? '') ?: null,
            'addr' => trim($d['physical_address'] ?? '') ?: null,
            'status' => $d['supplier_status'] ?? 'active',
            'id' => $supplierId,
        ]);
        return ['success' => true, 'msg' => 'Supplier updated successfully.'];
    }

    public static function delete(PDO $pdo, int $supplierId): array
    {
        try {
            $pdo->prepare('DELETE FROM suppliers WHERE id = :id')->execute(['id' => $supplierId]);
            return ['success' => true, 'msg' => 'Supplier deleted successfully.'];
        } catch (Throwable $e) {
            return ['success' => false, 'msg' => 'Could not delete supplier: ' . $e->getMessage()];
        }
    }
}
