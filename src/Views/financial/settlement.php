<?php
/** financial/settlement.php - match payments to invoices and claims (not connected yet). */
view('layouts/header', ['title' => 'Settlement']);
?>
<section class="welcome-banner welcome-banner--compact">
    <div class="welcome-text">
        <h1>Settlement</h1>
        <p>Match payments against the invoices and claims they settle.</p>
    </div>
    <div class="welcome-actions">
        <a class="secondary-btn" href="<?= e(url('financial', ['action' => 'invoice'])) ?>">
            <i class="fa-solid fa-file-invoice"></i> View invoices
        </a>
    </div>
</section>

<div class="page-body">

    <?php require __DIR__ . '/_tabs.php'; ?>

    <section class="stats-grid stats-grid--3">
        <div class="stat-card green">
            <div class="stat-icon"><i class="fa-solid fa-scale-balanced"></i></div>
            <div class="stat-details"><h2>0</h2><p>Matched payments</p></div>
        </div>
        <div class="stat-card blue">
            <div class="stat-icon"><i class="fa-solid fa-sack-dollar"></i></div>
            <div class="stat-details"><h2>RM 0</h2><p>Settled this period</p></div>
        </div>
        <div class="stat-card yellow">
            <div class="stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div class="stat-details"><h2>0</h2><p>Unmatched</p></div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h2>Settlement ledger</h2>
                <p>Matched and unmatched payments will be listed here.</p>
            </div>
            <span class="badge">0 entries</span>
        </div>

        <div class="empty-state">
            <div class="empty-icon"><i class="fa-solid fa-scale-balanced"></i></div>
            <h3>Nothing to settle yet</h3>
            <p>Once invoices and claims exist, their payments can be matched here.</p>
        </div>
    </section>

</div>
<?php view('layouts/footer'); ?>
