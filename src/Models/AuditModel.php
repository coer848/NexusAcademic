<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * Audit Trails, Security Logs & System Telemetry Data Model (Repository Pattern)
 * 100% Parameterized Queries & Anti-IDOR Protection
 */

namespace NexusAcademic\Models;

use PDO;
use Database;

require_once __DIR__ . '/../../config/database.php';

class AuditModel {

    public static function log(int $userId, string $action, string $resourceType, ?string $resourceId, ?array $details = null, ?string $ip = null): void {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare('
                INSERT INTO audit_logs (user_id, action, resource_type, resource_id, details_json, ip_address)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $userId,
                $action,
                $resourceType,
                $resourceId,
                $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
                $ip ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1')
            ]);
        } catch (\Throwable $e) {
            // Fail safe on logging errors so application execution is never blocked
            error_log('Audit logging failed: ' . $e->getMessage());
        }
    }

    public static function getAuditLogs(int $limit = 100): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT 
                al.*,
                u.first_name, u.last_name, u.email, u.role
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            ORDER BY al.created_at DESC, al.id DESC
            LIMIT ?
        ');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function getGlobalGradeHistory(?int $teacherId = null): array {
        $pdo = Database::getConnection();

        $sql = '
            SELECT 
                gh.id, gh.old_score, gh.new_score, gh.change_reason, gh.created_at, gh.ip_address,
                ev.name AS evaluation_name, ev.weight AS evaluation_weight,
                cg.group_code, s.name AS subject_name,
                st_u.first_name AS student_first_name, st_u.last_name AS student_last_name, st.student_code,
                ch_u.first_name AS changed_by_first_name, ch_u.last_name AS changed_by_last_name, ch_u.role AS changed_by_role
            FROM grade_history gh
            JOIN evaluations ev ON gh.evaluation_id = ev.id
            JOIN course_groups cg ON ev.group_id = cg.id
            JOIN subjects s ON cg.subject_id = s.id
            JOIN students st ON gh.student_id = st.id
            JOIN users st_u ON st.user_id = st_u.id
            JOIN users ch_u ON gh.changed_by_user_id = ch_u.id
        ';

        $params = [];
        if ($teacherId !== null) {
            $sql .= ' WHERE cg.teacher_id = ?';
            $params[] = $teacherId;
        }

        $sql .= ' ORDER BY gh.created_at DESC, gh.id DESC LIMIT 150';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function getSystemMetrics(): array {
        $pdo = Database::getConnection();

        $usersCount = $pdo->query('SELECT role, COUNT(*) as count FROM users GROUP BY role')->fetchAll();
        $totalStudents = (int)$pdo->query('SELECT COUNT(*) as count FROM students')->fetch()['count'];
        $totalTeachers = (int)$pdo->query('SELECT COUNT(*) as count FROM teachers')->fetch()['count'];
        $totalGroups = (int)$pdo->query('SELECT COUNT(*) as count FROM course_groups')->fetch()['count'];
        $totalSessions = (int)$pdo->query('SELECT COUNT(*) as count FROM class_sessions')->fetch()['count'];
        $totalAttendances = (int)$pdo->query('SELECT COUNT(*) as count FROM attendances')->fetch()['count'];
        $flaggedAttendances = (int)$pdo->query('SELECT COUNT(*) as count FROM attendances WHERE is_flagged = 1')->fetch()['count'];
        $totalGrades = (int)$pdo->query('SELECT COUNT(*) as count FROM grades')->fetch()['count'];
        $totalGradeChanges = (int)$pdo->query('SELECT COUNT(*) as count FROM grade_history WHERE old_score IS NOT NULL')->fetch()['count'];

        return [
            'usersCount'         => $usersCount,
            'totalStudents'      => $totalStudents,
            'totalTeachers'      => $totalTeachers,
            'totalGroups'        => $totalGroups,
            'totalSessions'      => $totalSessions,
            'totalAttendances'   => $totalAttendances,
            'flaggedAttendances' => $flaggedAttendances,
            'totalGrades'        => $totalGrades,
            'totalGradeChanges'  => $totalGradeChanges
        ];
    }
}
