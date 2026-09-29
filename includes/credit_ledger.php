<?php
/**
 * Credit / Ledger Management System
 *
 * Adds the durable customer ledger (customer_transactions) and the requested
 * customer-level credit columns (credit_period_days, is_credit_blocked) on top
 * of the existing credit engine in credit_helpers.php.
 *
 * The customer_transactions table is a materialized projection of the canonical
 * sources of truth:
 *   - INVOICE rows come from invoices (debit = total_amount)
 *   - PAYMENT  rows come from invoice_payments (credit = amount recorded)
 * Rebuilds are idempotent (delete-per-customer + reinsert) and safe to run
 * inside the caller's transaction (pure DML). DDL migrations are guarded to
 * run only outside transactions (DDL implicitly commits in MySQL).
 */

if (!function_exists('creditLedgerEnsureMigrations')) {

/**
 * Idempotent migration: adds customers.credit_period_days + customers.is_credit_blocked,
 * creates customer_transactions, mirrors existing terms, syncs block flags, and
 * backfills the ledger from canonical invoice/payment data.
 */
function creditLedgerEnsureMigrations(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    if ($pdo->inTransaction()) return;

    $hasCol = static function (string $table, string $col) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
        $st->execute(['t' => $table, 'c' => $col]);
        return (int) $st->fetchColumn() > 0;
    };

    if (!$hasCol('customers', 'credit_period_days')) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN credit_period_days INT NOT NULL DEFAULT 30 AFTER credit_terms_days");
    }
    if (!$hasCol('customers', 'is_credit_blocked')) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN is_credit_blocked TINYINT(1) NOT NULL DEFAULT 0 AFTER credit_period_days");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_transactions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        transaction_id VARCHAR(64) NOT NULL,
        customer_id INT UNSIGNED NOT NULL,
        type ENUM('INVOICE','PAYMENT') NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        reference_number VARCHAR(100) NULL,
        created_at DATETIME NOT NULL,
        created_by INT UNSIGNED NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_ledger_txn (transaction_id),
        KEY idx_ledger_customer_date (customer_id, created_at),
        CONSTRAINT fk_ledger_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Mirror the operational terms column into the requested credit_period_days.
    $pdo->exec("UPDATE customers SET credit_period_days = credit_terms_days WHERE credit_period_days <> credit_terms_days");

    creditLedgerSyncBlockFlags($pdo);

    // Backfill ledger for every customer with any ledger activity.
    $ids = $pdo->query("SELECT DISTINCT customer_id FROM (
            SELECT customer_id FROM invoices
            UNION SELECT customer_id FROM invoice_payments
        ) t WHERE customer_id > 0")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $cid) {
        creditLedgerRebuildCustomer($pdo, (int) $cid);
    }

    $done = true;
}

/**
 * Recompute and persist the customers.is_credit_blocked flag based on the same
 * policy gates used by creditCheckOrder/creditValidateOrder.
 */
function creditLedgerSyncBlockFlag(PDO $pdo, int $customerId): void {
    $profile = creditGetProfile($pdo, $customerId);
    $blocked = 0;
    if (!empty($profile)) {
        $requireApproval = creditGetSetting($pdo, 'require_credit_approval', '1');
        $blockOnOverdue  = creditGetSetting($pdo, 'block_new_orders_on_overdue', '0');
        if ($profile['customer_status'] === 'suspended') {
            $blocked = 1;
        } elseif ($profile['credit_approval_status'] === 'suspended') {
            $blocked = 1;
        } elseif ($profile['credit_approval_status'] === 'rejected') {
            $blocked = 1;
        } elseif ($profile['credit_approval_status'] !== 'approved' && $requireApproval === '1') {
            $blocked = 1;
        } elseif ($blockOnOverdue === '1' && (float) $profile['overdue_balance'] > 0) {
            $blocked = 1;
        } elseif ((float) $profile['credit_limit'] > 0 && (float) $profile['credit_used'] >= (float) $profile['credit_limit']) {
            $blocked = 1;
        }
    }
    $pdo->prepare('UPDATE customers SET is_credit_blocked = :b WHERE id = :id')
        ->execute(['b' => $blocked, 'id' => $customerId]);
}

/**
 * Synchronize block flags for all credit customers (or a single one when given).
 * Returns the number of customers updated.
 */
function creditLedgerSyncBlockFlags(PDO $pdo, ?int $customerId = null): int {
    if ($customerId !== null) {
        creditLedgerSyncBlockFlag($pdo, $customerId);
        return 1;
    }
    $ids = $pdo->query("SELECT id FROM customers WHERE credit_limit > 0")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $cid) {
        creditLedgerSyncBlockFlag($pdo, (int) $cid);
    }
    return count($ids);
}

/**
 * Rebuild the durable ledger rows for one customer from the canonical sources.
 * Idempotent (DELETE per customer then reinsert); safe inside a transaction.
 */
