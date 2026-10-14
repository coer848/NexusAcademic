<?php
/**
 * Evaluations, Grades & Gradebook Controller
 * Manages evaluation criteria, grades, weighted averages, batch submissions, and immutable audit trails
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Services/GradeService.php';

class GradeController {

    /**
     * POST /api/evaluations (TEACHER & ADMIN)
     */
    public static function createEvaluation(array $user, array $body): void {
        $groupId     = (int)($body['group_id'] ?? 0);
        $name        = trim($body['name'] ?? '');
        $description = trim($body['description'] ?? '');
        $type        = strtoupper(trim($body['type'] ?? ''));
        $weight      = isset($body['weight']) ? (float)$body['weight'] : null;
        $maxScore    = isset($body['max_score']) ? (float)$body['max_score'] : 5.0;
        $dueDate     = !empty($body['due_date']) ? trim($body['due_date']) : null;

        if (!$groupId || empty($name) || empty($type) || $weight === null) {
            http_response_code(400);
            echo json_encode(['error' => 'MISSING_FIELDS', 'message' => 'group_id, name, type y weight son requeridos.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $pdo = Database::getConnection();

        $gStmt = $pdo->prepare('SELECT id, teacher_id FROM course_groups WHERE id = ?');
        $gStmt->execute([$groupId]);
        $group = $gStmt->fetch();

        if (!$group) {
            http_response_code(404);
            echo json_encode(['error' => 'GROUP_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($user['role'] === 'TEACHER' && (int)$group['teacher_id'] !== (int)$user['teacherId']) {
            http_response_code(403);
            echo json_encode(['error' => 'NOT_COURSE_TEACHER', 'message' => 'No es el docente de este grupo.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($weight <= 0 || $weight > 100) {
            http_response_code(400);
            echo json_encode(['error' => 'INVALID_WEIGHT', 'message' => 'La ponderación debe ser un porcentaje positivo mayor a 0 y menor o igual a 100.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Validate cumulative weight
        $eStmt = $pdo->prepare('SELECT id, weight FROM evaluations WHERE group_id = ? AND status = "ACTIVE"');
        $eStmt->execute([$groupId]);
        $existing = $eStmt->fetchAll();

        $weightVal = GradeService::validateEvaluationWeights($existing, $weight);
        if (!$weightVal['valid']) {
            http_response_code(400);
            echo json_encode([
                'error'   => 'INVALID_WEIGHT_SUM',
                'message' => "La suma de ponderaciones ({$weightVal['projectedTotal']}%) superaría el 100%. Máximo disponible: {$weightVal['maxAllowed']}%."
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $ins = $pdo->prepare('
            INSERT INTO evaluations (group_id, name, description, type, weight, max_score, due_date, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, "ACTIVE")
        ');
        $ins->execute([$groupId, $name, $description ?: null, $type, $weight, $maxScore, $dueDate]);
        $evalId = (int)$pdo->lastInsertId();

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $aud = $pdo->prepare('
            INSERT INTO audit_logs (user_id, action, resource_type, resource_id, details_json, ip_address)
            VALUES (?, "EVALUATION_CREATED", "EVALUATION", ?, ?, ?)
        ');
        $aud->execute([$user['id'], (string)$evalId, json_encode(['group_id' => $groupId, 'name' => $name, 'weight' => $weight]), $ip]);

        http_response_code(201);
        echo json_encode(['success' => true, 'evaluationId' => $evalId], JSON_UNESCAPED_UNICODE);
    }

    /**
     * PUT /api/evaluations/:id (TEACHER & ADMIN)
     */
    public static function updateEvaluation(array $user, $evalId, array $body): void {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('
            SELECT ev.*, cg.teacher_id 
            FROM evaluations ev
            JOIN course_groups cg ON ev.group_id = cg.id
            WHERE ev.id = ?
        ');
        $stmt->execute([(int)$evalId]);
        $evalObj = $stmt->fetch();

        if (!$evalObj) {
            http_response_code(404);
            echo json_encode(['error' => 'EVALUATION_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($user['role'] === 'TEACHER' && (int)$evalObj['teacher_id'] !== (int)$user['teacherId']) {
            http_response_code(403);
            echo json_encode(['error' => 'NOT_COURSE_TEACHER'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (isset($body['weight'])) {
            $numWeight = (float)$body['weight'];
            if ($numWeight <= 0 || $numWeight > 100) {
                http_response_code(400);
                echo json_encode(['error' => 'INVALID_WEIGHT'], JSON_UNESCAPED_UNICODE);
                return;
            }
            $eStmt = $pdo->prepare('SELECT id, weight FROM evaluations WHERE group_id = ? AND status = "ACTIVE"');
            $eStmt->execute([(int)$evalObj['group_id']]);
            $existing = $eStmt->fetchAll();

            $weightVal = GradeService::validateEvaluationWeights($existing, $numWeight, $evalId);
            if (!$weightVal['valid']) {
                http_response_code(400);
                echo json_encode([
                    'error'   => 'INVALID_WEIGHT_SUM',
                    'message' => "Ponderación excede el 100%. Máximo permitido: {$weightVal['maxAllowed']}%."
                ], JSON_UNESCAPED_UNICODE);
                return;
            }
        }

        $upd = $pdo->prepare('
            UPDATE evaluations
            SET name = COALESCE(?, name),
                description = COALESCE(?, description),
                type = COALESCE(?, type),
                weight = COALESCE(?, weight),
                max_score = COALESCE(?, max_score),
                due_date = COALESCE(?, due_date),
                status = COALESCE(?, status)
            WHERE id = ?
        ');
        $upd->execute([
            !empty($body['name']) ? trim($body['name']) : null,
            isset($body['description']) ? trim($body['description']) : null,
            !empty($body['type']) ? strtoupper(trim($body['type'])) : null,
            isset($body['weight']) ? (float)$body['weight'] : null,
            isset($body['max_score']) ? (float)$body['max_score'] : null,
            isset($body['due_date']) ? trim($body['due_date']) : null,
            !empty($body['status']) ? strtoupper(trim($body['status'])) : null,
            (int)$evalId
        ]);

        echo json_encode(['success' => true, 'message' => 'Evaluación actualizada con éxito.'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * DELETE /api/evaluations/:id (TEACHER & ADMIN)
     */
    public static function deleteEvaluation(array $user, $evalId): void {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('
            SELECT ev.*, cg.teacher_id 
            FROM evaluations ev
            JOIN course_groups cg ON ev.group_id = cg.id
            WHERE ev.id = ?
        ');
        $stmt->execute([(int)$evalId]);
        $evalObj = $stmt->fetch();

        if (!$evalObj) {
            http_response_code(404);
            echo json_encode(['error' => 'EVALUATION_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($user['role'] === 'TEACHER' && (int)$evalObj['teacher_id'] !== (int)$user['teacherId']) {
            http_response_code(403);
            echo json_encode(['error' => 'NOT_COURSE_TEACHER'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $del = $pdo->prepare('DELETE FROM evaluations WHERE id = ?');
        $del->execute([(int)$evalId]);

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $aud = $pdo->prepare('
            INSERT INTO audit_logs (user_id, action, resource_type, resource_id, details_json, ip_address)
            VALUES (?, "EVALUATION_DELETED", "EVALUATION", ?, "{}", ?)
        ');
        $aud->execute([$user['id'], (string)$evalId, $ip]);

        echo json_encode(['success' => true, 'message' => 'Evaluación eliminada correctamente.'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST /api/grades (TEACHER & ADMIN)
     */
    public static function saveGrade(array $user, array $body): void {
        $evalId       = (int)($body['evaluation_id'] ?? 0);
        $studentId    = (int)($body['student_id'] ?? 0);
        $score        = $body['score'] ?? null;
        $feedback     = trim($body['feedback'] ?? '');
        $changeReason = trim($body['change_reason'] ?? '');

        if (!$evalId || !$studentId || $score === null) {
            http_response_code(400);
            echo json_encode(['error' => 'MISSING_FIELDS', 'message' => 'evaluation_id, student_id y score son obligatorios.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $pdo = Database::getConnection();

        // Check evaluation & group ownership
        $stmt = $pdo->prepare('
            SELECT ev.*, cg.teacher_id, cg.id AS group_id 
            FROM evaluations ev
            JOIN course_groups cg ON ev.group_id = cg.id
            WHERE ev.id = ?
        ');
        $stmt->execute([$evalId]);
        $evalObj = $stmt->fetch();

        if (!$evalObj) {
            http_response_code(404);
            echo json_encode(['error' => 'EVALUATION_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($user['role'] === 'TEACHER' && (int)$evalObj['teacher_id'] !== (int)$user['teacherId']) {
            http_response_code(403);
            echo json_encode(['error' => 'NOT_COURSE_TEACHER', 'message' => 'No es el docente de este grupo.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Validate student enrollment
        $eChk = $pdo->prepare('SELECT id FROM enrollments WHERE group_id = ? AND student_id = ? AND status = "ACTIVE"');
        $eChk->execute([(int)$evalObj['group_id'], $studentId]);
        if (!$eChk->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'STUDENT_NOT_ENROLLED', 'message' => 'El estudiante no está matriculado en este grupo.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Validate score bounds
        $inst = $pdo->query('SELECT min_grade, max_grade FROM institutions LIMIT 1')->fetch();
        $minG = $inst ? (float)$inst['min_grade'] : 0.0;
        $maxG = $inst ? (float)$inst['max_grade'] : 5.0;

        $scoreVal = GradeService::validateScoreBounds($score, $minG, $maxG);
        if (!$scoreVal['valid']) {
            http_response_code(400);
            echo json_encode(['error' => 'SCORE_OUT_OF_BOUNDS', 'message' => $scoreVal['reason']], JSON_UNESCAPED_UNICODE);
            return;
        }

        $finalScore = $scoreVal['score'];

        try {
            $result = Database::transaction(function (PDO $db) use ($evalId, $studentId, $finalScore, $feedback, $changeReason, $user) {
                $gStmt = $db->prepare('SELECT id, score, feedback FROM grades WHERE evaluation_id = ? AND student_id = ?');
                $gStmt->execute([$evalId, $studentId]);
                $existing = $gStmt->fetch();

                $oldScore = null;
                $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

                if ($existing) {
                    $gradeId  = (int)$existing['id'];
                    $oldScore = (float)$existing['score'];

                    if ($oldScore != $finalScore && empty($changeReason)) {
                        throw new RuntimeException('REASON_REQUIRED: Debe proporcionar el motivo de la modificación de la nota.');
                    }

                    $upd = $db->prepare('
                        UPDATE grades 
                        SET score = ?, feedback = ?, graded_by_user_id = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ');
                    $upd->execute([$finalScore, $feedback ?: null, $user['id'], $gradeId]);
                } else {
                    $ins = $db->prepare('
                        INSERT INTO grades (evaluation_id, student_id, score, feedback, graded_by_user_id)
                        VALUES (?, ?, ?, ?, ?)
                    ');
                    $ins->execute([$evalId, $studentId, $finalScore, $feedback ?: null, $user['id']]);
                    $gradeId = (int)$db->lastInsertId();
                }

                // Insert into immutable grade_history
                $gh = $db->prepare('
                    INSERT INTO grade_history (
                        grade_id, evaluation_id, student_id, old_score, new_score, change_reason, changed_by_user_id, ip_address
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $gh->execute([
                    $gradeId,
                    $evalId,
                    $studentId,
                    $oldScore,
                    $finalScore,
                    !empty($changeReason) ? $changeReason : ($oldScore === null ? 'Calificación inicial' : 'Ajuste de nota'),
                    $user['id'],
                    $ip
                ]);

                return ['gradeId' => $gradeId, 'oldScore' => $oldScore, 'newScore' => $finalScore];
            });

            echo json_encode([
                'success' => true,
                'message' => 'Calificación guardada y auditada exitosamente.',
                'grade'   => $result
            ], JSON_UNESCAPED_UNICODE);
        } catch (RuntimeException $e) {
            http_response_code(400);
            echo json_encode(['error' => 'REASON_REQUIRED', 'message' => str_replace('REASON_REQUIRED: ', '', $e->getMessage())], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'SERVER_ERROR', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/grades/batch (TEACHER & ADMIN)
     */
    public static function saveBatchGrades(array $user, array $body): void {
        $evalId        = (int)($body['evaluation_id'] ?? 0);
        $gradesList    = $body['gradesList'] ?? [];
        $defaultReason = trim($body['default_reason'] ?? 'Calificación masiva en lote');

        if (!$evalId || !is_array($gradesList)) {
            http_response_code(400);
            echo json_encode(['error' => 'INVALID_PAYLOAD', 'message' => 'evaluation_id y lista de notas requeridas.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('
            SELECT ev.*, cg.teacher_id, cg.id AS group_id 
            FROM evaluations ev
            JOIN course_groups cg ON ev.group_id = cg.id
            WHERE ev.id = ?
        ');
        $stmt->execute([$evalId]);
        $evalObj = $stmt->fetch();

        if (!$evalObj) {
            http_response_code(404);
            echo json_encode(['error' => 'EVALUATION_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($user['role'] === 'TEACHER' && (int)$evalObj['teacher_id'] !== (int)$user['teacherId']) {
            http_response_code(403);
            echo json_encode(['error' => 'NOT_COURSE_TEACHER'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $inst = $pdo->query('SELECT min_grade, max_grade FROM institutions LIMIT 1')->fetch();
        $minG = $inst ? (float)$inst['min_grade'] : 0.0;
        $maxG = $inst ? (float)$inst['max_grade'] : 5.0;

        try {
            Database::transaction(function (PDO $db) use ($evalId, $gradesList, $defaultReason, $user, $minG, $maxG) {
                $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

                foreach ($gradesList as $item) {
                    $studentId    = (int)($item['student_id'] ?? 0);
                    $score        = $item['score'] ?? null;
                    $feedback     = trim($item['feedback'] ?? '');
                    $changeReason = trim($item['change_reason'] ?? '');

                    if ($score === null || $score === '') {
                        continue;
                    }

                    $scoreVal = GradeService::validateScoreBounds($score, $minG, $maxG);
                    if (!$scoreVal['valid']) {
                        throw new RuntimeException("Estudiante ID {$studentId}: " . $scoreVal['reason']);
                    }

                    $finalScore = $scoreVal['score'];

                    $gStmt = $db->prepare('SELECT id, score FROM grades WHERE evaluation_id = ? AND student_id = ?');
                    $gStmt->execute([$evalId, $studentId]);
                    $existing = $gStmt->fetch();

                    if ($existing) {
                        $gradeId  = (int)$existing['id'];
                        $oldScore = (float)$existing['score'];

                        if ($oldScore != $finalScore) {
                            $upd = $db->prepare('
                                UPDATE grades 
                                SET score = ?, feedback = ?, graded_by_user_id = ?, updated_at = CURRENT_TIMESTAMP
                                WHERE id = ?
                            ');
                            $upd->execute([$finalScore, $feedback ?: null, $user['id'], $gradeId]);

                            $gh = $db->prepare('
                                INSERT INTO grade_history (
                                    grade_id, evaluation_id, student_id, old_score, new_score, change_reason, changed_by_user_id, ip_address
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                            ');
                            $gh->execute([
                                $gradeId,
                                $evalId,
                                $studentId,
                                $oldScore,
                                $finalScore,
                                !empty($changeReason) ? $changeReason : $defaultReason,
                                $user['id'],
                                $ip
                            ]);
                        }
                    } else {
                        $ins = $db->prepare('
                            INSERT INTO grades (evaluation_id, student_id, score, feedback, graded_by_user_id)
                            VALUES (?, ?, ?, ?, ?)
                        ');
                        $ins->execute([$evalId, $studentId, $finalScore, $feedback ?: null, $user['id']]);
                        $gradeId = (int)$db->lastInsertId();

                        $gh = $db->prepare('
                            INSERT INTO grade_history (
                                grade_id, evaluation_id, student_id, old_score, new_score, change_reason, changed_by_user_id, ip_address
                            ) VALUES (?, ?, ?, NULL, ?, ?, ?, ?)
                        ');
                        $gh->execute([
                            $gradeId,
                            $evalId,
                            $studentId,
                            $finalScore,
                            !empty($changeReason) ? $changeReason : $defaultReason,
                            $user['id'],
                            $ip
                        ]);
                    }
                }
            });

            echo json_encode(['success' => true, 'message' => 'Calificaciones registradas correctamente.'], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['error' => 'BATCH_ERROR', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * GET /api/grades/group/:groupId/matrix (TEACHER & ADMIN)
     */
    public static function getGroupGradesMatrix(array $user, $groupId): void {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('
            SELECT cg.*, s.name AS subject_name, s.code AS subject_code 
            FROM course_groups cg
            JOIN subjects s ON cg.subject_id = s.id
            WHERE cg.id = ?
        ');
        $stmt->execute([(int)$groupId]);
        $group = $stmt->fetch();

        if (!$group) {
            http_response_code(404);
            echo json_encode(['error' => 'GROUP_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($user['role'] === 'TEACHER' && (int)$group['teacher_id'] !== (int)$user['teacherId']) {
            http_response_code(403);
            echo json_encode(['error' => 'NOT_COURSE_TEACHER'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $evStmt = $pdo->prepare('SELECT * FROM evaluations WHERE group_id = ? ORDER BY due_date ASC, id ASC');
        $evStmt->execute([(int)$groupId]);
        $evaluations = $evStmt->fetchAll();

        $stStmt = $pdo->prepare('
            SELECT 
                st.id AS student_id, st.student_code, st.career,
                u.id AS user_id, u.first_name, u.last_name, u.email
            FROM enrollments e
            JOIN students st ON e.student_id = st.id
            JOIN users u ON st.user_id = u.id
            WHERE e.group_id = ? AND e.status = "ACTIVE"
            ORDER BY u.last_name ASC, u.first_name ASC
        ');
        $stStmt->execute([(int)$groupId]);
        $enrolledStudents = $stStmt->fetchAll();

        $grStmt = $pdo->prepare('
            SELECT g.*, ev.group_id
            FROM grades g
            JOIN evaluations ev ON g.evaluation_id = ev.id
            WHERE ev.group_id = ?
        ');
        $grStmt->execute([(int)$groupId]);
        $allGrades = $grStmt->fetchAll();

        $inst = $pdo->query('SELECT min_passing_grade, max_grade FROM institutions LIMIT 1')->fetch();
        $minPass = $inst ? (float)$inst['min_passing_grade'] : 3.0;
        $maxG    = $inst ? (float)$inst['max_grade'] : 5.0;

        $matrix = [];
        foreach ($enrolledStudents as $student) {
            $stId = (int)$student['student_id'];
            $stGrades = array_values(array_filter($allGrades, fn($g) => (int)$g['student_id'] === $stId));
            $stats = GradeService::calculateStudentAverage($evaluations, $stGrades, $minPass, $maxG);

            $matrix[] = [
                'student'              => $student,
                'grades'               => $stats['details'],
                'weightedAverage'      => $stats['weightedAverage'],
                'accumulatedScore'     => $stats['accumulatedScore'],
                'totalEvaluatedWeight' => $stats['totalEvaluatedWeight'],
                'remainingWeight'      => $stats['remainingWeight'],
                'projectedNeededGrade' => $stats['projectedNeededGrade'],
                'isPassing'            => $stats['isPassing']
            ];
        }

        echo json_encode([
            'group'            => $group,
            'evaluations'      => $evaluations,
            'matrix'           => $matrix,
            'totalStudents'    => count($enrolledStudents),
            'totalEvaluations' => count($evaluations)
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /api/grades/my-grades (STUDENT ONLY - Anti-IDOR Protected)
     */
    public static function getMyGrades(array $user): void {
        $studentId = $user['studentId'];
        if (!$studentId) {
            http_response_code(403);
            echo json_encode(['error' => 'STUDENT_ROLE_REQUIRED'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $pdo = Database::getConnection();

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

        $inst = $pdo->query('SELECT min_passing_grade, max_grade FROM institutions LIMIT 1')->fetch();
        $minPass = $inst ? (float)$inst['min_passing_grade'] : 3.0;
        $maxG    = $inst ? (float)$inst['max_grade'] : 5.0;

        $report = [];
        foreach ($groups as $grp) {
            $grpId = (int)$grp['group_id'];

            $evStmt = $pdo->prepare('SELECT * FROM evaluations WHERE group_id = ? ORDER BY due_date ASC, id ASC');
            $evStmt->execute([$grpId]);
            $evaluations = $evStmt->fetchAll();

            $grStmt = $pdo->prepare('
                SELECT g.* 
                FROM grades g
                JOIN evaluations ev ON g.evaluation_id = ev.id
                WHERE ev.group_id = ? AND g.student_id = ?
            ');
            $grStmt->execute([$grpId, $studentId]);
            $grades = $grStmt->fetchAll();

            $stats = GradeService::calculateStudentAverage($evaluations, $grades, $minPass, $maxG);

            $report[] = [
                'group' => $grp,
                'stats' => $stats
            ];
        }

        echo json_encode(['studentId' => $studentId, 'courses' => $report], JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /api/grades/:gradeId/history (TEACHER, ADMIN, STUDENT)
     */
    public static function getGradeAuditHistory(array $user, $gradeId): void {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('
            SELECT g.*, ev.name AS evaluation_name, ev.weight, ev.group_id, cg.teacher_id
            FROM grades g
            JOIN evaluations ev ON g.evaluation_id = ev.id
            JOIN course_groups cg ON ev.group_id = cg.id
            WHERE g.id = ?
        ');
        $stmt->execute([(int)$gradeId]);
        $grade = $stmt->fetch();

        if (!$grade) {
            http_response_code(404);
            echo json_encode(['error' => 'GRADE_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($user['role'] === 'STUDENT' && (int)$grade['student_id'] !== (int)$user['studentId']) {
            http_response_code(403);
            echo json_encode(['error' => 'ACCESS_DENIED', 'message' => 'No puede ver el historial de otro estudiante.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($user['role'] === 'TEACHER' && (int)$grade['teacher_id'] !== (int)$user['teacherId']) {
            http_response_code(403);
            echo json_encode(['error' => 'ACCESS_DENIED'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $hStmt = $pdo->prepare('
            SELECT 
                gh.*,
                u.first_name AS changed_by_first_name, u.last_name AS changed_by_last_name, u.role AS changed_by_role
            FROM grade_history gh
            JOIN users u ON gh.changed_by_user_id = u.id
            WHERE gh.grade_id = ?
            ORDER BY gh.created_at DESC, gh.id DESC
        ');
        $hStmt->execute([(int)$gradeId]);
        $history = $hStmt->fetchAll();

        echo json_encode(['grade' => $grade, 'history' => $history], JSON_UNESCAPED_UNICODE);
    }
}
