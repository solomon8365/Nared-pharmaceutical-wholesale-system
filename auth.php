<?php
require_once __DIR__ . '/db.php';

// Secure session cookie settings
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
if ($secure) {
    ini_set('session.cookie_secure', '1');
}
// Fix domain: remove port for cookie domain (RFC 6265)
$host = $_SERVER['HTTP_HOST'] ?? '';
$cookieDomain = '';
if ($host !== '') {
    $hostParts = explode(':', $host);
    $cookieDomain = $hostParts[0]; // hostname only, no port
    // Don't set domain for localhost/IP - let browser handle it
    if ($cookieDomain === 'localhost' || filter_var($cookieDomain, FILTER_VALIDATE_IP)) {
        $cookieDomain = '';
    }
}
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => $cookieDomain,
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * The authenticated user's ID, resolved ONLY from the server-side session
 * (never from client-supplied input). Returns null when not signed in.
 */
function currentUserId(): ?int
{
    $u = $_SESSION['user'] ?? null;
    if (is_array($u) && isset($u['id']) && $u['id'] !== '' && $u['id'] !== null) {
        return (int) $u['id'];
    }
    // Back-compat alias set at login; defensive fallback keyed off the user array.
    $id = $_SESSION['user_id'] ?? null;
    return ($id !== null && $id !== '') ? (int) $id : null;
}

/**
 * Session inactivity timeout (minutes). Defaults to 30 minutes as required.
 * Respects the existing `session_timeout` setting when present and valid.
 */
function sessionTimeoutMinutes(): int
{
    $m = (int) get_setting('session_timeout', '30');
    if ($m < 1) {
        $m = 30;
    }
    return $m;
}

/**
 * Where to send the user after unlocking the lock screen. Prefers the page they
 * were on when locked, otherwise the role's default landing page.
 */
function landingAfterUnlock(): string
{
    $redirect = $_SESSION['lock_redirect'] ?? '';
    if ($redirect !== '' && preg_match('/^[a-z0-9_]+$/', $redirect)) {
        unset($_SESSION['lock_redirect']);
        return $redirect;
    }
    unset($_SESSION['lock_redirect']);
    return portalLandingPage();
}

/**
 * The default landing page for a role after login (used for portal-aware redirects).
 */
function portalLandingPage(?array $user = null): string
{
    $role = ($user ?? currentUser())['role'] ?? 'admin';
    switch ($role) {
        case 'delivery':     return 'delivery_dashboard';
        case 'finance':      return 'finance_dashboard';
        case 'store_manager':return 'store_dashboard';
        case 'salesperson':  return 'sales';
        default:             return 'dashboard';
    }
}

/**
 * Maps a role to its login `portal` parameter value ('' when not portal-specific).
 */
function portalParamForRole(string $role): string
{
    switch ($role) {
        case 'delivery':      return 'delivery';
        case 'finance':       return 'finance';
        case 'store_manager': return 'store';
        default:              return '';
    }
}

/**
 * Server-side inactivity enforcement. Called on every authenticated request.
 *
 * When the session has been idle longer than the configured timeout, the session is
 * placed into a LOCKED state (the user record is retained, so the user is not fully
 * logged out and their account is NOT locked — the account lock for failed logins is
 * completely separate). While locked, every non-lock page redirects to the lock screen.
 *
 * Returns true when the session is usable; the caller should return/exit after a
 * redirect (redirect + exit are handled internally on lock/expiry).
 */
