import test from 'node:test';
import assert from 'node:assert/strict';
import { app } from '../src/server.js';
import { runSeed } from '../src/database/seed.js';

let server;
let baseUrl;
let teacherToken;
let studentTokenValeria;
let studentTokenAlejandro; // Not enrolled in group 1

test.before(async () => {
  runSeed();
  await new Promise((resolve) => {
    server = app.listen(0, () => {
      const port = server.address().port;
      baseUrl = `http://127.0.0.1:${port}`;
      resolve();
    });
  });

  // Login Teacher
  const tRes = await fetch(`${baseUrl}/api/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: 'prof.carlos@nexus.edu', password: 'Teacher123!' })
  });
  const tData = await tRes.json();
  teacherToken = tData.token;

  // Login Student Valeria (Enrolled in Group 1)
  const sRes1 = await fetch(`${baseUrl}/api/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: 'estudiante.valeria@nexus.edu', password: 'Student123!' })
  });
  const sData1 = await sRes1.json();
  studentTokenValeria = sData1.token;

  // Login Student Alejandro (NOT enrolled in Group 1)
  const sRes2 = await fetch(`${baseUrl}/api/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: 'estudiante.alejandro@nexus.edu', password: 'Student123!' })
  });
  const sData2 = await sRes2.json();
  studentTokenAlejandro = sData2.token;
});

test.after(async () => {
  if (server) {
    await new Promise((resolve) => server.close(resolve));
  }
});

let activeSessionId;
let activeQRPayload;

test('ATT-001: Docente inicia sesión de clase y obtiene token QR HMAC', async () => {
  const res = await fetch(`${baseUrl}/api/sessions/start`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${teacherToken}`
    },
    body: JSON.stringify({
      group_id: 1,
      topic: 'Sesión 1: Arquitectura y Criptografía QR',
      geo_lat: 4.60971,
      geo_lng: -74.08175,
      geo_radius_meters: 100.0
    })
  });

  assert.equal(res.status, 201);
  const data = await res.json();
  assert.equal(data.success, true);
  assert.ok(data.sessionId > 0);
  assert.ok(data.qrPayload.signature && data.qrPayload.nonce);

  activeSessionId = data.sessionId;
  activeQRPayload = data.qrPayload;
});

test('ATT-002: Estudiante matriculado registra asistencia exitosa con QR válido', async () => {
  const res = await fetch(`${baseUrl}/api/attendance/register`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${studentTokenValeria}`
    },
    body: JSON.stringify({
      sessionId: activeQRPayload.sessionId,
      nonce: activeQRPayload.nonce,
      timestamp: activeQRPayload.timestamp,
      signature: activeQRPayload.signature,
      latitude: 4.60972,
      longitude: -74.08174
    })
  });

  assert.equal(res.status, 200);
  const data = await res.json();
  assert.equal(data.success, true);
  assert.equal(data.attendance.status, 'PRESENT');
  assert.equal(data.attendance.isFlagged, 0);
});

test('ATT-003: Rechazo de doble registro por el mismo estudiante en la misma sesión (Unicidad)', async () => {
  // Fetch fresh rotating QR token
  const qrRes = await fetch(`${baseUrl}/api/sessions/${activeSessionId}/qr-token`, {
    headers: { 'Authorization': `Bearer ${teacherToken}` }
  });
  const qrData = await qrRes.json();

  const res = await fetch(`${baseUrl}/api/attendance/register`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${studentTokenValeria}`
    },
    body: JSON.stringify({
      sessionId: qrData.qrPayload.sessionId,
      nonce: qrData.qrPayload.nonce,
      timestamp: qrData.qrPayload.timestamp,
      signature: qrData.qrPayload.signature
    })
  });

  assert.equal(res.status, 409);
  const data = await res.json();
  assert.equal(data.error, 'ATTENDANCE_ALREADY_REGISTERED');
});

