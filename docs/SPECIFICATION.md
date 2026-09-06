# SPECIFICATION — PLATAFORMA ACADÉMICA INTEGRAL (NexusAcademic / NexusQR)

**Fecha:** 2026-09-03  
**Versión:** 1.0.0  
**Estado:** Activo y Verificable

---

## 1. Módulo de Autenticación, Usuarios y Roles (RBAC)

### REQ-001: Autenticación Segura con JWT y Hashing Bcrypt
- **Descripción:** El sistema debe permitir el inicio de sesión para Administradores, Profesores y Estudiantes utilizando credenciales únicas (email y contraseña), retornando un token JWT firmado y seguro. Las contraseñas deben almacenarse usando un salt de bcrypt con factor de costo adecuado (mínimo 10).
- **Prioridad:** Crítica
- **Criterio de Aceptación:** Solicitud con credenciales válidas retorna token JWT y datos de perfil; solicitud con credenciales incorrectas retorna HTTP 401 Unauthorized sin revelar si el usuario existe o no.
- **Método de Prueba:** Test de integración de autenticación (`tests/auth.test.js`).
- **Estado:** PENDING

### REQ-002: Control de Acceso Basado en Roles (RBAC)
- **Descripción:** El backend debe autorizar cada solicitud a endpoints protegidos verificando el rol (`ADMIN`, `TEACHER`, `STUDENT`) y la pertenencia a los recursos solicitados.
- **Prioridad:** Crítica
- **Criterio de Aceptación:** Si un usuario intenta acceder a una ruta fuera de su rol o a recursos ajenos, el servidor debe responder HTTP 403 Forbidden.
- **Método de Prueba:** Tests de seguridad RBAC y ataque de escalamiento vertical/horizontal (`tests/security.test.js`).
- **Estado:** PENDING

---

## 2. Módulo de Estructura Académica

### REQ-003: Gestión de Institución y Períodos Académicos
- **Descripción:** El Administrador puede crear, listar, actualizar y cerrar períodos académicos (ej. 2026-1), así como configurar la escala de notas de la institución (ej. 0.0 - 5.0).
- **Prioridad:** Alta
- **Criterio de Aceptación:** El administrador puede alternar el período activo; las asignaturas y grupos se vinculan estrictamente al período correspondiente.
- **Método de Prueba:** Test de integración de estructura académica.
- **Estado:** PENDING

### REQ-004: Gestión de Asignaturas, Grupos y Matrículas
- **Descripción:** El Administrador puede registrar asignaturas, asignar profesores a grupos específicos e inscribir estudiantes en grupos.
- **Prioridad:** Alta
- **Criterio de Aceptación:** Un estudiante solo puede figurar como matriculado una vez por grupo (`UNIQUE(student_id, group_id)`). Los profesores solo pueden visualizar y gestionar grupos que tienen asignados.
- **Método de Prueba:** Test de integridad referencial de base de datos.
- **Estado:** PENDING

---

## 3. Módulo de Asistencia y QR Dinámico

### REQ-005: Inicio y Control de Sesión de Clase
- **Descripción:** El profesor asignado a un grupo puede iniciar una sesión de clase, definir el tema, activar la ventana de toma de asistencia y cerrar la sesión.
- **Prioridad:** Crítica
- **Criterio de Aceptación:** Solo el profesor titular del grupo puede iniciar o cerrar la sesión. Una sesión cerrada no acepta nuevos registros de asistencia bajo ninguna circunstancia.
- **Método de Prueba:** Test de flujo de sesión de asistencia.
- **Estado:** PENDING

### REQ-006: Generación de QR Dinámico y Rotación Criptográfica
- **Descripción:** Durante una sesión activa, el sistema genera tokens QR efímeros firmados criptográficamente (HMAC-SHA256) con una ventana de validez configurable (ej. 20 segundos). Al expirar la ventana, se rota el token automáticamente.
- **Prioridad:** Crítica
- **Criterio de Aceptación:** El QR contiene un payload seguro (session_id, timestamp, nonce, signature). Un token con timestamp fuera de la ventana de validez es rechazado inmediatamente con código HTTP 400/422.
- **Método de Prueba:** Test de validación de tokens QR expirados y firmas manipuladas (`SEC-001`, `SEC-003`).
- **Estado:** PENDING

### REQ-007: Registro de Asistencia y Regla de Unicidad
- **Descripción:** El estudiante autenticado escanea el QR dinámico o envía el token. El servidor valida la firma, la expiración, la matrícula del estudiante en el grupo, y registra la asistencia.
- **Prioridad:** Crítica
- **Criterio de Aceptación:** Se cumple estrictamente: $\text{1 Estudiante} + \text{1 Sesión} \le \text{1 Asistencia}$. Reintentos o duplicados son rechazados atómicamente por la base de datos sin generar inconsistencias.
- **Método de Prueba:** Test de concurrencia y duplicación (`SEC-004`).
- **Estado:** PENDING

