<?php
/**
 * Authentication & User Management Controller
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Services/CryptoService.php';

class AuthController {

    /**
     * POST /api/auth/register
     */
    public static function register(array $body): void {
        $email      = trim($body['email'] ?? '');
        $password   = $body['password'] ?? '';
        $firstName  = trim($body['first_name'] ?? '');
        $lastName   = trim($body['last_name'] ?? '');
        $role       = strtoupper(trim($body['role'] ?? ''));
        $code       = trim($body['code'] ?? '');
        $career     = trim($body['career'] ?? '');
        $department = trim($body['department'] ?? '');
        $specialty  = trim($body['specialty'] ?? '');
        $semester   = isset($body['semester']) ? (int)$body['semester'] : 1;

        if (empty($email) || empty($password) || empty($firstName) || empty($lastName) || empty($role)) {
            http_response_code(400);
            echo json_encode([
                'error'   => 'MISSING_FIELDS',
                'message' => 'Nombre, apellido, correo, contraseña y rol son obligatorios.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (strlen($password) < 6) {
            http_response_code(400);
            echo json_encode([
                'error'   => 'INVALID_PASSWORD',
                'message' => 'La contraseña debe tener al menos 6 caracteres.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $validRoles = ['ADMIN', 'TEACHER', 'STUDENT'];
        if (!in_array($role, $validRoles, true)) {
            http_response_code(400);
            echo json_encode([
                'error'   => 'INVALID_ROLE',
                'message' => 'El rol debe ser ADMIN, TEACHER o STUDENT.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $cleanEmail = strtolower($email);
        $pdo = Database::getConnection();

        // Check if email already registered
        $chk = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $chk->execute([$cleanEmail]);
        if ($chk->fetch()) {
            http_response_code(409);
            echo json_encode([
                'error'   => 'EMAIL_ALREADY_EXISTS',
                'message' => 'El correo electrónico ya se encuentra registrado.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $passwordHash = CryptoService::hashPassword($password);

        try {
            $registeredUser = Database::transaction(function (PDO $db) use ($cleanEmail, $passwordHash, $firstName, $lastName, $role, $code, $department, $specialty, $career, $semester) {
                // 1. Insert into users
                $uStmt = $db->prepare('
                    INSERT INTO users (email, password_hash, first_name, last_name, role, status)
                    VALUES (?, ?, ?, ?, ?, "ACTIVE")
                ');
                $uStmt->execute([$cleanEmail, $passwordHash, $firstName, $lastName, $role]);
                $userId = (int)$db->lastInsertId();

                $teacherProfile = null;
                $studentProfile = null;

                // 2. Insert into role-specific table
                if ($role === 'TEACHER') {
                    $teacherCode = !empty($code) ? strtoupper($code) : 'DOC-' . rand(100, 999);
                    $tDept = !empty($department) ? $department : 'Facultad de Ingeniería & Tecnología';
                    $tSpec = !empty($specialty) ? $specialty : 'Docencia Universitaria';

                    $tStmt = $db->prepare('
                        INSERT INTO teachers (user_id, teacher_code, department, specialty)
                        VALUES (?, ?, ?, ?)
                    ');
                    $tStmt->execute([$userId, $teacherCode, $tDept, $tSpec]);
                    $teacherProfile = [
                        'id'           => (int)$db->lastInsertId(),
                        'teacher_code' => $teacherCode,
                        'department'   => $tDept
                    ];
                } elseif ($role === 'STUDENT') {
                    $studentCode = !empty($code) ? strtoupper($code) : 'EST-2026-' . rand(10, 99);
                    $sCareer = !empty($career) ? $career : 'Ingeniería de Software';

                    $sStmt = $db->prepare('
                        INSERT INTO students (user_id, student_code, career, semester)
                        VALUES (?, ?, ?, ?)
                    ');
                    $sStmt->execute([$userId, $studentCode, $sCareer, $semester]);
                    $studentProfile = [
                        'id'           => (int)$db->lastInsertId(),
                        'student_code' => $studentCode,
                        'career'       => $sCareer
                    ];
                }

                // 3. Audit log
                $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                $aud = $db->prepare('
                    INSERT INTO audit_logs (user_id, action, resource_type, resource_id, details_json, ip_address)
                    VALUES (?, "USER_REGISTERED", "USER", ?, ?, ?)
                ');
                $aud->execute([$userId, (string)$userId, json_encode(['email' => $cleanEmail, 'role' => $role]), $ip]);

                return [
                    'id'        => $userId,
                    'email'     => $cleanEmail,
                    'firstName' => $firstName,
                    'lastName'  => $lastName,
                    'role'      => $role,
                    'teacher'   => $teacherProfile,
                    'student'   => $studentProfile
                ];
            });

            // Generate JWT Token
            $token = CryptoService::signToken([
                'id'        => $registeredUser['id'],
                'email'     => $registeredUser['email'],
                'role'      => $registeredUser['role'],
                'firstName' => $registeredUser['firstName'],
                'lastName'  => $registeredUser['lastName'],
                'teacherId' => $registeredUser['teacher']['id'] ?? null,
                'studentId' => $registeredUser['student']['id'] ?? null
            ]);

            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => '¡Cuenta creada y registrada con éxito!',
                'token'   => $token,
                'user'    => $registeredUser
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'error'   => 'SERVER_ERROR',
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/auth/login
     */
    public static function login(array $body): void {
        $email    = strtolower(trim($body['email'] ?? ''));
        $password = $body['password'] ?? '';

        if (empty($email) || empty($password)) {
            http_response_code(400);
            echo json_encode([
                'error'   => 'MISSING_CREDENTIALS',
                'message' => 'Email y contraseña son obligatorios.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT id, email, password_hash, first_name, last_name, role, status 
            FROM users 
            WHERE email = ?
        ');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            http_response_code(401);
            echo json_encode([
                'error'   => 'INVALID_CREDENTIALS',
                'message' => 'Credenciales inválidas.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($user['status'] !== 'ACTIVE') {
            http_response_code(403);
            echo json_encode([
                'error'   => 'ACCOUNT_SUSPENDED',
                'message' => 'La cuenta se encuentra inactiva o suspendida.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!CryptoService::verifyPassword($password, $user['password_hash'])) {
            http_response_code(401);
            echo json_encode([
                'error'   => 'INVALID_CREDENTIALS',
                'message' => 'Credenciales inválidas.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Fetch role profile
        $teacherProfile = null;
        $studentProfile = null;

        if ($user['role'] === 'TEACHER') {
            $tStmt = $pdo->prepare('SELECT id, teacher_code, department, specialty FROM teachers WHERE user_id = ?');
            $tStmt->execute([$user['id']]);
            $teacherProfile = $tStmt->fetch() ?: null;
        } elseif ($user['role'] === 'STUDENT') {
            $sStmt = $pdo->prepare('SELECT id, student_code, career, semester FROM students WHERE user_id = ?');
            $sStmt->execute([$user['id']]);
            $studentProfile = $sStmt->fetch() ?: null;
        }

        $tokenPayload = [
            'id'        => (int)$user['id'],
            'email'     => $user['email'],
            'role'      => $user['role'],
            'firstName' => $user['first_name'],
            'lastName'  => $user['last_name'],
            'teacherId' => $teacherProfile ? (int)$teacherProfile['id'] : null,
            'studentId' => $studentProfile ? (int)$studentProfile['id'] : null
        ];

        $token = CryptoService::signToken($tokenPayload);

        // Audit login
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $aud = $pdo->prepare('
                INSERT INTO audit_logs (user_id, action, resource_type, resource_id, details_json, ip_address)
                VALUES (?, "LOGIN_SUCCESS", "AUTH", ?, ?, ?)
            ');
            $aud->execute([$user['id'], (string)$user['id'], json_encode(['role' => $user['role']]), $ip]);
        } catch (Throwable $e) {}

        echo json_encode([
            'success' => true,
            'token'   => $token,
            'user'    => [
                'id'        => (int)$user['id'],
                'email'     => $user['email'],
                'firstName' => $user['first_name'],
                'lastName'  => $user['last_name'],
                'role'      => $user['role'],
                'teacher'   => $teacherProfile,
                'student'   => $studentProfile
            ]
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /api/auth/me
     */
    public static function getMe(array $user): void {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT id, email, first_name, last_name, role, status, created_at 
            FROM users 
            WHERE id = ?
        ');
        $stmt->execute([$user['id']]);
        $dbUser = $stmt->fetch();

        if (!$dbUser) {
            http_response_code(404);
            echo json_encode(['error' => 'USER_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $teacher = null;
        $student = null;

        if ($dbUser['role'] === 'TEACHER') {
            $tStmt = $pdo->prepare('SELECT id, teacher_code, department, specialty FROM teachers WHERE user_id = ?');
            $tStmt->execute([$dbUser['id']]);
            $teacher = $tStmt->fetch() ?: null;
        } elseif ($dbUser['role'] === 'STUDENT') {
            $sStmt = $pdo->prepare('SELECT id, student_code, career, semester FROM students WHERE user_id = ?');
            $sStmt->execute([$dbUser['id']]);
            $student = $sStmt->fetch() ?: null;
        }

        $instStmt = $pdo->query('SELECT * FROM institutions LIMIT 1');
        $institution = $instStmt->fetch() ?: null;

        echo json_encode([
            'user' => [
                'id'        => (int)$dbUser['id'],
                'email'     => $dbUser['email'],
                'firstName' => $dbUser['first_name'],
                'lastName'  => $dbUser['last_name'],
                'role'      => $dbUser['role'],
                'status'    => $dbUser['status'],
                'createdAt' => $dbUser['created_at'],
                'teacher'   => $teacher,
                'student'   => $student
            ],
            'institution' => $institution
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST /api/auth/change-password
     */
    public static function changePassword(array $user, array $body): void {
        $currentPassword = $body['currentPassword'] ?? '';
        $newPassword     = $body['newPassword'] ?? '';

        if (empty($currentPassword) || empty($newPassword) || strlen($newPassword) < 6) {
            http_response_code(400);
            echo json_encode([
                'error'   => 'INVALID_PASSWORD',
                'message' => 'La nueva contraseña debe tener al menos 6 caracteres.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();

        if (!$row || !CryptoService::verifyPassword($currentPassword, $row['password_hash'])) {
            http_response_code(400);
            echo json_encode([
                'error'   => 'INCORRECT_PASSWORD',
                'message' => 'La contraseña actual no es correcta.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $newHash = CryptoService::hashPassword($newPassword);
        $upd = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $upd->execute([$newHash, $user['id']]);

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $aud = $pdo->prepare('
            INSERT INTO audit_logs (user_id, action, resource_type, resource_id, details_json, ip_address)
            VALUES (?, "PASSWORD_CHANGED", "USER", ?, "{}", ?)
        ');
        $aud->execute([$user['id'], (string)$user['id'], $ip]);

        echo json_encode([
            'success' => true,
            'message' => 'Contraseña actualizada correctamente.'
        ], JSON_UNESCAPED_UNICODE);
    }
}
