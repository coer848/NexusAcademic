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
                TIMESTAMPDIFF(SECOND, cs.started_at, CURRENT_TIMESTAMP) AS elapsed_seconds,
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

    public static function getLatestSessionForStudent(int $studentId): ?array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT 
                cs.*,
                TIMESTAMPDIFF(SECOND, cs.started_at, CURRENT_TIMESTAMP) AS elapsed_seconds,
                cg.group_code, cg.classroom,
                s.name AS subject_name,
                u.first_name AS teacher_first_name, u.last_name AS teacher_last_name
            FROM class_sessions cs
            JOIN course_groups cg ON cs.group_id = cg.id
            JOIN enrollments e ON e.group_id = cg.id
            JOIN subjects s ON cg.subject_id = s.id
            JOIN teachers t ON cs.teacher_id = t.id
            JOIN users u ON t.user_id = u.id
            WHERE e.student_id = ? AND e.status = "ACTIVE"
            ORDER BY (cs.status = "ACTIVE") DESC, cs.started_at DESC, cs.id DESC
            LIMIT 1
        ');
        $stmt->execute([$studentId]);
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

    public static function isNonceUsed(string $nonce): bool {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id FROM attendance_tokens WHERE nonce = ? LIMIT 1');
        $stmt->execute([$nonce]);
        return (bool)$stmt->fetch();
    }

    public static function recordUsedNonce(int $sessionId, string $nonce, int $studentId): void {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            INSERT IGNORE INTO attendance_tokens (session_id, nonce, student_id)
            VALUES (?, ?, ?)
        ');
        $stmt->execute([$sessionId, $nonce, $studentId]);
    }

    public static function recordAttendance(array $data): int {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            INSERT INTO attendances (
                session_id,
                student_id,
                status,
                registered_at,
                verification_method,
                ip_address,
                user_agent,
                device_info,
                distance_meters,
                is_flagged,
                flag_reason
            )
            VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $data['session_id'],
            $data['student_id'],
            $data['status'] ?? 'PRESENT',
            $data['verification_method'] ?? 'QR_DYNAMIC_HMAC',
            $data['ip_address'] ?? null,
            $data['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null),
            $data['device_info'] ?? ($data['device_fingerprint'] ?? null),
            $data['distance_meters'] ?? null,
            !empty($data['is_flagged']) ? 1 : 0,
            $data['flag_reason'] ?? null
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function getSessionAttendances(int $sessionId): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT 
                a.*,
                a.registered_at AS scanned_at,
                st.student_code, st.career,
                u.first_name, u.last_name, u.email
            FROM attendances a
            JOIN students st ON a.student_id = st.id
            JOIN users u ON st.user_id = u.id
            WHERE a.session_id = ?
            ORDER BY a.registered_at DESC, a.id DESC
        ');
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll();
    }

    public static function getEnrolledCountByGroup(int $groupId): int {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM enrollments WHERE group_id = ? AND status = "ACTIVE"');
        $stmt->execute([$groupId]);
        $row = $stmt->fetch();
        return $row ? (int)$row['cnt'] : 0;
    }

    public static function getMyAttendanceHistory(int $studentId): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT 
                a.*,
                a.registered_at AS scanned_at,
                cs.topic,
                cs.started_at,
                cs.started_at AS session_date,
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
            ORDER BY a.registered_at DESC, a.id DESC
        ');
        $stmt->execute([$studentId]);
        return $stmt->fetchAll();
    }
}
