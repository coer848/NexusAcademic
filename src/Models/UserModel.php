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
}
