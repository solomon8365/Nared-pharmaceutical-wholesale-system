<?php
/**
 * CustomerService
 *
 * Centralized customer CRUD, search and portal-login creation. Pages must
 * not write to customers directly.
 */
class CustomerService
{
    public static function find(PDO $pdo, int $customerId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = :id');
        $stmt->execute(['id' => $customerId]);
        return $stmt->fetch() ?: null;
    }

    public static function create(PDO $pdo, array $d, ?int $registeredBy = null): int
    {
        $name = trim($d['name'] ?? '');
        if ($name === '') {
            throw new ServiceException('Customer name is required.');
        }
        // `customers.registered_by` references `salespeople(id)` (see
        // sql/migration_sales_rep_features.sql), while callers pass the
        // logged-in account's `users.id`. Translate it to the matching
        // salesperson row, or store NULL when the account is not a salesperson.
        $salespersonId = self::salespersonIdForUser($pdo, $registeredBy);
        try {
            $stmt = $pdo->prepare('INSERT INTO customers (name, customer_type, license_number, tin_number, contact_person, phone, email, physical_address, credit_limit, payment_terms, customer_status, registered_by, created_at) VALUES (:name, :ctype, :lic, :tin, :contact, :phone, :email, :addr, :cl, :pt, :cstatus, :reg, NOW())');
            $stmt->execute([
                'name' => $name,
                'ctype' => !empty($d['customer_type']) ? $d['customer_type'] : null,
                'lic' => !empty($d['license_number']) ? trim($d['license_number']) : null,
                'tin' => !empty($d['tin_number']) ? trim($d['tin_number']) : null,
                'contact' => trim($d['contact'] ?? ''),
                'phone' => trim($d['phone'] ?? ''),
                'email' => trim($d['email'] ?? ''),
                'addr' => trim($d['physical_address'] ?? ''),
                'cl' => (float) ($d['credit_limit'] ?? 0),
                'pt' => !empty($d['payment_terms']) ? trim($d['payment_terms']) : null,
                'cstatus' => $d['customer_status'] ?? 'active',
                'reg' => $salespersonId,
            ]);
        } catch (PDOException $e) {
            throw new ServiceException('Could not save the customer. Please check the details and try again.');
        }
        $id = (int) $pdo->lastInsertId();
        AuditService::cms($pdo, $registeredBy, 'customer_created', 'customers', $id, $name);
        return $id;
    }

    /**
     * Map a logged-in account (users.id) to its linked salesperson record.
     * Returns null when the account is not linked to an active salesperson,
     * which is valid because customers.registered_by is nullable.
     */
    private static function salespersonIdForUser(PDO $pdo, ?int $userId): ?int
    {
        if ($userId === null || $userId <= 0) {
            return null;
        }
        try {
            $stmt = $pdo->prepare('SELECT id FROM salespeople WHERE user_id = :uid LIMIT 1');
            $stmt->execute(['uid' => $userId]);
            $id = $stmt->fetchColumn();
            return ($id === false || $id === null) ? null : (int) $id;
        } catch (PDOException $e) {
            return null; // legacy schemas without salespeople.user_id
        }
    }

    public static function update(PDO $pdo, int $customerId, array $d, ?int $userId = null): array
    {
        $name = trim($d['name'] ?? '');
        if ($customerId <= 0 || $name === '') {
            return ['success' => false, 'msg' => 'Customer name is required.'];
        }
        $stmt = $pdo->prepare('UPDATE customers SET name = :name, customer_type = :ctype, license_number = :lic, tin_number = :tin, contact_person = :contact, phone = :phone, email = :email, physical_address = :addr, credit_limit = :cl, payment_terms = :pt, customer_status = :cstatus WHERE id = :id');
        $stmt->execute([
            'name' => $name,
            'ctype' => !empty($d['customer_type']) ? $d['customer_type'] : null,
            'lic' => !empty($d['license_number']) ? trim($d['license_number']) : null,
            'tin' => !empty($d['tin_number']) ? trim($d['tin_number']) : null,
            'contact' => trim($d['contact'] ?? ''),
            'phone' => trim($d['phone'] ?? ''),
            'email' => trim($d['email'] ?? ''),
            'addr' => trim($d['physical_address'] ?? ''),
            'cl' => (float) ($d['credit_limit'] ?? 0),
            'pt' => !empty($d['payment_terms']) ? trim($d['payment_terms']) : null,
            'cstatus' => $d['customer_status'] ?? 'active',
            'id' => $customerId,
        ]);
        AuditService::cms($pdo, $userId, 'customer_updated', 'customers', $customerId, $name);
        return ['success' => true, 'msg' => 'Customer updated successfully.'];
    }

    public static function delete(PDO $pdo, int $customerId, ?int $userId = null): array
    {
        try {
            $pdo->prepare('DELETE FROM customers WHERE id = :id')->execute(['id' => $customerId]);
            AuditService::cms($pdo, $userId, 'customer_deleted', 'customers', $customerId);
            return ['success' => true, 'msg' => 'Customer deleted successfully.'];
        } catch (Throwable $e) {
            return ['success' => false, 'msg' => 'Could not delete customer: ' . $e->getMessage()];
        }
    }

    /** Create a portal login for a customer (role_id 2 = customer). */
    public static function createPortalLogin(PDO $pdo, int $customerId, string $username, string $password, ?int $userId = null): array
    {
        if ($customerId <= 0 || $username === '' || strlen($password) < 6) {
            return ['success' => false, 'msg' => 'Customer, username, and password (min 6 characters) are required.'];
        }
        $cstmt = $pdo->prepare('SELECT name FROM customers WHERE id = :id LIMIT 1');
        $cstmt->execute(['id' => $customerId]);
        $cname = $cstmt->fetchColumn();
        if ($cname === false) {
            return ['success' => false, 'msg' => 'Customer not found.'];
        }
        try {
            AuthenticationService::createUser($pdo, $username, $password, (string) $cname, 'customer', 2, $customerId);
            AuditService::cms($pdo, $userId, 'portal_login_created', 'customers', $customerId, $username);
            return ['success' => true, 'msg' => 'Customer portal login created. They can sign in with that username.'];
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return ['success' => false, 'msg' => 'Username is already taken.'];
            }
            return ['success' => false, 'msg' => 'Could not create login: ' . $e->getMessage()];
        }
    }

    public static function list(PDO $pdo, string $search = ''): array
    {
        $base = "SELECT c.*, (SELECT u.id FROM users u WHERE u.customer_id = c.id AND u.role = 'customer' LIMIT 1) AS portal_user_id, (SELECT u.username FROM users u WHERE u.customer_id = c.id AND u.role = 'customer' LIMIT 1) AS portal_username FROM customers c";
        if ($search !== '') {
            $stmt = $pdo->prepare("$base WHERE c.name LIKE :q1 OR c.contact_person LIKE :q2 OR c.phone LIKE :q3 OR c.license_number LIKE :q4 OR c.tin_number LIKE :q5 ORDER BY c.created_at DESC");
            $stmt->execute(['q1' => "%$search%", 'q2' => "%$search%", 'q3' => "%$search%", 'q4' => "%$search%", 'q5' => "%$search%"]);
        } else {
            $stmt = $pdo->query("$base ORDER BY c.created_at DESC");
        }
        return $stmt->fetchAll();
    }

    public static function name(PDO $pdo, int $customerId): string
    {
        return CreditService::customerName($pdo, $customerId);
    }
}
