<?php
/**
 * products/categories.php - add, edit, view and delete categories.
 *
 * Expects: $categories (with product_count), $editing (category or null),
 * $values, $errors.
 */
view('layouts/header', ['title' => 'Categories']);

$isEdit = $editing !== null;
?>
<section class="welcome-banner welcome-banner--compact">
    <div class="welcome-text">
        <h1>Categories</h1>
        <p>Group your products and services. Names are unique to your account.</p>
    </div>
    <div class="welcome-actions">
        <a class="secondary-btn" href="<?= e(url('products')) ?>"><i class="fa-solid fa-box-open"></i> View products</a>
    </div>
</section>

<div class="page-body">

    <?php require __DIR__ . '/_tabs.php'; ?>

    <div class="grid-main-side">

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2><?= count($categories) ?> categor<?= count($categories) === 1 ? 'y' : 'ies' ?></h2>
                    <p>A category can only be deleted once it has no products.</p>
                </div>
            </div>

            <?php if ($categories === []): ?>
                <div class="empty-state">
                    <div class="empty-icon"><i class="fa-solid fa-tags"></i></div>
                    <h3>No categories yet</h3>
                    <p>Add one with the form, for example "Food", "Beverages" or "Services".</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table category-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th class="num">Products</th>
                                <th class="actions"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $category): ?>
                                <?php $count = (int) $category['product_count']; ?>
                                <tr<?= $isEdit && (int) $editing['id'] === (int) $category['id'] ? ' class="is-selected"' : '' ?>>
                                    <td data-label="Name">
                                        <strong><?= e($category['name']) ?></strong>
                                        <?php if (!empty($category['description'])): ?>
                                            <small class="cell-sub"><?= e(mb_strimwidth((string) $category['description'], 0, 90, '…')) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Products" class="num">
                                        <a href="<?= e(url('products', ['category' => $category['id']])) ?>" title="View products in this category">
                                            <?= $count ?> <i class="fa-solid fa-arrow-right" style="font-size:10px"></i>
                                        </a>
                                    </td>
                                    <td class="actions">
                                        <a class="icon-btn" href="<?= e(url('categories', ['edit' => $category['id']])) ?>" title="Edit" aria-label="Edit <?= e($category['name']) ?>">
                                            <i class="fa-regular fa-pen-to-square"></i>
                                        </a>
                                        <?php if ($count > 0): ?>
                                            <button class="icon-btn" type="button" disabled
                                                    title="Move or remove its <?= $count ?> product<?= $count === 1 ? '' : 's' ?> first"
                                                    aria-label="Cannot delete <?= e($category['name']) ?>: it still has products">
                                                <i class="fa-regular fa-trash-can"></i>
                                            </button>
                                        <?php else: ?>
                                            <form method="post" action="<?= e(url('categories', ['action' => 'delete'])) ?>"
                                                  data-confirm="Delete the category &quot;<?= e($category['name']) ?>&quot;?"
                                                  data-confirm-title="Delete category?" data-confirm-ok="Delete">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= (int) $category['id'] ?>">
                                                <button class="icon-btn icon-btn--danger" type="submit" title="Delete" aria-label="Delete <?= e($category['name']) ?>">
                                                    <i class="fa-regular fa-trash-can"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2><?= $isEdit ? 'Edit category' : 'Add category' ?></h2>
                    <p><?= $isEdit ? 'Rename it or change its description.' : 'Create a new group for your products.' ?></p>
                </div>
            </div>

            <form class="panel-body" method="post" novalidate data-ajax
                  action="<?= e(url('categories', ['action' => $isEdit ? 'update' : 'store'])) ?>">
                <?= csrf_field() ?>
                <?php if ($isEdit): ?>
                    <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
                <?php endif; ?>

                <?php if (!empty($errors['form'])): ?>
                    <div class="flash flash--error" role="alert">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <div><span><?= e($errors['form']) ?></span></div>
                    </div>
                <?php endif; ?>

                <div class="field">
                    <label for="cat_name">Name *</label>
                    <input id="cat_name" name="name" type="text" maxlength="100" required
                           placeholder="e.g. Beverages" value="<?= e((string) ($values['name'] ?? '')) ?>"
                           <?= isset($errors['name']) ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
                    <p class="field-error" data-error-for="name"><?= e($errors['name'] ?? '') ?></p>
                </div>

                <div class="field">
                    <label for="cat_description">Description</label>
                    <textarea id="cat_description" name="description" rows="4"
                              placeholder="Optional"><?= e((string) ($values['description'] ?? '')) ?></textarea>
                    <p class="field-error" data-error-for="description"><?= e($errors['description'] ?? '') ?></p>
                </div>

                <div class="form-actions">
                    <button class="primary-btn" type="submit">
                        <i class="fa-solid <?= $isEdit ? 'fa-floppy-disk' : 'fa-plus' ?>"></i> <?= $isEdit ? 'Save changes' : 'Add category' ?>
                    </button>
                    <?php if ($isEdit): ?>
                        <a class="secondary-btn" href="<?= e(url('categories')) ?>">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

    </div>
</div>
<?php view('layouts/footer'); ?>
