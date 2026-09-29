<?php
require_once __DIR__ . '/../includes/finance_helpers.php';
ensureFinanceErpMigration(finGetDb());
$pdo = finGetDb();
$user = currentUser();
$uid = (int) ($user['id'] ?? 0);
$username = $user['username'] ?? 'system';
$messages = [];

$tab = $_GET['tab'] ?? 'dashboard';
$subtab = $_GET['subtab'] ?? 'list';

// Admins only get the transfer approval view; full bank management is Finance-only.
$adminOnlyApprove = isAdmin();
if ($adminOnlyApprove) {
    $tab = 'transfers';
}

// ─── ACTION HANDLERS ─────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['_action'] ?? '';

    if ($adminOnlyApprove && !in_array($action, ['approve_transfer', 'reject_transfer'], true)) {
        $action = '';
        $messages[] = ['type' => 'error', 'text' => 'Bank management is restricted to Finance staff. Admins may only approve or reject transfers.'];
    }

    // ADD/EDIT ACCOUNT
    if ($action === 'add_account' || $action === 'edit_account') {
        $editId = $action === 'edit_account' ? (int)($_POST['account_id'] ?? 0) : 0;
        $bankName = trim($_POST['bank_name'] ?? '');
        $branch = trim($_POST['branch'] ?? '');
        $accountName = trim($_POST['account_name'] ?? '');
        $accountNumber = trim($_POST['account_number'] ?? '');
        $swiftCode = trim($_POST['swift_code'] ?? '');
        $currency = trim($_POST['currency'] ?? SettingsService::currencySymbol());
        $accountType = $_POST['account_type'] ?? 'current';
        $openingBalance = (float) ($_POST['opening_balance'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        if ($bankName === '' || $accountName === '') {
            $messages[] = ['type' => 'error', 'text' => 'Bank name and account name are required.'];
        } elseif ($openingBalance < 0) {
            $messages[] = ['type' => 'error', 'text' => 'Opening balance cannot be negative.'];
        } elseif ($editId) {
            $pdo->prepare('UPDATE bank_accounts SET bank_name=?, branch=?, account_name=?, account_number=?, swift_code=?, currency=?, account_type=?, notes=?, updated_at=NOW() WHERE id=?')
                ->execute([$bankName, $branch, $accountName, $accountNumber, $swiftCode, $currency, $accountType, $notes, $editId]);
            $messages[] = ['type' => 'success', 'text' => 'Account updated.'];
            bankAuditLog($pdo, $uid, $username, 'edit_account', 'bank_account', $editId, null, json_encode(['bank_name'=>$bankName,'account_name'=>$accountName]));
        } else {
            if ($accountNumber !== '') {
                $dup = $pdo->prepare('SELECT COUNT(*) FROM bank_accounts WHERE account_number = ?');
                $dup->execute([$accountNumber]);
                if ((int) $dup->fetchColumn() > 0) {
                    $messages[] = ['type' => 'error', 'text' => 'An account with that account number already exists.'];
                } else {
                    $pdo->prepare('INSERT INTO bank_accounts (bank_name, branch, account_name, account_number, swift_code, currency, account_type, opening_balance, balance, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$bankName, $branch, $accountName, $accountNumber, $swiftCode, $currency, $accountType, $openingBalance, $openingBalance, 'active', $notes]);
                    $newId = (int) $pdo->lastInsertId();
                    $messages[] = ['type' => 'success', 'text' => 'Bank account created.'];
                    bankAuditLog($pdo, $uid, $username, 'add_account', 'bank_account', $newId, null, json_encode(['bank_name'=>$bankName,'account_name'=>$accountName]));
                }
            } else {
                $pdo->prepare('INSERT INTO bank_accounts (bank_name, branch, account_name, account_number, swift_code, currency, account_type, opening_balance, balance, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$bankName, $branch, $accountName, $accountNumber, $swiftCode, $currency, $accountType, $openingBalance, $openingBalance, 'active', $notes]);
                $newId = (int) $pdo->lastInsertId();
                $messages[] = ['type' => 'success', 'text' => 'Bank account created.'];
                bankAuditLog($pdo, $uid, $username, 'add_account', 'bank_account', $newId, null, json_encode(['bank_name'=>$bankName,'account_name'=>$accountName]));
            }
        }
    }

    // DEPOSIT
    elseif ($action === 'deposit') {
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $amount = (float) ($_POST['amount'] ?? 0);
        $ref = trim($_POST['reference_no'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $account = bankGetAccountById($pdo, $accountId);
        if (!$account || $account['status'] !== 'active') {
            $messages[] = ['type' => 'error', 'text' => 'Account not found or not active.'];
        } elseif ($amount <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Amount must be positive.'];
        } else {
            if (bankRecordTransaction($pdo, $accountId, 'deposit', $amount, $ref, $desc ?: 'Deposit', null, null, $uid)) {
                $messages[] = ['type' => 'success', 'text' => 'Deposit of ' . finFmt($amount) . ' recorded.'];
                bankAuditLog($pdo, $uid, $username, 'deposit', 'bank_transaction', $accountId, null, json_encode(['amount'=>$amount,'ref'=>$ref]));
            } else {
                $messages[] = ['type' => 'error', 'text' => 'Deposit could not be recorded.'];
            }
        }
    }

    // WITHDRAWAL
    elseif ($action === 'withdraw') {
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $amount = (float) ($_POST['amount'] ?? 0);
        $ref = trim($_POST['reference_no'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $account = bankGetAccountById($pdo, $accountId);
        $allowNegative = bankGetSetting($pdo, 'allow_negative_balance', '0') === '1';
        if (!$account || $account['status'] !== 'active') {
            $messages[] = ['type' => 'error', 'text' => 'Account not found or not active.'];
        } elseif ($amount <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Amount must be positive.'];
        } elseif (!$allowNegative && (float)$account['balance'] < $amount) {
            $messages[] = ['type' => 'error', 'text' => 'Insufficient balance. Available: ' . finFmt((float)$account['balance'])];
        } else {
            if (bankRecordTransaction($pdo, $accountId, 'withdrawal', $amount, $ref, $desc ?: 'Withdrawal', null, null, $uid)) {
                $messages[] = ['type' => 'success', 'text' => 'Withdrawal of ' . finFmt($amount) . ' recorded.'];
                bankAuditLog($pdo, $uid, $username, 'withdraw', 'bank_transaction', $accountId, null, json_encode(['amount'=>$amount,'ref'=>$ref]));
            } else {
                $messages[] = ['type' => 'error', 'text' => 'Withdrawal could not be recorded.'];
            }
        }
    }

    // TRANSFER
    elseif ($action === 'create_transfer') {
        $transferType = $_POST['transfer_type'] ?? 'internal';
        $fromType = $_POST['from_account_type'] ?? 'bank';
        $toType = $_POST['to_account_type'] ?? 'bank';
        $fromId = (int) ($_POST['from_account_id'] ?? 0);
        $toId = (int) ($_POST['to_account_id'] ?? 0);
        $amount = (float) ($_POST['amount'] ?? 0);
        $desc = trim($_POST['description'] ?? '');
        $needsApproval = bankGetSetting($pdo, 'transfer_approval_required', '1') === '1';
        if ($amount <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Amount must be positive.'];
        } elseif ($fromId === $toId && $fromType === $toType) {
            $messages[] = ['type' => 'error', 'text' => 'Source and destination must be different.'];
        } else {
            $tfNo = bankGenerateTransferNo($pdo);
            $status = $needsApproval ? 'submitted' : 'approved';
            $pdo->prepare('INSERT INTO bank_transfers (transfer_no, transfer_type, from_account_type, from_account_id, to_account_type, to_account_id, amount, description, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$tfNo, $transferType, $fromType, $fromId, $toType, $toId, $amount, $desc, $status, $uid]);
            $tfId = (int) $pdo->lastInsertId();
            $msg = $status === 'submitted' ? ' created and submitted for admin approval.' : ' created and approved.';
            $messages[] = ['type' => 'success', 'text' => 'Transfer ' . $tfNo . $msg];
            bankAuditLog($pdo, $uid, $username, 'create_transfer', 'bank_transfer', $tfId, null, json_encode(['transfer_no'=>$tfNo,'amount'=>$amount,'type'=>$transferType]));
            if ($status === 'submitted') {
                try {
                    $adminIds = $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($adminIds as $adminId) {
                        NotificationService::finance($pdo, (int) $adminId, $tfId, 'Transfer Approval Required', 'Transfer ' . $tfNo . ' of ' . finFmt($amount) . ' is awaiting your approval.', 'warning');
                    }
                } catch (Throwable $e) { /* notification must never break the request */ }
            }
        }
    }

    // APPROVE TRANSFER
    elseif ($action === 'approve_transfer') {
        if (!isAdmin()) {
            $messages[] = ['type' => 'error', 'text' => 'Only admins can approve transfers.'];
        } else {
            $tfId = (int) ($_POST['transfer_id'] ?? 0);
            $tf = $pdo->prepare('SELECT * FROM bank_transfers WHERE id = ?');
            $tf->execute([$tfId]); $tf = $tf->fetch();
            if ($tf && $tf['status'] === 'submitted') {
                $pdo->prepare("UPDATE bank_transfers SET status='approved', approved_by=?, approved_at=NOW() WHERE id=?")->execute([$uid, $tfId]);
                redirect_with_flash('?page=finance_bank', 'Transfer ' . $tf['transfer_no'] . ' approved.');
                bankAuditLog($pdo, $uid, $username, 'approve_transfer', 'bank_transfer', $tfId, 'submitted', 'approved');
            } else {
                $messages[] = ['type' => 'error', 'text' => 'Transfer not found or not submittable.'];
            }
        }
    }

    // COMPLETE TRANSFER
    elseif ($action === 'complete_transfer') {
        $tfId = (int) ($_POST['transfer_id'] ?? 0);
        $tf = $pdo->prepare('SELECT * FROM bank_transfers WHERE id = ?');
        $tf->execute([$tfId]); $tf = $tf->fetch();
        if ($tf && in_array($tf['status'], ['approved','draft'])) {
            if (bankExecuteTransfer($pdo, $tfId, $uid, $username)) {
                redirect_with_flash('?page=finance_bank', 'Transfer ' . $tf['transfer_no'] . ' completed.');
            } else {
                $messages[] = ['type' => 'error', 'text' => 'Transfer ' . $tf['transfer_no'] . ' could not be completed (e.g. insufficient balance or unavailable account).'];
            }
        } else {
            $messages[] = ['type' => 'error', 'text' => 'Transfer not found or already completed.'];
        }
    }

    // REJECT TRANSFER
    elseif ($action === 'reject_transfer') {
        $tfId = (int) ($_POST['transfer_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? '');
        $tf = $pdo->prepare('SELECT * FROM bank_transfers WHERE id = ?');
        $tf->execute([$tfId]); $tf = $tf->fetch();
        if ($tf && in_array($tf['status'], ['submitted','approved'])) {
            $pdo->prepare("UPDATE bank_transfers SET status='rejected', rejected_by=?, rejected_at=NOW(), rejection_reason=? WHERE id=?")->execute([$uid, $reason, $tfId]);
            redirect_with_flash('?page=finance_bank', 'Transfer ' . $tf['transfer_no'] . ' rejected.');
            bankAuditLog($pdo, $uid, $username, 'reject_transfer', 'bank_transfer', $tfId, $tf['status'], 'rejected');
        } else {
            $messages[] = ['type' => 'error', 'text' => 'Transfer not found or cannot be rejected.'];
        }
    }

    // SUBMIT TRANSFER
    elseif ($action === 'submit_transfer') {
        $tfId = (int) ($_POST['transfer_id'] ?? 0);
        $tf = $pdo->prepare('SELECT * FROM bank_transfers WHERE id = ?');
        $tf->execute([$tfId]); $tf = $tf->fetch();
        if ($tf && $tf['status'] === 'draft') {
            $pdo->prepare("UPDATE bank_transfers SET status='submitted' WHERE id=?")->execute([$tfId]);
            redirect_with_flash('?page=finance_bank', 'Transfer ' . $tf['transfer_no'] . ' submitted for approval.');
            bankAuditLog($pdo, $uid, $username, 'submit_transfer', 'bank_transfer', $tfId, 'draft', 'submitted');
        } else {
            $messages[] = ['type' => 'error', 'text' => 'Transfer not found or not in draft status.'];
        }
    }

    // CLOSE / FREEZE / ACTIVATE ACCOUNT
    elseif (in_array($action, ['close_account','freeze_account','activate_account'])) {
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $account = bankGetAccountById($pdo, $accountId);
        if (!$account) { $messages[] = ['type' => 'error', 'text' => 'Account not found.']; }
        else {
            if ($action === 'close_account') {
                $pdo->prepare("UPDATE bank_accounts SET status='closed', closed_at=NOW(), closed_by=?, closed_reason=?, updated_at=NOW() WHERE id=?")->execute([$uid, $reason, $accountId]);
                bankAuditLog($pdo, $uid, $username, 'close_account', 'bank_account', $accountId, 'active', 'closed');
                $messages[] = ['type' => 'success', 'text' => 'Account closed.'];
            } elseif ($action === 'freeze_account') {
                $pdo->prepare("UPDATE bank_accounts SET status='frozen', frozen_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$accountId]);
                bankAuditLog($pdo, $uid, $username, 'freeze_account', 'bank_account', $accountId, 'active', 'frozen');
                $messages[] = ['type' => 'success', 'text' => 'Account frozen.'];
            } else {
                $pdo->prepare("UPDATE bank_accounts SET status='active', updated_at=NOW() WHERE id=?")->execute([$accountId]);
                bankAuditLog($pdo, $uid, $username, 'activate_account', 'bank_account', $accountId, $account['status'], 'active');
                $messages[] = ['type' => 'success', 'text' => 'Account activated.'];
            }
        }
    }

    // SET DEFAULT
    elseif ($action === 'set_default') {
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $pdo->exec('UPDATE bank_accounts SET is_default = 0');
        $pdo->prepare('UPDATE bank_accounts SET is_default = 1 WHERE id = ?')->execute([$accountId]);
        $messages[] = ['type' => 'success', 'text' => 'Default account updated.'];
        bankAuditLog($pdo, $uid, $username, 'set_default', 'bank_account', $accountId, null, (string)$accountId);
    }

    // SAVE SETTINGS
    elseif ($action === 'save_settings') {
        $keys = ['default_bank','allow_negative_balance','transfer_approval_required','auto_post_to_ledger','daily_reconciliation_reminder','monthly_closing_lock','currency_precision'];
        foreach ($keys as $k) {
            $v = $_POST[$k] ?? '0';
            bankSetSetting($pdo, $k, $v);
        }
        $messages[] = ['type' => 'success', 'text' => 'Bank settings saved.'];
        bankAuditLog($pdo, $uid, $username, 'save_settings', 'bank_settings', 0, null, json_encode($_POST));
    }

    // START RECONCILIATION
    elseif ($action === 'start_reconciliation') {
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $stmtDate = $_POST['statement_date'] ?? date('Y-m-d');
        $stmtBalance = (float) ($_POST['statement_balance'] ?? 0);
        $account = bankGetAccountById($pdo, $accountId);
        if (!$account) { $messages[] = ['type' => 'error', 'text' => 'Account not found.']; }
        else {
            $sysBal = (float) $account['balance'];
            $diff = $sysBal - $stmtBalance;
            $pdo->prepare('INSERT INTO bank_reconciliations (bank_account_id, reconciliation_date, opening_balance, system_balance, statement_balance, difference, status, created_by) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$accountId, $stmtDate, $sysBal, $sysBal, $stmtBalance, $diff, 'in_progress', $uid]);
            $recId = (int) $pdo->lastInsertId();
            $messages[] = ['type' => 'success', 'text' => 'Reconciliation started. Difference: ' . finFmt(abs($diff)) . ($diff >= 0 ? ' (system higher)' : ' (statement higher)')];
            bankAuditLog($pdo, $uid, $username, 'start_reconciliation', 'reconciliation', $recId, null, json_encode(['account'=>$accountId,'sys'=>$sysBal,'stmt'=>$stmtBalance]));
        }
    }

    // MATCH TRANSACTION (reconciliation)
    elseif ($action === 'match_transaction') {
        $recId = (int) ($_POST['rec_id'] ?? 0);
        $txnId = (int) ($_POST['transaction_id'] ?? 0);
        $pdo->prepare('INSERT INTO bank_reconciliation_items (reconciliation_id, transaction_id, match_type, is_matched) VALUES (?,?,?,?)')
            ->execute([$recId, $txnId, 'matched', 1]);
        $messages[] = ['type' => 'success', 'text' => 'Transaction matched.'];
    }

    // COMPLETE RECONCILIATION
    elseif ($action === 'complete_reconciliation') {
        $recId = (int) ($_POST['rec_id'] ?? 0);
        $pdo->prepare("UPDATE bank_reconciliations SET status='completed', completed_by=?, completed_at=NOW() WHERE id=?")->execute([$uid, $recId]);
        $messages[] = ['type' => 'success', 'text' => 'Reconciliation completed.'];
        bankAuditLog($pdo, $uid, $username, 'complete_reconciliation', 'reconciliation', $recId, 'in_progress', 'completed');
    }

    // VOID TRANSACTION
    elseif ($action === 'void_transaction') {
        $txnId = (int) ($_POST['transaction_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $txn = $pdo->prepare('SELECT * FROM bank_transactions WHERE id = ?');
        $txn->execute([$txnId]); $txn = $txn->fetch();
        if ($txn && $txn['status'] === 'completed') {
            $pdo->prepare("UPDATE bank_transactions SET status='reversed', description=CONCAT(description, ?) WHERE id=?")->execute([' [VOID: ' . $reason . ']', $txnId]);
            bankUpdateBalance($pdo, (int)$txn['bank_account_id']);
            $messages[] = ['type' => 'success', 'text' => 'Transaction voided and balance recalculated.'];
            bankAuditLog($pdo, $uid, $username, 'void_transaction', 'bank_transaction', $txnId, 'completed', 'reversed');
        } else {
            $messages[] = ['type' => 'error', 'text' => 'Transaction not found or already reversed.'];
        }
    }
}

// ─── HELPERS ──────────────────────────────────────────────────

function bankExecuteTransfer(PDO $pdo, int $tfId, int $uid, string $username): bool {
    $tf = $pdo->prepare('SELECT * FROM bank_transfers WHERE id = ?');
    $tf->execute([$tfId]); $tf = $tf->fetch();
    if (!$tf) return false;

    $amount = (float) $tf['amount'];
    $desc = $tf['description'] ?: 'Transfer: ' . ($tf['transfer_no'] ?? '');
    $ref = $tf['reference_no'] ?? $tf['transfer_no'] ?? '';
    $ownsTxn = !$pdo->inTransaction();
    if ($ownsTxn) {
        $pdo->beginTransaction();
    }
    try {
        $allowNeg = bankGetSetting($pdo, 'allow_negative_balance', '0') === '1';

        // FROM side
        if ($tf['from_account_type'] === 'bank') {
            $fStmt = $pdo->prepare('SELECT * FROM bank_accounts WHERE id = ? FOR UPDATE');
            $fStmt->execute([(int)$tf['from_account_id']]);
            $fromAcc = $fStmt->fetch();
            if (!$fromAcc || ($fromAcc['status'] !== 'active' && $fromAcc['status'] !== 'frozen')) throw new Exception('Source account not available.');
            $newFromBal = (float) $fromAcc['balance'] - $amount;
            if (!$allowNeg && $newFromBal < 0) throw new Exception('Insufficient balance in source account.');
            $pdo->prepare('INSERT INTO bank_transactions (bank_account_id, transaction_type, amount, debit, credit, balance_after, reference_no, description, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$fromAcc['id'], 'transfer_out', $amount, 0, $amount, $newFromBal, $ref, $desc, 'completed', $uid]);
            $pdo->prepare('UPDATE bank_accounts SET balance = ?, updated_at = NOW() WHERE id = ?')->execute([$newFromBal, $fromAcc['id']]);
        } elseif ($tf['from_account_type'] === 'cash') {
            $r = cashApply($pdo, (int)$tf['from_account_id'], 'transfer_out', $amount, 'Transfer', $ref, $desc, $uid);
            if (!$r['success']) throw new Exception($r['msg']);
        }

        // TO side
        if ($tf['to_account_type'] === 'bank') {
            $tStmt = $pdo->prepare('SELECT * FROM bank_accounts WHERE id = ? FOR UPDATE');
            $tStmt->execute([(int)$tf['to_account_id']]);
            $toAcc = $tStmt->fetch();
            if (!$toAcc || $toAcc['status'] !== 'active') throw new Exception('Destination account not available.');
            $newToBal = (float) $toAcc['balance'] + $amount;
            $pdo->prepare('INSERT INTO bank_transactions (bank_account_id, transaction_type, amount, debit, credit, balance_after, reference_no, description, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$toAcc['id'], 'transfer_in', $amount, $amount, 0, $newToBal, $ref, $desc, 'completed', $uid]);
            $pdo->prepare('UPDATE bank_accounts SET balance = ?, updated_at = NOW() WHERE id = ?')->execute([$newToBal, $toAcc['id']]);
        } elseif ($tf['to_account_type'] === 'cash') {
            $r = cashApply($pdo, (int)$tf['to_account_id'], 'transfer_in', $amount, 'Transfer', $ref, $desc, $uid);
            if (!$r['success']) throw new Exception($r['msg']);
        }

        $autoPost = bankGetSetting($pdo, 'auto_post_to_ledger', '1') === '1';
        $pdo->prepare('UPDATE bank_transfers SET status=?, completed_by=?, completed_at=NOW(), posted_to_ledger=? WHERE id=?')
            ->execute(['completed', $uid, $autoPost ? 1 : 0, $tfId]);
        if ($ownsTxn) {
            $pdo->commit();
        }
        bankAuditLog($pdo, $uid, $username, 'complete_transfer', 'bank_transfer', $tfId, $tf['status'], 'completed');
        return true;
    } catch (Throwable $e) {
        if ($ownsTxn && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

// ─── DATA QUERIES ─────────────────────────────────────────────

// Dashboard
$dashTotalBalance = (float) $pdo->query("SELECT COALESCE(SUM(balance),0) FROM bank_accounts WHERE status = 'active'")->fetchColumn();
$dashActiveCount = (int) $pdo->query("SELECT COUNT(*) FROM bank_accounts WHERE status = 'active'")->fetchColumn();
$dashTodaysDeposits = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM bank_transactions WHERE transaction_type IN ('deposit','customer_payment','transfer_in') AND DATE(created_at) = CURDATE() AND status = 'completed'")->fetchColumn();
$dashTodaysWithdrawals = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM bank_transactions WHERE transaction_type IN ('withdrawal','supplier_payment','expense','refund','transfer_out') AND DATE(created_at) = CURDATE() AND status = 'completed'")->fetchColumn();
$dashMonthDeposits = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM bank_transactions WHERE transaction_type IN ('deposit','customer_payment','transfer_in') AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE()) AND status='completed'")->fetchColumn();
$dashMonthWithdrawals = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM bank_transactions WHERE transaction_type IN ('withdrawal','supplier_payment','expense','refund','transfer_out') AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE()) AND status='completed'")->fetchColumn();
$dashPendingTransfers = (int) $pdo->query("SELECT COUNT(*) FROM bank_transfers WHERE status IN ('draft','submitted','approved')")->fetchColumn();
$dashPendingRecon = (int) $pdo->query("SELECT COUNT(*) FROM bank_reconciliations WHERE status IN ('in_progress','draft')")->fetchColumn();
$dashReturnedTxns = (int) $pdo->query("SELECT COUNT(*) FROM bank_transactions WHERE status = 'reversed'")->fetchColumn();
$dashLastSync = $pdo->query("SELECT MAX(updated_at) FROM bank_accounts")->fetchColumn();

// Accounts
$allAccounts = $pdo->query("SELECT * FROM bank_accounts ORDER BY is_default DESC, status ASC, bank_name ASC")->fetchAll();
$accountsJson = json_encode(array_combine(array_column($allAccounts, 'id'), array_map(function ($a) {
    return [
        'id' => (int) $a['id'],
        'bank_name' => $a['bank_name'],
        'branch' => $a['branch'] ?? '',
        'account_name' => $a['account_name'],
        'account_number' => $a['account_number'] ?? '',
        'swift_code' => $a['swift_code'] ?? '',
        'currency' => $a['currency'] ?? SettingsService::currencySymbol(),
        'account_type' => $a['account_type'] ?? 'current',
        'notes' => $a['notes'] ?? '',
    ];
}, $allAccounts)));

// Transactions
$txnPage = max(1, (int)($_GET['txn_p'] ?? 1));
$txnPerPage = 50;
$txnFilter = '';
$txnParams = [];
if (!empty($_GET['txn_from'])) { $txnFilter .= ' AND DATE(bt.created_at) >= ?'; $txnParams[] = $_GET['txn_from']; }
if (!empty($_GET['txn_to'])) { $txnFilter .= ' AND DATE(bt.created_at) <= ?'; $txnParams[] = $_GET['txn_to']; }
if (!empty($_GET['txn_account'])) { $txnFilter .= ' AND bt.bank_account_id = ?'; $txnParams[] = (int)$_GET['txn_account']; }
if (!empty($_GET['txn_type'])) { $txnFilter .= ' AND bt.transaction_type = ?'; $txnParams[] = $_GET['txn_type']; }
if (!empty($_GET['txn_status'])) { $txnFilter .= ' AND bt.status = ?'; $txnParams[] = $_GET['txn_status']; }
if (!empty($_GET['txn_user'])) { $txnFilter .= ' AND bt.created_by = ?'; $txnParams[] = (int)$_GET['txn_user']; }
$txnCountStmt = $pdo->prepare("SELECT COUNT(*) FROM bank_transactions bt LEFT JOIN bank_accounts ba ON bt.bank_account_id=ba.id WHERE 1=1 $txnFilter");
$txnCountStmt->execute($txnParams);
$txnTotal = (int) $txnCountStmt->fetchColumn();
$txnPages = max(1, ceil($txnTotal / $txnPerPage));
$txnOffset = ($txnPage - 1) * $txnPerPage;
$txnStmt = $pdo->prepare("SELECT bt.*, ba.account_name, ba.bank_name, ba.account_number FROM bank_transactions bt LEFT JOIN bank_accounts ba ON bt.bank_account_id=ba.id WHERE 1=1 $txnFilter ORDER BY bt.created_at DESC LIMIT $txnPerPage OFFSET $txnOffset");
$txnStmt->execute($txnParams);
$transactions = $txnStmt->fetchAll();

// Transfers
$tfStatus = $_GET['tf_status'] ?? '';
$tfFilter = $tfStatus ? " AND status = " . $pdo->quote($tfStatus) : '';
$transfers = $pdo->query("SELECT * FROM bank_transfers WHERE 1=1 $tfFilter ORDER BY created_at DESC LIMIT 100")->fetchAll();

// Reconciliation list
$reconList = $pdo->query("SELECT br.*, ba.bank_name, ba.account_name, ba.account_number FROM bank_reconciliations br LEFT JOIN bank_accounts ba ON br.bank_account_id=ba.id ORDER BY br.created_at DESC LIMIT 50")->fetchAll();

// Audit log
$auditLimit = 200;
$auditLog = $pdo->query("SELECT * FROM bank_audit_log ORDER BY created_at DESC LIMIT $auditLimit")->fetchAll();

// Settings
$settings = [];
$stmt = $pdo->query("SELECT setting_key, setting_value FROM bank_settings");
foreach ($stmt->fetchAll() as $row) { $settings[$row['setting_key']] = $row['setting_value']; }

// Users for filters
$usersList = $pdo->query("SELECT id, username FROM users WHERE role IN ('admin','finance') ORDER BY username")->fetchAll();

// Transaction type map
$txnTypeLabels = [
    'deposit' => 'Deposit', 'withdrawal' => 'Withdrawal', 'transfer_in' => 'Transfer In',
    'transfer_out' => 'Transfer Out', 'interest' => 'Interest', 'fee' => 'Fee',
    'adjustment' => 'Adjustment', 'customer_payment' => 'Customer Payment',
    'supplier_payment' => 'Supplier Payment', 'expense' => 'Expense', 'refund' => 'Refund'
];
$txnTypeIcons = [
    'deposit' => 'arrow-down', 'withdrawal' => 'arrow-up', 'transfer_in' => 'exchange-alt',
    'transfer_out' => 'exchange-alt', 'interest' => 'percent', 'fee' => 'hand-holding-usd',
    'adjustment' => 'sliders-h', 'customer_payment' => 'user-check',
    'supplier_payment' => 'building', 'expense' => 'receipt', 'refund' => 'undo-alt'
];
$txnTypeColors = [
    'deposit' => 'var(--fin-green)', 'withdrawal' => 'var(--fin-red)',
    'transfer_in' => 'var(--fin-blue)', 'transfer_out' => 'var(--fin-orange)',
    'interest' => 'var(--fin-purple)', 'fee' => 'var(--fin-red)',
    'adjustment' => 'var(--fin-indigo)', 'customer_payment' => 'var(--fin-green)',
    'supplier_payment' => 'var(--fin-red)', 'expense' => 'var(--fin-orange)',
    'refund' => 'var(--fin-pink)'
];

$balanceColors = ['active' => 'var(--fin-green)', 'frozen' => 'var(--fin-blue)', 'closed' => 'var(--fin-red)', 'inactive' => 'var(--fin-gray)'];
?>
<?= finCss() ?>
<style>
.bnk-wrap{max-width:1440px;margin:0 auto;padding:0 0 40px}
.bnk-wrap h1{font-size:1.4rem;font-weight:800;color:#0f172a;margin:0 0 4px;display:flex;align-items:center;gap:10px}
.bnk-subtitle{font-size:.82rem;color:var(--fin-gray);margin-bottom:20px}
.bnk-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:22px}
.bnk-stat{background:var(--fin-card);border-radius:var(--fin-radius);border:1px solid var(--fin-border);padding:16px;box-shadow:var(--fin-shadow);cursor:default}
.bnk-stat:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,.1)}
.bnk-stat-top{display:flex;align-items:center;gap:10px;margin-bottom:6px}
.bnk-stat-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:.9rem;color:#fff;flex-shrink:0}
.bnk-stat-val{font-size:1.15rem;font-weight:800;color:#0f172a;line-height:1.2}
.bnk-stat-label{font-size:.7rem;color:var(--fin-gray);font-weight:500}
.bnk-account-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px;margin-bottom:20px}
.bnk-card{background:var(--fin-card);border-radius:var(--fin-radius);border:1px solid var(--fin-border);overflow:hidden;box-shadow:var(--fin-shadow);transition:all .2s}
.bnk-card:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(0,0,0,.08)}
.bnk-card-head{padding:16px 18px 12px;display:flex;justify-content:space-between;align-items:flex-start;border-bottom:1px solid #f1f5f9}
.bnk-card-name{font-weight:700;color:#0f172a;font-size:.95rem}
.bnk-card-bank{font-size:.78rem;color:var(--fin-gray);margin-top:1px}
.bnk-card-number{font-size:.72rem;color:#94a3b8;font-family:monospace;margin-top:2px}
.bnk-card-status{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:10px;font-size:.68rem;font-weight:600}
.bnk-card-status.active{background:#dcfce7;color:#166534}
.bnk-card-status.frozen{background:#dbeafe;color:#1e40af}
.bnk-card-status.closed{background:#fee2e2;color:#991b1b}
.bnk-card-status.inactive{background:#f1f5f9;color:#64748b}
.bnk-card-body{padding:12px 18px 16px}
.bnk-card-balance-row{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px}
.bnk-card-balance-label{font-size:.72rem;color:var(--fin-gray);font-weight:500}
.bnk-card-balance{font-size:1.25rem;font-weight:800}
.bnk-card-details{display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:.78rem;margin-bottom:10px}
.bnk-card-detail span{color:var(--fin-gray)}.bnk-card-detail strong{color:#334155;font-weight:600}
.bnk-card-actions{display:flex;gap:6px;flex-wrap:wrap;padding-top:10px;border-top:1px solid #f1f5f9}
.bnk-btn{padding:6px 12px;border-radius:8px;font-size:.75rem;font-weight:600;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all .12s;font-family:inherit;text-decoration:none}
.bnk-btn:hover{opacity:.85;transform:translateY(-1px)}
.bnk-btn-sm{padding:4px 8px;font-size:.7rem;border-radius:6px}
.bnk-btn-green{background:#059669;color:#fff}.bnk-btn-blue{background:#2563eb;color:#fff}
.bnk-btn-red{background:#dc2626;color:#fff}.bnk-btn-orange{background:#d97706;color:#fff}
.bnk-btn-purple{background:#7c3aed;color:#fff}.bnk-btn-outline{background:transparent;border:1px solid #cbd5e1;color:#475569}
.bnk-btn-outline:hover{background:#f8fafc;border-color:#94a3b8}
.bnk-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:600}
.bnk-badge-green{background:#dcfce7;color:#166534}
.bnk-badge-red{background:#fee2e2;color:#991b1b}
.bnk-badge-orange{background:#ffedd5;color:#9a3412}
.bnk-badge-blue{background:#dbeafe;color:#1e40af}
.bnk-badge-purple{background:#ede9fe;color:#5b21b6}
.bnk-badge-gray{background:#f1f5f9;color:#64748b}
.bnk-badge-teal{background:#ccfbf1;color:#115e59}
.bnk-txn-icon{width:32px;height:32px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;font-size:.75rem;color:#fff;flex-shrink:0}
.bnk-txn-table .bnk-txn-row{display:flex;align-items:center;gap:12px;padding:10px 14px;border-bottom:1px solid #f1f5f9;transition:background .1s}
.bnk-txn-table .bnk-txn-row:hover{background:#f8fafc}
.bnk-txn-table .bnk-txn-row:last-child{border-bottom:none}
.bnk-txn-info{flex:1;min-width:0}
.bnk-txn-type{font-weight:600;font-size:.82rem;color:#0f172a}
.bnk-txn-meta{font-size:.72rem;color:var(--fin-gray);display:flex;gap:10px;flex-wrap:wrap}
.bnk-txn-amount{font-weight:700;font-size:.9rem;text-align:right;white-space:nowrap}
.bnk-txn-debit{color:var(--fin-green)}.bnk-txn-credit{color:var(--fin-red)}
.bnk-filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:14px 16px;background:#f8fafc;border-radius:10px;margin-bottom:16px;border:1px solid #e2e8f0}
.bnk-filters select,.bnk-filters input[type=date]{padding:7px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:.8rem;background:#fff;font-family:inherit}
.bnk-pagination{display:flex;gap:6px;justify-content:center;align-items:center;margin-top:14px;font-size:.8rem}
.bnk-pagination a,.bnk-pagination span{padding:5px 10px;border-radius:6px;border:1px solid #e2e8f0;text-decoration:none;color:#475569;font-weight:500}
.bnk-pagination a:hover{background:#f1f5f9;border-color:#94a3b8}
.bnk-pagination .active{background:var(--fin-blue);color:#fff;border-color:var(--fin-blue)}
.bnk-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:16px}
.bnk-summary-item{background:#f8fafc;border-radius:10px;padding:14px;text-align:center}
.bnk-summary-val{font-size:1.2rem;font-weight:800;color:#0f172a}
.bnk-summary-label{font-size:.72rem;color:var(--fin-gray);margin-top:2px}
.bnk-empty{text-align:center;padding:40px;color:#94a3b8}
.bnk-empty i{font-size:2rem;color:#cbd5e1;display:block;margin-bottom:8px}
.bnk-msg{display:flex;align-items:center;gap:10px;padding:12px 18px;border-radius:10px;margin-bottom:16px;font-size:.85rem;font-weight:600}
.bnk-msg.success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}
.bnk-msg.error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.bnk-msg.info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}
.bnk-section-title{font-size:.78rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.04em;margin-bottom:12px;display:flex;align-items:center;gap:6px}
.bnk-tf-workflow{display:flex;gap:0;align-items:center;margin-bottom:12px;padding:8px 0}
.bnk-tf-step{display:flex;align-items:center;gap:6px;font-size:.72rem;font-weight:600;padding:4px 10px;border-radius:12px}
.bnk-tf-step.done{background:#dcfce7;color:#166534}
.bnk-tf-step.active{background:#dbeafe;color:#1e40af}
.bnk-tf-step.pending{background:#f1f5f9;color:#94a3b8}
.bnk-tf-step.rejected{background:#fee2e2;color:#991b1b}
.bnk-tf-arrow{color:#cbd5e1;font-size:.7rem;margin:0 2px}
@media(max-width:1200px){.bnk-stats{grid-template-columns:repeat(3,1fr)}}
@media(max-width:768px){.bnk-stats{grid-template-columns:repeat(2,1fr)}.bnk-account-grid{grid-template-columns:1fr}}
@media(max-width:480px){.bnk-stats{grid-template-columns:1fr}}
</style>

<section class="bnk-wrap">
<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:4px">
    <div>
        <h1><i class="fas fa-university" style="color:var(--fin-blue)"></i> Bank Management</h1>
        <div class="bnk-subtitle">Professional banking system — managed by Finance &amp; Administration</div>
    </div>
</div>

<?php foreach ($messages as $m): ?>
<div class="bnk-msg <?= $m['type'] ?>"><i class="fas fa-<?= $m['type']==='success'?'check-circle':'exclamation-circle' ?>"></i> <?= htmlspecialchars($m['text']) ?></div>
<?php endforeach; ?>

<div class="fin-tabs">
    <?php if ($adminOnlyApprove): ?>
    <a href="?page=finance_bank&tab=transfers" class="fin-tab active"><i class="fas fa-arrow-right-arrow-left"></i> Transfer Approvals</a>
    <?php else: ?>
    <a href="?page=finance_bank&tab=dashboard" class="fin-tab <?= $tab==='dashboard'?'active':'' ?>"><i class="fas fa-chart-pie"></i> Dashboard</a>
    <a href="?page=finance_bank&tab=accounts" class="fin-tab <?= $tab==='accounts'?'active':'' ?>"><i class="fas fa-piggy-bank"></i> Accounts</a>
    <a href="?page=finance_bank&tab=transactions" class="fin-tab <?= $tab==='transactions'?'active':'' ?>"><i class="fas fa-exchange-alt"></i> Transactions</a>
    <a href="?page=finance_bank&tab=transfers" class="fin-tab <?= $tab==='transfers'?'active':'' ?>"><i class="fas fa-arrow-right-arrow-left"></i> Transfers</a>
    <a href="?page=finance_bank&tab=reconciliation" class="fin-tab <?= $tab==='reconciliation'?'active':'' ?>"><i class="fas fa-balance-scale"></i> Reconciliation</a>
    <a href="?page=finance_bank&tab=settings" class="fin-tab <?= $tab==='settings'?'active':'' ?>"><i class="fas fa-cog"></i> Settings</a>
    <a href="?page=finance_bank&tab=reports" class="fin-tab <?= $tab==='reports'?'active':'' ?>"><i class="fas fa-file-alt"></i> Reports</a>
    <a href="?page=finance_bank&tab=audit" class="fin-tab <?= $tab==='audit'?'active':'' ?>"><i class="fas fa-history"></i> Audit Log</a>
    <?php endif; ?>
</div>

<?php
// ═══════════════════════════════════════════════════════════════
// TAB: DASHBOARD
// ═══════════════════════════════════════════════════════════════
if ($tab === 'dashboard'):
?>
<div class="bnk-stats">
    <div class="bnk-stat">
        <div class="bnk-stat-top"><div class="bnk-stat-icon" style="background:linear-gradient(135deg,#2563eb,#1d4ed8)"><i class="fas fa-university"></i></div></div>
        <div class="bnk-stat-val" data-counter="<?= $dashTotalBalance ?>" data-prefix="<?= currency_sym() ?> " data-decimals="2"><?= currency_sym() ?> 0.00</div>
        <div class="bnk-stat-label">Total Bank Balance</div>
    </div>
    <div class="bnk-stat">
        <div class="bnk-stat-top"><div class="bnk-stat-icon" style="background:linear-gradient(135deg,#059669,#047857)"><i class="fas fa-piggy-bank"></i></div></div>
        <div class="bnk-stat-val" data-counter="<?= $dashActiveCount ?>">0</div>
        <div class="bnk-stat-label">Active Bank Accounts</div>
    </div>
    <div class="bnk-stat">
        <div class="bnk-stat-top"><div class="bnk-stat-icon" style="background:linear-gradient(135deg,#d97706,#b45309)"><i class="fas fa-arrow-down"></i></div></div>
        <div class="bnk-stat-val" data-counter="<?= $dashTodaysDeposits ?>" data-prefix="<?= currency_sym() ?> " data-decimals="2"><?= currency_sym() ?> 0.00</div>
        <div class="bnk-stat-label">Today's Deposits / Inflow</div>
    </div>
    <div class="bnk-stat">
        <div class="bnk-stat-top"><div class="bnk-stat-icon" style="background:linear-gradient(135deg,#dc2626,#b91c1c)"><i class="fas fa-arrow-up"></i></div></div>
        <div class="bnk-stat-val" data-counter="<?= $dashTodaysWithdrawals ?>" data-prefix="<?= currency_sym() ?> " data-decimals="2"><?= currency_sym() ?> 0.00</div>
        <div class="bnk-stat-label">Today's Withdrawals / Outflow</div>
    </div>
    <div class="bnk-stat">
        <div class="bnk-stat-top"><div class="bnk-stat-icon" style="background:linear-gradient(135deg,#7c3aed,#6d28d9)"><i class="fas fa-calendar-alt"></i></div></div>
        <div class="bnk-stat-val" data-counter="<?= $dashMonthDeposits ?>" data-prefix="<?= currency_sym() ?> " data-decimals="2"><?= currency_sym() ?> 0.00</div>
        <div class="bnk-stat-label">This Month Deposits</div>
    </div>
    <div class="bnk-stat">
        <div class="bnk-stat-top"><div class="bnk-stat-icon" style="background:linear-gradient(135deg,#ea580c,#c2410c)"><i class="fas fa-calendar-times"></i></div></div>
        <div class="bnk-stat-val" data-counter="<?= $dashMonthWithdrawals ?>" data-prefix="<?= currency_sym() ?> " data-decimals="2"><?= currency_sym() ?> 0.00</div>
        <div class="bnk-stat-label">This Month Withdrawals</div>
    </div>
    <div class="bnk-stat">
        <div class="bnk-stat-top"><div class="bnk-stat-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706)"><i class="fas fa-clock"></i></div></div>
        <div class="bnk-stat-val" data-counter="<?= $dashPendingTransfers ?>">0</div>
        <div class="bnk-stat-label">Pending Transfers</div>
    </div>
    <div class="bnk-stat">
        <div class="bnk-stat-top"><div class="bnk-stat-icon" style="background:linear-gradient(135deg,#0891b2,#0e7490)"><i class="fas fa-balance-scale"></i></div></div>
        <div class="bnk-stat-val" data-counter="<?= $dashPendingRecon ?>">0</div>
        <div class="bnk-stat-label">Pending Reconciliation</div>
    </div>
    <div class="bnk-stat">
        <div class="bnk-stat-top"><div class="bnk-stat-icon" style="background:linear-gradient(135deg,#db2777,#be185d)"><i class="fas fa-exclamation-triangle"></i></div></div>
        <div class="bnk-stat-val" data-counter="<?= $dashReturnedTxns ?>">0</div>
        <div class="bnk-stat-label">Returned / Voided Transactions</div>
    </div>
    <div class="bnk-stat">
        <div class="bnk-stat-top"><div class="bnk-stat-icon" style="background:linear-gradient(135deg,#4f46e5,#4338ca)"><i class="fas fa-sync-alt"></i></div></div>
        <div class="bnk-stat-val" style="font-size:.9rem"><?= $dashLastSync ? htmlspecialchars(date('M d, H:i', strtotime($dashLastSync))) : 'Never' ?></div>
        <div class="bnk-stat-label">Last Bank Sync</div>
    </div>
</div>

<div class="fin-quick-actions">
    <a href="?page=finance_bank&tab=accounts#add" class="fin-qa" onclick="event.preventDefault();openAddAccount()"><i style="background:var(--fin-blue)"><i class="fas fa-plus"></i></i> Add Account</a>
    <a href="?page=finance_bank&tab=transactions" class="fin-qa"><i style="background:var(--fin-green)"><i class="fas fa-list"></i></i> View Transactions</a>
    <a href="?page=finance_bank&tab=transfers" class="fin-qa"><i style="background:var(--fin-purple)"><i class="fas fa-exchange-alt"></i></i> New Transfer</a>
    <a href="?page=finance_bank&tab=reconciliation" class="fin-qa"><i style="background:var(--fin-teal)"><i class="fas fa-balance-scale"></i></i> Reconciliation</a>
    <a href="?page=finance_reports&tab=bank" class="fin-qa"><i style="background:var(--fin-indigo)"><i class="fas fa-file-alt"></i></i> Generate Report</a>
</div>

<?php
// ═══════════════════════════════════════════════════════════════
// TAB: ACCOUNTS
// ═══════════════════════════════════════════════════════════════
elseif ($tab === 'accounts'):
?>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px">
    <div class="bnk-section-title"><i class="fas fa-piggy-bank"></i> Bank Accounts</div>
    <button class="bnk-btn bnk-btn-green" onclick="openAddAccount()"><i class="fas fa-plus"></i> Add Account</button>
</div>

<?php if (empty($allAccounts)): ?>
<div class="bnk-empty"><i class="fas fa-piggy-bank"></i>No bank accounts yet. Click "Add Account" to create one.</div>
<?php else: ?>
<div class="bnk-account-grid">
<?php foreach ($allAccounts as $a): ?>
    <div class="bnk-card">
        <div class="bnk-card-head">
            <div>
                <div class="bnk-card-name"><?= htmlspecialchars($a['account_name']) ?> <?php if ((int)$a['is_default']): ?><span class="bnk-badge bnk-badge-teal" style="font-size:.65rem">Default</span><?php endif; ?></div>
                <div class="bnk-card-bank"><?= htmlspecialchars($a['bank_name']) ?></div>
                <div class="bnk-card-number"><?= htmlspecialchars($a['account_number'] ?? '—') ?></div>
            </div>
            <div>
                <span class="bnk-card-status <?= $a['status'] ?>"><?= ucfirst($a['status']) ?></span>
            </div>
        </div>
        <div class="bnk-card-body">
            <div class="bnk-card-balance-row">
                <span class="bnk-card-balance-label">Current Balance</span>
                <span class="bnk-card-balance" style="color:<?= (float)$a['balance'] >= 0 ? 'var(--fin-green)' : 'var(--fin-red)' ?>"><?= finFmt((float)$a['balance']) ?></span>
            </div>
            <div class="bnk-card-details">
                <div class="bnk-card-detail"><span>Branch</span><br><strong><?= htmlspecialchars($a['branch'] ?? '—') ?></strong></div>
                <div class="bnk-card-detail"><span>SWIFT</span><br><strong><?= htmlspecialchars($a['swift_code'] ?? '—') ?></strong></div>
                <div class="bnk-card-detail"><span>Type</span><br><strong><?= ucfirst($a['account_type'] ?? 'current') ?></strong></div>
                <div class="bnk-card-detail"><span>Currency</span><br><strong><?= htmlspecialchars($a['currency'] ?? SettingsService::currencySymbol()) ?></strong></div>
                <div class="bnk-card-detail"><span>Opening</span><br><strong><?= finFmt((float)$a['opening_balance']) ?></strong></div>
                <div class="bnk-card-detail"><span>Created</span><br><strong><?= date('M d, Y', strtotime($a['created_at'])) ?></strong></div>
            </div>
            <div class="bnk-card-actions">
                <button class="bnk-btn bnk-btn-blue bnk-btn-sm" onclick="editAccount(<?= $a['id'] ?>)"><i class="fas fa-edit"></i> Edit</button>
                <?php if ($a['status'] === 'active'): ?>
                    <button class="bnk-btn bnk-btn-orange bnk-btn-sm" onclick="freezeAccount(<?= $a['id'] ?>)"><i class="fas fa-snowflake"></i> Freeze</button>
                    <button class="bnk-btn bnk-btn-red bnk-btn-sm" onclick="closeAccount(<?= $a['id'] ?>)"><i class="fas fa-times-circle"></i> Close</button>
                <?php elseif ($a['status'] === 'frozen'): ?>
                    <button class="bnk-btn bnk-btn-green bnk-btn-sm" onclick="activateAccount(<?= $a['id'] ?>)"><i class="fas fa-play"></i> Activate</button>
                <?php elseif ($a['status'] === 'closed' || $a['status'] === 'inactive'): ?>
                    <button class="bnk-btn bnk-btn-green bnk-btn-sm" onclick="activateAccount(<?= $a['id'] ?>)"><i class="fas fa-play"></i> Activate</button>
                <?php endif; ?>
                <?php if (!$a['is_default']): ?>
                    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="_action" value="set_default"><input type="hidden" name="account_id" value="<?= $a['id'] ?>"><button type="submit" class="bnk-btn bnk-btn-outline bnk-btn-sm"><i class="fas fa-star"></i> Set Default</button></form>
                <?php endif; ?>
                <a href="?page=finance_bank&tab=transactions&txn_account=<?= $a['id'] ?>" class="bnk-btn bnk-btn-outline bnk-btn-sm"><i class="fas fa-list"></i> Transactions</a>
                <a href="?page=finance_reports&tab=bank&account=<?= $a['id'] ?>" class="bnk-btn bnk-btn-outline bnk-btn-sm"><i class="fas fa-print"></i> Statement</a>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php
// ═══════════════════════════════════════════════════════════════
// TAB: TRANSACTIONS
// ═══════════════════════════════════════════════════════════════
elseif ($tab === 'transactions'):
$activeTxnAcc = $_GET['txn_account'] ?? '';
$activeTxnType = $_GET['txn_type'] ?? '';
$activeTxnStatus = $_GET['txn_status'] ?? '';
$activeTxnUser = $_GET['txn_user'] ?? '';
$activeTxnFrom = $_GET['txn_from'] ?? '';
$activeTxnTo = $_GET['txn_to'] ?? '';
?>
<div class="bnk-section-title"><i class="fas fa-exchange-alt"></i> Transaction History</div>

<form method="get" class="bnk-filters">
    <input type="hidden" name="page" value="finance_bank">
    <input type="hidden" name="tab" value="transactions">
    <label style="font-size:.72rem;font-weight:600;color:#475569">From <input type="date" name="txn_from" value="<?= htmlspecialchars($activeTxnFrom) ?>"></label>
    <label style="font-size:.72rem;font-weight:600;color:#475569">To <input type="date" name="txn_to" value="<?= htmlspecialchars($activeTxnTo) ?>"></label>
    <select name="txn_account"><option value="">All Accounts</option>
        <?php foreach (bankGetAllAccounts($pdo) as $ba): ?>
        <option value="<?= $ba['id'] ?>" <?= $activeTxnAcc==(string)$ba['id']?'selected':'' ?>><?= htmlspecialchars($ba['bank_name'].' - '.$ba['account_name']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="txn_type"><option value="">All Types</option>
        <?php foreach ($txnTypeLabels as $k => $l): ?>
        <option value="<?= $k ?>" <?= $activeTxnType===$k?'selected':'' ?>><?= $l ?></option>
        <?php endforeach; ?>
    </select>
    <select name="txn_status"><option value="">All Status</option>
        <option value="completed" <?= $activeTxnStatus==='completed'?'selected':'' ?>>Completed</option>
        <option value="pending" <?= $activeTxnStatus==='pending'?'selected':'' ?>>Pending</option>
        <option value="reversed" <?= $activeTxnStatus==='reversed'?'selected':'' ?>>Reversed</option>
        <option value="pending_reconcile" <?= $activeTxnStatus==='pending_reconcile'?'selected':'' ?>>Pending Reconcile</option>
    </select>
    <select name="txn_user"><option value="">All Users</option>
        <?php foreach ($usersList as $u): ?>
        <option value="<?= $u['id'] ?>" <?= $activeTxnUser==(string)$u['id']?'selected':'' ?>><?= htmlspecialchars($u['username']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="bnk-btn bnk-btn-blue"><i class="fas fa-filter"></i> Filter</button>
    <a href="?page=finance_bank&tab=transactions" class="bnk-btn bnk-btn-outline"><i class="fas fa-times"></i> Clear</a>
</form>

<div class="fin-card" style="padding:0;overflow:hidden">
<?php if (empty($transactions)): ?>
<div class="bnk-empty"><i class="fas fa-exchange-alt"></i>No transactions found.</div>
<?php else: ?>
<div class="bnk-txn-table">
    <?php foreach ($transactions as $t): ?>
    <?php
        $tt = $t['transaction_type'];
        $label = $txnTypeLabels[$tt] ?? ucfirst($tt);
        $icon = $txnTypeIcons[$tt] ?? 'exchange-alt';
        $color = $txnTypeColors[$tt] ?? 'var(--fin-gray)';
        $isDebit = (float)$t['debit'] > 0;
    ?>
    <div class="bnk-txn-row">
        <div class="bnk-txn-icon" style="background:<?= $color ?>"><i class="fas fa-<?= $icon ?>"></i></div>
        <div class="bnk-txn-info">
            <div class="bnk-txn-type"><?= $label ?></div>
            <div class="bnk-txn-meta">
                <span><i class="far fa-calendar-alt"></i> <?= date('M d, Y H:i', strtotime($t['created_at'])) ?></span>
                <span><i class="fas fa-university"></i> <?= htmlspecialchars($t['bank_name'] ?? '') ?> <?= htmlspecialchars($t['account_name'] ?? '') ?></span>
                <?php if ($t['reference_no']): ?><span><i class="fas fa-hashtag"></i> <?= htmlspecialchars($t['reference_no']) ?></span><?php endif; ?>
                <?php if ($t['description']): ?><span><i class="fas fa-comment"></i> <?= htmlspecialchars($t['description']) ?></span><?php endif; ?>
                <span class="bnk-badge <?= $t['status']==='completed'?'bnk-badge-green':($t['status']==='reversed'?'bnk-badge-red':'bnk-badge-orange') ?>"><?= ucfirst($t['status']) ?></span>
            </div>
        </div>
        <div style="text-align:right;min-width:160px">
            <?php if ((float)$t['debit'] > 0): ?>
            <div class="bnk-txn-amount bnk-txn-debit">+<?= finFmt((float)$t['debit']) ?></div>
            <?php else: ?>
            <div class="bnk-txn-amount bnk-txn-credit">-<?= finFmt((float)$t['credit'] ?: (float)$t['amount']) ?></div>
            <?php endif; ?>
            <div style="font-size:.68rem;color:#94a3b8">Bal: <?= finFmt((float)$t['balance_after']) ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php if ($txnPages > 1): ?>
<div class="bnk-pagination">
    <?php for ($p = 1; $p <= $txnPages; $p++): ?>
        <?php if ($p === $txnPage): ?>
            <span class="active"><?= $p ?></span>
        <?php else: ?>
            <a href="?page=finance_bank&tab=transactions&txn_p=<?= $p ?><?= $activeTxnAcc?'&txn_account='.$activeTxnAcc:'' ?><?= $activeTxnType?'&txn_type='.$activeTxnType:'' ?><?= $activeTxnStatus?'&txn_status='.$activeTxnStatus:'' ?><?= $activeTxnFrom?'&txn_from='.$activeTxnFrom:'' ?><?= $activeTxnTo?'&txn_to='.$activeTxnTo:'' ?>"><?= $p ?></a>
        <?php endif; ?>
    <?php endfor; ?>
</div>
<?php endif; ?>
<?php endif; ?>
</div>

<?php
// ═══════════════════════════════════════════════════════════════
// TAB: TRANSFERS
// ═══════════════════════════════════════════════════════════════
elseif ($tab === 'transfers'):
$bankAccounts = $pdo->query("SELECT id, bank_name, account_name, account_number, balance, currency, status FROM bank_accounts WHERE status IN ('active','frozen') ORDER BY bank_name")->fetchAll();
$cashAccounts = $pdo->query("SELECT id, account_name, account_type, balance FROM cash_accounts WHERE status = 'active' ORDER BY account_name")->fetchAll();
$tfStatusFilter = $_GET['tf_status'] ?? '';
$needsApproval = bankGetSetting($pdo, 'transfer_approval_required', '1') === '1';
$bankLookup = [];
foreach ($bankAccounts as $a) { $bankLookup[(int)$a['id']] = $a; }
$cashLookup = [];
foreach ($cashAccounts as $a) { $cashLookup[(int)$a['id']] = $a; }
$tfAccountLabel = function ($tf, $side) use ($bankLookup, $cashLookup) {
    $type = $tf[$side . '_account_type'];
    $id = (int) $tf[$side . '_account_id'];
    if ($type === 'bank' && isset($bankLookup[$id])) {
        $a = $bankLookup[$id];
        return htmlspecialchars($a['bank_name'] . ' - ' . $a['account_name']) . '<br><small style="color:#94a3b8">Balance: ' . finFmt((float)$a['balance']) . '</small>';
    }
    if ($type === 'cash' && isset($cashLookup[$id])) {
        $a = $cashLookup[$id];
        return htmlspecialchars($a['account_name']) . ' <small>(Cash)</small><br><small style="color:#94a3b8">Balance: ' . finFmt((float)$a['balance']) . '</small>';
    }
    return strtoupper($type) . ' #' . $id;
};
?>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px">
    <div class="bnk-section-title"><i class="fas fa-arrow-right-arrow-left"></i> Transfer Management</div>
    <?php if (!$adminOnlyApprove): ?><button class="bnk-btn bnk-btn-green" onclick="document.getElementById('modal-new-transfer').classList.add('show')"><i class="fas fa-plus"></i> New Transfer</button><?php endif; ?>
</div>

<?php if ($adminOnlyApprove): ?>
<div class="bnk-section-title" style="margin-top:8px"><i class="fas fa-piggy-bank"></i> Current Balances</div>
<div class="bnk-summary-grid">
    <?php foreach ($bankAccounts as $a): ?>
    <div class="bnk-summary-item">
        <div class="bnk-summary-val"><?= finFmt((float)$a['balance']) ?></div>
        <div class="bnk-summary-label"><?= htmlspecialchars($a['bank_name'] . ' - ' . $a['account_name']) ?></div>
    </div>
    <?php endforeach; ?>
    <?php foreach ($cashAccounts as $a): ?>
    <div class="bnk-summary-item">
        <div class="bnk-summary-val"><?= finFmt((float)$a['balance']) ?></div>
        <div class="bnk-summary-label"><?= htmlspecialchars($a['account_name']) ?> (Cash)</div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<form method="get" style="display:inline-flex;gap:8px;align-items:center;margin-bottom:16px">
    <input type="hidden" name="page" value="finance_bank">
    <input type="hidden" name="tab" value="transfers">
    <select name="tf_status" onchange="this.form.submit()">
        <option value="">All Status</option>
        <option value="draft" <?= $tfStatusFilter==='draft'?'selected':'' ?>>Draft</option>
        <option value="submitted" <?= $tfStatusFilter==='submitted'?'selected':'' ?>>Submitted</option>
        <option value="approved" <?= $tfStatusFilter==='approved'?'selected':'' ?>>Approved</option>
        <option value="completed" <?= $tfStatusFilter==='completed'?'selected':'' ?>>Completed</option>
        <option value="rejected" <?= $tfStatusFilter==='rejected'?'selected':'' ?>>Rejected</option>
    </select>
    <noscript><button type="submit" class="bnk-btn bnk-btn-blue bnk-btn-sm">Filter</button></noscript>
</form>

<?php if (empty($transfers)): ?>
<div class="bnk-empty"><i class="fas fa-exchange-alt"></i>No transfers found.</div>
<?php else: ?>
<div class="fin-card" style="padding:0;overflow:hidden">
<table class="fin-table">
    <thead><tr><th>Transfer #</th><th>Type</th><th>From</th><th>To</th><th>Amount</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($transfers as $tf): ?>
    <tr>
        <td><strong><?= htmlspecialchars($tf['transfer_no']) ?></strong></td>
        <td><?= str_replace('_',' ',ucfirst($tf['transfer_type'])) ?></td>
        <td style="font-size:.8rem">
            <?= $tfAccountLabel($tf, 'from') ?>
        </td>
        <td style="font-size:.8rem">
            <?= $tfAccountLabel($tf, 'to') ?>
        </td>
        <td style="font-weight:700"><?= finFmt((float)$tf['amount']) ?></td>
        <td><span class="bnk-badge <?= $tf['status']==='completed'?'bnk-badge-green':($tf['status']==='rejected'?'bnk-badge-red':($tf['status']==='draft'?'bnk-badge-gray':($tf['status']==='approved'?'bnk-badge-blue':'bnk-badge-orange'))) ?>"><?= ucfirst($tf['status']) ?></span></td>
        <td style="font-size:.78rem"><?= date('M d, Y', strtotime($tf['created_at'])) ?></td>
        <td>
            <?php if ($tf['status'] === 'draft'): ?>
                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="_action" value="submit_transfer"><input type="hidden" name="transfer_id" value="<?= $tf['id'] ?>"><button type="submit" class="bnk-btn bnk-btn-blue bnk-btn-sm"><i class="fas fa-paper-plane"></i> Submit</button></form>
                <?php if (!$needsApproval): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Complete this transfer directly?')"><?= csrf_field() ?><input type="hidden" name="_action" value="complete_transfer"><input type="hidden" name="transfer_id" value="<?= $tf['id'] ?>"><button type="submit" class="bnk-btn bnk-btn-green bnk-btn-sm"><i class="fas fa-check"></i> Complete</button></form>
                <?php endif; ?>
            <?php elseif ($tf['status'] === 'submitted'): ?>
                <?php if (isAdmin()): ?>
                    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="_action" value="approve_transfer"><input type="hidden" name="transfer_id" value="<?= $tf['id'] ?>"><button type="submit" class="bnk-btn bnk-btn-green bnk-btn-sm"><i class="fas fa-thumbs-up"></i> Approve</button></form>
                    <button class="bnk-btn bnk-btn-red bnk-btn-sm" onclick="rejectTransfer(<?= $tf['id'] ?>)"><i class="fas fa-times"></i> Reject</button>
                <?php else: ?>
                    <span class="bnk-badge bnk-badge-orange"><i class="fas fa-hourglass-half"></i> Awaiting admin approval</span>
                <?php endif; ?>
            <?php elseif ($tf['status'] === 'approved'): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Execute this transfer? This will move funds.')"><?= csrf_field() ?><input type="hidden" name="_action" value="complete_transfer"><input type="hidden" name="transfer_id" value="<?= $tf['id'] ?>"><button type="submit" class="bnk-btn bnk-btn-green bnk-btn-sm"><i class="fas fa-check-double"></i> Complete</button></form>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php
// ═══════════════════════════════════════════════════════════════
// TAB: RECONCILIATION
// ═══════════════════════════════════════════════════════════════
elseif ($tab === 'reconciliation'):
$reconActive = $_GET['recon_id'] ?? 0;
$activeRecon = null;
$reconItems = [];
$unmatchedTxns = [];
if ($reconActive) {
    $stmt = $pdo->prepare('SELECT br.*, ba.bank_name, ba.account_name, ba.account_number FROM bank_reconciliations br LEFT JOIN bank_accounts ba ON br.bank_account_id=ba.id WHERE br.id=?');
    $stmt->execute([$reconActive]);
    $activeRecon = $stmt->fetch();
    if ($activeRecon) {
        $reconItems = $pdo->prepare('SELECT * FROM bank_reconciliation_items WHERE reconciliation_id=?');
        $reconItems->execute([$reconActive]);
        $reconItems = $reconItems->fetchAll();
        $unmatched = $pdo->prepare("SELECT bt.*, ba.account_name, ba.bank_name FROM bank_transactions bt LEFT JOIN bank_accounts ba ON bt.bank_account_id=ba.id WHERE bt.bank_account_id=? AND bt.status='completed' AND bt.id NOT IN (SELECT transaction_id FROM bank_reconciliation_items WHERE reconciliation_id=? AND transaction_id IS NOT NULL) ORDER BY bt.created_at DESC");
        $unmatched->execute([$activeRecon['bank_account_id'], $reconActive]);
        $unmatchedTxns = $unmatched->fetchAll();
    }
}
?>
<div class="bnk-section-title"><i class="fas fa-balance-scale"></i> Bank Reconciliation</div>

<div class="fin-grid-2" style="margin-bottom:16px">
    <div class="fin-card">
        <h2><i class="fas fa-plus-circle" style="color:var(--fin-green)"></i> Start New Reconciliation</h2>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="_action" value="start_reconciliation">
            <div class="fin-form-group"><label>Bank Account</label>
                <select name="account_id" required>
                    <option value="">Select account...</option>
                    <?php foreach (bankGetAccounts($pdo) as $ba): ?>
                    <option value="<?= $ba['id'] ?>"><?= htmlspecialchars($ba['bank_name'].' - '.$ba['account_name'].' ('.finFmt((float)$ba['balance']).')') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fin-form-group"><label>Statement Date</label><input type="date" name="statement_date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="fin-form-group"><label>Statement Ending Balance (<?= currency_sym() ?>)</label><input type="number" step="0.01" name="statement_balance" required placeholder="0.00"></div>
            <button type="submit" class="bnk-btn bnk-btn-blue"><i class="fas fa-play"></i> Start Reconciliation</button>
        </form>
    </div>
    <div class="fin-card">
        <h2><i class="fas fa-history" style="color:var(--fin-blue)"></i> Previous Reconciliations</h2>
        <?php if (empty($reconList)): ?>
        <div class="bnk-empty" style="padding:15px"><i class="fas fa-inbox"></i>No reconciliations yet.</div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="fin-table">
            <thead><tr><th>Account</th><th>Date</th><th>System</th><th>Statement</th><th>Diff</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($reconList as $r): ?>
            <tr>
                <td style="font-size:.78rem"><?= htmlspecialchars($r['bank_name']??'') ?></td>
                <td style="font-size:.78rem"><?= htmlspecialchars($r['reconciliation_date']) ?></td>
                <td><?= finFmt((float)$r['system_balance']) ?></td>
                <td><?= finFmt((float)$r['statement_balance']) ?></td>
                <td style="font-weight:700;color:<?= (float)$r['difference']==0?'var(--fin-green)':'var(--fin-red)' ?>"><?= finFmt((float)$r['difference']) ?></td>
                <td><span class="bnk-badge <?= $r['status']==='completed'?'bnk-badge-green':($r['status']==='verified'?'bnk-badge-blue':'bnk-badge-orange') ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span></td>
                <td><a href="?page=finance_bank&tab=reconciliation&recon_id=<?= $r['id'] ?>" class="bnk-btn bnk-btn-outline bnk-btn-sm"><i class="fas fa-eye"></i> View</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($activeRecon): ?>
<div class="fin-card">
    <h2><i class="fas fa-balance-scale" style="color:var(--fin-teal)"></i> Active Reconciliation — <?= htmlspecialchars($activeRecon['bank_name']??'') ?> (<?= htmlspecialchars($activeRecon['account_name']??'') ?>)</h2>
    <div class="bnk-summary-grid">
        <div class="bnk-summary-item"><div class="bnk-summary-val"><?= finFmt((float)$activeRecon['system_balance']) ?></div><div class="bnk-summary-label">System Balance</div></div>
        <div class="bnk-summary-item"><div class="bnk-summary-val"><?= finFmt((float)$activeRecon['statement_balance']) ?></div><div class="bnk-summary-label">Statement Balance</div></div>
        <div class="bnk-summary-item"><div class="bnk-summary-val" style="color:<?= (float)$activeRecon['difference']==0?'var(--fin-green)':'var(--fin-red)' ?>"><?= finFmt((float)$activeRecon['difference']) ?></div><div class="bnk-summary-label">Difference</div></div>
        <div class="bnk-summary-item"><div class="bnk-summary-val"><?= count($reconItems) ?></div><div class="bnk-summary-label">Matched Transactions</div></div>
        <div class="bnk-summary-item"><div class="bnk-summary-val"><?= count($unmatchedTxns) ?></div><div class="bnk-summary-label">Unmatched Transactions</div></div>
    </div>

    <?php if (!empty($unmatchedTxns)): ?>
    <div class="bnk-section-title" style="margin-top:12px"><i class="fas fa-list"></i> Unmatched Transactions — click to match</div>
    <div style="max-height:300px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:8px">
    <table class="fin-table">
        <thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Reference</th><th>Match</th></tr></thead>
        <tbody>
        <?php foreach ($unmatchedTxns as $u): ?>
        <tr>
            <td style="font-size:.75rem"><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
            <td style="font-size:.78rem"><?= $txnTypeLabels[$u['transaction_type']]??$u['transaction_type'] ?></td>
            <td style="font-weight:700"><?= finFmt((float)$u['amount']) ?></td>
            <td style="font-size:.75rem"><?= htmlspecialchars($u['reference_no']??'—') ?></td>
            <td>
                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="_action" value="match_transaction"><input type="hidden" name="rec_id" value="<?= $activeRecon['id'] ?>"><input type="hidden" name="transaction_id" value="<?= $u['id'] ?>"><button type="submit" class="bnk-btn bnk-btn-green bnk-btn-sm"><i class="fas fa-check"></i> Match</button></form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php if ($activeRecon['status'] === 'in_progress'): ?>
    <form method="post" style="margin-top:12px" onsubmit="return confirm('Complete this reconciliation?')"><?= csrf_field() ?><input type="hidden" name="_action" value="complete_reconciliation"><input type="hidden" name="rec_id" value="<?= $activeRecon['id'] ?>"><button type="submit" class="bnk-btn bnk-btn-green"><i class="fas fa-check-double"></i> Complete Reconciliation</button></form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
// ═══════════════════════════════════════════════════════════════
// TAB: SETTINGS
// ═══════════════════════════════════════════════════════════════
elseif ($tab === 'settings'):
$defaultBank = $settings['default_bank'] ?? '0';
$allowNeg = $settings['allow_negative_balance'] ?? '0';
$transferApproval = $settings['transfer_approval_required'] ?? '1';
$autoPost = $settings['auto_post_to_ledger'] ?? '1';
$dailyRecon = $settings['daily_reconciliation_reminder'] ?? '0';
$monthlyLock = $settings['monthly_closing_lock'] ?? '0';
$precision = $settings['currency_precision'] ?? '2';
?>
<div class="bnk-section-title"><i class="fas fa-cog"></i> Bank Settings</div>

<div class="fin-grid-2">
    <div class="fin-card">
        <h2><i class="fas fa-sliders-h" style="color:var(--fin-blue)"></i> General Settings</h2>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="_action" value="save_settings">
            <div class="fin-form-group"><label>Default Bank Account</label>
                <select name="default_bank">
                    <option value="0">None</option>
                    <?php foreach (bankGetAllAccounts($pdo) as $ba): ?>
                    <option value="<?= $ba['id'] ?>" <?= $defaultBank==(string)$ba['id']?'selected':'' ?>><?= htmlspecialchars($ba['bank_name'].' - '.$ba['account_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fin-form-group" style="display:flex;align-items:center;gap:10px">
                <label><input type="checkbox" name="allow_negative_balance" value="1" <?= $allowNeg==='1'?'checked':'' ?>> Allow Negative Balance</label>
            </div>
            <div class="fin-form-group" style="display:flex;align-items:center;gap:10px">
                <label><input type="checkbox" name="transfer_approval_required" value="1" <?= $transferApproval==='1'?'checked':'' ?>> Transfer Approval Required</label>
            </div>
            <div class="fin-form-group" style="display:flex;align-items:center;gap:10px">
                <label><input type="checkbox" name="auto_post_to_ledger" value="1" <?= $autoPost==='1'?'checked':'' ?>> Auto Post to General Ledger</label>
            </div>
            <div class="fin-form-group" style="display:flex;align-items:center;gap:10px">
                <label><input type="checkbox" name="daily_reconciliation_reminder" value="1" <?= $dailyRecon==='1'?'checked':'' ?>> Daily Reconciliation Reminder</label>
            </div>
            <div class="fin-form-group" style="display:flex;align-items:center;gap:10px">
                <label><input type="checkbox" name="monthly_closing_lock" value="1" <?= $monthlyLock==='1'?'checked':'' ?>> Monthly Closing Lock</label>
            </div>
            <div class="fin-form-group"><label>Currency Precision</label>
                <select name="currency_precision">
                    <option value="0" <?= $precision==='0'?'selected':'' ?>>0 (no decimals)</option>
                    <option value="2" <?= $precision==='2'?'selected':'' ?>>2 decimals</option>
                    <option value="4" <?= $precision==='4'?'selected':'' ?>>4 decimals</option>
                </select>
            </div>
            <button type="submit" class="bnk-btn bnk-btn-blue"><i class="fas fa-save"></i> Save Settings</button>
        </form>
    </div>
    <div class="fin-card">
        <h2><i class="fas fa-info-circle" style="color:var(--fin-teal)"></i> Banking System Info</h2>
        <div style="font-size:.85rem;line-height:1.8">
            <strong>Total Accounts:</strong> <?= count($allAccounts) ?><br>
            <strong>Active:</strong> <?= $dashActiveCount ?><br>
            <strong>Total Balance:</strong> <?= finFmt($dashTotalBalance) ?><br>
            <strong>Pending Transfers:</strong> <?= $dashPendingTransfers ?><br>
            <strong>Pending Reconciliation:</strong> <?= $dashPendingRecon ?><br>
            <strong>Voided Transactions:</strong> <?= $dashReturnedTxns ?><br>
            <hr style="border:none;border-top:1px solid #e2e8f0;margin:10px 0">
            <strong>System:</strong> <?= APP_NAME ?><br>
            <strong>Module:</strong> Bank Management v2.0<br>
            <strong>Database:</strong> MySQL / MariaDB<br>
            <strong>Last Sync:</strong> <?= $dashLastSync ? htmlspecialchars($dashLastSync) : 'Never' ?><br>
        </div>
    </div>
</div>

<?php
// ═══════════════════════════════════════════════════════════════
// TAB: REPORTS
// ═══════════════════════════════════════════════════════════════
elseif ($tab === 'reports'):
$reportType = $_GET['report_type'] ?? 'statement';
$reportAccount = (int)($_GET['account'] ?? 0);
$reportFrom = $_GET['from'] ?? date('Y-m-01');
$reportTo = $_GET['to'] ?? date('Y-m-d');
$reportData = [];
$reportTitle = '';

if ($reportType === 'statement' && $reportAccount) {
    $stmt = $pdo->prepare("SELECT bt.*, ba.account_name, ba.bank_name, ba.account_number FROM bank_transactions bt LEFT JOIN bank_accounts ba ON bt.bank_account_id=ba.id WHERE bt.bank_account_id=? AND DATE(bt.created_at) BETWEEN ? AND ? ORDER BY bt.created_at ASC");
    $stmt->execute([$reportAccount, $reportFrom, $reportTo]);
    $reportData = $stmt->fetchAll();
    $acc = bankGetAccountById($pdo, $reportAccount);
    $reportTitle = 'Bank Statement: ' . ($acc ? htmlspecialchars($acc['bank_name'] . ' - ' . $acc['account_name']) : '');
} elseif ($reportType === 'deposits') {
    $stmt = $pdo->prepare("SELECT bt.*, ba.account_name, ba.bank_name FROM bank_transactions bt LEFT JOIN bank_accounts ba ON bt.bank_account_id=ba.id WHERE bt.transaction_type IN ('deposit','customer_payment') AND DATE(bt.created_at) BETWEEN ? AND ? ORDER BY bt.created_at DESC");
    $stmt->execute([$reportFrom, $reportTo]);
    $reportData = $stmt->fetchAll();
    $reportTitle = 'Deposit Report';
} elseif ($reportType === 'withdrawals') {
    $stmt = $pdo->prepare("SELECT bt.*, ba.account_name, ba.bank_name FROM bank_transactions bt LEFT JOIN bank_accounts ba ON bt.bank_account_id=ba.id WHERE bt.transaction_type IN ('withdrawal','supplier_payment','expense') AND DATE(bt.created_at) BETWEEN ? AND ? ORDER BY bt.created_at DESC");
    $stmt->execute([$reportFrom, $reportTo]);
    $reportData = $stmt->fetchAll();
    $reportTitle = 'Withdrawal Report';
} elseif ($reportType === 'transfers') {
    $stmt = $pdo->prepare("SELECT * FROM bank_transfers WHERE DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC");
    $stmt->execute([$reportFrom, $reportTo]);
    $reportData = $stmt->fetchAll();
    $reportTitle = 'Transfer Report';
} elseif ($reportType === 'monthly') {
    $stmt = $pdo->query("SELECT DATE_FORMAT(created_at,'%Y-%m') AS period, transaction_type, COUNT(*) AS txn_count, SUM(amount) AS total_amount, SUM(debit) AS total_debit, SUM(credit) AS total_credit FROM bank_transactions WHERE status='completed' GROUP BY period, transaction_type ORDER BY period DESC");
    $reportData = $stmt->fetchAll();
    $reportTitle = 'Monthly Summary';
} elseif ($reportType === 'cashflow') {
    $stmt = $pdo->prepare("SELECT DATE(created_at) AS txn_date, SUM(CASE WHEN debit > 0 THEN debit ELSE 0 END) AS inflow, SUM(CASE WHEN credit > 0 THEN credit ELSE 0 END) AS outflow, COUNT(*) AS txn_count FROM bank_transactions WHERE status='completed' AND DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY txn_date ASC");
    $stmt->execute([$reportFrom, $reportTo]);
    $reportData = $stmt->fetchAll();
    $reportTitle = 'Cash Flow Report';
}
?>
<div class="bnk-section-title"><i class="fas fa-file-alt"></i> Reports</div>

<div class="fin-card" style="margin-bottom:16px">
    <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="page" value="finance_bank">
        <input type="hidden" name="tab" value="reports">
        <div class="fin-form-group" style="margin-bottom:0"><label>Report Type</label>
            <select name="report_type">
                <option value="statement" <?= $reportType==='statement'?'selected':'' ?>>Bank Statement</option>
                <option value="deposits" <?= $reportType==='deposits'?'selected':'' ?>>Deposit Report</option>
                <option value="withdrawals" <?= $reportType==='withdrawals'?'selected':'' ?>>Withdrawal Report</option>
                <option value="transfers" <?= $reportType==='transfers'?'selected':'' ?>>Transfer Report</option>
                <option value="cashflow" <?= $reportType==='cashflow'?'selected':'' ?>>Cash Flow</option>
                <option value="monthly" <?= $reportType==='monthly'?'selected':'' ?>>Monthly Summary</option>
            </select>
        </div>
        <div class="fin-form-group" style="margin-bottom:0"><label>Account</label>
            <select name="account">
                <option value="0">All Accounts</option>
                <?php foreach (bankGetAllAccounts($pdo) as $ba): ?>
                <option value="<?= $ba['id'] ?>" <?= $reportAccount===$ba['id']?'selected':'' ?>><?= htmlspecialchars($ba['bank_name'].' - '.$ba['account_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fin-form-group" style="margin-bottom:0"><label>From</label><input type="date" name="from" value="<?= $reportFrom ?>"></div>
        <div class="fin-form-group" style="margin-bottom:0"><label>To</label><input type="date" name="to" value="<?= $reportTo ?>"></div>
        <button type="submit" class="bnk-btn bnk-btn-blue"><i class="fas fa-search"></i> Generate</button>
        <button type="button" class="bnk-btn bnk-btn-outline" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
    </form>
</div>

<?php if ($reportType === 'statement' && !empty($reportData)): ?>
<div class="fin-card">
    <h2><i class="fas fa-university" style="color:var(--fin-blue)"></i> <?= $reportTitle ?></h2>
    <p style="font-size:.8rem;color:#64748b;margin-bottom:12px">Period: <?= htmlspecialchars($reportFrom) ?> to <?= htmlspecialchars($reportTo) ?></p>
    <div style="overflow-x:auto">
    <table class="fin-table">
        <thead><tr><th>Date</th><th>Reference</th><th>Description</th><th>Debit</th><th>Credit</th><th>Balance</th></tr></thead>
        <tbody>
        <?php $runningBal = 0; ?>
        <?php foreach ($reportData as $r): ?>
        <tr>
            <td style="font-size:.78rem"><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
            <td style="font-size:.78rem"><?= htmlspecialchars($r['reference_no'] ?? '—') ?></td>
            <td style="font-size:.78rem"><?= htmlspecialchars($r['description']??$txnTypeLabels[$r['transaction_type']]??'') ?></td>
            <td style="font-weight:700;color:var(--fin-green)"><?= (float)$r['debit'] > 0 ? finFmt((float)$r['debit']) : '—' ?></td>
            <td style="font-weight:700;color:var(--fin-red)"><?= (float)$r['credit'] > 0 ? finFmt((float)$r['credit']) : '—' ?></td>
            <td style="font-weight:600"><?= finFmt((float)$r['balance_after']) ?></td>
        </tr>
        <?php $runningBal = (float)$r['balance_after']; endforeach; ?>
        </tbody>
        <tfoot><tr style="font-weight:700;background:#f8fafc"><td colspan="5" style="text-align:right">Ending Balance</td><td><?= finFmt($runningBal) ?></td></tr></tfoot>
    </table>
    </div>
</div>
<?php elseif ($reportType === 'monthly' && !empty($reportData)): ?>
<div class="fin-card">
    <h2><i class="fas fa-calendar-alt" style="color:var(--fin-purple)"></i> <?= $reportTitle ?></h2>
    <div style="overflow-x:auto">
    <table class="fin-table">
        <thead><tr><th>Period</th><th>Type</th><th>Count</th><th>Total Debit</th><th>Total Credit</th><th>Net</th></tr></thead>
        <tbody>
        <?php foreach ($reportData as $r): ?>
        <tr>
            <td style="font-weight:600"><?= htmlspecialchars($r['period']) ?></td>
            <td><?= $txnTypeLabels[$r['transaction_type']]??$r['transaction_type'] ?></td>
            <td><?= (int)$r['txn_count'] ?></td>
            <td style="color:var(--fin-green);font-weight:700"><?= finFmt((float)$r['total_debit']) ?></td>
            <td style="color:var(--fin-red);font-weight:700"><?= finFmt((float)$r['total_credit']) ?></td>
            <td style="font-weight:700"><?= finFmt((float)$r['total_debit'] - (float)$r['total_credit']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php elseif ($reportType === 'cashflow' && !empty($reportData)): ?>
<div class="fin-card">
    <h2><i class="fas fa-chart-line" style="color:var(--fin-teal)"></i> <?= $reportTitle ?></h2>
    <div style="overflow-x:auto">
    <table class="fin-table">
        <thead><tr><th>Date</th><th>Transactions</th><th>Inflow</th><th>Outflow</th><th>Net</th></tr></thead>
        <tbody>
        <?php foreach ($reportData as $r): ?>
        <tr>
            <td style="font-weight:600"><?= htmlspecialchars($r['txn_date']) ?></td>
            <td><?= (int)$r['txn_count'] ?></td>
            <td style="color:var(--fin-green);font-weight:700"><?= finFmt((float)$r['inflow']) ?></td>
            <td style="color:var(--fin-red);font-weight:700"><?= finFmt((float)$r['outflow']) ?></td>
            <td style="font-weight:700"><?= finFmt((float)$r['inflow'] - (float)$r['outflow']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php elseif (!empty($reportData)): ?>
<div class="fin-card">
    <h2><i class="fas fa-file-alt" style="color:var(--fin-indigo)"></i> <?= $reportTitle ?></h2>
    <div style="overflow-x:auto">
    <table class="fin-table">
        <thead><tr><th>Date</th><th>Account</th><th>Reference</th><th>Description</th><th>Amount</th></tr></thead>
        <tbody>
        <?php foreach ($reportData as $r): ?>
        <tr>
            <td style="font-size:.78rem"><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
            <td style="font-size:.78rem"><?= htmlspecialchars($r['bank_name']??$r['transfer_type']??'') ?></td>
            <td style="font-size:.78rem"><?= htmlspecialchars($r['reference_no']??$r['transfer_no']??'—') ?></td>
            <td style="font-size:.78rem"><?= htmlspecialchars($r['description']??'') ?></td>
            <td style="font-weight:700"><?= finFmt((float)($r['amount']??$r['total_amount']??0)) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php elseif ($reportData !== null): ?>
<div class="bnk-empty"><i class="fas fa-inbox"></i>No data found for the selected criteria.</div>
<?php endif; ?>

<?php
// ═══════════════════════════════════════════════════════════════
// TAB: AUDIT LOG
// ═══════════════════════════════════════════════════════════════
elseif ($tab === 'audit'):
?>
<div class="bnk-section-title"><i class="fas fa-history"></i> Audit Log</div>
<div class="fin-card" style="padding:0;overflow:hidden">
<?php if (empty($auditLog)): ?>
<div class="bnk-empty"><i class="fas fa-history"></i>No audit records yet.</div>
<?php else: ?>
<div style="overflow-x:auto">
<table class="fin-table">
    <thead><tr><th>Date</th><th>User</th><th>Action</th><th>Entity</th><th>Entity ID</th><th>IP Address</th></tr></thead>
    <tbody>
    <?php foreach ($auditLog as $a): ?>
    <tr>
        <td style="font-size:.75rem;white-space:nowrap"><?= date('M d, Y H:i', strtotime($a['created_at'])) ?></td>
        <td style="font-size:.8rem"><?= htmlspecialchars($a['username'] ?? '—') ?></td>
        <td><span class="bnk-badge <?= strpos($a['action'],'add')===0||strpos($a['action'],'complete')!==false?'bnk-badge-green':(strpos($a['action'],'delete')!==false||strpos($a['action'],'reject')!==false||strpos($a['action'],'void')!==false||strpos($a['action'],'close')!==false?'bnk-badge-red':'bnk-badge-blue') ?>"><?= htmlspecialchars(str_replace('_',' ',$a['action'])) ?></span></td>
        <td style="font-size:.78rem"><?= htmlspecialchars(str_replace('_',' ',$a['entity_type'])) ?></td>
        <td style="font-size:.78rem"><?= (int)$a['entity_id'] ?></td>
        <td style="font-size:.72rem;color:#94a3b8;font-family:monospace"><?= htmlspecialchars($a['ip_address'] ?? '—') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
</div>
<?php endif; ?>

</section>

<?php
// ═══════════════════════════════════════════════════════════════
// MODALS (shared across tabs)
// ═══════════════════════════════════════════════════════════════
?>

<!-- Add/Edit Account Modal -->
<div class="fin-modal-overlay" id="modal-add-account" onclick="if(event.target===this)finCloseModal('modal-add-account')">
<div class="fin-modal" style="max-width:600px">
    <h3><i class="fas fa-piggy-bank" style="color:var(--fin-blue)"></i> <span id="modal-account-title">Add Bank Account</span></h3>
    <form method="post" id="form-account">
        <?= csrf_field() ?><input type="hidden" name="_action" id="account-action" value="add_account">
        <input type="hidden" name="account_id" id="account-id" value="0">
        <div class="fin-grid-2">
            <div class="fin-form-group"><label>Bank Name *</label><input type="text" name="bank_name" id="acc-bank-name" required placeholder="e.g. Commercial Bank of Ethiopia"></div>
            <div class="fin-form-group"><label>Branch</label><input type="text" name="branch" id="acc-branch" placeholder="Branch name"></div>
        </div>
        <div class="fin-grid-2">
            <div class="fin-form-group"><label>Account Name *</label><input type="text" name="account_name" id="acc-account-name" required placeholder="Account holder name"></div>
            <div class="fin-form-group"><label>Account Number</label><input type="text" name="account_number" id="acc-account-number" placeholder="Account number"></div>
        </div>
        <div class="fin-grid-2">
            <div class="fin-form-group"><label>SWIFT Code</label><input type="text" name="swift_code" id="acc-swift" placeholder="e.g. CBETETAA"></div>
            <div class="fin-form-group"><label>Currency</label><select name="currency" id="acc-currency"><option value="ETB">ETB - Ethiopian Birr</option><option value="USD">USD - US Dollar</option><option value="EUR">EUR - Euro</option></select></div>
        </div>
        <div class="fin-grid-2">
            <div class="fin-form-group"><label>Account Type</label>
                <select name="account_type" id="acc-type">
                    <option value="current">Current</option>
                    <option value="savings">Savings</option>
                    <option value="fixed_deposit">Fixed Deposit</option>
                </select>
            </div>
            <div class="fin-form-group"><label>Opening Balance (<?= currency_sym() ?>)</label><input type="number" step="0.01" name="opening_balance" id="acc-opening" value="0" placeholder="0.00"></div>
        </div>
        <div class="fin-form-group"><label>Notes</label><textarea name="notes" id="acc-notes" rows="2" style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;font-family:inherit"></textarea></div>
        <div style="display:flex;gap:8px;margin-top:16px">
            <button type="submit" class="bnk-btn bnk-btn-green"><i class="fas fa-save"></i> Save Account</button>
            <button type="button" class="bnk-btn bnk-btn-outline" onclick="finCloseModal('modal-add-account')">Cancel</button>
        </div>
    </form>
</div>
</div>

<!-- Deposit Modal -->
<div class="fin-modal-overlay" id="modal-deposit" onclick="if(event.target===this)finCloseModal('modal-deposit')">
<div class="fin-modal" style="max-width:450px">
    <h3><i class="fas fa-arrow-down" style="color:var(--fin-green)"></i> Record Deposit</h3>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="_action" value="deposit">
        <input type="hidden" name="account_id" id="dep-account-id" value="0">
        <div class="fin-form-group"><label>Account</label><input type="text" id="dep-account-name" readonly style="background:#f8fafc;font-weight:600"></div>
        <div class="fin-form-group"><label>Amount (<?= currency_sym() ?>) *</label><input type="number" step="0.01" name="amount" min="0.01" required placeholder="0.00"></div>
        <div class="fin-form-group"><label>Reference Number</label><input type="text" name="reference_no" placeholder="Deposit slip or transaction ref"></div>
        <div class="fin-form-group"><label>Description</label><textarea name="description" rows="2" style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;font-family:inherit" placeholder="Optional description"></textarea></div>
        <div style="display:flex;gap:8px;margin-top:16px">
            <button type="submit" class="bnk-btn bnk-btn-green"><i class="fas fa-check"></i> Record Deposit</button>
            <button type="button" class="bnk-btn bnk-btn-outline" onclick="finCloseModal('modal-deposit')">Cancel</button>
        </div>
    </form>
</div>
</div>

<!-- Withdrawal Modal -->
<div class="fin-modal-overlay" id="modal-withdraw" onclick="if(event.target===this)finCloseModal('modal-withdraw')">
<div class="fin-modal" style="max-width:450px">
    <h3><i class="fas fa-arrow-up" style="color:var(--fin-red)"></i> Record Withdrawal</h3>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="_action" value="withdraw">
        <input type="hidden" name="account_id" id="wit-account-id" value="0">
        <div class="fin-form-group"><label>Account</label><input type="text" id="wit-account-name" readonly style="background:#f8fafc;font-weight:600"></div>
        <div class="fin-form-group" id="wit-balance-display" style="font-size:.82rem;color:var(--fin-gray)">Available Balance: <strong id="wit-balance-val"><?= currency_sym() ?> 0.00</strong></div>
        <div class="fin-form-group"><label>Amount (<?= currency_sym() ?>) *</label><input type="number" step="0.01" name="amount" min="0.01" required placeholder="0.00"></div>
        <div class="fin-form-group"><label>Reference Number</label><input type="text" name="reference_no" placeholder="Withdrawal slip or ref"></div>
        <div class="fin-form-group"><label>Description</label><textarea name="description" rows="2" style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;font-family:inherit" placeholder="Optional description"></textarea></div>
        <div style="display:flex;gap:8px;margin-top:16px">
            <button type="submit" class="bnk-btn bnk-btn-red"><i class="fas fa-check"></i> Record Withdrawal</button>
            <button type="button" class="bnk-btn bnk-btn-outline" onclick="finCloseModal('modal-withdraw')">Cancel</button>
        </div>
    </form>
</div>
</div>

<!-- New Transfer Modal -->
<div class="fin-modal-overlay" id="modal-new-transfer" onclick="if(event.target===this)finCloseModal('modal-new-transfer')">
<div class="fin-modal" style="max-width:550px">
    <h3><i class="fas fa-exchange-alt" style="color:var(--fin-purple)"></i> New Transfer</h3>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="_action" value="create_transfer">
        <div class="fin-form-group"><label>Transfer Type</label>
            <select name="transfer_type" id="tf-type" onchange="toggleTransferType()">
                <option value="internal">Internal (Bank → Bank)</option>
                <option value="cash_to_bank">Cash → Bank</option>
                <option value="bank_to_cash">Bank → Cash</option>
            </select>
        </div>
        <input type="hidden" name="from_account_type" id="tf-from-type" value="bank">
        <input type="hidden" name="to_account_type" id="tf-to-type" value="bank">
        <div class="fin-form-group" id="tf-from-group"><label>From Account</label>
            <select name="from_account_id" id="tf-from" required>
                <option value="">Select source...</option>
                <optgroup label="Bank Accounts" id="tf-from-bank">
                    <?php foreach (($bankAccounts ?? []) as $ba): ?>
                    <option value="<?= $ba['id'] ?>" data-type="bank" data-bal="<?= (float)$ba['balance'] ?>"><?= htmlspecialchars($ba['bank_name'].' - '.$ba['account_name'].' ('.finFmt((float)$ba['balance']).')') ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <optgroup label="Cash Accounts" id="tf-from-cash" style="display:none">
                    <?php foreach (($cashAccounts ?? []) as $ca): ?>
                    <option value="<?= $ca['id'] ?>" data-type="cash" data-bal="<?= (float)$ca['balance'] ?>"><?= htmlspecialchars($ca['account_name'].' ('.finFmt((float)$ca['balance']).')') ?></option>
                    <?php endforeach; ?>
                </optgroup>
            </select>
        </div>
        <div class="fin-form-group" id="tf-to-group"><label>To Account</label>
            <select name="to_account_id" id="tf-to" required>
                <option value="">Select destination...</option>
                <optgroup label="Bank Accounts" id="tf-to-bank">
                    <?php foreach (($bankAccounts ?? []) as $ba): ?>
                    <option value="<?= $ba['id'] ?>" data-type="bank"><?= htmlspecialchars($ba['bank_name'].' - '.$ba['account_name'].' ('.finFmt((float)$ba['balance']).')') ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <optgroup label="Cash Accounts" id="tf-to-cash" style="display:none">
                    <?php foreach (($cashAccounts ?? []) as $ca): ?>
                    <option value="<?= $ca['id'] ?>" data-type="cash"><?= htmlspecialchars($ca['account_name'].' ('.finFmt((float)$ca['balance']).')') ?></option>
                    <?php endforeach; ?>
                </optgroup>
            </select>
        </div>
        <div class="fin-form-group"><label>Amount (<?= currency_sym() ?>) *</label><input type="number" step="0.01" name="amount" min="0.01" required placeholder="0.00"></div>
        <div class="fin-form-group"><label>Description</label><textarea name="description" rows="2" style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;font-family:inherit" placeholder="Transfer description"></textarea></div>
        <div style="font-size:.78rem;color:#64748b;margin-bottom:12px"><i class="fas fa-info-circle"></i> When created, the transfer will be submitted for admin approval before it can be executed.</div>
        <div style="display:flex;gap:8px">
            <button type="submit" class="bnk-btn bnk-btn-purple"><i class="fas fa-exchange-alt"></i> Create Transfer</button>
            <button type="button" class="bnk-btn bnk-btn-outline" onclick="finCloseModal('modal-new-transfer')">Cancel</button>
        </div>
    </form>
</div>
</div>

<!-- Close Account Modal -->
<div class="fin-modal-overlay" id="modal-close-account" onclick="if(event.target===this)finCloseModal('modal-close-account')">
<div class="fin-modal" style="max-width:450px">
    <h3><i class="fas fa-times-circle" style="color:var(--fin-red)"></i> Close Bank Account</h3>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="_action" value="close_account">
        <input type="hidden" name="account_id" id="close-account-id" value="0">
        <p style="margin-bottom:12px;font-size:.85rem">Are you sure you want to close <strong id="close-account-name">this account</strong>?</p>
        <div class="fin-form-group"><label>Reason for closing</label><textarea name="reason" rows="3" style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;font-family:inherit" required placeholder="Provide a reason..."></textarea></div>
        <div style="display:flex;gap:8px;margin-top:12px">
            <button type="submit" class="bnk-btn bnk-btn-red"><i class="fas fa-times-circle"></i> Confirm Close</button>
            <button type="button" class="bnk-btn bnk-btn-outline" onclick="finCloseModal('modal-close-account')">Cancel</button>
        </div>
    </form>
</div>
</div>

<!-- Reject Transfer Modal -->
<div class="fin-modal-overlay" id="modal-reject-transfer" onclick="if(event.target===this)finCloseModal('modal-reject-transfer')">
<div class="fin-modal" style="max-width:450px">
    <h3><i class="fas fa-times" style="color:var(--fin-red)"></i> Reject Transfer</h3>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="_action" value="reject_transfer">
        <input type="hidden" name="transfer_id" id="reject-tf-id" value="0">
        <div class="fin-form-group"><label>Rejection Reason *</label><textarea name="rejection_reason" rows="3" style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;font-family:inherit" required placeholder="Explain why..."></textarea></div>
        <div style="display:flex;gap:8px;margin-top:12px">
            <button type="submit" class="bnk-btn bnk-btn-red"><i class="fas fa-times"></i> Reject Transfer</button>
            <button type="button" class="bnk-btn bnk-btn-outline" onclick="finCloseModal('modal-reject-transfer')">Cancel</button>
        </div>
    </form>
</div>
</div>

<?= finJs() ?>
<script>
var BANK_ACCOUNTS = <?= $accountsJson ?: '[]' ?>;
function resetAccountModal() {
    document.getElementById('account-action').value = 'add_account';
    document.getElementById('account-id').value = '0';
    document.getElementById('modal-account-title').textContent = 'Add Bank Account';
    document.getElementById('acc-bank-name').value = '';
    document.getElementById('acc-branch').value = '';
    document.getElementById('acc-account-name').value = '';
    document.getElementById('acc-account-number').value = '';
    document.getElementById('acc-swift').value = '';
    document.getElementById('acc-currency').value = 'ETB';
    document.getElementById('acc-type').value = 'current';
    document.getElementById('acc-opening').value = '0';
    document.getElementById('acc-opening').disabled = false;
    document.getElementById('acc-notes').value = '';
}
function openAddAccount() {
    resetAccountModal();
    document.getElementById('modal-add-account').classList.add('show');
}
function editAccount(id) {
    var a = BANK_ACCOUNTS[id] || null;
    if (!a) { alert('Account not found.'); return; }
    resetAccountModal();
    document.getElementById('account-action').value = 'edit_account';
    document.getElementById('account-id').value = a.id;
    document.getElementById('modal-account-title').textContent = 'Edit Bank Account';
    document.getElementById('acc-bank-name').value = a.bank_name;
    document.getElementById('acc-branch').value = a.branch;
    document.getElementById('acc-account-name').value = a.account_name;
    document.getElementById('acc-account-number').value = a.account_number;
    document.getElementById('acc-swift').value = a.swift_code;
    document.getElementById('acc-currency').value = a.currency || 'ETB';
    document.getElementById('acc-type').value = a.account_type || 'current';
    document.getElementById('acc-opening').value = '';
    document.getElementById('acc-opening').disabled = true;
    document.getElementById('acc-notes').value = a.notes;
    document.getElementById('modal-add-account').classList.add('show');
}
function toggleTransferType() {
    var t = document.getElementById('tf-type').value;
    var fromBank = document.getElementById('tf-from-bank');
    var fromCash = document.getElementById('tf-from-cash');
    var toBank = document.getElementById('tf-to-bank');
    var toCash = document.getElementById('tf-to-cash');
    var fromType = document.getElementById('tf-from-type');
    var toType = document.getElementById('tf-to-type');
    fromBank.style.display = 'none'; fromCash.style.display = 'none';
    toBank.style.display = 'none'; toCash.style.display = 'none';
    if (t === 'internal') {
        fromBank.style.display = ''; toBank.style.display = '';
        fromType.value = 'bank'; toType.value = 'bank';
    } else if (t === 'cash_to_bank') {
        fromCash.style.display = ''; toBank.style.display = '';
        fromType.value = 'cash'; toType.value = 'bank';
    } else {
        fromBank.style.display = ''; toCash.style.display = '';
        fromType.value = 'bank'; toType.value = 'cash';
    }
}
function openDeposit(id, name, bal) {
    document.getElementById('dep-account-id').value = id;
    document.getElementById('dep-account-name').value = name;
    document.getElementById('modal-deposit').classList.add('show');
}
function openWithdraw(id, name, bal) {
    document.getElementById('wit-account-id').value = id;
    document.getElementById('wit-account-name').value = name;
    document.getElementById('wit-balance-val').textContent = '<?= currency_sym() ?> ' + parseFloat(bal).toLocaleString('en-US', {minimumFractionDigits:2,maximumFractionDigits:2});
    document.getElementById('modal-withdraw').classList.add('show');
}
function freezeAccount(id) {
    if (!confirm('Freeze this account? Transactions will be paused.')) return;
    var f = document.createElement('form'); f.method = 'post';
    f.innerHTML = '<?= csrf_field() ?><input name="_action" value="freeze_account"><input name="account_id" value="'+id+'">';
    document.body.appendChild(f); f.submit();
}
function activateAccount(id) {
    if (!confirm('Activate this account?')) return;
    var f = document.createElement('form'); f.method = 'post';
    f.innerHTML = '<?= csrf_field() ?><input name="_action" value="activate_account"><input name="account_id" value="'+id+'">';
    document.body.appendChild(f); f.submit();
}
function closeAccount(id) {
    document.getElementById('close-account-id').value = id;
    document.getElementById('close-account-name').textContent = 'account #' + id;
    document.getElementById('modal-close-account').classList.add('show');
}
function rejectTransfer(id) {
    document.getElementById('reject-tf-id').value = id;
    document.getElementById('modal-reject-transfer').classList.add('show');
}
function finCloseModal(id) {
    var m = document.getElementById(id);
    if (m) m.classList.remove('show');
}
function finCloseAllModals() {
    document.querySelectorAll('.fin-modal-overlay').forEach(function(m){m.classList.remove('show');});
}
document.addEventListener('DOMContentLoaded',function(){
    finAnimatedCounters();
    document.querySelectorAll('.fin-modal-overlay').forEach(function(m){
        m.addEventListener('click',function(e){if(e.target===m)finCloseAllModals();});
    });
    document.addEventListener('keydown',function(e){if(e.key==='Escape')finCloseAllModals();});
});
</script>
