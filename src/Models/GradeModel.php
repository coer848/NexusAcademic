<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * Evaluations & Gradebook Data Model (Repository Pattern)
 * 100% Parameterized Queries & Anti-IDOR Protection
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
        $stmt = $pdo->prepare('
            INSERT INTO evaluations (group_id, name, description, type, weight, max_score, due_date, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, "ACTIVE")
        ');
        $stmt->execute([
            $data['group_id'],
            $data['name'],
            $data['description'] ?: null,
            $data['type'],
            $data['weight'],
            $data['max_score'] ?? 5.0,
            $data['due_date'] ?: null
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function updateEvaluation(int $evalId, array $data): bool {
        $pdo = Database::getConnection();
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
            $data['type'] ?? null,
            $data['weight'] ?? null,
            $data['max_score'] ?? null,
            $data['due_date'] ?? null,
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

    public static function saveGrade(array $data): int {
        return Database::transaction(function (PDO $db) use ($data) {
            $evalId    = (int)$data['evaluation_id'];
            $studentId = (int)$data['student_id'];
            $score     = (float)$data['score'];
            $feedback  = $data['feedback'] ?? null;
            $changedBy = (int)$data['changed_by_user_id'];
            $reason    = $data['change_reason'] ?? 'Calificación inicial registrada';
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
                    SET score = ?, feedback = ?, graded_at = CURRENT_TIMESTAMP, graded_by = ?
                    WHERE id = ?
                ');
                $upd->execute([$score, $feedback, $changedBy, $gradeId]);
            } else {
                $ins = $db->prepare('
                    INSERT INTO grades (evaluation_id, student_id, score, feedback, graded_at, graded_by)
                    VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, ?)
                ');
                $ins->execute([$evalId, $studentId, $score, $feedback, $changedBy]);
                $gradeId = (int)$db->lastInsertId();
            }

            // Record immutable audit history
            $hist = $db->prepare('
                INSERT INTO grade_history (grade_id, evaluation_id, student_id, old_score, new_score, changed_by_user_id, change_reason, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $hist->execute([$gradeId, $evalId, $studentId, $oldScore, $score, $changedBy, $reason, $ip]);

            return $gradeId;
        });
    }

    public static function getGradeHistory(int $gradeId): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT 
                gh.*,
                u.first_name AS changed_by_first_name, u.last_name AS changed_by_last_name, u.role AS changed_by_role
            FROM grade_history gh
            JOIN users u ON gh.changed_by_user_id = u.id
            WHERE gh.grade_id = ?
            ORDER BY gh.created_at DESC
        ');
        $stmt->execute([$gradeId]);
        return $stmt->fetchAll();
    }

    public static function getMyGrades(int $studentId): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT 
                g.*,
                ev.name AS evaluation_name, ev.type AS evaluation_type, ev.weight, ev.max_score, ev.due_date,
                cg.id AS group_id, cg.group_code, cg.classroom,
                s.name AS subject_name, s.code AS subject_code, s.credits,
                u.first_name AS teacher_first_name, u.last_name AS teacher_last_name
            FROM grades g
            JOIN evaluations ev ON g.evaluation_id = ev.id
            JOIN course_groups cg ON ev.group_id = cg.id
            JOIN subjects s ON cg.subject_id = s.id
            JOIN teachers t ON cg.teacher_id = t.id
            JOIN users u ON t.user_id = u.id
            WHERE g.student_id = ?
            ORDER BY cg.id ASC, ev.due_date ASC
        ');
        $stmt->execute([$studentId]);
        return $stmt->fetchAll();
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
        $eStmt = $pdo->prepare('SELECT * FROM evaluations WHERE group_id = ? AND status = "ACTIVE" ORDER BY id ASC');
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

        // 4. Get all grades for this group
        $allGradesStmt = $pdo->prepare('
            SELECT g.evaluation_id, g.student_id, g.score, g.feedback, g.graded_at
            FROM grades g
            JOIN evaluations ev ON g.evaluation_id = ev.id
            WHERE ev.group_id = ?
        ');
        $allGradesStmt->execute([$groupId]);
        $allGrades = $allGradesStmt->fetchAll();

        $gradeLookup = [];
        foreach ($allGrades as $gr) {
            $gradeLookup[$gr['student_id']][$gr['evaluation_id']] = $gr;
        }

        // Build weighted matrix rows
        $matrixRows = [];
        foreach ($students as $student) {
            $studentGrades = [];
            $accumulatedScore = 0.0;
            $accumulatedWeight = 0.0;

            foreach ($evaluations as $ev) {
                $evId = (int)$ev['id'];
                $w = (float)$ev['weight'];
                $gRecord = $gradeLookup[$student['student_id']][$evId] ?? null;

                if ($gRecord !== null) {
                    $scoreVal = (float)$gRecord['score'];
                    $studentGrades[] = [
                        'evaluationId' => $evId,
                        'name'         => $ev['name'],
                        'weight'       => $w,
                        'score'        => $scoreVal,
                        'isGraded'     => true
                    ];
                    $accumulatedScore += ($scoreVal * ($w / 100.0));
                    $accumulatedWeight += $w;
                } else {
                    $studentGrades[] = [
                        'evaluationId' => $evId,
                        'name'         => $ev['name'],
                        'weight'       => $w,
                        'score'        => null,
                        'isGraded'     => false
                    ];
                }
            }

            $weightedAvg = $accumulatedWeight > 0 
                ? round($accumulatedScore / ($accumulatedWeight / 100.0), 2)
                : null;

            $matrixRows[] = [
                'student'         => $student,
                'grades'          => $studentGrades,
                'weightedAverage' => $weightedAvg,
                'isPassing'       => ($weightedAvg !== null && $weightedAvg >= 3.0)
            ];
        }

        return [
            'group'       => $group,
            'evaluations' => $evaluations,
            'students'    => $students,
            'rows'        => $matrixRows
        ];
    }
}
