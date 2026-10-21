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


    // Gmail inbox on the dashboard (read-only OAuth connection)
    $router->get('gmail/connect', [GmailController::class, 'connect'], $auth);
    $router->get('gmail/callback', [GmailController::class, 'callback'], $auth);
    $router->get('gmail/inbox', [GmailController::class, 'inbox'], $auth);
    $router->post('gmail/disconnect', [GmailController::class, 'disconnect'], $auth);
    $router->post('dashboard/test_email', [DashboardController::class, 'testEmail'], $auth);
    $router->get('gmail/message',[GmailController::class, 'message'],$auth);


    /* ------------------------------------------------------- financial --- */
    // Invoice: list + upload popup (?new=1, ?edit=N). Edit / mark paid / delete are Supervisor only.
    $router->get('financial/invoice', [FinancialController::class, 'invoice'], $auth);
    $router->post('financial/role', [FinancialController::class, 'role'], $auth);
    $router->post('financial/store', [FinancialController::class, 'store'], $auth);
    $router->post('financial/update', [FinancialController::class, 'update'], $auth);
    $router->post('financial/mark_paid', [FinancialController::class, 'markPaid'], $auth);
    $router->post('financial/delete', [FinancialController::class, 'delete'], $auth);
    $router->get('financial/file', [FinancialController::class, 'file'], $auth);

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
    $router->get('customer/chat', [ChatbotController::class, 'customerChat'], $auth);

    // Product & Service (Customer)
    $router->get('products', [ProductController::class, 'index'], $auth);
    $router->get('products/create', [ProductController::class, 'create'], $auth);
    $router->post('products/store', [ProductController::class, 'store'], $auth);
    $router->get('products/edit', [ProductController::class, 'edit'], $auth);
    $router->post('products/update', [ProductController::class, 'update'], $auth);
    $router->post('products/delete', [ProductController::class, 'delete'], $auth);

    // Orders from the AI chatbot (buy_product): review payment receipts
    $router->get('orders', [OrderController::class, 'index'], $auth);
    $router->get('orders/receipt', [OrderController::class, 'receipt'], $auth);
    $router->post('orders/approve', [OrderController::class, 'approve'], $auth);
    $router->post('orders/reject', [OrderController::class, 'reject'], $auth);
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

    // Website Chat Interface: the same AI pipeline, for testing without Telegram.
    $router->post('customer/chat_send', [ChatbotController::class, 'customerChatSend'], $auth);
    $router->get('customer/system_message', [ChatbotController::class, 'customerSystemMessage'], $auth);

    /* ----------------------------------------------- AI chatbot: staff --- */
    $router->get('staff/settings', [ChatbotController::class, 'staffSettings'], $auth);
    $router->get('staff/chat', [ChatbotController::class, 'staffChat'], $auth);

    /* -------------------------------------------------------- profile --- */
    $router->get('profile', [ProfileController::class, 'index'], $auth);
    $router->post('profile/update', [ProfileController::class, 'update'], $auth);
    $router->post('profile/password', [ProfileController::class, 'password'], $auth);

    /* ------------------------------------------- old addresses (redirect) --- */
    $router->get('email', static fn () => redirect(url('dashboard')));
    $router->get('chatbot/customer', static fn () => redirect(url('customer', ['action' => 'chat'])));
    $router->get('chatbot/staff', static fn () => redirect(url('staff', ['action' => 'chat'])));
    

};
