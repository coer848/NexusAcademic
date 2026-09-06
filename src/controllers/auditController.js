import { db } from '../database/db.js';

export const auditController = {
  /**
   * GET /api/audit/logs (ADMIN ONLY)
   */
  getAuditLogs(req, res) {
    try {
      const limit = Number(req.query.limit) || 100;
      const logs = db.prepare(`
        SELECT 
          al.*,
          u.first_name, u.last_name, u.email, u.role
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.id
        ORDER BY al.created_at DESC, al.id DESC
        LIMIT ?
      `).all(limit);

      return res.json(logs);
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * GET /api/audit/grades-history (ADMIN & TEACHER)
   */
  getGlobalGradeHistory(req, res) {
    try {
      let query = `
        SELECT 
          gh.id, gh.old_score, gh.new_score, gh.change_reason, gh.created_at, gh.ip_address,
          ev.name AS evaluation_name, ev.weight AS evaluation_weight,
          cg.group_code, s.name AS subject_name,
          st_u.first_name AS student_first_name, st_u.last_name AS student_last_name, st.student_code,
          ch_u.first_name AS changed_by_first_name, ch_u.last_name AS changed_by_last_name, ch_u.role AS changed_by_role
        FROM grade_history gh
        JOIN evaluations ev ON gh.evaluation_id = ev.id
        JOIN course_groups cg ON ev.group_id = cg.id
        JOIN subjects s ON cg.subject_id = s.id
        JOIN students st ON gh.student_id = st.id
        JOIN users st_u ON st.user_id = st_u.id
        JOIN users ch_u ON gh.changed_by_user_id = ch_u.id
      `;

      const params = [];
      if (req.user.role === 'TEACHER') {
        query += ' WHERE cg.teacher_id = ?';
        params.push(req.user.teacherId);
      }

      query += ' ORDER BY gh.created_at DESC, gh.id DESC LIMIT 150';

      const history = db.prepare(query).all(...params);
      return res.json(history);
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * GET /api/audit/metrics (ADMIN ONLY)
   */
  getSystemMetrics(req, res) {
    try {
      const usersCount = db.prepare('SELECT role, COUNT(*) as count FROM users GROUP BY role').all();
      const totalStudents = db.prepare('SELECT COUNT(*) as count FROM students').get().count;
      const totalTeachers = db.prepare('SELECT COUNT(*) as count FROM teachers').get().count;
      const totalGroups = db.prepare('SELECT COUNT(*) as count FROM course_groups').get().count;
      const totalSessions = db.prepare('SELECT COUNT(*) as count FROM class_sessions').get().count;
      const totalAttendances = db.prepare('SELECT COUNT(*) as count FROM attendances').get().count;
      const flaggedAttendances = db.prepare('SELECT COUNT(*) as count FROM attendances WHERE is_flagged = 1').get().count;
      const totalGrades = db.prepare('SELECT COUNT(*) as count FROM grades').get().count;
      const totalGradeChanges = db.prepare('SELECT COUNT(*) as count FROM grade_history WHERE old_score IS NOT NULL').get().count;

      return res.json({
        usersCount,
        totalStudents,
        totalTeachers,
        totalGroups,
        totalSessions,
        totalAttendances,
        flaggedAttendances,
        totalGrades,
        totalGradeChanges
      });
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  }
};
