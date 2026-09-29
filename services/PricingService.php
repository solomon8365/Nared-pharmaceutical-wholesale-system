<?php
/**
 * PricingService
 *
 * Global pricing configuration. Prices are derived exclusively from the
 * product's purchase price and two GLOBAL markup percentages stored in the
 * central settings table:
 *
 *   pricing_selling_markup    -> Selling Price  = purchase + purchase * % / 100
 *   pricing_wholesale_markup  -> Wholesale Price = purchase + purchase * % / 100
 *
 * There are NO per-product markup fields. The computed selling / wholesale
 * prices are written back onto products.price and products.wholesale_price so
 * every existing order-entry page keeps working unchanged. Historical order
 * line prices and invoices are never touched.
 */
class PricingService
{
    public const KEY_SELLING = 'pricing_selling_markup';
    public const KEY_WHOLESALE = 'pricing_wholesale_markup';

    public const DEFAULT_SELLING = 20;
    public const DEFAULT_WHOLESALE = 5;

    public const MAX_MARKUP = 500;

    private static bool $settingsEnsured = false;

    /**
     * Seed the two global pricing settings rows if they do not exist yet.
     * The selling markup inherits the legacy default_markup value (20) so
     * new products keep pricing the same as before the upgrade.
     */
    public static function ensureSettings(PDO $pdo): void
    {
        if (self::$settingsEnsured) return;
        self::$settingsEnsured = true;

        require_once __DIR__ . '/../db.php';
        $legacy = (float) (get_setting('default_markup', (string) self::DEFAULT_SELLING) ?: self::DEFAULT_SELLING);
        $legacy = $legacy > 0 ? $legacy : self::DEFAULT_SELLING;

        $stmt = $pdo->prepare('INSERT IGNORE INTO settings (key_name, value) VALUES (:k1, :v1), (:k2, :v2)');
        $stmt->execute([
            'k1' => self::KEY_SELLING,
            'v1' => number_format($legacy, 2, '.', ''),
            'k2' => self::KEY_WHOLESALE,
            'v2' => (string) self::DEFAULT_WHOLESALE,
        ]);
        clear_settings_cache();
    }

    /** Current global selling markup percentage (e.g. 10 => +10%). */
    public static function sellingMarkup(): float
    {
        require_once __DIR__ . '/../config.php';
        return (float) (get_setting(self::KEY_SELLING, (string) self::DEFAULT_SELLING) ?: self::DEFAULT_SELLING);
    }

    /** Current global wholesale markup percentage (e.g. 5 => +5%). */
    public static function wholesaleMarkup(): float
    {
        require_once __DIR__ . '/../config.php';
        return (float) (get_setting(self::KEY_WHOLESALE, (string) self::DEFAULT_WHOLESALE) ?: self::DEFAULT_WHOLESALE);
    }

    /**
     * Calculate the selling and wholesale prices for a given purchase price
     * using the current global markups. This is the ONLY price formula.
     */
    public static function calculatePrices(float $purchasePrice): array
    {
        $purchasePrice = max(0, (float) $purchasePrice);
        $selling = round($purchasePrice * (1 + self::sellingMarkup() / 100), 2);
        $wholesale = round($purchasePrice * (1 + self::wholesaleMarkup() / 100), 2);
        return [
            'price' => $selling,
            'wholesale_price' => $wholesale,
            'selling_markup' => self::sellingMarkup(),
            'wholesale_markup' => self::wholesaleMarkup(),
        ];
    }

