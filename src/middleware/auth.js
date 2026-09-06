import { verifyToken } from '../utils/crypto.js';
import { db } from '../database/db.js';

export function authenticate(req, res, next) {
  const authHeader = req.headers['authorization'] || req.headers['Authorization'];
  
  if (!authHeader || !authHeader.startsWith('Bearer ')) {
    return res.status(401).json({
      error: 'AUTHENTICATION_REQUIRED',
      message: 'Token de autorización ausente o formato inválido (debe ser Bearer <token>).'
    });
  }

  const token = authHeader.substring(7).trim();
  const payload = verifyToken(token);

  if (!payload) {
    return res.status(401).json({
      error: 'INVALID_OR_EXPIRED_TOKEN',
      message: 'El token de sesión es inválido o ha expirado.'
    });
  }

  // Verify user in database
  const user = db.prepare('SELECT id, email, first_name, last_name, role, status FROM users WHERE id = ?').get(payload.id);

  if (!user || user.status !== 'ACTIVE') {
    return res.status(401).json({
      error: 'USER_INACTIVE_OR_DELETED',
      message: 'El usuario no existe o se encuentra inactivo.'
    });
  }

  // Attach role-specific extensions
  let teacherId = null;
  let studentId = null;

  if (user.role === 'TEACHER') {
    const teacher = db.prepare('SELECT id, teacher_code, department FROM teachers WHERE user_id = ?').get(user.id);
    if (teacher) teacherId = teacher.id;
  } else if (user.role === 'STUDENT') {
    const student = db.prepare('SELECT id, student_code, career, semester FROM students WHERE user_id = ?').get(user.id);
    if (student) studentId = student.id;
  }

  req.user = {
    id: user.id,
    email: user.email,
    firstName: user.first_name,
    lastName: user.last_name,
    role: user.role,
    teacherId,
    studentId
  };

  next();
}
