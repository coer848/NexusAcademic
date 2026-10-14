<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * Live Class Sessions & Dynamic QR Attendance Routes
 */

use NexusAcademic\Core\Router;
use NexusAcademic\Controllers\AttendanceController;
use NexusAcademic\Middleware\AuthMiddleware;
use NexusAcademic\Middleware\RbacMiddleware;

/** @var Router $router */

// Class Sessions
$router->group('sessions', function (Router $r) {
    $r->post('start', [AttendanceController::class, 'startSession'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('TEACHER', 'ADMIN')
    ]);

    $r->get('{id:\d+}/qr-token', [AttendanceController::class, 'getSessionQRToken'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('TEACHER', 'ADMIN')
    ]);

    $r->post('{id:\d+}/close', [AttendanceController::class, 'closeSession'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('TEACHER', 'ADMIN')
    ]);
});

// Student Attendance & Verification
$router->group('attendance', function (Router $r) {
    $r->post('register', [AttendanceController::class, 'registerAttendance'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('STUDENT')
    ]);

    $r->get('session/{id:\d+}', [AttendanceController::class, 'getSessionAttendance'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('TEACHER', 'ADMIN')
    ]);

    $r->get('my-history', [AttendanceController::class, 'getMyAttendanceHistory'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('STUDENT')
    ]);
});
