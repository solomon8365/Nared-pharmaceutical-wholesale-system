<?php
require_once __DIR__ . '/../services/init.php';
requirePermission('products', 'view');
require_once __DIR__ . '/../includes/product_upload.php';
require_once __DIR__ . '/../includes/stock_helper.php';

$pdo = getPDO();
$messages = [];
$allCategories = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$allSuppliers = $pdo->query('SELECT id, name FROM suppliers WHERE supplier_status = "active" ORDER BY name')->fetchAll();

PricingService::ensureSettings($pdo);
$pricingSelling = PricingService::sellingMarkup();
$pricingWholesale = PricingService::wholesaleMarkup();

$editProduct = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int) $_GET['edit']]);
    $editProduct = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['_action'] ?? 'create';

    if ($action === 'delete') {
        requirePermission('products', 'delete');
        $deleteId = (int) ($_POST['delete_id'] ?? 0);
        if ($deleteId > 0) {
            try {
                $pdo->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $deleteId]);
                $messages[] = ['type' => 'success', 'text' => 'Product deleted successfully.'];
            } catch (Throwable $e) {
                $messages[] = ['type' => 'error', 'text' => 'Could not delete product: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'pricing_settings') {
        requirePermission('products', 'edit');
        $sellingRaw = trim((string) ($_POST['selling_markup'] ?? ''));
        $wholesaleRaw = trim((string) ($_POST['wholesale_markup'] ?? ''));
        if ($sellingRaw === '' || $wholesaleRaw === '') {
            $messages[] = ['type' => 'error', 'text' => 'Both selling and wholesale markup percentages are required.'];
        } else {
            try {
                $summary = PricingService::updateGlobalMarkups($pdo, (float) $sellingRaw, (float) $wholesaleRaw, (int) ($_SESSION['user_id'] ?? 0));
                $pricingSelling = (float) $sellingRaw;
                $pricingWholesale = (float) $wholesaleRaw;
                $messages[] = ['type' => 'success', 'text' => 'Pricing settings saved. ' . $summary['changed'] . ' of ' . $summary['eligible'] . ' products were recalculated from the new global markups. Historical orders and invoices are unchanged.'];
            } catch (Throwable $e) {
                $messages[] = ['type' => 'error', 'text' => $e->getMessage()];
            }
        }
    } elseif ($action === 'update') {
        requirePermission('products', 'edit');
        $productId = (int) ($_POST['product_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $stock = (int) ($_POST['stock'] ?? 0);
        $purchasePrice = (float) ($_POST['purchase_price'] ?? 0);

        if ($productId <= 0 || $name === '') {
            $messages[] = ['type' => 'error', 'text' => 'Product name is required.'];
        } else {
            $old = $pdo->prepare('SELECT price, wholesale_price, purchase_price FROM products WHERE id = :id');
            $old->execute(['id' => $productId]);
            $oldRow = $old->fetch();
            $oldPrice = (float) ($oldRow['price'] ?? 0);
            $oldWholesale = (float) ($oldRow['wholesale_price'] ?? 0);

            // Prices are always derived server-side from the purchase price and
            // the global markups. Browser-supplied price/wholesale are ignored.
            $calc = $purchasePrice > 0 ? PricingService::calculatePrices($purchasePrice) : null;
            $price = $calc ? $calc['price'] : $oldPrice;
            $wholesale = $calc ? $calc['wholesale_price'] : $oldWholesale;
            $profitMargin = $purchasePrice > 0 ? round(($price - $purchasePrice) / $purchasePrice * 100, 2) : 0;

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('UPDATE products SET name = :name, brand = :brand, generic_name = :gn, dosage_form = :df, strength = :strength, pack_size = :ps, unit_of_measure = :uom, manufacturer = :mfr, country_of_origin = :coo, storage_condition = :sc, shelf_life = :shelflife, manufacturing_date = :mfg, description = :description, category_id = :cat, barcode = :barcode, price = :price, purchase_price = :pp, profit_margin = :pm, stock_quantity = :stock, reorder_level = :reorder, min_order_qty = :minorder, max_stock_level = :maxstock, shelf_location = :shelf, product_type = :ptype, requires_prescription = :rx, vat_percent = :vat, wholesale_price = :wp, supplier_id = :sid, supplier_product_code = :spc, internal_product_code = :ipc, product_weight = :pw, package_dimensions = :pd, product_status = :pstatus, updated_at = NOW() WHERE id = :id');
                $stmt->execute([
                    'name' => $name,
                    'brand' => !empty($_POST['brand']) ? trim($_POST['brand']) : null,
                    'gn' => !empty($_POST['generic_name']) ? trim($_POST['generic_name']) : null,
                    'df' => !empty($_POST['dosage_form']) ? trim($_POST['dosage_form']) : null,
                    'strength' => !empty($_POST['strength']) ? trim($_POST['strength']) : null,
                    'ps' => !empty($_POST['pack_size']) ? trim($_POST['pack_size']) : null,
                    'uom' => !empty($_POST['unit_of_measure']) ? trim($_POST['unit_of_measure']) : null,
                    'mfr' => !empty($_POST['manufacturer']) ? trim($_POST['manufacturer']) : null,
                    'coo' => !empty($_POST['country_of_origin']) ? trim($_POST['country_of_origin']) : null,
                    'sc' => !empty($_POST['storage_condition']) ? trim($_POST['storage_condition']) : null,
                    'shelflife' => !empty($_POST['shelf_life']) ? (int) $_POST['shelf_life'] : null,
                    'mfg' => !empty($_POST['manufacturing_date']) ? $_POST['manufacturing_date'] : null,
                    'description' => $description,
                    'cat' => !empty($_POST['category_id']) ? (int) $_POST['category_id'] : null,
                    'barcode' => !empty($_POST['barcode']) ? trim($_POST['barcode']) : null,
                    'price' => $price,
                    'pp' => $purchasePrice,
                    'pm' => $profitMargin,
                    'stock' => $stock,
                    'reorder' => (int) ($_POST['reorder_level'] ?? 0),
                    'minorder' => (int) ($_POST['min_order_qty'] ?? 0),
                    'maxstock' => (int) ($_POST['max_stock_level'] ?? 0),
                    'shelf' => !empty($_POST['shelf_location']) ? trim($_POST['shelf_location']) : null,
                    'ptype' => $_POST['product_type'] ?? 'otc',
                    'rx' => isset($_POST['requires_prescription']) ? 1 : 0,
                    'vat' => (float) ($_POST['vat_percent'] ?? SettingsService::vatRate()),
                    'wp' => $wholesale,
                    'sid' => !empty($_POST['supplier_id']) ? (int) $_POST['supplier_id'] : null,
                    'spc' => !empty($_POST['supplier_product_code']) ? trim($_POST['supplier_product_code']) : null,
                    'ipc' => !empty($_POST['internal_product_code']) ? trim($_POST['internal_product_code']) : null,
                    'pw' => !empty($_POST['product_weight']) ? trim($_POST['product_weight']) : null,
                    'pd' => !empty($_POST['package_dimensions']) ? trim($_POST['package_dimensions']) : null,
                    'pstatus' => $_POST['product_status'] ?? 'active',
                    'id' => $productId,
                ]);

                if (abs($oldPrice - $price) > 0.001) {
                    $uid = (int) ($_SESSION['user_id'] ?? 0);
                    $ph = $pdo->prepare('INSERT INTO price_history (product_id, old_price, new_price, changed_by, created_at) VALUES (:pid, :old, :new, :uid, NOW())');
                    $ph->execute(['pid' => $productId, 'old' => $oldPrice, 'new' => $price, 'uid' => $uid]);
                }

                $upload = $_FILES['image'] ?? null;
                if ($upload && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $imagePath = store_product_image($upload, $productId);
                    if ($imagePath !== null) {
                        $pdo->prepare('UPDATE products SET image_path = :p WHERE id = :id')->execute(['p' => $imagePath, 'id' => $productId]);
                    }
                }

                $pdo->commit();
                $messages[] = ['type' => 'success', 'text' => 'Product updated successfully.'];
            } catch (Throwable $e) {
                $pdo->rollBack();
                $messages[] = ['type' => 'error', 'text' => $e->getMessage()];
            }
        }
    } else {
        requirePermission('products', 'create');
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $stock = (int) ($_POST['stock'] ?? 0);
        $purchasePrice = (float) ($_POST['purchase_price'] ?? 0);

        if ($name === '' || $purchasePrice <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Product name and purchase price are required.'];
        } else {
            // Prices are derived server-side from the purchase price and the
            // global markups. Browser-supplied price/wholesale are ignored.
            $calc = PricingService::calculatePrices($purchasePrice);
            $price = $calc['price'];
            $wholesale = $calc['wholesale_price'];
            $profitMargin = round(($price - $purchasePrice) / $purchasePrice * 100, 2);

            if (empty($_POST['barcode']) && get_setting('auto_generate_barcode', '0') === '1') {
                $_POST['barcode'] = 'PROD-' . strtoupper(bin2hex(random_bytes(3)));
            }

            $productCode = 'PRD-' . strtoupper(bin2hex(random_bytes(3)));
            $sku = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 4)) . '-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
            $profitMargin = $purchasePrice > 0 ? round(($price - $purchasePrice) / $purchasePrice * 100, 2) : 0;
            $userId = (int) ($_SESSION['user_id'] ?? 0);

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('INSERT INTO products (name, brand, generic_name, dosage_form, strength, pack_size, unit_of_measure, manufacturer, country_of_origin, storage_condition, shelf_life, manufacturing_date, description, category_id, barcode, product_code, sku, profit_margin, created_by, price, purchase_price, stock_quantity, reorder_level, min_order_qty, max_stock_level, shelf_location, product_type, requires_prescription, vat_percent, wholesale_price, supplier_id, supplier_product_code, internal_product_code, product_weight, package_dimensions, product_status, created_at) VALUES (:name, :brand, :gn, :df, :strength, :ps, :uom, :mfr, :coo, :sc, :shelflife, :mfg, :description, :cat, :barcode, :pcode, :sku, :pm, :cb, :price, :pp, :stock, :reorder, :minorder, :maxstock, :shelf, :ptype, :rx, :vat, :wp, :sid, :spc, :ipc, :pw, :pd, :pstatus, NOW())');
                $stmt->execute([
                    'name' => $name,
                    'brand' => !empty($_POST['brand']) ? trim($_POST['brand']) : null,
                    'gn' => !empty($_POST['generic_name']) ? trim($_POST['generic_name']) : null,
                    'df' => !empty($_POST['dosage_form']) ? trim($_POST['dosage_form']) : null,
                    'strength' => !empty($_POST['strength']) ? trim($_POST['strength']) : null,
                    'ps' => !empty($_POST['pack_size']) ? trim($_POST['pack_size']) : null,
                    'uom' => !empty($_POST['unit_of_measure']) ? trim($_POST['unit_of_measure']) : null,
                    'mfr' => !empty($_POST['manufacturer']) ? trim($_POST['manufacturer']) : null,
                    'coo' => !empty($_POST['country_of_origin']) ? trim($_POST['country_of_origin']) : null,
                    'sc' => !empty($_POST['storage_condition']) ? trim($_POST['storage_condition']) : null,
                    'shelflife' => !empty($_POST['shelf_life']) ? (int) $_POST['shelf_life'] : null,
                    'mfg' => !empty($_POST['manufacturing_date']) ? $_POST['manufacturing_date'] : null,
                    'description' => $description,
                    'cat' => !empty($_POST['category_id']) ? (int) $_POST['category_id'] : null,
                    'barcode' => !empty($_POST['barcode']) ? trim($_POST['barcode']) : null,
                    'pcode' => $productCode,
                    'sku' => $sku,
                    'pm' => $profitMargin,
                    'cb' => $userId ?: null,
                    'price' => $price,
                    'pp' => $purchasePrice,
                    'stock' => $stock,
                    'reorder' => (int) ($_POST['reorder_level'] ?? 0),
                    'minorder' => (int) ($_POST['min_order_qty'] ?? 0),
                    'maxstock' => (int) ($_POST['max_stock_level'] ?? 0),
                    'shelf' => !empty($_POST['shelf_location']) ? trim($_POST['shelf_location']) : null,
                    'ptype' => $_POST['product_type'] ?? 'otc',
                    'rx' => isset($_POST['requires_prescription']) ? 1 : 0,
                    'vat' => (float) ($_POST['vat_percent'] ?? SettingsService::vatRate()),
                    'wp' => $wholesale,
                    'sid' => !empty($_POST['supplier_id']) ? (int) $_POST['supplier_id'] : null,
                    'spc' => !empty($_POST['supplier_product_code']) ? trim($_POST['supplier_product_code']) : null,
                    'ipc' => !empty($_POST['internal_product_code']) ? trim($_POST['internal_product_code']) : null,
                    'pw' => !empty($_POST['product_weight']) ? trim($_POST['product_weight']) : null,
                    'pd' => !empty($_POST['package_dimensions']) ? trim($_POST['package_dimensions']) : null,
                    'pstatus' => $_POST['product_status'] ?? 'active',
                ]);
                $newId = (int) $pdo->lastInsertId();

                $imagePath = null;
                $upload = $_FILES['image'] ?? null;
                if ($upload && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $imagePath = store_product_image($upload, $newId);
                }
                if ($imagePath !== null) {
                    $pdo->prepare('UPDATE products SET image_path = :p WHERE id = :id')->execute(['p' => $imagePath, 'id' => $newId]);
                }

                $batchNo = trim($_POST['batch_number'] ?? '');
                $expiryDate = trim($_POST['expiry_date'] ?? '');
                if ($batchNo !== '' && $expiryDate !== '') {
                    $pb = $pdo->prepare('INSERT INTO product_batches (product_id, batch_number, expiry_date, quantity, purchase_price) VALUES (:pid, :bn, :exp, :qty, :pp)');
                    $pb->execute(['pid' => $newId, 'bn' => $batchNo, 'exp' => $expiryDate, 'qty' => $stock, 'pp' => $purchasePrice]);
                }

                $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($productCode);
                $pdo->prepare('UPDATE products SET qr_code = :qr WHERE id = :id')->execute(['qr' => $qrUrl, 'id' => $newId]);

                $pdo->commit();
                $messages[] = ['type' => 'success', 'text' => "Product added — Code: {$productCode} | SKU: {$sku}"];
            } catch (Throwable $e) {
                $pdo->rollBack();
                $messages[] = ['type' => 'error', 'text' => $e->getMessage()];
            }
        }
    }
}

