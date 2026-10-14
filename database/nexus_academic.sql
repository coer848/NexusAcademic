-- ====================================================================
-- NEXUS ACADEMIC PLATFORM - DDL & SEED DATA (MYSQL / MARIADB)
-- Compatible con phpMyAdmin, XAMPP, MariaDB 10.4+ y MySQL 8.0+
-- Base de Datos: nexusacademic
-- ====================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- 1. Crear Base de Datos si no existe
CREATE DATABASE IF NOT EXISTS `nexusacademic` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `nexusacademic`;

-- ====================================================================
-- 2. ELIMINAR TABLAS EXISTENTES (ORDEN INVERSO DE DEPENDENCIAS)
-- ====================================================================
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `grade_history`;
DROP TABLE IF EXISTS `grades`;
DROP TABLE IF EXISTS `evaluations`;
DROP TABLE IF EXISTS `attendance_tokens`;
DROP TABLE IF EXISTS `attendances`;
DROP TABLE IF EXISTS `class_sessions`;
DROP TABLE IF EXISTS `enrollments`;
DROP TABLE IF EXISTS `course_groups`;
DROP TABLE IF EXISTS `subjects`;
DROP TABLE IF EXISTS `students`;
DROP TABLE IF EXISTS `teachers`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `academic_periods`;
DROP TABLE IF EXISTS `institutions`;

-- ====================================================================
-- 3. ESTRUCTURA DE TABLAS RELACIONALES
-- ====================================================================

-- 3.1 INSTITUCIONES
CREATE TABLE `institutions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `code` VARCHAR(50) NOT NULL UNIQUE,
  `grading_scale` VARCHAR(20) NOT NULL DEFAULT '0-5',
  `min_grade` DECIMAL(4,2) NOT NULL DEFAULT 0.00,
  `max_grade` DECIMAL(4,2) NOT NULL DEFAULT 5.00,
  `min_passing_grade` DECIMAL(4,2) NOT NULL DEFAULT 3.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.2 PERIODOS ACADÉMICOS
CREATE TABLE `academic_periods` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `institution_id` INT NOT NULL DEFAULT 1,
  `name` VARCHAR(100) NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_periods_institution` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.3 USUARIOS
CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(191) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `role` ENUM('ADMIN', 'TEACHER', 'STUDENT') NOT NULL,
  `status` ENUM('ACTIVE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `avatar_url` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.4 DOCENTES (Extensión de Rol)
CREATE TABLE `teachers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL UNIQUE,
  `teacher_code` VARCHAR(50) NOT NULL UNIQUE,
  `department` VARCHAR(150) NOT NULL,
  `specialty` VARCHAR(150) DEFAULT NULL,
  CONSTRAINT `fk_teachers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.5 ESTUDIANTES (Extensión de Rol)
CREATE TABLE `students` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL UNIQUE,
  `student_code` VARCHAR(50) NOT NULL UNIQUE,
  `career` VARCHAR(150) NOT NULL,
  `semester` INT NOT NULL DEFAULT 1,
  CONSTRAINT `fk_students_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.6 ASIGNATURAS / MATERIAS
CREATE TABLE `subjects` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(50) NOT NULL UNIQUE,
  `name` VARCHAR(200) NOT NULL,
  `credits` INT NOT NULL DEFAULT 3,
  `description` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.7 GRUPOS DE CURSO (ASIGNATURA + DOCENTE + PERIODO)
CREATE TABLE `course_groups` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `subject_id` INT NOT NULL,
  `teacher_id` INT NOT NULL,
  `period_id` INT NOT NULL,
  `group_code` VARCHAR(20) NOT NULL,
  `schedule` VARCHAR(150) NOT NULL,
  `classroom` VARCHAR(100) NOT NULL,
  `geo_lat` DECIMAL(10,7) DEFAULT 4.6097000,
  `geo_lng` DECIMAL(10,7) DEFAULT -74.0817000,
  `geo_radius_meters` DECIMAL(6,2) DEFAULT 100.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_subject_period_group` (`subject_id`, `period_id`, `group_code`),
  CONSTRAINT `fk_groups_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_groups_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_groups_period` FOREIGN KEY (`period_id`) REFERENCES `academic_periods` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.8 MATRÍCULAS (ESTUDIANTE <-> GRUPO)
CREATE TABLE `enrollments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `group_id` INT NOT NULL,
  `status` ENUM('ACTIVE', 'DROPPED', 'COMPLETED') NOT NULL DEFAULT 'ACTIVE',
  `enrolled_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_student_group` (`student_id`, `group_id`),
  CONSTRAINT `fk_enrollments_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_enrollments_group` FOREIGN KEY (`group_id`) REFERENCES `course_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.9 SESIONES DE CLASE (PARA ASISTENCIA & QR)
CREATE TABLE `class_sessions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `group_id` INT NOT NULL,
  `teacher_id` INT NOT NULL,
  `topic` VARCHAR(255) NOT NULL,
  `status` ENUM('ACTIVE', 'CLOSED') NOT NULL DEFAULT 'ACTIVE',
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `closed_at` DATETIME DEFAULT NULL,
  `session_secret` VARCHAR(64) NOT NULL,
  `geo_lat` DECIMAL(10,7) DEFAULT NULL,
  `geo_lng` DECIMAL(10,7) DEFAULT NULL,
  `geo_radius_meters` DECIMAL(6,2) DEFAULT 100.00,
  CONSTRAINT `fk_sessions_group` FOREIGN KEY (`group_id`) REFERENCES `course_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sessions_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.10 TOKENS DE ASISTENCIA (NONCES USADOS - PROTECCIÓN ANTI-REPLAY)
CREATE TABLE `attendance_tokens` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `session_id` INT NOT NULL,
  `nonce` VARCHAR(64) NOT NULL UNIQUE,
  `student_id` INT NOT NULL,
  `used_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_tokens_session` FOREIGN KEY (`session_id`) REFERENCES `class_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tokens_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.11 REGISTROS DE ASISTENCIA
CREATE TABLE `attendances` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `session_id` INT NOT NULL,
  `student_id` INT NOT NULL,
  `status` ENUM('PRESENT', 'LATE', 'ABSENT', 'EXCUSED') NOT NULL DEFAULT 'PRESENT',
  `registered_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `verification_method` VARCHAR(50) NOT NULL DEFAULT 'QR_DYNAMIC_HMAC',
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` TEXT DEFAULT NULL,
  `device_info` TEXT DEFAULT NULL,
  `distance_meters` DECIMAL(8,2) DEFAULT NULL,
  `is_flagged` TINYINT(1) NOT NULL DEFAULT 0,
  `flag_reason` TEXT DEFAULT NULL,
  UNIQUE KEY `uk_session_student` (`session_id`, `student_id`),
  CONSTRAINT `fk_attendances_session` FOREIGN KEY (`session_id`) REFERENCES `class_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attendances_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.12 EVALUACIONES
CREATE TABLE `evaluations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `group_id` INT NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `type` ENUM('PARCIAL', 'FINAL', 'QUIZ', 'TALLER', 'PROYECTO', 'EXPOSICION', 'PARTICIPACION') NOT NULL,
  `weight` DECIMAL(5,2) NOT NULL,
  `max_score` DECIMAL(4,2) NOT NULL DEFAULT 5.00,
  `due_date` DATE DEFAULT NULL,
  `status` ENUM('ACTIVE', 'CLOSED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_evaluations_group` FOREIGN KEY (`group_id`) REFERENCES `course_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.13 CALIFICACIONES
CREATE TABLE `grades` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `evaluation_id` INT NOT NULL,
  `student_id` INT NOT NULL,
  `score` DECIMAL(4,2) NOT NULL,
  `feedback` TEXT DEFAULT NULL,
  `graded_by_user_id` INT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_evaluation_student` (`evaluation_id`, `student_id`),
  CONSTRAINT `fk_grades_evaluation` FOREIGN KEY (`evaluation_id`) REFERENCES `evaluations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_grades_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_grades_user` FOREIGN KEY (`graded_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.14 HISTORIAL DE AUDITORÍA DE CALIFICACIONES (INMUTABLE)
CREATE TABLE `grade_history` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `grade_id` INT NOT NULL,
  `evaluation_id` INT NOT NULL,
  `student_id` INT NOT NULL,
  `old_score` DECIMAL(4,2) DEFAULT NULL,
  `new_score` DECIMAL(4,2) NOT NULL,
  `change_reason` TEXT NOT NULL,
  `changed_by_user_id` INT NOT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_gh_grade` FOREIGN KEY (`grade_id`) REFERENCES `grades` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gh_evaluation` FOREIGN KEY (`evaluation_id`) REFERENCES `evaluations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gh_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gh_user` FOREIGN KEY (`changed_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.15 REGISTROS DE AUDITORÍA GENERAL
CREATE TABLE `audit_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `resource_type` VARCHAR(100) NOT NULL,
  `resource_id` VARCHAR(100) DEFAULT NULL,
  `details_json` LONGTEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- 4. ÍNDICES DE RENDIMIENTO
-- ====================================================================
CREATE INDEX `idx_users_role` ON `users` (`role`);
CREATE INDEX `idx_course_groups_teacher` ON `course_groups` (`teacher_id`);
CREATE INDEX `idx_course_groups_subject` ON `course_groups` (`subject_id`);
CREATE INDEX `idx_enrollments_student` ON `enrollments` (`student_id`);
CREATE INDEX `idx_enrollments_group` ON `enrollments` (`group_id`);
CREATE INDEX `idx_class_sessions_group` ON `class_sessions` (`group_id`);
CREATE INDEX `idx_class_sessions_status` ON `class_sessions` (`status`);
CREATE INDEX `idx_attendances_session` ON `attendances` (`session_id`);
CREATE INDEX `idx_attendances_student` ON `attendances` (`student_id`);
CREATE INDEX `idx_evaluations_group` ON `evaluations` (`group_id`);
CREATE INDEX `idx_grades_evaluation` ON `grades` (`evaluation_id`);
CREATE INDEX `idx_grades_student` ON `grades` (`student_id`);
CREATE INDEX `idx_audit_action` ON `audit_logs` (`action`);

-- ====================================================================
-- 5. DATOS SEMILLA INICIALES (DEMO & PRODUCCIÓN)
-- ====================================================================

-- 5.1 INSTITUCIÓN
INSERT INTO `institutions` (`id`, `name`, `code`, `grading_scale`, `min_grade`, `max_grade`, `min_passing_grade`)
VALUES (1, 'Nexus Institute of Technology & Science', 'NEXUS-TECH', '0-5', 0.00, 5.00, 3.00);

-- 5.2 PERIODO ACADÉMICO
INSERT INTO `academic_periods` (`id`, `institution_id`, `name`, `start_date`, `end_date`, `is_active`)
VALUES (1, 1, '2026-1 (Semestre Activo)', '2026-02-01', '2026-06-30', 1);

-- 5.3 USUARIOS (Contraseñas generadas con bcrypt estándar PHP)
-- Admin: Admin123!
-- Docentes: Teacher123!
-- Estudiantes: Student123!
INSERT INTO `users` (`id`, `email`, `password_hash`, `first_name`, `last_name`, `phone`, `role`, `status`) VALUES
(1, 'admin@nexus.edu', '$2y$10$rdbQcCnB07aRMwmqn.yhMe3jLCH2IYIkluW/.vHy4y6uw6k05FFW6', 'Valeria', 'Alarcón (Admin)', '+57 300 111 2233', 'ADMIN', 'ACTIVE'),
(2, 'prof.carlos@nexus.edu', '$2y$10$5CZMfbGGc8YqV2VGtTDCNOpixMIi1vgU8W7ALGMQOGGAd354keov.', 'Carlos', 'Mendoza', '+57 311 222 3344', 'TEACHER', 'ACTIVE'),
(3, 'prof.elena@nexus.edu', '$2y$10$5CZMfbGGc8YqV2VGtTDCNOpixMIi1vgU8W7ALGMQOGGAd354keov.', 'Elena', 'Ramos', '+57 312 333 4455', 'TEACHER', 'ACTIVE'),
(4, 'estudiante.valeria@nexus.edu', '$2y$10$DIZXAj53vjsAP4juQ10Pr.Ieaf81HGLdKSOW1Vs.vF5710jpxukQC', 'Valeria', 'Ríos', '+57 320 444 5566', 'STUDENT', 'ACTIVE'),
(5, 'estudiante.mateo@nexus.edu', '$2y$10$DIZXAj53vjsAP4juQ10Pr.Ieaf81HGLdKSOW1Vs.vF5710jpxukQC', 'Mateo', 'Silva', '+57 321 555 6677', 'STUDENT', 'ACTIVE'),
(6, 'estudiante.camila@nexus.edu', '$2y$10$DIZXAj53vjsAP4juQ10Pr.Ieaf81HGLdKSOW1Vs.vF5710jpxukQC', 'Camila', 'Torres', '+57 322 666 7788', 'STUDENT', 'ACTIVE'),
(7, 'estudiante.alejandro@nexus.edu', '$2y$10$DIZXAj53vjsAP4juQ10Pr.Ieaf81HGLdKSOW1Vs.vF5710jpxukQC', 'Alejandro', 'Vega', '+57 323 777 8899', 'STUDENT', 'ACTIVE');

-- 5.4 DOCENTES
INSERT INTO `teachers` (`id`, `user_id`, `teacher_code`, `department`, `specialty`) VALUES
(1, 2, 'DOC-101', 'Ingeniería de Software & Ciberseguridad', 'Arquitectura de Software y Sistemas Distribuidos'),
(2, 3, 'DOC-102', 'Ciencias de la Computación', 'Algoritmia y Criptografía Aplicada');

-- 5.5 ESTUDIANTES
INSERT INTO `students` (`id`, `user_id`, `student_code`, `career`, `semester`) VALUES
(1, 4, 'EST-2026-01', 'Ingeniería de Software', 6),
(2, 5, 'EST-2026-02', 'Ingeniería de Software', 6),
(3, 6, 'EST-2026-03', 'Ingeniería de Software', 6),
(4, 7, 'EST-2026-04', 'Ciberseguridad y Redes', 5);

-- 5.6 ASIGNATURAS
INSERT INTO `subjects` (`id`, `code`, `name`, `credits`, `description`) VALUES
(1, 'IS-301', 'Arquitectura de Software y Sistemas Seguros', 4, 'Diseño de sistemas distribuidos, patrones de arquitectura, RBAC y auditoría.'),
(2, 'CC-204', 'Criptografía y Algoritmos Avanzados', 3, 'Firmas digitales HMAC, protocolos de autenticación y estructuras de datos.'),
(3, 'DB-102', 'Bases de Datos Relacionales y Transaccionales', 3, 'Diseño relacional, ACID, aislamiento y optimización de consultas.');

-- 5.7 GRUPOS DE CURSO
INSERT INTO `course_groups` (`id`, `subject_id`, `teacher_id`, `period_id`, `group_code`, `schedule`, `classroom`, `geo_lat`, `geo_lng`, `geo_radius_meters`) VALUES
(1, 1, 1, 1, 'IS301-G01', 'Lun y Mié 08:00 - 10:00', 'Laboratorio 302 - Edificio Innovación', 4.6097100, -74.0817500, 100.00),
(2, 2, 2, 1, 'CC204-G01', 'Mar y Jue 10:00 - 12:00', 'Aula Magna 104', 4.6098000, -74.0819000, 80.00),
(3, 3, 1, 1, 'DB102-G01', 'Viernes 14:00 - 18:00', 'Laboratorio de Bases de Datos 201', 4.6096500, -74.0816000, 100.00);

-- 5.8 MATRÍCULAS
INSERT INTO `enrollments` (`student_id`, `group_id`, `status`) VALUES
(1, 1, 'ACTIVE'),
(2, 1, 'ACTIVE'),
(3, 1, 'ACTIVE'),
(4, 1, 'ACTIVE'),
(1, 2, 'ACTIVE'),
(2, 2, 'ACTIVE'),
(3, 3, 'ACTIVE'),
(4, 3, 'ACTIVE');

-- 5.9 EVALUACIONES (Ponderaciones suman 100% por grupo)
INSERT INTO `evaluations` (`id`, `group_id`, `name`, `description`, `type`, `weight`, `max_score`, `due_date`, `status`) VALUES
(1, 1, 'Parcial 1 - Arquitectura de Microservicios', 'Evaluación teórica y diseño de diagramas C4', 'PARCIAL', 25.00, 5.00, '2026-03-15', 'ACTIVE'),
(2, 1, 'Taller Práctico - RBAC & Criptografía', 'Implementación de autenticación segura con tokens', 'TALLER', 20.00, 5.00, '2026-04-10', 'ACTIVE'),
(3, 1, 'Proyecto Final - Plataforma Distribuida', 'Entrega del sistema en producción con auditoría', 'PROYECTO', 35.00, 5.00, '2026-05-25', 'ACTIVE'),
(4, 1, 'Quices & Participación Continua', 'Seguimiento formativo acumulativo', 'PARTICIPACION', 20.00, 5.00, '2026-06-10', 'ACTIVE'),
(5, 2, 'Parcial 1 - Criptografía Simétrica & Hash', 'Algoritmos AES, HMAC y firmas digitales', 'PARCIAL', 30.00, 5.00, '2026-03-20', 'ACTIVE'),
(6, 2, 'Laboratorio Criptográfico', 'Implementación de intercambio Diffie-Hellman', 'TALLER', 30.00, 5.00, '2026-04-18', 'ACTIVE'),
(7, 2, 'Examen Final de Criptografía', 'Evaluación global de competencias', 'FINAL', 40.00, 5.00, '2026-06-05', 'ACTIVE');

-- 5.10 CALIFICACIONES REGISTRADAS
INSERT INTO `grades` (`id`, `evaluation_id`, `student_id`, `score`, `feedback`, `graded_by_user_id`) VALUES
(1, 1, 1, 4.80, 'Excelente dominio conceptual de patrones y estilo arquitectónico.', 2),
(2, 1, 2, 4.20, 'Buen análisis funcional, pulir diagramas de secuencia.', 2),
(3, 1, 3, 3.70, 'Cumple requerimientos base, profundizar en mecanismos de aislamiento.', 2),
(4, 1, 4, 4.50, 'Muy buen planteamiento de seguridad perimetral.', 2),
(5, 2, 1, 5.00, 'Solución impecable, código limpio y pruebas unitarias completas.', 2),
(6, 2, 2, 4.00, 'Implementación correcta del middleware de autorización.', 2),
(7, 5, 1, 4.60, 'Gran aplicación matemática de funciones hash.', 3),
(8, 5, 2, 3.90, 'Buen desarrollo, verificar entropía en generación de llaves.', 3);

-- 5.11 HISTORIAL DE AUDITORÍA DE CALIFICACIONES
INSERT INTO `grade_history` (`grade_id`, `evaluation_id`, `student_id`, `old_score`, `new_score`, `change_reason`, `changed_by_user_id`, `ip_address`) VALUES
(1, 1, 1, NULL, 4.80, 'Calificación inicial registrada', 2, '127.0.0.1'),
(2, 1, 2, NULL, 4.20, 'Calificación inicial registrada', 2, '127.0.0.1'),
(3, 1, 3, NULL, 3.50, 'Calificación inicial registrada', 2, '127.0.0.1'),
(3, 1, 3, 3.50, 3.70, 'Revisión presencial: se constató entrega de anexo en tiempo límite', 2, '127.0.0.1'),
(4, 1, 4, NULL, 4.50, 'Calificación inicial registrada', 2, '127.0.0.1'),
(5, 2, 1, NULL, 5.00, 'Calificación inicial registrada', 2, '127.0.0.1'),
(6, 2, 2, NULL, 4.00, 'Calificación inicial registrada', 2, '127.0.0.1');

-- 5.12 SESIÓN DE CLASE DE PRUEBA
INSERT INTO `class_sessions` (`id`, `group_id`, `teacher_id`, `topic`, `status`, `session_secret`, `geo_lat`, `geo_lng`, `geo_radius_meters`) VALUES
(1, 1, 1, 'Sesión 01: Arquitecturas Empresariales y Estilos de Integración', 'CLOSED', 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90', 4.6097100, -74.0817500, 100.00);

-- 5.13 ASISTENCIAS DE PRUEBA
INSERT INTO `attendances` (`session_id`, `student_id`, `status`, `verification_method`, `ip_address`, `distance_meters`, `is_flagged`) VALUES
(1, 1, 'PRESENT', 'QR_DYNAMIC_HMAC', '127.0.0.1', 8.50, 0),
(1, 2, 'PRESENT', 'QR_DYNAMIC_HMAC', '127.0.0.1', 14.20, 0),
(1, 3, 'PRESENT', 'QR_DYNAMIC_HMAC', '127.0.0.1', 19.80, 0);

-- 5.14 LOGS DE AUDITORÍA
INSERT INTO `audit_logs` (`user_id`, `action`, `resource_type`, `resource_id`, `details_json`, `ip_address`) VALUES
(1, 'SYSTEM_INITIALIZED', 'SYSTEM', '1', '{"message":"Base de datos relacional MySQL/phpMyAdmin inicializada con éxito"}', '127.0.0.1'),
(2, 'SESSION_CREATED', 'CLASS_SESSION', '1', '{"topic":"Sesión 01: Arquitecturas Empresariales"}', '127.0.0.1'),
(2, 'GRADE_RECORDED', 'GRADE', '1', '{"evaluation_id":1,"student_id":1,"score":4.8}', '127.0.0.1');

SET FOREIGN_KEY_CHECKS = 1;
COMMIT;
