<?php
/**
 * NexusAcademic Enterprise - Global Configuration
 * PHP & MySQL / phpMyAdmin Environment
 * Loads environment variables from .env file securely
 */

// ====================================================================
// 1. SECURE .ENV LOADER
// ====================================================================
(function () {
    $possiblePaths = [
        dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env',
        __DIR__ . DIRECTORY_SEPARATOR . '.env',
        dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env'
    ];

    $envFile = null;
    foreach ($possiblePaths as $path) {
        if (file_exists($path) && is_readable($path)) {
            $envFile = $path;
            break;
        }
    }

    if ($envFile) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            // Skip comments and empty lines
            if (empty($line) || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }

            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Strip wrapping quotes
                if (
                    (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))
                ) {
                    $value = substr($value, 1, -1);
                }

                if (!empty($key)) {
                    putenv("{$key}={$value}");
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                }
            }
        }
    }
})();

// Helper function to read environment variable with fallback
function env(string $key, $default = null) {
    $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($val === false || $val === null || $val === '') {
        return $default;
    }
    // Type casting
    $lower = strtolower((string)$val);
    if ($lower === 'true') return true;
    if ($lower === 'false') return false;
    if ($lower === 'null') return null;
    return $val;
}

// ====================================================================
// 2. DATABASE CONFIGURATION (FROM .ENV)
// ====================================================================
define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_PORT', env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', 'nexusacademic'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

// ====================================================================
// 3. CRYPTOGRAPHY & JWT SECURITY (FROM .ENV)
// ====================================================================
define('JWT_SECRET', env('JWT_SECRET', 'nexus-academic-hyper-secure-secret-key-2026-v3'));
define('JWT_EXPIRES_IN_SECONDS', (int)env('JWT_EXPIRES_IN_SECONDS', 86400)); // 24 hours

// ====================================================================
// 4. DYNAMIC QR PARAMETERS (FROM .ENV)
// ====================================================================
define('QR_ROTATION_INTERVAL_SECONDS', (int)env('QR_ROTATION_INTERVAL_SECONDS', 20));
define('QR_TOLERANCE_SECONDS', (int)env('QR_TOLERANCE_SECONDS', 35));

// ====================================================================
// 5. RATE LIMITING (FROM .ENV)
// ====================================================================
define('RATE_LIMIT_GLOBAL_MAX', (int)env('RATE_LIMIT_GLOBAL_MAX', 120));
define('RATE_LIMIT_GLOBAL_DECAY', (int)env('RATE_LIMIT_GLOBAL_DECAY', 60));
define('RATE_LIMIT_AUTH_MAX', (int)env('RATE_LIMIT_AUTH_MAX', 10));
define('RATE_LIMIT_AUTH_DECAY', (int)env('RATE_LIMIT_AUTH_DECAY', 60));

// ====================================================================
// 6. APPLICATION METADATA & ENVIRONMENT
// ====================================================================
define('APP_ENV', env('APP_ENV', 'production'));
define('APP_NAME', env('APP_NAME', 'NexusAcademic Enterprise'));
define('APP_VERSION', env('APP_VERSION', '3.0.0'));

// Base URL helper
function getBaseUrl(): string {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $scriptDir = str_replace('\\', '/', $scriptDir);
    if ($scriptDir === '/' || $scriptDir === '.') {
        $scriptDir = '';
    }
    return "$protocol://$host$scriptDir";
}
