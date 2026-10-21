<?php
/**
 * products/index.php - the signed-in user's products and services.
 *
 * Expects: $products, $total (matching filters), $allCount (all of the user's
 * products), $categories, $filters [q, category, status, sort], $page, $pages, $perPage.
 */
view('layouts/header', ['title' => 'Product & Service']);

$sorts     = \Src\Models\Product::SORTS;
$filtered  = $filters['q'] !== '' || $filters['category'] > 0 || $filters['status'] !== '';
$listQuery = array_filter([
    'q'        => $filters['q'],
    'category' => $filters['category'] ?: null,
    'status'   => $filters['status'],
    'sort'     => $filters['sort'] !== 'newest' ? $filters['sort'] : null,
], static fn ($v) => $v !== null && $v !== '');
$listUrl   = url('products', $listQuery + ($page > 1 ? ['p' => $page] : []));
$from      = $total === 0 ? 0 : ($page - 1) * $perPage + 1;
$to        = min($total, $page * $perPage);
?>
<section class="welcome-banner welcome-banner--compact">
    <div class="welcome-text">
        <h1>Product &amp; Service</h1>
        <p>Manage what you sell. The customer assistant answers from this list.</p>
    </div>
    <div class="welcome-actions">
        <button class="secondary-btn" type="button" data-import-open>
            <i class="fa-solid fa-file-import"></i> Import products
        </button>
        <a class="primary-btn" href="<?= e(url('products', ['action' => 'create'])) ?>">
            <i class="fa-solid fa-plus"></i> Add product / service
        </a>
    </div>
</section>

