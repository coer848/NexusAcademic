<?php
/**
 * NexusAcademic Enterprise - Global Configuration
 * PHP & MySQL / phpMyAdmin Environment
 */

// Database Connection Settings (XAMPP Defaults)
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'nexusacademic');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');

// Cryptography & JWT Security
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'nexus-academic-hyper-secure-secret-key-2026-v3');
define('JWT_EXPIRES_IN_SECONDS', 86400); // 24 hours

// Dynamic QR Parameters
define('QR_ROTATION_INTERVAL_SECONDS', 20); // 20-second dynamic TOTP-style rotation
define('QR_TOLERANCE_SECONDS', 35);         // Max validity window to prevent replay of old frames

// Application Environment
define('APP_ENV', getenv('APP_ENV') ?: 'production');
define('APP_NAME', 'NexusAcademic Enterprise');
define('APP_VERSION', '3.0.0');

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
