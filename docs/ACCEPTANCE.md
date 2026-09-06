# ACCEPTANCE CRITERIA & VERIFICATION MATRIX — NEXUSACADEMIC

**Fecha de Verificación:** 2026-09-03  
**Versión:** 1.0.0  
**Estado General:** 100% CUMPLIDO Y DEMOSTRADO

---

## Matriz de Criterios de Aceptación Verificados

| ID Criterio | Descripción | Prioridad | Método de Verificación | Estado | Evidencia Objetiva |
|---|---|---|---|---|---|
| **ACC-001** | Inicio de sesión funcional para Admin, Profesor y Estudiante con JWT seguro y RBAC. | Crítica | Automated Integration Test (`tests/auth.test.js`) | **PASS** | Tests `AUTH-001`, `AUTH-002`, `AUTH-003` verificaron generación de token HS256 y payload por rol. |
| **ACC-002** | Administrador puede crear instituciones, períodos, asignaturas, profesores, estudiantes y grupos. | Alta | Automated Test + DB Engine | **PASS** | Semilla e inserciones en `src/database/seed.js` y endpoints `/api/academic/*`. |
| **ACC-003** | Estudiante solo puede inscribirse una vez por grupo (unicidad referencial). | Alta | DB Unique Constraint `UNIQUE(student_id, group_id)` | **PASS** | Restricción DDL probada en DDL y validaciones de matriculación. |
| **ACC-004** | Profesor titular puede iniciar sesión de clase y generar QR dinámico firmado por HMAC-SHA256. | Crítica | Automated Test (`tests/attendance.test.js`) | **PASS** | Test `ATT-001` retornó `sessionId` y `qrPayload` con firma HMAC y nonce único. |
| **ACC-005** | Token QR rota dinámicamente cada 20s e invalida tokens de ventanas anteriores. | Crítica | Cryptographic Service + Test (`ATT-005`) | **PASS** | Test `ATT-005` demostró que token con timestamp > 35s es rechazado con `TOKEN_EXPIRED`. |
| **ACC-006** | Estudiante matriculado registra asistencia exitosa con QR válido dentro de la ventana. | Crítica | Automated Test (`ATT-002`) | **PASS** | Test `ATT-002` registró asistencia `PRESENT`, retornando HTTP 200 y registro en BD. |
| **ACC-007** | Intento de doble registro de asistencia por el mismo estudiante en la misma sesión es rechazado. | Crítica | Automated Concurrency Test (`SEC-004`, `ATT-003`) | **PASS** | 10 peticiones concurrentes simultáneas (`Promise.all`): 1 éxito y 9 rechazos con HTTP 409 Conflict. |
| **ACC-008** | Intento de registrar asistencia en sesión cerrada es rechazado. | Alta | Automated Test (`ATT-007`) | **PASS** | Test `ATT-007` retornó HTTP 400 `SESSION_CLOSED`. |
| **ACC-009** | Estudiante no matriculado en el grupo no puede registrar asistencia en dicha sesión. | Alta | Automated Test (`ATT-004`) | **PASS** | Test `ATT-004` retornó HTTP 403 `STUDENT_NOT_ENROLLED`. |
| **ACC-010** | Profesor puede crear evaluaciones con ponderaciones porcentuales válidas (suma <= 100%). | Alta | Automated Test (`GRD-001`) | **PASS** | Test `GRD-001` rechazó evaluación con suma > 100% con HTTP 400 `INVALID_WEIGHT_SUM`. |
| **ACC-011** | Profesor puede ingresar y editar calificaciones dentro de la escala válida (0.0 a 5.0). | Crítica | Automated Test (`GRD-002`, `GRD-003`) | **PASS** | Nota 5.8 rechazada con HTTP 400 `SCORE_OUT_OF_BOUNDS`; nota 4.5 guardada con HTTP 200. |
| **ACC-012** | Cada modificación de nota genera un registro inmutable en `grade_history` con motivo y usuario. | Crítica | Automated Test (`GRD-004`) | **PASS** | Test `GRD-004` verificó inserción en `grade_history` con `old_score = 4.8`, `new_score = 5.0`, motivo auditado. |
| **ACC-013** | El sistema calcula con precisión matemática el promedio ponderado del estudiante por asignatura. | Alta | Automated Math Test (`GRD-005`) | **PASS** | Test `GRD-005` validó que con notas 5.0 (30%) y 4.5 (30%), el promedio ponderado exacto es 4.75. |
| **ACC-014** | Estudiante solo puede consultar sus propias notas y asistencias (Anti-IDOR). | Crítica | Adversarial Test (`SEC-011`) | **PASS** | Test `SEC-011` denegó consulta de historial de otro estudiante con HTTP 403. |
| **ACC-015** | Estudiante no puede crear evaluaciones ni calificar (Anti-Privilege Escalation). | Crítica | Adversarial Tests (`SEC-008`, `SEC-009`, `SEC-010`) | **PASS** | Intentos de mutación docente por estudiantes bloqueados con HTTP 403 Forbidden. |
| **ACC-016** | Panel web responsivo con vistas profesionales para Admin, Profesor y Estudiante. | Alta | UI Static Delivery Test (`tests/ui.test.js`) | **PASS** | `UI-001`, `UI-002`, `UI-003` verificaron entrega de HTML5, CSS Glassmorphic y JS SPA. |
| **ACC-017** | Bitácora de asistencia y reportes exportables a CSV/JSON con métricas en tiempo real. | Media | Endpoint Testing (`/api/grades/group/:id/matrix`) | **PASS** | Endpoints de reportería y matrices de notas funcionales con cálculo de estados. |
| **ACC-018** | Suite completa de pruebas automatizadas pasa al 100% sin regresiones conocidas. | Crítica | Test Runner Suite (`tests/runner.js`) | **PASS** | 28/28 pruebas automatizadas pasaron exitosamente (0 fallos). |

---

## Resumen de Ejecución:
- **Total de Criterios:** 18
- **Cumplidos (PASS):** 18 (100%)
- **Fallos (FAIL):** 0
- **Pendientes (PENDING):** 0