<div class="page-body">

    <?php require __DIR__ . '/_tabs.php'; ?>

    <?php if ($categories === []): ?>
        <div class="flash flash--info" role="status">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <strong>Start with a category</strong>
                <span>Every product belongs to a category, for example "Food", "Services" or "Accessories".
                    <a href="<?= e(url('categories')) ?>">Create your first category</a>.</span>
            </div>
        </div>
    <?php endif; ?>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h2><?= (int) $allCount ?> product<?= $allCount === 1 ? '' : 's' ?> &amp; service<?= $allCount === 1 ? '' : 's' ?></h2>
                <p>
                    <?php if ($total > 0): ?>
                        Showing <?= $from ?>–<?= $to ?> of <?= (int) $total ?><?= $filtered ? ' matching' : '' ?>
                    <?php elseif ($allCount > 0): ?>
                        Nothing matches these filters.
                    <?php else: ?>
                        Nothing added yet.
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <?php if ($allCount > 0): ?>
            <form class="toolbar" method="get" action="<?= e(url('products')) ?>" data-auto-submit>
                <input type="hidden" name="page" value="products">

                <div class="toolbar-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <label class="sr-only" for="q">Search</label>
                    <input id="q" name="q" type="search" placeholder="Search name or SKU..." value="<?= e($filters['q']) ?>">
                </div>

                <label class="sr-only" for="category">Category</label>
                <select id="category" name="category">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['id'] ?>"<?= $filters['category'] === (int) $category['id'] ? ' selected' : '' ?>>
                            <?= e($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label class="sr-only" for="status">Status</label>
                <select id="status" name="status">
                    <option value="">All statuses</option>
                    <option value="active"<?= $filters['status'] === 'active' ? ' selected' : '' ?>>Active</option>
                    <option value="inactive"<?= $filters['status'] === 'inactive' ? ' selected' : '' ?>>Inactive</option>
                </select>

                <label class="sr-only" for="sort">Sort by</label>
                <select id="sort" name="sort">
                    <?php foreach ($sorts as $key => [$label]): ?>
                        <option value="<?= e($key) ?>"<?= $filters['sort'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <button class="secondary-btn" type="submit"><i class="fa-solid fa-filter"></i> Apply</button>
                <?php if ($filtered || $filters['sort'] !== 'newest'): ?>
                    <a class="toolbar-reset" href="<?= e(url('products')) ?>">Reset</a>
                <?php endif; ?>
            </form>
        <?php endif; ?>

        <?php if ($allCount === 0): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fa-solid fa-box-open"></i></div>
                <h3>No products or services yet</h3>
                <p>Add what you sell, with prices and details, so the customer assistant can answer questions about it.</p>
                <a class="primary-btn" href="<?= e($categories === [] ? url('categories') : url('products', ['action' => 'create'])) ?>">
                    <i class="fa-solid fa-plus"></i> <?= $categories === [] ? 'Create a category first' : 'Add your first product' ?>
                </a>
            </div>

        <?php elseif ($total === 0): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
                <h3>No matches</h3>
                <p>Try another search or clear the filters.</p>
                <a class="secondary-btn" href="<?= e(url('products')) ?>">Clear filters</a>
            </div>

        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table product-table">
                    <thead>
                        <tr>
                            <th>Product / service</th>
                            <th>SKU</th>
                            <th>Category</th>
                            <th class="num">Price</th>
                            <th>Status</th>
                            <th class="actions"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td data-label="Product">
                                    <div class="product-cell">
                                        <?php if (!empty($product['image_url'])): ?>
                                            <img class="product-thumb" src="<?= e(asset($product['image_url'])) ?>" alt="" loading="lazy">
                                        <?php else: ?>
                                            <span class="product-thumb product-thumb--empty"><i class="fa-regular fa-image"></i></span>
                                        <?php endif; ?>
                                        <span>
                                            <strong><?= e($product['name']) ?></strong>
                                            <?php if (!empty($product['description'])): ?>
                                                <small><?= e(mb_strimwidth((string) $product['description'], 0, 70, '…')) ?></small>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </td>
                                <td data-label="SKU"><?= $product['sku'] !== null ? e($product['sku']) : '<span class="muted">—</span>' ?></td>
                                <td data-label="Category">
                                    <a class="badge" href="<?= e(url('products', ['category' => $product['category_id']])) ?>"><?= e($product['category_name']) ?></a>
                                </td>
                                <td data-label="Price" class="num">
                                    <?= e(format_money($product['price'], $product['currency'])) ?>
                                </td>
                                <td data-label="Status">
                                    <?php if ($product['status'] === 'active'): ?>
                                        <span class="badge badge--success"><i class="fa-solid fa-circle" style="font-size:6px"></i> Active</span>
                                    <?php else: ?>
                                        <span class="badge badge--muted">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions">
                                    <a class="icon-btn" href="<?= e(url('products', ['action' => 'edit', 'id' => $product['id']])) ?>" title="Edit" aria-label="Edit <?= e($product['name']) ?>">
                                        <i class="fa-regular fa-pen-to-square"></i>
                                    </a>
                                    <form method="post" action="<?= e(url('products', ['action' => 'delete'])) ?>"
                                          data-confirm="Remove &quot;<?= e($product['name']) ?>&quot;? This cannot be undone."
                                          data-confirm-title="Remove product?" data-confirm-ok="Remove">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                                        <input type="hidden" name="return" value="<?= e($listUrl) ?>">
                                        <button class="icon-btn icon-btn--danger" type="submit" title="Remove" aria-label="Remove <?= e($product['name']) ?>">
                                            <i class="fa-regular fa-trash-can"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($pages > 1): ?>
                <nav class="pagination" aria-label="Pages">
                    <?php if ($page > 1): ?>
                        <a href="<?= e(url('products', $listQuery + ['p' => $page - 1])) ?>" aria-label="Previous page"><i class="fa-solid fa-chevron-left"></i></a>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $pages; $i++): ?>
                        <?php if ($i === 1 || $i === $pages || abs($i - $page) <= 1): ?>
                            <a href="<?= e(url('products', $listQuery + ['p' => $i])) ?>"<?= $i === $page ? ' class="active" aria-current="page"' : '' ?>><?= $i ?></a>
                        <?php elseif (abs($i - $page) === 2): ?>
                            <span>…</span>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($page < $pages): ?>
                        <a href="<?= e(url('products', $listQuery + ['p' => $page + 1])) ?>" aria-label="Next page"><i class="fa-solid fa-chevron-right"></i></a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>

</div>
<!-- Import products from CSV / XLSX (logic in assets/js/product-import.js) -->
<dialog class="import-dialog" id="importDialog" aria-labelledby="importTitle"
        data-preview-url="<?= e(url('products', ['action' => 'import_preview'])) ?>"
        data-commit-url="<?= e(url('products', ['action' => 'import_commit'])) ?>"
        data-cancel-url="<?= e(url('products', ['action' => 'import_cancel'])) ?>">

    <!-- Step 1: choose a file -->
    <section class="import-step" data-step="upload">
        <div class="import-head">
            <div>
                <h3 id="importTitle">Import products</h3>
                <p>Upload a CSV or Excel (.xlsx) file. Columns are detected automatically from the header row.</p>
            </div>
            <button class="icon-btn" type="button" data-import-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <label class="upload-drop import-drop" data-import-drop>
            <i class="fa-solid fa-file-csv import-drop-icon"></i>
            <strong data-import-file-label>Drop your file here or click to browse</strong>
            <span class="muted">.csv or .xlsx, up to 5 MB and <?= number_format(\Src\Services\ProductImportService::MAX_ROWS) ?> rows</span>
            <input type="file" name="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" data-import-file hidden>
        </label>
        <p class="field-error" data-import-error></p>

        <div class="import-template" style="margin-top:8px;font-size:12px">
            <a href="<?= e(url('products', ['action' => 'import_template'])) ?>"><i class="fa-solid fa-download"></i> Download a template</a>
        </div>
    </section>

    <!-- Step 2: new categories found -->
    <section class="import-step import-ask" data-step="categories" hidden>
        <div class="confirm-icon import-ask-icon"><i class="fa-solid fa-folder-plus"></i></div>
        <h3>New categories found</h3>
        <p>Some products in <strong data-import-filename></strong> belong to categories you don't have yet:</p>
        <div class="import-chips" data-import-new-categories></div>
        <p><strong>Do you want me to help you add these new categories?</strong><br>
            <span class="muted">If not, the import will be cancelled.</span></p>
        <div class="confirm-actions">
            <button class="secondary-btn" type="button" data-import-decline>No, cancel import</button>
            <button class="primary-btn" type="button" data-import-accept><i class="fa-solid fa-check"></i> Yes, add categories</button>
        </div>
    </section>

    <!-- Step 3: review and import -->
    <section class="import-step" data-step="review" hidden>
        <div class="import-head">
            <div>
                <h3>Review import</h3>
                <p><i class="fa-regular fa-file"></i> <span data-import-filename></span></p>
            </div>
            <button class="icon-btn" type="button" data-import-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div class="import-summary" data-import-summary></div>

        <div data-import-categories-note hidden class="flash flash--info">
            <i class="fa-solid fa-folder-plus"></i>
            <div><strong>New categories will be added</strong><span data-import-categories-list></span></div>
        </div>

        <div data-import-sample-wrap hidden>
            <h4 class="import-subtitle">Preview (first rows)</h4>
            <div class="table-wrap import-table">
                <table class="data-table">
                    <thead><tr><th>Name</th><th>SKU</th><th>Category</th><th class="num">Price</th><th>Status</th></tr></thead>
                    <tbody data-import-sample></tbody>
                </table>
            </div>
        </div>

        <details class="import-skipped" data-import-skipped-wrap hidden>
            <summary data-import-skipped-title></summary>
            <ul data-import-skipped></ul>
        </details>

        <div class="import-foot">
            <button class="secondary-btn" type="button" data-import-restart><i class="fa-solid fa-arrow-left"></i> Choose another file</button>
            <button class="primary-btn" type="button" data-import-commit><i class="fa-solid fa-file-import"></i> Import</button>
        </div>
    </section>
</dialog>

<?php view('layouts/footer', ['scripts' => ['assets/js/product-import.js']]); ?>
