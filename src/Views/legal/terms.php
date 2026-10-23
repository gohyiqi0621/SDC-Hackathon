<?php
/**
 * legal/terms.php - the public Terms of Service (no sign-in).
 * Linked from Google's OAuth consent screen and from Create account.
 */
use Src\Controllers\LegalController;

$email = LegalController::CONTACT_EMAIL;
view('layouts/header', ['title' => 'Terms of Service', 'bare' => true, 'wide' => true]);
?>
<article class="auth-card legal">
    <header class="legal-head">
        <h1>Terms of Service</h1>
        <p>Last updated: <?= e(LegalController::UPDATED) ?></p>
    </header>

    <p>
        These Terms of Service ("Terms") apply when you use <?= e(APP_NAME) ?> (the "Service"), built by team
        CTRL + Future ("we", "us"). By creating an account or using the Service, you agree to these Terms and to our
        <a href="<?= e(url('privacy')) ?>">Privacy Policy</a>. If you do not agree, please do not use the Service.
    </p>

    <h2>1. The Service</h2>
    <p>
        <?= e(APP_NAME) ?> helps small and medium businesses manage products and services, customer orders,
        invoices, receipts and expense claims, with AI assistants for customers (on Telegram and web chat) and for
        staff (including Gmail and Google Calendar). The Service is still being developed, so features may change,
        be added or be removed.
    </p>

    <h2>2. Your account</h2>
    <ul>
        <li>You must be at least 18 and able to act for the business you register.</li>
        <li>Give accurate information and keep it up to date.</li>
        <li>Keep your password secret. You are responsible for everything done with your account; tell us at once if you think it has been misused.</li>
    </ul>

    <h2>3. Acceptable use</h2>
    <p>You agree not to:</p>
    <ul>
        <li>break any law, or use the Service for fraud, scams, spam or misleading sales;</li>
        <li>upload content that is illegal, harmful, or that you have no right to share;</li>
        <li>try to access other users' data, break our security, or overload or disrupt the Service;</li>
        <li>use the AI assistants to harass people or to create harmful content;</li>
        <li>copy, resell or reverse engineer the Service without our written permission.</li>
    </ul>

    <h2>4. Your content and your customers' data</h2>
    <p>
        You own the information you put into the Service, such as your company details, products, records and files.
        You give us permission to store and process it only to run the Service for you. When your Customer Assistant
        collects information from your customers, you are responsible for having a lawful reason to collect it and
        for telling your customers how you use it, as required by the Personal Data Protection Act 2010.
    </p>

    <h2>5. AI assistants</h2>
    <ul>
        <li>AI answers, summaries, auto-filled fields and generated documents can be wrong or incomplete. Check them before you rely on them or send them to anyone.</li>
        <li>You are responsible for what your assistants tell your customers, including prices, stock and promises, so keep your products, SOPs and settings correct.</li>
        <li>If you use your own AI provider API key, you are responsible for that provider's charges and terms.</li>
    </ul>

    <h2>6. Invoices, payments and tax</h2>
    <p>
        The Service helps you prepare invoices and receipts and record payments, but it is not an accounting, tax or
        legal adviser. You are responsible for the correctness of your documents, for confirming that payments were
        really received, and for meeting your SST, tax and record-keeping duties. The Service does not move money:
        payments go directly between you and your customers.
    </p>

    <h2>7. Third-party services</h2>
    <p>
        The Service connects to services run by others, such as Telegram, Google (Gmail and Calendar) and AI
        providers. Your use of them is also governed by their own terms, and we are not responsible for their
        availability or actions. You can disconnect them at any time.
    </p>

    <h2>8. Fees</h2>
    <p>
        The Service is currently provided free of charge. If we introduce paid plans, we will tell you in advance,
        and you can choose whether to continue.
    </p>

    <h2>9. Availability and changes</h2>
    <p>
        We work to keep the Service running, but it may sometimes be unavailable, for example during updates or
        because of problems with our hosting or third-party services. Keep your own copies of important records.
    </p>

    <h2>10. Suspension and closing your account</h2>
    <p>
        You may stop using the Service at any time and ask us to delete your account. We may suspend or close an
        account that breaks these Terms or puts other users or the Service at risk; where we reasonably can, we will
        tell you first.
    </p>

    <h2>11. Disclaimer</h2>
    <p>
        The Service is provided "as is" and "as available". To the extent the law allows, we make no promise that it
        will be error-free, uninterrupted or fit for a particular purpose.
    </p>

    <h2>12. Limitation of liability</h2>
    <p>
        To the extent the law allows, we are not liable for indirect or consequential losses, such as lost profits,
        lost data or lost business, arising from your use of the Service. Nothing in these Terms limits rights you
        have under Malaysian law that cannot be limited.
    </p>

    <h2>13. Governing law</h2>
    <p>These Terms are governed by the laws of Malaysia, and the courts of Malaysia will hear any dispute about them.</p>

    <h2>14. Changes to these Terms</h2>
    <p>
        We may update these Terms. We will change the "Last updated" date above and, for important changes, tell you
        in the app or by email. Using the Service after a change means you accept the new Terms.
    </p>

    <h2>15. Contact us</h2>
    <p>Questions about these Terms: <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a>.</p>

    <a class="legal-back" href="<?= e(url('auth')) ?>"><i class="fa-solid fa-arrow-left"></i> Back to <?= e(APP_NAME) ?></a>
</article>
<?php view('layouts/footer', ['bare' => true]); ?>
