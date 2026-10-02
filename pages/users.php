<?php
requirePermission('users', 'view');
$pdo = getPDO();
$messages = [];
$errors = [];

$allRoles = $pdo->query('SELECT id, name, is_system FROM roles ORDER BY is_system DESC, name ASC')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (isset($_POST['create_user'])) {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $fullName = trim($_POST['full_name'] ?? '');
        $roleId = (int) ($_POST['role_id'] ?? 0);

        if ($username === '' || $password === '' || $fullName === '') {
            $errors[] = 'All fields are required.';
        } elseif (strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters.';
        } elseif ($roleId <= 0) {
            $errors[] = 'Select a role.';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u');
            $stmt->execute(['u' => $username]);
            if ($stmt->fetch()) {
                $errors[] = 'Username already taken.';
            } else {
$roleRow = $pdo->prepare('SELECT name, is_system FROM roles WHERE id = :id');
$roleRow->execute([':id' => $roleId]);
$role = $roleRow->fetch();
if (!$role) {
    $errors[] = 'Selected role no longer exists.';
} else {
// Role name (as shown on the Roles page) -> users.role enum value.
// 'Super Admin' keeps the 'admin' enum: it is the protected root slot.
$enumMap = ['super admin' => 'admin', 'admin' => 'admin', 'manager' => 'manager', 'customer' => 'customer', 'delivery staff' => 'delivery', 'delivery' => 'delivery', 'finance officer' => 'finance', 'finance' => 'finance', 'salesperson' => 'salesperson', 'store manager' => 'store_manager'];
$roleEnum = $enumMap[strtolower(trim($role['name']))] ?? null;
if ($roleEnum === null) {
    $errors[] = "Role '{$role['name']}' is not linked to a system role type. Rename it to a standard role name or assign its permissions manually.";
} else {
userCreate($pdo, $username, $password, $fullName, $roleEnum, $roleId);
$messages[] = ['type' => 'success', 'text' => "User '{$fullName}' created."];
}
}
}
        }
    }

    if (isset($_POST['reset_password'])) {
        $id = (int) ($_POST['user_id'] ?? 0);
        $newPass = $_POST['new_password'] ?? '';
        if ($id <= 0 || strlen($newPass) < 6) {
            $errors[] = 'Password must be at least 6 characters.';
        } else {
            userUpdatePassword($pdo, $id, $newPass);
            $messages[] = ['type' => 'success', 'text' => 'Password reset.'];
        }
    }
}

if (isset($_GET['delete'])) {
    require_csrf();
    $id = (int) $_GET['delete'];
    if ($id > 0) {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id AND role != 'admin'");
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() > 0) {
            $messages[] = ['type' => 'success', 'text' => 'User deleted.'];
        } else {
            $errors[] = 'Cannot delete admin users.';
        }
    }
    safeRedirect('index.php?page=users');
}

