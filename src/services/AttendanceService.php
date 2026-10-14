<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * Attendance & Live QR Cryptography Business Logic Service
 * Anti-Replay Nonce Verification, Geopresence & WebRTC Scanners
 */

namespace NexusAcademic\Services;

use Database;
use CryptoService;
use NexusAcademic\Models\AttendanceModel;
use NexusAcademic\Models\AcademicModel;
use NexusAcademic\Models\AuditModel;
use Exception;

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/CryptoService.php';
require_once __DIR__ . '/../Models/AttendanceModel.php';
require_once __DIR__ . '/../Models/AcademicModel.php';
require_once __DIR__ . '/../Models/AuditModel.php';

class AttendanceService {

    public static function startSession(array $user, array $data, ?string $ip = null): array {
        $groupId         = (int)($data['group_id'] ?? 0);
        $topic           = trim($data['topic'] ?? '');
        $geoLat          = isset($data['geo_lat']) ? (float)$data['geo_lat'] : null;
        $geoLng          = isset($data['geo_lng']) ? (float)$data['geo_lng'] : null;
        $geoRadiusMeters = isset($data['geo_radius_meters']) ? (float)$data['geo_radius_meters'] : null;

        if (!$groupId || empty($topic)) {
            throw new Exception('group_id y topic son obligatorios.', 400);
        }

        $group = AcademicModel::getGroupById($groupId);
        if (!$group) {
            throw new Exception('Grupo no encontrado.', 404);
        }

        if ($user['role'] === 'TEACHER' && (int)$group['teacher_id'] !== (int)$user['teacherId']) {
            throw new Exception('No es el docente asignado a este grupo.', 403);
        }

        // Close previous active sessions
        AttendanceModel::closeActiveSessions($groupId);

        $sessionSecret = bin2hex(random_bytes(32));
        $finalLat = $geoLat !== null ? $geoLat : ($group['geo_lat'] ? (float)$group['geo_lat'] : 4.6097);
        $finalLng = $geoLng !== null ? $geoLng : ($group['geo_lng'] ? (float)$group['geo_lng'] : -74.0817);
        $finalRadius = $geoRadiusMeters !== null ? $geoRadiusMeters : ($group['geo_radius_meters'] ? (float)$group['geo_radius_meters'] : 100.0);

        $sessionId = AttendanceModel::createSession([
            'group_id'          => $groupId,
            'teacher_id'        => $group['teacher_id'],
            'topic'             => $topic,
            'session_secret'    => $sessionSecret,
            'geo_lat'           => $finalLat,
            'geo_lng'           => $finalLng,
            'geo_radius_meters' => $finalRadius
        ]);

        $initialQR = CryptoService::generateQRPayload($sessionId, $sessionSecret);

        AuditModel::log((int)$user['id'], 'SESSION_STARTED', 'CLASS_SESSION', (string)$sessionId, [
            'groupId' => $groupId,
            'topic'   => $topic
        ], $ip);

        return [
            'success'   => true,
            'sessionId' => $sessionId,
            'topic'     => $topic,
            'startedAt' => date('c'),
            'qrPayload' => $initialQR
        ];
    }

    public static function getSessionQRToken(array $user, int $sessionId): array {
        $session = AttendanceModel::getSessionById($sessionId);
        if (!$session) {
            throw new Exception('Sesión no encontrada.', 404);
        }

        if ($session['status'] !== 'ACTIVE') {
            throw new Exception('La sesión se encuentra cerrada o finalizada.', 400);
        }

        if ($user['role'] === 'TEACHER' && (int)$session['teacher_id'] !== (int)$user['teacherId']) {
            throw new Exception('No es el docente de esta sesión.', 403);
        }

        $qrPayload = CryptoService::generateQRPayload($sessionId, $session['session_secret']);
        return [
            'success'   => true,
            'sessionId' => $sessionId,
            'qrPayload' => $qrPayload
        ];
    }

    public static function closeSession(array $user, int $sessionId, ?string $ip = null): void {
        $session = AttendanceModel::getSessionById($sessionId);
        if (!$session) {
            throw new Exception('Sesión no encontrada.', 404);
        }

        if ($user['role'] === 'TEACHER' && (int)$session['teacher_id'] !== (int)$user['teacherId']) {
            throw new Exception('No tiene permisos para cerrar esta sesión.', 403);
        }

        AttendanceModel::closeSession($sessionId);
        AuditModel::log((int)$user['id'], 'SESSION_CLOSED', 'CLASS_SESSION', (string)$sessionId, null, $ip);
    }

