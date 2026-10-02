<?php
/**
 * Public customer self-registration.
 *
 * A new business customer applies here using their trade licence and TIN
 * number. The application is stored with the account in 'pending' state; the
 * customer cannot sign in until an administrator approves it from
 * pages/customers.php (customers.approve permission).
 */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (currentUser()) {
    header('Location: index.php?page=dashboard');
    exit;
}

require_once __DIR__ . '/../includes/auto_migrate.php';
ensureCustomerSelfRegistrationMigration(getPDO());

$pdo = getPDO();
$errors = [];
$submitted = false;

$businessName  = trim($_POST['business_name'] ?? '');
$customerType  = trim($_POST['customer_type'] ?? '');
$licenseNumber = trim($_POST['license_number'] ?? '');
$tinNumber     = trim($_POST['tin_number'] ?? '');
$contactPerson = trim($_POST['contact_person'] ?? '');
$phone         = trim($_POST['phone'] ?? '');
$email         = trim($_POST['email'] ?? '');
$address       = trim($_POST['physical_address'] ?? '');
$username      = trim($_POST['username'] ?? '');
$password      = $_POST['password'] ?? '';
$password2     = $_POST['password_confirm'] ?? '';

$allowedTypes = ['pharmacy', 'drug_store', 'hospital', 'clinic', 'wholesaler', 'other_licensed'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    // Normalised identifiers: TIN must be digits only, licence may keep its
    // dashes/slashes but is compared case-insensitively by the database.
    $tinDigits = preg_replace('/\D+/', '', $tinNumber);

    if ($businessName === '') {
        $errors[] = 'Business / pharmacy name is required.';
    } elseif (mb_strlen($businessName) > 150) {
        $errors[] = 'Business name is too long (max 150 characters).';
    }
    if ($customerType !== '' && !in_array($customerType, $allowedTypes, true)) {
        $errors[] = 'Please choose a valid customer type.';
    }
    if ($licenseNumber === '') {
        $errors[] = 'Trade licence number is required.';
    } elseif (mb_strlen($licenseNumber) < 3) {
        $errors[] = 'Trade licence number must be at least 3 characters.';
    } elseif (!preg_match('/^[A-Za-z0-9\/\-\. ]+$/u', $licenseNumber)) {
        $errors[] = 'Trade licence number may only contain letters, numbers, spaces, dashes and dots.';
    }
    if ($tinNumber === '') {
        $errors[] = 'TIN number is required.';
    } elseif (strlen($tinDigits) < 9 || strlen($tinDigits) > 15) {
        $errors[] = 'TIN must be 9 to 15 digits (Ethiopian TINs are normally 12 digits).';
    }
    if ($contactPerson === '') {
        $errors[] = 'Contact person is required.';
    }
    if ($phone === '' || strlen(preg_replace('/\D+/', '', $phone)) < 6) {
        $errors[] = 'A valid phone number is required.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address or leave it blank.';
    }
    if ($username === '') {
        $errors[] = 'Username is required.';
    } elseif (mb_strlen($username) < 3) {
        $errors[] = 'Username must be at least 3 characters.';
    } elseif (!preg_match('/^[A-Za-z0-9._-]+$/', $username)) {
        $errors[] = 'Username may only contain letters, numbers, dots, dashes and underscores.';
    }
    if ($password === '') {
        $errors[] = 'Password is required.';
    } elseif (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    } elseif ($password !== $password2) {
        $errors[] = 'Passwords do not match.';
    }

    // Uniqueness — licence, TIN and username must not already be taken.
    if (empty($errors)) {
        $stmt = $pdo->prepare('SELECT id FROM customers WHERE license_number = ? LIMIT 1');
        $stmt->execute([$licenseNumber]);
        if ($stmt->fetch()) {
            $errors[] = 'That trade licence number is already registered.';
        }
    }
    if (empty($errors)) {
        $stmt = $pdo->prepare('SELECT id FROM customers WHERE tin_number = ? LIMIT 1');
        $stmt->execute([$tinDigits]);
        if ($stmt->fetch()) {
            $errors[] = 'That TIN number is already registered.';
        }
    }
    if (empty($errors)) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $errors[] = 'That username is already taken.';
        }
    }

    if (empty($errors)) {
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO customers
                    (name, customer_type, license_number, tin_number, contact_person, phone, email, physical_address, customer_status, created_at)
                 VALUES (:name, :ctype, :license, :tin, :contact, :phone, :email, :address, \'inactive\', NOW())'
            );
            $ins->execute([
                'name'    => $businessName,
                'ctype'   => $customerType !== '' ? $customerType : null,
                'license' => $licenseNumber,
                'tin'     => $tinDigits,
                'contact' => $contactPerson,
                'phone'   => $phone,
                'email'   => $email !== '' ? $email : null,
                'address' => $address !== '' ? $address : null,
            ]);
            $customerId = (int) $pdo->lastInsertId();

            $userId = userCreate($pdo, $username, $password, $businessName, 'customer', 2, $customerId);
            $pdo->prepare("UPDATE users SET account_status = 'pending' WHERE id = ?")->execute([$userId]);

            $pdo->commit();
            $submitted = true;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = ($e->errorInfo[1] ?? 0) === 1062
                ? 'That licence number, TIN number or username is already registered.'
                : 'Registration could not be saved. Please try again.';
        }
    }
}

