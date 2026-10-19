<?php
/**
 * Read-only product search for the Place Order page (Step 1).
 *
 * Returns a small, ranked list of IN-STOCK active products whose
 * name / brand / generic / strength / barcode match ?q=.
 *
 * "Available stock" is computed the same way the fulfillment FEFO deduction
 * works: from ACTIVE batches when the product is batch-tracked, otherwise from
 * products.stock_quantity. Products with no sellable stock are excluded so the
 * customer never selects something that cannot be ordered.
 *
 * This endpoint never writes. It exists so pages/place_order.php no longer has
 * to render the entire catalog into every row's <select>.
 */
require_once __DIR__ . '/auth.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$q = trim((string) ($_GET['q'] ?? ''));
if (function_exists('mb_substr')) {
    if (mb_strlen($q) > 60) {
        $q = mb_substr($q, 0, 60);
    }
} elseif (strlen($q) > 60) {
    $q = substr($q, 0, 60);
}

// An empty query returns the first page of in-stock products so the picker is
// usable before the customer types (and when they clear the search).
// Otherwise split the query into words so "para 500" / "amox 500mg" works:
// every word must match at least one searchable field (AND across words).
$tokens = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
$tokens = array_slice($tokens, 0, 5);
$hasQuery = !empty($tokens);

$escapeLike = static function (string $value): string {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
};

try {
    $pdo = getPDO();
    $select = "SELECT p.id, p.name, p.brand, p.generic_name, p.dosage_form, p.strength,
                      p.price, p.wholesale_price, p.barcode,
                      CASE WHEN EXISTS (SELECT 1 FROM product_batches b WHERE b.product_id = p.id)
                           THEN COALESCE((SELECT SUM(b2.quantity) FROM product_batches b2
                                          WHERE b2.product_id = p.id AND b2.quantity > 0 AND b2.status = 'active'), 0)
                           ELSE p.stock_quantity END AS available_stock
               FROM products p
               WHERE p.product_status = 'active'";
    $sql = $select;
    $params = [];
    if ($hasQuery) {
        $clauses = [];
        foreach ($tokens as $i => $token) {
            $like = '%' . $escapeLike($token) . '%';
            $clauses[] = "(p.name LIKE :n{$i} OR p.brand LIKE :b{$i} OR p.generic_name LIKE :g{$i}
                           OR p.strength LIKE :s{$i} OR p.barcode LIKE :c{$i})";
            $params["n{$i}"] = $like;
            $params["b{$i}"] = $like;
            $params["g{$i}"] = $like;
            $params["s{$i}"] = $like;
            $params["c{$i}"] = $like;
        }
        $sql .= ' AND ' . implode(' AND ', $clauses);
    }
    $sql .= " HAVING available_stock > 0 ORDER BY p.name LIMIT 25";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $matchedBy = 'exact';

    // Typo-tolerant fallback: if the literal search found nothing, compare the
    // first word by SOUNDEX so a misspelling like "parastamol" still surfaces
    // "Paracetamol". Only runs on a miss, and still respects stock + limit.
    if (!$rows && $hasQuery && strlen($tokens[0]) >= 3) {
        $first = $tokens[0];
        $fallbackSql = $select . " AND (LEFT(SOUNDEX(p.name), 4) = LEFT(SOUNDEX(:x1), 4)
                                        OR LEFT(SOUNDEX(p.generic_name), 4) = LEFT(SOUNDEX(:x2), 4)
                                        OR LEFT(SOUNDEX(p.brand), 4) = LEFT(SOUNDEX(:x3), 4))
                                 HAVING available_stock > 0 ORDER BY p.name LIMIT 25";
        $fallback = $pdo->prepare($fallbackSql);
        $fallback->execute(['x1' => $first, 'x2' => $first, 'x3' => $first]);
        $rows = $fallback->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $matchedBy = 'similar';
        }
    }

    $results = array_map(static function (array $r): array {
        return [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'brand' => (string) ($r['brand'] ?? ''),
            'generic' => (string) ($r['generic_name'] ?? ''),
            'form' => (string) ($r['dosage_form'] ?? ''),
            'strength' => (string) ($r['strength'] ?? ''),
            'barcode' => (string) ($r['barcode'] ?? ''),
            'price' => (float) $r['price'],
            'wholesale_price' => (float) ($r['wholesale_price'] ?? 0),
            'stock' => (int) $r['available_stock'],
        ];
    }, $rows);

    echo json_encode(['results' => $results, 'query' => $q, 'matched_by' => $matchedBy]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['results' => [], 'error' => 'search_failed']);
}
