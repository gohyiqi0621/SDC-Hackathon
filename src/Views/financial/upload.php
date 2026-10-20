<?php
/**
 * financial/upload.php - add (or edit) an invoice, with the file library beside it.
 *
 * Left: upload a PDF / image and/or type the details by hand.
 * Right: every invoice the user has, searchable, each file one click to open.
 *
 * Expects: $invoice (null when adding), $values, $errors, $library, $libraryQ, $fileCount.
 */
use Src\Models\Invoice;
use Src\Services\InvoiceService;

$editing  = $invoice !== null;
$title    = $editing ? 'Edit Invoice' : 'Upload Invoice';
$v        = static fn (string $key, string $default = ''): string => (string) ($values[$key] ?? $default);
$err      = static fn (string $key): string => isset($errors[$key]) ? ' class="is-invalid" aria-invalid="true"' : '';
$maxMb    = (int) (InvoiceService::FILE_MAX_BYTES / 1024 / 1024);
$currency = $v('currency', 'MYR') ?: 'MYR';
$status   = $v('status', 'unpaid') ?: 'unpaid';
$hasFile  = $editing && !empty($invoice['file_path']);

/** Font Awesome icon for a stored file type. */
$fileIcon = static function (?string $mime): string {
    if ($mime === 'application/pdf') {
        return 'fa-regular fa-file-pdf';
    }
    return $mime ? 'fa-regular fa-file-image' : 'fa-regular fa-keyboard';
};

view('layouts/header', ['title' => $title]);
?>
<section class="welcome-banner welcome-banner--compact">
    <div class="welcome-text">
        <h1><?= e($editing ? 'Edit invoice ' . $invoice['invoice_number'] : 'Upload Invoice') ?></h1>
        <p><?= $editing ? 'Update the details or replace the file, then save.' : 'Upload a PDF or photo of the invoice, or type the details in by hand.' ?></p>
    </div>
    <div class="welcome-actions">
        <a class="secondary-btn" href="<?= e(url('financial', ['action' => 'invoice'])) ?>">
            <i class="fa-solid fa-arrow-left"></i> Back to invoices
        </a>
    </div>
</section>

