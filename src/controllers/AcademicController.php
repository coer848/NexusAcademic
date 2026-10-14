<?php
/**
 * Academic Structure Controller
 * Manages institutions, periods, subjects, course groups, enrollments, teachers, and students
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Services/CryptoService.php';

class AcademicController {

    /**
     * GET /api/academic/institution
     */
    public static function getInstitution(): void {
        $pdo = Database::getConnection();
        $stmt = $pdo->query('SELECT * FROM institutions LIMIT 1');
        $row = $stmt->fetch() ?: null;
        echo json_encode($row, JSON_UNESCAPED_UNICODE);
    }

    /**
     * PUT /api/academic/institution (ADMIN ONLY)
     */
    public static function updateInstitution(array $user, array $body): void {
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
        $stmt->execute([
            $body['name'] ?? null,
            $body['code'] ?? null,
            $body['grading_scale'] ?? null,
            isset($body['min_grade']) ? (float)$body['min_grade'] : null,
            isset($body['max_grade']) ? (float)$body['max_grade'] : null,
            isset($body['min_passing_grade']) ? (float)$body['min_passing_grade'] : null
        ]);

        echo json_encode(['success' => true, 'message' => 'Configuración institucional actualizada.'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /api/academic/periods
     */
    public static function getPeriods(): void {
        $pdo = Database::getConnection();
        $stmt = $pdo->query('SELECT * FROM academic_periods ORDER BY id DESC');
        echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST /api/academic/periods (ADMIN ONLY)
     */
    public static function createPeriod(array $user, array $body): void {
        $name      = trim($body['name'] ?? '');
        $startDate = trim($body['start_date'] ?? '');
        $endDate   = trim($body['end_date'] ?? '');
        $isActive  = !empty($body['is_active']) ? 1 : 0;

        if (empty($name) || empty($startDate) || empty($endDate)) {
            http_response_code(400);
            echo json_encode(['error' => 'MISSING_FIELDS', 'message' => 'Nombre y fechas son obligatorios.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            INSERT INTO academic_periods (name, start_date, end_date, is_active)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$name, $startDate, $endDate, $isActive]);

        http_response_code(201);
        echo json_encode(['success' => true, 'periodId' => (int)$pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /api/academic/subjects
     */
    public static function getSubjects(): void {
        $pdo = Database::getConnection();
        $stmt = $pdo->query('SELECT * FROM subjects ORDER BY code ASC');
        echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST /api/academic/subjects (ADMIN ONLY)
     */
    public static function createSubject(array $user, array $body): void {
        $code        = strtoupper(trim($body['code'] ?? ''));
        $name        = trim($body['name'] ?? '');
        $credits     = isset($body['credits']) ? (int)$body['credits'] : 3;
        $description = trim($body['description'] ?? '');

        if (empty($code) || empty($name)) {
            http_response_code(400);
            echo json_encode(['error' => 'MISSING_FIELDS', 'message' => 'Código y nombre son obligatorios.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $pdo = Database::getConnection();
        try {
            $stmt = $pdo->prepare('
                INSERT INTO subjects (code, name, credits, description)
                VALUES (?, ?, ?, ?)
            ');
            $stmt->execute([$code, $name, $credits, $description ?: null]);

            http_response_code(201);
            echo json_encode(['success' => true, 'subjectId' => (int)$pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                http_response_code(409);
                echo json_encode(['error' => 'SUBJECT_EXISTS', 'message' => 'El código de asignatura ya existe.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            throw $e;
        }
    }

    /**
     * GET /api/academic/groups
     */
    public static function getGroups(array $user): void {
        $pdo = Database::getConnection();
        $role = $user['role'];
        $teacherId = $user['teacherId'];
        $studentId = $user['studentId'];

        $sql = '
            SELECT 
                cg.id, cg.group_code, cg.schedule, cg.classroom, cg.geo_lat, cg.geo_lng, cg.geo_radius_meters, cg.created_at,
                s.id AS subject_id, s.code AS subject_code, s.name AS subject_name, s.credits AS subject_credits,
                ap.id AS period_id, ap.name AS period_name, ap.is_active AS period_is_active,
                t.id AS teacher_id, t.teacher_code,
                u.first_name AS teacher_first_name, u.last_name AS teacher_last_name, u.email AS teacher_email,
                (SELECT COUNT(*) FROM enrollments e WHERE e.group_id = cg.id AND e.status = "ACTIVE") AS enrolled_students_count,
                (SELECT COUNT(*) FROM evaluations ev WHERE ev.group_id = cg.id) AS evaluations_count,
                (SELECT COUNT(*) FROM class_sessions cs WHERE cs.group_id = cg.id) AS total_sessions_count,
                (SELECT COUNT(*) FROM class_sessions cs WHERE cs.group_id = cg.id AND cs.status = "ACTIVE") AS active_sessions_count
            FROM course_groups cg
            JOIN subjects s ON cg.subject_id = s.id
            JOIN academic_periods ap ON cg.period_id = ap.id
            JOIN teachers t ON cg.teacher_id = t.id
            JOIN users u ON t.user_id = u.id
        ';

        $params = [];
        if ($role === 'TEACHER') {
            $sql .= ' WHERE cg.teacher_id = ?';
            $params[] = $teacherId;
        } elseif ($role === 'STUDENT') {
            $sql .= '
                JOIN enrollments enr ON enr.group_id = cg.id
                WHERE enr.student_id = ? AND enr.status = "ACTIVE"
            ';
            $params[] = $studentId;
        }

        $sql .= ' ORDER BY ap.id DESC, s.name ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /api/academic/groups/:id
     */
    public static function getGroupDetails(array $user, $groupId): void {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('
            SELECT 
                cg.id, cg.group_code, cg.schedule, cg.classroom, cg.geo_lat, cg.geo_lng, cg.geo_radius_meters,
                s.id AS subject_id, s.code AS subject_code, s.name AS subject_name, s.credits AS subject_credits, s.description AS subject_description,
                ap.id AS period_id, ap.name AS period_name,
                t.id AS teacher_id, t.teacher_code, t.department AS teacher_dept,
                u.first_name AS teacher_first_name, u.last_name AS teacher_last_name, u.email AS teacher_email
            FROM course_groups cg
            JOIN subjects s ON cg.subject_id = s.id
            JOIN academic_periods ap ON cg.period_id = ap.id
            JOIN teachers t ON cg.teacher_id = t.id
            JOIN users u ON t.user_id = u.id
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
            echo json_encode(['error' => 'NOT_ASSIGNED_TEACHER', 'message' => 'No tiene permiso para ver este grupo.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($user['role'] === 'STUDENT') {
            $enr = $pdo->prepare('SELECT id FROM enrollments WHERE group_id = ? AND student_id = ? AND status = "ACTIVE"');
            $enr->execute([(int)$groupId, $user['studentId']]);
            if (!$enr->fetch()) {
                http_response_code(403);
                echo json_encode(['error' => 'NOT_ENROLLED', 'message' => 'No está matriculado en este grupo.'], JSON_UNESCAPED_UNICODE);
                return;
            }
        }

        $students = [];
        if ($user['role'] !== 'STUDENT') {
            $stStmt = $pdo->prepare('
                SELECT 
                    st.id AS student_id, st.student_code, st.career, st.semester,
                    u.id AS user_id, u.first_name, u.last_name, u.email,
                    e.enrolled_at, e.status AS enrollment_status
                FROM enrollments e
                JOIN students st ON e.student_id = st.id
                JOIN users u ON st.user_id = u.id
                WHERE e.group_id = ? AND e.status = "ACTIVE"
                ORDER BY u.last_name ASC, u.first_name ASC
            ');
            $stStmt->execute([(int)$groupId]);
            $students = $stStmt->fetchAll();
        }

        $evStmt = $pdo->prepare('SELECT * FROM evaluations WHERE group_id = ? ORDER BY due_date ASC, id ASC');
        $evStmt->execute([(int)$groupId]);
        $evaluations = $evStmt->fetchAll();

        $sessStmt = $pdo->prepare('
            SELECT id, topic, started_at, geo_lat, geo_lng, geo_radius_meters
            FROM class_sessions 
            WHERE group_id = ? AND status = "ACTIVE"
            ORDER BY id DESC LIMIT 1
        ');
        $sessStmt->execute([(int)$groupId]);
        $activeSession = $sessStmt->fetch() ?: null;

        echo json_encode([
            'group'         => $group,
            'students'      => $students,
            'evaluations'   => $evaluations,
            'activeSession' => $activeSession
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST /api/academic/groups (ADMIN ONLY)
     */
    public static function createGroup(array $user, array $body): void {
        $subjectId = (int)($body['subject_id'] ?? 0);
        $teacherId = (int)($body['teacher_id'] ?? 0);
        $periodId  = (int)($body['period_id'] ?? 0);
        $groupCode = strtoupper(trim($body['group_code'] ?? ''));
        $schedule  = trim($body['schedule'] ?? 'Por definir');
        $classroom = trim($body['classroom'] ?? 'Por definir');
        $geoLat    = isset($body['geo_lat']) ? (float)$body['geo_lat'] : 4.6097;
        $geoLng    = isset($body['geo_lng']) ? (float)$body['geo_lng'] : -74.0817;
        $geoRadius = isset($body['geo_radius_meters']) ? (float)$body['geo_radius_meters'] : 100.0;

        if (!$subjectId || !$teacherId || !$periodId || empty($groupCode)) {
            http_response_code(400);
            echo json_encode(['error' => 'MISSING_FIELDS', 'message' => 'Asignatura, profesor, período y código son requeridos.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $pdo = Database::getConnection();
        try {
            $stmt = $pdo->prepare('
                INSERT INTO course_groups (subject_id, teacher_id, period_id, group_code, schedule, classroom, geo_lat, geo_lng, geo_radius_meters)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$subjectId, $teacherId, $periodId, $groupCode, $schedule, $classroom, $geoLat, $geoLng, $geoRadius]);
            $groupId = (int)$pdo->lastInsertId();

            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $aud = $pdo->prepare('
                INSERT INTO audit_logs (user_id, action, resource_type, resource_id, details_json, ip_address)
                VALUES (?, "GROUP_CREATED", "COURSE_GROUP", ?, ?, ?)
            ');
            $aud->execute([$user['id'], (string)$groupId, json_encode(['group_code' => $groupCode, 'teacher_id' => $teacherId, 'subject_id' => $subjectId]), $ip]);

            http_response_code(201);
            echo json_encode(['success' => true, 'groupId' => $groupId], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                http_response_code(409);
                echo json_encode(['error' => 'GROUP_EXISTS', 'message' => 'Ya existe este código de grupo para la asignatura y período.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            throw $e;
        }
    }

    /**
     * POST /api/academic/teacher-course (TEACHER & ADMIN)
     */
    public static function createTeacherCourse(array $user, array $body): void {
        $subjectName = trim($body['subject_name'] ?? '');
        $subjectCode = trim($body['subject_code'] ?? '');
        $groupCode   = strtoupper(trim($body['group_code'] ?? 'G01'));
        $schedule    = trim($body['schedule'] ?? 'Horario por definir');
        $classroom   = trim($body['classroom'] ?? 'Aula por asignar');
        $credits     = isset($body['credits']) ? (int)$body['credits'] : 3;
        $description = trim($body['description'] ?? '');

        if (empty($subjectName)) {
            http_response_code(400);
            echo json_encode(['error' => 'MISSING_FIELDS', 'message' => 'El nombre de la asignatura es obligatorio.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $teacherId = ($user['role'] === 'TEACHER') ? $user['teacherId'] : (int)($body['teacher_id'] ?? 0);
        if (!$teacherId) {
            http_response_code(400);
            echo json_encode(['error' => 'TEACHER_REQUIRED', 'message' => 'No se encontró el ID del docente.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $pdo = Database::getConnection();

        // 1. Get active academic period
        $pStmt = $pdo->query('SELECT id FROM academic_periods WHERE is_active = 1 ORDER BY id DESC LIMIT 1');
        $period = $pStmt->fetch();
        if (!$period) {
            $pStmt = $pdo->query('SELECT id FROM academic_periods ORDER BY id DESC LIMIT 1');
            $period = $pStmt->fetch();
        }
        if (!$period) {
            $pdo->exec("INSERT INTO academic_periods (name, start_date, end_date, is_active) VALUES ('2026-1', '2026-02-01', '2026-06-30', 1)");
            $periodId = (int)$pdo->lastInsertId();
        } else {
            $periodId = (int)$period['id'];
        }

        // 2. Generate code if empty
        if (empty($subjectCode)) {
            $words = array_filter(explode(' ', $subjectName), fn($w) => strlen($w) > 2);
            $prefix = '';
            foreach ($words as $w) {
                $prefix .= strtoupper(mb_substr($w, 0, 1));
            }
            $prefix = substr($prefix, 0, 4) ?: 'ASIG';
            $subjectCode = $prefix . '-' . rand(100, 999);
        } else {
            $subjectCode = strtoupper($subjectCode);
        }

        // 3. Find or insert Subject
        $sFind = $pdo->prepare('SELECT id FROM subjects WHERE UPPER(name) = UPPER(?) OR code = ?');
        $sFind->execute([$subjectName, $subjectCode]);
        $sub = $sFind->fetch();

        if ($sub) {
            $subjectId = (int)$sub['id'];
        } else {
            try {
                $sIns = $pdo->prepare('INSERT INTO subjects (code, name, credits, description) VALUES (?, ?, ?, ?)');
                $sIns->execute([$subjectCode, $subjectName, $credits, $description ?: null]);
                $subjectId = (int)$pdo->lastInsertId();
            } catch (PDOException $e) {
                $altCode = $subjectCode . '-' . rand(10, 99);
                $sIns = $pdo->prepare('INSERT INTO subjects (code, name, credits, description) VALUES (?, ?, ?, ?)');
                $sIns->execute([$altCode, $subjectName, $credits, $description ?: null]);
                $subjectId = (int)$pdo->lastInsertId();
            }
        }

        // 4. Create Course Group
        try {
            $gIns = $pdo->prepare('
                INSERT INTO course_groups (subject_id, teacher_id, period_id, group_code, schedule, classroom, geo_lat, geo_lng, geo_radius_meters)
                VALUES (?, ?, ?, ?, ?, ?, 4.6097, -74.0817, 100.0)
            ');
            $gIns->execute([$subjectId, $teacherId, $periodId, $groupCode, $schedule, $classroom]);
            $groupId = (int)$pdo->lastInsertId();

            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $aud = $pdo->prepare('
                INSERT INTO audit_logs (user_id, action, resource_type, resource_id, details_json, ip_address)
                VALUES (?, "TEACHER_CREATED_COURSE", "COURSE_GROUP", ?, ?, ?)
            ');
            $aud->execute([$user['id'], (string)$groupId, json_encode(['subject_name' => $subjectName, 'group_code' => $groupCode]), $ip]);

            http_response_code(201);
            echo json_encode([
                'success'   => true,
                'groupId'   => $groupId,
                'subjectId' => $subjectId,
                'message'   => "Asignatura \"{$subjectName}\" ({$groupCode}) creada y asignada exitosamente."
            ], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                http_response_code(409);
                echo json_encode(['error' => 'GROUP_EXISTS', 'message' => 'Ya tiene un grupo con ese código para esta asignatura.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            throw $e;
        }
    }

    /**
     * PUT /api/academic/groups/:id (ADMIN & TEACHER)
     */
    public static function updateGroup(array $user, $groupId, array $body): void {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('SELECT id, teacher_id, subject_id FROM course_groups WHERE id = ?');
        $stmt->execute([(int)$groupId]);
        $group = $stmt->fetch();

        if (!$group) {
            http_response_code(404);
            echo json_encode(['error' => 'GROUP_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($user['role'] === 'TEACHER' && (int)$group['teacher_id'] !== (int)$user['teacherId']) {
            http_response_code(403);
            echo json_encode(['error' => 'FORBIDDEN', 'message' => 'No tiene permiso para editar este grupo.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!empty($body['subject_name'])) {
            $updSub = $pdo->prepare('UPDATE subjects SET name = ? WHERE id = ?');
            $updSub->execute([trim($body['subject_name']), $group['subject_id']]);
        }

        $upd = $pdo->prepare('
            UPDATE course_groups
            SET teacher_id = COALESCE(?, teacher_id),
                group_code = COALESCE(?, group_code),
                schedule = COALESCE(?, schedule),
                classroom = COALESCE(?, classroom),
                geo_lat = COALESCE(?, geo_lat),
                geo_lng = COALESCE(?, geo_lng),
                geo_radius_meters = COALESCE(?, geo_radius_meters)
            WHERE id = ?
        ');

        $newTeacherId = ($user['role'] === 'ADMIN' && !empty($body['teacher_id'])) ? (int)$body['teacher_id'] : null;
        $upd->execute([
            $newTeacherId,
            !empty($body['group_code']) ? strtoupper(trim($body['group_code'])) : null,
            !empty($body['schedule']) ? trim($body['schedule']) : null,
            !empty($body['classroom']) ? trim($body['classroom']) : null,
            isset($body['geo_lat']) ? (float)$body['geo_lat'] : null,
            isset($body['geo_lng']) ? (float)$body['geo_lng'] : null,
            isset($body['geo_radius_meters']) ? (float)$body['geo_radius_meters'] : null,
            (int)$groupId
        ]);

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $aud = $pdo->prepare('
            INSERT INTO audit_logs (user_id, action, resource_type, resource_id, details_json, ip_address)
            VALUES (?, "GROUP_UPDATED", "COURSE_GROUP", ?, ?, ?)
        ');
        $aud->execute([$user['id'], (string)$groupId, json_encode(['updated_fields' => array_keys($body)]), $ip]);

        echo json_encode(['success' => true, 'message' => 'Grupo académico actualizado exitosamente.'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * DELETE /api/academic/groups/:id (ADMIN & TEACHER)
     */
    public static function deleteGroup(array $user, $groupId): void {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('SELECT id, teacher_id FROM course_groups WHERE id = ?');
        $stmt->execute([(int)$groupId]);
        $group = $stmt->fetch();

        if (!$group) {
            http_response_code(404);
            echo json_encode(['error' => 'GROUP_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($user['role'] === 'TEACHER' && (int)$group['teacher_id'] !== (int)$user['teacherId']) {
            http_response_code(403);
            echo json_encode(['error' => 'FORBIDDEN', 'message' => 'No tiene permiso para eliminar este grupo.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $del = $pdo->prepare('DELETE FROM course_groups WHERE id = ?');
        $del->execute([(int)$groupId]);

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $aud = $pdo->prepare('
            INSERT INTO audit_logs (user_id, action, resource_type, resource_id, details_json, ip_address)
            VALUES (?, "GROUP_DELETED", "COURSE_GROUP", ?, "{}", ?)
        ');
        $aud->execute([$user['id'], (string)$groupId, $ip]);

        echo json_encode(['success' => true, 'message' => 'Grupo eliminado correctamente.'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /api/academic/available-courses
     */
    public static function getAvailableCourses(): void {
        $pdo = Database::getConnection();
        $stmt = $pdo->query('
            SELECT 
                cg.id, cg.group_code, cg.schedule, cg.classroom,
                s.id AS subject_id, s.code AS subject_code, s.name AS subject_name, s.credits,
                u.first_name AS teacher_first_name, u.last_name AS teacher_last_name,
                ap.name AS period_name,
                (SELECT COUNT(*) FROM enrollments e WHERE e.group_id = cg.id AND e.status = "ACTIVE") AS enrolled_count
            FROM course_groups cg
            JOIN subjects s ON cg.subject_id = s.id
            JOIN teachers t ON cg.teacher_id = t.id
            JOIN users u ON t.user_id = u.id
            JOIN academic_periods ap ON cg.period_id = ap.id
            ORDER BY s.name ASC, cg.group_code ASC
        ');
        echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST /api/academic/enrollments (ADMIN & STUDENT)
     */
    public static function enrollStudent(array $user, array $body): void {
        $studentId = ($user['role'] === 'STUDENT') ? $user['studentId'] : (int)($body['student_id'] ?? 0);
        $groupId   = (int)($body['group_id'] ?? 0);
        $groupCode = trim($body['group_code'] ?? '');

        $pdo = Database::getConnection();

        if (!$groupId && !empty($groupCode)) {
            $fStmt = $pdo->prepare('SELECT id FROM course_groups WHERE UPPER(group_code) = UPPER(?)');
            $fStmt->execute([$groupCode]);
            $found = $fStmt->fetch();
            if ($found) {
                $groupId = (int)$found['id'];
            }
        }

        if (!$studentId || !$groupId) {
            http_response_code(400);
            echo json_encode(['error' => 'MISSING_FIELDS', 'message' => 'Se requiere el estudiante y el grupo a matricular.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $ins = $pdo->prepare('INSERT INTO enrollments (student_id, group_id, status) VALUES (?, ?, "ACTIVE")');
            $ins->execute([$studentId, $groupId]);
            $enrollmentId = (int)$pdo->lastInsertId();

            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $aud = $pdo->prepare('
                INSERT INTO audit_logs (user_id, action, resource_type, resource_id, details_json, ip_address)
                VALUES (?, "STUDENT_ENROLLED", "ENROLLMENT", ?, ?, ?)
            ');
            $aud->execute([$user['id'], (string)$enrollmentId, json_encode(['student_id' => $studentId, 'group_id' => $groupId]), $ip]);

            http_response_code(201);
            echo json_encode([
                'success'      => true,
                'enrollmentId' => $enrollmentId,
                'message'      => '¡Matrícula completada exitosamente!'
            ], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                http_response_code(409);
                echo json_encode(['error' => 'ALREADY_ENROLLED', 'message' => 'Ya se encuentra matriculado en esta asignatura.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            throw $e;
        }
    }

    /**
     * GET /api/academic/teachers (ADMIN ONLY)
     */
    public static function getTeachers(): void {
        $pdo = Database::getConnection();
        $stmt = $pdo->query('
            SELECT 
                t.id AS teacher_id, t.teacher_code, t.department, t.specialty,
                u.id AS user_id, u.first_name, u.last_name, u.email, u.phone, u.status,
                (SELECT COUNT(*) FROM course_groups cg WHERE cg.teacher_id = t.id) AS assigned_groups_count
            FROM teachers t
            JOIN users u ON t.user_id = u.id
            ORDER BY u.last_name ASC
        ');
        echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * PUT /api/academic/teachers/:id (ADMIN ONLY)
     */
    public static function updateTeacher(array $user, $teacherId, array $body): void {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('SELECT user_id FROM teachers WHERE id = ?');
        $stmt->execute([(int)$teacherId]);
        $teacher = $stmt->fetch();

        if (!$teacher) {
            http_response_code(404);
            echo json_encode(['error' => 'TEACHER_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
            return;
        }

        Database::transaction(function (PDO $db) use ($teacherId, $teacher, $body) {
            $tUpd = $db->prepare('
                UPDATE teachers 
                SET department = COALESCE(?, department),
                    specialty = COALESCE(?, specialty)
                WHERE id = ?
            ');
            $tUpd->execute([
                !empty($body['department']) ? trim($body['department']) : null,
                !empty($body['specialty']) ? trim($body['specialty']) : null,
                (int)$teacherId
            ]);

            if (!empty($body['first_name']) || !empty($body['last_name']) || isset($body['phone'])) {
                $uUpd = $db->prepare('
                    UPDATE users 
                    SET first_name = COALESCE(?, first_name),
                        last_name = COALESCE(?, last_name),
                        phone = COALESCE(?, phone)
                    WHERE id = ?
                ');
                $uUpd->execute([
                    !empty($body['first_name']) ? trim($body['first_name']) : null,
                    !empty($body['last_name']) ? trim($body['last_name']) : null,
                    isset($body['phone']) ? trim($body['phone']) : null,
                    $teacher['user_id']
                ]);
            }
        });

        echo json_encode(['success' => true, 'message' => 'Datos del docente actualizados correctamente.'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /api/academic/students (ADMIN & TEACHER)
     */
    public static function getStudents(): void {
        $pdo = Database::getConnection();
        $stmt = $pdo->query('
            SELECT 
                s.id AS student_id, s.student_code, s.career, s.semester,
                u.id AS user_id, u.first_name, u.last_name, u.email, u.phone, u.status,
                (SELECT COUNT(*) FROM enrollments e WHERE e.student_id = s.id AND e.status = "ACTIVE") AS enrolled_courses_count
            FROM students s
            JOIN users u ON s.user_id = u.id
            ORDER BY u.last_name ASC
        ');
        echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
    }
}
