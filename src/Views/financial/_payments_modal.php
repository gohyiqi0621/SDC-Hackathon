<?php
/**
 * financial/_payments_modal.php - an invoice's payments and receipts.
 *
 * Everyone sees what has been paid and can print each receipt. A Supervisor
 * also records a new payment (partial payments allowed): each one becomes a
 * receipt numbered RCPT-2026-0001.
 *
 * Expects: $pay [invoice, receipts, values, errors].
 */
use Src\Models\Invoice;
use Src\Models\Receipt;

$invoice    = $pay['invoice'];
$receipts   = $pay['receipts'];
$pv         = static fn (string $key, string $default = ''): string => (string) ($pay['values'][$key] ?? $default);
$perr       = static fn (string $key): string => isset($pay['errors'][$key]) ? ' class="is-invalid" aria-invalid="true"' : '';
$cur        = (string) $invoice['currency'];
$balance    = $invoice['status'] === 'voided' ? 0.0 : max(0, (float) $invoice['amount'] - (float) $invoice['amount_paid']);
$canRecord  = is_supervisor() && in_array($invoice['status'], ['unpaid', 'partial'], true);
[$statusLabel, $statusClass] = Invoice::STATUS_LABELS[$invoice['status']] ?? [ucfirst((string) $invoice['status']), 'badge--muted'];
?>
<dialog class="modal modal--narrow" id="paymentsModal" aria-labelledby="paymentsModalTitle" data-modal-autoopen
        data-modal-return="<?= e(url('financial', ['action' => 'invoice'])) ?>">
    <div class="modal-form">
        <header class="modal-head">
            <div>
                <h2 id="paymentsModalTitle">Payments · <?= e($invoice['invoice_number'] ?: 'Draft') ?></h2>
                <p><?= e($invoice['party_name']) ?> · <span class="badge <?= e($statusClass) ?>"><?= e($statusLabel) ?></span></p>
            </div>
            <button class="modal-close" type="button" data-modal-close aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </header>

        <div class="modal-scroll">
            <div class="pay-summary">
                <div><span>Invoice total</span><strong><?= e(format_money($invoice['amount'], $cur)) ?></strong></div>
                <div><span>Paid</span><strong class="pay-paid"><?= e(format_money($invoice['amount_paid'], $cur)) ?></strong></div>
                <div><span>Balance due</span><strong class="<?= $balance > 0 ? 'pay-due' : 'pay-paid' ?>"><?= e(format_money(number_format($balance, 2, '.', ''), $cur)) ?></strong></div>
            </div>

            <h3 class="modal-pane-title"><i class="fa-solid fa-receipt"></i> Receipts</h3>
            <?php if ($receipts === []): ?>
                <p class="items-empty">No payments recorded yet.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr><th>Receipt</th><th>Date</th><th>Method</th><th class="num">Amount</th><th class="actions"><span class="sr-only">Print</span></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($receipts as $r): ?>
                                <tr>
                                    <td><strong><?= e($r['receipt_number']) ?></strong>
                                        <?php if (!empty($r['reference'])): ?><br><small class="muted">Ref: <?= e($r['reference']) ?></small><?php endif; ?></td>
                                    <td><?= e(fmt_datetime($r['payment_date'], 'j M Y')) ?></td>
                                    <td><?= e(Receipt::METHODS[$r['method']] ?? $r['method']) ?></td>
                                    <td class="num"><?= e(format_money($r['amount'], $r['currency'])) ?></td>
                                    <td class="actions">
                                        <a class="icon-btn" href="<?= e(url('financial', ['action' => 'receipt', 'id' => $r['id']])) ?>" target="_blank" rel="noopener"
                                           title="Print receipt" aria-label="Print receipt <?= e($r['receipt_number']) ?>">
                                            <i class="fa-solid fa-print"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($canRecord): ?>
                <h3 class="modal-pane-title"><i class="fa-solid fa-money-bill-wave"></i> Record a payment</h3>

                <?php if (!empty($pay['errors']['form'])): ?>
                    <div class="flash flash--error" role="alert">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <div><strong>Not saved</strong><span><?= e($pay['errors']['form']) ?></span></div>
                    </div>
                <?php endif; ?>

                <form method="post" novalidate data-ajax action="<?= e(url('financial', ['action' => 'pay'])) ?>" class="pay-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $invoice['id'] ?>">

                    <div class="form-row">
                        <div class="field">
                            <label for="pay_amount">Amount received (<?= e($cur) ?>) *</label>
                            <input id="pay_amount" name="amount" type="text" inputmode="decimal" required data-money autocomplete="off"
                                   value="<?= e($pv('amount')) ?>"<?= $perr('amount') ?>>
                            <p class="field-hint">Less than the balance = a part payment.</p>
                            <p class="field-error" data-error-for="amount"><?= e($pay['errors']['amount'] ?? '') ?></p>
                        </div>
                        <div class="field">
                            <label for="pay_date">Date received *</label>
                            <input id="pay_date" name="payment_date" type="date" required max="<?= e(date('Y-m-d')) ?>"
                                   value="<?= e($pv('payment_date')) ?>"<?= $perr('payment_date') ?>>
                            <p class="field-error" data-error-for="payment_date"><?= e($pay['errors']['payment_date'] ?? '') ?></p>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label for="pay_method">Paid by *</label>
                            <select id="pay_method" name="method"<?= $perr('method') ?>>
                                <?php foreach (Receipt::METHODS as $key => $label): ?>
                                    <option value="<?= e($key) ?>"<?= $pv('method') === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="field-error" data-error-for="method"><?= e($pay['errors']['method'] ?? '') ?></p>
                        </div>
                        <div class="field">
                            <label for="pay_ref">Reference</label>
                            <input id="pay_ref" name="reference" type="text" maxlength="100"
                                   placeholder="e.g. bank ref or cheque no." value="<?= e($pv('reference')) ?>"<?= $perr('reference') ?>>
                            <p class="field-error" data-error-for="reference"><?= e($pay['errors']['reference'] ?? '') ?></p>
                        </div>
                    </div>

                    <div class="field">
                        <label for="pay_notes">Notes</label>
                        <input id="pay_notes" name="notes" type="text" maxlength="500" placeholder="Optional, printed on the receipt"
                               value="<?= e($pv('notes')) ?>"<?= $perr('notes') ?>>
                        <p class="field-error" data-error-for="notes"><?= e($pay['errors']['notes'] ?? '') ?></p>
                    </div>

                    <div class="form-actions">
                        <button class="primary-btn" type="submit"><i class="fa-solid fa-receipt"></i> Record payment &amp; create receipt</button>
                    </div>
                </form>
            <?php elseif (in_array($invoice['status'], ['unpaid', 'partial'], true)): ?>
                <p class="field-hint modal-note"><i class="fa-solid fa-circle-info"></i> Only a supervisor can record payments.</p>
            <?php endif; ?>
        </div>

        <footer class="modal-foot">
            <?php if ($invoice['status'] !== 'draft'): ?>
                <a class="secondary-btn" href="<?= e(url('financial', ['action' => 'print', 'id' => $invoice['id']])) ?>" target="_blank" rel="noopener">
                    <i class="fa-solid fa-print"></i> Print invoice
                </a>
            <?php endif; ?>
            <button class="primary-btn" type="button" data-modal-close>Done</button>
        </footer>
    </div>
</dialog>
