<?php
/**
 * FinanceService
 *
 * Centralized finance queries and KPIs. All numeric queries delegate to
 * finance_helpers so a single source of truth drives dashboards and pages.
 */
class FinanceService
{
    public static function helpers(): void
    {
        require_once __DIR__ . '/../includes/finance_helpers.php';
    }

    public static function db(): PDO
    {
        static::helpers();
        return finGetDb();
    }

    public static function todaySales(): float
    {
        return (float) finGetTodaySales(static::db());
    }

    public static function todaySalesPrevious(): float
    {
        return (float) finGetTodaySalesPrev(static::db());
    }

    public static function monthRevenue(): float
    {
        return (float) finGetMonthRevenue(static::db());
    }

    public static function monthRevenuePrevious(): float
    {
        return (float) finGetMonthRevenuePrev(static::db());
    }

    public static function todayCollections(): float
    {
        return (float) finGetTodayCollections(static::db());
    }

    public static function todayCollectionsPrevious(): float
    {
        return (float) finGetTodayCollectionsPrev(static::db());
    }

    public static function monthExpenses(): float
    {
        return (float) finGetMonthExpenses(static::db());
    }

    public static function monthExpensesPrevious(): float
    {
        return (float) finGetMonthExpensesPrev(static::db());
    }

    public static function monthIncome(): float
    {
        return (float) finGetMonthIncome(static::db());
    }

    public static function monthCOGS(): float
    {
        return (float) finGetMonthCOGS(static::db());
    }

    public static function outstandingReceivables(): float
    {
        return (float) finGetOutstandingReceivables(static::db());
    }

    public static function outstandingPayables(): float
    {
        return (float) finGetOutstandingPayables(static::db());
    }

    public static function bankBalance(): float
    {
        return (float) finGetBankBalance(static::db());
    }

    public static function cashBalance(): float
    {
        return (float) finGetCashBalance(static::db());
    }

    public static function monthRefunds(): float
    {
        return (float) finGetTotalRefunds(static::db());
    }

    public static function pendingPaymentApprovals(): int
    {
        return (int) finGetPendingPaymentApprovals(static::db());
    }

    public static function monthlyData(): array
    {
        return finGetMonthlyData(static::db());
    }

    public static function paymentMethodData(): array
    {
        return finGetPaymentMethodData(static::db());
    }

    public static function percentChange(float $current, float $previous): array
    {
        return finPctChange($current, $previous);
    }

    /** Record a finance notification (e.g. for payment approvals). */
    public static function notify(PDO $pdo, ?int $userId, ?int $orderId, string $title, string $message, string $type = 'info'): void
    {
        NotificationService::finance($pdo, $userId, $orderId, $title, $message, $type);
    }
}
