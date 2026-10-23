<?php
/**
 * financial/_invoice_modal.php - the "Create invoice" popup (also edits drafts).
 *
 * Left: the customer (suggested from earlier invoices) and the invoice details.
 * Right: every active product / service with a tick box and a quantity; the
 * totals update as you tick. Prices come from Products & Services and are not
 * editable here (the server prices the invoice again from the database).
 *
 * Buttons: "Save as draft" (no number yet, editable later) and "Create
 * invoice" (numbered INV-2026-0001; a User confirms first, as they cannot
 * change it afterwards).
 *
 * Expects: $modal [invoice (null = new), values, errors], $modalOpen,
 * $products (Product::forInvoicing), $customers, $finance (FinanceSetting).
 */
use Src\Models\Invoice;

$invoice    = $modal['invoice'];
$values     = $modal['values'];
$errors     = $modal['errors'];
$editing    = $invoice !== null;
$isDraft    = !$editing || $invoice['status'] === 'draft';
$supervisor = is_supervisor();
$v          = static fn (string $key, string $default = ''): string => (string) ($values[$key] ?? $default);
$err        = static fn (string $key): string => isset($errors[$key]) ? ' class="is-invalid" aria-invalid="true"' : '';
$currency   = $v('currency', 'MYR') ?: 'MYR';
$lines      = is_array($values['lines'] ?? null) ? $values['lines'] : [];
$sstRate    = $finance['sst_registered'] ? (float) $finance['sst_rate'] : 0.0;
$sstLabel   = rtrim(rtrim(number_format($sstRate, 2, '.', ''), '0'), '.');

// Payment terms: a standard choice, or "Custom…" with the saved / typed text.
$termsValue  = $v('payment_terms');
$termsChoice = $termsValue === '' ? '' : (isset(Invoice::PAYMENT_TERMS[$termsValue]) || $termsValue === '__custom' ? $termsValue : '__custom');
$termsCustom = $termsChoice === '__custom' ? ($termsValue === '__custom' ? $v('payment_terms_custom') : $termsValue) : '';

$title = !$editing ? 'Create invoice'
    : ($isDraft ? 'Continue draft' : 'Edit invoice ' . $invoice['invoice_number']);
