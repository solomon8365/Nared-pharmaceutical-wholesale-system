<?php
require_once __DIR__ . '/../services/init.php';
require_once __DIR__ . '/../includes/finance_helpers.php';
require_once __DIR__ . '/../includes/workflow_engine.php';

$pdo = finGetDb();
ensureFinanceErpMigration($pdo);
ensureSupplierPaymentCurrencyMigration($pdo);
ensureSupplierPaymentStatusBackfill($pdo);

$baseCurrency = finBaseCurrency();
$currencies = finCurrencies($pdo);

requirePermission('supplier_payments', 'view');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['csrf_token'])) {
    $csrf_token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrf_token;
} else {
    $csrf_token = $_SESSION['csrf_token'];
}

$success = '';
$error = '';
$uid = $_SESSION['user']['id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'record_payment') {
            requirePoCapability('payment');
            $order_id = intval($_POST['order_id'] ?? 0);
            $amount_paid = floatval($_POST['amount_paid'] ?? 0);
            $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
            $payment_method = $_POST['payment_method'] ?? 'bank_transfer';
            $reference = trim($_POST['reference'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            $currency = strtoupper(trim($_POST['currency'] ?? ''));
            $exchange_rate_input = floatval($_POST['exchange_rate'] ?? 0);

            if ($order_id <= 0 || $amount_paid <= 0 || $currency === '') {
                $error = 'Invalid order, amount, or currency.';
            } else {
                try {
                    $pdo->beginTransaction();

                    $curStmt = $pdo->prepare('SELECT code, exchange_rate, is_base FROM currencies WHERE code = ? AND is_active = 1 LIMIT 1');
                    $curStmt->execute([$currency]);
                    $cur = $curStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$cur) {
                        throw new Exception('Selected currency is invalid or inactive.');
                    }

                    if ($currency === $baseCurrency) {
                        $exchange_rate = 1.0000;
                        $base_amount = $amount_paid;
                    } else {
                        $exchange_rate = $exchange_rate_input > 0 ? $exchange_rate_input : (float) $cur['exchange_rate'];
                        if ($exchange_rate <= 0) {
                            throw new Exception('Exchange rate must be greater than zero for foreign currency.');
                        }
                        $base_amount = round($amount_paid * $exchange_rate, 2);
                    }

                    $stmt = $pdo->prepare('SELECT id, total_amount FROM purchase_orders WHERE id = ?');
                    $stmt->execute([$order_id]);
                    $po = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$po) {
                        throw new Exception('Purchase order not found.');
                    }

                    $stmt2 = $pdo->prepare('SELECT COALESCE(SUM(base_amount), 0) AS total_paid FROM supplier_payments WHERE order_id = ?');
                    $stmt2->execute([$order_id]);
                    $existing = $stmt2->fetch(PDO::FETCH_ASSOC);
                    $total_already_paid = floatval($existing['total_paid']);

                    $new_total_paid = $total_already_paid + $base_amount;
                    $po_total = (float) $po['total_amount'];

                    if ($new_total_paid > $po_total) {
                        throw new Exception('Payment amount exceeds remaining balance. Remaining: ' . finMoney($baseCurrency, max($po_total - $total_already_paid, 0)));
                    }

                    $voucher_no = 'SPV-' . date('Ymd') . '-' . str_pad($order_id, 4, '0', STR_PAD_LEFT);

                    // Payment status sync: remaining = 0 => Paid, 0 < remaining < total => Partial, remaining = total => Unpaid
                    $remaining_after = $po_total - $new_total_paid;
                    if ($remaining_after <= 0.005) {
                        $new_payment_status = 'paid';
                    } elseif ($new_total_paid > 0) {
                        $new_payment_status = 'partial';
                    } else {
                        $new_payment_status = 'unpaid';
                    }

                    // Unique, non-empty entry number generated BEFORE any insert (collision-safe)
                    $journal_entry_date = $payment_date;
                    $journal_entry_number = finGenerateEntryNumber($pdo, $journal_entry_date);

                    $stmt3 = $pdo->prepare('INSERT INTO supplier_payments (order_id, amount_paid, payment_date, payment_method, reference, notes, currency, exchange_rate, base_amount, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
                    $stmt3->execute([$order_id, $amount_paid, $payment_date, $payment_method, $reference, $notes, $currency, $exchange_rate, $base_amount]);

                    $account_payable_id = 0;
                    $cash_bank_id = 0;
                    $stmtAcct = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = '2100' LIMIT 1");
                    $stmtAcct->execute();
                    $rowAcct = $stmtAcct->fetch(PDO::FETCH_ASSOC);
                    if ($rowAcct) $account_payable_id = (int) $rowAcct['id'];

                    $stmtCash = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = '1100' LIMIT 1");
                    $stmtCash->execute();
                    $rowCash = $stmtCash->fetch(PDO::FETCH_ASSOC);
                    if ($rowCash) $cash_bank_id = (int) $rowCash['id'];

                    if ($account_payable_id && $cash_bank_id) {
                        $stmtJe = $pdo->prepare('INSERT INTO journal_entries (entry_date, entry_number, description, reference_type, reference_id, status, total_debit, total_credit, created_by, currency, exchange_rate, base_amount, created_at) VALUES (?, ?, ?, ?, ?, "draft", ?, ?, ?, ?, ?, ?, NOW())');
                        $inserted = false;
                        for ($attempt = 1; $attempt <= 5 && !$inserted; $attempt++) {
                            try {
                                $stmtJe->execute([$journal_entry_date, $journal_entry_number, 'Supplier Payment - ' . $voucher_no . ' (' . $currency . ' ' . number_format($amount_paid, 2) . ')', 'supplier_payment', $order_id, $base_amount, $base_amount, $uid, $currency, $exchange_rate, $base_amount]);
                                $inserted = true;
                            } catch (PDOException $e) {
                                // Duplicate entry_number from a concurrent request: regenerate and retry
                                if ((int) ($e->errorInfo[1] ?? 0) !== 1062 || $attempt === 5) {
                                    throw $e;
                                }
                                $journal_entry_number = finGenerateEntryNumber($pdo, $journal_entry_date);
                            }
                        }
                        $journal_id = $pdo->lastInsertId();

                        $stmtJd1 = $pdo->prepare('INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit, description) VALUES (?, ?, ?, ?, ?)');
                        $stmtJd1->execute([$journal_id, $account_payable_id, $base_amount, 0, 'Decrease accounts payable (' . $currency . ' ' . number_format($amount_paid, 2) . ')']);
                        $stmtJd2 = $pdo->prepare('INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit, description) VALUES (?, ?, ?, ?, ?)');
                        $stmtJd2->execute([$journal_id, $cash_bank_id, 0, $base_amount, 'Cash/Bank outflow (' . $currency . ' ' . number_format($amount_paid, 2) . ')']);
                    }

                    // Auto-record bank transaction if bank account selected (bank ledger kept in base currency)
                    $bankAccId = (int) ($_POST['bank_account_id'] ?? 0);
                    if ($bankAccId > 0) {
                        $bankDesc = 'Supplier payment - PO #' . $order_id . ' (' . $payment_method . ') in ' . $currency;
                        bankRecordTransaction($pdo, $bankAccId, 'supplier_payment', $base_amount, $reference, $bankDesc, null, null, $uid, $currency, $exchange_rate, $base_amount);
                    }

                    $stmt4 = $pdo->prepare('UPDATE purchase_orders SET payment_voucher_no = ?, payment_method = ?, payment_reference = ?, payment_date = ? WHERE id = ?');
                    $stmt4->execute([$voucher_no, $payment_method, $reference, $payment_date, $order_id]);

                    // Payment status changes go through the guarded workflow engine
                    $transition = WorkflowService::transition($pdo, StatusCatalog::WORKFLOW_PURCHASE_PAYMENT, $order_id, $new_payment_status, [
                        'skip_transaction' => true,
                        'changed_by' => $uid,
                        'notes' => 'Supplier payment recorded - voucher ' . $voucher_no,
                    ]);
                    if (!$transition['success']) {
                        throw new Exception($transition['error']);
                    }

                    // A recorded payment means finance has processed it: advance the PO
                    // approval status so store manager/admin no longer see "Awaiting Finance".
                    if (($po['status'] ?? '') === 'payment_pending') {
                        $approvalTransition = WorkflowService::transition($pdo, StatusCatalog::WORKFLOW_PURCHASE, $order_id, 'payment_approved', [
                            'skip_transaction' => true,
                            'changed_by' => $uid,
                            'extra' => [
                                'finance_notes' => 'Supplier payment recorded - voucher ' . $voucher_no,
                                'payment_approved_by' => $uid,
                                'payment_approved_at' => date('Y-m-d H:i:s'),
                            ],
                            'notes' => 'Supplier payment recorded - PO payment approved',
                        ]);
                        if (!$approvalTransition['success']) {
                            throw new Exception($approvalTransition['error']);
                        }
                    }

                    $pdo->commit();
                    $success = 'Payment of ' . finMoney($currency, $amount_paid) . ($currency !== $baseCurrency ? ' (' . finMoney($baseCurrency, $base_amount) . ')' : '') . ' recorded successfully. Voucher: ' . $voucher_no;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = $e->getMessage();
                }
            }
        } elseif ($action === 'delete_payment') {
            requirePoCapability('payment');
            $payment_id = intval($_POST['payment_id'] ?? 0);

            if ($payment_id <= 0) {
                $error = 'Invalid payment ID.';
            } else {
                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare('SELECT id, order_id, amount_paid FROM supplier_payments WHERE id = ?');
                    $stmt->execute([$payment_id]);
                    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$payment) {
                        throw new Exception('Payment not found.');
                    }

                    $stmt2 = $pdo->prepare('DELETE FROM supplier_payments WHERE id = ?');
                    $stmt2->execute([$payment_id]);

                    $order_id = $payment['order_id'];
                    $stmt3 = $pdo->prepare('SELECT total_amount FROM purchase_orders WHERE id = ?');
                    $stmt3->execute([$order_id]);
                    $po = $stmt3->fetch(PDO::FETCH_ASSOC);

                    $stmt4 = $pdo->prepare('SELECT COALESCE(SUM(base_amount), 0) AS total_paid FROM supplier_payments WHERE order_id = ?');
                    $stmt4->execute([$order_id]);
                    $remaining = $stmt4->fetch(PDO::FETCH_ASSOC);
                    $total_paid = floatval($remaining['total_paid']);

                    $po_total = (float) $po['total_amount'];
                    if ($po_total - $total_paid <= 0.005) {
                        $new_status = 'paid';
                    } elseif ($total_paid > 0) {
                        $new_status = 'partial';
                    } else {
                        $new_status = 'unpaid';
                    }

                    $transition = WorkflowService::transition($pdo, StatusCatalog::WORKFLOW_PURCHASE_PAYMENT, $order_id, $new_status, [
                        'skip_transaction' => true,
                        'changed_by' => $uid,
                        'notes' => 'Supplier payment #' . $payment_id . ' deleted; PO payment status resynced',
                    ]);
                    if (!$transition['success']) {
                        throw new Exception($transition['error']);
                    }

                    $pdo->commit();
                    $success = 'Payment deleted and PO status resynced.';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = $e->getMessage();
                }
            }
        } elseif ($action === 'approve' || $action === 'reject') {
            requirePoCapability('payment');
            $order_id = intval($_POST['order_id'] ?? 0);

            if ($order_id <= 0) {
                $error = 'Invalid PO.';
            } else {
                $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = :id AND (status = 'payment_pending' OR (status = 'received' AND (payment_voucher_no IS NULL OR payment_voucher_no = '')))");
                $stmt->execute(['id' => $order_id]);
                $po = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$po) {
                    $error = 'PO not found or not awaiting payment approval.';
                } else {
                    $notes = trim($_POST['finance_notes'] ?? '');
                    $userId = (int) ($_SESSION['user']['id'] ?? 0);

                    if ($action === 'approve') {
                        $voucherNo = trim($_POST['payment_voucher_no'] ?? '');
                        $paymentDate = trim($_POST['payment_date'] ?? date('Y-m-d'));
                        $paymentMethod = trim($_POST['payment_method'] ?? '');
                        $paymentReference = trim($_POST['payment_reference'] ?? '');
                        $receiptPath = '';

                        if ($voucherNo === '') {
                            $error = 'Payment voucher number is required.';
                        } elseif (empty($paymentMethod)) {
                            $error = 'Payment method is required.';
                        } else {
                            if (!empty($_FILES['payment_receipt']['name'])) {
                                $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
                                if (!in_array($_FILES['payment_receipt']['type'], $allowedTypes)) {
                                    $error = 'Receipt must be PDF, JPG, or PNG.';
                                } elseif ($_FILES['payment_receipt']['size'] > 5 * 1024 * 1024) {
                                    $error = 'Receipt file must be under 5MB.';
                                } else {
                                    $uploadDir = __DIR__ . '/../uploads/';
                                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                                    $ext = strtolower(pathinfo($_FILES['payment_receipt']['name'], PATHINFO_EXTENSION));
                                    $receiptPath = 'payment_receipt_' . $order_id . '_' . time() . '.' . $ext;
                                    move_uploaded_file($_FILES['payment_receipt']['tmp_name'], $uploadDir . $receiptPath);
                                }
                            }

                            if ($error === '') {
                                $extra = [
                                    'finance_notes' => $notes,
                                    'payment_approved_by' => $userId,
                                    'payment_approved_at' => date('Y-m-d H:i:s'),
                                    'payment_voucher_no' => $voucherNo,
                                    'payment_date' => $paymentDate,
                                    'payment_method' => $paymentMethod,
                                    'payment_reference' => $paymentReference,
                                    'payment_receipt' => $receiptPath,
                                ];
                                if ($po['status'] === 'received') {
                                    $set = [];
                                    $params = ['id' => $order_id];
                                    foreach ($extra as $col => $val) {
                                        $set[] = $col . ' = :e_' . $col;
                                        $params['e_' . $col] = $val;
                                    }
                                    $pdo->prepare('UPDATE purchase_orders SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
                                    WorkflowEngine::logStatusChange($pdo, 'purchase', $order_id, 'received', 'received', $userId, 'Payment approved after goods received');
                                    $success = "PO #{$order_id} payment approved.";
                                } else {
                                    $result = WorkflowService::transition($pdo, StatusCatalog::WORKFLOW_PURCHASE, $order_id, 'payment_approved', [
                                        'extra' => $extra,
                                        'changed_by' => $userId,
                                    ]);
                                    if ($result['success']) {
                                        $success = "PO #{$order_id} payment approved.";
                                    } else {
                                        $error = $result['error'];
                                    }
                                }
                            }
                        }
                    } else {
                        $result = WorkflowService::transition($pdo, StatusCatalog::WORKFLOW_PURCHASE, $order_id, 'payment_rejected', [
                            'extra' => ['finance_notes' => $notes],
                            'changed_by' => $userId,
                        ]);
                        if ($result['success']) {
                            $success = "PO #{$order_id} payment rejected.";
                        } else {
                            $error = $result['error'];
                        }
                    }
                }
            }
        }
    }
}

