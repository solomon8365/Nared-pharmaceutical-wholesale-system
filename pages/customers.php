<?php
require_once __DIR__ . '/../services/init.php';
requirePermission('customers', 'view');
$pdo = getPDO();
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
        }
    }
}

$search = trim($_GET['search'] ?? '');
$customers = CustomerService::list($pdo, $search);
?>
<style>@media(max-width:768px){.mob-grid-2{grid-template-columns:1fr!important}}</style>
<section>
    <h1>Business Customers</h1>
    <?php foreach ($messages as $message): ?>
        <div class="alert alert-<?= htmlspecialchars($message['type']) ?>"><?= htmlspecialchars($message['text']) ?></div>
    <?php endforeach; ?>

    <?php if ($editCustomer && hasPermission('customers', 'edit')): ?>
        <div class="panel" style="max-width:700px;margin-bottom:16px">
            <h2>Edit customer #<?= htmlspecialchars((string) $editCustomer['id']) ?></h2>
            <form method="post" class="form-grid">
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

    <div class="tab-bar">
        <?php if (hasPermission('customers', 'create')): ?>
        <button class="tab-btn <?= hasPermission('customers', 'create') ? 'active' : '' ?>" onclick="switchTab(this,'cust-add')"><i class="fas fa-plus"></i> Add Customer</button>
        <button class="tab-btn" onclick="switchTab(this,'cust-portal')"><i class="fas fa-user-lock"></i> Portal Login</button>
        <?php endif; ?>
        <button class="tab-btn <?= hasPermission('customers', 'create') ? '' : 'active' ?>" onclick="switchTab(this,'cust-list')"><i class="fas fa-list"></i> Customer List</button>
    </div>
    <div class="tab-content">
        <?php if (hasPermission('customers', 'create')): ?>
        <div id="cust-add" class="tab-panel <?= hasPermission('customers', 'create') ? 'active' : '' ?>">
            <div class="panel">
                <h2>Add business customer</h2>
                <form method="post" class="form-grid" style="max-width:700px">
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
            <div class="panel">
                <h2>Customer portal login</h2>
                <p class="hint">Customers can also <a href="index.php?page=register">register themselves</a> and order online. Create a login here if you prefer to set one up for them.</p>
                <form method="post" class="form-grid" style="max-width:500px">
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
        <div id="cust-list" class="tab-panel">
            <div class="panel">
                <h2>Customer list</h2>
                <form method="get" style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap">
                    <input type="hidden" name="page" value="customers">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search customers..." style="flex:1;margin:0">
                    <button type="submit" style="margin:0">Search</button>
                    <?php if ($search !== ''): ?><a href="?page=customers" style="display:inline-flex;align-items:center;padding:0 12px;color:var(--brown-800);font-weight:600">Clear</a><?php endif; ?>
                </form>
                <div style="overflow-x:auto">
                <table>
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
                                    <td><span style="display:inline-block;padding:2px 6px;border-radius:3px;font-size:0.72rem;font-weight:700;background:<?= $csColor ?>20;color:<?= $csColor ?>"><?= ucfirst($cs) ?></span></td>
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