<div class="page-body">

    <?php require __DIR__ . '/_tabs.php'; ?>

    <?php if (!empty($errors['form'])): ?>
        <div class="flash flash--error" role="alert">
            <i class="fa-solid fa-circle-exclamation"></i>
            <div><strong>Not saved</strong><span><?= e($errors['form']) ?></span></div>
        </div>
    <?php endif; ?>

    <div class="grid-main-side">

        <!-- ------------------------------------------------ the form --- -->
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2><?= $editing ? 'Invoice details' : 'New invoice' ?></h2>
                    <p>Attach the file (optional) and fill in the details. Fields marked * are required.</p>
                </div>
                <?php if ($editing): ?>
                    <a class="secondary-btn" href="<?= e(url('financial', ['action' => 'upload'])) ?>">
                        <i class="fa-solid fa-plus"></i> New invoice
                    </a>
                <?php endif; ?>
            </div>

            <div class="panel-body">
                <form method="post" enctype="multipart/form-data" novalidate data-ajax
                      action="<?= e(url('financial', ['action' => $editing ? 'update' : 'store'])) ?>">
                    <?= csrf_field() ?>
                    <?php if ($editing): ?>
                        <input type="hidden" name="id" value="<?= (int) $invoice['id'] ?>">
                    <?php endif; ?>

                    <div class="upload-drop" data-upload-drop>
                        <div class="empty-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                        <strong data-file-label><?= $hasFile ? e($invoice['file_name']) : 'Choose a PDF, PNG or JPG' ?></strong>
                        <p class="field-hint">
                            <?= $hasFile
                                ? 'This invoice already has a file. Choose another one to replace it.'
                                : 'Drag a file here, or browse. Up to ' . $maxMb . ' MB. You can also skip this and type the details only.' ?>
                        </p>
                        <label class="secondary-btn" for="invoice_file">
                            <i class="fa-regular fa-folder-open"></i> <?= $hasFile ? 'Replace file' : 'Browse files' ?>
                        </label>
                        <input id="invoice_file" name="invoice_file" type="file"
                               accept="application/pdf,image/jpeg,image/png,image/webp" data-file-input hidden>
                        <?php if ($hasFile): ?>
                            <div class="upload-current">
                                <a href="<?= e(url('financial', ['action' => 'file', 'id' => $invoice['id']])) ?>" target="_blank" rel="noopener">
                                    <i class="fa-regular fa-eye"></i> Open current file
                                </a>
                                <label class="checkbox">
                                    <input type="checkbox" name="remove_file" value="1"> Remove file
                                </label>
                            </div>
                        <?php endif; ?>
                    </div>
                    <p class="field-error" data-error-for="invoice_file"><?= e($errors['invoice_file'] ?? '') ?></p>

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

                    <div class="field">
                        <label for="status">Payment status</label>
                        <select id="status" name="status"<?= $err('status') ?>>
                            <?php foreach (Invoice::STATUS_LABELS as $key => [$label]): ?>
                                <option value="<?= e($key) ?>"<?= $status === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="field-hint">Settlement will update this automatically once payments are recorded.</p>
                        <p class="field-error" data-error-for="status"><?= e($errors['status'] ?? '') ?></p>
                    </div>

                    <div class="field">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes" rows="3" maxlength="2000"
                                  placeholder="Optional, e.g. what the invoice is for."<?= $err('notes') ?>><?= e($v('notes')) ?></textarea>
                        <p class="field-error" data-error-for="notes"><?= e($errors['notes'] ?? '') ?></p>
                    </div>

                    <div class="form-actions">
                        <?php if ($editing): ?>
                            <a class="secondary-btn" href="<?= e(url('financial', ['action' => 'invoice'])) ?>">Cancel</a>
                        <?php endif; ?>
                        <button class="primary-btn" type="submit">
                            <i class="fa-regular fa-floppy-disk"></i> <?= $editing ? 'Save changes' : 'Save invoice' ?>
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <!-- --------------------------------------------- file library --- -->
        <section class="panel file-library">
            <div class="panel-header">
                <div>
                    <h2>Invoice files</h2>
                    <p>Every invoice you have saved. Click one to open its file.</p>
                </div>
                <span class="badge"><?= (int) $fileCount ?> invoice<?= $fileCount === 1 ? '' : 's' ?></span>
            </div>

            <?php if ($fileCount > 0): ?>
                <form class="toolbar" method="get" action="<?= e(url('financial', ['action' => 'upload'])) ?>" role="search">
                    <input type="hidden" name="page" value="financial">
                    <input type="hidden" name="action" value="upload">
                    <div class="toolbar-search">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <label class="sr-only" for="library-q">Search invoices</label>
                        <input id="library-q" name="q" type="search" placeholder="Search number, name or file..."
                               value="<?= e($libraryQ) ?>" data-filter-list="#invoice-files" autocomplete="off">
                    </div>
                </form>
            <?php endif; ?>

            <div class="panel-body">
                <?php if ($fileCount === 0): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fa-regular fa-folder-open"></i></div>
                        <h3>No invoices yet</h3>
                        <p>Saved invoices appear here, ready to open or search.</p>
                    </div>
                <?php else: ?>
                    <ul class="item-list file-list" id="invoice-files">
                        <?php foreach ($library as $row): ?>
                            <?php
                            [$statusLabel, $statusClass] = Invoice::STATUS_LABELS[$row['status']] ?? [ucfirst((string) $row['status']), 'badge--muted'];
                            $haystack = mb_strtolower($row['invoice_number'] . ' ' . $row['party_name'] . ' ' . ($row['file_name'] ?? ''));
                            $current  = $editing && (int) $invoice['id'] === (int) $row['id'];
                            ?>
                            <li class="<?= $current ? 'is-current' : '' ?>" data-filter-item="<?= e($haystack) ?>">
                                <span class="item-icon file-icon<?= $row['file_mime'] === 'application/pdf' ? ' file-icon--pdf' : '' ?>">
                                    <i class="<?= e($fileIcon($row['file_mime'])) ?>"></i>
                                </span>
                                <span class="file-meta">
                                    <?php if (!empty($row['file_path'])): ?>
                                        <a href="<?= e(url('financial', ['action' => 'file', 'id' => $row['id']])) ?>" target="_blank" rel="noopener">
                                            <strong><?= e($row['invoice_number']) ?></strong>
                                        </a>
                                    <?php else: ?>
                                        <strong><?= e($row['invoice_number']) ?></strong>
                                    <?php endif; ?>
                                    <small><?= e($row['party_name']) ?> · <?= e(format_money($row['amount'], $row['currency'])) ?></small>
                                    <small class="muted">
                                        <?= e(fmt_datetime($row['issue_date'], 'j M Y')) ?>
                                        · <?= !empty($row['file_path']) ? e($row['file_name']) : 'Manual entry, no file' ?>
                                    </small>
                                </span>
                                <span class="file-actions">
                                    <span class="badge <?= e($statusClass) ?>"><?= e($statusLabel) ?></span>
                                    <?php if (!empty($row['file_path'])): ?>
                                        <a class="icon-btn" href="<?= e(url('financial', ['action' => 'file', 'id' => $row['id'], 'download' => 1])) ?>"
                                           title="Download" aria-label="Download <?= e($row['invoice_number']) ?>">
                                            <i class="fa-solid fa-download"></i>
                                        </a>
                                    <?php endif; ?>
                                    <a class="icon-btn" href="<?= e(url('financial', ['action' => 'edit', 'id' => $row['id']])) ?>"
                                       title="Edit" aria-label="Edit <?= e($row['invoice_number']) ?>">
                                        <i class="fa-regular fa-pen-to-square"></i>
                                    </a>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <div class="empty-state" data-filter-empty<?= $library === [] ? '' : ' hidden' ?>>
                        <div class="empty-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
                        <h3>No matches</h3>
                        <p>Try another invoice number, name or file name.</p>
                    </div>

                    <?php if (count($library) >= 50): ?>
                        <p class="field-hint">Showing the 50 newest. Search to find older ones, or open the
                            <a href="<?= e(url('financial', ['action' => 'invoice'])) ?>">full invoice list</a>.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>

    </div>

</div>
<?php view('layouts/footer'); ?>
