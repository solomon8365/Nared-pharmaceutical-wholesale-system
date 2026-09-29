<?php
requirePermission('settings', 'view');
$pdo = getPDO();
$messages = [];
$errors = [];

function sv(string $key, string $default = ''): string {
    global $settings;
    return htmlspecialchars($settings[$key] ?? $default);
}
function sc(string $key, string $val): string {
    global $settings;
    return ($settings[$key] ?? '') === $val ? ' selected' : '';
}

$settings = get_all_settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (($_POST['_action'] ?? '') === 'save') {
        requirePermission('settings', 'view');
        $upsert = $pdo->prepare('INSERT INTO settings (key_name, value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE value = :v2');
        $keys = ['vat_rate', 'currency', 'default_discount', 'rounding_precision', 'invoice_prefix', 'receipt_prefix', 'default_supplier_currency'];
        foreach ($keys as $k) {
            $v = trim($_POST[$k] ?? '');
            if ($k === 'vat_rate' && (!is_numeric($v) || (float) $v < 0 || (float) $v > 100)) {
                $errors[] = 'VAT rate must be between 0 and 100.';
                continue;
            }
            if ($k === 'default_discount' && (!is_numeric($v) || (float) $v < 0 || (float) $v > 100)) {
                $errors[] = 'Default discount must be between 0 and 100.';
                continue;
            }
            $upsert->execute(['k' => $k, 'v' => $v, 'v2' => $v]);
        }
        clear_settings_cache();
        $settings = get_all_settings();
        if (empty($errors)) {
            $messages[] = ['type' => 'success', 'text' => 'Tax & currency settings saved.'];
        }
    }
}
?>
<style>
.as-grid{display:grid;gap:14px}
.as-g2{grid-template-columns:1fr 1fr}
.as-g3{grid-template-columns:1fr 1fr 1fr}
@media(max-width:768px){.as-g2,.as-g3{grid-template-columns:1fr}}
</style>
<section>
    <div class="as-page-title"><i class="fas fa-percent" style="color:#3b82f6"></i> Tax &amp; Currency</div>
    <div class="as-page-sub">VAT rate, currency, pricing defaults, and document numbering</div>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error" style="margin-bottom:16px;padding:12px 16px;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-radius:8px"><?= htmlspecialchars(implode('; ', $errors)) ?></div>
    <?php endif; ?>
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>" style="margin-bottom:16px"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <div class="as-card">
            <div class="as-card-title"><i class="fas fa-coins"></i> Currency &amp; Tax</div>
            <div class="as-grid as-g3">
                <div class="as-field"><label>Base Currency</label><select name="currency"><option value="ETB"<?= sc('currency', 'ETB') ?>>ETB - Ethiopian Birr</option><option value="USD"<?= sc('currency', 'USD') ?>>USD - US Dollar</option><option value="EUR"<?= sc('currency', 'EUR') ?>>EUR - Euro</option><option value="GBP"<?= sc('currency', 'GBP') ?>>GBP - British Pound</option><option value="KES"<?= sc('currency', 'KES') ?>>KES - Kenyan Shilling</option><option value="NGN"<?= sc('currency', 'NGN') ?>>NGN - Nigerian Naira</option></select><div class="as-hint">Displayed with all monetary values</div></div>
                <div class="as-field"><label>VAT Percentage (%)</label><input type="number" step="0.01" min="0" max="100" name="vat_rate" value="<?= sv('vat_rate', '15') ?>"><div class="as-hint">Applied on taxable products</div></div>
                <div class="as-field"><label>Rounding Precision</label><select name="rounding_precision"><option value="0"<?= sc('rounding_precision', '0') ?>>0 (whole numbers)</option><option value="2"<?= sc('rounding_precision', '2') ?>>2 (standard)</option><option value="4"<?= sc('rounding_precision', '4') ?>>4 (high precision)</option></select><div class="as-hint">Decimal places used in totals</div></div>
            </div>
        </div>
        <div class="as-card">
            <div class="as-card-title"><i class="fas fa-tags"></i> Pricing Defaults</div>
            <div class="as-grid as-g3">
                <div class="as-field"><label>Default Discount (%)</label><input type="number" step="0.01" min="0" max="100" name="default_discount" value="<?= sv('default_discount', '0') ?>"><div class="as-hint">Applied on new orders</div></div>
                <div class="as-field"><label>Default Supplier Currency</label><select name="default_supplier_currency"><option value="ETB"<?= sc('default_supplier_currency', 'ETB') ?>>ETB</option><option value="USD"<?= sc('default_supplier_currency', 'USD') ?>>USD</option><option value="EUR"<?= sc('default_supplier_currency', 'EUR') ?>>EUR</option><option value="CNY"<?= sc('default_supplier_currency', 'CNY') ?>>CNY</option><option value="INR"<?= sc('default_supplier_currency', 'INR') ?>>INR</option></select><div class="as-hint">For supplier transactions</div></div>
            </div>
        </div>
        <div class="as-card">
            <div class="as-card-title"><i class="fas fa-file-invoice"></i> Document Numbering</div>
            <div class="as-grid as-g2">
                <div class="as-field"><label>Invoice Prefix</label><input type="text" name="invoice_prefix" value="<?= sv('invoice_prefix', 'INV-') ?>"><div class="as-hint">Prefix for generated invoice numbers</div></div>
                <div class="as-field"><label>Receipt Prefix</label><input type="text" name="receipt_prefix" value="<?= sv('receipt_prefix', 'RCP-') ?>"><div class="as-hint">Prefix for generated receipt numbers</div></div>
            </div>
        </div>
        <div class="as-save-bar"><button type="submit" class="as-btn as-btn-primary"><i class="fas fa-save"></i> Save Tax &amp; Currency Settings</button></div>
    </form>
</section>
