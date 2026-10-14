<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * Authentication & Identity Routes
 */

use NexusAcademic\Core\Router;
use NexusAcademic\Controllers\AuthController;
use NexusAcademic\Middleware\AuthMiddleware;
use NexusAcademic\Middleware\RateLimitMiddleware;

/** @var Router $router */
$router->group('auth', function (Router $r) {
    // Public authentication endpoints with rate limiting
    $r->post('register', [AuthController::class, 'register'], [
        RateLimitMiddleware::handle(10, 60, 'reg')
    ]);

    $r->post('login', [AuthController::class, 'login'], [
        RateLimitMiddleware::handle(15, 60, 'login')
    ]);

    // Authenticated profile & session endpoints
    $r->get('me', [AuthController::class, 'getMe'], [
        AuthMiddleware::handle()
    ]);

    $r->put('profile', [AuthController::class, 'updateProfile'], [
        AuthMiddleware::handle()
    ]);

    $r->post('change-password', [AuthController::class, 'changePassword'], [
        AuthMiddleware::handle(),
        RateLimitMiddleware::handle(5, 60, 'chpass')
    ]);

    $r->post('delete-account', [AuthController::class, 'deleteAccount'], [
        AuthMiddleware::handle()
    ]);

    $r->delete('delete-account', [AuthController::class, 'deleteAccount'], [
        AuthMiddleware::handle()
    ]);

    $r->delete('me', [AuthController::class, 'deleteAccount'], [
        AuthMiddleware::handle()
    ]);
});
