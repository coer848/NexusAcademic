<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * System Audit Trails & Telemetry Routes
 */

use NexusAcademic\Core\Router;
use NexusAcademic\Controllers\AuditController;
use NexusAcademic\Middleware\AuthMiddleware;
use NexusAcademic\Middleware\RbacMiddleware;

/** @var Router $router */
$router->group('audit', function (Router $r) {
    // Audit logs (Admin only)
    $r->get('logs', [AuditController::class, 'getAuditLogs'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('ADMIN')
    ]);

    // Global grade change telemetry (Admin & Teacher)
    $r->get('grades-history', [AuditController::class, 'getGlobalGradeHistory'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('ADMIN', 'TEACHER')
    ]);

    // System KPIs and metrics (Admin only)
    $r->get('metrics', [AuditController::class, 'getSystemMetrics'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('ADMIN')
    ]);
});
