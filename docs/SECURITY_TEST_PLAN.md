# SECURITY TEST PLAN — PLATAFORMA ACADÉMICA INTEGRAL

**Fecha:** 2026-09-03  
**Versión:** 1.0.0  
**Objetivo:** Especificar los casos de prueba de seguridad, penetración y auditoría adversarial que deben ser ejecutados y verificados con pruebas automatizadas.

---

## 1. Casos de Prueba de Seguridad (SEC-001 a SEC-018)

| ID Caso | Escenario de Ataque / Prueba | Procedimiento de Prueba | Resultado Esperado | Estado |
|---|---|---|---|---|
| **SEC-001** | QR Expirado | Enviar solicitud de asistencia con token QR cuya estampa de tiempo supere la ventana de tolerancia (> 30s en el pasado). | HTTP 400/422 `TOKEN_EXPIRED` y rechazo de registro en BD. | PENDING |
| **SEC-002** | QR Reutilizado / Anti-Replay | Enviar el mismo token de asistencia dos veces desde el mismo estudiante. | Primera solicitud: HTTP 200 OK. Segunda solicitud: HTTP 409 Conflict `ATTENDANCE_ALREADY_REGISTERED`. | PENDING |
| **SEC-003** | Manipulación de Firma (Tampering) | Modificar el payload del QR (ej. cambiar `session_id` o `timestamp`) manteniendo una firma inválida. | HTTP 401/403 `INVALID_SIGNATURE` y denegación inmediata. | PENDING |
| **SEC-004** | Asistencia Duplicada Concurrente | Enviar 10 peticiones simultáneas con `Promise.all` para el mismo estudiante en la misma sesión activa. | Exactamente 1 registro exitoso (HTTP 200) y 9 rechazos (HTTP 409). Integridad estricta en BD. | PENDING |
| **SEC-005** | Manipulación de Identidad de Estudiante | Estudiante A envía en el body el `student_id` del Estudiante B intentando registrar asistencia por otro. | El backend ignora el body y usa el `student_id` del JWT autenticado, o rechaza si no coinciden. | PENDING |
| **SEC-006** | Manipulación de Sesión (Sesión Cerrada) | Intentar registrar asistencia en una sesión cuyo estado sea `CLOSED`. | HTTP 400 `SESSION_CLOSED`. No se añade registro. | PENDING |
| **SEC-007** | Acceso a Grupo Ajeno por Estudiante | Estudiante intenta registrar asistencia en una sesión de un grupo en el cual NO está matriculado. | HTTP 403 `STUDENT_NOT_ENROLLED`. | PENDING |
| **SEC-008** | Escalamiento de Privilegios Vertical | Estudiante autenticado envía petición a `POST /api/sessions/start` o `POST /api/evaluations`. | HTTP 403 Forbidden `ACCESS_DENIED`. | PENDING |
| **SEC-009** | Modificación de Nota por Estudiante | Estudiante autenticado envía petición a `PUT /api/grades/:id`. | HTTP 403 Forbidden `ACCESS_DENIED`. | PENDING |
| **SEC-010** | Consulta de Nota Ajena (IDOR) | Estudiante A solicita `GET /api/grades/student/:otherStudentId`. | HTTP 403 Forbidden o respuesta filtrada solo a sus propias notas. | PENDING |
| **SEC-011** | Manipulación de Evaluación por Docente Ajeno | Docente A intenta modificar una evaluación creada en un grupo del Docente B. | HTTP 403 Forbidden `NOT_COURSE_TEACHER`. | PENDING |
| **SEC-012** | Manipulación de Ponderaciones Inválidas | Crear evaluación con ponderación negativa (-10%) o suma total > 100%. | HTTP 400 Bad Request `INVALID_WEIGHT_SUM`. | PENDING |
| **SEC-013** | Acceso No Autenticado a Recursos Privados | Solicitar endpoints privados sin cabecera `Authorization: Bearer <token>`. | HTTP 401 Unauthorized `AUTHENTICATION_REQUIRED`. | PENDING |
| **SEC-014** | Manipulación de Calificación Fuera de Rango | Enviar una calificación con nota = 7.5 en una escala institucional de 0.0 - 5.0, o nota negativa (-1.0). | HTTP 400 Bad Request `SCORE_OUT_OF_BOUNDS`. | PENDING |
| **SEC-015** | Inyección SQL en Parámetros y Búsqueda | Enviar payloads maliciosos `1' OR '1'='1` en parámetros de consulta o búsqueda. | Consultas preparadas parametrizadas impiden inyección; respuesta segura sin fugas de datos. | PENDING |
| **SEC-016** | Fuga de Información de Contraseñas / Hashes | Solicitar endpoints de usuarios o perfiles y validar si el payload JSON expone `password_hash`. | El campo `password_hash` nunca debe estar presente en respuestas JSON (`undefined` o excluido explícitamente). | PENDING |
| **SEC-017** | Inmutabilidad de la Tabla de Historial | Intentar invocar operaciones de borrado o actualización directa sobre `grade_history`. | No existen endpoints de modificación; la base de datos mantiene integridad estricta append-only. | PENDING |
| **SEC-018** | Replay en Sesión de Diferente Período | Reutilizar un token QR generado para una sesión anterior en una nueva sesión activa. | Rechazo inmediato por discordancia de clave secreta (`session_secret`) y nonce. | PENDING |

---

## 2. Automatización y Ejecución

Todos los casos de prueba deben implementarse en suites automatizadas de Node.js:
- `tests/auth.test.js`
- `tests/attendance.test.js`
- `tests/grades.test.js`
- `tests/security.test.js` (Adversarial Security Suite)
