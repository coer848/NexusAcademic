<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * Evaluations & Gradebook Controller (Thin HTTP Adapter)
 */

namespace NexusAcademic\Controllers;

use NexusAcademic\Core\Request;
use NexusAcademic\Core\Response;
use NexusAcademic\Models\GradeModel;
use NexusAcademic\Models\AcademicModel;
use NexusAcademic\Models\AuditModel;
use GradeService;
use Exception;

require_once __DIR__ . '/../Core/Request.php';
require_once __DIR__ . '/../Core/Response.php';
require_once __DIR__ . '/../Models/GradeModel.php';
require_once __DIR__ . '/../Models/AcademicModel.php';
require_once __DIR__ . '/../Models/AuditModel.php';
require_once __DIR__ . '/../Services/GradeService.php';

class GradeController {

    public static function createEvaluation($req, ?array $body = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $data = ($req instanceof Request) ? $req->getBody() : (array)$body;
            $ip   = ($req instanceof Request) ? $req->getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

            $groupId = (int)($data['group_id'] ?? 0);
            $name    = trim($data['name'] ?? '');
            $type    = strtoupper(trim($data['type'] ?? ''));
            $weight  = isset($data['weight']) ? (float)$data['weight'] : null;

            if (!$groupId || empty($name) || empty($type) || $weight === null) {
                throw new Exception('group_id, name, type y weight son requeridos.', 400);
            }

            $group = AcademicModel::getGroupById($groupId);
            if (!$group) {
                throw new Exception('Grupo no encontrado.', 404);
            }

            if ($user['role'] === 'TEACHER' && (int)$group['teacher_id'] !== (int)$user['teacherId']) {
                throw new Exception('No es el docente de este grupo.', 403);
            }

            if ($weight <= 0 || $weight > 100) {
                throw new Exception('La ponderación debe ser un porcentaje positivo entre 1% y 100%.', 400);
            }

            $existing = GradeModel::getEvaluationsByGroup($groupId);
            $weightVal = GradeService::validateEvaluationWeights($existing, $weight);
            if (!$weightVal['valid']) {
                throw new Exception("La suma de ponderaciones ({$weightVal['projectedTotal']}%) superaría el 100%. Máximo disponible: {$weightVal['maxAllowed']}%.", 400);
            }

            $evalId = GradeModel::createEvaluation($data);
            AuditModel::log((int)$user['id'], 'EVALUATION_CREATED', 'EVALUATION', (string)$evalId, [
                'group_id' => $groupId,
                'name'     => $name,
                'weight'   => $weight
            ], $ip);

            Response::created(['evaluationId' => $evalId]);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'CREATE_EVALUATION_ERROR', $code);
        }
    }

    public static function updateEvaluation($req, $evalId = null, ?array $body = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $id   = ($req instanceof Request) ? (int)$req->getParam('id') : (int)$evalId;
            $data = ($req instanceof Request) ? $req->getBody() : (array)$body;
            $ip   = ($req instanceof Request) ? $req->getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

            $evalObj = GradeModel::getEvaluationById($id);
            if (!$evalObj) {
                throw new Exception('Evaluación no encontrada.', 404);
            }

            if ($user['role'] === 'TEACHER' && (int)$evalObj['teacher_id'] !== (int)$user['teacherId']) {
                throw new Exception('No tiene permisos para modificar esta evaluación.', 403);
            }

            if (isset($data['weight'])) {
                $newWeight = (float)$data['weight'];
                if ($newWeight <= 0 || $newWeight > 100) {
                    throw new Exception('La ponderación debe estar entre 1% y 100%.', 400);
                }
                $existing = GradeModel::getEvaluationsByGroup((int)$evalObj['group_id']);
                $filtered = array_filter($existing, fn($e) => (int)$e['id'] !== $id);
                $weightVal = GradeService::validateEvaluationWeights($filtered, $newWeight);
                if (!$weightVal['valid']) {
                    throw new Exception("La suma de ponderaciones ({$weightVal['projectedTotal']}%) superaría el 100%.", 400);
                }
            }

            GradeModel::updateEvaluation($id, $data);
            AuditModel::log((int)$user['id'], 'EVALUATION_UPDATED', 'EVALUATION', (string)$id, $data, $ip);

            Response::success(null, 'Evaluación académica actualizada.');
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'UPDATE_EVALUATION_ERROR', $code);
        }
    }

    public static function deleteEvaluation($req, $evalId = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $id   = ($req instanceof Request) ? (int)$req->getParam('id') : (int)$evalId;
            $ip   = ($req instanceof Request) ? $req->getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

            $evalObj = GradeModel::getEvaluationById($id);
            if (!$evalObj) {
                throw new Exception('Evaluación no encontrada.', 404);
            }

            if ($user['role'] === 'TEACHER' && (int)$evalObj['teacher_id'] !== (int)$user['teacherId']) {
                throw new Exception('No tiene permisos para eliminar esta evaluación.', 403);
            }

            GradeModel::deleteEvaluation($id);
            AuditModel::log((int)$user['id'], 'EVALUATION_DELETED', 'EVALUATION', (string)$id, null, $ip);

            Response::success(null, 'Evaluación y calificaciones eliminadas.');
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'DELETE_EVALUATION_ERROR', $code);
        }
    }

    public static function saveGrade($req, ?array $body = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $data = ($req instanceof Request) ? $req->getBody() : (array)$body;
            $ip   = ($req instanceof Request) ? $req->getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

            $evalId    = (int)($data['evaluation_id'] ?? 0);
            $studentId = (int)($data['student_id'] ?? 0);
            $score     = isset($data['score']) ? (float)$data['score'] : null;

            if (!$evalId || !$studentId || $score === null) {
                throw new Exception('evaluation_id, student_id y score son requeridos.', 400);
            }

            $evalObj = GradeModel::getEvaluationById($evalId);
            if (!$evalObj) {
                throw new Exception('Evaluación no encontrada.', 404);
            }

            if ($user['role'] === 'TEACHER' && (int)$evalObj['teacher_id'] !== (int)$user['teacherId']) {
                throw new Exception('No es el docente a cargo de esta calificación.', 403);
            }

            $maxScore = (float)($evalObj['max_score'] ?? 5.0);
            if (!GradeService::isValidScore($score, 0.0, $maxScore)) {
                throw new Exception("La calificación debe estar comprendida entre 0.0 y {$maxScore}.", 400);
            }

            // Anti-IDOR: verify that the student being graded is enrolled in the group
            $isEnrolled = AcademicModel::isStudentEnrolled((int)$evalObj['group_id'], $studentId);
            if (!$isEnrolled) {
                throw new Exception('El estudiante indicado no se encuentra matriculado en este curso.', 400);
            }

            $data['changed_by_user_id'] = (int)$user['id'];
            $data['ip_address'] = $ip;

            $gradeId = GradeModel::saveGrade($data);
            AuditModel::log((int)$user['id'], 'GRADE_SAVED', 'GRADE', (string)$gradeId, [
                'student_id'    => $studentId,
                'evaluation_id' => $evalId,
                'score'         => $score
            ], $ip);

            Response::json(['success' => true, 'gradeId' => $gradeId, 'score' => $score]);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'SAVE_GRADE_ERROR', $code);
        }
    }

    public static function saveBatchGrades($req, ?array $body = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $data = ($req instanceof Request) ? $req->getBody() : (array)$body;
            $ip   = ($req instanceof Request) ? $req->getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

            $evalId = (int)($data['evaluation_id'] ?? 0);
            $grades = $data['grades'] ?? [];

            if (!$evalId || !is_array($grades)) {
                throw new Exception('evaluation_id y arreglo de grades son requeridos.', 400);
            }

            $evalObj = GradeModel::getEvaluationById($evalId);
            if (!$evalObj) {
                throw new Exception('Evaluación no encontrada.', 404);
            }

            if ($user['role'] === 'TEACHER' && (int)$evalObj['teacher_id'] !== (int)$user['teacherId']) {
                throw new Exception('No tiene permisos sobre esta evaluación.', 403);
            }

            $maxScore = (float)($evalObj['max_score'] ?? 5.0);
            $processedCount = 0;

            foreach ($grades as $g) {
                $studentId = (int)($g['student_id'] ?? 0);
                $score = isset($g['score']) ? (float)$g['score'] : null;
                if ($studentId && $score !== null && GradeService::isValidScore($score, 0.0, $maxScore)) {
                    // Anti-IDOR check on batch enrollment
                    if (AcademicModel::isStudentEnrolled((int)$evalObj['group_id'], $studentId)) {
                        GradeModel::saveGrade([
                            'evaluation_id'      => $evalId,
                            'student_id'         => $studentId,
                            'score'              => $score,
                            'feedback'           => $g['feedback'] ?? null,
                            'change_reason'      => $g['change_reason'] ?? 'Calificación masiva de grupo',
                            'changed_by_user_id' => (int)$user['id'],
                            'ip_address'         => $ip
                        ]);
                        $processedCount++;
                    }
                }
            }

            Response::json(['success' => true, 'processedCount' => $processedCount]);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'BATCH_GRADE_ERROR', $code);
        }
    }

    public static function getGroupGradesMatrix($req, $groupId = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $id   = ($req instanceof Request) ? (int)$req->getParam('id') : (int)$groupId;

            $group = AcademicModel::getGroupById($id);
            if (!$group) {
                throw new Exception('Grupo no encontrado.', 404);
            }

            if ($user['role'] === 'TEACHER' && (int)$group['teacher_id'] !== (int)$user['teacherId']) {
                throw new Exception('No tiene permisos para ver las notas de este grupo.', 403);
            }

            $matrix = GradeModel::getGroupGradesMatrix($id);
            Response::json($matrix);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'GRADES_MATRIX_ERROR', $code);
        }
    }

    public static function getMyGrades($req = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $studentId = (int)($user['studentId'] ?? 0);
            if (!$studentId) {
                throw new Exception('Perfil de estudiante no encontrado.', 403);
            }

            $grades = GradeModel::getMyGrades($studentId);
            Response::json($grades);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'MY_GRADES_ERROR', $code);
        }
    }

    public static function getGradeAuditHistory($req, $gradeId = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $id   = ($req instanceof Request) ? (int)$req->getParam('id') : (int)$gradeId;

            $grade = GradeModel::getGradeById($id);
            if (!$grade) {
                throw new Exception('Registro de calificación no encontrado.', 404);
            }

            // Anti-IDOR Authorization
            if ($user['role'] === 'STUDENT' && (int)$grade['student_id'] !== (int)$user['studentId']) {
                throw new Exception('Acceso denegado: No puede consultar el historial de calificaciones de otros estudiantes.', 403);
            }
            if ($user['role'] === 'TEACHER' && (int)$grade['teacher_id'] !== (int)$user['teacherId']) {
                throw new Exception('Acceso denegado: No es el docente asignado a esta asignatura.', 403);
            }

            $history = GradeModel::getGradeHistory($id);
            Response::json($history);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'GRADE_HISTORY_ERROR', $code);
        }
    }
}

// Global class alias for legacy procedural includes
class_alias(GradeController::class, 'GradeController');
