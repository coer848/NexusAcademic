<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * Academic Structure, Courses, Groups & Enrollment Routes
 */

use NexusAcademic\Core\Router;
use NexusAcademic\Controllers\AcademicController;
use NexusAcademic\Middleware\AuthMiddleware;
use NexusAcademic\Middleware\RbacMiddleware;

/** @var Router $router */
$router->group('academic', function (Router $r) {
    // Institution configuration
    $r->get('institution', [AcademicController::class, 'getInstitution'], [
        AuthMiddleware::handle()
    ]);
    $r->put('institution', [AcademicController::class, 'updateInstitution'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('ADMIN')
    ]);

    // Academic periods
    $r->get('periods', [AcademicController::class, 'getPeriods'], [
        AuthMiddleware::handle()
    ]);
    $r->post('periods', [AcademicController::class, 'createPeriod'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('ADMIN')
    ]);

    // Subjects catalog
    $r->get('subjects', [AcademicController::class, 'getSubjects'], [
        AuthMiddleware::handle()
    ]);
    $r->post('subjects', [AcademicController::class, 'createSubject'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('ADMIN')
    ]);

    // Groups & Classes
    $r->get('groups', [AcademicController::class, 'getGroups'], [
        AuthMiddleware::handle()
    ]);
    $r->post('groups', [AcademicController::class, 'createGroup'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('ADMIN')
    ]);

    // Teacher direct course creation
    $r->post('teacher-course', [AcademicController::class, 'createTeacherCourse'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('TEACHER', 'ADMIN')
    ]);

    // Group details & individual group operations
    $r->get('groups/{id:\d+}', [AcademicController::class, 'getGroupDetails'], [
        AuthMiddleware::handle()
    ]);
    $r->put('groups/{id:\d+}', [AcademicController::class, 'updateGroup'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('TEACHER', 'ADMIN')
    ]);
    $r->delete('groups/{id:\d+}', [AcademicController::class, 'deleteGroup'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('TEACHER', 'ADMIN')
    ]);

    // Student self-enrollment & catalog
    $r->get('available-courses', [AcademicController::class, 'getAvailableCourses'], [
        AuthMiddleware::handle()
    ]);
    $r->post('enrollments', [AcademicController::class, 'enrollStudent'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('ADMIN', 'STUDENT')
    ]);

    // Teachers directory & management
    $r->get('teachers', [AcademicController::class, 'getTeachers'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('ADMIN')
    ]);
    $r->put('teachers/{id:\d+}', [AcademicController::class, 'updateTeacher'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('ADMIN')
    ]);

    // Students directory
    $r->get('students', [AcademicController::class, 'getStudents'], [
        AuthMiddleware::handle(),
        RbacMiddleware::handle('ADMIN', 'TEACHER')
    ]);
});
