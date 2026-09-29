<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if (currentUser()) {
    header('Location: index.php?page=dashboard');
    exit;
}

$preserveRedirect = '';
$preserveProductId = 0;
$portal = $_GET['portal'] ?? '';
$sessionExpired = isset($_GET['expired']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $preserveRedirect = ($_POST['after_login'] ?? '') === 'place_order' ? 'place_order' : '';
    $preserveProductId = (int) ($_POST['product_id'] ?? 0);
    $portal = $_POST['portal'] ?? $portal;
    $sessionExpired = isset($_POST['expired']);
} else {
    $preserveRedirect = ($_GET['redirect'] ?? '') === 'place_order' ? 'place_order' : '';
    $preserveProductId = (int) ($_GET['product_id'] ?? 0);
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lockoutMsg = check_login_attempts();
    if ($lockoutMsg) {
        $errors[] = $lockoutMsg;
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $errors[] = 'Username and password are required.';
        } else {
            $loginPortal = $portal === 'delivery' ? 'delivery' : ($portal === 'finance' ? 'finance' : ($portal === 'store' ? 'store' : null));
            $code = loginForPortal($username, $password, $loginPortal);
            if ($code === 'invalid_credentials') {
                record_login_attempt();
                $errors[] = 'Invalid username or password.';
            } else {
                clear_login_attempts();
                if (isDeliveryStaff()) {
                    header('Location: index.php?page=delivery_dashboard');
                    exit;
                }
                if (isFinanceOfficer()) {
                    header('Location: index.php?page=finance_dashboard');
                    exit;
                }
                if (isSalesperson()) {
                    header('Location: index.php?page=sales');
                    exit;
                }
                if (isStoreManager()) {
                    header('Location: index.php?page=store_dashboard');
                    exit;
                }
                if (isCustomer() && $preserveRedirect === 'place_order') {
                    $url = 'index.php?page=place_order';
                    if ($preserveProductId > 0) {
                        $url .= '&product_id=' . $preserveProductId;
                    }
                    header('Location: ' . $url);
                    exit;
                }
                if (isCustomer()) {
                    header('Location: index.php?page=dashboard');
                    exit;
                }
                header('Location: index.php?page=dashboard');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in - <?= htmlspecialchars(APP_NAME) ?></title>
    <?= favicon_tags() ?>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
    <style>
        .login-page { background: radial-gradient(ellipse at 15% 30%, rgba(37,99,235,0.08) 0%, transparent 50%), radial-gradient(ellipse at 85% 70%, rgba(5,150,105,0.06) 0%, transparent 50%), linear-gradient(160deg,#eff6ff 0%,#f0fdf4 100%) !important; }
        .login-card-head { background:linear-gradient(160deg,#1e3a5f,#1e40af 50%,#1a56db); padding:36px 32px 28px; text-align:center; position:relative; overflow:hidden; }
        .login-card-logo-img { width:56px; height:56px; object-fit:contain; border-radius:14px; background:#fff; padding:4px; box-shadow:0 4px 14px rgba(0,0,0,0.25); flex-shrink:0; }
    </style>
</head>
<body>
<div class="login-page">
    <div class="login-card">
        <div class="login-card-head">
            <div class="login-card-logo" style="display:flex;justify-content:center;margin-bottom:14px;">
                <div style="display:flex;align-items:center;gap:14px;">
                    <img src="<?= htmlspecialchars(brand_logo()) ?>" alt="NARED Logo" class="login-card-logo-img">
                    <div style="display:flex;flex-direction:column;text-align:left;">
                        <span style="font-size:1.6rem;font-weight:900;color:#00a35e;letter-spacing:5px;line-height:1.15;">NARED</span>
                        <span style="font-size:0.65rem;font-weight:600;color:#006838;letter-spacing:3.5px;line-height:1.2;">GENERAL TRADING PLC</span>
                    </div>
                </div>
            </div>
            <p style="color:#00a35e;font-weight:700"><?= htmlspecialchars(APP_NAME) ?></p>
        </div>
        <div class="login-card-body">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
            <?php endif; ?>
            <?php if ($sessionExpired && empty($errors)): ?>
                <div class="alert alert-warning" style="background:var(--amber-50);color:var(--amber-700);border:1px solid var(--amber-200)">Your session has expired due to inactivity. Please log in again.</div>
            <?php endif; ?>
            <form method="post" action="index.php?page=login">
                <?= csrf_field() ?>
                <?php if ($sessionExpired): ?><input type="hidden" name="expired" value="1"><?php endif; ?>
                <input type="hidden" name="after_login" value="<?= htmlspecialchars($preserveRedirect) ?>">
                <input type="hidden" name="product_id" value="<?= $preserveProductId > 0 ? htmlspecialchars((string) $preserveProductId) : '' ?>">
                <input type="hidden" name="portal" value="<?= htmlspecialchars($portal) ?>">
                <div class="login-field">
                    <label for="username">Username</label>
                    <div class="login-input-wrap">
                        <svg class="login-input-icon" viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                            <circle cx="10" cy="7" r="4"/>
                            <path d="M3 18c0-4 3.1-7 7-7s7 3 7 7"/>
                        </svg>
                        <input type="text" id="username" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autocomplete="username" placeholder="Enter your username">
                    </div>
                </div>
                <div class="login-field">
                    <label for="password">Password</label>
                    <div class="login-input-wrap">
                        <svg class="login-input-icon" viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                            <rect x="3" y="9" width="14" height="9" rx="2"/>
                            <path d="M7 9V6a3 3 0 0 1 6 0v3"/>
                            <circle cx="10" cy="14" r="1.2" fill="currentColor" stroke="none"/>
                        </svg>
                        <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="Enter your password">
                    </div>
                    <label class="pw-toggle"><input type="checkbox"> Show password</label>
                </div>
                <button type="submit" style="background:linear-gradient(180deg,#00a35e,#006838);border:1px solid #004d2b">Sign in</button>
            </form>
            <div class="login-card-footer" style="flex-wrap:wrap;justify-content:center;gap:6px">
                <a href="index.php?page=register">New customer? Register</a>
                <span class="login-card-sep">·</span>
                <a href="index.php?page=catalog">Browse products</a>
                <span class="login-card-sep">·</span>
                <a href="index.php?page=login&portal=delivery" style="color:var(--red-700)">Delivery staff</a>
                <span class="login-card-sep">·</span>
                <a href="index.php?page=login&portal=finance" style="color:var(--blue-700)">Finance</a>
                <span class="login-card-sep">·</span>
                <a href="index.php?page=login&portal=store" style="color:var(--fin-teal)">Store Manager</a>
            </div>
        </div>
    </div>
</div>
<script>
document.querySelector('form').addEventListener('submit', function(e) {
    var u = this.querySelector('[name="username"]');
    var p = this.querySelector('[name="password"]');
    var valid = true;
    [u, p].forEach(function(el) {
        el.classList.remove('error');
        var err = el.parentNode.querySelector('.field-error');
        if (err) err.style.display = 'none';
    });
    if (u.value.trim() === '') { u.classList.add('error'); valid = false; }
    if (p.value.trim() === '') { p.classList.add('error'); valid = false; }
    if (!valid) e.preventDefault();
    else this.querySelector('button[type="submit"]').classList.add('loading');
});
document.querySelectorAll('.pw-toggle input[type="checkbox"]').forEach(function(cb) {
    cb.addEventListener('change', function() {
        var p = this.closest('form').querySelector('[name="password"]');
        if (p) p.type = this.checked ? 'text' : 'password';
    });
});
</script>
</body>
</html>
