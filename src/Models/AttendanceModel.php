<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * Attendance & Live Class Session Data Model (Repository Pattern)
 * 100% Parameterized Queries & Anti-IDOR Protection
 */

namespace NexusAcademic\Models;

use PDO;
use Database;

require_once __DIR__ . '/../../config/database.php';

class AttendanceModel {

    public static function getSessionById(int $sessionId): ?array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT 
                cs.*,
                cg.group_code, cg.classroom,
                s.name AS subject_name,
                u.first_name AS teacher_first_name, u.last_name AS teacher_last_name
            FROM class_sessions cs
            JOIN course_groups cg ON cs.group_id = cg.id
            JOIN subjects s ON cg.subject_id = s.id
            JOIN teachers t ON cs.teacher_id = t.id
            JOIN users u ON t.user_id = u.id
            WHERE cs.id = ?
            LIMIT 1
        ');
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function closeActiveSessions(int $groupId): bool {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            UPDATE class_sessions 
            SET status = "CLOSED", closed_at = CURRENT_TIMESTAMP 
            WHERE group_id = ? AND status = "ACTIVE"
        ');
        return $stmt->execute([$groupId]);
    }

    public static function createSession(array $data): int {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            INSERT INTO class_sessions (group_id, teacher_id, topic, status, session_secret, geo_lat, geo_lng, geo_radius_meters)
            VALUES (?, ?, ?, "ACTIVE", ?, ?, ?, ?)
        ');
        $stmt->execute([
            $data['group_id'],
            $data['teacher_id'],
            $data['topic'],
            $data['session_secret'],
            $data['geo_lat'],
            $data['geo_lng'],
            $data['geo_radius_meters']
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function closeSession(int $sessionId): bool {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            UPDATE class_sessions 
            SET status = "CLOSED", closed_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ');
        return $stmt->execute([$sessionId]);
    }

    public static function hasStudentAttended(int $sessionId, int $studentId): bool {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id FROM attendances WHERE session_id = ? AND student_id = ? LIMIT 1');
        $stmt->execute([$sessionId, $studentId]);
        return (bool)$stmt->fetch();
    }

    public static function recordAttendance(array $data): int {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            INSERT INTO attendances (session_id, student_id, scanned_at, verification_method, device_fingerprint, ip_address, geo_lat, geo_lng, is_flagged, flag_reason)
            VALUES (?, ?, CURRENT_TIMESTAMP, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $data['session_id'],
            $data['student_id'],
            $data['verification_method'] ?? 'DYNAMIC_QR',
            $data['device_fingerprint'] ?? null,
            $data['ip_address'] ?? null,
            $data['geo_lat'] ?? null,
            $data['geo_lng'] ?? null,
            $data['is_flagged'] ? 1 : 0,
            $data['flag_reason'] ?? null
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function getSessionAttendances(int $sessionId): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT 
                a.*,
                st.student_code, st.career,
                u.first_name, u.last_name, u.email
            FROM attendances a
            JOIN students st ON a.student_id = st.id
            JOIN users u ON st.user_id = u.id
            WHERE a.session_id = ?
            ORDER BY a.scanned_at ASC
        ');
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll();
    }

    public static function getMyAttendanceHistory(int $studentId): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT 
                a.*,
                cs.topic, cs.created_at AS session_date,
                cg.group_code, cg.classroom,
                s.name AS subject_name, s.code AS subject_code,
                u.first_name AS teacher_first_name, u.last_name AS teacher_last_name
            FROM attendances a
            JOIN class_sessions cs ON a.session_id = cs.id
            JOIN course_groups cg ON cs.group_id = cg.id
            JOIN subjects s ON cg.subject_id = s.id
            JOIN teachers t ON cs.teacher_id = t.id
            JOIN users u ON t.user_id = u.id
            WHERE a.student_id = ?
            ORDER BY a.scanned_at DESC
        ');
        $stmt->execute([$studentId]);
        return $stmt->fetchAll();
    }
}
