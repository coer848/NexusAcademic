<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * Evaluations & Gradebook Data Model (Repository Pattern)
 * 100% Parameterized Queries, Batch O(N) Matrix Aggregation & Anti-IDOR Protection
 */

namespace NexusAcademic\Models;

use PDO;
use Database;

require_once __DIR__ . '/../../config/database.php';

class GradeModel {

    public static function getEvaluationsByGroup(int $groupId): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT * FROM evaluations 
            WHERE group_id = ? AND status = "ACTIVE"
            ORDER BY due_date ASC, id ASC
        ');
        $stmt->execute([$groupId]);
        return $stmt->fetchAll();
    }

    public static function getEvaluationById(int $evalId): ?array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT ev.*, cg.teacher_id, cg.group_code
            FROM evaluations ev
            JOIN course_groups cg ON ev.group_id = cg.id
            WHERE ev.id = ?
            LIMIT 1
        ');
        $stmt->execute([$evalId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function createEvaluation(array $data): int {
        $pdo = Database::getConnection();
        $groupId     = (int)($data['group_id'] ?? $data['groupId'] ?? 0);
        $name        = trim((string)($data['name'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        $type        = strtoupper(trim((string)($data['type'] ?? 'PARCIAL')));
        $weight      = (float)($data['weight'] ?? 0);
        $maxScore    = isset($data['max_score']) ? (float)$data['max_score'] : (isset($data['maxScore']) ? (float)$data['maxScore'] : 5.0);
        $dueDate     = !empty($data['due_date']) ? $data['due_date'] : (!empty($data['dueDate']) ? $data['dueDate'] : null);

        $stmt = $pdo->prepare('
            INSERT INTO evaluations (group_id, name, description, type, weight, max_score, due_date, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, "ACTIVE")
        ');
        $stmt->execute([
            $groupId,
            $name,
            $description ?: null,
            $type,
            $weight,
            $maxScore,
            $dueDate
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function updateEvaluation(int $evalId, array $data): bool {
        $pdo = Database::getConnection();
        $maxScore = isset($data['max_score']) ? (float)$data['max_score'] : (isset($data['maxScore']) ? (float)$data['maxScore'] : null);
        $dueDate  = $data['due_date'] ?? ($data['dueDate'] ?? null);

        $stmt = $pdo->prepare('
            UPDATE evaluations 
            SET name = COALESCE(?, name),
                description = COALESCE(?, description),
                type = COALESCE(?, type),
                weight = COALESCE(?, weight),
                max_score = COALESCE(?, max_score),
                due_date = COALESCE(?, due_date)
            WHERE id = ?
        ');
        return $stmt->execute([
            $data['name'] ?? null,
            $data['description'] ?? null,
            isset($data['type']) ? strtoupper(trim((string)$data['type'])) : null,
            isset($data['weight']) ? (float)$data['weight'] : null,
            $maxScore,
            $dueDate ?: null,
            $evalId
        ]);
    }

    public static function deleteEvaluation(int $evalId): bool {
        return Database::transaction(function (PDO $db) use ($evalId) {
            $db->prepare('DELETE FROM grade_history WHERE evaluation_id = ?')->execute([$evalId]);
            $db->prepare('DELETE FROM grades WHERE evaluation_id = ?')->execute([$evalId]);
            $del = $db->prepare('DELETE FROM evaluations WHERE id = ?');
            return $del->execute([$evalId]);
        });
    }

    public static function getGrade(int $evalId, int $studentId): ?array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM grades WHERE evaluation_id = ? AND student_id = ? LIMIT 1');
        $stmt->execute([$evalId, $studentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function getGradeById(int $gradeId): ?array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT g.*, ev.group_id, cg.teacher_id
            FROM grades g
            JOIN evaluations ev ON g.evaluation_id = ev.id
            JOIN course_groups cg ON ev.group_id = cg.id
            WHERE g.id = ?
            LIMIT 1
        ');
        $stmt->execute([$gradeId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function saveGrade(array $data): int {
        return Database::transaction(function (PDO $db) use ($data) {
            $evalId    = (int)($data['evaluation_id'] ?? $data['evaluationId'] ?? 0);
            $studentId = (int)($data['student_id'] ?? $data['studentId'] ?? 0);
            $score     = (float)$data['score'];
            $feedback  = $data['feedback'] ?? null;
            $changedBy = (int)$data['changed_by_user_id'];
            $reason    = trim((string)($data['change_reason'] ?? $data['changeReason'] ?? 'Calificación inicial registrada'));
            $ip        = $data['ip_address'] ?? '127.0.0.1';

            $chk = $db->prepare('SELECT id, score FROM grades WHERE evaluation_id = ? AND student_id = ?');
            $chk->execute([$evalId, $studentId]);
            $existing = $chk->fetch();

            $oldScore = null;
            $gradeId = null;

            if ($existing) {
                $oldScore = (float)$existing['score'];
                $gradeId  = (int)$existing['id'];

                $upd = $db->prepare('
                    UPDATE grades 
                    SET score = ?, feedback = ?, graded_by_user_id = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ');
                $upd->execute([$score, $feedback, $changedBy, $gradeId]);
            } else {
                $ins = $db->prepare('
                    INSERT INTO grades (evaluation_id, student_id, score, feedback, graded_by_user_id)
                    VALUES (?, ?, ?, ?, ?)
                ');
                $ins->execute([$evalId, $studentId, $score, $feedback, $changedBy]);
                $gradeId = (int)$db->lastInsertId();
            }

            // Record immutable audit history
            $hist = $db->prepare('
                INSERT INTO grade_history (grade_id, evaluation_id, student_id, old_score, new_score, changed_by_user_id, change_reason, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $hist->execute([$gradeId, $evalId, $studentId, $oldScore, $score, $changedBy, $reason ?: 'Actualización de nota', $ip]);

            return $gradeId;
        });
    }

    public static function getGradeHistory(int $gradeId): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT 
                gh.*,
                u.first_name,
                u.last_name,
                u.role,
                u.first_name AS changed_by_first_name,
                u.last_name AS changed_by_last_name,
                u.role AS changed_by_role
            FROM grade_history gh
            JOIN users u ON gh.changed_by_user_id = u.id
            WHERE gh.grade_id = ?
            ORDER BY gh.created_at DESC, gh.id DESC
        ');
        $stmt->execute([$gradeId]);
        return $stmt->fetchAll();
    }

    public static function getStudentCourseGradesReport(int $studentId): array {
        $pdo = Database::getConnection();

        // 1. Fetch active enrolled groups for this student
        $gStmt = $pdo->prepare('
            SELECT 
                cg.id AS group_id, cg.group_code, cg.schedule, cg.classroom,
                s.id AS subject_id, s.name AS subject_name, s.code AS subject_code, s.credits,
                ap.name AS period_name,
                u.first_name AS teacher_first_name, u.last_name AS teacher_last_name
            FROM enrollments e
            JOIN course_groups cg ON e.group_id = cg.id
            JOIN subjects s ON cg.subject_id = s.id
            JOIN academic_periods ap ON cg.period_id = ap.id
            JOIN teachers t ON cg.teacher_id = t.id
            JOIN users u ON t.user_id = u.id
            WHERE e.student_id = ? AND e.status = "ACTIVE"
            ORDER BY s.name ASC
        ');
        $gStmt->execute([$studentId]);
        $groups = $gStmt->fetchAll();

        if (empty($groups)) {
            return [];
        }

        // 2. Fetch institution passing grade bounds
        $minPass = 3.0;
        $maxG = 5.0;
        try {
            $instStmt = $pdo->query('SELECT min_passing_grade, max_grade FROM institutions LIMIT 1');
            $inst = $instStmt ? $instStmt->fetch() : null;
            if ($inst) {
                $minPass = (float)($inst['min_passing_grade'] ?? 3.0);
                $maxG = (float)($inst['max_grade'] ?? 5.0);
            }
        } catch (\Throwable $e) {}

        $serviceFile = dirname(__DIR__) . '/services/GradeService.php';
        if (file_exists($serviceFile)) {
            require_once $serviceFile;
        }

        // 3. Batch-load evaluations and student grades for all enrolled groups (Zero N+1)
        $groupIds = array_map(fn($g) => (int)$g['group_id'], $groups);
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));

        $evStmt = $pdo->prepare("SELECT * FROM evaluations WHERE group_id IN ($placeholders) AND status = 'ACTIVE' ORDER BY due_date ASC, id ASC");
        $evStmt->execute($groupIds);
        $allEvaluations = $evStmt->fetchAll();

        $evalsByGroup = [];
        foreach ($allEvaluations as $ev) {
            $evalsByGroup[(int)$ev['group_id']][] = $ev;
        }

        $grStmt = $pdo->prepare("
            SELECT g.*, ev.group_id
            FROM grades g
            JOIN evaluations ev ON g.evaluation_id = ev.id
            WHERE ev.group_id IN ($placeholders) AND g.student_id = ?
        ");
        $grParams = array_merge($groupIds, [$studentId]);
        $grStmt->execute($grParams);
        $allGrades = $grStmt->fetchAll();

        $gradesByGroup = [];
        foreach ($allGrades as $gr) {
            $gradesByGroup[(int)$gr['group_id']][] = $gr;
        }

        $report = [];
        foreach ($groups as $group) {
            $groupId = (int)$group['group_id'];
            $evaluations = $evalsByGroup[$groupId] ?? [];
            $grades      = $gradesByGroup[$groupId] ?? [];

            $stats = class_exists('GradeService')
                ? \GradeService::calculateStudentAverage($evaluations, $grades, $minPass, $maxG)
                : [
                    'weightedAverage'      => null,
                    'accumulatedScore'     => 0.0,
                    'totalEvaluatedWeight' => 0.0,
                    'remainingWeight'      => 100.0,
                    'projectedNeededGrade' => null,
                    'isPassing'            => false,
                    'status'               => 'NO_EVALUATIONS',
                    'details'              => []
                ];

            $report[] = [
                'course'      => $group,
                'group'       => $group,
                'calculation' => $stats,
                'stats'       => $stats
            ];
        }

        return $report;
    }

    public static function getMyGrades(int $studentId): array {
        return self::getStudentCourseGradesReport($studentId);
    }

    public static function getGroupGradesMatrix(int $groupId): array {
        $pdo = Database::getConnection();

        // 1. Get Group details
        $gStmt = $pdo->prepare('
            SELECT cg.*, s.name AS subject_name, s.code AS subject_code
            FROM course_groups cg
            JOIN subjects s ON cg.subject_id = s.id
            WHERE cg.id = ?
        ');
        $gStmt->execute([$groupId]);
        $group = $gStmt->fetch();

        // 2. Get Evaluations
        $eStmt = $pdo->prepare('SELECT * FROM evaluations WHERE group_id = ? AND status = "ACTIVE" ORDER BY due_date ASC, id ASC');
        $eStmt->execute([$groupId]);
        $evaluations = $eStmt->fetchAll();

        // 3. Get Enrolled Students
        $sStmt = $pdo->prepare('
            SELECT 
                st.id AS student_id, st.student_code, st.career, st.semester,
                u.first_name, u.last_name, u.email
            FROM enrollments e
            JOIN students st ON e.student_id = st.id
            JOIN users u ON st.user_id = u.id
            WHERE e.group_id = ? AND e.status = "ACTIVE"
            ORDER BY u.last_name ASC, u.first_name ASC
        ');
        $sStmt->execute([$groupId]);
        $students = $sStmt->fetchAll();

        // 4. Get all grades for this group in one batch query
        $allGradesStmt = $pdo->prepare('
            SELECT g.id, g.evaluation_id, g.student_id, g.score, g.feedback, g.updated_at AS graded_at
            FROM grades g
            JOIN evaluations ev ON g.evaluation_id = ev.id
            WHERE ev.group_id = ?
        ');
        $allGradesStmt->execute([$groupId]);
        $allGrades = $allGradesStmt->fetchAll();

        $gradeLookup = [];
        foreach ($allGrades as $gr) {
            $gradeLookup[(int)$gr['student_id']][(int)$gr['evaluation_id']] = $gr;
        }

        // Build weighted matrix rows
        $matrixRows = [];
        foreach ($students as $student) {
            $sId = (int)$student['student_id'];
            $studentGrades = [];
            $accumulatedScore = 0.0;
            $accumulatedWeight = 0.0;

            foreach ($evaluations as $ev) {
                $evId = (int)$ev['id'];
                $w = (float)$ev['weight'];
                $gRecord = $gradeLookup[$sId][$evId] ?? null;

                if ($gRecord !== null) {
                    $scoreVal = (float)$gRecord['score'];
                    $studentGrades[] = [
                        'gradeId'      => (int)$gRecord['id'],
                        'evaluationId' => $evId,
                        'name'         => $ev['name'],
                        'weight'       => $w,
                        'score'        => $scoreVal,
                        'feedback'     => $gRecord['feedback'] ?? null,
                        'isGraded'     => true
                    ];
                    $accumulatedScore += ($scoreVal * ($w / 100.0));
                    $accumulatedWeight += $w;
                } else {
                    $studentGrades[] = [
                        'gradeId'      => null,
                        'evaluationId' => $evId,
                        'name'         => $ev['name'],
                        'weight'       => $w,
                        'score'        => null,
                        'feedback'     => null,
                        'isGraded'     => false
                    ];
                }
            }

            $weightedAvg = $accumulatedWeight > 0
                ? round($accumulatedScore / ($accumulatedWeight / 100.0), 2)
                : null;

            $matrixRows[] = [
                'student'              => $student,
                'grades'               => $studentGrades,
                'weightedAverage'      => $weightedAvg,
                'accumulatedScore'     => round($accumulatedScore, 2),
                'totalEvaluatedWeight' => round($accumulatedWeight, 2),
                'isPassing'            => ($weightedAvg !== null && $weightedAvg >= 3.0)
            ];
        }

        return [
            'group'       => $group,
            'evaluations' => $evaluations,
            'students'    => $students,
            'rows'        => $matrixRows,
            'matrix'      => $matrixRows
        ];
    }
}
