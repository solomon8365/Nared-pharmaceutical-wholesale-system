<?php
requirePermission('categories', 'view');
$pdo = getPDO();
$messages = [];

// ─── Ensure extended category schema (idempotent) ─────────────
try {
    $hasCol = function (string $col) use ($pdo): bool {
        $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $s->execute(['categories', $col]);
        return ((int) $s->fetchColumn()) > 0;
    };
    $addCol = function (string $col, string $def) use ($pdo, $hasCol): void {
        if (!$hasCol($col)) {
            try { $pdo->exec("ALTER TABLE categories ADD COLUMN `$col` $def"); } catch (Throwable $e) { /* ignore */ }
        }
    };
    $addCol('slug',        'VARCHAR(160) NULL');
    $addCol('status',      "VARCHAR(20) NOT NULL DEFAULT 'active'");
    $addCol('color',       "VARCHAR(20) NULL DEFAULT '#2563eb'");
    $addCol('icon',        "VARCHAR(50) NULL DEFAULT 'fa-tag'");
    $addCol('parent_id',   'INT(10) UNSIGNED NULL');
    $addCol('sort_order',  'INT NOT NULL DEFAULT 0');
    $addCol('updated_at',  'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');
    try { $pdo->exec("UPDATE categories SET slug = LOWER(REPLACE(REPLACE(name, ' ', '-'), '--', '-')) WHERE slug IS NULL OR slug = ''"); } catch (Throwable $e) { /* ignore */ }
    try { $pdo->exec("UPDATE categories SET status = 'active' WHERE status IS NULL OR status = ''"); } catch (Throwable $e) { /* ignore */ }
} catch (Throwable $e) { /* never block page */ }

// Handle delete / toggle from list
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['_action'] ?? '';
    if ($action === 'delete') {
        requirePermission('categories', 'delete');
        $deleteId = (int) ($_POST['delete_id'] ?? 0);
        if ($deleteId > 0) {
            $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id = :id');
            $cntStmt->execute(['id' => $deleteId]);
            $pc = (int) $cntStmt->fetchColumn();
            if ($pc > 0) {
                $messages[] = ['type' => 'error', 'text' => "Cannot delete: {$pc} product(s) are assigned to this category. Reassign them first."];
            } else {
                $pdo->prepare('DELETE FROM categories WHERE id = :id')->execute(['id' => $deleteId]);
                $messages[] = ['type' => 'success', 'text' => 'Category deleted.'];
            }
        }
    } elseif ($action === 'toggle_status') {
        requirePermission('categories', 'edit');
        $catId = (int) ($_POST['category_id'] ?? 0);
        $newStatus = ($_POST['status'] ?? '') === 'active' ? 'inactive' : 'active';
        if ($catId > 0) {
            try {
                $pdo->prepare('UPDATE categories SET status = :s, updated_at = NOW() WHERE id = :id')->execute(['s' => $newStatus, 'id' => $catId]);
                $messages[] = ['type' => 'success', 'text' => 'Category status updated.'];
            } catch (Throwable $e) {
                $messages[] = ['type' => 'error', 'text' => 'Could not update status.'];
            }
        }
    }
}

