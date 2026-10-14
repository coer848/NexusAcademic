<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * Developer Master & System SuperAdmin Controller
 * Total Visibility, User Lifecycle Management & Database Health Telemetry
 */

namespace NexusAcademic\Controllers;

use Exception;
use NexusAcademic\Core\Request;
use NexusAcademic\Core\Response;
use NexusAcademic\Models\UserModel;
use NexusAcademic\Models\AuditModel;
use NexusAcademic\Services\CryptoService;

require_once __DIR__ . '/../Models/UserModel.php';
require_once __DIR__ . '/../Models/AuditModel.php';
require_once __DIR__ . '/../Services/CryptoService.php';

class DevController {

    /**
     * GET /api/dev/overview
     * Returns full system telemetry, health, database table row counts, and auth key statuses
     */
    public static function getOverview($req): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            if (($user['role'] ?? '') !== 'SUPERADMIN' && ($user['role'] ?? '') !== 'ADMIN') {
                Response::forbidden('Acceso denegado. Se requiere nivel de Programador / SuperAdmin.');
            }

            $telemetry = UserModel::getSystemTelemetry();
            Response::json([
                'success'   => true,
                'telemetry' => $telemetry
            ]);
        } catch (Exception $e) {
            Response::error($e->getMessage(), 'DEV_OVERVIEW_ERROR', 500);
        }
    }

    /**
     * GET /api/dev/users
     * Lists all registered accounts with complete metadata and profile extensions
     */
    public static function getUsers($req): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            if (($user['role'] ?? '') !== 'SUPERADMIN' && ($user['role'] ?? '') !== 'ADMIN') {
                Response::forbidden('Acceso denegado. Se requiere nivel de Programador / SuperAdmin.');
            }

            $users = UserModel::getAllUsersDetailed();
            Response::json([
                'success' => true,
                'total'   => count($users),
                'users'   => $users
            ]);
        } catch (Exception $e) {
            Response::error($e->getMessage(), 'DEV_USERS_ERROR', 500);
        }
    }

    /**
     * PUT /api/dev/users/{id}/status
     * Toggles an account between ACTIVE and INACTIVE (suspension)
     */
    public static function updateUserStatus($req, $targetUserId = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $id   = ($req instanceof Request) ? (int)$req->getParam('id') : (int)$targetUserId;
            $body = ($req instanceof Request) ? $req->getBody() : $_POST;

            if (($user['role'] ?? '') !== 'SUPERADMIN') {
                Response::forbidden('Solo el Administrador General / Programador puede suspender o activar cuentas.');
            }

            if ($id === (int)$user['id']) {
                throw new Exception('No se puede suspender la cuenta del Programador en sesión activa.', 400);
            }

            $status = strtoupper(trim($body['status'] ?? ''));
            if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
                throw new Exception('El estado debe ser ACTIVE o INACTIVE.', 400);
            }

            $success = UserModel::updateUserStatus($id, $status);
            if (!$success) {
                throw new Exception('No se pudo actualizar el estado del usuario.', 400);
            }

            AuditModel::log((int)$user['id'], 'DEV_USER_STATUS_CHANGED', 'USER', (string)$id, ['new_status' => $status]);

            Response::success([
                'userId' => $id,
                'status' => $status
            ], "Estado de la cuenta actualizado a {$status} correctamente.");
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'DEV_STATUS_ERROR', $code);
        }
    }

    /**
     * PUT /api/dev/users/{id}/role
     * Modifies the academic role of a user
     */
    public static function updateUserRole($req, $targetUserId = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $id   = ($req instanceof Request) ? (int)$req->getParam('id') : (int)$targetUserId;
            $body = ($req instanceof Request) ? $req->getBody() : $_POST;

            if (($user['role'] ?? '') !== 'SUPERADMIN') {
                Response::forbidden('Solo el Administrador General / Programador puede cambiar roles maestros.');
            }

            if ($id === (int)$user['id']) {
                throw new Exception('No puede alterar el rol de su propia cuenta de Programador.', 400);
            }

            $newRole = strtoupper(trim($body['role'] ?? ''));
            $validRoles = ['ADMIN', 'TEACHER', 'STUDENT'];
            if (!in_array($newRole, $validRoles, true)) {
                throw new Exception('Rol inválido. Debe ser ADMIN, TEACHER o STUDENT.', 400);
            }

            $success = UserModel::updateUserRole($id, $newRole);
            if (!$success) {
                throw new Exception('No se pudo cambiar el rol del usuario.', 400);
            }

            AuditModel::log((int)$user['id'], 'DEV_USER_ROLE_CHANGED', 'USER', (string)$id, ['new_role' => $newRole]);

            Response::success([
                'userId' => $id,
                'role'   => $newRole
            ], "Rol del usuario actualizado a {$newRole} exitosamente.");
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'DEV_ROLE_ERROR', $code);
        }
    }

    /**
     * POST /api/dev/users/{id}/reset-password
     * Overrides password for a user with a secure hash
     */
    public static function resetUserPassword($req, $targetUserId = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $id   = ($req instanceof Request) ? (int)$req->getParam('id') : (int)$targetUserId;
            $body = ($req instanceof Request) ? $req->getBody() : $_POST;

            if (($user['role'] ?? '') !== 'SUPERADMIN') {
                Response::forbidden('Acceso denegado: Privilegio reservado para el Administrador General.');
            }

            $newPass = trim($body['password'] ?? '');
            if (empty($newPass)) {
                // Auto-generate high-entropy temporary password
                $newPass = 'Nexus#' . bin2hex(random_bytes(4)) . '!';
            }

            if (strlen($newPass) < 6) {
                throw new Exception('La contraseña debe contener al menos 6 caracteres.', 400);
            }

            $hash = CryptoService::hashPassword($newPass);
            $success = UserModel::resetUserPassword($id, $hash);
            if (!$success) {
                throw new Exception('No se pudo restablecer la contraseña.', 400);
            }

            AuditModel::log((int)$user['id'], 'DEV_PASSWORD_RESET', 'USER', (string)$id);

            Response::success([
                'userId'            => $id,
                'temporaryPassword' => $newPass
            ], 'Contraseña restablecida exitosamente.');
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'DEV_RESET_PASS_ERROR', $code);
        }
    }

    /**
     * DELETE /api/dev/users/{id}
     * Purges a rogue, fake or test user account
     */
    public static function deleteUser($req, $targetUserId = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $id   = ($req instanceof Request) ? (int)$req->getParam('id') : (int)$targetUserId;

            if (($user['role'] ?? '') !== 'SUPERADMIN') {
                Response::forbidden('Acceso denegado: Solo el Administrador General puede purgar cuentas.');
            }

            if ($id === (int)$user['id']) {
                throw new Exception('No puede auto-eliminar su cuenta de Programador.', 400);
            }

            $targetUser = UserModel::findById($id);
            if (!$targetUser) {
                throw new Exception('El usuario seleccionado no existe.', 404);
            }

            $success = UserModel::deleteAccount($id, $targetUser['role']);
            if (!$success) {
                throw new Exception('No se pudo eliminar la cuenta.', 400);
            }

            AuditModel::log((int)$user['id'], 'DEV_USER_PURGED', 'USER', (string)$id, ['purged_email' => $targetUser['email'], 'role' => $targetUser['role']]);

            Response::success(['userId' => $id], "La cuenta de {$targetUser['email']} ha sido eliminada permanentemente del sistema.");
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'DEV_DELETE_USER_ERROR', $code);
        }
    }
}
