-- ====================================================================
-- NEXUS ACADEMIC PLATFORM - DDL RELATIONAL DATABASE SCHEMA
-- Engine: SQLite3 (WAL Mode, Foreign Keys, Strict Unique Constraints)
-- ====================================================================

PRAGMA foreign_keys = ON;

-- 1. INSTITUTIONS
CREATE TABLE IF NOT EXISTS institutions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  code TEXT NOT NULL UNIQUE,
  grading_scale TEXT NOT NULL DEFAULT '0-5', -- '0-5', '0-10', '0-100'
  min_grade REAL NOT NULL DEFAULT 0.0,
  max_grade REAL NOT NULL DEFAULT 5.0,
  min_passing_grade REAL NOT NULL DEFAULT 3.0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 2. ACADEMIC PERIODS
CREATE TABLE IF NOT EXISTS academic_periods (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  institution_id INTEGER NOT NULL DEFAULT 1,
  name TEXT NOT NULL, -- e.g. "2026-1", "2026-2"
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0, 1)),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE
);

-- 3. USERS (ADMIN, TEACHER, STUDENT)
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL UNIQUE COLLATE NOCASE,
  password_hash TEXT NOT NULL,
  first_name TEXT NOT NULL,
  last_name TEXT NOT NULL,
  role TEXT NOT NULL CHECK(role IN ('ADMIN', 'TEACHER', 'STUDENT')),
  status TEXT NOT NULL DEFAULT 'ACTIVE' CHECK(status IN ('ACTIVE', 'INACTIVE')),
  avatar_url TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 4. TEACHERS (Role Extension)
CREATE TABLE IF NOT EXISTS teachers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL UNIQUE,
  teacher_code TEXT NOT NULL UNIQUE,
  department TEXT NOT NULL,
  specialty TEXT,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 5. STUDENTS (Role Extension)
CREATE TABLE IF NOT EXISTS students (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL UNIQUE,
  student_code TEXT NOT NULL UNIQUE,
  career TEXT NOT NULL,
  semester INTEGER NOT NULL DEFAULT 1,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 6. SUBJECTS
CREATE TABLE IF NOT EXISTS subjects (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  credits INTEGER NOT NULL DEFAULT 3,
  description TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 7. COURSE GROUPS (SUBJECT + TEACHER + PERIOD)
CREATE TABLE IF NOT EXISTS course_groups (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  subject_id INTEGER NOT NULL,
  teacher_id INTEGER NOT NULL,
  period_id INTEGER NOT NULL,
  group_code TEXT NOT NULL, -- e.g. "G01", "G02"
  schedule TEXT NOT NULL, -- e.g. "Lun-Mie 08:00 - 10:00"
  classroom TEXT NOT NULL, -- e.g. "Lab 302"
  geo_lat REAL DEFAULT 4.6097, -- default classroom coordinates for geofence check
  geo_lng REAL DEFAULT -74.0817,
  geo_radius_meters REAL DEFAULT 100.0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(subject_id, period_id, group_code),
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT,
  FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT,
  FOREIGN KEY (period_id) REFERENCES academic_periods(id) ON DELETE RESTRICT
);

-- 8. ENROLLMENTS (STUDENT <-> GROUP)
CREATE TABLE IF NOT EXISTS enrollments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  student_id INTEGER NOT NULL,
  group_id INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'ACTIVE' CHECK(status IN ('ACTIVE', 'DROPPED', 'COMPLETED')),
  enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(student_id, group_id),
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (group_id) REFERENCES course_groups(id) ON DELETE CASCADE
);

-- 9. CLASS SESSIONS (FOR ATTENDANCE & QR)
CREATE TABLE IF NOT EXISTS class_sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  group_id INTEGER NOT NULL,
  teacher_id INTEGER NOT NULL,
  topic TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'ACTIVE' CHECK(status IN ('ACTIVE', 'CLOSED')),
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME,
  session_secret TEXT NOT NULL, -- Random 64-char hex key used for HMAC signing
  geo_lat REAL,
  geo_lng REAL,
  geo_radius_meters REAL DEFAULT 100.0,
  FOREIGN KEY (group_id) REFERENCES course_groups(id) ON DELETE CASCADE,
  FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT
);

