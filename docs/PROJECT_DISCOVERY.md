# PROJECT DISCOVERY — PLATAFORMA ACADÉMICA INTEGRAL (NexusAcademic / NexusQR)

**Fecha:** 2026-09-03  
**Versión:** 1.0.0  
**Autor:** Equipo de Ingeniería de Software (Antigravity Autonomous Team)

---

## 1. Estado Inicial del Workspace

El análisis del entorno y del espacio de trabajo revela la existencia de:
- Un subdirectorio `Prototipo/` que contiene una interfaz estática de demostración previa (`index.html`, `style.css`, `app.js`).
- Dicho prototipo simulaba visualmente un terminal de escaneo y un generador de credenciales QR estático/pseudoaleatorio, con datos mockeados en memoria del navegador (`localStorage` / objetos JS en memoria).
- **Limitaciones del prototipo previo:**
  1. No existía backend, autenticación real, sesiones persistentes ni base de datos relacional.
  2. No implementaba RBAC (Roles: Administrador, Profesor, Estudiante).
  3. No contaba con validación criptográfica de tokens QR efímeros ni protección contra replay attack en servidor.
  4. Carecía de módulos de Evaluación, Calificaciones, Ponderaciones, Cálculo de Promedios e Historial Auditado de Notas.
  5. Carecía de pruebas automatizadas (Unit, Integration, Security, Adversarial).

---

## 2. Stack Tecnológico Seleccionado

Para garantizar máxima fiabilidad, cero fricción de ejecución en el entorno local de Windows/Node.js, portabilidad, seguridad y alta velocidad:

- **Backend & API Runtime:** Node.js (ES Modules / Express REST API) con middleware de seguridad (`helmet`, `cors`, `rate-limit`, `jsonwebtoken`, `bcryptjs`).
- **Base de Datos & Persistencia:** SQLite3 relacional con modo WAL (Write-Ahead Logging), claves foráneas (`PRAGMA foreign_keys = ON`), restricciones de unicidad (`UNIQUE`), restricciones `CHECK` para rangos de notas y estados, e índices optimizados para auditoría y consultas frecuentes.
- **Criptografía & QR:** `crypto` nativo de Node.js (HMAC-SHA256, tokens temporales con ventana de expiración rotativa tipo TOTP, nonces únicos) + generación y decodificación de códigos QR estándar.
- **Frontend & UI/UX:** HTML5 semántico, Vanilla CSS de alto impacto estético (Glassmorphism, Tailwind-free, variables CSS de diseño atómico, micro-animaciones, tipografía moderna Plus Jakarta Sans y JetBrains Mono) y Vanilla JavaScript modular para los paneles de Administrador, Profesor y Estudiante.
- **Testing & QA Suite:** Test Runner nativo de Node.js (`node:test`, `node:assert`) y scripts de auditoría adversarial automatizados para verificar penetración, IDOR, race conditions y consistencia transaccional.

---

## 3. Identificación de Entidades y Modelo de Dominio

1. **Institución (`institutions`):** Datos globales y configuración de escala de notas.
2. **Período Académico (`academic_periods`):** Ej. 2026-1, 2026-2 con fechas de inicio/fin y estado (activo/cerrado).
3. **Usuarios & Credenciales (`users`):** `id`, `email`, `password_hash`, `first_name`, `last_name`, `role` (ADMIN, TEACHER, STUDENT), `status`, `created_at`.
4. **Perfiles de Rol (`teachers`, `students`):** Información extendida (código estudiantil, especialidad, departamento).
5. **Asignaturas (`subjects`):** Código de asignatura, nombre, créditos, descripción.
6. **Grupos / Cursos (`courses` / `groups`):** Asignatura vinculada, profesor asignado, período académico, aula, horario.
7. **Matrículas (`enrollments`):** Relación N:M entre Estudiante y Grupo con estado (activo, retirado).
8. **Sesiones de Clase (`class_sessions`):** `id`, `group_id`, `teacher_id`, `topic`, `status` (PENDING, ACTIVE, CLOSED), `started_at`, `closed_at`, `session_secret`, `geo_lat`, `geo_lng`, `geo_radius_meters`.
9. **Tokens de Asistencia QR (`attendance_tokens`):** Nonce único, session_id, hash de validación, expiración (timestamp), utilizado (0/1).
10. **Registros de Asistencia (`attendances`):** `id`, `session_id`, `student_id`, `status` (PRESENT, LATE, ABSENT, EXCUSED), `registered_at`, `verification_method`, `ip_address`, `device_info`, `distance_meters`, `is_flagged`.
11. **Evaluaciones (`evaluations`):** `id`, `group_id`, `name`, `description`, `type` (PARCIAL, QUIZ, TALLER, PROYECTO, PARTICIPACION), `weight` (ponderación en %), `max_score`, `min_passing_score`, `due_date`, `status`.
12. **Calificaciones (`grades`):** `id`, `evaluation_id`, `student_id`, `score`, `feedback`, `graded_by_user_id`, `created_at`, `updated_at`.
13. **Historial de Notas (`grade_history`):** `id`, `grade_id`, `evaluation_id`, `student_id`, `old_score`, `new_score`, `change_reason`, `changed_by_user_id`, `created_at`.
14. **Bitácora de Auditoría Global (`audit_logs`):** `id`, `user_id`, `action`, `resource_type`, `resource_id`, `payload_summary`, `ip_address`, `status`, `created_at`.

---

## 4. Riesgos y Supuestos Técnicos

| Riesgo Identificado | Impacto | Mitigación Arquitectural |
|---|---|---|
| Compartir captura de pantalla de QR por WhatsApp | Alto | QR con rotación rápida (15-30s), tokens HMAC de un solo uso con invalidación instantánea en BD, y verificación de proximidad/geofencing opcional. |
| Inyección de Calificaciones / IDOR | Crítico | Validación estricta en middleware de backend de que el `user_id` autenticado sea el profesor titular del grupo asociado a la evaluación. |
| Modificación de notas por Estudiantes | Crítico | Verificación de rol en capa de autorización de backend; rechazo en endpoints de mutación con código HTTP 403 Forbidden. |
| Asistencia duplicada o Race Condition | Alto | Restricción `UNIQUE(session_id, student_id)` a nivel de base de datos relacional y transacciones `BEGIN IMMEDIATE`. |
| Manipulación de Ponderaciones | Medio | Validación matemática de que la suma de ponderaciones no exceda el 100% o cálculo normalizado estandarizado. |

---

## 5. Alcance y Fuera de Alcance

### En Alcance:
- Servidor REST seguro con autenticación JWT, hashing bcrypt y RBAC granular (Admin, Docente, Estudiante).
- Base de datos relacional SQLite completa con integridad referencial, transacciones y constraints.
- Motor de QR dinámico seguro con rotación en tiempo real y validación criptográfica en backend.
- Mecanismos de verificación de presencia física (análisis de amenazas, geofencing, IP/dispositivo, tokens efímeros).
- Módulo académico completo de Evaluaciones, Ponderaciones, Calificaciones, Promedios e Historial Auditado.
- Paneles UI interactivos, responsivos y visualmente impresionantes para Administrador, Profesor y Estudiante.
- Suite completa de pruebas unitarias, de integración, de seguridad y ataque adversarial.
- Documentación exhaustiva en `docs/`.

### Fuera de Alcance:
- Integración con pasarelas de pago de matrículas financieras (no solicitado en la especificación académica).
- Hardware biométrico físico propietario (se usa cámara web/móvil y análisis de presencia por software).
