<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * Authentication & Identity Controller (Thin HTTP Adapter)
 */

namespace NexusAcademic\Controllers;

use NexusAcademic\Core\Request;
use NexusAcademic\Core\Response;
use NexusAcademic\Services\AuthService;
use Exception;

require_once __DIR__ . '/../Core/Request.php';
require_once __DIR__ . '/../Core/Response.php';
require_once __DIR__ . '/../Services/AuthService.php';

class AuthController {

    /**
     * POST /api/auth/register
     */
    public static function register($req): void {
        try {
            $body = ($req instanceof Request) ? $req->getBody() : (array)$req;
            $ip   = ($req instanceof Request) ? $req->getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

            $result = AuthService::register($body, $ip);
            Response::created($result);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'REGISTER_ERROR', $code);
        }
    }

    /**
     * POST /api/auth/login
     */
    public static function login($req): void {
        try {
            $body  = ($req instanceof Request) ? $req->getBody() : (array)$req;
            $ip    = ($req instanceof Request) ? $req->getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            $email = $body['email'] ?? '';
            $pass  = $body['password'] ?? '';

            $result = AuthService::login($email, $pass, $ip);
            Response::json($result);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 401;
            Response::error($e->getMessage(), 'LOGIN_ERROR', $code);
        }
    }

    /**
     * GET /api/auth/me
     */
    public static function getMe($req): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $result = AuthService::getMe($user);
            Response::json($result);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 404;
            Response::error($e->getMessage(), 'AUTH_ME_ERROR', $code);
        }
    }

    /**
     * POST /api/auth/change-password
     */
    public static function changePassword($req, ?array $body = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $data = ($req instanceof Request) ? $req->getBody() : (array)$body;
            $ip   = ($req instanceof Request) ? $req->getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

            $curr = $data['current_password'] ?? '';
            $new  = $data['new_password'] ?? '';

            AuthService::changePassword($user, $curr, $new, $ip);
            Response::success(null, 'Contraseña actualizada con éxito.');
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'CHANGE_PASSWORD_ERROR', $code);
        }
    }

    /**
     * PUT /api/auth/profile
     */
    public static function updateProfile($req, ?array $body = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $data = ($req instanceof Request) ? $req->getBody() : (array)$body;
            $ip   = ($req instanceof Request) ? $req->getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

            $result = AuthService::updateProfile($user, $data, $ip);
            Response::success($result, 'Perfil actualizado correctamente.');
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'UPDATE_PROFILE_ERROR', $code);
        }
    }

    /**
     * POST/DELETE /api/auth/delete-account
     */
    public static function deleteAccount($req, ?array $body = null): void {
        try {
            $user = ($req instanceof Request) ? $req->getUser() : (array)$req;
            $data = ($req instanceof Request) ? $req->getBody() : (array)$body;
            $ip   = ($req instanceof Request) ? $req->getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

            $pass = $data['password'] ?? '';
            AuthService::deleteAccount($user, $pass, $ip);
            Response::success(null, 'Cuenta eliminada definitivamente del sistema.');
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            Response::error($e->getMessage(), 'DELETE_ACCOUNT_ERROR', $code);
        }
    }
}

// Global class alias for legacy procedural includes
class_alias(AuthController::class, 'AuthController');
