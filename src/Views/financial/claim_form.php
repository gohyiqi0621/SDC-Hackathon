<?php
/**
 * financial/claim_form.php - submit or edit an expense claim.
 *
 * A form of its own, separate from Upload Invoice: who spent the money, on
 * what, how much, and the receipt.
 *
 * Expects: $claim (null when adding), $values (form values), $errors.
 */
use Src\Models\Claim;
use Src\Services\PrivateFiles;

$editing  = $claim !== null;
$title    = $editing ? 'Edit Claim' : 'New Claim';
$v        = static fn (string $key, string $default = ''): string => (string) ($values[$key] ?? $default);
$err      = static fn (string $key): string => isset($errors[$key]) ? ' class="is-invalid" aria-invalid="true"' : '';
$maxMb    = (int) (PrivateFiles::MAX_BYTES / 1024 / 1024);
$currency = $v('currency', 'MYR') ?: 'MYR';
$category = $v('category');
$hasFile  = $editing && !empty($claim['file_path']);

view('layouts/header', ['title' => $title]);
?>
<section class="welcome-banner welcome-banner--compact">
    <div class="welcome-text">
        <h1><?= $editing ? 'Edit claim' : 'New claim' ?></h1>
        <p><?= $editing ? 'Update the claim, then save.' : 'Claim back money spent for the business. Fields marked * are required.' ?></p>
    </div>
    <div class="welcome-actions">
        <a class="secondary-btn" href="<?= e(url('financial', ['action' => 'claims'])) ?>">
            <i class="fa-solid fa-arrow-left"></i> Back to claims
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

    <?php if ($editing && $claim['status'] !== 'submitted'): ?>
        <div class="flash flash--info" role="status">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <strong>This claim is <?= e(strtolower(Claim::STATUS_LABELS[$claim['status']][0] ?? $claim['status'])) ?></strong>
                <span>Saving changes keeps its current status.</span>
            </div>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" novalidate data-ajax
          action="<?= e(url('financial', ['action' => $editing ? 'claim_update' : 'claim_store'])) ?>">
        <?= csrf_field() ?>
        <?php if ($editing): ?>
            <input type="hidden" name="id" value="<?= (int) $claim['id'] ?>">
        <?php endif; ?>

        <div class="grid-main-side">

            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2>Claim details</h2>
                        <p>Who spent the money and what it was for.</p>
                    </div>
                </div>
                <div class="panel-body">
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
                </div>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2>Receipt</h2>
                        <p>PDF, JPG, PNG or WebP, up to <?= $maxMb ?> MB. Optional, but it speeds up approval.</p>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="upload-drop" data-upload-drop>
                        <div class="empty-icon"><i class="fa-solid fa-receipt"></i></div>
                        <strong data-file-label><?= $hasFile ? e($claim['file_name']) : 'Choose a receipt' ?></strong>
                        <p class="field-hint"><?= $hasFile ? 'Choose another file to replace it.' : 'Drag the receipt here, or browse. A clear photo is fine.' ?></p>
                        <label class="secondary-btn" for="receipt">
                            <i class="fa-regular fa-folder-open"></i> <?= $hasFile ? 'Replace receipt' : 'Browse files' ?>
                        </label>
                        <input id="receipt" name="receipt" type="file"
                               accept="application/pdf,image/jpeg,image/png,image/webp" data-file-input hidden>
                        <?php if ($hasFile): ?>
                            <div class="upload-current">
                                <a href="<?= e(url('financial', ['action' => 'claim_file', 'id' => $claim['id']])) ?>" target="_blank" rel="noopener">
                                    <i class="fa-regular fa-eye"></i> Open current receipt
                                </a>
                                <label class="checkbox">
                                    <input type="checkbox" name="remove_receipt" value="1"> Remove receipt
                                </label>
                            </div>
                        <?php endif; ?>
                    </div>
                    <p class="field-error" data-error-for="receipt"><?= e($errors['receipt'] ?? '') ?></p>

                    <ul class="item-list">
                        <li>
                            <span class="item-icon"><i class="fa-solid fa-paper-plane"></i></span>
                            <span><strong>Submitted</strong><small>Waiting for someone to review it</small></span>
                            <span class="badge">Step 1</span>
                        </li>
                        <li>
                            <span class="item-icon"><i class="fa-solid fa-check"></i></span>
                            <span><strong>Approved or rejected</strong><small>Reviewed from the claims list</small></span>
                            <span class="badge">Step 2</span>
                        </li>
                        <li>
                            <span class="item-icon"><i class="fa-solid fa-wallet"></i></span>
                            <span><strong>Reimbursed</strong><small>The money has been paid back</small></span>
                            <span class="badge">Step 3</span>
                        </li>
                    </ul>
                </div>
            </section>

        </div>

        <div class="form-footer">
            <a class="secondary-btn" href="<?= e(url('financial', ['action' => 'claims'])) ?>">Cancel</a>
            <button class="primary-btn btn-lg" type="submit">
                <i class="fa-solid fa-paper-plane"></i> <?= $editing ? 'Save changes' : 'Submit claim' ?>
            </button>
        </div>
    </form>

</div>
<?php view('layouts/footer'); ?>
