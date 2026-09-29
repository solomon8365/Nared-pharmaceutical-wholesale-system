<?php
/**
 * Credit Management Helpers
 */

require_once __DIR__ . '/../services/SettingsService.php';
require_once __DIR__ . '/workflow_helpers.php';
require_once __DIR__ . '/workflow_engine.php';
require_once __DIR__ . '/credit_ledger.php';

function creditEnsureSettingsMigrated(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $pdo->query('SELECT 1 FROM credit_control_settings LIMIT 1')->fetchAll();
    } catch (Exception $e) { return; } // table doesn't exist
    $defaults = [
        'allow_credit_sales' => '1', 'require_credit_approval' => '1',
        'max_credit_limit' => (string) SettingsService::maxCreditLimit(),
        'default_credit_days' => (string) SettingsService::defaultCreditDays(),
        'grace_period_days' => '7', 'penalty_percentage' => '2',
        'interest_percentage' => '1.5', 'auto_suspend_on_overdue' => '0',
        'block_new_orders_on_overdue' => '0', 'allow_override' => '1',
        'agreement_title' => 'Credit Account Terms & Conditions',
        'agreement_content' => "By placing an order on credit, you agree to the following terms:\n\n"
            . "1. Payment Terms: All credit orders are due within the payment terms stated in the current Credit Agreement (the number of days shown when you accept this agreement). Payment must be made by the due date printed on your invoice.\n"
            . "2. Interest and Penalties: Overdue balances may incur a late payment penalty and monthly interest in accordance with the company's credit policy.\n"
            . "3. Suspension: Failure to pay may result in suspension of your credit account and blocking of new credit orders.\n"
            . "4. Agreement Changes: These terms may be updated from time to time. The version you accept when placing an order is the version that applies to that order.",
        'require_agreement_acceptance' => '1',
    ];
    // Merge with main settings defaults (override if set in our settings table)
    foreach ($defaults as $k => $fallback) {
        $main = get_setting($k, '');
        $defaults[$k] = $main !== '' ? $main : $fallback;
    }
    $ins = $pdo->prepare('INSERT IGNORE INTO credit_control_settings (setting_key, setting_value) VALUES (:k, :v)');
    foreach ($defaults as $k => $v) $ins->execute(['k' => $k, 'v' => $v]);
    $done = true;
}

function creditGetSetting(PDO $pdo, string $key, string $default = '', bool $fresh = false): string {
    static $cache = [];
    if (!$fresh && isset($cache[$key])) return $cache[$key];
    creditEnsureSettingsMigrated($pdo);
    // Check credit_control_settings first (legacy table)
    $stmt = $pdo->prepare('SELECT setting_value FROM credit_control_settings WHERE setting_key = :k');
    $stmt->execute(['k' => $key]);
    $val = $stmt->fetchColumn();
    if ($val !== false) {
        $cache[$key] = $val;
        return $val;
    }
    // Fall back to main settings table
    if (function_exists('get_setting')) {
        $main = get_setting($key, $default);
        $cache[$key] = $main;
        return $main;
    }
    $cache[$key] = $default;
    return $default;
}

function isCreditSalesEnabled(): bool {
    return get_setting('allow_credit_sales', '0') === '1';
}

function creditRecalcUsed(PDO $pdo, int $customerId, ?int $excludeOrderId = null): float {
    $invStmt = $pdo->prepare('SELECT COALESCE(SUM(outstanding_balance), 0) FROM invoices WHERE customer_id = :cid AND payment_status IN ("unpaid","partial")');
    $invStmt->execute(['cid' => $customerId]);
    $invoiceOutstanding = (float) $invStmt->fetchColumn();

    // Only count pending/approved orders that do NOT yet have an invoice, otherwise
    // the amount is double-counted once the order is approved (invoice + order).
    $orderStmt = $pdo->prepare("SELECT COALESCE(SUM(co.total_amount), 0) FROM customer_orders co WHERE co.customer_id = :cid AND co.status IN ('pending','approved') AND co.payment_confirmed = 0 AND co.id <> :excl AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.customer_order_id = co.id)");
    $orderStmt->execute(['cid' => $customerId, 'excl' => $excludeOrderId ?? 0]);
    $pendingOrders = (float) $orderStmt->fetchColumn();

    $used = $invoiceOutstanding + $pendingOrders;
    $pdo->prepare('UPDATE customers SET credit_used = :u, credit_score = :sc WHERE id = :id')
        ->execute(['u' => $used, 'sc' => creditCalculateScore($pdo, $customerId, $used), 'id' => $customerId]);
    return $used;
}

/**
 * Recalculate a customer's credit score from payment behavior, utilization,
 * overdue exposure and write-off history. Deterministic, clamped to 100..999.
 *
 * @param float|null $used Reuse an already-computed credit_used value to avoid recursion.
 */
function creditCalculateScore(PDO $pdo, int $customerId, ?float $used = null): int {
    if ($used === null) {
        $used = creditRecalcUsed($pdo, $customerId);
    }

    $ageStmt = $pdo->prepare('SELECT created_at, credit_limit FROM customers WHERE id = :id');
    $ageStmt->execute(['id' => $customerId]);
    $c = $ageStmt->fetch();
    $limit = $c ? (float) $c['credit_limit'] : 0.0;
    $years = $c && $c['created_at'] ? (int) ((time() - strtotime($c['created_at'])) / 86400 / 365.25) : 0;

    $payStmt = $pdo->prepare('SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM customer_credit_payments WHERE customer_id = :id');
    $payStmt->execute(['id' => $customerId]);
    $payRow = $payStmt->fetch(PDO::FETCH_NUM);
    $payCount = (int) $payRow[0];
    $totalPaid = (float) $payRow[1];

    $invStmt = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(DATEDIFF(CURDATE(), credit_due_date)), 0) AS days FROM invoices WHERE customer_id = :id AND payment_status IN ('unpaid','partial') AND credit_due_date IS NOT NULL AND credit_due_date < CURDATE()");
    $invStmt->execute(['id' => $customerId]);
    $invRow = $invStmt->fetch();
    $overdueInvoices = (int) $invRow['cnt'];
    $overdueDays = (int) $invRow['days'];

    $woStmt = $pdo->prepare("SELECT COUNT(*) FROM credit_audit_log WHERE customer_id = :id AND action = 'write_off'");
    $woStmt->execute(['id' => $customerId]);
    $writeOffs = (int) $woStmt->fetchColumn();

    $score = 500;
    $score += min($payCount, 10) * 10;                    // payment history: up to +100
    $score += min($years, 5) * 10;                        // account age: up to +50
    $score += $totalPaid > 0 ? 10 : 0;                    // ever paid on credit
    if ($limit > 0) {
        $score -= (int) round(min($used / $limit, 1.5) * 100); // utilization: up to -150
    }
    $score -= min($overdueInvoices * 15 + $overdueDays, 200); // overdue exposure: up to -200
    $score -= min($writeOffs * 50, 150);                  // write-offs: up to -150
    return max(100, min(999, $score));
}

