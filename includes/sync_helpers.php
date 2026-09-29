<?php
/**
 * Data Synchronization & Migration Helpers
 * All operations are idempotent and wrapped in transactions with rollback.
 */
require_once __DIR__ . '/auto_migrate.php';

function syncRequireSuperAdmin(): void {
    if (!currentUser()) { header('Location: index.php?page=login'); exit; }
    $role = currentUser()['role'] ?? '';
    if ($role !== 'admin') {
        http_response_code(403);
        echo '<div style="padding:30px;text-align:center;font-family:sans-serif"><h2 style="color:#dc2626">Access Denied</h2><p>Only Super Admin can run data synchronization.</p></div>';
        exit;
    }
}

function syncLog(string &$log, string $msg): void {
    $log .= '[' . date('H:i:s') . '] ' . $msg . "\n";
}

function syncRun(string &$log, string $label, callable $fn): array {
    $start = microtime(true);
    $logEntry = '';
    try {
        $result = $fn($logEntry);
        $elapsed = round(microtime(true) - $start, 3);
        syncLog($log, "[$label] Done in {$elapsed}s — {$result['updated']} updated, {$result['skipped']} skipped, {$result['failed']} failed" . (!empty($result['errors']) ? ', errors: ' . implode('; ', array_slice($result['errors'], 0, 5)) : ''));
        return $result;
    } catch (Exception $e) {
        $elapsed = round(microtime(true) - $start, 3);
        syncLog($log, "[$label] FAILED after {$elapsed}s: " . $e->getMessage());
        return ['updated' => 0, 'skipped' => 0, 'failed' => 1, 'errors' => [$e->getMessage()]];
    }
}

function syncAll(PDO $pdo, string &$log): array {
    $totals = ['updated' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
    $syncs = [
        'Products'       => 'syncProducts',
        'Customers'      => 'syncCustomers',
        'Suppliers'      => 'syncSuppliers',
        'Orders'         => 'syncOrders',
        'Invoices'       => 'syncInvoices',
        'Payments'       => 'syncPayments',
        'Deliveries'     => 'syncDeliveries',
        'Credit Profiles'=> 'syncCreditProfiles',
        'Finance'        => 'syncFinanceBalances',
        'Inventory'      => 'syncInventory',
        'Workflows'      => 'syncWorkflows',
    ];
    syncLog($log, '=== BEGIN FULL SYNCHRONIZATION ===');
    foreach ($syncs as $label => $fn) {
        syncLog($log, "--- $label ---");
        $r = syncRun($log, $label, function(&$innerLog) use ($pdo, $fn) { return $fn($pdo, $innerLog); });
        $totals['updated'] += $r['updated'];
        $totals['skipped'] += $r['skipped'];
        $totals['failed']  += $r['failed'];
        foreach ($r['errors'] ?? [] as $e) $totals['errors'][] = $e;
    }
    syncLog($log, '=== FULL SYNCHRONIZATION COMPLETE: ' . json_encode($totals) . ' ===');
    return $totals;
}

// ─── PRODUCTS ─────────────────────────────────────────────────
function syncProducts(PDO $pdo, string &$log = ''): array {
    $pdo->beginTransaction();
    try {
        $updated = 0; $skipped = 0; $failed = 0; $errors = [];
        $rows = $pdo->query("SELECT id, sku, barcode, name AS product_name FROM products")->fetchAll();
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $changes = [];
            if (empty(trim($r['sku'] ?? ''))) {
                $sku = 'SKU-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
                $changes['sku'] = $sku;
            }
            if (empty(trim($r['barcode'] ?? ''))) {
                $barcode = 'BAR-' . str_pad((string)$id, 8, '0', STR_PAD_LEFT);
                $changes['barcode'] = $barcode;
            }
            if (!empty($changes)) {
                $sets = []; $params = [];
                foreach ($changes as $k => $v) { $sets[] = "$k = :$k"; $params[$k] = $v; }
                $params['id'] = $id;
                $pdo->prepare("UPDATE products SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
                $updated++;
            } else { $skipped++; }
        }
        $pdo->commit();
        return ['updated' => $updated, 'skipped' => $skipped, 'failed' => $failed, 'errors' => $errors];
    } catch (Exception $e) { $pdo->rollBack(); throw $e; }
}

// ─── CUSTOMERS ────────────────────────────────────────────────
function syncCustomers(PDO $pdo, string &$log = ''): array {
    $pdo->beginTransaction();
    try {
        $updated = 0; $skipped = 0; $failed = 0; $errors = [];
        $rows = $pdo->query("SELECT id, customer_code FROM customers")->fetchAll();
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            if (empty(trim($r['customer_code'] ?? ''))) {
                $code = 'CUS-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
                $pdo->prepare("UPDATE customers SET customer_code = :c WHERE id = :id")->execute(['c' => $code, 'id' => $id]);
                $updated++;
            } else { $skipped++; }
        }
        $pdo->commit();
        return ['updated' => $updated, 'skipped' => $skipped, 'failed' => $failed, 'errors' => $errors];
    } catch (Exception $e) { $pdo->rollBack(); throw $e; }
}

