<?php
require_once __DIR__ . '/../services/init.php';
requirePermission('customers', 'view');
$pdo = getPDO();
require_once __DIR__ . '/../includes/auto_migrate.php';
ensureCustomerSelfRegistrationMigration($pdo);
$messages = [];

$editCustomer = null;
if (isset($_GET['edit'])) {
    $editCustomer = CustomerService::find($pdo, (int) $_GET['edit']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['_action'] ?? '';

    if ($action === 'delete') {
        requirePermission('customers', 'delete');
        $deleteId = (int) ($_POST['delete_id'] ?? 0);
        if ($deleteId > 0) {
            $res = CustomerService::delete($pdo, $deleteId, (int) ($_SESSION['user']['id'] ?? 0));
            $messages[] = ['type' => $res['success'] ? 'success' : 'error', 'text' => $res['msg']];
        }
    } elseif ($action === 'update') {
        requirePermission('customers', 'edit');
        $customerId = (int) ($_POST['customer_id'] ?? 0);
        $res = CustomerService::update($pdo, $customerId, $_POST, (int) ($_SESSION['user']['id'] ?? 0));
        $messages[] = ['type' => $res['success'] ? 'success' : 'error', 'text' => $res['msg']];
    } elseif ($action === 'approve_registration') {
        requirePermission('customers', 'approve');
        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            $uid = (int) ($_SESSION['user']['id'] ?? 0);
            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE users SET account_status = 'approved', approved_by = ?, approved_at = NOW(), rejection_reason = NULL WHERE id = ? AND role = 'customer'")
                    ->execute([$uid, $userId]);
                if ($pdo->query('SELECT ROW_COUNT()')->fetchColumn() < 1) {
                    throw new RuntimeException('no-op');
                }
                $cid = $pdo->prepare('SELECT customer_id FROM users WHERE id = ?');
                $cid->execute([$userId]);
                $customerId = $cid->fetchColumn();
                if ($customerId) {
                    $pdo->prepare("UPDATE customers SET customer_status = 'active' WHERE id = ?")->execute([(int) $customerId]);
                }
                $pdo->commit();
                $messages[] = ['type' => 'success', 'text' => 'Registration approved — the customer can now sign in.'];
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $messages[] = ['type' => 'error', 'text' => 'Approval failed. The application may already be handled.'];
            }
        }
    } elseif ($action === 'reject_registration') {
        requirePermission('customers', 'approve');
        $userId = (int) ($_POST['user_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? '');
        if ($userId > 0) {
            $uid = (int) ($_SESSION['user']['id'] ?? 0);
            $pdo->prepare("UPDATE users SET account_status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ? AND role = 'customer'")
                ->execute([$uid, $reason !== '' ? mb_substr($reason, 0, 255) : 'Not approved', $userId]);
            $cid = $pdo->prepare('SELECT customer_id FROM users WHERE id = ?');
            $cid->execute([$userId]);
            $customerId = $cid->fetchColumn();
            if ($customerId) {
                $pdo->prepare("UPDATE customers SET customer_status = 'inactive' WHERE id = ?")->execute([(int) $customerId]);
            }
            $messages[] = ['type' => 'success', 'text' => 'Registration rejected. The customer cannot sign in.'];
        }
    } elseif (isset($_POST['create_customer_login'])) {
        requirePermission('customers', 'create');
        $res = CustomerService::createPortalLogin($pdo, (int) ($_POST['link_customer_id'] ?? 0), trim($_POST['new_username'] ?? ''), $_POST['new_password'] ?? '', (int) ($_SESSION['user']['id'] ?? 0));
        $messages[] = ['type' => $res['success'] ? 'success' : 'error', 'text' => $res['msg']];
    } else {
        requirePermission('customers', 'create');
        try {
            CustomerService::create($pdo, $_POST, (int) ($_SESSION['user']['id'] ?? 0));
            $messages[] = ['type' => 'success', 'text' => 'Customer added successfully.'];
        } catch (ServiceException $e) {
            $messages[] = ['type' => 'error', 'text' => $e->getMessage()];
        } catch (Throwable $e) {
            $messages[] = ['type' => 'error', 'text' => 'Could not add the customer. Please check the details and try again.'];
        }
    }
}

$search = trim($_GET['search'] ?? '');
$customers = CustomerService::list($pdo, $search);

// Self-registration applications awaiting an administrator decision.
$pendingUsers = [];
if (hasPermission('customers', 'approve')) {
    $pendingUsers = $pdo->query(
        "SELECT u.id AS user_id, u.username, u.account_status, u.created_at, u.rejection_reason,
                c.id AS customer_id, c.name, c.customer_type, c.license_number, c.tin_number,
                c.contact_person, c.phone, c.email, c.physical_address, c.customer_status
         FROM users u
         LEFT JOIN customers c ON c.id = u.customer_id
         WHERE u.role = 'customer' AND u.account_status IN ('pending','rejected')
         ORDER BY FIELD(u.account_status,'pending','rejected'), u.created_at DESC"
    )->fetchAll();
}
$pendingCount = 0;
foreach ($pendingUsers as $pu) {
    if ($pu['account_status'] === 'pending') { $pendingCount++; }
}
$canApprove = hasPermission('customers', 'approve');
$canCreate  = hasPermission('customers', 'create');
// Applications awaiting a decision become the landing tab.
$regActive  = $canApprove && $pendingCount > 0;
$defaultTab = $regActive ? 'cust-reg' : ($canCreate ? 'cust-add' : 'cust-list');
?>
<style>
@media(max-width:768px){.mob-grid-2{grid-template-columns:1fr!important}}
.cust-page{max-width:1300px;margin:0 auto}
.cust-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px}
.cust-stat{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.cust-stat-val{font-size:1.3rem;font-weight:800;color:#0f172a}
.cust-stat-label{font-size:.72rem;color:#64748b;margin-top:2px}
.cust-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,.06);margin-bottom:16px}
.cust-table{width:100%;border-collapse:separate;border-spacing:0;font-size:.84rem}
.cust-table th{text-align:left;padding:10px 12px;background:#f8fafc;font-weight:700;color:#475569;border-bottom:2px solid #e2e8f0;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.cust-table td{padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.cust-table tbody tr:hover{background:#f8fafc}
.cust-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:.7rem;font-weight:600}
.cust-badge-active{background:#dcfce7;color:#166534}
.cust-badge-inactive{background:#f1f5f9;color:#64748b}
.cust-badge-suspended{background:#fee2e2;color:#991b1b}
.cust-btn{padding:6px 12px;border:none;border-radius:8px;font-size:.78rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;font-family:inherit;text-decoration:none}
.cust-btn-primary{background:#2563eb;color:#fff}
.cust-btn-outline{background:transparent;border:1px solid #cbd5e1;color:#475569}
.cust-btn-sm{padding:3px 8px;font-size:.75rem}
.cust-search{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
.cust-search input{padding:8px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;font-family:inherit;min-width:200px;flex:1}
.cust-tabs{display:flex;gap:4px;flex-wrap:wrap;margin-bottom:16px;padding:6px;background:#f1f5f9;border-radius:12px}
.cust-tabs .tab-btn{padding:8px 14px;border:none;background:transparent;border-radius:8px;font-size:.82rem;font-weight:600;color:#475569;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-family:inherit}
.cust-tabs .tab-btn.active{background:#fff;color:#2563eb;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.cust-panel{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,.06);margin-bottom:16px}
.cust-panel h2{font-size:1rem;font-weight:700;color:#0f172a;margin:0 0 12px;display:flex;align-items:center;gap:8px}
.cust-form label{display:block;font-size:.8rem;font-weight:600;color:#475569;margin-bottom:4px}
.cust-form input,.cust-form select,.cust-form textarea{width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;font-family:inherit;box-sizing:border-box}
.cust-form input:focus,.cust-form select:focus,.cust-form textarea:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
.cust-form textarea{resize:vertical;min-height:60px}
.cust-form .row{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px}
.cust-form .actions{display:flex;gap:8px;margin-top:14px;flex-wrap:wrap}
.cust-empty{text-align:center;padding:30px;color:#94a3b8}
.cust-empty i{font-size:2rem;color:#cbd5e1;display:block;margin-bottom:8px}
@media(max-width:768px){.cust-table{font-size:.78rem}.cust-table th,.cust-table td{padding:8px 6px}}
</style>
<section class="cust-page">
    <h1>Business Customers</h1>
    <?php
    $custTotal = count($customers);
    $custActive = count(array_filter($customers, fn($c) => ($c['customer_status'] ?? 'active') === 'active'));
    $custPending = $pendingCount;
    ?>
    <div class="cust-stats">
        <div class="cust-stat"><div class="cust-stat-val"><?= $custTotal ?></div><div class="cust-stat-label">Total Customers</div></div>
        <div class="cust-stat"><div class="cust-stat-val"><?= $custActive ?></div><div class="cust-stat-label">Active</div></div>
        <div class="cust-stat"><div class="cust-stat-val"><?= $custPending ?></div><div class="cust-stat-label">Pending Registrations</div></div>
        <div class="cust-stat"><div class="cust-stat-val"><?= count($pendingUsers) ?></div><div class="cust-stat-label">Applications</div></div>
    </div>
    <?php foreach ($messages as $message): ?>
        <div class="alert alert-<?= htmlspecialchars($message['type']) ?>"><?= htmlspecialchars($message['text']) ?></div>
    <?php endforeach; ?>

    <?php if ($editCustomer && hasPermission('customers', 'edit')): ?>
        <div class="panel" style="max-width:700px;margin-bottom:16px">
            <h2>Edit customer #<?= htmlspecialchars((string) $editCustomer['id']) ?></h2>
            <form method="post" class="form-grid cust-form">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="update">
                <input type="hidden" name="customer_id" value="<?= htmlspecialchars((string) $editCustomer['id']) ?>">
                <div class="mob-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <label>Name<input type="text" name="name" value="<?= htmlspecialchars($editCustomer['name']) ?>" required></label>
                    <label>Customer Type
                        <select name="customer_type">
                            <option value="">Select...</option>
                            <?php foreach (['pharmacy' => 'Pharmacy','drug_store' => 'Drug Store','hospital' => 'Hospital','clinic' => 'Clinic','wholesaler' => 'Wholesaler','other_licensed' => 'Other Licensed'] as $val => $label): ?>
                                <option value="<?= $val ?>"<?= ($editCustomer['customer_type'] ?? '') === $val ? ' selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>License Number<input type="text" name="license_number" value="<?= htmlspecialchars($editCustomer['license_number'] ?? '') ?>"></label>
                    <label>TIN Number<input type="text" name="tin_number" value="<?= htmlspecialchars($editCustomer['tin_number'] ?? '') ?>"></label>
                    <label>Contact Person<input type="text" name="contact" value="<?= htmlspecialchars((string) ($editCustomer['contact_person'] ?? '')) ?>"></label>
                    <label>Phone<input type="text" name="phone" value="<?= htmlspecialchars((string) ($editCustomer['phone'] ?? '')) ?>"></label>
                    <label>Email<input type="email" name="email" value="<?= htmlspecialchars($editCustomer['email'] ?? '') ?>"></label>
                    <label>Credit Limit (<?= currency_sym() ?>)<input type="number" step="0.01" name="credit_limit" value="<?= htmlspecialchars((string) ($editCustomer['credit_limit'] ?? 0)) ?>"></label>
                    <label>Payment Terms<input type="text" name="payment_terms" value="<?= htmlspecialchars($editCustomer['payment_terms'] ?? '') ?>" placeholder="e.g. Net 30"></label>
                    <label>Customer Status
                        <select name="customer_status">
                            <option value="active"<?= ($editCustomer['customer_status'] ?? 'active') === 'active' ? ' selected' : '' ?>>Active</option>
                            <option value="inactive"<?= ($editCustomer['customer_status'] ?? '') === 'inactive' ? ' selected' : '' ?>>Inactive</option>
                            <option value="suspended"<?= ($editCustomer['customer_status'] ?? '') === 'suspended' ? ' selected' : '' ?>>Suspended</option>
                        </select>
                    </label>
                </div>
                <label>Physical Address<textarea name="physical_address"><?= htmlspecialchars($editCustomer['physical_address'] ?? '') ?></textarea></label>
                <div>
                    <button type="submit">Update customer</button>
                    <a href="?page=customers" style="display:inline-block;padding:12px 20px;color:var(--brown-800);font-weight:700">Cancel</a>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div class="tab-bar cust-tabs">
        <?php if ($canApprove): ?>
        <button class="tab-btn<?= $regActive ? ' active' : '' ?>" onclick="switchTab(this,'cust-reg')"><i class="fas fa-user-clock"></i> Registrations<?= $pendingCount > 0 ? ' (' . $pendingCount . ')' : '' ?></button>
        <?php endif; ?>
        <?php if ($canCreate): ?>
        <button class="tab-btn<?= $defaultTab === 'cust-add' ? ' active' : '' ?>" onclick="switchTab(this,'cust-add')"><i class="fas fa-plus"></i> Add Customer</button>
        <button class="tab-btn" onclick="switchTab(this,'cust-portal')"><i class="fas fa-user-lock"></i> Portal Login</button>
        <?php endif; ?>
        <button class="tab-btn<?= $defaultTab === 'cust-list' ? ' active' : '' ?>" onclick="switchTab(this,'cust-list')"><i class="fas fa-list"></i> Customer List</button>
    </div>
    <div class="tab-content">
        <?php if ($canCreate): ?>
        <div id="cust-add" class="tab-panel<?= $defaultTab === 'cust-add' ? ' active' : '' ?>">
            <div class="panel cust-panel">
                <h2>Add business customer</h2>
                <form method="post" class="form-grid cust-form" style="max-width:700px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="create">
                    <div class="mob-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                        <label>Name<input type="text" name="name" required></label>
                        <label>Customer Type
                            <select name="customer_type">
                                <option value="">Select...</option>
                                <?php foreach (['pharmacy' => 'Pharmacy','drug_store' => 'Drug Store','hospital' => 'Hospital','clinic' => 'Clinic','wholesaler' => 'Wholesaler','other_licensed' => 'Other Licensed'] as $val => $label): ?>
                                    <option value="<?= $val ?>"><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>License Number<input type="text" name="license_number"></label>
                        <label>TIN Number<input type="text" name="tin_number"></label>
                        <label>Contact Person<input type="text" name="contact"></label>
                        <label>Phone<input type="text" name="phone"></label>
                        <label>Email<input type="email" name="email"></label>
                        <label>Credit Limit (<?= currency_sym() ?>)<input type="number" step="0.01" name="credit_limit" value="0"></label>
                        <label>Payment Terms<input type="text" name="payment_terms" placeholder="e.g. Net 30"></label>
                        <label>Customer Status
                            <select name="customer_status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                            </select>
                        </label>
                    </div>
                    <label>Physical Address<textarea name="physical_address"></textarea></label>
                    <button type="submit">Create customer</button>
                </form>
            </div>
        </div>
        <div id="cust-portal" class="tab-panel">
            <div class="panel cust-panel">
                <h2>Customer portal login</h2>
                <p class="hint">Customers can also <a href="index.php?page=register">register themselves</a> and order online. Create a login here if you prefer to set one up for them.</p>
                <form method="post" class="form-grid cust-form" style="max-width:500px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="create_customer_login" value="1">
                    <label>Customer
                        <select name="link_customer_id" required>
                            <option value="">Select customer</option>
                            <?php foreach ($customers as $customer): ?>
                                <option value="<?= htmlspecialchars((string) $customer['id']) ?>"><?= htmlspecialchars($customer['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Username<input type="text" name="new_username" required autocomplete="off"></label>
                    <label>Password<input type="password" name="new_password" required minlength="6" autocomplete="new-password"></label>
                    <button type="submit">Create login</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($canApprove): ?>
        <div id="cust-reg" class="tab-panel<?= $regActive ? ' active' : '' ?>">
            <div class="panel cust-panel">
                <h2>Customer registrations</h2>
                <p class="hint">Applications submitted from the public registration page. Verify the <strong>licence number</strong> and <strong>TIN number</strong> before approving — the customer cannot sign in until you do.</p>
                <div style="overflow-x:auto">
                <table>
                    <thead><tr><th>Applied</th><th>Business</th><th>Type</th><th>Licence No.</th><th>TIN</th><th>Contact</th><th>Phone</th><th>Username</th><th>Status</th><th>Decision</th></tr></thead>
                    <tbody>
                        <?php if (empty($pendingUsers)): ?>
                            <tr><td colspan="10">No registrations awaiting approval.</td></tr>
                        <?php else: ?>
                            <?php foreach ($pendingUsers as $pu):
                                $isPending = $pu['account_status'] === 'pending';
                            ?>
                                <tr>
                                    <td style="font-size:0.8rem;white-space:nowrap"><?= htmlspecialchars((string) $pu['created_at']) ?></td>
                                    <td><?= htmlspecialchars((string) ($pu['name'] ?? '—')) ?><?= !empty($pu['physical_address']) ? '<br><span style="font-size:0.75rem;color:#64748b">' . htmlspecialchars((string) $pu['physical_address']) . '</span>' : '' ?></td>
                                    <td style="font-size:0.82rem"><?= $pu['customer_type'] ? htmlspecialchars(str_replace('_', ' ', ucfirst((string) $pu['customer_type']))) : '—' ?></td>
                                    <td style="font-size:0.82rem"><strong><?= htmlspecialchars((string) ($pu['license_number'] ?? '—')) ?></strong></td>
                                    <td style="font-size:0.82rem"><strong><?= htmlspecialchars((string) ($pu['tin_number'] ?? '—')) ?></strong></td>
                                    <td><?= htmlspecialchars((string) ($pu['contact_person'] ?? '')) ?></td>
                                    <td style="font-size:0.82rem"><?= htmlspecialchars((string) ($pu['phone'] ?? '')) ?></td>
                                    <td style="font-size:0.82rem"><?= htmlspecialchars((string) $pu['username']) ?></td>
                                    <td>
                                        <?php if ($isPending): ?>
                                            <span style="display:inline-block;padding:2px 6px;border-radius:3px;font-size:0.72rem;font-weight:700;background:#f59e0b20;color:#b45309">Pending</span>
                                        <?php else: ?>
                                            <span style="display:inline-block;padding:2px 6px;border-radius:3px;font-size:0.72rem;font-weight:700;background:#dc262620;color:#b91c1c" title="<?= htmlspecialchars((string) ($pu['rejection_reason'] ?? '')) ?>">Rejected</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isPending): ?>
                                            <form method="post" style="display:inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="_action" value="approve_registration">
                                                <input type="hidden" name="user_id" value="<?= (int) $pu['user_id'] ?>">
                                                <button type="submit" style="padding:4px 12px;font-size:0.82rem"><i class="fas fa-check"></i> Approve</button>
                                            </form>
                                            <form method="post" style="display:inline-flex;gap:6px;align-items:center;margin-top:6px">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="_action" value="reject_registration">
                                                <input type="hidden" name="user_id" value="<?= (int) $pu['user_id'] ?>">
                                                <input type="text" name="rejection_reason" placeholder="Reason (optional)" style="margin:0;padding:4px 8px;font-size:0.8rem;width:150px">
                                                <button type="submit" class="btn-danger" style="padding:4px 10px;font-size:0.82rem">Reject</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" style="display:inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="_action" value="approve_registration">
                                                <input type="hidden" name="user_id" value="<?= (int) $pu['user_id'] ?>">
                                                <button type="submit" style="padding:4px 12px;font-size:0.82rem"><i class="fas fa-rotate-left"></i> Approve anyway</button>
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
        <?php endif; ?>
        <div id="cust-list" class="tab-panel<?= $defaultTab === 'cust-list' ? ' active' : '' ?>">
            <div class="panel cust-panel">
                <h2>Customer list</h2>
                <form method="get" class="cust-search">
                    <input type="hidden" name="page" value="customers">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search customers...">
                    <button type="submit" class="cust-btn cust-btn-primary cust-btn-sm"><i class="fas fa-search"></i> Search</button>
                    <?php if ($search !== ''): ?><a href="?page=customers" class="cust-btn cust-btn-outline cust-btn-sm">Clear</a><?php endif; ?>
                </form>
                <div style="overflow-x:auto">
                <table class="cust-table">
                    <thead><tr><th>ID</th><th>Name</th><th>Type</th><th>License</th><th>TIN</th><th>Contact</th><th>Phone</th><th>Email</th><th>Credit Limit</th><th>Status</th><th>Portal</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php if (empty($customers)): ?>
                            <tr><td colspan="12">No customers found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($customers as $customer): 
                                $cs = $customer['customer_status'] ?? 'active';
                                $csColor = $cs === 'active' ? '#059669' : ($cs === 'inactive' ? '#f59e0b' : '#dc2626');
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) $customer['id']) ?></td>
                                    <td><?= htmlspecialchars($customer['name']) ?></td>
                                    <td style="font-size:0.82rem"><?= $customer['customer_type'] ? htmlspecialchars(str_replace('_', ' ', ucfirst($customer['customer_type']))) : '—' ?></td>
                                    <td style="font-size:0.82rem"><?= htmlspecialchars($customer['license_number'] ?? '—') ?></td>
                                    <td style="font-size:0.82rem"><?= htmlspecialchars($customer['tin_number'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars((string) ($customer['contact_person'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string) ($customer['phone'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars($customer['email'] ?? '—') ?></td>
                                    <td style="font-size:0.82rem"><?= fmt((float) ($customer['credit_limit'] ?? 0)) ?></td>
                                    <td><span class="cust-badge <?= $cs === 'active' ? 'cust-badge-active' : ($cs === 'inactive' ? 'cust-badge-inactive' : 'cust-badge-suspended') ?>"><?= ucfirst($cs) ?></span></td>
                                    <td><?= $customer['portal_username'] ? htmlspecialchars((string) $customer['portal_username']) : '—' ?></td>
                                    <td>
                                        <?php if (hasPermission('customers', 'edit')): ?>
                                        <a href="?page=customers&edit=<?= htmlspecialchars((string) $customer['id']) ?>" style="color:var(--brown-800);font-weight:700;text-decoration:none;margin-right:10px">Edit</a>
                                        <?php endif; ?>
                                        <?php if (hasPermission('customers', 'delete')): ?>
                                        <form method="post" style="display:inline" onsubmit="return confirm('Delete this customer?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="_action" value="delete">
                                            <input type="hidden" name="delete_id" value="<?= htmlspecialchars((string) $customer['id']) ?>">
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
    </div>
</section>
