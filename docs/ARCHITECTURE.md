# ARCHITECTURE — PLATAFORMA ACADÉMICA INTEGRAL (NexusAcademic)

**Fecha:** 2026-09-03  
**Versión:** 1.0.0

---

## 1. Visión General de la Arquitectura

La plataforma sigue una arquitectura en capas desacopladas (**Layered Architecture / Clean Architecture**) con separación clara de responsabilidades:

```mermaid
graph TD
    Client[Cliente Web / PWA Modern Glassmorphic UI]
    
    subgraph Frontend Layer
        Views[Admin / Docente / Estudiante Views]
        Scanner[Scanner QR & Geolocation Telemetry]
        ApiClient[Fetch API Client with JWT Interceptor]
    end
    
    subgraph Security & Routing Layer
        RateLimiter[Express Rate Limiter]
        Helmet[Security Headers / Helmet]
        AuthMiddleware[JWT Authenticator & RBAC Guard]
    end
    
    subgraph Business Logic & Controllers
        AuthCtrl[Auth & Session Controller]
        AcademicCtrl[Institución, Asignaturas, Grupos, Matrículas]
        QRCtrl[Dynamic QR & Attendance Controller]
        GradeCtrl[Evaluations, Grades & History Controller]
        AuditCtrl[Audit Logs & Metrics Controller]
    end
    
    subgraph Services & Cryptography
        QRService[HMAC-SHA256 Token Engine & TOTP Window]
        GradeService[Weighted Average & Rounding Engine]
        PresenceService[Haversine Geo-distance & Anti-Fraud]
    end
    
    subgraph Data & Persistence Layer
        DBEngine[(SQLite3 Database Engine - WAL Mode)]
        Schema[Strict Schema: Foreign Keys, Unique & Check Constraints]
        Transactions[Atomic Transactions BEGIN / COMMIT]
    end

    Client --> Views
    Views --> Scanner
    Views --> ApiClient
    ApiClient --> RateLimiter
    RateLimiter --> Helmet
    Helmet --> AuthMiddleware
    AuthMiddleware --> AuthCtrl
    AuthMiddleware --> AcademicCtrl
    AuthMiddleware --> QRCtrl
    AuthMiddleware --> GradeCtrl
    AuthMiddleware --> AuditCtrl
    
    QRCtrl --> QRService
    QRCtrl --> PresenceService
    GradeCtrl --> GradeService
    
    AuthCtrl --> DBEngine
    AcademicCtrl --> DBEngine
    QRCtrl --> DBEngine
    GradeCtrl --> DBEngine
    AuditCtrl --> DBEngine
    DBEngine --> Schema
    DBEngine --> Transactions
```

---

## 2. Modelo Relacional de Base de Datos (E-R)

```mermaid
erDiagram
    INSTITUTION ||--o{ ACADEMIC_PERIOD : "configura"
    ACADEMIC_PERIOD ||--o{ COURSE_GROUP : "contiene"
    SUBJECT ||--o{ COURSE_GROUP : "instancia"
    USER ||--o| TEACHER : "perfil docente"
    USER ||--o| STUDENT : "perfil estudiante"
    TEACHER ||--o{ COURSE_GROUP : "imparte"
    STUDENT ||--o{ ENROLLMENT : "se matricula"
    COURSE_GROUP ||--o{ ENROLLMENT : "recibe"
    
    COURSE_GROUP ||--o{ CLASS_SESSION : "ejecuta"
    CLASS_SESSION ||--o{ ATTENDANCE_TOKEN : "emite"
    CLASS_SESSION ||--o{ ATTENDANCE : "registra"
    STUDENT ||--o{ ATTENDANCE : "asiste"
    
    COURSE_GROUP ||--o{ EVALUATION : "planifica"
    EVALUATION ||--o{ GRADE : "califica"
    STUDENT ||--o{ GRADE : "obtiene"
    GRADE ||--o{ GRADE_HISTORY : "audita cambios"
    USER ||--o{ AUDIT_LOG : "genera eventos"

    INSTITUTION {
        int id PK
        string name
        string code
        string grading_scale "0-5, 0-10, 0-100"
        float min_passing_grade
        float max_grade
    }

    USER {
        int id PK
        string email UK
        string password_hash
        string first_name
        string last_name
        string role "ADMIN, TEACHER, STUDENT"
        string status "ACTIVE, INACTIVE"
        datetime created_at
    }

    STUDENT {
        int id PK
        int user_id FK, UK
        string student_code UK
        string career
    }

    TEACHER {
        int id PK
        int user_id FK, UK
        string teacher_code UK
        string department
    }

    SUBJECT {
        int id PK
        string code UK
        string name
        int credits
    }

    COURSE_GROUP {
        int id PK
        int subject_id FK
        int teacher_id FK
        int period_id FK
        string group_code
        string schedule
        string classroom
    }

    ENROLLMENT {
        int id PK
        int student_id FK
        int group_id FK
        string status "ACTIVE, DROPPED"
        datetime enrolled_at
    }

    CLASS_SESSION {
        int id PK
        int group_id FK
        int teacher_id FK
        string topic
        string status "ACTIVE, CLOSED"
        datetime started_at
        datetime closed_at
        string session_secret
        float geo_lat
        float geo_lng
        float geo_radius_meters
    }

    ATTENDANCE {
        int id PK
        int session_id FK
        int student_id FK
        string status "PRESENT, LATE, ABSENT, EXCUSED"
        datetime registered_at
        string verification_method
        string ip_address
        float distance_meters
        int is_flagged
    }

    EVALUATION {
        int id PK
        int group_id FK
        string name
        string type "PARCIAL, QUIZ, TALLER, PROYECTO"
        float weight
        float max_score
        date due_date
        string status "ACTIVE, CLOSED"
    }

    GRADE {
        int id PK
        int evaluation_id FK
        int student_id FK
        float score
        string feedback
        int graded_by_user_id FK
        datetime updated_at
    }

    GRADE_HISTORY {
        int id PK
        int grade_id FK
        int evaluation_id FK
        int student_id FK
        float old_score
        float new_score
        string change_reason
        int changed_by_user_id FK
        datetime created_at
    }

    AUDIT_LOG {
        int id PK
        int user_id FK
        string action
        string resource_type
        int resource_id
        string details_json
        string ip_address
        datetime created_at
    }
```

