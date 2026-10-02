<?php
requirePermission('roles', 'view');
$pdo = getPDO();
$messages = [];
$errors = [];

$permLabels = [
    'view' => 'View', 'create' => 'Create', 'edit' => 'Edit', 'delete' => 'Delete',
    'approve' => 'Approve', 'export' => 'Export', 'confirm' => 'Confirm', 'unconfirm' => 'Unconfirm',
    'process' => 'Process', 'pay' => 'Record Payment', 'assign_delivery' => 'Assign Delivery',
    'cancel_refund' => 'Cancel / Refund', 'manage' => 'Manage', 'override' => 'Credit Override',
];
$allPermissions = array_keys($permLabels);

$groups = [
    ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'fa-boxes-stacked', 'accent' => '#3b82f6', 'modules' => [
        ['key' => 'store_dashboard', 'label' => 'Store Dashboard', 'perms' => ['view']],
        ['key' => 'products', 'label' => 'Products', 'perms' => ['view', 'create', 'edit', 'delete']],
        ['key' => 'categories', 'label' => 'Categories', 'perms' => ['view', 'create', 'edit', 'delete']],
        ['key' => 'stock', 'label' => 'Manage Stock', 'perms' => ['view']],
        ['key' => 'stock_movements', 'label' => 'Stock Log', 'perms' => ['view']],
        ['key' => 'stock_adjustments', 'label' => 'Stock Adjustment', 'perms' => ['view', 'create']],
        ['key' => 'stock_transfers', 'label' => 'Stock Transfer', 'perms' => ['view', 'create', 'edit', 'delete']],
        ['key' => 'physical_count', 'label' => 'Physical Count', 'perms' => ['view', 'create']],
        ['key' => 'warehouse_picking', 'label' => 'Warehouse Picking', 'perms' => ['view', 'edit']],
        ['key' => 'batch_expiry', 'label' => 'Batch & Expiry', 'perms' => ['view', 'edit']],
        ['key' => 'expiry_management', 'label' => 'Expiry Management', 'perms' => ['view', 'edit', 'delete']],
        ['key' => 'expiry_alerts', 'label' => 'Expiry Alerts', 'perms' => ['view']],
        ['key' => 'out_of_stock_queue', 'label' => 'Out of Stock Queue', 'perms' => ['view']],
        ['key' => 'price_history', 'label' => 'Price History', 'perms' => ['view']],
    ]],
    ['key' => 'procurement', 'label' => 'Procurement', 'icon' => 'fa-truck-fast', 'accent' => '#f59e0b', 'modules' => [
        ['key' => 'suppliers', 'label' => 'View Suppliers', 'perms' => ['view']],
        ['key' => 'purchase_orders', 'label' => 'Purchase Orders', 'perms' => ['view', 'create', 'edit', 'delete', 'approve']],
        ['key' => 'goods_receiving', 'label' => 'Receive Goods', 'perms' => ['view', 'edit']],
        ['key' => 'purchase_returns', 'label' => 'Purchase Returns', 'perms' => ['view']],
        ['key' => 'purchase_payment_approval', 'label' => 'Purchase Payment Approval', 'perms' => ['view', 'approve']],
    ]],
    ['key' => 'sales', 'label' => 'Sales', 'icon' => 'fa-cash-register', 'accent' => '#059669', 'modules' => [
        ['key' => 'customers', 'label' => 'Business Customers', 'perms' => ['view', 'create', 'edit', 'delete']],
        ['key' => 'sales', 'label' => 'Sales Orders', 'perms' => ['view', 'create', 'pay']],
        ['key' => 'customer_order_queue', 'label' => 'Order Approval', 'perms' => ['view', 'approve', 'cancel_refund', 'assign_delivery']],
        ['key' => 'order_fulfillment', 'label' => 'Order Fulfillment', 'perms' => ['view', 'edit']],
        ['key' => 'invoice', 'label' => 'Sales Invoices', 'perms' => ['view']],
        ['key' => 'sales_returns', 'label' => 'Sales Return', 'perms' => ['view']],
        ['key' => 'salespeople', 'label' => 'Salespeople', 'perms' => ['view']],
        ['key' => 'territories', 'label' => 'Territories', 'perms' => ['view']],
        ['key' => 'visits', 'label' => 'Visits & Registration', 'perms' => ['view']],
        ['key' => 'sales_performance', 'label' => 'Sales Performance', 'perms' => ['view']],
        ['key' => 'commissions', 'label' => 'Commissions', 'perms' => ['view']],
        ['key' => 'catalog', 'label' => 'Product Catalog (Customer)', 'perms' => ['view']],
        ['key' => 'place_order', 'label' => 'Place Order (Customer)', 'perms' => ['view']],
        ['key' => 'my_orders', 'label' => 'My Orders (Customer)', 'perms' => ['view']],
    ]],
    ['key' => 'finance', 'label' => 'Finance', 'icon' => 'fa-coins', 'accent' => '#8b5cf6', 'modules' => [
        ['key' => 'finance_dashboard', 'label' => 'Finance Dashboard', 'perms' => ['view']],
        ['key' => 'finance_payments', 'label' => 'Payment Confirmation', 'perms' => ['view', 'confirm', 'unconfirm', 'process', 'pay']],
        ['key' => 'finance_refunds', 'label' => 'Refunds', 'perms' => ['view', 'confirm', 'unconfirm', 'process']],
        ['key' => 'supplier_payments', 'label' => 'Supplier Payments', 'perms' => ['view', 'edit', 'delete']],
        ['key' => 'expenses', 'label' => 'Expenses', 'perms' => ['view', 'create', 'edit', 'delete']],
        ['key' => 'income', 'label' => 'Income', 'perms' => ['view', 'create', 'edit', 'delete']],
        ['key' => 'credit_management', 'label' => 'Credit Management', 'perms' => ['view', 'approve', 'edit', 'delete', 'pay', 'override']],
        ['key' => 'credit_control', 'label' => 'Credit Control', 'perms' => ['view', 'approve', 'edit', 'delete', 'pay', 'override']],
        ['key' => 'credit_reports', 'label' => 'Credit Reports', 'perms' => ['view', 'export']],
    ]],
    ['key' => 'delivery', 'label' => 'Delivery', 'icon' => 'fa-truck', 'accent' => '#0ea5e9', 'modules' => [
        ['key' => 'delivery_dashboard', 'label' => 'Delivery Dashboard', 'perms' => ['view']],
        ['key' => 'delivery_management', 'label' => 'Delivery Hub', 'perms' => ['view']],
        ['key' => 'delivery_staff', 'label' => 'Driver Management', 'perms' => ['view', 'create', 'edit', 'delete']],
        ['key' => 'assign_deliveries', 'label' => 'Assign / Reassign Driver', 'perms' => ['view', 'create', 'edit', 'assign_delivery']],
        ['key' => 'delivery_tracking', 'label' => 'Delivery Monitoring', 'perms' => ['view', 'edit']],
        ['key' => 'delivery_confirmation', 'label' => 'Confirm Customer Receipt', 'perms' => ['view', 'confirm']],
        ['key' => 'delivery_returns', 'label' => 'Delivery Returns', 'perms' => ['view', 'create']],
        ['key' => 'delivery_history', 'label' => 'Delivery Reports', 'perms' => ['view']],
    ]],
    ['key' => 'reports', 'label' => 'Reports', 'icon' => 'fa-chart-line', 'accent' => '#f97316', 'modules' => [
        ['key' => 'reports', 'label' => 'View Reports', 'perms' => ['view', 'export']],
        ['key' => 'finance_reports', 'label' => 'Financial Reports', 'perms' => ['view', 'export']],
        ['key' => 'dashboard', 'label' => 'Dashboard Analytics', 'perms' => ['view']],
    ]],
    ['key' => 'user_management', 'label' => 'User Management', 'icon' => 'fa-users-gear', 'accent' => '#dc2626', 'modules' => [
        ['key' => 'users', 'label' => 'Users', 'perms' => ['view', 'create', 'edit', 'delete']],
        ['key' => 'roles', 'label' => 'Manage Roles & Permissions', 'perms' => ['view', 'manage']],
    ]],
    ['key' => 'settings', 'label' => 'Settings', 'icon' => 'fa-sliders', 'accent' => '#64748b', 'modules' => [
        ['key' => 'settings', 'label' => 'Company Profile / System Settings', 'perms' => ['view']],
        ['key' => 'backup', 'label' => 'Backup & Restore', 'perms' => ['view']],
        ['key' => 'system_sync', 'label' => 'Data Sync', 'perms' => ['view']],
        ['key' => 'cms', 'label' => 'Content Management (CMS)', 'perms' => ['view', 'create', 'edit', 'delete']],
        ['key' => 'info', 'label' => 'Information Center', 'perms' => ['view']],
    ]],
];