function enforceSessionTimeout(): bool
{
    $user = currentUser();
    if (!$user) {
        return true;
    }

    $currentPage = $_GET['page'] ?? '';

    // 1) Session is already locked: keep refusing other pages so the timeout cannot
    //    be bypassed by merely navigating/refreshing. Do NOT refresh last_activity
    //    while locked.
    if (!empty($_SESSION['locked'])) {
        if ($currentPage === 'lock') {
            return true; // allow the lock screen to render / process unlock
        }
        header('Location: index.php?page=lock');
        exit;
    }

    // 2) Not locked: apply the inactivity timeout.
    $last = (int) ($_SESSION['last_activity'] ?? 0);
    $timeoutSec = sessionTimeoutMinutes() * 60;

    if ($timeoutSec > 0 && $last > 0 && (time() - $last) > $timeoutSec) {
        // Lock the session (keep user data). No account lock, no other user affected.
        $_SESSION['locked'] = true;
        $_SESSION['locked_at'] = time();
        // If we are already ON the lock page (e.g. the in-page unlock AJAX coming from
        // the client-side idle modal), do NOT redirect-and-exit here — doing so overwrote
        // the AJAX JSON with an HTML redirect and forced the user to enter their password
        // twice (first attempt always failed). Instead mark the session locked and let the
        // lock page / ajax handler run so a correct password can unlock immediately.
        if ($currentPage === 'lock') {
            return true;
        }
        // Remember where the user was so we can return after unlocking (best-effort).
        if ($currentPage !== '' && $currentPage !== 'logout') {
            $_SESSION['lock_redirect'] = $currentPage;
        }
        header('Location: index.php?page=lock');
        exit;
    }

    $_SESSION['last_activity'] = time();
    return true;
}

/**
 * Unlock an active (locked) session by verifying the user's password.
 *
 * On success the lock is cleared, the session id is regenerated, the activity clock
 * is reset, and true is returned. On failure returns false. Uses the existing
 * password_verify check — this does NOT touch the account lock for failed logins.
 */
function unlockSession(string $password): bool
{
    $user = currentUser();
    if (!$user) {
        return false;
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int) $user['id']]);
    $hash = $stmt->fetchColumn();

    if (!$hash || !password_verify($password, $hash)) {
        return false;
    }

    // Resume the session.
    unset($_SESSION['locked'], $_SESSION['locked_at']);
    $_SESSION['last_activity'] = time();
    session_regenerate_id(true);

    return true;
}

function requireLogin(): void
{
    if (!currentUser()) {
        header('Location: index.php?page=login');
        exit;
    }
    enforceSessionTimeout();
}

function userRole(): string
{
    $u = currentUser();
    if (!is_array($u)) {
        return '';
    }
    // Never assume 'admin' when the role is missing/invalid — that would let a
    // session without a role slip into the administration tier. Return an empty
    // role instead so all is*() checks evaluate to false.
    $role = $u['role'] ?? '';
    return is_string($role) ? $role : '';
}

/**
 * "Power user" — the two administration tiers (Super Admin and Manager).
 *
 * Kept true for Manager so the existing navigation/feature gates written
 * against isAdmin() keep working after the Admin role was split. The
 * dangerous operations are gated by the permission system or isSuperAdmin()
 * instead, never by this function.
 */
function isAdmin(): bool
{
    return currentUser() !== null && in_array(userRole(), ['admin', 'manager'], true);
}

/**
 * Root account (role_id 1). Only this account bypasses the permission system
 * and may restore/delete backups, change system settings, manage users and
 * roles, run database repair tools, or enter the site during maintenance.
 */
function isSuperAdmin(): bool
{
    return currentUser() !== null && userRole() === 'admin' && getUserRoleId() === 1;
}

function isCustomer(): bool
{
    return currentUser() !== null && userRole() === 'customer';
}

function isDeliveryStaff(): bool
{
    return currentUser() !== null && userRole() === 'delivery';
}

function isFinanceOfficer(): bool
{
    return currentUser() !== null && userRole() === 'finance';
}

function isSalesperson(): bool
{
    return currentUser() !== null && userRole() === 'salesperson';
}

function isStoreManager(): bool
{
    return currentUser() !== null && userRole() === 'store_manager';
}

function customerAccountCustomerId(): ?int
{
    if (!isCustomer()) {
        return null;
    }
    $id = currentUser()['customer_id'] ?? null;

    return $id !== null && $id !== '' ? (int) $id : null;
}

