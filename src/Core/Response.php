<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * Core HTTP Response Formatter & Emitter
 */

namespace NexusAcademic\Core;

class Response {

    /**
     * Send standard JSON payload
     */
    public static function json($data, int $statusCode = 200): void {
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=UTF-8');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Send standard success response
     */
    public static function success($data = null, string $message = 'Operación exitosa', int $statusCode = 200): void {
        $payload = ['success' => true, 'message' => $message];
        if ($data !== null) {
            if (is_array($data) && !array_is_list($data)) {
                $payload = array_merge($payload, $data);
            } else {
                $payload['data'] = $data;
            }
        }
        self::json($payload, $statusCode);
    }

    /**
     * Send HTTP 201 Created response
     */
    public static function created($data = null, string $message = 'Recurso creado exitosamente'): void {
        self::success($data, $message, 201);
    }

    /**
     * Send standard error response
     */
    public static function error(string $message, string $errorCode = 'BAD_REQUEST', $statusCode = 400, ?array $details = null): void {
        $code = (is_numeric($statusCode) && (int)$statusCode >= 400 && (int)$statusCode < 600) ? (int)$statusCode : 400;
        $payload = [
            'success' => false,
            'error'   => $errorCode,
            'message' => $message
        ];
        if ($details !== null) {
            $payload['details'] = $details;
        }
        self::json($payload, $code);
    }

    /**
     * Send HTTP 401 Unauthorized response
     */
    public static function unauthorized(string $message = 'Autenticación requerida o token inválido'): void {
        self::error($message, 'UNAUTHORIZED', 401);
    }

    /**
     * Send HTTP 403 Forbidden response
     */
    public static function forbidden(string $message = 'Acceso denegado para su rol actual'): void {
        self::error($message, 'FORBIDDEN', 403);
    }

    /**
     * Send HTTP 404 Not Found response
     */
    public static function notFound(string $message = 'El recurso solicitado no fue encontrado'): void {
        self::error($message, 'NOT_FOUND', 404);
    }

    /**
     * Send HTTP 500 Internal Server Error response
     */
    public static function serverError(string $message = 'Error interno en el servidor'): void {
        self::error($message, 'INTERNAL_SERVER_ERROR', 500);
    }
}
