<?php
/**
 * Finance ERP Module - Shared Helpers, CSS, and JS
 * Used by all finance_* pages for consistent design
 */

require_once __DIR__ . '/../services/SettingsService.php';

function ensureFinanceErpMigration(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM chart_of_accounts LIMIT 1');
        $stmt->execute();
        $stmt->closeCursor();
        return;
    } catch (Exception $e) {}
    try { $pdo->exec('ALTER TABLE refunds ADD COLUMN receipt_path VARCHAR(255) NULL AFTER refund_type'); } catch (Exception $e) {}
    $migFile = __DIR__ . '/../migrations/migration_finance_erp.sql';
    if (!file_exists($migFile)) return;
    $sql = file_get_contents($migFile);
    if (!$sql) return;
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $stmt) {
        if ($stmt === '' || stripos($stmt, 'PREPARE') === 0 || stripos($stmt, 'SET ') === 0 || stripos($stmt, 'EXECUTE') === 0 || stripos($stmt, 'DEALLOCATE') === 0) continue;
        try { $pdo->exec($stmt); } catch (Exception $e) {}
    }
}

// ─── MULTI-CURRENCY SUPPORT ─────────────────────────────────
// Creates the `currencies` table (seeded from the base currency setting) and adds
// currency / exchange_rate / base_amount columns where a payment amount is stored.
// Idempotent; run at page load OUTSIDE any active transaction (DDL commits).
function ensureSupplierPaymentCurrencyMigration(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $base = SettingsService::currencySymbol();
    $pdo->exec("CREATE TABLE IF NOT EXISTS currencies (
        code VARCHAR(10) NOT NULL PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        symbol VARCHAR(10) NOT NULL DEFAULT '',
        exchange_rate DECIMAL(14,4) NOT NULL DEFAULT 1.0000,
        is_base TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $seed = [
        [$base, $base === 'ETB' ? 'Ethiopian Birr' : 'Base Currency', '', 1.0000, 1],
        ['USD', 'US Dollar', '$', 140.5000, 0],
        ['EUR', 'Euro', '€', 152.0000, 0],
        ['GBP', 'British Pound', '£', 178.0000, 0],
    ];
    $ins = $pdo->prepare('INSERT IGNORE INTO currencies (code, name, symbol, exchange_rate, is_base) VALUES (?, ?, ?, ?, ?)');
    foreach ($seed as $r) $ins->execute($r);
    $pdo->exec('UPDATE currencies SET is_base = (code = ' . $pdo->quote($base) . ')');

    $colDefs = [
        'supplier_payments' => [
            'currency' => 'VARCHAR(10) NOT NULL DEFAULT ' . $pdo->quote($base),
            'exchange_rate' => 'DECIMAL(14,4) NOT NULL DEFAULT 1.0000',
            'base_amount' => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00',
        ],
        'bank_transactions' => [
            'currency' => 'VARCHAR(10) NULL',
            'exchange_rate' => 'DECIMAL(14,4) NULL DEFAULT 1.0000',
            'base_amount' => 'DECIMAL(14,2) NULL',
        ],
        'journal_entries' => [
            'currency' => 'VARCHAR(10) NULL',
            'exchange_rate' => 'DECIMAL(14,4) NULL DEFAULT 1.0000',
            'base_amount' => 'DECIMAL(14,2) NULL',
        ],
    ];
    foreach ($colDefs as $table => $cols) {
        $existing = [];
        foreach ($pdo->query("SHOW COLUMNS FROM `$table`") as $c) {
            $existing[$c['Field']] = true;
        }
        foreach ($cols as $name => $def) {
            if (isset($existing[$name])) continue;
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$name` $def");
        }
    }
    // Backfill legacy (pre-currency) ETB payments so base ledger math stays correct.
    $pdo->exec('UPDATE supplier_payments SET base_amount = amount_paid, exchange_rate = 1.0000 WHERE exchange_rate = 1.0000 AND base_amount = 0.00 AND amount_paid <> 0');
}

/**
 * Backfill: POs that already have supplier payments recorded but whose approval
 * status is still 'payment_pending' (recorded before the payment flow advanced
 * the PO status). Advances them to 'payment_approved' so store manager/admin no
 * longer see "Awaiting Finance" for POs finance has already paid. Idempotent.
 */
function ensureSupplierPaymentStatusBackfill(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    require_once __DIR__ . '/workflow_engine.php';
    WorkflowEngine::ensureStatusHistoryTable($pdo);

    $pos = $pdo->query("
        SELECT po.id, po.status, po.payment_status, po.payment_voucher_no, po.payment_approved_by, po.payment_approved_at,
               po.total_amount, COALESCE(pd.total_paid, 0) AS total_paid
        FROM purchase_orders po
        LEFT JOIN (SELECT order_id, SUM(base_amount) AS total_paid FROM supplier_payments GROUP BY order_id) pd ON pd.order_id = po.id
        WHERE po.status = 'payment_pending' AND COALESCE(pd.total_paid, 0) > 0
    ")->fetchAll();

    foreach ($pos as $po) {
        $poid = (int) $po['id'];
        $totalPaid = (float) $po['total_paid'];
        $poTotal = (float) $po['total_amount'];
        $changedBy = $po['payment_approved_by'] ? (int) $po['payment_approved_by'] : null;

        if ($po['payment_status'] === 'partial' && $totalPaid >= $poTotal) {
            $pdo->prepare('UPDATE purchase_orders SET payment_status = "paid" WHERE id = ?')->execute([$poid]);
            WorkflowEngine::logStatusChange($pdo, 'purchase_payment', $poid, 'partial', 'paid', $changedBy, 'Backfill: fully paid');
        }

        $extraSet = ['status = "payment_approved"'];
        $params = ['id' => $poid];
        if (!$po['payment_approved_at']) {
            $extraSet[] = 'payment_approved_at = NOW()';
        }
        if (!$po['payment_voucher_no']) {
            $extraSet[] = 'payment_voucher_no = CONCAT("SPV-BF-", LPAD(id, 5, "0"))';
        }
        $pdo->prepare('UPDATE purchase_orders SET ' . implode(', ', $extraSet) . ' WHERE id = :id')->execute($params);

        WorkflowEngine::logStatusChange($pdo, 'purchase', $poid, 'payment_pending', 'payment_approved', $changedBy, 'Backfill: supplier payment already recorded');
    }
}

function finBaseCurrency(): string {
    return SettingsService::currencySymbol();
}

function finCurrencies(PDO $pdo, bool $activeOnly = true): array {
    $sql = 'SELECT code, name, symbol, exchange_rate, is_base FROM currencies' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY is_base DESC, code ASC';
    return $pdo->query($sql)->fetchAll();
}

function finMoney(string $code, float $amount): string {
    return $code . ' ' . number_format($amount, 2);
}

function finGetDb(): PDO {
    if (function_exists('getPDO')) {
        return getPDO();
    }
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true]);
    }
    return $pdo;
}

function finFmt(float $amount): string {
    return currency_sym() . ' ' . number_format($amount, 2);
}

function finFmtShort(float $amount): string {
    if (abs($amount) >= 1000000) return currency_sym() . ' ' . number_format($amount / 1000000, 1) . 'M';
    if (abs($amount) >= 1000) return currency_sym() . ' ' . number_format($amount / 1000, 1) . 'K';
    return currency_sym() . ' ' . number_format($amount, 2);
}

function finPctChange(float $current, float $previous): array {
    if ($previous == 0) return ['pct' => $current > 0 ? 100 : 0, 'dir' => $current > 0 ? 'up' : 'same'];
    $pct = round((($current - $previous) / abs($previous)) * 100, 1);
    return ['pct' => abs($pct), 'dir' => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'same')];
}

function finNotificationCount(PDO $pdo): int {
    try {
        $stmt = $pdo->query('SELECT COUNT(*) FROM finance_notifications WHERE is_read = 0');
        return (int) $stmt->fetchColumn();
    } catch (Exception $e) { return 0; }
}

function finGenerateEntryNumber(PDO $pdo, ?string $entryDate = null): string {
    static $generated = []; // in-request guard so repeated calls never hand out the same number
    $datePart = $entryDate ? date('Ymd', strtotime($entryDate)) : date('Ymd');
    $prefix = get_setting('journal_entry_prefix', 'JE-') . $datePart . '-';
    $stmt = $pdo->prepare('SELECT entry_number FROM journal_entries WHERE entry_number LIKE ? ORDER BY entry_number DESC LIMIT 1');
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $num = 1;
    if ($last) {
        $num = (int) substr($last, strlen($prefix)) + 1;
    }
    // Guarantee a non-empty, non-NULL, collision-free value even if records were
    // backfilled, generated earlier in this request, or concurrent inserts race
    // on the UNIQUE(entry_number) index.
    for ($i = 0; $i < 1000; $i++) {
        $candidate = $prefix . str_pad($num, 4, '0', STR_PAD_LEFT);
        if (isset($generated[$candidate])) {
            $num++;
            continue;
        }
        $chk = $pdo->prepare('SELECT COUNT(*) FROM journal_entries WHERE entry_number = ?');
        $chk->execute([$candidate]);
        if ((int) $chk->fetchColumn() === 0) {
            $generated[$candidate] = true;
            return $candidate;
        }
        $num++;
    }
    return $prefix . strtoupper(substr(md5(uniqid('', true)), 0, 8));
}

function finGetTodaySales(PDO $pdo): float {
    $stmt = $pdo->query('SELECT COALESCE(SUM(total_amount), 0) FROM customer_orders WHERE DATE(created_at) = CURDATE() AND status != "cancelled"');
    return (float) $stmt->fetchColumn();
}

function finGetTodaySalesPrev(PDO $pdo): float {
    $stmt = $pdo->query('SELECT COALESCE(SUM(total_amount), 0) FROM customer_orders WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND status != "cancelled"');
    return (float) $stmt->fetchColumn();
}

function finGetTodayCollections(PDO $pdo): float {
    $stmt = $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE DATE(created_at) = CURDATE()');
    return (float) $stmt->fetchColumn();
}

function finGetTodayCollectionsPrev(PDO $pdo): float {
    $stmt = $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)');
    return (float) $stmt->fetchColumn();
}

function finGetOutstandingReceivables(PDO $pdo): float {
    $stmt = $pdo->query('SELECT COALESCE(SUM(outstanding_balance), 0) FROM invoices WHERE payment_status IN ("unpaid","partial")');
    return (float) $stmt->fetchColumn();
}

/**
 * SQL fragment: aggregate supplier payments per PO in BASE currency.
 * Single source of truth for the "paid per purchase order" formula.
 */
function finPoPaidJoinSql(): string {
    return 'LEFT JOIN (SELECT order_id, SUM(base_amount) AS total FROM supplier_payments GROUP BY order_id) paid ON po.id = paid.order_id';
}

/**
 * Purchase order statuses that still count towards outstanding payables.
 */
function finPoPayableStatuses(): array {
    return ['received', 'payment_pending', 'payment_approved', 'payment_rejected'];
}

function finGetOutstandingPayables(PDO $pdo): float {
    $statuses = implode('","', finPoPayableStatuses());
    $stmt = $pdo->query("SELECT COALESCE(SUM(po.total_amount - COALESCE(paid.total, 0)), 0) FROM purchase_orders po " . finPoPaidJoinSql() . " WHERE po.status IN (\"{$statuses}\") AND po.total_amount > COALESCE(paid.total, 0)");
    return (float) $stmt->fetchColumn();
}

function finGetBankBalance(PDO $pdo): float {
    try {
        $stmt = $pdo->query('SELECT COALESCE(SUM(balance), 0) FROM bank_accounts WHERE status = "active"');
        return (float) $stmt->fetchColumn();
    } catch (Exception $e) { return 0; }
}

function finGetCashBalance(PDO $pdo): float {
    try {
        $stmt = $pdo->query('SELECT COALESCE(SUM(balance), 0) FROM cash_accounts WHERE status = "active"');
        return (float) $stmt->fetchColumn();
    } catch (Exception $e) { return 0; }
}

function cashGetBalance(PDO $pdo, int $accountId): float {
    $stmt = $pdo->prepare('SELECT balance FROM cash_accounts WHERE id = :id');
    $stmt->execute(['id' => $accountId]);
    return (float) $stmt->fetchColumn();
}

/**
 * Atomic cash movement. Validates the account (must exist and be active), applies the
 * balance change and records the transaction in one transaction so concurrent movements
 * cannot lose updates. $adjustTarget is required for 'adjustment' (the new balance);
 * the stored amount is the signed delta.
 *
 * @return array{success: bool, msg: string, balance: float}
 */
function cashApply(PDO $pdo, int $accountId, string $txnType, float $amount, string $category, string $referenceNo, string $description, ?int $userId, ?float $adjustTarget = null): array {
    if ($amount < 0) {
        return ['success' => false, 'msg' => 'Amount cannot be negative.', 'balance' => 0.0];
    }
    if (!in_array($txnType, ['in', 'out', 'transfer_in', 'transfer_out', 'adjustment'], true)) {
        return ['success' => false, 'msg' => 'Unsupported cash transaction type.', 'balance' => 0.0];
    }
    $ownsTxn = !$pdo->inTransaction();
    if ($ownsTxn) {
        $pdo->beginTransaction();
    }
    try {
        // Lock the account row so two concurrent cash_outs can't both pass the balance check.
        $stmt = $pdo->prepare('SELECT id, balance, status FROM cash_accounts WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $accountId]);
        $acc = $stmt->fetch();
        if (!$acc) {
            if ($ownsTxn) { $pdo->rollBack(); }
            return ['success' => false, 'msg' => 'Cash account not found.', 'balance' => 0.0];
        }
        if ($acc['status'] !== 'active') {
            if ($ownsTxn) { $pdo->rollBack(); }
            return ['success' => false, 'msg' => 'Cash account is not active.', 'balance' => 0.0];
        }
        $cur = (float) $acc['balance'];

        if ($txnType === 'in' || $txnType === 'transfer_in') {
            $newBal = $cur + $amount;
        } elseif ($txnType === 'out' || $txnType === 'transfer_out') {
            if ($amount > $cur) {
                if ($ownsTxn) { $pdo->rollBack(); }
                return ['success' => false, 'msg' => 'Insufficient cash balance.', 'balance' => $cur];
            }
            $newBal = $cur - $amount;
        } else { // adjustment
            $newBal = (float) $adjustTarget;
            if ($newBal < 0) {
                if ($ownsTxn) { $pdo->rollBack(); }
                return ['success' => false, 'msg' => 'Cash balance cannot be negative.', 'balance' => $cur];
            }
            $amount = $newBal - $cur; // signed delta
        }

        $pdo->prepare('INSERT INTO cash_transactions (cash_account_id, transaction_type, amount, balance_after, category, reference_no, description, status, created_by) VALUES (:cid, :tt, :amt, :bal, :cat, :ref, :desc, "completed", :uid)')
            ->execute(['cid' => $accountId, 'tt' => $txnType, 'amt' => $amount, 'bal' => $newBal, 'cat' => $category, 'ref' => $referenceNo, 'desc' => $description, 'uid' => $userId]);
        $pdo->prepare('UPDATE cash_accounts SET balance = :b, updated_at = NOW() WHERE id = :id')->execute(['b' => $newBal, 'id' => $accountId]);
        if ($ownsTxn) {
            $pdo->commit();
        }
        return ['success' => true, 'msg' => 'Cash transaction recorded.', 'balance' => $newBal];
    } catch (Throwable $e) {
        if ($ownsTxn && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function finGetMonthRevenue(PDO $pdo): float {
    $stmt = $pdo->query('SELECT COALESCE(SUM(total_amount), 0) FROM customer_orders WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status != "cancelled"');
    return (float) $stmt->fetchColumn();
}

function finGetMonthRevenuePrev(PDO $pdo): float {
    $stmt = $pdo->query('SELECT COALESCE(SUM(total_amount), 0) FROM customer_orders WHERE MONTH(created_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(created_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND status != "cancelled"');
    return (float) $stmt->fetchColumn();
}

function finGetMonthExpenses(PDO $pdo): float {
    $stmt = $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE MONTH(expense_date) = MONTH(CURDATE()) AND YEAR(expense_date) = YEAR(CURDATE())');
    return (float) $stmt->fetchColumn();
}

function finGetMonthExpensesPrev(PDO $pdo): float {
    $stmt = $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE MONTH(expense_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(expense_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))');
    return (float) $stmt->fetchColumn();
}

function finGetMonthIncome(PDO $pdo): float {
    $stmt = $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM income WHERE MONTH(income_date) = MONTH(CURDATE()) AND YEAR(income_date) = YEAR(CURDATE())');
    return (float) $stmt->fetchColumn();
}

function finGetMonthCOGS(PDO $pdo): float {
    $stmt = $pdo->query('SELECT COALESCE(SUM(ci.quantity * p.purchase_price), 0) FROM customer_order_items ci JOIN customer_orders co ON ci.customer_order_id = co.id JOIN products p ON ci.product_id = p.id WHERE MONTH(co.created_at) = MONTH(CURDATE()) AND YEAR(co.created_at) = YEAR(CURDATE()) AND co.status NOT IN ("rejected")');
    return (float) $stmt->fetchColumn();
}

function finGetTotalRefunds(PDO $pdo): float {
    try {
        $stmt = $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())');
        return (float) $stmt->fetchColumn();
    } catch (Exception $e) { return 0; }
}

function finGetPendingPaymentApprovals(PDO $pdo): int {
    $stmt = $pdo->query('SELECT COUNT(*) FROM customer_orders WHERE payment_confirmed = 0 AND status IN ("pending","approved") AND payment_method != "credit"');
    return (int) $stmt->fetchColumn();
}

function finGetMonthlyData(PDO $pdo, string $type = 'revenue'): array {
    $months = [];
    for ($i = 11; $i >= 0; $i--) {
        $m = date('n', strtotime("-{$i} months"));
        $y = date('Y', strtotime("-{$i} months"));
        $label = date('M Y', strtotime("-{$i} months"));
        if ($type === 'revenue') {
            $stmt = $pdo->prepare('SELECT COALESCE(SUM(total_amount), 0) FROM customer_orders WHERE MONTH(created_at) = ? AND YEAR(created_at) = ? AND status != "cancelled"');
        } elseif ($type === 'expense') {
            $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE MONTH(expense_date) = ? AND YEAR(expense_date) = ?');
        } elseif ($type === 'income') {
            $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM income WHERE MONTH(income_date) = ? AND YEAR(income_date) = ?');
        } else {
            $stmt = $pdo->prepare('SELECT 0');
        }
        $stmt->execute([$m, $y]);
        $months[] = ['label' => $label, 'value' => (float) $stmt->fetchColumn(), 'month' => $m, 'year' => $y];
    }
    return $months;
}

function finGetPaymentMethodData(PDO $pdo): array {
    $stmt = $pdo->query("SELECT payment_method, COALESCE(SUM(amount), 0) AS total FROM invoice_payments GROUP BY payment_method ORDER BY total DESC");
    $data = [];
    foreach ($stmt->fetchAll() as $row) {
        $label = ucwords(str_replace('_', ' ', $row['payment_method'] ?? 'Other'));
        $data[] = ['label' => $label, 'value' => (float) $row['total']];
    }
    return $data;
}

// ═══════════════════════════════════════════════════════════════
// BANK MANAGEMENT HELPERS
// ═══════════════════════════════════════════════════════════════

function bankAuditLog(PDO $pdo, ?int $userId, string $username, string $action, string $entityType, ?int $entityId, ?string $oldValue = null, ?string $newValue = null): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $pdo->prepare('INSERT INTO bank_audit_log (user_id, username, action, entity_type, entity_id, old_value, new_value, ip_address) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$userId, $username, $action, $entityType, $entityId, $oldValue, $newValue, $ip]);
}

function bankGetSetting(PDO $pdo, string $key, string $default = ''): string {
    static $cache = [];
    if (!isset($cache[$key])) {
        $stmt = $pdo->prepare('SELECT setting_value FROM bank_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        $cache[$key] = $val !== false ? $val : $default;
    }
    return $cache[$key];
}

function bankSetSetting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare('INSERT INTO bank_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()')
        ->execute([$key, $value]);
}

function bankUpdateBalance(PDO $pdo, int $accountId): void {
    $acc = bankGetAccountById($pdo, $accountId);
    if (!$acc) return;
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) FROM bank_transactions WHERE bank_account_id = ? AND status != 'reversed'");
    $stmt->execute([$accountId]);
    $balance = (float) $acc['opening_balance'] + (float) $stmt->fetchColumn();
    $pdo->prepare('UPDATE bank_accounts SET balance = ?, updated_at = NOW() WHERE id = ?')->execute([$balance, $accountId]);
}

function bankGenerateTransferNo(PDO $pdo): string {
    $prefix = 'TFR-' . date('Ymd') . '-';
    $stmt = $pdo->prepare("SELECT transfer_no FROM bank_transfers WHERE transfer_no LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    if ($last) {
        $num = (int) substr($last, strlen($prefix)) + 1;
    } else {
        $num = 1;
    }
    return $prefix . str_pad($num, 4, '0', STR_PAD_LEFT);
}

function bankGetAccountBalance(PDO $pdo, int $accountId): float {
    $stmt = $pdo->prepare('SELECT balance FROM bank_accounts WHERE id = ?');
    $stmt->execute([$accountId]);
    return (float) $stmt->fetchColumn();
}

function bankGetAccountById(PDO $pdo, int $accountId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM bank_accounts WHERE id = ?');
    $stmt->execute([$accountId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function bankGetAccounts(PDO $pdo, string $status = 'active'): array {
    $stmt = $pdo->prepare('SELECT * FROM bank_accounts WHERE status = ? ORDER BY is_default DESC, bank_name ASC');
    $stmt->execute([$status]);
    return $stmt->fetchAll();
}

function bankGetAllAccounts(PDO $pdo): array {
    return $pdo->query('SELECT * FROM bank_accounts ORDER BY is_default DESC, status ASC, bank_name ASC')->fetchAll();
}

// ─── AUTOMATIC BANK INTEGRATION ──────────────────────────────

function bankRecordTransaction(PDO $pdo, int $bankAccountId, string $txnType, float $amount, string $referenceNo = '', string $description = '', ?int $customerId = null, ?int $supplierId = null, ?int $userId = null, ?string $currency = null, ?float $exchangeRate = null, ?float $baseAmount = null): bool {
    $ownsTxn = !$pdo->inTransaction();
    if ($ownsTxn) {
        $pdo->beginTransaction();
    }
    try {
        // Lock the account row so concurrent movements can't lose updates.
        $stmt = $pdo->prepare('SELECT * FROM bank_accounts WHERE id = ? FOR UPDATE');
        $stmt->execute([$bankAccountId]);
        $account = $stmt->fetch();
        if (!$account || $account['status'] !== 'active') {
            if ($ownsTxn) { $pdo->rollBack(); }
            return false;
        }
        static $colsKnown = null;
        if ($colsKnown === null) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bank_transactions' AND COLUMN_NAME = 'currency'");
            $chk->execute();
            $colsKnown = ((int) $chk->fetchColumn()) > 0;
        }
        $isInflow = in_array($txnType, ['deposit','customer_payment','transfer_in','interest']);
        $newBal = (float) $account['balance'] + ($isInflow ? $amount : -$amount);
        $debit = $isInflow ? $amount : 0;
        $credit = $isInflow ? 0 : $amount;
        if ($colsKnown) {
            $pdo->prepare('INSERT INTO bank_transactions (bank_account_id, transaction_type, amount, debit, credit, balance_after, reference_no, description, customer_id, supplier_id, status, created_by, currency, exchange_rate, base_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$bankAccountId, $txnType, $amount, $debit, $credit, $newBal, $referenceNo, $description, $customerId, $supplierId, 'completed', $userId, $currency, $exchangeRate, $baseAmount]);
        } else {
            $pdo->prepare('INSERT INTO bank_transactions (bank_account_id, transaction_type, amount, debit, credit, balance_after, reference_no, description, customer_id, supplier_id, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$bankAccountId, $txnType, $amount, $debit, $credit, $newBal, $referenceNo, $description, $customerId, $supplierId, 'completed', $userId]);
        }
        $pdo->prepare('UPDATE bank_accounts SET balance = ?, updated_at = NOW() WHERE id = ?')->execute([$newBal, $bankAccountId]);
        if ($ownsTxn) {
            $pdo->commit();
        }
        return true;
    } catch (Throwable $e) {
        if ($ownsTxn && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function bankGetDefaultAccountId(PDO $pdo): int {
    return (int) bankGetSetting($pdo, 'default_bank', '0');
}

function bankRenderAccountDropdown(PDO $pdo, string $name = 'bank_account_id', string $selected = ''): string {
    $html = '<select name="' . htmlspecialchars($name) . '" style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;background:#fff;font-family:inherit">';
    $html .= '<option value="">No bank account</option>';
    foreach (bankGetAccounts($pdo) as $ba) {
        $sel = ((string)$ba['id'] === $selected || (!$selected && (int)$ba['is_default'])) ? ' selected' : '';
        $html .= '<option value="' . $ba['id'] . '"' . $sel . '>' . htmlspecialchars($ba['bank_name'] . ' - ' . $ba['account_name'] . ' (' . finFmt((float)$ba['balance']) . ')') . '</option>';
    }
    $html .= '</select>';
    return $html;
}

function financeConfirmOrderPayment(PDO $pdo, int $orderId, float $verifiedAmount, string $notes, int $uid, int $bankAccountId = 0): array {
    $stmt = $pdo->prepare("SELECT co.id, co.customer_id, co.total_amount, co.payment_method, co.transaction_ref FROM customer_orders co WHERE co.id = :id AND co.status IN ('pending','approved') AND co.payment_confirmed = 0 AND co.fulfillment_status = 'prepared' LIMIT 1");
    $stmt->execute(['id' => $orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        return ['success' => false, 'msg' => 'Order not found or already confirmed.'];
    }
    $actualAmount = $verifiedAmount > 0 ? $verifiedAmount : (float) $order['total_amount'];
    WorkflowEngine::ensureStatusHistoryTable($pdo);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE customer_orders SET payment_confirmed = 1, payment_confirmed_at = NOW(), payment_confirmed_by = :uid, payment_notes = :notes, verified_amount = :amt, verified_by = :uid2, verified_at = NOW() WHERE id = :id")
            ->execute(['uid' => $uid, 'notes' => $notes !== '' ? $notes : null, 'amt' => $actualAmount, 'uid2' => $uid, 'id' => $orderId]);

        $invStmt = $pdo->prepare('SELECT id, total_amount, outstanding_balance FROM invoices WHERE customer_order_id = :oid LIMIT 1');
        $invStmt->execute(['oid' => $orderId]);
        $inv = $invStmt->fetch();
        if ($inv) {
            $payMethod = ($order['payment_method'] ?? '') === 'credit' ? 'credit' : ($order['payment_method'] ?? 'cash');
            recordInvoicePayment($pdo, (int)$inv['id'], $actualAmount, $payMethod, $order['transaction_ref'], null, $notes !== '' ? $notes : 'Confirmed by finance', $uid);
            syncInvoicePayments($pdo, (int)$inv['id']);
            if (($order['payment_method'] ?? '') === 'credit' && function_exists('creditLogAudit')) {
                $pdo->prepare('INSERT INTO customer_credit_payments (customer_id, amount, payment_date, payment_method, reference_no, notes, created_by) VALUES (:cid, :amt, CURDATE(), "credit", :ref, :notes, :uid)')
                    ->execute(['cid' => $order['customer_id'], 'amt' => $actualAmount, 'ref' => $order['transaction_ref'], 'notes' => $notes !== '' ? $notes : 'Credit sale confirmed by finance', 'uid' => $uid]);
                creditLogAudit($pdo, (int)$order['customer_id'], 'credit_sale_confirmed', null, json_encode(['order_id' => $orderId, 'amount' => $actualAmount, 'ref' => $order['transaction_ref']]), $uid);
            }
        }

        logPaymentAction($pdo, $orderId, $order['customer_id'], 'payment_confirmed', $actualAmount, $order['payment_method'], $order['transaction_ref'], $uid, $notes !== '' ? $notes : 'Payment confirmed by finance');
        createOrderNotification($pdo, $orderId, $order['customer_id'], 'Payment Confirmed', 'Your payment for order #' . $orderId . ' for ' . finFmt($actualAmount) . ' has been confirmed by finance.', 'success');

        if ($bankAccountId > 0 && $order['payment_method'] !== 'credit') {
            $bankDesc = 'Customer payment - Order #' . $orderId . ' (' . $order['payment_method'] . ')';
            bankRecordTransaction($pdo, $bankAccountId, 'customer_payment', $actualAmount, $order['transaction_ref'] ?? '', $bankDesc, (int)$order['customer_id'], null, $uid);
        }
        $fulfRes = FulfillmentService::confirmPayment($pdo, $orderId, $uid, ($order['payment_method'] ?? '') === 'credit');
        if (!$fulfRes['success']) {
            throw new Exception($fulfRes['msg']);
        }
        $fulfMethod = FulfillmentService::method($pdo, $orderId);
        $isPickup = $fulfMethod === StatusCatalog::FULFILLMENT_METHOD_PICKUP;
        NotificationService::finance($pdo, null, $orderId, $isPickup ? 'Order Ready for Pickup' : 'Order Ready for Delivery', $isPickup
            ? "Order #{$orderId} has been confirmed and is waiting customer pickup."
            : "Order #{$orderId} has been confirmed and is ready for delivery.", 'success');
        $pdo->commit();
        return ['success' => true, 'msg' => "Payment for Order #{$orderId} confirmed. Amount: " . finFmt($actualAmount)];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'msg' => ErpService::friendlyError($e)];
    }
}

function finCss(): string {
    return <<<'CSS'
<style>
:root{--fin-blue:#2563eb;--fin-blue-d:#1d4ed8;--fin-green:#059669;--fin-green-d:#047857;--fin-red:#dc2626;--fin-red-d:#b91c1c;--fin-orange:#d97706;--fin-orange-d:#b45309;--fin-purple:#7c3aed;--fin-purple-d:#6d28d9;--fin-teal:#0d9488;--fin-indigo:#4f46e5;--fin-pink:#db2777;--fin-gray:#64748b;--fin-bg:#f1f5f9;--fin-card:#fff;--fin-border:#e2e8f0;--fin-radius:14px;--fin-shadow:0 1px 3px rgba(0,0,0,.06)}
.fin-wrap{max-width:1440px;margin:0 auto;padding:0 0 40px}
.fin-wrap h1{font-size:1.4rem;font-weight:800;color:#0f172a;margin-bottom:6px;display:flex;align-items:center;gap:10px}
.fin-subtitle{font-size:.82rem;color:var(--fin-gray);margin-bottom:20px}
.fin-stats{display:grid;grid-template-columns:repeat(6,1fr);gap:14px;margin-bottom:22px}
.fin-stat{background:var(--fin-card);border-radius:var(--fin-radius);border:1px solid var(--fin-border);padding:18px 16px;display:flex;align-items:flex-start;gap:12px;box-shadow:var(--fin-shadow);cursor:pointer;transition:all .2s;position:relative;overflow:hidden}
.fin-stat:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,.1)}
.fin-stat-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;color:#fff}
.fin-stat-body{flex:1;min-width:0}
.fin-stat-val{font-size:1.2rem;font-weight:800;color:#0f172a;line-height:1.2}
.fin-stat-label{font-size:.72rem;color:var(--fin-gray);margin-top:2px;font-weight:500}
.fin-stat-change{display:inline-flex;align-items:center;gap:3px;font-size:.68rem;font-weight:700;margin-top:4px;padding:2px 6px;border-radius:10px}
.fin-stat-change.up{background:#dcfce7;color:#166534}
.fin-stat-change.down{background:#fee2e2;color:#991b1b}
.fin-stat-change.same{background:#f1f5f9;color:#64748b}
.fin-stat-spark{position:absolute;bottom:0;right:0;width:80px;height:30px;opacity:.15}
.fin-card{background:var(--fin-card);border-radius:var(--fin-radius);border:1px solid var(--fin-border);padding:20px;margin-bottom:16px;box-shadow:var(--fin-shadow)}
.fin-card h2{font-size:1rem;font-weight:700;color:#0f172a;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.fin-card h2 i{font-size:.9rem}
.fin-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.fin-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px}
.fin-table{width:100%;border-collapse:separate;border-spacing:0;font-size:.84rem}
.fin-table th{text-align:left;padding:11px 14px;background:#f8fafc;font-weight:700;color:#475569;border-bottom:2px solid var(--fin-border);font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.fin-table td{padding:11px 14px;border-bottom:1px solid #f1f5f9}
.fin-table tbody tr{transition:background .1s}
.fin-table tbody tr:hover{background:#f8fafc}
.fin-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:.72rem;font-weight:700;white-space:nowrap}
.fin-badge-green{background:#dcfce7;color:#166534}
.fin-badge-orange{background:#ffedd5;color:#9a3412}
.fin-badge-red{background:#fee2e2;color:#991b1b}
.fin-badge-blue{background:#dbeafe;color:#1e40af}
.fin-badge-purple{background:#ede9fe;color:#5b21b6}
.fin-badge-teal{background:#ccfbf1;color:#115e59}
.fin-badge-gray{background:#f1f5f9;color:#475569}
.fin-badge-indigo{background:#e0e7ff;color:#3730a3}
.fin-btn{padding:8px 16px;border:none;border-radius:10px;font-size:.82rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;font-family:inherit}
.fin-btn:hover{opacity:.85;transform:translateY(-1px)}
.fin-btn-blue{background:var(--fin-blue);color:#fff}
.fin-btn-green{background:var(--fin-green);color:#fff}
.fin-btn-red{background:var(--fin-red);color:#fff}
.fin-btn-orange{background:var(--fin-orange);color:#fff}
.fin-btn-purple{background:var(--fin-purple);color:#fff}
.fin-btn-outline{background:transparent;border:1px solid #cbd5e1;color:#475569}
.fin-btn-outline:hover{background:#f8fafc;border-color:#94a3b8}
.fin-btn-sm{padding:5px 10px;font-size:.75rem;border-radius:8px}
.fin-tabs{display:flex;gap:0;border-bottom:2px solid var(--fin-border);margin-bottom:0;overflow-x:auto}
.fin-tab{padding:11px 20px;font-size:.84rem;font-weight:600;color:var(--fin-gray);cursor:pointer;border:none;background:none;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .15s;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;font-family:inherit}
.fin-tab:hover{color:#334155}
.fin-tab.active{color:var(--fin-blue);border-bottom-color:var(--fin-blue)}
.fin-panel{display:none;padding:20px 0}
.fin-panel.active{display:block}
.fin-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px}
.fin-toolbar select,.fin-toolbar input[type="text"],.fin-toolbar input[type="date"],.fin-toolbar input[type="number"]{padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;background:#fff;font-family:inherit}
.fin-toolbar input[type="text"]{min-width:200px}
.fin-empty{text-align:center;padding:35px;color:var(--fin-gray)}
.fin-empty i{font-size:2.2rem;display:block;margin-bottom:10px;color:#cbd5e1}
.fin-filter-pills{display:flex;gap:6px;flex-wrap:wrap}
.fin-pill{padding:6px 14px;border-radius:20px;font-size:.78rem;font-weight:600;border:1px solid var(--fin-border);background:#fff;color:var(--fin-gray);cursor:pointer;transition:all .15s;font-family:inherit}
.fin-pill:hover{border-color:#94a3b8;color:#334155}
.fin-pill.active{background:var(--fin-blue);color:#fff;border-color:var(--fin-blue)}
.fin-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10000;align-items:center;justify-content:center}
.fin-modal-overlay.show{display:flex}
.fin-modal{background:#fff;border-radius:16px;padding:24px;max-width:600px;width:95%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.fin-modal h3{font-size:1.1rem;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.fin-form-group{margin-bottom:14px}
.fin-form-group label{display:block;font-size:.8rem;font-weight:600;color:#475569;margin-bottom:4px}
.fin-form-group input,.fin-form-group select,.fin-form-group textarea{width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;font-family:inherit}
.fin-form-group textarea{resize:vertical;min-height:60px}
.fin-kpi-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:16px}
.fin-kpi{background:#f8fafc;border-radius:10px;padding:14px;text-align:center}
.fin-kpi-val{font-size:1.3rem;font-weight:800;color:#0f172a}
.fin-kpi-label{font-size:.72rem;color:var(--fin-gray);margin-top:2px;font-weight:500}
.fin-progress-wrap{width:100%;background:#e2e8f0;border-radius:6px;height:8px;overflow:hidden}
.fin-progress-bar{height:100%;border-radius:6px;transition:width .3s}
.fin-section-title{font-size:.78rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.04em;margin-bottom:12px;display:flex;align-items:center;gap:6px}
.fin-quick-actions{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px}
.fin-qa{display:flex;align-items:center;gap:8px;padding:10px 16px;background:var(--fin-card);border:1px solid var(--fin-border);border-radius:12px;font-size:.82rem;font-weight:600;color:#334155;cursor:pointer;text-decoration:none;transition:all .15s}
.fin-qa:hover{border-color:var(--fin-blue);color:var(--fin-blue);transform:translateY(-1px);box-shadow:0 2px 8px rgba(37,99,235,.15)}
.fin-qa i{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.85rem}
.fin-notif{display:flex;align-items:flex-start;gap:10px;padding:10px 14px;border-radius:10px;margin-bottom:8px;font-size:.82rem}
.fin-notif i{margin-top:2px;font-size:.85rem;flex-shrink:0}
.fin-notif-warning{background:#fffbeb;border:1px solid #fde68a;color:#92400e}
.fin-notif-critical{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.fin-notif-info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}
@media(max-width:1200px){.fin-stats{grid-template-columns:repeat(3,1fr)}.fin-grid-2,.fin-grid-3{grid-template-columns:1fr}}
@media(max-width:768px){.fin-stats{grid-template-columns:repeat(2,1fr)}.fin-table{font-size:.78rem}.fin-table th,.fin-table td{padding:8px 6px}.fin-toolbar input[type="text"]{min-width:0;flex:1}}
@media(max-width:480px){.fin-stats{grid-template-columns:1fr}.fin-wrap{padding:0 0 24px}.fin-card{padding:14px}.fin-toolbar{flex-direction:column;align-items:stretch}.fin-toolbar select,.fin-toolbar input[type="text"],.fin-toolbar input[type="date"]{width:100%}.fin-btn-sm{min-height:36px;padding:8px 12px;font-size:.78rem}.fin-quick-actions{gap:6px}.fin-qa{padding:8px 12px;font-size:.78rem}}
@media(max-width:380px){.fin-stats{gap:8px}.fin-stat{padding:12px 10px;gap:8px}.fin-stat-icon{width:36px;height:36px;font-size:.9rem}.fin-stat-val{font-size:1rem}.fin-card{padding:12px}.fin-kpi{padding:10px}.fin-kpi-val{font-size:1.1rem}}
</style>
CSS;
}

function finJs(): string {
    return <<<'JS'
<script>
function finSwitchTab(btn,id){
    var p=btn.closest('.fin-tabs');
    if(p){p.querySelectorAll('.fin-tab').forEach(function(t){t.classList.remove('active')})}
    var wrap=btn.closest('.fin-wrap')||document;
    wrap.querySelectorAll('.fin-panel').forEach(function(x){x.classList.remove('active')});
    btn.classList.add('active');
    var el=document.getElementById(id);
    if(el)el.classList.add('active');
}
function finAnimatedCounters(){
    document.querySelectorAll('[data-counter]').forEach(function(el){
        var target=parseFloat(el.getAttribute('data-counter'));
        var prefix=el.getAttribute('data-prefix')||'';
        var suffix=el.getAttribute('data-suffix')||'';
        var decimals=el.getAttribute('data-decimals')||'0';
        var start=0;
        var duration=800;
        var startTime=null;
        function step(ts){
            if(!startTime)startTime=ts;
            var progress=Math.min((ts-startTime)/duration,1);
            var eased=1-Math.pow(1-progress,3);
            var current=start+eased*(target-start);
            el.textContent=prefix+current.toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g,',')+suffix;
            if(progress<1)requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    });
}
function finOpenModal(id){var m=document.getElementById(id);if(m)m.classList.add('show')}
function finCloseModal(id){var m=document.getElementById(id);if(m)m.classList.remove('show')}
function finCloseAllModals(){document.querySelectorAll('.fin-modal-overlay').forEach(function(m){m.classList.remove('show')})}
document.addEventListener('DOMContentLoaded',function(){
    finAnimatedCounters();
    document.querySelectorAll('.fin-modal-overlay').forEach(function(m){
        m.addEventListener('click',function(e){if(e.target===m)finCloseAllModals()});
    });
});
</script>
JS;
}