function creditGetProfile(PDO $pdo, int $customerId): array {
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = :id');
    $stmt->execute(['id' => $customerId]);
    $c = $stmt->fetch();
    if (!$c) return [];

    $limit = (float) $c['credit_limit'];
    $used = creditRecalcUsed($pdo, $customerId);
    $available = max($limit - $used, 0);
    $outstanding = $used;

    $overdueStmt = $pdo->prepare('SELECT COALESCE(SUM(outstanding_balance), 0) FROM invoices WHERE customer_id = :cid AND payment_status IN ("unpaid","partial") AND credit_due_date IS NOT NULL AND credit_due_date < CURDATE()');
    $overdueStmt->execute(['cid' => $customerId]);
    $overdueBalance = (float) $overdueStmt->fetchColumn();

    $totalPaidStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM customer_credit_payments WHERE customer_id = :cid');
    $totalPaidStmt->execute(['cid' => $customerId]);
    $totalPaid = (float) $totalPaidStmt->fetchColumn();

    $totalCreditSalesStmt = $pdo->prepare('SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE customer_id = :cid');
    $totalCreditSalesStmt->execute(['cid' => $customerId]);
    $totalCreditSales = (float) $totalCreditSalesStmt->fetchColumn();

    $utilization = $limit > 0 ? round(($used / $limit) * 100, 1) : 0;

    $avgPayStmt = $pdo->prepare('SELECT AVG(DATEDIFF(cp.payment_date, i.generated_at)) FROM customer_credit_payments cp JOIN invoices i ON cp.customer_id = i.customer_id WHERE cp.customer_id = :cid');
    $avgPayStmt->execute(['cid' => $customerId]);
    $avgPayDays = $avgPayStmt->fetchColumn();

    return [
        'id' => $c['id'], 'name' => $c['name'], 'customer_name' => $c['name'], 'customer_type' => $c['customer_type'],
        'credit_limit' => $limit, 'credit_used' => $used, 'available_credit' => $available,
        'outstanding_balance' => $outstanding, 'overdue_balance' => $overdueBalance,
        'credit_terms_days' => (int) $c['credit_terms_days'],
        'credit_period_days' => (int) ($c['credit_period_days'] ?? $c['credit_terms_days']),
        'is_credit_blocked' => (int) ($c['is_credit_blocked'] ?? 0),
        'is_credit_approved' => (int) $c['is_credit_approved'],
        'credit_approval_status' => $c['credit_approval_status'] ?? 'pending',
        'credit_approved_by' => $c['credit_approved_by'] ?? null,
        'credit_approved_at' => $c['credit_approved_at'] ?? null,
        'last_payment_date' => $c['last_payment_date'], 'last_payment_amount' => (float) $c['last_payment_amount'],
        'total_credit_sales' => $totalCreditSales, 'total_payments' => $totalPaid,
        'credit_score' => (int) $c['credit_score'],
        'utilization' => $utilization, 'avg_pay_days' => $avgPayDays,
        'customer_status' => $c['customer_status'],
    ];
}

function creditGetAging(PDO $pdo, ?int $customerId = null): array {
    $buckets = ['current' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 0, '90_plus' => 0];
    $bucketInvoices = ['current' => [], '1_30' => [], '31_60' => [], '61_90' => [], '90_plus' => []];

    $where = 'WHERE i.payment_status IN ("unpaid","partial") AND i.outstanding_balance > 0';
    $params = [];
    if ($customerId) { $where .= ' AND i.customer_id = :cid'; $params['cid'] = $customerId; }

    $stmt = $pdo->prepare("SELECT i.*, c.name AS customer_name FROM invoices i JOIN customers c ON i.customer_id = c.id {$where} ORDER BY i.credit_due_date ASC");
    $stmt->execute($params);
    $invoices = $stmt->fetchAll();

    $now = new DateTime();
    foreach ($invoices as $inv) {
        $bal = (float) $inv['outstanding_balance'];
        if (!$inv['credit_due_date']) { $buckets['current'] += $bal; $bucketInvoices['current'][] = $inv; continue; }
        $due = new DateTime($inv['credit_due_date']);
        $days = (int) $now->diff($due)->days;
        if ($due >= $now) { $buckets['current'] += $bal; $bucketInvoices['current'][] = $inv; }
        elseif ($days <= 30) { $buckets['1_30'] += $bal; $bucketInvoices['1_30'][] = $inv; }
        elseif ($days <= 60) { $buckets['31_60'] += $bal; $bucketInvoices['31_60'][] = $inv; }
        elseif ($days <= 90) { $buckets['61_90'] += $bal; $bucketInvoices['61_90'][] = $inv; }
        else { $buckets['90_plus'] += $bal; $bucketInvoices['90_plus'][] = $inv; }
    }
    return ['buckets' => $buckets, 'invoices' => $bucketInvoices, 'total' => array_sum($buckets)];
}

