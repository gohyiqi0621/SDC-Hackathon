<?php
/**
 * legal/privacy.php - the public Privacy Policy (no sign-in).
 *
 * Linked from Google's OAuth consent screen, so it must describe the Google
 * user data we use (Gmail, Calendar) and include the Limited Use statement.
 * Keep it true to what the code does when features change.
 */
use Src\Controllers\LegalController;

$email = LegalController::CONTACT_EMAIL;
view('layouts/header', ['title' => 'Privacy Policy', 'bare' => true, 'wide' => true]);
?>
<article class="auth-card legal">
    <header class="legal-head">
        <h1>Privacy Policy</h1>
        <p>Last updated: <?= e(LegalController::UPDATED) ?></p>
    </header>

    <p>
        This Privacy Policy explains how <?= e(APP_NAME) ?> ("we", "us") collects, uses, shares and protects
        information when you use our web application and its AI assistants (the "Service"). We follow the
        Personal Data Protection Act 2010 of Malaysia (PDPA).
    </p>

    <h2>1. Who we are</h2>
    <p>
        <?= e(APP_NAME) ?> is a business portal for small and medium enterprises, built by team CTRL + Future.
        It helps a business manage its products, orders, invoices, receipts and expense claims, and offers two
        AI assistants: a Customer Assistant that chats with the business's customers, and a Staff Assistant
        that helps the business's staff. You can reach us at <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a>.
    </p>

    <h2>2. Information we collect</h2>
    <h3>Information you give us</h3>
    <ul>
        <li><strong>Account details:</strong> your name, email address and password (stored only as a secure hash).</li>
        <li><strong>Company profile:</strong> company name, registration number, address, phone, logo, bank or payment details and payment QR code.</li>
        <li><strong>Business records:</strong> products and services, orders, invoices, receipts, expense claims and the files you upload with them (for example receipts and bank transfer slips).</li>
        <li><strong>Assistant settings:</strong> instructions (SOPs), tone and the AI provider API keys you choose to save (stored encrypted).</li>
    </ul>
    <h3>Information about your customers</h3>
    <p>
        When your customers chat with your Customer Assistant on Telegram or on the web chat, we store the
        conversation and the details needed to handle their orders and bookings, such as their name, Telegram
        username, phone number, order items and payment references. You, the business, decide what is collected
        from your customers; we process it on your behalf.
    </p>
    <h3>Google user data</h3>
    <p>If you choose to connect a Google account, we access only what each feature needs:</p>
    <ul>
        <li><strong>Gmail</strong> (<code>gmail.modify</code>, <code>gmail.send</code>): to read and organise your emails on the Dashboard, show you summaries and categories, and send emails that you ask the Staff Assistant to send.</li>
        <li><strong>Google Calendar</strong> (<code>calendar.readonly</code>, <code>calendar.events</code>): to check your free time and create, change or cancel appointments that your customers book through the Customer Assistant.</li>
    </ul>
    <p>We store the access tokens for these connections encrypted, and only for as long as the connection stays active.</p>
    <h3>Information collected automatically</h3>
    <ul>
        <li>One essential session cookie that keeps you signed in. We do not use advertising or tracking cookies.</li>
        <li>Small preferences saved in your browser, such as whether the sidebar is collapsed.</li>
        <li>Server logs (for example time, page and error messages) used to keep the Service working and secure.</li>
    </ul>

    <h2>3. How we use information</h2>
    <ul>
        <li>To provide the Service: show your records, create invoices and receipts, run your AI assistants and send notifications.</li>
        <li>To answer your customers' questions, take their orders and book appointments, as you have set up.</li>
        <li>To keep accounts secure, prevent misuse and fix problems.</li>
        <li>To contact you about your account, for example password reset emails.</li>
    </ul>
    <p>We do not sell your information, and we do not use it for advertising.</p>

    <h2>4. Google API Services: Limited Use</h2>
    <p class="legal-note">
        <?= e(APP_NAME) ?>'s use and transfer of information received from Google APIs to any other app will
        adhere to the <a href="https://developers.google.com/terms/api-services-user-data-policy" target="_blank" rel="noopener">Google API Services User Data Policy</a>,
        including the Limited Use requirements.
    </p>
    <p>In particular, for data we receive from Google:</p>
    <ul>
        <li>We use it only to provide and improve the user-facing features described above, which you can see and control.</li>
        <li>We transfer it to others only as needed to provide those features (for example to the AI provider that writes an email reply you asked for), to comply with the law, or as part of a merger or sale with your notice.</li>
        <li>We do not use it for advertising, and we do not sell it.</li>
        <li>No person at <?= e(APP_NAME) ?> reads it unless you give us permission for specific messages, it is needed for security or to investigate abuse, or the law requires it.</li>
        <li>We do not use it to develop, improve or train generalised AI or machine-learning models.</li>
    </ul>

    <h2>5. AI processing</h2>
    <p>
        To answer chats, suggest replies, read receipts and write documents, the relevant text or file is sent to
        the AI provider selected in your assistant settings, such as Anthropic (Claude), OpenAI, DeepSeek, Agnes AI
        or RunPod, through our AI workflow service (Flowise). These providers process the content only to return a
        result to us. When you use your own API key, your agreement with that provider also applies. AI output can
        be wrong, so please review it before relying on it.
    </p>

    <h2>6. Who we share information with</h2>
    <p>We share information only with service providers that help us run the Service, and only what they need:</p>
    <ul>
        <li><strong>Hosting and database:</strong> Replit (application hosting) and our PostgreSQL database provider.</li>
        <li><strong>Messaging:</strong> Telegram, to deliver your Customer Assistant chats and staff alerts.</li>
        <li><strong>Google:</strong> Gmail and Google Calendar, when you connect them.</li>
        <li><strong>AI providers:</strong> as described in section 5.</li>
        <li><strong>Email delivery:</strong> Brevo, to send account emails such as password resets.</li>
    </ul>
    <p>We may also disclose information when the law requires it, or to protect the rights and safety of our users and the public.</p>

    <h2>7. How long we keep information</h2>
    <p>
        We keep your information while your account is active. Business records such as invoices and receipts may
        need to be kept for the period required by Malaysian tax and company law. When you disconnect Gmail or
        Google Calendar, we revoke our access with Google and delete the stored tokens; a saved Telegram bot token
        is deleted when you choose to remove it. When you ask us to
        delete your account, we delete or anonymise your information within 30 days, except where the law requires
        us to keep it.
    </p>

    <h2>8. Your choices and rights</h2>
    <ul>
        <li><strong>Access and correction:</strong> you can view and update your profile, company details and records in the app at any time.</li>
        <li><strong>Disconnect Google:</strong> use Disconnect on the Connections pages, or remove access at <a href="https://myaccount.google.com/permissions" target="_blank" rel="noopener">myaccount.google.com/permissions</a>.</li>
        <li><strong>Delete your account:</strong> email us from your account's email address and we will delete it.</li>
        <li><strong>Under the PDPA</strong> you may also ask for a copy of your personal data, ask us to correct it, or withdraw your consent. Write to <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a>; we reply within 21 days.</li>
    </ul>
    <p>
        If you are a customer of a business that uses <?= e(APP_NAME) ?>, please contact that business first about
        your data; we will help them with your request.
    </p>

    <h2>9. Security</h2>
    <p>
        We protect information with HTTPS, hashed passwords, encrypted tokens and API keys, access checks on every
        page, and limited staff access. No system is completely secure, so please use a strong password and keep it
        private.
    </p>

    <h2>10. Children</h2>
    <p>The Service is for businesses and is not meant for anyone under 18. We do not knowingly collect children's information.</p>

    <h2>11. Changes to this policy</h2>
    <p>
        We may update this policy as the Service changes. We will change the "Last updated" date above and, for
        important changes, tell you in the app or by email.
    </p>

    <h2>12. Contact us</h2>
    <p>Questions or requests about privacy: <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a>.</p>

    <a class="legal-back" href="<?= e(url('auth')) ?>"><i class="fa-solid fa-arrow-left"></i> Back to <?= e(APP_NAME) ?></a>
</article>
<?php view('layouts/footer', ['bare' => true]); ?>