$tab = $_GET['tab'] ?? 'unpaid';

$poStatusList = implode('","', finPoPayableStatuses());

$stmtOutstanding = $pdo->query("SELECT COALESCE(SUM(po.total_amount - IFNULL(paid.total, 0)), 0) AS outstanding FROM purchase_orders po " . finPoPaidJoinSql() . " WHERE po.status IN (\"{$poStatusList}\") AND (po.total_amount - IFNULL(paid.total, 0)) > 0");
$totalOutstanding = $stmtOutstanding->fetchColumn();

$stmtPaidMonth = $pdo->query('SELECT COALESCE(SUM(base_amount), 0) FROM supplier_payments WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())');
$totalPaidMonth = $stmtPaidMonth->fetchColumn();

$stmtPending = $pdo->query('SELECT COUNT(*) FROM purchase_orders WHERE status = "payment_pending"');
$pendingApprovals = $stmtPending->fetchColumn();

$stmtSuppliers = $pdo->query("SELECT COUNT(DISTINCT po.supplier_id) FROM purchase_orders po " . finPoPaidJoinSql() . " WHERE po.status IN (\"{$poStatusList}\") AND (po.total_amount - IFNULL(paid.total, 0)) > 0");
$suppliersWithDues = $stmtSuppliers->fetchColumn();

