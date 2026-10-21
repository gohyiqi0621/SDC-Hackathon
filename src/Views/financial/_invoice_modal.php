<?php
/**
 * financial/_invoice_modal.php - the "Upload invoice" popup (also used to edit).
 *
 * Left: the file, with a live preview. Right: the details. A User gets a
 * confirmation step before submitting (they cannot change it afterwards) and
 * no payment-status field; a Supervisor can set the status and edit later.
 *
 * Expects: $modal [invoice (null when adding), values, errors], $modalOpen.
 */
use Src\Models\Invoice;
use Src\Services\InvoiceService;

$invoice    = $modal['invoice'];
$values     = $modal['values'];
$errors     = $modal['errors'];
$editing    = $invoice !== null;
$supervisor = is_supervisor();
$v          = static fn (string $key, string $default = ''): string => (string) ($values[$key] ?? $default);
$err        = static fn (string $key): string => isset($errors[$key]) ? ' class="is-invalid" aria-invalid="true"' : '';
$maxMb      = (int) (InvoiceService::FILE_MAX_BYTES / 1024 / 1024);
$currency   = $v('currency', 'MYR') ?: 'MYR';
$status     = $v('status', 'unpaid') ?: 'unpaid';
$hasFile    = $editing && !empty($invoice['file_path']);
$fileUrl    = $hasFile ? url('financial', ['action' => 'file', 'id' => $invoice['id']]) : '';
$isPdf      = $hasFile && $invoice['file_mime'] === 'application/pdf';
?>
<dialog class="modal" id="invoiceModal" aria-labelledby="invoiceModalTitle"
        <?= $modalOpen ? 'data-modal-autoopen' : '' ?>
        <?= $editing ? 'data-modal-return="' . e(url('financial', ['action' => 'invoice'])) . '"' : '' ?>>
    <form class="modal-form" method="post" enctype="multipart/form-data" novalidate data-ajax
          action="<?= e(url('financial', ['action' => $editing ? 'update' : 'store'])) ?>"
          <?= !$supervisor ? 'data-submit-confirm="Please make sure every detail is correct. Once submitted, you cannot edit or delete this invoice." data-submit-confirm-title="Submit this invoice?"' : '' ?>>
        <?= csrf_field() ?>
        <?php if ($editing): ?>
            <input type="hidden" name="id" value="<?= (int) $invoice['id'] ?>">
        <?php endif; ?>

        <header class="modal-head">
            <div>
                <h2 id="invoiceModalTitle"><?= $editing ? 'Edit invoice ' . e($invoice['invoice_number']) : 'Upload invoice' ?></h2>
                <p><?= $editing ? 'Update the details or replace the file, then save.' : 'Attach the invoice file and fill in its details. Fields marked * are required.' ?></p>
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

        <div class="modal-body">

            <!-- ------------------------------------------- the file --- -->
            <section class="modal-pane modal-pane--file" data-file-preview>
                <div class="upload-drop" data-upload-drop>
                    <div class="empty-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                    <strong data-file-label><?= $hasFile ? e($invoice['file_name']) : 'Choose a PDF, PNG or JPG' ?></strong>
                    <p class="field-hint">
                        <?= $hasFile ? 'Choose another file to replace it.' : 'Drag the file here, or browse. Up to ' . $maxMb . ' MB. Optional.' ?>
                    </p>
                    <label class="secondary-btn" for="invoice_file">
                        <i class="fa-regular fa-folder-open"></i> <?= $hasFile ? 'Replace file' : 'Browse files' ?>
                    </label>
                    <input id="invoice_file" name="invoice_file" type="file"
                           accept="application/pdf,image/jpeg,image/png,image/webp" data-file-input hidden>
                </div>
                <p class="field-error" data-error-for="invoice_file"><?= e($errors['invoice_file'] ?? '') ?></p>

                <div class="file-preview" data-preview-frame<?= $hasFile ? '' : ' hidden' ?>>
                    <?php if ($hasFile && $isPdf): ?>
                        <iframe src="<?= e($fileUrl) ?>" title="Invoice file preview" data-preview-pdf></iframe>
                        <img alt="" data-preview-img hidden>
                    <?php elseif ($hasFile): ?>
                        <iframe title="Invoice file preview" data-preview-pdf hidden></iframe>
                        <img src="<?= e($fileUrl) ?>" alt="Invoice file preview" data-preview-img>
                    <?php else: ?>
                        <iframe title="Invoice file preview" data-preview-pdf hidden></iframe>
                        <img alt="Invoice file preview" data-preview-img hidden>
                    <?php endif; ?>
                </div>

                <div class="file-preview-empty" data-preview-empty<?= $hasFile ? ' hidden' : '' ?>>
                    <i class="fa-regular fa-file-lines"></i>
                    <span>The file preview appears here, so you can check the details against it.</span>
                </div>

                <?php if ($hasFile): ?>
                    <div class="upload-current">
                        <a href="<?= e($fileUrl) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-up-right-from-square"></i> Open in new tab</a>
                        <label class="checkbox"><input type="checkbox" name="remove_file" value="1"> Remove file</label>
                    </div>
                <?php endif; ?>
            </section>

            <!-- ---------------------------------------- the details --- -->
            <section class="modal-pane modal-pane--fields">
                <div class="form-row">
                    <div class="field">
                        <label for="invoice_number">Invoice number *</label>
                        <input id="invoice_number" name="invoice_number" type="text" maxlength="100" required
                               placeholder="e.g. INV-2026-104" value="<?= e($v('invoice_number')) ?>"<?= $err('invoice_number') ?>>
                        <p class="field-error" data-error-for="invoice_number"><?= e($errors['invoice_number'] ?? '') ?></p>
                    </div>

                    <div class="field">
                        <label for="party_name">Customer / supplier *</label>
                        <input id="party_name" name="party_name" type="text" maxlength="200" required
                               placeholder="e.g. ABC Supplier Sdn Bhd" value="<?= e($v('party_name')) ?>"<?= $err('party_name') ?>>
                        <p class="field-error" data-error-for="party_name"><?= e($errors['party_name'] ?? '') ?></p>
                    </div>
                </div>

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
                        <label for="amount">Amount *</label>
                        <input id="amount" name="amount" type="text" inputmode="decimal" required data-money
                               autocomplete="off" placeholder="0.00" value="<?= e($v('amount')) ?>"<?= $err('amount') ?>>
                        <p class="field-error" data-error-for="amount"><?= e($errors['amount'] ?? '') ?></p>
                    </div>

                    <div class="field">
                        <label for="currency">Currency</label>
                        <select id="currency" name="currency"<?= $err('currency') ?>>
                            <?php foreach (Invoice::CURRENCIES as $code): ?>
                                <option value="<?= e($code) ?>"<?= $currency === $code ? ' selected' : '' ?>><?= e($code) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="field-error" data-error-for="currency"><?= e($errors['currency'] ?? '') ?></p>
                    </div>
                </div>

                <?php if ($supervisor): ?>
                    <div class="field">
                        <label for="status">Payment status</label>
                        <select id="status" name="status"<?= $err('status') ?>>
                            <?php foreach (Invoice::STATUS_LABELS as $key => [$label]): ?>
                                <option value="<?= e($key) ?>"<?= $status === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="field-error" data-error-for="status"><?= e($errors['status'] ?? '') ?></p>
                    </div>
                <?php else: ?>
                    <p class="field-hint modal-note">
                        <i class="fa-solid fa-circle-info"></i>
                        New invoices start as <strong>Unpaid</strong>. A supervisor updates the payment status.
                    </p>
                <?php endif; ?>

                <div class="field">
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes" rows="3" maxlength="2000"
                              placeholder="Optional, e.g. what the invoice is for."<?= $err('notes') ?>><?= e($v('notes')) ?></textarea>
                    <p class="field-error" data-error-for="notes"><?= e($errors['notes'] ?? '') ?></p>
                </div>
            </section>
        </div>

        <footer class="modal-foot">
            <?php if (!$supervisor): ?>
                <span class="modal-foot-note"><i class="fa-solid fa-lock"></i> You can't change it after submitting.</span>
            <?php endif; ?>
            <button class="secondary-btn" type="button" data-modal-close>Cancel</button>
            <button class="primary-btn" type="submit">
                <i class="fa-regular fa-floppy-disk"></i> <?= $editing ? 'Save changes' : ($supervisor ? 'Save invoice' : 'Submit invoice') ?>
            </button>
        </footer>
    </form>
</dialog>