$alertAll = $pdo->query("
    SELECT COUNT(*) AS total, SUM(CASE WHEN expiry_date < CURDATE() THEN 1 ELSE 0 END) AS expired,
        SUM(CASE WHEN expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN 1 ELSE 0 END) AS expiring
    FROM product_batches WHERE quantity > 0
")->fetch();
$lowStockItems = $pdo->query("
    SELECT p.id, p.name, p.stock_quantity, p.price,
        COALESCE((SELECT SUM(si.quantity) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE si.product_id = p.id AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) / 30, 0) AS daily_avg
    FROM products p WHERE p.stock_quantity <= 10 ORDER BY p.stock_quantity ASC LIMIT 10
")->fetchAll();

$alertBatches = $pdo->query("
    SELECT pb.id, pb.batch_number, pb.expiry_date, pb.quantity, p.name AS product_name, p.id AS product_id,
        DATEDIFF(pb.expiry_date, CURDATE()) AS days_left
    FROM product_batches pb JOIN products p ON pb.product_id = p.id
    WHERE pb.quantity > 0 AND pb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
    ORDER BY pb.expiry_date ASC LIMIT 20
")->fetchAll();
$hasAlerts = ((int)$alertAll['expired'] + (int)$alertAll['expiring']) > 0;

$supplierFilter = (int) ($_GET['supplier_id'] ?? 0);
$statusFilter = trim($_GET['status'] ?? '');
$stockLevel = trim($_GET['stock_level'] ?? '');
$countryFilter = trim($_GET['country'] ?? '');

$search = trim($_GET['search'] ?? '');
$catFilter = (int) ($_GET['category_id'] ?? 0);

$page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page = 25;

$sql = 'SELECT p.*, c.name AS category_name, s.name AS supplier_name FROM products p LEFT JOIN categories c ON p.category_id = c.id LEFT JOIN suppliers s ON p.supplier_id = s.id';
$conds = [];
$params = [];
if ($search !== '') {
    $conds[] = '(p.name LIKE :q1 OR p.brand LIKE :q2 OR p.generic_name LIKE :q3 OR p.barcode LIKE :q4 OR p.sku LIKE :q5)';
    $params['q1'] = "%$search%";
    $params['q2'] = "%$search%";
    $params['q3'] = "%$search%";
    $params['q4'] = "%$search%";
    $params['q5'] = "%$search%";
}
if ($catFilter > 0) { $conds[] = 'p.category_id = :cat'; $params['cat'] = $catFilter; }
if ($supplierFilter > 0) { $conds[] = 'p.supplier_id = :sid'; $params['sid'] = $supplierFilter; }
if ($statusFilter !== '') { $conds[] = 'p.product_status = :st'; $params['st'] = $statusFilter; }
if ($countryFilter !== '') { $conds[] = 'p.country_of_origin LIKE :co'; $params['co'] = "%$countryFilter%"; }
if ($stockLevel === 'low') { $conds[] = 'p.stock_quantity > 0 AND p.stock_quantity <= p.reorder_level'; }
elseif ($stockLevel === 'out') { $conds[] = 'p.stock_quantity = 0'; }
elseif ($stockLevel === 'normal') { $conds[] = 'p.stock_quantity > p.reorder_level'; }
if (!empty($conds)) $sql .= ' WHERE ' . implode(' AND ', $conds);

$countSql = str_replace('SELECT p.*, c.name AS category_name, s.name AS supplier_name', 'SELECT COUNT(*)', $sql);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalProducts = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalProducts / $per_page));
if ($page_num > $totalPages) $page_num = $totalPages;
$offset = ($page_num - 1) * $per_page;

$sql .= ' ORDER BY p.created_at DESC LIMIT ' . $per_page . ' OFFSET ' . $offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$batchInfoMap = [];
$expiredInfoMap = [];
if (!empty($products)) {
    $pids = array_map('intval', array_column($products, 'id'));
    if ($pids) {
        $ph = implode(',', $pids);
        $bi = $pdo->prepare("SELECT product_id, COUNT(*) AS cnt, MIN(expiry_date) AS earliest FROM product_batches WHERE product_id IN ($ph) AND quantity > 0 GROUP BY product_id");
        $bi->execute();
        while ($row = $bi->fetch()) $batchInfoMap[$row['product_id']] = $row;
        $ei = $pdo->prepare("SELECT DISTINCT product_id FROM product_batches WHERE product_id IN ($ph) AND expiry_date < CURDATE() AND quantity > 0");
        $ei->execute();
        while ($row = $ei->fetch()) $expiredInfoMap[(int)$row['product_id']] = true;
    }
}

$summaryTotalRow = stockTotals($pdo, null, 'purchase_price');
$summaryTotal = (int) ($summaryTotalRow['total'] ?? 0);
$summaryInvValue = (float) ($summaryTotalRow['total_value'] ?? 0);
$summaryStock = (int) ($summaryTotalRow['total_qty'] ?? 0);
$summaryLowStock = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE stock_quantity > 0 AND stock_quantity <= reorder_level')->fetchColumn();
$summaryOutOfStock = (int) ($summaryTotalRow['out_count'] ?? 0);
$summaryExpired = expiredProductCount($pdo);

if (isset($_GET['batch_data']) || isset($_GET['stock_history']) || isset($_GET['price_history'])) {
    header('Content-Type: application/json');
    if (isset($_GET['batch_data'])) {
        $pid = (int)$_GET['batch_data'];
        $bj = $pdo->prepare('SELECT *, DATEDIFF(expiry_date, CURDATE()) AS days_left FROM product_batches WHERE product_id = :pid ORDER BY expiry_date ASC');
        $bj->execute(['pid' => $pid]);
        echo json_encode($bj->fetchAll(PDO::FETCH_ASSOC));
    } elseif (isset($_GET['stock_history'])) {
        $pid = (int)$_GET['stock_history'];
        $sh = $pdo->prepare('SELECT sm.*, u.full_name AS user_name FROM stock_movements sm LEFT JOIN users u ON sm.reference_id = u.id WHERE sm.product_id = :pid ORDER BY sm.created_at DESC LIMIT 50');
        $sh->execute(['pid' => $pid]);
        echo json_encode($sh->fetchAll(PDO::FETCH_ASSOC));
    } elseif (isset($_GET['price_history'])) {
        $pid = (int)$_GET['price_history'];
        $ph = $pdo->prepare('SELECT ph.*, u.full_name AS user_name FROM price_history ph LEFT JOIN users u ON ph.changed_by = u.id WHERE ph.product_id = :pid ORDER BY ph.created_at DESC LIMIT 50');
        $ph->execute(['pid' => $pid]);
        echo json_encode($ph->fetchAll(PDO::FETCH_ASSOC));
    }
    exit;
}

function countryFlag($c) {
    $map = ['Ethiopia'=>"\xF0\x9F\x87\xBA\xF0\x9F\x87\xBC",'India'=>"\xF0\x9F\x87\xAE\xF0\x9F\x87\xB3",'China'=>"\xF0\x9F\x87\xA8\xF0\x9F\x87\xB3",'United Kingdom'=>"\xF0\x9F\x87\xAC\xF0\x9F\x87\xA7",'USA'=>"\xF0\x9F\x87\xBA\xF0\x9F\x87\xB8",'United States'=>"\xF0\x9F\x87\xBA\xF0\x9F\x87\xB8",'Germany'=>"\xF0\x9F\x87\xA9\xF0\x9F\x87\xAA",'France'=>"\xF0\x9F\x87\xAB\xF0\x9F\x87\xB7",'Japan'=>"\xF0\x9F\x87\xAF\xF0\x9F\x87\xB5",'Switzerland'=>"\xF0\x9F\x87\xA8\xF0\x9F\x87\xAD",'UAE'=>"\xF0\x9F\x87\xA6\xF0\x9F\x87\xAA",'Italy'=>"\xF0\x9F\x87\xAE\xF0\x9F\x87\xB9",'Turkey'=>"\xF0\x9F\x87\xB9\xF0\x9F\x87\xB7",'Kenya'=>"\xF0\x9F\x87\xB0\xF0\x9F\x87\xAA",'Nigeria'=>"\xF0\x9F\x87\xB3\xF0\x9F\x87\xAC",'South Korea'=>"\xF0\x9F\x87\xB0\xF0\x9F\x87\xB7",'Canada'=>"\xF0\x9F\x87\xA8\xF0\x9F\x87\xA6",'Brazil'=>"\xF0\x9F\x87\xA7\xF0\x9F\x87\xB7",'Egypt'=>"\xF0\x9F\x87\xAA\xF0\x9F\x87\xBC",'Israel'=>"\xF0\x9F\x87\xAE\xF0\x9F\x87\xB1",'Pakistan'=>"\xF0\x9F\x87\xB5\xF0\x9F\x87\xB0",'Bangladesh'=>"\xF0\x9F\x87\xA7\xF0\x9F\x87\xA9",'Sri Lanka'=>"\xF0\x9F\x87\xB1\xF0\x9F\x87\xB0",'UK'=>"\xF0\x9F\x87\xAC\xF0\x9F\x87\xA7",'Korea'=>"\xF0\x9F\x87\xB0\xF0\x9F\x87\xB7"];
    $c = trim($c ?? '');
    return $map[$c] ?? '';
}

$batchViewProductId = isset($_GET['batches']) ? (int) $_GET['batches'] : 0;
$batchViewProduct = null;
$batches = [];
if ($batchViewProductId > 0) {
    $bvStmt = $pdo->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
    $bvStmt->execute(['id' => $batchViewProductId]);
    $batchViewProduct = $bvStmt->fetch();
    if ($batchViewProduct) {
        $bStmt = $pdo->prepare('SELECT * FROM product_batches WHERE product_id = :pid ORDER BY expiry_date ASC');
        $bStmt->execute(['pid' => $batchViewProductId]);
        $batches = $bStmt->fetchAll();
    }
}

$showAddModal = isset($_GET['add']);

?>
<style>
.erp-wrap{max-width:100%;margin:0 auto;padding:0 4px}
.erp-page-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;gap:12px;flex-wrap:wrap}
.erp-page-title{font-size:1.3rem;font-weight:700;color:#1e293b;display:flex;align-items:center;gap:8px}
.erp-page-title i{color:#3b82f6}

.erp-summary{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:16px}
.erp-summary-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;display:flex;align-items:center;gap:12px;transition:box-shadow .15s}
.erp-summary-card:hover{box-shadow:0 2px 8px rgba(0,0,0,.06)}
.erp-sc-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.erp-sc-val{font-size:1.15rem;font-weight:700;color:#1e293b;line-height:1.2}
.erp-sc-label{font-size:0.72rem;color:#94a3b8;font-weight:500}

.erp-toolbar{display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap}
.erp-search{position:relative;flex:1;min-width:220px;max-width:400px}
.erp-search input{width:100%;padding:9px 12px 9px 36px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.85rem;background:#fff;transition:border .15s}
.erp-search input:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.1)}
.erp-search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:0.85rem}
.erp-toolbar-info{font-size:0.82rem;color:#64748b;white-space:nowrap}
.erp-toolbar-info strong{color:#1e293b}
.erp-toolbar-actions{display:flex;gap:6px;margin-left:auto}
.erp-btn{padding:7px 14px;border:none;border-radius:8px;cursor:pointer;font-size:0.8rem;font-weight:600;display:inline-flex;align-items:center;gap:5px;transition:all .15s;text-decoration:none}
.erp-btn-sm{padding:5px 10px;font-size:0.75rem;border-radius:6px}
.erp-btn-primary{background:#3b82f6;color:#fff}.erp-btn-primary:hover{background:#2563eb}
.erp-btn-success{background:#10b981;color:#fff}.erp-btn-success:hover{background:#059669}
.erp-btn-outline{background:#fff;color:#64748b;border:1px solid #e2e8f0}.erp-btn-outline:hover{background:#f8fafc;color:#1e293b;border-color:#cbd5e1}
.erp-btn-danger-soft{background:#fef2f2;color:#dc2626}.erp-btn-danger-soft:hover{background:#fee2e2}
.erp-btn-icon{width:30px;height:30px;padding:0;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;border:1px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer;font-size:0.78rem;transition:all .15s}
.erp-btn-icon:hover{background:#f1f5f9;color:#1e293b;border-color:#cbd5e1}

.erp-filter-panel{display:none;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:12px}
.erp-filter-panel.open{display:block}
.erp-filter-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;align-items:end}
.erp-filter-grid label{display:block;font-size:0.72rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px}
.erp-filter-grid input,.erp-filter-grid select{width:100%;padding:7px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:0.82rem}
.erp-filter-grid input:focus,.erp-filter-grid select:focus{outline:none;border-color:#3b82f6}

.erp-table-wrap{background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden}
.erp-table{width:100%;border-collapse:collapse}
.erp-table thead{background:#f8fafc}
.erp-table th{padding:10px 14px;font-size:0.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #e2e8f0;white-space:nowrap;text-align:left}
.erp-table td{padding:8px 14px;font-size:0.82rem;border-bottom:1px solid #f1f5f9;vertical-align:middle;color:#334155}
.erp-table tbody tr:hover{background:#f8fafc}
.erp-table tbody tr:last-child td{border-bottom:none}

.erp-prod-cell{display:flex;align-items:center;gap:10px;min-width:180px}
.erp-prod-thumb{width:36px;height:36px;border-radius:8px;object-fit:cover;border:1px solid #e2e8f0;flex-shrink:0;background:#f1f5f9}
.erp-prod-thumb-placeholder{width:36px;height:36px;border-radius:8px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:0.85rem;flex-shrink:0}
.erp-prod-name{font-weight:600;color:#1e293b;line-height:1.2}
.erp-prod-generic{font-size:0.72rem;color:#94a3b8;line-height:1.2}

.erp-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:0.7rem;font-weight:600;white-space:nowrap}
.erp-badge-green{background:#dcfce7;color:#16a34a}
.erp-badge-orange{background:#fff7ed;color:#ea580c}
.erp-badge-red{background:#fef2f2;color:#dc2626}
.erp-badge-black{background:#1e293b;color:#fff}
.erp-badge-gray{background:#f1f5f9;color:#64748b}

.erp-actions{display:flex;gap:3px;flex-wrap:nowrap}

.erp-pagination{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-top:1px solid #e2e8f0;background:#f8fafc;border-radius:0 0 10px 10px;flex-wrap:wrap;gap:8px}
.erp-pagination-pages{display:flex;gap:3px;align-items:center}
.erp-pagination-pages a,.erp-pagination-pages span{display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:0.8rem;font-weight:500;color:#64748b;background:#fff;text-decoration:none;transition:all .15s}
.erp-pagination-pages a:hover{background:#f1f5f9;color:#1e293b;border-color:#cbd5e1}
.erp-pagination-pages .active{background:#3b82f6;color:#fff;border-color:#3b82f6}
.erp-pagination-pages .disabled{opacity:.4;pointer-events:none}
.erp-pagination-size{display:flex;align-items:center;gap:6px;font-size:0.82rem;color:#64748b}
.erp-pagination-size select{padding:4px 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:0.82rem}

.erp-fab{position:fixed;bottom:28px;right:28px;width:52px;height:52px;border-radius:16px;background:#3b82f6;color:#fff;border:none;cursor:pointer;font-size:1.3rem;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px rgba(59,130,246,.4);transition:all .2s;z-index:100}
.erp-fab:hover{background:#2563eb;transform:scale(1.05);box-shadow:0 6px 20px rgba(59,130,246,.5)}

.erp-modal{display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);align-items:center;justify-content:center}
.erp-modal.open{display:flex}
.erp-modal-box{background:#fff;border-radius:14px;max-width:680px;width:92%;max-height:82vh;overflow-y:auto;padding:24px;position:relative;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.erp-modal-close{position:absolute;top:14px;right:14px;background:none;border:none;font-size:1.1rem;cursor:pointer;color:#94a3b8;width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;transition:all .15s}
.erp-modal-close:hover{background:#f1f5f9;color:#1e293b}
.erp-modal-title{font-size:1.05rem;font-weight:700;color:#1e293b;margin-bottom:16px;display:flex;align-items:center;gap:8px}

.erp-edit-card,.erp-batch-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px;margin-bottom:16px}
.erp-edit-card h2,.erp-batch-card h2{font-size:1rem;font-weight:700;color:#1e293b;margin:0 0 16px;display:flex;align-items:center;gap:8px}

@media(max-width:1200px){.erp-summary{grid-template-columns:repeat(3,1fr)}}
@media(max-width:768px){.erp-summary{grid-template-columns:repeat(2,1fr)}.erp-toolbar{flex-direction:column;align-items:stretch}.erp-search{max-width:none}}
@media print{.erp-toolbar,.erp-fab,.erp-filter-panel,.erp-summary,.no-print{display:none!important}.erp-table-wrap{overflow:visible}}
</style>

<section class="erp-wrap">
    <div class="erp-page-head">
        <div class="erp-page-title"><i class="fas fa-pills"></i> Product Management</div>
        <div style="display:flex;gap:8px">
            <?php if (hasPermission('products', 'create')): ?>
                <a href="?page=products&add=1" class="erp-btn erp-btn-success no-print" title="Add New Product"><i class="fas fa-plus"></i> Add Product</a>
            <?php endif; ?>
        </div>
    </div>

    <?php foreach ($messages as $message): ?>
        <div class="alert alert-<?= htmlspecialchars($message['type']) ?>" style="margin-bottom:12px"><?= htmlspecialchars($message['text']) ?></div>
    <?php endforeach; ?>

    <?php if (hasPermission('products', 'edit')): ?>
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:16px">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:10px">
            <div style="font-size:0.95rem;font-weight:700;color:#1e293b"><i class="fas fa-percent" style="color:#7c3aed;margin-right:6px"></i> Global Pricing Settings</div>
            <span style="font-size:0.72rem;color:#94a3b8">Prices are calculated automatically from each product's purchase price. Historical orders/invoices are never changed.</span>
        </div>
        <form method="post" style="display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="pricing_settings">
            <label style="font-size:0.78rem;color:#64748b;font-weight:600">Selling Markup (%)<input type="number" step="0.01" min="0" max="500" name="selling_markup" value="<?= htmlspecialchars((string) $pricingSelling) ?>" style="width:130px;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:0.85rem;margin-top:3px"></label>
            <label style="font-size:0.78rem;color:#64748b;font-weight:600">Wholesale Markup (%)<input type="number" step="0.01" min="0" max="500" name="wholesale_markup" value="<?= htmlspecialchars((string) $pricingWholesale) ?>" style="width:130px;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:0.85rem;margin-top:3px"></label>
            <button type="submit" class="erp-btn erp-btn-primary" style="height:38px"><i class="fas fa-save"></i> Save Pricing Settings</button>
        </form>
        <div style="margin-top:10px;font-size:0.76rem;color:#64748b;line-height:1.7">
            Selling Price = Purchase Price + (Purchase Price × Selling Markup ÷ 100) &nbsp;•&nbsp; Wholesale Price = Purchase Price + (Purchase Price × Wholesale Markup ÷ 100)<br>
            Delivery orders use the Selling Price; Customer Pickup orders use the Wholesale Price. Saving these values immediately recalculates every product that has a purchase price.
        </div>
    </div>
    <?php endif; ?>

    <div class="erp-summary">
        <div class="erp-summary-card"><div class="erp-sc-icon" style="background:#eff6ff;color:#3b82f6"><i class="fas fa-boxes-stacked"></i></div><div><div class="erp-sc-val"><?= number_format($summaryTotal) ?></div><div class="erp-sc-label">Total Products</div></div></div>
        <div class="erp-summary-card"><div class="erp-sc-icon" style="background:#f0fdf4;color:#16a34a"><i class="fas fa-coins"></i></div><div><div class="erp-sc-val"><?= fmt($summaryInvValue) ?></div><div class="erp-sc-label">Inventory Value</div></div></div>
        <div class="erp-summary-card"><div class="erp-sc-icon" style="background:#f5f3ff;color:#7c3aed"><i class="fas fa-cubes"></i></div><div><div class="erp-sc-val"><?= number_format($summaryStock) ?></div><div class="erp-sc-label">Total Stock</div></div></div>
        <div class="erp-summary-card"><div class="erp-sc-icon" style="background:#fff7ed;color:#ea580c"><i class="fas fa-exclamation-triangle"></i></div><div><div class="erp-sc-val" style="color:<?= $summaryLowStock > 0 ? '#ea580c' : '' ?>"><?= $summaryLowStock ?></div><div class="erp-sc-label">Low Stock</div></div></div>
        <div class="erp-summary-card"><div class="erp-sc-icon" style="background:#fef2f2;color:#dc2626"><i class="fas fa-times-circle"></i></div><div><div class="erp-sc-val" style="color:<?= $summaryOutOfStock > 0 ? '#dc2626' : '' ?>"><?= $summaryOutOfStock ?></div><div class="erp-sc-label">Out of Stock</div></div></div>
        <div class="erp-summary-card"><div class="erp-sc-icon" style="background:#1e293b;color:#fff"><i class="fas fa-clock"></i></div><div><div class="erp-sc-val" style="color:<?= $summaryExpired > 0 ? '#dc2626' : '' ?>"><?= $summaryExpired ?></div><div class="erp-sc-label">Expired</div></div></div>
    </div>

    <?php if ($editProduct): ?>
    <?php if (hasPermission('products', 'edit')): ?>
    <div class="erp-edit-card">
        <h2><i class="fas fa-edit" style="color:#f59e0b"></i> Edit Product — <?= htmlspecialchars($editProduct['name']) ?></h2>
        <?php require_once __DIR__ . '/products_edit_partial.php'; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($batchViewProduct): ?>
    <div class="erp-batch-card">
        <h2><i class="fas fa-layer-group" style="color:#7c3aed"></i> Batch View — <?= htmlspecialchars($batchViewProduct['name']) ?></h2>
        <p style="margin:0 0 12px"><a href="?page=products" style="font-size:0.82rem;color:#3b82f6;text-decoration:none">&larr; Back to list</a></p>
        <table style="width:100%;border-collapse:collapse;font-size:0.82rem">
            <thead><tr style="background:#f8fafc"><th style="padding:8px 12px;text-align:left;border-bottom:1px solid #e2e8f0">Batch #</th><th style="padding:8px 12px;text-align:left">Expiry</th><th style="padding:8px 12px;text-align:right">Qty</th><th style="padding:8px 12px;text-align:right">Price</th></tr></thead>
            <tbody>
                <?php if (empty($batches)): ?>
                    <tr><td colspan="4" style="padding:16px;text-align:center;color:#94a3b8">No batches found.</td></tr>
                <?php else: ?>
                    <?php foreach ($batches as $b): ?>
                        <tr style="border-bottom:1px solid #f1f5f9">
                            <td style="padding:8px 12px;font-weight:600;color:#1e293b"><?= htmlspecialchars($b['batch_number']) ?></td>
                            <td style="padding:8px 12px;<?= strtotime($b['expiry_date']) <= time() + 7776000 ? 'color:#dc2626;font-weight:700' : '' ?>"><?= htmlspecialchars($b['expiry_date']) ?></td>
                            <td style="padding:8px 12px;text-align:right;font-weight:600"><?= (int)$b['quantity'] ?></td>
                            <td style="padding:8px 12px;text-align:right"><?= fmt((float)$b['purchase_price']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="erp-toolbar no-print">
        <div class="erp-search">
            <i class="fas fa-search"></i>
            <input type="text" id="liveSearch" value="<?= htmlspecialchars($search) ?>" placeholder="Search products by name, SKU, barcode...">
        </div>
        <button type="button" class="erp-btn erp-btn-outline" onclick="document.getElementById('filterPanel').classList.toggle('open')" title="Toggle Advanced Filters">
            <i class="fas fa-sliders-h"></i> Filters
            <?php if ($catFilter > 0 || $supplierFilter > 0 || $statusFilter !== '' || $stockLevel !== '' || $countryFilter !== ''): ?>
                <span style="background:#3b82f6;color:#fff;border-radius:10px;padding:1px 7px;font-size:0.65rem;font-weight:700">Active</span>
            <?php endif; ?>
        </button>
        <div class="erp-toolbar-info">
            Showing <strong><?= ($offset + 1) ?>–<?= min($offset + $per_page, $totalProducts) ?></strong> of <strong><?= number_format($totalProducts) ?></strong> products
        </div>
        <div class="erp-toolbar-actions">
            <button onclick="exportTable('excel')" class="erp-btn erp-btn-outline" title="Export to Excel"><i class="fas fa-file-excel" style="color:#10b981"></i> Excel</button>
            <button onclick="window.print()" class="erp-btn erp-btn-outline" title="Print page"><i class="fas fa-print" style="color:#3b82f6"></i> Print</button>
        </div>
    </div>

    <div id="filterPanel" class="erp-filter-panel no-print">
        <form method="get" id="filterForm">
            <input type="hidden" name="page" value="products">
            <div class="erp-filter-grid">
                <div><label>Category</label><select name="category_id" onchange="this.form.submit()"><option value="">All Categories</option><?php foreach ($allCategories as $cat): ?><option value="<?= (int)$cat['id'] ?>"<?= $catFilter === (int)$cat['id'] ? ' selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option><?php endforeach; ?></select></div>
                <div><label>Supplier</label><select name="supplier_id" onchange="this.form.submit()"><option value="">All Suppliers</option><?php foreach ($allSuppliers as $s): ?><option value="<?= (int)$s['id'] ?>"<?= $supplierFilter === (int)$s['id'] ? ' selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?></select></div>
                <div><label>Status</label><select name="status" onchange="this.form.submit()"><option value="">All</option><option value="active"<?= $statusFilter === 'active' ? ' selected' : '' ?>>Active</option><option value="inactive"<?= $statusFilter === 'inactive' ? ' selected' : '' ?>>Inactive</option><option value="discontinued"<?= $statusFilter === 'discontinued' ? ' selected' : '' ?>>Discontinued</option></select></div>
                <div><label>Stock Level</label><select name="stock_level" onchange="this.form.submit()"><option value="">All</option><option value="normal"<?= $stockLevel === 'normal' ? ' selected' : '' ?>>In Stock</option><option value="low"<?= $stockLevel === 'low' ? ' selected' : '' ?>>Low Stock</option><option value="out"<?= $stockLevel === 'out' ? ' selected' : '' ?>>Out of Stock</option></select></div>
                <div><label>Country</label><input type="text" name="country" value="<?= htmlspecialchars($countryFilter) ?>" placeholder="e.g. India"></div>
                <div style="display:flex;gap:6px;align-items:end;padding-bottom:2px">
                    <button type="submit" class="erp-btn erp-btn-primary" style="height:34px"><i class="fas fa-check"></i> Apply</button>
                    <?php if ($catFilter > 0 || $supplierFilter > 0 || $statusFilter !== '' || $stockLevel !== '' || $countryFilter !== ''): ?>
                        <a href="?page=products" class="erp-btn erp-btn-outline" style="height:34px">Clear</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <div class="erp-table-wrap">
        <table class="erp-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Stock</th>
                    <th>Price</th>
                    <th>Expiry</th>
                    <th>Country</th>
                    <th>Status</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($products)): ?>
                <tr><td colspan="8" style="text-align:center;padding:40px 20px;color:#94a3b8">
                    <i class="fas fa-box-open" style="font-size:2rem;display:block;margin-bottom:8px;color:#cbd5e1"></i>
                    No products found.
                </td></tr>
            <?php else: ?>
                <?php foreach ($products as $product):
                    $pStock = (int)$product['stock_quantity'];
                    $pReorder = (int)($product['reorder_level'] ?? 0);
                    $pStatus = $product['product_status'] ?? 'active';
                    $bi = $batchInfoMap[$product['id']] ?? null;
                    $batchCount = $bi ? (int)$bi['cnt'] : 0;
                    $earliestExpiry = $bi ? $bi['earliest'] : null;
                    $hasExpired = isset($expiredInfoMap[$product['id']]);

                    if ($pStatus === 'inactive') { $sLabel='Inactive'; $sCls='erp-badge-gray'; $sIco='fa-ban'; }
                    elseif ($hasExpired) { $sLabel='Expired'; $sCls='erp-badge-black'; $sIco='fa-clock'; }
                    elseif ($pStock == 0) { $sLabel='Out of Stock'; $sCls='erp-badge-red'; $sIco='fa-times-circle'; }
                    elseif ($pStock <= $pReorder && $pStock > 0) { $sLabel='Low Stock'; $sCls='erp-badge-orange'; $sIco='fa-exclamation-triangle'; }
                    else { $sLabel='In Stock'; $sCls='erp-badge-green'; $sIco='fa-check-circle'; }

                    $flag = countryFlag($product['country_of_origin'] ?? '');
                    $supplierName = !empty($product['supplier_name']) ? htmlspecialchars($product['supplier_name']) : '<span style="color:#94a3b8;font-style:italic">Not Assigned</span>';
                ?>
                <tr>
                    <td>
                        <div class="erp-prod-cell">
                            <?php if (!empty($product['image_path'])): ?>
                                <img src="<?= htmlspecialchars($product['image_path']) ?>" alt="" class="erp-prod-thumb">
                            <?php else: ?>
                                <div class="erp-prod-thumb-placeholder"><i class="fas fa-pills"></i></div>
                            <?php endif; ?>
                            <div>
                                <a href="?page=product_detail&id=<?= (int)$product['id'] ?>" style="text-decoration:none;color:inherit" title="View full product details">
                                    <div class="erp-prod-name"><?= htmlspecialchars($product['name']) ?></div>
                                </a>
                                <?php if (!empty($product['generic_name'])): ?><div class="erp-prod-generic"><?= htmlspecialchars($product['generic_name']) ?></div><?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td><span style="font-family:monospace;font-size:0.78rem;color:#64748b"><?= htmlspecialchars($product['sku'] ?? '--') ?></span></td>
                    <td>
                        <span style="font-weight:600"><?= number_format($pStock) ?></span>
                        <span style="font-size:0.7rem;color:#94a3b8;margin-left:2px"><?= htmlspecialchars($product['unit_of_measure'] ?? 'pcs') ?></span>
                    </td>
                    <td><span style="font-weight:600;color:#1e293b"><?= fmt((float)$product['price']) ?></span></td>
                    <td>
                        <?php if ($earliestExpiry): ?>
                            <?php
                                $daysLeft = (int)ceil((strtotime($earliestExpiry) - time()) / 86400);
                                $expColor = $daysLeft < 0 ? '#dc2626' : ($daysLeft <= 30 ? '#ea580c' : ($daysLeft <= 90 ? '#f59e0b' : '#64748b'));
                            ?>
                            <div style="font-size:0.82rem;color:<?= $expColor ?>;font-weight:<?= $daysLeft <= 30 ? '700' : '500' ?>"><?= htmlspecialchars($earliestExpiry) ?></div>
                            <div style="font-size:0.68rem;color:#94a3b8"><?= $daysLeft < 0 ? abs($daysLeft) . 'd overdue' : $daysLeft . 'd left' ?></div>
                        <?php else: ?>
                            <span style="color:#cbd5e1;font-size:0.82rem">--</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($flag): ?>
                            <span style="font-size:1rem;margin-right:4px"><?= $flag ?></span><span style="font-size:0.8rem"><?= htmlspecialchars($product['country_of_origin'] ?? '') ?></span>
                        <?php else: ?>
                            <span style="color:#94a3b8;font-size:0.82rem">--</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="erp-badge <?= $sCls ?>"><i class="fas <?= $sIco ?>" style="font-size:0.62rem"></i> <?= $sLabel ?></span></td>
                    <td>
                        <div class="erp-actions" style="justify-content:flex-end">
                            <a href="?page=product_detail&id=<?= (int)$product['id'] ?>" class="erp-btn-icon" title="View Product Details"><i class="fas fa-eye"></i></a>
                            <?php if (hasPermission('products', 'edit')): ?>
                                <a href="?page=products&edit=<?= (int)$product['id'] ?>" class="erp-btn-icon" title="Edit Product"><i class="fas fa-pen"></i></a>
                            <?php endif; ?>
                            <?php if (!empty($product['barcode'])): ?>
                                <button class="erp-btn-icon" onclick="showBarcodeModal(<?= htmlspecialchars(json_encode($product['barcode'])) ?>, <?= htmlspecialchars(json_encode($product['qr_code'] ?? '')) ?>)" title="Show Barcode"><i class="fas fa-barcode"></i></button>
                            <?php endif; ?>
                            <?php if ($batchCount > 0): ?>
                                <button class="erp-btn-icon" onclick="showBatchModal(<?= (int)$product['id'] ?>)" title="View <?= $batchCount ?> Batch(es)"><i class="fas fa-layer-group"></i></button>
                            <?php endif; ?>
                            <button class="erp-btn-icon" onclick="showStockHistory(<?= (int)$product['id'] ?>)" title="Stock Movement History"><i class="fas fa-exchange-alt"></i></button>
                            <button class="erp-btn-icon" onclick="showPriceHistory(<?= (int)$product['id'] ?>)" title="Price Change History"><i class="fas fa-tag"></i></button>
                            <?php if (hasPermission('products', 'delete')): ?>
                            <form method="post" style="display:inline" onsubmit="return confirm('Delete this product permanently?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="delete">
                                <input type="hidden" name="delete_id" value="<?= (int)$product['id'] ?>">
                                <button type="submit" class="erp-btn-icon" style="color:#dc2626;border-color:#fecaca" title="Delete Product"><i class="fas fa-trash-alt"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php if ($totalPages > 1): ?>
        <div class="erp-pagination">
            <div class="erp-pagination-size">
                <span>Page</span>
                <select onchange="window.location.href='?page=products&p='+this.value+'&search=<?= urlencode($search) ?>&category_id=<?= $catFilter ?>&supplier_id=<?= $supplierFilter ?>&status=<?= urlencode($statusFilter) ?>&stock_level=<?= urlencode($stockLevel) ?>&country=<?= urlencode($countryFilter) ?>'">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <option value="<?= $i ?>"<?= $i === $page_num ? ' selected' : '' ?>><?= $i ?></option>
                    <?php endfor; ?>
                </select>
                <span>of <?= number_format($totalPages) ?></span>
            </div>
            <div class="erp-pagination-pages">
                <a href="?page=products&p=1&search=<?= urlencode($search) ?>&category_id=<?= $catFilter ?>&supplier_id=<?= $supplierFilter ?>&status=<?= urlencode($statusFilter) ?>&stock_level=<?= urlencode($stockLevel) ?>&country=<?= urlencode($countryFilter) ?>" class="<?= $page_num <= 1 ? 'disabled' : '' ?>" title="First page"><i class="fas fa-angle-double-left"></i></a>
                <a href="?page=products&p=<?= max(1, $page_num - 1) ?>&search=<?= urlencode($search) ?>&category_id=<?= $catFilter ?>&supplier_id=<?= $supplierFilter ?>&status=<?= urlencode($statusFilter) ?>&stock_level=<?= urlencode($stockLevel) ?>&country=<?= urlencode($countryFilter) ?>" class="<?= $page_num <= 1 ? 'disabled' : '' ?>" title="Previous page"><i class="fas fa-angle-left"></i></a>
                <?php
                $startP = max(1, $page_num - 2);
                $endP = min($totalPages, $page_num + 2);
                if ($startP > 1): ?><span style="color:#94a3b8">...</span><?php endif;
                for ($i = $startP; $i <= $endP; $i++):
                ?>
                    <a href="?page=products&p=<?= $i ?>&search=<?= urlencode($search) ?>&category_id=<?= $catFilter ?>&supplier_id=<?= $supplierFilter ?>&status=<?= urlencode($statusFilter) ?>&stock_level=<?= urlencode($stockLevel) ?>&country=<?= urlencode($countryFilter) ?>" class="<?= $i === $page_num ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor;
                if ($endP < $totalPages): ?><span style="color:#94a3b8">...</span><?php endif;
                ?>
                <a href="?page=products&p=<?= min($totalPages, $page_num + 1) ?>&search=<?= urlencode($search) ?>&category_id=<?= $catFilter ?>&supplier_id=<?= $supplierFilter ?>&status=<?= urlencode($statusFilter) ?>&stock_level=<?= urlencode($stockLevel) ?>&country=<?= urlencode($countryFilter) ?>" class="<?= $page_num >= $totalPages ? 'disabled' : '' ?>" title="Next page"><i class="fas fa-angle-right"></i></a>
                <a href="?page=products&p=<?= $totalPages ?>&search=<?= urlencode($search) ?>&category_id=<?= $catFilter ?>&supplier_id=<?= $supplierFilter ?>&status=<?= urlencode($statusFilter) ?>&stock_level=<?= urlencode($stockLevel) ?>&country=<?= urlencode($countryFilter) ?>" class="<?= $page_num >= $totalPages ? 'disabled' : '' ?>" title="Last page"><i class="fas fa-angle-double-right"></i></a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php if (hasPermission('products', 'create')): ?>
<a href="?page=products&add=1" class="erp-fab" id="addFabBtn" title="Add New Product"><i class="fas fa-plus"></i></a>
<?php endif; ?>

<div id="batch-modal" class="erp-modal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="erp-modal-box">
        <button class="erp-modal-close" onclick="document.getElementById('batch-modal').classList.remove('open')"><i class="fas fa-times"></i></button>
        <div class="erp-modal-title"><i class="fas fa-layer-group" style="color:#7c3aed"></i> Batch Details</div>
        <div id="batch-modal-content" style="text-align:center;padding:20px;color:#94a3b8">Loading...</div>
    </div>
</div>
<div id="stock-modal" class="erp-modal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="erp-modal-box">
        <button class="erp-modal-close" onclick="document.getElementById('stock-modal').classList.remove('open')"><i class="fas fa-times"></i></button>
        <div class="erp-modal-title"><i class="fas fa-exchange-alt" style="color:#7c3aed"></i> Stock Movement History</div>
        <div id="stock-modal-content" style="text-align:center;padding:20px;color:#94a3b8">Loading...</div>
    </div>
</div>
<div id="price-modal" class="erp-modal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="erp-modal-box">
        <button class="erp-modal-close" onclick="document.getElementById('price-modal').classList.remove('open')"><i class="fas fa-times"></i></button>
        <div class="erp-modal-title"><i class="fas fa-tag" style="color:#f59e0b"></i> Price History</div>
        <div id="price-modal-content" style="text-align:center;padding:20px;color:#94a3b8">Loading...</div>
    </div>
</div>
<div id="barcode-modal" class="erp-modal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="erp-modal-box" style="max-width:380px;text-align:center">
        <button class="erp-modal-close" onclick="document.getElementById('barcode-modal').classList.remove('open')"><i class="fas fa-times"></i></button>
        <div class="erp-modal-title" style="justify-content:center"><i class="fas fa-barcode" style="color:#3b82f6"></i> Product Barcode</div>
        <div id="barcode-modal-content"></div>
    </div>
</div>

<?php if (hasPermission('products', 'create')): ?>
<div id="add-modal" class="erp-modal <?= $showAddModal ? 'open' : '' ?>" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="erp-modal-box" style="max-width:620px">
        <button class="erp-modal-close" onclick="window.location.href='?page=products'"><i class="fas fa-times"></i></button>
        <div class="erp-modal-title"><i class="fas fa-plus-circle" style="color:#10b981"></i> Add New Product</div>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="create">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <label style="font-size:0.78rem;color:#64748b;font-weight:600">Product Name *<input type="text" name="name" required style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:0.85rem;margin-top:3px"></label>
                <label style="font-size:0.78rem;color:#64748b;font-weight:600">Brand<input type="text" name="brand" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:0.85rem;margin-top:3px"></label>
                <label style="font-size:0.78rem;color:#64748b;font-weight:600">Generic Name<input type="text" name="generic_name" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:0.85rem;margin-top:3px"></label>
                <label style="font-size:0.78rem;color:#64748b;font-weight:600">Dosage Form<select name="dosage_form" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:0.85rem;margin-top:3px"><option value="">Select...</option><?php foreach (['Tablet','Capsule','Syrup','Suspension','Injection','Ointment','Cream','Drops','Inhaler','Spray','Powder','Granules','Solution','Suppository','Patch'] as $df): ?><option value="<?= $df ?>"><?= $df ?></option><?php endforeach; ?></select></label>
                <label style="font-size:0.78rem;color:#64748b;font-weight:600">Strength<input type="text" name="strength" placeholder="e.g. 500mg" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:0.85rem;margin-top:3px"></label>
                <label style="font-size:0.78rem;color:#64748b;font-weight:600">Pack Size<input type="text" name="pack_size" placeholder="e.g. 10x10" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:0.85rem;margin-top:3px"></label>
                <label style="font-size:0.78rem;color:#64748b;font-weight:600">Category<select name="category_id" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:0.85rem;margin-top:3px"><option value="">No category</option><?php foreach ($allCategories as $cat): ?><option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option><?php endforeach; ?></select></label>
                <label style="font-size:0.78rem;color:#64748b;font-weight:600">Supplier<select name="supplier_id" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:0.85rem;margin-top:3px"><option value="">No supplier</option><?php foreach ($allSuppliers as $sup): ?><option value="<?= (int)$sup['id'] ?>"><?= htmlspecialchars($sup['name']) ?></option><?php endforeach; ?></select></label>
                <label style="font-size:0.78rem;color:#64748b;font-weight:600">Photo<input type="file" name="image" accept="image/*" style="width:100%;font-size:0.85rem;margin-top:3px"></label>
                <label style="font-size:0.78rem;color:#64748b;font-weight:600">Barcode<input type="text" name="barcode" placeholder="UPC / EAN" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:0.85rem;margin-top:3px"></label>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px">
                <label style="font-size:0.78rem;color:#64748b;font-weight:600">Purchase Price (<?= SettingsService::currencySymbol() ?>) *<input type="number" step="0.01" min="0" name="purchase_price" value="0" id="add-purchase-price" required style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:0.85rem;margin-top:3px"></label>
                <label style="font-size:0.78rem;color:#64748b;font-weight:600">Stock<input type="number" name="stock" value="0" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:0.85rem;margin-top:3px"></label>
            </div>
            <div style="margin-top:10px;padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:0.82rem">
                <div style="font-weight:700;color:#1e293b;margin-bottom:8px"><i class="fas fa-calculator" style="color:#7c3aed;margin-right:4px"></i> Calculated Prices (read-only)</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 16px">
                    <div><span style="color:#94a3b8">Global Selling Markup</span><br><strong><?= htmlspecialchars((string) $pricingSelling) ?>%</strong></div>
                    <div><span style="color:#94a3b8">Calculated Selling Price</span><br><strong style="color:#16a34a" id="add-calc-price"><?= SettingsService::currencySymbol() ?> 0.00</strong></div>
                    <div><span style="color:#94a3b8">Global Wholesale Markup</span><br><strong><?= htmlspecialchars((string) $pricingWholesale) ?>%</strong></div>
                    <div><span style="color:#94a3b8">Calculated Wholesale Price</span><br><strong style="color:#7c3aed" id="add-calc-wholesale"><?= SettingsService::currencySymbol() ?> 0.00</strong></div>
                </div>
            </div>
            <label style="font-size:0.78rem;color:#64748b;font-weight:600;display:block;margin-top:10px">Description<textarea name="description" rows="2" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:0.85rem;margin-top:3px;resize:vertical"></textarea></label>
            <hr style="border:none;border-top:1px solid #e2e8f0;margin:12px 0">
            <p style="font-size:0.78rem;color:#94a3b8;font-weight:600;margin:0 0 8px">Initial Batch (optional)</p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <label style="font-size:0.78rem;color:#64748b;font-weight:600">Batch #<input type="text" name="batch_number" placeholder="e.g. BATCH001" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:0.85rem;margin-top:3px"></label>
                <label style="font-size:0.78rem;color:#64748b;font-weight:600">Expiry Date<input type="date" name="expiry_date" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:0.85rem;margin-top:3px"></label>
            </div>
            <div style="display:flex;gap:8px;margin-top:16px;justify-content:flex-end">
                <a href="?page=products" style="padding:9px 18px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.85rem;color:#64748b;text-decoration:none;font-weight:600">Cancel</a>
                <button type="submit" class="erp-btn erp-btn-success"><i class="fas fa-save"></i> Create Product</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function showBatchModal(pid){
    var m=document.getElementById('batch-modal'),c=document.getElementById('batch-modal-content');
    m.classList.add('open');
    c.innerHTML='<div style="text-align:center;padding:20px;color:#94a3b8"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
    fetch('?page=products&batch_data='+pid).then(function(r){return r.json();}).then(function(d){
        if(!d.length){c.innerHTML='<p style="color:#94a3b8;text-align:center;padding:20px">No batches found.</p>';return;}
        var h='<table style="width:100%;border-collapse:collapse;font-size:0.82rem"><thead><tr style="background:#f8fafc"><th style="padding:8px 12px;text-align:left;border-bottom:1px solid #e2e8f0">Batch #</th><th style="padding:8px 12px;text-align:left">Expiry</th><th style="padding:8px 12px;text-align:left">Qty</th><th style="padding:8px 12px;text-align:left">Purchase Price</th><th style="padding:8px 12px;text-align:left">Status</th></tr></thead><tbody>';
        d.forEach(function(b){
            var dl=parseInt(b.days_left)||0;var st,bg,cl;
            if(dl<0){st='Expired';bg='#dc2626';cl='#fff';}else if(dl<=30){st=dl+' days left';bg='#fef2f2';cl='#dc2626';}else if(dl<=90){st=dl+' days left';bg='#fff7ed';cl='#ea580c';}else{st='Active';bg='#f0fdf4';cl='#16a34a';}
            h+='<tr style="border-bottom:1px solid #f1f5f9"><td style="padding:8px 12px;font-weight:600">'+esc(b.batch_number)+'</td><td style="padding:8px 12px">'+esc(b.expiry_date)+'</td><td style="padding:8px 12px;font-weight:600">'+b.quantity+'</td><td style="padding:8px 12px">'+esc(parseFloat(b.purchase_price).toFixed(2))+'</td><td style="padding:8px 12px"><span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:600;background:'+bg+';color:'+cl+'">'+st+'</span></td></tr>';
        });
        h+='</tbody></table>';c.innerHTML=h;
    }).catch(function(){c.innerHTML='<p style="color:#dc2626">Failed to load batch data.</p>';});
}

function showStockHistory(pid){
    var m=document.getElementById('stock-modal'),c=document.getElementById('stock-modal-content');
    m.classList.add('open');
    c.innerHTML='<div style="text-align:center;padding:20px"><i class="fas fa-spinner fa-spin"></i></div>';
    fetch('?page=products&stock_history='+pid).then(function(r){return r.json();}).then(function(d){
        if(!d.length){c.innerHTML='<p style="color:#94a3b8;text-align:center">No stock movements recorded.</p>';return;}
        var h='<table style="width:100%;border-collapse:collapse;font-size:0.82rem"><thead><tr style="background:#f8fafc"><th style="padding:8px 12px;text-align:left">Date</th><th style="padding:8px 12px;text-align:left">Change</th><th style="padding:8px 12px;text-align:left">Reason</th><th style="padding:8px 12px;text-align:left">By</th></tr></thead><tbody>';
        d.forEach(function(r){
            var ch=parseInt(r.quantity_change);var cl=ch>=0?'#16a34a':'#dc2626';
            h+='<tr style="border-bottom:1px solid #f1f5f9"><td style="padding:8px 12px">'+esc(r.created_at)+'</td><td style="padding:8px 12px;font-weight:700;color:'+cl+'">'+(ch>=0?'+':'')+ch+'</td><td style="padding:8px 12px">'+esc(r.reason)+'</td><td style="padding:8px 12px">'+esc(r.user_name||'System')+'</td></tr>';
        });
        h+='</tbody></table>';c.innerHTML=h;
    }).catch(function(){c.innerHTML='<p style="color:#dc2626">Failed to load history.</p>';});
}

function showPriceHistory(pid){
    var m=document.getElementById('price-modal'),c=document.getElementById('price-modal-content');
    m.classList.add('open');
    c.innerHTML='<div style="text-align:center;padding:20px"><i class="fas fa-spinner fa-spin"></i></div>';
    fetch('?page=products&price_history='+pid).then(function(r){return r.json();}).then(function(d){
        if(!d.length){c.innerHTML='<p style="color:#94a3b8;text-align:center">No price changes recorded.</p>';return;}
        var h='<table style="width:100%;border-collapse:collapse;font-size:0.82rem"><thead><tr style="background:#f8fafc"><th style="padding:8px 12px;text-align:left">Date</th><th style="padding:8px 12px;text-align:left">Old Price</th><th style="padding:8px 12px;text-align:left">New Price</th><th style="padding:8px 12px;text-align:left">Changed By</th></tr></thead><tbody>';
        d.forEach(function(r){
            h+='<tr style="border-bottom:1px solid #f1f5f9"><td style="padding:8px 12px">'+esc(r.created_at)+'</td><td style="padding:8px 12px">'+esc(parseFloat(r.old_price).toFixed(2))+'</td><td style="padding:8px 12px;font-weight:700">'+esc(parseFloat(r.new_price).toFixed(2))+'</td><td style="padding:8px 12px">'+esc(r.user_name||'System')+'</td></tr>';
        });
        h+='</tbody></table>';c.innerHTML=h;
    }).catch(function(){c.innerHTML='<p style="color:#dc2626">Failed to load history.</p>';});
}

function showBarcodeModal(code, qr){
    var m=document.getElementById('barcode-modal'),c=document.getElementById('barcode-modal-content');
    var h='<div style="padding:20px 0">';
    h+='<div style="font-family:monospace;font-size:1.4rem;font-weight:700;color:#1e293b;letter-spacing:2px;margin-bottom:12px">'+esc(code)+'</div>';
    h+='<svg class="barcode-render" data-barcode="'+esc(code)+'" style="max-width:260px;margin:0 auto 12px;display:block"></svg>';
    if(qr){h+='<img src="'+esc(qr)+'" style="width:120px;height:120px;border-radius:8px;border:1px solid #e2e8f0;margin:8px auto;display:block" alt="QR Code">';}
    h+='</div>';
    c.innerHTML=h;
    m.classList.add('open');
    if(typeof JsBarcode!=='undefined'&&c.querySelector('.barcode-render')){
        try{JsBarcode(c.querySelector('.barcode-render'),code,{format:'CODE128',width:1.5,height:40,fontSize:12,margin:8});}catch(e){}
    }
}

function esc(s){var d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}

document.getElementById('liveSearch').addEventListener('keydown',function(e){
    if(e.key==='Enter'){e.preventDefault();var v=this.value.trim();window.location.href='?page=products&search='+encodeURIComponent(v)+'&category_id=<?= $catFilter ?>&supplier_id=<?= $supplierFilter ?>&status=<?= urlencode($statusFilter) ?>&stock_level=<?= urlencode($stockLevel) ?>&country=<?= urlencode($countryFilter) ?>';}
});

var _searchTimer;
document.getElementById('liveSearch').addEventListener('input',function(){
    clearTimeout(_searchTimer);
    var self=this;
    _searchTimer=setTimeout(function(){
        var v=self.value.trim();
        if(v.length>=2||v.length===0){window.location.href='?page=products&search='+encodeURIComponent(v)+'&category_id=<?= $catFilter ?>&supplier_id=<?= $supplierFilter ?>&status=<?= urlencode($statusFilter) ?>&stock_level=<?= urlencode($stockLevel) ?>&country=<?= urlencode($countryFilter) ?>';}
    },500);
});

function exportTable(type){
    var t=document.querySelector('.erp-table');
    if(!t)return;
    if(type==='excel'){
        var h=t.outerHTML.replace(/<img[^>]*>/gi,'').replace(/<svg[^>]*>.*?<\/svg>/gi,'');
        var b=new Blob(['<html><head><meta charset="utf-8"></head><body>'+h+'</body></html>'],{type:'application/vnd.ms-excel'});
        var a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='products_'+new Date().toISOString().slice(0,10)+'.xls';a.click();
    }
}

document.addEventListener('keydown',function(e){if(e.key==='Escape'){document.querySelectorAll('.erp-modal.open').forEach(function(m){m.classList.remove('open');});}});

(function(){
    var pp=document.getElementById('add-purchase-price');
    if(!pp)return;
    var sellMk=<?= (float) $pricingSelling ?>, whMk=<?= (float) $pricingWholesale ?>, sym='<?= SettingsService::currencySymbol() ?>';
    var show=function(){
        var v=parseFloat(pp.value)||0;
        document.getElementById('add-calc-price').textContent=sym+' '+(v*(1+sellMk/100)).toFixed(2);
        document.getElementById('add-calc-wholesale').textContent=sym+' '+(v*(1+whMk/100)).toFixed(2);
    };
    pp.addEventListener('input',show);
    show();
})();
</script>