test('ATT-004: Rechazo de estudiante no matriculado en el grupo', async () => {
  const qrRes = await fetch(`${baseUrl}/api/sessions/${activeSessionId}/qr-token`, {
    headers: { 'Authorization': `Bearer ${teacherToken}` }
  });
  const qrData = await qrRes.json();

  const res = await fetch(`${baseUrl}/api/attendance/register`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${studentTokenAlejandro}`
    },
    body: JSON.stringify({
      sessionId: qrData.qrPayload.sessionId,
      nonce: qrData.qrPayload.nonce,
      timestamp: qrData.qrPayload.timestamp,
      signature: qrData.qrPayload.signature
    })
  });

  assert.equal(res.status, 403);
  const data = await res.json();
  assert.equal(data.error, 'STUDENT_NOT_ENROLLED');
});

test('ATT-005: Rechazo de token QR expirado (> 35s de antigüedad)', async () => {
  const expiredTimestamp = Date.now() - 45000; // 45s ago
  const res = await fetch(`${baseUrl}/api/attendance/register`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${studentTokenValeria}`
    },
    body: JSON.stringify({
      sessionId: activeSessionId,
      nonce: 'exp-nonce-1234',
      timestamp: expiredTimestamp,
      signature: 'dummy-signature'
    })
  });

  assert.equal(res.status, 400);
  const data = await res.json();
  assert.equal(data.error, 'TOKEN_EXPIRED');
});

test('ATT-006: Rechazo de token con firma manipulada', async () => {
  const res = await fetch(`${baseUrl}/api/attendance/register`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${studentTokenValeria}`
    },
    body: JSON.stringify({
      sessionId: activeSessionId,
      nonce: 'tampered-nonce-777',
      timestamp: Date.now(),
      signature: '0000000000000000000000000000000000000000000000000000000000000000'
    })
  });

  assert.equal(res.status, 401);
  const data = await res.json();
  assert.equal(data.error, 'INVALID_SIGNATURE');
});

test('ATT-007: Cierre de sesión e invalidación de registros posteriores', async () => {
  // Close session
  const closeRes = await fetch(`${baseUrl}/api/sessions/${activeSessionId}/close`, {
    method: 'POST',
    headers: { 'Authorization': `Bearer ${teacherToken}` }
  });
  assert.equal(closeRes.status, 200);

  // Attempt registration on closed session
  const res = await fetch(`${baseUrl}/api/attendance/register`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${studentTokenValeria}`
    },
    body: JSON.stringify({
      sessionId: activeSessionId,
      nonce: 'closed-test-nonce',
      timestamp: Date.now(),
      signature: 'sig'
    })
  });

  assert.equal(res.status, 400);
  const data = await res.json();
  assert.equal(data.error, 'SESSION_CLOSED');
});

let createdTeacherGroupId;

test('ATT-008: Docente crea directamente su propia asignatura y grupo', async () => {
  const res = await fetch(`${baseUrl}/api/academic/teacher-course`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${teacherToken}`
    },
    body: JSON.stringify({
      subject_name: 'Inteligencia Artificial y Redes Neuronales',
      subject_code: 'IA-601',
      group_code: 'G01',
      schedule: 'Viernes 14:00 - 18:00',
      classroom: 'Laboratorio de IA',
      credits: 4,
      description: 'Curso avanzado de redes neuronales profundas'
    })
  });

  assert.equal(res.status, 201);
  const data = await res.json();
  assert.equal(data.success, true);
  assert.ok(data.groupId > 0);
  createdTeacherGroupId = data.groupId;
});

test('ATT-009: Docente actualiza su asignatura u horario', async () => {
  const res = await fetch(`${baseUrl}/api/academic/groups/${createdTeacherGroupId}`, {
    method: 'PUT',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${teacherToken}`
    },
    body: JSON.stringify({
      subject_name: 'Inteligencia Artificial Avanzada',
      schedule: 'Viernes 15:00 - 19:00',
      classroom: 'Lab Cómputo 4'
    })
  });

  assert.equal(res.status, 200);
  const data = await res.json();
  assert.equal(data.success, true);
});

test('ATT-010: Docente elimina su grupo de clase', async () => {
  const res = await fetch(`${baseUrl}/api/academic/groups/${createdTeacherGroupId}`, {
    method: 'DELETE',
    headers: {
      'Authorization': `Bearer ${teacherToken}`
    }
  });

  assert.equal(res.status, 200);
  const data = await res.json();
  assert.equal(data.success, true);
});