$poQuery = "
    SELECT po.id, CONCAT('PO-', po.id) AS order_number, s.name AS supplier_name, po.total_amount, po.payment_status,
           po.payment_voucher_no, po.payment_method, po.payment_reference, po.payment_date,
           po.status AS order_status,
           IFNULL(paid.total, 0) AS total_paid,
           (po.total_amount - IFNULL(paid.total, 0)) AS remaining,
           po.created_at AS due_date
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    " . finPoPaidJoinSql() . "
    WHERE po.status IN (\"{$poStatusList}\")
";

$stmtPo = null;
$tabTitle = '';
switch ($tab) {
    case 'partial':
        $stmtPo = $pdo->prepare($poQuery . ' AND IFNULL(paid.total, 0) > 0 AND IFNULL(paid.total, 0) < po.total_amount ORDER BY po.id DESC');
        $stmtPo->execute();
        $tabTitle = 'Partially Paid';
        break;
    case 'paid':
        $stmtPo = $pdo->prepare($poQuery . ' AND IFNULL(paid.total, 0) >= po.total_amount ORDER BY po.id DESC');
        $stmtPo->execute();
        $tabTitle = 'Fully Paid';
        break;
    case 'history':
        $stmtPo = $pdo->query('SELECT sp.id, sp.order_id, CONCAT("PO-", po.id) AS order_number, s.name AS supplier_name, sp.amount_paid, sp.currency, sp.exchange_rate, sp.base_amount, sp.payment_date, sp.payment_method, sp.reference, sp.notes, sp.created_at FROM supplier_payments sp LEFT JOIN purchase_orders po ON sp.order_id = po.id LEFT JOIN suppliers s ON po.supplier_id = s.id ORDER BY sp.payment_date DESC, sp.id DESC');
        $stmtPo->execute();
        $tabTitle = 'Payment History';
        break;
    case 'approvals':
        $stmtPo = $pdo->query("SELECT po.id, CONCAT('PO-', po.id) AS order_number, s.name AS supplier_name, po.total_amount, po.payment_status, po.status AS order_status, IFNULL(paid.total, 0) AS total_paid, (po.total_amount - IFNULL(paid.total, 0)) AS remaining FROM purchase_orders po LEFT JOIN suppliers s ON po.supplier_id = s.id " . finPoPaidJoinSql() . " WHERE po.status = 'payment_pending' OR (po.status = 'received' AND (po.payment_voucher_no IS NULL OR po.payment_voucher_no = '')) ORDER BY po.id DESC");
        $stmtPo->execute();
        $tabTitle = 'Payment Approval';
        break;
    default:
        $stmtPo = $pdo->prepare($poQuery . ' AND IFNULL(paid.total, 0) = 0 ORDER BY po.id DESC');
        $stmtPo->execute();
        $tabTitle = 'Unpaid Purchase Orders';
        break;
}
$rows = $stmtPo->fetchAll(PDO::FETCH_ASSOC);

$stmtUnpaidPOs = $pdo->query("
    SELECT po.id, CONCAT('PO-', po.id) AS order_number, s.name AS supplier_name, po.total_amount, IFNULL(paid.total, 0) AS total_paid,
           (po.total_amount - IFNULL(paid.total, 0)) AS remaining
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    " . finPoPaidJoinSql() . "
    WHERE po.status IN (\"{$poStatusList}\")
      AND IFNULL(paid.total, 0) < po.total_amount
    ORDER BY po.id DESC
");
$unpaidPOs = $stmtUnpaidPOs->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Payments - Finance ERP</title>
    <?= finCss() ?>
    <style>
        .fin-stats { display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
        .fin-stat { flex: 1; min-width: 200px; }
        .fin-stat .stat-value { font-size: 1.8rem; font-weight: 700; color: #1a237e; }
        .fin-stat .stat-label { font-size: 0.85rem; color: #666; margin-top: 4px; }
        .fin-tab { display: flex; gap: 0; border-bottom: 2px solid #e0e0e0; margin-bottom: 20px; overflow-x: auto; -webkit-overflow-scrolling: touch; flex-wrap: nowrap; }
        .fin-tab a { padding: 10px 20px; text-decoration: none; color: #555; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all 0.2s; font-weight: 500; }
        .fin-tab a:hover { color: #1a237e; background: #f5f5f5; }
        .fin-tab a.active { color: #1a237e; border-bottom-color: #1a237e; font-weight: 600; }
        .fin-table { width: 100%; border-collapse: collapse; }
        .fin-table th { background: #f0f4f8; padding: 10px 12px; text-align: left; font-size: 0.8rem; text-transform: uppercase; color: #555; border-bottom: 2px solid #dee2e6; white-space: nowrap; }
        .fin-table td { padding: 10px 12px; border-bottom: 1px solid #eee; font-size: 0.9rem; }
        .fin-table tr:hover td { background: #f8f9ff; }
        .fin-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; }
        .fin-badge.unpaid { background: #ffebee; color: #c62828; }
        .fin-badge.partial { background: #fff3e0; color: #e65100; }
        .fin-badge.paid { background: #e8f5e9; color: #2e7d32; }
        .fin-badge.pending { background: #fff8e1; color: #f57f17; }
        .fin-badge.bank_transfer { background: #e3f2fd; color: #1565c0; }
        .fin-badge.cash { background: #e8f5e9; color: #2e7d32; }
        .fin-badge.mobile_banking { background: #f3e5f5; color: #7b1fa2; }
        .fin-badge.cheque { background: #fce4ec; color: #c62828; }
        .fin-btn { padding: 7px 16px; border: none; border-radius: 4px; cursor: pointer; font-size: 0.85rem; font-weight: 500; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .fin-btn.primary { background: #1a237e; color: #fff; }
        .fin-btn.primary:hover { background: #283593; }
        .fin-btn.danger { background: #c62828; color: #fff; }
        .fin-btn.danger:hover { background: #d32f2f; }
        .fin-btn.success { background: #2e7d32; color: #fff; }
        .fin-btn.success:hover { background: #388e3c; }
        .fin-btn.outline { background: transparent; border: 1px solid #ccc; color: #555; }
        .fin-btn.outline:hover { background: #f5f5f5; }
        .fin-panel { display: none; }
        .fin-panel.active { display: block; }
        .fin-card { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 20px; margin-bottom: 20px; }
        .fin-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media(max-width:480px){.form-grid{grid-template-columns:1fr}}
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem; color: #333; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 0.9rem; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: #1a237e; box-shadow: 0 0 0 2px rgba(26,35,126,0.1); }
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-size: 0.9rem; }
        .alert.success { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; }
        .alert.error { background: #ffebee; color: #c62828; border: 1px solid #ef9a9a; }
        .amount-positive { color: #c62828; font-weight: 600; }
        .amount-zero { color: #2e7d32; font-weight: 600; }
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal-overlay.show { display: flex; }
        .modal-box { background: #fff; border-radius: 8px; padding: 24px; width: 600px; max-width: 90vw; max-height: 85vh; overflow-y: auto; box-shadow: 0 8px 32px rgba(0,0,0,0.2); }
        @media(max-width:480px){.modal-box{width:auto;padding:16px}}
        .modal-box h3 { margin-top: 0; margin-bottom: 16px; color: #1a237e; }
        .modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }
        .empty-state { text-align: center; padding: 40px; color: #999; }
        .empty-state h4 { margin-bottom: 8px; color: #666; }
    </style>
    <?= finJs() ?>
</head>
<body>
<div class="fin-wrap">
    <h1 style="margin-bottom: 4px; color: #1a237e;">Supplier Payments</h1>
    <p style="color: #666; margin-bottom: 24px; font-size: 0.95rem;">Manage purchase order payments to suppliers</p>

    <?php if ($success): ?>
        <div class="alert success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="fin-stats">
        <div class="fin-card fin-stat">
            <div class="stat-label">Total Outstanding</div>
            <div class="stat-value"><?= htmlspecialchars(finMoney($baseCurrency, (float) $totalOutstanding)) ?></div>
        </div>
        <div class="fin-card fin-stat">
            <div class="stat-label">Paid This Month</div>
            <div class="stat-value" style="color: #2e7d32;"><?= htmlspecialchars(finMoney($baseCurrency, (float) $totalPaidMonth)) ?></div>
        </div>
        <div class="fin-card fin-stat">
            <div class="stat-label">Pending Approvals</div>
            <div class="stat-value" style="color: #e65100;"><?= intval($pendingApprovals) ?></div>
        </div>
        <div class="fin-card fin-stat">
            <div class="stat-label">Suppliers with Dues</div>
            <div class="stat-value" style="color: #c62828;"><?= intval($suppliersWithDues) ?></div>
        </div>
    </div>

    <div class="fin-card">
        <div class="fin-toolbar">
            <div class="fin-tab">
                <a href="?page=supplier_payments&tab=unpaid" class="<?= $tab === 'unpaid' ? 'active' : '' ?>">Unpaid POs</a>
                <a href="?page=supplier_payments&tab=partial" class="<?= $tab === 'partial' ? 'active' : '' ?>">Partially Paid</a>
                <a href="?page=supplier_payments&tab=paid" class="<?= $tab === 'paid' ? 'active' : '' ?>">Fully Paid</a>
                <a href="?page=supplier_payments&tab=history" class="<?= $tab === 'history' ? 'active' : '' ?>">Payment History</a>
                <?php if (canProcessPurchasePayment()): ?>
                <a href="?page=supplier_payments&tab=approvals" class="<?= $tab === 'approvals' ? 'active' : '' ?>">Payment Approval</a>
                <?php endif; ?>
            </div>
            <?php if ($tab !== 'history' && $tab !== 'approvals'): ?>
                <?php if (canProcessPurchasePayment()): ?>
                <button class="fin-btn primary" onclick="openPaymentModal()">+ Record Payment</button>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($tab === 'history'): ?>
            <div style="overflow-x:auto">
            <table class="fin-table">
                <thead>
                    <tr>
                        <th>Payment ID</th>
                        <th>PO Number</th>
                        <th>Supplier</th>
                        <th>Amount Paid</th>
                        <th>Payment Date</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th>Notes</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="9" class="empty-state"><h4>No payment history</h4><p>Payments will appear here once recorded.</p></td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php $payCur = ($row['currency'] ?? '') ?: $baseCurrency; ?>
                            <tr>
                                <td>#<?= intval($row['id']) ?></td>
                                <td><strong><?= htmlspecialchars($row['order_number'] ?? 'N/A') ?></strong></td>
                                <td><?= htmlspecialchars($row['supplier_name'] ?? 'Unknown') ?></td>
                                <td><strong><?= htmlspecialchars(finMoney($payCur, (float) $row['amount_paid'])) ?></strong><?php if ($payCur !== $baseCurrency && (float) $row['base_amount'] > 0): ?><br><span style="color:#888;font-size:.78rem;"><?= htmlspecialchars(finMoney($baseCurrency, (float) $row['base_amount'])) ?></span><?php endif; ?></td>
                                <td><?= htmlspecialchars($row['payment_date']) ?></td>
                                <td><span class="fin-badge <?= htmlspecialchars($row['payment_method'] ?? '') ?>"><?= ucwords(str_replace('_', ' ', $row['payment_method'] ?? '')) ?></span></td>
                                <td><?= htmlspecialchars($row['reference'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($row['notes'] ?? '-') ?></td>
                                <td>
                                    <button class="fin-btn outline" onclick="printVoucher(<?= intval($row['id']) ?>, '<?= htmlspecialchars($row['order_number'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($row['supplier_name'] ?? '', ENT_QUOTES) ?>', <?= floatval($row['amount_paid']) ?>, '<?= htmlspecialchars($row['payment_date'], ENT_QUOTES) ?>', '<?= htmlspecialchars(strtoupper($row['payment_method'] ?? ''), ENT_QUOTES) ?>', '<?= htmlspecialchars($row['reference'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($payCur, ENT_QUOTES) ?>')">Print</button>
                                    <?php if (canProcessPurchasePayment()): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this payment record?')">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="action" value="delete_payment">
                                        <input type="hidden" name="payment_id" value="<?= intval($row['id']) ?>">
                                        <button type="submit" class="fin-btn danger" style="padding: 8px 16px; font-size: 0.82rem; min-height: 44px;">Delete</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        <?php elseif ($tab === 'approvals'): ?>
            <div style="overflow-x:auto">
            <table class="fin-table">
                <thead>
                    <tr>
                        <th>PO Number</th>
                        <th>Supplier Name</th>
                        <th>Total Amount</th>
                        <th>Paid Amount</th>
                        <th>Remaining</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="7" class="empty-state"><h4>No pending approvals</h4><p>There are no purchase orders awaiting payment approval.</p></td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                                $remaining = floatval($row['remaining']);
                                $paidAmt = floatval($row['total_paid']);
                                $totalAmt = floatval($row['total_amount']);
                                $statusClass = 'pending';
                                $statusLabel = 'Awaiting Approval';
                                if ($row['order_status'] === 'received') {
                                    $statusLabel = 'Received - Awaiting Payment';
                                }
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($row['order_number']) ?></strong></td>
                                <td><?= htmlspecialchars($row['supplier_name'] ?? 'Unknown') ?></td>
                                <td><strong><?= htmlspecialchars(finMoney($baseCurrency, $totalAmt)) ?></strong></td>
                                <td><?= htmlspecialchars(finMoney($baseCurrency, $paidAmt)) ?></td>
                                <td class="<?= $remaining > 0 ? 'amount-positive' : 'amount-zero' ?>"><?= $remaining > 0 ? htmlspecialchars(finMoney($baseCurrency, $remaining)) : 'Fully Paid' ?></td>
                                <td><span class="fin-badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                                <td style="white-space:nowrap">
                                    <?php if (canProcessPurchasePayment()): ?>
                                    <button class="fin-btn success" style="padding: 8px 16px; font-size: 0.82rem; min-height: 44px;" onclick="showApprovalForm(<?= intval($row['id']) ?>, 'approve')">Approve</button>
                                    <button class="fin-btn danger" style="padding: 8px 16px; font-size: 0.82rem; min-height: 44px;" onclick="showApprovalForm(<?= intval($row['id']) ?>, 'reject')">Reject</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr id="approve-form-<?= intval($row['id']) ?>" class="approval-form-row" style="display:none">
                                <td colspan="7">
                                    <div style="padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px">
                                        <h4 style="margin:0 0 10px;color:#1a237e;">Approve Payment - <?= htmlspecialchars($row['order_number']) ?></h4>
                                        <form method="POST" enctype="multipart/form-data" class="form-grid" style="grid-template-columns:1fr 1fr 1fr">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="order_id" value="<?= intval($row['id']) ?>">
                                            <div class="form-group">
                                                <label>Voucher No. *</label>
                                                <input type="text" name="payment_voucher_no" required placeholder="e.g. SPV-0001">
                                            </div>
                                            <div class="form-group">
                                                <label>Payment Date</label>
                                                <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>">
                                            </div>
                                            <div class="form-group">
                                                <label>Payment Method *</label>
                                                <select name="payment_method" required>
                                                    <option value="">Select</option>
                                                    <option value="cash">Cash</option>
                                                    <option value="bank_transfer">Bank Transfer</option>
                                                    <option value="cheque">Cheque</option>
                                                    <option value="mobile_money">Mobile Money</option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label>Reference No.</label>
                                                <input type="text" name="payment_reference" placeholder="Cheque/Transfer ref">
                                            </div>
                                            <div class="form-group">
                                                <label>Payment Receipt (PDF/JPG/PNG, max 5MB)</label>
                                                <input type="file" name="payment_receipt" accept=".pdf,.jpg,.jpeg,.png">
                                            </div>
                                            <div class="form-group" style="grid-column:1/-1">
                                                <label>Finance Notes</label>
                                                <textarea name="finance_notes" rows="2" placeholder="Optional notes about this payment"></textarea>
                                            </div>
                                            <div style="grid-column:1/-1;display:flex;gap:8px">
                                                <button type="submit" class="fin-btn success"><i class="fas fa-check"></i> Confirm Approval</button>
                                                <button type="button" class="fin-btn outline" onclick="hideApprovalForm(<?= intval($row['id']) ?>, 'approve')">Cancel</button>
                                            </div>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <tr id="reject-form-<?= intval($row['id']) ?>" class="approval-form-row" style="display:none">
                                <td colspan="7">
                                    <div style="padding:12px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px">
                                        <h4 style="margin:0 0 10px;color:#c62828;">Reject Payment - <?= htmlspecialchars($row['order_number']) ?></h4>
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <input type="hidden" name="order_id" value="<?= intval($row['id']) ?>">
                                            <div class="form-group">
                                                <label>Reason for Rejection *</label>
                                                <textarea name="finance_notes" rows="2" required placeholder="Explain why this payment is rejected"></textarea>
                                            </div>
                                            <div style="display:flex;gap:8px">
                                                <button type="submit" class="fin-btn danger"><i class="fas fa-times"></i> Confirm Rejection</button>
                                                <button type="button" class="fin-btn outline" onclick="hideApprovalForm(<?= intval($row['id']) ?>, 'reject')">Cancel</button>
                                            </div>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        <?php else: ?>
            <div style="overflow-x:auto">
            <table class="fin-table">
                <thead>
                    <tr>
                        <th>PO Number</th>
                        <th>Supplier Name</th>
                        <th>Invoice No</th>
                        <th>Total Amount</th>
                        <th>Paid Amount</th>
                        <th>Remaining</th>
                        <th>Due Date</th>
                        <th>Payment Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="9" class="empty-state"><h4>No records found</h4><p>There are no <?= htmlspecialchars(strtolower($tabTitle)) ?> purchase orders at this time.</p></td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                                $remaining = floatval($row['remaining']);
                                $paidAmt = floatval($row['total_paid']);
                                $totalAmt = floatval($row['total_amount']);
                                $statusClass = 'unpaid';
                                $statusLabel = 'Unpaid';
                                if ($paidAmt >= $totalAmt && $totalAmt > 0) {
                                    $statusClass = 'paid';
                                    $statusLabel = 'Paid';
                                } elseif ($paidAmt > 0) {
                                    $statusClass = 'partial';
                                    $statusLabel = 'Partial';
                                } else {
                                    $poStatus = $row['order_status'] ?? '';
                                    if (strpos($poStatus, 'payment') !== false) {
                                        $statusClass = 'pending';
                                        $statusLabel = ucfirst($poStatus);
                                    }
                                }
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($row['order_number']) ?></strong></td>
                                <td><?= htmlspecialchars($row['supplier_name'] ?? 'Unknown') ?></td>
                                <td><?= htmlspecialchars($row['payment_voucher_no'] ?? '-') ?></td>
                                <td><strong><?= htmlspecialchars(finMoney($baseCurrency, $totalAmt)) ?></strong></td>
                                <td><?= htmlspecialchars(finMoney($baseCurrency, $paidAmt)) ?></td>
                                <td class="<?= $remaining > 0 ? 'amount-positive' : 'amount-zero' ?>"><?= $remaining > 0 ? htmlspecialchars(finMoney($baseCurrency, $remaining)) : 'Fully Paid' ?></td>
                                <td><?= htmlspecialchars($row['due_date'] ? date('d M Y', strtotime($row['due_date'])) : '-') ?></td>
                                <td><span class="fin-badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                                <td>
                                    <?php if ($remaining > 0): ?>
                                        <?php if (canProcessPurchasePayment()): ?>
                                        <button class="fin-btn success" style="padding: 8px 16px; font-size: 0.82rem; min-height: 44px;" onclick="openPaymentModal(<?= intval($row['id']) ?>, '<?= htmlspecialchars($row['order_number'], ENT_QUOTES) ?>', <?= $remaining ?>)">Pay Now</button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <button class="fin-btn outline" style="padding: 8px 16px; font-size: 0.82rem; min-height: 44px;" onclick="printVoucher(0, '<?= htmlspecialchars($row['order_number'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['supplier_name'] ?? '', ENT_QUOTES) ?>', <?= $totalAmt ?>, '<?= htmlspecialchars($row['due_date'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars(strtoupper($row['payment_method'] ?? ''), ENT_QUOTES) ?>', '<?= htmlspecialchars($row['payment_voucher_no'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($baseCurrency, ENT_QUOTES) ?>')">Voucher</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal-overlay" id="paymentModal">
    <div class="modal-box">
        <h3>Record Supplier Payment</h3>
        <form method="POST" id="paymentForm">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="action" value="record_payment">

            <div class="form-group">
                <label for="order_id">Purchase Order *</label>
                <select name="order_id" id="order_id" required>
                    <option value="">-- Select Purchase Order --</option>
                    <?php foreach ($unpaidPOs as $po): ?>
                        <option value="<?= intval($po['id']) ?>" data-remaining="<?= floatval($po['remaining']) ?>">
                            <?= htmlspecialchars($po['order_number']) ?> - <?= htmlspecialchars($po['supplier_name'] ?? 'Unknown') ?> (Remaining: <?= htmlspecialchars(finMoney($baseCurrency, (float) $po['remaining'])) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label for="amount_paid">Payment Amount *</label>
                    <input type="number" name="amount_paid" id="amount_paid" step="0.01" min="0.01" required placeholder="0.00">
                </div>
                <div class="form-group">
                    <label for="payment_date">Payment Date *</label>
                    <input type="date" name="payment_date" id="payment_date" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label for="currency">Currency *</label>
                    <select name="currency" id="currency" required>
                        <?php foreach ($currencies as $c): ?>
                            <option value="<?= htmlspecialchars($c['code']) ?>" data-rate="<?= htmlspecialchars((string) $c['exchange_rate']) ?>" <?= $c['code'] === $baseCurrency ? 'selected' : '' ?>><?= htmlspecialchars($c['code']) ?> - <?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="exchange-rate-group" style="display:none;">
                    <label for="exchange_rate">Exchange Rate (1 unit = <?= htmlspecialchars($baseCurrency) ?>) *</label>
                    <input type="number" name="exchange_rate" id="exchange_rate" step="0.0001" min="0.0001" placeholder="e.g. 140.5000">
                </div>
            </div>

            <div class="form-group" id="base-amount-preview" style="display:none;">
                <label>Base Amount (<?= htmlspecialchars($baseCurrency) ?>)</label>
                <div id="base-amount-value" style="font-weight:700;color:#1a237e;">0.00</div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label for="payment_method">Payment Method *</label>
                    <select name="payment_method" id="payment_method" required>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="cash">Cash</option>
                        <option value="mobile_banking">Mobile Banking</option>
                        <option value="cheque">Cheque</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="bank_account_id">Bank Account</label>
                    <?= bankRenderAccountDropdown($pdo, 'bank_account_id', (string)bankGetDefaultAccountId($pdo)) ?>
                </div>
                <div class="form-group">
                    <label for="reference">Reference Number</label>
                    <input type="text" name="reference" id="reference" placeholder="Transaction / Cheque no.">
                </div>
            </div>

            <div class="form-group">
                <label for="notes">Notes</label>
                <textarea name="notes" id="notes" rows="3" placeholder="Additional payment notes..."></textarea>
            </div>

            <div class="modal-actions">
                <button type="button" class="fin-btn outline" onclick="closePaymentModal()">Cancel</button>
                <?php if (canProcessPurchasePayment()): ?>
                <button type="submit" class="fin-btn primary">Record Payment</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<script>
function showApprovalForm(id, type) {
    hideApprovalForm(id, type === 'approve' ? 'reject' : 'approve');
    var el = document.getElementById(type + '-form-' + id);
    if (el) el.style.display = el.style.display === 'none' ? '' : 'none';
}
function hideApprovalForm(id, type) {
    var el = document.getElementById(type + '-form-' + id);
    if (el) el.style.display = 'none';
}

function openPaymentModal(poId, poNumber, remaining) {
    document.getElementById('paymentModal').classList.add('show');
    if (poId) {
        var sel = document.getElementById('order_id');
        sel.value = poId;
        if (remaining) {
            document.getElementById('amount_paid').max = remaining;
            document.getElementById('amount_paid').placeholder = 'Max: ' + BASE_CURRENCY + ' ' + parseFloat(remaining).toFixed(2);
        }
    }
    updateCurrencyUI();
}

function closePaymentModal() {
    document.getElementById('paymentModal').classList.remove('show');
    document.getElementById('paymentForm').reset();
    document.getElementById('amount_paid').removeAttribute('max');
}

document.getElementById('order_id').addEventListener('change', function() {
    var opt = this.options[this.selectedIndex];
    var rem = opt.getAttribute('data-remaining');
    if (rem) {
        document.getElementById('amount_paid').max = rem;
        document.getElementById('amount_paid').placeholder = 'Max: ' + BASE_CURRENCY + ' ' + parseFloat(rem).toFixed(2);
    }
    updateCurrencyUI();
});

document.getElementById('paymentModal').addEventListener('click', function(e) {
    if (e.target === this) closePaymentModal();
});

function printVoucher(id, poNumber, supplier, amount, date, method, reference, cur) {
    cur = cur || '<?= htmlspecialchars($baseCurrency, ENT_QUOTES) ?>';
    var w = window.open('', '_blank', 'width=600,height=700');
    w.document.write('<!DOCTYPE html><html><head><title>Payment Voucher - ' + poNumber + '</title>');
    w.document.write('<style>');
    w.document.write('body { font-family: Arial, sans-serif; padding: 30px; color: #333; }');
    w.document.write('.header { text-align: center; border-bottom: 3px double #1a237e; padding-bottom: 16px; margin-bottom: 20px; }');
    w.document.write('.header h1 { margin: 0; color: #1a237e; font-size: 22px; }');
    w.document.write('.header p { margin: 4px 0 0; color: #666; font-size: 13px; }');
    w.document.write('.voucher-title { text-align: center; font-size: 18px; font-weight: 700; color: #c62828; margin: 16px 0; text-transform: uppercase; letter-spacing: 2px; }');
    w.document.write('table.info { width: 100%; border-collapse: collapse; margin: 16px 0; }');
    w.document.write('table.info td { padding: 8px 10px; border-bottom: 1px solid #eee; font-size: 14px; }');
    w.document.write('table.info td:first-child { font-weight: 600; width: 40%; color: #555; }');
    w.document.write('.amount-box { background: #f0f4f8; border: 2px solid #1a237e; border-radius: 8px; padding: 16px; text-align: center; margin: 20px 0; }');
    w.document.write('.amount-box .label { font-size: 12px; color: #666; text-transform: uppercase; }');
    w.document.write('.amount-box .value { font-size: 28px; font-weight: 700; color: #1a237e; }');
    w.document.write('.footer { margin-top: 40px; border-top: 1px solid #ddd; padding-top: 12px; font-size: 11px; color: #999; text-align: center; }');
    w.document.write('.signature { display: flex; justify-content: space-between; margin-top: 50px; }');
    w.document.write('.signature div { text-align: center; width: 40%; }');
    w.document.write('.signature div .line { border-top: 1px solid #333; margin-top: 50px; padding-top: 6px; font-size: 12px; }');
    w.document.write('</style></head><body>');
    w.document.write('<div class="header"><h1>PHARMACEUTICAL WHOLESALE ERP</h1><p>Finance Department</p></div>');
    w.document.write('<div class="voucher-title">Payment Voucher</div>');
    w.document.write('<table class="info">');
    w.document.write('<tr><td>Voucher Number:</td><td>' + (id ? 'SPV-' + new Date().toISOString().slice(0,10).replace(/-/g,'') + '-' + String(id).padStart(4,'0') : reference || 'N/A') + '</td></tr>');
    w.document.write('<tr><td>Purchase Order:</td><td>' + poNumber + '</td></tr>');
    w.document.write('<tr><td>Supplier:</td><td>' + supplier + '</td></tr>');
    w.document.write('<tr><td>Payment Date:</td><td>' + date + '</td></tr>');
    w.document.write('<tr><td>Payment Method:</td><td>' + method + '</td></tr>');
    w.document.write('<tr><td>Reference:</td><td>' + (reference || 'N/A') + '</td></tr>');
    w.document.write('</table>');
    w.document.write('<div class="amount-box"><div class="label">Amount Paid</div><div class="value">' + cur + ' ' + parseFloat(amount).toFixed(2) + '</div></div>');
    w.document.write('<div class="signature">');
    w.document.write('<div><div class="line">Prepared by Finance</div></div>');
    w.document.write('<div><div class="line">Approved by Admin</div></div>');
    w.document.write('</div>');
    w.document.write('<div class="footer">This is a computer-generated payment voucher. Printed on ' + new Date().toLocaleString() + '</div>');
    w.document.write('</body></html>');
    w.document.close();
    w.print();
}

var BASE_CURRENCY = '<?= htmlspecialchars($baseCurrency, ENT_QUOTES) ?>';

function updateCurrencyUI() {
    var sel = document.getElementById('currency');
    var opt = sel.options[sel.selectedIndex];
    var code = opt.value;
    var rate = opt.getAttribute('data-rate');
    var isBase = (code === BASE_CURRENCY);
    var fxGroup = document.getElementById('exchange-rate-group');
    var basePreview = document.getElementById('base-amount-preview');
    var fxInput = document.getElementById('exchange_rate');
    var amountInput = document.getElementById('amount_paid');

    fxGroup.style.display = isBase ? 'none' : '';
    basePreview.style.display = isBase ? 'none' : '';
    if (isBase) {
        fxInput.value = '1';
        fxInput.removeAttribute('required');
    } else {
        if (!fxInput.value || fxInput.value === '1') {
            fxInput.value = rate;
        }
        fxInput.setAttribute('required', 'required');
    }
    var amount = parseFloat(amountInput.value) || 0;
    var rateVal = parseFloat(fxInput.value) || 0;
    document.getElementById('base-amount-value').textContent =
        (amount > 0 && rateVal > 0 && !isBase) ? BASE_CURRENCY + ' ' + (amount * rateVal).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}) : '0.00';
}

document.getElementById('currency').addEventListener('change', updateCurrencyUI);
document.getElementById('exchange_rate').addEventListener('input', updateCurrencyUI);
document.getElementById('amount_paid').addEventListener('input', updateCurrencyUI);
updateCurrencyUI();
</script>

<?php if (in_array($tab, ['unpaid', 'partial', 'paid'])): ?>
<script>
(function() {
    var statusMap = { unpaid: 0, partial: 1, paid: 2 };
    var current = '<?= htmlspecialchars($tab) ?>';
    var panels = document.querySelectorAll('.fin-panel');
    var tabs = document.querySelectorAll('.fin-tab a');

    tabs.forEach(function(t) {
        t.classList.remove('active');
        if (t.getAttribute('href').indexOf('tab=' + current) !== -1) {
            t.classList.add('active');
        }
    });
})();
</script>
<?php endif; ?>

</body>
</html>
