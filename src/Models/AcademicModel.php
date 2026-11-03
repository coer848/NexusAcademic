<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * Academic Structure & Enrollment Data Model (Repository Pattern)
 * 100% Parameterized Queries & Anti-IDOR Protection
 */

namespace NexusAcademic\Models;

use PDO;
use Database;

require_once __DIR__ . '/../../config/database.php';

class AcademicModel {

    public static function getInstitution(): ?array {
        $pdo = Database::getConnection();
        $stmt = $pdo->query('SELECT * FROM institutions LIMIT 1');
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function updateInstitution(array $data): bool {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            UPDATE institutions 
            SET name = COALESCE(?, name),
                code = COALESCE(?, code),
                grading_scale = COALESCE(?, grading_scale),
                min_grade = COALESCE(?, min_grade),
                max_grade = COALESCE(?, max_grade),
                min_passing_grade = COALESCE(?, min_passing_grade)
            WHERE id = 1
        ');
        return $stmt->execute([
            $data['name'] ?? null,
            $data['code'] ?? null,
            $data['grading_scale'] ?? ($data['gradingScale'] ?? null),
            isset($data['min_grade']) ? (float)$data['min_grade'] : (isset($data['minGrade']) ? (float)$data['minGrade'] : null),
            isset($data['max_grade']) ? (float)$data['max_grade'] : (isset($data['maxGrade']) ? (float)$data['maxGrade'] : null),
            isset($data['min_passing_grade']) ? (float)$data['min_passing_grade'] : (isset($data['minPassingGrade']) ? (float)$data['minPassingGrade'] : null)
        ]);
    }

    public static function getPeriods(): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->query('
            SELECT 
                id,
                institution_id,
                name,
                SUBSTRING_INDEX(name, " ", 1) AS code,
                start_date,
                end_date,
                is_active,
                created_at
            FROM academic_periods
            ORDER BY id DESC
        ');
        return $stmt->fetchAll();
    }

    public static function createPeriod(string $name, string $startDate, string $endDate, int $isActive): int {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            INSERT INTO academic_periods (name, start_date, end_date, is_active)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$name, $startDate, $endDate, $isActive]);
        return (int)$pdo->lastInsertId();
    }

    public static function getSubjects(): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->query('SELECT * FROM subjects ORDER BY code ASC');
        return $stmt->fetchAll();
    }

    public static function createSubject(string $code, string $name, int $credits, ?string $description): int {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            INSERT INTO subjects (code, name, credits, description)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$code, $name, $credits, $description]);
        return (int)$pdo->lastInsertId();
    }

    public static function getGroups(?array $user = null): array {
        $pdo = Database::getConnection();

        $sql = '
            SELECT 
                cg.*,
                cg.schedule AS schedule_json,
                s.name AS subject_name, s.code AS subject_code, s.credits,
                ap.name AS period_name,
                u.first_name AS teacher_first_name, u.last_name AS teacher_last_name, u.email AS teacher_email,
                t.teacher_code,
                (SELECT COUNT(*) FROM enrollments WHERE group_id = cg.id AND status = "ACTIVE") AS enrolled_count,
                (SELECT COUNT(*) FROM enrollments WHERE group_id = cg.id AND status = "ACTIVE") AS enrolled_students_count,
                (SELECT COUNT(*) FROM class_sessions WHERE group_id = cg.id) AS total_sessions_count
            FROM course_groups cg
            JOIN subjects s ON cg.subject_id = s.id
            JOIN academic_periods ap ON cg.period_id = ap.id
            LEFT JOIN teachers t ON cg.teacher_id = t.id
            LEFT JOIN users u ON t.user_id = u.id
        ';

        $params = [];
        if ($user) {
            if (($user['role'] ?? '') === 'TEACHER') {
                $sql .= ' WHERE cg.teacher_id = ?';
                $params[] = (int)($user['teacherId'] ?? 0);
            } elseif (($user['role'] ?? '') === 'STUDENT') {
                $sql .= ' JOIN enrollments e ON e.group_id = cg.id WHERE e.student_id = ? AND e.status = "ACTIVE"';
                $params[] = (int)($user['studentId'] ?? 0);
            }
        }

        $sql .= ' ORDER BY cg.group_code ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function getGroupById(int $id): ?array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT 
                cg.*,
                cg.schedule AS schedule_json,
                s.name AS subject_name, s.code AS subject_code, s.credits,
                ap.name AS period_name,
                u.first_name AS teacher_first_name, u.last_name AS teacher_last_name, u.email AS teacher_email,
                t.teacher_code,
                (SELECT COUNT(*) FROM enrollments WHERE group_id = cg.id AND status = "ACTIVE") AS enrolled_count,
                (SELECT COUNT(*) FROM enrollments WHERE group_id = cg.id AND status = "ACTIVE") AS enrolled_students_count,
                (SELECT COUNT(*) FROM class_sessions WHERE group_id = cg.id) AS total_sessions_count
            FROM course_groups cg
            JOIN subjects s ON cg.subject_id = s.id
            JOIN academic_periods ap ON cg.period_id = ap.id
            LEFT JOIN teachers t ON cg.teacher_id = t.id
            LEFT JOIN users u ON t.user_id = u.id
            WHERE cg.id = ?
            LIMIT 1
        ');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function createGroup(array $data): int {
        $pdo = Database::getConnection();

        $subjectId = (int)($data['subject_id'] ?? $data['subjectId'] ?? 0);
        $periodId  = (int)($data['period_id'] ?? $data['periodId'] ?? 0);
        $teacherId = (int)($data['teacher_id'] ?? $data['teacherId'] ?? 0);
        $groupCode = trim((string)($data['group_code'] ?? $data['groupCode'] ?? ''));
        $schedule  = trim((string)($data['schedule'] ?? $data['schedule_json'] ?? 'Horario por definir'));
        $classroom = trim((string)($data['classroom'] ?? 'Aula por asignar'));
        $geoLat    = isset($data['geo_lat']) ? (float)$data['geo_lat'] : (isset($data['geoLat']) ? (float)$data['geoLat'] : 4.6097000);
        $geoLng    = isset($data['geo_lng']) ? (float)$data['geo_lng'] : (isset($data['geoLng']) ? (float)$data['geoLng'] : -74.0817000);
        $geoRadius = isset($data['geo_radius_meters']) ? (float)$data['geo_radius_meters'] : (isset($data['geoRadiusMeters']) ? (float)$data['geoRadiusMeters'] : 100.00);

        $stmt = $pdo->prepare('
            INSERT INTO course_groups (subject_id, period_id, teacher_id, group_code, schedule, classroom, geo_lat, geo_lng, geo_radius_meters)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $subjectId,
            $periodId,
            $teacherId,
            $groupCode,
            $schedule,
            $classroom,
            $geoLat,
            $geoLng,
            $geoRadius
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function updateGroup(int $id, array $data): bool {
        $pdo = Database::getConnection();

        $teacherId = isset($data['teacher_id']) ? (int)$data['teacher_id'] : (isset($data['teacherId']) ? (int)$data['teacherId'] : null);
        $classroom = $data['classroom'] ?? null;
        $schedule  = $data['schedule'] ?? ($data['schedule_json'] ?? null);
        $geoLat    = isset($data['geo_lat']) ? (float)$data['geo_lat'] : (isset($data['geoLat']) ? (float)$data['geoLat'] : null);
        $geoLng    = isset($data['geo_lng']) ? (float)$data['geo_lng'] : (isset($data['geoLng']) ? (float)$data['geoLng'] : null);
        $geoRadius = isset($data['geo_radius_meters']) ? (float)$data['geo_radius_meters'] : (isset($data['geoRadiusMeters']) ? (float)$data['geoRadiusMeters'] : null);

        $stmt = $pdo->prepare('
            UPDATE course_groups 
            SET teacher_id = COALESCE(?, teacher_id),
                classroom = COALESCE(?, classroom),
                schedule = COALESCE(?, schedule),
                geo_lat = COALESCE(?, geo_lat),
                geo_lng = COALESCE(?, geo_lng),
                geo_radius_meters = COALESCE(?, geo_radius_meters)
            WHERE id = ?
        ');
        return $stmt->execute([
            $teacherId ?: null,
            $classroom,
            $schedule,
            $geoLat,
            $geoLng,
            $geoRadius,
            $id
        ]);
    }

    public static function deleteGroup(int $id): bool {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM course_groups WHERE id = ?');
        return $stmt->execute([$id]);
    }

    public static function getAvailableCourses(int $studentId): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT 
                cg.id,
                cg.group_code,
                cg.classroom,
                cg.schedule,
                cg.schedule AS schedule_json,
                30 AS max_students,
                s.id AS subject_id,
                s.name AS subject_name,
                s.code AS subject_code,
                s.credits,
                ap.name AS period_name,
                u.first_name AS teacher_first_name,
                u.last_name AS teacher_last_name,
                (SELECT COUNT(*) FROM enrollments WHERE group_id = cg.id AND status = "ACTIVE") AS enrolled_count,
                (SELECT COUNT(*) FROM enrollments WHERE group_id = cg.id AND status = "ACTIVE") AS enrolled_students_count,
                CASE WHEN EXISTS(
                    SELECT 1 FROM enrollments WHERE group_id = cg.id AND student_id = ? AND status = "ACTIVE"
                ) THEN 1 ELSE 0 END AS is_enrolled
            FROM course_groups cg
            JOIN subjects s ON cg.subject_id = s.id
            JOIN academic_periods ap ON cg.period_id = ap.id
            LEFT JOIN teachers t ON cg.teacher_id = t.id
            LEFT JOIN users u ON t.user_id = u.id
            WHERE ap.is_active = 1
            ORDER BY s.name ASC, cg.group_code ASC
        ');
        $stmt->execute([$studentId]);
        return $stmt->fetchAll();
    }

    public static function isStudentEnrolled(int $groupId, int $studentId): bool {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id FROM enrollments WHERE group_id = ? AND student_id = ? AND status = "ACTIVE" LIMIT 1');
        $stmt->execute([$groupId, $studentId]);
        return (bool)$stmt->fetch();
    }

    public static function enrollStudent(int $groupId, int $studentId): int {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            INSERT INTO enrollments (student_id, group_id, status)
            VALUES (?, ?, "ACTIVE")
        ');
        $stmt->execute([$studentId, $groupId]);
        return (int)$pdo->lastInsertId();
    }

    public static function getTeachers(): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->query('
            SELECT 
                t.id,
                t.teacher_code,
                t.department,
                t.specialty,
                u.created_at,
                u.id AS user_id,
                u.first_name,
                u.last_name,
                u.email,
                u.phone,
                u.status,
                (SELECT COUNT(*) FROM course_groups cg WHERE cg.teacher_id = t.id) AS assigned_groups_count
            FROM teachers t
            JOIN users u ON t.user_id = u.id
            ORDER BY u.last_name ASC, u.first_name ASC
        ');
        return $stmt->fetchAll();
    }

    public static function updateTeacher(int $teacherId, array $data): bool {
        return Database::transaction(function (PDO $db) use ($teacherId, $data) {
            $tStmt = $db->prepare('SELECT user_id FROM teachers WHERE id = ?');
            $tStmt->execute([$teacherId]);
            $row = $tStmt->fetch();
            if (!$row) return false;

            $userId = (int)$row['user_id'];
            $firstName = $data['first_name'] ?? ($data['firstName'] ?? null);
            $lastName  = $data['last_name'] ?? ($data['lastName'] ?? null);
            $phone     = $data['phone'] ?? null;

            if ($firstName !== null || $lastName !== null || $phone !== null) {
                $uStmt = $db->prepare('
                    UPDATE users 
                    SET first_name = COALESCE(?, first_name),
                        last_name = COALESCE(?, last_name),
                        phone = COALESCE(?, phone)
                    WHERE id = ?
                ');
                $uStmt->execute([
                    $firstName,
                    $lastName,
                    $phone,
                    $userId
                ]);
            }

            $tUpd = $db->prepare('
                UPDATE teachers 
                SET department = COALESCE(?, department),
                    specialty = COALESCE(?, specialty)
                WHERE id = ?
            ');
            return $tUpd->execute([
                $data['department'] ?? null,
                $data['specialty'] ?? null,
                $teacherId
            ]);
        });
    }

    public static function getStudents(): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->query('
            SELECT 
                st.id,
                st.student_code,
                st.career,
                st.semester,
                u.created_at,
                u.id AS user_id,
                u.first_name,
                u.last_name,
                u.email,
                u.phone,
                u.status,
                (SELECT COUNT(*) FROM enrollments e WHERE e.student_id = st.id AND e.status = "ACTIVE") AS enrolled_courses_count
            FROM students st
            JOIN users u ON st.user_id = u.id
            ORDER BY u.last_name ASC, u.first_name ASC
        ');
        return $stmt->fetchAll();
    }
}
