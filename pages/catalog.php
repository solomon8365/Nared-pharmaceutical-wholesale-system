<?php
$pdo = getPDO();
$search = trim($_GET['search'] ?? '');
$catFilter = (int) ($_GET['category_id'] ?? 0);
$mfrFilter = trim($_GET['manufacturer'] ?? '');
$stockFilter = trim($_GET['stock_status'] ?? '');
$rxFilter = isset($_GET['rx']) && $_GET['rx'] !== '' ? (int)$_GET['rx'] : -1;
$dealFilter = !empty($_GET['deals']) ? 1 : 0;
$sort = trim($_GET['sort'] ?? 'name');
$viewMode = $_GET['view'] ?? 'grid';
$activeTab = $_GET['tab'] ?? 'all';

$allCats = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$allManufacturers = $pdo->query('SELECT DISTINCT manufacturer FROM products WHERE manufacturer IS NOT NULL AND manufacturer != \'\' ORDER BY manufacturer')->fetchAll(PDO::FETCH_COLUMN);
$catNames = [];
foreach ($allCats as $c) $catNames[(int)$c['id']] = $c['name'];
$catCounts = [];
try { foreach ($pdo->query("SELECT category_id, COUNT(*) AS cnt FROM products WHERE product_status = 'active' GROUP BY category_id") as $r) $catCounts[(int)$r['category_id']] = (int)$r['cnt']; } catch (Exception $e) {}
$totalActive = array_sum($catCounts);

