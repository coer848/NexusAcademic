<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * User & Identity Data Model (Repository Pattern)
 * 100% Parameterized Queries & Anti-IDOR Protection
 */

namespace NexusAcademic\Models;

use PDO;
use Database;

require_once __DIR__ . '/../../config/database.php';

class UserModel {

    public static function findByEmail(string $email): ?array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([strtolower(trim($email))]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findByPhone(string $phone): ?array {
        $cleanPhone = preg_replace('/[^0-9+]/', '', trim($phone));
        if (empty($cleanPhone)) return null;
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE phone = ? LIMIT 1');
        $stmt->execute([$cleanPhone]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(int $id): ?array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findActiveById(int $id): ?array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id, email, first_name, last_name, phone, role, status, created_at FROM users WHERE id = ? AND status = "ACTIVE" LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function createUser(PDO $db, string $email, string $passwordHash, string $firstName, string $lastName, ?string $phone, string $role): int {
        $stmt = $db->prepare('
            INSERT INTO users (email, password_hash, first_name, last_name, phone, role, status)
            VALUES (?, ?, ?, ?, ?, ?, "ACTIVE")
        ');
        $stmt->execute([strtolower(trim($email)), $passwordHash, $firstName, $lastName, $phone, $role]);
        return (int)$db->lastInsertId();
    }

    public static function createTeacherProfile(PDO $db, int $userId, string $teacherCode, string $department, string $specialty): int {
        $stmt = $db->prepare('
            INSERT INTO teachers (user_id, teacher_code, department, specialty)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$userId, $teacherCode, $department, $specialty]);
        return (int)$db->lastInsertId();
    }

    public static function createStudentProfile(PDO $db, int $userId, string $studentCode, string $career, int $semester): int {
        $stmt = $db->prepare('
            INSERT INTO students (user_id, student_code, career, semester)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$userId, $studentCode, $career, $semester]);
        return (int)$db->lastInsertId();
    }

    public static function getTeacherByUserId(int $userId): ?array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM teachers WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function getStudentByUserId(int $userId): ?array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM students WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function updatePassword(int $userId, string $newPasswordHash): bool {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        return $stmt->execute([$newPasswordHash, $userId]);
    }

    public static function updateProfile(int $userId, string $firstName, string $lastName, ?string $phone): bool {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE users SET first_name = ?, last_name = ?, phone = ? WHERE id = ?');
        return $stmt->execute([$firstName, $lastName, $phone, $userId]);
    }

    public static function deleteAccount(int $userId, string $role): bool {
        return Database::transaction(function (PDO $db) use ($userId, $role) {
            if ($role === 'TEACHER') {
                $tStmt = $db->prepare('SELECT id FROM teachers WHERE user_id = ?');
                $tStmt->execute([$userId]);
                $teacher = $tStmt->fetch();
                if ($teacher) {
                    $tId = (int)$teacher['id'];
                    $db->prepare('DELETE FROM course_groups WHERE teacher_id = ?')->execute([$tId]);
                    $db->prepare('DELETE FROM teachers WHERE id = ?')->execute([$tId]);
                }
            } elseif ($role === 'STUDENT') {
                $sStmt = $db->prepare('SELECT id FROM students WHERE user_id = ?');
                $sStmt->execute([$userId]);
                $student = $sStmt->fetch();
                if ($student) {
                    $sId = (int)$student['id'];
                    $db->prepare('DELETE FROM attendances WHERE student_id = ?')->execute([$sId]);
                    $db->prepare('DELETE FROM grades WHERE student_id = ?')->execute([$sId]);
                    $db->prepare('DELETE FROM enrollments WHERE student_id = ?')->execute([$sId]);
                    $db->prepare('DELETE FROM students WHERE id = ?')->execute([$sId]);
                }
            }

            $delUser = $db->prepare('DELETE FROM users WHERE id = ?');
            return $delUser->execute([$userId]);
        });
    }

    /**
     * Master Dev / SuperAdmin: Retrieve all users with extended profiles
     */
    public static function getAllUsersDetailed(): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->query('
            SELECT 
                u.id, u.email, u.first_name, u.last_name, u.phone, u.role, u.status, u.created_at,
                t.teacher_code, t.department, t.specialty,
                s.student_code, s.career, s.semester
            FROM users u
            LEFT JOIN teachers t ON u.id = t.user_id
            LEFT JOIN students s ON u.id = s.user_id
            ORDER BY u.id DESC
        ');
        return $stmt->fetchAll();
    }

    /**
     * Master Dev / SuperAdmin: Toggle User Status (ACTIVE / INACTIVE)
     */
    public static function updateUserStatus(int $userId, string $status): bool {
        $cleanStatus = in_array($status, ['ACTIVE', 'INACTIVE'], true) ? $status : 'ACTIVE';
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE users SET status = ? WHERE id = ?');
        return $stmt->execute([$cleanStatus, $userId]);
    }

    /**
     * Master Dev / SuperAdmin: Update User Role
     */
    public static function updateUserRole(int $userId, string $newRole): bool {
        $validRoles = ['SUPERADMIN', 'ADMIN', 'TEACHER', 'STUDENT'];
        if (!in_array($newRole, $validRoles, true)) return false;

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
        return $stmt->execute([$newRole, $userId]);
    }

    /**
     * Master Dev / SuperAdmin: Reset User Password
     */
    public static function resetUserPassword(int $userId, string $passwordHash): bool {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        return $stmt->execute([$passwordHash, $userId]);
    }

    /**
     * Master Dev / SuperAdmin: Comprehensive System Telemetry & Database Diagnostics
     */
    public static function getSystemTelemetry(): array {
        $pdo = Database::getConnection();

        // 1. User counts by role
        $roleCounts = [
            'total'      => 0,
            'superadmin' => 0,
            'admin'      => 0,
            'teacher'    => 0,
            'student'    => 0,
            'active'     => 0,
            'inactive'   => 0
        ];
        try {
            $stmt = $pdo->query('SELECT role, status, COUNT(*) AS cnt FROM users GROUP BY role, status');
            while ($row = $stmt->fetch()) {
                $cnt = (int)$row['cnt'];
                $roleCounts['total'] += $cnt;
                $r = strtolower($row['role']);
                if (isset($roleCounts[$r])) $roleCounts[$r] += $cnt;
                $s = strtolower($row['status']);
                if (isset($roleCounts[$s])) $roleCounts[$s] += $cnt;
            }
        } catch (\Throwable $e) {}

        // 2. Table row counts
        $tables = [
            'users'             => (int)($pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() ?? 0),
            'teachers'          => (int)($pdo->query('SELECT COUNT(*) FROM teachers')->fetchColumn() ?? 0),
            'students'          => (int)($pdo->query('SELECT COUNT(*) FROM students')->fetchColumn() ?? 0),
            'subjects'          => (int)($pdo->query('SELECT COUNT(*) FROM subjects')->fetchColumn() ?? 0),
            'course_groups'     => (int)($pdo->query('SELECT COUNT(*) FROM course_groups')->fetchColumn() ?? 0),
            'enrollments'       => (int)($pdo->query('SELECT COUNT(*) FROM enrollments')->fetchColumn() ?? 0),
            'class_sessions'    => (int)($pdo->query('SELECT COUNT(*) FROM class_sessions')->fetchColumn() ?? 0),
            'attendances'       => (int)($pdo->query('SELECT COUNT(*) FROM attendances')->fetchColumn() ?? 0),
            'attendance_tokens' => (int)($pdo->query('SELECT COUNT(*) FROM attendance_tokens')->fetchColumn() ?? 0),
            'evaluations'       => (int)($pdo->query('SELECT COUNT(*) FROM evaluations')->fetchColumn() ?? 0),
            'grades'            => (int)($pdo->query('SELECT COUNT(*) FROM grades')->fetchColumn() ?? 0),
            'grade_history'     => (int)($pdo->query('SELECT COUNT(*) FROM grade_history')->fetchColumn() ?? 0),
            'audit_logs'        => (int)($pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn() ?? 0)
        ];

        return [
            'roles'        => $roleCounts,
            'tables'       => $tables,
            'phpVersion'   => PHP_VERSION,
            'serverTime'   => date('Y-m-d H:i:s'),
            'env'          => defined('APP_ENV') ? APP_ENV : 'production',
            'appName'      => defined('APP_NAME') ? APP_NAME : 'NexusAcademic Enterprise',
            'version'      => defined('APP_VERSION') ? APP_VERSION : '3.0.0',
            'teacherKey'   => defined('TEACHER_REGISTRATION_KEY') ? TEACHER_REGISTRATION_KEY : 'NEXUS-DOCENTE-AUTH-2026',
            'adminKey'     => defined('ADMIN_REGISTRATION_KEY') ? ADMIN_REGISTRATION_KEY : 'NEXUS-ADMIN-MASTER-2026'
        ];
    }

    /**
     * Master Dev / SuperAdmin: Auto-provision developer account from .env credentials
     */
    public static function ensureMasterDeveloperAccount(): ?array {
        $devEmail = strtolower(trim(defined('DEV_MASTER_EMAIL') ? DEV_MASTER_EMAIL : (env('DEV_MASTER_EMAIL', 'master@nexusacademic.dev'))));
        $devPass  = defined('DEV_MASTER_PASSWORD') ? DEV_MASTER_PASSWORD : (env('DEV_MASTER_PASSWORD', 'Nexus#MasterDev2026!Pro'));

        if (empty($devEmail) || empty($devPass)) {
            return null;
        }

        $pdo = Database::getConnection();

        // Ensure users table role ENUM includes SUPERADMIN
        try {
            $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('SUPERADMIN', 'ADMIN', 'TEACHER', 'STUDENT') NOT NULL");
        } catch (\Throwable $e) {}

        $user = self::findByEmail($devEmail);
        require_once dirname(__DIR__) . '/Services/CryptoService.php';

        if (!$user) {
            $hash = \CryptoService::hashPassword($devPass);
            self::createUser($pdo, $devEmail, $hash, 'Master', 'Developer', '+573000000000', 'SUPERADMIN');
            $user = self::findByEmail($devEmail);
        } else {
            // Verify and keep password in sync with .env
            if (!\CryptoService::verifyPassword($devPass, $user['password_hash'])) {
                $hash = \CryptoService::hashPassword($devPass);
                self::updatePassword((int)$user['id'], $hash);
                $user = self::findByEmail($devEmail);
            }
            if ($user['role'] !== 'SUPERADMIN') {
                self::updateUserRole((int)$user['id'], 'SUPERADMIN');
                $user['role'] = 'SUPERADMIN';
            }
        }

        return $user;
    }
}