$v = fn(string $key): string => htmlspecialchars((string)($_POST[$key] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer registration - <?= htmlspecialchars(APP_NAME) ?></title>
    <?= favicon_tags() ?>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
    <style>
        .login-page { background: radial-gradient(ellipse at 15% 30%, rgba(37,99,235,0.08) 0%, transparent 50%), radial-gradient(ellipse at 85% 70%, rgba(5,150,105,0.06) 0%, transparent 50%), linear-gradient(160deg,#eff6ff 0%,#f0fdf4 100%) !important; }
        .login-card-head { background:linear-gradient(160deg,#1e3a5f,#1e40af 50%,#1a56db); padding:30px 32px 24px; text-align:center; position:relative; overflow:hidden; }
        .login-card-logo-img { width:52px; height:52px; object-fit:contain; border-radius:14px; background:#fff; padding:4px; box-shadow:0 4px 14px rgba(0,0,0,0.25); flex-shrink:0; }
        .reg-grid { display:grid; grid-template-columns:1fr 1fr; gap:0 16px; }
        @media(max-width:560px){ .reg-grid { grid-template-columns:1fr; } }
        .reg-grid .full { grid-column:1 / -1; }
        .reg-req { color:#dc2626; font-weight:700; }
        .reg-done { text-align:center; padding:8px 0 4px; }
        .reg-done i { font-size:3rem; color:#059669; }
    </style>
</head>
<body>
<div class="login-page">
    <div class="login-card" style="max-width:720px">
        <div class="login-card-head">
            <div class="login-card-logo" style="display:flex;align-items:center;justify-content:center;margin-bottom:12px;">
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
            <?php if ($submitted): ?>
                <div class="alert alert-success">Application received. Your account is waiting for administrator approval.</div>
                <div class="reg-done">
                    <i class="fas fa-circle-check"></i>
                    <h2 style="margin:10px 0 6px;color:#1e293b">Thank you — registration submitted</h2>
                    <p style="color:#475569;margin:0 0 14px">
                        Your application for <strong><?= htmlspecialchars($businessName) ?></strong> has been saved with
                        licence <strong><?= htmlspecialchars($licenseNumber) ?></strong> and TIN <strong><?= htmlspecialchars($tinDigits) ?></strong>.
                        An administrator will verify your licence and TIN before your account is activated.
                    </p>
                    <p style="color:#64748b;font-size:0.85rem;margin:0 0 18px">
                        You will be able to sign in at <strong><?= htmlspecialchars($_POST['username'] ?? '') ?></strong> once approved.
                    </p>
                    <a href="index.php?page=login" style="display:inline-block;padding:11px 22px;background:linear-gradient(180deg,#00a35e,#006838);color:#fff;border-radius:8px;text-decoration:none;font-weight:700">Go to sign in</a>
                </div>
            <?php else: ?>
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-error"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
                <?php endif; ?>
                <p style="color:#475569;font-size:0.9rem;margin:0 0 14px">
                    Register your pharmacy or business below. Your <strong>trade licence number</strong> and
                    <strong>TIN number</strong> are required and will be verified by an administrator before your account is activated.
                </p>
                <form method="post" action="index.php?page=register" data-ajax-off>
                    <?= csrf_field() ?>
                    <div class="reg-grid">
                        <div class="login-field">
                            <label for="business_name">Business / pharmacy name <span class="reg-req">*</span></label>
                            <input type="text" id="business_name" name="business_name" value="<?= $v('business_name') ?>" required maxlength="150" placeholder="e.g. Sunrise Pharmacy PLC">
                        </div>
                        <div class="login-field">
                            <label for="customer_type">Business type</label>
                            <select id="customer_type" name="customer_type">
                                <option value="">Select...</option>
                                <?php foreach (['pharmacy' => 'Pharmacy', 'drug_store' => 'Drug Store', 'hospital' => 'Hospital', 'clinic' => 'Clinic', 'wholesaler' => 'Wholesaler', 'other_licensed' => 'Other Licensed'] as $val => $label): ?>
                                    <option value="<?= $val ?>"<?= ($_POST['customer_type'] ?? '') === $val ? ' selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="login-field">
                            <label for="license_number">Trade licence number <span class="reg-req">*</span></label>
                            <input type="text" id="license_number" name="license_number" value="<?= $v('license_number') ?>" required maxlength="100" placeholder="e.g. AA-12345/2">
                        </div>
                        <div class="login-field">
                            <label for="tin_number">TIN number <span class="reg-req">*</span></label>
                            <input type="text" id="tin_number" name="tin_number" value="<?= $v('tin_number') ?>" required inputmode="numeric" placeholder="e.g. 000123456789">
                        </div>
                        <div class="login-field">
                            <label for="contact_person">Contact person <span class="reg-req">*</span></label>
                            <input type="text" id="contact_person" name="contact_person" value="<?= $v('contact_person') ?>" required maxlength="150">
                        </div>
                        <div class="login-field">
                            <label for="phone">Phone <span class="reg-req">*</span></label>
                            <input type="tel" id="phone" name="phone" value="<?= $v('phone') ?>" required placeholder="e.g. 0912345678">
                        </div>
                        <div class="login-field">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" value="<?= $v('email') ?>" placeholder="Optional">
                        </div>
                        <div class="login-field">
                            <label for="username">Username <span class="reg-req">*</span></label>
                            <input type="text" id="username" name="username" value="<?= $v('username') ?>" required minlength="3" maxlength="100" autocomplete="username">
                        </div>
                        <div class="login-field full">
                            <label for="physical_address">Business address</label>
                            <textarea id="physical_address" name="physical_address" rows="2" placeholder="Optional"><?= $v('physical_address') ?></textarea>
                        </div>
                        <div class="login-field">
                            <label for="password">Password <span class="reg-req">*</span></label>
                            <input type="password" id="password" name="password" required minlength="6" autocomplete="new-password">
                            <label class="pw-toggle"><input type="checkbox"> Show password</label>
                        </div>
                        <div class="login-field">
                            <label for="password_confirm">Confirm password <span class="reg-req">*</span></label>
                            <input type="password" id="password_confirm" name="password_confirm" required minlength="6" autocomplete="new-password">
                        </div>
                    </div>
                    <button type="submit" style="background:linear-gradient(180deg,#00a35e,#006838);border:1px solid #004d2b;margin-top:6px">Submit registration</button>
                </form>
            <?php endif; ?>
            <div class="login-card-footer" style="flex-wrap:wrap;justify-content:center;gap:6px">
                <a href="index.php?page=login">Already registered? Sign in</a>
                <span class="login-card-sep">·</span>
                <a href="index.php?page=catalog">Browse products</a>
            </div>
        </div>
    </div>
</div>
<script>
document.querySelectorAll('.pw-toggle input[type="checkbox"]').forEach(function (cb) {
    cb.addEventListener('change', function () {
        var box = this.closest('.login-field');
        var inputs = box ? box.querySelectorAll('input[type="password"]') : [];
        if (inputs.length) inputs.forEach(function (p) { p.type = this.checked ? 'text' : 'password'; }, this);
    });
});
</script>
</body>
</html>