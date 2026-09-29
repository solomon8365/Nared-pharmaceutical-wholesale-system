<?php
requirePermission('users', 'view');
$pdo = getPDO();
$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['_action'] ?? '';

    if ($action === 'create') {
        requirePermission('users', 'create');
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
        if ($name === '') {
            $errors[] = 'Department name is required.';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM departments WHERE LOWER(name) = LOWER(:n)');
            $stmt->execute(['n' => $name]);
            if ($stmt->fetch()) {
                $errors[] = 'A department with this name already exists.';
            } else {
                $pdo->prepare('INSERT INTO departments (name, description, status, created_at) VALUES (:n, :d, :s, NOW())')
                    ->execute(['n' => $name, 'd' => $description, 's' => $status]);
                $messages[] = ['type' => 'success', 'text' => "Department '{$name}' created."];
            }
        }
    } elseif ($action === 'update') {
        requirePermission('users', 'edit');
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
        if ($id <= 0 || $name === '') {
            $errors[] = 'Department name is required.';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM departments WHERE LOWER(name) = LOWER(:n) AND id != :id');
            $stmt->execute(['n' => $name, 'id' => $id]);
            if ($stmt->fetch()) {
                $errors[] = 'A department with this name already exists.';
            } else {
                $pdo->prepare('UPDATE departments SET name = :n, description = :d, status = :s WHERE id = :id')
                    ->execute(['n' => $name, 'd' => $description, 's' => $status, 'id' => $id]);
                $messages[] = ['type' => 'success', 'text' => 'Department updated.'];
            }
        }
    } elseif ($action === 'toggle') {
        requirePermission('users', 'edit');
        $id = (int) ($_POST['id'] ?? 0);
        $status = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
        if ($id > 0) {
            $pdo->prepare('UPDATE departments SET status = :s WHERE id = :id')->execute(['s' => $status, 'id' => $id]);
            $messages[] = ['type' => 'success', 'text' => 'Department status updated.'];
        }
    } elseif ($action === 'delete') {
        requirePermission('users', 'delete');
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM departments WHERE id = :id')->execute(['id' => $id]);
            $messages[] = ['type' => 'success', 'text' => 'Department deleted.'];
        }
    }
}