?>
<dialog class="modal" id="invoiceModal" aria-labelledby="invoiceModalTitle"
        <?= $modalOpen ? 'data-modal-autoopen' : '' ?>
        <?= $editing ? 'data-modal-return="' . e(url('financial', ['action' => 'invoice'])) . '"' : '' ?>>
    <form class="modal-form" method="post" novalidate data-ajax data-invoice-form
          action="<?= e(url('financial', ['action' => $editing ? 'update' : 'store'])) ?>"
          <?= !$supervisor && $isDraft ? 'data-submit-confirm="Please check the customer, items and total. Once created, the invoice gets its number and you cannot edit or delete it." data-submit-confirm-title="Create this invoice?" data-submit-confirm-intent="issue"' : '' ?>>
        <?= csrf_field() ?>
        <input type="hidden" name="intent" value="issue" data-intent-field>
        <?php if ($editing): ?>
            <input type="hidden" name="id" value="<?= (int) $invoice['id'] ?>">
        <?php endif; ?>
        <?php if (!$editing && !empty($values['order_id'])): ?>
            <input type="hidden" name="order_id" value="<?= (int) $values['order_id'] ?>">
        <?php endif; ?>

        <header class="modal-head">
            <div>
                <h2 id="invoiceModalTitle"><?= e($title) ?></h2>
                <p>
                    <?php if (!$editing && !empty($values['order_id'])): ?>
                        Filled from order <?= e($v('reference_no')) ?>. Check the details, then create the invoice.
                    <?php else: ?>
                        Choose the customer and tick what you are billing for. Fields marked * are required.
                    <?php endif; ?>
                </p>
            </div>
            <button class="modal-close" type="button" data-modal-close aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </header>

        <?php if (!empty($errors['form'])): ?>
            <div class="flash flash--error modal-flash" role="alert">
                <i class="fa-solid fa-circle-exclamation"></i>
                <div><strong>Not saved</strong><span><?= e($errors['form']) ?></span></div>
            </div>
        <?php endif; ?>

        <div class="modal-body modal-body--invoice">

            <!-- ------------------------------------ customer + details --- -->
            <section class="modal-pane modal-pane--side">
                <h3 class="modal-pane-title"><i class="fa-regular fa-user"></i> Bill to</h3>

                <div class="field">
                    <label for="party_name">Customer *</label>
                    <input id="party_name" name="party_name" type="text" maxlength="200" required autocomplete="off"
                           list="customerList" data-customer-input
                           placeholder="Start typing, e.g. Kafe Ali" value="<?= e($v('party_name')) ?>"<?= $err('party_name') ?>>
                    <datalist id="customerList">
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= e($c['party_name']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <p class="field-hint" data-customer-hint hidden><i class="fa-solid fa-wand-magic-sparkles"></i> Address and phone filled from the last invoice.</p>
                    <p class="field-error" data-error-for="party_name"><?= e($errors['party_name'] ?? '') ?></p>
                    <script type="application/json" data-customer-data><?= json_encode(array_values($customers), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
                </div>

                <div class="field">
                    <label for="party_address">Address</label>
                    <textarea id="party_address" name="party_address" rows="3" maxlength="1000"
                              placeholder="Street, city, postcode"<?= $err('party_address') ?>><?= e($v('party_address')) ?></textarea>
                    <p class="field-error" data-error-for="party_address"><?= e($errors['party_address'] ?? '') ?></p>
                </div>

                <div class="field">
                    <label for="party_phone">Phone</label>
                    <input id="party_phone" name="party_phone" type="text" maxlength="50"
                           placeholder="e.g. 012-345 6789" value="<?= e($v('party_phone')) ?>"<?= $err('party_phone') ?>>
                    <p class="field-error" data-error-for="party_phone"><?= e($errors['party_phone'] ?? '') ?></p>
                </div>

                <h3 class="modal-pane-title"><i class="fa-regular fa-calendar"></i> Invoice details</h3>

                <div class="form-row">
                    <div class="field">
                        <label for="issue_date">Invoice date *</label>
                        <input id="issue_date" name="issue_date" type="date" required
                               value="<?= e($v('issue_date')) ?>"<?= $err('issue_date') ?>>
                        <p class="field-error" data-error-for="issue_date"><?= e($errors['issue_date'] ?? '') ?></p>
                    </div>
                    <div class="field">
                        <label for="due_date">Due date</label>
                        <input id="due_date" name="due_date" type="date"
                               value="<?= e($v('due_date')) ?>"<?= $err('due_date') ?>>
                        <p class="field-error" data-error-for="due_date"><?= e($errors['due_date'] ?? '') ?></p>
                    </div>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label for="payment_terms">Payment terms</label>
                        <select id="payment_terms" name="payment_terms" data-payment-terms<?= $err('payment_terms') ?>>
                            <option value=""<?= $termsChoice === '' ? ' selected' : '' ?>>Choose…</option>
                            <?php foreach (Invoice::PAYMENT_TERMS as $label => $days): ?>
                                <option value="<?= e($label) ?>" data-days="<?= (int) $days ?>"<?= $termsChoice === $label ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                            <option value="__custom"<?= $termsChoice === '__custom' ? ' selected' : '' ?>>Custom…</option>
                        </select>
                        <input name="payment_terms_custom" type="text" maxlength="100" class="terms-custom"
                               placeholder="e.g. 50% deposit, balance on delivery" value="<?= e($termsCustom) ?>"
                               data-payment-terms-custom<?= $termsChoice === '__custom' ? '' : ' hidden' ?>>
                        <p class="field-error" data-error-for="payment_terms"><?= e($errors['payment_terms'] ?? '') ?></p>
                    </div>
                    <div class="field">
                        <label for="currency">Currency</label>
                        <select id="currency" name="currency" data-invoice-currency<?= $err('currency') ?>>
                            <?php foreach (Invoice::CURRENCIES as $code): ?>
                                <option value="<?= e($code) ?>"<?= $currency === $code ? ' selected' : '' ?>><?= e($code) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="field-error" data-error-for="currency"><?= e($errors['currency'] ?? '') ?></p>
                    </div>
                </div>

                <div class="field">
                    <label for="reference_no">Reference no.</label>
                    <input id="reference_no" name="reference_no" type="text" maxlength="100"
                           placeholder="Optional, e.g. customer PO or order number" value="<?= e($v('reference_no')) ?>"<?= $err('reference_no') ?>>
                    <p class="field-error" data-error-for="reference_no"><?= e($errors['reference_no'] ?? '') ?></p>
                </div>

                <div class="field">
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes" rows="2" maxlength="2000"
                              placeholder="Optional, printed on the invoice"<?= $err('notes') ?>><?= e($v('notes')) ?></textarea>
                    <p class="field-error" data-error-for="notes"><?= e($errors['notes'] ?? '') ?></p>
                </div>
            </section>

            <!-- ------------------------------------- product picker --- -->
            <section class="modal-pane modal-pane--items" data-picker data-sst-rate="<?= e((string) $sstRate) ?>">
                <div class="picker-head">
                    <h3 class="modal-pane-title"><i class="fa-solid fa-box-open"></i> Items</h3>
                    <?php if ($products !== []): ?>
                        <div class="toolbar-search picker-search">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <label class="sr-only" for="picker-q">Search products</label>
                            <input id="picker-q" type="search" placeholder="Search products..." autocomplete="off" data-picker-search>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($products === []): ?>
                    <div class="empty-state picker-empty">
                        <div class="empty-icon"><i class="fa-solid fa-box-open"></i></div>
                        <h3>No products or services yet</h3>
                        <p>Invoices are built from what you sell. Add your products and services first.</p>
                        <a class="primary-btn" href="<?= e(url('products', ['action' => 'create'])) ?>">
                            <i class="fa-solid fa-plus"></i> Add product / service
                        </a>
                    </div>
                <?php else: ?>
                    <p class="field-hint">Tick each product or service to bill and set its quantity. Prices come from Products &amp; Services.</p>

                    <div class="picker-list" data-picker-list>
                        <?php foreach ($products as $p): ?>
                            <?php
                            $pid     = (int) $p['id'];
                            $qty     = (string) ($lines[$pid] ?? $lines[(string) $pid] ?? '');
                            $checked = $qty !== '' && (float) $qty > 0;
                            ?>
                            <label class="picker-row<?= $checked ? ' is-picked' : '' ?>" data-picker-row
                                   data-price="<?= e((string) $p['price']) ?>" data-currency="<?= e((string) $p['currency']) ?>"
                                   data-search="<?= e(mb_strtolower($p['name'] . ' ' . ($p['sku'] ?? '') . ' ' . $p['category_name'])) ?>">
                                <input type="checkbox" class="picker-check" data-picker-check<?= $checked ? ' checked' : '' ?>>
                                <span class="picker-name">
                                    <strong><?= e($p['name']) ?></strong>
                                    <small><?= e($p['category_name']) ?><?= !empty($p['sku']) ? ' · ' . e($p['sku']) : '' ?></small>
                                    <small class="picker-currency-note" data-picker-currency-note hidden>Priced in <?= e($p['currency']) ?></small>
                                </span>
                                <span class="picker-price"><?= e(format_money($p['price'], $p['currency'])) ?></span>
                                <span class="picker-qty">
                                    <span class="sr-only">Quantity of <?= e($p['name']) ?></span>
                                    <input type="text" inputmode="decimal" name="lines[<?= $pid ?>]" value="<?= e($checked ? $qty : '1') ?>"
                                           aria-label="Quantity" data-picker-qty<?= $checked ? '' : ' disabled' ?>>
                                </span>
                                <span class="picker-total" data-picker-total>—</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="items-empty" data-picker-nomatch hidden>No products match your search.</p>
                <?php endif; ?>
                <p class="field-error" data-error-for="items"><?= e($errors['items'] ?? '') ?></p>

                <dl class="items-totals picker-totals">
                    <div>
                        <dt>Subtotal <small data-picker-count></small></dt>
                        <dd data-picker-subtotal>0.00</dd>
                    </div>
                    <div>
                        <dt><?= $sstRate > 0 ? 'SST ' . e($sstLabel) . '%' : 'SST' ?></dt>
                        <dd data-picker-sst><?= $sstRate > 0 ? '0.00' : '<span class="muted">Not SST-registered</span>' ?></dd>
                    </div>
                    <div class="items-grand">
                        <dt>Total</dt>
                        <dd data-picker-grand>0.00</dd>
                    </div>
                </dl>
            </section>
        </div>

        <footer class="modal-foot">
            <?php if (!$supervisor && $isDraft): ?>
                <span class="modal-foot-note"><i class="fa-solid fa-lock"></i> Drafts can be changed; created invoices cannot.</span>
            <?php endif; ?>
            <button class="secondary-btn" type="button" data-modal-close>Cancel</button>
            <?php if ($isDraft): ?>
                <button class="secondary-btn" type="submit" data-intent="draft">
                    <i class="fa-regular fa-floppy-disk"></i> Save as draft
                </button>
                <button class="primary-btn" type="submit" data-intent="issue">
                    <i class="fa-solid fa-file-invoice"></i> Create invoice
                </button>
            <?php else: ?>
                <button class="primary-btn" type="submit" data-intent="save">
                    <i class="fa-regular fa-floppy-disk"></i> Save changes
                </button>
            <?php endif; ?>
        </footer>
    </form>
</dialog>
