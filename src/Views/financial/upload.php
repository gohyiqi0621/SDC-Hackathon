<?php
/** financial/upload.php - upload a supplier invoice (saving and reading the file is the next step). */
view('layouts/header', ['title' => 'Upload Invoice']);
?>
<section class="welcome-banner welcome-banner--compact">
    <div class="welcome-text">
        <h1>Upload Invoice</h1>
        <p>Add a supplier invoice so it can be reconciled.</p>
    </div>
    <div class="welcome-actions">
        <a class="secondary-btn" href="<?= e(url('financial', ['action' => 'invoice'])) ?>">
            <i class="fa-solid fa-arrow-left"></i> Back to invoices
        </a>
    </div>
</section>

<div class="page-body">

    <?php require __DIR__ . '/_tabs.php'; ?>

    <div class="flash flash--warning" role="status">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div>
            <strong>Upload processing is being connected</strong>
            <span>The screen is ready. Saving the file and reading its details is the next integration step.</span>
        </div>
    </div>

    <div class="grid-main-side">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>Invoice file</h2>
                    <p>PDF, PNG or JPG, up to 10 MB.</p>
                </div>
            </div>

            <div class="panel-body">
                <div class="upload-drop" data-upload-drop>
                    <div class="empty-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                    <strong data-file-label>Choose a PDF, PNG or JPG</strong>
                    <p class="field-hint">Drag a file here, or browse. A clear photo or a PDF of the full invoice works best.</p>
                    <label class="secondary-btn" for="invoice_file"><i class="fa-regular fa-folder-open"></i> Browse files</label>
                    <input id="invoice_file" name="invoice_file" type="file" accept="application/pdf,image/*" data-file-input hidden>
                </div>

                <form method="post" action="<?= e(url('financial', ['action' => 'upload'])) ?>">
                    <?= csrf_field() ?>

                    <div class="field">
                        <label for="invoice_number">Invoice number</label>
                        <input id="invoice_number" name="invoice_number" type="text" placeholder="INV-2026-104" disabled>
                    </div>

                    <div class="field">
                        <label for="supplier">Supplier</label>
                        <input id="supplier" name="supplier" type="text" placeholder="Supplier name" disabled>
                    </div>

                    <div class="field">
                        <label for="amount">Amount (RM)</label>
                        <input id="amount" name="amount" type="text" inputmode="decimal" placeholder="0.00" disabled>
                    </div>

                    <div class="form-actions">
                        <button class="primary-btn" type="submit" disabled>
                            <i class="fa-solid fa-cloud-arrow-up"></i> Upload invoice
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>What happens next</h2>
                    <p>The upload will feed the invoice workspace.</p>
                </div>
            </div>
            <div class="panel-body">
                <ul class="item-list">
                    <li>
                        <span class="item-icon"><i class="fa-solid fa-lock"></i></span>
                        <span><strong>File is stored</strong><small>Private to your account</small></span>
                        <span class="badge">Step 1</span>
                    </li>
                    <li>
                        <span class="item-icon"><i class="fa-solid fa-wand-magic-sparkles"></i></span>
                        <span><strong>Details are read</strong><small>Number, supplier, amount and due date</small></span>
                        <span class="badge">Step 2</span>
                    </li>
                    <li>
                        <span class="item-icon"><i class="fa-solid fa-link"></i></span>
                        <span><strong>Invoice is matched</strong><small>It appears in the invoice list</small></span>
                        <span class="badge">Step 3</span>
                    </li>
                </ul>
            </div>
        </section>
    </div>

</div>
<?php view('layouts/footer'); ?>
