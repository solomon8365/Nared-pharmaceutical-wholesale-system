-- Finance ERP Module Migration
-- Safe to run multiple times (IF NOT EXISTS)

-- 1. Bank Accounts
CREATE TABLE IF NOT EXISTS bank_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_name VARCHAR(150) NOT NULL,
    bank_name VARCHAR(150) NOT NULL,
    account_number VARCHAR(50) NULL,
    account_type ENUM('savings','current','fixed_deposit') DEFAULT 'current',
    balance DECIMAL(14,2) DEFAULT 0.00,
    opening_balance DECIMAL(14,2) DEFAULT 0.00,
    currency VARCHAR(10) DEFAULT 'ETB',
    branch VARCHAR(150) NULL,
    status ENUM('active','inactive','closed') DEFAULT 'active',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Bank Transactions
CREATE TABLE IF NOT EXISTS bank_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bank_account_id INT UNSIGNED NOT NULL,
    transaction_type ENUM('deposit','withdrawal','transfer_in','transfer_out','interest','fee','adjustment') NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    balance_after DECIMAL(14,2) NOT NULL,
    reference_no VARCHAR(100) NULL,
    description TEXT NULL,
    related_account_id INT UNSIGNED NULL,
    status ENUM('completed','pending','reversed') DEFAULT 'completed',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bank_account (bank_account_id),
    INDEX idx_type (transaction_type),
    INDEX idx_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Cash Accounts