// ─── SUPPLIERS ────────────────────────────────────────────────
function syncSuppliers(PDO $pdo, string &$log = ''): array {
    ensureSupplierCodeMigration($pdo);
    $pdo->beginTransaction();
    try {
        $updated = 0; $skipped = 0; $failed = 0; $errors = [];
        $rows = $pdo->query("SELECT id, supplier_code FROM suppliers")->fetchAll();
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            if (empty(trim($r['supplier_code'] ?? ''))) {
                $code = 'SUP-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
                $pdo->prepare("UPDATE suppliers SET supplier_code = :c WHERE id = :id")->execute(['c' => $code, 'id' => $id]);
                $updated++;
            } else { $skipped++; }
        }
        $pdo->commit();
        return ['updated' => $updated, 'skipped' => $skipped, 'failed' => $failed, 'errors' => $errors];
    } catch (Exception $e) { $pdo->rollBack(); throw $e; }
}

// ─── ORDERS ───────────────────────────────────────────────────
function syncOrders(PDO $pdo, string &$log = ''): array {
    $pdo->beginTransaction();
    try {
        $updated = 0; $skipped = 0; $failed = 0; $errors = [];
        // Ensure all orders have workflow history
        $orders = $pdo->query("SELECT id, status FROM customer_orders")->fetchAll();
        foreach ($orders as $o) {
            $oid = (int) $o['id'];
            $st = $o['status'];
            // Ensure status_history entry exists for current status
            $exists = $pdo->prepare("SELECT COUNT(*) FROM status_history WHERE workflow = 'order' AND entity_id = ? AND to_status = ?");
            $exists->execute([$oid, $st]);
            if ((int) $exists->fetchColumn() === 0) {
                $pdo->prepare("INSERT INTO status_history (workflow, entity_id, from_status, to_status, changed_by, notes, created_at) VALUES ('order',?,?,?,1,'Sync: ensured status record',NOW())")
                    ->execute([$oid, $st, $st]);
                $updated++;
            } else { $skipped++; }
        }
        // Recalculate total_amount from line items where mismatched.
        // Matches OrderService::buildOrderLines / calculateOrderTotals: line total = qty*unit_price - discount + tax.
        $stmt = $pdo->query("SELECT t.id, t.calc_total FROM (SELECT co.id, co.total_amount, COALESCE(SUM(coi.quantity * coi.unit_price - COALESCE(coi.discount, 0) + COALESCE(coi.tax_amount, 0)), 0) AS calc_total FROM customer_orders co JOIN customer_order_items coi ON coi.customer_order_id = co.id GROUP BY co.id) t WHERE ROUND(t.calc_total, 2) != t.total_amount");
        foreach ($stmt as $row) {
            $pdo->prepare("UPDATE customer_orders SET total_amount = ? WHERE id = ?")->execute([round((float)$row['calc_total'], 2), (int)$row['id']]);
            $updated++;
        }
        $pdo->commit();
        return ['updated' => $updated, 'skipped' => $skipped, 'failed' => $failed, 'errors' => $errors];
    } catch (Exception $e) { $pdo->rollBack(); throw $e; }
}

