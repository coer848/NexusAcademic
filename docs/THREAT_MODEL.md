# THREAT MODEL & SECURITY ANALYSIS — PLATAFORMA ACADÉMICA INTEGRAL

**Fecha:** 2026-09-03  
**Versión:** 1.0.0  
**Metodología:** STRIDE (Spoofing, Tampering, Repudiation, Information Disclosure, Denial of Service, Elevation of Privilege)

---

## 1. Análisis de Amenazas y Vectores de Ataque

| ID Amenaza | Categoría STRIDE | Vector de Ataque / Escenario | Severidad | Mitigación Arquitectónica | Estado de Mitigación |
|---|---|---|---|---|---|
| **THREAT-01** | Spoofing / Tampering | **Captura y Envío de QR por Mensajería (WhatsApp/Telegram):** Un estudiante presente toma foto del QR y la envía a un compañero ausente. | Alta | Rotación rápida de QR (ventana de 20s), expiración forzada en backend, y opcionalmente geofencing con tolerancia configurable. El receptor no alcanza a escanear a tiempo antes de que expire la ventana. | Mitigado por Rotación TOTP + Ventana Temporal |
| **THREAT-02** | Tampering / Replay | **Replay Attack de Token QR:** Un atacante intercepta un payload QR previo e intenta reenviarlo repetidamente. | Crítica | Tabla de nonces efímeros usados (`attendance_tokens` o set en memoria/BD) + restricción `UNIQUE(session_id, student_id)` en BD. | Mitigado por Nonce + Unique Constraint |
| **THREAT-03** | Tampering | **Manipulación de Payload o Firma Criptográfica:** El atacante modifica el `session_id` o `timestamp` en el payload. | Crítica | Validación de firma HMAC-SHA256 con la clave secreta de la sesión (`session_secret`) almacenada exclusivamente en backend. | Mitigado por HMAC-SHA256 |
| **THREAT-04** | Elevation of Privilege | **Escalamiento Vertical (Estudiante a Docente/Admin):** Estudiante intenta llamar endpoints de creación de evaluaciones, asignación de notas o inicio de sesiones. | Crítica | Middleware de autorización RBAC (`requireRole('TEACHER', 'ADMIN')`) en cada endpoint sensible del backend. | Mitigado por RBAC Guard |
| **THREAT-05** | Information Disclosure / IDOR | **Insecure Direct Object Reference (IDOR):** Estudiante consulta notas o récord de asistencia de otros alumnos cambiando el `student_id` en la petición. | Crítica | El controlador extrae el `student_id` directamente del JWT autenticado, ignorando cualquier ID forzado por el cliente, o valida estrictamente que pertenezca al usuario. | Mitigado por JWT Context Binding |
| **THREAT-06** | Tampering | **Modificación No Autorizada de Notas por Docente Ajeno:** Un profesor intenta calificar o alterar notas de un grupo que no tiene asignado. | Crítica | Verificación de relación docente-grupo: `WHERE group.teacher_id = req.user.teacher_id`. Si no coincide, HTTP 403. | Mitigado por Resource Ownership Guard |
| **THREAT-07** | Tampering | **Alteración Silenciosa de Notas sin Registro:** Un usuario modifica una nota sin que quede rastro del valor original. | Crítica | Transacción atómica que actualiza `grades` e inserta obligatoriamente en `grade_history` con `old_score`, `new_score`, `changed_by_user_id` y `change_reason`. | Mitigado por Audit History |
| **THREAT-08** | Denial of Service | **Fuerza Bruta y Spam de Escaneo:** Envío masivo de peticiones a endpoints de login o escaneo QR para saturar la API. | Alta | Rate limiting en Express (`express-rate-limit`) por IP y por usuario autenticado. | Mitigado por Rate Limiter |
| **THREAT-09** | Tampering / Concurrency | **Race Condition en Registro de Asistencia:** Dos solicitudes concurrentes intentan registrar asistencia simultáneamente para el mismo estudiante en la misma sesión. | Alta | Transacciones con aislamiento inmediato en SQLite (`BEGIN IMMEDIATE`) y clave única `UNIQUE(session_id, student_id)`. La segunda inserción falla atómicamente por violación de constraint. | Mitigado por DB Constraints & Transactions |
| **THREAT-10** | Tampering | **Registro en Sesión Cerrada o Inexistente:** Estudiante envía token cuando el profesor ya ha concluido la clase. | Media | Verificación del estado de sesión (`status === 'ACTIVE'`). Si la sesión está cerrada (`CLOSED`), se rechaza con HTTP 400. | Mitigado por Session State Guard |

---

## 2. Análisis Crítico y Limitaciones de la Presencia Física

> [!IMPORTANT]
> **Declaración de Honestidad de Ingeniería:** Un código QR por sí solo **NUNCA** constituye una prueba matemática absoluta de presencia física sin interacción de hardware especializado (ej. torniquetes biométricos físicos o lectores RFID dedicados).

### Limitaciones Identificadas:
1. **Compartición en Tiempo Real Ultrarrápida:** Con streaming de video en vivo (ej. Discord/Meet), un estudiante ausente podría teóricamente escanear el QR rotativo si lo hace dentro de la ventana de 15-20 segundos.
2. **GPS Spoofing:** En navegadores móviles, usuarios avanzados pueden falsear coordenadas geográficas usando aplicaciones de ubicación simulada si el sistema solo depende de la API de Geolocation del navegador.

### Estrategia de Defensa en Capas implementada:
- **Capa 1 (Temporal):** Ventana de expiración ultra-corta (20 segundos) que hace inviable reenviar fotos estáticas.
- **Capa 2 (Criptográfica):** HMAC-SHA256 con secret de sesión que impide forjar tokens.
- **Capa 3 (Anti-Replay):** Token de un solo uso por estudiante.
- **Capa 4 (Telemetría de Red y Dispositivo):** Registro de IP, User-Agent y distancia calculada mediante fórmula Haversine. Si la distancia excede el radio del aula (ej. > 50 metros) o proviene de una subred ajena, se marca como sospechoso (`is_flagged = 1`) para revisión manual del docente.
- **Capa 5 (Supervisión Visual del Docente):** Panel en tiempo real que muestra contador y lista de registrados en vivo con fotos/nombres, permitiendo al profesor contrastar visualmente el número de alumnos en el aula física contra el sistema.