function requireAdmin(): void
{
    if (!currentUser()) {
        if (headers_sent()) { echo '<script>location.href="index.php?page=login"</script>'; exit; }
        header('Location: index.php?page=login');
        exit;
    }
    if (!isAdmin()) {
        if (headers_sent()) {
            echo '<div style="padding:30px;text-align:center;font-family:sans-serif"><h2 style="color:#dc2626">Access Denied</h2><p style="color:#374151;margin:12px 0">Admin access required.</p><a href="index.php?page=dashboard" style="display:inline-block;padding:10px 24px;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;font-weight:600">Back to Dashboard</a></div>';
            exit;
        }
        header('Location: index.php?page=dashboard');
        exit;
    }
}

/**
 * Super Admin guard: only the root account (role_id 1 with the 'admin' enum
 * value) may pass. This is the hard server-side gate for the Super Admin
 * module — it does NOT accept the generic Admin/Manager tier.
 */
function requireSuperAdmin(): void
{
    if (!currentUser()) {
        if (headers_sent()) { echo '<script>location.href="index.php?page=login"</script>'; exit; }
        header('Location: index.php?page=login');
        exit;
    }
    if (!isSuperAdmin()) {
        if (headers_sent()) {
            echo '<div style="padding:30px;text-align:center;font-family:sans-serif"><h2 style="color:#dc2626">Access Denied</h2><p style="color:#374151;margin:12px 0">Super Admin access required.</p><a href="index.php?page=dashboard" style="display:inline-block;padding:10px 24px;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;font-weight:600">Back to Dashboard</a></div>';
            exit;
        }
        header('Location: index.php?page=dashboard');
        exit;
    }
}

function requireCustomer(): void
{
    if (!currentUser()) {
        if (headers_sent()) { echo '<script>location.href="index.php?page=login"</script>'; exit; }
        header('Location: index.php?page=login');
        exit;
    }
    if (!isCustomer()) {
        if (headers_sent()) {
            echo '<div style="padding:30px;text-align:center;font-family:sans-serif"><h2 style="color:#dc2626">Access Denied</h2><p style="color:#374151;margin:12px 0">Customer access required.</p><a href="index.php?page=dashboard" style="display:inline-block;padding:10px 24px;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;font-weight:600">Back to Dashboard</a></div>';
            exit;
        }
        header('Location: index.php?page=dashboard');
        exit;
    }
}

function requireDeliveryStaff(): void
{
    if (!currentUser()) {
        if (headers_sent()) { echo '<script>location.href="index.php?page=login"</script>'; exit; }
        header('Location: index.php?page=login');
        exit;
    }
    if (!isDeliveryStaff() && !isAdmin()) {
        if (headers_sent()) {
            echo '<div style="padding:30px;text-align:center;font-family:sans-serif"><h2 style="color:#dc2626">Access Denied</h2><p style="color:#374151;margin:12px 0">Delivery staff access required.</p><a href="index.php?page=dashboard" style="display:inline-block;padding:10px 24px;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;font-weight:600">Back to Dashboard</a></div>';
            exit;
        }
        header('Location: index.php?page=dashboard');
        exit;
    }
}

/**
 * Delivery operator guard: only the Delivery Staff role (role_id=3) may
 * perform delivery operations. Admin is deliberately excluded even though
 * requireDeliveryStaff() admits admin — Admin only views Delivery Reports.
 */
function requireDeliveryOperator(): void
{
    if (!currentUser()) {
        if (headers_sent()) { echo '<script>location.href="index.php?page=login"</script>'; exit; }
        header('Location: index.php?page=login');
        exit;
    }
    if (!isDeliveryStaff()) {
        if (headers_sent()) {
            echo '<div style="padding:30px;text-align:center;font-family:sans-serif"><h2 style="color:#dc2626">Access Denied</h2><p style="color:#374151;margin:12px 0">Delivery staff access required.</p><a href="index.php?page=dashboard" style="display:inline-block;padding:10px 24px;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;font-weight:600">Back to Dashboard</a></div>';
            exit;
        }
        header('Location: index.php?page=dashboard');
        exit;
    }
}

