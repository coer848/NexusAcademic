<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * Role-Based Access Control (RBAC) Middleware
 */

namespace NexusAcademic\Middleware;

use NexusAcademic\Core\Request;
use NexusAcademic\Core\Response;

class RbacMiddleware {

    /**
     * Enforce required roles on an authenticated user record
     *
     * @param array $user Authenticated user payload
     * @param string ...$allowedRoles List of permissible roles (e.g. 'ADMIN', 'TEACHER')
     */
    public static function requireRole(array $user, string ...$allowedRoles): void {
        if (!in_array($user['role'] ?? '', $allowedRoles, true)) {
            Response::forbidden('No posee los privilegios académicos requeridos para ejecutar esta acción.');
        }
    }

    /**
     * Middleware handler for Router pipeline
     */
    public static function handle(string ...$allowedRoles): callable {
        return function (Request $request) use ($allowedRoles) {
            $user = $request->getUser();
            if (!$user) {
                $user = AuthMiddleware::authenticate($request);
            }
            self::requireRole($user, ...$allowedRoles);
        };
    }
}
