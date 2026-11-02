<?php
requirePermission('suppliers', 'view');
require_once __DIR__ . '/../includes/stock_helper.php';
$pdo = getPDO();
$messages = [];

$editSupplier = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int) $_GET['edit']]);
    $editSupplier = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['_action'] ?? 'create';

    if ($action === 'update') {
        requirePermission('suppliers', 'edit');
        $supplierId = (int) ($_POST['supplier_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['supplier_code'] ?? '');
        $contact = trim($_POST['contact'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['physical_address'] ?? '');
        $status = in_array($_POST['supplier_status'] ?? 'active', ['active', 'inactive'], true) ? $_POST['supplier_status'] : 'active';

        if ($supplierId <= 0 || $name === '') {
            $messages[] = ['type' => 'error', 'text' => 'Supplier name is required.'];
        } else {
            try {
                $stmt = $pdo->prepare('UPDATE suppliers SET name = :name, supplier_code = :code, contact_person = :contact, phone = :phone, email = :email, physical_address = :addr, supplier_status = :sstatus WHERE id = :id');
                $stmt->execute(['name' => $name, 'code' => $code ?: null, 'contact' => $contact, 'phone' => $phone, 'email' => $email, 'addr' => $address, 'sstatus' => $status, 'id' => $supplierId]);
                redirect_with_flash('?page=suppliers', 'Supplier updated successfully.');
            } catch (Throwable $e) {
                $messages[] = ['type' => 'error', 'text' => 'Could not update supplier: ' . $e->getMessage()];
            }
        }
    } else {
        requirePermission('suppliers', 'create');
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['supplier_code'] ?? '');
        $contact = trim($_POST['contact'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['physical_address'] ?? '');
        $status = in_array($_POST['supplier_status'] ?? 'active', ['active', 'inactive'], true) ? $_POST['supplier_status'] : 'active';

        if ($name === '') {
            $messages[] = ['type' => 'error', 'text' => 'Supplier name is required.'];
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO suppliers (name, supplier_code, contact_person, phone, email, physical_address, supplier_status, created_at) VALUES (:name, :code, :contact, :phone, :email, :addr, :sstatus, NOW())');
                $stmt->execute(['name' => $name, 'code' => $code ?: null, 'contact' => $contact, 'phone' => $phone, 'email' => $email, 'addr' => $address, 'sstatus' => $status]);
                redirect_with_flash('?page=suppliers', 'Supplier added successfully.');
            } catch (Throwable $e) {
                $messages[] = ['type' => 'error', 'text' => 'Could not add supplier: ' . $e->getMessage()];
            }
        }
    }
}

$allSuppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name')->fetchAll();
?>
<style>
.sup-wrap{max-width:720px;margin:0 auto}
.sup-header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px}
.sup-header h1{font-size:1.5rem;font-weight:800;color:#0f172a;margin:0;display:flex;align-items:center;gap:10px}
.sup-panel{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.sup-panel h2{font-size:1rem;font-weight:700;color:#0f172a;margin:0 0 14px;display:flex;align-items:center;gap:8px}
.sup-form label{display:block;font-size:.8rem;font-weight:600;color:#475569;margin-bottom:4px}
.sup-form input,.sup-form select,.sup-form textarea{width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;font-family:inherit;box-sizing:border-box}
.sup-form textarea{resize:vertical;min-height:60px}
.sup-form .row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.sup-form .actions{display:flex;gap:8px;margin-top:14px;flex-wrap:wrap}
.sup-btn{padding:9px 16px;border:none;border-radius:8px;font-size:.82rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-family:inherit;text-decoration:none}
.sup-btn-primary{background:#2563eb;color:#fff}
.sup-btn-success{background:#059669;color:#fff}
.sup-btn-outline{background:transparent;border:1px solid #cbd5e1;color:#475569}
</style>

<section class="sup-wrap">
    <div class="sup-header">
        <h1><i class="fas fa-truck"></i> <?= $editSupplier ? 'Edit Supplier' : 'Add Supplier' ?></h1>
        <a href="?page=suppliers" class="sup-btn sup-btn-outline"><i class="fas fa-arrow-left"></i> Back to List</a>
    </div>

    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>" style="margin-bottom:12px"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <div class="sup-panel">
        <h2><i class="fas fa-building"></i> Supplier Details</h2>
        <form method="post" class="sup-form" data-swal-exclude="true">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="<?= $editSupplier ? 'update' : 'create' ?>">
            <?php if ($editSupplier): ?><input type="hidden" name="supplier_id" value="<?= htmlspecialchars((string) $editSupplier['id']) ?>"><?php endif; ?>

            <div class="row">
                <label>Name *
                    <input type="text" name="name" required placeholder="Supplier name" value="<?= htmlspecialchars($editSupplier['name'] ?? '') ?>">
                </label>
                <label>Supplier Code
                    <input type="text" name="supplier_code" placeholder="e.g. SUP-001" value="<?= htmlspecialchars($editSupplier['supplier_code'] ?? '') ?>">
                </label>
            </div>
            <div class="row">
                <label>Status
                    <select name="supplier_status">
                        <option value="active" <?= ($editSupplier['supplier_status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= ($editSupplier['supplier_status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </label>
                <label>Contact Person
                    <input type="text" name="contact" value="<?= htmlspecialchars((string) ($editSupplier['contact_person'] ?? '')) ?>">
                </label>
            </div>
            <div class="row">
                <label>Phone
                    <input type="text" name="phone" value="<?= htmlspecialchars((string) ($editSupplier['phone'] ?? '')) ?>">
                </label>
                <label>Email
                    <input type="email" name="email" value="<?= htmlspecialchars($editSupplier['email'] ?? '') ?>">
                </label>
            </div>
            <label>Physical Address
                <textarea name="physical_address"><?= htmlspecialchars($editSupplier['physical_address'] ?? '') ?></textarea>
            </label>

            <div class="actions">
                <button type="submit" class="sup-btn <?= $editSupplier ? 'sup-btn-success' : 'sup-btn-primary' ?>">
                    <i class="fas fa-<?= $editSupplier ? 'save' : 'plus' ?>"></i> <?= $editSupplier ? 'Update Supplier' : 'Create Supplier' ?>
                </button>
                <a href="?page=suppliers" class="sup-btn sup-btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</section>