$staff = $pdo->query("SELECT u.id, u.username, u.full_name, u.role, u.created_at, r.name AS role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.role != 'customer' ORDER BY u.created_at DESC")->fetchAll();
$customers = $pdo->query("SELECT u.id, u.username, u.full_name, u.created_at, c.name AS customer_name, c.phone, c.customer_status FROM users u LEFT JOIN customers c ON u.customer_id = c.id WHERE u.role = 'customer' ORDER BY u.created_at DESC")->fetchAll();
?>
<section>
    <h1>User Management</h1>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
    <?php endif; ?>
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>
    <div class="tab-bar">
        <button class="tab-btn active" onclick="switchTab(this,'user-staff')"><i class="fas fa-user-tie"></i> Staff</button>
        <button class="tab-btn" onclick="switchTab(this,'user-customers')"><i class="fas fa-store"></i> Business Customers</button>
        <button class="tab-btn" onclick="switchTab(this,'user-create')"><i class="fas fa-user-plus"></i> New Staff</button>
    </div>
    <div class="tab-content">
        <div id="user-staff" class="tab-panel active">
            <div class="panel">
                <div style="overflow-x:auto">
                <table>
                    <thead><tr><th>ID</th><th>Full Name</th><th>Username</th><th>Role</th><th>Created</th><th></th></tr></thead>
                    <tbody>
                        <?php if (empty($staff)): ?>
                            <tr><td colspan="6">No staff found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($staff as $u): ?>
                                <tr>
                                    <td><?= (int) $u['id'] ?></td>
                                    <td><?= htmlspecialchars($u['full_name'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($u['username']) ?></td>
                                    <td><?= htmlspecialchars($u['role_name'] ?? $u['role']) ?></td>
                                    <td><?= htmlspecialchars($u['created_at']) ?></td>
                                    <td style="white-space:nowrap">
                                        <button onclick="showResetForm(<?= (int)$u['id'] ?>, '<?= htmlspecialchars(addslashes($u['full_name'] ?? $u['username'])) ?>')" style="color:var(--brown-700);background:none;border:none;cursor:pointer;font-size:0.82rem;margin-right:6px" title="Reset password"><i class="fas fa-key"></i></button>
                                        <?php if ($u['role'] !== 'admin'): ?>
                                            <a href="?page=users&delete=<?= (int) $u['id'] ?>&csrf_token=<?= htmlspecialchars(csrf_token()) ?>" onclick="return confirm('Delete <?= htmlspecialchars(addslashes($u['full_name'] ?? $u['username'])) ?>?')" style="color:var(--red-600);font-size:0.82rem" title="Delete"><i class="fas fa-trash"></i></a>
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
        <div id="user-customers" class="tab-panel">
            <div class="panel">
                <p class="hint">Customer accounts are created automatically when a business customer registers or is created from the <a href="?page=customers">Business Customers</a> page.</p>
                <div style="overflow-x:auto">
                <table>
                    <thead><tr><th>ID</th><th>Login Username</th><th>Business Name</th><th>Phone</th><th>Status</th><th>Created</th><th></th></tr></thead>
                    <tbody>
                        <?php if (empty($customers)): ?>
                            <tr><td colspan="7">No customer accounts found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($customers as $c): ?>
                                <tr>
                                    <td><?= (int) $c['id'] ?></td>
                                    <td><?= htmlspecialchars($c['username']) ?></td>
                                    <td><?= htmlspecialchars($c['customer_name'] ?? $c['full_name'] ?? '') ?></td>
                                    <td><?= htmlspecialchars((string) ($c['phone'] ?? '')) ?></td>
                                    <td><span class="status-badge status-<?= ($c['customer_status'] ?? 'active') === 'active' ? 'approved' : 'pending' ?>"><?= htmlspecialchars($c['customer_status'] ?? 'active') ?></span></td>
                                    <td><?= htmlspecialchars($c['created_at']) ?></td>
                                    <td>
                                        <button onclick="showResetForm(<?= (int)$c['id'] ?>, '<?= htmlspecialchars(addslashes($c['username'])) ?>')" style="color:var(--brown-700);background:none;border:none;cursor:pointer;font-size:0.82rem" title="Reset password"><i class="fas fa-key"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
        <div id="user-create" class="tab-panel">
            <div class="panel" style="max-width:500px">
                <h2>Create Staff Account</h2>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="create_user" value="1">
                    <label>Full Name<input type="text" name="full_name" required></label>
                    <label>Username<input type="text" name="username" required></label>
                    <label>Password<input type="password" name="password" required minlength="6"></label>
                    <label>Role
                        <select name="role_id" required>
                            <option value="">— Select Role —</option>
                            <?php foreach ($allRoles as $r): ?>
                                <?php if (strtolower($r['name']) !== 'customer'): ?>
                                    <option value="<?= (int) $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit"><i class="fas fa-plus"></i> Create Staff</button>
                </form>
            </div>
        </div>
    </div>
</section>
<div id="reset-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.4);z-index:9999;align-items:center;justify-content:center" onclick="if(event.target===this)closeResetForm()">
    <div style="background:#fff;border-radius:12px;padding:28px 32px;max-width:380px;width:90%;box-shadow:0 8px 30px rgba(0,0,0,0.2)">
        <h3>Reset Password</h3>
        <p id="reset-user-name" style="color:var(--neutral-600);font-size:0.85rem;margin:4px 0 16px"></p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="reset_password" value="1">
            <input type="hidden" name="user_id" id="reset-user-id" value="">
            <label>New password<input type="password" name="new_password" required minlength="6" style="width:100%;padding:8px 10px;font-size:0.95rem;margin-top:4px"></label>
            <div style="display:flex;gap:10px;margin-top:16px">
                <button type="submit" style="flex:1;background:var(--brown-600);color:#fff;border:none;border-radius:8px;padding:9px;font-weight:700;cursor:pointer">Save</button>
                <button type="button" onclick="closeResetForm()" style="flex:1;background:var(--neutral-200);color:var(--neutral-700);border:none;border-radius:8px;padding:9px;font-weight:600;cursor:pointer">Cancel</button>
            </div>
        </form>
    </div>
</div>
<script>
function showResetForm(id, name) {
    document.getElementById('reset-user-id').value = id;
    document.getElementById('reset-user-name').textContent = name;
    document.getElementById('reset-modal').style.display = 'flex';
}
function closeResetForm() {
    document.getElementById('reset-modal').style.display = 'none';
}
</script>
