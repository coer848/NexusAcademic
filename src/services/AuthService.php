<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * Authentication & Identity Business Logic Service
 * Zero Trust Security & OWASP Compliant
 */

namespace NexusAcademic\Services;

use Database;
use CryptoService;
use NexusAcademic\Models\UserModel;
use NexusAcademic\Models\AcademicModel;
use NexusAcademic\Models\AuditModel;
use Exception;

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/CryptoService.php';
require_once __DIR__ . '/../Models/UserModel.php';
require_once __DIR__ . '/../Models/AcademicModel.php';
require_once __DIR__ . '/../Models/AuditModel.php';

class AuthService {

    public static function register(array $data, ?string $clientIp = null): array {
        $email      = strtolower(trim($data['email'] ?? ''));
        $password   = $data['password'] ?? '';
        $firstName  = trim($data['first_name'] ?? '');
        $lastName   = trim($data['last_name'] ?? '');
        $phone      = trim($data['phone'] ?? '');
        $role       = strtoupper(trim($data['role'] ?? ''));
        $code       = trim($data['code'] ?? '');
        $career     = trim($data['career'] ?? '');
        $department = trim($data['department'] ?? '');
        $specialty  = trim($data['specialty'] ?? '');
        $semester   = isset($data['semester']) ? (int)$data['semester'] : 1;

        if (empty($email) || empty($password) || empty($firstName) || empty($lastName) || empty($role)) {
            throw new Exception('Nombre, apellido, correo, contraseña y rol son obligatorios.', 400);
        }

        if (strlen($password) < 6) {
            throw new Exception('La contraseña debe tener al menos 6 caracteres.', 400);
        }

        $validRoles = ['ADMIN', 'TEACHER', 'STUDENT'];
        if (!in_array($role, $validRoles, true)) {
            throw new Exception('El rol debe ser ADMIN, TEACHER o STUDENT.', 400);
        }

        if (UserModel::findByEmail($email)) {
            throw new Exception('El correo electrónico ya se encuentra registrado.', 409);
        }

        $passwordHash = CryptoService::hashPassword($password);

        $result = Database::transaction(function (\PDO $db) use ($email, $passwordHash, $firstName, $lastName, $phone, $role, $code, $department, $specialty, $career, $semester, $clientIp) {
            $userId = UserModel::createUser($db, $email, $passwordHash, $firstName, $lastName, !empty($phone) ? $phone : null, $role);

            $teacherProfile = null;
            $studentProfile = null;

            if ($role === 'TEACHER') {
                $teacherCode = !empty($code) ? strtoupper($code) : 'DOC-' . rand(100, 999);
                $tDept = !empty($department) ? $department : 'Facultad de Ingeniería & Tecnología';
                $tSpec = !empty($specialty) ? $specialty : 'Docencia Universitaria';
                $tId = UserModel::createTeacherProfile($db, $userId, $teacherCode, $tDept, $tSpec);
                $teacherProfile = ['id' => $tId, 'teacher_code' => $teacherCode, 'department' => $tDept];
            } elseif ($role === 'STUDENT') {
                $studentCode = !empty($code) ? strtoupper($code) : 'EST-' . date('Y') . '-' . rand(1000, 9999);
                $sCareer = !empty($career) ? $career : 'Ingeniería de Software & Computación';
                $sId = UserModel::createStudentProfile($db, $userId, $studentCode, $sCareer, $semester);
                $studentProfile = ['id' => $sId, 'student_code' => $studentCode, 'career' => $sCareer, 'semester' => $semester];
            }

            // Generate JWT Token
            $tokenPayload = ['id' => $userId, 'email' => $email, 'role' => $role];
            if ($teacherProfile) $tokenPayload['teacherId'] = $teacherProfile['id'];
            if ($studentProfile) $tokenPayload['studentId'] = $studentProfile['id'];

            $token = CryptoService::signToken($tokenPayload);

            // Audit
            AuditModel::log($userId, 'USER_REGISTERED', 'USER', (string)$userId, ['role' => $role, 'email' => $email], $clientIp);

            return [
                'token' => $token,
                'user'  => [
                    'id'             => $userId,
                    'email'          => $email,
                    'firstName'      => $firstName,
                    'lastName'       => $lastName,
                    'phone'          => $phone,
                    'role'           => $role,
                    'teacherProfile' => $teacherProfile,
                    'studentProfile' => $studentProfile
                ]
            ];
        });

        return $result;
    }

