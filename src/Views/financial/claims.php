<?php
/** financial/claims.php - expense claims (data source not connected yet). */
view('layouts/header', ['title' => 'Claims']);
?>
<section class="welcome-banner welcome-banner--compact">
    <div class="welcome-text">
        <h1>Claims</h1>
        <p>Keep expense claims and supporting documents in one place.</p>
    </div>
    <div class="welcome-actions">
        <a class="secondary-btn" href="<?= e(url('financial', ['action' => 'upload'])) ?>">
            <i class="fa-solid fa-paperclip"></i> Add document
        </a>
    </div>
</section>

<div class="page-body">

    <?php require __DIR__ . '/_tabs.php'; ?>

    <section class="stats-grid stats-grid--3">
        <div class="stat-card blue">
            <div class="stat-icon"><i class="fa-solid fa-receipt"></i></div>
            <div class="stat-details"><h2>0</h2><p>Submitted</p></div>
        </div>
        <div class="stat-card green">
            <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
            <div class="stat-details"><h2>0</h2><p>Approved</p></div>
        </div>
        <div class="stat-card pink">
            <div class="stat-icon"><i class="fa-solid fa-wallet"></i></div>
            <div class="stat-details"><h2>0</h2><p>Reimbursed</p></div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h2>Claims timeline</h2>
                <p>Every claim will show its status here.</p>
            </div>
            <span class="badge">0 claims</span>
        </div>

        <div class="empty-state">
            <div class="empty-icon"><i class="fa-solid fa-receipt"></i></div>
            <h3>No claims yet</h3>
            <p>When a claim is submitted it will appear here with its receipts and status.</p>
            <a class="secondary-btn" href="<?= e(url('financial', ['action' => 'upload'])) ?>">
                <i class="fa-solid fa-paperclip"></i> Add supporting document
            </a>
        </div>
    </section>

</div>
<?php view('layouts/footer'); ?>
