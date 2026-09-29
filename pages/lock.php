<?php
// Inactivity Lock Screen — requires an authenticated (locked) session.
// The session is LOCKED, not logged out, so the user can resume after entering
// their password. This never triggers the account lock for failed logins.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Must have a logged-in session to reach the lock screen.
$lockUser = currentUser();
if (!$lockUser) {
    header('Location: index.php?page=login');
    exit;
}

// ── AJAX unlock handler (from the in-page lock modal) ───────────────────
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === 'unlock';
if ($isAjax) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }
    $csrf = $_POST['csrf'] ?? '';
    if (!csrf_token() || !hash_equals(csrf_token(), $csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh and try again.']);
        exit;
    }
    $password = (string) ($_POST['password'] ?? '');
    if ($password === '') {
        echo json_encode(['success' => false, 'message' => 'Password is required.']);
        exit;
    }
    if (unlockSession($password)) {
        $redirect = landingAfterUnlock();
        echo json_encode(['success' => true, 'redirect' => $redirect]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Incorrect password. Please try again.']);
    }
    exit;
}

// ── Full page unlock handling ───────────────────────────────────────────
$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$isAjax) {
    require_csrf();
    $password = (string) ($_POST['password'] ?? '');
    if ($password === '') {
        $errors[] = 'Password is required.';
    } elseif (unlockSession($password)) {
        header('Location: index.php?page=' . landingAfterUnlock());
        exit;
    } else {
        $errors[] = 'Incorrect password. Please try again.';
    }
}

// Session is not actually locked -> send to the appropriate landing page.
// (Only the timeout -> lock flow or the client idle modal should land here while locked.)
if (empty($_SESSION['locked'])) {
    header('Location: index.php?page=' . landingAfterUnlock());
    exit;
}

