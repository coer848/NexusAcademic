import express from 'express';
import { authenticate } from '../middleware/auth.js';
import { requireRole } from '../middleware/rbac.js';

import { authController } from '../controllers/authController.js';
import { academicController } from '../controllers/academicController.js';
import { attendanceController } from '../controllers/attendanceController.js';
import { gradeController } from '../controllers/gradeController.js';
import { auditController } from '../controllers/auditController.js';

const router = express.Router();

// ================= AUTHENTICATION & REGISTRATION =================
router.post('/auth/register', authController.register);
router.post('/auth/login', authController.login);
router.get('/auth/me', authenticate, authController.getMe);
router.post('/auth/change-password', authenticate, authController.changePassword);

// ================= ACADEMIC STRUCTURE =================
router.get('/academic/institution', authenticate, academicController.getInstitution);
router.put('/academic/institution', authenticate, requireRole('ADMIN'), academicController.updateInstitution);

router.get('/academic/periods', authenticate, academicController.getPeriods);
router.post('/academic/periods', authenticate, requireRole('ADMIN'), academicController.createPeriod);

router.get('/academic/subjects', authenticate, academicController.getSubjects);
router.post('/academic/subjects', authenticate, requireRole('ADMIN'), academicController.createSubject);

router.get('/academic/groups', authenticate, academicController.getGroups);
router.get('/academic/groups/:id', authenticate, academicController.getGroupDetails);
router.get('/academic/available-courses', authenticate, academicController.getAvailableCourses);
router.post('/academic/groups', authenticate, requireRole('ADMIN'), academicController.createGroup);
router.post('/academic/teacher-course', authenticate, requireRole('TEACHER', 'ADMIN'), academicController.createTeacherCourse);
router.put('/academic/groups/:id', authenticate, requireRole('TEACHER', 'ADMIN'), academicController.updateGroup);
router.delete('/academic/groups/:id', authenticate, requireRole('TEACHER', 'ADMIN'), academicController.deleteGroup);

router.post('/academic/enrollments', authenticate, requireRole('ADMIN', 'STUDENT'), academicController.enrollStudent);
router.get('/academic/teachers', authenticate, requireRole('ADMIN'), academicController.getTeachers);
router.put('/academic/teachers/:id', authenticate, requireRole('ADMIN'), academicController.updateTeacher);
router.get('/academic/students', authenticate, requireRole('ADMIN', 'TEACHER'), academicController.getStudents);

// ================= SESSIONS & DYNAMIC QR ATTENDANCE =================
router.post('/sessions/start', authenticate, requireRole('TEACHER', 'ADMIN'), attendanceController.startSession);
router.get('/sessions/:id/qr-token', authenticate, requireRole('TEACHER', 'ADMIN'), attendanceController.getSessionQRToken);
router.post('/sessions/:id/close', authenticate, requireRole('TEACHER', 'ADMIN'), attendanceController.closeSession);

router.post('/attendance/register', authenticate, requireRole('STUDENT'), attendanceController.registerAttendance);
router.get('/attendance/session/:id', authenticate, requireRole('TEACHER', 'ADMIN'), attendanceController.getSessionAttendance);
router.get('/attendance/my-history', authenticate, requireRole('STUDENT'), attendanceController.getMyAttendanceHistory);

// ================= EVALUATIONS, GRADES & WEIGHTED AVERAGES =================
router.post('/evaluations', authenticate, requireRole('TEACHER', 'ADMIN'), gradeController.createEvaluation);
router.put('/evaluations/:id', authenticate, requireRole('TEACHER', 'ADMIN'), gradeController.updateEvaluation);
router.delete('/evaluations/:id', authenticate, requireRole('TEACHER', 'ADMIN'), gradeController.deleteEvaluation);

router.post('/grades', authenticate, requireRole('TEACHER', 'ADMIN'), gradeController.saveGrade);
router.post('/grades/batch', authenticate, requireRole('TEACHER', 'ADMIN'), gradeController.saveBatchGrades);
router.get('/grades/group/:groupId/matrix', authenticate, requireRole('TEACHER', 'ADMIN'), gradeController.getGroupGradesMatrix);
router.get('/grades/my-grades', authenticate, requireRole('STUDENT'), gradeController.getMyGrades);
router.get('/grades/:gradeId/history', authenticate, gradeController.getGradeAuditHistory);

// ================= AUDIT TRAIL & SYSTEM METRICS =================
router.get('/audit/logs', authenticate, requireRole('ADMIN'), auditController.getAuditLogs);
router.get('/audit/grades-history', authenticate, requireRole('ADMIN', 'TEACHER'), auditController.getGlobalGradeHistory);
router.get('/audit/metrics', authenticate, requireRole('ADMIN'), auditController.getSystemMetrics);

export default router;
