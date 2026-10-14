<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * Academic Structure & Course Management Business Logic Service
 * Anti-IDOR & High-Integrity Multi-Tenant EdTech
 */

namespace NexusAcademic\Services;

use Database;
use NexusAcademic\Models\AcademicModel;
use NexusAcademic\Models\AuditModel;
use Exception;

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Models/AcademicModel.php';
require_once __DIR__ . '/../Models/AuditModel.php';

class AcademicService {

    public static function getInstitution(): ?array {
        return AcademicModel::getInstitution();
    }

    public static function updateInstitution(array $user, array $data, ?string $ip = null): void {
        AcademicModel::updateInstitution($data);
        AuditModel::log((int)$user['id'], 'INSTITUTION_UPDATED', 'INSTITUTION', '1', $data, $ip);
    }

    public static function getPeriods(): array {
        return AcademicModel::getPeriods();
    }

    public static function createPeriod(array $user, array $data, ?string $ip = null): int {
        $name      = trim($data['name'] ?? '');
        $startDate = trim($data['start_date'] ?? '');
        $endDate   = trim($data['end_date'] ?? '');
        $isActive  = !empty($data['is_active']) ? 1 : 0;

        if (empty($name) || empty($startDate) || empty($endDate)) {
            throw new Exception('Nombre y fechas de inicio/fin son obligatorios.', 400);
        }

        $id = AcademicModel::createPeriod($name, $startDate, $endDate, $isActive);
        AuditModel::log((int)$user['id'], 'PERIOD_CREATED', 'ACADEMIC_PERIOD', (string)$id, ['name' => $name], $ip);
        return $id;
    }

    public static function getSubjects(): array {
        return AcademicModel::getSubjects();
    }

    public static function createSubject(array $user, array $data, ?string $ip = null): int {
        $code        = strtoupper(trim($data['code'] ?? ''));
        $name        = trim($data['name'] ?? '');
        $credits     = isset($data['credits']) ? (int)$data['credits'] : 3;
        $description = trim($data['description'] ?? '');

        if (empty($code) || empty($name)) {
            throw new Exception('Código y nombre de la asignatura son obligatorios.', 400);
        }

        try {
            $id = AcademicModel::createSubject($code, $name, $credits, $description ?: null);
            AuditModel::log((int)$user['id'], 'SUBJECT_CREATED', 'SUBJECT', (string)$id, ['code' => $code, 'name' => $name], $ip);
            return $id;
        } catch (\PDOException $e) {
            if ($e->getCode() == 23000) {
                throw new Exception("Ya existe una asignatura registrada con el código '{$code}'.", 409);
            }
            throw $e;
        }
    }

    public static function getGroups(array $user): array {
        return AcademicModel::getGroups($user);
    }

    public static function getGroupDetails(array $user, int $groupId): array {
        $group = AcademicModel::getGroupById($groupId);
        if (!$group) {
            throw new Exception('Grupo no encontrado.', 404);
        }

        // Anti-IDOR Authorization Check
        if ($user['role'] === 'TEACHER' && (int)$group['teacher_id'] !== (int)$user['teacherId']) {
            throw new Exception('No tiene permisos para acceder a este grupo.', 403);
        }

        $pdo = Database::getConnection();

        // Load enrolled students
        $sStmt = $pdo->prepare('
            SELECT 
                st.id AS student_id, st.student_code, st.career, st.semester,
                u.first_name, u.last_name, u.email, u.phone,
                e.enrolled_at, e.status AS enrollment_status
            FROM enrollments e
            JOIN students st ON e.student_id = st.id
            JOIN users u ON st.user_id = u.id
            WHERE e.group_id = ? AND e.status = "ACTIVE"
            ORDER BY u.last_name ASC, u.first_name ASC
        ');
        $sStmt->execute([$groupId]);
        $enrolledStudents = $sStmt->fetchAll();

        // Load evaluations
        $eStmt = $pdo->prepare('
            SELECT * FROM evaluations 
            WHERE group_id = ? AND status = "ACTIVE"
            ORDER BY due_date ASC, id ASC
        ');
        $eStmt->execute([$groupId]);
        $evaluations = $eStmt->fetchAll();

        // Load latest class session
        $sessStmt = $pdo->prepare('
            SELECT * FROM class_sessions 
            WHERE group_id = ? 
            ORDER BY created_at DESC 
            LIMIT 1
        ');
        $sessStmt->execute([$groupId]);
        $latestSession = $sessStmt->fetch() ?: null;

        return [
            'group'            => $group,
            'students'         => $enrolledStudents,
            'evaluations'      => $evaluations,
            'activeSession'    => ($latestSession && $latestSession['status'] === 'ACTIVE') ? $latestSession : null,
            'latestSession'    => $latestSession
        ];
    }

    public static function createGroup(array $user, array $data, ?string $ip = null): int {
        $subjectId = (int)($data['subject_id'] ?? 0);
        $periodId  = (int)($data['period_id'] ?? 0);
        $groupCode = strtoupper(trim($data['group_code'] ?? ''));

        if (!$subjectId || !$periodId || empty($groupCode)) {
            throw new Exception('subject_id, period_id y group_code son obligatorios.', 400);
        }

        $id = AcademicModel::createGroup($data);
        AuditModel::log((int)$user['id'], 'GROUP_CREATED', 'COURSE_GROUP', (string)$id, ['code' => $groupCode], $ip);
        return $id;
    }

    public static function createTeacherCourse(array $user, array $data, ?string $ip = null): int {
        $teacherId = (int)($user['teacherId'] ?? 0);
        if (!$teacherId && $user['role'] !== 'ADMIN') {
            throw new Exception('No se encontró el perfil docente asociado al usuario.', 403);
        }

        $subjectName = trim($data['subject_name'] ?? '');
        $groupCode   = strtoupper(trim($data['group_code'] ?? ''));
        $classroom   = trim($data['classroom'] ?? 'Aula Virtual / Presencial');
        $schedule    = trim($data['schedule'] ?? 'Horario Flexible');

        if (empty($subjectName) || empty($groupCode)) {
            throw new Exception('El nombre de la asignatura y el código del grupo son obligatorios.', 400);
        }

        $pdo = Database::getConnection();

        // 1. Find or create subject
        $sFind = $pdo->prepare('SELECT id FROM subjects WHERE name = ? LIMIT 1');
        $sFind->execute([$subjectName]);
        $subj = $sFind->fetch();

        if ($subj) {
            $subjectId = (int)$subj['id'];
        } else {
            $codeGen = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $subjectName), 0, 4)) . '-' . rand(100, 999);
            $sIns = $pdo->prepare('INSERT INTO subjects (code, name, credits) VALUES (?, ?, 3)');
            $sIns->execute([$codeGen, $subjectName]);
            $subjectId = (int)$pdo->lastInsertId();
        }

        // 2. Find active period
        $pFind = $pdo->query('SELECT id FROM academic_periods WHERE is_active = 1 ORDER BY id DESC LIMIT 1');
        $period = $pFind->fetch();
        if (!$period) {
            $pIns = $pdo->prepare('INSERT INTO academic_periods (name, start_date, end_date, is_active) VALUES (?, CURRENT_DATE, DATE_ADD(CURRENT_DATE, INTERVAL 6 MONTH), 1)');
            $pIns->execute(['Periodo ' . date('Y') . '-I']);
            $periodId = (int)$pdo->lastInsertId();
        } else {
            $periodId = (int)$period['id'];
        }

        // 3. Create group
        $groupId = AcademicModel::createGroup([
            'subject_id'        => $subjectId,
            'period_id'         => $periodId,
            'teacher_id'        => $teacherId ?: null,
            'group_code'        => $groupCode,
            'classroom'         => $classroom,
            'schedule_json'     => json_encode(['details' => $schedule]),
            'max_students'      => 40,
            'geo_lat'           => 4.6097,
            'geo_lng'           => -74.0817,
            'geo_radius_meters' => 100
        ]);

        AuditModel::log((int)$user['id'], 'TEACHER_COURSE_CREATED', 'COURSE_GROUP', (string)$groupId, [
            'subject' => $subjectName,
            'code'    => $groupCode
        ], $ip);

        return $groupId;
    }

