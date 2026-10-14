<?php
/**
 * NexusAcademic Enterprise - Main Web Entrypoint
 * Plataforma Académica Integral en PHP con Base de Datos MySQL / phpMyAdmin
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

// Quick check if database and tables are ready
$dbReady = false;
$dbError = null;

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($stmt->fetch()) {
        $dbReady = true;
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

// If database is not ready and not in API mode, redirect or offer setup
if (!$dbReady && !isset($_GET['skip_check'])) {
    header('Location: setup.php');
    exit;
}

// Serve the Main Platform Web Interface
require_once __DIR__ . '/public/index.html';