$inStockCount = 0;
try { $inStockCount = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE product_status = 'active' AND stock_quantity > 0")->fetchColumn(); } catch (Exception $e) {}
$inStockRate = $totalActive > 0 ? round(($inStockCount / $totalActive) * 100) : 100;

$user = currentUser();
$isCustomerUser = $user && isCustomer();
// Internal stock detail (low-stock buckets, expiry dates, batch numbers) is shown only to signed-in staff.
$catShowInternal = $user !== null && !isCustomer();
$customerId = 0;
if ($isCustomerUser) {
    try { $cStmt = $pdo->prepare('SELECT id FROM customers WHERE id = (SELECT customer_id FROM users WHERE id = :uid)'); $cStmt->execute(['uid' => $user['id']]); $customerId = (int)$cStmt->fetchColumn(); } catch (Exception $e) {}
}

/* ── Favorites ── */
$favIds = [];
$favProducts = [];
if ($customerId) {
    try {
        $favStmt = $pdo->prepare('SELECT product_id FROM customer_favorites WHERE customer_id = :cid');
        $favStmt->execute(['cid' => $customerId]);
        $favIds = array_column($favStmt->fetchAll(), 'product_id');
        if (!empty($favIds)) {
            $fPh = implode(',', array_fill(0, count($favIds), '?'));
            $fpStmt = $pdo->prepare("SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.id IN ($fPh) AND p.product_status = 'active'");
            $fpStmt->execute($favIds);
            $favProducts = $fpStmt->fetchAll();
        }
    } catch (Exception $e) {}
}
if (isset($_POST['_fav']) && $customerId) {
    require_csrf();
    $fid = (int)$_POST['product_id'];
    $exists = in_array($fid, $favIds);
    if ($exists) {
        $pdo->prepare('DELETE FROM customer_favorites WHERE customer_id = :cid AND product_id = :pid')->execute(['cid' => $customerId, 'pid' => $fid]);
    } else {
        $pdo->prepare('INSERT IGNORE INTO customer_favorites (customer_id, product_id) VALUES (:cid, :pid)')->execute(['cid' => $customerId, 'pid' => $fid]);
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        echo json_encode(['success' => true, 'action' => $exists ? 'removed' : 'added']);
        exit;
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

/* ── Comparison ── */
$compareIds = [];
$compareProducts = [];
if ($customerId) {
    try {
        $ciStmt = $pdo->prepare('SELECT product_id FROM product_comparisons WHERE customer_id = :cid');
        $ciStmt->execute(['cid' => $customerId]);
        $compareIds = array_column($ciStmt->fetchAll(), 'product_id');
        if (!empty($compareIds)) {
            $cPh = implode(',', array_fill(0, count($compareIds), '?'));
            $cStmt = $pdo->prepare("SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.id IN ($cPh) AND p.product_status = 'active'");
            $cStmt->execute($compareIds);
            $compareProducts = $cStmt->fetchAll();
        }
    } catch (Exception $e) {}
}
if (isset($_POST['_compare_clear']) && $customerId) {
    require_csrf();
    $pdo->prepare('DELETE FROM product_comparisons WHERE customer_id = :cid')->execute(['cid' => $customerId]);
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}
if (isset($_POST['_compare']) && $customerId) {
    require_csrf();
    $cpid = (int)$_POST['product_id'];
    if (in_array($cpid, $compareIds)) {
        $pdo->prepare('DELETE FROM product_comparisons WHERE customer_id = :cid AND product_id = :pid')->execute(['cid' => $customerId, 'pid' => $cpid]);
    } elseif (count($compareIds) < 4) {
        $pdo->prepare('INSERT IGNORE INTO product_comparisons (customer_id, product_id) VALUES (:cid, :pid)')->execute(['cid' => $customerId, 'pid' => $cpid]);
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

/* ── Track recently viewed ── */
if ($customerId && isset($_GET['view_product'])) {
    $vpid = (int)$_GET['view_product'];
    if ($vpid > 0) {
        try { $pdo->prepare('INSERT INTO recently_viewed_products (customer_id, product_id) VALUES (:cid, :pid) ON DUPLICATE KEY UPDATE viewed_at = NOW()')->execute(['cid' => $customerId, 'pid' => $vpid]); } catch (Exception $e) {}
    }
}

/* ── Main Product Query ── */
$sql = 'SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.product_status = "active"';
$params = [];
if ($search !== '') {
    $sql .= ' AND (p.name LIKE :q1 OR p.brand LIKE :q2 OR p.generic_name LIKE :q3 OR p.barcode LIKE :q4 OR p.manufacturer LIKE :q5 OR p.sku LIKE :q6 OR p.product_code LIKE :q7)';
    foreach (['q1','q2','q3','q4','q5','q6','q7'] as $k) $params[$k] = "%$search%";
}
if ($catFilter > 0) { $sql .= ' AND p.category_id = :cat'; $params['cat'] = $catFilter; }
if ($mfrFilter !== '') { $sql .= ' AND p.manufacturer = :mfr'; $params['mfr'] = $mfrFilter; }
if ($stockFilter === 'in_stock') $sql .= ' AND p.stock_quantity > 0';
elseif ($catShowInternal && $stockFilter === 'low_stock') $sql .= ' AND p.stock_quantity > 0 AND p.stock_quantity <= 10';
elseif ($stockFilter === 'out_of_stock') $sql .= ' AND p.stock_quantity <= 0';
elseif ($catShowInternal && $stockFilter === 'expiring') $sql .= ' AND p.id IN (SELECT product_id FROM product_batches WHERE quantity > 0 AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY))';

if ($rxFilter === 1) {
    $sql .= ' AND p.requires_prescription = 1';
} elseif ($rxFilter === 0) {
    $sql .= ' AND (p.requires_prescription = 0 OR p.requires_prescription IS NULL)';
}

if ($dealFilter === 1) {
    $sql .= ' AND p.wholesale_price > 0 AND p.wholesale_price < p.price';
}

$orderBy = match($sort) { 'price_asc'=>'p.price ASC','price_desc'=>'p.price DESC','stock'=>'p.stock_quantity ASC','newest'=>'p.created_at DESC', default=>'p.name ASC' };
$sql .= ' ORDER BY ' . $orderBy;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();
$totalProducts = count($products);

/* ── Batch Data ── */
$batchMap = []; $expiryMap = [];
if (!empty($products)) {
    $ids = array_column($products, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $bStmt = $pdo->prepare("SELECT product_id, batch_number, expiry_date, quantity FROM product_batches WHERE product_id IN ($ph) AND quantity > 0 AND status = 'active' ORDER BY expiry_date ASC");
    $bStmt->execute($ids);
    foreach ($bStmt->fetchAll() as $b) {
        $pid = (int)$b['product_id'];
        if (!isset($batchMap[$pid])) $batchMap[$pid] = [];
        $batchMap[$pid][] = $b;
        if (!isset($expiryMap[$pid]) || $b['expiry_date'] < $expiryMap[$pid]) $expiryMap[$pid] = $b['expiry_date'];
    }
}

/* ── Frequently Ordered ── */
$frequentlyOrdered = [];
if ($activeTab === 'frequent' || $activeTab === 'all') {
    try { $frequentlyOrdered = $pdo->query("SELECT p.*, c.name AS category_name, SUM(coi.quantity) AS total_ordered FROM customer_order_items coi JOIN customer_orders co ON coi.customer_order_id = co.id JOIN products p ON coi.product_id = p.id LEFT JOIN categories c ON p.category_id = c.id WHERE co.status IN ('approved','pending') AND p.product_status = 'active' GROUP BY p.id ORDER BY total_ordered DESC LIMIT 12")->fetchAll(); } catch (Throwable $e) {}
}

/* ── Recently Purchased ── */
$recentlyPurchased = [];
try { $recentlyPurchased = $pdo->query("SELECT DISTINCT p.*, c.name AS category_name FROM sale_items si JOIN sales s ON si.sale_id = s.id JOIN products p ON si.product_id = p.id LEFT JOIN categories c ON p.category_id = c.id WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND p.product_status = 'active' ORDER BY s.sale_date DESC LIMIT 12")->fetchAll(); } catch (Throwable $e) {}

function buildBatchJson(array $batchMap, int $pid): string {
    $arr = [];
    foreach ($batchMap[$pid] ?? [] as $b) $arr[] = ['batch'=>$b['batch_number'],'expiry'=>$b['expiry_date'],'qty'=>(int)$b['quantity']];
    return htmlspecialchars(json_encode($arr));
}

function stockBadge(int $s, bool $internal = false): array {
    if ($s <= 0) return ['OUT OF STOCK', 'out', 'fa-times-circle'];
    if ($internal && $s <= 10) return ['LOW STOCK', 'low', 'fa-exclamation-triangle'];
    return ['IN STOCK', 'in', 'fa-check-circle'];
}

function catIcon(string $name): string {
    $n = strtolower($name);
    $m = [
        'pain'=>'fa-tablets','analges'=>'fa-tablets','anti-inflamm'=>'fa-shield-virus','inflamm'=>'fa-shield-virus',
        'antibiotic'=>'fa-capsules','antimal'=>'fa-bug','malaria'=>'fa-bug','vitamin'=>'fa-bolt','supplement'=>'fa-bolt',
        'mineral'=>'fa-bolt','omega'=>'fa-bolt','cardio'=>'fa-heartbeat','heart'=>'fa-heartbeat','blood'=>'fa-heartbeat',
        'gastro'=>'fa-disease','digest'=>'fa-disease','stomach'=>'fa-disease','respir'=>'fa-lungs','cough'=>'fa-lungs',
        'cold'=>'fa-lungs','asthma'=>'fa-lungs','allerg'=>'fa-allergies','diabet'=>'fa-syringe','insulin'=>'fa-syringe',
        'inject'=>'fa-syringe','derm'=>'fa-hand-holding-medical','skin'=>'fa-hand-holding-medical','hair'=>'fa-hand-holding-medical',
        'eye'=>'fa-eye','bone'=>'fa-bone','ortho'=>'fa-bone','neuro'=>'fa-brain','immune'=>'fa-shield-virus',
        'fever'=>'fa-thermometer','flu'=>'fa-thermometer','general'=>'fa-prescription-bottle','other'=>'fa-prescription-bottle'
    ];
    foreach ($m as $k => $icon) if (str_contains($n, $k)) return $icon;
    return 'fa-prescription-bottle';
}

function getDosageTheme(string $form): array {
    $f = strtolower(trim($form));
    if (str_contains($f, 'tablet') || str_contains($f, 'pill') || str_contains($f, 'caplet')) {
        return ['theme' => 'tablet', 'icon' => 'fa-tablets', 'label' => 'Oral Tablet'];
    }
    if (str_contains($f, 'capsule')) {
        return ['theme' => 'capsule', 'icon' => 'fa-capsules', 'label' => 'Oral Capsule'];
    }
    if (str_contains($f, 'cream') || str_contains($f, 'ointment') || str_contains($f, 'gel')) {
        return ['theme' => 'cream', 'icon' => 'fa-pump-medical', 'label' => 'Topical Formulation'];
    }
    if (str_contains($f, 'inhaler') || str_contains($f, 'aerosol') || str_contains($f, 'spray')) {
        return ['theme' => 'inhaler', 'icon' => 'fa-wind', 'label' => 'Inhalation Aerosol'];
    }
    if (str_contains($f, 'powder') || str_contains($f, 'sachet') || str_contains($f, 'granule')) {
        return ['theme' => 'powder', 'icon' => 'fa-box-tissue', 'label' => 'Oral Powder / Sachet'];
    }
    if (str_contains($f, 'inject') || str_contains($f, 'infus') || str_contains($f, 'vial') || str_contains($f, 'ampoule')) {
        return ['theme' => 'injection', 'icon' => 'fa-syringe', 'label' => 'Injectable Solution'];
    }
    if (str_contains($f, 'syrup') || str_contains($f, 'suspension') || str_contains($f, 'liquid') || str_contains($f, 'solution') || str_contains($f, 'drop')) {
        return ['theme' => 'syrup', 'icon' => 'fa-prescription-bottle', 'label' => 'Oral Liquid / Syrup'];
    }
    return ['theme' => 'general', 'icon' => 'fa-pills', 'label' => 'Pharmaceutical Product'];
}

$flags = ['Ethiopia'=>'🇪🇹','India'=>'🇮🇳','China'=>'🇨🇳','USA'=>'🇺🇸','Germany'=>'🇩🇪','UK'=>'🇬🇧','France'=>'🇫🇷','Japan'=>'🇯🇵','Switzerland'=>'🇨🇭','Italy'=>'🇮🇹','Turkey'=>'🇹🇷','Nigeria'=>'🇳🇬','Kenya'=>'🇰🇪','Egypt'=>'🇪🇬','South Korea'=>'🇰🇷','Brazil'=>'🇧🇷'];
if (!function_exists('countryFlag')) {
    function countryFlag(string $country): string { global $flags; return $flags[$country] ?? '🌍'; }
}

$displayProducts = match($activeTab) {
    'frequent' => $frequentlyOrdered,
    'recent' => $recentlyPurchased,
    'favorites' => $favProducts,
    default => $products
};
$tabFrequentIds = array_column($frequentlyOrdered, 'id');
?>

<style>
/* ═════════════════════════════════════════════════════════════════════════
   MODERN ADVANCED PHARMACEUTICAL CATALOG STYLING
═════════════════════════════════════════════════════════════════════════ */
.cat-wrap {
    --cat-blue-900: #0f172a;
    --cat-blue-800: #1e3a8a;
    --cat-blue-700: #1d4ed8;
    --cat-blue-600: #2563eb;
    --cat-blue-500: #3b82f6;
    --cat-blue-100: #dbeafe;
    --cat-blue-50:  #eff6ff;

    --cat-emerald: #059669;
    --cat-emerald-bg: #ecfdf5;
    --cat-emerald-border: #a7f3d0;

    --cat-amber: #d97706;
    --cat-amber-bg: #fffbeb;
    --cat-amber-border: #fde68a;

    --cat-rose: #e11d48;
    --cat-rose-bg: #fff1f2;
    --cat-rose-border: #fecdd3;

    --cat-purple: #7c3aed;
    --cat-purple-bg: #f5f3ff;

    --cat-bg: #ffffff;
    --cat-bg-alt: #f8fafc;
    --cat-bg-subtle: #f1f5f9;
    --cat-border: #e2e8f0;
    --cat-border-hover: #93c5fd;

    --cat-title: #0f172a;
    --cat-body: #334155;
    --cat-muted: #64748b;
    --cat-faint: #94a3b8;

    --cat-card-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.05), 0 2px 6px -1px rgba(15, 23, 42, 0.03);
    --cat-hover-shadow: 0 20px 35px -5px rgba(15, 23, 42, 0.12), 0 10px 15px -5px rgba(37, 99, 235, 0.08);

    max-width: 1440px;
    margin: 0 auto;
    padding-bottom: 60px;
}

body.dark-mode .cat-wrap {
    --cat-bg: #1e293b;
    --cat-bg-alt: #0f172a;
    --cat-bg-subtle: #334155;
    --cat-border: #334155;
    --cat-border-hover: #3b82f6;
    --cat-title: #f8fafc;
    --cat-body: #e2e8f0;
    --cat-muted: #94a3b8;
    --cat-faint: #64748b;
    --cat-blue-50: #172554;
    --cat-card-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
    --cat-hover-shadow: 0 20px 40px rgba(0, 0, 0, 0.45);
}

/* ── Hero Banner ── */
.cat-hero {
    position: relative;
    border-radius: 20px;
    background: linear-gradient(135deg, #0b192c 0%, #1e3a8a 52%, #1d4ed8 100%);
    color: #fff;
    padding: 28px 32px;
    margin-bottom: 20px;
    box-shadow: 0 16px 36px -10px rgba(30, 58, 138, 0.35);
    overflow: hidden;
}
.cat-hero::before {
    content: "";
    position: absolute;
    top: -50%;
    right: -20%;
    width: 600px;
    height: 600px;
    background: radial-gradient(circle, rgba(56, 189, 248, 0.18) 0%, rgba(37, 99, 235, 0) 70%);
    pointer-events: none;
}
.cat-hero::after {
    content: "";
    position: absolute;
    bottom: -40%;
    left: 20%;
    width: 400px;
    height: 400px;
    background: radial-gradient(circle, rgba(147, 197, 253, 0.12) 0%, rgba(30, 58, 138, 0) 70%);
    pointer-events: none;
}
.cat-hero-content {
    position: relative;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 30px;
    flex-wrap: wrap;
}
.cat-hero-left {
    flex: 1;
    min-width: 320px;
}
.cat-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 5px 14px;
    background: rgba(255, 255, 255, 0.14);
    border: 1px solid rgba(255, 255, 255, 0.25);
    border-radius: 99px;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    backdrop-filter: blur(8px);
}
.cat-hero-title {
    font-size: 1.85rem;
    font-weight: 800;
    line-height: 1.2;
    margin: 12px 0 8px;
    letter-spacing: -0.02em;
}
.cat-hero-title .hl {
    background: linear-gradient(90deg, #60a5fa, #38bdf8);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}
.cat-hero-desc {
    font-size: 0.92rem;
    color: rgba(255, 255, 255, 0.88);
    margin: 0;
    line-height: 1.55;
    max-width: 640px;
}
.cat-hero-trust {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-top: 14px;
    font-size: 0.75rem;
    color: #bae6fd;
    font-weight: 600;
    flex-wrap: wrap;
}
.cat-hero-trust span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.cat-hero-stats {
    display: grid;
    grid-template-columns: repeat(2, minmax(110px, 1fr));
    gap: 12px;
    flex-shrink: 0;
}
.cat-stat-tile {
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 14px;
    padding: 12px 16px;
    text-align: center;
    backdrop-filter: blur(10px);
    transition: transform 0.2s ease, background 0.2s;
}
.cat-stat-tile:hover {
    transform: translateY(-2px);
    background: rgba(255, 255, 255, 0.15);
}
.cat-stat-val {
    font-size: 1.45rem;
    font-weight: 800;
    line-height: 1.1;
    display: block;
    color: #fff;
}
.cat-stat-lbl {
    font-size: 0.7rem;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.8);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-top: 3px;
    display: block;
}

/* ── Category Chips Bar ── */
.cat-chips-bar {
    display: flex;
    gap: 10px;
    overflow-x: auto;
    padding-bottom: 8px;
    margin-bottom: 16px;
    scrollbar-width: thin;
    -webkit-overflow-scrolling: touch;
}
.cat-chip-item {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 99px;
    background: var(--cat-bg);
    border: 1.5px solid var(--cat-border);
    color: var(--cat-body);
    font-size: 0.84rem;
    font-weight: 600;
    text-decoration: none;
    white-space: nowrap;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
}
.cat-chip-item:hover {
    border-color: var(--cat-blue-600);
    color: var(--cat-blue-600);
    transform: translateY(-1px);
}
.cat-chip-item.active {
    background: var(--cat-blue-600);
    border-color: var(--cat-blue-600);
    color: #fff;
    box-shadow: 0 4px 14px rgba(37, 99, 235, 0.3);
}
.cat-chip-badge {
    padding: 1px 8px;
    border-radius: 99px;
    font-size: 0.72rem;
    font-weight: 700;
    background: var(--cat-bg-subtle);
    color: var(--cat-muted);
}
.cat-chip-item.active .cat-chip-badge {
    background: rgba(255, 255, 255, 0.25);
    color: #fff;
}

/* ── Search & Filter Controls ── */
.cat-control-card {
    background: var(--cat-bg);
    border: 1.5px solid var(--cat-border);
    border-radius: 16px;
    padding: 18px 20px;
    margin-bottom: 22px;
    box-shadow: var(--cat-card-shadow);
}
.cat-search-box {
    display: flex;
    align-items: center;
    background: var(--cat-bg-alt);
    border: 2px solid var(--cat-border);
    border-radius: 12px;
    padding: 4px 6px 4px 16px;
    margin-bottom: 14px;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.cat-search-box:focus-within {
    border-color: var(--cat-blue-600);
    background: var(--cat-bg);
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
}
.cat-search-input {
    flex: 1;
    border: none !important;
    background: transparent !important;
    padding: 10px 12px 10px 6px !important;
    font-size: 0.96rem !important;
    color: var(--cat-title) !important;
    outline: none !important;
    box-shadow: none !important;
    margin: 0 !important;
}
.cat-search-input::placeholder {
    color: var(--cat-faint);
}
.cat-search-actions {
    display: flex;
    align-items: center;
    gap: 6px;
}
.cat-scan-btn {
    border: 1px solid var(--cat-border);
    background: var(--cat-bg);
    color: var(--cat-muted);
    border-radius: 8px;
    padding: 8px 12px;
    font-size: 0.86rem;
    cursor: pointer;
    transition: all 0.15s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.cat-scan-btn:hover {
    border-color: var(--cat-blue-600);
    color: var(--cat-blue-600);
    background: var(--cat-blue-50);
}
.cat-submit-btn {
    background: var(--cat-blue-600);
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 10px 22px;
    font-weight: 700;
    font-size: 0.9rem;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: background 0.15s, transform 0.1s;
}
.cat-submit-btn:hover {
    background: var(--cat-blue-700);
    transform: translateY(-1px);
}

/* ── Explicit Filter Row Toolbar (Overrides global CSS width:100%) ── */
.cat-toolbar-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.cat-filters-inline {
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    gap: 10px !important;
    flex-wrap: wrap !important;
    flex: 1 1 auto;
    margin: 0 !important;
}
.cat-filters-inline select.cat-filter-select,
form.cat-filters-inline select {
    width: auto !important;
    min-width: 150px !important;
    max-width: 220px !important;
    flex: 1 1 160px !important;
    padding: 9px 32px 9px 12px !important;
    border: 1.5px solid var(--cat-border) !important;
    border-radius: 9px !important;
    background-color: var(--cat-bg) !important;
    color: var(--cat-body) !important;
    font-size: 0.85rem !important;
    font-weight: 600 !important;
    outline: none !important;
    cursor: pointer !important;
    margin: 0 !important;
    box-sizing: border-box !important;
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%2364748b' d='M1.41 0L6 4.58L10.59 0L12 1.41L6 7.41L0 1.41L1.41 0Z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.cat-filters-inline select.cat-filter-select:focus {
    border-color: var(--cat-blue-600) !important;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12) !important;
}
.cat-filters-inline select.cat-filter-select.is-active {
    border-color: var(--cat-blue-600) !important;
    background-color: var(--cat-blue-50) !important;
    color: var(--cat-blue-700) !important;
}

.cat-clear-link {
    padding: 8px 14px;
    border-radius: 8px;
    background: var(--cat-rose-bg);
    border: 1px solid var(--cat-rose-border);
    color: var(--cat-rose);
    font-size: 0.82rem;
    font-weight: 700;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s;
    white-space: nowrap;
}
.cat-clear-link:hover {
    background: var(--cat-rose);
    color: #fff;
}

/* ── View Switcher ── */
.cat-view-toggles {
    display: inline-flex;
    border: 1.5px solid var(--cat-border);
    border-radius: 10px;
    overflow: hidden;
    background: var(--cat-bg-alt);
}
.cat-view-btn {
    padding: 8px 14px;
    border: none;
    background: transparent;
    color: var(--cat-muted);
    cursor: pointer;
    font-size: 0.9rem;
    text-decoration: none;
    transition: all 0.15s;
    display: inline-flex;
    align-items: center;
}
.cat-view-btn.active {
    background: var(--cat-blue-600);
    color: #fff;
    box-shadow: 0 2px 6px rgba(37, 99, 235, 0.25);
}

/* ── Quick Filter Tags Row ── */
.cat-qf-bar {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px dashed var(--cat-border);
    flex-wrap: wrap;
}
.cat-qf-label {
    font-size: 0.78rem;
    font-weight: 700;
    color: var(--cat-muted);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-right: 4px;
}
.cat-qf-chip {
    padding: 4px 12px;
    border-radius: 99px;
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--cat-body);
    background: var(--cat-bg-alt);
    border: 1px solid var(--cat-border);
    text-decoration: none;
    transition: all 0.15s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.cat-qf-chip:hover {
    border-color: var(--cat-blue-500);
    color: var(--cat-blue-600);
    background: var(--cat-blue-50);
}
.cat-qf-chip.active {
    background: var(--cat-blue-600);
    border-color: var(--cat-blue-600);
    color: #fff;
}

/* ── Smart Tabs Bar ── */
.cat-nav-tabs {
    display: flex;
    gap: 6px;
    border-bottom: 2px solid var(--cat-border);
    margin-bottom: 24px;
    overflow-x: auto;
    scrollbar-width: none;
}
.cat-nav-tabs::-webkit-scrollbar { display: none; }
.cat-nav-tab {
    padding: 12px 20px;
    font-size: 0.92rem;
    font-weight: 700;
    color: var(--cat-muted);
    text-decoration: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
    transition: all 0.15s;
}
.cat-nav-tab:hover {
    color: var(--cat-title);
}
.cat-nav-tab.active {
    color: var(--cat-blue-600);
    border-bottom-color: var(--cat-blue-600);
}
.cat-tab-badge {
    padding: 2px 9px;
    border-radius: 99px;
    font-size: 0.72rem;
    font-weight: 700;
    background: var(--cat-bg-subtle);
    color: var(--cat-muted);
}
.cat-nav-tab.active .cat-tab-badge {
    background: var(--cat-blue-100);
    color: var(--cat-blue-700);
}

/* ═════════════════════════════════════════════════════════════════════════
   PRODUCT GRID & CARDS
═════════════════════════════════════════════════════════════════════════ */
.cat-grid-layout {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 22px;
    margin-bottom: 30px;
}
@media (max-width: 1320px) {
    .cat-grid-layout { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 980px) {
    .cat-grid-layout { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 600px) {
    .cat-grid-layout { grid-template-columns: 1fr; }
}

.cat-card {
    background: var(--cat-bg);
    border: 1px solid var(--cat-border);
    border-radius: 18px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    position: relative;
    box-shadow: var(--cat-card-shadow);
    transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.25s ease, border-color 0.25s ease;
}
.cat-card:hover {
    transform: translateY(-6px);
    box-shadow: var(--cat-hover-shadow);
    border-color: var(--cat-border-hover);
}
.cat-card::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3.5px;
    background: linear-gradient(90deg, #2563eb, #38bdf8);
    opacity: 0;
    transition: opacity 0.25s ease;
    z-index: 4;
}
.cat-card:hover::before {
    opacity: 1;
}

/* ── Visual / Photo Area ── */
.cat-card-visual {
    height: 175px;
    position: relative;
    background: var(--cat-bg-alt);
    border-bottom: 1px solid var(--cat-border);
    overflow: hidden;
}
.cat-card-img-box {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden;
}
.cat-card-img-box.has-photo img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    padding: 12px;
    transition: transform 0.3s ease;
}
.cat-card:hover .cat-card-img-box.has-photo img {
    transform: scale(1.08);
}

/* Themed Medical Illustrations for products without uploaded images */
.cat-theme-tablet {
    background: radial-gradient(circle at 50% 30%, #f0f7ff 0%, #dbeafe 100%);
    color: #1d4ed8;
}
body.dark-mode .cat-theme-tablet {
    background: radial-gradient(circle at 50% 30%, #1e293b 0%, #0f172a 100%);
    color: #60a5fa;
}

.cat-theme-capsule {
    background: radial-gradient(circle at 50% 30%, #ecfdf5 0%, #ccfbf1 100%);
    color: #0f766e;
}
body.dark-mode .cat-theme-capsule {
    background: radial-gradient(circle at 50% 30%, #064e3b 0%, #022c22 100%);
    color: #34d399;
}

.cat-theme-cream {
    background: radial-gradient(circle at 50% 30%, #fffbeb 0%, #fef3c7 100%);
    color: #b45309;
}
body.dark-mode .cat-theme-cream {
    background: radial-gradient(circle at 50% 30%, #451a03 0%, #1c1917 100%);
    color: #fbbf24;
}

.cat-theme-inhaler {
    background: radial-gradient(circle at 50% 30%, #f0fdfa 0%, #e0f2fe 100%);
    color: #0284c7;
}

.cat-theme-powder {
    background: radial-gradient(circle at 50% 30%, #faf5ff 0%, #ede9fe 100%);
    color: #7c3aed;
}

.cat-theme-injection {
    background: radial-gradient(circle at 50% 30%, #f5f3ff 0%, #e0e7ff 100%);
    color: #4f46e5;
}

.cat-theme-syrup {
    background: radial-gradient(circle at 50% 30%, #fff7ed 0%, #ffedd5 100%);
    color: #c2410c;
}

.cat-theme-general {
    background: radial-gradient(circle at 50% 30%, #f8fafc 0%, #e2e8f0 100%);
    color: #475569;
}

.cat-illu-grid-pattern {
    position: absolute;
    inset: 0;
    opacity: 0.18;
    background-image: radial-gradient(currentColor 1.2px, transparent 1.2px);
    background-size: 14px 14px;
}
.cat-illu-content {
    position: relative;
    z-index: 2;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: transform 0.25s ease;
}
.cat-card:hover .cat-illu-content {
    transform: scale(1.05);
}
.cat-illu-icon-circle {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.92);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.85rem;
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.07);
    border: 1.5px solid rgba(255, 255, 255, 0.95);
}
body.dark-mode .cat-illu-icon-circle {
    background: rgba(30, 41, 59, 0.88);
    border-color: rgba(255, 255, 255, 0.12);
}
.cat-illu-badge-stamp {
    display: inline-block;
    padding: 3px 12px;
    border-radius: 99px;
    background: rgba(255, 255, 255, 0.92);
    font-size: 0.74rem;
    font-weight: 700;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
    letter-spacing: 0.02em;
}
body.dark-mode .cat-illu-badge-stamp {
    background: rgba(15, 23, 42, 0.88);
    color: #f1f5f9;
}
.cat-illu-watermark {
    position: absolute;
    bottom: 8px;
    right: 10px;
    font-size: 0.64rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    opacity: 0.45;
}

/* ── Badges on Visual Header ── */
.cat-card-badges-left {
    position: absolute;
    top: 10px;
    left: 10px;
    display: flex;
    flex-direction: column;
    gap: 5px;
    z-index: 3;
}
.cat-pill-badge {
    padding: 3.5px 10px;
    border-radius: 99px;
    font-size: 0.68rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    backdrop-filter: blur(8px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}
.cat-pill-badge.in {
    background: rgba(16, 185, 129, 0.92);
    color: #fff;
}
.cat-pill-badge.low {
    background: rgba(245, 158, 11, 0.92);
    color: #fff;
}
.cat-pill-badge.out {
    background: rgba(239, 68, 68, 0.92);
    color: #fff;
}
.cat-pill-badge.rx {
    background: rgba(124, 58, 237, 0.92);
    color: #fff;
}
.cat-pill-badge.otc {
    background: rgba(14, 165, 233, 0.92);
    color: #fff;
}
.cat-pill-badge.popular {
    background: rgba(225, 29, 72, 0.95);
    color: #fff;
}

.cat-dot-pulse {
    width: 6.5px;
    height: 6.5px;
    border-radius: 50%;
    background: #fff;
    display: inline-block;
    box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.4);
    animation: catPulse 1.8s infinite;
}
@keyframes catPulse {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.3); opacity: 0.7; }
}

/* ── Top-Right Action Floating Buttons ── */
.cat-card-actions-right {
    position: absolute;
    top: 10px;
    right: 10px;
    display: flex;
    gap: 6px;
    z-index: 3;
}
.cat-action-circle {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    border: 1px solid rgba(255, 255, 255, 0.8);
    background: rgba(255, 255, 255, 0.92);
    color: var(--cat-muted);
    font-size: 0.86rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
    backdrop-filter: blur(6px);
    transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
}
body.dark-mode .cat-action-circle {
    background: rgba(30, 41, 59, 0.9);
    border-color: rgba(255, 255, 255, 0.15);
    color: #cbd5e1;
}
.cat-action-circle:hover {
    transform: scale(1.12);
}
.cat-action-circle.fav.active {
    background: #ffe4e6;
    border-color: #fda4af;
    color: #e11d48;
}
.cat-action-circle.cmp.active {
    background: var(--cat-blue-100);
    border-color: #93c5fd;
    color: var(--cat-blue-700);
}

/* ── Hover Quick-View Pill ── */
.cat-quickview-btn {
    position: absolute;
    bottom: 12px;
    left: 50%;
    transform: translate(-50%, 15px);
    opacity: 0;
    background: rgba(15, 23, 42, 0.88);
    color: #fff;
    border: 1px solid rgba(255, 255, 255, 0.2);
    padding: 7px 18px;
    border-radius: 99px;
    font-size: 0.78rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    cursor: pointer;
    backdrop-filter: blur(6px);
    transition: all 0.22s ease;
    z-index: 3;
    pointer-events: none;
}
.cat-card-visual:hover .cat-quickview-btn {
    opacity: 1;
    transform: translate(-50%, 0);
    pointer-events: auto;
}
.cat-quickview-btn:hover {
    background: #0f172a;
    border-color: rgba(255, 255, 255, 0.4);
    transform: translate(-50%, -2px);
}

/* ── Card Body Details ── */
.cat-card-body {
    padding: 16px 18px 8px;
    flex: 1;
    display: flex;
    flex-direction: column;
}
.cat-meta-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 6px;
    font-size: 0.72rem;
    font-weight: 700;
}
.cat-cat-tag {
    color: var(--cat-blue-600);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.cat-origin-tag {
    color: var(--cat-muted);
    font-weight: 600;
    white-space: nowrap;
}

.cat-card-title {
    font-size: 1.05rem;
    font-weight: 800;
    color: var(--cat-title);
    margin: 0 0 3px;
    line-height: 1.35;
    letter-spacing: -0.01em;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    min-height: 2.7em;
}
.cat-card-subtitle {
    font-size: 0.82rem;
    color: var(--cat-muted);
    margin: 0 0 10px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-style: italic;
}

.cat-spec-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 10px;
}
.cat-spec-chip {
    padding: 3px 9px;
    border-radius: 6px;
    background: var(--cat-bg-subtle);
    border: 1px solid var(--cat-border);
    font-size: 0.73rem;
    font-weight: 600;
    color: var(--cat-body);
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.cat-spec-chip i {
    color: var(--cat-faint);
}

.cat-mfr-row {
    font-size: 0.76rem;
    color: var(--cat-muted);
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.cat-mfr-row i {
    color: var(--cat-faint);
}

/* ── Stock depth line ── */
.cat-stock-depth {
    margin-top: auto;
    padding-top: 8px;
    border-top: 1px dashed var(--cat-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 0.72rem;
    font-weight: 700;
    color: var(--cat-muted);
    margin-bottom: 12px;
}
.cat-stock-depth.in { color: var(--cat-emerald); }
.cat-stock-depth.low { color: var(--cat-amber); }
.cat-stock-depth.out { color: var(--cat-rose); }

/* ── Pricing Block ── */
.cat-price-block {
    background: var(--cat-bg-alt);
    border-radius: 10px;
    padding: 10px 12px;
    margin-bottom: 14px;
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 10px;
}
.cat-price-primary {
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--cat-title);
    line-height: 1;
}
.cat-price-curr {
    font-size: 0.8rem;
    font-weight: 700;
    color: var(--cat-muted);
    margin-right: 2px;
}
.cat-wholesale-tag {
    font-size: 0.73rem;
    font-weight: 700;
    color: var(--cat-emerald);
    background: var(--cat-emerald-bg);
    border: 1px solid var(--cat-emerald-border);
    padding: 2px 7px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

/* ── Card Footer Actions ── */
.cat-card-footer {
    padding: 0 18px 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.cat-order-bar {
    display: flex;
    align-items: stretch;
    gap: 8px;
}

/* Quantity Stepper */
.cat-stepper {
    display: inline-flex;
    align-items: center;
    background: var(--cat-bg-subtle);
    border: 1.5px solid var(--cat-border);
    border-radius: 9px;
    padding: 2px;
    flex-shrink: 0;
}
.cat-step-btn {
    width: 28px;
    height: 32px;
    border: none;
    background: transparent;
    color: var(--cat-body);
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.15s, color 0.15s;
    border-radius: 6px;
}
.cat-step-btn:hover {
    background: var(--cat-bg);
    color: var(--cat-blue-600);
}
.cat-step-input {
    width: 36px !important;
    height: 32px !important;
    border: none !important;
    background: transparent !important;
    text-align: center !important;
    font-size: 0.86rem !important;
    font-weight: 700 !important;
    color: var(--cat-title) !important;
    padding: 0 !important;
    margin: 0 !important;
    outline: none !important;
    -moz-appearance: textfield;
}
.cat-step-input::-webkit-outer-spin-button,
.cat-step-input::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

.cat-btn-add {
    flex: 1;
    background: var(--cat-blue-600);
    color: #fff;
    border: none;
    border-radius: 9px;
    padding: 8px 14px;
    font-size: 0.84rem;
    font-weight: 700;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 2px 6px rgba(37, 99, 235, 0.25);
    white-space: nowrap;
}
.cat-btn-add:hover {
    background: var(--cat-blue-700);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.35);
}
.cat-btn-add.out {
    background: var(--cat-bg-subtle);
    color: var(--cat-faint);
    border: 1px solid var(--cat-border);
    cursor: not-allowed;
    box-shadow: none;
    transform: none;
}
.cat-btn-add.login {
    background: #475569;
    box-shadow: none;
}
.cat-btn-add.login:hover {
    background: #334155;
}

.cat-btn-specs {
    border: 1.5px solid var(--cat-border);
    background: transparent;
    color: var(--cat-muted);
    border-radius: 9px;
    padding: 7px 12px;
    font-size: 0.78rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: all 0.15s;
}
.cat-btn-specs:hover {
    background: var(--cat-bg-alt);
    border-color: var(--cat-blue-500);
    color: var(--cat-blue-600);
}

/* ═════════════════════════════════════════════════════════════════════════
   LIST VIEW
═════════════════════════════════════════════════════════════════════════ */
.cat-list-layout {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-bottom: 30px;
}
.cat-list-item {
    background: var(--cat-bg);
    border: 1px solid var(--cat-border);
    border-radius: 14px;
    padding: 14px 18px;
    display: flex;
    align-items: center;
    gap: 18px;
    transition: border-color 0.2s, box-shadow 0.2s, transform 0.2s;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
}
.cat-list-item:hover {
    border-color: var(--cat-border-hover);
    box-shadow: var(--cat-card-shadow);
    transform: translateX(3px);
}
.cat-list-thumb {
    width: 68px;
    height: 68px;
    border-radius: 10px;
    overflow: hidden;
    flex-shrink: 0;
    border: 1px solid var(--cat-border);
    display: flex;
    align-items: center;
    justify-content: center;
}
.cat-list-thumb img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    padding: 4px;
}
.cat-list-thumb-icon {
    font-size: 1.6rem;
}
.cat-list-info {
    flex: 1;
    min-width: 0;
}
.cat-list-title-row {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.cat-list-title {
    font-size: 0.98rem;
    font-weight: 800;
    color: var(--cat-title);
    margin: 0;
    text-decoration: none;
}
.cat-list-sub {
    font-size: 0.8rem;
    color: var(--cat-muted);
    font-style: italic;
    margin-top: 2px;
}
.cat-list-specs-row {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-top: 6px;
    font-size: 0.78rem;
    color: var(--cat-muted);
    flex-wrap: wrap;
}
.cat-list-specs-row span {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.cat-list-right {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-shrink: 0;
}
.cat-list-price-box {
    text-align: right;
}
.cat-list-price {
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--cat-title);
}

/* ═════════════════════════════════════════════════════════════════════════
   BOTTOM FLOATING COMPARE DRAWER
═════════════════════════════════════════════════════════════════════════ */
.cat-compare-drawer {
    position: fixed;
    bottom: 20px;
    left: 50%;
    transform: translateX(-50%);
    background: #0f172a;
    color: #fff;
    padding: 12px 24px;
    border-radius: 99px;
    box-shadow: 0 16px 36px rgba(0, 0, 0, 0.35);
    display: flex;
    align-items: center;
    gap: 20px;
    z-index: 1000;
    border: 1px solid rgba(255, 255, 255, 0.2);
    animation: catSlideUp 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}
@keyframes catSlideUp {
    from { transform: translate(-50%, 60px); opacity: 0; }
    to { transform: translate(-50%, 0); opacity: 1; }
}
.cat-cmp-icon-badge {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: var(--cat-blue-600);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
}
.cat-cmp-btn-view {
    background: #fff;
    color: #0f172a;
    border: none;
    padding: 8px 18px;
    border-radius: 99px;
    font-weight: 800;
    font-size: 0.84rem;
    cursor: pointer;
    transition: background 0.15s;
}
.cat-cmp-btn-view:hover {
    background: #dbeafe;
}
.cat-cmp-btn-clear {
    background: transparent;
    color: #94a3b8;
    border: 1px solid rgba(255, 255, 255, 0.2);
    padding: 8px 14px;
    border-radius: 99px;
    font-weight: 700;
    font-size: 0.8rem;
    cursor: pointer;
    transition: color 0.15s;
}
.cat-cmp-btn-clear:hover {
    color: #fff;
    border-color: rgba(255, 255, 255, 0.4);
}

/* ═════════════════════════════════════════════════════════════════════════
   MODALS (Product Details & Comparison)
═════════════════════════════════════════════════════════════════════════ */
.cat-modal-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.65);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 20px;
    backdrop-filter: blur(4px);
}
.cat-modal-sheet {
    background: var(--cat-bg);
    border-radius: 20px;
    max-width: 720px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.35);
    border: 1px solid var(--cat-border);
}
.cat-modal-sheet.wide {
    max-width: 960px;
}
.cat-modal-head {
    padding: 22px 26px;
    border-bottom: 1px solid var(--cat-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}
.cat-modal-head-title {
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--cat-title);
    margin: 0;
}
.cat-modal-head-sub {
    font-size: 0.85rem;
    color: var(--cat-muted);
    margin: 2px 0 0;
}
.cat-modal-close-btn {
    background: var(--cat-bg-subtle);
    border: none;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    color: var(--cat-muted);
    font-size: 1.2rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s;
}
.cat-modal-close-btn:hover {
    background: #fee2e2;
    color: #dc2626;
}
.cat-modal-content {
    padding: 24px 26px;
}
.cat-modal-foot {
    padding: 18px 26px;
    border-top: 1px solid var(--cat-border);
    background: var(--cat-bg-alt);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    border-radius: 0 0 20px 20px;
}

/* Compare Matrix Table */
.cat-compare-table-wrap {
    overflow-x: auto;
}
.cat-compare-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.86rem;
}
.cat-compare-table th,
.cat-compare-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--cat-border);
    text-align: left;
}
.cat-compare-table th.spec-name,
.cat-compare-table td.spec-name {
    width: 160px;
    font-weight: 700;
    color: var(--cat-muted);
    background: var(--cat-bg-alt);
}
.cat-compare-table tr:hover td {
    background: var(--cat-bg-subtle);
}

/* Empty State */
.cat-empty-state {
    text-align: center;
    padding: 60px 20px;
    background: var(--cat-bg);
    border-radius: 18px;
    border: 1px dashed var(--cat-border);
}
.cat-empty-icon {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: var(--cat-blue-50);
    color: var(--cat-blue-600);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    margin: 0 auto 16px;
}
.cat-empty-title {
    font-size: 1.2rem;
    font-weight: 800;
    color: var(--cat-title);
    margin: 0 0 6px;
}
.cat-empty-msg {
    font-size: 0.9rem;
    color: var(--cat-muted);
    max-width: 440px;
    margin: 0 auto 18px;
    line-height: 1.5;
}
.cat-empty-cta {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 22px;
    background: var(--cat-blue-600);
    color: #fff;
    border-radius: 9px;
    font-weight: 700;
    text-decoration: none;
    font-size: 0.88rem;
}
</style>

<div class="cat-wrap">

<!-- ════════════ HERO BANNER ════════════ -->
<div class="cat-hero">
    <div class="cat-hero-content">
        <div class="cat-hero-left">
            <span class="cat-eyebrow"><i class="fas fa-shield-halved"></i> Verified Wholesale Distribution</span>
            <h1 class="cat-hero-title">Wholesale <span class="hl">Pharmaceutical</span> Catalog</h1>
            <p class="cat-hero-desc">Direct manufacturer pricing, batch-level FEFO tracking, and reliable wholesale fulfillment for registered pharmacies and healthcare institutions.</p>
            <div class="cat-hero-trust">
                <span><i class="fas fa-check-circle"></i> GMP Certified Facilities</span>
                <span><i class="fas fa-snowflake"></i> Temperature Controlled</span>
                <span><i class="fas fa-bolt"></i> Fast Priority Dispatch</span>
            </div>
        </div>
        <div class="cat-hero-stats">
            <div class="cat-stat-tile">
                <span class="cat-stat-val"><?= number_format($totalActive) ?></span>
                <span class="cat-stat-lbl">Active Products</span>
            </div>
            <div class="cat-stat-tile">
                <span class="cat-stat-val"><?= count($catCounts) ?></span>
                <span class="cat-stat-lbl">Categories</span>
            </div>
            <div class="cat-stat-tile">
                <span class="cat-stat-val"><?= count($allManufacturers) ?></span>
                <span class="cat-stat-lbl">Manufacturers</span>
            </div>
            <div class="cat-stat-tile">
                <span class="cat-stat-val"><?= $inStockRate ?>%</span>
                <span class="cat-stat-lbl">In-Stock Rate</span>
            </div>
        </div>
    </div>
</div>

<!-- ════════════ CATEGORY CHIPS BAR ════════════ -->
<div class="cat-chips-bar">
    <a class="cat-chip-item <?= $catFilter === 0 ? 'active' : '' ?>" href="?page=catalog&<?= http_build_query(array_merge($_GET, ['category_id' => '', 'tab' => 'all'])) ?>">
        <i class="fas fa-th-large"></i> All Categories <span class="cat-chip-badge"><?= $totalActive ?></span>
    </a>
    <?php foreach ($catCounts as $cid => $cnt): ?>
        <a class="cat-chip-item <?= $catFilter === $cid ? 'active' : '' ?>" href="?page=catalog&<?= http_build_query(array_merge($_GET, ['category_id' => (string)$cid, 'tab' => 'all'])) ?>">
            <i class="fas <?= catIcon($catNames[$cid] ?? '') ?>"></i> <?= htmlspecialchars($catNames[$cid] ?? 'Category') ?> <span class="cat-chip-badge"><?= $cnt ?></span>
        </a>
    <?php endforeach; ?>
</div>

<!-- ════════════ SEARCH & TOOLBAR CONTROLS ════════════ -->
<div class="cat-control-card">
    <!-- Big Search Input -->
    <form method="get" id="catSearchForm">
        <input type="hidden" name="page" value="catalog">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
        <div class="cat-search-box">
            <i class="fas fa-search" style="color:var(--cat-faint);font-size:1.1rem;margin-right:6px"></i>
            <input type="text" name="search" id="catSearchInput" class="cat-search-input" placeholder="Search by medicine name, generic formula, brand, manufacturer, dosage, barcode..." value="<?= htmlspecialchars($search) ?>" autocomplete="off">
            <div class="cat-search-actions">
                <button type="button" class="cat-scan-btn" onclick="openCameraScanner()" title="Scan product barcode with camera"><i class="fas fa-camera"></i> Camera</button>
                <button type="button" class="cat-scan-btn" id="catScanBtn" title="Scan with handheld USB barcode reader"><i class="fas fa-barcode"></i> Scanner</button>
                <button type="submit" class="cat-submit-btn"><i class="fas fa-magnifying-glass"></i> Search</button>
            </div>
        </div>
    </form>
    <input type="text" id="catBarcodeInput" style="position:absolute;left:-9999px;opacity:0" tabindex="-1">

    <!-- Filters & Sort Row (Responsive flex, prevents 100% width stacking) -->
    <div class="cat-toolbar-row">
        <form class="cat-filters-inline" method="get" id="catFilterForm">
            <input type="hidden" name="page" value="catalog">
            <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
            <input type="hidden" name="view" value="<?= htmlspecialchars($viewMode) ?>">
            <?php if ($rxFilter !== -1): ?><input type="hidden" name="rx" value="<?= $rxFilter ?>"><?php endif; ?>
            <?php if ($dealFilter): ?><input type="hidden" name="deals" value="1"><?php endif; ?>

            <select name="category_id" class="cat-filter-select <?= $catFilter > 0 ? 'is-active' : '' ?>" onchange="this.form.submit()">
                <option value="">All Categories (<?= count($catCounts) ?>)</option>
                <?php foreach ($allCats as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $catFilter === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="manufacturer" class="cat-filter-select <?= $mfrFilter !== '' ? 'is-active' : '' ?>" onchange="this.form.submit()">
                <option value="">All Manufacturers (<?= count($allManufacturers) ?>)</option>
                <?php foreach ($allManufacturers as $m): ?>
                    <option value="<?= htmlspecialchars($m) ?>" <?= $mfrFilter === $m ? 'selected' : '' ?>><?= htmlspecialchars($m) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="stock_status" class="cat-filter-select <?= $stockFilter !== '' ? 'is-active' : '' ?>" onchange="this.form.submit()">
                <option value="">All Stock Status</option>
                <option value="in_stock" <?= $stockFilter === 'in_stock' ? 'selected' : '' ?>>In Stock</option>
                <?php if ($catShowInternal): ?>
                    <option value="low_stock" <?= $stockFilter === 'low_stock' ? 'selected' : '' ?>>Low Stock (≤10)</option>
                    <option value="expiring" <?= $stockFilter === 'expiring' ? 'selected' : '' ?>>Expiring Soon (90d)</option>
                <?php endif; ?>
                <option value="out_of_stock" <?= $stockFilter === 'out_of_stock' ? 'selected' : '' ?>>Out of Stock</option>
            </select>

            <select name="sort" class="cat-filter-select" onchange="this.form.submit()">
                <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name A-Z</option>
                <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price: Low to High</option>
                <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: High to Low</option>
                <option value="stock" <?= $sort === 'stock' ? 'selected' : '' ?>>Stock: Low to High</option>
                <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest Arrivals</option>
            </select>

            <?php if ($search || $catFilter || $mfrFilter || $stockFilter || $rxFilter !== -1 || $dealFilter): ?>
                <a href="?page=catalog" class="cat-clear-link" title="Clear all active filters"><i class="fas fa-times"></i> Reset</a>
            <?php endif; ?>
        </form>

        <div class="cat-view-toggles">
            <a href="?page=catalog&<?= http_build_query(array_merge($_GET, ['view' => 'grid'])) ?>" class="cat-view-btn <?= $viewMode === 'grid' || $viewMode !== 'list' ? 'active' : '' ?>" title="Grid View"><i class="fas fa-th-large"></i></a>
            <a href="?page=catalog&<?= http_build_query(array_merge($_GET, ['view' => 'list'])) ?>" class="cat-view-btn <?= $viewMode === 'list' ? 'active' : '' ?>" title="List / Bulk Re-Order View"><i class="fas fa-list"></i></a>
        </div>
    </div>

    <!-- Quick Filter Tag Chips -->
    <div class="cat-qf-bar">
        <span class="cat-qf-label"><i class="fas fa-sliders"></i> Quick Filters:</span>
        <a href="?page=catalog&<?= http_build_query(array_merge($_GET, ['stock_status' => $stockFilter === 'in_stock' ? '' : 'in_stock'])) ?>" class="cat-qf-chip <?= $stockFilter === 'in_stock' ? 'active' : '' ?>">
            <i class="fas fa-check-circle"></i> In Stock Only
        </a>
        <a href="?page=catalog&<?= http_build_query(array_merge($_GET, ['rx' => $rxFilter === 1 ? '' : '1'])) ?>" class="cat-qf-chip <?= $rxFilter === 1 ? 'active' : '' ?>">
            <i class="fas fa-prescription"></i> Prescription (Rx)
        </a>
        <a href="?page=catalog&<?= http_build_query(array_merge($_GET, ['rx' => $rxFilter === 0 ? '' : '0'])) ?>" class="cat-qf-chip <?= $rxFilter === 0 ? 'active' : '' ?>">
            <i class="fas fa-shield-heart"></i> OTC Medicines
        </a>
        <a href="?page=catalog&<?= http_build_query(array_merge($_GET, ['deals' => $dealFilter ? '' : '1'])) ?>" class="cat-qf-chip <?= $dealFilter ? 'active' : '' ?>">
            <i class="fas fa-tags"></i> Wholesale Specials
        </a>
    </div>
</div>

<!-- ════════════ SMART NAV TABS ════════════ -->
<div class="cat-nav-tabs">
    <a href="?page=catalog&tab=all&<?= http_build_query(array_diff_key($_GET, ['tab'=>''])) ?>" class="cat-nav-tab <?= $activeTab === 'all' ? 'active' : '' ?>">
        <i class="fas fa-layer-group"></i> All Products <span class="cat-tab-badge" id="catLiveCount"><?= $totalProducts ?></span>
    </a>
    <a href="?page=catalog&tab=frequent&<?= http_build_query(array_diff_key($_GET, ['tab'=>''])) ?>" class="cat-nav-tab <?= $activeTab === 'frequent' ? 'active' : '' ?>">
        <i class="fas fa-fire" style="color:#ea580c"></i> Frequently Ordered <span class="cat-tab-badge"><?= count($frequentlyOrdered) ?></span>
    </a>
    <a href="?page=catalog&tab=recent&<?= http_build_query(array_diff_key($_GET, ['tab'=>''])) ?>" class="cat-nav-tab <?= $activeTab === 'recent' ? 'active' : '' ?>">
        <i class="fas fa-clock-rotate-left"></i> Recently Purchased <span class="cat-tab-badge"><?= count($recentlyPurchased) ?></span>
    </a>
    <?php if ($customerId): ?>
    <a href="?page=catalog&tab=favorites&<?= http_build_query(array_diff_key($_GET, ['tab'=>''])) ?>" class="cat-nav-tab <?= $activeTab === 'favorites' ? 'active' : '' ?>">
        <i class="fas fa-heart" style="color:#e11d48"></i> Saved Favorites <span class="cat-tab-badge"><?= count($favIds) ?></span>
    </a>
    <?php endif; ?>
</div>

<div id="catLiveEmptyNotice" style="display:none">
    <div class="cat-empty-state">
        <div class="cat-empty-icon"><i class="fas fa-search"></i></div>
        <h3 class="cat-empty-title">No matching medicines found</h3>
        <p class="cat-empty-msg">No products on this page match your live search. Try a broader search keyword.</p>
    </div>
</div>

<?php if (empty($displayProducts)): ?>
    <div class="cat-empty-state">
        <div class="cat-empty-icon"><i class="fas fa-box-open"></i></div>
        <h3 class="cat-empty-title">No products found</h3>
        <p class="cat-empty-msg">No active products match your current search and filter criteria. You can reset filters to view our full wholesale inventory.</p>
        <a href="?page=catalog" class="cat-empty-cta"><i class="fas fa-undo-alt"></i> Browse All Products</a>
    </div>
<?php else: ?>

<!-- ════════════ GRID VIEW ════════════ -->
<?php if ($viewMode !== 'list'): ?>
<div class="cat-grid-layout" id="catProductGrid">
    <?php foreach ($displayProducts as $p):
        $pid = (int) $p['id'];
        $stock = (int) $p['stock_quantity'];
        $sb = stockBadge($stock, $catShowInternal);
        $isFav = in_array($pid, $favIds);
        $isCmp = in_array($pid, $compareIds);
        $isFrq = in_array($pid, $tabFrequentIds);
        $earliest = $expiryMap[$pid] ?? null;
        $expSoon = ($earliest && $stock > 0 && strtotime($earliest) <= strtotime('+90 days'));
        $imgFull = storage_exists($p['image_path'], 'products') ? htmlspecialchars(storage_url($p['image_path'], 'products')) : '';
        $wp = ($isCustomerUser && (float)($p['wholesale_price'] ?? 0) > 0 && (float)$p['wholesale_price'] < (float)$p['price']) ? (float)$p['wholesale_price'] : 0;
        $dosageInfo = getDosageTheme($p['dosage_form'] ?? '');
    ?>
    <div class="cat-card" data-pid="<?= $pid ?>" data-name="<?= htmlspecialchars($p['name']) ?>" data-brand="<?= htmlspecialchars($p['brand'] ?? '') ?>" data-generic="<?= htmlspecialchars($p['generic_name'] ?? '') ?>" data-barcode="<?= htmlspecialchars($p['barcode'] ?? '') ?>" data-dosage="<?= htmlspecialchars($p['dosage_form'] ?? '') ?>" data-strength="<?= htmlspecialchars($p['strength'] ?? '') ?>" data-mfr="<?= htmlspecialchars($p['manufacturer'] ?? '') ?>" data-category="<?= htmlspecialchars($p['category_name'] ?? '') ?>" data-price="<?= fmt((float)$p['price']) ?>" data-pack="<?= htmlspecialchars($p['pack_size'] ?? '') ?>" data-unit="<?= htmlspecialchars($p['unit_of_measure'] ?? '') ?>" data-country="<?= htmlspecialchars($p['country_of_origin'] ?? '') ?>" data-storage="<?= htmlspecialchars($p['storage_condition'] ?? '') ?>" data-desc="<?= htmlspecialchars($p['description'] ?? '') ?>" data-image="<?= $imgFull ?>" data-mfg="<?= htmlspecialchars($p['manufacturing_date'] ?? '') ?>" data-rx="<?= (int)($p['requires_prescription'] ?? 0) ?>" data-wp="<?= $wp > 0 ? fmt($wp) : '' ?>" data-avail="<?= $stock <= 0 ? 0 : 1 ?>" data-lowstock="<?= ($catShowInternal && $stock > 0 && $stock <= 10) ? 1 : 0 ?>" data-batches="<?= buildBatchJson($batchMap, $pid) ?>">
        
        <!-- Visual Header -->
        <div class="cat-card-visual" onclick="showCatDetail(this.closest('.cat-card'))" style="cursor:pointer">
            <!-- Left Badges -->
            <div class="cat-card-badges-left">
                <span class="cat-pill-badge <?= $sb[1] ?>">
                    <span class="cat-dot-pulse"></span> <?= $sb[0] ?>
                </span>
                <?php if (!empty($p['requires_prescription'])): ?>
                    <span class="cat-pill-badge rx" title="Prescription Required by Law"><i class="fas fa-prescription"></i> Rx</span>
                <?php else: ?>
                    <span class="cat-pill-badge otc" title="Over-the-Counter Medicine"><i class="fas fa-shield-heart"></i> OTC</span>
                <?php endif; ?>
                <?php if ($isFrq): ?>
                    <span class="cat-pill-badge popular" title="Frequently Ordered by Pharmacies"><i class="fas fa-fire"></i> Best Seller</span>
                <?php endif; ?>
                <?php if ($catShowInternal && $expSoon): ?>
                    <span class="cat-pill-badge low" title="Expires soon"><i class="fas fa-hourglass-half"></i> Exp <?= date('M Y', strtotime($earliest)) ?></span>
                <?php endif; ?>
            </div>

            <!-- Right Action Buttons -->
            <div class="cat-card-actions-right" onclick="event.stopPropagation()">
                <?php if ($isCustomerUser): ?>
                    <button type="button" class="cat-action-circle fav <?= $isFav ? 'active' : '' ?>" onclick="catToggleFav(event, <?= $pid ?>)" title="<?= $isFav ? 'Remove Favorite' : 'Save to Favorites' ?>">
                        <i class="<?= $isFav ? 'fa-solid' : 'fa-regular' ?> fa-heart"></i>
                    </button>
                    <button type="button" class="cat-action-circle cmp <?= $isCmp ? 'active' : '' ?>" onclick="catToggleCmp(event, <?= $pid ?>)" title="<?= $isCmp ? 'Remove from Comparison' : 'Add to Compare' ?>">
                        <i class="fa-solid fa-scale-balanced"></i>
                    </button>
                <?php endif; ?>
            </div>

            <!-- Artwork / Product Photo -->
            <?php if ($imgFull): ?>
                <div class="cat-card-img-box has-photo">
                    <img src="<?= $imgFull ?>" alt="<?= htmlspecialchars($p['name']) ?>" loading="lazy">
                </div>
            <?php else: ?>
                <div class="cat-card-img-box cat-theme-<?= $dosageInfo['theme'] ?>">
                    <div class="cat-illu-grid-pattern"></div>
                    <div class="cat-illu-content">
                        <div class="cat-illu-icon-circle">
                            <i class="fa-solid <?= $dosageInfo['icon'] ?>"></i>
                        </div>
                        <div class="cat-illu-badge-stamp">
                            <span class="cat-stamp-strength"><?= htmlspecialchars($p['strength'] ?: ($p['dosage_form'] ?: 'Standard')) ?></span>
                        </div>
                    </div>
                    <div class="cat-illu-watermark"><?= htmlspecialchars($dosageInfo['label']) ?></div>
                </div>
            <?php endif; ?>

            <button type="button" class="cat-quickview-btn" onclick="catQuickView(event)">
                <i class="fas fa-eye"></i> Quick View
            </button>
        </div>

        <!-- Card Body -->
        <div class="cat-card-body">
            <div class="cat-meta-row">
                <span class="cat-cat-tag"><i class="fas <?= catIcon($p['category_name'] ?? '') ?>"></i> <?= htmlspecialchars($p['category_name'] ?? 'Pharmaceuticals') ?></span>
                <?php if ($p['country_of_origin']): ?>
                    <span class="cat-origin-tag"><?= countryFlag($p['country_of_origin']) ?> <?= htmlspecialchars($p['country_of_origin']) ?></span>
                <?php endif; ?>
            </div>

            <h3 class="cat-card-title" title="<?= htmlspecialchars($p['name']) ?>" onclick="showCatDetail(this.closest('.cat-card'))" style="cursor:pointer"><?= htmlspecialchars($p['name']) ?></h3>
            <div class="cat-card-subtitle"><?= htmlspecialchars(($p['generic_name'] ?? '') ?: ($p['brand'] ?? '')) ?></div>

            <div class="cat-spec-chips">
                <?php if ($p['dosage_form']): ?>
                    <span class="cat-spec-chip"><i class="fas fa-capsules"></i> <?= htmlspecialchars($p['dosage_form']) ?></span>
                <?php endif; ?>
                <?php if ($p['strength']): ?>
                    <span class="cat-spec-chip"><i class="fas fa-weight-scale"></i> <?= htmlspecialchars($p['strength']) ?></span>
                <?php endif; ?>
                <?php if ($p['pack_size']): ?>
                    <span class="cat-spec-chip"><i class="fas fa-box"></i> <?= htmlspecialchars($p['pack_size']) ?></span>
                <?php endif; ?>
            </div>

            <div class="cat-mfr-row">
                <i class="fas fa-industry"></i>
                <span><?= htmlspecialchars($p['manufacturer'] ?: 'Verified Manufacturer') ?></span>
            </div>

            <div class="cat-stock-depth <?= $sb[1] ?>">
                <span><i class="fas fa-boxes-stacked"></i> <?= $stock > 0 ? number_format($stock) . ' units available' : 'Currently Backordered' ?></span>
                <?php if ($p['sku'] || $p['barcode']): ?>
                    <span style="font-family:monospace;color:var(--cat-faint)">#<?= htmlspecialchars($p['barcode'] ?: $p['sku']) ?></span>
                <?php endif; ?>
            </div>

            <div class="cat-price-block">
                <div>
                    <span style="font-size:0.7rem;font-weight:700;color:var(--cat-muted);text-transform:uppercase;display:block">Unit Price</span>
                    <div class="cat-price-primary"><span class="cat-price-curr"><?= currency_sym() ?></span><?= number_format((float)$p['price'], 2) ?></div>
                </div>
                <?php if ($wp > 0): ?>
                    <div style="text-align:right">
                        <span class="cat-wholesale-tag" title="Wholesale / Bulk order price"><i class="fas fa-tag"></i> WS: <?= fmt($wp) ?></span>
                        <div style="font-size:0.68rem;font-weight:700;color:var(--cat-emerald);margin-top:2px">Save <?= round((1 - ($wp / (float)$p['price'])) * 100) ?>%</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Card Footer -->
        <div class="cat-card-footer">
            <div class="cat-order-bar">
                <?php if ($stock <= 0): ?>
                    <button class="cat-btn-add out" disabled><i class="fas fa-ban"></i> Out of Stock</button>
                <?php elseif ($isCustomerUser): ?>
                    <div class="cat-stepper" onclick="event.stopPropagation()">
                        <button type="button" class="cat-step-btn minus" onclick="stepQty(this, -1)">−</button>
                        <input type="number" class="cat-step-input" value="1" min="1" max="<?= max(1, $stock) ?>" data-pid="<?= $pid ?>" onchange="validateStep(this)">
                        <button type="button" class="cat-step-btn plus" onclick="stepQty(this, 1)">+</button>
                    </div>
                    <a href="index.php?page=place_order&product_id=<?= $pid ?>&qty=1" class="cat-btn-add" onclick="submitToOrder(event, this, <?= $pid ?>)">
                        <i class="fas fa-cart-plus"></i> Add to Order
                    </a>
                <?php else: ?>
                    <a href="index.php?page=login" class="cat-btn-add login" onclick="event.stopPropagation()">
                        <i class="fas fa-sign-in-alt"></i> Sign in to Order
                    </a>
                <?php endif; ?>
            </div>
            <button type="button" class="cat-btn-specs" onclick="event.stopPropagation();showCatDetail(this.closest('.cat-card'))">
                <i class="fas fa-circle-info"></i> View Clinical Specs
            </button>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ════════════ LIST VIEW ════════════ -->
<?php else: ?>
<div class="cat-list-layout" id="catProductList">
    <?php foreach ($displayProducts as $p):
        $pid = (int) $p['id'];
        $stock = (int) $p['stock_quantity'];
        $sb = stockBadge($stock, $catShowInternal);
        $isFav = in_array($pid, $favIds);
        $isCmp = in_array($pid, $compareIds);
        $imgFull = storage_exists($p['image_path'], 'products') ? htmlspecialchars(storage_url($p['image_path'], 'products')) : '';
        $wp = ($isCustomerUser && (float)($p['wholesale_price'] ?? 0) > 0 && (float)$p['wholesale_price'] < (float)$p['price']) ? (float)$p['wholesale_price'] : 0;
        $dosageInfo = getDosageTheme($p['dosage_form'] ?? '');
    ?>
    <div class="cat-list-item" data-pid="<?= $pid ?>" data-name="<?= htmlspecialchars($p['name']) ?>" data-brand="<?= htmlspecialchars($p['brand'] ?? '') ?>" data-generic="<?= htmlspecialchars($p['generic_name'] ?? '') ?>" data-barcode="<?= htmlspecialchars($p['barcode'] ?? '') ?>" data-dosage="<?= htmlspecialchars($p['dosage_form'] ?? '') ?>" data-strength="<?= htmlspecialchars($p['strength'] ?? '') ?>" data-mfr="<?= htmlspecialchars($p['manufacturer'] ?? '') ?>" data-category="<?= htmlspecialchars($p['category_name'] ?? '') ?>" data-price="<?= fmt((float)$p['price']) ?>" data-pack="<?= htmlspecialchars($p['pack_size'] ?? '') ?>" data-unit="<?= htmlspecialchars($p['unit_of_measure'] ?? '') ?>" data-country="<?= htmlspecialchars($p['country_of_origin'] ?? '') ?>" data-storage="<?= htmlspecialchars($p['storage_condition'] ?? '') ?>" data-desc="<?= htmlspecialchars($p['description'] ?? '') ?>" data-image="<?= $imgFull ?>" data-mfg="<?= htmlspecialchars($p['manufacturing_date'] ?? '') ?>" data-rx="<?= (int)($p['requires_prescription'] ?? 0) ?>" data-wp="<?= $wp > 0 ? fmt($wp) : '' ?>" data-avail="<?= $stock <= 0 ? 0 : 1 ?>" data-lowstock="<?= ($catShowInternal && $stock > 0 && $stock <= 10) ? 1 : 0 ?>" data-batches="<?= buildBatchJson($batchMap, $pid) ?>">
        <div class="cat-list-thumb cat-theme-<?= $dosageInfo['theme'] ?>">
            <?php if ($imgFull): ?>
                <img src="<?= $imgFull ?>" alt="<?= htmlspecialchars($p['name']) ?>">
            <?php else: ?>
                <span class="cat-list-thumb-icon"><i class="fa-solid <?= $dosageInfo['icon'] ?>"></i></span>
            <?php endif; ?>
        </div>
        <div class="cat-list-info">
            <div class="cat-list-title-row">
                <a href="#" class="cat-list-title" onclick="event.preventDefault();showCatDetail(this.closest('.cat-list-item'))"><?= htmlspecialchars($p['name']) ?></a>
                <span class="cat-pill-badge <?= $sb[1] ?>" style="font-size:0.64rem"><span class="cat-dot-pulse"></span> <?= $sb[0] ?></span>
                <?php if (!empty($p['requires_prescription'])): ?>
                    <span class="cat-pill-badge rx" style="font-size:0.64rem">Rx</span>
                <?php endif; ?>
            </div>
            <div class="cat-list-sub"><?= htmlspecialchars(($p['generic_name'] ?? '') ?: ($p['brand'] ?? '')) ?></div>
            <div class="cat-list-specs-row">
                <?php if ($p['dosage_form']): ?><span><i class="fas fa-capsules"></i> <?= htmlspecialchars($p['dosage_form']) ?></span><?php endif; ?>
                <?php if ($p['strength']): ?><span><i class="fas fa-weight-scale"></i> <?= htmlspecialchars($p['strength']) ?></span><?php endif; ?>
                <?php if ($p['category_name']): ?><span><i class="fas fa-tag"></i> <?= htmlspecialchars($p['category_name']) ?></span><?php endif; ?>
                <?php if ($p['manufacturer']): ?><span><i class="fas fa-industry"></i> <?= htmlspecialchars($p['manufacturer']) ?></span><?php endif; ?>
                <?php if ($p['country_of_origin']): ?><span><?= countryFlag($p['country_of_origin']) ?> <?= htmlspecialchars($p['country_of_origin']) ?></span><?php endif; ?>
            </div>
        </div>
        <div class="cat-list-right">
            <div class="cat-list-price-box">
                <div class="cat-list-price"><?= fmt((float)$p['price']) ?></div>
                <?php if ($wp > 0): ?>
                    <span class="cat-wholesale-tag">WS: <?= fmt($wp) ?></span>
                <?php endif; ?>
            </div>
            <div class="cat-order-bar">
                <?php if ($stock <= 0): ?>
                    <button class="cat-btn-add out" disabled><i class="fas fa-ban"></i> Out of Stock</button>
                <?php elseif ($isCustomerUser): ?>
                    <div class="cat-stepper">
                        <button type="button" class="cat-step-btn minus" onclick="stepQty(this, -1)">−</button>
                        <input type="number" class="cat-step-input" value="1" min="1" max="<?= max(1, $stock) ?>" data-pid="<?= $pid ?>" onchange="validateStep(this)">
                        <button type="button" class="cat-step-btn plus" onclick="stepQty(this, 1)">+</button>
                    </div>
                    <a href="index.php?page=place_order&product_id=<?= $pid ?>&qty=1" class="cat-btn-add" onclick="submitToOrder(event, this, <?= $pid ?>)">
                        <i class="fas fa-cart-plus"></i> Add
                    </a>
                <?php else: ?>
                    <a href="index.php?page=login" class="cat-btn-add login"><i class="fas fa-sign-in-alt"></i> Sign in</a>
                <?php endif; ?>
            </div>
            <button type="button" class="cat-btn-specs" onclick="showCatDetail(this.closest('.cat-list-item'))" title="Quick specs"><i class="fas fa-eye"></i></button>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- ════════════ FLOATING COMPARISON DRAWER ════════════ -->
<?php if (!empty($compareProducts)): ?>
<div class="cat-compare-drawer" id="catCompareDrawer">
    <div class="cat-cmp-icon-badge"><i class="fa-solid fa-scale-balanced"></i></div>
    <div>
        <div style="font-weight:800;font-size:0.9rem">Comparing <?= count($compareProducts) ?> Products</div>
        <div style="font-size:0.75rem;color:#94a3b8;max-width:320px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
            <?= htmlspecialchars(implode(', ', array_map(fn($cp) => $cp['name'], $compareProducts))) ?>
        </div>
    </div>
    <button type="button" class="cat-cmp-btn-view" onclick="openCompareModal()"><i class="fas fa-columns"></i> View Comparison</button>
    <form method="post" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="_compare_clear" value="1">
        <button type="submit" class="cat-cmp-btn-clear" title="Clear comparison list"><i class="fas fa-times"></i> Clear</button>
    </form>
</div>
<?php endif; ?>

<!-- ════════════ COMPARISON MODAL ════════════ -->
<div class="cat-modal-backdrop" id="catCompareModal" onclick="if(event.target===this)closeCompareModal()">
    <div class="cat-modal-sheet wide">
        <div class="cat-modal-head">
            <div>
                <h3 class="cat-modal-head-title"><i class="fa-solid fa-scale-balanced" style="color:var(--cat-blue-600)"></i> Side-by-Side Product Comparison</h3>
                <p class="cat-modal-head-sub">Compare clinical specifications, strength, prices, and stock availability across brands</p>
            </div>
            <button type="button" class="cat-modal-close-btn" onclick="closeCompareModal()">&times;</button>
        </div>
        <div class="cat-modal-content">
            <?php if (!empty($compareProducts)): ?>
            <div class="cat-compare-table-wrap">
                <table class="cat-compare-table">
                    <thead>
                        <tr>
                            <th class="spec-name">Attribute</th>
                            <?php foreach ($compareProducts as $cp): ?>
                                <th>
                                    <div style="font-weight:800;font-size:0.95rem;color:var(--cat-title)"><?= htmlspecialchars($cp['name']) ?></div>
                                    <div style="font-size:0.78rem;color:var(--cat-muted);font-style:italic"><?= htmlspecialchars($cp['generic_name'] ?: ($cp['brand'] ?: '—')) ?></div>
                                    <div style="font-size:1.1rem;font-weight:800;color:var(--cat-blue-600);margin-top:4px"><?= fmt((float)$cp['price']) ?></div>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="spec-name"><i class="fas fa-tag"></i> Category</td>
                            <?php foreach ($compareProducts as $cp): ?><td><?= htmlspecialchars($cp['category_name'] ?? '—') ?></td><?php endforeach; ?>
                        </tr>
                        <tr>
                            <td class="spec-name"><i class="fas fa-capsules"></i> Dosage Form</td>
                            <?php foreach ($compareProducts as $cp): ?><td><strong><?= htmlspecialchars($cp['dosage_form'] ?? '—') ?></strong></td><?php endforeach; ?>
                        </tr>
                        <tr>
                            <td class="spec-name"><i class="fas fa-weight-scale"></i> Strength</td>
                            <?php foreach ($compareProducts as $cp): ?><td><strong><?= htmlspecialchars($cp['strength'] ?? '—') ?></strong></td><?php endforeach; ?>
                        </tr>
                        <tr>
                            <td class="spec-name"><i class="fas fa-industry"></i> Manufacturer</td>
                            <?php foreach ($compareProducts as $cp): ?><td><?= htmlspecialchars($cp['manufacturer'] ?? '—') ?></td><?php endforeach; ?>
                        </tr>
                        <tr>
                            <td class="spec-name"><i class="fas fa-globe"></i> Origin</td>
                            <?php foreach ($compareProducts as $cp): ?><td><?= countryFlag($cp['country_of_origin'] ?? '') ?> <?= htmlspecialchars($cp['country_of_origin'] ?? '—') ?></td><?php endforeach; ?>
                        </tr>
                        <tr>
                            <td class="spec-name"><i class="fas fa-prescription"></i> Prescription</td>
                            <?php foreach ($compareProducts as $cp): ?><td><?= !empty($cp['requires_prescription']) ? '<span class="cat-pill-badge rx" style="font-size:0.7rem">Rx Required</span>' : '<span class="cat-pill-badge otc" style="font-size:0.7rem">OTC</span>' ?></td><?php endforeach; ?>
                        </tr>
                        <tr>
                            <td class="spec-name"><i class="fas fa-boxes-stacked"></i> Stock Status</td>
                            <?php foreach ($compareProducts as $cp): ?><td><?= (int)$cp['stock_quantity'] > 0 ? '<span style="color:var(--cat-emerald);font-weight:700">● In Stock (' . (int)$cp['stock_quantity'] . ')</span>' : '<span style="color:var(--cat-rose);font-weight:700">Out of Stock</span>' ?></td><?php endforeach; ?>
                        </tr>
                        <tr style="background:var(--cat-bg-alt)">
                            <td class="spec-name">Quick Action</td>
                            <?php foreach ($compareProducts as $cp): ?>
                                <td>
                                    <?php if ((int)$cp['stock_quantity'] > 0 && $isCustomerUser): ?>
                                        <a href="index.php?page=place_order&product_id=<?= (int)$cp['id'] ?>&qty=1" class="cat-btn-add" style="padding:7px 14px"><i class="fas fa-cart-plus"></i> Order Now</a>
                                    <?php elseif ((int)$cp['stock_quantity'] <= 0): ?>
                                        <span style="font-size:0.8rem;color:var(--cat-faint)">Unavailable</span>
                                    <?php else: ?>
                                        <a href="index.php?page=login" class="cat-btn-add login" style="padding:7px 14px">Sign in</a>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <p style="text-align:center;color:var(--cat-muted);padding:30px 0">No products currently selected for comparison.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ════════════ CLINICAL SPECS MODAL (Quick View) ════════════ -->
<div class="cat-modal-backdrop" id="pdOverlay" onclick="if(event.target===this)closeCatDetail()">
    <div class="cat-modal-sheet">
        <div class="cat-modal-head">
            <div style="display:flex;gap:14px;align-items:center">
                <div id="pdModalThumb" style="width:56px;height:56px;border-radius:12px;background:var(--cat-bg-subtle);display:flex;align-items:center;justify-content:center;font-size:1.6rem;color:var(--cat-blue-600);border:1px solid var(--cat-border);overflow:hidden;flex-shrink:0"></div>
                <div>
                    <h3 class="cat-modal-head-title" id="pdName"></h3>
                    <p class="cat-modal-head-sub" id="pdSub"></p>
                    <span id="pdSku" style="font-size:0.75rem;font-family:monospace;color:var(--cat-faint)"></span>
                </div>
            </div>
            <button type="button" class="cat-modal-close-btn" onclick="closeCatDetail()">&times;</button>
        </div>
        <div class="cat-modal-content">
            <div id="pdDesc" style="display:none;margin-bottom:18px">
                <div style="font-size:0.75rem;font-weight:700;color:var(--cat-muted);text-transform:uppercase;margin-bottom:4px"><i class="fas fa-file-medical"></i> Product Overview</div>
                <p style="font-size:0.88rem;color:var(--cat-body);line-height:1.6;margin:0" id="pdDescText"></p>
            </div>
            
            <div style="font-size:0.75rem;font-weight:700;color:var(--cat-muted);text-transform:uppercase;margin-bottom:8px"><i class="fas fa-clipboard-list"></i> Clinical Specifications</div>
            <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px" id="pdGrid"></div>

            <div id="pdStorage" style="display:none;margin-top:16px;padding-top:16px;border-top:1px dashed var(--cat-border)">
                <div style="font-size:0.75rem;font-weight:700;color:var(--cat-muted);text-transform:uppercase;margin-bottom:4px"><i class="fas fa-thermometer-half"></i> Storage &amp; Handling</div>
                <p style="font-size:0.85rem;color:var(--cat-body);margin:0" id="pdStorageText"></p>
            </div>

            <?php if ($catShowInternal): ?>
            <div id="pdBatches" style="display:none;margin-top:16px;padding-top:16px;border-top:1px dashed var(--cat-border)">
                <div style="font-size:0.75rem;font-weight:700;color:var(--cat-muted);text-transform:uppercase;margin-bottom:6px"><i class="fas fa-boxes-stacked"></i> Active Batches (FEFO Tracked)</div>
                <div id="pdBatchList" style="display:flex;flex-direction:column;gap:6px"></div>
            </div>
            <?php endif; ?>

            <div style="margin-top:16px;padding-top:16px;border-top:1px dashed var(--cat-border)">
                <div style="font-size:0.75rem;font-weight:700;color:var(--cat-muted);text-transform:uppercase;margin-bottom:6px"><i class="fas fa-warehouse"></i> Warehouse Availability</div>
                <div id="pdStockBar"></div>
            </div>
        </div>
        <div class="cat-modal-foot">
            <div>
                <span id="pdPrice" style="font-size:1.35rem;font-weight:800;color:var(--cat-title)"></span>
                <span id="pdWp" style="display:none;font-size:0.8rem;color:var(--cat-emerald);font-weight:700;margin-left:8px;background:var(--cat-emerald-bg);padding:2px 8px;border-radius:6px"></span>
            </div>
            <div style="display:flex;align-items:center;gap:10px">
                <div class="cat-stepper" id="pdModalStepper">
                    <button type="button" class="cat-step-btn minus" onclick="stepQty(this, -1)">−</button>
                    <input type="number" class="cat-step-input" id="pdModalQty" value="1" min="1" max="9999" onchange="validateStep(this)">
                    <button type="button" class="cat-step-btn plus" onclick="stepQty(this, 1)">+</button>
                </div>
                <a id="pdOrderBtn" href="#" class="cat-btn-add" style="padding:10px 24px;font-size:0.92rem"><i class="fas fa-cart-plus"></i> Add to Order</a>
            </div>
        </div>
    </div>
</div>

</div><!-- /.cat-wrap -->

<script>
var CAT_INTERNAL = <?= $catShowInternal ? 'true' : 'false' ?>;

/* Stepper logic */
function stepQty(btn, delta) {
    var wrap = btn.closest('.cat-stepper');
    var input = wrap.querySelector('.cat-step-input');
    var val = parseInt(input.value) || 1;
    var max = parseInt(input.getAttribute('max')) || 99999;
    var min = parseInt(input.getAttribute('min')) || 1;
    val = Math.max(min, Math.min(max, val + delta));
    input.value = val;

    var container = wrap.closest('.cat-order-bar') || wrap.closest('.cat-modal-foot');
    if (container) {
        var orderBtn = container.querySelector('.cat-btn-add, #pdOrderBtn');
        if (orderBtn && orderBtn.href && orderBtn.href.indexOf('place_order') !== -1) {
            var url = new URL(orderBtn.href, window.location.origin);
            url.searchParams.set('qty', val);
            orderBtn.href = url.pathname + url.search;
        }
    }
}

function validateStep(input) {
    var val = parseInt(input.value) || 1;
    var max = parseInt(input.getAttribute('max')) || 99999;
    var min = parseInt(input.getAttribute('min')) || 1;
    val = Math.max(min, Math.min(max, val));
    input.value = val;

    var container = input.closest('.cat-order-bar') || input.closest('.cat-modal-foot');
    if (container) {
        var orderBtn = container.querySelector('.cat-btn-add, #pdOrderBtn');
        if (orderBtn && orderBtn.href && orderBtn.href.indexOf('place_order') !== -1) {
            var url = new URL(orderBtn.href, window.location.origin);
            url.searchParams.set('qty', val);
            orderBtn.href = url.pathname + url.search;
        }
    }
}

function submitToOrder(e, link, pid) {
    e.stopPropagation();
    var wrap = link.closest('.cat-order-bar');
    var qty = 1;
    if (wrap) {
        var input = wrap.querySelector('.cat-step-input');
        if (input) qty = parseInt(input.value) || 1;
    }
    link.href = 'index.php?page=place_order&product_id=' + pid + '&qty=' + qty;
}

/* Instant live search on keystroke */
var searchTimer = null;
var searchInput = document.getElementById('catSearchInput');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        var query = this.value.trim().toLowerCase();
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function() {
            var items = document.querySelectorAll('.cat-card, .cat-list-item');
            var matched = 0;
            items.forEach(function(item) {
                if (!query) {
                    item.style.display = '';
                    matched++;
                    return;
                }
                var hay = (item.dataset.name + ' ' + (item.dataset.generic||'') + ' ' + (item.dataset.brand||'') + ' ' + (item.dataset.dosage||'') + ' ' + (item.dataset.strength||'') + ' ' + (item.dataset.mfr||'') + ' ' + (item.dataset.category||'') + ' ' + (item.dataset.barcode||'')).toLowerCase();
                if (hay.indexOf(query) !== -1) {
                    item.style.display = '';
                    matched++;
                } else {
                    item.style.display = 'none';
                }
            });
            var emptyNotice = document.getElementById('catLiveEmptyNotice');
            if (emptyNotice) {
                emptyNotice.style.display = (matched === 0 && query !== '') ? 'block' : 'none';
            }
            var countEl = document.getElementById('catLiveCount');
            if (countEl) {
                countEl.textContent = matched;
            }
        }, 120);
    });
}

/* Favorites toggle with AJAX and toast */
function catToggleFav(e, pid) {
    e.stopPropagation();
    var btn = e.currentTarget;
    var icon = btn.querySelector('i');
    var isFav = btn.classList.contains('active');

    btn.classList.toggle('active');
    if (icon) {
        icon.className = isFav ? 'fa-regular fa-heart' : 'fa-solid fa-heart';
    }

    var formData = new FormData();
    formData.append('_fav', '1');
    formData.append('product_id', pid);
    formData.append('csrf_token', typeof csrfToken !== 'undefined' ? csrfToken : '');

    fetch(window.location.href, {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(res) {
        if (typeof showToast === 'function') {
            showToast(isFav ? 'Removed from favorites' : 'Added to favorites', 'success');
        }
    }).catch(function() {});
}

/* Compare toggle */
function catToggleCmp(e, pid) {
    e.stopPropagation();
    var formData = new FormData();
    formData.append('_compare', '1');
    formData.append('product_id', pid);
    formData.append('csrf_token', typeof csrfToken !== 'undefined' ? csrfToken : '');

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    }).then(function() {
        window.location.reload();
    });
}

function openCompareModal() {
    var modal = document.getElementById('catCompareModal');
    if (modal) modal.style.display = 'flex';
}
function closeCompareModal() {
    var modal = document.getElementById('catCompareModal');
    if (modal) modal.style.display = 'none';
}

/* Quick View details sheet */
function catQuickView(e) {
    e.stopPropagation();
    var card = e.currentTarget.closest('.cat-card, .cat-list-item');
    if (card) showCatDetail(card);
}

function showCatDetail(el) {
    if (!el || !el.dataset) return;
    var modal = document.getElementById('pdOverlay');

    document.getElementById('pdName').textContent = el.dataset.name || '';
    document.getElementById('pdSub').textContent = [el.dataset.generic, el.dataset.brand].filter(Boolean).join(' — ');
    document.getElementById('pdSku').textContent = el.dataset.barcode ? 'Barcode: ' + el.dataset.barcode : '';
    document.getElementById('pdPrice').textContent = el.dataset.price || '';

    var wp = document.getElementById('pdWp');
    if (el.dataset.wp) {
        wp.textContent = 'Wholesale: ' + el.dataset.wp;
        wp.style.display = 'inline-block';
    } else {
        wp.style.display = 'none';
    }

    var thumb = document.getElementById('pdModalThumb');
    if (el.dataset.image) {
        thumb.innerHTML = '<img src="' + el.dataset.image + '" alt="" style="width:100%;height:100%;object-fit:contain">';
    } else {
        thumb.innerHTML = '<i class="fa-solid fa-pills"></i>';
    }

    var dd = document.getElementById('pdDesc');
    if (el.dataset.desc) {
        document.getElementById('pdDescText').textContent = el.dataset.desc;
        dd.style.display = 'block';
    } else {
        dd.style.display = 'none';
    }

    var st = document.getElementById('pdStorage');
    if (el.dataset.storage) {
        document.getElementById('pdStorageText').textContent = el.dataset.storage;
        st.style.display = 'block';
    } else {
        st.style.display = 'none';
    }

    var fields = [];
    if (el.dataset.dosage) fields.push(['Dosage Form', el.dataset.dosage]);
    if (el.dataset.strength) fields.push(['Strength', el.dataset.strength]);
    if (el.dataset.pack) fields.push(['Pack Size', el.dataset.pack]);
    if (el.dataset.unit) fields.push(['Unit', el.dataset.unit]);
    if (el.dataset.mfr) fields.push(['Manufacturer', el.dataset.mfr]);
    if (el.dataset.country) fields.push(['Country of Origin', el.dataset.country]);
    if (el.dataset.category) fields.push(['Category', el.dataset.category]);
    if (el.dataset.mfg) fields.push(['Mfg Date', el.dataset.mfg]);
    if (el.dataset.rx === '1') fields.push(['Prescription Status', 'Rx Required by Law']);

    document.getElementById('pdGrid').innerHTML = fields.map(function(f) {
        return '<div style="background:var(--cat-bg-alt);border:1px solid var(--cat-border);border-radius:8px;padding:8px 12px"><div style="font-size:0.7rem;font-weight:700;color:var(--cat-muted);text-transform:uppercase">' + f[0] + '</div><div style="font-size:0.86rem;font-weight:700;color:var(--cat-title);margin-top:2px">' + f[1] + '</div></div>';
    }).join('');

    var avail = String(el.dataset.avail) === '1';
    var bd = document.getElementById('pdBatches');
    if (CAT_INTERNAL && bd) {
        try {
            var batches = JSON.parse(el.dataset.batches || '[]');
            var bl = document.getElementById('pdBatchList');
            if (batches.length > 0) {
                bl.innerHTML = batches.map(function(b) {
                    return '<div style="display:flex;justify-content:space-between;align-items:center;background:var(--cat-bg-alt);border:1px solid var(--cat-border);border-radius:8px;padding:8px 12px;font-size:0.82rem"><span><strong>' + b.batch + '</strong> (' + b.qty + ' units)</span><span style="color:var(--cat-emerald);font-weight:700">Exp: ' + b.expiry + '</span></div>';
                }).join('');
                bd.style.display = 'block';
            } else {
                bd.style.display = 'none';
            }
        } catch (e) {
            bd.style.display = 'none';
        }
    }

    var sbEl = document.getElementById('pdStockBar');
    if (!avail) {
        sbEl.innerHTML = '<span class="cat-pill-badge out" style="padding:6px 14px;font-size:0.8rem"><i class="fas fa-times-circle"></i> Out of Stock</span>';
    } else if (CAT_INTERNAL && String(el.dataset.lowstock) === '1') {
        sbEl.innerHTML = '<span class="cat-pill-badge low" style="padding:6px 14px;font-size:0.8rem"><i class="fas fa-exclamation-triangle"></i> Low Stock</span>';
    } else {
        sbEl.innerHTML = '<span class="cat-pill-badge in" style="padding:6px 14px;font-size:0.8rem"><i class="fas fa-check-circle"></i> In Stock &bull; Ready for Dispatch</span>';
    }

    var btn = document.getElementById('pdOrderBtn');
    var qtyInput = document.getElementById('pdModalQty');
    if (qtyInput) qtyInput.value = 1;

    <?php if ($isCustomerUser): ?>
    if (!avail) {
        btn.href = '#';
        btn.classList.add('out');
        btn.innerHTML = '<i class="fas fa-ban"></i> Out of Stock';
    } else {
        btn.classList.remove('out');
        btn.href = 'index.php?page=place_order&product_id=' + el.dataset.pid + '&qty=1';
        btn.innerHTML = '<i class="fas fa-cart-plus"></i> Add to Order';
    }
    <?php else: ?>
    btn.href = 'index.php?page=login';
    btn.classList.add('login');
    btn.innerHTML = '<i class="fas fa-sign-in-alt"></i> Sign in to Order';
    <?php endif; ?>

    modal.style.display = 'flex';
}

function closeCatDetail() {
    var modal = document.getElementById('pdOverlay');
    if (modal) modal.style.display = 'none';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeCatDetail();
        closeCompareModal();
    }
});

/* Barcode handling */
var scanBtn = document.getElementById('catScanBtn');
if (scanBtn) {
    scanBtn.addEventListener('click', function() {
        var i = document.getElementById('catBarcodeInput');
        if (i) {
            i.style.position = 'relative';
            i.style.left = '0';
            i.style.opacity = '1';
            i.focus();
        }
    });
}
var barcodeInput = document.getElementById('catBarcodeInput');
if (barcodeInput) {
    barcodeInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            var code = this.value.trim();
            if (!code) return;
            var sInput = document.getElementById('catSearchInput');
            if (sInput) sInput.value = code;
            document.getElementById('catSearchForm').submit();
        }
    });
}
document.addEventListener('barcode-scanned', function(e) {
    var sInput = document.getElementById('catSearchInput');
    if (sInput && e.detail && e.detail.code) {
        sInput.value = e.detail.code;
        document.getElementById('catSearchForm').submit();
    }
});
</script>
