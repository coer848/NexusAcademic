import { db, transaction } from '../database/db.js';
import { calculateStudentAverage, validateEvaluationWeights, validateScoreBounds } from '../services/gradeService.js';

export const gradeController = {
  /**
   * POST /api/evaluations (TEACHER & ADMIN)
   */
  createEvaluation(req, res) {
    try {
      const { group_id, name, description, type, weight, max_score, due_date } = req.body;

      if (!group_id || !name || !type || weight == null) {
        return res.status(400).json({
          error: 'MISSING_FIELDS',
          message: 'group_id, name, type y weight son requeridos.'
        });
      }

      // Verify teacher ownership
      const group = db.prepare('SELECT id, teacher_id FROM course_groups WHERE id = ?').get(group_id);
      if (!group) return res.status(404).json({ error: 'GROUP_NOT_FOUND' });

      if (req.user.role === 'TEACHER' && group.teacher_id !== req.user.teacherId) {
        return res.status(403).json({ error: 'NOT_COURSE_TEACHER', message: 'No es el docente de este grupo.' });
      }

      const numWeight = Number(weight);
      if (isNaN(numWeight) || numWeight <= 0 || numWeight > 100) {
        return res.status(400).json({
          error: 'INVALID_WEIGHT',
          message: 'La ponderación debe ser un porcentaje positivo mayor a 0 y menor o igual a 100.'
        });
      }

      // Validate cumulative weight
      const existingEvaluations = db.prepare("SELECT id, weight FROM evaluations WHERE group_id = ? AND status = 'ACTIVE'").all(group_id);
      const weightValidation = validateEvaluationWeights(existingEvaluations, numWeight);

      if (!weightValidation.valid) {
        return res.status(400).json({
          error: 'INVALID_WEIGHT_SUM',
          message: `La suma de ponderaciones (${weightValidation.projectedTotal}%) superaría el 100%. Máximo disponible: ${weightValidation.maxAllowed}%.`
        });
      }

      const info = db.prepare(`
        INSERT INTO evaluations (group_id, name, description, type, weight, max_score, due_date, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'ACTIVE')
      `).run(
        group_id,
        name.trim(),
        description || null,
        type,
        numWeight,
        max_score ? Number(max_score) : 5.0,
        due_date || null
      );

      const evaluationId = Number(info.lastInsertRowid);

      db.prepare(`
        INSERT INTO audit_logs (user_id, action, resource_type, resource_id, details_json, ip_address)
        VALUES (?, 'EVALUATION_CREATED', 'EVALUATION', ?, ?, ?)
      `).run(req.user.id, String(evaluationId), JSON.stringify({ group_id, name, weight: numWeight }), req.ip || '127.0.0.1');

      return res.status(201).json({ success: true, evaluationId });
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * PUT /api/evaluations/:id (TEACHER & ADMIN)
   */
  updateEvaluation(req, res) {
    try {
      const evaluationId = req.params.id;
      const { name, description, type, weight, max_score, due_date, status } = req.body;

      const evalObj = db.prepare(`
        SELECT ev.*, cg.teacher_id 
        FROM evaluations ev
        JOIN course_groups cg ON ev.group_id = cg.id
        WHERE ev.id = ?
      `).get(evaluationId);

      if (!evalObj) return res.status(404).json({ error: 'EVALUATION_NOT_FOUND' });

      if (req.user.role === 'TEACHER' && evalObj.teacher_id !== req.user.teacherId) {
        return res.status(403).json({ error: 'NOT_COURSE_TEACHER' });
      }

      if (weight != null) {
        const numWeight = Number(weight);
        if (isNaN(numWeight) || numWeight <= 0 || numWeight > 100) {
          return res.status(400).json({ error: 'INVALID_WEIGHT' });
        }
        const existing = db.prepare("SELECT id, weight FROM evaluations WHERE group_id = ? AND status = 'ACTIVE'").all(evalObj.group_id);
        const weightVal = validateEvaluationWeights(existing, numWeight, evaluationId);
        if (!weightVal.valid) {
          return res.status(400).json({
            error: 'INVALID_WEIGHT_SUM',
            message: `Ponderación excede el 100%. Máximo permitido: ${weightVal.maxAllowed}%.`
          });
        }
      }

      db.prepare(`
        UPDATE evaluations
        SET name = COALESCE(?, name),
            description = COALESCE(?, description),
            type = COALESCE(?, type),
            weight = COALESCE(?, weight),
            max_score = COALESCE(?, max_score),
            due_date = COALESCE(?, due_date),
            status = COALESCE(?, status)
        WHERE id = ?
      `).run(
        name ? name.trim() : null,
        description !== undefined ? description : null,
        type || null,
        weight != null ? Number(weight) : null,
        max_score != null ? Number(max_score) : null,
        due_date !== undefined ? due_date : null,
        status || null,
        evaluationId
      );

      return res.json({ success: true, message: 'Evaluación actualizada con éxito.' });
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * DELETE /api/evaluations/:id (TEACHER & ADMIN)
   */
  deleteEvaluation(req, res) {
    try {
      const evaluationId = req.params.id;
      const evalObj = db.prepare(`
        SELECT ev.*, cg.teacher_id 
        FROM evaluations ev
        JOIN course_groups cg ON ev.group_id = cg.id
        WHERE ev.id = ?
      `).get(evaluationId);

      if (!evalObj) return res.status(404).json({ error: 'EVALUATION_NOT_FOUND' });

      if (req.user.role === 'TEACHER' && evalObj.teacher_id !== req.user.teacherId) {
        return res.status(403).json({ error: 'NOT_COURSE_TEACHER' });
      }

      db.prepare('DELETE FROM evaluations WHERE id = ?').run(evaluationId);

      db.prepare(`
        INSERT INTO audit_logs (user_id, action, resource_type, resource_id, details_json, ip_address)
        VALUES (?, 'EVALUATION_DELETED', 'EVALUATION', ?, '{}', ?)
      `).run(req.user.id, String(evaluationId), req.ip || '127.0.0.1');

      return res.json({ success: true, message: 'Evaluación eliminada correctamente.' });
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * POST /api/grades (TEACHER & ADMIN)
   * Assigns or updates a single grade with required audit reasoning
   */
  saveGrade(req, res) {
    try {
      const { evaluation_id, student_id, score, feedback, change_reason } = req.body;

      if (!evaluation_id || !student_id || score == null) {
        return res.status(400).json({
          error: 'MISSING_FIELDS',
          message: 'evaluation_id, student_id y score son obligatorios.'
        });
      }

      // Check evaluation & teacher ownership
      const evalObj = db.prepare(`
        SELECT ev.*, cg.teacher_id, cg.id AS group_id 
        FROM evaluations ev
        JOIN course_groups cg ON ev.group_id = cg.id
        WHERE ev.id = ?
      `).get(evaluation_id);

      if (!evalObj) return res.status(404).json({ error: 'EVALUATION_NOT_FOUND' });

      if (req.user.role === 'TEACHER' && evalObj.teacher_id !== req.user.teacherId) {
        return res.status(403).json({ error: 'NOT_COURSE_TEACHER', message: 'No es el docente de este grupo.' });
      }

      // Validate student enrollment in group
      const enrollment = db.prepare(`
        SELECT id FROM enrollments WHERE group_id = ? AND student_id = ? AND status = 'ACTIVE'
      `).get(evalObj.group_id, student_id);

      if (!enrollment) {
        return res.status(400).json({
          error: 'STUDENT_NOT_ENROLLED',
          message: 'El estudiante no está matriculado en este grupo.'
        });
      }

      // Validate score bounds against institution scale
      const institution = db.prepare('SELECT min_grade, max_grade FROM institutions LIMIT 1').get();
      const minG = institution ? institution.min_grade : 0.0;
      const maxG = institution ? institution.max_grade : 5.0;

      const scoreVal = validateScoreBounds(score, minG, maxG);
      if (!scoreVal.valid) {
        return res.status(400).json({ error: 'SCORE_OUT_OF_BOUNDS', message: scoreVal.reason });
      }

      const finalScore = scoreVal.score;

      // Atomic update & audit
      const result = transaction((dbSync) => {
        const existingGrade = dbSync.prepare(`
          SELECT id, score, feedback FROM grades 
          WHERE evaluation_id = ? AND student_id = ?
        `).get(evaluation_id, student_id);

        let gradeId;
        let oldScore = null;

        if (existingGrade) {
          gradeId = existingGrade.id;
          oldScore = existingGrade.score;

          // If score changed, reason is mandatory
          if (oldScore !== finalScore && (!change_reason || change_reason.trim() === '')) {
            throw new Error('REASON_REQUIRED: Debe proporcionar el motivo de la modificación de la nota.');
          }

          dbSync.prepare(`
            UPDATE grades 
            SET score = ?, feedback = ?, graded_by_user_id = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
          `).run(finalScore, feedback || null, req.user.id, gradeId);
        } else {
          const insertInfo = dbSync.prepare(`
            INSERT INTO grades (evaluation_id, student_id, score, feedback, graded_by_user_id)
            VALUES (?, ?, ?, ?, ?)
          `).run(evaluation_id, student_id, finalScore, feedback || null, req.user.id);

          gradeId = Number(insertInfo.lastInsertRowid);
        }

        // Insert immutable audit trail
        dbSync.prepare(`
          INSERT INTO grade_history (
            grade_id, evaluation_id, student_id, old_score, new_score, change_reason, changed_by_user_id, ip_address
          ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        `).run(
          gradeId,
          evaluation_id,
          student_id,
          oldScore,
          finalScore,
          change_reason || (oldScore == null ? 'Calificación inicial' : 'Ajuste de nota'),
          req.user.id,
          req.ip || '127.0.0.1'
        );

        return { gradeId, oldScore, newScore: finalScore };
      });

      return res.json({
        success: true,
        message: 'Calificación guardada y auditada exitosamente.',
        grade: result
      });
    } catch (err) {
      if (err.message && err.message.startsWith('REASON_REQUIRED')) {
        return res.status(400).json({ error: 'REASON_REQUIRED', message: err.message.replace('REASON_REQUIRED: ', '') });
      }
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * POST /api/grades/batch (TEACHER & ADMIN)
   * Submits multiple grades in a single atomic transaction
   */
  saveBatchGrades(req, res) {
    try {
      const { evaluation_id, gradesList, default_reason } = req.body;

      if (!evaluation_id || !gradesList || !Array.isArray(gradesList)) {
        return res.status(400).json({ error: 'INVALID_PAYLOAD', message: 'evaluation_id y lista de notas requeridas.' });
      }

      const evalObj = db.prepare(`
        SELECT ev.*, cg.teacher_id, cg.id AS group_id 
        FROM evaluations ev
        JOIN course_groups cg ON ev.group_id = cg.id
        WHERE ev.id = ?
      `).get(evaluation_id);

      if (!evalObj) return res.status(404).json({ error: 'EVALUATION_NOT_FOUND' });

      if (req.user.role === 'TEACHER' && evalObj.teacher_id !== req.user.teacherId) {
        return res.status(403).json({ error: 'NOT_COURSE_TEACHER' });
      }

      const institution = db.prepare('SELECT min_grade, max_grade FROM institutions LIMIT 1').get();
      const minG = institution ? institution.min_grade : 0.0;
      const maxG = institution ? institution.max_grade : 5.0;

      transaction((dbSync) => {
        for (const item of gradesList) {
          const { student_id, score, feedback, change_reason } = item;
          if (score == null || score === '') continue;

          const scoreVal = validateScoreBounds(score, minG, maxG);
          if (!scoreVal.valid) {
            throw new Error(`Estudiante ID ${student_id}: ${scoreVal.reason}`);
          }

          const existingGrade = dbSync.prepare(`
            SELECT id, score FROM grades WHERE evaluation_id = ? AND student_id = ?
          `).get(evaluation_id, student_id);

          let gradeId;
          let oldScore = null;

          if (existingGrade) {
            gradeId = existingGrade.id;
            oldScore = existingGrade.score;

            if (oldScore !== scoreVal.score) {
              dbSync.prepare(`
                UPDATE grades 
                SET score = ?, feedback = ?, graded_by_user_id = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
              `).run(scoreVal.score, feedback || null, req.user.id, gradeId);

              dbSync.prepare(`
                INSERT INTO grade_history (
                  grade_id, evaluation_id, student_id, old_score, new_score, change_reason, changed_by_user_id, ip_address
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
              `).run(
                gradeId,
                evaluation_id,
                student_id,
                oldScore,
                scoreVal.score,
                change_reason || default_reason || 'Calificación masiva en lote',
                req.user.id,
                req.ip || '127.0.0.1'
              );
            }
          } else {
            const insertInfo = dbSync.prepare(`
              INSERT INTO grades (evaluation_id, student_id, score, feedback, graded_by_user_id)
              VALUES (?, ?, ?, ?, ?)
            `).run(evaluation_id, student_id, scoreVal.score, feedback || null, req.user.id);

            gradeId = Number(insertInfo.lastInsertRowid);

            dbSync.prepare(`
              INSERT INTO grade_history (
                grade_id, evaluation_id, student_id, old_score, new_score, change_reason, changed_by_user_id, ip_address
              ) VALUES (?, ?, ?, NULL, ?, ?, ?, ?)
            `).run(
              gradeId,
              evaluation_id,
              student_id,
              scoreVal.score,
              change_reason || default_reason || 'Calificación inicial en lote',
              req.user.id,
              req.ip || '127.0.0.1'
            );
          }
        }
      });

      return res.json({ success: true, message: 'Calificaciones registradas correctamente.' });
    } catch (err) {
      return res.status(400).json({ error: 'BATCH_ERROR', message: err.message });
    }
  },

  /**
   * GET /api/grades/group/:groupId/matrix (TEACHER & ADMIN)
   * Full gradebook matrix for the group with calculated weighted averages
   */
  getGroupGradesMatrix(req, res) {
    try {
      const groupId = req.params.groupId;

      const group = db.prepare(`
        SELECT cg.*, s.name AS subject_name, s.code AS subject_code 
        FROM course_groups cg
        JOIN subjects s ON cg.subject_id = s.id
        WHERE cg.id = ?
      `).get(groupId);

      if (!group) return res.status(404).json({ error: 'GROUP_NOT_FOUND' });

      if (req.user.role === 'TEACHER' && group.teacher_id !== req.user.teacherId) {
        return res.status(403).json({ error: 'NOT_COURSE_TEACHER' });
      }

      const evaluations = db.prepare(`
        SELECT * FROM evaluations WHERE group_id = ? ORDER BY due_date ASC, id ASC
      `).all(groupId);

      const enrolledStudents = db.prepare(`
        SELECT 
          st.id AS student_id, st.student_code, st.career,
          u.id AS user_id, u.first_name, u.last_name, u.email
        FROM enrollments e
        JOIN students st ON e.student_id = st.id
        JOIN users u ON st.user_id = u.id
        WHERE e.group_id = ? AND e.status = 'ACTIVE'
        ORDER BY u.last_name ASC, u.first_name ASC
      `).all(groupId);

      const allGrades = db.prepare(`
        SELECT g.*, ev.group_id
        FROM grades g
        JOIN evaluations ev ON g.evaluation_id = ev.id
        WHERE ev.group_id = ?
      `).all(groupId);

      const institution = db.prepare('SELECT min_passing_grade, max_grade FROM institutions LIMIT 1').get();
      const minPass = institution ? institution.min_passing_grade : 3.0;
      const maxG = institution ? institution.max_grade : 5.0;

      // Build student matrix
      const matrix = enrolledStudents.map(student => {
        const studentGrades = allGrades.filter(g => g.student_id === student.student_id);
        const stats = calculateStudentAverage(evaluations, studentGrades, minPass, maxG);

        return {
          student,
          grades: stats.details,
          weightedAverage: stats.weightedAverage,
          accumulatedScore: stats.accumulatedScore,
          totalEvaluatedWeight: stats.totalEvaluatedWeight,
          remainingWeight: stats.remainingWeight,
          projectedNeededGrade: stats.projectedNeededGrade,
          isPassing: stats.isPassing
        };
      });

      return res.json({
        group,
        evaluations,
        matrix,
        totalStudents: enrolledStudents.length,
        totalEvaluations: evaluations.length
      });
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * GET /api/grades/my-grades (STUDENT ONLY - Anti-IDOR Guaranteed)
   */
  getMyGrades(req, res) {
    try {
      const studentId = req.user.studentId;
      if (!studentId) {
        return res.status(403).json({ error: 'STUDENT_ROLE_REQUIRED' });
      }

      // Enrolled active groups
      const groups = db.prepare(`
        SELECT 
          cg.id AS group_id, cg.group_code, cg.schedule, cg.classroom,
          s.id AS subject_id, s.name AS subject_name, s.code AS subject_code, s.credits,
          ap.name AS period_name,
          u.first_name AS teacher_first_name, u.last_name AS teacher_last_name
        FROM enrollments e
        JOIN course_groups cg ON e.group_id = cg.id
        JOIN subjects s ON cg.subject_id = s.id
        JOIN academic_periods ap ON cg.period_id = ap.id
        JOIN teachers t ON cg.teacher_id = t.id
        JOIN users u ON t.user_id = u.id
        WHERE e.student_id = ? AND e.status = 'ACTIVE'
        ORDER BY s.name ASC
      `).all(studentId);

      const institution = db.prepare('SELECT min_passing_grade, max_grade FROM institutions LIMIT 1').get();
      const minPass = institution ? institution.min_passing_grade : 3.0;
      const maxG = institution ? institution.max_grade : 5.0;

      const report = groups.map(group => {
        const evaluations = db.prepare(`
          SELECT * FROM evaluations WHERE group_id = ? ORDER BY due_date ASC, id ASC
        `).all(group.group_id);

        const grades = db.prepare(`
          SELECT g.* 
          FROM grades g
          JOIN evaluations ev ON g.evaluation_id = ev.id
          WHERE ev.group_id = ? AND g.student_id = ?
        `).all(group.group_id, studentId);

        const stats = calculateStudentAverage(evaluations, grades, minPass, maxG);

        return {
          group,
          stats
        };
      });

      return res.json({ studentId, courses: report });
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * GET /api/grades/:gradeId/history (TEACHER, ADMIN, STUDENT)
   */
  getGradeAuditHistory(req, res) {
    try {
      const gradeId = req.params.gradeId;
      const grade = db.prepare(`
        SELECT g.*, ev.name AS evaluation_name, ev.weight, ev.group_id, cg.teacher_id
        FROM grades g
        JOIN evaluations ev ON g.evaluation_id = ev.id
        JOIN course_groups cg ON ev.group_id = cg.id
        WHERE g.id = ?
      `).get(gradeId);

      if (!grade) return res.status(404).json({ error: 'GRADE_NOT_FOUND' });

      // RBAC check: student can only view their own history; teacher must own group; admin can view all
      if (req.user.role === 'STUDENT' && grade.student_id !== req.user.studentId) {
        return res.status(403).json({ error: 'ACCESS_DENIED', message: 'No puede ver el historial de otro estudiante.' });
      }
      if (req.user.role === 'TEACHER' && grade.teacher_id !== req.user.teacherId) {
        return res.status(403).json({ error: 'ACCESS_DENIED' });
      }

      const history = db.prepare(`
        SELECT 
          gh.*,
          u.first_name AS changed_by_first_name, u.last_name AS changed_by_last_name, u.role AS changed_by_role
        FROM grade_history gh
        JOIN users u ON gh.changed_by_user_id = u.id
        WHERE gh.grade_id = ?
        ORDER BY gh.created_at DESC, gh.id DESC
      `).all(gradeId);

      return res.json({ grade, history });
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  }
};
