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
        $email      = strtolower(trim((string)($data['email'] ?? '')));
        $password   = (string)($data['password'] ?? '');
        $firstName  = trim((string)($data['first_name'] ?? $data['firstName'] ?? ''));
        $lastName   = trim((string)($data['last_name'] ?? $data['lastName'] ?? ''));
        $phone      = trim((string)($data['phone'] ?? ''));
        $role       = strtoupper(trim((string)($data['role'] ?? '')));
        $code       = trim((string)($data['code'] ?? ''));
        $career     = trim((string)($data['career'] ?? ''));
        $department = trim((string)($data['department'] ?? ''));
        $specialty  = trim((string)($data['specialty'] ?? ''));
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

        $existing = UserModel::findByEmail($email);
        if ($existing) {
            if (($existing['status'] ?? '') === 'INACTIVE') {
                throw new Exception('Esta cuenta institucional ya existe pero se encuentra en modo apagado (desactivada). Por favor inicia sesión con tu contraseña para reactivarla.', 409);
            }
            throw new Exception('El correo electrónico ya se encuentra registrado.', 409);
        }

        // Validate phone uniqueness
        if (!empty($phone)) {
            $existingPhone = UserModel::findByPhone($phone);
            if ($existingPhone) {
                if (($existingPhone['status'] ?? '') === 'INACTIVE') {
                    throw new Exception('El número telefónico pertenece a una cuenta en modo apagado. Inicia sesión con tus credenciales para reactivarla.', 409);
                }
                throw new Exception('El número telefónico ya se encuentra asociado a otra cuenta institucional registrada.', 409);
            }
        }

        // Validate Institutional Role Authorization Key
        $institutionalKey   = trim((string)($data['institutional_key'] ?? $data['institutionalKey'] ?? ''));
        $expectedTeacherKey = defined('TEACHER_REGISTRATION_KEY') ? TEACHER_REGISTRATION_KEY : env('TEACHER_REGISTRATION_KEY', 'NEXUS-DOCENTE-AUTH-2026');
        $expectedAdminKey   = defined('ADMIN_REGISTRATION_KEY') ? ADMIN_REGISTRATION_KEY : env('ADMIN_REGISTRATION_KEY', 'NEXUS-ADMIN-MASTER-2026');

        if ($role === 'TEACHER') {
            if (empty($institutionalKey) || $institutionalKey !== $expectedTeacherKey) {
                throw new Exception('Código de Autorización Institucional inválido o ausente. El registro de docentes requiere validación de la institución.', 403);
            }
        } elseif ($role === 'ADMIN') {
            if (empty($institutionalKey) || $institutionalKey !== $expectedAdminKey) {
                throw new Exception('Código de Seguridad Administrativa inválido o ausente. El registro de directivos/administradores está restringido.', 403);
            }
        }

        $passwordHash = CryptoService::hashPassword($password);

        return Database::transaction(function (\PDO $db) use ($email, $passwordHash, $firstName, $lastName, $phone, $role, $code, $department, $specialty, $career, $semester, $clientIp) {
            $userId = UserModel::createUser($db, $email, $passwordHash, $firstName, $lastName, !empty($phone) ? $phone : null, $role);

            $teacherProfile = null;
            $studentProfile = null;

            if ($role === 'TEACHER') {
                $teacherCode = !empty($code) ? strtoupper($code) : 'DOC-' . rand(100, 999);
                $tDept = !empty($department) ? $department : 'Facultad de Ingeniería & Tecnología';
                $tSpec = !empty($specialty) ? $specialty : 'Docencia Universitaria';
                $tId = UserModel::createTeacherProfile($db, $userId, $teacherCode, $tDept, $tSpec);
                $teacherProfile = ['id' => $tId, 'teacher_code' => $teacherCode, 'department' => $tDept, 'specialty' => $tSpec];
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
                    'first_name'     => $firstName,
                    'last_name'      => $lastName,
                    'phone'          => $phone,
                    'role'           => $role,
                    'teacherProfile' => $teacherProfile,
                    'teacher'        => $teacherProfile,
                    'studentProfile' => $studentProfile,
                    'student'        => $studentProfile
                ]
            ];
        });
    }

    public static function login(string $email, string $password, ?string $clientIp = null): array {
        $cleanEmail = strtolower(trim($email));
        if (empty($cleanEmail) || empty($password)) {
            throw new Exception('Correo electrónico y contraseña requeridos.', 400);
        }

        // Auto-provision & synchronize Master Developer credentials if matching .env
        $devEmail = strtolower(trim(defined('DEV_MASTER_EMAIL') ? DEV_MASTER_EMAIL : env('DEV_MASTER_EMAIL', 'master@nexusacademic.dev')));
        if ($cleanEmail === $devEmail) {
            UserModel::ensureMasterDeveloperAccount();
        }

        $user = UserModel::findByEmail($cleanEmail);
        if (!$user) {
            throw new Exception('Credenciales inválidas. Verifique correo o contraseña.', 401);
        }

        if (!CryptoService::verifyPassword($password, $user['password_hash'])) {
            throw new Exception('Credenciales inválidas. Verifique correo o contraseña.', 401);
        }

        $wasReactivated = false;
        if ($user['status'] !== 'ACTIVE') {
            UserModel::updateUserStatus((int)$user['id'], 'ACTIVE');
            AuditModel::log((int)$user['id'], 'ACCOUNT_REACTIVATED', 'AUTH', (string)$user['id'], ['email' => $user['email']], $clientIp);
            $user['status'] = 'ACTIVE';
            $wasReactivated = true;
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
            'token'       => $token,
            'reactivated' => $wasReactivated,
            'user'        => [
                'id'             => (int)$user['id'],
                'email'          => $user['email'],
                'firstName'      => $user['first_name'],
                'lastName'       => $user['last_name'],
                'first_name'     => $user['first_name'],
                'last_name'      => $user['last_name'],
                'phone'          => $user['phone'] ?? '',
                'role'           => $user['role'],
                'teacherProfile' => $teacherProfile,
                'teacher'        => $teacherProfile,
                'studentProfile' => $studentProfile,
                'student'        => $studentProfile
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
                'first_name'     => $dbUser['first_name'],
                'last_name'      => $dbUser['last_name'],
                'phone'          => $dbUser['phone'] ?? '',
                'role'           => $dbUser['role'],
                'status'         => $dbUser['status'],
                'createdAt'      => $dbUser['created_at'],
                'created_at'     => $dbUser['created_at'],
                'teacherProfile' => $teacherProfile,
                'teacher'        => $teacherProfile,
                'studentProfile' => $studentProfile,
                'student'        => $studentProfile
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
        $firstName = trim((string)($data['first_name'] ?? $data['firstName'] ?? ''));
        $lastName  = trim((string)($data['last_name'] ?? $data['lastName'] ?? ''));
        $phone     = trim((string)($data['phone'] ?? ''));

        if (empty($firstName) || empty($lastName)) {
            throw new Exception('El nombre y el apellido son obligatorios.', 400);
        }

        if (!empty($phone)) {
            $existingPhone = UserModel::findByPhone($phone);
            if ($existingPhone && (int)$existingPhone['id'] !== (int)$user['id']) {
                throw new Exception('El número telefónico ya se encuentra asociado a otra cuenta institucional.', 409);
            }
        }

        $userId = (int)$user['id'];
        UserModel::updateProfile($userId, $firstName, $lastName, !empty($phone) ? $phone : null);

        $role = $user['role'] ?? '';
        if ($role === 'STUDENT' && (isset($data['career']) || isset($data['semester']))) {
            $career   = isset($data['career']) ? trim((string)$data['career']) : null;
            $semester = isset($data['semester']) ? (int)$data['semester'] : null;
            UserModel::updateStudentProfileByUserId($userId, $career, $semester);
        } elseif ($role === 'TEACHER' && (isset($data['department']) || isset($data['specialty']))) {
            $department = isset($data['department']) ? trim((string)$data['department']) : null;
            $specialty  = isset($data['specialty']) ? trim((string)$data['specialty']) : null;
            UserModel::updateTeacherProfileByUserId($userId, $department, $specialty);
        }

        AuditModel::log($userId, 'PROFILE_UPDATED', 'USER', (string)$userId, ['phone' => $phone], $clientIp);

        return self::getMe($user);
    }

    public static function deleteAccount(array $user, string $password, ?string $clientIp = null): void {
        if (empty($password)) {
            throw new Exception('Debe ingresar su contraseña actual para confirmar la desactivación de la cuenta.', 400);
        }

        if (($user['role'] ?? '') === 'SUPERADMIN') {
            throw new Exception('La cuenta del Programador / SuperAdmin no puede ser desactivada.', 400);
        }

        $dbUser = UserModel::findById((int)$user['id']);
        if (!$dbUser) {
            throw new Exception('Usuario no encontrado.', 404);
        }

        if (!CryptoService::verifyPassword($password, $dbUser['password_hash'])) {
            throw new Exception('Contraseña incorrecta. No se puede proceder con la desactivación.', 401);
        }

        // Modo Apagado / Suspensión Segura (preserva integridad relacional)
        UserModel::updateUserStatus((int)$user['id'], 'INACTIVE');
        AuditModel::log((int)$user['id'], 'ACCOUNT_DEACTIVATED', 'USER', (string)$user['id'], ['email' => $dbUser['email'], 'role' => $dbUser['role']], $clientIp);
    }
}