function requireFinanceOfficer(): void
{
    if (!currentUser()) {
        if (headers_sent()) { echo '<script>location.href="index.php?page=login"</script>'; exit; }
        header('Location: index.php?page=login');
        exit;
    }
    if (!isFinanceOfficer() && !isAdmin()) {
        if (headers_sent()) {
            echo '<div style="padding:30px;text-align:center;font-family:sans-serif"><h2 style="color:#dc2626">Access Denied</h2><p style="color:#374151;margin:12px 0">Finance officer access required.</p><a href="index.php?page=dashboard" style="display:inline-block;padding:10px 24px;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;font-weight:600">Back to Dashboard</a></div>';
            exit;
        }
        header('Location: index.php?page=dashboard');
        exit;
    }
}

function requireSalesperson(): void
{
    if (!currentUser()) {
        if (headers_sent()) { echo '<script>location.href="index.php?page=login"</script>'; exit; }
        header('Location: index.php?page=login');
        exit;
    }
    if (!isSalesperson() && !isAdmin()) {
        if (headers_sent()) {
            echo '<div style="padding:30px;text-align:center;font-family:sans-serif"><h2 style="color:#dc2626">Access Denied</h2><p style="color:#374151;margin:12px 0">Salesperson access required.</p><a href="index.php?page=sales" style="display:inline-block;padding:10px 24px;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;font-weight:600">Back to Sales</a></div>';
            exit;
        }
        header('Location: index.php?page=sales');
        exit;
    }
}

function requireStoreManager(): void
{
    if (!currentUser()) {
        if (headers_sent()) { echo '<script>location.href="index.php?page=login"</script>'; exit; }
        header('Location: index.php?page=login');
        exit;
    }
    if (!isStoreManager() && !isAdmin()) {
        if (headers_sent()) {
            echo '<div style="padding:30px;text-align:center;font-family:sans-serif"><h2 style="color:#dc2626">Access Denied</h2><p style="color:#374151;margin:12px 0">Store Manager access required.</p><a href="index.php?page=store_dashboard" style="display:inline-block;padding:10px 24px;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;font-weight:600">Back to Store Dashboard</a></div>';
            exit;
        }
        header('Location: index.php?page=store_dashboard');
        exit;
    }
}

/**
 * Resolve the logged-in salesperson's salespeople record. Uses the explicit
 * salespeople.user_id link first, then falls back to matching full_name
 * against salespeople.name (legacy accounts).
 *
 * @return array|null the active salespeople row, or null when unlinked
 */
function salespersonForUser(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM salespeople WHERE user_id = :uid AND status = 'active' LIMIT 1");
    $stmt->execute(['uid' => $userId]);
    $row = $stmt->fetch();
    if ($row) return $row;

    $u = currentUser();
    $name = trim((string) ($u['full_name'] ?? ''));
    if ($name !== '') {
        $stmt2 = $pdo->prepare("SELECT * FROM salespeople WHERE LOWER(TRIM(name)) = LOWER(TRIM(:n)) AND status = 'active' LIMIT 1");
        $stmt2->execute(['n' => $name]);
        $row2 = $stmt2->fetch();
        if ($row2) return $row2;
    }
    return null;
}

// =============================================================
// Purchase Order Separation of Duties
// =============================================================
// Admin must NOT get create/edit/receive/payment powers on the
// Purchase Order workflow simply because the Admin role has the
// global `__all__` shortcut. These helpers resolve the user's actual
// role via the roles table and apply the strict PO matrix instead of
// the generic hasPermission() shortcut.

function poRoleIdByName(string $name): ?int
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $pdo = getPDO();
            foreach ($pdo->query('SELECT id, name FROM roles') as $r) {
                $cache[strtolower(trim($r['name']))] = (int) $r['id'];
            }
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return $cache[strtolower(trim($name))] ?? null;
}

function poStoreManagerRoleId(): ?int { return poRoleIdByName('Store manager'); }
function poFinanceRoleId(): ?int { return poRoleIdByName('Finance Officer'); }

/**
 * The administration tier for the PO workflow.
 *
 * The legacy single "Admin" role was split into "Super Admin" (role 1, the
 * root account) and "Manager" (role 7, day-to-day operations). Resolving the
 * literal name "Admin" therefore returns null — the role no longer exists —
 * which silently evaluated every `$rid === poAdminRoleId()` comparison to
 * false and disabled PO approval (and hid the Purchase Orders menu) for the
 * Super Admin and Manager alike. Resolve the whole tier instead.
 *
 * @return int[] Existing administration role ids (Super Admin, Manager).
 */
