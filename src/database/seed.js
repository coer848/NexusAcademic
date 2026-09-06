import { db, transaction } from './db.js';
import { hashPassword } from '../utils/crypto.js';

export function runSeed() {
  transaction((dbSync) => {
    // 1. Clean existing records in reverse dependency order
    dbSync.exec(`
      DELETE FROM audit_logs;
      DELETE FROM grade_history;
      DELETE FROM grades;
      DELETE FROM evaluations;
      DELETE FROM attendance_tokens;
      DELETE FROM attendances;
      DELETE FROM class_sessions;
      DELETE FROM enrollments;
      DELETE FROM course_groups;
      DELETE FROM subjects;
      DELETE FROM students;
      DELETE FROM teachers;
      DELETE FROM users;
      DELETE FROM academic_periods;
      DELETE FROM institutions;
    `);

    // 2. Institution
    const instStmt = dbSync.prepare(`
      INSERT INTO institutions (id, name, code, grading_scale, min_grade, max_grade, min_passing_grade)
      VALUES (1, 'Nexus Institute of Technology & Science', 'NEXUS-TECH', '0-5', 0.0, 5.0, 3.0)
    `);
    instStmt.run();

    // 3. Academic Period
    const periodStmt = dbSync.prepare(`
      INSERT INTO academic_periods (id, institution_id, name, start_date, end_date, is_active)
      VALUES (1, 1, '2026-1 (Semestre Activo)', '2026-02-01', '2026-06-30', 1)
    `);
    periodStmt.run();

    // Passwords hash (Scrypt + salt)
    const adminPass = hashPassword('Admin123!');
    const teacherPass = hashPassword('Teacher123!');
    const studentPass = hashPassword('Student123!');

    // 4. Users (Admin, Teachers, Students)
    const users = [
      { id: 1, email: 'admin@nexus.edu', pass: adminPass, fn: 'Valeria', ln: 'Alarcón (Admin)', role: 'ADMIN' },
      { id: 2, email: 'prof.carlos@nexus.edu', pass: teacherPass, fn: 'Carlos', ln: 'Mendoza', role: 'TEACHER' },
      { id: 3, email: 'prof.elena@nexus.edu', pass: teacherPass, fn: 'Elena', ln: 'Ramos', role: 'TEACHER' },
      { id: 4, email: 'estudiante.valeria@nexus.edu', pass: studentPass, fn: 'Valeria', ln: 'Ríos', role: 'STUDENT' },
      { id: 5, email: 'estudiante.mateo@nexus.edu', pass: studentPass, fn: 'Mateo', ln: 'Silva', role: 'STUDENT' },
      { id: 6, email: 'estudiante.camila@nexus.edu', pass: studentPass, fn: 'Camila', ln: 'Torres', role: 'STUDENT' },
      { id: 7, email: 'estudiante.alejandro@nexus.edu', pass: studentPass, fn: 'Alejandro', ln: 'Vega', role: 'STUDENT' }
    ];

    const userStmt = dbSync.prepare(`
      INSERT INTO users (id, email, password_hash, first_name, last_name, role, status)
      VALUES (?, ?, ?, ?, ?, ?, 'ACTIVE')
    `);

    users.forEach(u => {
      userStmt.run(u.id, u.email, u.pass, u.fn, u.ln, u.role);
    });

    // 5. Teachers
    const teacherStmt = dbSync.prepare(`
      INSERT INTO teachers (id, user_id, teacher_code, department, specialty)
      VALUES (?, ?, ?, ?, ?)
    `);
    teacherStmt.run(1, 2, 'DOC-101', 'Ingeniería de Software & Ciberseguridad', 'Arquitectura de Software y Sistemas Distribuidos');
    teacherStmt.run(2, 3, 'DOC-102', 'Ciencias de la Computación', 'Algoritmia y Criptografía Aplicada');

    // 6. Students
    const studentStmt = dbSync.prepare(`
      INSERT INTO students (id, user_id, student_code, career, semester)
      VALUES (?, ?, ?, ?, ?)
    `);
    studentStmt.run(1, 4, 'EST-2026-01', 'Ingeniería de Software', 6);
    studentStmt.run(2, 5, 'EST-2026-02', 'Ingeniería de Software', 6);
    studentStmt.run(3, 6, 'EST-2026-03', 'Ingeniería de Software', 6);
    studentStmt.run(4, 7, 'EST-2026-04', 'Ciberseguridad y Redes', 5);

    // 7. Subjects
    const subStmt = dbSync.prepare(`
      INSERT INTO subjects (id, code, name, credits, description)
      VALUES (?, ?, ?, ?, ?)
    `);
    subStmt.run(1, 'IS-301', 'Arquitectura de Software y Sistemas Seguros', 4, 'Diseño de sistemas distribuidos, patrones de arquitectura, RBAC y auditoría.');
    subStmt.run(2, 'CC-204', 'Criptografía y Algoritmos Avanzados', 3, 'Firmas digitales HMAC, protocolos de autenticación y estructuras de datos.');
    subStmt.run(3, 'DB-102', 'Bases de Datos Relacionales y Transaccionales', 3, 'Diseño relacional, ACID, aislamiento y optimización de consultas.');

    // 8. Course Groups
    const groupStmt = dbSync.prepare(`
      INSERT INTO course_groups (id, subject_id, teacher_id, period_id, group_code, schedule, classroom, geo_lat, geo_lng, geo_radius_meters)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    `);
    groupStmt.run(1, 1, 1, 1, 'IS301-G01', 'Lun y Mié 08:00 - 10:00', 'Laboratorio 302 - Edificio Innovación', 4.60971, -74.08175, 100.0);
    groupStmt.run(2, 2, 2, 1, 'CC204-G01', 'Mar y Jue 10:00 - 12:00', 'Aula Magna 104', 4.60980, -74.08190, 80.0);
    groupStmt.run(3, 3, 1, 1, 'DB102-G01', 'Viernes 14:00 - 18:00', 'Laboratorio de Bases de Datos 201', 4.60965, -74.08160, 100.0);

    // 9. Enrollments
    const enrollStmt = dbSync.prepare(`
      INSERT INTO enrollments (id, student_id, group_id, status)
      VALUES (?, ?, ?, 'ACTIVE')
    `);
    // Group 1 (IS-301): Valeria, Mateo, Camila
    enrollStmt.run(1, 1, 1);
    enrollStmt.run(2, 2, 1);
    enrollStmt.run(3, 3, 1);

    // Group 2 (CC-204): Valeria, Camila, Alejandro
    enrollStmt.run(4, 1, 2);
    enrollStmt.run(5, 3, 2);
    enrollStmt.run(6, 4, 2);

    // 10. Evaluations for Group 1 (IS-301)
    const evalStmt = dbSync.prepare(`
      INSERT INTO evaluations (id, group_id, name, description, type, weight, max_score, due_date, status)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE')
    `);
    evalStmt.run(1, 1, 'Parcial 1: Arquitectura Limpia y Seguridad', 'Evaluación escrita sobre Clean Architecture y patrones defensivos.', 'PARCIAL', 30.0, 5.0, '2026-03-20');
    evalStmt.run(2, 1, 'Taller 1: Implementación de QR Criptográfico', 'Desarrollo de motor de tokens efímeros HMAC-SHA256.', 'TALLER', 30.0, 5.0, '2026-04-15');
    evalStmt.run(3, 1, 'Proyecto Final: Plataforma Académica Integral', 'Despliegue de plataforma con RBAC, auditoría y pruebas.', 'PROYECTO', 40.0, 5.0, '2026-05-30');

    // 11. Initial Grades & History
    const gradeStmt = dbSync.prepare(`
      INSERT INTO grades (id, evaluation_id, student_id, score, feedback, graded_by_user_id)
      VALUES (?, ?, ?, ?, ?, ?)
    `);
    gradeStmt.run(1, 1, 1, 4.8, 'Excelente dominio conceptual y diseño de diagramas.', 2);
    gradeStmt.run(2, 1, 2, 3.5, 'Buen enfoque, revisar manejo de transacciones en concurrencia.', 2);
    gradeStmt.run(3, 1, 3, 4.3, 'Gran trabajo en modelado de amenazas STRIDE.', 2);

    // Audit logs for initial grades
    const histStmt = dbSync.prepare(`
      INSERT INTO grade_history (id, grade_id, evaluation_id, student_id, old_score, new_score, change_reason, changed_by_user_id)
      VALUES (?, ?, ?, ?, NULL, ?, 'Calificación inicial del Parcial 1', ?)
    `);
    histStmt.run(1, 1, 1, 1, 4.8, 2);
    histStmt.run(2, 2, 1, 2, 3.5, 2);
    histStmt.run(3, 3, 1, 3, 4.3, 2);
  });
}

if (process.argv[1] && process.argv[1].endsWith('seed.js')) {
  runSeed();
  console.log('✅ Base de datos reseteada y poblada exitosamente.');
}
