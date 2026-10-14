<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * Authentication Middleware (JWT Bearer Token Validator)
 */

namespace NexusAcademic\Middleware;

use Database;
use CryptoService;
use NexusAcademic\Core\Request;
use NexusAcademic\Core\Response;

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Services/CryptoService.php';

class AuthMiddleware {

    /**
     * Authenticates the incoming request via Bearer token
     *
     * @param Request|null $request Optional request object to bind user context
     * @return array The authenticated user record with role extensions
     */
    public static function authenticate(?Request $request = null): array {
        $authHeader = null;

        if ($request !== null) {
            $authHeader = $request->getHeader('Authorization');
        }

        if (!$authHeader) {
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
        }

        if (!$authHeader || !preg_match('/^Bearer\s+(.*)$/i', $authHeader, $matches)) {
            Response::unauthorized('Token de autorización ausente o formato inválido (debe ser Bearer <token>).');
        }

        $token = trim($matches[1]);
        $payload = CryptoService::verifyToken($token);

        if (!$payload || empty($payload['id'])) {
            Response::unauthorized('El token de sesión es inválido o ha expirado.');
        }

        // Verify active user in MySQL database
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id, email, first_name, last_name, role, status FROM users WHERE id = ?');
        $stmt->execute([(int)$payload['id']]);
        $user = $stmt->fetch();

        if (!$user || $user['status'] !== 'ACTIVE') {
            Response::unauthorized('El usuario no existe o se encuentra inactivo.');
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

        $userData = [
            'id'        => (int)$user['id'],
            'email'     => $user['email'],
            'firstName' => $user['first_name'],
            'lastName'  => $user['last_name'],
            'role'      => $user['role'],
            'teacherId' => $teacherId,
            'studentId' => $studentId
        ];

        if ($request !== null) {
            $request->setUser($userData);
        }

        return $userData;
    }

    /**
     * Middleware handler for Router pipeline
     */
    public static function handle(): callable {
        return function (Request $request) {
            self::authenticate($request);
        };
    }
}
