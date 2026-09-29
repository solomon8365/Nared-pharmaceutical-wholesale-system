<?php
requirePermission('categories', 'view');
$pdo = getPDO();
$messages = [];

$editCat = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM categories WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int) $_GET['edit']]);
    $editCat = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['_action'] ?? 'create';

    if ($action === 'delete') {
        requirePermission('categories', 'delete');
        $deleteId = (int) ($_POST['delete_id'] ?? 0);
        if ($deleteId > 0) {
            $pdo->prepare('DELETE FROM categories WHERE id = :id')->execute(['id' => $deleteId]);
            $messages[] = ['type' => 'success', 'text' => 'Category deleted.'];
        }
    } elseif ($action === 'update') {
        requirePermission('categories', 'edit');
        $catId = (int) ($_POST['category_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($catId <= 0 || $name === '') {
            $messages[] = ['type' => 'error', 'text' => 'Category name is required.'];
        } else {
            try {
                $pdo->prepare('UPDATE categories SET name = :name, description = :desc WHERE id = :id')->execute(['name' => $name, 'desc' => $desc, 'id' => $catId]);
                $messages[] = ['type' => 'success', 'text' => 'Category updated.'];
            } catch (PDOException $e) {
                $messages[] = ['type' => 'error', 'text' => 'Name already taken.'];
            }
        }
    } else {
        requirePermission('categories', 'create');
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($name === '') {
            $messages[] = ['type' => 'error', 'text' => 'Category name is required.'];
        } else {
            try {
                $pdo->prepare('INSERT INTO categories (name, description, created_at) VALUES (:name, :desc, NOW())')->execute(['name' => $name, 'desc' => $desc]);
                $messages[] = ['type' => 'success', 'text' => 'Category added.'];
            } catch (PDOException $e) {
                $messages[] = ['type' => 'error', 'text' => 'Name already exists.'];
            }
        }
    }
}

$categories = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM products WHERE category_id = c.id) AS product_count FROM categories c ORDER BY c.name')->fetchAll();
?>
<section>
    <h1>Categories</h1>
    <?php foreach ($messages as $message): ?>
        <div class="alert alert-<?= htmlspecialchars($message['type']) ?>"><?= htmlspecialchars($message['text']) ?></div>
    <?php endforeach; ?>

    <?php if ($editCat && hasPermission('categories', 'edit')): ?>
        <div class="panel" style="max-width:500px;margin-bottom:16px">
            <h2>Edit category</h2>
            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="update">
                <input type="hidden" name="category_id" value="<?= htmlspecialchars((string) $editCat['id']) ?>">
                <label>Name<input type="text" name="name" value="<?= htmlspecialchars($editCat['name']) ?>" required></label>
                <label>Description<textarea name="description"><?= htmlspecialchars((string) ($editCat['description'] ?? '')) ?></textarea></label>
                <div><button type="submit">Update</button><a href="?page=categories" style="display:inline-block;padding:12px 20px;color:var(--brown-800);font-weight:700">Cancel</a></div>
            </form>
        </div>
    <?php endif; ?>

    <div class="tab-bar">
        <button class="tab-btn active" onclick="switchTab(this,'cat-add')"><i class="fas fa-plus"></i> Add Category</button>
        <button class="tab-btn" onclick="switchTab(this,'cat-list')"><i class="fas fa-list"></i> Category List</button>
    </div>
    <div class="tab-content">
        <div id="cat-add" class="tab-panel <?= hasPermission('categories', 'create') ? 'active' : '' ?>">
            <?php if (hasPermission('categories', 'create')): ?>
            <div class="panel">
                <h2>Add category</h2>
                <form method="post" class="form-grid" style="max-width:500px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="create">
                    <label>Name<input type="text" name="name" required></label>
                    <label>Description<textarea name="description"></textarea></label>
                    <button type="submit">Create</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <div id="cat-list" class="tab-panel">
            <div class="panel">
                <h2>Category list</h2>
                <table>
                    <thead><tr><th>Name</th><th>Description</th><th>Products</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php if (empty($categories)): ?>
                            <tr><td colspan="4">No categories yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($categories as $cat): ?>
                                <tr>
                                    <td><?= htmlspecialchars($cat['name']) ?></td>
                                    <td><?= htmlspecialchars((string) ($cat['description'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string) $cat['product_count']) ?></td>
                                    <td>
                                        <?php if (hasPermission('categories', 'edit')): ?>
                                        <a href="?page=categories&edit=<?= htmlspecialchars((string) $cat['id']) ?>" style="color:var(--brown-800);font-weight:700;text-decoration:none;margin-right:10px">Edit</a>
                                        <?php endif; ?>
                                        <?php if (hasPermission('categories', 'delete')): ?>
                                        <form method="post" style="display:inline" onsubmit="return confirm('Delete this category?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="_action" value="delete">
                                            <input type="hidden" name="delete_id" value="<?= htmlspecialchars((string) $cat['id']) ?>">
                                            <button type="submit" class="btn-danger" style="padding:4px 10px;font-size:0.82rem">Delete</button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
