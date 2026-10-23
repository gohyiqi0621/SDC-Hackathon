<?php
/**
 * ai_settings/company.php - Settings > Company Profile.
 *
 * The business details both assistants use (name, category, background,
 * logo, payment QR). Same form and endpoints as before
 * (ai_settings/company_profile, company_profile_save), now on its own page.
 *
 * Expects: $userId, $endpoints, $businessCategories, $customerSetupUrl, $staffSetupUrl.
 */
view('layouts/header', [
    'title'  => 'Company Profile',
    'styles' => ['assets/css/ai-settings.css'],
]);
?>
<section class="welcome-banner welcome-banner--compact">
    <div class="welcome-text">
        <h1>Company Profile</h1>
        <p>Your business details. The customer assistant, the staff assistant and your payment QR all use them.</p>
    </div>
</section>

<div class="page-body ai-settings company-page"
     data-ai-settings
     data-user-id="<?= (int) $userId ?>"
     data-endpoints="<?= e(json_encode($endpoints, JSON_UNESCAPED_SLASHES)) ?>">
    <section class="ai-panel" data-panel="company">
        <?php view('ai_settings/_company_information_form', ['businessCategories' => $businessCategories]); ?>
    </section>
    <p class="company-page__next">
        Next: <a href="<?= e($customerSetupUrl) ?>">Customer Assistant setup</a> ·
        <a href="<?= e($staffSetupUrl) ?>">Staff Assistant setup</a>
    </p>
</div>
<?php view('layouts/footer', ['scripts' => ['assets/js/ai-settings-api.js', 'assets/js/ai-settings.js']]); ?>