function poAdminRoleIds(): array
{
    $ids = [poRoleIdByName('Super Admin'), poRoleIdByName('Manager')];
    return array_values(array_filter($ids, static fn ($id) => $id !== null));
}

/** Primary administration role id (Super Admin), or null if unavailable. */
function poAdminRoleId(): ?int
{
    $ids = poAdminRoleIds();
    return $ids[0] ?? null;
}

function canViewPurchaseOrder(): bool
{
    $rid = getUserRoleId();
    if ($rid === null) {
        return false;
    }
    $allowed = array_filter(array_merge(
        poAdminRoleIds(),
        [poStoreManagerRoleId(), poFinanceRoleId()]
    ), static fn ($id) => $id !== null);
    return in_array($rid, $allowed, true) && hasPermission('purchase_orders', 'view');
}

function canCreatePurchaseOrder(): bool
{
    $rid = getUserRoleId();
    return $rid === poStoreManagerRoleId() && hasPermission('purchase_orders', 'create');
}

function canEditPurchaseOrder(): bool
{
    $rid = getUserRoleId();
    return $rid === poStoreManagerRoleId() && hasPermission('purchase_orders', 'edit');
}

function canReceivePurchaseOrder(): bool
{
    return canEditPurchaseOrder();
}

function canCancelPurchaseOrder(): bool
{
    $rid = getUserRoleId();
    return $rid === poStoreManagerRoleId() && hasPermission('purchase_orders', 'delete');
}

function canApprovePurchaseOrder(): bool
{
    $rid = getUserRoleId();
    return $rid !== null
        && in_array($rid, poAdminRoleIds(), true)
        && hasPermission('purchase_orders', 'approve');
}

function canProcessPurchasePayment(): bool
{
    $rid = getUserRoleId();
    return $rid === poFinanceRoleId() && hasPermission('purchase_payment_approval', 'approve');
}

function poDenyPurchaseOrder(): void
{
    if (headers_sent()) {
        echo '<div style="padding:30px;text-align:center;font-family:sans-serif"><h2 style="color:#dc2626">Access Denied</h2><p style="color:#374151;margin:12px 0">You do not have permission for this Purchase Order action.</p><a href="index.php?page=dashboard" style="display:inline-block;padding:10px 24px;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;font-weight:600">Back to Dashboard</a></div>';
        exit;
    }
    header('Location: index.php?page=dashboard');
    exit;
}

function requirePoCapability(string $cap): void
{
    if (!currentUser()) {
        header('Location: index.php?page=login');
        exit;
    }
    $ok = match ($cap) {
        'view'    => canViewPurchaseOrder(),
        'create'  => canCreatePurchaseOrder(),
        'edit'    => canEditPurchaseOrder(),
        'receive' => canReceivePurchaseOrder(),
        'cancel'  => canCancelPurchaseOrder(),
        'approve' => canApprovePurchaseOrder(),
        'payment' => canProcessPurchasePayment(),
        default   => false,
    };
    if (!$ok) poDenyPurchaseOrder();
}

/**
 * Sign in with optional portal restriction.
 *
 * @param string|null $portal 'admin', 'customer', or null for any role (e.g. post-registration)
 *
 * @return string|null null on success; otherwise 'empty', 'invalid_credentials',
 *                     'wrong_portal', 'pending_approval' or 'account_rejected'
 */