function creditLogAudit(PDO $pdo, int $customerId, string $action, ?string $oldVal = null, ?string $newVal = null, ?int $userId = null): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $pdo->prepare('INSERT INTO credit_audit_log (customer_id, user_id, action, old_value, new_value, ip_address) VALUES (:cid, :uid, :act, :ov, :nv, :ip)')
        ->execute(['cid' => $customerId, 'uid' => $userId, 'act' => $action, 'ov' => $oldVal, 'nv' => $newVal, 'ip' => $ip]);
}

function creditGetLimit(PDO $pdo, int $customerId): float {
    $stmt = $pdo->prepare('SELECT credit_limit FROM customers WHERE id = :id');
    $stmt->execute(['id' => $customerId]);
    return (float) $stmt->fetchColumn();
}

function creditSetLimit(PDO $pdo, int $customerId, float $newLimit): void {
    $pdo->prepare('UPDATE customers SET credit_limit = :cl WHERE id = :id')->execute(['cl' => $newLimit, 'id' => $customerId]);
}

/**
 * Create a credit profile for an EXISTING customer by updating the customer's
 * own credit fields on the customers table. No duplicate customer or credit
 * records are ever created. Follows the existing approval workflow: a new
 * profile starts as 'pending' until approved in the Credit Approvals tab.
 *
 * @return array{success: bool, msg: string}
 */
function creditCreateProfile(PDO $pdo, int $customerId, float $limit, int $termsDays, ?int $userId = null): array {
    if ($customerId <= 0) return ['success' => false, 'msg' => 'Please select a customer.'];
    if (!is_finite($limit) || $limit <= 0) return ['success' => false, 'msg' => 'Credit limit must be a positive amount.'];

    $stmt = $pdo->prepare('SELECT name, credit_limit, customer_status FROM customers WHERE id = :id');
    $stmt->execute(['id' => $customerId]);
    $c = $stmt->fetch();
    if (!$c) return ['success' => false, 'msg' => 'Selected customer does not exist.'];
    if ($c['customer_status'] === 'suspended') return ['success' => false, 'msg' => htmlspecialchars($c['name']) . ' is suspended and cannot be granted credit.'];

    if ((float) $c['credit_limit'] > 0) {
        return ['success' => false, 'msg' => htmlspecialchars($c['name']) . ' already has an active credit profile.'];
    }

    $maxLimit = (float) creditGetSetting($pdo, 'max_credit_limit', (string) SettingsService::maxCreditLimit());
    if ($maxLimit > 0 && $limit > $maxLimit) {
        return ['success' => false, 'msg' => 'Credit limit exceeds the maximum of ' . fmt($maxLimit) . '.'];
    }

    if ($termsDays < 1 || $termsDays > 365) {
        return ['success' => false, 'msg' => 'Payment terms must be between 1 and 365 days.'];
    }

    $pdo->prepare('UPDATE customers SET credit_limit = :cl, credit_terms_days = :td, credit_period_days = :td, credit_approval_status = :st, is_credit_approved = :ia WHERE id = :id')
        ->execute(['cl' => $limit, 'td' => $termsDays, 'st' => 'pending', 'ia' => 0, 'id' => $customerId]);

    creditRecalcUsed($pdo, $customerId);
    creditLogAudit($pdo, $customerId, 'credit_profile_created', null,
        json_encode(['limit' => $limit, 'terms_days' => $termsDays, 'status' => 'pending']), $userId);
    creditNotify($pdo, $customerId, 'info', 'Credit Account Requested',
        'A credit profile has been created for your account and is awaiting approval.');

    return ['success' => true, 'msg' => 'Credit profile created for ' . htmlspecialchars($c['name'])
        . ' (limit ' . fmt($limit) . ', ' . $termsDays . ' days, pending approval).'];
}

function creditGetApprovalStatus(PDO $pdo, int $customerId): ?string {
    $stmt = $pdo->prepare('SELECT credit_approval_status FROM customers WHERE id = :id');
    $stmt->execute(['id' => $customerId]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (string) $v;
}

/**
 * Ensure the Credit Agreement schema exists: versioned credit_agreements table,
 * order/invoice agreement columns. Idempotent; safe to call repeatedly.
 */
function creditEnsureAgreementMigration(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $sqls = [
        "CREATE TABLE IF NOT EXISTS credit_agreements (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            version INT NOT NULL,
            title VARCHAR(255) NOT NULL DEFAULT '',
            content TEXT NULL,
            payment_terms_days INT NOT NULL DEFAULT 30,
            require_acceptance TINYINT(1) NOT NULL DEFAULT 1,
            require_credit_approval TINYINT(1) NOT NULL DEFAULT 1,
            rules_json TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_credit_agreements_version (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='agreement_version_id');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN agreement_version_id INT UNSIGNED NULL AFTER payment_method','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='agreement_accepted_at');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN agreement_accepted_at DATETIME NULL AFTER agreement_version_id','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='agreement_version_id');
         SET @s=IF(@e=0,'ALTER TABLE invoices ADD COLUMN agreement_version_id INT UNSIGNED NULL AFTER payment_status','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
    ];
    foreach ($sqls as $sql) {
        try { $pdo->exec($sql); } catch (Exception $e) { /* ignore */ }
    }
}

/**
 * Current editable agreement configuration, read from the existing
 * credit_control_settings store (no duplicate settings system).
 */
