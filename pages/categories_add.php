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

$editCat = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM categories WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int) $_GET['edit']]);
    $editCat = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['_action'] ?? 'create';

    if ($action === 'update') {
        requirePermission('categories', 'edit');
        $catId = (int) ($_POST['category_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $status = in_array($_POST['status'] ?? 'active', ['active', 'inactive'], true) ? $_POST['status'] : 'active';
        $color = trim($_POST['color'] ?? '#2563eb');
        $icon = trim($_POST['icon'] ?? 'fa-tag');
        $parentId = ($_POST['parent_id'] ?? '') !== '' ? (int) $_POST['parent_id'] : null;
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        if ($catId <= 0 || $name === '') {
            $messages[] = ['type' => 'error', 'text' => 'Category name is required.'];
        } else {
            if ($slug === '') $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
            try {
                $pdo->prepare('UPDATE categories SET name = :name, description = :desc, slug = :slug, status = :status, color = :color, icon = :icon, parent_id = :parent_id, sort_order = :sort_order, updated_at = NOW() WHERE id = :id')
                    ->execute(['name' => $name, 'desc' => $desc, 'slug' => $slug, 'status' => $status, 'color' => $color, 'icon' => $icon, 'parent_id' => $parentId, 'sort_order' => $sortOrder, 'id' => $catId]);
                redirect_with_flash('?page=categories', 'Category updated.');
            } catch (PDOException $e) {
                $messages[] = ['type' => 'error', 'text' => 'Name or slug already taken.'];
            } catch (Throwable $e) {
                $messages[] = ['type' => 'error', 'text' => 'Update failed: ' . $e->getMessage()];
            }
        }
    } else {
        requirePermission('categories', 'create');
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $status = in_array($_POST['status'] ?? 'active', ['active', 'inactive'], true) ? $_POST['status'] : 'active';
        $color = trim($_POST['color'] ?? '#2563eb');
        $icon = trim($_POST['icon'] ?? 'fa-tag');
        $parentId = ($_POST['parent_id'] ?? '') !== '' ? (int) $_POST['parent_id'] : null;
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        if ($name === '') {
            $messages[] = ['type' => 'error', 'text' => 'Category name is required.'];
        } else {
            if ($slug === '') $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
            try {
                $pdo->prepare('INSERT INTO categories (name, description, slug, status, color, icon, parent_id, sort_order, created_at) VALUES (:name, :desc, :slug, :status, :color, :icon, :parent_id, :sort_order, NOW())')
                    ->execute(['name' => $name, 'desc' => $desc, 'slug' => $slug, 'status' => $status, 'color' => $color, 'icon' => $icon, 'parent_id' => $parentId, 'sort_order' => $sortOrder]);
                redirect_with_flash('?page=categories', 'Category added.');
            } catch (PDOException $e) {
                $messages[] = ['type' => 'error', 'text' => 'Name or slug already exists.'];
            } catch (Throwable $e) {
                $messages[] = ['type' => 'error', 'text' => 'Create failed: ' . $e->getMessage()];
            }
        }
    }
}

$allCategories = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
?>
<style>
.cat-wrap{max-width:720px;margin:0 auto}
.cat-header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px}
.cat-header h1{font-size:1.5rem;font-weight:800;color:#0f172a;margin:0;display:flex;align-items:center;gap:10px}
.cat-panel{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.cat-panel h2{font-size:1rem;font-weight:700;color:#0f172a;margin:0 0 14px;display:flex;align-items:center;gap:8px}
.cat-form label{display:block;font-size:.8rem;font-weight:600;color:#475569;margin-bottom:4px}
.cat-form input,.cat-form select,.cat-form textarea{width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;font-family:inherit;box-sizing:border-box}
.cat-form textarea{resize:vertical;min-height:60px}
.cat-form .row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.cat-form .actions{display:flex;gap:8px;margin-top:14px;flex-wrap:wrap}
.cat-btn{padding:9px 16px;border:none;border-radius:8px;font-size:.82rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-family:inherit;text-decoration:none}
.cat-btn-primary{background:#2563eb;color:#fff}
.cat-btn-success{background:#059669;color:#fff}
.cat-btn-outline{background:transparent;border:1px solid #cbd5e1;color:#475569}
</style>

<section class="cat-wrap">
    <div class="cat-header">
        <h1><i class="fas fa-<?= $editCat ? 'edit' : 'plus-circle' ?>"></i> <?= $editCat ? 'Edit Category' : 'Add Category' ?></h1>
        <a href="?page=categories" class="cat-btn cat-btn-outline"><i class="fas fa-arrow-left"></i> Back to List</a>
    </div>

    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>" style="margin-bottom:12px"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <div class="cat-panel">
        <h2><i class="fas fa-tag"></i> Category Details</h2>
        <form method="post" class="cat-form" data-swal-exclude="true">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="<?= $editCat ? 'update' : 'create' ?>">
            <?php if ($editCat): ?><input type="hidden" name="category_id" value="<?= htmlspecialchars((string) $editCat['id']) ?>"><?php endif; ?>

            <label>Name *
                <input type="text" name="name" id="cat-name" required placeholder="e.g. Pharmaceuticals" value="<?= htmlspecialchars($editCat['name'] ?? '') ?>" oninput="autoSlug(this.value)">
            </label>
            <label>Slug
                <input type="text" name="slug" id="cat-slug" placeholder="auto-generated" value="<?= htmlspecialchars($editCat['slug'] ?? '') ?>">
            </label>
            <label>Description
                <textarea name="description" placeholder="Optional description"><?= htmlspecialchars((string) ($editCat['description'] ?? '')) ?></textarea>
            </label>
            <div class="row">
                <label>Parent Category
                    <select name="parent_id">
                        <option value="">None (Top-level)</option>
                        <?php foreach ($allCategories as $c): if ($editCat && (int)$c['id'] === (int)$editCat['id']) continue; ?>
                            <option value="<?= (int)$c['id'] ?>" <?= isset($editCat['parent_id']) && (int)$editCat['parent_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Status
                    <select name="status">
                        <option value="active" <?= ($editCat['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= ($editCat['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </label>
            </div>
            <div class="row">
                <label>Color
                    <input type="color" name="color" value="<?= htmlspecialchars($editCat['color'] ?? '#2563eb') ?>" style="height:40px;padding:4px">
                </label>
                <label>Icon (FontAwesome)
                    <input type="text" name="icon" placeholder="fa-tag" value="<?= htmlspecialchars($editCat['icon'] ?? 'fa-tag') ?>">
                </label>
            </div>
            <label>Sort Order
                <input type="number" name="sort_order" value="<?= htmlspecialchars((string) ($editCat['sort_order'] ?? 0)) ?>">
            </label>

            <div class="actions">
                <button type="submit" class="cat-btn <?= $editCat ? 'cat-btn-success' : 'cat-btn-primary' ?>">
                    <i class="fas fa-<?= $editCat ? 'save' : 'plus' ?>"></i> <?= $editCat ? 'Update Category' : 'Create Category' ?>
                </button>
                <a href="?page=categories" class="cat-btn cat-btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</section>

<script>
function autoSlug(val) {
    var slug = val.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    var el = document.getElementById('cat-slug');
    if (el && el.value === '') el.value = slug;
}
</script>