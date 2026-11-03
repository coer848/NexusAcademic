import test from 'node:test';
import assert from 'node:assert/strict';
import { app } from '../src/server.js';
import { runSeed } from '../src/database/seed.js';

let server;
let baseUrl;

test.before(async () => {
  runSeed();
  await new Promise((resolve) => {
    server = app.listen(0, () => {
      const port = server.address().port;
      baseUrl = `http://127.0.0.1:${port}`;
      resolve();
    });
  });
});

test.after(async () => {
  if (server) {
    await new Promise((resolve) => server.close(resolve));
  }
});

test('AUTH-001: Login exitoso como Administrador', async () => {
  const res = await fetch(`${baseUrl}/api/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      email: 'admin@nexus.edu',
      password: 'Admin123!'
    })
  });

  assert.equal(res.status, 200);
  const data = await res.json();
  assert.equal(data.success, true);
  assert.equal(data.user.role, 'ADMIN');
  assert.ok(data.token && typeof data.token === 'string');
});

test('AUTH-002: Login exitoso como Docente', async () => {
  const res = await fetch(`${baseUrl}/api/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      email: 'prof.carlos@nexus.edu',
      password: 'Teacher123!'
    })
  });

  assert.equal(res.status, 200);
  const data = await res.json();
  assert.equal(data.user.role, 'TEACHER');
  assert.ok(data.user.teacher != null);
  assert.equal(data.user.teacher.teacher_code, 'DOC-101');
});

test('AUTH-003: Login exitoso como Estudiante', async () => {
  const res = await fetch(`${baseUrl}/api/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      email: 'estudiante.valeria@nexus.edu',
      password: 'Student123!'
    })
  });

  assert.equal(res.status, 200);
  const data = await res.json();
  assert.equal(data.user.role, 'STUDENT');
  assert.ok(data.user.student != null);
  assert.equal(data.user.student.student_code, 'EST-2026-01');
});

test('AUTH-004: Rechazo con contraseña incorrecta', async () => {
  const res = await fetch(`${baseUrl}/api/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      email: 'admin@nexus.edu',
      password: 'WrongPassword!'
    })
  });

  assert.equal(res.status, 401);
  const data = await res.json();
  assert.equal(data.error, 'INVALID_CREDENTIALS');
});

test('AUTH-005: Rechazo con usuario inexistente', async () => {
  const res = await fetch(`${baseUrl}/api/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      email: 'noexiste@nexus.edu',
      password: 'SomePassword123!'
    })
  });

  assert.equal(res.status, 401);
  const data = await res.json();
  assert.equal(data.error, 'INVALID_CREDENTIALS');
});

test('AUTH-006: Auto-registro de nuevo estudiante', async () => {
  const res = await fetch(`${baseUrl}/api/auth/register`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      first_name: 'Camila',
      last_name: 'Torres',
      email: 'camila.torres@nexus.edu',
      password: 'StudentPass123!',
      role: 'STUDENT',
      code: 'EST-2026-99',
      career: 'Ingeniería de Software'
    })
  });

  assert.equal(res.status, 201);
  const data = await res.json();
  assert.equal(data.success, true);
  assert.equal(data.user.role, 'STUDENT');
  assert.equal(data.user.firstName, 'Camila');
  assert.ok(data.token);
});

test('AUTH-007: Auto-registro de nuevo docente', async () => {
  const res = await fetch(`${baseUrl}/api/auth/register`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      first_name: 'Fernando',
      last_name: 'Mendoza',
      email: 'prof.fernando@nexus.edu',
      password: 'TeacherPass123!',
      role: 'TEACHER',
      code: 'DOC-501',
      department: 'Inteligencia Artificial'
    })
  });

  assert.equal(res.status, 201);
  const data = await res.json();
  assert.equal(data.success, true);
  assert.equal(data.user.role, 'TEACHER');
  assert.ok(data.token);
});

test('AUTH-008: Rechazo de registro con email duplicado', async () => {
  const res = await fetch(`${baseUrl}/api/auth/register`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      first_name: 'Duplicado',
      last_name: 'Test',
      email: 'admin@nexus.edu',
      password: 'Password123!',
      role: 'STUDENT'
    })
  });

  assert.equal(res.status, 409);
  const data = await res.json();
  assert.equal(data.error, 'EMAIL_ALREADY_EXISTS');
});