// ─── INVOICES ────────────────────────────────────────────────
function syncInvoices(PDO $pdo, string &$log = ''): array {
    require_once __DIR__ . '/workflow_helpers.php';
    $pdo->beginTransaction();
    try {
        $updated = 0; $skipped = 0; $failed = 0; $errors = [];
        // Recalculate outstanding_balance, amount_paid and payment_status for all invoices
        $invs = $pdo->query("SELECT id, total_amount, outstanding_balance, amount_paid, payment_status FROM invoices")->fetchAll();
        foreach ($invs as $inv) {
            $iid = (int) $inv['id'];
            $total = (float) $inv['total_amount'];
            $paid = workflowGetInvoicePaidAmount($pdo, $iid);
            $newOutstanding = max($total - $paid, 0);
            $newStatus = workflowInvoicePaymentStatus($total, $paid);
            $oldOutstanding = (float) $inv['outstanding_balance'];
            $oldPaid = (float) $inv['amount_paid'];
            if (abs($oldOutstanding - $newOutstanding) > 0.01 || abs($oldPaid - $paid) > 0.01) {
                $pdo->prepare("UPDATE invoices SET amount_paid = ?, outstanding_balance = ?, payment_status = ? WHERE id = ?")->execute([$paid, $newOutstanding, $newStatus, $iid]);
                $updated++;
            } else { $skipped++; }
        }
        $pdo->commit();
        return ['updated' => $updated, 'skipped' => $skipped, 'failed' => $failed, 'errors' => $errors];
    } catch (Exception $e) { $pdo->rollBack(); throw $e; }
}

// ─── PAYMENTS ─────────────────────────────────────────────────
function syncPayments(PDO $pdo, string &$log = ''): array {
    $pdo->beginTransaction();
    try {
        $updated = 0; $skipped = 0; $failed = 0; $errors = [];

        // Ensure payment_confirmed orders have corresponding invoice_payments
        $orders = $pdo->query("SELECT co.id, co.customer_id, co.total_amount, co.payment_confirmed, co.payment_method, co.transaction_ref, co.verified_amount FROM customer_orders co WHERE co.payment_confirmed = 1")->fetchAll();
        foreach ($orders as $o) {
            $amt = (float) ($o['verified_amount'] ?: $o['total_amount']);
            // Find invoice for this order
            $invId = $pdo->prepare("SELECT id FROM invoices WHERE customer_order_id = ? LIMIT 1");
            $invId->execute([(int) $o['id']]);
            $iid = $invId->fetchColumn();
            if ($iid) {
                // Check if payment already recorded for this invoice
                $exists = $pdo->prepare("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ? AND amount = ?");
                $exists->execute([$iid, $amt]);
                if ((int) $exists->fetchColumn() === 0) {
                    $pmtMethod = $o['payment_method'] ?? 'bank';
                    $ref = $o['transaction_ref'] ?? ('SYNC-' . $o['id']);
                    $pdo->prepare("INSERT INTO invoice_payments (invoice_id, customer_id, amount, payment_method, reference_no, notes, created_at) VALUES (?,?,?,?,?,?,NOW())")
                        ->execute([$iid, $o['customer_id'], $amt, $pmtMethod, $ref, 'Sync: payment migration']);
                    $updated++;
                } else { $skipped++; }
            } else { $skipped++; }
        }
        $pdo->commit();
        return ['updated' => $updated, 'skipped' => $skipped, 'failed' => $failed, 'errors' => $errors];
    } catch (Exception $e) { $pdo->rollBack(); throw $e; }
}

// ─── DELIVERIES ──────────────────────────────────────────────
function syncDeliveries(PDO $pdo, string &$log = ''): array {
    $pdo->beginTransaction();
    try {
        $updated = 0; $skipped = 0; $failed = 0; $errors = [];
        // Ensure all approved orders with delivery_status have workflow entries
        $rows = $pdo->query("SELECT id, delivery_status, delivery_confirmed_at FROM customer_orders WHERE delivery_status IS NOT NULL")->fetchAll();
        foreach ($rows as $r) {
            $oid = (int) $r['id'];
            $ds = $r['delivery_status'];
            $exists = $pdo->prepare("SELECT COUNT(*) FROM status_history WHERE workflow = 'delivery' AND entity_id = ? AND to_status = ?");
            $exists->execute([$oid, $ds]);
            if ((int) $exists->fetchColumn() === 0) {
                $pdo->prepare("INSERT INTO status_history (workflow, entity_id, from_status, to_status, changed_by, notes, created_at) VALUES ('delivery',?,NULL,?,1,'Sync: delivery migration',?)")
                    ->execute([$oid, $ds, $r['delivery_confirmed_at'] ?? date('Y-m-d H:i:s')]);
                $updated++;
            } else { $skipped++; }
        }
        $pdo->commit();
        return ['updated' => $updated, 'skipped' => $skipped, 'failed' => $failed, 'errors' => $errors];
    } catch (Exception $e) { $pdo->rollBack(); throw $e; }
}

