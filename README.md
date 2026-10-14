# NexusAcademic Enterprise (v3.0) 🎓
### Plataforma Académica Integral en PHP con Base de Datos en phpMyAdmin / MySQL

Plataforma institucional de alto rendimiento desarrollada en **PHP 8.0+**, con base de datos relacional **MySQL / MariaDB** (gestionada mediante **phpMyAdmin** en XAMPP), frontend SPA reactivo con diseño *Glassmorphism*, verificación de presencia física (Geofencing Haversine), motor de calificaciones ponderadas con historial inmutable, tokens criptográficos dinámicos para control de asistencia QR y control de acceso basado en roles (RBAC).

---

## 🚀 Requisitos del Sistema (Entorno Web XAMPP)
* **Servidor Web:** Apache (incluido en XAMPP).
* **PHP:** Versión `>= 8.0` (con extensiones `pdo_mysql`, `openssl`, `json`).
* **Base de Datos:** MySQL `>= 5.7` o MariaDB `>= 10.4` (incluido en XAMPP).
* **Gestor de BD:** [phpMyAdmin](http://localhost/phpmyadmin/) en `http://localhost/phpmyadmin/`.
* **Navegador Web:** Chrome, Firefox, Edge o Safari con soporte WebRTC/Cámara.

---

## 🛠️ Puesta en Marcha en XAMPP

1. **Ubicación del Proyecto:**
   Asegúrate de que la carpeta del proyecto esté ubicada en:
   ```text
   C:\xampp\htdocs\NexusAcademic
   ```

2. **Iniciar Servicios en XAMPP Control Panel:**
   - Abre el **XAMPP Control Panel**.
   - Haz clic en **Start** para **Apache**.
   - Haz clic en **Start** para **MySQL**.

3. **Base de Datos en phpMyAdmin:**
   - La base de datos se llama `nexusacademic`.
   - Puedes abrir [phpMyAdmin](http://localhost/phpmyadmin/) e importar el archivo `nexus_academic.sql` directamente desde la pestaña **Importar**.
   - O bien, abre el Asistente de Configuración Web en tu navegador:
     👉 [http://localhost/NexusAcademic/setup.php](http://localhost/NexusAcademic/setup.php) y haz clic en **"Inicializar / Restaurar Datos con 1 Clic"**.

4. **Acceso al Aplicativo Web:**
   Abre tu navegador e ingresa a:
   👉 **[http://localhost/NexusAcademic/](http://localhost/NexusAcademic/)**

---

## 🔑 Cuentas de Acceso Preconfiguradas (Demo)

| Rol | Correo Electrónico | Contraseña | Capacidades |
| :--- | :--- | :--- | :--- |
| **Administrador** | `admin@nexus.edu` | `Admin123!` | Gestión global, períodos, asignaturas, auditoría y métricas del sistema |
| **Docente 1** | `prof.carlos@nexus.edu` | `Teacher123!` | Proyección de QR dinámico, pase de lista en vivo, calificaciones |
| **Docente 2** | `prof.elena@nexus.edu` | `Teacher123!` | Gestión de sus propios cursos, evaluaciones y planillas |
| **Estudiante 1** | `estudiante.valeria@nexus.edu` | `Student123!` | Escaneo de QR con cámara WebRTC, notas y proyección de promedio |
| **Estudiante 2** | `estudiante.mateo@nexus.edu` | `Student123!` | Escaneo de QR, consulta de notas y asistencia |

---

## 📁 Estructura del Proyecto Web PHP

```text
NexusAcademic/
├── config/
│   ├── config.php                  # Credenciales DB, secretos JWT y parámetros QR
│   └── database.php                # Conexión Singleton PDO MySQL y soporte de transacciones
├── src/
│   ├── Controllers/
│   │   ├── AuthController.php      # Login, Registro, Datos de sesión, Cambio de clave
│   │   ├── AcademicController.php  # Materias, Períodos, Grupos, Docentes, Estudiantes
│   │   ├── AttendanceController.php# Asistencia QR dinámico, Nonces anti-replay, Geocercas
│   │   ├── GradeController.php     # Evaluaciones, Planilla de notas, Promedios ponderados
│   │   └── AuditController.php     # Trazabilidad inmutable, Historial y Métricas
│   ├── Middleware/
│   │   ├── AuthMiddleware.php      # Extracción y validación de Bearer Token JWT
│   │   └── RbacMiddleware.php      # Control de acceso por roles (ADMIN, TEACHER, STUDENT)
│   └── Services/
│       ├── CryptoService.php       # JWT HS256, Firma HMAC-SHA256, Haversine GPS
│       └── GradeService.php        # Algoritmos de promedios ponderados y validaciones
├── api/
│   ├── index.php                   # Front-controller enrutador de la API REST
│   └── .htaccess                   # Reescritura Apache para rutas limpias de la API
├── database/
│   └── nexus_academic.sql          # Script DDL + DML completo para phpMyAdmin
├── public/                         # Frontend SPA y activos estáticos
│   ├── css/style.css               # Estilos de vanguardia con Glassmorphism
│   ├── js/app.js                   # Controlador cliente, audio sintético y escáner
│   └── index.html                  # Plantilla base del SPA
├── css/                            # Activos CSS en raíz para acceso web directo
├── js/                             # Activos JS en raíz para acceso web directo
├── index.php                       # Entrada web principal (valida DB y sirve la plataforma)
├── setup.php                       # Panel de diagnóstico y asistente de phpMyAdmin
├── nexus_academic.sql              # Copia raíz para importación rápida en phpMyAdmin
├── .htaccess                       # Reglas de Apache y cabeceras de seguridad
└── README.md                       # Documentación técnica
```

---

## 📡 Endpoints de la API REST (`/api/...`)

Todos los endpoints responden en formato JSON y están completamente protegidos mediante JWT y RBAC:

* **Autenticación:**
  * `POST /api/auth/register` - Registro público de usuarios
  * `POST /api/auth/login` - Inicio de sesión y expedición de JWT
  * `GET /api/auth/me` - Perfil de usuario autenticado e institución
  * `POST /api/auth/change-password` - Cambio de contraseña
* **Estructura Académica:**
  * `GET /api/academic/institution` / `PUT /api/academic/institution` (Admin)
  * `GET /api/academic/periods` / `POST /api/academic/periods` (Admin)
  * `GET /api/academic/subjects` / `POST /api/academic/subjects` (Admin)
  * `GET /api/academic/groups` / `POST /api/academic/groups` (Admin)
  * `GET /api/academic/groups/{id}` / `PUT /api/academic/groups/{id}` / `DELETE` (Docente/Admin)
  * `POST /api/academic/teacher-course` - Creación directa de curso por el docente
  * `GET /api/academic/available-courses` - Asignaturas disponibles para matrícula
  * `POST /api/academic/enrollments` - Matrícula de estudiantes
  * `GET /api/academic/teachers` / `PUT /api/academic/teachers/{id}` (Admin)
  * `GET /api/academic/students` (Admin/Docente)
* **Asistencia & QR Dinámico:**
  * `POST /api/sessions/start` - Inicia sesión de clase y firma primer token QR
  * `GET /api/sessions/{id}/qr-token` - Rota el token QR dinámico cada 20 segundos
  * `POST /api/sessions/{id}/close` - Cierra la sesión de clase
  * `POST /api/attendance/register` - Valida firma criptográfica, nonce, tiempo y geocerca GPS
  * `GET /api/attendance/session/{id}` - Asistencia en vivo y estudiantes pendientes
  * `GET /api/attendance/my-history` - Historial del estudiante
* **Evaluaciones & Calificaciones:**
  * `POST /api/evaluations` / `PUT /api/evaluations/{id}` / `DELETE` (Docente/Admin)
  * `POST /api/grades` - Registro de nota individual con justificación obligatoria
  * `POST /api/grades/batch` - Calificación masiva en lote
  * `GET /api/grades/group/{id}/matrix` - Planilla completa con promedios ponderados
  * `GET /api/grades/my-grades` - Calificaciones del estudiante con proyección
  * `GET /api/grades/{id}/history` - Auditoría inmutable de cambios de nota
* **Auditoría & Métricas:**
  * `GET /api/audit/logs` - Registro de actividades del sistema
  * `GET /api/audit/grades-history` - Trazabilidad global de ajustes de calificaciones
  * `GET /api/audit/metrics` - Estadísticas y KPIs institucionales
