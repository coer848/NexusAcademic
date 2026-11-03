import crypto from 'node:crypto';
import { db, transaction } from '../database/db.js';
import { generateQRPayload, verifyQRPayload, calculateHaversineDistance } from '../utils/crypto.js';

export const attendanceController = {
  /**
   * POST /api/sessions/start (TEACHER ONLY)
   */
  startSession(req, res) {
    try {
      const { group_id, topic, geo_lat, geo_lng, geo_radius_meters } = req.body;
      const teacherId = req.user.teacherId;

      if (!group_id || !topic) {
        return res.status(400).json({ error: 'MISSING_FIELDS', message: 'group_id y topic son obligatorios.' });
      }

      // Verify teacher assignment unless admin
      const group = db.prepare('SELECT id, teacher_id, classroom, geo_lat, geo_lng, geo_radius_meters FROM course_groups WHERE id = ?').get(group_id);
      if (!group) {
        return res.status(404).json({ error: 'GROUP_NOT_FOUND' });
      }
      if (req.user.role === 'TEACHER' && group.teacher_id !== teacherId) {
        return res.status(403).json({ error: 'NOT_COURSE_TEACHER', message: 'No es el docente asignado a este grupo.' });
      }

      // Close any previous open session for this group to avoid overlapping
      db.prepare(`
        UPDATE class_sessions 
        SET status = 'CLOSED', closed_at = CURRENT_TIMESTAMP 
        WHERE group_id = ? AND status = 'ACTIVE'
      `).run(group_id);

      // Generate random 64-character hex secret for HMAC
      const sessionSecret = crypto.randomBytes(32).toString('hex');
      const finalLat = geo_lat != null ? Number(geo_lat) : group.geo_lat;
      const finalLng = geo_lng != null ? Number(geo_lng) : group.geo_lng;
      const finalRadius = geo_radius_meters != null ? Number(geo_radius_meters) : (group.geo_radius_meters || 100.0);

      const info = db.prepare(`
        INSERT INTO class_sessions (group_id, teacher_id, topic, status, session_secret, geo_lat, geo_lng, geo_radius_meters)
        VALUES (?, ?, ?, 'ACTIVE', ?, ?, ?, ?)
      `).run(
        group_id,
        group.teacher_id,
        topic.trim(),
        sessionSecret,
        finalLat,
        finalLng,
        finalRadius
      );

      const sessionId = Number(info.lastInsertRowid);
      const initialQR = generateQRPayload(sessionId, sessionSecret);

      db.prepare(`
        INSERT INTO audit_logs (user_id, action, resource_type, resource_id, details_json, ip_address)
        VALUES (?, 'SESSION_STARTED', 'CLASS_SESSION', ?, ?, ?)
      `).run(req.user.id, String(sessionId), JSON.stringify({ groupId: group_id, topic }), req.ip || '127.0.0.1');

      return res.status(201).json({
        success: true,
        sessionId,
        topic,
        startedAt: new Date().toISOString(),
        qrPayload: initialQR
      });
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * GET /api/sessions/:id/qr-token (TEACHER & ADMIN)
   * Rotates and produces the latest dynamic HMAC-signed token
   */
  getSessionQRToken(req, res) {
    try {
      const sessionId = req.params.id;
      const session = db.prepare('SELECT id, group_id, teacher_id, status, session_secret FROM class_sessions WHERE id = ?').get(sessionId);

      if (!session) {
        return res.status(404).json({ error: 'SESSION_NOT_FOUND' });
      }

      if (session.status !== 'ACTIVE') {
        return res.status(400).json({
          error: 'SESSION_CLOSED',
          message: 'La sesión de clase ya está cerrada. No se pueden generar nuevos códigos QR.'
        });
      }

      if (req.user.role === 'TEACHER' && session.teacher_id !== req.user.teacherId) {
        return res.status(403).json({ error: 'NOT_COURSE_TEACHER' });
      }

      const qrPayload = generateQRPayload(session.id, session.session_secret);
      return res.json({ success: true, qrPayload });
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * POST /api/sessions/:id/close (TEACHER & ADMIN)
   */
  closeSession(req, res) {
    try {
      const sessionId = req.params.id;
      const session = db.prepare('SELECT id, group_id, teacher_id, status FROM class_sessions WHERE id = ?').get(sessionId);

      if (!session) {
        return res.status(404).json({ error: 'SESSION_NOT_FOUND' });
      }

      if (req.user.role === 'TEACHER' && session.teacher_id !== req.user.teacherId) {
        return res.status(403).json({ error: 'NOT_COURSE_TEACHER' });
      }

      db.prepare(`
        UPDATE class_sessions 
        SET status = 'CLOSED', closed_at = CURRENT_TIMESTAMP 
        WHERE id = ?
      `).run(sessionId);

      db.prepare(`
        INSERT INTO audit_logs (user_id, action, resource_type, resource_id, details_json, ip_address)
        VALUES (?, 'SESSION_CLOSED', 'CLASS_SESSION', ?, '{}', ?)
      `).run(req.user.id, String(sessionId), req.ip || '127.0.0.1');

      return res.json({ success: true, message: 'Sesión de clase cerrada con éxito.' });
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * POST /api/attendance/register (STUDENT ONLY)
   */
  registerAttendance(req, res) {
    try {
      const studentId = req.user.studentId;
      if (!studentId) {
        return res.status(403).json({
          error: 'STUDENT_ROLE_REQUIRED',
          message: 'Solo los estudiantes pueden registrar asistencia.'
        });
      }

      const { sessionId, nonce, timestamp, signature, latitude, longitude, userAgent } = req.body || {};

      if (!sessionId || !nonce || !timestamp || !signature) {
        return res.status(400).json({
          error: 'MISSING_QR_PAYLOAD',
          message: 'Payload QR incompleto (se requiere sessionId, nonce, timestamp, signature).'
        });
      }

      // 1. Fetch Session
      const session = db.prepare(`
        SELECT id, group_id, teacher_id, status, session_secret, geo_lat, geo_lng, geo_radius_meters 
        FROM class_sessions 
        WHERE id = ?
      `).get(sessionId);

      if (!session) {
        return res.status(404).json({ error: 'SESSION_NOT_FOUND', message: 'La sesión no existe.' });
      }

      if (session.status !== 'ACTIVE') {
        return res.status(400).json({
          error: 'SESSION_CLOSED',
          message: 'La sesión de clase se encuentra cerrada. Registro no permitido.'
        });
      }

      // 2. Verify Student Enrollment
      const enrollment = db.prepare(`
        SELECT id FROM enrollments 
        WHERE group_id = ? AND student_id = ? AND status = 'ACTIVE'
      `).get(session.group_id, studentId);

      if (!enrollment) {
        return res.status(403).json({
          error: 'STUDENT_NOT_ENROLLED',
          message: 'El estudiante no está matriculado en esta asignatura/grupo.'
        });
      }

      // 3. Cryptographic Verification of Dynamic QR Payload
      const cryptoResult = verifyQRPayload(sessionId, nonce, timestamp, signature, session.session_secret);

      if (!cryptoResult.valid) {
        if (cryptoResult.reason === 'TOKEN_EXPIRED') {
          return res.status(400).json({
            error: 'TOKEN_EXPIRED',
            message: `El código QR ha expirado (${Math.round(cryptoResult.ageSeconds)}s de antigüedad). Por favor escanee el código QR actualizado proyectado en pantalla.`
          });
        }
        return res.status(401).json({
          error: 'INVALID_SIGNATURE',
          message: 'Firma criptográfica inválida o token manipulado.'
        });
      }

      // 4. Anti-Replay Check (Nonce)
      const existingToken = db.prepare('SELECT id FROM attendance_tokens WHERE nonce = ?').get(nonce);
      if (existingToken) {
        return res.status(409).json({
          error: 'TOKEN_ALREADY_USED',
          message: 'Este token QR específico ya fue utilizado.'
        });
      }

      // 5. Unique Attendance Check: 1 student + 1 session <= 1 attendance
      const existingAttendance = db.prepare(`
        SELECT id, registered_at, status FROM attendances 
        WHERE session_id = ? AND student_id = ?
      `).get(sessionId, studentId);

      if (existingAttendance) {
        return res.status(409).json({
          error: 'ATTENDANCE_ALREADY_REGISTERED',
          message: 'Ya se encuentra registrada la asistencia para esta sesión.',
          attendance: existingAttendance
        });
      }

      // 6. Geopresence & Telemetry Analysis
      let distanceMeters = null;
      let isFlagged = 0;
      let flagReason = null;

      if (latitude != null && longitude != null && session.geo_lat != null && session.geo_lng != null) {
        distanceMeters = calculateHaversineDistance(
          Number(latitude),
          Number(longitude),
          session.geo_lat,
          session.geo_lng
        );

        const maxRadius = session.geo_radius_meters || 100.0;
        if (distanceMeters > maxRadius) {
          isFlagged = 1;
          flagReason = `Distancia calculada (${distanceMeters}m) excede el radio permitido de aula (${maxRadius}m).`;
        }
      }

      // 7. Atomic Insert (Transaction)
      const attendanceResult = transaction((dbSync) => {
        // Insert attendance record
        const attInfo = dbSync.prepare(`
          INSERT INTO attendances (
            session_id, student_id, status, registered_at, verification_method,
            ip_address, user_agent, distance_meters, is_flagged, flag_reason
          ) VALUES (?, ?, 'PRESENT', CURRENT_TIMESTAMP, 'QR_DYNAMIC_HMAC', ?, ?, ?, ?, ?)
        `).run(
          sessionId,
          studentId,
          req.ip || '127.0.0.1',
          userAgent || req.headers['user-agent'] || 'Unknown',
          distanceMeters,
          isFlagged,
          flagReason
        );

        // Mark nonce as used
        dbSync.prepare(`
          INSERT INTO attendance_tokens (session_id, nonce, student_id)
          VALUES (?, ?, ?)
        `).run(sessionId, nonce, studentId);

        return {
          id: Number(attInfo.lastInsertRowid),
          sessionId,
          studentId,
          status: 'PRESENT',
          registeredAt: new Date().toISOString(),
          isFlagged,
          flagReason,
          distanceMeters
        };
      });

      return res.status(200).json({
        success: true,
        message: '¡Asistencia registrada con éxito!',
        attendance: attendanceResult
      });
    } catch (err) {
      if (err.message && err.message.includes('UNIQUE constraint failed')) {
        return res.status(409).json({
          error: 'ATTENDANCE_ALREADY_REGISTERED',
          message: 'Asistencia ya registrada previamente (conflicto de concurrencia prevenido).'
        });
      }
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * GET /api/attendance/session/:id (TEACHER & ADMIN)
   * Returns live session attendances and pending enrolled students
   */
  getSessionAttendance(req, res) {
    try {
      const sessionId = req.params.id;
      const session = db.prepare(`
        SELECT cs.*, cg.group_code, s.name AS subject_name
        FROM class_sessions cs
        JOIN course_groups cg ON cs.group_id = cg.id
        JOIN subjects s ON cg.subject_id = s.id
        WHERE cs.id = ?
      `).get(sessionId);

      if (!session) {
        return res.status(404).json({ error: 'SESSION_NOT_FOUND' });
      }

      if (req.user.role === 'TEACHER' && session.teacher_id !== req.user.teacherId) {
        return res.status(403).json({ error: 'NOT_COURSE_TEACHER' });
      }

      // Present/Registered attendances
      const attendances = db.prepare(`
        SELECT 
          a.id AS attendance_id, a.status, a.registered_at, a.verification_method,
          a.distance_meters, a.is_flagged, a.flag_reason, a.ip_address,
          st.id AS student_id, st.student_code, st.career,
          u.first_name, u.last_name, u.email
        FROM attendances a
        JOIN students st ON a.student_id = st.id
        JOIN users u ON st.user_id = u.id
        WHERE a.session_id = ?
        ORDER BY a.registered_at DESC
      `).all(sessionId);

      // Enrolled students in the group
      const allEnrolled = db.prepare(`
        SELECT 
          st.id AS student_id, st.student_code, st.career,
          u.first_name, u.last_name, u.email
        FROM enrollments e
        JOIN students st ON e.student_id = st.id
        JOIN users u ON st.user_id = u.id
        WHERE e.group_id = ? AND e.status = 'ACTIVE'
        ORDER BY u.last_name ASC
      `).all(session.group_id);

      const registeredStudentIds = new Set(attendances.map(a => a.student_id));
      const pendingStudents = allEnrolled.filter(s => !registeredStudentIds.has(s.student_id));

      return res.json({
        session,
        attendances,
        pendingStudents,
        totalEnrolled: allEnrolled.length,
        totalPresent: attendances.length,
        attendancePercentage: allEnrolled.length > 0 ? Math.round((attendances.length / allEnrolled.length) * 100) : 0
      });
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * GET /api/attendance/my-history (STUDENT ONLY)
   */
  getMyAttendanceHistory(req, res) {
    try {
      const studentId = req.user.studentId;
      if (!studentId) {
        return res.status(403).json({ error: 'STUDENT_ROLE_REQUIRED' });
      }

      const records = db.prepare(`
        SELECT 
          a.id AS attendance_id, a.status, a.registered_at, a.verification_method, a.is_flagged,
          cs.id AS session_id, cs.topic, cs.started_at,
          cg.id AS group_id, cg.group_code, cg.classroom,
          s.name AS subject_name, s.code AS subject_code,
          u.first_name AS teacher_first_name, u.last_name AS teacher_last_name
        FROM attendances a
        JOIN class_sessions cs ON a.session_id = cs.id
        JOIN course_groups cg ON cs.group_id = cg.id
        JOIN subjects s ON cg.subject_id = s.id
        JOIN teachers t ON cg.teacher_id = t.id
        JOIN users u ON t.user_id = u.id
        WHERE a.student_id = ?
        ORDER BY a.registered_at DESC
      `).all(studentId);

      // Calculate summary stats per group
      const enrolledGroups = db.prepare(`
        SELECT 
          cg.id AS group_id, cg.group_code, s.name AS subject_name, s.code AS subject_code,
          (SELECT COUNT(*) FROM class_sessions WHERE group_id = cg.id) AS total_sessions,
          (SELECT COUNT(*) FROM attendances a JOIN class_sessions cs ON a.session_id = cs.id WHERE cs.group_id = cg.id AND a.student_id = ?) AS attended_sessions
        FROM enrollments e
        JOIN course_groups cg ON e.group_id = cg.id
        JOIN subjects s ON cg.subject_id = s.id
        WHERE e.student_id = ? AND e.status = 'ACTIVE'
      `).all(studentId, studentId);

      return res.json({ records, enrolledGroups });
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  }
};
