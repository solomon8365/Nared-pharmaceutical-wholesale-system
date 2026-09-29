<?php
/**
 * CalculationService
 *
 * Centralized business calculations:
 *   - order totals (subtotal, discount, VAT, grand total)
 *   - revenue / profit / COGS KPIs
 *   - outstanding balances, available credit
 *   - stock value, dashboard KPIs
 *
 * Pages must never re-implement these formulas inline.
 */
class CalculationService
{
    public static function orderTotals(PDO $pdo, int $orderId): array
    {
        require_once __DIR__ . '/../includes/workflow_helpers.php';
        return calculateOrderTotals($pdo, $orderId);
    }

    public static function vatRate(?PDO $pdo = null): float
    {
        require_once __DIR__ . '/../includes/workflow_helpers.php';
        return workflowGetVatRate($pdo);
    }

    /** Total VAT for a subtotal after discount, at the system VAT rate. */
    public static function vatOn(float $subtotalAfterDiscount): float
    {
        $rate = static::vatRate();
        return round($subtotalAfterDiscount * $rate / 100, 2);
    }

    public static function revenueToday(PDO $pdo): float
    {
        require_once __DIR__ . '/../includes/finance_helpers.php';
        return finGetTodaySales($pdo);
    }

    public static function revenueTodayPrevious(PDO $pdo): float
    {
        require_once __DIR__ . '/../includes/finance_helpers.php';
        return finGetTodaySalesPrev($pdo);
    }

    public static function revenueMonth(PDO $pdo): float
    {
        require_once __DIR__ . '/../includes/finance_helpers.php';
        return finGetMonthRevenue($pdo);
    }

    public static function revenueMonthPrevious(PDO $pdo): float
    {
        require_once __DIR__ . '/../includes/finance_helpers.php';
        return finGetMonthRevenuePrev($pdo);
    }

    public static function collectionsToday(PDO $pdo): float
    {
        require_once __DIR__ . '/../includes/finance_helpers.php';
        return finGetTodayCollections($pdo);
    }

    public static function collectionsTodayPrevious(PDO $pdo): float
    {
        require_once __DIR__ . '/../includes/finance_helpers.php';
        return finGetTodayCollectionsPrev($pdo);
    }

    public static function outstandingReceivables(PDO $pdo): float
    {
        require_once __DIR__ . '/../includes/finance_helpers.php';
        return finGetOutstandingReceivables($pdo);
    }

    public static function outstandingPayables(PDO $pdo): float
    {
        require_once __DIR__ . '/../includes/finance_helpers.php';
        return finGetOutstandingPayables($pdo);
    }

    public static function bankBalance(PDO $pdo): float
    {
        require_once __DIR__ . '/../includes/finance_helpers.php';
        return finGetBankBalance($pdo);
    }

    public static function cashBalance(PDO $pdo): float
    {
        require_once __DIR__ . '/../includes/finance_helpers.php';
        return finGetCashBalance($pdo);
    }

    public static function monthExpenses(PDO $pdo): float
    {
        require_once __DIR__ . '/../includes/finance_helpers.php';
        return finGetMonthExpenses($pdo);
    }

    public static function monthExpensesPrevious(PDO $pdo): float
    {
        require_once __DIR__ . '/../includes/finance_helpers.php';
        return finGetMonthExpensesPrev($pdo);
    }

    public static function monthIncome(PDO $pdo): float
    {
        require_once __DIR__ . '/../includes/finance_helpers.php';
        return finGetMonthIncome($pdo);
    }

    public static function monthCOGS(PDO $pdo): float
    {
        require_once __DIR__ . '/../includes/finance_helpers.php';
        return finGetMonthCOGS($pdo);
    }

    /** Gross profit for the month = revenue minus COGS. */
    public static function monthProfit(PDO $pdo): float
    {
        return static::revenueMonth($pdo) - static::monthCOGS($pdo);
    }

    public static function monthRefunds(PDO $pdo): float
    {
        require_once __DIR__ . '/../includes/finance_helpers.php';
        return finGetTotalRefunds($pdo);
    }

    public static function pendingPaymentApprovals(PDO $pdo): int
    {
        require_once __DIR__ . '/../includes/finance_helpers.php';
        return finGetPendingPaymentApprovals($pdo);
    }

    /** Revenue/expense/income series over the last 12 months. */
    public static function monthlySeries(PDO $pdo, string $type = 'revenue'): array
    {
        require_once __DIR__ . '/../includes/finance_helpers.php';
        return finGetMonthlyData($pdo, $type);
    }

    /** Payment method breakdown for charts. */
    public static function paymentMethodBreakdown(PDO $pdo): array
    {
        require_once __DIR__ . '/../includes/finance_helpers.php';
        return finGetPaymentMethodData($pdo);
    }

    /** Percentage change between two values: ['pct' => float, 'dir' => 'up'|'down'|'same']. */
    public static function percentChange(float $current, float $previous): array
    {
        require_once __DIR__ . '/../includes/finance_helpers.php';
        return finPctChange($current, $previous);
    }

    public static function stockTotals(PDO $pdo, ?int $lowThreshold = null, string $valueColumn = 'price', ?string $status = null): array
    {
        require_once __DIR__ . '/../includes/stock_helper.php';
        return stockTotals($pdo, $lowThreshold, $valueColumn, $status);
    }

    public static function stockValue(PDO $pdo): float
    {
        $totals = static::stockTotals($pdo, null, 'purchase_price');
        return (float) ($totals['total_value'] ?? 0);
    }

    public static function batchExpiryCounts(PDO $pdo, int $days = 60, bool $inStockOnly = false): array
    {
        require_once __DIR__ . '/../includes/stock_helper.php';
        return batchExpiryCounts($pdo, $days, $inStockOnly);
    }

    public static function expiredProductCount(PDO $pdo): int
    {
        require_once __DIR__ . '/../includes/stock_helper.php';
        return expiredProductCount($pdo);
    }

    public static function dashboardKpis(PDO $pdo): array
    {
        return [
            'revenue_today' => static::revenueToday($pdo),
            'collections_today' => static::collectionsToday($pdo),
            'month_revenue' => static::revenueMonth($pdo),
            'month_expenses' => static::monthExpenses($pdo),
            'month_income' => static::monthIncome($pdo),
            'month_cogs' => static::monthCOGS($pdo),
            'month_profit' => static::monthProfit($pdo),
            'outstanding_receivables' => static::outstandingReceivables($pdo),
            'outstanding_payables' => static::outstandingPayables($pdo),
            'bank_balance' => static::bankBalance($pdo),
            'cash_balance' => static::cashBalance($pdo),
            'stock_value' => static::stockValue($pdo),
            'pending_payment_approvals' => static::pendingPaymentApprovals($pdo),
            'month_refunds' => static::monthRefunds($pdo),
        ];
    }
}
