<?php
/** financial/invoice.php - invoices issued to customers (data source not connected yet). */
view('layouts/header', ['title' => 'Invoice']);
?>
<section class="welcome-banner welcome-banner--compact">
    <div class="welcome-text">
        <h1>Invoice</h1>
        <p>Track invoices issued to customers.</p>
    </div>
    <div class="welcome-actions">
        <a class="primary-btn" href="<?= e(url('financial', ['action' => 'upload'])) ?>">
            <i class="fa-solid fa-cloud-arrow-up"></i> Upload invoice
        </a>
    </div>
</section>

<div class="page-body">

    <?php require __DIR__ . '/_tabs.php'; ?>

    <section class="stats-grid stats-grid--3">
        <div class="stat-card blue">
            <div class="stat-icon"><i class="fa-solid fa-file-invoice"></i></div>
            <div class="stat-details"><h2>0</h2><p>Issued</p></div>
        </div>
        <div class="stat-card green">
            <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
            <div class="stat-details"><h2>0</h2><p>Paid</p></div>
        </div>
        <div class="stat-card yellow">
            <div class="stat-icon"><i class="fa-solid fa-hourglass-half"></i></div>
            <div class="stat-details"><h2>0</h2><p>Outstanding</p></div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h2>All invoices</h2>
                <p>No invoice has been added yet.</p>
            </div>
            <span class="badge">0 invoices</span>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Invoice</th><th>Customer</th><th>Due</th><th>Amount</th><th>Status</th></tr>
                </thead>
            </table>
        </div>

        <div class="empty-state">
            <div class="empty-icon"><i class="fa-solid fa-file-invoice"></i></div>
            <h3>No invoices yet</h3>
            <p>Upload a supplier invoice or connect the invoice source to fill this table.</p>
            <a class="primary-btn" href="<?= e(url('financial', ['action' => 'upload'])) ?>">
                <i class="fa-solid fa-cloud-arrow-up"></i> Upload invoice
            </a>
        </div>
    </section>

</div>
<?php view('layouts/footer'); ?>
