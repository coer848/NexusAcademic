<?php
/**
 * Authentication Middleware (JWT Bearer Token Validator)
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Services/CryptoService.php';

class AuthMiddleware {

    /**
     * Authenticates the incoming request via Bearer token
     *
     * @return array The authenticated user record with role extensions
     */
    public static function authenticate(): array {
        $authHeader = null;

        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = trim($_SERVER['HTTP_AUTHORIZATION']);
        } elseif (isset($_SERVER['Authorization'])) {
            $authHeader = trim($_SERVER['Authorization']);
        } elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['Authorization'])) {
                $authHeader = trim($headers['Authorization']);
            } elseif (isset($headers['authorization'])) {
                $authHeader = trim($headers['authorization']);
            }
        }

        if (!$authHeader || !preg_match('/^Bearer\s+(.*)$/i', $authHeader, $matches)) {
            http_response_code(401);
            echo json_encode([
                'error'   => 'AUTHENTICATION_REQUIRED',
                'message' => 'Token de autorización ausente o formato inválido (debe ser Bearer <token>).'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $token = trim($matches[1]);
        $payload = CryptoService::verifyToken($token);

        if (!$payload || empty($payload['id'])) {
            http_response_code(401);
            echo json_encode([
                'error'   => 'INVALID_OR_EXPIRED_TOKEN',
                'message' => 'El token de sesión es inválido o ha expirado.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Verify active user in MySQL database
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id, email, first_name, last_name, role, status FROM users WHERE id = ?');
        $stmt->execute([(int)$payload['id']]);
        $user = $stmt->fetch();

        if (!$user || $user['status'] !== 'ACTIVE') {
            http_response_code(401);
            echo json_encode([
                'error'   => 'USER_INACTIVE_OR_DELETED',
                'message' => 'El usuario no existe o se encuentra inactivo.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Load role-specific profile IDs
        $teacherId = null;
        $studentId = null;

        if ($user['role'] === 'TEACHER') {
            $tStmt = $pdo->prepare('SELECT id, teacher_code, department FROM teachers WHERE user_id = ?');
            $tStmt->execute([$user['id']]);
            $teacher = $tStmt->fetch();
            if ($teacher) {
                $teacherId = (int)$teacher['id'];
            }
        } elseif ($user['role'] === 'STUDENT') {
            $sStmt = $pdo->prepare('SELECT id, student_code, career, semester FROM students WHERE user_id = ?');
            $sStmt->execute([$user['id']]);
            $student = $sStmt->fetch();
            if ($student) {
                $studentId = (int)$student['id'];
            }
        }

        return [
            'id'        => (int)$user['id'],
            'email'     => $user['email'],
            'firstName' => $user['first_name'],
            'lastName'  => $user['last_name'],
            'role'      => $user['role'],
            'teacherId' => $teacherId,
            'studentId' => $studentId
        ];
    }
}
