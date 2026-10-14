<?php
/**
 * Role-Based Access Control (RBAC) Middleware
 */

class RbacMiddleware {

    /**
     * Ensures the authenticated user has one of the required roles
     *
     * @param array $user Authenticated user array
     * @param string ...$allowedRoles Allowed roles ('ADMIN', 'TEACHER', 'STUDENT')
     */
    public static function requireRole(array $user, string ...$allowedRoles): void {
        if (!in_array($user['role'], $allowedRoles, true)) {
            http_response_code(403);
            echo json_encode([
                'error'   => 'ACCESS_DENIED',
                'message' => sprintf(
                    'Acceso denegado. Se requiere uno de los siguientes roles: %s.',
                    implode(', ', $allowedRoles)
                )
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