// ─── CREDIT PROFILES ──────────────────────────────────────────
function syncCreditProfiles(PDO $pdo, string &$log = ''): array {
    $pdo->beginTransaction();
    try {
        $updated = 0; $skipped = 0; $failed = 0; $errors = [];
        $custs = $pdo->query("SELECT id FROM customers WHERE credit_limit > 0")->fetchAll();
        $st1 = $pdo->prepare("SELECT credit_used FROM customers WHERE id = ?");
        foreach ($custs as $c) {
            $cid = (int) $c['id'];
            if (function_exists('creditRecalcUsed')) {
                $st1->execute([$cid]); $oldUsed = (float) $st1->fetchColumn();
                creditRecalcUsed($pdo, $cid);
                $st1->execute([$cid]); $newUsed = (float) $st1->fetchColumn();
                if (abs($oldUsed - $newUsed) > 0.01) $updated++; else $skipped++;
                if (function_exists('creditApplyOverLimitStatus')) creditApplyOverLimitStatus($pdo, $cid);
            } else { $skipped++; }
        }
        $pdo->commit();
        return ['updated' => $updated, 'skipped' => $skipped, 'failed' => $failed, 'errors' => $errors];
    } catch (Exception $e) { $pdo->rollBack(); throw $e; }
}

// ─── FINANCE BALANCES ─────────────────────────────────────────
function syncFinanceBalances(PDO $pdo, string &$log = ''): array {
    $pdo->beginTransaction();
    try {
        $updated = 0; $skipped = 0; $failed = 0; $errors = [];

        $stBank = $pdo->prepare("SELECT COALESCE(SUM(debit), 0) FROM bank_transactions WHERE bank_account_id = ? AND status = 'completed'");
        $stBank2 = $pdo->prepare("SELECT COALESCE(SUM(credit), 0) FROM bank_transactions WHERE bank_account_id = ? AND status = 'completed'");
        $stBank3 = $pdo->prepare("SELECT balance FROM bank_accounts WHERE id = ?");

        $accounts = $pdo->query("SELECT id, opening_balance FROM bank_accounts")->fetchAll();
        foreach ($accounts as $a) {
            $aid = (int) $a['id'];
            $opening = (float) $a['opening_balance'];
            $stBank->execute([$aid]); $debits = (float) $stBank->fetchColumn();
            $stBank2->execute([$aid]); $credits = (float) $stBank2->fetchColumn();
            $calcBal = $opening + $debits - $credits;
            $stBank3->execute([$aid]); $currentBal = (float) $stBank3->fetchColumn();
            if (abs($currentBal - $calcBal) > 0.01) {
                $pdo->prepare("UPDATE bank_accounts SET balance = ?, updated_at = NOW() WHERE id = ?")->execute([$calcBal, $aid]);
                $updated++;
            } else { $skipped++; }
        }

        $stCash = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE cash_account_id = ? AND transaction_type IN ('in','transfer_in','adjustment') AND status = 'completed'");
        $stCash2 = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE cash_account_id = ? AND transaction_type IN ('out','transfer_out') AND status = 'completed'");
        $stCash3 = $pdo->prepare("SELECT balance FROM cash_accounts WHERE id = ?");

        $cashAccounts = $pdo->query("SELECT id, opening_balance FROM cash_accounts")->fetchAll();
        foreach ($cashAccounts as $ca) {
            $caid = (int) $ca['id'];
            $opening = (float) $ca['opening_balance'];
            $stCash->execute([$caid]); $inflows = (float) $stCash->fetchColumn();
            $stCash2->execute([$caid]); $outflows = (float) $stCash2->fetchColumn();
            $calcBal = $opening + $inflows - $outflows;
            $stCash3->execute([$caid]); $currentBal = (float) $stCash3->fetchColumn();
            if (abs($currentBal - $calcBal) > 0.01 && ($inflows != 0 || $outflows != 0)) {
                $pdo->prepare("UPDATE cash_accounts SET balance = ?, updated_at = NOW() WHERE id = ?")->execute([$calcBal, $caid]);
                $updated++;
            } else { $skipped++; }
        }

        $pdo->commit();
        return ['updated' => $updated, 'skipped' => $skipped, 'failed' => $failed, 'errors' => $errors];
    } catch (Exception $e) { $pdo->rollBack(); throw $e; }
}

