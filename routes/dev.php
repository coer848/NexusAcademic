<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * Developer Master & System SuperAdmin Routes
 */

use NexusAcademic\Core\Router;
use NexusAcademic\Controllers\DevController;
use NexusAcademic\Middleware\AuthMiddleware;
use NexusAcademic\Middleware\RbacMiddleware;

/** @var Router $router */
$router->group('dev', function (Router $r) {
    // System health & telemetry overview
    $r->get('overview', [DevController::class, 'getOverview'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('SUPERADMIN', 'ADMIN')
    ]);

    // All users list
    $r->get('users', [DevController::class, 'getUsers'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('SUPERADMIN', 'ADMIN')
    ]);

    // Create user directly (SuperAdmin / Admin)
    $r->post('users', [DevController::class, 'createUser'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('SUPERADMIN', 'ADMIN')
    ]);

    // User status toggle (ACTIVE / INACTIVE)
    $r->put('users/{id:\d+}/status', [DevController::class, 'updateUserStatus'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('SUPERADMIN')
    ]);

    // User role modification
    $r->put('users/{id:\d+}/role', [DevController::class, 'updateUserRole'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('SUPERADMIN')
    ]);

    // Reset user password
    $r->post('users/{id:\d+}/reset-password', [DevController::class, 'resetUserPassword'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('SUPERADMIN')
    ]);

    // Purge user account
    $r->delete('users/{id:\d+}', [DevController::class, 'deleteUser'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('SUPERADMIN')
    ]);
});
