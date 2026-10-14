<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * System Audit & Metrics Controller (Thin HTTP Adapter)
 */

namespace NexusAcademic\Controllers;

use NexusAcademic\Core\Request;
use NexusAcademic\Core\Response;
use NexusAcademic\Services\AuditService;
use Exception;

require_once __DIR__ . '/../Core/Request.php';
require_once __DIR__ . '/../Core/Response.php';
require_once __DIR__ . '/../Services/AuditService.php';

class AuditController {

    public static function getAuditLogs($req = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $limit = ($req instanceof Request) ? (int)$req->getQuery('limit', 100) : (isset($_GET['limit']) ? (int)$_GET['limit'] : 100);

            $logs = AuditService::getAuditLogs($user, $limit);
            Response::json($logs);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'AUDIT_LOGS_ERROR', $code);
        }
    }

    public static function getGlobalGradeHistory($req = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $history = AuditService::getGlobalGradeHistory($user);
            Response::json($history);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'GRADE_AUDIT_ERROR', $code);
        }
    }

    public static function getSystemMetrics($req = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $metrics = AuditService::getSystemMetrics($user);
            Response::json($metrics);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'SYSTEM_METRICS_ERROR', $code);
        }
    }
}

// Global class alias for legacy procedural includes
class_alias(AuditController::class, 'AuditController');
