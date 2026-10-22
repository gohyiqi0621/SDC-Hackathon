<?php
/**
 * financial/_invoice_items.php - the line-item editor inside the invoice popup.
 *
 * Rows of SKU / item / unit / qty / unit price / discount, a live line total,
 * and the invoice totals (subtotal - discount + SST = total). The browser
 * shows the sums as you type; the server works them out again on save.
 * With no items, the Total box is typed in by hand (total-only invoices).
 *
 * Expects (from _invoice_modal.php): $values, $errors, $err, $v.
 */
$items = array_values(array_filter(
    is_array($values['items'] ?? null) ? $values['items'] : [],
    'is_array'
));

/** Quantity without trailing zeros: "150.000" -> "150", "1.500" -> "1.5". */
$qtyText = static function (mixed $q): string {
    $q = (string) $q;
    return str_contains($q, '.') ? rtrim(rtrim($q, '0'), '.') : $q;
};

/** One editable row. $i is the array index ("__i__" in the template). */
$itemRow = static function (string $i, array $item) use ($qtyText): string {
    $f = static fn (string $key): string => e((string) ($item[$key] ?? ''));
    $name = static fn (string $key): string => 'items[' . $i . '][' . $key . ']';

    return '<tr data-item-row>'
        . '<td class="items-num" data-item-num></td>'
        . '<td><input type="text" name="' . $name('sku') . '" value="' . $f('sku') . '" maxlength="100" aria-label="SKU" placeholder="SKU"></td>'
        . '<td class="items-desc"><input type="text" name="' . $name('description') . '" value="' . $f('description') . '" maxlength="255" aria-label="Item" placeholder="Item name"></td>'
        . '<td><input type="text" name="' . $name('uom') . '" value="' . $f('uom') . '" maxlength="20" aria-label="Unit" placeholder="Unit"></td>'
        . '<td><input type="text" inputmode="decimal" name="' . $name('quantity') . '" value="' . e($qtyText($item['quantity'] ?? '')) . '" aria-label="Quantity" placeholder="0" data-item-qty></td>'
        . '<td><input type="text" inputmode="decimal" name="' . $name('unit_price') . '" value="' . $f('unit_price') . '" aria-label="Unit price" placeholder="0.00" data-money data-item-price></td>'
        . '<td><input type="text" inputmode="decimal" name="' . $name('discount') . '" value="' . ((float) ($item['discount'] ?? 0) > 0 ? $f('discount') : '') . '" aria-label="Discount" placeholder="0.00" data-money data-item-disc></td>'
        . '<td class="items-total" data-item-total>0.00</td>'
        . '<td><button class="icon-btn icon-btn--danger" type="button" data-item-remove title="Remove item" aria-label="Remove item"><i class="fa-solid fa-xmark"></i></button></td>'
        . '</tr>';
};

$discount = (float) ($values['discount_amount'] ?? 0) > 0 ? (string) $values['discount_amount'] : '';
$sstRate  = (float) ($values['sst_rate'] ?? 0) > 0 ? rtrim(rtrim((string) $values['sst_rate'], '0'), '.') : '';
?>
<section class="items-editor" data-items>
    <div class="items-head">
        <div>
            <h3>Items</h3>
            <p class="field-hint">Add each line on the invoice. No items? Leave this empty and type the total below.</p>
        </div>
        <button class="secondary-btn" type="button" data-item-add><i class="fa-solid fa-plus"></i> Add item</button>
    </div>

    <div class="items-scroll">
        <table class="items-table">
            <thead>
                <tr>
                    <th class="items-num">#</th>
                    <th>SKU</th>
                    <th class="items-desc">Item *</th>
                    <th>Unit</th>
                    <th>Qty *</th>
                    <th>Unit price *</th>
                    <th>Disc.</th>
                    <th class="items-total">Total</th>
                    <th><span class="sr-only">Remove</span></th>
                </tr>
            </thead>
            <tbody data-items-body>
                <?php foreach ($items as $index => $item): ?>
                    <?= $itemRow((string) $index, $item) ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="items-empty" data-items-empty<?= $items === [] ? '' : ' hidden' ?>>No items yet.</p>
    </div>
    <p class="field-error" data-error-for="items"><?= e($errors['items'] ?? '') ?></p>

    <template data-item-template><?= $itemRow('__i__', []) ?></template>

    <dl class="items-totals">
        <div data-when-items>
            <dt>Subtotal</dt>
            <dd data-total-subtotal>0.00</dd>
        </div>
        <div data-when-items>
            <dt><label for="discount_amount">Discount</label></dt>
            <dd>
                <input id="discount_amount" name="discount_amount" type="text" inputmode="decimal" data-money
                       autocomplete="off" placeholder="0.00" value="<?= e($discount) ?>"<?= $err('discount_amount') ?>>
                <p class="field-error" data-error-for="discount_amount"><?= e($errors['discount_amount'] ?? '') ?></p>
            </dd>
        </div>
        <div data-when-items>
            <dt><label for="sst_rate">SST</label></dt>
            <dd class="items-sst">
                <span class="items-rate"><input id="sst_rate" name="sst_rate" type="text" inputmode="decimal"
                       autocomplete="off" placeholder="0" value="<?= e($sstRate) ?>"<?= $err('sst_rate') ?>>%</span>
                <span data-total-sst>0.00</span>
                <p class="field-error" data-error-for="sst_rate"><?= e($errors['sst_rate'] ?? '') ?></p>
            </dd>
        </div>
        <div class="items-grand">
            <dt><label for="amount">Total *</label></dt>
            <dd>
                <input id="amount" name="amount" type="text" inputmode="decimal" required data-money data-total-amount
                       autocomplete="off" placeholder="0.00" value="<?= e($v('amount')) ?>"<?= $err('amount') ?>>
                <p class="field-error" data-error-for="amount"><?= e($errors['amount'] ?? '') ?></p>
            </dd>
        </div>
    </dl>
</section>