$search = trim($_GET['q'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$sql = 'SELECT c.*, (SELECT COUNT(*) FROM products WHERE category_id = c.id) AS product_count,
        (SELECT name FROM categories p WHERE p.id = c.parent_id) AS parent_name
        FROM categories c WHERE 1=1';
$params = [];
if ($search !== '') { $sql .= ' AND (c.name LIKE :q1 OR c.description LIKE :q2)'; $params[':q1'] = '%' . $search . '%'; $params[':q2'] = '%' . $search . '%'; }
if ($statusFilter !== '' && in_array($statusFilter, ['active', 'inactive'], true)) { $sql .= ' AND c.status = :st'; $params[':st'] = $statusFilter; }
$sql .= ' ORDER BY c.sort_order ASC, c.name ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$categories = $stmt->fetchAll();

$totalCats = count($categories);
$activeCats = count(array_filter($categories, fn($c) => ($c['status'] ?? 'active') === 'active'));
$totalProducts = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
?>
<style>
.cat-wrap{max-width:1200px;margin:0 auto}
.cat-header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px}
.cat-header h1{font-size:1.5rem;font-weight:800;color:#0f172a;margin:0;display:flex;align-items:center;gap:10px}
.cat-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:20px}
.cat-stat{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.cat-stat-val{font-size:1.4rem;font-weight:800;color:#0f172a}
.cat-stat-label{font-size:.75rem;color:#64748b;margin-top:2px}
.cat-panel{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.cat-panel h2{font-size:1rem;font-weight:700;color:#0f172a;margin:0 0 14px;display:flex;align-items:center;gap:8px}
.cat-table{width:100%;border-collapse:separate;border-spacing:0;font-size:.84rem}
.cat-table th{text-align:left;padding:10px 12px;background:#f8fafc;font-weight:700;color:#475569;border-bottom:2px solid #e2e8f0;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em}
.cat-table td{padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.cat-table tbody tr:hover{background:#f8fafc}
.cat-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:.7rem;font-weight:600}
.cat-badge-active{background:#dcfce7;color:#166534}
.cat-badge-inactive{background:#f1f5f9;color:#64748b}
.cat-color-dot{width:14px;height:14px;border-radius:50%;display:inline-block;border:1px solid rgba(0,0,0,.1);vertical-align:middle}
.cat-search{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
.cat-search input{padding:8px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;font-family:inherit;min-width:200px;flex:1}
.cat-search select{padding:8px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;font-family:inherit}
.cat-empty{text-align:center;padding:30px;color:#94a3b8}
.cat-empty i{font-size:2rem;color:#cbd5e1;display:block;margin-bottom:8px}
.cat-btn{padding:9px 16px;border:none;border-radius:8px;font-size:.82rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-family:inherit;text-decoration:none}
.cat-btn-primary{background:#2563eb;color:#fff}
.cat-btn-outline{background:transparent;border:1px solid #cbd5e1;color:#475569}
.cat-btn-danger{background:#dc2626;color:#fff}
.cat-btn-sm{padding:4px 10px;font-size:.75rem}
</style>

<section class="cat-wrap">
    <div class="cat-header">
        <h1><i class="fas fa-tags" style="color:#2563eb"></i> Categories</h1>
        <?php if (hasPermission('categories', 'create')): ?>
            <a href="?page=categories_add" class="cat-btn cat-btn-primary"><i class="fas fa-plus"></i> Add Category</a>
        <?php endif; ?>
    </div>

    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>" style="margin-bottom:12px"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <div class="cat-stats">
        <div class="cat-stat"><div class="cat-stat-val"><?= $totalCats ?></div><div class="cat-stat-label">Total Categories</div></div>
        <div class="cat-stat"><div class="cat-stat-val"><?= $activeCats ?></div><div class="cat-stat-label">Active Categories</div></div>
        <div class="cat-stat"><div class="cat-stat-val"><?= $totalProducts ?></div><div class="cat-stat-label">Total Products</div></div>
        <div class="cat-stat"><div class="cat-stat-val"><?= $totalCats > 0 ? round($totalProducts / max($totalCats, 1), 1) : 0 ?></div><div class="cat-stat-label">Avg Products / Category</div></div>
    </div>

    <div class="cat-panel">
        <h2><i class="fas fa-list"></i> Category List</h2>
        <form method="get" class="cat-search">
            <input type="hidden" name="page" value="categories">
            <input type="text" name="q" placeholder="Search categories..." value="<?= htmlspecialchars($search) ?>">
            <select name="status">
                <option value="">All statuses</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
            <button type="submit" class="cat-btn cat-btn-primary cat-btn-sm"><i class="fas fa-search"></i> Search</button>
        </form>

        <?php if (empty($categories)): ?>
            <div class="cat-empty"><i class="fas fa-inbox"></i>No categories found.</div>
        <?php else: ?>
            <div style="overflow-x:auto">
            <table class="cat-table">
                <thead><tr><th></th><th>Name</th><th>Slug</th><th>Parent</th><th>Products</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($categories as $cat): ?>
                    <tr>
                        <td><span class="cat-color-dot" style="background:<?= htmlspecialchars($cat['color'] ?? '#2563eb') ?>"></span> <i class="fas <?= htmlspecialchars($cat['icon'] ?? 'fa-tag') ?>" style="color:<?= htmlspecialchars($cat['color'] ?? '#2563eb') ?>"></i></td>
                        <td><strong><?= htmlspecialchars($cat['name']) ?></strong><?php if (!empty($cat['description'])): ?><br><span style="font-size:.75rem;color:#64748b"><?= htmlspecialchars(mb_substr($cat['description'], 0, 60)) ?><?= mb_strlen($cat['description']) > 60 ? '…' : '' ?></span><?php endif; ?></td>
                        <td style="font-size:.78rem;color:#64748b"><?= htmlspecialchars($cat['slug'] ?? '—') ?></td>
                        <td style="font-size:.78rem"><?= htmlspecialchars($cat['parent_name'] ?? '—') ?></td>
                        <td><span class="cat-badge" style="background:#e0e7ff;color:#3730a3"><?= (int) $cat['product_count'] ?></span></td>
                        <td>
                            <?php if (hasPermission('categories', 'edit')): ?>
                                <form method="post" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="toggle_status">
                                    <input type="hidden" name="category_id" value="<?= (int) $cat['id'] ?>">
                                    <input type="hidden" name="status" value="<?= htmlspecialchars($cat['status'] ?? 'active') ?>">
                                    <button type="submit" class="cat-badge <?= ($cat['status'] ?? 'active') === 'active' ? 'cat-badge-active' : 'cat-badge-inactive' ?>" style="border:none;cursor:pointer"><?= ucfirst($cat['status'] ?? 'active') ?></button>
                                </form>
                            <?php else: ?>
                                <span class="cat-badge <?= ($cat['status'] ?? 'active') === 'active' ? 'cat-badge-active' : 'cat-badge-inactive' ?>"><?= ucfirst($cat['status'] ?? 'active') ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap">
                            <?php if (hasPermission('categories', 'edit')): ?>
                                <a href="?page=categories_add&edit=<?= (int) $cat['id'] ?>" class="cat-btn cat-btn-outline cat-btn-sm"><i class="fas fa-edit"></i> Edit</a>
                            <?php endif; ?>
                            <?php if (hasPermission('categories', 'delete')): ?>
                                <form method="post" style="display:inline" onsubmit="return confirm('Delete this category?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="delete">
                                    <input type="hidden" name="delete_id" value="<?= (int) $cat['id'] ?>">
                                    <button type="submit" class="cat-btn cat-btn-danger cat-btn-sm"><i class="fas fa-trash"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>
</section>