// ─── INVENTORY ────────────────────────────────────────────────
function syncInventory(PDO $pdo, string &$log = ''): array {
    $pdo->beginTransaction();
    try {
        $updated = 0; $skipped = 0; $failed = 0; $errors = [];

        // Available stock = sum of ACTIVE batches only (quarantined/expired/disposed are not sellable).
        // Products with no batches keep their manual stock_quantity (guard: HAVING COUNT(b.id) > 0).
        $prods = $pdo->query("SELECT p.id, p.stock_quantity, COALESCE(SUM(b.quantity), 0) AS batch_qty FROM products p LEFT JOIN product_batches b ON b.product_id = p.id AND b.status = 'active' GROUP BY p.id HAVING COUNT(b.id) > 0")->fetchAll();
        foreach ($prods as $p) {
            $pid = (int) $p['id'];
            $currentStock = (float) $p['stock_quantity'];
            $batchTotal = (float) $p['batch_qty'];
            if (abs($currentStock - $batchTotal) > 0.01) {
                $pdo->prepare("UPDATE products SET stock_quantity = ? WHERE id = ?")->execute([$batchTotal, $pid]);
                $updated++;
            } else { $skipped++; }
        }

        $stMov = $pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE product_id = ?");
        $stQty = $pdo->prepare("SELECT COALESCE(stock_quantity, 0) FROM products WHERE id = ?");
        $prodIds = $pdo->query("SELECT id FROM products")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($prodIds as $pid) {
            $stMov->execute([$pid]); $hasMovement = (int) $stMov->fetchColumn();
            if ($hasMovement === 0) {
                $stQty->execute([$pid]); $initialQty = (float) $stQty->fetchColumn();
                $pdo->prepare("INSERT INTO stock_movements (product_id, quantity_change, reason, reference_type, reference_id, created_at) VALUES (?,?,?,?,?,NOW())")->execute([$pid, $initialQty, 'Sync: initial inventory record', 'SYNC', $pid]);
                $updated++;
            }
        }

        $pdo->commit();
        return ['updated' => $updated, 'skipped' => $skipped, 'failed' => $failed, 'errors' => $errors];
    } catch (Exception $e) { $pdo->rollBack(); throw $e; }
}

// ─── WORKFLOW STATUSES ────────────────────────────────────────
function syncWorkflows(PDO $pdo, string &$log = ''): array {
    require_once __DIR__ . '/workflow_engine.php';
    // DDL (CREATE TABLE) implicitly commits MySQL transactions, so the table
    // must exist BEFORE beginTransaction.
    WorkflowEngine::ensureStatusHistoryTable($pdo);
    $pdo->beginTransaction();
    try {
        $updated = 0; $skipped = 0; $failed = 0; $errors = [];

        // Sync purchase order workflow (canonical workflow key: 'purchase')
        $pos = $pdo->query("SELECT id, status FROM purchase_orders")->fetchAll();
        foreach ($pos as $po) {
            $poid = (int) $po['id'];
            $st = $po['status'];
            $exists = $pdo->prepare("SELECT COUNT(*) FROM status_history WHERE workflow = 'purchase' AND entity_id = ? AND to_status = ?");
            $exists->execute([$poid, $st]);
            if ((int) $exists->fetchColumn() === 0) {
                WorkflowEngine::logStatusChange($pdo, 'purchase', $poid, null, $st, 1, 'Sync: workflow migration');
                $updated++;
            } else { $skipped++; }
        }

        // Sync invoice workflow
        $invs = $pdo->query("SELECT id, payment_status FROM invoices")->fetchAll();
        foreach ($invs as $inv) {
            $iid = (int) $inv['id'];
            $st = $inv['payment_status'];
            $exists = $pdo->prepare("SELECT COUNT(*) FROM status_history WHERE workflow = 'invoice' AND entity_id = ? AND to_status = ?");
            $exists->execute([$iid, $st]);
            if ((int) $exists->fetchColumn() === 0) {
                WorkflowEngine::logStatusChange($pdo, 'invoice', $iid, null, $st, 1, 'Sync: workflow migration');
                $updated++;
            } else { $skipped++; }
        }

        $pdo->commit();
        return ['updated' => $updated, 'skipped' => $skipped, 'failed' => $failed, 'errors' => $errors];
    } catch (Exception $e) { $pdo->rollBack(); throw $e; }
}

