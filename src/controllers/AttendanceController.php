<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * Attendance & Live Class Session Controller (Thin HTTP Adapter)
 */

namespace NexusAcademic\Controllers;

use NexusAcademic\Core\Request;
use NexusAcademic\Core\Response;
use NexusAcademic\Services\AttendanceService;
use Exception;

require_once __DIR__ . '/../Core/Request.php';
require_once __DIR__ . '/../Core/Response.php';
require_once __DIR__ . '/../Services/AttendanceService.php';

class AttendanceController {

    public static function startSession($req, ?array $body = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $data = ($req instanceof Request) ? $req->getBody() : (array)$body;
            $ip   = ($req instanceof Request) ? $req->getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

            $result = AttendanceService::startSession($user, $data, $ip);
            Response::created($result);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'START_SESSION_ERROR', $code);
        }
    }

    public static function getSessionQRToken($req, $sessionId = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $id   = ($req instanceof Request) ? (int)$req->getParam('id') : (int)$sessionId;

            $result = AttendanceService::getSessionQRToken($user, $id);
            Response::json($result);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'QR_TOKEN_ERROR', $code);
        }
    }

    public static function closeSession($req, $sessionId = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $id   = ($req instanceof Request) ? (int)$req->getParam('id') : (int)$sessionId;
            $ip   = ($req instanceof Request) ? $req->getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

            AttendanceService::closeSession($user, $id, $ip);
            Response::success(null, 'Sesión de clase finalizada correctamente.');
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'CLOSE_SESSION_ERROR', $code);
        }
    }

    public static function registerAttendance($req, ?array $body = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $data = ($req instanceof Request) ? $req->getBody() : (array)$body;
            $ip   = ($req instanceof Request) ? $req->getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

            $result = AttendanceService::registerAttendance($user, $data, $ip);
            Response::created($result);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'ATTENDANCE_REGISTRATION_ERROR', $code);
        }
    }

    public static function getSessionAttendance($req, $sessionId = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $id   = ($req instanceof Request) ? (int)$req->getParam('id') : (int)$sessionId;

            $attendances = AttendanceService::getSessionAttendance($user, $id);
            Response::json($attendances);
        } catch (Exception $e) {
            $rawCode = $e->getCode();
            $code = (is_numeric($rawCode) && (int)$rawCode >= 400 && (int)$rawCode < 600) ? (int)$rawCode : 400;
            Response::error($e->getMessage(), 'GET_ATTENDANCE_ERROR', $code);
        }
    }

    public static function getMyAttendanceHistory($req = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $history = AttendanceService::getMyAttendanceHistory($user);
            Response::json($history);
        } catch (Exception $e) {
            $rawCode = $e->getCode();
            $code = (is_numeric($rawCode) && (int)$rawCode >= 400 && (int)$rawCode < 600) ? (int)$rawCode : 400;
            Response::error($e->getMessage(), 'ATTENDANCE_HISTORY_ERROR', $code);
        }
    }
}

// Global class alias for legacy procedural includes
class_alias(AttendanceController::class, 'AttendanceController');
