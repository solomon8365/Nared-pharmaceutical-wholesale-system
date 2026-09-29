<?php
$pdo = getPDO();
$messages = [];
$errors = [];
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $current = $_POST['current_password'] ?? '';
    $newPass = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($current === '' || $newPass === '' || $confirm === '') {
        $errors[] = 'All fields are required.';
    } elseif (strlen($newPass) < 6) {
        $errors[] = 'New password must be at least 6 characters.';
    } elseif ($newPass !== $confirm) {
        $errors[] = 'New passwords do not match.';
    } else {
        $stmt = $pdo->prepare('SELECT password FROM users WHERE id = :id');
        $stmt->execute(['id' => $user['id']]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($current, $row['password'])) {
            $errors[] = 'Current password is incorrect.';
        } else {
            userUpdatePassword($pdo, (int) $user['id'], $newPass);
            $messages[] = ['type' => 'success', 'text' => 'Password changed successfully.'];
        }
    }
}
?>
<section style="max-width:500px">
    <h1>Change Password</h1>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
    <?php endif; ?>
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>
    <div class="panel">
        <form method="post">
            <?= csrf_field() ?>
            <label>Current Password<input type="password" name="current_password" required></label>
            <label>New Password<input type="password" name="new_password" required minlength="6"></label>
            <label>Confirm New Password<input type="password" name="confirm_password" required minlength="6"></label>
            <button type="submit"><i class="fas fa-save"></i> Update Password</button>
        </form>
    </div>
</section>
