
<?php
// Temporary worker information
// Later, this will come from the login session.
$workerName = "Sze Yen";
$workerRole = "Worker";
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Smart SME | AI Email Dashboard</title>

    <!-- CSS -->
    <link rel="stylesheet" href="style.css">

    <!-- Icons -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>

<body>

<div class="dashboard">

    <!-- ========== TOP NAVIGATION ========== -->
    <header class="topbar">

        <div class="search-container">
            <i class="fa-solid fa-magnifying-glass"></i>

            <input
                type="text"
                id="searchInput"
                placeholder="Search emails, senders or keywords..."
            >

            <span class="search-shortcut">⌘ K</span>
        </div>

        <div class="topbar-right">

            <button class="notification-btn"
                    id="notificationBtn"
                    aria-label="Notifications">
                <i class="fa-regular fa-bell"></i>
                <span class="notification-dot"></span>
            </button>

            <div class="profile">
                <div class="profile-avatar">SY</div>

                <div class="profile-info">
                    <span class="profile-name">
                        <?php echo htmlspecialchars($workerName); ?>
                    </span>
                    <small><?php echo htmlspecialchars($workerRole); ?></small>
                </div>

                <i class="fa-solid fa-chevron-down"></i>
            </div>

        </div>

    </header>


    <!-- ========== WELCOME BANNER ========== -->
    <section class="welcome-banner">

        <div class="welcome-text">
            <h1>
                Good morning,
                <?php echo htmlspecialchars($workerName); ?>!
                ☀️
            </h1>

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


<!-- JavaScript -->
<script src="script.js"></script>

</body>
</html>
