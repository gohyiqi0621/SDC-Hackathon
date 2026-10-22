<?php
/**
 * financial/_claim_modal.php - the "New claim" popup (also used to edit).
 *
 * Same layout as the invoice popup: the receipt with a live preview on the
 * left, the details on the right. A User confirms before submitting, because
 * they cannot change the claim afterwards.
 *
 * Expects: $modal [claim (null when adding), values, errors], $modalOpen.
 */
use Src\Models\Claim;
use Src\Services\PrivateFiles;

$claim      = $modal['claim'];
$values     = $modal['values'];
$errors     = $modal['errors'];
$editing    = $claim !== null;
$supervisor = is_supervisor();
$v          = static fn (string $key, string $default = ''): string => (string) ($values[$key] ?? $default);
$err        = static fn (string $key): string => isset($errors[$key]) ? ' class="is-invalid" aria-invalid="true"' : '';
$maxMb      = (int) (PrivateFiles::MAX_BYTES / 1024 / 1024);
$currency   = $v('currency', 'MYR') ?: 'MYR';
$category   = $v('category');
$hasFile    = $editing && !empty($claim['file_path']);
$fileUrl    = $hasFile ? url('financial', ['action' => 'claim_file', 'id' => $claim['id']]) : '';
$isPdf      = $hasFile && $claim['file_mime'] === 'application/pdf';
?>
<dialog class="modal" id="claimModal" aria-labelledby="claimModalTitle"
        <?= $modalOpen ? 'data-modal-autoopen' : '' ?>
        <?= $editing ? 'data-modal-return="' . e(url('financial', ['action' => 'claims'])) . '"' : '' ?>>
    <form class="modal-form" method="post" enctype="multipart/form-data" novalidate data-ajax
          action="<?= e(url('financial', ['action' => $editing ? 'claim_update' : 'claim_store'])) ?>"
          <?= !$supervisor ? 'data-submit-confirm="Please make sure every detail is correct. Once submitted, you cannot edit or delete this claim." data-submit-confirm-title="Submit this claim?"' : '' ?>>
        <?= csrf_field() ?>
        <?php if ($editing): ?>
            <input type="hidden" name="id" value="<?= (int) $claim['id'] ?>">
        <?php endif; ?>

        <header class="modal-head">
            <div>
                <h2 id="claimModalTitle"><?= $editing ? 'Edit claim' : 'New claim' ?></h2>
                <p><?= $editing ? 'Update the claim, then save. Its status stays the same.' : 'Claim back money spent for the business. Fields marked * are required.' ?></p>
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

            <!-- ---------------------------------------- the receipt --- -->
            <section class="modal-pane modal-pane--file" data-file-preview>
                <div class="upload-drop" data-upload-drop>
                    <div class="empty-icon"><i class="fa-solid fa-receipt"></i></div>
                    <strong data-file-label><?= $hasFile ? e($claim['file_name']) : 'Choose a receipt' ?></strong>
                    <p class="field-hint">
                        <?= $hasFile ? 'Choose another file to replace it.' : 'PDF, JPG, PNG or WebP, up to ' . $maxMb . ' MB. Optional, but it speeds up approval.' ?>
                    </p>
                    <label class="secondary-btn" for="receipt">
                        <i class="fa-regular fa-folder-open"></i> <?= $hasFile ? 'Replace receipt' : 'Browse files' ?>
                    </label>
                    <input id="receipt" name="receipt" type="file"
                           accept="application/pdf,image/jpeg,image/png,image/webp" data-file-input hidden>
                </div>
                <p class="field-error" data-error-for="receipt"><?= e($errors['receipt'] ?? '') ?></p>

                <div class="ai-fill" data-ai-fill data-ai-kind="claim"
                     data-ai-url="<?= e(url('financial', ['action' => 'extract'])) ?>"
                     <?= $hasFile ? 'data-ai-existing="' . e($fileUrl) . '"' : '' ?>>
                    <i class="fa-solid fa-wand-magic-sparkles ai-fill-icon" data-ai-icon></i>
                    <span class="ai-fill-text" data-ai-message>
                        <?= $hasFile ? 'AI can read this receipt again and refill the details.' : 'Choose a receipt and AI fills in the details for you.' ?>
                    </span>
                    <button class="secondary-btn ai-fill-btn" type="button" data-ai-run<?= $hasFile ? '' : ' hidden' ?>>
                        <i class="fa-solid fa-rotate"></i> <span data-ai-run-label><?= $hasFile ? 'Read with AI' : 'Read again' ?></span>
                    </button>
                </div>

                <div class="file-preview" data-preview-frame<?= $hasFile ? '' : ' hidden' ?>>
                    <?php if ($hasFile && $isPdf): ?>
                        <iframe src="<?= e($fileUrl) ?>" title="Receipt preview" data-preview-pdf></iframe>
                        <img alt="" data-preview-img hidden>
                    <?php elseif ($hasFile): ?>
                        <iframe title="Receipt preview" data-preview-pdf hidden></iframe>
                        <img src="<?= e($fileUrl) ?>" alt="Receipt preview" data-preview-img>
                    <?php else: ?>
                        <iframe title="Receipt preview" data-preview-pdf hidden></iframe>
                        <img alt="Receipt preview" data-preview-img hidden>
                    <?php endif; ?>
                </div>

                <div class="file-preview-empty" data-preview-empty<?= $hasFile ? ' hidden' : '' ?>>
                    <i class="fa-solid fa-receipt"></i>
                    <span>The receipt preview appears here, so you can check the amount against it.</span>
                </div>

                <?php if ($hasFile): ?>
                    <div class="upload-current">
                        <a href="<?= e($fileUrl) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-up-right-from-square"></i> Open in new tab</a>
                        <label class="checkbox"><input type="checkbox" name="remove_receipt" value="1"> Remove receipt</label>
                    </div>
                <?php endif; ?>
            </section>

            <!-- ---------------------------------------- the details --- -->
            <section class="modal-pane modal-pane--fields">
                <div class="form-row">
                    <div class="field">
                        <label for="claimant_name">Claimed by *</label>
                        <input id="claimant_name" name="claimant_name" type="text" maxlength="150" required
                               placeholder="e.g. Ali bin Abu" value="<?= e($v('claimant_name')) ?>"<?= $err('claimant_name') ?>>
                        <p class="field-error" data-error-for="claimant_name"><?= e($errors['claimant_name'] ?? '') ?></p>
                    </div>

                    <div class="field">
                        <label for="expense_date">Date spent *</label>
                        <input id="expense_date" name="expense_date" type="date" required max="<?= e(date('Y-m-d')) ?>"
                               value="<?= e($v('expense_date')) ?>"<?= $err('expense_date') ?>>
                        <p class="field-error" data-error-for="expense_date"><?= e($errors['expense_date'] ?? '') ?></p>
                    </div>
                </div>

                <div class="field">
                    <label for="title">What was it for? *</label>
                    <input id="title" name="title" type="text" maxlength="200" required
                           placeholder="e.g. Petrol for delivery to Johor" value="<?= e($v('title')) ?>"<?= $err('title') ?>>
                    <p class="field-error" data-error-for="title"><?= e($errors['title'] ?? '') ?></p>
                </div>

                <fieldset class="field">
                    <legend>Category *</legend>
                    <div class="segmented segmented--wrap">
                        <?php foreach (Claim::CATEGORIES as $key => [$label, $icon]): ?>
                            <label>
                                <input type="radio" name="category" value="<?= e($key) ?>"<?= $category === $key ? ' checked' : '' ?>>
                                <span><i class="<?= e($icon) ?>"></i> <?= e($label) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="field-error" data-error-for="category"><?= e($errors['category'] ?? '') ?></p>
                </fieldset>

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
                            <?php foreach (Claim::CURRENCIES as $code): ?>
                                <option value="<?= e($code) ?>"<?= $currency === $code ? ' selected' : '' ?>><?= e($code) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="field-error" data-error-for="currency"><?= e($errors['currency'] ?? '') ?></p>
                    </div>
                </div>

                <div class="field">
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes" rows="3" maxlength="2000"
                              placeholder="Optional, e.g. client name or trip details."<?= $err('notes') ?>><?= e($v('notes')) ?></textarea>
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
                <i class="fa-solid fa-paper-plane"></i> <?= $editing ? 'Save changes' : 'Submit claim' ?>
            </button>
        </footer>
    </form>
</dialog>
