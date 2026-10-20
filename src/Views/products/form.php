<?php
/**
 * products/form.php - add or edit a product / service.
 *
 * Expects: $product (null when adding), $values (form values), $errors, $categories.
 */
$editing  = $product !== null;
$title    = $editing ? 'Edit Product / Service' : 'Add Product / Service';
$v        = static fn (string $key, string $default = ''): string => (string) ($values[$key] ?? $default);
$err      = static fn (string $key): string => isset($errors[$key]) ? ' class="is-invalid" aria-invalid="true"' : '';
$image    = $values['image_url'] ?? ($product['image_url'] ?? null);
$maxMb    = (int) (\Src\Services\ProductService::IMAGE_MAX_BYTES / 1024 / 1024);
$currency = $v('currency', 'MYR') ?: 'MYR';

view('layouts/header', ['title' => $title]);
?>
<section class="welcome-banner welcome-banner--compact">
    <div class="welcome-text">
        <h1><?= e($editing ? 'Edit ' . $product['name'] : 'Add product / service') ?></h1>
        <p><?= $editing ? 'Update the details and save your changes.' : 'Fill in the details. Fields marked * are required.' ?></p>
    </div>
    <div class="welcome-actions">
        <a class="secondary-btn" href="<?= e(url('products')) ?>"><i class="fa-solid fa-arrow-left"></i> Back to list</a>
    </div>
</section>