function loginForPortal(string $username, string $password, ?string $portal): ?string
{
    $username = trim($username);
    if ($username === '' || $password === '') {
        return 'empty';
    }

    $pdo = getPDO();
    require_once __DIR__ . '/includes/auto_migrate.php';
    ensureSalespersonRoleMigration($pdo);
    ensureStoreManagerRoleMigration($pdo);
    ensureCustomerSelfRegistrationMigration($pdo);
    ensureSuperAdminRoleMigration($pdo);
    ensurePurchasePaymentApprovalMigration($pdo);
    ensureOrderWorkflowRolePermissions($pdo);
    ensureInvoiceViewAllRoles($pdo);
    ensureCoreWorkflowTablesMigration($pdo);
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return 'invalid_credentials';
    }

    $role = $user['role'] ?? 'admin';

    // Self-registered customers stay blocked until an administrator approves
    // their licence/TIN (pages/customers.php, customers.approve).
    $accountStatus = $user['account_status'] ?? 'approved';
    if ($accountStatus === 'pending') {
        return 'pending_approval';
    }
    if ($accountStatus === 'rejected') {
        return 'account_rejected';
    }

    if ($portal === 'admin' && $role !== 'admin') {
        return 'wrong_portal';
    }
    if ($portal === 'customer' && $role !== 'customer') {
        return 'wrong_portal';
    }
    if ($portal === 'delivery' && $role !== 'delivery') {
        return 'wrong_portal';
    }
    if ($portal === 'finance' && $role !== 'finance') {
        return 'wrong_portal';
    }
    if ($portal === 'store' && $role !== 'store_manager') {
        return 'wrong_portal';
    }

    unset($user['password']);
    session_regenerate_id(true);
    $_SESSION['user'] = $user;
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['last_activity'] = time();

    return null;
}

/** @deprecated Prefer loginForPortal with an explicit portal for new code */
function login(string $username, string $password): bool
{
    return loginForPortal($username, $password, null) === null;
}

function logout(?string $extra = ''): void
{
    session_unset();
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
    header('Location: index.php?page=login' . $extra);
    exit;
}

function check_login_attempts(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = :ip AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $stmt->execute(['ip' => $ip]);
    $attempts = (int) $stmt->fetchColumn();
    $maxAttempts = 5;
    if ($attempts >= $maxAttempts) {
        return 'Too many login attempts. Please wait 15 minutes.';
    }
    return null;
}

function record_login_attempt(): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $pdo = getPDO();
    $pdo->prepare('INSERT INTO login_attempts (ip_address, attempted_at) VALUES (:ip, NOW())')->execute(['ip' => $ip]);
}

function clear_login_attempts(): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $pdo = getPDO();
    $pdo->prepare('DELETE FROM login_attempts WHERE ip_address = :ip')->execute(['ip' => $ip]);
}

function generate_csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf_token" value="' . generate_csrf_token() . '">';
}

function csrf_token(): string
{
    return generate_csrf_token();
}

