import { db, transaction } from '../database/db.js';
import { hashPassword } from '../utils/crypto.js';

export const academicController = {
  /**
   * GET /api/academic/institution
   */
  getInstitution(req, res) {
    try {
      const institution = db.prepare('SELECT * FROM institutions LIMIT 1').get();
      return res.json(institution);
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * PUT /api/academic/institution (ADMIN ONLY)
   */
  updateInstitution(req, res) {
    try {
      const { name, code, grading_scale, min_grade, max_grade, min_passing_grade } = req.body;
      db.prepare(`
        UPDATE institutions 
        SET name = COALESCE(?, name),
            code = COALESCE(?, code),
            grading_scale = COALESCE(?, grading_scale),
            min_grade = COALESCE(?, min_grade),
            max_grade = COALESCE(?, max_grade),
            min_passing_grade = COALESCE(?, min_passing_grade)
        WHERE id = 1
      `).run(name, code, grading_scale, min_grade, max_grade, min_passing_grade);

      return res.json({ success: true, message: 'Configuración institucional actualizada.' });
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * GET /api/academic/periods
   */
  getPeriods(req, res) {
    try {
      const periods = db.prepare('SELECT * FROM academic_periods ORDER BY id DESC').all();
      return res.json(periods);
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * POST /api/academic/periods (ADMIN ONLY)
   */
  createPeriod(req, res) {
    try {
      const { name, start_date, end_date, is_active } = req.body;
      if (!name || !start_date || !end_date) {
        return res.status(400).json({ error: 'MISSING_FIELDS', message: 'Nombre y fechas son obligatorios.' });
      }

      const info = db.prepare(`
        INSERT INTO academic_periods (name, start_date, end_date, is_active)
        VALUES (?, ?, ?, ?)
      `).run(name, start_date, end_date, is_active ? 1 : 0);

      return res.status(201).json({ success: true, periodId: Number(info.lastInsertRowid) });
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * GET /api/academic/subjects
   */
  getSubjects(req, res) {
    try {
      const subjects = db.prepare('SELECT * FROM subjects ORDER BY code ASC').all();
      return res.json(subjects);
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * POST /api/academic/subjects (ADMIN ONLY)
   */
  createSubject(req, res) {
    try {
      const { code, name, credits, description } = req.body;
      if (!code || !name) {
        return res.status(400).json({ error: 'MISSING_FIELDS', message: 'Código y nombre son obligatorios.' });
      }

      const info = db.prepare(`
        INSERT INTO subjects (code, name, credits, description)
        VALUES (?, ?, ?, ?)
      `).run(code.trim().toUpperCase(), name.trim(), credits || 3, description || null);

      return res.status(201).json({ success: true, subjectId: Number(info.lastInsertRowid) });
    } catch (err) {
      if (err.message && err.message.includes('UNIQUE')) {
        return res.status(409).json({ error: 'SUBJECT_EXISTS', message: 'El código de asignatura ya existe.' });
      }
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * GET /api/academic/groups
   */
  getGroups(req, res) {
    try {
      const { role, teacherId, studentId } = req.user;

      let query = `
        SELECT 
          cg.id, cg.group_code, cg.schedule, cg.classroom, cg.geo_lat, cg.geo_lng, cg.geo_radius_meters, cg.created_at,
          s.id AS subject_id, s.code AS subject_code, s.name AS subject_name, s.credits AS subject_credits,
          ap.id AS period_id, ap.name AS period_name, ap.is_active AS period_is_active,
          t.id AS teacher_id, t.teacher_code,
          u.first_name AS teacher_first_name, u.last_name AS teacher_last_name, u.email AS teacher_email,
          (SELECT COUNT(*) FROM enrollments e WHERE e.group_id = cg.id AND e.status = 'ACTIVE') AS enrolled_students_count,
          (SELECT COUNT(*) FROM evaluations ev WHERE ev.group_id = cg.id) AS evaluations_count,
          (SELECT COUNT(*) FROM class_sessions cs WHERE cs.group_id = cg.id) AS total_sessions_count,
          (SELECT COUNT(*) FROM class_sessions cs WHERE cs.group_id = cg.id AND cs.status = 'ACTIVE') AS active_sessions_count
        FROM course_groups cg
        JOIN subjects s ON cg.subject_id = s.id
        JOIN academic_periods ap ON cg.period_id = ap.id
        JOIN teachers t ON cg.teacher_id = t.id
        JOIN users u ON t.user_id = u.id
      `;

      let params = [];

      if (role === 'TEACHER') {
        query += ' WHERE cg.teacher_id = ?';
        params.push(teacherId);
      } else if (role === 'STUDENT') {
        query += `
          JOIN enrollments enr ON enr.group_id = cg.id
          WHERE enr.student_id = ? AND enr.status = 'ACTIVE'
        `;
        params.push(studentId);
      }

      query += ' ORDER BY ap.id DESC, s.name ASC';

      const groups = db.prepare(query).all(...params);
      return res.json(groups);
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * GET /api/academic/groups/:id
   */
  getGroupDetails(req, res) {
    try {
      const groupId = req.params.id;

      const group = db.prepare(`
        SELECT 
          cg.id, cg.group_code, cg.schedule, cg.classroom, cg.geo_lat, cg.geo_lng, cg.geo_radius_meters,
          s.id AS subject_id, s.code AS subject_code, s.name AS subject_name, s.credits AS subject_credits, s.description AS subject_description,
          ap.id AS period_id, ap.name AS period_name,
          t.id AS teacher_id, t.teacher_code, t.department AS teacher_dept,
          u.first_name AS teacher_first_name, u.last_name AS teacher_last_name, u.email AS teacher_email
        FROM course_groups cg
        JOIN subjects s ON cg.subject_id = s.id
        JOIN academic_periods ap ON cg.period_id = ap.id
        JOIN teachers t ON cg.teacher_id = t.id
        JOIN users u ON t.user_id = u.id
        WHERE cg.id = ?
      `).get(groupId);

      if (!group) {
        return res.status(404).json({ error: 'GROUP_NOT_FOUND' });
      }

      if (req.user.role === 'TEACHER' && group.teacher_id !== req.user.teacherId) {
        return res.status(403).json({ error: 'NOT_ASSIGNED_TEACHER', message: 'No tiene permiso para ver este grupo.' });
      }
      if (req.user.role === 'STUDENT') {
        const isEnrolled = db.prepare(`
          SELECT id FROM enrollments WHERE group_id = ? AND student_id = ? AND status = 'ACTIVE'
        `).get(groupId, req.user.studentId);
        if (!isEnrolled) {
          return res.status(403).json({ error: 'NOT_ENROLLED', message: 'No está matriculado en este grupo.' });
        }
      }

      let students = [];
      if (req.user.role !== 'STUDENT') {
        students = db.prepare(`
          SELECT 
            st.id AS student_id, st.student_code, st.career, st.semester,
            u.id AS user_id, u.first_name, u.last_name, u.email,
            e.enrolled_at, e.status AS enrollment_status
          FROM enrollments e
          JOIN students st ON e.student_id = st.id
          JOIN users u ON st.user_id = u.id
          WHERE e.group_id = ? AND e.status = 'ACTIVE'
          ORDER BY u.last_name ASC, u.first_name ASC
        `).all(groupId);
      }

      const evaluations = db.prepare(`
        SELECT * FROM evaluations WHERE group_id = ? ORDER BY due_date ASC, id ASC
      `).all(groupId);

      const activeSession = db.prepare(`
        SELECT id, topic, started_at, geo_lat, geo_lng, geo_radius_meters
        FROM class_sessions 
        WHERE group_id = ? AND status = 'ACTIVE'
        ORDER BY id DESC LIMIT 1
      `).get(groupId);

      return res.json({
        group,
        students,
        evaluations,
        activeSession: activeSession || null
      });
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * POST /api/academic/groups (ADMIN ONLY)
   */
  createGroup(req, res) {
    try {
      const { subject_id, teacher_id, period_id, group_code, schedule, classroom, geo_lat, geo_lng, geo_radius_meters } = req.body;

      if (!subject_id || !teacher_id || !period_id || !group_code) {
        return res.status(400).json({ error: 'MISSING_FIELDS', message: 'Asignatura, profesor, período y código son requeridos.' });
      }

      const info = db.prepare(`
        INSERT INTO course_groups (subject_id, teacher_id, period_id, group_code, schedule, classroom, geo_lat, geo_lng, geo_radius_meters)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
      `).run(
        subject_id,
        teacher_id,
        period_id,
        group_code.trim().toUpperCase(),
        schedule || 'Por definir',
        classroom || 'Por definir',
        geo_lat || 4.6097,
        geo_lng || -74.0817,
        geo_radius_meters || 100.0
      );

      const groupId = Number(info.lastInsertRowid);

      db.prepare(`
        INSERT INTO audit_logs (user_id, action, resource_type, resource_id, details_json, ip_address)
        VALUES (?, 'GROUP_CREATED', 'COURSE_GROUP', ?, ?, ?)
      `).run(req.user.id, String(groupId), JSON.stringify({ group_code, teacher_id, subject_id }), req.ip || '127.0.0.1');

      return res.status(201).json({ success: true, groupId });
    } catch (err) {
      if (err.message && err.message.includes('UNIQUE')) {
        return res.status(409).json({ error: 'GROUP_EXISTS', message: 'Ya existe este código de grupo para la asignatura y período.' });
      }
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * POST /api/academic/teacher-course (TEACHER & ADMIN)
   * Permite que el docente cree y escriba directamente el nombre de su asignatura y su grupo de clase
   */
  createTeacherCourse(req, res) {
    try {
      const { subject_name, subject_code, group_code, schedule, classroom, credits, description } = req.body;
      if (!subject_name || !subject_name.trim()) {
        return res.status(400).json({ error: 'MISSING_FIELDS', message: 'El nombre de la asignatura es obligatorio.' });
      }

      let teacherId = req.user.role === 'TEACHER' ? req.user.teacherId : req.body.teacher_id;
      if (!teacherId) {
        return res.status(400).json({ error: 'TEACHER_REQUIRED', message: 'No se encontró el ID del docente.' });
      }

      // 1. Get or create active academic period
      let period = db.prepare(`SELECT id FROM academic_periods WHERE is_active = 1 ORDER BY id DESC LIMIT 1`).get();
      if (!period) {
        period = db.prepare(`SELECT id FROM academic_periods ORDER BY id DESC LIMIT 1`).get();
      }
      if (!period) {
        const pInfo = db.prepare(`INSERT INTO academic_periods (name, code, start_date, end_date, is_active) VALUES ('2026-1', '2026-1', '2026-01-15', '2026-06-30', 1)`).run();
        period = { id: Number(pInfo.lastInsertRowid) };
      }

      // 2. Determine subject code
      const cleanName = subject_name.trim();
      let cleanCode = (subject_code && subject_code.trim()) ? subject_code.trim().toUpperCase() : null;
      if (!cleanCode) {
        const words = cleanName.split(/\s+/).filter(w => w.length > 2);
        const prefix = words.map(w => w[0].toUpperCase()).join('').substring(0, 4) || 'ASIG';
        const num = Math.floor(100 + Math.random() * 900);
        cleanCode = `${prefix}-${num}`;
      }

      // 3. Find or Insert Subject
      let subject = db.prepare(`SELECT id FROM subjects WHERE UPPER(name) = UPPER(?) OR code = ?`).get(cleanName, cleanCode);
      let subjectId;
      if (subject) {
        subjectId = subject.id;
      } else {
        try {
          const subInfo = db.prepare(`
            INSERT INTO subjects (code, name, credits, description)
            VALUES (?, ?, ?, ?)
          `).run(cleanCode, cleanName, credits ? Number(credits) : 3, description ? description.trim() : null);
          subjectId = Number(subInfo.lastInsertRowid);
        } catch (subErr) {
          const altCode = `${cleanCode}-${Date.now().toString().slice(-4)}`;
          const subInfo = db.prepare(`
            INSERT INTO subjects (code, name, credits, description)
            VALUES (?, ?, ?, ?)
          `).run(altCode, cleanName, credits ? Number(credits) : 3, description ? description.trim() : null);
          subjectId = Number(subInfo.lastInsertRowid);
        }
      }

      // 4. Create Course Group
      const finalGroupCode = (group_code && group_code.trim()) ? group_code.trim().toUpperCase() : 'G01';
      const info = db.prepare(`
        INSERT INTO course_groups (subject_id, teacher_id, period_id, group_code, schedule, classroom, geo_lat, geo_lng, geo_radius_meters)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
      `).run(
        subjectId,
        teacherId,
        period.id,
        finalGroupCode,
        schedule ? schedule.trim() : 'Horario por definir',
        classroom ? classroom.trim() : 'Aula por asignar',
        4.6097,
        -74.0817,
        100.0
      );

      const groupId = Number(info.lastInsertRowid);

      db.prepare(`
        INSERT INTO audit_logs (user_id, action, resource_type, resource_id, details_json, ip_address)
        VALUES (?, 'TEACHER_CREATED_COURSE', 'COURSE_GROUP', ?, ?, ?)
      `).run(req.user.id, String(groupId), JSON.stringify({ subject_name: cleanName, group_code: finalGroupCode }), req.ip || '127.0.0.1');

      return res.status(201).json({
        success: true,
        groupId,
        subjectId,
        message: `Asignatura "${cleanName}" (${finalGroupCode}) creada y asignada exitosamente.`
      });
    } catch (err) {
      if (err.message && err.message.includes('UNIQUE')) {
        return res.status(409).json({ error: 'GROUP_EXISTS', message: 'Ya tiene un grupo con ese código para esta asignatura.' });
      }
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * PUT /api/academic/groups/:id (ADMIN & TEACHER)
   * Permite reasignar o modificar el nombre de asignatura, horario, aula o código del grupo
   */
  updateGroup(req, res) {
    try {
      const groupId = req.params.id;
      const { subject_name, teacher_id, group_code, schedule, classroom, geo_lat, geo_lng, geo_radius_meters } = req.body;

      const group = db.prepare('SELECT id, teacher_id, subject_id FROM course_groups WHERE id = ?').get(groupId);
      if (!group) return res.status(404).json({ error: 'GROUP_NOT_FOUND' });

      if (req.user.role === 'TEACHER' && group.teacher_id !== req.user.teacherId) {
        return res.status(403).json({ error: 'FORBIDDEN', message: 'No tiene permiso para editar este grupo.' });
      }

      if (subject_name && subject_name.trim()) {
        db.prepare('UPDATE subjects SET name = ? WHERE id = ?').run(subject_name.trim(), group.subject_id);
      }

      db.prepare(`
        UPDATE course_groups
        SET teacher_id = COALESCE(?, teacher_id),
            group_code = COALESCE(?, group_code),
            schedule = COALESCE(?, schedule),
            classroom = COALESCE(?, classroom),
            geo_lat = COALESCE(?, geo_lat),
            geo_lng = COALESCE(?, geo_lng),
            geo_radius_meters = COALESCE(?, geo_radius_meters)
        WHERE id = ?
      `).run(
        (req.user.role === 'ADMIN' && teacher_id) ? teacher_id : null,
        group_code ? group_code.trim().toUpperCase() : null,
        schedule ? schedule.trim() : null,
        classroom ? classroom.trim() : null,
        geo_lat != null ? Number(geo_lat) : null,
        geo_lng != null ? Number(geo_lng) : null,
        geo_radius_meters != null ? Number(geo_radius_meters) : null,
        groupId
      );

      db.prepare(`
        INSERT INTO audit_logs (user_id, action, resource_type, resource_id, details_json, ip_address)
        VALUES (?, 'GROUP_UPDATED', 'COURSE_GROUP', ?, ?, ?)
      `).run(req.user.id, String(groupId), JSON.stringify({ schedule, classroom, group_code }), req.ip || '127.0.0.1');

      return res.json({ success: true, message: 'Grupo académico actualizado exitosamente.' });
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * DELETE /api/academic/groups/:id (ADMIN & TEACHER)
   */
  deleteGroup(req, res) {
    try {
      const groupId = req.params.id;
      const group = db.prepare('SELECT id, teacher_id FROM course_groups WHERE id = ?').get(groupId);
      if (!group) return res.status(404).json({ error: 'GROUP_NOT_FOUND' });

      if (req.user.role === 'TEACHER' && group.teacher_id !== req.user.teacherId) {
        return res.status(403).json({ error: 'FORBIDDEN', message: 'No tiene permiso para eliminar este grupo.' });
      }

      db.prepare('DELETE FROM course_groups WHERE id = ?').run(groupId);

      db.prepare(`
        INSERT INTO audit_logs (user_id, action, resource_type, resource_id, details_json, ip_address)
        VALUES (?, 'GROUP_DELETED', 'COURSE_GROUP', ?, '{}', ?)
      `).run(req.user.id, String(groupId), req.ip || '127.0.0.1');

      return res.json({ success: true, message: 'Grupo eliminado correctamente.' });
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * GET /api/academic/available-courses (STUDENT, TEACHER, ADMIN)
   */
  getAvailableCourses(req, res) {
    try {
      const courses = db.prepare(`
        SELECT 
          cg.id, cg.group_code, cg.schedule, cg.classroom,
          s.id AS subject_id, s.code AS subject_code, s.name AS subject_name, s.credits,
          u.first_name AS teacher_first_name, u.last_name AS teacher_last_name,
          ap.name AS period_name,
          (SELECT COUNT(*) FROM enrollments e WHERE e.group_id = cg.id AND e.status = 'ACTIVE') AS enrolled_count
        FROM course_groups cg
        JOIN subjects s ON cg.subject_id = s.id
        JOIN teachers t ON cg.teacher_id = t.id
        JOIN users u ON t.user_id = u.id
        JOIN academic_periods ap ON cg.period_id = ap.id
        ORDER BY s.name ASC, cg.group_code ASC
      `).all();

      return res.json(courses);
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * POST /api/academic/enrollments (ADMIN & STUDENT)
   */
  enrollStudent(req, res) {
    try {
      let { student_id, group_id, group_code } = req.body;

      if (req.user.role === 'STUDENT') {
        student_id = req.user.studentId;
      }

      if (!group_id && group_code) {
        const found = db.prepare('SELECT id FROM course_groups WHERE UPPER(group_code) = UPPER(?)').get(group_code.trim());
        if (found) group_id = found.id;
      }

      if (!student_id || !group_id) {
        return res.status(400).json({ error: 'MISSING_FIELDS', message: 'Se requiere el estudiante y el grupo a matricular.' });
      }

      const info = db.prepare(`
        INSERT INTO enrollments (student_id, group_id, status)
        VALUES (?, ?, 'ACTIVE')
      `).run(student_id, group_id);

      db.prepare(`
        INSERT INTO audit_logs (user_id, action, resource_type, resource_id, details_json, ip_address)
        VALUES (?, 'STUDENT_ENROLLED', 'ENROLLMENT', ?, ?, ?)
      `).run(req.user.id, String(info.lastInsertRowid), JSON.stringify({ student_id, group_id }), req.ip || '127.0.0.1');

      return res.status(201).json({ success: true, enrollmentId: Number(info.lastInsertRowid), message: '¡Matrícula completada exitosamente!' });
    } catch (err) {
      if (err.message && err.message.includes('UNIQUE')) {
        return res.status(409).json({ error: 'ALREADY_ENROLLED', message: 'Ya se encuentra matriculado en esta asignatura.' });
      }
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * GET /api/academic/teachers (ADMIN ONLY)
   */
  getTeachers(req, res) {
    try {
      const teachers = db.prepare(`
        SELECT 
          t.id AS teacher_id, t.teacher_code, t.department, t.specialty,
          u.id AS user_id, u.first_name, u.last_name, u.email, u.status,
          (SELECT COUNT(*) FROM course_groups cg WHERE cg.teacher_id = t.id) AS assigned_groups_count
        FROM teachers t
        JOIN users u ON t.user_id = u.id
        ORDER BY u.last_name ASC
      `).all();
      return res.json(teachers);
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * PUT /api/academic/teachers/:id (ADMIN ONLY)
   */
  updateTeacher(req, res) {
    try {
      const teacherId = req.params.id;
      const { department, specialty, first_name, last_name } = req.body;

      const teacher = db.prepare('SELECT user_id FROM teachers WHERE id = ?').get(teacherId);
      if (!teacher) return res.status(404).json({ error: 'TEACHER_NOT_FOUND' });

      transaction((dbSync) => {
        dbSync.prepare(`
          UPDATE teachers 
          SET department = COALESCE(?, department),
              specialty = COALESCE(?, specialty)
          WHERE id = ?
        `).run(department || null, specialty || null, teacherId);

        if (first_name || last_name) {
          dbSync.prepare(`
            UPDATE users 
            SET first_name = COALESCE(?, first_name),
                last_name = COALESCE(?, last_name)
            WHERE id = ?
          `).run(first_name || null, last_name || null, teacher.user_id);
        }
      });

      return res.json({ success: true, message: 'Datos del docente actualizados correctamente.' });
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * GET /api/academic/students (ADMIN & TEACHER)
   */
  getStudents(req, res) {
    try {
      const students = db.prepare(`
        SELECT 
          s.id AS student_id, s.student_code, s.career, s.semester,
          u.id AS user_id, u.first_name, u.last_name, u.email, u.status,
          (SELECT COUNT(*) FROM enrollments e WHERE e.student_id = s.id AND e.status = 'ACTIVE') AS enrolled_courses_count
        FROM students s
        JOIN users u ON s.user_id = u.id
        ORDER BY u.last_name ASC
      `).all();
      return res.json(students);
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  }
};
