# IMPLEMENTATION PLAN — PLATAFORMA ACADÉMICA INTEGRAL

**Fecha:** 2026-09-03  
**Versión:** 1.0.0  
**Estrategia:** Desarrollo Incremental con Ciclos de Verificación y Auditoría Adversarial

---

## Desglose de Tareas de Ingeniería

### Fase 1: Fundación del Proyecto y Persistencia de Datos
- [ ] **TASK-001: Fundación del Proyecto:** Inicializar `package.json`, scripts de arranque, testing, linting y dependencias de seguridad (`express`, `better-sqlite3` / `sqlite3`, `jsonwebtoken`, `bcryptjs`, `cors`, `helmet`, `qrcode`, `uuid`).
- [ ] **TASK-002: Base de Datos Relacional y Esquema DDL:** Diseñar e implementar `src/database/schema.sql` y `src/database/db.js` con soporte de claves foráneas, restricciones de unicidad, índices y transacciones atómicas.
- [ ] **TASK-003: Seed y Datos Iniciales de Demostración:** Crear script `src/database/seed.js` con una institución, períodos académicos, asignaturas, profesores, estudiantes, grupos y matrículas realistas.

### Fase 2: Autenticación, Autorización y Núcleo de Seguridad
- [ ] **TASK-004: Módulo de Autenticación (JWT & Bcrypt):** Implementar servicio de hashing, generación/verificación de tokens JWT, login y perfil en `src/controllers/authController.js`.
- [ ] **TASK-005: Middleware de Seguridad y RBAC:** Implementar `src/middleware/auth.js` y `src/middleware/rbac.js` con validación estricta de roles (`ADMIN`, `TEACHER`, `STUDENT`) y propiedad de recursos.

### Fase 3: Estructura Académica (Admin & Gestión)
- [ ] **TASK-006: Controladores Académicos:** Endpoints para períodos, asignaturas, profesores, estudiantes, grupos y matrículas (`src/controllers/academicController.js`).
- [ ] **TASK-007: Rutas de Administración y Auditoría:** Endpoints de auditoría global (`src/controllers/auditController.js`).

### Fase 4: Motor de Asistencia y QR Dinámico
- [ ] **TASK-008: Servicio de QR Criptográfico y TOTP:** Motor en `src/services/qrService.js` para generación de tokens efímeros firmados por HMAC-SHA256 y ventana temporal rotativa.
- [ ] **TASK-009: Controlador de Sesiones de Clase y Asistencia:** Iniciar sesión, generar token rotativo, validar token, geofence/telemetría y registro atómico de asistencia en `src/controllers/attendanceController.js`.
- [ ] **TASK-010: Prevención de Fraude y Anti-Replay:** Restricción de no reutilización de nonces y verificación estricta de que $\text{Estudiante} + \text{Sesión} \le 1 \text{ Asistencia}$.

### Fase 5: Evaluaciones, Calificaciones y Promedios
- [ ] **TASK-011: Módulo de Evaluaciones:** Creación y configuración de evaluaciones con ponderaciones porcentuales (`src/controllers/evaluationController.js`).
- [ ] **TASK-012: Módulo de Calificaciones y Validación de Escala:** Asignación y edición de notas con validación de límites (0.0 - 5.0) en `src/controllers/gradeController.js`.
- [ ] **TASK-013: Servicio de Cálculo de Promedios Ponderados:** Motor matemático con manejo de redondeo y evaluaciones pendientes en `src/services/gradeService.js`.
- [ ] **TASK-014: Historial Inmutable de Notas:** Registro automático y obligatorio en `grade_history` con motivo del cambio y usuario auditor.

### Fase 6: Frontend Integral (UI/UX Glassmorphic de Alto Impacto)
- [ ] **TASK-015: Arquitectura Frontend y Cliente API:** Cliente API modular con manejo de tokens, estados reactivos y notificaciones Toast en `public/js/api.js`.
- [ ] **TASK-016: Panel de Administración:** Dashboard interactivo para gestionar la institución, usuarios, asignaturas y visualizar logs de auditoría.
- [ ] **TASK-017: Panel de Profesor:** Dashboard para gestionar grupos, iniciar sesiones con QR rotativo en vivo, monitor de asistencia en tiempo real, gestor de evaluaciones y matriz de calificaciones.
- [ ] **TASK-018: Panel de Estudiante:** Dashboard con escáner de cámara QR integrado, visualización de historial de asistencia, asignaturas matriculadas, notas parciales, observaciones y promedios calculados.
- [ ] **TASK-019: Estilos y Diseño Visual Premium (Vanilla CSS):** `public/css/style.css` con Glassmorphism moderno, diseño adaptativo para móviles/tablets/desktop, micro-animaciones y paleta de colores HSL.

### Fase 7: Testing Exhaustivo, Pruebas de Seguridad y Regresión
- [ ] **TASK-020: Suite de Tests Unitarios e Integración:** `tests/auth.test.js`, `tests/academic.test.js`, `tests/attendance.test.js`, `tests/grades.test.js`.
- [ ] **TASK-021: Suite de Tests de Seguridad Adversarial:** `tests/security.test.js` probando todos los casos `SEC-001` a `SEC-018` (replay, IDOR, expired QR, duplicate attendance, privilege escalation, etc.).
- [ ] **TASK-022: Reporte Final y Evidencia:** Actualizar `docs/ACCEPTANCE.md` y generar `docs/FINAL_REPORT.md` con resultados verificables.
