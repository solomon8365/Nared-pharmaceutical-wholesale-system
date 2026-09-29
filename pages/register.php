<?php
requirePermission('customers', 'view');
$pdo = getPDO();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    requirePermission('customers', 'create');
    $businessName = trim($_POST['business_name'] ?? '');
    $contactPerson = trim($_POST['contact_person'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password_confirm'] ?? '';

    if ($businessName === '' || $phone === '' || $username === '' || $password === '') {
        $errors[] = 'Business name, phone, username, and password are required.';
    } elseif (strlen($username) < 3) {
        $errors[] = 'Username must be at least 3 characters.';
    } elseif (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    } elseif ($password !== $password2) {
        $errors[] = 'Passwords do not match.';
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO customers (name, contact_person, phone, created_at) VALUES (:name, :contact, :phone, NOW())');
            $stmt->execute([
                'name' => $businessName,
                'contact' => $contactPerson !== '' ? $contactPerson : null,
                'phone' => $phone,
            ]);
            $customerId = (int) $pdo->lastInsertId();

            userCreate($pdo, $username, $password, $businessName, 'customer', 2, $customerId);

            $pdo->commit();
            $messages[] = ['type' => 'success', 'text' => "Customer account '{$businessName}' created."];
        } catch (PDOException $e) {
            $pdo->rollBack();
            if (($e->errorInfo[1] ?? 0) === 1062) {
                $errors[] = 'That username is already taken.';
            } else {
                $errors[] = 'Registration failed. Please try again.';
            }
        }
    }
}
?>
<section>
    <h1>Register Customer</h1>
    <p class="hint">Create a new customer account. The customer will receive their credentials to log in.</p>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
    <?php endif; ?>
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <div class="panel" style="max-width:500px">
        <form method="post" action="index.php?page=register">
            <?= csrf_field() ?>
            <label>Business / pharmacy name <input type="text" name="business_name" value="<?= htmlspecialchars($_POST['business_name'] ?? '') ?>" required></label>
            <label>Contact person <input type="text" name="contact_person" value="<?= htmlspecialchars($_POST['contact_person'] ?? '') ?>" placeholder="Optional"></label>
            <label>Phone <input type="text" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" required></label>
            <label>Username <input type="text" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required minlength="3"></label>
            <label>Password <input type="password" name="password" required minlength="6"></label>
            <label>Confirm password <input type="password" name="password_confirm" required minlength="6"></label>
            <button type="submit"><i class="fas fa-user-plus"></i> Create Customer Account</button>
        </form>
    </div>
</section>