$departments = $pdo->query('SELECT * FROM departments ORDER BY status ASC, name ASC')->fetchAll();
$activeCount = 0;
$inactiveCount = 0;
foreach ($departments as $d) {
    if (($d['status'] ?? 'active') === 'active') $activeCount++; else $inactiveCount++;
}
$editingId = (int) ($_GET['edit'] ?? 0);
$editing = null;
if ($editingId > 0) {
    foreach ($departments as $d) {
        if ((int) $d['id'] === $editingId) { $editing = $d; break; }
    }
}
?>
<style>
.dept-main-grid{display:grid;grid-template-columns:1fr 2fr;gap:16px;align-items:start}
@media(max-width:768px){.dept-main-grid{grid-template-columns:1fr}}
</style>
<section>
    <div class="as-page-title"><i class="fas fa-sitemap" style="color:#3b82f6"></i> Departments</div>
    <div class="as-page-sub">Organize staff into departments for reporting and access management</div>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error" style="margin-bottom:16px;padding:12px 16px;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-radius:8px"><?= htmlspecialchars(implode('; ', $errors)) ?></div>
    <?php endif; ?>
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>" style="margin-bottom:16px"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <div class="as-stat-grid">
        <div class="as-stat"><div class="as-stat-val"><?= count($departments) ?></div><div class="as-stat-label">Total Departments</div></div>
        <div class="as-stat"><div class="as-stat-val" style="color:#059669"><?= $activeCount ?></div><div class="as-stat-label">Active</div></div>
        <div class="as-stat"><div class="as-stat-val" style="color:#94a3b8"><?= $inactiveCount ?></div><div class="as-stat-label">Inactive</div></div>
    </div>

    <div class="dept-main-grid">
        <div class="as-card" style="margin:0">
            <div class="as-card-title"><i class="fas fa-<?= $editing ? 'edit' : 'plus' ?>"></i> <?= $editing ? 'Edit Department' : 'Add Department' ?></div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="<?= $editing ? 'update' : 'create' ?>">
                <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
                <div class="as-field" style="margin-bottom:12px"><label>Department Name</label><input type="text" name="name" value="<?= htmlspecialchars($editing['name'] ?? '') ?>" required placeholder="e.g. Purchasing"></div>
                <div class="as-field" style="margin-bottom:12px"><label>Description</label><textarea name="description" rows="3" placeholder="Optional description"><?= htmlspecialchars($editing['description'] ?? '') ?></textarea></div>
                <div class="as-field" style="margin-bottom:16px">
                    <label>Status</label>
                    <select name="status">
                        <option value="active"<?= ($editing && ($editing['status'] ?? '') === 'inactive') ? '' : ' selected' ?>>Active</option>
                        <option value="inactive"<?= ($editing && ($editing['status'] ?? '') === 'inactive') ? ' selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div style="display:flex;gap:8px">
                    <button type="submit" class="as-btn as-btn-primary"><i class="fas fa-save"></i> <?= $editing ? 'Update Department' : 'Create Department' ?></button>
                    <?php if ($editing): ?><a href="?page=departments" class="as-btn as-btn-outline">Cancel</a><?php endif; ?>
                </div>
            </form>
        </div>

        <div class="as-card" style="margin:0">
            <div class="as-card-title"><i class="fas fa-list"></i> All Departments (<?= count($departments) ?>)</div>
            <?php if (empty($departments)): ?>
                <div class="as-empty"><i class="fas fa-sitemap"></i> No departments yet. Create your first department.</div>
            <?php else: ?>
            <div class="panel" style="padding:0;overflow-x:auto">
                <table>
                    <thead><tr><th>ID</th><th>Name</th><th>Description</th><th>Status</th><th>Created</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($departments as $d): ?>
                        <tr>
                            <td><?= (int) $d['id'] ?></td>
                            <td style="font-weight:600;color:#1e293b"><?= htmlspecialchars($d['name']) ?></td>
                            <td><?= htmlspecialchars((string) ($d['description'] ?? '')) ?: '—' ?></td>
                            <td><span class="status-badge status-<?= ($d['status'] ?? 'active') === 'active' ? 'approved' : 'pending' ?>"><?= htmlspecialchars($d['status'] ?? 'active') ?></span></td>
                            <td><?= htmlspecialchars($d['created_at']) ?></td>
                            <td style="white-space:nowrap;text-align:right">
                                <a href="?page=departments&edit=<?= (int) $d['id'] ?>" style="color:var(--brown-700);font-size:0.82rem;margin-right:8px" title="Edit"><i class="fas fa-edit"></i></a>
                                <?php if (($d['status'] ?? 'active') === 'active'): ?>
                                    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="_action" value="toggle"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="status" value="inactive"><button type="submit" title="Deactivate" style="color:var(--amber-600);background:none;border:none;cursor:pointer;font-size:0.82rem;margin-right:8px"><i class="fas fa-eye-slash"></i></button></form>
                                <?php else: ?>
                                    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="_action" value="toggle"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="status" value="active"><button type="submit" title="Activate" style="color:var(--green-600);background:none;border:none;cursor:pointer;font-size:0.82rem;margin-right:8px"><i class="fas fa-eye"></i></button></form>
                                <?php endif; ?>
                                <form method="post" style="display:inline" onsubmit="return confirm('Delete department <?= htmlspecialchars(addslashes($d['name'])) ?>?')"><?= csrf_field() ?><input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><button type="submit" title="Delete" style="color:var(--red-600);background:none;border:none;cursor:pointer;font-size:0.82rem"><i class="fas fa-trash"></i></button></form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>