    /**
     * Recompute one product's selling/wholesale prices from its stored
     * purchase price. Products without a purchase price are skipped so their
     * existing prices are never zeroed out. Returns true when the product
     * changed. Selling-price changes are recorded in price_history.
     */
    public static function recalculateProduct(PDO $pdo, int $productId, ?int $userId = null): bool
    {
        $stmt = $pdo->prepare('SELECT id, purchase_price, price, wholesale_price FROM products WHERE id = :id');
        $stmt->execute(['id' => $productId]);
        $row = $stmt->fetch();
        if ($row === false) return false;

        $purchase = (float) $row['purchase_price'];
        if ($purchase <= 0) return false;

        $calc = self::calculatePrices($purchase);
        $newSelling = $calc['price'];
        $newWholesale = $calc['wholesale_price'];
        $oldSelling = (float) $row['price'];
        $oldWholesale = (float) $row['wholesale_price'];

        if (abs($oldSelling - $newSelling) < 0.005 && abs($oldWholesale - $newWholesale) < 0.005) {
            return false;
        }

        $pm = round(($newSelling - $purchase) / $purchase * 100, 2);
        $upd = $pdo->prepare('UPDATE products SET price = :price, wholesale_price = :wp, profit_margin = :pm, updated_at = NOW() WHERE id = :id');
        $upd->execute(['price' => $newSelling, 'wp' => $newWholesale, 'pm' => $pm, 'id' => $productId]);

        if (abs($oldSelling - $newSelling) >= 0.005) {
            $ph = $pdo->prepare('INSERT INTO price_history (product_id, old_price, new_price, changed_by, created_at) VALUES (:pid, :old, :new, :uid, NOW())');
            $ph->execute(['pid' => $productId, 'old' => $oldSelling, 'new' => $newSelling, 'uid' => $userId]);
        }
        return true;
    }

    /**
     * Recompute every product that has a purchase price. Returns a summary
     * array. Caller is responsible for wrapping in a transaction when needed.
     */
    public static function recalculateAll(PDO $pdo, ?int $userId = null): array
    {
        self::ensureSettings($pdo);
        $ids = $pdo->query('SELECT id FROM products WHERE purchase_price > 0')->fetchAll(PDO::FETCH_COLUMN);
        $changed = 0;
        foreach ($ids as $productId) {
            if (self::recalculateProduct($pdo, (int) $productId, $userId)) $changed++;
        }
        return ['eligible' => count($ids), 'changed' => $changed];
    }

    /**
     * Persist the global markup percentages and immediately recalculate all
     * current product prices. Runs in a single transaction with server-side
     * validation. Historical orders / invoices are not modified.
     */
    public static function updateGlobalMarkups(PDO $pdo, float $selling, float $wholesale, ?int $userId = null): array
    {
        if ($selling < 0 || $wholesale < 0) {
            throw new ServiceException('Markup percentages cannot be negative.');
        }
        if ($selling > self::MAX_MARKUP || $wholesale > self::MAX_MARKUP) {
            throw new ServiceException('Markup percentage cannot exceed ' . self::MAX_MARKUP . '%.');
        }

        self::ensureSettings($pdo);

        $pdo->beginTransaction();
        try {
            $set = $pdo->prepare('INSERT INTO settings (key_name, value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE value = VALUES(value)');
            $set->execute(['k' => self::KEY_SELLING, 'v' => number_format($selling, 2, '.', '')]);
            $set->execute(['k' => self::KEY_WHOLESALE, 'v' => number_format($wholesale, 2, '.', '')]);

            // Force recalculation against the NEW percentages, not cached ones.
            clear_settings_cache();

            $summary = self::recalculateAll($pdo, $userId);

            AuditService::cms(
                $pdo,
                $userId,
                'pricing_settings_updated',
                'settings',
                null,
                'Selling markup ' . number_format($selling, 2) . '%, Wholesale markup ' . number_format($wholesale, 2)
                    . '% — ' . $summary['changed'] . ' of ' . $summary['eligible'] . ' products recalculated'
            );

            $pdo->commit();
            clear_settings_cache();
        } catch (Throwable $e) {
            $pdo->rollBack();
            clear_settings_cache();
            throw $e instanceof ServiceException ? $e : new ServiceException($e->getMessage());
        }
        return $summary;
    }
}