function verify_csrf_token(?string $token): bool
{
    if (empty($_SESSION['_csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['_csrf_token'], $token);
}

function require_csrf(): void
{
    $token = $_POST['_csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        http_response_code(419);
        die('CSRF token validation failed. Please go back and try again.');
    }
}

// =============================================================
// Dynamic Role & Permission System
// =============================================================

function getUserRoleId(): ?int
{
    $u = currentUser();
    if (!$u) return null;
    if (isset($u['role_id']) && (int) $u['role_id'] > 0) {
        return (int) $u['role_id'];
    }

    // No trustworthy role_id in the session snapshot. Resolve it from the
    // database by the authenticated user's id (never from client input) and
    // cache it for this request. If the account genuinely has no valid role,
    // return null so NO role is assumed — we must never silently fall back to
    // an Admin/Super Admin role when the role is missing or invalid.
    $uid = currentUserId();
    if ($uid === null) {
        return null;
    }

    static $resolved = [];
    if (array_key_exists($uid, $resolved)) {
        return $resolved[$uid];
    }

    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare('SELECT role_id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $uid]);
        $rid = $stmt->fetchColumn();
        $resolved[$uid] = ($rid !== null && (int) $rid > 0) ? (int) $rid : null;
    } catch (Throwable $e) {
        $resolved[$uid] = null;
    }

    // Keep the cached session snapshot consistent for the rest of the request.
    if ($resolved[$uid] !== null && isset($_SESSION['user']) && is_array($_SESSION['user'])) {
        $_SESSION['user']['role_id'] = $resolved[$uid];
    }

    return $resolved[$uid];
}

function loadPermissionsForRole(int $roleId): array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT module, permission FROM role_permissions WHERE role_id = :rid');
    $stmt->execute(['rid' => $roleId]);
    $perms = [];
    foreach ($stmt->fetchAll() as $row) {
        $perms[$row['module']][$row['permission']] = true;
    }
    return $perms;
}

function getPermissions(): array
{
    $u = currentUser();
    if (!$u) return [];
    // Real system admin (role_id=1) always has all permissions
    if (isAdmin() && getUserRoleId() === 1) return ['__all__' => true];
    // Cache in session for performance; invalidate if global perm version changed
    $cacheKey = '_perms_' . ($u['role_id'] ?? $u['role'] ?? '');
    $ver = getPermVersion();
    if (!isset($_SESSION[$cacheKey]) || !isset($_SESSION[$cacheKey . '_v']) || $_SESSION[$cacheKey . '_v'] !== $ver) {
        $roleId = getUserRoleId();
        $_SESSION[$cacheKey] = $roleId ? loadPermissionsForRole($roleId) : [];
        $_SESSION[$cacheKey . '_v'] = $ver;
    }
    return $_SESSION[$cacheKey];
}

function hasPermission(string $module, string $permission = 'view'): bool
{
    $perms = getPermissions();
    // Admin shortcut
    if (isset($perms['__all__'])) return true;
    return isset($perms[$module][$permission]);
}

function hasAnyModulePermission(array $modules, string $permission = 'view'): bool
{
    foreach ($modules as $mod) {
        if (hasPermission($mod, $permission)) return true;
    }
    return false;
}

function requirePermission(string $module, string $permission = 'view'): void
{
    if (!currentUser()) {
        header('Location: index.php?page=login');
        exit;
    }
    if (!hasPermission($module, $permission)) {
        if (headers_sent()) {
            echo '<div style="padding:30px;text-align:center;font-family:sans-serif"><h2 style="color:#dc2626">Permission Not Allowed</h2><p style="color:#374151;margin:12px 0">You do not have permission to perform this action.</p><a href="index.php?page=dashboard" style="display:inline-block;padding:10px 24px;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;font-weight:600">Back to Dashboard</a></div>';
            exit;
        }
        header('Location: index.php?page=dashboard');
        exit;
    }
}

function getPermVersion(): int
{
    $file = __DIR__ . '/perm_version.txt';
    if (!file_exists($file)) return 0;
    $v = trim(file_get_contents($file));
    return is_numeric($v) ? (int) $v : 0;
}

function bumpPermVersion(): void
{
    $file = __DIR__ . '/perm_version.txt';
    file_put_contents($file, (string)(getPermVersion() + 1), LOCK_EX);
}

function safeRedirect(string $url): void
{
    if (!headers_sent()) {
        header('Location: ' . $url);
    } else {
        echo '<script>location.href=' . json_encode($url) . '</script>';
    }
    exit;
}

/**
 * Store a one-time flash message to be shown as a toast on the next render.
 * Used with Post/Redirect/Get so successful form actions (e.g. Approve) return
 * the user to the (freshly re-queried) list instead of leaving them on the form.
 */
function set_flash(string $type, string $message): void
{
    $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Read and clear the pending flash message (called during page render so the
 * global toast renderer can display it once).
 */
function get_flash(): ?array
{
    if (empty($_SESSION['_flash'])) {
        return null;
    }
    $f = $_SESSION['_flash'];
    unset($_SESSION['_flash']);
    return $f;
}

/**
 * Perform a POST/Redirect/GET redirect back to a list/overview page after a
 * successful action, carrying a one-time toast. The freshly rendered list will
 * already reflect the change, so no manual "Back" navigation is required.
 */
function redirect_with_flash(string $url, string $message, string $type = 'success'): void
{
    set_flash($type, $message);
    safeRedirect($url);
}

function clearPermissionCache(): void
{
    bumpPermVersion();
    foreach (array_keys($_SESSION) as $key) {
        if (str_starts_with($key, '_perms_')) {
            unset($_SESSION[$key]);
        }
    }
}

// =============================================================
// Shared User Management Helpers
// =============================================================

function userCreate(PDO $pdo, string $username, string $rawPassword, string $fullName, string $role, int $roleId, ?int $customerId = null): int
{
    $hash = password_hash($rawPassword, PASSWORD_DEFAULT);
    if ($customerId !== null) {
        $stmt = $pdo->prepare('INSERT INTO users (username, password, full_name, role, role_id, customer_id, created_at) VALUES (:u, :p, :fn, :role, :rid, :cid, NOW())');
        $stmt->execute(['u' => $username, 'p' => $hash, 'fn' => $fullName, 'role' => $role, 'rid' => $roleId, 'cid' => $customerId]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO users (username, password, full_name, role, role_id, created_at) VALUES (:u, :p, :fn, :role, :rid, NOW())');
        $stmt->execute(['u' => $username, 'p' => $hash, 'fn' => $fullName, 'role' => $role, 'rid' => $roleId]);
    }
    return (int) $pdo->lastInsertId();
}

function userUpdatePassword(PDO $pdo, int $userId, string $rawPassword): void
{
    $pdo->prepare('UPDATE users SET password = :p WHERE id = :id')
        ->execute(['p' => password_hash($rawPassword, PASSWORD_DEFAULT), 'id' => $userId]);
}

function userFullName(PDO $pdo, int $userId): string
{
    $stmt = $pdo->prepare('SELECT full_name FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    return (string) $stmt->fetchColumn();
}

function copyRolePermissions(int $fromRoleId, int $toRoleId): void
{
    $pdo = getPDO();
    $insert = $pdo->prepare('INSERT INTO role_permissions (role_id, module, permission) VALUES (:rid, :mod, :perm)');
    foreach (loadPermissionsForRole($fromRoleId) as $module => $permList) {
        foreach (array_keys($permList) as $perm) {
            $insert->execute(['rid' => $toRoleId, 'mod' => $module, 'perm' => $perm]);
        }
    }
}

function saveRolePermissions(int $roleId, array $submittedPerms, array $modules, array $allPermissions): void
{
    $pdo = getPDO();
    $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :rid')->execute(['rid' => $roleId]);
    $insert = $pdo->prepare('INSERT INTO role_permissions (role_id, module, permission) VALUES (:rid, :mod, :perm)');
    foreach ($submittedPerms as $module => $permList) {
        if (!isset($modules[$module])) continue;
        foreach ($permList as $perm => $val) {
            if (in_array($perm, $allPermissions, true)) {
                $insert->execute(['rid' => $roleId, 'mod' => $module, 'perm' => $perm]);
            }
        }
    }
}

// =============================================================
// Super Admin audit trail
// =============================================================
// Records sensitive administrative changes (user/role/settings/backup) in a
// dedicated, non-destructive table. Created on demand with CREATE TABLE IF NOT
// EXISTS so it never disturbs existing data.

function ensureAdminAuditLog(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS admin_audit_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NULL,
                username VARCHAR(100) NULL,
                action VARCHAR(100) NOT NULL,
                entity VARCHAR(100) NULL,
                entity_id VARCHAR(64) NULL,
                details TEXT NULL,
                ip_address VARCHAR(45) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_admin_audit_created (created_at),
                KEY idx_admin_audit_user (user_id),
                KEY idx_admin_audit_entity (entity)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        // Table creation is best-effort; logging must never break a request.
    }
}

/**
 * Write an entry to the Super Admin audit trail. Never throws: a logging
 * failure must not abort the underlying administrative operation.
 */
function logAdminAction(string $action, string $entity = '', $entityId = null, string $details = ''): void
{
    try {
        $pdo = getPDO();
        ensureAdminAuditLog($pdo);
        $u = currentUser();
        $stmt = $pdo->prepare(
            'INSERT INTO admin_audit_log (user_id, username, action, entity, entity_id, details, ip_address, created_at)
             VALUES (:uid, :un, :ac, :en, :eid, :de, :ip, NOW())'
        );
        $stmt->execute([
            'uid' => currentUserId(),
            'un'  => isset($u['username']) ? (string) $u['username'] : null,
            'ac'  => $action,
            'en'  => $entity !== '' ? $entity : null,
            'eid' => $entityId !== null ? (string) $entityId : null,
            'de'  => $details !== '' ? $details : null,
            'ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        // ignore
    }
}
