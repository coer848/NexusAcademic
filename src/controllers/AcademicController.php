<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * Academic Structure Controller (Thin HTTP Adapter)
 */

namespace NexusAcademic\Controllers;

use NexusAcademic\Core\Request;
use NexusAcademic\Core\Response;
use NexusAcademic\Services\AcademicService;
use Exception;

require_once __DIR__ . '/../Core/Request.php';
require_once __DIR__ . '/../Core/Response.php';
require_once __DIR__ . '/../Services/AcademicService.php';

class AcademicController {

    public static function getInstitution($req = null): void {
        $inst = AcademicService::getInstitution();
        Response::json($inst);
    }

    public static function updateInstitution($req, ?array $body = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $data = ($req instanceof Request) ? $req->getBody() : (array)$body;
            $ip   = ($req instanceof Request) ? $req->getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

            AcademicService::updateInstitution($user, $data, $ip);
            Response::success(null, 'Configuración institucional actualizada.');
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'UPDATE_INSTITUTION_ERROR', $code);
        }
    }

    public static function getPeriods($req = null): void {
        $periods = AcademicService::getPeriods();
        Response::json($periods);
    }

    public static function createPeriod($req, ?array $body = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $data = ($req instanceof Request) ? $req->getBody() : (array)$body;
            $ip   = ($req instanceof Request) ? $req->getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

            $id = AcademicService::createPeriod($user, $data, $ip);
            Response::created(['periodId' => $id]);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'CREATE_PERIOD_ERROR', $code);
        }
    }

    public static function getSubjects($req = null): void {
        $subjects = AcademicService::getSubjects();
        Response::json($subjects);
    }

    public static function createSubject($req, ?array $body = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $data = ($req instanceof Request) ? $req->getBody() : (array)$body;
            $ip   = ($req instanceof Request) ? $req->getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

            $id = AcademicService::createSubject($user, $data, $ip);
            Response::created(['subjectId' => $id]);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'CREATE_SUBJECT_ERROR', $code);
        }
    }

    public static function getGroups($req = null): void {
        $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
        $groups = AcademicService::getGroups($user);
        Response::json($groups);
    }

    public static function getGroupDetails($req, $groupId = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $id   = ($req instanceof Request) ? (int)$req->getParam('id') : (int)$groupId;

            $details = AcademicService::getGroupDetails($user, $id);
            Response::json($details);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 404;
            Response::error($e->getMessage(), 'GROUP_DETAILS_ERROR', $code);
        }
    }

    public static function createGroup($req, ?array $body = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $data = ($req instanceof Request) ? $req->getBody() : (array)$body;
            $ip   = ($req instanceof Request) ? $req->getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

            $id = AcademicService::createGroup($user, $data, $ip);
            Response::created(['groupId' => $id]);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'CREATE_GROUP_ERROR', $code);
        }
    }

    public static function createTeacherCourse($req, ?array $body = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $data = ($req instanceof Request) ? $req->getBody() : (array)$body;
            $ip   = ($req instanceof Request) ? $req->getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

            $id = AcademicService::createTeacherCourse($user, $data, $ip);
            Response::created(['groupId' => $id]);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'CREATE_COURSE_ERROR', $code);
        }
    }

    public static function updateGroup($req, $groupId = null, ?array $body = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $id   = ($req instanceof Request) ? (int)$req->getParam('id') : (int)$groupId;
            $data = ($req instanceof Request) ? $req->getBody() : (array)$body;
            $ip   = ($req instanceof Request) ? $req->getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

            AcademicService::updateGroup($user, $id, $data, $ip);
            Response::success(null, 'Grupo académico actualizado.');
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'UPDATE_GROUP_ERROR', $code);
        }
    }

    public static function deleteGroup($req, $groupId = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $id   = ($req instanceof Request) ? (int)$req->getParam('id') : (int)$groupId;
            $ip   = ($req instanceof Request) ? $req->getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

            AcademicService::deleteGroup($user, $id, $ip);
            Response::success(null, 'Grupo académico eliminado.');
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'DELETE_GROUP_ERROR', $code);
        }
    }

    public static function getAvailableCourses($req = null): void {
        $user = ($req instanceof Request) ? $req->getUser() : [];
        $courses = AcademicService::getAvailableCourses($user);
        Response::json($courses);
    }

    public static function enrollStudent($req, ?array $body = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $data = ($req instanceof Request) ? $req->getBody() : (array)$body;
            $ip   = ($req instanceof Request) ? $req->getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

            $id = AcademicService::enrollStudent($user, $data, $ip);
            Response::created(['enrollmentId' => $id]);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'ENROLLMENT_ERROR', $code);
        }
    }

    public static function getTeachers($req = null): void {
        $teachers = AcademicService::getTeachers();
        Response::json($teachers);
    }

    public static function updateTeacher($req, $teacherId = null, ?array $body = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $id   = ($req instanceof Request) ? (int)$req->getParam('id') : (int)$teacherId;
            $data = ($req instanceof Request) ? $req->getBody() : (array)$body;
            $ip   = ($req instanceof Request) ? $req->getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

            AcademicService::updateTeacher($user, $id, $data, $ip);
            Response::success(null, 'Datos del docente actualizados.');
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'UPDATE_TEACHER_ERROR', $code);
        }
    }

    public static function getStudents($req = null): void {
        $students = AcademicService::getStudents();
        Response::json($students);
    }
}

// Global class alias for legacy procedural includes
class_alias(AcademicController::class, 'AcademicController');
