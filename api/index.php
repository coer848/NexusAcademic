<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * API Front Controller & Kernel Dispatcher
 */

// Error reporting configuration
error_reporting(E_ALL);
ini_set('display_errors', '0');

// CORS & OWASP Security Headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Handle OPTIONS preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Global Exception Handler returning standardized JSON
set_exception_handler(function (Throwable $e) {
    $rawCode = $e->getCode();
    $statusCode = (is_numeric($rawCode) && (int)$rawCode >= 400 && (int)$rawCode < 600) ? (int)$rawCode : 500;
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'error'   => 'INTERNAL_SERVER_ERROR',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
});

// Universal PSR-4 Autoloader for NexusAcademic Namespace
spl_autoload_register(function (string $class): void {
    $prefix = 'NexusAcademic\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relativeClass = substr($class, $len);
    $parts = explode('\\', $relativeClass);
    $baseDir = __DIR__ . '/../src/';

    // 1. Direct match: src/Controllers/DevController.php
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
        return;
    }

    // 2. Folder casing fallback (e.g., controllers/DevController.php)
    if (count($parts) === 2) {
        $folderVariations = [strtolower($parts[0]), ucfirst(strtolower($parts[0]))];
        foreach ($folderVariations as $f) {
            $candidate = $baseDir . $f . '/' . $parts[1] . '.php';
            if (file_exists($candidate)) {
                require_once $candidate;
                return;
            }
        }
    }
});

// Require Configuration & Database
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Require Core Components
require_once __DIR__ . '/../src/Core/Response.php';
require_once __DIR__ . '/../src/Core/Request.php';
require_once __DIR__ . '/../src/Core/Router.php';

// Require Services & Models
require_once __DIR__ . '/../src/Services/CryptoService.php';
require_once __DIR__ . '/../src/Services/GradeService.php';
require_once __DIR__ . '/../src/Services/AuthService.php';
require_once __DIR__ . '/../src/Services/AcademicService.php';
require_once __DIR__ . '/../src/Services/AttendanceService.php';
require_once __DIR__ . '/../src/Services/AuditService.php';

require_once __DIR__ . '/../src/Models/UserModel.php';
require_once __DIR__ . '/../src/Models/AcademicModel.php';
require_once __DIR__ . '/../src/Models/AttendanceModel.php';
require_once __DIR__ . '/../src/Models/GradeModel.php';
require_once __DIR__ . '/../src/Models/AuditModel.php';

// Require Middlewares
require_once __DIR__ . '/../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../src/Middleware/RbacMiddleware.php';
require_once __DIR__ . '/../src/Middleware/RateLimitMiddleware.php';

// Require Controllers
require_once __DIR__ . '/../src/Controllers/AuthController.php';
require_once __DIR__ . '/../src/Controllers/AcademicController.php';
require_once __DIR__ . '/../src/Controllers/AttendanceController.php';
require_once __DIR__ . '/../src/Controllers/GradeController.php';
require_once __DIR__ . '/../src/Controllers/AuditController.php';
require_once __DIR__ . '/../src/Controllers/DevController.php';

use NexusAcademic\Core\Request;
use NexusAcademic\Core\Router;

// Bootstrap HTTP Request & Router
$request = new Request();
$router  = new Router();

// Global Rate Limiting across all API routes (Mitigates Scraping & DoS)
$router->use(function (Request $req) {
    \NexusAcademic\Middleware\RateLimitMiddleware::check($req, RATE_LIMIT_GLOBAL_MAX, RATE_LIMIT_GLOBAL_DECAY, 'api_global');
});

// Load Modular Routes
$registerRoutes = require_once __DIR__ . '/../routes/api.php';
$registerRoutes($router);

// Dispatch incoming request through the pipeline
$router->dispatch($request);
