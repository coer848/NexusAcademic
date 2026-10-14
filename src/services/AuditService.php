<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * Audit Trails, Telemetry & Security Analytics Business Logic Service
 */

namespace NexusAcademic\Services;

use NexusAcademic\Models\AuditModel;

require_once __DIR__ . '/../Models/AuditModel.php';

class AuditService {

    public static function getAuditLogs(array $user, int $limit = 100): array {
        if ($limit <= 0 || $limit > 500) {
            $limit = 100;
        }
        return AuditModel::getAuditLogs($limit);
    }

    public static function getGlobalGradeHistory(array $user): array {
        $teacherId = null;
        if ($user['role'] === 'TEACHER') {
            $teacherId = (int)($user['teacherId'] ?? 0);
        }
        return AuditModel::getGlobalGradeHistory($teacherId);
    }

    public static function getSystemMetrics(array $user): array {
        return AuditModel::getSystemMetrics();
    }
}
