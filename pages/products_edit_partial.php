<div class="panel">
    <h2 style="font-size:1.1rem;font-weight:700;color:var(--brown-800);margin-bottom:16px"><i class="fas fa-edit" style="margin-right:6px"></i> Edit Product #<?= htmlspecialchars((string) $editProduct['id']) ?></h2>
    <form method="post" class="form-grid" enctype="multipart/form-data" style="max-width:700px">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="update">
        <input type="hidden" name="product_id" value="<?= htmlspecialchars((string) $editProduct['id']) ?>">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <label>Name<input type="text" name="name" value="<?= htmlspecialchars($editProduct['name']) ?>" required></label>
            <label>Brand<input type="text" name="brand" value="<?= htmlspecialchars($editProduct['brand'] ?? '') ?>"></label>
            <label>Generic Name<input type="text" name="generic_name" value="<?= htmlspecialchars($editProduct['generic_name'] ?? '') ?>"></label>
            <label>Dosage Form
                <select name="dosage_form">
                    <option value="">Select...</option>
                    <?php foreach (['Tablet','Capsule','Syrup','Suspension','Injection','Ointment','Cream','Drops','Inhaler','Spray','Powder','Granules','Solution','Suppository','Patch'] as $df): ?>
                        <option value="<?= $df ?>"<?= ($editProduct['dosage_form'] ?? '') === $df ? ' selected' : '' ?>><?= $df ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Strength<input type="text" name="strength" value="<?= htmlspecialchars($editProduct['strength'] ?? '') ?>" placeholder="e.g. 500mg"></label>
            <label>Pack Size<input type="text" name="pack_size" value="<?= htmlspecialchars($editProduct['pack_size'] ?? '') ?>" placeholder="e.g. 10 x 10 tablets"></label>
            <label>Manufacturer<input type="text" name="manufacturer" value="<?= htmlspecialchars($editProduct['manufacturer'] ?? '') ?>" placeholder="e.g. Addis Pharma"></label>
            <label>Country of Origin<input type="text" name="country_of_origin" value="<?= htmlspecialchars($editProduct['country_of_origin'] ?? '') ?>" placeholder="e.g. Ethiopia"></label>
            <label>Unit of Measure
                <select name="unit_of_measure">
                    <option value="">Select...</option>
                    <?php foreach (['Pcs','Box','Bottle','Strip','Ampoule','Vial','Tube','Sachet','Roll','Pair'] as $uom): ?>
                        <option value="<?= $uom ?>"<?= ($editProduct['unit_of_measure'] ?? '') === $uom ? ' selected' : '' ?>><?= $uom ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Storage Condition
                <select name="storage_condition">
                    <option value="">Select...</option>
                    <?php foreach (['Room Temperature','Refrigerated (2-8C)','Frozen (-20C)','Cool & Dry','Light Sensitive','Dry Place'] as $sc): ?>
                        <option value="<?= $sc ?>"<?= ($editProduct['storage_condition'] ?? '') === $sc ? ' selected' : '' ?>><?= $sc ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Shelf Life (months)<input type="number" name="shelf_life" min="0" value="<?= htmlspecialchars($editProduct['shelf_life'] ?? '') ?>" placeholder="e.g. 24"></label>
            <label>Manufacturing Date<input type="date" name="manufacturing_date" value="<?= htmlspecialchars($editProduct['manufacturing_date'] ?? '') ?>"></label>
            <label>Product Status
                <select name="product_status">
                    <option value="active"<?= ($editProduct['product_status'] ?? 'active') === 'active' ? ' selected' : '' ?>>Active</option>
                    <option value="inactive"<?= ($editProduct['product_status'] ?? 'active') === 'inactive' ? ' selected' : '' ?>>Inactive</option>
                    <option value="discontinued"<?= ($editProduct['product_status'] ?? '') === 'discontinued' ? ' selected' : '' ?>>Discontinued</option>
                </select>
            </label>
        </div>
        <label>Description<textarea name="description"><?= htmlspecialchars((string) ($editProduct['description'] ?? '')) ?></textarea></label>
        <label>Category
            <select name="category_id">
                <option value="">No category</option>
                <?php foreach ($allCategories as $cat): ?>
                    <option value="<?= htmlspecialchars((string) $cat['id']) ?>"<?= (int) ($editProduct['category_id'] ?? 0) === (int) $cat['id'] ? ' selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Photo (leave empty to keep current, max 2MB)<input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif"></label>
        <label>Barcode
            <div style="display:flex;gap:6px">
                <input type="text" name="barcode" value="<?= htmlspecialchars((string) ($editProduct['barcode'] ?? '')) ?>" placeholder="UPC / EAN / custom" style="flex:1">
                <button type="button" onclick="generateBarcode(this)" style="white-space:nowrap;padding:8px 12px">Generate</button>
            </div>
            <svg class="barcode-preview" style="display:<?= !empty($editProduct['barcode']) ? 'block' : 'none' ?>;margin-top:6px;max-width:200px"></svg>
        </label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <label>Purchase Price (<?= currency_sym() ?>)<input type="number" step="0.01" min="0" name="purchase_price" id="edit-purchase-price" value="<?= htmlspecialchars((string) ($editProduct['purchase_price'] ?? 0)) ?>"></label>
            <label>VAT (%)<input type="number" step="0.01" name="vat_percent" value="<?= htmlspecialchars((string) ($editProduct['vat_percent'] ?? SettingsService::vatRate())) ?>"></label>
            <label>Calculated Selling Price (read-only)<div style="padding:8px 10px;background:var(--neutral-50);border:1px solid var(--neutral-200);border-radius:6px;font-weight:700;color:var(--green-700)" id="edit-calc-price"><?= fmt((float) $editProduct['price']) ?></div></label>
            <label>Calculated Wholesale Price (read-only)<div style="padding:8px 10px;background:var(--neutral-50);border:1px solid var(--neutral-200);border-radius:6px;font-weight:700;color:#7c3aed" id="edit-calc-wholesale"><?= fmt((float) ($editProduct['wholesale_price'] ?? 0)) ?></div></label>
            <label>Stock Quantity<input type="number" name="stock" value="<?= htmlspecialchars((string) $editProduct['stock_quantity']) ?>"></label>
            <label>Reorder Level<input type="number" name="reorder_level" value="<?= htmlspecialchars((string) ($editProduct['reorder_level'] ?? 0)) ?>"></label>
            <label>Min Order Qty<input type="number" name="min_order_qty" value="<?= htmlspecialchars((string) ($editProduct['min_order_qty'] ?? 0)) ?>"></label>
            <label>Max Stock Level<input type="number" name="max_stock_level" value="<?= htmlspecialchars((string) ($editProduct['max_stock_level'] ?? 0)) ?>"></label>
        </div>
        <p style="font-size:0.78rem;color:var(--neutral-500);margin-top:10px">
            Selling and wholesale prices are recalculated automatically from the Purchase Price using the global markups
            (Selling <?= number_format((float) PricingService::sellingMarkup(), 1) ?>%, Wholesale <?= number_format((float) PricingService::wholesaleMarkup(), 1) ?>%)
            set in <a href="?page=products" style="color:#3b82f6">Product Management &rarr; Global Pricing Settings</a>. Historical orders and invoices are not changed.
        </p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <label>Supplier
                <select name="supplier_id">
                    <option value="">No supplier</option>
                    <?php foreach ($allSuppliers as $sup): ?>
                        <option value="<?= htmlspecialchars((string) $sup['id']) ?>"<?= (int) ($editProduct['supplier_id'] ?? 0) === (int) $sup['id'] ? ' selected' : '' ?>><?= htmlspecialchars($sup['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Supplier Product Code<input type="text" name="supplier_product_code" value="<?= htmlspecialchars($editProduct['supplier_product_code'] ?? '') ?>" placeholder="e.g. SUP-1234"></label>
            <label>Internal Product Code<input type="text" name="internal_product_code" value="<?= htmlspecialchars($editProduct['internal_product_code'] ?? '') ?>" placeholder="e.g. INT-5678"></label>
            <label>Shelf Location<input type="text" name="shelf_location" value="<?= htmlspecialchars($editProduct['shelf_location'] ?? '') ?>" placeholder="e.g. Aisle 3, Shelf B"></label>
            <label>Product Weight<input type="text" name="product_weight" value="<?= htmlspecialchars($editProduct['product_weight'] ?? '') ?>" placeholder="e.g. 250g"></label>
            <label>Package Dimensions<input type="text" name="package_dimensions" value="<?= htmlspecialchars($editProduct['package_dimensions'] ?? '') ?>" placeholder="e.g. 20x10x5 cm"></label>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <label>Product Type
                <select name="product_type">
                    <option value="otc"<?= ($editProduct['product_type'] ?? 'otc') === 'otc' ? ' selected' : '' ?>>OTC (Over the Counter)</option>
                    <option value="prescription"<?= ($editProduct['product_type'] ?? '') === 'prescription' ? ' selected' : '' ?>>Prescription</option>
                    <option value="herbal"<?= ($editProduct['product_type'] ?? '') === 'herbal' ? ' selected' : '' ?>>Herbal</option>
                    <option value="supplement"<?= ($editProduct['product_type'] ?? '') === 'supplement' ? ' selected' : '' ?>>Supplement</option>
                    <option value="device"<?= ($editProduct['product_type'] ?? '') === 'device' ? ' selected' : '' ?>>Medical Device</option>
                    <option value="other"<?= ($editProduct['product_type'] ?? '') === 'other' ? ' selected' : '' ?>>Other</option>
                </select>
            </label>
            <label style="display:flex;align-items:center;gap:8px;padding-top:28px">
                <input type="checkbox" name="requires_prescription" value="1" style="width:18px;height:18px"<?= !empty($editProduct['requires_prescription']) ? ' checked' : '' ?>> Requires Prescription
            </label>
        </div>
        <hr style="border:none;border-top:1px solid var(--neutral-200);margin:12px 0">
        <p style="font-size:0.85rem;font-weight:700;color:var(--brown-700);margin:0 0 10px"><i class="fas fa-robot" style="margin-right:4px"></i> System-Generated Info</p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 16px;font-size:0.85rem;background:var(--neutral-50);padding:14px;border-radius:8px;border:1px solid var(--neutral-200)">
            <div><span style="font-weight:600;color:var(--neutral-500)">Product Code</span><br><span style="font-weight:700;font-family:monospace"><?= htmlspecialchars($editProduct['product_code'] ?? '---') ?></span></div>
            <div><span style="font-weight:600;color:var(--neutral-500)">SKU</span><br><span style="font-weight:700;font-family:monospace"><?= htmlspecialchars($editProduct['sku'] ?? '---') ?></span></div>
            <div><span style="font-weight:600;color:var(--neutral-500)">Barcode</span><br><span style="font-weight:700;font-family:monospace"><?= htmlspecialchars($editProduct['barcode'] ?? '---') ?></span></div>
            <div><span style="font-weight:600;color:var(--neutral-500)">QR Code</span><br><?php if (!empty($editProduct['qr_code'])): ?><img src="<?= htmlspecialchars($editProduct['qr_code']) ?>" style="width:80px;height:80px;border-radius:4px;border:1px solid var(--neutral-200)"><?php else: ?><span>---</span><?php endif; ?></div>
            <div><span style="font-weight:600;color:var(--neutral-500)">Profit Margin</span><br><span style="font-weight:700;color:var(--green-700)"><?= number_format((float)($editProduct['profit_margin'] ?? 0), 1) ?>%</span></div>
            <div><span style="font-weight:600;color:var(--neutral-500)">Available Stock</span><br><span style="font-weight:700"><?= (int)($editProduct['stock_quantity'] ?? 0) ?> units</span></div>
            <div><span style="font-weight:600;color:var(--neutral-500)">Stock Status</span><br><?php $stk = (int)($editProduct['stock_quantity'] ?? 0); ?><span style="font-weight:700;color:<?= $stk <= 0 ? 'var(--red-700)' : ($stk <= 10 ? '#92400e' : 'var(--green-700)') ?>"><?= $stk <= 0 ? 'Out of Stock' : ($stk <= 10 ? 'Low Stock' : 'In Stock') ?></span></div>
            <div><span style="font-weight:600;color:var(--neutral-500)">Created Date</span><br><span><?= htmlspecialchars($editProduct['created_at'] ?? '---') ?></span></div>
            <div><span style="font-weight:600;color:var(--neutral-500)">Last Updated</span><br><span><?= htmlspecialchars($editProduct['updated_at'] ?? 'Never') ?></span></div>
            <div><span style="font-weight:600;color:var(--neutral-500)">Created By</span><br><span><?php if (!empty($editProduct['created_by'])): ?><?php echo htmlspecialchars(userFullName($pdo, (int) $editProduct['created_by']) ?: 'User #' . $editProduct['created_by']); ?><?php else: ?>---<?php endif; ?></span></div>
        </div>
        <div style="margin-top:12px;display:flex;gap:8px;align-items:center">
            <button type="submit" style="padding:10px 20px;background:var(--brown-700);color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600;font-size:0.85rem"><i class="fas fa-save" style="margin-right:4px"></i> Update Product</button>
            <a href="?page=products" style="display:inline-block;padding:10px 20px;color:var(--brown-800);font-weight:700;font-size:0.85rem">Cancel</a>
        </div>
    </form>
</div>
<script>
(function(){
    var pp=document.getElementById('edit-purchase-price');
    if(!pp)return;
    var sellMk=<?= (float) PricingService::sellingMarkup() ?>, whMk=<?= (float) PricingService::wholesaleMarkup() ?>, sym='<?= currency_sym() ?>';
    var show=function(){
        var v=parseFloat(pp.value)||0;
        document.getElementById('edit-calc-price').textContent=sym+' '+(v*(1+sellMk/100)).toFixed(2);
        document.getElementById('edit-calc-wholesale').textContent=sym+' '+(v*(1+whMk/100)).toFixed(2);
    };
    pp.addEventListener('input',show);
})();
</script>
