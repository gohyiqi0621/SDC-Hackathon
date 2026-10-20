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
use Src\Controllers\CategoryController;
use Src\Controllers\ChatbotController;
use Src\Controllers\DashboardController;
use Src\Controllers\FinancialController;
use Src\Controllers\ProductController;
use Src\Controllers\ProfileController;
use Src\Middleware\AuthMiddleware;
use Src\Middleware\GuestMiddleware;
use Src\Support\Router;

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
    $router->post('dashboard/test_email', [DashboardController::class, 'testEmail'], $auth);

    /* ------------------------------------------------------- financial --- */
    $router->get('financial/invoice', [FinancialController::class, 'invoice'], $auth);
    $router->get('financial/claims', [FinancialController::class, 'claims'], $auth);
    $router->get('financial/settlement', [FinancialController::class, 'settlement'], $auth);
    $router->get('financial/upload', [FinancialController::class, 'upload'], $auth);

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

    $router->get('categories', [CategoryController::class, 'index'], $auth);
    $router->post('categories/store', [CategoryController::class, 'store'], $auth);
    $router->post('categories/update', [CategoryController::class, 'update'], $auth);
    $router->post('categories/delete', [CategoryController::class, 'delete'], $auth);

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