CREATE TABLE IF NOT EXISTS cash_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_name VARCHAR(150) NOT NULL,
    account_type ENUM('main','petty','float') DEFAULT 'main',
    balance DECIMAL(14,2) DEFAULT 0.00,
    opening_balance DECIMAL(14,2) DEFAULT 0.00,
    status ENUM('active','inactive') DEFAULT 'active',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_cash_account_name (account_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Cash Transactions
CREATE TABLE IF NOT EXISTS cash_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cash_account_id INT UNSIGNED NOT NULL,
    transaction_type ENUM('in','out','adjustment','transfer_in','transfer_out') NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    balance_after DECIMAL(14,2) NOT NULL,
    category VARCHAR(100) NULL,
    reference_no VARCHAR(100) NULL,
    description TEXT NULL,
    status ENUM('completed','pending','reversed') DEFAULT 'completed',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cash_account (cash_account_id),
    INDEX idx_type (transaction_type),
    INDEX idx_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Chart of Accounts
CREATE TABLE IF NOT EXISTS chart_of_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_code VARCHAR(20) NOT NULL UNIQUE,
    account_name VARCHAR(150) NOT NULL,
    account_type ENUM('asset','liability','equity','income','expense') NOT NULL,
    account_group VARCHAR(100) NULL,
    parent_id INT UNSIGNED NULL,
    opening_balance DECIMAL(14,2) DEFAULT 0.00,
    current_balance DECIMAL(14,2) DEFAULT 0.00,
    is_active TINYINT(1) DEFAULT 1,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Journal Entries
CREATE TABLE IF NOT EXISTS journal_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_date DATE NOT NULL,
    entry_number VARCHAR(50) NOT NULL UNIQUE,
    description TEXT NOT NULL,
    reference_type VARCHAR(50) NULL,
    reference_id INT UNSIGNED NULL,
    status ENUM('draft','posted','reversed') DEFAULT 'draft',
    total_debit DECIMAL(14,2) DEFAULT 0.00,
    total_credit DECIMAL(14,2) DEFAULT 0.00,
    created_by INT UNSIGNED NULL,
    posted_by INT UNSIGNED NULL,
    posted_at DATETIME NULL,
    reversed_at DATETIME NULL,
    reversal_of INT UNSIGNED NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date (entry_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Journal Entry Lines
CREATE TABLE IF NOT EXISTS journal_entry_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    journal_entry_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    debit DECIMAL(14,2) DEFAULT 0.00,
    credit DECIMAL(14,2) DEFAULT 0.00,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_journal_entry (journal_entry_id),
    INDEX idx_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Finance Notifications
CREATE TABLE IF NOT EXISTS finance_notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    notification_type VARCHAR(50) NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    reference_type VARCHAR(50) NULL,
    reference_id INT UNSIGNED NULL,
    severity ENUM('info','warning','critical') DEFAULT 'info',
    is_read TINYINT(1) DEFAULT 0,
    created_for INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_unread (is_read),
    INDEX idx_type (notification_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. Expense approvals table
CREATE TABLE IF NOT EXISTS expense_approvals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    expense_id INT UNSIGNED NOT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    approved_by INT UNSIGNED NULL,
    approved_at DATETIME NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_expense (expense_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. Seed default chart of accounts for pharmaceutical wholesale
INSERT IGNORE INTO chart_of_accounts (account_code, account_name, account_type, account_group) VALUES
-- Assets
('1000', 'Cash on Hand', 'asset', 'Cash'),
('1010', 'Petty Cash', 'asset', 'Cash'),
('1020', 'Bank - Main Account', 'asset', 'Bank'),
('1030', 'Bank - Savings Account', 'asset', 'Bank'),
('1100', 'Accounts Receivable', 'asset', 'Receivables'),
('1200', 'Inventory', 'asset', 'Inventory'),
('1300', 'Prepaid Expenses', 'asset', 'Prepayments'),
('1400', 'Fixed Assets', 'asset', 'Fixed Assets'),
-- Liabilities
('2000', 'Accounts Payable', 'asset', 'Payables'),
('2100', 'Credit Card Payable', 'liability', 'Payables'),
('2200', 'Tax Payable', 'liability', 'Tax'),
('2300', 'VAT Payable', 'liability', 'Tax'),
('2400', 'Accrued Expenses', 'liability', 'Accruals'),
-- Equity
('3000', 'Owner Equity', 'equity', 'Equity'),
('3100', 'Retained Earnings', 'equity', 'Equity'),
('3200', 'Current Year Earnings', 'equity', 'Equity'),
-- Income
('4000', 'Sales Revenue', 'income', 'Revenue'),
('4100', 'Credit Sales', 'income', 'Revenue'),
('4200', 'Service Income', 'income', 'Revenue'),
('4300', 'Interest Income', 'income', 'Other Income'),
('4400', 'Other Income', 'income', 'Other Income'),
-- Expenses
('5000', 'Cost of Goods Sold', 'expense', 'COGS'),
('5100', 'Purchase Returns', 'expense', 'COGS'),
('6000', 'Salary Expense', 'expense', 'Operating'),
('6100', 'Rent Expense', 'expense', 'Operating'),
('6200', 'Utilities Expense', 'expense', 'Operating'),
('6300', 'Transportation Expense', 'expense', 'Operating'),
('6400', 'Office Supplies', 'expense', 'Operating'),
('6500', 'Maintenance Expense', 'expense', 'Operating'),
('6600', 'Marketing Expense', 'expense', 'Operating'),
('6700', 'Insurance Expense', 'expense', 'Operating'),
('6800', 'Tax Expense', 'expense', 'Tax'),
('6900', 'Depreciation Expense', 'expense', 'Non-Operating'),
('7000', 'Interest Expense', 'expense', 'Non-Operating'),
('7100', 'Bank Charges', 'expense', 'Non-Operating'),
('7200', 'Miscellaneous Expense', 'expense', 'Other');

-- 11. Seed default cash accounts (idempotent: only when missing)
INSERT INTO cash_accounts (account_name, account_type, balance, opening_balance, status)
SELECT 'Main Cash Register', 'main', 0.00, 0.00, 'active'
WHERE NOT EXISTS (SELECT 1 FROM cash_accounts WHERE account_name = 'Main Cash Register');
INSERT INTO cash_accounts (account_name, account_type, balance, opening_balance, status)
SELECT 'Petty Cash Box', 'petty', 0.00, 0.00, 'active'
WHERE NOT EXISTS (SELECT 1 FROM cash_accounts WHERE account_name = 'Petty Cash Box');

-- 12. Add approval status to expenses if missing
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'pharma' AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'approval_status');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE expenses ADD COLUMN approval_status ENUM("pending","approved","rejected") DEFAULT "approved" AFTER receipt_path', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'pharma' AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'approved_by');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE expenses ADD COLUMN approved_by INT UNSIGNED NULL AFTER approval_status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'pharma' AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'approved_at');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE expenses ADD COLUMN approved_at DATETIME NULL AFTER approved_by', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 13. Add notes to refunds if missing
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'pharma' AND TABLE_NAME = 'refunds' AND COLUMN_NAME = 'status');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE refunds ADD COLUMN status ENUM("pending","approved","rejected","completed") DEFAULT "completed" AFTER refunded_by', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'pharma' AND TABLE_NAME = 'refunds' AND COLUMN_NAME = 'refund_type');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE refunds ADD COLUMN refund_type ENUM("customer","supplier") DEFAULT "customer" AFTER status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'pharma' AND TABLE_NAME = 'refunds' AND COLUMN_NAME = 'supplier_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE refunds ADD COLUMN supplier_id INT UNSIGNED NULL AFTER refund_type', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 14. Add expense category values for ERP
ALTER TABLE expenses MODIFY COLUMN expense_category VARCHAR(100) NOT NULL;
