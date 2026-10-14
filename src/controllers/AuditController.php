<?php
/**
 * System Audit & Metrics Controller
 * Provides immutable activity audit logs, grade changes telemetry, and institutional KPIs
 */

require_once __DIR__ . '/../../config/database.php';

class AuditController {

    /**
     * GET /api/audit/logs (ADMIN ONLY)
     */
    public static function getAuditLogs(array $user): void {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        if ($limit <= 0 || $limit > 500) {
            $limit = 100;
        }

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

        echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /api/audit/grades-history (ADMIN & TEACHER)
     */
    public static function getGlobalGradeHistory(array $user): void {
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
        if ($user['role'] === 'TEACHER') {
            $sql .= ' WHERE cg.teacher_id = ?';
            $params[] = (int)$user['teacherId'];
        }

        $sql .= ' ORDER BY gh.created_at DESC, gh.id DESC LIMIT 150';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /api/audit/metrics (ADMIN ONLY)
     */
    public static function getSystemMetrics(array $user): void {
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

        echo json_encode([
            'usersCount'         => $usersCount,
            'totalStudents'      => $totalStudents,
            'totalTeachers'      => $totalTeachers,
            'totalGroups'        => $totalGroups,
            'totalSessions'      => $totalSessions,
            'totalAttendances'   => $totalAttendances,
            'flaggedAttendances' => $flaggedAttendances,
            'totalGrades'        => $totalGrades,
            'totalGradeChanges'  => $totalGradeChanges
        ], JSON_UNESCAPED_UNICODE);
    }
}
