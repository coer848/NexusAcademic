<?php
/**
 * Dynamic QR Attendance & Class Session Controller
 * Handles cryptographically signed QR codes, anti-replay nonces, and geopresence verification
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Services/CryptoService.php';

class AttendanceController {

    /**
     * POST /api/sessions/start (TEACHER & ADMIN)
     */
    public static function startSession(array $user, array $body): void {
        $groupId         = (int)($body['group_id'] ?? 0);
        $topic           = trim($body['topic'] ?? '');
        $geoLat          = isset($body['geo_lat']) ? (float)$body['geo_lat'] : null;
        $geoLng          = isset($body['geo_lng']) ? (float)$body['geo_lng'] : null;
        $geoRadiusMeters = isset($body['geo_radius_meters']) ? (float)$body['geo_radius_meters'] : null;

        if (!$groupId || empty($topic)) {
            http_response_code(400);
            echo json_encode(['error' => 'MISSING_FIELDS', 'message' => 'group_id y topic son obligatorios.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $pdo = Database::getConnection();

        $gStmt = $pdo->prepare('SELECT id, teacher_id, classroom, geo_lat, geo_lng, geo_radius_meters FROM course_groups WHERE id = ?');
        $gStmt->execute([$groupId]);
        $group = $gStmt->fetch();

        if (!$group) {
            http_response_code(404);
            echo json_encode(['error' => 'GROUP_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($user['role'] === 'TEACHER' && (int)$group['teacher_id'] !== (int)$user['teacherId']) {
            http_response_code(403);
            echo json_encode(['error' => 'NOT_COURSE_TEACHER', 'message' => 'No es el docente asignado a este grupo.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Close any existing active sessions for this group
        $updPrev = $pdo->prepare('UPDATE class_sessions SET status = "CLOSED", closed_at = CURRENT_TIMESTAMP WHERE group_id = ? AND status = "ACTIVE"');
        $updPrev->execute([$groupId]);

        // Generate 64-character random hex secret for HMAC
        $sessionSecret = bin2hex(random_bytes(32));
        $finalLat = $geoLat !== null ? $geoLat : ($group['geo_lat'] ? (float)$group['geo_lat'] : 4.6097);
        $finalLng = $geoLng !== null ? $geoLng : ($group['geo_lng'] ? (float)$group['geo_lng'] : -74.0817);
        $finalRadius = $geoRadiusMeters !== null ? $geoRadiusMeters : ($group['geo_radius_meters'] ? (float)$group['geo_radius_meters'] : 100.0);

        $ins = $pdo->prepare('
            INSERT INTO class_sessions (group_id, teacher_id, topic, status, session_secret, geo_lat, geo_lng, geo_radius_meters)
            VALUES (?, ?, ?, "ACTIVE", ?, ?, ?, ?)
        ');
        $ins->execute([$groupId, $group['teacher_id'], $topic, $sessionSecret, $finalLat, $finalLng, $finalRadius]);
        $sessionId = (int)$pdo->lastInsertId();

        $initialQR = CryptoService::generateQRPayload($sessionId, $sessionSecret);

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $aud = $pdo->prepare('
            INSERT INTO audit_logs (user_id, action, resource_type, resource_id, details_json, ip_address)
            VALUES (?, "SESSION_STARTED", "CLASS_SESSION", ?, ?, ?)
        ');
        $aud->execute([$user['id'], (string)$sessionId, json_encode(['groupId' => $groupId, 'topic' => $topic]), $ip]);

        http_response_code(201);
        echo json_encode([
            'success'   => true,
            'sessionId' => $sessionId,
            'topic'     => $topic,
            'startedAt' => date('c'),
            'qrPayload' => $initialQR
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /api/sessions/:id/qr-token (TEACHER & ADMIN)
     */
    public static function getSessionQRToken(array $user, $sessionId): void {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('SELECT id, group_id, teacher_id, status, session_secret FROM class_sessions WHERE id = ?');
        $stmt->execute([(int)$sessionId]);
        $session = $stmt->fetch();

        if (!$session) {
            http_response_code(404);
            echo json_encode(['error' => 'SESSION_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($session['status'] !== 'ACTIVE') {
            http_response_code(400);
            echo json_encode([
                'error'   => 'SESSION_CLOSED',
                'message' => 'La sesión de clase ya está cerrada. No se pueden generar nuevos códigos QR.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($user['role'] === 'TEACHER' && (int)$session['teacher_id'] !== (int)$user['teacherId']) {
            http_response_code(403);
            echo json_encode(['error' => 'NOT_COURSE_TEACHER'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $qrPayload = CryptoService::generateQRPayload($session['id'], $session['session_secret']);
        echo json_encode(['success' => true, 'qrPayload' => $qrPayload], JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST /api/sessions/:id/close (TEACHER & ADMIN)
     */
    public static function closeSession(array $user, $sessionId): void {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('SELECT id, group_id, teacher_id, status FROM class_sessions WHERE id = ?');
        $stmt->execute([(int)$sessionId]);
        $session = $stmt->fetch();

        if (!$session) {
            http_response_code(404);
            echo json_encode(['error' => 'SESSION_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($user['role'] === 'TEACHER' && (int)$session['teacher_id'] !== (int)$user['teacherId']) {
            http_response_code(403);
            echo json_encode(['error' => 'NOT_COURSE_TEACHER'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $upd = $pdo->prepare('UPDATE class_sessions SET status = "CLOSED", closed_at = CURRENT_TIMESTAMP WHERE id = ?');
        $upd->execute([(int)$sessionId]);

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $aud = $pdo->prepare('
            INSERT INTO audit_logs (user_id, action, resource_type, resource_id, details_json, ip_address)
            VALUES (?, "SESSION_CLOSED", "CLASS_SESSION", ?, "{}", ?)
        ');
        $aud->execute([$user['id'], (string)$sessionId, $ip]);

        echo json_encode(['success' => true, 'message' => 'Sesión de clase cerrada con éxito.'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST /api/attendance/register (STUDENT ONLY)
     */
    public static function registerAttendance(array $user, array $body): void {
        $studentId = $user['studentId'];
        if (!$studentId) {
            http_response_code(403);
            echo json_encode(['error' => 'STUDENT_ROLE_REQUIRED', 'message' => 'Solo los estudiantes pueden registrar asistencia.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $sessionId = (int)($body['sessionId'] ?? 0);
        $nonce     = trim($body['nonce'] ?? '');
        $timestamp = $body['timestamp'] ?? null;
        $signature = trim($body['signature'] ?? '');
        $latitude  = isset($body['latitude']) ? (float)$body['latitude'] : null;
        $longitude = isset($body['longitude']) ? (float)$body['longitude'] : null;
        $userAgent = $body['userAgent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');

        if (!$sessionId || empty($nonce) || empty($timestamp) || empty($signature)) {
            http_response_code(400);
            echo json_encode([
                'error'   => 'MISSING_QR_PAYLOAD',
                'message' => 'Payload QR incompleto (se requiere sessionId, nonce, timestamp, signature).'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $pdo = Database::getConnection();

        // 1. Fetch Session
        $sStmt = $pdo->prepare('
            SELECT id, group_id, teacher_id, status, session_secret, geo_lat, geo_lng, geo_radius_meters 
            FROM class_sessions 
            WHERE id = ?
        ');
        $sStmt->execute([$sessionId]);
        $session = $sStmt->fetch();

        if (!$session) {
            http_response_code(404);
            echo json_encode(['error' => 'SESSION_NOT_FOUND', 'message' => 'La sesión no existe.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($session['status'] !== 'ACTIVE') {
            http_response_code(400);
            echo json_encode(['error' => 'SESSION_CLOSED', 'message' => 'La sesión de clase se encuentra cerrada. Registro no permitido.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 2. Verify Student Enrollment
        $eStmt = $pdo->prepare('SELECT id FROM enrollments WHERE group_id = ? AND student_id = ? AND status = "ACTIVE"');
        $eStmt->execute([$session['group_id'], $studentId]);
        if (!$eStmt->fetch()) {
            http_response_code(403);
            echo json_encode(['error' => 'STUDENT_NOT_ENROLLED', 'message' => 'El estudiante no está matriculado en esta asignatura/grupo.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 3. Cryptographic Verification of Dynamic QR Payload
        $cryptoResult = CryptoService::verifyQRPayload($sessionId, $nonce, $timestamp, $signature, $session['session_secret']);

        if (!$cryptoResult['valid']) {
            if ($cryptoResult['reason'] === 'TOKEN_EXPIRED') {
                http_response_code(400);
                echo json_encode([
                    'error'   => 'TOKEN_EXPIRED',
                    'message' => "El código QR ha expirado ({$cryptoResult['ageSeconds']}s de antigüedad). Por favor escanee el código QR actualizado proyectado en pantalla."
                ], JSON_UNESCAPED_UNICODE);
                return;
            }
            http_response_code(401);
            echo json_encode([
                'error'   => 'INVALID_SIGNATURE',
                'message' => 'Firma criptográfica inválida o token manipulado.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 4. Anti-Replay Check (Nonce)
        $tStmt = $pdo->prepare('SELECT id FROM attendance_tokens WHERE nonce = ?');
        $tStmt->execute([$nonce]);
        if ($tStmt->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'TOKEN_ALREADY_USED', 'message' => 'Este token QR específico ya fue utilizado.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 5. Unique Attendance Check (1 student + 1 session <= 1 attendance)
        $aStmt = $pdo->prepare('SELECT id, registered_at, status FROM attendances WHERE session_id = ? AND student_id = ?');
        $aStmt->execute([$sessionId, $studentId]);
        $existing = $aStmt->fetch();
        if ($existing) {
            http_response_code(409);
            echo json_encode([
                'error'      => 'ATTENDANCE_ALREADY_REGISTERED',
                'message'    => 'Ya se encuentra registrada la asistencia para esta sesión.',
                'attendance' => $existing
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 6. Geopresence & Telemetry Analysis
        $distanceMeters = null;
        $isFlagged = 0;
        $flagReason = null;

        if ($latitude !== null && $longitude !== null && $session['geo_lat'] !== null && $session['geo_lng'] !== null) {
            $distanceMeters = CryptoService::calculateHaversineDistance(
                $latitude,
                $longitude,
                (float)$session['geo_lat'],
                (float)$session['geo_lng']
            );

            $maxRadius = $session['geo_radius_meters'] ? (float)$session['geo_radius_meters'] : 100.0;
            if ($distanceMeters > $maxRadius) {
                $isFlagged = 1;
                $flagReason = "Distancia calculada ({$distanceMeters}m) excede el radio permitido de aula ({$maxRadius}m).";
            }
        }

        // 7. Atomic Insert (Transaction)
        try {
            $attendanceResult = Database::transaction(function (PDO $db) use ($sessionId, $studentId, $userAgent, $distanceMeters, $isFlagged, $flagReason, $nonce) {
                $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

                $insAtt = $db->prepare('
                    INSERT INTO attendances (
                        session_id, student_id, status, registered_at, verification_method,
                        ip_address, user_agent, distance_meters, is_flagged, flag_reason
                    ) VALUES (?, ?, "PRESENT", CURRENT_TIMESTAMP, "QR_DYNAMIC_HMAC", ?, ?, ?, ?, ?)
                ');
                $insAtt->execute([
                    $sessionId,
                    $studentId,
                    $ip,
                    $userAgent,
                    $distanceMeters,
                    $isFlagged,
                    $flagReason
                ]);
                $attendanceId = (int)$db->lastInsertId();

                $insTok = $db->prepare('INSERT INTO attendance_tokens (session_id, nonce, student_id) VALUES (?, ?, ?)');
                $insTok->execute([$sessionId, $nonce, $studentId]);

                return [
                    'id'             => $attendanceId,
                    'sessionId'      => $sessionId,
                    'studentId'      => $studentId,
                    'status'         => 'PRESENT',
                    'registeredAt'   => date('c'),
                    'isFlagged'      => $isFlagged,
                    'flagReason'     => $flagReason,
                    'distanceMeters' => $distanceMeters
                ];
            });

            http_response_code(200);
            echo json_encode([
                'success'    => true,
                'message'    => '¡Asistencia registrada con éxito!',
                'attendance' => $attendanceResult
            ], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                http_response_code(409);
                echo json_encode([
                    'error'   => 'ATTENDANCE_ALREADY_REGISTERED',
                    'message' => 'Asistencia ya registrada previamente (conflicto de concurrencia prevenido).'
                ], JSON_UNESCAPED_UNICODE);
                return;
            }
            throw $e;
        }
    }

    /**
     * GET /api/attendance/session/:id (TEACHER & ADMIN)
     */
    public static function getSessionAttendance(array $user, $sessionId): void {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('
            SELECT cs.*, cg.group_code, s.name AS subject_name
            FROM class_sessions cs
            JOIN course_groups cg ON cs.group_id = cg.id
            JOIN subjects s ON cg.subject_id = s.id
            WHERE cs.id = ?
        ');
        $stmt->execute([(int)$sessionId]);
        $session = $stmt->fetch();

        if (!$session) {
            http_response_code(404);
            echo json_encode(['error' => 'SESSION_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($user['role'] === 'TEACHER' && (int)$session['teacher_id'] !== (int)$user['teacherId']) {
            http_response_code(403);
            echo json_encode(['error' => 'NOT_COURSE_TEACHER'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Present attendances
        $aStmt = $pdo->prepare('
            SELECT 
                a.id AS attendance_id, a.status, a.registered_at, a.verification_method,
                a.distance_meters, a.is_flagged, a.flag_reason, a.ip_address,
                st.id AS student_id, st.student_code, st.career,
                u.first_name, u.last_name, u.email
            FROM attendances a
            JOIN students st ON a.student_id = st.id
            JOIN users u ON st.user_id = u.id
            WHERE a.session_id = ?
            ORDER BY a.registered_at DESC
        ');
        $aStmt->execute([(int)$sessionId]);
        $attendances = $aStmt->fetchAll();

        // All enrolled students
        $eStmt = $pdo->prepare('
            SELECT 
                st.id AS student_id, st.student_code, st.career,
                u.first_name, u.last_name, u.email
            FROM enrollments e
            JOIN students st ON e.student_id = st.id
            JOIN users u ON st.user_id = u.id
            WHERE e.group_id = ? AND e.status = "ACTIVE"
            ORDER BY u.last_name ASC
        ');
        $eStmt->execute([(int)$session['group_id']]);
        $allEnrolled = $eStmt->fetchAll();

        $registeredStudentIds = array_flip(array_column($attendances, 'student_id'));
        $pendingStudents = array_values(array_filter($allEnrolled, function ($s) use ($registeredStudentIds) {
            return !isset($registeredStudentIds[$s['student_id']]);
        }));

        $totalEnrolled = count($allEnrolled);
        $totalPresent  = count($attendances);
        $percentage    = ($totalEnrolled > 0) ? round(($totalPresent / $totalEnrolled) * 100) : 0;

        echo json_encode([
            'session'              => $session,
            'attendances'          => $attendances,
            'pendingStudents'      => $pendingStudents,
            'totalEnrolled'        => $totalEnrolled,
            'totalPresent'         => $totalPresent,
            'attendancePercentage' => $percentage
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /api/attendance/my-history (STUDENT ONLY)
     */
    public static function getMyAttendanceHistory(array $user): void {
        $studentId = $user['studentId'];
        if (!$studentId) {
            http_response_code(403);
            echo json_encode(['error' => 'STUDENT_ROLE_REQUIRED'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('
            SELECT 
                a.id AS attendance_id, a.status, a.registered_at, a.verification_method, a.is_flagged,
                cs.id AS session_id, cs.topic, cs.started_at,
                cg.id AS group_id, cg.group_code, cg.classroom,
                s.name AS subject_name, s.code AS subject_code,
                u.first_name AS teacher_first_name, u.last_name AS teacher_last_name
            FROM attendances a
            JOIN class_sessions cs ON a.session_id = cs.id
            JOIN course_groups cg ON cs.group_id = cg.id
            JOIN subjects s ON cg.subject_id = s.id
            JOIN teachers t ON cg.teacher_id = t.id
            JOIN users u ON t.user_id = u.id
            WHERE a.student_id = ?
            ORDER BY a.registered_at DESC
        ');
        $stmt->execute([$studentId]);
        $records = $stmt->fetchAll();

        $gStmt = $pdo->prepare('
            SELECT 
                cg.id AS group_id, cg.group_code, s.name AS subject_name, s.code AS subject_code,
                (SELECT COUNT(*) FROM class_sessions WHERE group_id = cg.id) AS total_sessions,
                (SELECT COUNT(*) FROM attendances a JOIN class_sessions cs ON a.session_id = cs.id WHERE cs.group_id = cg.id AND a.student_id = ?) AS attended_sessions
            FROM enrollments e
            JOIN course_groups cg ON e.group_id = cg.id
            JOIN subjects s ON cg.subject_id = s.id
            WHERE e.student_id = ? AND e.status = "ACTIVE"
        ');
        $gStmt->execute([$studentId, $studentId]);
        $enrolledGroups = $gStmt->fetchAll();

        echo json_encode([
            'records'        => $records,
            'enrolledGroups' => $enrolledGroups
        ], JSON_UNESCAPED_UNICODE);
    }
}
