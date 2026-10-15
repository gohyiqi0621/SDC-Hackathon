<?php
/**
 * items/form.php - one form for create and edit.
 * $item is null when creating; $values holds what was typed (or the row) so a
 * failed submit never wipes the user's input.
 */
view('layouts/header', ['title' => $title]);

$isEdit = $item !== null;
$action = $isEdit ? url('items', ['action' => 'update']) : url('items', ['action' => 'store']);
$error  = static fn(string $field): string => $errors[$field] ?? '';
?>
<div class="page-head">
    <div>
        <h2 class="page-head__title"><?= e($title) ?></h2>
        <p class="page-head__sub"><?= $isEdit ? 'Update the details and save.' : 'Fill in the details and save.' ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= e(url('items')) ?>">Back to items</a>
    </div>
</div>

<section class="card">
    <div class="card__head">
        <div>
            <h3>Item details</h3>
            <p>A clear title is all you need. You can edit it any time.</p>
        </div>
    </div>

    <form method="post" action="<?= e($action) ?>" data-ajax>
        <?= csrf_field() ?>
        <?php if ($isEdit): ?>
            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
        <?php endif; ?>

        <div class="field">
            <label for="title">Title</label>
            <input id="title" name="title" type="text" maxlength="190" required
                   value="<?= e($values['title'] ?? '') ?>"
                   <?= $error('title') !== '' ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
            <p class="field__error" data-error-for="title"><?= e($error('title')) ?></p>
        </div>

        <div class="field">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="5" maxlength="2000"
                      <?= $error('description') !== '' ? 'class="is-invalid" aria-invalid="true"' : '' ?>><?= e($values['description'] ?? '') ?></textarea>
            <p class="field__hint">Optional, up to 2000 characters.</p>
            <p class="field__error" data-error-for="description"><?= e($error('description')) ?></p>
        </div>

        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status"
                    <?= $error('status') !== '' ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
                <?php foreach (Item::STATUSES as $option): ?>
                    <option value="<?= e($option) ?>"<?= ($values['status'] ?? '') === $option ? ' selected' : '' ?>>
                        <?= e(ucfirst($option)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field__error" data-error-for="status"><?= e($error('status')) ?></p>
        </div>

        <div class="form-actions">
            <button class="btn btn--primary" type="submit"><?= $isEdit ? 'Save changes' : 'Create item' ?></button>
            <a class="btn" href="<?= e(url('items')) ?>">Cancel</a>
        </div>
    </form>
</section>

<?php view('layouts/footer', ['scripts' => [asset('js/api.js')]]); ?>