    public static function login(string $email, string $password, ?string $clientIp = null): array {
        $cleanEmail = strtolower(trim($email));
        if (empty($cleanEmail) || empty($password)) {
            throw new Exception('Correo electrónico y contraseña requeridos.', 400);
        }

        $user = UserModel::findByEmail($cleanEmail);
        if (!$user) {
            throw new Exception('Credenciales inválidas. Verifique correo o contraseña.', 401);
        }

        if ($user['status'] !== 'ACTIVE') {
            throw new Exception('La cuenta se encuentra inactiva o deshabilitada. Contacte a la administración.', 403);
        }

        if (!CryptoService::verifyPassword($password, $user['password_hash'])) {
            throw new Exception('Credenciales inválidas. Verifique correo o contraseña.', 401);
        }

        // Load profile IDs
        $teacherProfile = null;
        $studentProfile = null;

        if ($user['role'] === 'TEACHER') {
            $teacherProfile = UserModel::getTeacherByUserId((int)$user['id']);
        } elseif ($user['role'] === 'STUDENT') {
            $studentProfile = UserModel::getStudentByUserId((int)$user['id']);
        }

        $tokenPayload = [
            'id'    => (int)$user['id'],
            'email' => $user['email'],
            'role'  => $user['role']
        ];
        if ($teacherProfile) $tokenPayload['teacherId'] = (int)$teacherProfile['id'];
        if ($studentProfile) $tokenPayload['studentId'] = (int)$studentProfile['id'];

        $token = CryptoService::signToken($tokenPayload);

        AuditModel::log((int)$user['id'], 'LOGIN_SUCCESS', 'AUTH', (string)$user['id'], ['role' => $user['role']], $clientIp);

        return [
            'token' => $token,
            'user'  => [
                'id'             => (int)$user['id'],
                'email'          => $user['email'],
                'firstName'      => $user['first_name'],
                'lastName'       => $user['last_name'],
                'phone'          => $user['phone'] ?? '',
                'role'           => $user['role'],
                'teacherProfile' => $teacherProfile,
                'studentProfile' => $studentProfile
            ]
        ];
    }

    public static function getMe(array $user): array {
        $dbUser = UserModel::findActiveById((int)$user['id']);
        if (!$dbUser) {
            throw new Exception('Usuario no encontrado o inactivo.', 404);
        }

        $teacherProfile = null;
        $studentProfile = null;

        if ($dbUser['role'] === 'TEACHER') {
            $teacherProfile = UserModel::getTeacherByUserId((int)$dbUser['id']);
        } elseif ($dbUser['role'] === 'STUDENT') {
            $studentProfile = UserModel::getStudentByUserId((int)$dbUser['id']);
        }

        $institution = AcademicModel::getInstitution();

        return [
            'user' => [
                'id'             => (int)$dbUser['id'],
                'email'          => $dbUser['email'],
                'firstName'      => $dbUser['first_name'],
                'lastName'       => $dbUser['last_name'],
                'phone'          => $dbUser['phone'] ?? '',
                'role'           => $dbUser['role'],
                'status'         => $dbUser['status'],
                'createdAt'      => $dbUser['created_at'],
                'teacherProfile' => $teacherProfile,
                'studentProfile' => $studentProfile
            ],
            'institution' => $institution
        ];
    }

    public static function changePassword(array $user, string $currentPassword, string $newPassword, ?string $clientIp = null): void {
        if (empty($currentPassword) || empty($newPassword)) {
            throw new Exception('Debe suministrar la contraseña actual y la nueva contraseña.', 400);
        }

        if (strlen($newPassword) < 6) {
            throw new Exception('La nueva contraseña debe tener al menos 6 caracteres.', 400);
        }

        $dbUser = UserModel::findById((int)$user['id']);
        if (!$dbUser) {
            throw new Exception('Usuario no encontrado.', 404);
        }

        if (!CryptoService::verifyPassword($currentPassword, $dbUser['password_hash'])) {
            throw new Exception('La contraseña actual suministrada no es correcta.', 401);
        }

        $newHash = CryptoService::hashPassword($newPassword);
        UserModel::updatePassword((int)$user['id'], $newHash);

        AuditModel::log((int)$user['id'], 'PASSWORD_CHANGED', 'AUTH', (string)$user['id'], null, $clientIp);
    }

    public static function updateProfile(array $user, array $data, ?string $clientIp = null): array {
        $firstName = trim($data['first_name'] ?? '');
        $lastName  = trim($data['last_name'] ?? '');
        $phone     = trim($data['phone'] ?? '');

        if (empty($firstName) || empty($lastName)) {
            throw new Exception('El nombre y el apellido son obligatorios.', 400);
        }

        UserModel::updateProfile((int)$user['id'], $firstName, $lastName, !empty($phone) ? $phone : null);
        AuditModel::log((int)$user['id'], 'PROFILE_UPDATED', 'USER', (string)$user['id'], ['phone' => $phone], $clientIp);

        return self::getMe($user);
    }

    public static function deleteAccount(array $user, string $password, ?string $clientIp = null): void {
        if (empty($password)) {
            throw new Exception('Debe ingresar su contraseña actual para confirmar la eliminación de la cuenta.', 400);
        }

        $dbUser = UserModel::findById((int)$user['id']);
        if (!$dbUser) {
            throw new Exception('Usuario no encontrado.', 404);
        }

        if (!CryptoService::verifyPassword($password, $dbUser['password_hash'])) {
            throw new Exception('Contraseña incorrecta. No se puede proceder con la eliminación.', 401);
        }

        UserModel::deleteAccount((int)$user['id'], $dbUser['role']);
        AuditModel::log((int)$user['id'], 'ACCOUNT_DELETED', 'USER', (string)$user['id'], ['email' => $dbUser['email'], 'role' => $dbUser['role']], $clientIp);
    }
}
