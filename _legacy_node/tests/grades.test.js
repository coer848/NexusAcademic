import test from 'node:test';
import assert from 'node:assert/strict';
import { app } from '../src/server.js';
import { runSeed } from '../src/database/seed.js';

let server;
let baseUrl;
let teacherToken;
let studentTokenValeria;

test.before(async () => {
  runSeed();
  await new Promise((resolve) => {
    server = app.listen(0, () => {
      const port = server.address().port;
      baseUrl = `http://127.0.0.1:${port}`;
      resolve();
    });
  });

  // Login Teacher Carlos (Teacher ID 1, Group 1)
  const tRes = await fetch(`${baseUrl}/api/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: 'prof.carlos@nexus.edu', password: 'Teacher123!' })
  });
  const tData = await tRes.json();
  teacherToken = tData.token;

  // Login Student Valeria (Student ID 1)
  const sRes = await fetch(`${baseUrl}/api/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: 'estudiante.valeria@nexus.edu', password: 'Student123!' })
  });
  const sData = await sRes.json();
  studentTokenValeria = sData.token;
});

test.after(async () => {
  if (server) {
    await new Promise((resolve) => server.close(resolve));
  }
});

test('GRD-001: Rechazo de evaluación que supera el 100% acumulado de ponderación', async () => {
  // Group 1 already has 30% + 30% + 40% = 100% in seed
  const res = await fetch(`${baseUrl}/api/evaluations`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${teacherToken}`
    },
    body: JSON.stringify({
      group_id: 1,
      name: 'Examen Sorpresa Extra',
      type: 'QUIZ',
      weight: 15.0
    })
  });

  assert.equal(res.status, 400);
  const data = await res.json();
  assert.equal(data.error, 'INVALID_WEIGHT_SUM');
});

test('GRD-002: Rechazo de calificación fuera del rango institucional (0.0 a 5.0)', async () => {
  const res = await fetch(`${baseUrl}/api/grades`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${teacherToken}`
    },
    body: JSON.stringify({
      evaluation_id: 2, // Taller 1
      student_id: 1, // Valeria
      score: 5.8 // OUT OF BOUNDS
    })
  });

  assert.equal(res.status, 400);
  const data = await res.json();
  assert.equal(data.error, 'SCORE_OUT_OF_BOUNDS');
});

test('GRD-003: Asignación de calificación válida dentro de la escala', async () => {
  const res = await fetch(`${baseUrl}/api/grades`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${teacherToken}`
    },
    body: JSON.stringify({
      evaluation_id: 2, // Taller 1 (30%)
      student_id: 1, // Valeria
      score: 4.5,
      feedback: 'Implementación impecable de HMAC y control de ventana temporal.'
    })
  });

  assert.equal(res.status, 200);
  const data = await res.json();
  assert.equal(data.success, true);
  assert.equal(data.grade.newScore, 4.5);
});

test('GRD-004: Modificación de calificación genera registro de auditoría en grade_history', async () => {
  // Update grade for Valeria in Parcial 1 from 4.8 to 5.0 with reason
  const res = await fetch(`${baseUrl}/api/grades`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${teacherToken}`
    },
    body: JSON.stringify({
      evaluation_id: 1, // Parcial 1
      student_id: 1, // Valeria
      score: 5.0,
      change_reason: 'Revisión formal de reclamo: se valida punto extra por optimización de consultas.'
    })
  });

  assert.equal(res.status, 200);
  const data = await res.json();
  assert.equal(data.grade.oldScore, 4.8);
  assert.equal(data.grade.newScore, 5.0);

  // Check audit history endpoint
  const histRes = await fetch(`${baseUrl}/api/grades/${data.grade.gradeId}/history`, {
    headers: { 'Authorization': `Bearer ${teacherToken}` }
  });
  assert.equal(histRes.status, 200);
  const histData = await histRes.json();
  assert.ok(histData.history.length >= 2);
  const latestChange = histData.history[0];
  assert.equal(latestChange.old_score, 4.8);
  assert.equal(latestChange.new_score, 5.0);
  assert.ok(latestChange.change_reason.includes('Revisión formal'));
});

test('GRD-005: Cálculo matemático exacto de promedio ponderado', async () => {
  // Valeria has:
  // Parcial 1 (Weight 30%): 5.0 -> Contribution: 5.0 * 0.30 = 1.50
  // Taller 1 (Weight 30%): 4.5 -> Contribution: 4.5 * 0.30 = 1.35
  // Total Evaluated Weight: 60%
  // Normalized Weighted Average: (1.50 + 1.35) / 0.60 = 2.85 / 0.60 = 4.75
  // Remaining Weight: 40% (Proyecto Final)

  const res = await fetch(`${baseUrl}/api/grades/my-grades`, {
    headers: { 'Authorization': `Bearer ${studentTokenValeria}` }
  });

  assert.equal(res.status, 200);
  const data = await res.json();
  assert.equal(data.studentId, 1);
  const course1 = data.courses.find(c => c.group.group_id === 1);
  assert.ok(course1 != null);

  const stats = course1.stats;
  assert.equal(stats.totalEvaluatedWeight, 60.0);
  assert.equal(stats.accumulatedScore, 2.85);
  assert.equal(stats.weightedAverage, 4.75);
  assert.equal(stats.remainingWeight, 40.0);
  assert.equal(stats.isPassing, true);
});