function creditLedgerRebuildCustomer(PDO $pdo, int $customerId): void {
    if (!$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_transactions'")->fetchColumn()) {
        return;
    }
    $pdo->prepare('DELETE FROM customer_transactions WHERE customer_id = :cid')->execute(['cid' => $customerId]);

    $ins = $pdo->prepare('INSERT INTO customer_transactions (transaction_id, customer_id, type, amount, reference_number, created_at, created_by) VALUES (:tid, :cid, :type, :amt, :ref, :dt, :uid)');

    // INVOICE rows (historical debits)
    $invStmt = $pdo->prepare('SELECT i.id, i.invoice_no, i.total_amount, i.generated_at, i.approved_by, i.prepared_by FROM invoices i WHERE i.customer_id = :cid ORDER BY i.generated_at ASC, i.id ASC');
    $invStmt->execute(['cid' => $customerId]);
    foreach ($invStmt->fetchAll() as $inv) {
        $ins->execute([
            'tid' => 'INV-' . $inv['id'],
            'cid' => $customerId,
            'type' => 'INVOICE',
            'amt' => (float) $inv['total_amount'],
            'ref' => $inv['invoice_no'],
            'dt' => $inv['generated_at'],
            'uid' => $inv['approved_by'] ?: ($inv['prepared_by'] ?: null),
        ]);
    }

    // PAYMENT rows (credits applied via the FIFO allocator / finance pipeline)
    $payStmt = $pdo->prepare('SELECT ip.id, ip.amount, ip.reference_no, ip.created_at, ip.recorded_by FROM invoice_payments ip WHERE ip.customer_id = :cid ORDER BY ip.created_at ASC, ip.id ASC');
    $payStmt->execute(['cid' => $customerId]);
    foreach ($payStmt->fetchAll() as $pmt) {
        $ins->execute([
            'tid' => 'PAY-' . $pmt['id'],
            'cid' => $customerId,
            'type' => 'PAYMENT',
            'amt' => (float) $pmt['amount'],
            'ref' => $pmt['reference_no'],
            'dt' => $pmt['created_at'],
            'uid' => $pmt['recorded_by'],
        ]);
    }
}

/**
 * Best-effort refresh of the ledger projection + block flag. Never throws, so
 * it can safely run inside existing payment/invoice transactions without
 * risking the surrounding operation.
 */
function creditLedgerResetBestEffort(PDO $pdo, int $customerId): void {
    try {
        if ($pdo->inTransaction()) {
            // Sync flag via direct query to avoid nested transactions.
            $st = $pdo->prepare("SELECT id, credit_limit, credit_used, credit_approval_status, customer_status, credit_terms_days FROM customers WHERE id = :id");
            $st->execute(['id' => $customerId]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if ($c) {
                $overdueStmt = $pdo->prepare('SELECT COALESCE(SUM(outstanding_balance),0) FROM invoices WHERE customer_id = :cid AND payment_status IN ("unpaid","partial") AND credit_due_date IS NOT NULL AND credit_due_date < CURDATE() AND outstanding_balance > 0');
                $overdueStmt->execute(['cid' => $customerId]);
                $overdue = (float) $overdueStmt->fetchColumn();
                $requireApproval = creditGetSetting($pdo, 'require_credit_approval', '1');
                $blockOnOverdue  = creditGetSetting($pdo, 'block_new_orders_on_overdue', '0');
                $blocked = 0;
                if ($c['customer_status'] === 'suspended') $blocked = 1;
                elseif ($c['credit_approval_status'] === 'suspended') $blocked = 1;
                elseif ($c['credit_approval_status'] === 'rejected') $blocked = 1;
                elseif ($c['credit_approval_status'] !== 'approved' && $requireApproval === '1') $blocked = 1;
                elseif ($blockOnOverdue === '1' && $overdue > 0) $blocked = 1;
                elseif ((float) $c['credit_limit'] > 0 && (float) $c['credit_used'] >= (float) $c['credit_limit']) $blocked = 1;
                $pdo->prepare('UPDATE customers SET is_credit_blocked = :b WHERE id = :id')->execute(['b' => $blocked, 'id' => $customerId]);
            }
        } else {
            creditLedgerSyncBlockFlag($pdo, $customerId);
        }
    } catch (Throwable $e) {
        // best-effort only
    }
    try {
        creditLedgerRebuildCustomer($pdo, $customerId);
    } catch (Throwable $e) {
        // best-effort only
    }
}

/**
 * Unpaid/partial invoices for a customer, oldest first (the FIFO order in which
 * payments are cleared).
 */
function creditLedgerGetUnpaidInvoices(PDO $pdo, int $customerId): array {
    $st = $pdo->prepare("SELECT i.id, i.invoice_no, i.reference_no, i.sales_type, i.total_amount, i.amount_paid, i.outstanding_balance, i.payment_status, i.credit_due_date, i.generated_at, i.days_overdue
                         FROM invoices i
                         WHERE i.customer_id = :cid AND i.payment_status IN ('unpaid','partial') AND i.outstanding_balance > 0
                         ORDER BY i.generated_at ASC, i.id ASC");
    $st->execute(['cid' => $customerId]);
    return $st->fetchAll();
}

/**
 * Full ledger payload for the Customer Ledger page:
 *   - profile      : real-time credit profile (limit, used, available, ...)
 *   - statement    : durable ledger rows with a running balance column
 *   - unpaid_ins   : FIFO-ordered unpaid/partial invoices
 */
function creditLedgerGetCustomer(PDO $pdo, int $customerId): array {
    creditLedgerEnsureMigrations($pdo);
    $profile = creditGetProfile($pdo, $customerId);

    $st = $pdo->prepare('SELECT transaction_id, type, amount, reference_number, created_at, created_by FROM customer_transactions WHERE customer_id = :cid ORDER BY created_at ASC, id ASC');
    $st->execute(['cid' => $customerId]);
    $rows = $st->fetchAll();

    $balance = 0.0;
    foreach ($rows as &$row) {
        if ($row['type'] === 'INVOICE') {
            $balance += (float) $row['amount'];
            $row['running_balance'] = $balance;
        } else {
            $balance -= (float) $row['amount'];
            $row['running_balance'] = $balance;
        }
        $row['amount'] = (float) $row['amount'];
    }
    unset($row);

    return [
        'profile'       => $profile ?: null,
        'statement'     => $rows,
        'running_total' => $balance,
        'unpaid'        => creditLedgerGetUnpaidInvoices($pdo, $customerId),
    ];
}

} // end if (!function_exists(...))