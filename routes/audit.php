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
    // Audit logs (SuperAdmin & Admin)
    $r->get('logs', [AuditController::class, 'getAuditLogs'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('SUPERADMIN', 'ADMIN')
    ]);

    // Global grade change telemetry (SuperAdmin, Admin & Teacher)
    $r->get('grades-history', [AuditController::class, 'getGlobalGradeHistory'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('SUPERADMIN', 'ADMIN', 'TEACHER')
    ]);

    // System KPIs and metrics (SuperAdmin & Admin)
    $r->get('metrics', [AuditController::class, 'getSystemMetrics'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('SUPERADMIN', 'ADMIN')
    ]);
});