    public static function updateGroup(array $user, int $groupId, array $data, ?string $ip = null): void {
        $group = AcademicModel::getGroupById($groupId);
        if (!$group) {
            throw new Exception('Grupo no encontrado.', 404);
        }

        if ($user['role'] === 'TEACHER' && (int)$group['teacher_id'] !== (int)$user['teacherId']) {
            throw new Exception('No tiene permisos para modificar este grupo.', 403);
        }

        AcademicModel::updateGroup($groupId, $data);
        AuditModel::log((int)$user['id'], 'GROUP_UPDATED', 'COURSE_GROUP', (string)$groupId, $data, $ip);
    }

    public static function deleteGroup(array $user, int $groupId, ?string $ip = null): void {
        $group = AcademicModel::getGroupById($groupId);
        if (!$group) {
            throw new Exception('Grupo no encontrado.', 404);
        }

        if ($user['role'] === 'TEACHER' && (int)$group['teacher_id'] !== (int)$user['teacherId']) {
            throw new Exception('No tiene permisos para eliminar este grupo.', 403);
        }

        AcademicModel::deleteGroup($groupId);
        AuditModel::log((int)$user['id'], 'GROUP_DELETED', 'COURSE_GROUP', (string)$groupId, null, $ip);
    }

    public static function getAvailableCourses(array $user): array {
        $studentId = (int)($user['studentId'] ?? 0);
        return AcademicModel::getAvailableCourses($studentId);
    }

    public static function enrollStudent(array $user, array $data, ?string $ip = null): int {
        $groupId   = (int)($data['group_id'] ?? 0);
        $studentId = isset($data['student_id']) ? (int)$data['student_id'] : (int)($user['studentId'] ?? 0);

        if (!$groupId || !$studentId) {
            throw new Exception('group_id y student_id son requeridos.', 400);
        }

        // Anti-IDOR: Student can only enroll themselves
        if ($user['role'] === 'STUDENT' && (int)$user['studentId'] !== $studentId) {
            throw new Exception('No puede matricular a otros estudiantes.', 403);
        }

        if (AcademicModel::isStudentEnrolled($groupId, $studentId)) {
            throw new Exception('El estudiante ya se encuentra matriculado en este grupo.', 409);
        }

        $id = AcademicModel::enrollStudent($groupId, $studentId);
        AuditModel::log((int)$user['id'], 'STUDENT_ENROLLED', 'ENROLLMENT', (string)$id, [
            'group_id'   => $groupId,
            'student_id' => $studentId
        ], $ip);

        return $id;
    }

    public static function getTeachers(): array {
        return AcademicModel::getTeachers();
    }

    public static function updateTeacher(array $user, int $teacherId, array $data, ?string $ip = null): void {
        $success = AcademicModel::updateTeacher($teacherId, $data);
        if (!$success) {
            throw new Exception('Docente no encontrado.', 404);
        }
        AuditModel::log((int)$user['id'], 'TEACHER_UPDATED', 'TEACHER', (string)$teacherId, $data, $ip);
    }

    public static function getStudents(): array {
        return AcademicModel::getStudents();
    }
}