function creditAgreementSettings(PDO $pdo, bool $fresh = false): array {
    return [
        'title' => (string) creditGetSetting($pdo, 'agreement_title', 'Credit Account Terms & Conditions', $fresh),
        'content' => (string) creditGetSetting($pdo, 'agreement_content', '', $fresh),
        'payment_terms_days' => max(1, (int) creditGetSetting($pdo, 'default_credit_days', '30', $fresh)),
        'require_acceptance' => creditGetSetting($pdo, 'require_agreement_acceptance', '1', $fresh) === '1',
        'require_credit_approval' => creditGetSetting($pdo, 'require_credit_approval', '1', $fresh) === '1',
        'rules_json' => json_encode([
            'max_credit_limit' => (float) creditGetSetting($pdo, 'max_credit_limit', '500000', $fresh),
            'grace_period_days' => (int) creditGetSetting($pdo, 'grace_period_days', '7', $fresh),
            'penalty_percentage' => (float) creditGetSetting($pdo, 'penalty_percentage', '2', $fresh),
            'interest_percentage' => (float) creditGetSetting($pdo, 'interest_percentage', '1.5', $fresh),
            'auto_suspend_on_overdue' => creditGetSetting($pdo, 'auto_suspend_on_overdue', '0', $fresh) === '1',
            'block_new_orders_on_overdue' => creditGetSetting($pdo, 'block_new_orders_on_overdue', '0', $fresh) === '1',
            'allow_credit_sales' => creditGetSetting($pdo, 'allow_credit_sales', '1', $fresh) === '1',
        ]),
    ];
}

/**
 * The active (currently applicable) Credit Agreement as an immutable version
 * row. Customers see and accept THIS version; it is snapshotted onto orders so
 * later agreement changes never rewrite historical orders.
 */
function creditGetActiveAgreement(PDO $pdo): array {
    creditEnsureAgreementMigration($pdo);
    $stmt = $pdo->query('SELECT * FROM credit_agreements WHERE is_active = 1 ORDER BY id DESC LIMIT 1');
    $row = $stmt->fetch();
    if ($row) return $row;
    $settings = creditAgreementSettings($pdo);
    $pdo->prepare('INSERT INTO credit_agreements (version, title, content, payment_terms_days, require_acceptance, require_credit_approval, rules_json, is_active, created_by, created_at) VALUES (1, :t, :c, :ptd, :ra, :rca, :rj, 1, NULL, NOW())')
        ->execute([
            't' => $settings['title'], 'c' => $settings['content'],
            'ptd' => $settings['payment_terms_days'],
            'ra' => $settings['require_acceptance'] ? 1 : 0,
            'rca' => $settings['require_credit_approval'] ? 1 : 0,
            'rj' => $settings['rules_json'],
        ]);
    $id = (int) $pdo->lastInsertId();
    $stmt2 = $pdo->prepare('SELECT * FROM credit_agreements WHERE id = :id');
    $stmt2->execute(['id' => $id]);
    return $stmt2->fetch() ?: [];
}

/**
 * Publish a new Credit Agreement version from the current settings.
 * Deactivates the previous active version so new orders use the new terms
 * while orders already placed keep their accepted version.
 */
function creditPublishAgreement(PDO $pdo, ?int $userId = null, bool $fresh = true): array {
    creditEnsureAgreementMigration($pdo);
    $settings = creditAgreementSettings($pdo, $fresh);
    $maxVer = (int) $pdo->query('SELECT COALESCE(MAX(version), 0) FROM credit_agreements')->fetchColumn();
    $version = $maxVer + 1;
    $pdo->prepare('UPDATE credit_agreements SET is_active = 0 WHERE is_active = 1')->execute();
    $pdo->prepare('INSERT INTO credit_agreements (version, title, content, payment_terms_days, require_acceptance, require_credit_approval, rules_json, is_active, created_by, created_at) VALUES (:v, :t, :c, :ptd, :ra, :rca, :rj, 1, :by, NOW())')
        ->execute([
            'v' => $version, 't' => $settings['title'], 'c' => $settings['content'],
            'ptd' => $settings['payment_terms_days'],
            'ra' => $settings['require_acceptance'] ? 1 : 0,
            'rca' => $settings['require_credit_approval'] ? 1 : 0,
            'rj' => $settings['rules_json'], 'by' => $userId,
        ]);
    return ['success' => true, 'version' => $version, 'id' => (int) $pdo->lastInsertId()];
}