function syncGetSummary(PDO $pdo): array {
    ensureSupplierCodeMigration($pdo);
    return [
        'products' => (int) $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn(),
        'products_no_sku' => (int) $pdo->query("SELECT COUNT(*) FROM products WHERE sku IS NULL OR sku = ''")->fetchColumn(),
        'products_no_barcode' => (int) $pdo->query("SELECT COUNT(*) FROM products WHERE barcode IS NULL OR barcode = ''")->fetchColumn(),
        'customers' => (int) $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn(),
        'customers_no_code' => (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE customer_code IS NULL OR customer_code = ''")->fetchColumn(),
        'suppliers' => (int) $pdo->query("SELECT COUNT(*) FROM suppliers")->fetchColumn(),
        'suppliers_no_code' => (int) $pdo->query("SELECT COUNT(*) FROM suppliers WHERE supplier_code IS NULL OR supplier_code = ''")->fetchColumn(),
        'orders' => (int) $pdo->query("SELECT COUNT(*) FROM customer_orders")->fetchColumn(),
        'orders_no_workflow' => (int) $pdo->query("SELECT COUNT(*) FROM customer_orders co WHERE NOT EXISTS (SELECT 1 FROM status_history sh WHERE sh.workflow = 'order' AND sh.entity_id = co.id)")->fetchColumn(),
        'invoices' => (int) $pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn(),
        'invoices_mismatch' => (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE outstanding_balance > total_amount")->fetchColumn(),
        'bank_accounts' => (int) $pdo->query("SELECT COUNT(*) FROM bank_accounts")->fetchColumn(),
        'cash_accounts' => (int) $pdo->query("SELECT COUNT(*) FROM cash_accounts")->fetchColumn(),
        'credit_customers' => (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE credit_limit > 0")->fetchColumn(),
    ];
}

// ═══════════════════════════════════════════════════════════════════
//  INTEGRITY CHECK SYSTEM
// ═══════════════════════════════════════════════════════════════════

function ensureIntegrityChecksTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS integrity_checks (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        status ENUM('healthy','warning','error') NOT NULL DEFAULT 'healthy',
        modules_checked INT UNSIGNED NOT NULL DEFAULT 0,
        issues_found INT UNSIGNED NOT NULL DEFAULT 0,
        result_json LONGTEXT,
        checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function saveIntegrityCheckResult(PDO $pdo, array $result): void {
    ensureIntegrityChecksTable($pdo);
    $stmt = $pdo->prepare("INSERT INTO integrity_checks (status, modules_checked, issues_found, result_json, checked_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([
        $result['system_status'],
        $result['modules_checked'],
        $result['issues_found'],
        json_encode($result),
    ]);
}

function getIntegrityHistory(PDO $pdo, int $limit = 15): array {
    ensureIntegrityChecksTable($pdo);
    return $pdo->query("SELECT id, status, modules_checked, issues_found, checked_at FROM integrity_checks ORDER BY checked_at DESC LIMIT " . (int) $limit)->fetchAll();
}

function getLastIntegrityCheck(PDO $pdo): ?array {
    ensureIntegrityChecksTable($pdo);
    $row = $pdo->query("SELECT * FROM integrity_checks ORDER BY checked_at DESC LIMIT 1")->fetch();
    if (!$row) return null;
    $row['result_json'] = json_decode($row['result_json'] ?? '{}', true);
    return $row;
}

function _ic(string $key, string $label, string $category, string $status, string $message, int $issues = 0): array {
    return compact('key', 'label', 'category', 'status', 'message', 'issues');
}

function runIntegrityChecks(PDO $pdo): array {
    $checks = [];

    // ── Orders ↔ Invoices ──────────────────────────────────────
    $missingInvoice = (int) $pdo->query(
        "SELECT COUNT(*) FROM customer_orders co
         WHERE co.status NOT IN ('cancelled','draft')
         AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.customer_order_id = co.id)"
    )->fetchColumn();
    $checks[] = _ic(
        'orders_invoices', 'Orders \u2194 Invoices', 'data_consistency',
        $missingInvoice > 0 ? 'warning' : 'synced',
        $missingInvoice > 0 ? "$missingInvoice order(s) missing invoices" : "All orders have matching invoices",
        $missingInvoice
    );

    // ── Payments ↔ Finance ──────────────────────────────────────
    $overpaid = (int) $pdo->query(
        "SELECT COUNT(*) FROM invoices WHERE amount_paid > total_amount + 0.01"
    )->fetchColumn();
    $checks[] = _ic(
        'payments_finance', 'Payments \u2194 Finance', 'data_consistency',
        $overpaid > 0 ? 'error' : 'synced',
        $overpaid > 0 ? "$overpaid invoice(s) overpaid" : "All payment records consistent",
        $overpaid
    );

    // ── Products ↔ Stock ───────────────────────────────────────
    $noSku = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE (sku IS NULL OR sku = '') AND product_status = 'active'")->fetchColumn();
    $noBarcode = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE (barcode IS NULL OR barcode = '') AND product_status = 'active'")->fetchColumn();
    $prodIssues = $noSku + $noBarcode;
    $checks[] = _ic(
        'products_stock', 'Products \u2194 Stock', 'data_consistency',
        $prodIssues > 0 ? 'warning' : 'synced',
        $prodIssues > 0 ? "$noSku product(s) missing SKU, $noBarcode missing barcode" : "All products have identifiers",
        $prodIssues
    );

    // ── Inventory ↔ Warehouses ─────────────────────────────────
    $stockMismatch = (int) $pdo->query(
        "SELECT COUNT(*) FROM products p
         JOIN (SELECT product_id, SUM(quantity) AS batch_qty FROM product_batches WHERE status = 'active' GROUP BY product_id) b ON b.product_id = p.id
         WHERE ABS(p.stock_quantity - b.batch_qty) > 0.5"
    )->fetchColumn();
    $checks[] = _ic(
        'inventory_warehouses', 'Inventory \u2194 Warehouses', 'data_consistency',
        $stockMismatch > 0 ? 'error' : 'synced',
        $stockMismatch > 0 ? "$stockMismatch product(s) stock mismatch with batches" : "Inventory matches warehouse batches",
        $stockMismatch
    );

    // ── Orders ↔ Inventory ─────────────────────────────────────
    $negativeStock = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE stock_quantity < 0")->fetchColumn();
    $checks[] = _ic(
        'orders_inventory', 'Orders \u2194 Inventory', 'data_consistency',
        $negativeStock > 0 ? 'error' : 'synced',
        $negativeStock > 0 ? "$negativeStock product(s) with negative stock" : "No negative stock quantities",
        $negativeStock
    );

    // ── Deliveries ↔ Orders ────────────────────────────────────
    $deliveryMismatch = (int) $pdo->query(
        "SELECT COUNT(*) FROM customer_orders
         WHERE delivery_status IS NOT NULL
         AND status IN ('pending','draft','cancelled')"
    )->fetchColumn();
    $checks[] = _ic(
        'deliveries_orders', 'Deliveries \u2194 Orders', 'data_consistency',
        $deliveryMismatch > 0 ? 'warning' : 'synced',
        $deliveryMismatch > 0 ? "$deliveryMismatch order(s) with delivery status but wrong order status" : "Delivery records consistent",
        $deliveryMismatch
    );

    // ── Credit Profiles ────────────────────────────────────────
    $creditIssues = 0;
    if (function_exists('creditRecalcUsed')) {
        $creditCustomers = $pdo->query("SELECT id, credit_used FROM customers WHERE credit_limit > 0")->fetchAll();
        foreach ($creditCustomers as $cc) {
            $calcStmt = $pdo->prepare("SELECT COALESCE(SUM(outstanding_balance), 0) FROM invoices WHERE customer_id = ?");
            $calcStmt->execute([(int) $cc['id']]);
            $expected = (float) $calcStmt->fetchColumn();
            if (abs((float) $cc['credit_used'] - $expected) > 0.01) $creditIssues++;
        }
    }
    $checks[] = _ic(
        'credit_profiles', 'Credit Profiles', 'data_consistency',
        $creditIssues > 0 ? 'warning' : 'synced',
        $creditIssues > 0 ? "$creditIssues credit profile(s) need recalculation" : "Credit profiles consistent",
        $creditIssues
    );

    // ── Finance Balances ───────────────────────────────────────
    $financeIssues = 0;
    $bankAccounts = $pdo->query("SELECT id, opening_balance, balance FROM bank_accounts")->fetchAll();
    foreach ($bankAccounts as $ba) {
        $dStmt = $pdo->prepare("SELECT COALESCE(SUM(debit),0) FROM bank_transactions WHERE bank_account_id = ? AND status = 'completed'");
        $dStmt->execute([(int) $ba['id']]);
        $debits = (float) $dStmt->fetchColumn();
        $cStmt = $pdo->prepare("SELECT COALESCE(SUM(credit),0) FROM bank_transactions WHERE bank_account_id = ? AND status = 'completed'");
        $cStmt->execute([(int) $ba['id']]);
        $credits = (float) $cStmt->fetchColumn();
        $expected = (float) $ba['opening_balance'] + $debits - $credits;
        if (abs((float) $ba['balance'] - $expected) > 0.01) $financeIssues++;
    }
    $cashAccounts = $pdo->query("SELECT id, opening_balance, balance FROM cash_accounts")->fetchAll();
    foreach ($cashAccounts as $ca) {
        $inStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_transactions WHERE cash_account_id = ? AND transaction_type IN ('in','transfer_in','adjustment') AND status = 'completed'");
        $inStmt->execute([(int) $ca['id']]);
        $inflows = (float) $inStmt->fetchColumn();
        $outStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_transactions WHERE cash_account_id = ? AND transaction_type IN ('out','transfer_out') AND status = 'completed'");
        $outStmt->execute([(int) $ca['id']]);
        $outflows = (float) $outStmt->fetchColumn();
        $expected = (float) $ca['opening_balance'] + $inflows - $outflows;
        if (abs((float) $ca['balance'] - $expected) > 0.01 && ($inflows != 0 || $outflows != 0)) $financeIssues++;
    }
    $checks[] = _ic(
        'finance_balances', 'Finance Balances', 'data_consistency',
        $financeIssues > 0 ? 'warning' : 'synced',
        $financeIssues > 0 ? "$financeIssues account(s) balance mismatch" : "All account balances consistent",
        $financeIssues
    );

    // ── Workflows ──────────────────────────────────────────────
    $orderNoWf = (int) $pdo->query(
        "SELECT COUNT(*) FROM customer_orders co WHERE NOT EXISTS (SELECT 1 FROM status_history sh WHERE sh.workflow = 'order' AND sh.entity_id = co.id)"
    )->fetchColumn();
    $checks[] = _ic(
        'workflows', 'Workflows', 'data_consistency',
        $orderNoWf > 0 ? 'warning' : 'synced',
        $orderNoWf > 0 ? "$orderNoWf order(s) missing workflow history" : "All workflows have history records",
        $orderNoWf
    );

    // ── Customers ──────────────────────────────────────────────
    $custNoCode = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE customer_code IS NULL OR customer_code = ''")->fetchColumn();
    $checks[] = _ic(
        'customers', 'Customers', 'data_consistency',
        $custNoCode > 0 ? 'warning' : 'synced',
        $custNoCode > 0 ? "$custNoCode customer(s) missing customer code" : "All customers have codes",
        $custNoCode
    );

    // ── Suppliers ──────────────────────────────────────────────
    $supNoCode = (int) $pdo->query("SELECT COUNT(*) FROM suppliers WHERE supplier_code IS NULL OR supplier_code = ''")->fetchColumn();
    $checks[] = _ic(
        'suppliers', 'Suppliers', 'data_consistency',
        $supNoCode > 0 ? 'warning' : 'synced',
        $supNoCode > 0 ? "$supNoCode supplier(s) missing supplier code" : "All suppliers have codes",
        $supNoCode
    );

    // Aggregate
    $totalIssues = array_sum(array_column($checks, 'issues'));
    $totalModules = count($checks);
    $hasError = in_array('error', array_column($checks, 'status'), true);
    $hasWarning = in_array('warning', array_column($checks, 'status'), true);

    $systemStatus = 'healthy';
    if ($hasError) $systemStatus = 'error';
    elseif ($hasWarning) $systemStatus = 'warning';

    return [
        'system_status' => $systemStatus,
        'modules_checked' => $totalModules,
        'issues_found' => $totalIssues,
        'checks' => $checks,
        'checked_at' => date('Y-m-d H:i:s'),
    ];
}