### REQ-008: Verificación de Presencia Física y Telemetría
- **Descripción:** El sistema valida capas complementarias de presencia física (rotación rápida de token QR, geolocalización opcional respecto a coordenadas del aula con radio de tolerancia, y registro de IP/User-Agent).
- **Prioridad:** Alta
- **Criterio de Aceptación:** El profesor puede visualizar la bitácora con marcas de tiempo precisas, registros sospechosos (fuera de radio o con anomalías de red) y porcentaje de asistencia.
- **Método de Prueba:** Test de verificación de telemetría y geofence.
- **Estado:** PENDING

---

## 4. Módulo de Evaluaciones, Calificaciones y Promedios

### REQ-009: Creación y Configuración de Evaluaciones
- **Descripción:** El profesor puede crear evaluaciones para su grupo (Parcial, Quiz, Taller, Proyecto, etc.), asignando una ponderación porcentual (%) y fecha límite.
- **Prioridad:** Alta
- **Criterio de Aceptación:** El sistema valida que la ponderación sea mayor a 0 y que la suma de ponderaciones de las evaluaciones no supere el 100%. Solo el docente del grupo puede crear/editar evaluaciones.
- **Método de Prueba:** Test de validación de evaluaciones y ponderaciones.
- **Estado:** PENDING

### REQ-010: Registro y Edición de Calificaciones con Validación de Rango
- **Descripción:** El profesor puede calificar a los estudiantes matriculados en una evaluación. Las notas deben estar estrictamente dentro del rango configurado en la escala institucional (ej. 0.0 a 5.0).
- **Prioridad:** Crítica
- **Criterio de Aceptación:** Notas fuera de rango o con formato inválido son rechazadas en backend (HTTP 400). Los estudiantes no pueden modificar notas bajo ninguna circunstancia.
- **Método de Prueba:** Test de límites de notas y pruebas de mutación no autorizada.
- **Estado:** PENDING

### REQ-011: Cálculo de Promedios Ponderados en Tiempo Real
- **Descripción:** El sistema calcula el promedio acumulado del estudiante en cada asignatura aplicando la fórmula matemática:
  $$\text{Promedio} = \frac{\sum_{i=1}^n (\text{Nota}_i \times \text{Peso}_i)}{\sum_{i=1}^n \text{Peso}_i}$$
  con redondeo a 2 decimales y manejo transparente de evaluaciones pendientes.
- **Prioridad:** Alta
- **Criterio de Aceptación:** Cálculos matemáticos demostrados con casos extremos (notas en 0, notas máximas, notas con pesos dispares, evaluaciones parciales).
- **Método de Prueba:** Test de cálculo numérico y redondeo.
- **Estado:** PENDING

### REQ-012: Historial Inmutable y Auditoría de Modificaciones de Notas
- **Descripción:** Cada vez que una calificación es creada o actualizada, se genera un registro inmutable en `grade_history` con `old_score`, `new_score`, `changed_by_user_id`, `timestamp` y `change_reason`.
- **Prioridad:** Crítica
- **Criterio de Aceptación:** Ningún usuario (incluyendo profesores o administradores) puede alterar o eliminar registros históricos mediante la API regular. El historial es consultable para auditorías académicas.
- **Método de Prueba:** Test de inmutabilidad del historial de calificaciones (`SEC-010`).
- **Estado:** PENDING

---

## 5. Módulo de Privacidad, Reportes e Interfaz de Usuario

### REQ-013: Aislamiento Estricto de Datos de Estudiantes (Anti-IDOR)
- **Descripción:** Un estudiante autenticado únicamente puede consultar sus propias notas, sus asistencias y sus grupos matriculados.
- **Prioridad:** Crítica
- **Criterio de Aceptación:** Cualquier intento de consultar la nota o asistencia de otro estudiante especificando su `student_id` en parámetros o URL es interceptado y denegado (HTTP 403 / 404).
- **Método de Prueba:** Test adversarial de vulnerabilidad IDOR (`SEC-007`, `SEC-009`).
- **Estado:** PENDING

### REQ-014: Reportes Académicos y Exportación
- **Descripción:** Profesores y Administradores pueden generar reportes detallados de asistencia y rendimiento académico por grupo y por estudiante, exportables a CSV/JSON.
- **Prioridad:** Media
- **Criterio de Aceptación:** El reporte consolida asistencias (totales, tardanzas, ausencias), notas desglosadas por evaluación y promedio final.
- **Método de Prueba:** Test de generación de reportes.
- **Estado:** PENDING

### REQ-015: Experiencia de Usuario Profesional y Adaptativa
- **Descripción:** Interfaz moderna con paneles diferenciados para Administrador, Profesor y Estudiante, diseño Glassmorphism, feedback visual inmediato, estados de carga, confirmaciones y diseño responsive multidispositivo.
- **Prioridad:** Alta
- **Criterio de Aceptación:** Navegación fluida, tiempos de respuesta menores a 100ms en operaciones locales y accesibilidad adecuada.
- **Método de Prueba:** Verificación UI e inspección en navegador.
- **Estado:** PENDING