// Flat canonical module map (for backend validation, matches old $modules)
$modules = [];
foreach ($groups as $g) {
    foreach ($g['modules'] as $m) {
        $modules[$m['key']] = $m['label'];
    }
}
// module => [perm => true] of everything the UI manages (used to preserve legacy rows)
$managedPairs = [];
foreach ($groups as $g) {
    foreach ($g['modules'] as $m) {
        foreach ($m['perms'] as $p) $managedPairs[$m['key']][$p] = true;
    }
}

// Load roles + permissions
$roles = $pdo->query('SELECT * FROM roles ORDER BY is_system DESC, name ASC')->fetchAll();
$rolePermissions = [];
$rpStmt = $pdo->query('SELECT role_id, module, permission FROM role_permissions');
foreach ($rpStmt->fetchAll() as $rp) {
    $rolePermissions[(int) $rp['role_id']][$rp['module']][$rp['permission']] = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['_action'] ?? '';

    if ($action === 'create' || $action === 'save') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
        $color = trim($_POST['color'] ?? '');
        if (!preg_match('/^#[0-9a-fA-F]{3,9}$/', $color)) $color = '#3b82f6';

        if ($name === '') {
            $errors[] = 'Role name is required.';
        } else {
            $copyFromId = (int) ($_POST['copy_perms_from'] ?? 0);

            if ($action === 'create') {
                $stmt = $pdo->prepare('INSERT INTO roles (name, description, status, color, created_at) VALUES (:n, :d, :s, :c, NOW())');
                $stmt->execute(['n' => $name, 'd' => $description, 's' => $status, 'c' => $color]);
                $roleId = (int) $pdo->lastInsertId();
                if ($copyFromId > 0) copyRolePermissions($copyFromId, $roleId);
                $messages[] = ['type' => 'success', 'text' => "Role '{$name}' created."];
            } else {
                $roleId = (int) ($_POST['role_id'] ?? 0);
                if ($roleId <= 0) {
                    $errors[] = 'Invalid role.';
                } elseif ($roleId === 1) {
                    // The root slot is referenced by code (role_id 1 = Super Admin
                    // bypass), so its name must never be edited out from under it.
                    $pdo->prepare("UPDATE roles SET name = 'Super Admin', description = :d, status = :s, color = :c WHERE id = 1")
                        ->execute(['d' => $description, 's' => $status, 'c' => $color]);
                    $messages[] = ['type' => 'success', 'text' => 'Super Admin role updated (name is fixed).'];
                } else {
                    $pdo->prepare('UPDATE roles SET name = :n, description = :d, status = :s, color = :c WHERE id = :id')
                        ->execute(['n' => $name, 'd' => $description, 's' => $status, 'c' => $color, 'id' => $roleId]);
                    $messages[] = ['type' => 'success', 'text' => "Role '{$name}' saved."];
                }
            }

            if (isset($roleId) && $roleId > 0 && empty($errors)) {
                $submitted = $_POST['perms'] ?? [];
                $existing = $rolePermissions[$roleId] ?? [];
                $merged = $submitted;
                // Reserved operations (backup restore/delete, system settings,
                // user & role management, database repair) belong to the Super
                // Admin only — strip them from every other role on save.
                if ($roleId !== 1) {
                    $reservedModules = ['settings', 'users', 'roles', 'system_sync'];
                    $reservedPerms = ['backup' => ['restore', 'delete']];
                    $stripped = 0;
                    foreach ($reservedModules as $mod) {
                        if (isset($merged[$mod])) {
                            unset($merged[$mod]);
                            $stripped++;
                        }
                    }
                    foreach ($reservedPerms as $mod => $perms) {
                        if (!isset($merged[$mod])) {
                            continue;
                        }
                        foreach ($perms as $perm) {
                            if (isset($merged[$mod][$perm])) {
                                unset($merged[$mod][$perm]);
                                $stripped++;
                            }
                        }
                        if (!$merged[$mod]) {
                            unset($merged[$mod]);
                        }
                    }
                    if ($stripped > 0) {
                        $messages[] = ['type' => 'error', 'text' => 'Blocked ' . $stripped . ' reserved permission(s): backup restore/delete, system settings, user & role management and database repair tools can only be granted to the Super Admin role.'];
                    }
                }
                $legacyPairs = [];
                // Preserve any legacy (module, permission) pairs not represented in this UI
                foreach ($existing as $mod => $permMap) {
                    foreach ($permMap as $perm => $t) {
                        if (!isset($managedPairs[$mod][$perm])) {
                            $merged[$mod][$perm] = '1';
                            $legacyPairs[] = [$mod, $perm];
                        }
                    }
                }
                saveRolePermissions($roleId, $merged, $modules, $allPermissions);
                // Re-insert legacy pairs the shared save helper filters out (module not in $modules)
                $legacyIns = $pdo->prepare('INSERT IGNORE INTO role_permissions (role_id, module, permission) VALUES (:rid, :mod, :perm)');
                foreach ($legacyPairs as $lp) {
                    $legacyIns->execute(['rid' => $roleId, 'mod' => $lp[0], 'perm' => $lp[1]]);
                }
                clearPermissionCache();
                $rolePermissions[$roleId] = [];
                foreach ($merged as $mod => $permMap) {
                    foreach ($permMap as $perm => $t) {
                        $rolePermissions[$roleId][$mod][$perm] = true;
                    }
                }
                $messages[] = ['type' => 'success', 'text' => 'Permissions saved.'];
            }
            // Refresh roles list in case of create
            if ($action === 'create') {
                $roles = $pdo->query('SELECT * FROM roles ORDER BY is_system DESC, name ASC')->fetchAll();
                $roleId = $roleId ?? 0;
            } else {
                $roleId = (int) ($_POST['role_id'] ?? 0);
            }
        }
    }

    if ($action === 'clone') {
        $srcId = (int) ($_POST['role_id'] ?? 0);
        if ($srcId <= 0) {
            $errors[] = 'Select a role to clone.';
        } else {
            $src = $pdo->prepare('SELECT * FROM roles WHERE id = :id');
            $src->execute(['id' => $srcId]);
            $src = $src->fetch();
            if (!$src) {
                $errors[] = 'Source role not found.';
            } else {
                $newName = $src['name'] . ' (Copy)';
                $pdo->prepare('INSERT INTO roles (name, description, status, color, created_at) VALUES (:n, :d, :s, :c, NOW())')
                    ->execute(['n' => $newName, 'd' => $src['description'] ?? '', 's' => $src['status'] ?? 'active', 'c' => $src['color'] ?? '#3b82f6']);
                $newId = (int) $pdo->lastInsertId();
                copyRolePermissions($srcId, $newId);
                clearPermissionCache();
                $roles = $pdo->query('SELECT * FROM roles ORDER BY is_system DESC, name ASC')->fetchAll();
                $roleId = $newId;
                $messages[] = ['type' => 'success', 'text' => "Cloned '{$src['name']}' → '{$newName}'."];
            }
        }
    }

    if ($action === 'delete') {
        $deleteId = (int) ($_POST['delete_id'] ?? 0);
        if ($deleteId > 0) {
            $check = $pdo->prepare('SELECT is_system, name FROM roles WHERE id = :id');
            $check->execute(['id' => $deleteId]);
            $role = $check->fetch();
            if (!$role) {
                $errors[] = 'Role not found.';
            } elseif ($role['is_system']) {
                $errors[] = 'System roles cannot be deleted.';
            } else {
                $pdo->prepare('UPDATE users SET role_id = NULL WHERE role_id = :rid')->execute(['rid' => $deleteId]);
                $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :rid')->execute(['rid' => $deleteId]);
                $pdo->prepare('DELETE FROM roles WHERE id = :id')->execute(['id' => $deleteId]);
                clearPermissionCache();
                $roles = $pdo->query('SELECT * FROM roles ORDER BY is_system DESC, name ASC')->fetchAll();
                $messages[] = ['type' => 'success', 'text' => "Role '{$role['name']}' deleted."];
            }
        }
    }
}

