import test from 'node:test';
import assert from 'node:assert/strict';
import { app } from '../src/server.js';
import { runSeed } from '../src/database/seed.js';

let server;
let baseUrl;
let adminToken;
let teacherTokenCarlos; // Teacher 1 (Group 1, 3)
let teacherTokenElena;  // Teacher 2 (Group 2)
let studentTokenValeria; // Student 1 (Group 1, 2)
let studentTokenMateo;   // Student 2 (Group 1)

test.before(async () => {
  runSeed();
  await new Promise((resolve) => {
    server = app.listen(0, () => {
      const port = server.address().port;
      baseUrl = `http://127.0.0.1:${port}`;
      resolve();
    });
  });

  // Logins
  const lAdmin = await (await fetch(`${baseUrl}/api/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: 'admin@nexus.edu', password: 'Admin123!' })
  })).json();
  adminToken = lAdmin.token;

  const lCarlos = await (await fetch(`${baseUrl}/api/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: 'prof.carlos@nexus.edu', password: 'Teacher123!' })
  })).json();
  teacherTokenCarlos = lCarlos.token;

  const lElena = await (await fetch(`${baseUrl}/api/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: 'prof.elena@nexus.edu', password: 'Teacher123!' })
  })).json();
  teacherTokenElena = lElena.token;

  const lValeria = await (await fetch(`${baseUrl}/api/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: 'estudiante.valeria@nexus.edu', password: 'Student123!' })
  })).json();
  studentTokenValeria = lValeria.token;

  const lMateo = await (await fetch(`${baseUrl}/api/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: 'estudiante.mateo@nexus.edu', password: 'Student123!' })
  })).json();
  studentTokenMateo = lMateo.token;
});

test.after(async () => {
  if (server) {
    await new Promise((resolve) => server.close(resolve));
  }
});

test('SEC-004: Race Condition & Concurrencia masiva (10 peticiones simultáneas para el mismo estudiante)', async () => {
  // Start fresh session by Carlos
  const sRes = await fetch(`${baseUrl}/api/sessions/start`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${teacherTokenCarlos}` },
    body: JSON.stringify({ group_id: 1, topic: 'Prueba de Concurrencia' })
  });
  const sData = await sRes.json();
  const qr = sData.qrPayload;

  // Send 10 simultaneous requests with Promise.all
  const promises = Array.from({ length: 10 }).map(() => {
    return fetch(`${baseUrl}/api/attendance/register`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${studentTokenMateo}` },
      body: JSON.stringify({
        sessionId: qr.sessionId,
        nonce: qr.nonce,
        timestamp: qr.timestamp,
        signature: qr.signature
      })
    });
  });

  const responses = await Promise.all(promises);
  const statuses = responses.map(r => r.status);

  // Exactly ONE request must succeed (200), and exactly 9 must be rejected (409 Conflict)
  const successCount = statuses.filter(s => s === 200).length;
  const conflictCount = statuses.filter(s => s === 409).length;

  assert.equal(successCount, 1, 'Debe haber exactamente 1 registro exitoso');
  assert.equal(conflictCount, 9, 'Debe haber exactamente 9 rechazos por conflicto');
});

test('SEC-008: Escalamiento vertical (Estudiante intenta iniciar sesión de clase)', async () => {
  const res = await fetch(`${baseUrl}/api/sessions/start`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${studentTokenValeria}` },
    body: JSON.stringify({ group_id: 1, topic: 'Ataque de Escalamiento' })
  });

  assert.equal(res.status, 403);
  const data = await res.json();
  assert.equal(data.error, 'ACCESS_DENIED');
});

test('SEC-009: Escalamiento vertical (Estudiante intenta crear una evaluación)', async () => {
  const res = await fetch(`${baseUrl}/api/evaluations`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${studentTokenValeria}` },
    body: JSON.stringify({ group_id: 1, name: 'Hack Eval', type: 'QUIZ', weight: 10 })
  });

  assert.equal(res.status, 403);
  const data = await res.json();
  assert.equal(data.error, 'ACCESS_DENIED');
});

test('SEC-010: Escalamiento vertical (Estudiante intenta calificar o modificar notas)', async () => {
  const res = await fetch(`${baseUrl}/api/grades`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${studentTokenValeria}` },
    body: JSON.stringify({ evaluation_id: 1, student_id: 1, score: 5.0 })
  });

  assert.equal(res.status, 403);
  const data = await res.json();
  assert.equal(data.error, 'ACCESS_DENIED');
});

test('SEC-011: IDOR (Estudiante intenta consultar historial de notas de otro estudiante)', async () => {
  // Valeria (Student ID 1) tries to view Grade 2 (Mateo's grade, Student ID 2)
  const res = await fetch(`${baseUrl}/api/grades/2/history`, {
    headers: { 'Authorization': `Bearer ${studentTokenValeria}` }
  });

  assert.equal(res.status, 403);
  const data = await res.json();
  assert.equal(data.error, 'ACCESS_DENIED');
});

test('SEC-012: Violación de límites de propiedad docente (Docente Elena intenta calificar en grupo de Carlos)', async () => {
  // Evaluation 1 belongs to Group 1 (Carlos is teacher)
  const res = await fetch(`${baseUrl}/api/grades`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${teacherTokenElena}` },
    body: JSON.stringify({ evaluation_id: 1, student_id: 1, score: 4.0 })
  });

  assert.equal(res.status, 403);
  const data = await res.json();
  assert.equal(data.error, 'NOT_COURSE_TEACHER');
});

test('SEC-013: Acceso no autenticado rechazado en endpoints protegidos', async () => {
  const res = await fetch(`${baseUrl}/api/academic/groups`);
  assert.equal(res.status, 401);
  const data = await res.json();
  assert.equal(data.error, 'AUTHENTICATION_REQUIRED');
});

test('SEC-016: Fuga de información (password_hash nunca debe exponerse en respuestas JSON)', async () => {
  const res = await fetch(`${baseUrl}/api/auth/me`, {
    headers: { 'Authorization': `Bearer ${studentTokenValeria}` }
  });

  assert.equal(res.status, 200);
  const data = await res.json();
  assert.equal(data.user.password_hash, undefined);
  assert.equal(data.user.password, undefined);
});
