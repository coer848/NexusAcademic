<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * Evaluations, Gradebook & Grading Routes
 */

use NexusAcademic\Core\Router;
use NexusAcademic\Controllers\GradeController;
use NexusAcademic\Middleware\AuthMiddleware;
use NexusAcademic\Middleware\RbacMiddleware;

/** @var Router $router */

// Evaluations criteria
$router->group('evaluations', function (Router $r) {
    $r->post('', [GradeController::class, 'createEvaluation'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('TEACHER', 'ADMIN')
    ]);

    $r->put('{id:\d+}', [GradeController::class, 'updateEvaluation'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('TEACHER', 'ADMIN')
    ]);

    $r->delete('{id:\d+}', [GradeController::class, 'deleteEvaluation'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('TEACHER', 'ADMIN')
    ]);
});

// Grades & Gradebook Matrix
$router->group('grades', function (Router $r) {
    $r->post('', [GradeController::class, 'saveGrade'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('TEACHER', 'ADMIN')
    ]);

    $r->post('batch', [GradeController::class, 'saveBatchGrades'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('TEACHER', 'ADMIN')
    ]);

    $r->get('group/{id:\d+}/matrix', [GradeController::class, 'getGroupGradesMatrix'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('TEACHER', 'ADMIN')
    ]);

    $r->get('my-grades', [GradeController::class, 'getMyGrades'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('STUDENT')
    ]);

    $r->get('{id:\d+}/history', [GradeController::class, 'getGradeAuditHistory'], [
        AuthMiddleware::handle()
    ]);
});
