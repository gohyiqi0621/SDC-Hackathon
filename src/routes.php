<?php
/**
 * routes.php - every URL the app answers, in one place.
 *
 * URLs work in both forms (public/.htaccess rewrites the clean one):
 *   /?page=financial&action=invoice   ==   /financial/invoice
 *
 * GuestMiddleware: only for signed-out visitors (signed-in users go to the dashboard).
 * AuthMiddleware:  only for signed-in users. CSRF is checked on every POST by the Router.
 */
declare(strict_types=1);

use Src\Controllers\AiSettingsController;
use Src\Controllers\AuthController;
use Src\Controllers\CategoryController;
use Src\Controllers\ChatbotController;
use Src\Controllers\ClaimController;
use Src\Controllers\DashboardController;
use Src\Controllers\FinancialController;
use Src\Controllers\GmailController;
use Src\Controllers\OrderController;
use Src\Controllers\ProductController;
use Src\Controllers\ProfileController;
use Src\Controllers\SystemController;
use Src\Controllers\TelegramWebhookController;
use Src\Controllers\ToolController;
use Src\Middleware\AuthMiddleware;
use Src\Middleware\GuestMiddleware;
use Src\Support\Router;
use Src\Controllers\AIController;

return static function (Router $router): void {

    $auth = [AuthMiddleware::class];

    /* ------------------------------------------------------------ auth --- */
    $router->get('auth', [AuthController::class, 'index']);
    $router->get('auth/register', [AuthController::class, 'showRegister'], [GuestMiddleware::class]);
    $router->post('auth/register', [AuthController::class, 'register'], [GuestMiddleware::class]);
    $router->get('auth/login', [AuthController::class, 'showLogin'], [GuestMiddleware::class]);
    $router->post('auth/login', [AuthController::class, 'login'], [GuestMiddleware::class]);
    $router->get('auth/forgot', [AuthController::class, 'showForgot'], [GuestMiddleware::class]);
    $router->post('auth/forgot', [AuthController::class, 'forgot'], [GuestMiddleware::class]);
    $router->get('auth/reset', [AuthController::class, 'showReset'], [GuestMiddleware::class]);
    $router->post('auth/reset', [AuthController::class, 'reset'], [GuestMiddleware::class]);
    $router->post('auth/logout', [AuthController::class, 'logout'], $auth);

    /* ------------------------------------------------------- dashboard --- */
    $router->get('dashboard', [DashboardController::class, 'index'], $auth);
    
// AI: generate email summary
$router->post(
    'ai/summarize',
    [AIController::class, 'summarize'],
    $auth
);

$router->post(
    'ai/regenerate',
    [AIController::class, 'regenerate'],
    $auth
);


    
    /* ------------------------------------------------------- Gmail --- */

    // Gmail OAuth connection
    $router->get(
        'gmail/connect',
        [GmailController::class, 'connect'],
        $auth
    );

    $router->get(
        'gmail/callback',
        [GmailController::class, 'callback'],
        $auth
    );

    $router->post(
        'gmail/disconnect',
        [GmailController::class, 'disconnect'],
        $auth
    );

    // Gmail folders
    $router->get(
        'gmail/inbox',
        [GmailController::class, 'inbox'],
        $auth
    );

    $router->get(
        'gmail/sent',
        [GmailController::class, 'sent'],
        $auth
    );

    $router->get(
        'gmail/spam',
        [GmailController::class, 'spam'],
        $auth
    );

    $router->get(
        'gmail/trash',
        [GmailController::class, 'trash'],
        $auth
    );

    // Gmail message details
    $router->get(
        'gmail/message',
        [GmailController::class, 'message'],
        $auth
    );

    // Gmail conversation threads
    $router->get(
        'gmail/thread',
        [GmailController::class, 'thread'],
        $auth
    );

    // Send Gmail reply
    $router->post(
        'gmail/send',
        [GmailController::class, 'send'],
        $auth
    );

    // Gmail actions:
    // star, unstar, archive, trash, restore,
    // read, unread, spam, not_spam,
    // important, not_important
    $router->post(
        'gmail/action',
        [GmailController::class, 'action'],
        $auth
    );

    // Dashboard test email
    $router->post(
        'dashboard/test_email',
        [DashboardController::class, 'testEmail'],
        $auth
    );



    /* ------------------------------------------------------- financial --- */
    // Invoice: list + upload popup (?new=1, ?edit=N). Edit / mark paid / delete are Supervisor only.
    $router->get('financial/invoice', [FinancialController::class, 'invoice'], $auth);
    $router->get('financial/print', [FinancialController::class, 'print'], $auth);
    $router->post('financial/role', [FinancialController::class, 'role'], $auth);
    $router->post('financial/store', [FinancialController::class, 'store'], $auth);
    $router->post('financial/update', [FinancialController::class, 'update'], $auth);
    $router->post('financial/mark_paid', [FinancialController::class, 'markPaid'], $auth);
    $router->post('financial/delete', [FinancialController::class, 'delete'], $auth);
    $router->get('financial/file', [FinancialController::class, 'file'], $auth);
    $router->post('financial/extract', [FinancialController::class, 'extract'], $auth); // AI auto-fill (invoice + claim popups)

    // Claims: list + new-claim popup (?new=1, ?edit=N). Review / edit / delete are Supervisor only.
    $router->get('financial/claims', [ClaimController::class, 'index'], $auth);
    $router->post('financial/claim_store', [ClaimController::class, 'store'], $auth);
    $router->post('financial/claim_update', [ClaimController::class, 'update'], $auth);
    $router->post('financial/claim_status', [ClaimController::class, 'status'], $auth);
    $router->post('financial/claim_delete', [ClaimController::class, 'delete'], $auth);
    $router->get('financial/claim_file', [ClaimController::class, 'file'], $auth);

    // Old addresses: the Upload Invoice and claim-form pages became popups; Settlement was removed.
    $router->get('financial/upload', [FinancialController::class, 'upload'], $auth);
    $router->get('financial/edit', [FinancialController::class, 'edit'], $auth);
    $router->get('financial/claim_new', [ClaimController::class, 'create'], $auth);
    $router->get('financial/claim_edit', [ClaimController::class, 'edit'], $auth);
    $router->get('financial/settlement', static fn () => redirect(url('financial', ['action' => 'invoice'])), $auth);

    /* -------------------------------------------- AI chatbot: customer --- */
    $router->get('customer/settings', [ChatbotController::class, 'customerSettings'], $auth);
    $router->get('customer/connections', [ChatbotController::class, 'customerConnections'], $auth);
    $router->get('company', [ChatbotController::class, 'companyProfile'], $auth);
    $router->get('customer/chat', [ChatbotController::class, 'customerChat'], $auth);
    $router->get('customer/chat_feed', [ChatbotController::class, 'customerChatFeed'], $auth);

    // Product & Service (Customer)
    $router->get('products', [ProductController::class, 'index'], $auth);
    $router->get('products/create', [ProductController::class, 'create'], $auth);
    $router->post('products/store', [ProductController::class, 'store'], $auth);
    $router->get('products/edit', [ProductController::class, 'edit'], $auth);
    $router->post('products/update', [ProductController::class, 'update'], $auth);
    $router->post('products/delete', [ProductController::class, 'delete'], $auth);
    $router->post('products/import_preview', [ProductController::class, 'importPreview'], $auth);
    $router->post('products/import_commit', [ProductController::class, 'importCommit'], $auth);
    $router->post('products/import_cancel', [ProductController::class, 'importCancel'], $auth);
    $router->get('products/import_template', [ProductController::class, 'importTemplate'], $auth);

    // Orders from the AI chatbot (buy_product): review payment receipts
    $router->get('orders', [OrderController::class, 'index'], $auth);
    $router->get('orders/receipt', [OrderController::class, 'receipt'], $auth);
    $router->post('orders/approve', [OrderController::class, 'approve'], $auth);
    $router->post('orders/reject', [OrderController::class, 'reject'], $auth);
    $router->post('orders/ship', [OrderController::class, 'ship'], $auth);
    $router->post('orders/cancel', [OrderController::class, 'cancel'], $auth);

    $router->get('categories', [CategoryController::class, 'index'], $auth);
    $router->post('categories/store', [CategoryController::class, 'store'], $auth);
    $router->post('categories/update', [CategoryController::class, 'update'], $auth);
    $router->post('categories/delete', [CategoryController::class, 'delete'], $auth);

    /* ------------------------------ AI Settings & Integration (JSON) --- */
    // Used by assets/js/ai-settings-api.js on customer/settings. The user is
    // always taken from the session; POSTs are CSRF-checked by the Router.
    $router->get('ai_settings/company_profile', [AiSettingsController::class, 'companyProfile'], $auth);
    $router->post('ai_settings/company_profile_save', [AiSettingsController::class, 'companyProfileSave'], $auth);
    $router->get('ai_settings/assistant', [AiSettingsController::class, 'assistant'], $auth);
    $router->post('ai_settings/assistant_save', [AiSettingsController::class, 'assistantSave'], $auth);
    $router->post('ai_settings/generate_sops', [AiSettingsController::class, 'generateSops'], $auth);
    $router->get('ai_settings/staff_assistant', [AiSettingsController::class, 'staffAssistant'], $auth);
    $router->post('ai_settings/staff_assistant_save', [AiSettingsController::class, 'staffAssistantSave'], $auth);
    $router->post('ai_settings/staff_generate_sops', [AiSettingsController::class, 'staffGenerateSops'], $auth);
    $router->get('ai_settings/telegram', [AiSettingsController::class, 'telegram'], $auth);
    $router->post('ai_settings/telegram_connect', [AiSettingsController::class, 'telegramConnect'], $auth);
    $router->post('ai_settings/telegram_disconnect', [AiSettingsController::class, 'telegramDisconnect'], $auth);
    $router->post('ai_settings/telegram_forget', [AiSettingsController::class, 'telegramForget'], $auth);
    $router->post('ai_settings/telegram_staff_link', [AiSettingsController::class, 'telegramStaffLink'], $auth);
    $router->post('ai_settings/telegram_staff_unlink', [AiSettingsController::class, 'telegramStaffUnlink'], $auth);
    $router->get('ai_settings/google_calendar', [AiSettingsController::class, 'googleCalendar'], $auth);
    $router->get('ai_settings/google_calendar_oauth_start', [AiSettingsController::class, 'googleCalendarOauthStart'], $auth);
    $router->get('ai_settings/google_calendar_oauth_callback', [AiSettingsController::class, 'googleCalendarOauthCallback'], $auth);
    $router->get('ai_settings/google_calendar_calendars', [AiSettingsController::class, 'googleCalendarCalendars'], $auth);
    $router->post('ai_settings/google_calendar_save', [AiSettingsController::class, 'googleCalendarSave'], $auth);
    $router->post('ai_settings/google_calendar_disconnect', [AiSettingsController::class, 'googleCalendarDisconnect'], $auth);

    /* ------------------------------ Telegram -> website -> Flowise ------- */
    // Called by Telegram and by Flowise, not by a browser: no login and no
    // CSRF token (csrf: false). Each checks its own secret instead - the
    // webhook secret header / the signed tool token.
    $router->post('telegram/webhook', [TelegramWebhookController::class, 'handle'], [], false);
    $router->post('tools/check_product', [ToolController::class, 'checkProduct'], [], false);
    $router->post('tools/buy_product', [ToolController::class, 'buyProduct'], [], false);
    $router->post('tools/check_availability', [ToolController::class, 'checkAvailability'], [], false);
    $router->post('tools/make_booking', [ToolController::class, 'makeBooking'], [], false);
    // Staff assistant tools (staff tokens only; send_email_reply needs the staff member's OK).
    $router->post('tools/check_invoice', [ToolController::class, 'checkInvoice'], [], false);
    $router->post('tools/check_claims', [ToolController::class, 'checkClaims'], [], false);
    $router->post('tools/check_orders', [ToolController::class, 'checkOrders'], [], false);
    $router->post('tools/check_email', [ToolController::class, 'checkEmail'], [], false);
    $router->post('tools/read_email', [ToolController::class, 'readEmail'], [], false);
    $router->post('tools/send_email_reply', [ToolController::class, 'sendEmailReply'], [], false);
    $router->post('tools/send_email', [ToolController::class, 'sendEmail'], [], false);
    $router->post('tools/review_receipt', [ToolController::class, 'reviewReceipt'], [], false);

    // Preview of the system message built from AI Settings.
    $router->get('customer/system_message', [ChatbotController::class, 'customerSystemMessage'], $auth);

    /* ----------------------------------------------- AI chatbot: staff --- */
    $router->get('staff/settings', [ChatbotController::class, 'staffSettings'], $auth);
    $router->get('staff/connections', [ChatbotController::class, 'staffConnections'], $auth);
    $router->get('staff/chat', [ChatbotController::class, 'staffChat'], $auth);
    $router->post('staff/chat_send', [ChatbotController::class, 'staffChatSend'], $auth);
    $router->post('staff/order_decide', [ChatbotController::class, 'staffOrderDecide'], $auth);

    /* -------------------------------------------------------- profile --- */
    $router->get('profile', [ProfileController::class, 'index'], $auth);
    $router->get('system/settings', [SystemController::class, 'settings'], $auth);
    // Flow log: signed-in owner, or ?key=<LOG_VIEW_KEY> (checked in SystemController).
    $router->get('system/logs', [SystemController::class, 'logs']);
    $router->get('system/logs_feed', [SystemController::class, 'logsFeed']);
    $router->post('profile/update', [ProfileController::class, 'update'], $auth);
    $router->post('profile/password', [ProfileController::class, 'password'], $auth);

    /* ------------------------------------------- old addresses (redirect) --- */
    $router->get('email', static fn () => redirect(url('dashboard')));
    $router->get('chatbot/customer', static fn () => redirect(url('customer', ['action' => 'chat'])));
    $router->get('chatbot/staff', static fn () => redirect(url('staff', ['action' => 'chat'])));
    

};