    public static function registerAttendance(array $user, array $data, ?string $ip = null): array {
        $studentId = (int)($user['studentId'] ?? 0);
        if (!$studentId) {
            throw new Exception('Perfil de estudiante no encontrado para este usuario.', 403);
        }

        $rawPayload = $data['payload'] ?? null;
        if (empty($rawPayload)) {
            throw new Exception('El código QR o payload escaneado es obligatorio.', 400);
        }

        $parsed = is_array($rawPayload) ? $rawPayload : json_decode($rawPayload, true);
        if (!$parsed || empty($parsed['sessionId']) || empty($parsed['timestamp']) || empty($parsed['signature'])) {
            throw new Exception('Estructura de código QR inválida o corrupta.', 400);
        }

        $sessionId = (int)$parsed['sessionId'];
        $session = AttendanceModel::getSessionById($sessionId);
        if (!$session || $session['status'] !== 'ACTIVE') {
            throw new Exception('La sesión ya ha finalizado o no existe.', 400);
        }

        // Validate Student Enrollment
        if (!AcademicModel::isStudentEnrolled((int)$session['group_id'], $studentId)) {
            throw new Exception('Usted no está matriculado en la asignatura correspondiente a esta sesión.', 403);
        }

        // Check if already registered
        if (AttendanceModel::hasStudentAttended($sessionId, $studentId)) {
            throw new Exception('Ya has registrado tu asistencia para esta sesión de clase.', 409);
        }

        // Verify Cryptographic Signature
        $isValidSig = CryptoService::verifyQRPayload($parsed, $session['session_secret']);
        if (!$isValidSig) {
            throw new Exception('Firma criptográfica del código QR inválida. Posible código falsificado.', 400);
        }

        // Check Time Window (e.g. 60 seconds tolerance)
        $now = time();
        $age = $now - (int)$parsed['timestamp'];
        if ($age < -10 || $age > 60) {
            throw new Exception('El código QR ha expirado. Por favor escanee el código dinámico actual proyectado.', 400);
        }

        // Geopresence Validation
        $isFlagged = false;
        $flagReason = null;
        $studentLat = isset($data['geo_lat']) ? (float)$data['geo_lat'] : null;
        $studentLng = isset($data['geo_lng']) ? (float)$data['geo_lng'] : null;

        if ($session['geo_lat'] !== null && $session['geo_lng'] !== null) {
            if ($studentLat !== null && $studentLng !== null) {
                $distanceMeters = CryptoService::calculateHaversineDistance(
                    (float)$session['geo_lat'], (float)$session['geo_lng'],
                    $studentLat, $studentLng
                );
                $allowedRadius = (float)($session['geo_radius_meters'] ?? 100.0);
                if ($distanceMeters > $allowedRadius) {
                    $isFlagged = true;
                    $flagReason = sprintf('Fuera del radio permitido (%.1fm de distancia, máx %.1fm)', $distanceMeters, $allowedRadius);
                }
            } else {
                $isFlagged = true;
                $flagReason = 'Ubicación GPS no proporcionada por el dispositivo del estudiante';
            }
        }

        $attendanceId = AttendanceModel::recordAttendance([
            'session_id'          => $sessionId,
            'student_id'          => $studentId,
            'verification_method' => $data['verification_method'] ?? 'DYNAMIC_QR',
            'device_fingerprint'  => $data['device_fingerprint'] ?? null,
            'ip_address'          => $ip,
            'geo_lat'             => $studentLat,
            'geo_lng'             => $studentLng,
            'is_flagged'          => $isFlagged,
            'flag_reason'         => $flagReason
        ]);

        AuditModel::log((int)$user['id'], 'ATTENDANCE_REGISTERED', 'ATTENDANCE', (string)$attendanceId, [
            'session_id' => $sessionId,
            'flagged'    => $isFlagged
        ], $ip);

        return [
            'success'      => true,
            'attendanceId' => $attendanceId,
            'session'      => [
                'topic'       => $session['topic'],
                'groupCode'   => $session['group_code'],
                'subjectName' => $session['subject_name']
            ],
            'isFlagged'    => $isFlagged,
            'flagReason'   => $flagReason
        ];
    }

    public static function getSessionAttendance(array $user, int $sessionId): array {
        $session = AttendanceModel::getSessionById($sessionId);
        if (!$session) {
            throw new Exception('Sesión no encontrada.', 404);
        }

        if ($user['role'] === 'TEACHER' && (int)$session['teacher_id'] !== (int)$user['teacherId']) {
            throw new Exception('No tiene permisos para ver esta lista de asistencia.', 403);
        }

        return AttendanceModel::getSessionAttendances($sessionId);
    }

    public static function getMyAttendanceHistory(array $user): array {
        $studentId = (int)($user['studentId'] ?? 0);
        return AttendanceModel::getMyAttendanceHistory($studentId);
    }
}
