<?php
/**
 * NexusAcademic - API Front Controller & Router
 * Dispatches all REST API requests to appropriate PHP controllers
 */

// Enable error capture and JSON responses
error_reporting(E_ALL);
ini_set('display_errors', '0');

// CORS Headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

// Handle OPTIONS preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Global exception and error handler returning JSON
set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'   => 'INTERNAL_SERVER_ERROR',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

// Require Dependencies
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Services/CryptoService.php';
require_once __DIR__ . '/../src/Services/GradeService.php';
require_once __DIR__ . '/../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../src/Middleware/RbacMiddleware.php';
require_once __DIR__ . '/../src/Controllers/AuthController.php';
require_once __DIR__ . '/../src/Controllers/AcademicController.php';
require_once __DIR__ . '/../src/Controllers/AttendanceController.php';
require_once __DIR__ . '/../src/Controllers/GradeController.php';
require_once __DIR__ . '/../src/Controllers/AuditController.php';

// Parse Request Method & Route Path
$method = $_SERVER['REQUEST_METHOD'];

// Parse Request Body
$rawInput = file_get_contents('php://input');
$body = json_decode($rawInput, true);
if (!is_array($body)) {
    $body = !empty($_POST) ? $_POST : [];
}

// Extract and normalize route
$route = '';
if (!empty($_GET['route'])) {
    $route = trim($_GET['route'], '/');
} else {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    
    // Normalize path by removing script name or directory prefix
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    if ($scriptDir !== '/' && !empty($scriptDir) && str_starts_with($uri, $scriptDir)) {
        $uri = substr($uri, strlen($scriptDir));
    }
    
    // Remove /NexusAcademic or /api if present
    $uri = preg_replace('#^/NexusAcademic#i', '', $uri);
    $uri = preg_replace('#^/api(/index\.php)?#i', '', $uri);
    $route = trim($uri, '/');
}

// ====================================================================
// ROUTE DISPATCHER
// ====================================================================

// 1. AUTHENTICATION
if ($route === 'auth/register' && $method === 'POST') {
    AuthController::register($body);
    exit;
}
if ($route === 'auth/login' && $method === 'POST') {
    AuthController::login($body);
    exit;
}
if ($route === 'auth/me' && $method === 'GET') {
    $user = AuthMiddleware::authenticate();
    AuthController::getMe($user);
    exit;
}
if ($route === 'auth/change-password' && $method === 'POST') {
    $user = AuthMiddleware::authenticate();
    AuthController::changePassword($user, $body);
    exit;
}
if ($route === 'auth/profile' && $method === 'PUT') {
    $user = AuthMiddleware::authenticate();
    AuthController::updateProfile($user, $body);
    exit;
}
if (($route === 'auth/delete-account' && ($method === 'POST' || $method === 'DELETE')) ||
    ($route === 'auth/me' && $method === 'DELETE')) {
    $user = AuthMiddleware::authenticate();
    AuthController::deleteAccount($user, $body);
    exit;
}

// 2. ACADEMIC STRUCTURE
if ($route === 'academic/institution') {
    if ($method === 'GET') {
        $user = AuthMiddleware::authenticate();
        AcademicController::getInstitution();
        exit;
    }
    if ($method === 'PUT') {
        $user = AuthMiddleware::authenticate();
        RbacMiddleware::requireRole($user, 'ADMIN');
        AcademicController::updateInstitution($user, $body);
        exit;
    }
}

if ($route === 'academic/periods') {
    if ($method === 'GET') {
        $user = AuthMiddleware::authenticate();
        AcademicController::getPeriods();
        exit;
    }
    if ($method === 'POST') {
        $user = AuthMiddleware::authenticate();
        RbacMiddleware::requireRole($user, 'ADMIN');
        AcademicController::createPeriod($user, $body);
        exit;
    }
}

if ($route === 'academic/subjects') {
    if ($method === 'GET') {
        $user = AuthMiddleware::authenticate();
        AcademicController::getSubjects();
        exit;
    }
    if ($method === 'POST') {
        $user = AuthMiddleware::authenticate();
        RbacMiddleware::requireRole($user, 'ADMIN');
        AcademicController::createSubject($user, $body);
        exit;
    }
}

