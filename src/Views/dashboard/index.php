<?php
/**
 * dashboard/index.php - the home screen: the Smart SME AI email dashboard.
 *
 * Expects: $user, $brevoReady. The inbox, AI summary and reply are frontend
 * mock data driven by assets/js/dashboard.js; the Brevo strip is real and
 * sends a test message through the same mailer as password-reset email.
 */
$firstName = trim(explode(' ', trim((string) $user['name']))[0] ?? '') ?: (string) $user['name'];
$hour      = (int) date('G');
[$greeting, $greetingIcon] = $hour < 12 ? ['Good morning', '☀️'] : ($hour < 18 ? ['Good afternoon', '🌤️'] : ['Good evening', '🌙']);

view('layouts/header', [
    'title'  => 'Dashboard',
    'search' => true,
    'styles' => ['assets/css/dashboard.css'],
]);
?>

<!-- ========== WELCOME BANNER ========== -->
<section class="welcome-banner">

    <div class="welcome-text">
        <h1><?= e($greeting) ?>, <?= e($firstName) ?>! <?= $greetingIcon ?></h1>

        <p>Here's what matters in your emails today.</p>
    </div>

    <div class="ai-assistant">

        <div class="assistant-message">
            <strong>Let AI handle your emails</strong>

            <p>
                Summarize, draft replies and never
                miss what's important.
            </p>
        </div>

        <div class="assistant-robot">
            <i class="fa-solid fa-robot"></i>
        </div>

    </div>

</section>

