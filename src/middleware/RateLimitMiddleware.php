<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * Rate Limiting & Brute-Force Protection Middleware (OWASP ASVS)
 */

namespace NexusAcademic\Middleware;

use NexusAcademic\Core\Request;
use NexusAcademic\Core\Response;

class RateLimitMiddleware {

    /**
     * Enforce max requests per time window per IP
     *
     * @param int $maxAttempts Max permissible requests in the time window
     * @param int $decaySeconds Window duration in seconds
     */
    public static function check(Request $request, int $maxAttempts = 10, int $decaySeconds = 60, string $prefix = 'rate_limit'): void {
        $ip = $request->getClientIp();
        $key = md5($prefix . '_' . $ip);
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nexus_ratelimits';

        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0777, true);
        }

        $file = $tempDir . DIRECTORY_SEPARATOR . $key . '.json';
        $now = time();

        $data = ['count' => 0, 'first_attempt' => $now];
        if (file_exists($file)) {
            $content = @file_get_contents($file);
            $parsed = json_decode($content, true);
            if (is_array($parsed)) {
                $data = $parsed;
            }
        }

        // Reset if decay window passed
        if ($now - $data['first_attempt'] > $decaySeconds) {
            $data = ['count' => 1, 'first_attempt' => $now];
        } else {
            $data['count']++;
        }

        @file_put_contents($file, json_encode($data), LOCK_EX);

        $remaining = max(0, $maxAttempts - $data['count']);
        $resetTime = $data['first_attempt'] + $decaySeconds;

        if (!headers_sent()) {
            header("X-RateLimit-Limit: {$maxAttempts}");
            header("X-RateLimit-Remaining: {$remaining}");
            header("X-RateLimit-Reset: {$resetTime}");
        }

        if ($data['count'] > $maxAttempts) {
            $retryAfter = max(1, $resetTime - $now);
            header("Retry-After: {$retryAfter}");
            Response::error('Demasiadas solicitudes. Por favor espere antes de intentar nuevamente.', 'RATE_LIMIT_EXCEEDED', 429, [
                'retry_after_seconds' => $retryAfter
            ]);
        }
    }

    /**
     * Middleware handler for Router pipeline
     */
    public static function handle(int $maxAttempts = 10, int $decaySeconds = 60, string $prefix = 'global'): callable {
        return function (Request $request) use ($maxAttempts, $decaySeconds, $prefix) {
            self::check($request, $maxAttempts, $decaySeconds, $prefix);
        };
    }
}
