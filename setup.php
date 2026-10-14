<?php
/**
 * NexusAcademic Enterprise - Asistente de Configuración & Base de Datos phpMyAdmin
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$message = null;
$messageType = 'info';

// Handle Action: Initialize / Re-seed Database
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'seed') {
    try {
        if (defined('APP_ENV') && APP_ENV === 'production') {
            try {
                $pdoCheck = Database::getConnection();
                $existingUsers = (int)$pdoCheck->query("SELECT COUNT(*) FROM users")->fetchColumn();
                if ($existingUsers > 0 && empty($_POST['confirm_destructive_reseed'])) {
                    throw new Exception("Bloqueo de Seguridad OWASP: No se permite reiniciar la base de datos en producción cuando ya existen usuarios activos.");
                }
            } catch (Throwable $eDb) {
                // If tables don't exist yet, proceed with setup
            }
        }

        $sqlFile = __DIR__ . '/database/nexus_academic.sql';
        if (!file_exists($sqlFile)) {
            $sqlFile = __DIR__ . '/nexus_academic.sql';
        }

        if (!file_exists($sqlFile)) {
            throw new Exception("No se encontró el archivo SQL en: $sqlFile");
        }

        $sqlContent = file_get_contents($sqlFile);
        $pdo = Database::getConnection();
        $pdo->exec($sqlContent);

        $message = "¡Base de datos 'nexusacademic' y datos iniciales importados exitosamente!";
        $messageType = "success";
    } catch (Throwable $e) {
        $message = "Error al inicializar la base de datos: " . $e->getMessage();
        $messageType = "error";
    }
}

// Check Diagnostics
$phpVersion = phpversion();
$pdoLoaded = extension_loaded('pdo_mysql');
$dbConnected = false;
$dbError = null;
$tables = [];
$counts = [];

try {
    $pdo = Database::getConnection();
    $dbConnected = true;
    
    // Check tables
    $stmt = $pdo->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }

    if (in_array('users', $tables)) {
        $counts['users'] = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $counts['teachers'] = (int)$pdo->query("SELECT COUNT(*) FROM teachers")->fetchColumn();
        $counts['students'] = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
        $counts['subjects'] = (int)$pdo->query("SELECT COUNT(*) FROM subjects")->fetchColumn();
        $counts['groups'] = (int)$pdo->query("SELECT COUNT(*) FROM course_groups")->fetchColumn();
        $counts['evaluations'] = (int)$pdo->query("SELECT COUNT(*) FROM evaluations")->fetchColumn();
        $counts['grades'] = (int)$pdo->query("SELECT COUNT(*) FROM grades")->fetchColumn();
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$allTablesExist = count(array_intersect([
    'institutions', 'academic_periods', 'users', 'teachers', 'students',
    'subjects', 'course_groups', 'enrollments', 'class_sessions',
    'attendance_tokens', 'attendances', 'evaluations', 'grades',
    'grade_history', 'audit_logs'
], $tables)) === 15;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NexusAcademic - Configuración & phpMyAdmin</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        .setup-container {
            max-width: 980px;
            margin: 2.5rem auto;
            padding: 0 1.5rem;
        }
        .setup-card {
            background: rgba(18, 24, 38, 0.75);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 18px;
            padding: 2.2rem;
            margin-bottom: 2rem;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.4rem 0.9rem;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 700;
        }
        .status-badge.ok {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        .status-badge.warn {
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        .status-badge.err {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        .diag-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.2rem;
            margin: 1.5rem 0;
        }
        .diag-item {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 12px;
            padding: 1.2rem;
        }
        .diag-item .label {
            font-size: 0.78rem;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.4rem;
        }
        .diag-item .val {
            font-size: 1.15rem;
            font-weight: 700;
            color: #f8fafc;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .creds-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.2rem;
            margin-top: 1.2rem;
        }
        .cred-card {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(99, 102, 241, 0.2);
            border-radius: 14px;
            padding: 1.3rem;
            position: relative;
        }
        .cred-card.admin { border-color: rgba(239, 68, 68, 0.4); }
        .cred-card.teacher { border-color: rgba(59, 130, 246, 0.4); }
        .cred-card.student { border-color: rgba(16, 185, 129, 0.4); }
        .cred-card .role-title {
            font-size: 0.8rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            margin-bottom: 0.6rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .cred-card.admin .role-title { color: #f87171; }
        .cred-card.teacher .role-title { color: #60a5fa; }
        .cred-card.student .role-title { color: #34d399; }
        .cred-field {
            margin-bottom: 0.4rem;
            font-size: 0.86rem;
            color: #cbd5e1;
            font-family: 'JetBrains Mono', monospace;
        }
        .cred-field strong {
            color: #94a3b8;
            font-weight: 500;
        }
        .alert-box {
            padding: 1.2rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }
        .alert-box.success {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #34d399;
        }
        .alert-box.error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #f87171;
        }
    </style>
</head>
<body>
    <div class="bg-glow bg-glow-1"></div>
    <div class="bg-glow bg-glow-2"></div>
    <div class="grid-overlay"></div>

    <div class="setup-container">
        <!-- Brand Header -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
            <div style="display: flex; align-items: center; gap: 1rem;">
                <div style="width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg, #6366f1, #06b6d4); display: flex; align-items: center; justify-content: center; font-size: 1.6rem; color: #fff;">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
                <div>
                    <h1 style="font-size: 1.6rem; margin: 0; font-weight: 800; color: #fff;">Nexus<span style="color: #06b6d4;">Academic</span></h1>
                    <span style="font-size: 0.85rem; color: #94a3b8;">Centro de Control & Diagnóstico de Base de Datos (PHP + phpMyAdmin)</span>
                </div>
            </div>
            <div>
                <a href="index.php" class="btn btn-primary">
                    <i class="fa-solid fa-arrow-right-to-bracket"></i> Ir al Aplicativo Web
                </a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert-box <?= $messageType ?>">
                <i class="fa-solid <?= $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>" style="font-size: 1.4rem;"></i>
                <div><?= htmlspecialchars($message) ?></div>
            </div>
        <?php endif; ?>

        <!-- Diagnostics Card -->
        <div class="setup-card">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h2 style="font-size: 1.25rem; margin: 0; font-weight: 700; color: #fff;">
                    <i class="fa-solid fa-server" style="color: #6366f1; margin-right: 0.5rem;"></i> Estado del Entorno de Servidor (XAMPP)
                </h2>
                <div>
                    <?php if ($dbConnected && $allTablesExist): ?>
                        <span class="status-badge ok"><i class="fa-solid fa-circle-check"></i> Sistema Operativo & Listo</span>
                    <?php else: ?>
                        <span class="status-badge warn"><i class="fa-solid fa-triangle-exclamation"></i> Requiere Importación</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="diag-grid">
                <div class="diag-item">
                    <div class="label">Versión de PHP</div>
                    <div class="val">
                        <i class="fa-brands fa-php" style="color: #818cf8;"></i>
                        <?= htmlspecialchars($phpVersion) ?>
                    </div>
                </div>
                <div class="diag-item">
                    <div class="label">Driver PDO MySQL</div>
                    <div class="val">
                        <?php if ($pdoLoaded): ?>
                            <span style="color: #10b981;"><i class="fa-solid fa-check"></i> Activo</span>
                        <?php else: ?>
                            <span style="color: #ef4444;"><i class="fa-solid fa-xmark"></i> Inactivo</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="diag-item">
                    <div class="label">Base de Datos MySQL</div>
                    <div class="val">
                        <?php if ($dbConnected): ?>
                            <span style="color: #10b981;"><i class="fa-solid fa-database"></i> <?= htmlspecialchars(DB_NAME) ?></span>
                        <?php else: ?>
                            <span style="color: #ef4444;"><i class="fa-solid fa-circle-xmark"></i> Desconectada</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="diag-item">
                    <div class="label">Tablas Creadas</div>
                    <div class="val">
                        <i class="fa-solid fa-table-cells" style="color: #06b6d4;"></i>
                        <?= count($tables) ?> / 15 Tablas
                    </div>
                </div>
            </div>

            <?php if ($dbError): ?>
                <div style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); padding: 1rem; border-radius: 10px; color: #fca5a5; font-family: monospace; font-size: 0.85rem; margin-top: 1rem;">
                    <strong>Error de Conexión:</strong> <?= htmlspecialchars($dbError) ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- phpMyAdmin & Actions Card -->
        <div class="setup-card">
            <h2 style="font-size: 1.25rem; margin: 0 0 1rem 0; font-weight: 700; color: #fff;">
                <i class="fa-solid fa-database" style="color: #06b6d4; margin-right: 0.5rem;"></i> Gestión de Base de Datos en phpMyAdmin
            </h2>
            <p style="color: #94a3b8; font-size: 0.92rem; line-height: 1.6; margin-bottom: 1.5rem;">
                La plataforma NexusAcademic almacena toda su información en MySQL / MariaDB bajo la base de datos <code>nexusacademic</code>. Puedes administrar las tablas, usuarios y auditorías interactivamente desde phpMyAdmin o inicializarla automáticamente con un solo clic.
            </p>

            <div style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: center;">
                <form method="POST" onsubmit="return confirm('¿Deseas inicializar / recargar la base de datos con los datos semilla iniciales?');">
                    <input type="hidden" name="action" value="seed">
                    <button type="submit" class="btn btn-primary" style="background: linear-gradient(135deg, #10b981, #059669);">
                        <i class="fa-solid fa-bolt"></i> Inicializar / Restaurar Datos con 1 Clic
                    </button>
                </form>

                <a href="http://localhost/phpmyadmin/index.php?route=/database/structure&db=nexusacademic" target="_blank" class="btn btn-secondary">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Abrir phpMyAdmin (nexusacademic)
                </a>

                <a href="nexus_academic.sql" download class="btn btn-secondary">
                    <i class="fa-solid fa-download"></i> Descargar Script nexus_academic.sql
                </a>
            </div>
        </div>

        <!-- Demo Accounts Card -->
        <div class="setup-card">
            <h2 style="font-size: 1.25rem; margin: 0 0 0.5rem 0; font-weight: 700; color: #fff;">
                <i class="fa-solid fa-users" style="color: #f59e0b; margin-right: 0.5rem;"></i> Cuentas de Acceso Preconfiguradas (Demo)
            </h2>
            <p style="color: #94a3b8; font-size: 0.88rem; margin-bottom: 1.2rem;">
                Puedes iniciar sesión inmediatamente en la plataforma con cualquiera de los siguientes roles:
            </p>

            <div class="creds-grid">
                <!-- Admin -->
                <div class="cred-card admin">
                    <div class="role-title">
                        <i class="fa-solid fa-shield-halved"></i> ADMINISTRADOR DEL SISTEMA
                    </div>
                    <div class="cred-field"><strong>Email:</strong> admin@nexus.edu</div>
                    <div class="cred-field"><strong>Contraseña:</strong> Admin123!</div>
                    <div style="font-size: 0.8rem; color: #94a3b8; margin-top: 0.5rem;">
                        Acceso total a métricas, docentes, estudiantes, auditoría y materias.
                    </div>
                </div>

                <!-- Teacher -->
                <div class="cred-card teacher">
                    <div class="role-title">
                        <i class="fa-solid fa-chalkboard-user"></i> DOCENTE (Ing. Software)
                    </div>
                    <div class="cred-field"><strong>Email:</strong> prof.carlos@nexus.edu</div>
                    <div class="cred-field"><strong>Contraseña:</strong> Teacher123!</div>
                    <div style="font-size: 0.8rem; color: #94a3b8; margin-top: 0.5rem;">
                        Generación de QR dinámico, registro de notas y ponderaciones.
                    </div>
                </div>

                <!-- Student -->
                <div class="cred-card student">
                    <div class="role-title">
                        <i class="fa-solid fa-user-graduate"></i> ESTUDIANTE (Valeria Ríos)
                    </div>
                    <div class="cred-field"><strong>Email:</strong> estudiante.valeria@nexus.edu</div>
                    <div class="cred-field"><strong>Contraseña:</strong> Student123!</div>
                    <div style="font-size: 0.8rem; color: #94a3b8; margin-top: 0.5rem;">
                        Escaneo de QR con cámara/WebRTC, notas y proyección de promedio.
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div style="text-align: center; color: #64748b; font-size: 0.84rem; padding-bottom: 2rem;">
            NexusAcademic Enterprise • Formato Web PHP & MySQL / phpMyAdmin en XAMPP
        </div>
    </div>
</body>
</html>