<div class="page-body">

    <!-- ========== PASSWORD RESET EMAIL (BREVO) ========== -->
    <section class="brevo-strip" aria-label="Password reset email delivery">
        <div class="brevo-strip__info">
            <strong>
                <i class="fa-solid fa-paper-plane"></i>
                Password reset email
            </strong>
            <span>
                <?php if ($brevoReady): ?>
                    Brevo is connected. Reset links are sent from
                    <?= e(BREVO_SENDER_EMAIL) ?>.
                <?php else: ?>
                    Brevo is not configured yet. Add <code>BREVO_API_KEY</code> and
                    <code>BREVO_SENDER_EMAIL</code> to the app's <code>.env</code> to
                    enable password-reset email.
                <?php endif; ?>
            </span>
        </div>

        <?php if ($brevoReady): ?>
            <form method="post" action="<?= e(url('dashboard', ['action' => 'test_email'])) ?>">
                <?= csrf_field() ?>
                <button class="primary-btn" type="submit">
                    <i class="fa-regular fa-envelope"></i>
                    Send test email
                </button>
            </form>
        <?php endif; ?>
    </section>


    <!-- ========== STATISTICS CARDS ========== -->
    <section class="stats-grid">

        <div class="stat-card blue">

            <div class="stat-icon">
                <i class="fa-solid fa-envelope"></i>
            </div>

            <div class="stat-details">
                <h2 id="newEmailCount">24</h2>
                <p>New emails</p>
            </div>

            <span class="stat-trend positive">↑ 28%</span>

        </div>


        <div class="stat-card pink">

            <div class="stat-icon">
                <i class="fa-solid fa-star"></i>
            </div>

            <div class="stat-details">
                <h2 id="replyCount">5</h2>
                <p>Need your reply</p>
            </div>

            <span class="stat-trend negative">↑ 2</span>

        </div>


        <div class="stat-card green">

            <div class="stat-icon">
                <i class="fa-solid fa-calendar-days"></i>
            </div>

            <div class="stat-details">
                <h2 id="meetingCount">3</h2>
                <p>Meeting requests</p>
            </div>

            <span class="stat-trend positive">↑ 1</span>

        </div>


        <div class="stat-card yellow">

            <div class="stat-icon">
                <i class="fa-solid fa-flag"></i>
            </div>

            <div class="stat-details">
                <h2 id="importantCount">7</h2>
                <p>Important updates</p>
            </div>

            <span class="stat-trend warning">↑ 3</span>

        </div>

    </section>


    <!-- ========== MAIN CONTENT ========== -->
    <main class="main-content">


        <!-- ========== LEFT: INBOX ========== -->
        <section class="inbox-panel">

            <div class="panel-header">

                <h2>Inbox</h2>

                <div class="inbox-actions">

                    <button id="sortBtn">
                        <i class="fa-solid fa-arrow-down-wide-short"></i>
                        Sort
                    </button>

                    <button id="filterBtn">
                        <i class="fa-solid fa-filter"></i>
                        Filter
                    </button>

                </div>

            </div>


            <!-- Email Tabs -->
            <div class="email-tabs">

                <button class="tab active" data-filter="all">
                    All (24)
                </button>

                <button class="tab" data-filter="unread">
                    Unread (12)
                </button>

                <button class="tab" data-filter="important">
                    Important (7)
                </button>

                <button class="tab" data-filter="mentions">
                    Mentions (3)
                </button>

            </div>


            <!-- Email List -->
            <div class="email-list" id="emailList">

                <!-- Emails will be inserted using JavaScript -->

            </div>

        </section>



        <!-- ========== RIGHT: EMAIL DETAILS ========== -->
        <section class="email-detail-panel">

            <!-- Email Header -->
            <div class="email-detail-header">

                <div class="email-title-area">
                    <h2 id="emailSubject">
                        Partnership Proposal
                    </h2>

                    <span class="email-category"
                          id="emailCategory">
                        Inbox
                    </span>
                </div>

                <div class="email-tools">

                    <button id="starEmailBtn"
                            aria-label="Star email">
                        <i class="fa-regular fa-star"></i>
                    </button>

                    <button id="archiveEmailBtn"
                            aria-label="Archive email">
                        <i class="fa-solid fa-box-archive"></i>
                    </button>

                    <button id="deleteEmailBtn"
                            aria-label="Delete email">
                        <i class="fa-regular fa-trash-can"></i>
                    </button>

                    <button id="moreEmailBtn"
                            aria-label="More options">
                        <i class="fa-solid fa-ellipsis-vertical"></i>
                    </button>

                </div>

            </div>


            <!-- Sender -->
            <div class="sender-section">

                <div class="sender-avatar" id="senderAvatar">
                    KL
                </div>

                <div class="sender-info">

                    <strong id="senderName">Klook</strong>

                    <span id="senderEmail">
                        &lt;partnership@klook.com&gt;
                    </span>

                    <small>to me</small>

                </div>

                <span class="email-time" id="emailTime">
                    10:24 AM
                </span>

            </div>


            <!-- Email Body -->
            <div class="email-body" id="emailBody">

                <p>Hi Sze Yen,</p>

                <p>
                    We are Klook, a leading travel and
                    experience platform in Asia.
                </p>

                <p>
                    We would like to explore a potential
                    partnership with your brand for
                    upcoming campaigns in Malaysia.
                </p>

                <p>
                    Could we schedule a short call next
                    week to discuss the details?
                </p>

                <p>
                    Best regards,<br>
                    Amanda<br>
                    Partnerships Team<br>
                    Klook
                </p>

            </div>



            <!-- ========== AI SUMMARY ========== -->
            <div class="ai-summary-card">

                <div class="ai-card-header">

                    <h3>
                        <i class="fa-solid fa-wand-magic-sparkles"></i>
                        AI Summary
                    </h3>

                    <button id="copySummaryBtn"
                            aria-label="Copy summary">
                        <i class="fa-regular fa-copy"></i>
                    </button>

                </div>

                <div class="ai-summary-content"
                     id="aiSummary">

                    <strong>Key points</strong>

                    <ul>
                        <li>
                            Klook is interested in a partnership
                            for upcoming campaigns in Malaysia.
                        </li>

                        <li>
                            They would like to schedule a short
                            call next week.
                        </li>

                        <li>
                            Email is from Amanda,
                            Partnerships Team.
                        </li>
                    </ul>

                </div>

            </div>



            <!-- ========== AI REPLY ========== -->
            <div class="ai-reply-card">

                <div class="ai-card-header">

                    <h3>
                        <i class="fa-solid fa-robot"></i>
                        AI Reply (Draft)
                    </h3>

                    <select id="replyTone">
                        <option value="formal">Formal</option>
                        <option value="friendly">Friendly</option>
                        <option value="concise">Concise</option>
                    </select>

                </div>


                <textarea id="replyDraft" rows="7">
Hi Amanda,

Thank you for reaching out and for your interest in a partnership.

I'd be happy to discuss this further. How about next week? Please let me know a few time slots that work for you.

Best regards,
Sze Yen
                </textarea>


                <div class="reply-actions">

                    <button class="primary-btn" id="sendReplyBtn">
                        <i class="fa-regular fa-paper-plane"></i>
                        Send Reply
                    </button>

                    <button class="secondary-btn"
                            id="regenerateReplyBtn">
                        <i class="fa-solid fa-rotate"></i>
                        Regenerate
                    </button>

                    <button class="secondary-btn"
                            id="adjustReplyBtn">
                        <i class="fa-solid fa-sliders"></i>
                        Adjust
                    </button>

                </div>

            </div>

        </section>

    </main>


    <!-- ========== NOTIFICATION PANEL ========== -->
    <div class="notification-panel"
         id="notificationPanel"
         hidden>

        <div class="notification-header">
            <h3>Smart Notifications</h3>

            <button id="closeNotificationBtn"
                    aria-label="Close notifications">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div id="notificationList">
            <!-- Smart AI notifications -->
        </div>

    </div>

</div>

<?php view('layouts/footer', ['scripts' => ['assets/js/dashboard.js']]); ?>