---

## 3. Protocolo de Asistencia con QR Criptográfico Dinámico

```mermaid
sequenceDiagram
    autonumber
    actor Prof as Profesor (Docente)
    participant UI_Prof as Panel Docente
    participant API as Servidor Backend
    participant DB as SQLite3 (WAL Mode)
    actor Est as Estudiante
    participant UI_Est as Panel Estudiante (Scanner)

    Prof->>UI_Prof: Iniciar Sesión de Clase (Grupo G)
    UI_Prof->>API: POST /api/sessions/start {group_id, topic, geo_coords}
    API->>DB: INSERT INTO class_sessions (status=ACTIVE, secret=K_s)
    API-->>UI_Prof: Sesión Iniciada {session_id, token_initial}

    loop Cada 20 segundos (Ventana Rotativa)
        UI_Prof->>API: GET /api/sessions/:id/qr-token
        API->>API: Generar nonce efímero N_t + timestamp T_now
        API->>API: HMAC = HMAC_SHA256(K_s, session_id || N_t || T_now)
        API-->>UI_Prof: Payload firmado {session_id, nonce: N_t, ts: T_now, sig: HMAC}
        UI_Prof->>UI_Prof: Renderizar QR en Pantalla / Proyector
    end

    Est->>UI_Est: Abrir Escáner de Asistencia
    UI_Est->>UI_Est: Escanear QR proyectado (captura payload) + Capturar GeoCoords
    UI_Est->>API: POST /api/attendance/register {session_id, nonce, ts, sig, coords}
    
    critical Verificación y Transacción Atómica
        API->>DB: SELECT * FROM class_sessions WHERE id = session_id AND status = 'ACTIVE'
        API->>API: Verificar HMAC con secret K_s
        API->>API: Verificar Ventana Temporal (|T_server - T_token| <= 35s)
        API->>DB: Verificar Matrícula (student_id matriculado en group_id)
        API->>DB: Verificar Nonce no reutilizado (anti-replay)
        API->>API: Calcular distancia Haversine (coords vs aula)
        API->>DB: INSERT INTO attendances (session_id, student_id, status, registered_at, method, distance)
    end

    API-->>UI_Est: HTTP 200 Asistencia Confirmada {status: 'PRESENT', time: '...'}
    API-->>UI_Prof: SSE / Polling Actualización de Asistencia en Vivo (+1 Presente)
```

---

## 4. Principios de Seguridad y Aislamiento

1. **Defensa en Profundidad:**
   - Capa 1: Validación de esquema y tipos en entrada HTTP (`express-validator` / sanitización).
   - Capa 2: Autenticación mediante JWT con firma HMAC-SHA256 y expiración acotada.
   - Capa 3: Autorización RBAC granular con verificación de propiedad del recurso.
   - Capa 4: Base de datos relacional con `PRAGMA foreign_keys = ON`, `UNIQUE` constraints y transacciones.
2. **Prevención de Inyecciones:**
   - Todas las consultas SQL se realizan mediante consultas preparadas parametrizadas (`db.prepare('... WHERE id = ?').get(id)`).
3. **Inmutabilidad de Auditoría:**
   - La tabla `grade_history` y `audit_logs` son estrictamente **Append-Only**. Ningún endpoint de la API expone `UPDATE` o `DELETE` para estas tablas.
