import { db } from '../database/db.js';

/**
 * Middleware to restrict access based on user role
 * @param  {...string} roles Allowed roles ('ADMIN', 'TEACHER', 'STUDENT')
 */
export function requireRole(...roles) {
  return (req, res, next) => {
    if (!req.user) {
      return res.status(401).json({
        error: 'AUTHENTICATION_REQUIRED',
        message: 'Debe autenticarse para acceder a este recurso.'
      });
    }

    if (!roles.includes(req.user.role)) {
      return res.status(403).json({
        error: 'ACCESS_DENIED',
        message: `Acceso denegado. Se requiere uno de los siguientes roles: ${roles.join(', ')}.`
      });
    }

    next();
  };
}

/**
 * Middleware to ensure the authenticated teacher is assigned to the requested course group
 * Admin always bypasses this check
 */
export function requireTeacherOfGroup(paramName = 'groupId') {
  return (req, res, next) => {
    if (!req.user) {
      return res.status(401).json({ error: 'AUTHENTICATION_REQUIRED' });
    }

    if (req.user.role === 'ADMIN') {
      return next(); // Admins have global access
    }

    if (req.user.role !== 'TEACHER') {
      return res.status(403).json({
        error: 'ACCESS_DENIED',
        message: 'Solo profesores o administradores pueden acceder a este recurso.'
      });
    }

    const groupId = req.params[paramName] || req.body[paramName] || req.query[paramName];
    if (!groupId) {
      return res.status(400).json({
        error: 'MISSING_GROUP_ID',
        message: 'Identificador de grupo no proporcionado.'
      });
    }

    const group = db.prepare('SELECT id, teacher_id FROM course_groups WHERE id = ?').get(groupId);

    if (!group) {
      return res.status(404).json({
        error: 'GROUP_NOT_FOUND',
        message: 'El grupo académico especificado no existe.'
      });
    }

    if (group.teacher_id !== req.user.teacherId) {
      return res.status(403).json({
        error: 'NOT_ASSIGNED_TEACHER',
        message: 'Usted no es el profesor asignado a este grupo académico.'
      });
    }

    req.courseGroup = group;
    next();
  };
}

/**
 * Middleware to ensure student is enrolled or teacher is assigned to group
 */
export function requireMemberOfGroup(paramName = 'groupId') {
  return (req, res, next) => {
    if (!req.user) {
      return res.status(401).json({ error: 'AUTHENTICATION_REQUIRED' });
    }

    if (req.user.role === 'ADMIN') return next();

    const groupId = req.params[paramName] || req.body[paramName] || req.query[paramName];
    if (!groupId) {
      return res.status(400).json({ error: 'MISSING_GROUP_ID' });
    }

    if (req.user.role === 'TEACHER') {
      const group = db.prepare('SELECT id, teacher_id FROM course_groups WHERE id = ?').get(groupId);
      if (!group || group.teacher_id !== req.user.teacherId) {
        return res.status(403).json({ error: 'NOT_ASSIGNED_TEACHER' });
      }
      return next();
    }

    if (req.user.role === 'STUDENT') {
      const enrollment = db.prepare(`
        SELECT id FROM enrollments 
        WHERE group_id = ? AND student_id = ? AND status = 'ACTIVE'
      `).get(groupId, req.user.studentId);

      if (!enrollment) {
        return res.status(403).json({
          error: 'STUDENT_NOT_ENROLLED',
          message: 'Usted no está matriculado activamente en este grupo.'
        });
      }
      return next();
    }

    return res.status(403).json({ error: 'ACCESS_DENIED' });
  };
}