-- 10. ATTENDANCE TOKENS (USED NONCES FOR REPLAY PROTECTION)
CREATE TABLE IF NOT EXISTS attendance_tokens (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id INTEGER NOT NULL,
  nonce TEXT NOT NULL UNIQUE,
  student_id INTEGER NOT NULL,
  used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (session_id) REFERENCES class_sessions(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- 11. ATTENDANCE RECORDS (1 STUDENT + 1 SESSION <= 1 RECORD)
CREATE TABLE IF NOT EXISTS attendances (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id INTEGER NOT NULL,
  student_id INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'PRESENT' CHECK(status IN ('PRESENT', 'LATE', 'ABSENT', 'EXCUSED')),
  registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  verification_method TEXT NOT NULL DEFAULT 'QR_DYNAMIC',
  ip_address TEXT,
  user_agent TEXT,
  device_info TEXT,
  distance_meters REAL,
  is_flagged INTEGER NOT NULL DEFAULT 0 CHECK(is_flagged IN (0, 1)),
  flag_reason TEXT,
  UNIQUE(session_id, student_id), -- CRITICAL INVARIANT: 1 student max 1 record per session
  FOREIGN KEY (session_id) REFERENCES class_sessions(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- 12. EVALUATIONS
CREATE TABLE IF NOT EXISTS evaluations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  group_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  description TEXT,
  type TEXT NOT NULL CHECK(type IN ('PARCIAL', 'FINAL', 'QUIZ', 'TALLER', 'PROYECTO', 'EXPOSICION', 'PARTICIPACION')),
  weight REAL NOT NULL CHECK(weight > 0 AND weight <= 100), -- Weight percentage (e.g. 30.0 for 30%)
  max_score REAL NOT NULL DEFAULT 5.0 CHECK(max_score > 0),
  due_date DATE,
  status TEXT NOT NULL DEFAULT 'ACTIVE' CHECK(status IN ('ACTIVE', 'CLOSED')),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (group_id) REFERENCES course_groups(id) ON DELETE CASCADE
);

-- 13. GRADES (STUDENT SCORES ON EVALUATIONS)
CREATE TABLE IF NOT EXISTS grades (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  evaluation_id INTEGER NOT NULL,
  student_id INTEGER NOT NULL,
  score REAL NOT NULL CHECK(score >= 0.0 AND score <= 100.0),
  feedback TEXT,
  graded_by_user_id INTEGER NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(evaluation_id, student_id),
  FOREIGN KEY (evaluation_id) REFERENCES evaluations(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (graded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);

-- 14. GRADE HISTORY (IMMUTABLE AUDIT TRAIL)
CREATE TABLE IF NOT EXISTS grade_history (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  grade_id INTEGER NOT NULL,
  evaluation_id INTEGER NOT NULL,
  student_id INTEGER NOT NULL,
  old_score REAL,
  new_score REAL NOT NULL,
  change_reason TEXT NOT NULL,
  changed_by_user_id INTEGER NOT NULL,
  ip_address TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (grade_id) REFERENCES grades(id) ON DELETE CASCADE,
  FOREIGN KEY (evaluation_id) REFERENCES evaluations(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);

-- 15. AUDIT LOGS (GENERAL SYSTEM AUDITING)
CREATE TABLE IF NOT EXISTS audit_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER,
  action TEXT NOT NULL,
  resource_type TEXT NOT NULL,
  resource_id TEXT,
  details_json TEXT,
  ip_address TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ================= INDEXES FOR HIGH-EFFICIENCY LOOKUPS =================
CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);
CREATE INDEX IF NOT EXISTS idx_users_role ON users(role);
CREATE INDEX IF NOT EXISTS idx_teachers_user ON teachers(user_id);
CREATE INDEX IF NOT EXISTS idx_students_user ON students(user_id);
CREATE INDEX IF NOT EXISTS idx_groups_teacher ON course_groups(teacher_id);
CREATE INDEX IF NOT EXISTS idx_groups_subject ON course_groups(subject_id);
CREATE INDEX IF NOT EXISTS idx_enrollments_student ON enrollments(student_id);
CREATE INDEX IF NOT EXISTS idx_enrollments_group ON enrollments(group_id);
CREATE INDEX IF NOT EXISTS idx_sessions_group ON class_sessions(group_id);
CREATE INDEX IF NOT EXISTS idx_sessions_status ON class_sessions(status);
CREATE INDEX IF NOT EXISTS idx_attendances_session ON attendances(session_id);
CREATE INDEX IF NOT EXISTS idx_attendances_student ON attendances(student_id);
CREATE INDEX IF NOT EXISTS idx_evaluations_group ON evaluations(group_id);
CREATE INDEX IF NOT EXISTS idx_grades_eval ON grades(evaluation_id);
CREATE INDEX IF NOT EXISTS idx_grades_student ON grades(student_id);
CREATE INDEX IF NOT EXISTS idx_grade_hist_grade ON grade_history(grade_id);
CREATE INDEX IF NOT EXISTS idx_audit_user ON audit_logs(user_id);
CREATE INDEX IF NOT EXISTS idx_audit_action ON audit_logs(action);