if ($route === 'academic/groups' && $method === 'GET') {
    $user = AuthMiddleware::authenticate();
    AcademicController::getGroups($user);
    exit;
}
if ($route === 'academic/groups' && $method === 'POST') {
    $user = AuthMiddleware::authenticate();
    RbacMiddleware::requireRole($user, 'ADMIN');
    AcademicController::createGroup($user, $body);
    exit;
}

if ($route === 'academic/teacher-course' && $method === 'POST') {
    $user = AuthMiddleware::authenticate();
    RbacMiddleware::requireRole($user, 'TEACHER', 'ADMIN');
    AcademicController::createTeacherCourse($user, $body);
    exit;
}

if (preg_match('#^academic/groups/(\d+)$#', $route, $matches)) {
    $groupId = (int)$matches[1];
    if ($method === 'GET') {
        $user = AuthMiddleware::authenticate();
        AcademicController::getGroupDetails($user, $groupId);
        exit;
    }
    if ($method === 'PUT') {
        $user = AuthMiddleware::authenticate();
        RbacMiddleware::requireRole($user, 'TEACHER', 'ADMIN');
        AcademicController::updateGroup($user, $groupId, $body);
        exit;
    }
    if ($method === 'DELETE') {
        $user = AuthMiddleware::authenticate();
        RbacMiddleware::requireRole($user, 'TEACHER', 'ADMIN');
        AcademicController::deleteGroup($user, $groupId);
        exit;
    }
}

if ($route === 'academic/available-courses' && $method === 'GET') {
    $user = AuthMiddleware::authenticate();
    AcademicController::getAvailableCourses();
    exit;
}

if ($route === 'academic/enrollments' && $method === 'POST') {
    $user = AuthMiddleware::authenticate();
    RbacMiddleware::requireRole($user, 'ADMIN', 'STUDENT');
    AcademicController::enrollStudent($user, $body);
    exit;
}

if ($route === 'academic/teachers' && $method === 'GET') {
    $user = AuthMiddleware::authenticate();
    RbacMiddleware::requireRole($user, 'ADMIN');
    AcademicController::getTeachers();
    exit;
}
if (preg_match('#^academic/teachers/(\d+)$#', $route, $matches) && $method === 'PUT') {
    $teacherId = (int)$matches[1];
    $user = AuthMiddleware::authenticate();
    RbacMiddleware::requireRole($user, 'ADMIN');
    AcademicController::updateTeacher($user, $teacherId, $body);
    exit;
}

if ($route === 'academic/students' && $method === 'GET') {
    $user = AuthMiddleware::authenticate();
    RbacMiddleware::requireRole($user, 'ADMIN', 'TEACHER');
    AcademicController::getStudents();
    exit;
}

// 3. SESSIONS & DYNAMIC QR ATTENDANCE
if ($route === 'sessions/start' && $method === 'POST') {
    $user = AuthMiddleware::authenticate();
    RbacMiddleware::requireRole($user, 'TEACHER', 'ADMIN');
    AttendanceController::startSession($user, $body);
    exit;
}

if (preg_match('#^sessions/(\d+)/qr-token$#', $route, $matches) && $method === 'GET') {
    $sessionId = (int)$matches[1];
    $user = AuthMiddleware::authenticate();
    RbacMiddleware::requireRole($user, 'TEACHER', 'ADMIN');
    AttendanceController::getSessionQRToken($user, $sessionId);
    exit;
}

if (preg_match('#^sessions/(\d+)/close$#', $route, $matches) && $method === 'POST') {
    $sessionId = (int)$matches[1];
    $user = AuthMiddleware::authenticate();
    RbacMiddleware::requireRole($user, 'TEACHER', 'ADMIN');
    AttendanceController::closeSession($user, $sessionId);
    exit;
}

if ($route === 'attendance/register' && $method === 'POST') {
    $user = AuthMiddleware::authenticate();
    RbacMiddleware::requireRole($user, 'STUDENT');
    AttendanceController::registerAttendance($user, $body);
    exit;
}