// Reload roles + perms after POST (cheap, keeps single source of truth)
$roles = $pdo->query('SELECT * FROM roles ORDER BY is_system DESC, name ASC')->fetchAll();
$rolePermissions = [];
$rpStmt = $pdo->query('SELECT role_id, module, permission FROM role_permissions');
foreach ($rpStmt->fetchAll() as $rp) {
    $rolePermissions[(int) $rp['role_id']][$rp['module']][$rp['permission']] = true;
}

// Selected role
$isNew = isset($_GET['new']);
$selectedId = (int) ($_GET['role'] ?? ($roleId ?? 0));
$selected = null;
if (!$isNew && $selectedId > 0) {
    foreach ($roles as $r) {
        if ((int) $r['id'] === $selectedId) { $selected = $r; break; }
    }
}
if (!$isNew && !$selected && !empty($roles)) {
    $selected = $roles[0];
    $selectedId = (int) $selected['id'];
}
$curPerms = $selected ? ($rolePermissions[$selectedId] ?? []) : [];
$totalPermCount = 0;
foreach ($groups as $g) foreach ($g['modules'] as $m) $totalPermCount += count($m['perms']);
?>
<style>
.rp-wrap{display:flex;gap:20px;align-items:flex-start;padding:4px 0}
.rp-sidebar{width:280px;flex-shrink:0;background:var(--white);border:1px solid var(--neutral-300);border-radius:var(--radius-lg);box-shadow:var(--shadow-md);overflow:hidden;position:sticky;top:16px;max-height:calc(100vh - 80px);display:flex;flex-direction:column}
.rp-sidebar-head{padding:16px 16px 12px;border-bottom:1px solid var(--neutral-300);display:flex;align-items:center;justify-content:space-between}
.rp-sidebar-head h2{margin:0;font-size:1rem;font-weight:800;color:var(--neutral-900)}
.rp-sidebar-search{padding:12px;border-bottom:1px solid var(--neutral-300)}
.rp-sidebar-search input{width:100%;padding:9px 12px;border:1px solid var(--neutral-300);border-radius:10px;font-size:0.83rem;background:var(--neutral-100);color:var(--neutral-900)}
.rp-role-list{flex:1;overflow-y:auto;padding:8px}
.rp-role{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;cursor:pointer;border:1px solid transparent;transition:all .14s;margin-bottom:4px;position:relative}
.rp-role:hover{background:var(--neutral-100)}
.rp-role.active{background:var(--primary-50);border-color:var(--primary-200)}
.rp-role-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;border:2px solid rgba(0,0,0,.08)}
.rp-role-name{flex:1;min-width:0;font-size:0.84rem;font-weight:700;color:var(--neutral-900);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rp-role-badge{font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.3px;padding:2px 6px;border-radius:6px;background:var(--primary-100);color:var(--primary-600);flex-shrink:0}
.rp-role-actions{display:flex;gap:4px;align-items:center}
.rp-role-actions a,.rp-role-actions button{color:var(--neutral-500);background:none;border:none;padding:5px;border-radius:6px;cursor:pointer;font-size:0.78rem;text-decoration:none}
.rp-role-actions a:hover,.rp-role-actions button:hover{background:var(--neutral-200);color:var(--primary-600)}
.rp-role-actions .del:hover{color:var(--red-600)}
.rp-sidebar-foot{padding:10px 16px;border-top:1px solid var(--neutral-300);font-size:0.72rem;color:var(--neutral-500)}
.rp-main{flex:1;min-width:0;display:flex;flex-direction:column;gap:16px}
.rp-topbar{position:sticky;top:0;z-index:30;background:var(--white);border:1px solid var(--neutral-300);border-radius:var(--radius-lg);box-shadow:var(--shadow-md);padding:14px 18px}
.rp-topbar-row1{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between}
.rp-role-meta{display:flex;flex-direction:column;gap:12px;margin-top:14px;border-top:1px dashed var(--neutral-300);padding-top:14px}
.rp-meta-grid{display:grid;grid-template-columns:1fr 1fr 130px 130px;gap:12px;align-items:end}
.rp-field{display:flex;flex-direction:column;gap:5px}
.rp-field label{font-size:0.68rem;font-weight:700;color:var(--neutral-500);text-transform:uppercase;letter-spacing:.4px}
.rp-field input,.rp-field select{padding:9px 12px;border:1px solid var(--neutral-300);border-radius:10px;font-size:0.85rem;background:var(--white);color:var(--neutral-900);width:100%}
.rp-field input[type="color"]{padding:2px;height:40px;cursor:pointer}
.rp-btns{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.rp-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:10px;font-size:0.8rem;font-weight:700;cursor:pointer;border:1px solid transparent;transition:all .14s;text-decoration:none}
.rp-btn i{font-size:0.78rem}
.rp-btn-primary{background:var(--primary-600);color:#fff;box-shadow:var(--shadow-sm)}.rp-btn-primary:hover{background:var(--primary-700)}
.rp-btn-ghost{background:var(--white);color:var(--neutral-700);border-color:var(--neutral-300)}.rp-btn-ghost:hover{background:var(--neutral-100)}
.rp-btn-danger{background:var(--red-50);color:var(--red-600);border-color:var(--red-200)}.rp-btn-danger:hover{background:var(--red-100)}
.rp-btn-success{background:var(--green-600);color:#fff}.rp-btn-success:hover{background:var(--green-700)}
.rp-btn-sm{padding:6px 11px;font-size:0.74rem;border-radius:8px}
.rp-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.rp-toolbar input[type="text"]{flex:1;min-width:200px;padding:9px 12px;border:1px solid var(--neutral-300);border-radius:10px;font-size:0.85rem;background:var(--white);color:var(--neutral-900)}
.rp-toolbar input[type="text"]:focus,.rp-field input:focus,.rp-field select:focus{outline:none;border-color:var(--primary-500);box-shadow:var(--focus-ring)}
.rp-count{font-size:0.76rem;font-weight:700;color:var(--neutral-500);white-space:nowrap}
.rp-group{background:var(--white);border:1px solid var(--neutral-300);border-radius:var(--radius-lg);box-shadow:var(--shadow-md);overflow:hidden}
.rp-group.open{border-color:var(--primary-200)}
.rp-group-head{display:flex;align-items:center;gap:10px;padding:14px 18px;cursor:pointer;user-select:none;transition:background .13s}
.rp-group-head:hover{background:var(--neutral-100)}
.rp-group-icon{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;flex-shrink:0;font-size:0.95rem}
.rp-group-title{flex:1;min-width:0}
.rp-group-title h3{margin:0;font-size:0.92rem;font-weight:800;color:var(--neutral-900)}
.rp-group-title span{font-size:0.72rem;color:var(--neutral-500)}
.rp-group-tools{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.rp-group-tools .rp-btn{padding:5px 10px;font-size:0.7rem;border-radius:7px;font-weight:600}
.rp-group-chev{color:var(--neutral-400);transition:transform .2s;flex-shrink:0}
.rp-group.open .rp-group-chev{transform:rotate(180deg)}
.rp-group-body{display:none;border-top:1px solid var(--neutral-300);padding:16px 18px}
.rp-group.open .rp-group-body{display:block}
.rp-perm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:8px}
.rp-perm{display:flex;align-items:center;gap:10px;padding:8px 12px;border:1px solid var(--neutral-300);border-radius:10px;cursor:pointer;transition:all .13s;background:var(--white)}
.rp-perm:hover{border-color:var(--primary-300);background:var(--primary-50)}
.rp-perm input{width:17px;height:17px;accent-color:var(--primary-600);cursor:pointer;flex-shrink:0}
.rp-perm-label{font-size:0.82rem;font-weight:600;color:var(--neutral-800)}
.rp-perm-mod{font-size:0.68rem;color:var(--neutral-500);display:block;margin-top:1px}
.rp-perm.checked{border-color:var(--primary-300);background:var(--primary-50)}
.rp-empty{text-align:center;padding:60px 20px;color:var(--neutral-500);font-size:0.88rem}
.rp-hint{font-size:0.75rem;color:var(--neutral-500);margin-top:10px}
.rp-alerts{margin-bottom:0}
/* Preview modal */
.rp-modal-bg{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:9999;align-items:center;justify-content:center;padding:20px}
.rp-modal{background:var(--white);border-radius:var(--radius-lg);max-width:640px;width:100%;max-height:82vh;overflow-y:auto;box-shadow:var(--shadow-xl);padding:24px}
.rp-modal h3{margin:0 0 4px;font-size:1.05rem;color:var(--neutral-900)}
.rp-modal-sub{font-size:0.78rem;color:var(--neutral-500);margin-bottom:16px}
.rp-preview-group{margin-bottom:14px}
.rp-preview-group h4{margin:0 0 6px;font-size:0.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--neutral-500);display:flex;align-items:center;gap:7px}
.rp-preview-group h4 i{color:var(--primary-500)}
.rp-preview-item{display:inline-block;padding:4px 10px;margin:3px;border-radius:8px;background:var(--primary-50);border:1px solid var(--primary-200);font-size:0.75rem;font-weight:600;color:var(--primary-700)}
.rp-no-match{display:none}
@media(max-width:980px){.rp-wrap{flex-direction:column}.rp-sidebar{width:100%;position:static;max-height:none}.rp-meta-grid{grid-template-columns:1fr 1fr}}
@media(max-width:560px){.rp-meta-grid{grid-template-columns:1fr}}
</style>

<section>
    <div class="as-page-title"><i class="fas fa-users-gear" style="color:var(--primary-600)"></i> Roles &amp; Permissions</div>
    <div class="as-page-sub">Assign enterprise modules and granular permissions to each role</div>

    <div class="rp-wrap">
        <!-- LEFT PANEL -->
        <aside class="rp-sidebar">
            <div class="rp-sidebar-head">
                <h2><i class="fas fa-shield-halved" style="color:var(--primary-600);margin-right:6px"></i> Roles</h2>
                <a href="?page=roles&new=1" class="rp-btn rp-btn-primary rp-btn-sm"><i class="fas fa-plus"></i> New Role</a>
            </div>
            <div class="rp-sidebar-search">
                <input type="text" id="rp-role-search" placeholder="Search roles…" oninput="filterRoles(this.value)">
            </div>
            <div class="rp-role-list">
                <?php if (empty($roles)): ?>
                    <div class="rp-empty"><i class="fas fa-shield-halved"></i><br>No roles yet.<br>Create your first role.</div>
                <?php else: ?>
                    <?php foreach ($roles as $r): ?>
                        <?php $rcount = 0; foreach ($rolePermissions[(int)$r['id']] ?? [] as $pm) $rcount += count($pm); ?>
                        <div class="rp-role<?= $selected && (int)$selected['id'] === (int)$r['id'] ? ' active' : '' ?>" data-name="<?= htmlspecialchars(strtolower($r['name'])) ?>" onclick="window.location='?page=roles&role=<?= (int)$r['id'] ?>'">
                            <span class="rp-role-dot" style="background:<?= htmlspecialchars($r['color'] ?: '#3b82f6') ?>"></span>
                            <span class="rp-role-name" title="<?= htmlspecialchars($r['name']) ?>"><?= htmlspecialchars($r['name']) ?></span>
                            <?php if ($r['is_system']): ?><span class="rp-role-badge">System</span><?php endif; ?>
                            <span class="rp-role-actions" onclick="event.stopPropagation()">
                                <a href="?page=roles&role=<?= (int)$r['id'] ?>" title="Edit"><i class="fas fa-pen"></i></a>
                                <?php if (!$r['is_system']): ?>
                                    <form method="post" style="display:inline" onsubmit="return confirm('Delete role &quot;<?= htmlspecialchars(addslashes($r['name'])) ?>&quot;? Users with this role will be unassigned.')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="delete">
                                        <input type="hidden" name="delete_id" value="<?= (int)$r['id'] ?>">
                                        <button type="submit" class="del" title="Delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="rp-sidebar-foot"><?= count($roles) ?> role<?= count($roles) === 1 ? '' : 's' ?> defined</div>
        </aside>

        <!-- RIGHT PANEL -->
        <main class="rp-main">
            <?php if ($isNew): ?>
                <div class="rp-topbar">
                    <div class="rp-topbar-row1">
                        <div>
                            <div style="font-size:1.05rem;font-weight:800;color:var(--neutral-900)"><i class="fas fa-plus-circle" style="color:var(--primary-600);margin-right:6px"></i> Create New Role</div>
                            <div class="rp-hint" style="margin:2px 0 0">Define a name and pick which module permissions this role can access.</div>
                        </div>
                        <div class="rp-btns">
                            <a href="?page=roles" class="rp-btn rp-btn-ghost"><i class="fas fa-times"></i> Cancel</a>
                            <button form="rp-form" type="submit" class="rp-btn rp-btn-success"><i class="fas fa-check"></i> Create Role</button>
                        </div>
                    </div>
                    <div class="rp-role-meta">
                        <div class="rp-meta-grid">
                            <div class="rp-field"><label>Role Name</label><input form="rp-form" type="text" name="name" value="<?= htmlspecialchars($_GET['clone_name'] ?? '') ?>" required placeholder="e.g. Auditor"></div>
                            <div class="rp-field"><label>Description</label><input form="rp-form" type="text" name="description" placeholder="Optional description"></div>
                            <div class="rp-field"><label>Status</label><select form="rp-form" name="status"><option value="active" selected>Active</option><option value="inactive">Inactive</option></select></div>
                            <div class="rp-field"><label>Color</label><input form="rp-form" type="color" name="color" value="#3b82f6"></div>
                        </div>
                        <div class="rp-field" style="max-width:340px"><label>Copy Permissions From</label><select form="rp-form" name="copy_perms_from"><option value="0">— None (start empty) —</option><?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option><?php endforeach; ?></select></div>
                    </div>
                </div>
            <?php elseif ($selected): ?>
                <div class="rp-topbar">
                    <div class="rp-topbar-row1">
                        <div style="display:flex;align-items:center;gap:10px;min-width:0">
                            <span class="rp-role-dot" style="width:14px;height:14px;background:<?= htmlspecialchars($selected['color'] ?: '#3b82f6') ?>"></span>
                            <div style="min-width:0">
                                <div style="font-size:1.05rem;font-weight:800;color:var(--neutral-900);display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                                    <?= htmlspecialchars($selected['name']) ?>
                                    <?php if ($selected['is_system']): ?><span class="rp-role-badge">System</span><?php endif; ?>
                                    <span class="rp-role-badge" style="background:<?= ($selected['status'] ?? 'active') === 'active' ? 'var(--green-100)' : 'var(--red-100)' ?>;color:<?= ($selected['status'] ?? 'active') === 'active' ? 'var(--green-600)' : 'var(--red-600)' ?>"><?= htmlspecialchars($selected['status'] ?? 'active') ?></span>
                                </div>
                                <div class="rp-hint" style="margin:2px 0 0"><?= htmlspecialchars((string) ($selected['description'] ?? '')) ?: 'No description' ?></div>
                            </div>
                        </div>
                        <div class="rp-btns">
                            <?php if (!$selected['is_system']): ?>
                                <form method="post" style="display:inline" onsubmit="return confirm('Clone this role to a new role?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="clone">
                                    <input type="hidden" name="role_id" value="<?= (int)$selected['id'] ?>">
                                    <button type="submit" class="rp-btn rp-btn-ghost"><i class="fas fa-copy"></i> Clone Role</button>
                                </form>
                            <?php endif; ?>
                            <button type="button" class="rp-btn rp-btn-ghost" onclick="previewAccess()"><i class="fas fa-eye"></i> Preview Access</button>
                            <a href="?page=roles&role=<?= (int)$selected['id'] ?>" class="rp-btn rp-btn-ghost"><i class="fas fa-undo"></i> Discard</a>
                            <button form="rp-form" type="submit" class="rp-btn rp-btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                        </div>
                    </div>
                    <div class="rp-role-meta">
                        <div class="rp-meta-grid">
                            <div class="rp-field"><label>Role Name</label><input form="rp-form" type="text" name="name" value="<?= htmlspecialchars($selected['name']) ?>" required></div>
                            <div class="rp-field"><label>Description</label><input form="rp-form" type="text" name="description" value="<?= htmlspecialchars((string) ($selected['description'] ?? '')) ?>" placeholder="Optional description"></div>
                            <div class="rp-field"><label>Status</label><select form="rp-form" name="status"><option value="active"<?= ($selected['status'] ?? 'active') === 'active' ? ' selected' : '' ?>>Active</option><option value="inactive"<?= ($selected['status'] ?? 'active') === 'inactive' ? ' selected' : '' ?>>Inactive</option></select></div>
                            <div class="rp-field"><label>Color</label><input form="rp-form" type="color" name="color" value="<?= htmlspecialchars($selected['color'] ?: '#3b82f6') ?>"></div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="rp-empty"><i class="fas fa-shield-halved" style="font-size:2rem;color:var(--neutral-400)"></i><br><br>Select a role on the left, or create a new role.</div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error" style="margin-bottom:0;padding:12px 16px;background:var(--red-50);color:var(--red-700);border:1px solid var(--red-200);border-radius:10px"><?= htmlspecialchars(implode('; ', $errors)) ?></div>
            <?php endif; ?>
            <?php foreach ($messages as $m): ?>
                <div class="alert alert-<?= htmlspecialchars($m['type']) ?>" style="margin-bottom:0;padding:12px 16px;background:<?= $m['type'] === 'success' ? 'var(--green-50)' : 'var(--red-50)' ?>;color:<?= $m['type'] === 'success' ? 'var(--green-700)' : 'var(--red-700)' ?>;border:1px solid <?= $m['type'] === 'success' ? 'var(--green-200)' : 'var(--red-200)' ?>;border-radius:10px"><i class="fas fa-<?= $m['type'] === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i> <?= htmlspecialchars($m['text']) ?></div>
            <?php endforeach; ?>

            <?php if ($isNew || $selected): ?>
            <div class="rp-toolbar">
                <input type="text" id="rp-search" placeholder="Search permissions…  (e.g. approve, invoice, stock)" oninput="filterPerms(this.value)">
                <button type="button" class="rp-btn rp-btn-ghost rp-btn-sm" onclick="expandAll(true)"><i class="fas fa-expand"></i> Expand All</button>
                <button type="button" class="rp-btn rp-btn-ghost rp-btn-sm" onclick="expandAll(false)"><i class="fas fa-compress"></i> Collapse All</button>
                <button type="button" class="rp-btn rp-btn-ghost rp-btn-sm" onclick="groupAll(true)"><i class="fas fa-check-double"></i> Select All</button>
                <button type="button" class="rp-btn rp-btn-ghost rp-btn-sm" onclick="groupAll(false)"><i class="fas fa-ban"></i> Clear All</button>
                <span class="rp-count" id="rp-counter">0 / <?= $totalPermCount ?> selected</span>
            </div>

            <form method="post" id="rp-form">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="<?= $isNew ? 'create' : 'save' ?>">
                <?php if (!$isNew): ?><input type="hidden" name="role_id" value="<?= (int)$selected['id'] ?>"><?php endif; ?>

                <?php foreach ($groups as $gi => $g): ?>
                <?php $gSel = 0; foreach ($g['modules'] as $m) foreach ($m['perms'] as $p) if (isset($curPerms[$m['key']][$p])) $gSel++; ?>
                <?php $gTotal = 0; foreach ($g['modules'] as $m) $gTotal += count($m['perms']); ?>
                <div class="rp-group open" id="rp-g-<?= $g['key'] ?>" data-group="<?= $g['key'] ?>">
                    <div class="rp-group-head" onclick="rpToggleGroup('<?= $g['key'] ?>', event)">
                        <span class="rp-group-icon" style="background:<?= $g['accent'] ?>"><i class="fas <?= $g['icon'] ?>"></i></span>
                        <div class="rp-group-title">
                            <h3><?= $g['label'] ?></h3>
                            <span id="rp-gc-<?= $g['key'] ?>"><?= $gSel ?> / <?= $gTotal ?> selected</span>
                        </div>
                        <div class="rp-group-tools" onclick="event.stopPropagation()">
                            <button type="button" class="rp-btn rp-btn-ghost" onclick="groupSelect('<?= $g['key'] ?>', true)">Select All</button>
                            <button type="button" class="rp-btn rp-btn-ghost" onclick="groupSelect('<?= $g['key'] ?>', false)">Clear All</button>
                            <button type="button" class="rp-btn rp-btn-ghost" onclick="groupExpand('<?= $g['key'] ?>', true)"><i class="fas fa-expand"></i> Expand</button>
                            <button type="button" class="rp-btn rp-btn-ghost" onclick="groupExpand('<?= $g['key'] ?>', false)"><i class="fas fa-compress"></i> Collapse</button>
                        </div>
                        <i class="fas fa-chevron-down rp-group-chev"></i>
                    </div>
                    <div class="rp-group-body" id="rp-b-<?= $g['key'] ?>">
                        <div class="rp-perm-grid">
                            <?php foreach ($g['modules'] as $m): ?>
                                <?php foreach ($m['perms'] as $p): ?>
                                    <?php $checked = isset($curPerms[$m['key']][$p]); ?>
                                    <label class="rp-perm<?= $checked ? ' checked' : '' ?>">
                                        <input type="checkbox" name="perms[<?= $m['key'] ?>][<?= $p ?>]" value="1"<?= $checked ? ' checked' : '' ?> onchange="togglePerm(this)">
                                        <span>
                                            <span class="rp-perm-label"><?= htmlspecialchars($permLabels[$p]) ?> <?= htmlspecialchars($m['label']) ?></span>
                                            <span class="rp-perm-mod"><?= htmlspecialchars($m['key']) ?>.<?= $p ?></span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </form>
            <div class="rp-hint"><i class="fas fa-info-circle"></i> Permissions apply when a user is assigned this role. The <strong>Super Admin</strong> role always has full access and cannot be edited away — its permissions are bypassed in code. Backup restore/delete, system settings, user &amp; role management and database repair tools are reserved for it and are stripped from every other role.</div>
            <?php endif; ?>
        </main>
    </div>
</section>

<div class="rp-modal-bg" id="rp-preview" onclick="if(event.target===this)closePreview()">
    <div class="rp-modal">
        <h3><i class="fas fa-eye" style="color:var(--primary-600)"></i> Access Preview</h3>
        <div class="rp-modal-sub" id="rp-preview-title">Summary of modules this role can access based on selected permissions.</div>
        <div id="rp-preview-body"></div>
        <div style="text-align:right;margin-top:16px"><button type="button" class="rp-btn rp-btn-primary" onclick="closePreview()">Close</button></div>
    </div>
</div>

<script>
var groupKeys = [<?= implode(',', array_map(function ($g) { return "'" . $g['key'] . "'"; }, $groups)) ?>];
var groupTitles = {<?= implode(',', array_map(function ($g) { return "'" . $g['key'] . "': '" . addslashes($g['label']) . "'"; }, $groups)) ?>};
var groupIcons = {<?= implode(',', array_map(function ($g) { return "'" . $g['key'] . "': '" . $g['icon'] . "'"; }, $groups)) ?>};

function togglePerm(cb) {
    var label = cb.closest('.rp-perm');
    if (label) label.classList.toggle('checked', cb.checked);
    updateCounters();
    updateGroupCounters();
}
function permOf(cb) {
    var mod = cb.name.match(/perms\[([^\]]+)\]/);
    return mod ? mod[1] : '';
}
function rpToggleGroup(key, ev) {
    var el = document.getElementById('rp-g-' + key);
    el.classList.toggle('open');
}
function groupExpand(key, open) {
    var el = document.getElementById('rp-g-' + key);
    el.classList.toggle('open', !!open);
}
function groupSelect(key, checked) {
    document.querySelectorAll('#rp-g-' + key + ' input[type="checkbox"]').forEach(function(cb) {
        cb.checked = checked;
        cb.closest('.rp-perm').classList.toggle('checked', checked);
    });
    updateCounters();
    updateGroupCounters();
}
function groupAll(checked) {
    document.querySelectorAll('#rp-form input[type="checkbox"]').forEach(function(cb) {
        cb.checked = checked;
        cb.closest('.rp-perm').classList.toggle('checked', checked);
    });
    updateCounters();
    updateGroupCounters();
}
function expandAll(open) {
    document.querySelectorAll('.rp-group').forEach(function(el) { el.classList.toggle('open', !!open); });
}
function filterPerms(q) {
    q = (q || '').toLowerCase().trim();
    document.querySelectorAll('#rp-form .rp-perm').forEach(function(el) {
        var txt = el.textContent.toLowerCase();
        var show = !q || txt.indexOf(q) !== -1;
        el.classList.toggle('rp-no-match', !show);
        if (!q) el.style.display = '';
    });
    document.querySelectorAll('.rp-group').forEach(function(g) {
        var key = g.getAttribute('data-group');
        var visible = g.querySelectorAll('.rp-perm:not(.rp-no-match)').length;
        g.style.display = visible ? '' : 'none';
    });
}
function filterRoles(q) {
    q = (q || '').toLowerCase().trim();
    document.querySelectorAll('.rp-role').forEach(function(el) {
        var name = el.getAttribute('data-name') || '';
        el.style.display = (!q || name.indexOf(q) !== -1) ? '' : 'none';
    });
}
function updateCounters() {
    var total = document.querySelectorAll('#rp-form input[type="checkbox"]').length;
    var sel = document.querySelectorAll('#rp-form input[type="checkbox"]:checked').length;
    document.getElementById('rp-counter').textContent = sel + ' / ' + total + ' selected';
}
function updateGroupCounters() {
    groupKeys.forEach(function(key) {
        var el = document.getElementById('rp-g-' + key);
        if (!el) return;
        var boxes = el.querySelectorAll('input[type="checkbox"]');
        var sel = el.querySelectorAll('input[type="checkbox"]:checked').length;
        var lbl = document.getElementById('rp-gc-' + key);
        if (lbl) lbl.textContent = sel + ' / ' + boxes.length + ' selected';
    });
}
function previewAccess() {
    var body = document.getElementById('rp-preview-body');
    var title = document.getElementById('rp-preview-title');
    title.textContent = 'Summary of modules this role can access based on selected permissions.';
    var html = '';
    var any = false;
    groupKeys.forEach(function(key) {
        var el = document.getElementById('rp-g-' + key);
        if (!el) return;
        var items = [];
        el.querySelectorAll('input[type="checkbox"]:checked').forEach(function(cb) {
            var label = cb.closest('.rp-perm');
            if (label) items.push(label.querySelector('.rp-perm-label').textContent.trim());
        });
        if (items.length) {
            any = true;
            html += '<div class="rp-preview-group"><h4><i class="fas ' + groupIcons[key] + '"></i> ' + groupTitles[key] + '</h4><div>' +
                items.map(function(i) { return '<span class="rp-preview-item">' + i.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</span>'; }).join('') +
                '</div></div>';
        }
    });
    if (!any) {
        html = '<div class="rp-empty" style="padding:20px"><i class="fas fa-eye-slash"></i><br><br>No permissions selected for this role.</div>';
    }
    body.innerHTML = html;
    document.getElementById('rp-preview').style.display = 'flex';
}
function closePreview() {
    document.getElementById('rp-preview').style.display = 'none';
}
document.addEventListener('DOMContentLoaded', function() {
    updateCounters();
    updateGroupCounters();
});
</script>
