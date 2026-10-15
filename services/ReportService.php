<?php
/**
 * ReportService
 *
 * Centralized report queries shared across report pages and dashboards.
 */
class ReportService
{
    public static function salesByDay(PDO $pdo, string $from, string $to): array
    {
        $stmt = $pdo->prepare("SELECT DATE(sale_date) AS day, COUNT(*) AS orders, SUM(total_amount) AS revenue FROM sales WHERE DATE(sale_date) BETWEEN :from AND :to GROUP BY DATE(sale_date) ORDER BY day ASC");
        $stmt->execute(['from' => $from, 'to' => $to]);
        return $stmt->fetchAll();
    }

    public static function salesByProduct(PDO $pdo, string $from, string $to, int $limit = 20): array
    {
        $stmt = $pdo->prepare("SELECT p.name, SUM(si.quantity) AS qty, SUM(si.quantity * si.unit_price) AS revenue FROM sale_items si JOIN products p ON p.id = si.product_id JOIN sales s ON s.id = si.sale_id WHERE DATE(s.sale_date) BETWEEN :from AND :to GROUP BY p.id, p.name ORDER BY revenue DESC LIMIT :lim");
        $stmt->bindValue('from', $from);
        $stmt->bindValue('to', $to);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function topCustomers(PDO $pdo, string $from, string $to, int $limit = 10): array
    {
        $stmt = $pdo->prepare("SELECT c.name, COUNT(*) AS orders, SUM(s.total_amount) AS revenue FROM sales s JOIN customers c ON c.id = s.customer_id WHERE DATE(s.sale_date) BETWEEN :from AND :to GROUP BY c.id, c.name ORDER BY revenue DESC LIMIT :lim");
        $stmt->bindValue('from', $from);
        $stmt->bindValue('to', $to);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function paymentMethods(PDO $pdo, string $from, string $to): array
    {
        $stmt = $pdo->prepare("SELECT payment_method, COUNT(*) AS count, SUM(amount) AS total FROM invoice_payments WHERE DATE(created_at) BETWEEN :from AND :to GROUP BY payment_method ORDER BY total DESC");
        $stmt->execute(['from' => $from, 'to' => $to]);
        return $stmt->fetchAll();
    }

    public static function inventoryByCategory(PDO $pdo): array
    {
        return $pdo->query('SELECT COALESCE(c.name, "Uncategorized") AS category, COUNT(*) AS products, COALESCE(SUM(pb.quantity), 0) AS units, COALESCE(SUM(pb.quantity * pb.purchase_price), 0) AS cost_value FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN product_batches pb ON pb.product_id = p.id AND pb.quantity > 0 AND pb.status = \'active\' GROUP BY c.id, c.name ORDER BY cost_value DESC')->fetchAll();
    }

    public static function lowStock(PDO $pdo, int $limit = 50): array
    {
        $stmt = $pdo->prepare('SELECT p.*, COALESCE(SUM(pb.quantity), 0) AS stock_quantity FROM products p LEFT JOIN product_batches pb ON pb.product_id = p.id AND pb.quantity > 0 AND pb.status = \'active\' GROUP BY p.id HAVING stock_quantity <= p.reorder_level ORDER BY stock_quantity ASC LIMIT :lim');
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function ordersOverview(PDO $pdo, string $from, string $to): array
    {
        $stmt = $pdo->prepare("SELECT status, COUNT(*) AS count FROM customer_orders WHERE DATE(created_at) BETWEEN :from AND :to GROUP BY status");
        $stmt->execute(['from' => $from, 'to' => $to]);
        return $stmt->fetchAll();
    }
}
