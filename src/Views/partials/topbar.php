
<?php
/**
 * partials/topbar.php - the top navigation from the email dashboard.
 *
 * Expects: $user, $pageTitle. With $search = true (dashboard) it shows the
 * inbox search box and the notification bell; other pages show their title.
 */
?>
<header class="topbar">

    <div class="topbar-left">
        <button class="menu-toggle" type="button" data-nav-toggle aria-label="Open menu">
            <i class="fa-solid fa-bars"></i>
        </button>

        <?php if ($search): ?>
            <div class="search-container">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="searchInput" placeholder="Search emails, senders or keywords...">
                <span class="search-shortcut">⌘ K</span>
            </div>
        <?php else: ?>
            <p class="topbar-title"><?= e($pageTitle) ?></p>
        <?php endif; ?>
    </div>

    <div class="topbar-right">

        <?php if ($search): ?>
            <button class="notification-btn" id="notificationBtn" type="button" aria-label="Notifications">
                <i class="fa-regular fa-bell"></i>
                <span class="notification-dot"></span>
            </button>
        <?php endif; ?>

        <!-- ========== AI CHATBOT ========== -->
        <div class="header-chatbot" id="headerChatbot">

            <button
                type="button"
                class="header-chatbot-trigger"
                id="headerChatbotBtn"
                aria-label="Open AI Assistant"
                aria-expanded="false"
                aria-controls="headerChatbotPanel"
            >
                <span class="header-chatbot-icon">
                    <i class="fa-solid fa-robot"></i>
                </span>

                <span class="header-chatbot-text">
                    <strong>AI Assistant</strong>
                    <small>Hey! Need help with your emails?</small>
                </span>
            </button>

            <!-- CHATBOT POPUP -->
            <div class="header-chatbot-panel" id="headerChatbotPanel" hidden>

                <div class="header-chatbot-panel-top">
                    <div class="header-chatbot-title">
                        <i class="fa-solid fa-robot"></i>
                        <strong>AI Assistant</strong>
                    </div>

                    <button
                        type="button"
                        id="closeHeaderChatbot"
                        aria-label="Close chatbot"
                    >
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <!-- Reserved for chatbot animation -->
                <div class="header-chatbot-animation" id="headerChatbotAnimation">
                    <i class="fa-solid fa-robot"></i>
                </div>

                <!-- Chat messages -->
                <div class="header-chatbot-messages"
                     id="headerChatbotMessages"
                     aria-live="polite">

                    <div class="header-chatbot-bubble">
                        Hi there! 👋 How can I help you today?
                    </div>

                </div>

                <!-- Chat input -->
                <form class="header-chatbot-form" id="headerChatbotForm">

                    <input
                        type="text"
                        id="headerChatbotInput"
                        placeholder="Ask me anything..."
                        aria-label="Message AI Assistant"
                        required
                    >

                    <button type="submit" aria-label="Send message">
                        <i class="fa-solid fa-paper-plane"></i>
                    </button>

                </form>

            </div>
        </div>
        <!-- ========== END AI CHATBOT ========== -->

        <!-- ========== PROFILE ========== -->
        <div class="profile-menu" data-profile-menu>
            <button class="profile" type="button" data-profile-toggle aria-haspopup="true" aria-expanded="false">
                <span class="avatar"><?= e(initials_of($user)) ?></span>
                <span class="profile-info">
                    <span class="profile-name"><?= e($user['name']) ?></span>
                    <small>Member</small>
                </span>
                <i class="fa-solid fa-chevron-down"></i>
            </button>

            <div class="profile-dropdown" role="menu" hidden>
                <div class="profile-dropdown-head">
                    <strong><?= e($user['name']) ?></strong>
                    <span><?= e($user['email']) ?></span>
                </div>

                <a role="menuitem" href="<?= e(url('profile')) ?>">
                    <i class="fa-regular fa-circle-user"></i> Profile
                </a>

                <form method="post" action="<?= e(url('auth', ['action' => 'logout'])) ?>">
                    <?= csrf_field() ?>
                    <button class="is-danger" type="submit" role="menuitem">
                        <i class="fa-solid fa-arrow-right-from-bracket"></i> Logout
                    </button>
                </form>
            </div>
        </div>

    </div>

</header>