/** Fetch a specific immutable agreement version by id (or null). */
function creditGetAgreementVersion(PDO $pdo, ?int $id): ?array {
    if (!$id) return null;
    creditEnsureAgreementMigration($pdo);
    $stmt = $pdo->prepare('SELECT * FROM credit_agreements WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Whether customers must explicitly accept the credit agreement. */
function creditRequireAgreementAcceptance(PDO $pdo): bool {
    return creditGetSetting($pdo, 'require_agreement_acceptance', '1') === '1';
}

/** Payment terms (days) that apply to a credit order, from its agreement. */
function creditAgreementTermsForOrder(PDO $pdo, ?int $agreementVersionId, ?int $customerId = null): int {
    $ag = $agreementVersionId ? creditGetAgreementVersion($pdo, $agreementVersionId) : null;
    if ($ag && (int) $ag['payment_terms_days'] > 0) {
        return (int) $ag['payment_terms_days'];
    }
    if ($customerId) {
        $stmt = $pdo->prepare('SELECT credit_terms_days FROM customers WHERE id = :id');
        $stmt->execute(['id' => $customerId]);
        $terms = (int) $stmt->fetchColumn();
        if ($terms > 0) return $terms;
    }
    return (int) creditGetSetting($pdo, 'default_credit_days', '30');
}

function customerName(PDO $pdo, int $customerId): string {
    $stmt = $pdo->prepare('SELECT name FROM customers WHERE id = :id');
    $stmt->execute(['id' => $customerId]);
    return (string) $stmt->fetchColumn();
}

function creditNotify(PDO $pdo, int $customerId, string $type, string $title, string $message, ?int $userId = null): void {
    $pdo->prepare('INSERT INTO credit_notifications (customer_id, user_id, type, title, message) VALUES (:cid, :uid, :type, :title, :msg)')
        ->execute(['cid' => $customerId, 'uid' => $userId, 'type' => $type, 'title' => $title, 'msg' => $message]);
}

/**
 * Ensure the credit module's schema exists (penalty/interest columns,
 * auto_suspended_at). Skipped inside an open transaction because MySQL DDL
 * would implicitly commit the caller's transaction.
 */
function creditEnsureMigrations(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    require_once __DIR__ . '/auto_migrate.php';
    if ($pdo->inTransaction()) return;
    ensureOrderWorkflowMigration($pdo);
    creditEnsureAgreementMigration($pdo);
    creditLedgerEnsureMigrations($pdo);
    $done = true;
}

function creditSyncOverdue(PDO $pdo): int {
    creditEnsureMigrations($pdo);
    $stmt = $pdo->prepare("UPDATE invoices SET days_overdue = DATEDIFF(CURDATE(), credit_due_date), overdue_date = credit_due_date WHERE credit_due_date IS NOT NULL AND credit_due_date < CURDATE() AND payment_status IN ('unpaid','partial')");
    $stmt->execute();
    $count = $stmt->rowCount();

    // Enforce late charges (penalty + monthly interest) and auto-suspend policy.
    creditApplyLateCharges($pdo);
    creditAutoSuspendOverdue($pdo);
    creditRestoreAutoSuspended($pdo);

    // Keep customers.is_credit_blocked in sync with the policy gates (once per request).
    static $flagged = false;
    if (!$flagged && !$pdo->inTransaction()) {
        creditLedgerSyncBlockFlags($pdo);
        $flagged = true;
    }

    return $count;
}

/**
 * Apply one-time penalty and monthly interest to invoices past the grace
 * period, adding them to outstanding_balance so they are collected with the
 * next payment. Tracking columns prevent double-charging.
 */
function creditApplyLateCharges(PDO $pdo): int {
    $grace = (int) creditGetSetting($pdo, 'grace_period_days', '7');
    $penaltyPct = (float) creditGetSetting($pdo, 'penalty_percentage', '2');
    $interestPct = (float) creditGetSetting($pdo, 'interest_percentage', '1.5');
    if ($penaltyPct <= 0 && $interestPct <= 0) return 0;

    $applied = 0;
    $stmt = $pdo->query("SELECT id, customer_id, outstanding_balance, credit_due_date, penalty_applied_at, interest_last_applied_at FROM invoices WHERE payment_status IN ('unpaid','partial') AND outstanding_balance > 0 AND credit_due_date IS NOT NULL AND credit_due_date < CURDATE()");
    foreach ($stmt->fetchAll() as $inv) {
        $due = new DateTime($inv['credit_due_date']);
        $daysLate = (int) (new DateTime())->diff($due)->days;
        if ($daysLate <= $grace) continue;
        $bal = (float) $inv['outstanding_balance'];
        $charged = 0.0;

        // One-time late fee once past grace
        if ($penaltyPct > 0 && empty($inv['penalty_applied_at'])) {
            $penalty = round($bal * $penaltyPct / 100, 2);
            if ($penalty > 0) {
                $charged += $penalty;
                $bal += $penalty; // interest below accrues on the balance including the late fee
                $pdo->prepare('UPDATE invoices SET outstanding_balance = :bal, penalty_amount = penalty_amount + :p, penalty_applied_at = CURDATE() WHERE id = :id')
                    ->execute(['bal' => $bal, 'p' => $penalty, 'id' => $inv['id']]);
            }
        }

        // Monthly interest on the outstanding balance, first accruing after grace
        if ($interestPct > 0) {
            $start = $inv['interest_last_applied_at']
                ? new DateTime($inv['interest_last_applied_at'])
                : (clone $due)->modify('+' . $grace . ' days');
            if ($start > new DateTime()) $start = new DateTime();
            $diff = (new DateTime())->diff($start);
            $months = ($diff->y * 12) + $diff->m;
            if ($months >= 1) {
                $interest = round($bal * $interestPct / 100 * $months, 2);
                if ($interest > 0) {
                    $next = (clone $start)->modify('+' . $months . ' months');
                    $next = $next > new DateTime() ? new DateTime() : $next;
                    $charged += $interest;
                    $pdo->prepare('UPDATE invoices SET outstanding_balance = outstanding_balance + :i, interest_last_applied_at = :last WHERE id = :id')
                        ->execute(['i' => $interest, 'last' => $next->format('Y-m-d'), 'id' => $inv['id']]);
                }
            }
        }

        if ($charged > 0) {
            creditLogAudit($pdo, (int) $inv['customer_id'], 'late_charges_applied', null,
                json_encode(['invoice_id' => (int) $inv['id'], 'amount' => $charged, 'days_late' => $daysLate]), null);
            $applied++;
        }
    }
    return $applied;
}

/**
 * Auto-suspend credit for approved/over-limit customers who are overdue beyond
 * the grace period, when the auto_suspend_on_overdue setting is enabled.
 */
function creditAutoSuspendOverdue(PDO $pdo): int {
    if (creditGetSetting($pdo, 'auto_suspend_on_overdue', '0') !== '1') return 0;
    $grace = (int) creditGetSetting($pdo, 'grace_period_days', '7');

    $stmt = $pdo->query("SELECT id FROM customers c WHERE c.credit_approval_status IN ('approved','over_limit') AND EXISTS (
        SELECT 1 FROM invoices i WHERE i.customer_id = c.id AND i.payment_status IN ('unpaid','partial')
          AND i.outstanding_balance > 0 AND i.credit_due_date IS NOT NULL
          AND DATEDIFF(CURDATE(), i.credit_due_date) > " . (int) $grace . ')');

    $suspended = 0;
    foreach ($stmt->fetchAll() as $c) {
        $cid = (int) $c['id'];
        WorkflowEngine::transition($pdo, 'credit', $cid, 'suspended', [
            'skip_transaction' => true,
            'extra' => ['is_credit_approved' => 0, 'auto_suspended_at' => date('Y-m-d H:i:s')],
            'notes' => 'Automatic: overdue balance beyond grace period',
        ]);
        creditLogAudit($pdo, $cid, 'auto_suspend_set', 'approved', 'suspended', null);
        creditNotify($pdo, $cid, 'warning', 'Credit Suspended',
            'Your credit has been automatically suspended due to an overdue balance. Please make a payment to restore access.');
        $suspended++;
    }
    return $suspended;
}

/**
 * Restore credit for auto-suspended customers whose overdue balance has been
 * cleared (paid off or due date extended). Only applies when auto-suspend is on.
 */
function creditRestoreAutoSuspended(PDO $pdo, ?int $customerId = null): int {
    if (creditGetSetting($pdo, 'auto_suspend_on_overdue', '0') !== '1') return 0;
    $grace = (int) creditGetSetting($pdo, 'grace_period_days', '7');

    $where = "auto_suspended_at IS NOT NULL AND credit_approval_status = 'suspended' AND NOT EXISTS (
        SELECT 1 FROM invoices i WHERE i.customer_id = customers.id AND i.payment_status IN ('unpaid','partial')
          AND i.outstanding_balance > 0 AND i.credit_due_date IS NOT NULL
          AND DATEDIFF(CURDATE(), i.credit_due_date) > " . (int) $grace . ')';
    $params = [];
    if ($customerId) { $where .= ' AND id = :cid'; $params['cid'] = $customerId; }

    $stmt = $pdo->prepare("SELECT id FROM customers WHERE {$where}");
    $stmt->execute($params);
    $restored = 0;
    foreach ($stmt->fetchAll() as $c) {
        $cid = (int) $c['id'];
        WorkflowEngine::transition($pdo, 'credit', $cid, 'approved', [
            'skip_transaction' => true,
            'extra' => ['is_credit_approved' => 1, 'auto_suspended_at' => null],
            'notes' => 'Automatic: overdue balance cleared',
        ]);
        creditLogAudit($pdo, $cid, 'auto_suspend_cleared', 'suspended', 'approved', null);
        creditNotify($pdo, $cid, 'success', 'Credit Restored',
            'Your overdue balance has been cleared and credit access has been restored.');
        $restored++;
    }
    return $restored;
}

function creditCheckOrder(PDO $pdo, int $customerId, float $orderAmount): array {
    $allow = creditGetSetting($pdo, 'allow_credit_sales', '1');
    if ($allow !== '1') return ['ok' => false, 'msg' => 'Credit sales are disabled.'];

    $profile = creditGetProfile($pdo, $customerId);
    if (empty($profile)) return ['ok' => false, 'msg' => 'Customer not found.'];

    if ($profile['customer_status'] === 'suspended') return ['ok' => false, 'msg' => 'Customer account is suspended.'];

    if ($profile['credit_approval_status'] === 'suspended') return ['ok' => false, 'msg' => 'Customer credit is suspended.'];

    if ($profile['credit_approval_status'] !== 'approved') {
        $requireApproval = creditGetSetting($pdo, 'require_credit_approval', '1');
        if ($requireApproval === '1') return ['ok' => false, 'msg' => 'Credit not approved for this customer.'];
    }

    $maxLimit = (float) creditGetSetting($pdo, 'max_credit_limit', '500000');
    if ($profile['credit_limit'] > $maxLimit) {
        // still allow - just note
    }

    $blockOnOverdue = creditGetSetting($pdo, 'block_new_orders_on_overdue', '0');
    if ($blockOnOverdue === '1' && $profile['overdue_balance'] > 0) {
        return ['ok' => false, 'msg' => 'Customer has overdue balance of ' . fmt($profile['overdue_balance']) . '. New orders blocked.'];
    }

    if ($orderAmount > $profile['available_credit']) {
        return ['ok' => false, 'msg' => 'Credit limit exceeded. Available: ' . fmt($profile['available_credit']) . ', Order: ' . fmt($orderAmount) . '.'];
    }

    return ['ok' => true, 'msg' => '', 'profile' => $profile];
}

/**
 * ERP-style credit validation with override support.
 * Returns detailed breakdown: credit_limit, outstanding, available, requested, exceeded, utilization.
 * Only Finance Manager (finance) or Super Admin (admin) can override.
 *
 * @param int|null $excludeOrderId Exclude this order id from the outstanding total (used when
 *                                 re-validating an order that is already counted, e.g. approval/edits).
 */
function creditValidateOrder(PDO $pdo, int $customerId, float $orderAmount, bool $override = false, ?int $excludeOrderId = null): array {
    $profile = creditGetProfile($pdo, $customerId);
    if (empty($profile)) {
        return ['valid' => false, 'msg' => 'Customer not found.'];
    }

    // Policy gates (mirror creditCheckOrder) so disabled/suspended/rejected/unapproved
    // customers cannot place or approve credit orders through the order pipeline.
    if (creditGetSetting($pdo, 'allow_credit_sales', '1') !== '1') {
        return ['valid' => false, 'msg' => 'Credit sales are disabled.'];
    }
    if ($profile['customer_status'] === 'suspended') {
        return ['valid' => false, 'msg' => 'Customer account is suspended.'];
    }
    if ($profile['credit_approval_status'] === 'suspended') {
        return ['valid' => false, 'msg' => 'Customer credit is suspended.'];
    }
    if ($profile['credit_approval_status'] === 'rejected') {
        return ['valid' => false, 'msg' => 'Customer credit was rejected.'];
    }
    if ($profile['credit_approval_status'] !== 'approved') {
        $requireApproval = creditGetSetting($pdo, 'require_credit_approval', '1');
        if ($requireApproval === '1') {
            return ['valid' => false, 'msg' => 'Credit not approved for this customer.'];
        }
    }
    $blockOnOverdue = creditGetSetting($pdo, 'block_new_orders_on_overdue', '0');
    if ($blockOnOverdue === '1' && $profile['overdue_balance'] > 0) {
        return ['valid' => false, 'msg' => 'Customer has an overdue balance of ' . fmt($profile['overdue_balance']) . '. New credit orders are blocked.'];
    }

    $limit = $profile['credit_limit'];
    $outstanding = creditRecalcUsed($pdo, $customerId, $excludeOrderId);
    $available = max($limit - $outstanding, 0);
    $utilization = $limit > 0 ? round(($outstanding / $limit) * 100, 1) : 0;
    $exceeded = max(($outstanding + $orderAmount) - $limit, 0);
    $isOverLimit = $limit > 0 && ($outstanding + $orderAmount) > $limit;

    $result = [
        'valid' => true,
        'msg' => '',
        'credit_limit' => $limit,
        'outstanding' => $outstanding,
        'available' => $available,
        'requested' => $orderAmount,
        'exceeded' => $exceeded,
        'utilization' => $utilization,
        'is_over_limit' => $isOverLimit,
        'profile' => $profile,
    ];

    if ($isOverLimit) {
        $result['valid'] = false;
        if ($override) {
            if (!creditCanOverride()) {
                $result['msg'] = 'Only users with the credit override permission can override the credit limit.';
                $result['valid'] = false;
                $result['override_rejected'] = true;
            } else {
                $user = currentUser();
                $result['valid'] = true;
                $result['msg'] = 'Overridden by ' . htmlspecialchars(($user['username'] ?? '') ?: (string) ($user['role'] ?? 'user'));
                $result['overridden'] = true;
            }
        } else {
            $result['msg'] = 'Credit limit exceeded by ' . fmt($exceeded) . '. '
                . 'Limit: ' . fmt($limit) . ', Outstanding: ' . fmt($outstanding)
                . ', Available: ' . fmt($available) . ', Requested: ' . fmt($orderAmount) . '.';
        }
    }

    return $result;
}

/**
 * Apply OVER LIMIT status to a customer if they exceed their credit limit.
 * Returns 'over_limit' | 'near_limit' | 'safe' | 'no_limit'.
 */
function creditApplyOverLimitStatus(PDO $pdo, int $customerId): string {
    $profile = creditGetProfile($pdo, $customerId);
    if (empty($profile) || $profile['credit_limit'] <= 0) return 'no_limit';

    $utilization = $profile['utilization'];
    $exceedsLimit = $profile['credit_used'] > $profile['credit_limit'];

    // Update credit_approval_status based on over-limit condition
    if ($exceedsLimit && $profile['credit_approval_status'] === 'approved') {
        WorkflowEngine::transition($pdo, 'credit', $customerId, 'over_limit', ['skip_transaction' => true, 'notes' => 'Automatic: credit usage exceeded limit']);
        creditLogAudit($pdo, $customerId, 'over_limit_set', 'approved', 'over_limit', null);
        creditNotify($pdo, $customerId, 'warning', 'Credit Over Limit',
            'Your credit usage has exceeded your limit. Please make a payment to restore credit access.');
        return 'over_limit';
    }

    if ($utilization >= 100) return 'over_limit';
    if ($utilization > 80) return 'near_limit';
    return 'safe';
}

/**
 * Render a credit utilization bar with color coding.
 */
function creditRenderUtilizationBar(float $utilization): string {
    $color = $utilization >= 100 ? 'var(--fin-red)' : ($utilization > 80 ? 'var(--fin-orange)' : 'var(--fin-green)');
    $width = min($utilization, 100);
    return '<div class="cc-util-bar"><div class="cc-util-fill" style="width:' . $width . '%;background:' . $color . '"></div></div>';
}

/**
 * Get the color class for credit status.
 */
function creditUtilizationClass(float $utilization): string {
    if ($utilization >= 100) return 'cc-badge-red';
    if ($utilization > 80) return 'cc-badge-orange';
    return 'cc-badge-green';
}

/**
 * Determine if the current user may override the credit limit.
 * Permission-driven (credit_management.override / credit_control.override)
 * with a legacy role fallback when the permission layer is unavailable.
 */
function creditCanOverride(): bool {
    if (function_exists('hasPermission')) {
        return hasPermission('credit_management', 'override') || hasPermission('credit_control', 'override');
    }
    $user = currentUser();
    if (!$user) return false;
    $role = $user['role'] ?? '';
    return $role === 'finance' || $role === 'admin';
}

/**
 * Record a customer credit payment with server-side validation and
 * duplicate-reference prevention. Allocations are applied FIFO across the
 * customer's open invoices inside a single transaction (rolled back on error).
 *
 * @param string|null $error Out-param receiving a human-readable failure reason.
 */
function creditRecordPayment(PDO $pdo, int $customerId, float $amount, string $payDate, string $method, string $refNo, string $notes, ?int $userId = null, ?string &$error = null): bool {
    $allowedMethods = ['cash', 'bank', 'cbe_birr', 'telebirr', 'credit'];
    $amount = (float) $amount;
    $refNo = trim((string) $refNo);
    $payDate = trim((string) $payDate);
    if ($amount <= 0) { $error = 'Payment amount must be greater than zero.'; return false; }
    $d = DateTime::createFromFormat('Y-m-d', $payDate);
    if (!$d || $d->format('Y-m-d') !== $payDate) { $error = 'Invalid payment date.'; return false; }
    if ($method === '' || !in_array($method, $allowedMethods, true)) { $error = 'Invalid payment method.'; return false; }
    if ($refNo === '') { $error = 'A payment reference number is required.'; return false; }

    $cust = $pdo->prepare('SELECT id FROM customers WHERE id = :id');
    $cust->execute(['id' => $customerId]);
    if (!$cust->fetchColumn()) { $error = 'Customer not found.'; return false; }

    creditEnsureMigrations($pdo);
    $pdo->beginTransaction();
    try {
        // Duplicate prevention: the same reference should never be recorded twice
        // for the same customer at the same amount (customer_credit_payments OR the
        // invoice_payments ledger, which the finance pipeline also writes to).
        $dupStmt = $pdo->prepare('SELECT COUNT(*) FROM customer_credit_payments WHERE customer_id = :cid AND reference_no = :ref AND reference_no <> "" AND amount = :amt');
        $dupStmt->execute(['cid' => $customerId, 'ref' => $refNo, 'amt' => $amount]);
        $dupInv = $pdo->prepare('SELECT COUNT(*) FROM invoice_payments WHERE customer_id = :cid AND reference_no = :ref AND reference_no <> "" AND amount = :amt');
        $dupInv->execute(['cid' => $customerId, 'ref' => $refNo, 'amt' => $amount]);
        if ((int) $dupStmt->fetchColumn() > 0 || (int) $dupInv->fetchColumn() > 0) {
            $error = 'A payment with reference ' . $refNo . ' and the same amount has already been recorded.';
            $pdo->rollBack();
            return false;
        }

        $pdo->prepare('INSERT INTO customer_credit_payments (customer_id, amount, payment_date, payment_method, reference_no, notes, created_by) VALUES (:cid, :amt, :dt, :pm, :ref, :notes, :uid)')
            ->execute(['cid' => $customerId, 'amt' => $amount, 'dt' => $payDate, 'pm' => $method, 'ref' => $refNo, 'notes' => $notes, 'uid' => $userId]);

        $pdo->prepare('UPDATE customers SET last_payment_date = NOW(), last_payment_amount = :amt WHERE id = :cid')
            ->execute(['amt' => $amount, 'cid' => $customerId]);

        // Allocate payment across unpaid/partial invoices (FIFO) via finance pipeline
        $remaining = $amount;
        $invStmt = $pdo->prepare('SELECT id, outstanding_balance FROM invoices WHERE customer_id = :cid AND payment_status IN ("unpaid","partial") AND outstanding_balance > 0 ORDER BY generated_at ASC');
        $invStmt->execute(['cid' => $customerId]);

        foreach ($invStmt->fetchAll() as $inv) {
            if ($remaining <= 0) break;
            $toApply = min($remaining, (float) $inv['outstanding_balance']);
            recordInvoicePayment($pdo, (int) $inv['id'], $toApply, $method, $refNo ?: null, null, $notes ?: null, $userId);
            $remaining -= $toApply;
        }

        creditRecalcUsed($pdo, $customerId);
        // Check over-limit status - if paid down, restore to approved
        creditApplyOverLimitStatus($pdo, $customerId);
        $profile = creditGetProfile($pdo, $customerId);
        if ($profile['credit_used'] <= $profile['credit_limit'] && $profile['credit_approval_status'] === 'over_limit') {
            // Payment brought customer back under limit, restore approval
            WorkflowEngine::transition($pdo, 'credit', $customerId, 'approved', ['skip_transaction' => true, 'extra' => ['is_credit_approved' => 1], 'notes' => 'Automatic: usage restored within limit']);
            creditLogAudit($pdo, $customerId, 'over_limit_cleared', 'over_limit', 'approved', $userId);
            creditNotify($pdo, $customerId, 'success', 'Credit Limit Restored',
                'Your payment has brought your account back within the credit limit.');
        }
        // Restore credit if this payment cleared the overdue balance of an auto-suspended customer
        creditRestoreAutoSuspended($pdo, $customerId);
        $profile = creditGetProfile($pdo, $customerId);
        creditLogAudit($pdo, $customerId, 'payment_recorded', null, json_encode(['amount' => $amount, 'method' => $method, 'ref' => $refNo, 'allocated' => $amount - $remaining]), $userId);
        if ($profile['outstanding_balance'] <= 0) {
            creditNotify($pdo, $customerId, 'success', 'All Balances Cleared', 'Your outstanding balance has been fully paid.');
        } else {
            creditNotify($pdo, $customerId, 'info', 'Payment Received', 'Payment of ' . fmt($amount) . ' recorded. Remaining balance: ' . fmt($profile['outstanding_balance']) . '.');
        }

        // Refresh the durable ledger projection + block flag inside this transaction.
        creditLedgerResetBestEffort($pdo, $customerId);

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
        return false;
    }
}

/**
 * Reduce a credit invoice's outstanding balance when a customer refund is
 * processed against the underlying order (audited, must run inside the
 * caller's transaction so it rolls back with the refund).
 *
 * @return array ['success' => bool, 'msg' => string, 'applied' => float]
 */
function creditApplyRefund(PDO $pdo, int $customerId, int $orderId, float $amount, ?int $userId = null): array {
    $invStmt = $pdo->prepare('SELECT id, total_amount, amount_paid, outstanding_balance, payment_status, sales_type FROM invoices WHERE customer_order_id = :oid AND customer_id = :cid ORDER BY id DESC LIMIT 1');
    $invStmt->execute(['oid' => $orderId, 'cid' => $customerId]);
    $inv = $invStmt->fetch();
    if (!$inv) return ['success' => false, 'msg' => '', 'applied' => 0.0];
    if (($inv['sales_type'] ?? 'cash') !== 'credit') return ['success' => false, 'msg' => '', 'applied' => 0.0];

    $oldBal = (float) $inv['outstanding_balance'];
    if ($oldBal <= 0) return ['success' => false, 'msg' => '', 'applied' => 0.0];

    $apply = min($amount, $oldBal);
    $newBal = round($oldBal - $apply, 2);
    $newStatus = $newBal <= 0 ? 'paid' : 'partial';
    // Keep total - amount_paid == outstanding_balance consistent.
    $amountPaid = max((float) $inv['amount_paid'], (float) $inv['total_amount'] - $newBal);
    $pdo->prepare('UPDATE invoices SET outstanding_balance = :bal, amount_paid = :ap, payment_status = :ps WHERE id = :id')
        ->execute(['bal' => $newBal, 'ap' => $amountPaid, 'ps' => $newStatus, 'id' => $inv['id']]);
    creditLogAudit($pdo, $customerId, 'credit_refund', (string) $oldBal,
        json_encode(['order_id' => $orderId, 'invoice_id' => (int) $inv['id'], 'amount' => $apply]), $userId);
    creditRecalcUsed($pdo, $customerId);
    if ($newStatus !== $inv['payment_status']) {
        require_once __DIR__ . '/workflow_engine.php';
        WorkflowEngine::logStatusChange($pdo, 'invoice', (int) $inv['id'], $inv['payment_status'], $newStatus, $userId, 'Refund applied against credit invoice');
    }
    return ['success' => true, 'msg' => 'Credit outstanding reduced by ' . fmt($apply) . ' on Invoice #' . (int) $inv['id'] . '.', 'applied' => $apply];
}
