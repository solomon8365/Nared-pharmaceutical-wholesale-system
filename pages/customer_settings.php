<?php
$pdo = getPDO();
require_once __DIR__ . '/../includes/auto_migrate.php';
ensureCustomerSelfRegistrationMigration($pdo);
$messages = [];
$errors = [];
$cid = customerAccountCustomerId();

if ($cid === null) {
    echo '<section><h1>Profile Settings</h1><p class="hint">No customer profile linked to your account.</p></section>';
    return;
}

$stmt = $pdo->prepare('SELECT * FROM customers WHERE id = :id');
$stmt->execute(['id' => $cid]);
$customer = $stmt->fetch();

if (!$customer) {
    echo '<section><h1>Profile Settings</h1><p class="hint">Customer profile not found.</p></section>';
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $contactPerson = trim($_POST['contact_person'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['physical_address'] ?? '');
    $notifyEmail = isset($_POST['notify_email']) ? 1 : 0;
    $notifySms = isset($_POST['notify_sms']) ? 1 : 0;
    $pdo->prepare('UPDATE customers SET contact_person = :cp, phone = :ph, email = :em, physical_address = :ad, notify_email = :ne, notify_sms = :ns WHERE id = :id')
        ->execute(['cp' => $contactPerson, 'ph' => $phone, 'em' => $email ?: null, 'ad' => $address ?: null, 'ne' => $notifyEmail, 'ns' => $notifySms, 'id' => $cid]);
    $messages[] = ['type' => 'success', 'text' => 'Profile updated.'];
    $customer['contact_person'] = $contactPerson;
    $customer['phone'] = $phone;
    $customer['email'] = $email;
    $customer['physical_address'] = $address;
    $customer['notify_email'] = $notifyEmail;
    $customer['notify_sms'] = $notifySms;
}
?>
<section style="max-width:600px">
    <h1>Profile Settings</h1>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
    <?php endif; ?>
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>
    <div class="panel">
        <h2>Business Profile</h2>
        <p style="color:var(--neutral-500);margin:-8px 0 16px"><?= htmlspecialchars($customer['name']) ?></p>
        <form method="post">
            <?= csrf_field() ?>
            <label>Business Name<strong style="display:block;padding:6px 0;color:var(--neutral-500)"><?= htmlspecialchars($customer['name']) ?></strong></label>
            <label>Contact Person<input type="text" name="contact_person" value="<?= htmlspecialchars($customer['contact_person'] ?? '') ?>"></label>
            <label>Phone<input type="text" name="phone" value="<?= htmlspecialchars($customer['phone'] ?? '') ?>"></label>
            <label>Email<input type="email" name="email" value="<?= htmlspecialchars($customer['email'] ?? '') ?>"></label>
            <label>Physical Address<textarea name="physical_address" rows="3"><?= htmlspecialchars($customer['physical_address'] ?? '') ?></textarea></label>

            <?php if (!empty($customer['payment_terms'])): ?>
            <div style="margin:12px 0">
                <strong style="display:block;font-size:0.85rem;color:var(--neutral-500);margin-bottom:4px">Payment Terms</strong>
                <span style="color:var(--neutral-700)"><?= htmlspecialchars($customer['payment_terms']) ?></span>
            </div>
            <?php endif; ?>

            <h3 style="margin:16px 0 8px;font-size:0.95rem">Notifications</h3>
            <label style="display:flex;align-items:center;gap:10px;margin:6px 0">
                <input type="checkbox" name="notify_email" value="1" <?= !empty($customer['notify_email']) ? 'checked' : '' ?> style="width:18px;height:18px">
                <span>Email alerts for order status changes</span>
            </label>
            <label style="display:flex;align-items:center;gap:10px;margin:6px 0">
                <input type="checkbox" name="notify_sms" value="1" <?= !empty($customer['notify_sms']) ? 'checked' : '' ?> style="width:18px;height:18px">
                <span>SMS alerts for order status changes</span>
            </label>

            <button type="submit" style="margin-top:12px"><i class="fas fa-save"></i> Save Changes</button>
        </form>
    </div>
</section>
