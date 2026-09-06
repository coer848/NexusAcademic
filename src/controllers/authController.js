import { db, transaction } from '../database/db.js';
import { verifyPassword, hashPassword, signToken } from '../utils/crypto.js';

export const authController = {
  /**
   * POST /api/auth/register
   * Permite el auto-registro de nuevos usuarios (Estudiantes, Docentes, Administradores)
   */
  register(req, res) {
    try {
      const { email, password, first_name, last_name, role, code, career, department, specialty, semester } = req.body || {};

      if (!email || !password || !first_name || !last_name || !role) {
        return res.status(400).json({
          error: 'MISSING_FIELDS',
          message: 'Nombre, apellido, correo, contraseña y rol son obligatorios.'
        });
      }

      if (password.length < 6) {
        return res.status(400).json({
          error: 'INVALID_PASSWORD',
          message: 'La contraseña debe tener al menos 6 caracteres.'
        });
      }

      const validRoles = ['ADMIN', 'TEACHER', 'STUDENT'];
      const normalizedRole = role.trim().toUpperCase();
      if (!validRoles.includes(normalizedRole)) {
        return res.status(400).json({
          error: 'INVALID_ROLE',
          message: 'El rol debe ser ADMIN, TEACHER o STUDENT.'
        });
      }

      const cleanEmail = email.trim().toLowerCase();

      // Check unique email
      const existingUser = db.prepare('SELECT id FROM users WHERE email = ?').get(cleanEmail);
      if (existingUser) {
        return res.status(409).json({
          error: 'EMAIL_ALREADY_EXISTS',
          message: 'El correo electrónico ya se encuentra registrado.'
        });
      }

      const passwordHash = hashPassword(password);

      const registeredUser = transaction((dbSync) => {
        // 1. Insert into users table
        const userInsert = dbSync.prepare(`
          INSERT INTO users (email, password_hash, first_name, last_name, role, status)
          VALUES (?, ?, ?, ?, ?, 'ACTIVE')
        `).run(cleanEmail, passwordHash, first_name.trim(), last_name.trim(), normalizedRole);

        const userId = Number(userInsert.lastInsertRowid);
        let teacherProfile = null;
        let studentProfile = null;

        // 2. Insert into role-specific extension table
        if (normalizedRole === 'TEACHER') {
          const teacherCode = code ? code.trim().toUpperCase() : `DOC-${Math.floor(100 + Math.random() * 900)}`;
          const teacherInsert = dbSync.prepare(`
            INSERT INTO teachers (user_id, teacher_code, department, specialty)
            VALUES (?, ?, ?, ?)
          `).run(
            userId,
            teacherCode,
            department ? department.trim() : 'Facultad de Ingeniería & Tecnología',
            specialty ? specialty.trim() : 'Docencia Universitaria'
          );
          teacherProfile = {
            id: Number(teacherInsert.lastInsertRowid),
            teacher_code: teacherCode,
            department: department || 'Facultad de Ingeniería & Tecnología'
          };
        } else if (normalizedRole === 'STUDENT') {
          const studentCode = code ? code.trim().toUpperCase() : `EST-2026-${Math.floor(10 + Math.random() * 90)}`;
          const studentInsert = dbSync.prepare(`
            INSERT INTO students (user_id, student_code, career, semester)
            VALUES (?, ?, ?, ?)
          `).run(
            userId,
            studentCode,
            career ? career.trim() : 'Ingeniería de Software',
            semester ? Number(semester) : 1
          );
          studentProfile = {
            id: Number(studentInsert.lastInsertRowid),
            student_code: studentCode,
            career: career || 'Ingeniería de Software'
          };
        }

        // 3. Audit log
        dbSync.prepare(`
          INSERT INTO audit_logs (user_id, action, resource_type, resource_id, details_json, ip_address)
          VALUES (?, 'USER_REGISTERED', 'USER', ?, ?, ?)
        `).run(userId, String(userId), JSON.stringify({ email: cleanEmail, role: normalizedRole }), req.ip || '127.0.0.1');

        return {
          id: userId,
          email: cleanEmail,
          firstName: first_name.trim(),
          lastName: last_name.trim(),
          role: normalizedRole,
          teacher: teacherProfile,
          student: studentProfile
        };
      });

      // Generate JWT Token for immediate login
      const token = signToken({
        id: registeredUser.id,
        email: registeredUser.email,
        role: registeredUser.role,
        firstName: registeredUser.firstName,
        lastName: registeredUser.lastName,
        teacherId: registeredUser.teacher ? registeredUser.teacher.id : null,
        studentId: registeredUser.student ? registeredUser.student.id : null
      });

      return res.status(201).json({
        success: true,
        message: '¡Cuenta creada y registrada con éxito!',
        token,
        user: registeredUser
      });
    } catch (err) {
      if (err.message && err.message.includes('UNIQUE')) {
        return res.status(409).json({
          error: 'UNIQUE_VIOLATION',
          message: 'El código de identificación o correo ya se encuentra registrado.'
        });
      }
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * POST /api/auth/login
   */
  login(req, res) {
    try {
      const { email, password } = req.body || {};

      if (!email || !password) {
        return res.status(400).json({
          error: 'MISSING_CREDENTIALS',
          message: 'Email y contraseña son obligatorios.'
        });
      }

      const user = db.prepare(`
        SELECT id, email, password_hash, first_name, last_name, role, status 
        FROM users 
        WHERE email = ?
      `).get(email.trim().toLowerCase());

      if (!user) {
        return res.status(401).json({
          error: 'INVALID_CREDENTIALS',
          message: 'Credenciales inválidas.'
        });
      }

      if (user.status !== 'ACTIVE') {
        return res.status(403).json({
          error: 'ACCOUNT_SUSPENDED',
          message: 'La cuenta se encuentra inactiva o suspendida.'
        });
      }

      const isMatch = verifyPassword(password, user.password_hash);
      if (!isMatch) {
        return res.status(401).json({
          error: 'INVALID_CREDENTIALS',
          message: 'Credenciales inválidas.'
        });
      }

      // Fetch role profile details
      let teacherProfile = null;
      let studentProfile = null;

      if (user.role === 'TEACHER') {
        teacherProfile = db.prepare(`
          SELECT id, teacher_code, department, specialty 
          FROM teachers 
          WHERE user_id = ?
        `).get(user.id);
      } else if (user.role === 'STUDENT') {
        studentProfile = db.prepare(`
          SELECT id, student_code, career, semester 
          FROM students 
          WHERE user_id = ?
        `).get(user.id);
      }

      const tokenPayload = {
        id: user.id,
        email: user.email,
        role: user.role,
        firstName: user.first_name,
        lastName: user.last_name,
        teacherId: teacherProfile ? teacherProfile.id : null,
        studentId: studentProfile ? studentProfile.id : null
      };

      const token = signToken(tokenPayload);

      // Audit login
      try {
        db.prepare(`
          INSERT INTO audit_logs (user_id, action, resource_type, resource_id, details_json, ip_address)
          VALUES (?, 'LOGIN_SUCCESS', 'AUTH', ?, ?, ?)
        `).run(user.id, String(user.id), JSON.stringify({ role: user.role }), req.ip || '127.0.0.1');
      } catch (e) {}

      return res.json({
        success: true,
        token,
        user: {
          id: user.id,
          email: user.email,
          firstName: user.first_name,
          lastName: user.last_name,
          role: user.role,
          teacher: teacherProfile,
          student: studentProfile
        }
      });
    } catch (err) {
      console.error('Login error:', err);
      return res.status(500).json({ error: 'SERVER_ERROR', message: 'Error interno en autenticación.' });
    }
  },

  /**
   * GET /api/auth/me
   */
  getMe(req, res) {
    try {
      const user = db.prepare(`
        SELECT id, email, first_name, last_name, role, status, created_at 
        FROM users 
        WHERE id = ?
      `).get(req.user.id);

      if (!user) {
        return res.status(404).json({ error: 'USER_NOT_FOUND' });
      }

      let teacher = null;
      let student = null;

      if (user.role === 'TEACHER') {
        teacher = db.prepare('SELECT id, teacher_code, department, specialty FROM teachers WHERE user_id = ?').get(user.id);
      } else if (user.role === 'STUDENT') {
        student = db.prepare('SELECT id, student_code, career, semester FROM students WHERE user_id = ?').get(user.id);
      }

      const institution = db.prepare('SELECT * FROM institutions LIMIT 1').get();

      return res.json({
        user: {
          ...user,
          firstName: user.first_name,
          lastName: user.last_name,
          teacher,
          student
        },
        institution
      });
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  },

  /**
   * POST /api/auth/change-password
   */
  changePassword(req, res) {
    try {
      const { currentPassword, newPassword } = req.body;
      if (!currentPassword || !newPassword || newPassword.length < 6) {
        return res.status(400).json({
          error: 'INVALID_PASSWORD',
          message: 'La nueva contraseña debe tener al menos 6 caracteres.'
        });
      }

      const user = db.prepare('SELECT password_hash FROM users WHERE id = ?').get(req.user.id);
      if (!verifyPassword(currentPassword, user.password_hash)) {
        return res.status(400).json({
          error: 'INCORRECT_PASSWORD',
          message: 'La contraseña actual no es correcta.'
        });
      }

      const newHash = hashPassword(newPassword);
      db.prepare('UPDATE users SET password_hash = ? WHERE id = ?').run(newHash, req.user.id);

      db.prepare(`
        INSERT INTO audit_logs (user_id, action, resource_type, resource_id, details_json, ip_address)
        VALUES (?, 'PASSWORD_CHANGED', 'USER', ?, '{}', ?)
      `).run(req.user.id, String(req.user.id), req.ip || '127.0.0.1');

      return res.json({ success: true, message: 'Contraseña actualizada correctamente.' });
    } catch (err) {
      return res.status(500).json({ error: 'SERVER_ERROR', message: err.message });
    }
  }
};
