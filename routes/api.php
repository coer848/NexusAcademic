<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * Main API Routes Aggregator & Registry
 */

use NexusAcademic\Core\Router;

/**
 * Register all domain routes onto the provided router instance
 */
return function (Router $router): void {
    // 1. Authentication & Profile Routes
    require_once __DIR__ . '/auth.php';

    // 2. Academic Structure, Courses & Enrollment Routes
    require_once __DIR__ . '/academic.php';

    // 3. Class Sessions & Dynamic QR Attendance Routes
    require_once __DIR__ . '/attendance.php';

    // 4. Evaluations, Gradebook & Grading Routes
    require_once __DIR__ . '/grades.php';

    // 5. System Audit Trails & Telemetry Routes
    require_once __DIR__ . '/audit.php';
};
