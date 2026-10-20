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

use Src\Controllers\AuthController;
use Src\Controllers\ChatbotController;
use Src\Controllers\DashboardController;
use Src\Controllers\FinancialController;
use Src\Controllers\ProfileController;
use Src\Middleware\AuthMiddleware;
use Src\Middleware\GuestMiddleware;
use Src\Support\Router;

return static function (Router $router): void {

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
    $router->post('auth/logout', [AuthController::class, 'logout'], [AuthMiddleware::class]);

    /* ------------------------------------------------------- dashboard --- */
    $router->get('dashboard', [DashboardController::class, 'index'], [AuthMiddleware::class]);
    $router->post('dashboard/test_email', [DashboardController::class, 'testEmail'], [AuthMiddleware::class]);

    // The email dashboard is now the main dashboard; keep the old URL working.
    $router->get('email', static function (): void {
        redirect(url('dashboard'));
    });

    /* ------------------------------------------------------- financial --- */
    $router->get('financial/invoice', [FinancialController::class, 'invoice'], [AuthMiddleware::class]);
    $router->get('financial/claims', [FinancialController::class, 'claims'], [AuthMiddleware::class]);
    $router->get('financial/settlement', [FinancialController::class, 'settlement'], [AuthMiddleware::class]);
    $router->get('financial/upload', [FinancialController::class, 'upload'], [AuthMiddleware::class]);

    /* -------------------------------------------------------- chatbot --- */
    $router->get('chatbot/customer', [ChatbotController::class, 'customer'], [AuthMiddleware::class]);
    $router->get('chatbot/staff', [ChatbotController::class, 'staff'], [AuthMiddleware::class]);

    /* -------------------------------------------------------- profile --- */
    $router->get('profile', [ProfileController::class, 'index'], [AuthMiddleware::class]);
    $router->post('profile/update', [ProfileController::class, 'update'], [AuthMiddleware::class]);
    $router->post('profile/password', [ProfileController::class, 'password'], [AuthMiddleware::class]);
};
