import test from 'node:test';
import assert from 'node:assert/strict';
import { app } from '../src/server.js';

let server;
let baseUrl;

test.before(async () => {
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

test('UI-001: Servidor entrega index.html con status 200 y HTML5', async () => {
  const res = await fetch(`${baseUrl}/`);
  assert.equal(res.status, 200);
  const text = await res.text();
  assert.ok(text.includes('NexusAcademic'));
  assert.ok(text.includes('formLogin'));
  assert.ok(text.includes('viewTeacher'));
  assert.ok(text.includes('viewStudent'));
});

test('UI-002: Servidor entrega archivo CSS style.css con status 200', async () => {
  const res = await fetch(`${baseUrl}/css/style.css`);
  assert.equal(res.status, 200);
  const text = await res.text();
  assert.ok(text.includes('--primary: #6366f1;'));
  assert.ok(text.includes('.glass-card'));
});

test('UI-003: Servidor entrega archivo JS app.js con status 200', async () => {
  const res = await fetch(`${baseUrl}/js/app.js`);
  assert.equal(res.status, 200);
  const text = await res.text();
  assert.ok(text.includes('AppState'));
  assert.ok(text.includes('rotateTeacherQR'));
});