$userName = $lockUser['full_name'] ?? $lockUser['username'] ?? '';
$userAvatar = strtoupper(substr(trim($userName !== '' ? $userName : 'U'), 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Locked — <?= htmlspecialchars(APP_NAME) ?></title>
    <?= favicon_tags() ?>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
    <style>
        .lock-page { background: radial-gradient(ellipse at 15% 30%, rgba(37,99,235,0.08) 0%, transparent 50%), radial-gradient(ellipse at 85% 70%, rgba(5,150,105,0.06) 0%, transparent 50%), linear-gradient(160deg,#eff6ff 0%,#f0fdf4 100%) !important; }
        .lock-card { max-width: 400px; width: 92%; margin: 6vh auto 0; background: var(--white); border-radius: var(--radius-xl); box-shadow: var(--shadow-xl); overflow: hidden; border: 1px solid var(--neutral-300); }
        .lock-card-head { background: linear-gradient(160deg,#1e3a5f,#1e40af 50%,#1a56db); padding: 28px 30px 24px; text-align: center; position: relative; overflow: hidden; }
        .lock-icon { width: 64px; height: 64px; margin: 0 auto; border-radius: 50%; background: rgba(255,255,255,0.16); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 1.6rem; box-shadow: 0 4px 14px rgba(0,0,0,0.2); }
        .lock-avatar { width: 74px; height: 74px; margin: -37px auto 0; border-radius: 50%; background: linear-gradient(180deg,#00a35e,#006838); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.7rem; font-weight: 800; border: 4px solid var(--white); box-shadow: 0 4px 12px rgba(0,0,0,0.15); position: relative; z-index: 2; }
        .lock-body { padding: 6px 30px 28px; text-align: center; }
        .lock-body h1 { font-size: 1.25rem; font-weight: 800; color: var(--neutral-900); margin: 6px 0 4px; }
        .lock-body p { color: var(--neutral-500); font-size: .85rem; margin: 0 0 20px; }
        .lock-hint { color: var(--neutral-500); font-size: .75rem; margin-top: 14px; display: flex; align-items: center; justify-content: center; gap: 6px; }
        .lock-field { margin-bottom: 14px; }
        .lock-field label { display: block; text-align: left; font-size: .8rem; font-weight: 700; color: var(--neutral-700); margin-bottom: 6px; }
        .lock-input-wrap { display: flex; align-items: center; gap: 10px; border: 2px solid var(--neutral-400); border-radius: var(--radius-sm); padding: 10px 12px; background: var(--white); }
        .lock-input-wrap:focus-within { border-color: var(--brown-500); box-shadow: var(--focus-ring); }
        .lock-input-wrap svg { flex: 0 0 auto; color: var(--neutral-500); }
        .lock-input-wrap input { border: none; outline: none; flex: 1; font: inherit; font-size: .9rem; background: transparent; color: var(--neutral-900); width: 100%; }
        .lock-actions { display: flex; gap: 10px; }
        .lock-actions button[type="submit"] { flex: 1; }
        .lock-unlock-btn { background: linear-gradient(180deg,#00a35e,#006838); border: 1px solid #004d2b; color: #fff; font-weight: 800; padding: 11px 16px; border-radius: var(--radius-sm); cursor: pointer; font-size: .9rem; }
        .lock-logout-btn { background: var(--neutral-100); border: 1px solid var(--neutral-300); color: var(--neutral-700); font-weight: 700; padding: 11px 16px; border-radius: var(--radius-sm); cursor: pointer; font-size: .85rem; }
        .lock-err { background: var(--red-50); color: var(--red-700); border: 1px solid var(--red-200); padding: 10px 12px; border-radius: var(--radius-sm); font-size: .82rem; margin-bottom: 14px; text-align: left; }
        .lock-footer-link { color: var(--brown-600); font-weight: 600; text-decoration: none; font-size: .82rem; }
    </style>
</head>
<body class="lock-page">
<div class="lock-card">
    <div class="lock-card-head">
        <div class="lock-icon"><i class="fas fa-lock"></i></div>
        <p style="color:#00a35e;font-weight:700;margin:10px 0 0"><?= htmlspecialchars(APP_NAME) ?></p>
    </div>
    <div class="lock-avatar"><?= htmlspecialchars($userAvatar) ?></div>
    <div class="lock-body">
        <h1>Session Locked</h1>
        <p>Your session is locked due to inactivity.<br>Enter your password to continue.</p>
        <p style="font-weight:700;color:var(--neutral-800)"><?= htmlspecialchars($userName) ?></p>

        <?php if (!empty($errors)): ?>
            <div class="lock-err"><i class="fas fa-circle-exclamation" style="margin-right:6px"></i><?= htmlspecialchars(implode(' ', $errors)) ?></div>
        <?php endif; ?>

        <form method="post" action="index.php?page=lock" id="lock-form">
            <?= csrf_field() ?>
            <div class="lock-field">
                <label for="lock-password">Password</label>
                <div class="lock-input-wrap">
                    <svg viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                        <rect x="3" y="9" width="14" height="9" rx="2"/>
                        <path d="M7 9V6a3 3 0 0 1 6 0v3"/>
                    </svg>
                    <input type="password" id="lock-password" name="password" autocomplete="current-password" placeholder="Enter your password" required autofocus>
                </div>
            </div>
            <div class="lock-actions">
                <button type="submit" class="lock-unlock-btn" id="lock-submit"><i class="fas fa-unlock" style="margin-right:6px"></i>Unlock</button>
                <button type="button" class="lock-logout-btn" onclick="window.location.href='index.php?page=logout'">Log out</button>
            </div>
        </form>
        <div class="lock-hint"><i class="fas fa-info-circle"></i> Unlocking does not sign out or affect other sessions.</div>
    </div>
</div>
<script>
document.getElementById('lock-form').addEventListener('submit', function(e) {
    var btn = document.getElementById('lock-submit');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-circle-notch fa-spin" style="margin-right:6px"></i>Unlocking...';
});
</script>
</body>
</html>
<?php while (ob_get_level()) { ob_end_flush(); } ?>
