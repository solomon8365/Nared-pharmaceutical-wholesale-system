<?php
$pdo = getPDO();
$search = trim($_GET['search'] ?? '');
$catFilter = (int) ($_GET['category_id'] ?? 0);
$mfrFilter = trim($_GET['manufacturer'] ?? '');
$stockFilter = trim($_GET['stock_status'] ?? '');
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

$user = currentUser();
$isCustomerUser = $user && isCustomer();
$customerId = 0;
if ($isCustomerUser) {
    try { $cStmt = $pdo->prepare('SELECT id FROM customers WHERE id = (SELECT customer_id FROM users WHERE id = :uid)'); $cStmt->execute(['uid' => $user['id']]); $customerId = (int)$cStmt->fetchColumn(); } catch (Exception $e) {}
}

/* ── Favorites ── */
$favIds = [];
if ($customerId) {
    try { $favStmt = $pdo->prepare('SELECT product_id FROM customer_favorites WHERE customer_id = :cid'); $favStmt->execute(['cid' => $customerId]); $favIds = array_column($favStmt->fetchAll(), 'product_id'); } catch (Exception $e) {}
}
if (isset($_POST['_fav']) && $customerId) {
    require_csrf();
    $fid = (int)$_POST['product_id'];
    $exists = in_array($fid, $favIds);
    if ($exists) { $pdo->prepare('DELETE FROM customer_favorites WHERE customer_id = :cid AND product_id = :pid')->execute(['cid' => $customerId, 'pid' => $fid]); }
    else { $pdo->prepare('INSERT IGNORE INTO customer_favorites (customer_id, product_id) VALUES (:cid, :pid)')->execute(['cid' => $customerId, 'pid' => $fid]); }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

/* ── Comparison ── */
$compareIds = [];
if ($customerId) {
    try { $ciStmt = $pdo->prepare('SELECT product_id FROM product_comparisons WHERE customer_id = :cid'); $ciStmt->execute(['cid' => $customerId]); $compareIds = array_column($ciStmt->fetchAll(), 'product_id'); } catch (Exception $e) {}
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
elseif ($stockFilter === 'low_stock') $sql .= ' AND p.stock_quantity > 0 AND p.stock_quantity <= 10';
elseif ($stockFilter === 'out_of_stock') $sql .= ' AND p.stock_quantity <= 0';
elseif ($stockFilter === 'expiring') $sql .= ' AND p.id IN (SELECT product_id FROM product_batches WHERE quantity > 0 AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY))';

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
function stockBadge(int $s): array {
    if ($s <= 0) return ['Out of Stock','out','fa-times-circle'];
    if ($s <= 10) return ['Low Stock','low','fa-exclamation-triangle'];
    return ['In Stock','in','fa-check-circle'];
}
function stockPct(int $s): int {
    if ($s <= 0) return 0;
    return max(0, min(100, (int)round($s / 200 * 100)));
}
function catIcon(string $name): string {
    $n = strtolower($name);
    $m = ['pain'=>'fa-tablets','analges'=>'fa-tablets','anti-inflamm'=>'fa-shield-virus','inflamm'=>'fa-shield-virus','antibiotic'=>'fa-capsules','antimal'=>'fa-bug','malaria'=>'fa-bug','vitamin'=>'fa-bolt','supplement'=>'fa-bolt','mineral'=>'fa-bolt','omega'=>'fa-bolt','cardio'=>'fa-heartbeat','heart'=>'fa-heartbeat','blood'=>'fa-heartbeat','gastro'=>'fa-disease','digest'=>'fa-disease','stomach'=>'fa-disease','respir'=>'fa-lungs','cough'=>'fa-lungs','cold'=>'fa-lungs','asthma'=>'fa-lungs','allerg'=>'fa-allergies','diabet'=>'fa-syringe','insulin'=>'fa-syringe','inject'=>'fa-syringe','derm'=>'fa-hand-holding-medical','skin'=>'fa-hand-holding-medical','hair'=>'fa-hand-holding-medical','eye'=>'fa-eye','bone'=>'fa-bone','ortho'=>'fa-bone','neuro'=>'fa-brain','immune'=>'fa-shield-virus','fever'=>'fa-thermometer','flu'=>'fa-thermometer','cough'=>'fa-lungs','general'=>'fa-prescription-bottle','other'=>'fa-prescription-bottle'];
    foreach ($m as $k => $icon) if (str_contains($n, $k)) return $icon;
    return 'fa-prescription-bottle';
}

/* ── Country flags ── */
$flags = ['Ethiopia'=>'🇪🇹','India'=>'🇮🇳','China'=>'🇨🇳','USA'=>'🇺🇸','Germany'=>'🇩🇪','UK'=>'🇬🇧','France'=>'🇫🇷','Japan'=>'🇯🇵','Switzerland'=>'🇨🇭','Italy'=>'🇮🇹','Turkey'=>'🇹🇷','Nigeria'=>'🇳🇬','Kenya'=>'🇰🇪','Egypt'=>'🇪🇬','South Korea'=>'🇰🇷','Brazil'=>'🇧🇷'];
function countryFlag(string $country): string { global $flags; return $flags[$country] ?? '🌍'; }
?>
<style>
.cat{--cat-brand:#2563eb;--cat-brand-d:#1d4ed8;--cat-brand-l:#eff6ff;--cat-bg:#ffffff;--cat-bg2:#f8fafc;--cat-bg3:#f1f5f9;--cat-title:#0f172a;--cat-title2:#334155;--cat-mut:#64748b;--cat-faint:#94a3b8;--cat-line:#e2e8f0;--cat-line2:#f1f5f9;--cat-ok:#16a34a;--cat-warn:#d97706;--cat-bad:#dc2626;--cat-ring:rgba(37,99,235,0.12);max-width:1400px;margin:0 auto}
body.dark-mode .cat{--cat-brand:#3b82f6;--cat-brand-d:#60a5fa;--cat-brand-l:#172554;--cat-bg:#1f2937;--cat-bg2:#111827;--cat-bg3:#374151;--cat-title:#f1f5f9;--cat-title2:#e5e7eb;--cat-mut:#9ca3af;--cat-faint:#6b7280;--cat-line:#374151;--cat-line2:#273244;--cat-ring:rgba(96,165,250,0.18)}

/* ── Hero ── */
.cat-hero{position:relative;overflow:hidden;border-radius:18px;background:linear-gradient(130deg,#1e3a8a 0%,#1d4ed8 48%,#0ea5e9 100%);color:#fff;padding:26px 30px;margin-bottom:16px;box-shadow:0 12px 32px rgba(29,78,216,0.28)}
.cat-hero::before{content:"";position:absolute;inset:0;background:radial-gradient(circle at 88% 12%,rgba(255,255,255,0.2),transparent 44%),radial-gradient(circle at 6% 115%,rgba(255,255,255,0.12),transparent 42%)}
.cat-hero-deco{position:absolute;border-radius:24px;background:rgba(255,255,255,0.09);border:1px solid rgba(255,255,255,0.14);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,0.4);transform:rotate(-8deg);pointer-events:none}
.cat-hero-deco.d1{width:120px;height:120px;right:10px;top:-36px;font-size:2.6rem}
.cat-hero-deco.d2{width:72px;height:72px;right:152px;bottom:-22px;font-size:1.25rem;transform:rotate(14deg)}
.cat-hero-deco.d3{width:46px;height:46px;right:46px;bottom:28px;font-size:0.9rem;transform:rotate(-20deg)}
.cat-hero-inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:22px;flex-wrap:wrap}
.cat-hero-eyebrow{display:inline-flex;align-items:center;gap:7px;background:rgba(255,255,255,0.16);border:1px solid rgba(255,255,255,0.28);padding:5px 13px;border-radius:99px;font-size:0.7rem;font-weight:700;letter-spacing:0.07em;text-transform:uppercase}
.cat-hero-title{font-size:1.65rem;font-weight:800;margin:12px 0 6px;line-height:1.2;letter-spacing:-0.01em}
.cat-hero-title .hl{color:#bae6fd}
.cat-hero-sub{font-size:0.9rem;color:rgba(255,255,255,0.88);margin:0;max-width:620px;line-height:1.55}
.cat-hero-stats{display:flex;gap:10px;flex-shrink:0}
.cat-stat{background:rgba(255,255,255,0.14);border:1px solid rgba(255,255,255,0.22);border-radius:14px;padding:10px 16px;min-width:100px;text-align:center;backdrop-filter:blur(6px)}
.cat-stat i{font-size:1rem;display:block;margin-bottom:4px;opacity:0.85}
.cat-stat b{display:block;font-size:1.25rem;line-height:1.1}
.cat-stat span{font-size:0.7rem;font-weight:600;opacity:0.88}

/* ── Category chips ── */
.cat-chips{display:flex;gap:8px;overflow-x:auto;padding-bottom:6px;margin-bottom:14px;-webkit-overflow-scrolling:touch;scrollbar-width:none}
.cat-chips::-webkit-scrollbar{display:none}
.cat-chip{display:inline-flex;align-items:center;gap:7px;padding:7px 14px;border-radius:99px;border:1.5px solid var(--cat-line);background:var(--cat-bg);color:var(--cat-mut);font-size:0.8rem;font-weight:600;text-decoration:none;white-space:nowrap;transition:all 0.15s}
.cat-chip i{color:var(--cat-faint)}
.cat-chip span{background:var(--cat-bg3);color:var(--cat-mut);padding:1px 8px;border-radius:99px;font-size:0.68rem;font-weight:700}
.cat-chip:hover{border-color:var(--cat-brand);color:var(--cat-brand)}
.cat-chip:hover i{color:var(--cat-brand)}
.cat-chip.active{background:var(--cat-brand);border-color:var(--cat-brand);color:#fff}
.cat-chip.active i{color:#fff}
.cat-chip.active span{background:rgba(255,255,255,0.24);color:#fff}

.cat-search{display:flex;gap:0;background:var(--cat-bg);border:2px solid var(--cat-line);border-radius:12px;overflow:hidden;margin-bottom:14px;transition:border-color 0.2s,box-shadow 0.2s;box-shadow:0 1px 2px rgba(15,23,42,0.04)}
.cat-search:focus-within{border-color:var(--cat-brand);box-shadow:0 0 0 3px var(--cat-ring)}
.cat-search-icon{padding:0 14px;display:flex;align-items:center;color:var(--cat-faint);font-size:1rem}
.cat-search input{flex:1;border:none;outline:none;padding:12px 0;font-size:0.92rem;color:var(--cat-title);background:transparent;font-family:inherit}
.cat-search input::placeholder{color:var(--cat-faint)}
.cat-search-btn{padding:10px 20px;background:var(--cat-brand);color:#fff;border:none;font-weight:600;font-size:0.88rem;cursor:pointer;font-family:inherit;transition:background 0.15s}
.cat-search-btn:hover{background:var(--cat-brand-d)}
.cat-scan{padding:0 12px;border:none;background:none;color:var(--cat-mut);cursor:pointer;font-size:1rem;display:flex;align-items:center;transition:color 0.15s}
.cat-scan:hover{color:var(--cat-brand)}

.cat-toolbar{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.cat-filters{display:flex;gap:8px;flex-wrap:wrap;flex:1}
.cat-filter{padding:7px 14px;border:1.5px solid var(--cat-line);border-radius:8px;font-size:0.82rem;background:var(--cat-bg);color:var(--cat-title2);cursor:pointer;font-family:inherit;transition:all 0.15s;appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%2394a3b8'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:28px}
.cat-filter:focus{outline:none;border-color:var(--cat-brand)}
.cat-filter.active{border-color:var(--cat-brand);background-color:var(--cat-brand-l);color:var(--cat-brand)}
.cat-clear{padding:7px 14px;border:1.5px solid var(--cat-line);border-radius:8px;background:var(--cat-bg);color:var(--cat-mut);font-size:0.82rem;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:4px;transition:all 0.15s}
.cat-clear:hover{border-color:var(--cat-bad);color:var(--cat-bad)}
.cat-views{display:flex;border:1.5px solid var(--cat-line);border-radius:8px;overflow:hidden}
.cat-view{padding:7px 12px;border:none;background:var(--cat-bg);color:var(--cat-mut);cursor:pointer;font-size:0.85rem;transition:all 0.15s}
.cat-view.active{background:var(--cat-brand);color:#fff}
.cat-view:hover:not(.active){background:var(--cat-bg2)}

.cat-tabs{display:flex;gap:0;border-bottom:2px solid var(--cat-line);margin-bottom:20px}
.cat-tab{padding:10px 20px;font-size:0.88rem;font-weight:600;color:var(--cat-mut);cursor:pointer;border:none;background:none;border-bottom:2px solid transparent;margin-bottom:-2px;display:flex;align-items:center;gap:6px;transition:all 0.15s;text-decoration:none}
.cat-tab:hover{color:var(--cat-title)}
.cat-tab.active{color:var(--cat-brand);border-bottom-color:var(--cat-brand)}
.cat-tab-count{background:var(--cat-bg3);padding:2px 8px;border-radius:12px;font-size:0.72rem;font-weight:700;color:var(--cat-mut)}
.cat-tab.active .cat-tab-count{background:var(--cat-brand-l);color:var(--cat-brand)}

.cat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin-bottom:24px}
.cat-list{display:flex;flex-direction:column;gap:10px;margin-bottom:24px}

/* ── Product cards ── */
.cat-card{background:var(--cat-bg);border:1px solid var(--cat-line);border-radius:16px;overflow:hidden;transition:transform 0.22s cubic-bezier(0.4,0,0.2,1),box-shadow 0.22s,border-color 0.22s;display:flex;flex-direction:column;position:relative;box-shadow:0 1px 2px rgba(15,23,42,0.04)}
.cat-card:hover{transform:translateY(-5px);box-shadow:0 18px 40px rgba(15,23,42,0.1);border-color:#bfdbfe}
body.dark-mode .cat-card:hover{border-color:var(--cat-brand)}
.cat-card::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--cat-brand),#0ea5e9);opacity:0;transition:opacity 0.22s;z-index:3}
.cat-card:hover::before{opacity:1}
.cat-card-top{position:absolute;top:12px;right:12px;display:flex;gap:6px;z-index:2}
.cat-card-fav,.cat-card-cmp{width:32px;height:32px;border-radius:50%;border:1.5px solid var(--cat-line);background:var(--cat-bg);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all 0.15s;font-size:0.8rem;color:var(--cat-faint);box-shadow:0 1px 3px rgba(15,23,42,0.08)}
.cat-card-fav:hover,.cat-card-fav.active{background:#fef2f2;border-color:#fca5a5;color:#dc2626}
.cat-card-cmp:hover,.cat-card-cmp.active{background:var(--cat-brand-l);border-color:#93c5fd;color:var(--cat-brand)}
.cat-card-img{height:170px;background:linear-gradient(135deg,var(--cat-bg2),var(--cat-bg3));display:flex;align-items:center;justify-content:center;overflow:hidden;position:relative}
.cat-card-img img{width:100%;height:100%;object-fit:cover}
.cat-card-img .no-img{color:var(--cat-line);font-size:2.6rem}
.cat-card-badge{position:absolute;top:12px;left:12px;display:flex;gap:6px;flex-wrap:wrap}
.cat-qv{position:absolute;left:50%;bottom:10px;transform:translate(-50%,10px);background:rgba(15,23,42,0.85);color:#fff;border:none;border-radius:99px;padding:7px 16px;font-size:0.76rem;font-weight:600;display:flex;align-items:center;gap:6px;cursor:pointer;opacity:0;transition:all 0.2s;backdrop-filter:blur(4px);font-family:inherit;z-index:2}
.cat-card-img:hover .cat-qv,.cat-card-img:focus-within .cat-qv{opacity:1;transform:translate(-50%,0)}
.cat-qv:hover{background:#0f172a}
@media(hover:none){.cat-qv{display:none}}
.cat-badge{padding:3px 10px;border-radius:20px;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.03em}
.cat-badge.in{background:rgba(22,163,74,0.12);color:var(--cat-ok);backdrop-filter:blur(4px)}
.cat-badge.low{background:rgba(245,158,11,0.14);color:var(--cat-warn);backdrop-filter:blur(4px)}
.cat-badge.out{background:rgba(220,38,38,0.12);color:var(--cat-bad);backdrop-filter:blur(4px)}
.cat-badge.rx{background:rgba(124,58,237,0.12);color:#7c3aed;backdrop-filter:blur(4px)}
.cat-badge.freq{background:rgba(220,38,38,0.9);color:#fff}
.cat-badge.exp{background:rgba(190,18,60,0.12);color:#be123c;backdrop-filter:blur(4px)}
body.dark-mode .cat-badge.exp{background:rgba(244,63,94,0.14);color:#fb7185}
.cat-card-body{padding:15px 16px 4px;flex:1;display:flex;flex-direction:column}
.cat-card-name{font-size:0.95rem;font-weight:700;color:var(--cat-title);margin:0 0 4px;line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.cat-card-sub{font-size:0.78rem;color:var(--cat-mut);margin:0 0 10px}
.cat-card-details{display:grid;grid-template-columns:1fr 1fr;gap:4px 10px;font-size:0.78rem;margin-bottom:auto}
.cat-card-details dt{color:var(--cat-faint);font-weight:500}
.cat-card-details dd{color:var(--cat-title2);font-weight:600;margin:0}
.cat-card-origin{display:inline-flex;align-items:center;gap:4px;font-size:0.75rem;color:var(--cat-mut);margin-top:6px}
.cat-stock{margin:10px 0 0;padding-top:10px;border-top:1px dashed var(--cat-line)}
.cat-stock-top{display:flex;justify-content:space-between;align-items:center;font-size:0.72rem;margin-bottom:5px}
.cat-stock-top b{font-weight:700;color:var(--cat-title2)}
.cat-stock-top span{display:inline-flex;align-items:center;gap:5px;font-weight:700}
.cat-in{color:var(--cat-ok)} .cat-low{color:var(--cat-warn)} .cat-out{color:var(--cat-bad)}
.cat-stockbar{height:5px;background:var(--cat-bg3);border-radius:99px;overflow:hidden}
.cat-stockfill{height:100%;border-radius:99px;transition:width 0.3s}
.cat-card-footer{padding:10px 16px 14px;margin-top:auto;border-top:1px solid var(--cat-line2);display:flex;align-items:center;justify-content:space-between;gap:10px}
.cat-card-price{font-size:1.15rem;font-weight:800;color:var(--cat-title)}
.cat-card-order{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:8px;background:var(--cat-brand);color:#fff;font-weight:700;font-size:0.82rem;text-decoration:none;border:none;cursor:pointer;transition:all 0.15s;font-family:inherit}
.cat-card-order:hover{background:var(--cat-brand-d);transform:translateY(-1px)}
.cat-card-order:disabled{background:#cbd5e1;cursor:not-allowed;transform:none}
.cat-card-order.login{background:#64748b}
.cat-card-order.login:hover{background:#475569}

/* ── List view ── */
.cat-list-card{background:var(--cat-bg);border:1px solid var(--cat-line);border-radius:12px;padding:16px 20px;display:flex;align-items:center;gap:16px;transition:all 0.2s}
.cat-list-card:hover{border-color:#bfdbfe;box-shadow:0 4px 15px rgba(15,23,42,0.06)}
body.dark-mode .cat-list-card:hover{border-color:var(--cat-brand)}
.cat-list-img{width:60px;height:60px;border-radius:10px;background:var(--cat-bg3);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0}
.cat-list-img img{width:100%;height:100%;object-fit:cover}
.cat-list-img .no-img{color:var(--cat-line);font-size:1.5rem}
.cat-list-info{flex:1;min-width:0}
.cat-list-name{font-weight:700;font-size:0.92rem;color:var(--cat-title);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cat-list-sub{font-size:0.78rem;color:var(--cat-mut);margin-top:2px}
.cat-list-meta{display:flex;gap:16px;margin-top:4px;font-size:0.78rem;color:var(--cat-mut)}
.cat-list-meta span{display:flex;align-items:center;gap:4px}
.cat-list-right{display:flex;align-items:center;gap:16px;flex-shrink:0}
.cat-list-price{font-size:1rem;font-weight:800;color:var(--cat-title);text-align:right;white-space:nowrap}

.cat-empty{text-align:center;padding:50px 20px;color:var(--cat-faint)}
.cat-empty-ico{width:92px;height:92px;margin:0 auto 18px;border-radius:50%;background:linear-gradient(135deg,var(--cat-brand-l),var(--cat-bg2));display:flex;align-items:center;justify-content:center;font-size:2.1rem;color:var(--cat-brand);box-shadow:0 10px 24px rgba(15,23,42,0.05)}
.cat-empty h3{font-size:1.1rem;color:var(--cat-title2);margin:0 0 6px}
.cat-empty p{font-size:0.88rem}
.cat-empty-reset{display:inline-flex;align-items:center;gap:6px;margin-top:14px;padding:10px 20px;background:var(--cat-brand);color:#fff;border-radius:8px;text-decoration:none;font-weight:600;font-size:0.88rem;transition:background 0.15s}
.cat-empty-reset:hover{background:var(--cat-brand-d)}

/* ── Product detail modal ── */
.pd-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;padding:20px}
.pd-modal{background:var(--cat-bg);border-radius:16px;max-width:600px;width:100%;max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3)}
.pd-header{padding:20px 24px;border-bottom:1px solid var(--cat-line2);display:flex;gap:16px;align-items:flex-start}
.pd-img{width:80px;height:80px;border-radius:12px;background:var(--cat-bg3);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0}
.pd-img img{width:100%;height:100%;object-fit:cover}
.pd-img .no-img{color:var(--cat-line);font-size:2rem}
.pd-title{font-size:1.1rem;font-weight:700;color:var(--cat-title);margin:0 0 2px}
.pd-sub{font-size:0.85rem;color:var(--cat-mut);margin:0}
.pd-close{background:none;border:none;font-size:1.5rem;cursor:pointer;color:var(--cat-faint);padding:4px;line-height:1}
.pd-close:hover{color:var(--cat-title)}
.pd-body{padding:20px 24px}
.pd-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.pd-field dt{font-size:0.72rem;color:var(--cat-faint);font-weight:600;text-transform:uppercase;letter-spacing:0.04em}
.pd-field dd{font-size:0.88rem;color:var(--cat-title2);font-weight:600;margin:2px 0 0}
.pd-section{margin-top:16px;padding-top:16px;border-top:1px solid var(--cat-line2)}
.pd-section-title{font-size:0.78rem;font-weight:700;color:var(--cat-mut);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:8px}
.pd-batch{display:flex;align-items:center;justify-content:space-between;padding:8px 12px;border:1px solid var(--cat-line);border-radius:8px;margin-bottom:6px;font-size:0.82rem}
.pd-batch span{color:var(--cat-title2)}
.pd-stock-bar{height:6px;background:var(--cat-bg3);border-radius:99px;overflow:hidden;margin-top:8px}
.pd-stock-fill{height:100%;border-radius:99px;transition:width 0.3s}
.pd-footer{padding:16px 24px;border-top:1px solid var(--cat-line2);display:flex;align-items:center;justify-content:space-between}

@media(max-width:900px){.cat-hero-stats{width:100%;justify-content:flex-start;flex-wrap:wrap}}
@media(max-width:768px){.cat-grid{grid-template-columns:1fr}.cat-toolbar{flex-direction:column;align-items:stretch}.cat-filters{flex-direction:column}.cat-filter{width:100%}.cat-list-card{flex-direction:column;align-items:stretch}.cat-list-right{justify-content:space-between}.cat-list-meta{flex-wrap:wrap}.pd-grid{grid-template-columns:1fr}.cat-hero{padding:22px 18px}.cat-hero-title{font-size:1.35rem}.cat-hero-deco{opacity:0.5}}
@media(max-width:520px){.cat-hero-stats{display:grid;grid-template-columns:repeat(3,1fr);width:100%}.cat-stat{min-width:0;padding:8px 6px}.cat-stat b{font-size:1.05rem}}
</style>

<section class="cat">
<div class="cat-hero">
    <div class="cat-hero-deco d1"><i class="fas fa-capsules"></i></div>
    <div class="cat-hero-deco d2"><i class="fas fa-prescription-bottle"></i></div>
    <div class="cat-hero-deco d3"><i class="fas fa-syringe"></i></div>
    <div class="cat-hero-inner">
        <div class="cat-hero-copy">
            <span class="cat-hero-eyebrow"><i class="fas fa-capsules"></i> Pharmaceutical Catalog</span>
            <h1 class="cat-hero-title">Everything your <span class="hl">pharmacy</span> needs</h1>
            <p class="cat-hero-sub">Browse <?= number_format($totalActive) ?> active products across <?= count($catCounts) ?> categories from <?= count($allManufacturers) ?> manufacturers &mdash; search, filter and order in a few clicks.</p>
        </div>
        <div class="cat-hero-stats">
            <div class="cat-stat"><i class="fas fa-box-open"></i><b><?= number_format($totalActive) ?></b><span>Products</span></div>
            <div class="cat-stat"><i class="fas fa-th-large"></i><b><?= count($catCounts) ?></b><span>Categories</span></div>
            <div class="cat-stat"><i class="fas fa-factory"></i><b><?= count($allManufacturers) ?></b><span>Manufacturers</span></div>
        </div>
    </div>
</div>

<!-- Category chips -->
<div class="cat-chips">
    <a class="cat-chip <?= $catFilter === 0 ? 'active' : '' ?>" href="?page=catalog&<?= http_build_query(array_merge($_GET, ['category_id' => '', 'tab' => 'all'])) ?>"><i class="fas fa-th-large"></i> All <span><?= $totalActive ?></span></a>
    <?php foreach ($catCounts as $cid => $cnt): ?>
    <a class="cat-chip <?= $catFilter === $cid ? 'active' : '' ?>" href="?page=catalog&<?= http_build_query(array_merge($_GET, ['category_id' => (string)$cid, 'tab' => 'all'])) ?>"><i class="fas <?= catIcon($catNames[$cid] ?? '') ?>"></i> <?= htmlspecialchars($catNames[$cid] ?? 'Category') ?> <span><?= $cnt ?></span></a>
    <?php endforeach; ?>
</div>

<!-- Search -->
<form class="cat-search" method="get" id="catSearchForm">
    <input type="hidden" name="page" value="catalog">
    <div class="cat-search-icon"><i class="fas fa-search"></i></div>
    <input type="text" name="search" id="catSearchInput" placeholder="Search by name, brand, generic, SKU, barcode, manufacturer..." value="<?= htmlspecialchars($search) ?>">
    <button type="button" class="cat-scan" onclick="openCameraScanner()" title="Scan barcode"><i class="fas fa-camera"></i></button>
    <button type="button" class="cat-scan" id="catScanBtn" title="Type barcode"><i class="fas fa-barcode"></i></button>
    <button type="submit" class="cat-search-btn"><i class="fas fa-search"></i> Search</button>
</form>
<input type="text" id="catBarcodeInput" style="position:absolute;left:-9999px;opacity:0" tabindex="-1">
<input type="hidden" name="page" value="catalog" form="catFilterForm">

<!-- Toolbar -->
<div class="cat-toolbar">
    <form class="cat-filters" method="get" id="catFilterForm">
        <input type="hidden" name="page" value="catalog">
        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
        <select name="category_id" class="cat-filter" onchange="this.form.submit()"><option value="">All Categories</option><?php foreach ($allCats as $c): ?><option value="<?= $c['id'] ?>"<?= $catFilter === (int)$c['id'] ? ' selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?></select>
        <select name="manufacturer" class="cat-filter" onchange="this.form.submit()"><option value="">All Manufacturers</option><?php foreach ($allManufacturers as $m): ?><option value="<?= htmlspecialchars($m) ?>"<?= $mfrFilter === $m ? ' selected' : '' ?>><?= htmlspecialchars($m) ?></option><?php endforeach; ?></select>
        <select name="stock_status" class="cat-filter" onchange="this.form.submit()"><option value="">All Stock</option><option value="in_stock"<?= $stockFilter==='in_stock'?' selected':'' ?>>In Stock</option><option value="low_stock"<?= $stockFilter==='low_stock'?' selected':'' ?>>Low Stock</option><option value="out_of_stock"<?= $stockFilter==='out_of_stock'?' selected':'' ?>>Out of Stock</option><option value="expiring"<?= $stockFilter==='expiring'?' selected':'' ?>>Expiring Soon</option></select>
        <select name="sort" class="cat-filter" onchange="this.form.submit()"><option value="name"<?= $sort==='name'?' selected':'' ?>>Name A-Z</option><option value="price_asc"<?= $sort==='price_asc'?' selected':'' ?>>Price Low-High</option><option value="price_desc"<?= $sort==='price_desc'?' selected':'' ?>>Price High-Low</option><option value="stock"<?= $sort==='stock'?' selected':'' ?>>Stock Low-High</option><option value="newest"<?= $sort==='newest'?' selected':'' ?>>Newest</option></select>
        <?php if ($search||$catFilter||$mfrFilter||$stockFilter): ?><a href="?page=catalog" class="cat-clear"><i class="fas fa-times"></i> Clear</a><?php endif; ?>
    </form>
    <div class="cat-views">
        <a href="?page=catalog&<?= http_build_query(array_merge($_GET,['view'=>'grid'])) ?>" class="cat-view <?= $viewMode==='grid'||$viewMode!=='list' ? 'active' : '' ?>"><i class="fas fa-th"></i></a>
        <a href="?page=catalog&<?= http_build_query(array_merge($_GET,['view'=>'list'])) ?>" class="cat-view <?= $viewMode==='list' ? 'active' : '' ?>"><i class="fas fa-list"></i></a>
    </div>
</div>

<!-- Tabs -->
<div class="cat-tabs">
    <a href="?page=catalog&tab=all&<?= http_build_query(array_diff_key($_GET,['tab'=>''])) ?>" class="cat-tab <?= $activeTab==='all'?'active':'' ?>"><i class="fas fa-th-large"></i> All Products <span class="cat-tab-count"><?= $totalProducts ?></span></a>
    <a href="?page=catalog&tab=frequent&<?= http_build_query(array_diff_key($_GET,['tab'=>''])) ?>" class="cat-tab <?= $activeTab==='frequent'?'active':'' ?>"><i class="fas fa-fire"></i> Frequently Ordered <span class="cat-tab-count"><?= count($frequentlyOrdered) ?></span></a>
    <a href="?page=catalog&tab=recent&<?= http_build_query(array_diff_key($_GET,['tab'=>''])) ?>" class="cat-tab <?= $activeTab==='recent'?'active':'' ?>"><i class="fas fa-clock"></i> Recently Purchased <span class="cat-tab-count"><?= count($recentlyPurchased) ?></span></a>
</div>

<?php
$displayProducts = $activeTab === 'frequent' ? $frequentlyOrdered : ($activeTab === 'recent' ? $recentlyPurchased : $products);
$tabFrequentIds = array_column($frequentlyOrdered, 'id');
?>

<?php if (empty($displayProducts)): ?>
<div class="cat-empty">
    <div class="cat-empty-ico"><i class="fas fa-search"></i></div>
    <h3>No products found</h3>
    <p>Try adjusting your search or filters, or browse the full catalog.</p>
    <a href="?page=catalog" class="cat-empty-reset"><i class="fas fa-undo-alt"></i> View All Products</a>
</div>
<?php else: ?>

<?php if ($viewMode === 'list'): ?>
<div class="cat-list">
    <?php foreach ($displayProducts as $p):
        $pid=(int)$p['id']; $stock=(int)$p['stock_quantity']; $sb=stockBadge($stock);
        $isFav=in_array($pid,$favIds); $isCmp=in_array($pid,$compareIds); $isFrq=in_array($pid,$tabFrequentIds);
    ?>
    <div class="cat-list-card">
        <div class="cat-list-img">
            <?php if (!empty($p['image_path']) && file_exists(__DIR__.'/../uploads/products/'.$p['image_path'])): ?><img src="<?= PRODUCT_UPLOAD_URL.'/'.htmlspecialchars($p['image_path']) ?>" alt=""><?php else: ?><span class="no-img"><i class="fas fa-pills"></i></span><?php endif; ?>
        </div>
        <div class="cat-list-info">
            <div class="cat-list-name"><?= htmlspecialchars($p['name']) ?></div>
            <div class="cat-list-sub"><?= htmlspecialchars(($p['brand']??'').($p['brand']&&$p['generic_name']?' — ':'').($p['generic_name']??'')) ?></div>
            <div class="cat-list-meta">
                <?php if ($p['dosage_form']): ?><span><i class="fas fa-pills"></i> <?= htmlspecialchars($p['dosage_form']) ?></span><?php endif; ?>
                <?php if ($p['strength']): ?><span><i class="fas fa-weight-hanging"></i> <?= htmlspecialchars($p['strength']) ?></span><?php endif; ?>
                <?php if ($p['manufacturer']): ?><span><i class="fas fa-factory"></i> <?= htmlspecialchars($p['manufacturer']) ?></span><?php endif; ?>
            </div>
        </div>
        <div class="cat-list-right">
            <div>
                <div class="cat-list-price"><?= fmt((float)$p['price']) ?></div>
                <span class="cat-badge <?= $sb[1] ?>" style="margin-top:4px"><i class="fas <?= $sb[2] ?>"></i> <?= $sb[0] ?></span>
            </div>
            <?php if ($isCustomerUser): ?>
            <?php if ($stock <= 0): ?>
            <button class="cat-card-order" disabled title="Out of stock"><i class="fas fa-times-circle"></i> Out of Stock</button>
            <?php else: ?>
            <a class="cat-card-order" href="index.php?page=place_order&product_id=<?= $pid ?>"><i class="fas fa-cart-plus"></i> Order</a>
            <?php endif; ?>
            <?php else: ?>
            <a class="cat-card-order login" href="index.php?page=login"><i class="fas fa-sign-in-alt"></i> Sign In</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php else: ?>
<div class="cat-grid">
    <?php foreach ($displayProducts as $p):
        $pid=(int)$p['id']; $stock=(int)$p['stock_quantity']; $sb=stockBadge($stock); $pct=stockPct($stock);
        $isFav=in_array($pid,$favIds); $isCmp=in_array($pid,$compareIds); $isFrq=in_array($pid,$tabFrequentIds);
        $earliest=$expiryMap[$pid]??null; $expSoon=($earliest && $stock>0 && strtotime($earliest) <= strtotime('+90 days'));
        $imgFull = (!empty($p['image_path']) && file_exists(__DIR__.'/../uploads/products/'.$p['image_path'])) ? PRODUCT_UPLOAD_URL.'/'.htmlspecialchars($p['image_path']) : '';
    ?>
    <div class="cat-card" onclick="showCatDetail(this)" data-pid="<?= $pid ?>" data-name="<?= htmlspecialchars($p['name']) ?>" data-brand="<?= htmlspecialchars($p['brand']??'') ?>" data-generic="<?= htmlspecialchars($p['generic_name']??'') ?>" data-barcode="<?= htmlspecialchars($p['barcode']??'') ?>" data-dosage="<?= htmlspecialchars($p['dosage_form']??'') ?>" data-strength="<?= htmlspecialchars($p['strength']??'') ?>" data-mfr="<?= htmlspecialchars($p['manufacturer']??'') ?>" data-category="<?= htmlspecialchars($p['category_name']??'') ?>" data-price="<?= fmt((float)$p['price']) ?>" data-stock="<?= $stock ?>" data-pack="<?= htmlspecialchars($p['pack_size']??'') ?>" data-unit="<?= htmlspecialchars($p['unit_of_measure']??'') ?>" data-country="<?= htmlspecialchars($p['country_of_origin']??'') ?>" data-storage="<?= htmlspecialchars($p['storage_condition']??'') ?>" data-desc="<?= htmlspecialchars($p['description']??'') ?>" data-image="<?= $imgFull ?>" data-mfg="<?= htmlspecialchars($p['manufacturing_date']??'') ?>" data-rx="<?= (int)($p['requires_prescription']??0) ?>" data-batches="<?= buildBatchJson($batchMap,$pid) ?>">
        <div class="cat-card-top">
            <?php if ($isCustomerUser): ?>
            <form method="post" onclick="event.stopPropagation()" style="display:inline"><?= csrf_field() ?><input type="hidden" name="_fav" value="1"><input type="hidden" name="product_id" value="<?= $pid ?>"><button type="submit" class="cat-card-fav <?= $isFav?'active':'' ?>" title="<?= $isFav?'Remove from favorites':'Add to favorites' ?>"><i class="fas fa-heart"></i></button></form>
            <form method="post" onclick="event.stopPropagation()" style="display:inline"><?= csrf_field() ?><input type="hidden" name="_compare" value="1"><input type="hidden" name="product_id" value="<?= $pid ?>"><button type="submit" class="cat-card-cmp <?= $isCmp?'active':'' ?>" title="<?= $isCmp?'Remove from comparison':'Add to compare' ?>"><?= count($compareIds) ?>/4 <i class="fas fa-balance-scale"></i></button></form>
            <?php endif; ?>
        </div>
        <div class="cat-card-img">
            <?php if (!empty($p['image_path']) && file_exists(__DIR__.'/../uploads/products/'.$p['image_path'])): ?><img src="<?= PRODUCT_UPLOAD_URL.'/'.htmlspecialchars($p['image_path']) ?>" alt=""><?php else: ?><span class="no-img"><i class="fas fa-pills"></i></span><?php endif; ?>
            <div class="cat-card-badge">
                <span class="cat-badge <?= $sb[1] ?>"><i class="fas <?= $sb[2] ?>"></i> <?= $sb[0] ?></span>
                <?php if ($expSoon): ?><span class="cat-badge exp"><i class="fas fa-hourglass-half"></i> Exp <?= date('M Y', strtotime($earliest)) ?></span><?php endif; ?>
                <?php if ($isFrq): ?><span class="cat-badge freq"><i class="fas fa-fire"></i> Popular</span><?php endif; ?>
                <?php if (!empty($p['requires_prescription'])): ?><span class="cat-badge rx"><i class="fas fa-prescription"></i> Rx</span><?php endif; ?>
            </div>
            <button type="button" class="cat-qv" onclick="catQuickView(event)"><i class="fas fa-eye"></i> Quick View</button>
        </div>
        <div class="cat-card-body">
            <h3 class="cat-card-name"><?= htmlspecialchars($p['name']) ?></h3>
            <p class="cat-card-sub"><?= htmlspecialchars(($p['brand']??'').($p['brand']&&$p['generic_name']?' — ':'').($p['generic_name']??'')) ?></p>
            <dl class="cat-card-details">
                <?php if ($p['dosage_form']): ?><dt>Form</dt><dd><?= htmlspecialchars($p['dosage_form']) ?></dd><?php endif; ?>
                <?php if ($p['strength']): ?><dt>Strength</dt><dd><?= htmlspecialchars($p['strength']) ?></dd><?php endif; ?>
                <?php if ($p['pack_size']): ?><dt>Pack</dt><dd><?= htmlspecialchars($p['pack_size']) ?></dd><?php endif; ?>
                <?php if ($p['manufacturer']): ?><dt>Mfr</dt><dd><?= htmlspecialchars($p['manufacturer']) ?></dd><?php endif; ?>
                <?php if ($p['category_name']): ?><dt>Category</dt><dd><?= htmlspecialchars($p['category_name']) ?></dd><?php endif; ?>
            </dl>
            <?php if ($p['country_of_origin']): ?><div class="cat-card-origin"><?= countryFlag($p['country_of_origin']) ?> <?= htmlspecialchars($p['country_of_origin']) ?></div><?php endif; ?>
            <div class="cat-stock">
                <div class="cat-stock-top">
                    <span class="cat-<?= $sb[1] ?>"><i class="fas <?= $sb[2] ?>"></i> <?= $sb[0] ?></span>
                </div>
                <div class="cat-stockbar"><div class="cat-stockfill" style="width:<?= $pct ?>%;background:<?= $sb[1]==='in'?'var(--cat-ok)':($sb[1]==='low'?'var(--cat-warn)':'var(--cat-bad)') ?>"></div></div>
            </div>
        </div>
        <div class="cat-card-footer">
            <div class="cat-card-price"><?= fmt((float)$p['price']) ?></div>
            <?php if ($isCustomerUser): ?>
            <?php if ($stock <= 0): ?>
            <button class="cat-card-order" disabled onclick="event.stopPropagation()" title="Out of stock"><i class="fas fa-times-circle"></i> Out of Stock</button>
            <?php else: ?>
            <a class="cat-card-order" href="index.php?page=place_order&product_id=<?= $pid ?>" onclick="event.stopPropagation()"><i class="fas fa-cart-plus"></i> Order</a>
            <?php endif; ?>
            <?php else: ?>
            <a class="cat-card-order login" href="index.php?page=login" onclick="event.stopPropagation()"><i class="fas fa-sign-in-alt"></i> Sign In</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>
</section>

<!-- Product Detail Modal -->
<div class="pd-overlay" id="pdOverlay" onclick="if(event.target===this)closeCatDetail()">
    <div class="pd-modal">
        <div class="pd-header">
            <div class="pd-img" id="pdImg"><span class="no-img"><i class="fas fa-pills"></i></span></div>
            <div style="flex:1;min-width:0">
                <h2 class="pd-title" id="pdName"></h2>
                <p class="pd-sub" id="pdSub"></p>
                <p style="font-size:0.78rem;color:#94a3b8;margin:2px 0 0;font-family:monospace" id="pdSku"></p>
            </div>
            <button class="pd-close" onclick="closeCatDetail()">&times;</button>
        </div>
        <div class="pd-body">
            <div id="pdDesc" style="display:none"><p style="font-size:0.88rem;color:#475569;line-height:1.6;margin:0 0 12px" id="pdDescText"></p></div>
            <div class="pd-grid" id="pdGrid"></div>
            <div id="pdStorage" class="pd-section" style="display:none"><div class="pd-section-title"><i class="fas fa-thermometer-half"></i> Storage</div><p style="font-size:0.85rem;color:#475569;margin:0" id="pdStorageText"></p></div>
            <div id="pdBatches" class="pd-section" style="display:none"><div class="pd-section-title"><i class="fas fa-layer-group"></i> Available Batches</div><div id="pdBatchList"></div></div>
            <div class="pd-section"><div class="pd-section-title"><i class="fas fa-box"></i> Stock Level</div><div id="pdStockBar"></div></div>
        </div>
        <div class="pd-footer">
            <span id="pdPrice" style="font-size:1.2rem;font-weight:800;color:#1e293b"></span>
            <a id="pdOrderBtn" href="#" class="cat-card-order" style="padding:10px 24px;font-size:0.9rem"><i class="fas fa-cart-plus"></i> Order Now</a>
        </div>
    </div>
</div>

<script>
function catQuickView(e){e.stopPropagation();var c=e.currentTarget.closest('.cat-card');if(c)showCatDetail(c)}
function showCatDetail(el){
    var o=document.getElementById('pdOverlay');
    document.getElementById('pdName').textContent=el.dataset.name||'';
    document.getElementById('pdSub').textContent=[el.dataset.brand,el.dataset.generic].filter(Boolean).join(' — ');
    document.getElementById('pdSku').textContent=el.dataset.barcode?'SKU: '+el.dataset.barcode:'';
    document.getElementById('pdPrice').textContent=el.dataset.price||'';
    var img=document.getElementById('pdImg');
    if(el.dataset.image){img.innerHTML='<img src="'+el.dataset.image+'" alt="">'}else{img.innerHTML='<span class="no-img"><i class="fas fa-pills"></i></span>'}
    var dd=document.getElementById('pdDesc');if(el.dataset.desc){document.getElementById('pdDescText').textContent=el.dataset.desc;dd.style.display='block'}else{dd.style.display='none'}
    var st=document.getElementById('pdStorage');if(el.dataset.storage){document.getElementById('pdStorageText').textContent=el.dataset.storage;st.style.display='block'}else{st.style.display='none'}
    var fields=[];
    if(el.dataset.dosage)fields.push(['Dosage Form',el.dataset.dosage]);
    if(el.dataset.strength)fields.push(['Strength',el.dataset.strength]);
    if(el.dataset.pack)fields.push(['Pack Size',el.dataset.pack]);
    if(el.dataset.unit)fields.push(['Unit',el.dataset.unit]);
    if(el.dataset.mfr)fields.push(['Manufacturer',el.dataset.mfr]);
    if(el.dataset.country)fields.push(['Origin',el.dataset.country]);
    if(el.dataset.category)fields.push(['Category',el.dataset.category]);
    if(el.dataset.mfg)fields.push(['Mfg Date',el.dataset.mfg]);
    var stock=parseInt(el.dataset.stock)||0;
    var sl=stock<=0?'Out of Stock':stock<=10?'Low Stock':'In Stock';
    var sc=stock<=0?'#dc2626':stock<=10?'#d97706':'#16a34a';
    fields.push(['Stock',sl]);
    if(el.dataset.rx==='1')fields.push(['Prescription','Required']);
    document.getElementById('pdGrid').innerHTML=fields.map(function(f){return '<div class="pd-field"><dt>'+f[0]+'</dt><dd>'+f[1]+'</dd></div>'}).join('');
    try{var batches=JSON.parse(el.dataset.batches||'[]');var bl=document.getElementById('pdBatchList');var bd=document.getElementById('pdBatches');
    if(batches.length>0){bl.innerHTML=batches.map(function(b){var exp=new Date(b.expiry);var now=new Date();var mo=(exp.getFullYear()-now.getFullYear())*12+(exp.getMonth()-now.getMonth());var c=mo<=3?'color:#dc2626;background:#fef2f2':mo<=6?'color:#d97706;background:#fffbeb':'color:#475569;background:#f8fafc';return '<div class="pd-batch"><span style="font-weight:600">'+b.batch+'</span><span style="padding:2px 8px;border-radius:6px;font-weight:600;font-size:0.78rem;'+c+'">Exp: '+b.expiry+'</span></div>'}).join('');bd.style.display='block'}else{bd.style.display='none'}}catch(e){document.getElementById('pdBatches').style.display='none'}
    var pct=Math.min(100,stock>0?Math.min(stock/50*100,100):0);
    document.getElementById('pdStockBar').innerHTML='<div style="display:flex;justify-content:space-between;font-size:0.78rem;margin-bottom:4px"><span style="font-weight:600;color:'+sc+'">'+sl+'</span></div><div class="pd-stock-bar"><div class="pd-stock-fill" style="width:'+pct+'%;background:'+sc+'"></div></div>';
    var btn=document.getElementById('pdOrderBtn');
    <?php if ($isCustomerUser): ?>if(stock<=0){btn.href='#';btn.setAttribute('disabled','disabled');btn.innerHTML='<i class="fas fa-times-circle"></i> Out of Stock'}else{btn.removeAttribute('disabled');btn.href='index.php?page=place_order&product_id='+el.dataset.pid;btn.innerHTML='<i class="fas fa-cart-plus"></i> Order Now'}btn.style.display='';
    <?php else: ?>btn.href='index.php?page=login';btn.style.display='';<?php endif; ?>
    o.style.display='flex';
    if(window.history&&window.history.replaceState){window.history.replaceState({},'',window.location.pathname+'?page=catalog&view_product='+el.dataset.pid)}
}
function closeCatDetail(){document.getElementById('pdOverlay').style.display='none'}
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeCatDetail()});

document.getElementById('catScanBtn').addEventListener('click',function(){var i=document.getElementById('catBarcodeInput');i.style.position='relative';i.style.left='0';i.style.opacity='1';i.focus()});
document.getElementById('catBarcodeInput').addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();var c=this.value.trim();if(!c)return;document.getElementById('catSearchInput').value=c;document.getElementById('catSearchForm').submit()}});
document.addEventListener('barcode-scanned',function(e){var i=document.getElementById('catBarcodeInput');if(i){i.value=e.detail.code;i.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter'}))}});
</script>
