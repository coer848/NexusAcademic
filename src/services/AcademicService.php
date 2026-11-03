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
        $name      = trim((string)($data['name'] ?? ''));
        $startDate = trim((string)($data['start_date'] ?? $data['startDate'] ?? ''));
        $endDate   = trim((string)($data['end_date'] ?? $data['endDate'] ?? ''));
        $isActive  = (!empty($data['is_active']) || !empty($data['isActive'])) ? 1 : 0;

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
        $code        = strtoupper(trim((string)($data['code'] ?? '')));
        $name        = trim((string)($data['name'] ?? ''));
        $credits     = isset($data['credits']) ? (int)$data['credits'] : 3;
        $description = trim((string)($data['description'] ?? ''));

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
        if (($user['role'] ?? '') === 'TEACHER' && (int)$group['teacher_id'] !== (int)($user['teacherId'] ?? 0)) {
            throw new Exception('No tiene permisos para acceder a este grupo.', 403);
        }
        if (($user['role'] ?? '') === 'STUDENT') {
            $studentId = (int)($user['studentId'] ?? 0);
            if (!$studentId || !AcademicModel::isStudentEnrolled($groupId, $studentId)) {
                throw new Exception('Acceso denegado: Usted no se encuentra matriculado en este grupo.', 403);
            }
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

        // Load latest class session (ordered by started_at DESC)
        $sessStmt = $pdo->prepare('
            SELECT * FROM class_sessions 
            WHERE group_id = ? 
            ORDER BY started_at DESC, id DESC 
            LIMIT 1
        ');
        $sessStmt->execute([$groupId]);
        $latestSession = $sessStmt->fetch() ?: null;

        return [
            'group'         => $group,
            'students'      => $enrolledStudents,
            'evaluations'   => $evaluations,
            'activeSession' => ($latestSession && $latestSession['status'] === 'ACTIVE') ? $latestSession : null,
            'latestSession' => $latestSession
        ];
    }

    public static function createGroup(array $user, array $data, ?string $ip = null): int {
        $subjectId = (int)($data['subject_id'] ?? $data['subjectId'] ?? 0);
        $periodId  = (int)($data['period_id'] ?? $data['periodId'] ?? 0);
        $teacherId = (int)($data['teacher_id'] ?? $data['teacherId'] ?? 0);
        $groupCode = strtoupper(trim((string)($data['group_code'] ?? $data['groupCode'] ?? '')));

        if (!$subjectId || !$periodId || empty($groupCode)) {
            throw new Exception('Asignatura, periodo académico y código de grupo son obligatorios.', 400);
        }

        if (!$teacherId) {
            $pdo = Database::getConnection();
            $tRow = $pdo->query('SELECT id FROM teachers ORDER BY id ASC LIMIT 1')->fetch();
            $teacherId = $tRow ? (int)$tRow['id'] : 1;
        }

        $data['subject_id'] = $subjectId;
        $data['period_id']  = $periodId;
        $data['teacher_id'] = $teacherId;
        $data['group_code'] = $groupCode;

        try {
            $id = AcademicModel::createGroup($data);
            AuditModel::log((int)$user['id'], 'GROUP_CREATED', 'COURSE_GROUP', (string)$id, ['code' => $groupCode], $ip);
            return $id;
        } catch (\PDOException $e) {
            if ($e->getCode() == 23000) {
                throw new Exception("Ya existe un grupo registrado con el código '{$groupCode}' en este periodo.", 409);
            }
            throw $e;
        }
    }

    public static function createTeacherCourse(array $user, array $data, ?string $ip = null): int {
        $teacherId = (int)($user['teacherId'] ?? $data['teacher_id'] ?? $data['teacherId'] ?? 0);
        $isAdmin = in_array($user['role'] ?? '', ['ADMIN', 'SUPERADMIN'], true);

        if (!$teacherId && !$isAdmin) {
            throw new Exception('No se encontró el perfil docente asociado al usuario.', 403);
        }

        $pdo = Database::getConnection();
        if (!$teacherId) {
            $tRow = $pdo->query('SELECT id FROM teachers ORDER BY id ASC LIMIT 1')->fetch();
            $teacherId = $tRow ? (int)$tRow['id'] : 1;
        }

        $subjectName    = trim((string)($data['subject_name'] ?? $data['subjectName'] ?? ''));
        $subjectCodeReq = strtoupper(trim((string)($data['subject_code'] ?? $data['subjectCode'] ?? '')));
        $credits        = isset($data['credits']) ? (int)$data['credits'] : 3;
        $groupCode      = strtoupper(trim((string)($data['group_code'] ?? $data['groupCode'] ?? '')));
        $classroom      = trim((string)($data['classroom'] ?? 'Aula Virtual / Presencial'));
        $schedule       = trim((string)($data['schedule'] ?? 'Horario Flexible'));
        $geoRadius      = isset($data['geo_radius_meters']) ? (float)$data['geo_radius_meters'] : (isset($data['geoRadiusMeters']) ? (float)$data['geoRadiusMeters'] : 100.0);

        if (empty($subjectName) || empty($groupCode)) {
            throw new Exception('El nombre de la asignatura y el código del grupo son obligatorios.', 400);
        }

        // 1. Find or create subject
        $sFind = $pdo->prepare('SELECT id FROM subjects WHERE name = ? OR (code = ? AND ? != "") LIMIT 1');
        $sFind->execute([$subjectName, $subjectCodeReq, $subjectCodeReq]);
        $subj = $sFind->fetch();

        if ($subj) {
            $subjectId = (int)$subj['id'];
        } else {
            $codeGen = $subjectCodeReq ?: (strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $subjectName), 0, 4)) . '-' . rand(100, 999));
            $sIns = $pdo->prepare('INSERT INTO subjects (code, name, credits) VALUES (?, ?, ?)');
            $sIns->execute([$codeGen, $subjectName, max(1, $credits)]);
            $subjectId = (int)$pdo->lastInsertId();
        }

        // 2. Find active period
        $pFind = $pdo->query('SELECT id FROM academic_periods WHERE is_active = 1 ORDER BY id DESC LIMIT 1');
        $period = $pFind->fetch();
        if (!$period) {
            $pIns = $pdo->prepare('INSERT INTO academic_periods (name, start_date, end_date, is_active) VALUES (?, CURRENT_DATE, DATE_ADD(CURRENT_DATE, INTERVAL 6 MONTH), 1)');
            $pIns->execute([date('Y') . '-1 (Semestre Activo)']);
            $periodId = (int)$pdo->lastInsertId();
        } else {
            $periodId = (int)$period['id'];
        }

        // 3. Create group
        $groupId = AcademicModel::createGroup([
            'subject_id'        => $subjectId,
            'period_id'         => $periodId,
            'teacher_id'        => $teacherId,
            'group_code'        => $groupCode,
            'classroom'         => $classroom,
            'schedule'          => $schedule,
            'geo_lat'           => 4.6097,
            'geo_lng'           => -74.0817,
            'geo_radius_meters' => $geoRadius
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

        if (($user['role'] ?? '') === 'TEACHER' && (int)$group['teacher_id'] !== (int)($user['teacherId'] ?? 0)) {
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

        if (($user['role'] ?? '') === 'TEACHER' && (int)$group['teacher_id'] !== (int)($user['teacherId'] ?? 0)) {
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
        $groupId   = (int)($data['group_id'] ?? $data['groupId'] ?? 0);
        $studentId = isset($data['student_id']) ? (int)$data['student_id'] : (isset($data['studentId']) ? (int)$data['studentId'] : (int)($user['studentId'] ?? 0));

        if (!$groupId || !$studentId) {
            throw new Exception('group_id y student_id son requeridos.', 400);
        }

        // Anti-IDOR: Student can only enroll themselves
        if (($user['role'] ?? '') === 'STUDENT' && (int)($user['studentId'] ?? 0) !== $studentId) {
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