<div class="page-body">

    <?php if ($categories === []): ?>
        <div class="flash flash--warning" role="status">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div>
                <strong>You need a category first</strong>
                <span>Every product belongs to a category. <a href="<?= e(url('categories')) ?>">Create a category</a>, then come back.</span>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors['form'])): ?>
        <div class="flash flash--error" role="alert">
            <i class="fa-solid fa-circle-exclamation"></i>
            <div><strong>Not saved</strong><span><?= e($errors['form']) ?></span></div>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" novalidate data-ajax
          action="<?= e(url('products', ['action' => $editing ? 'update' : 'store'])) ?>">
        <?= csrf_field() ?>
        <?php if ($editing): ?>
            <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
        <?php endif; ?>

        <div class="grid-main-side">

            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2>Details</h2>
                        <p>What it is and how you describe it to customers.</p>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="field">
                        <label for="name">Product / service name *</label>
                        <input id="name" name="name" type="text" maxlength="200" required
                               placeholder="e.g. Nasi Lemak Set A" value="<?= e($v('name')) ?>"<?= $err('name') ?>>
                        <p class="field-error" data-error-for="name"><?= e($errors['name'] ?? '') ?></p>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label for="sku">SKU / product code</label>
                            <input id="sku" name="sku" type="text" maxlength="100"
                                   placeholder="Optional, e.g. NL-A-001" value="<?= e($v('sku')) ?>"<?= $err('sku') ?>>
                            <p class="field-error" data-error-for="sku"><?= e($errors['sku'] ?? '') ?></p>
                        </div>

                        <div class="field">
                            <div class="field-label-row">
                                <label for="category_id">Category *</label>
                                <a href="<?= e(url('categories')) ?>">Manage</a>
                            </div>
                            <select id="category_id" name="category_id" required<?= $err('category_id') ?>>
                                <option value="">Choose a category</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= (int) $category['id'] ?>"<?= (int) $v('category_id') === (int) $category['id'] ? ' selected' : '' ?>>
                                        <?= e($category['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="field-error" data-error-for="category_id"><?= e($errors['category_id'] ?? '') ?></p>
                        </div>
                    </div>

                    <div class="field">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" rows="4"
                                  placeholder="A short description customers will read."<?= $err('description') ?>><?= e($v('description')) ?></textarea>
                        <p class="field-error" data-error-for="description"><?= e($errors['description'] ?? '') ?></p>
                    </div>

                    <div class="field">
                        <label for="characteristics">Characteristics</label>
                        <textarea id="characteristics" name="characteristics" rows="7"
                                  placeholder="Anything useful: size, ingredients, materials, warranty, specifications...&#10;- One per line works well&#10;- Or write full sentences"<?= $err('characteristics') ?>><?= e($v('characteristics')) ?></textarea>
                        <p class="field-hint">Free text. Write sentences, specifications or bullet points.</p>
                        <p class="field-error" data-error-for="characteristics"><?= e($errors['characteristics'] ?? '') ?></p>
                    </div>
                </div>
            </section>

            <div class="stack">
                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Pricing &amp; status</h2>
                            <p>Prices cannot be negative.</p>
                        </div>
                    </div>
                    <div class="panel-body">
                        <div class="field">
                            <label for="currency">Currency</label>
                            <select id="currency" name="currency"<?= $err('currency') ?>>
                                <?php foreach (\Src\Models\Product::CURRENCIES as $code): ?>
                                    <option value="<?= e($code) ?>"<?= $currency === $code ? ' selected' : '' ?>><?= e($code) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="field-error" data-error-for="currency"><?= e($errors['currency'] ?? '') ?></p>
                        </div>

                        <div class="field">
                            <label for="price">Selling price *</label>
                            <input id="price" name="price" type="text" inputmode="decimal" required
                                   placeholder="0.00" value="<?= e($v('price')) ?>"<?= $err('price') ?>>
                            <p class="field-error" data-error-for="price"><?= e($errors['price'] ?? '') ?></p>
                        </div>

                        <div class="field">
                            <label for="promotion_price">Promotional price</label>
                            <input id="promotion_price" name="promotion_price" type="text" inputmode="decimal"
                                   placeholder="Optional" value="<?= e($v('promotion_price')) ?>"<?= $err('promotion_price') ?>>
                            <p class="field-hint">Leave empty when there is no promotion. Cannot be higher than the selling price.</p>
                            <p class="field-error" data-error-for="promotion_price"><?= e($errors['promotion_price'] ?? '') ?></p>
                        </div>

                        <fieldset class="field">
                            <legend>Status</legend>
                            <div class="segmented">
                                <label>
                                    <input type="radio" name="status" value="active"<?= $v('status', 'active') !== 'inactive' ? ' checked' : '' ?>>
                                    <span><i class="fa-solid fa-circle-check"></i> Active</span>
                                </label>
                                <label>
                                    <input type="radio" name="status" value="inactive"<?= $v('status') === 'inactive' ? ' checked' : '' ?>>
                                    <span><i class="fa-regular fa-circle-pause"></i> Inactive</span>
                                </label>
                            </div>
                            <p class="field-hint">Inactive items stay saved but the assistant will not offer them.</p>
                            <p class="field-error" data-error-for="status"><?= e($errors['status'] ?? '') ?></p>
                        </fieldset>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Image</h2>
                            <p>JPG, PNG, WebP or GIF, up to <?= $maxMb ?> MB.</p>
                        </div>
                    </div>
                    <div class="panel-body">
                        <div class="image-picker" data-image-picker>
                            <div class="image-preview">
                                <img src="<?= $image ? e(asset($image)) : '' ?>" alt="" data-image-preview<?= $image ? '' : ' hidden' ?>>
                                <span class="image-placeholder" data-image-placeholder<?= $image ? ' hidden' : '' ?>><i class="fa-regular fa-image"></i></span>
                            </div>
                            <div class="image-actions">
                                <label class="secondary-btn" for="image"><i class="fa-solid fa-upload"></i> <?= $image ? 'Replace image' : 'Choose image' ?></label>
                                <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp,image/gif" hidden data-image-input>
                                <?php if ($editing && $image): ?>
                                    <label class="checkbox">
                                        <input type="checkbox" name="remove_image" value="1" data-image-remove> Remove image
                                    </label>
                                <?php endif; ?>
                            </div>
                        </div>
                        <p class="field-error" data-error-for="image"><?= e($errors['image'] ?? '') ?></p>
                    </div>
                </section>
            </div>

        </div>

        <div class="form-footer">
            <a class="secondary-btn" href="<?= e(url('products')) ?>">Cancel</a>
            <button class="primary-btn btn-lg" type="submit"<?= $categories === [] ? ' disabled' : '' ?>>
                <i class="fa-regular fa-floppy-disk"></i> <?= $editing ? 'Save changes' : 'Add product / service' ?>
            </button>
        </div>
    </form>

</div>
<?php view('layouts/footer'); ?>