if (preg_match('#^attendance/session/(\d+)$#', $route, $matches) && $method === 'GET') {
    $sessionId = (int)$matches[1];
    $user = AuthMiddleware::authenticate();
    RbacMiddleware::requireRole($user, 'TEACHER', 'ADMIN');
    AttendanceController::getSessionAttendance($user, $sessionId);
    exit;
}

if ($route === 'attendance/my-history' && $method === 'GET') {
    $user = AuthMiddleware::authenticate();
    RbacMiddleware::requireRole($user, 'STUDENT');
    AttendanceController::getMyAttendanceHistory($user);
    exit;
}

// 4. EVALUATIONS & GRADES
if ($route === 'evaluations' && $method === 'POST') {
    $user = AuthMiddleware::authenticate();
    RbacMiddleware::requireRole($user, 'TEACHER', 'ADMIN');
    GradeController::createEvaluation($user, $body);
    exit;
}

if (preg_match('#^evaluations/(\d+)$#', $route, $matches)) {
    $evalId = (int)$matches[1];
    if ($method === 'PUT') {
        $user = AuthMiddleware::authenticate();
        RbacMiddleware::requireRole($user, 'TEACHER', 'ADMIN');
        GradeController::updateEvaluation($user, $evalId, $body);
        exit;
    }
    if ($method === 'DELETE') {
        $user = AuthMiddleware::authenticate();
        RbacMiddleware::requireRole($user, 'TEACHER', 'ADMIN');
        GradeController::deleteEvaluation($user, $evalId);
        exit;
    }
}

if ($route === 'grades' && $method === 'POST') {
    $user = AuthMiddleware::authenticate();
    RbacMiddleware::requireRole($user, 'TEACHER', 'ADMIN');
    GradeController::saveGrade($user, $body);
    exit;
}

if ($route === 'grades/batch' && $method === 'POST') {
    $user = AuthMiddleware::authenticate();
    RbacMiddleware::requireRole($user, 'TEACHER', 'ADMIN');
    GradeController::saveBatchGrades($user, $body);
    exit;
}

if (preg_match('#^grades/group/(\d+)/matrix$#', $route, $matches) && $method === 'GET') {
    $groupId = (int)$matches[1];
    $user = AuthMiddleware::authenticate();
    RbacMiddleware::requireRole($user, 'TEACHER', 'ADMIN');
    GradeController::getGroupGradesMatrix($user, $groupId);
    exit;
}

if ($route === 'grades/my-grades' && $method === 'GET') {
    $user = AuthMiddleware::authenticate();
    RbacMiddleware::requireRole($user, 'STUDENT');
    GradeController::getMyGrades($user);
    exit;
}

if (preg_match('#^grades/(\d+)/history$#', $route, $matches) && $method === 'GET') {
    $gradeId = (int)$matches[1];
    $user = AuthMiddleware::authenticate();
    GradeController::getGradeAuditHistory($user, $gradeId);
    exit;
}

// 5. AUDIT TRAIL & SYSTEM METRICS
if ($route === 'audit/logs' && $method === 'GET') {
    $user = AuthMiddleware::authenticate();
    RbacMiddleware::requireRole($user, 'ADMIN');
    AuditController::getAuditLogs($user);
    exit;
}

if ($route === 'audit/grades-history' && $method === 'GET') {
    $user = AuthMiddleware::authenticate();
    RbacMiddleware::requireRole($user, 'ADMIN', 'TEACHER');
    AuditController::getGlobalGradeHistory($user);
    exit;
}

if ($route === 'audit/metrics' && $method === 'GET') {
    $user = AuthMiddleware::authenticate();
    RbacMiddleware::requireRole($user, 'ADMIN');
    AuditController::getSystemMetrics($user);
    exit;
}

// Fallback: 404 Endpoint Not Found
http_response_code(404);
echo json_encode([
    'error'   => 'ENDPOINT_NOT_FOUND',
    'message' => "El recurso solicitado '{$route}' no existe en la API."
], JSON_UNESCAPED_UNICODE);
